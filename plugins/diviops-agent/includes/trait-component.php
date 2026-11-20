<?php
/**
 * Read-only structural inspection of saved Divi Component Slots.
 */

use ET\Builder\FrontEnd\BlockParser\BlockParserStore;
use ET\Builder\FrontEnd\BlockParser\BlockParserUtils;
use ET\Builder\Packages\GlobalLayout\GlobalLayout;
use ET\Builder\Packages\ModuleLibrary\GlobalSlot\GlobalSlotUtils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_Component {

	/** Preview immediate child membership. No rendering, migration or nested layout expansion. */
	public static function component_slot_preview( $request ) {
		$page_id       = $request['id'];
		$definition_id = $request->get_param( 'definition_id' );
		$instance      = $request->get_param( 'instance_index' );
		$slot_id       = $request->get_param( 'slot_id' );
		foreach ( [ $page_id, $definition_id, $instance ?? 1 ] as $number ) {
			if ( ( ! is_int( $number ) && ! is_string( $number ) ) || false === filter_var( $number, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] ) ) {
				return self::envelope_error( 'invalid_input', 'Page ID, definition ID and instance index must be positive integers.', null, 400 );
			}
		}
		if ( ! is_string( $slot_id ) || '' === $slot_id ) {
			return self::envelope_error( 'invalid_input', 'Supply a nonempty saved Slot ID.', null, 400 );
		}

		$page = get_post( (int) $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			return self::envelope_error( 'not_found', 'Owner page not found.', null, 404 );
		}
		if ( ! self::can_inspect_post_object( $page ) ) {
			return self::envelope_object_read_forbidden( (int) $page_id, 'page' );
		}
		$definition = get_post( (int) $definition_id );
		if ( ! $definition || 'et_pb_layout' !== $definition->post_type || 'publish' !== $definition->post_status ) {
			return self::envelope_error( 'not_found', 'Published Divi Library definition not found.', null, 404 );
		}
		if ( ! self::can_inspect_post_object( $definition ) ) {
			return self::envelope_object_read_forbidden( (int) $definition_id, 'library_item' );
		}
		if ( ! is_callable( [ BlockParserUtils::class, 'extract_global_layout_block' ] )
			|| ! is_callable( [ BlockParserUtils::class, 'normalize_global_layout_source_id' ] )
			|| ! is_callable( [ BlockParserUtils::class, 'parse_global_layout_blocks' ] )
			|| ! is_callable( [ BlockParserStore::class, 'get_fresh_global_layout_post_content' ] )
			|| ! is_callable( [ GlobalLayout::class, 'is_global_layout_template' ] )
			|| ! is_callable( [ GlobalSlotUtils::class, 'collect_payloads' ] ) ) {
			return self::envelope_error( 'unsupported_component', 'This preview requires the Divi Component and Slot parsers from Divi 5.19.1.', null, 422 );
		}
		if ( ! GlobalLayout::is_global_layout_template( (int) $definition_id ) ) {
			return self::envelope_error( 'not_found', 'Library item is not a shared Component definition.', null, 404 );
		}

		$references = self::component_references( $page->post_content, (int) $definition_id );
		if ( is_wp_error( $references ) ) {
			return self::envelope_from_wp_error( $references );
		}
		$count = count( $references );
		if ( null === $instance && $count > 1 ) {
			return self::envelope_error( 'ambiguous_instance', 'Multiple saved references use this definition.', 'Supply a one-based instance_index.', 409, [ 'instance_count' => $count ] );
		}
		$index = (int) ( $instance ?? 1 );
		if ( ! isset( $references[ $index - 1 ] ) ) {
			return self::envelope_error( 'not_found', 'Selected Component reference not found on the owner page.', null, 404 );
		}
		$definition_content = BlockParserStore::get_fresh_global_layout_post_content( (int) $definition_id );
		if ( null === $definition_content ) {
			return self::envelope_error( 'not_found', 'Shared definition content is unavailable.', null, 404 );
		}
		$preview = self::resolve_component_slot( $definition_content, $references[ $index - 1 ], $slot_id );
		if ( is_wp_error( $preview ) ) {
			return self::envelope_from_wp_error( $preview );
		}
		return self::envelope_success( [
			'page_id'             => (int) $page_id,
			'definition_id'       => (int) $definition_id,
			'instance_count'      => $count,
			'instance_index'      => $index,
			'slot_id'             => $slot_id,
			'page_checksum'       => 'sha256:' . hash( 'sha256', $page->post_content ),
			'definition_checksum' => 'sha256:' . hash( 'sha256', $definition_content ),
		] + $preview );
	}

	/** Native raw bounds preserve the distinction between a void reference and an empty pair. */
	private static function component_references( string $content, int $definition_id ) {
		$references = [];
		$offset     = 0;
		$marker     = '<!-- wp:divi/global-layout';
		while ( false !== ( $start = strpos( $content, $marker, $offset ) ) ) {
			$reference = BlockParserUtils::extract_global_layout_block( $content, $start );
			if ( null === $reference ) {
				return self::unsupported_component_shape( 'Cannot establish the bounds of a saved Component reference.' );
			}
			if ( $definition_id === BlockParserUtils::normalize_global_layout_source_id( $reference['attrs']['globalModule'] ?? null ) ) {
				$references[] = $reference;
			}
			// A saved nested reference belongs to this component, not directly to the owner.
			$offset = $reference['end'];
		}
		return $references;
	}

	private static function unsupported_component_shape( string $reason ): WP_Error {
		return new WP_Error( 'unsupported_component', $reason, [ 'status' => 422 ] );
	}

	/**
	 * Index only the current component's exposed Slots, matching GlobalSlotUtils'
	 * traversal boundaries. Keep all matches until the effective owner is known.
	 */
	private static function index_component_slots( array $blocks, array &$slots, bool $check_nested_ownership ) {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'];
			if ( 'divi/global-layout' === $name ) {
				continue;
			}
			$attrs = $block['attrs'];
			if ( ! is_array( $attrs ) ) {
				return self::unsupported_component_shape( 'Cannot decode the saved block attributes.' );
			}
			if ( $check_nested_ownership && self::component_has_local_selector( $attrs ) ) {
				return self::unsupported_component_shape( 'A nested module can override structural Slot selectors.' );
			}
			if ( $check_nested_ownership && true === ( $attrs['localChildren'] ?? false ) ) {
				return self::unsupported_component_shape( 'A nested module can replace its synchronized subtree from this snapshot.' );
			}
			if ( 'divi/global-slot' === $name ) {
				$id = $attrs['slotId'] ?? '';
				if ( ! is_string( $id ) || '' === $id ) {
					return self::unsupported_component_shape( 'Cannot identify a Slot without its saved string ID.' );
				}
				$slots[ $id ][] = $block;
				continue;
			}
			$error = self::index_component_slots( $block['innerBlocks'], $slots, $check_nested_ownership );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
		}
		return true;
	}

	/** Peel the optional Library placeholder to find a single concrete definition root. */
	private static function component_definition_root( array $blocks ) {
		$roots = [];
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] ) {
				if ( '' !== trim( $block['innerHTML'] ) ) {
					return self::unsupported_component_shape( 'The definition includes content outside its Divi root.' );
				}
				continue;
			}
			if ( 'divi/placeholder' === $block['blockName'] ) {
				if ( '' !== trim( $block['innerHTML'] ) ) {
					return self::unsupported_component_shape( 'The definition placeholder contains unparsed markup.' );
				}
				if ( empty( $block['innerBlocks'] ) ) {
					continue;
				}
				$block = self::component_definition_root( $block['innerBlocks'] );
				if ( is_wp_error( $block ) ) {
					return $block;
				}
			}
			$roots[] = $block;
		}
		if ( 1 !== count( $roots ) || 0 !== strpos( $roots[0]['blockName'], 'divi/' ) || 'divi/global-layout' === $roots[0]['blockName'] ) {
			return self::unsupported_component_shape( 'The definition must contain one concrete Divi root.' );
		}
		return $roots[0];
	}

	/** Structural selectors that Divi could replace during local attribute composition. */
	private static function component_has_local_selector( array $attrs ): bool {
		foreach ( (array) ( $attrs['localAttrsMap'] ?? [] ) as $entry ) {
			if ( in_array( $entry['attrName'] ?? null, [ 'localChildren', 'slotId' ], true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function resolve_component_slot( string $content, array $reference, string $slot_id ) {
		$inner_html = $reference['innerHTML'];
		if ( ! self::validate_divi_marker_sequence( $content )['ok'] || ! self::validate_divi_marker_sequence( $inner_html )['ok'] ) {
			return self::unsupported_component_shape( 'Slot inspection requires balanced Divi block delimiters.' );
		}
		$root = self::component_definition_root( BlockParserUtils::parse_global_layout_blocks( $content ) );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$local = $root['attrs']['localChildren'] ?? false;
		if ( ! is_bool( $local ) || ! is_array( $root['attrs'] ) ) {
			return self::unsupported_component_shape( 'Cannot determine localChildren on the definition root.' );
		}

		$root_override = $reference['attrs']['localAttrs'] ?? [];
		if ( ! empty( $root_override ) && ( self::component_has_local_selector( $root['attrs'] )
			|| ( 'divi/global-slot' === $root['blockName'] && true === ( $root_override['localModule'] ?? false ) ) ) ) {
			return self::unsupported_component_shape( 'The reference can override structural selectors on the definition root.' );
		}

		// BlockParserStore discards freeform and placeholder entries at snapshot top level.
		$snapshot = array_filter( BlockParserUtils::parse_global_layout_blocks( $inner_html ), static function ( $block ) {
			return ! empty( $block['blockName'] ) && 'divi/placeholder' !== $block['blockName'];
		} );
		$state = $reference['hasChildrenSnapshot'] ? ( $snapshot ? 'populated' : 'empty' ) : 'absent';

		$shells = [];
		// The root's own localChildren is handled below. Only a nested module could introduce another ownership boundary.
		$definition_tree = [ $root ];
		unset( $definition_tree[0]['attrs']['localChildren'] );
		$error = self::index_component_slots( $definition_tree, $shells, ! $local && ! empty( $snapshot ) );
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		$payloads = [];
		$error    = self::index_component_slots( $snapshot, $payloads, false );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$local_snapshot  = $local && $reference['hasChildrenSnapshot'];
		$root_is_slot    = 'divi/global-slot' === $root['blockName'];
		$shell_matches   = $shells[ $slot_id ] ?? [];
		$payload_matches = [];
		$native_payloads = [];

		if ( $local_snapshot && ! $root_is_slot ) {
			$payload_matches = $payloads[ $slot_id ] ?? [];
		} elseif ( ! $local && $shell_matches && $payloads ) {
			// Use the source ID selected by Divi's native graft, including numeric-string key handling.
			$native_payloads = GlobalSlotUtils::collect_payloads( $snapshot );
			if ( $slot_id && isset( $native_payloads[ $slot_id ] ) ) {
				$payload_matches = $payloads[ $native_payloads[ $slot_id ]['attrs']['slotId'] ];
			}
		}
		if ( ( ! $local_snapshot && count( $shell_matches ) > 1 ) || count( $payload_matches ) > 1 ) {
			return new WP_Error( 'duplicate_slot_id', 'Multiple Slots can supply the requested shell or its payload.', [ 'status' => 409 ] );
		}
		if ( ! $local ) {
			foreach ( $payloads as $matches ) {
				foreach ( $matches as $candidate ) {
					if ( true === ( $candidate['attrs']['localModule'] ?? false ) ) {
						return self::unsupported_component_shape( 'A fully local Slot can change the identity of a synchronized shell.' );
					}
				}
			}
		}
		$shell   = $shell_matches[0] ?? null;
		$payload = $payload_matches[0] ?? null;
		if ( null === $shell && null === $payload ) {
			return new WP_Error( 'not_found', 'Slot not found in the selected Component.', [ 'status' => 404 ] );
		}

		$shell_source = 'definition';
		$child_source = 'definition';
		$children     = $shell['innerBlocks'] ?? [];
		if ( $local_snapshot && $root_is_slot ) {
			// The template root remains the shell, with the snapshot as its direct children.
			$children     = $snapshot;
			$child_source = 'instance';
		} elseif ( $local_snapshot ) {
			$shell        = $payload;
			$shell_source = $payload ? 'instance' : null;
			$child_source = $payload ? 'instance' : 'none';
			$children     = $payload['innerBlocks'] ?? [];
		} elseif ( $native_payloads ) {
			// Divi only grafts when at least one Slot payload exists. In that pass every
			// unmatched shell loses its children, even if it has saved template children.
			$child_source = $payload ? 'instance' : 'none';
			$children     = $payload['innerBlocks'] ?? [];
		}

		// Labels identify saved source blocks. No dynamic field or preset resolution runs here.
		$summary = [];
		foreach ( $children as $child ) {
			$summary[] = [
				'index'       => count( $summary ),
				'block_name'  => $child['blockName'],
				'admin_label' => sanitize_text_field( self::tb_block_admin_label( $child ) ),
			];
		}
		return [
			'local_children'  => $local,
			'snapshot_state'  => $state,
			'slot_present'    => null !== $shell,
			'shell_source'    => $shell_source,
			'children_source' => $child_source,
			'children'        => $summary,
		];
	}
}
