<?php
/**
 * Bounded structural integrity for full-content authoring writes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_AuthoringShape {
	/** Check all content before any dry-run plan or mutation. */
	private static function authoring_shape_preflight( array $contents, string $operation, string $target, $request, ?int $target_id = null ) {
		$state = [ 'bytes' => 0, 'blocks' => 0, 'fields' => 0, 'string_bytes' => 0 ];
		foreach ( $contents as $content ) {
			if ( ! is_string( $content ) ) {
				return new WP_Error( 'invalid_input', 'Full-content authoring input must be a string.', [ 'status' => 400 ] );
			}
			$state['bytes'] += strlen( $content );
			if ( $state['bytes'] > self::AUTHORING_SHAPE_LIMITS['input_bytes'] ) {
				return self::authoring_shape_refusal( 'budget_exceeded' );
			}
		}
		foreach ( $contents as $content ) {
			$integrity = self::assert_divi_full_content_safe_for_write( $content );
			if ( is_wp_error( $integrity ) ) {
				return $integrity;
			}
			if ( '' === $content ) {
				continue;
			}
			if ( ! function_exists( 'parse_blocks' ) ) {
				return self::authoring_shape_refusal( 'parser_invalid' );
			}
			try {
				$blocks = parse_blocks( $content );
				$result = is_array( $blocks ) ? self::authoring_shape_walk( $blocks, 1, $state ) : 'parser_invalid';
			} catch ( \Throwable $error ) {
				return self::authoring_shape_refusal( 'parser_invalid' );
			}
			if ( true !== $result ) {
				return self::authoring_shape_refusal( $result );
			}
		}
		return true;
	}

	/** Count parsed strings without retaining or rendering a second content corpus. */
	private static function authoring_shape_walk( array $blocks, int $depth, array &$state ) {
		if ( $depth > self::AUTHORING_SHAPE_LIMITS['depth'] ) {
			return 'budget_exceeded';
		}
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				return 'parser_invalid';
			}
			if ( ++$state['blocks'] > self::AUTHORING_SHAPE_LIMITS['blocks'] ) {
				return 'budget_exceeded';
			}
			$name = $block['blockName'] ?? null;
			if ( null !== $name && ! is_string( $name ) ) {
				return 'parser_invalid';
			}
			$collect = function ( $value ) use ( &$collect, &$state, $name ) {
				if ( is_string( $value ) ) {
					if ( ++$state['fields'] > self::AUTHORING_SHAPE_LIMITS['fields'] || ( $state['string_bytes'] += strlen( $value ) ) > self::AUTHORING_SHAPE_LIMITS['string_bytes'] ) {
						return 'budget_exceeded';
					}
					if ( null === $name && ( false !== strpos( $value, '<!-- wp:' ) || false !== strpos( $value, '<!-- /wp:' ) ) ) {
						return 'parser_invalid';
					}
				} elseif ( is_array( $value ) ) {
					foreach ( $value as $child ) {
						$result = $collect( $child );
						if ( true !== $result ) {
							return $result;
						}
					}
				}
				return true;
			};
			$attrs = $block['attrs'] ?? [];
			$inner_html = $block['innerHTML'] ?? '';
			$inner_content = $block['innerContent'] ?? [];
			$children = $block['innerBlocks'] ?? [];
			if ( ! is_array( $attrs ) || ! is_string( $inner_html ) || ! is_array( $inner_content ) || ! is_array( $children ) ) {
				return 'parser_invalid';
			}
			$values = [ $attrs, $inner_content ];
			// WordPress mirrors innerContent strings in innerHTML; count that text once.
			if ( $inner_html !== implode( '', array_filter( $inner_content, 'is_string' ) ) ) {
				$values[] = $inner_html;
			}
			$result = $collect( $values );
			if ( true !== $result ) {
				return $result;
			}
			if ( ! empty( $children ) ) {
				$result = self::authoring_shape_walk( $children, $depth + 1, $state );
				if ( true !== $result ) {
					return $result;
				}
			}
		}
		return true;
	}

	private static function authoring_shape_refusal( string $reason ) {
		$message = 'budget_exceeded' === $reason
			? 'Full-content authoring input exceeds the required validation limits.'
			: 'Full-content authoring input could not be parsed for required validation.';
		return new WP_Error( 'invalid_input', $message, [ 'status' => 400 ] );
	}
}
