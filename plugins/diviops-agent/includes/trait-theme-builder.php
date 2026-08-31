<?php
/**
 * Trait DiviOps_Agent_ThemeBuilder
 *
 * Theme Builder template + layout CRUD.
 *
 * Part of the diviops-agent monolith split (#220). Mixed into
 * DiviOps_Agent via `use` in diviops-agent.php — `self::` calls and
 * class constants resolve as if these methods lived directly on the
 * class.
 *
 * Envelope-adopted. Every route returns:
 *   success: { ok: true,  data: <payload> }
 *   error:   { ok: false, error: { code, message, hint? } }
 *
 * Error code mapping for this namespace:
 *   - not_found     — layout_id (or template_id on tb_template_trash) resolves to no
 *                     post or to a wrong post type
 *   - invalid_input — content / header_content / footer_content not a string
 *   - forbidden     — caller lacks delete_post on the et_template / linked layouts
 *   - <wp_error_code> — wp_insert_post / wp_update_post returned WP_Error;
 *                     envelope_from_wp_error() preserves the underlying
 *                     WP slug (commonly `db_insert_error` /
 *                     `db_update_error` from the post-insert path,
 *                     `empty_content` / `invalid_post_type` from the
 *                     validator path). The literal `wp_error` slug only
 *                     surfaces when the upstream WP_Error has an empty
 *                     code — see envelope_from_wp_error() in trait-core
 *                     for the fallback. Callers should branch on
 *                     `error.code` against the WP vocabulary, not a
 *                     hard-coded `wp_error`. The master-post-missing
 *                     case now auto-bootstraps; only a downstream
 *                     wp_insert_post failure on the master itself
 *                     surfaces under this branch.
 *   - tb_template.command_failed — wp_trash_post / wp_delete_post returned a
 *                     falsy / WP_Error result during tb_template_trash, OR
 *                     delete_post_meta returned falsy after we counted matching
 *                     rows (residual stale meta). error.data.failed_step is one
 *                     of: 'layout_destroy', 'template_destroy', 'meta_scrub'
 *   - tb_template.default_already_exists — tb_template_create called with
 *                     condition="default" (or "") but the active Theme
 *                     Builder master's `_et_template` linked list
 *                     already names an et_template carrying
 *                     `_et_default = '1'` (regardless of `_et_enabled`
 *                     status: the router resolves by linked-list
 *                     position BEFORE the enable-gate, so a disabled
 *                     existing default linked ahead of the new one
 *                     would still shadow it). Templates outside the
 *                     active master's linked list (orphan defaults,
 *                     library-cloned-master defaults) cannot shadow
 *                     and DO NOT block. error.data carries
 *                     `existing_default_id` + `master_post_id`. HTTP 409.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_ThemeBuilder {

	/**
	 * Resolve the active Theme Builder master post.
	 *
	 * Single source of truth for master discovery across this trait
	 * (tb_template_create + tb_template_trash + the singleton-default
	 * scoping). Approximates the discovery conditions used by Divi's
	 * `et_theme_builder_get_theme_builder_post_id` (theme-builder.php:378):
	 * publish-status, single result ordered by date descending, excludes
	 * library-cloned masters via the `_et_library_theme_builder`
	 * meta-not-exists clause. Divi itself uses WP_Query directly (with
	 * `fields=ids` + `no_found_rows=true` + cache-update flags disabled);
	 * we use get_posts() here because it's a one-shot discovery and the
	 * perf delta is microscopic at this call frequency — readability
	 * wins. The conditions are what matter for correctness; the
	 * primitive is an implementation choice. `suppress_filters => false`
	 * so multi-site / third-party filter chains (WPML, polylang,
	 * locale-aware post-type filters) see the query — matches Divi's
	 * WP_Query posture; get_posts() defaults to true, which silently
	 * bypasses those filters.
	 *
	 * @return int Active master post ID, or 0 when none exists.
	 */
	private static function find_active_master() {
		$master = get_posts( [
			'post_type'        => 'et_theme_builder',
			'post_status'      => 'publish',
			'posts_per_page'   => 1,
			'orderby'          => 'date',
			'order'            => 'desc',
			'suppress_filters' => false,
			'meta_query'       => [
				[
					'key'     => '_et_library_theme_builder',
					'compare' => 'NOT EXISTS',
				],
			],
		] );
		return empty( $master ) ? 0 : (int) $master[0]->ID;
	}

	/**
	 * Read the active master's `_et_template` meta as an int[] of linked
	 * template post IDs. Returns [] when the master id is 0 or carries no
	 * meta. Used by the singleton-default scoping (only templates linked
	 * to the active master can shadow the router's default-pick) and by
	 * tb_template_trash's meta-scrub counter.
	 *
	 * @param int $master_id
	 * @return int[]
	 */
	private static function get_master_template_ids( $master_id ) {
		if ( $master_id <= 0 ) {
			return [];
		}
		$raw = get_post_meta( $master_id, '_et_template' );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$ids = [];
		foreach ( $raw as $ref ) {
			$id = (int) $ref;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	// ── Theme Builder Operations ────────────────────────────────────

	/**
	 * List all Theme Builder templates with their conditions and layout IDs.
	 */
	public static function tb_template_list( $request ) {
		$per_page = max( 1, min( absint( $request->get_param( 'per_page' ) ?? 50 ), 100 ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ?? 1 ) );

		$query = new WP_Query( [
			'post_type'      => 'et_template',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		] );

		// Prime post meta cache to avoid N+1 queries.
		if ( $query->posts ) {
			update_post_caches( $query->posts, 'et_template', false, true );
		}

		$results = [];
		foreach ( $query->posts as $post ) {
			$use_on      = get_post_meta( $post->ID, '_et_use_on' );
			$exclude     = get_post_meta( $post->ID, '_et_exclude_from' );
			$is_default  = '1' === get_post_meta( $post->ID, '_et_default', true );
			$is_enabled  = '1' === get_post_meta( $post->ID, '_et_enabled', true );

			$results[] = [
				'id'                    => $post->ID,
				'title'                 => $post->post_title,
				'is_default'            => $is_default,
				'enabled'               => $is_enabled,
				'conditions'            => $use_on,
				'exclusions'            => $exclude,
				'header_layout_id'      => (int) get_post_meta( $post->ID, '_et_header_layout_id', true ),
				'header_layout_enabled' => '1' === get_post_meta( $post->ID, '_et_header_layout_enabled', true ),
				'body_layout_id'        => (int) get_post_meta( $post->ID, '_et_body_layout_id', true ),
				'body_layout_enabled'   => '1' === get_post_meta( $post->ID, '_et_body_layout_enabled', true ),
				'footer_layout_id'      => (int) get_post_meta( $post->ID, '_et_footer_layout_id', true ),
				'footer_layout_enabled' => '1' === get_post_meta( $post->ID, '_et_footer_layout_enabled', true ),
			];
		}

		return self::envelope_success( [
			'results'     => $results,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		] );
	}

	/**
	 * Get a Theme Builder layout's content (header, body, or footer).
	 */
	public static function tb_layout_get( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		$valid_types = [ 'et_header_layout', 'et_body_layout', 'et_footer_layout' ];
		if ( ! $post || ! in_array( $post->post_type, $valid_types, true ) ) {
			return self::envelope_error(
				'not_found',
				"Theme Builder layout #{$post_id} not found.",
				'Run diviops_tb_template_list to discover valid header_layout_id / body_layout_id / footer_layout_id values.',
				404
			);
		}
		if ( ! self::can_inspect_post_object( $post ) ) {
			return self::envelope_object_read_forbidden( $post_id, 'theme_builder_layout' );
		}

		return self::envelope_success( [
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'type'        => $post->post_type,
			'content_raw' => $post->post_content,
		] );
	}

	/**
	 * Export read-only target context for offline cross-env header/footer preflight.
	 *
	 * This route intentionally does not read source markup and does not write target
	 * content. It returns the secret-free target JSON consumed by
	 * diviops-cross-env-preflight --target.
	 */
	public static function cross_env_target_context_get( $request ) {
		$destination_id   = absint( $request->get_param( 'destination_id' ) );
		$destination_kind = sanitize_key( (string) ( $request->get_param( 'destination_kind' ) ?: 'tb_header_layout' ) );
		$kind_map         = self::cross_env_layout_kind_map();
		$expected_type    = $kind_map[ $destination_kind ] ?? '';

		if ( $destination_id <= 0 ) {
			return self::envelope_error(
				'invalid_input',
				'destination_id must be a positive target Theme Builder header layout ID.',
				'Run diviops_tb_template_list and pass an existing header_layout_id.',
				400,
				[ 'field' => 'destination_id' ]
			);
		}

		if ( '' === $expected_type ) {
			return self::envelope_error(
				'invalid_input',
				'Only destination_kind=tb_header_layout or tb_footer_layout is supported for this read-only target context export.',
				'Use an existing same-kind Theme Builder header or footer layout ID.',
				400,
				[ 'received' => $destination_kind ]
			);
		}

		$post = get_post( $destination_id );
		if ( ! $post || $expected_type !== $post->post_type ) {
			return self::envelope_error(
				'not_found',
				"Theme Builder {$destination_kind} #{$destination_id} not found.",
				'Run diviops_tb_template_list and use a valid same-kind layout ID.',
				404,
				[
					'destination_id'   => $destination_id,
					'destination_kind' => $destination_kind,
				]
			);
		}
		if ( ! current_user_can( 'edit_post', $destination_id ) ) {
			return self::envelope_error(
				'forbidden',
				"Cannot inspect Theme Builder layout #{$destination_id}.",
				'Authenticate as a user with edit rights to this layout.',
				403,
				[ 'destination_id' => $destination_id ]
			);
		}

		$hints       = self::cross_env_normalize_asset_hints(
			$request->get_param( 'source_asset_hints' ),
			$request->get_param( 'source_attachment_ids' ),
			$request->get_param( 'source_upload_paths' )
		);
		$attachments = self::cross_env_attachment_candidates( $hints['assets'], $hints['source_ids'] );
		$remaps      = self::cross_env_attachment_remaps( $attachments, $hints['source_ids'] );
		$linkage     = self::cross_env_template_linkage( $destination_id, $destination_kind, $expected_type );

		return self::envelope_success( [
			'origin'                       => self::cross_env_site_origin(),
			'destination_kind'             => $destination_kind,
			'destination_id'               => $destination_id,
			'destination_title'            => (string) $post->post_title,
			'destination_post_type'        => $expected_type,
			'destination_checksum'         => [
				'algorithm' => 'sha256',
				'input'     => 'post_content',
				'computed'  => hash( 'sha256', (string) $post->post_content ),
			],
			'global_colors'                => (object) self::cross_env_global_colors(),
			'global_color_value_evidence'  => (object) self::cross_env_global_color_value_evidence(),
			'builtin_customizer_color_ids' => self::cross_env_builtin_customizer_color_ids(),
			'module_preset_ids'            => self::cross_env_target_module_preset_ids(),
			'attachments'                  => $attachments,
			'attachment_remaps'            => (object) $remaps,
			'cache_scope'                  => 'theme_builder_global',
			'template_linkage'             => $linkage['evidence'],
			'template_linkage_digest'      => $linkage['digest'],
			'_meta'                        => [
				'read_only'                   => true,
				'destination_checksum'        => 'sha256 hex of current target layout post_content; raw post_content is not exported',
				'template_linkage_digest'     => 'sha256 over canonical versioned target kind/post-type/id plus active Theme Builder master ID, exact master template order, and linked-template slot/enabled/condition/exclusion evidence; empty linkage is represented by master_template_ids=[] and links=[]',
				'global_color_value_evidence' => 'sha256 hex of resolved target color values for user global colors and WP Customizer-backed built-ins; raw values are not exported here',
				'module_preset_inventory'     => 'target D5 module preset IDs only; preset definitions are not exported',
				'attachment_matching'         => 'basename_or_upload_path_exact',
				'attachment_remap_rule'       => 'A remap is emitted only when exactly one target attachment candidate is found and exactly one source attachment ID was supplied.',
				'write_apply_media_import'    => false,
				'global_color_import_create'  => false,
				'free_pro_placement'          => 'free_core',
			],
		] );
	}

	/**
	 * Export read-only source payload for offline cross-env header/footer preflight.
	 *
	 * This route is a Free/core primitive: it reads one source header/footer layout and
	 * returns secret-free JSON suitable for diviops-cross-env-preflight --source.
	 * It has no apply, reconcile, media import, global color import, or cache path.
	 */
	public static function cross_env_source_export_get( $request ) {
		$source_id   = absint( $request->get_param( 'source_id' ) );
		$source_kind = sanitize_key( (string) ( $request->get_param( 'source_kind' ) ?: 'tb_header_layout' ) );
		$kind_map    = self::cross_env_layout_kind_map();
		$expected_type = $kind_map[ $source_kind ] ?? '';

		if ( $source_id <= 0 ) {
			return self::envelope_error(
				'invalid_input',
				'source_id must be a positive source Theme Builder header layout ID.',
				'Run diviops_tb_template_list and pass an existing header_layout_id.',
				400,
				[ 'field' => 'source_id' ]
			);
		}

		if ( '' === $expected_type ) {
			return self::envelope_error(
				'invalid_input',
				'Only source_kind=tb_header_layout or tb_footer_layout is supported for this read-only source export.',
				'Use an existing source Theme Builder header or footer layout ID.',
				400,
				[ 'received' => $source_kind ]
			);
		}

		$post = get_post( $source_id );
		if ( ! $post || $expected_type !== $post->post_type ) {
			return self::envelope_error(
				'not_found',
				"Theme Builder {$source_kind} #{$source_id} not found.",
				'Run diviops_tb_template_list and use a valid same-kind layout ID.',
				404,
				[
					'source_id'   => $source_id,
					'source_kind' => $source_kind,
				]
			);
		}
		if ( ! current_user_can( 'edit_post', $source_id ) ) {
			return self::envelope_error(
				'forbidden',
				"Cannot inspect Theme Builder layout #{$source_id}.",
				'Authenticate as a user with edit rights to this layout.',
				403,
				[ 'source_id' => $source_id ]
			);
		}

		$markup = self::cross_env_sanitize_markup_for_export( (string) $post->post_content );
		$module_preset_ids = self::cross_env_module_preset_ids_from_markup( $markup );

		return self::envelope_success( [
			'origin'          => self::cross_env_site_origin(),
			'object_kind'     => $source_kind,
			'object_id'       => $source_id,
			'object_title'    => (string) $post->post_title,
			'object_post_type'=> $expected_type,
			'markup'          => $markup,
			'checksum'        => hash( 'sha256', $markup ),
			'attachments'     => self::cross_env_source_attachments_from_markup( $markup ),
			'module_preset_ids' => $module_preset_ids,
			'exported_at'     => gmdate( 'c' ),
			'diviops_version' => self::VERSION,
			'_meta'           => [
				'read_only'                  => true,
				'checksum'                   => 'sha256 hex of markup, without a sha256: prefix',
				'markup_sanitization'        => 'absolute URL credentials, query strings, fragments, and admin URLs are redacted before checksum',
				'attachment_inventory'       => 'best_effort_markup_upload_urls_and_attachment_ids',
				'module_preset_inventory'    => 'referenced attrs.modulePreset IDs from source markup only; preset definitions are not exported',
				'write_apply_media_import'   => false,
				'global_color_import_create' => false,
				'free_pro_placement'         => 'free_core',
			],
		] );
	}

	private static function cross_env_layout_kind_map(): array {
		return [
			'tb_header_layout' => 'et_header_layout',
			'tb_footer_layout' => 'et_footer_layout',
		];
	}

	private static function cross_env_template_linkage( int $layout_id, string $kind, string $post_type ): array {
		$slot = 'tb_header_layout' === $kind ? 'header' : 'footer';
		$id_key = '_et_' . $slot . '_layout_id';
		$enabled_key = '_et_' . $slot . '_layout_enabled';
		$master_id = self::find_active_master();
		$master_template_ids = self::get_master_template_ids( $master_id );
		$links = [];
		foreach ( $master_template_ids as $master_position => $template_id ) {
			$template = get_post( $template_id );
			if ( ! $template || 'et_template' !== $template->post_type || $layout_id !== absint( get_post_meta( $template_id, $id_key, true ) ) ) {
				continue;
			}
			$links[] = [
				'template_id'     => $template_id,
				'master_position' => (int) $master_position,
				'slot'            => $slot,
				'layout_id'       => $layout_id,
				'layout_enabled'  => '1' === (string) get_post_meta( $template_id, $enabled_key, true ),
				'template_enabled'=> '1' === (string) get_post_meta( $template_id, '_et_enabled', true ),
				'template_default'=> '1' === (string) get_post_meta( $template_id, '_et_default', true ),
				'conditions'      => self::cross_env_sorted_template_meta( $template_id, '_et_use_on' ),
				'exclusions'      => self::cross_env_sorted_template_meta( $template_id, '_et_exclude_from' ),
			];
		}
		$input = [
			'schema'                => 'diviops.cross_env.theme_builder_layout.linkage.v1',
			'destination_kind'      => $kind,
			'destination_id'        => $layout_id,
			'destination_post_type' => $post_type,
			'active_master_id'       => $master_id,
			'master_template_ids'    => $master_template_ids,
			'links'                 => $links,
		];
		return [
			'evidence' => $input,
			'digest'   => [
				'algorithm' => 'sha256',
				'input'     => 'canonical_template_linkage',
				'computed'  => hash( 'sha256', self::cross_env_canonical_json( $input ) ),
			],
		];
	}

	private static function cross_env_sorted_template_meta( int $template_id, string $meta_key ): array {
		$values = get_post_meta( $template_id, $meta_key, false );
		if ( ! is_array( $values ) ) {
			return [];
		}
		$normalized = [];
		foreach ( $values as $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$normalized[] = self::cross_env_canonicalize( $value );
		}
		usort( $normalized, static function ( $left, $right ): int {
			return strcmp( self::cross_env_canonical_json( $left ), self::cross_env_canonical_json( $right ) );
		} );
		return $normalized;
	}

	private static function cross_env_canonical_json( $value ): string {
		return (string) json_encode( self::cross_env_canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private static function cross_env_canonicalize( $value ) {
		if ( is_array( $value ) ) {
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( $is_list ) {
				return array_map( [ __CLASS__, 'cross_env_canonicalize' ], $value );
			}
			$sorted = [];
			foreach ( $value as $key => $nested ) {
				$sorted[ $key ] = self::cross_env_canonicalize( $nested );
			}
			ksort( $sorted, SORT_STRING );
			return $sorted;
		}
		if ( is_object( $value ) ) {
			return self::cross_env_canonicalize( get_object_vars( $value ) );
		}
		return $value;
	}

	private static function cross_env_site_origin(): string {
		$raw    = (string) get_site_url();
		$parsed = wp_parse_url( $raw );
		$scheme = is_array( $parsed ) && ! empty( $parsed['scheme'] ) ? strtolower( (string) $parsed['scheme'] ) : 'https';
		$host   = is_array( $parsed ) && ! empty( $parsed['host'] ) ? strtolower( (string) $parsed['host'] ) : '';
		$port   = is_array( $parsed ) && ! empty( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '';
		return $host ? "{$scheme}://{$host}{$port}" : $raw;
	}

	private static function cross_env_global_colors(): array {
		$raw         = et_get_option( 'et_global_data' );
		$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
		$colors      = is_array( $global_data ) && is_array( $global_data['global_colors'] ?? null )
			? $global_data['global_colors']
			: [];

		return array_filter( $colors, 'is_array' );
	}

	private static function cross_env_target_module_preset_ids(): array {
		$ids     = [];
		$presets = self::get_d5_presets();
		if ( ! is_array( $presets ) ) {
			return [];
		}
		foreach ( self::collect_d5_preset_audit_entries( $presets ) as $row ) {
			if ( 'module' !== ( $row['bucket'] ?? '' ) ) {
				continue;
			}
			$id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( self::cross_env_is_safe_preset_id( $id ) ) {
				$ids[ $id ] = $id;
			}
		}
		ksort( $ids, SORT_STRING );
		return array_values( $ids );
	}

	private static function cross_env_module_preset_ids_from_markup( string $markup ): array {
		$ids = [];
		if ( false === strpos( $markup, '"modulePreset"' ) ) {
			return [];
		}
		if ( ! preg_match_all( '/<!--\s+(\/)?wp:([A-Za-z0-9_-]+\/[A-Za-z0-9_-]+)(.*?)(\/)?-->/s', $markup, $matches, PREG_SET_ORDER ) ) {
			return [];
		}

		foreach ( $matches as $match ) {
			$block_name = (string) ( $match[2] ?? '' );
			if ( 0 !== strpos( $block_name, 'divi/' ) ) {
				continue;
			}
			$tail  = (string) ( $match[3] ?? '' );
			$start = strpos( $tail, '{' );
			$end   = strrpos( $tail, '}' );
			if ( false === $start || false === $end || $end < $start ) {
				continue;
			}
			$attrs = json_decode( substr( $tail, $start, $end - $start + 1 ), true );
			if ( ! is_array( $attrs ) || ! array_key_exists( 'modulePreset', $attrs ) ) {
				continue;
			}
			$values = is_array( $attrs['modulePreset'] ) ? $attrs['modulePreset'] : [ $attrs['modulePreset'] ];
			foreach ( $values as $id ) {
				$id = is_string( $id ) ? $id : '';
				if ( self::cross_env_is_safe_preset_id( $id ) ) {
					$ids[ $id ] = $id;
				}
			}
		}

		ksort( $ids, SORT_STRING );
		return array_values( $ids );
	}

	private static function cross_env_is_safe_preset_id( string $id ): bool {
		return '' !== $id && 'default' !== $id && 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $id );
	}

	private static function cross_env_global_color_value_evidence(): array {
		$evidence = [];

		foreach ( self::cross_env_global_colors() as $id => $entry ) {
			if ( ! is_string( $id ) || '' === $id || ! is_array( $entry ) ) {
				continue;
			}
			$value = isset( $entry['color'] ) ? sanitize_text_field( (string) $entry['color'] ) : '';
			if ( '' === $value ) {
				continue;
			}
			$evidence[ $id ] = [
				'id'     => $id,
				'source' => 'global_colors',
				'digest' => [
					'algorithm' => 'sha256',
					'input'     => 'resolved_color',
					'computed'  => hash( 'sha256', $value ),
				],
			];
		}

		foreach ( self::customizer_color_entries() as $id => $entry ) {
			if ( ! is_string( $id ) || '' === $id || isset( $evidence[ $id ] ) || ! is_array( $entry ) ) {
				continue;
			}
			$value = isset( $entry['color'] ) ? sanitize_text_field( (string) $entry['color'] ) : '';
			if ( '' === $value ) {
				continue;
			}
			$evidence[ $id ] = [
				'id'     => $id,
				'source' => 'wp_customizer',
				'digest' => [
					'algorithm' => 'sha256',
					'input'     => 'resolved_color',
					'computed'  => hash( 'sha256', $value ),
				],
			];
		}

		ksort( $evidence, SORT_STRING );
		return $evidence;
	}

	private static function cross_env_builtin_customizer_color_ids(): array {
		if ( class_exists( '\ET\Builder\Packages\GlobalData\GlobalData' ) ) {
			$customizer = \ET\Builder\Packages\GlobalData\GlobalData::$customizer_colors ?? [];
			if ( is_array( $customizer ) && ! empty( $customizer ) ) {
				return array_values( array_map( 'strval', array_keys( $customizer ) ) );
			}
		}

		return [
			'gcid-primary-color',
			'gcid-secondary-color',
			'gcid-heading-color',
			'gcid-body-color',
			'gcid-link-color',
		];
	}

	private static function cross_env_source_attachments_from_markup( string $markup ): array {
		$attachments = [];
		$seen_paths  = [];

		foreach ( self::cross_env_attachment_ids_from_markup( $markup ) as $attachment_id ) {
			$row = self::cross_env_source_attachment_payload_from_id( $attachment_id );
			if ( null !== $row ) {
				$key                 = 'id:' . (string) $row['id'];
				$attachments[ $key ] = $row;
				if ( ! empty( $row['path'] ) ) {
					$seen_paths[ (string) $row['path'] ] = true;
				}
			}
		}

		foreach ( self::cross_env_upload_urls_from_markup( $markup ) as $url ) {
			$path = self::cross_env_upload_path_from_url( $url );
			if ( null === $path ) {
				continue;
			}
			if ( isset( $seen_paths[ $path ] ) ) {
				continue;
			}
			$key = 'path:' . $path;
			if ( isset( $attachments[ $key ] ) ) {
				continue;
			}

			$attachments[ $key ] = [
				'url'      => $url,
				'path'     => $path,
				'filename' => self::cross_env_basename( $path ),
			];
		}

		return array_values( $attachments );
	}

	private static function cross_env_sanitize_markup_for_export( string $markup ): string {
		$markup = (string) preg_replace_callback(
			'#https?://[^\s"\'<>\\\\)]+#i',
			static function ( $matches ) {
				$url = self::cross_env_sanitize_absolute_url( (string) $matches[0] );
				if ( null === $url ) {
					return '';
				}

				$parsed = wp_parse_url( $url );
				$path   = is_array( $parsed ) && ! empty( $parsed['path'] ) ? strtolower( (string) $parsed['path'] ) : '';
				if ( false !== strpos( $path, '/wp-admin/' ) || '/wp-login.php' === $path ) {
					return '[redacted]';
				}

				return $url;
			},
			$markup
		);

		return (string) preg_replace(
			[
				'#/(?:Users|home|private/var)/[^\s"\'<>]+#',
				'#[A-Za-z]:\\\\[^\s"\'<>]+#',
			],
			'[redacted]',
			$markup
		);
	}

	private static function cross_env_attachment_ids_from_markup( string $markup ): array {
		$ids = [];
		self::cross_env_collect_attachment_ids_from_divi_attrs( $markup, $ids );
		ksort( $ids, SORT_NUMERIC );
		return array_values( $ids );
	}

	private static function cross_env_collect_attachment_ids_from_divi_attrs( string $markup, array &$ids ): void {
		if ( ! preg_match_all( '/<!--\s+(\/)?wp:([A-Za-z0-9_-]+\/[A-Za-z0-9_-]+)(.*?)(\/)?-->/s', $markup, $matches, PREG_SET_ORDER ) ) {
			return;
		}

		foreach ( $matches as $match ) {
			$block_name = (string) ( $match[2] ?? '' );
			if ( 0 !== strpos( $block_name, 'divi/' ) ) {
				continue;
			}
			$tail  = (string) ( $match[3] ?? '' );
			$start = strpos( $tail, '{' );
			$end   = strrpos( $tail, '}' );
			if ( false === $start || false === $end || $end < $start ) {
				continue;
			}
			$attrs = json_decode( substr( $tail, $start, $end - $start + 1 ) );
			if ( ! is_object( $attrs ) && ! is_array( $attrs ) ) {
				continue;
			}
			self::cross_env_collect_attachment_ids_from_value( $attrs, '', false, $ids );
		}
	}

	private static function cross_env_collect_attachment_ids_from_value( $value, string $parent_key, bool $in_media_context, array &$ids ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $nested ) {
				self::cross_env_collect_attachment_ids_from_value( $nested, $parent_key, $in_media_context, $ids );
			}
			return;
		}
		if ( ! is_object( $value ) ) {
			return;
		}

		$object_media_context = $in_media_context || self::cross_env_is_media_context_object( $value, $parent_key );
		foreach ( get_object_vars( $value ) as $key => $nested ) {
			$id = self::cross_env_numeric_source_id( $nested );
			if ( self::cross_env_is_media_attachment_id_key( (string) $key ) && $id > 0 ) {
				$ids[ $id ] = $id;
				continue;
			}
			if ( 'id' === $key && $object_media_context && $id > 0 ) {
				$ids[ $id ] = $id;
				continue;
			}
			self::cross_env_collect_attachment_ids_from_value( $nested, (string) $key, $object_media_context, $ids );
		}
	}

	private static function cross_env_is_media_context_object( object $value, string $parent_key ): bool {
		if ( preg_match( '/(?:image|media|attachment|gallery|video|audio|poster|logo|avatar|icon)/i', $parent_key ) ) {
			return true;
		}
		foreach ( get_object_vars( $value ) as $key => $nested ) {
			if ( self::cross_env_is_media_attachment_id_key( (string) $key ) ) {
				return true;
			}
			if ( is_string( $nested ) && null !== self::cross_env_upload_path_from_url( $nested ) ) {
				return true;
			}
		}
		return self::cross_env_has_attachment_path_evidence( $value );
	}

	private static function cross_env_has_attachment_path_evidence( object $value ): bool {
		$id   = self::cross_env_numeric_source_id( $value->id ?? null );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return false;
		}

		$attached_file = (string) get_post_meta( $id, '_wp_attached_file', true );
		$attached_file = ltrim( str_replace( '\\', '/', $attached_file ), '/' );
		if ( '' === $attached_file ) {
			return false;
		}

		$suffix = '/' . $attached_file;
		foreach ( get_object_vars( $value ) as $nested ) {
			if ( ! is_string( $nested ) ) {
				continue;
			}
			$parsed = wp_parse_url( $nested );
			$path   = is_array( $parsed ) ? (string) ( $parsed['path'] ?? '' ) : '';
			$path   = ltrim( str_replace( '\\', '/', $path ), '/' );
			if ( $path === $attached_file || ( strlen( $path ) > strlen( $suffix ) && substr( $path, -strlen( $suffix ) ) === $suffix ) ) {
				return true;
			}
		}

		return false;
	}

	private static function cross_env_is_media_attachment_id_key( string $key ): bool {
		return in_array( $key, [ 'imageId', 'mediaId', 'attachmentId', 'backgroundImageId', 'videoId', 'audioId' ], true );
	}

	private static function cross_env_numeric_source_id( $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && preg_match( '/^\d+$/', $value ) ) {
			return absint( $value );
		}
		return 0;
	}

	private static function cross_env_upload_urls_from_markup( string $markup ): array {
		$urls = [];
		if ( ! preg_match_all( '#https?://[^\s"\'<>\\\\)]+#i', $markup, $matches ) ) {
			return [];
		}

		foreach ( $matches[0] as $raw_url ) {
			$url = self::cross_env_sanitize_absolute_url( $raw_url );
			if ( null === $url || null === self::cross_env_upload_path_from_url( $url ) ) {
				continue;
			}
			$urls[ $url ] = $url;
		}

		ksort( $urls, SORT_STRING );
		return array_values( $urls );
	}

	private static function cross_env_source_attachment_payload_from_id( int $attachment_id ): ?array {
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return null;
		}

		$attached_file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$attached_file = ltrim( str_replace( '\\', '/', $attached_file ), '/' );
		$path          = '' !== $attached_file ? $attached_file : null;
		$url           = '' !== $attached_file ? self::cross_env_attachment_url( $attachment_id, $attached_file ) : null;
		$mime          = isset( $post->post_mime_type ) ? (string) $post->post_mime_type : '';

		$row = [
			'id' => $attachment_id,
		];
		if ( null !== $url ) {
			$row['url'] = $url;
		}
		if ( null !== $path ) {
			$row['path'] = $path;
			$row['filename'] = self::cross_env_basename( $path );
		}
		if ( '' !== $mime ) {
			$row['mime'] = $mime;
		}

		return $row;
	}

	private static function cross_env_sanitize_absolute_url( string $raw_url ): ?string {
		$parsed = wp_parse_url( $raw_url );
		if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) $parsed['scheme'] );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return null;
		}

		$port = ! empty( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '';
		$path = isset( $parsed['path'] ) ? (string) $parsed['path'] : '';
		return $scheme . '://' . strtolower( (string) $parsed['host'] ) . $port . $path;
	}

	private static function cross_env_upload_path_from_url( string $url ): ?string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return null;
		}

		$uploads = wp_upload_dir( null, false );
		$baseurl = is_array( $uploads ) && empty( $uploads['error'] ) ? (string) ( $uploads['baseurl'] ?? '' ) : '';
		$base    = '' !== $baseurl ? self::cross_env_url_parts_with_site_origin( $baseurl ) : null;
		$parsed  = self::cross_env_url_parts_with_site_origin( $url );
		if ( ! is_array( $base ) || ! is_array( $parsed ) ) {
			return null;
		}

		$base_scheme = strtolower( (string) ( $base['scheme'] ?? '' ) );
		$url_scheme  = strtolower( (string) ( $parsed['scheme'] ?? '' ) );
		if ( ! in_array( $base_scheme, [ 'http', 'https' ], true ) || ! in_array( $url_scheme, [ 'http', 'https' ], true ) ) {
			return null;
		}

		foreach ( [ 'host', 'port' ] as $part ) {
			if ( strtolower( (string) ( $base[ $part ] ?? '' ) ) !== strtolower( (string) ( $parsed[ $part ] ?? '' ) ) ) {
				return null;
			}
		}

		$base_path = rtrim( (string) ( $base['path'] ?? '' ), '/' );
		$path      = (string) ( $parsed['path'] ?? '' );
		if ( '' !== $base_path && 0 !== strpos( $path, $base_path . '/' ) ) {
			return null;
		}

		$relative = ltrim( substr( $path, strlen( $base_path ) ), '/' );
		return '' !== $relative ? $relative : null;
	}

	private static function cross_env_url_parts_with_site_origin( string $url ): ?array {
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) ) {
			return null;
		}
		if ( ! empty( $parsed['scheme'] ) && ! empty( $parsed['host'] ) ) {
			return $parsed;
		}
		if ( ! function_exists( 'get_site_url' ) ) {
			return null;
		}

		$site = wp_parse_url( (string) get_site_url() );
		if ( ! is_array( $site ) || empty( $site['scheme'] ) || empty( $site['host'] ) ) {
			return null;
		}

		if ( ! empty( $parsed['host'] ) && 0 === strpos( $url, '//' ) ) {
			$parsed['scheme'] = $site['scheme'];
			return $parsed;
		}
		if ( empty( $parsed['host'] ) && 0 === strpos( $url, '/' ) ) {
			$parsed['scheme'] = $site['scheme'];
			$parsed['host']   = $site['host'];
			if ( ! empty( $site['port'] ) ) {
				$parsed['port'] = $site['port'];
			}
			return $parsed;
		}

		return null;
	}

	private static function cross_env_upload_path_from_rooted_path( string $path ): ?string {
		if ( ! function_exists( 'wp_upload_dir' ) || 0 !== strpos( $path, '/' ) ) {
			return null;
		}

		$uploads = wp_upload_dir( null, false );
		$baseurl = is_array( $uploads ) && empty( $uploads['error'] ) ? (string) ( $uploads['baseurl'] ?? '' ) : '';
		$base    = '' !== $baseurl ? wp_parse_url( $baseurl ) : null;
		if ( ! is_array( $base ) ) {
			return null;
		}

		$base_path = rtrim( (string) ( $base['path'] ?? '' ), '/' );
		if ( '' !== $base_path && 0 !== strpos( $path, $base_path . '/' ) ) {
			return null;
		}

		$relative = ltrim( substr( $path, strlen( $base_path ) ), '/' );
		return '' !== $relative ? $relative : null;
	}

	private static function cross_env_list_param( $raw ): array {
		if ( is_array( $raw ) ) {
			return array_values( $raw );
		}
		if ( null === $raw || '' === $raw ) {
			return [];
		}
		if ( is_string( $raw ) ) {
			$trimmed = trim( $raw );
			$decoded = json_decode( $trimmed, true );
			if ( is_array( $decoded ) ) {
				return array_values( $decoded );
			}
			return preg_split( '/[\r\n,]+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		}
		return [ $raw ];
	}

	private static function cross_env_normalize_asset_hints( $raw_assets, $raw_source_ids, $raw_upload_paths = null ): array {
		$source_ids = [];
		foreach ( self::cross_env_list_param( $raw_source_ids ) as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$source_ids[ $id ] = $id;
			}
		}

		$assets = [];
		foreach ( self::cross_env_list_param( $raw_assets ) as $raw ) {
			$hint = trim( (string) $raw );
			if ( '' === $hint ) {
				continue;
			}
			if ( preg_match( '/^\d+$/', $hint ) ) {
				$id = absint( $hint );
				if ( $id > 0 ) {
					$source_ids[ $id ] = $id;
				}
				continue;
			}

			$asset = self::cross_env_normalize_asset_hint( $hint );
			if ( null !== $asset ) {
				$key            = ( $asset['upload_path'] ?? '' ) . '|' . $asset['basename'];
				$assets[ $key ] = $asset;
			}
		}

		foreach ( self::cross_env_list_param( $raw_upload_paths ) as $raw ) {
			$asset = self::cross_env_normalize_exact_upload_path_hint( (string) $raw );
			if ( null !== $asset ) {
				$key            = $asset['upload_path'] . '|' . $asset['basename'];
				$assets[ $key ] = $asset;
			}
		}

		return [
			'assets'     => array_values( $assets ),
			'source_ids' => array_values( $source_ids ),
		];
	}

	private static function cross_env_normalize_asset_hint( string $hint ): ?array {
		$parsed = wp_parse_url( $hint );
		$path   = is_array( $parsed ) && isset( $parsed['path'] ) ? (string) $parsed['path'] : $hint;
		$path   = str_replace( '\\', '/', $path );
		$path   = preg_replace( '/[?#].*$/', '', $path );
		$path   = trim( (string) $path );
		if ( '' === $path ) {
			return null;
		}

		$is_absolute_url = is_array( $parsed ) && ! empty( $parsed['scheme'] ) && ! empty( $parsed['host'] );
		$upload_path    = null;
		if ( $is_absolute_url ) {
			$upload_path = self::cross_env_upload_path_from_url( $hint );
		} elseif ( 0 === strpos( $path, '/' ) ) {
			$upload_path = self::cross_env_upload_path_from_rooted_path( $path );
		} elseif ( 0 !== strpos( $path, '/' ) && false !== strpos( $path, '/' ) ) {
			$upload_path = ltrim( $path, '/' );
		}

		$basename = self::cross_env_basename( $upload_path ?: $path );
		if ( '' === $basename || '.' === $basename || '..' === $basename ) {
			return null;
		}

		return [
			'upload_path' => $upload_path ?: null,
			'basename'    => $basename,
		];
	}

	private static function cross_env_normalize_exact_upload_path_hint( string $hint ): ?array {
		$hint   = trim( $hint );
		$parsed = wp_parse_url( $hint );
		if ( '' === $hint || ( is_array( $parsed ) && ( ! empty( $parsed['scheme'] ) || ! empty( $parsed['host'] ) ) ) ) {
			return null;
		}
		$path = is_array( $parsed ) && isset( $parsed['path'] ) ? (string) $parsed['path'] : $hint;
		$path = ltrim( str_replace( '\\', '/', $path ), '/' );
		if ( '' === $path || preg_match( '#(^|/)\.\.?(/|$)#', $path ) ) {
			return null;
		}
		$basename = self::cross_env_basename( $path );
		if ( '' === $basename || '.' === $basename || '..' === $basename ) {
			return null;
		}
		return [
			'upload_path' => $path,
			'basename'    => $basename,
		];
	}

	private static function cross_env_attachment_candidates( array $asset_hints, array $source_ids ): array {
		$candidates      = [];
		$fallback_hints  = [];
		$exact_basenames = [];
		foreach ( $asset_hints as $hint ) {
			if ( empty( $hint['upload_path'] ) ) {
				$fallback_hints[] = $hint;
				continue;
			}
			$posts = self::cross_env_query_attachments_for_hint( $hint );
			foreach ( $posts as $post ) {
				$row = self::cross_env_attachment_candidate_payload( $post, $hint, $source_ids );
				if ( null === $row ) {
					continue;
				}
				$candidates[ (string) $row['id'] . '|' . (string) ( $row['path'] ?? '' ) ] = $row;
				$exact_basenames[ $hint['basename'] ] = true;
			}
		}

		foreach ( $fallback_hints as $hint ) {
			if ( isset( $exact_basenames[ $hint['basename'] ] ) ) {
				continue;
			}
			$posts = self::cross_env_query_attachments_for_hint( $hint );
			foreach ( $posts as $post ) {
				$row = self::cross_env_attachment_candidate_payload( $post, $hint, $source_ids );
				if ( null === $row ) {
					continue;
				}
				$candidates[ (string) $row['id'] . '|' . (string) ( $row['path'] ?? '' ) ] = $row;
			}
		}
		return array_values( $candidates );
	}

	private static function cross_env_query_attachments_for_hint( array $hint ): array {
		$meta_value = $hint['upload_path'] ?: $hint['basename'];
		if ( '' === (string) $meta_value ) {
			return [];
		}
		return get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 20,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'meta_query'     => [
				[
					'key'     => '_wp_attached_file',
					'value'   => $meta_value,
					'compare' => $hint['upload_path'] ? '=' : 'LIKE',
				],
			],
		] );
	}

	private static function cross_env_attachment_candidate_payload( $post, array $hint, array $source_ids ): ?array {
		$id            = (int) ( $post->ID ?? 0 );
		$attached_file = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( $id <= 0 || '' === $attached_file ) {
			return null;
		}
		$attached_file = ltrim( str_replace( '\\', '/', $attached_file ), '/' );
		$basename      = self::cross_env_basename( $attached_file );
		$path_matches  = ! empty( $hint['upload_path'] ) && $attached_file === ltrim( (string) $hint['upload_path'], '/' );
		$name_matches  = $basename === (string) $hint['basename'];
		if ( ! $path_matches && ! $name_matches ) {
			return null;
		}

		$row = [
			'id'       => $id,
			'target_id'=> $id,
			'path'     => $attached_file,
			'filename' => $basename,
			'proof'    => $path_matches ? 'target_upload_path_exact' : 'target_basename_exact',
		];
		if ( 1 === count( $source_ids ) ) {
			$row['source_attachment_id'] = (int) $source_ids[0];
		}

		$url = self::cross_env_attachment_url( $id, $attached_file );
		if ( null !== $url ) {
			$row['url'] = $url;
		}

		return $row;
	}

	private static function cross_env_attachment_url( int $attachment_id, string $attached_file ): ?string {
		$raw = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $attachment_id ) : '';
		$parsed = $raw ? wp_parse_url( (string) $raw ) : null;
		if ( is_array( $parsed ) && ! empty( $parsed['scheme'] ) && ! empty( $parsed['host'] ) && ! empty( $parsed['path'] ) ) {
			$port = ! empty( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '';
			return strtolower( (string) $parsed['scheme'] ) . '://' . strtolower( (string) $parsed['host'] ) . $port . (string) $parsed['path'];
		}
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return null;
		}
		$uploads = wp_upload_dir( null, false );
		$baseurl = is_array( $uploads ) && empty( $uploads['error'] ) ? (string) ( $uploads['baseurl'] ?? '' ) : '';
		return '' !== $baseurl ? rtrim( $baseurl, '/' ) . '/' . ltrim( $attached_file, '/' ) : null;
	}

	private static function cross_env_attachment_remaps( array $attachments, array $source_ids ): array {
		if ( 1 !== count( $source_ids ) ) {
			return [];
		}
		$target_ids = [];
		foreach ( $attachments as $row ) {
			$id = absint( $row['target_id'] ?? $row['id'] ?? 0 );
			if ( $id > 0 ) {
				$target_ids[ $id ] = $id;
			}
		}
		if ( 1 !== count( $target_ids ) ) {
			return [];
		}
		$proof = $attachments[0]['proof'] ?? 'target_attachments';
		return [
			(string) $source_ids[0] => [
				'target_id' => array_values( $target_ids )[0],
				'proof'     => $proof,
			],
		];
	}

	private static function cross_env_basename( string $path ): string {
		return function_exists( 'wp_basename' ) ? wp_basename( $path ) : basename( $path );
	}

	/**
	 * Update a Theme Builder layout's block markup content.
	 */
	public static function tb_layout_update( $request ) {
		$post_id = absint( $request['id'] );
		$content = $request->get_param( 'content' );
		$backup  = self::rollback_snapshot_requested( $request );
		$post    = get_post( $post_id );

		$valid_types = [ 'et_header_layout', 'et_body_layout', 'et_footer_layout' ];
		if ( ! $post || ! in_array( $post->post_type, $valid_types, true ) ) {
			return self::envelope_error(
				'not_found',
				"Theme Builder layout #{$post_id} not found.",
				'Run diviops_tb_template_list to discover valid header_layout_id / body_layout_id / footer_layout_id values.',
				404
			);
		}
		if ( ! is_string( $content ) ) {
			return self::envelope_error(
				'invalid_input',
				'content must be a string of Divi block markup.',
				null,
				400
			);
		}
		$normalized = self::normalize_divi_full_content_for_write( $content );
		if ( empty( $normalized['ok'] ) ) {
			$error = $normalized['error'] ?? [];
			return self::envelope_error(
				'invalid_input',
				$error['message'] ?? 'content contains unsafe Divi block attribute JSON.',
				$error['hint'] ?? 'Pass valid WordPress block markup. Raw HTML inside Divi block attributes is allowed, but malformed escapes must be corrected before writing.',
				400,
				array_merge( [ 'field' => 'content' ], $error )
			);
		}
		$content = $normalized['content'];
		$shape = self::authoring_shape_preflight( [ $content ], 'tb_layout_update', 'theme_builder_layout:' . $post->post_type, $request, $post_id );
		if ( is_wp_error( $shape ) ) {
			return self::envelope_from_content_write_error( $shape );
		}

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			$extra = $backup ? [ 'backup' => self::rollback_snapshot_plan_for_post_write( $post, 'diviops_tb_layout_update', [ 'tool_operation' => 'tb_layout.update' ] ) ] : [];
			return self::dry_run_response(
				"Would replace post_content on {$post->post_type} #{$post_id} ('{$post->post_title}') (" . strlen( (string) $post->post_content ) . "→" . strlen( $content ) . ' bytes).',
				[ [
					'kind'   => 'tb_layout.update',
					'target' => "{$post->post_type}#{$post_id}",
					'before' => [ 'bytes' => strlen( (string) $post->post_content ) ],
					'after'  => [ 'bytes' => strlen( $content ) ],
				] ],
				[],
				$extra
			);
		}

		$snapshot = null;
		if ( $backup ) {
			$snapshot = self::rollback_snapshot_create_for_post_write( $post, 'diviops_tb_layout_update', [ 'tool_operation' => 'tb_layout.update' ] );
			if ( is_wp_error( $snapshot ) ) {
				return self::envelope_from_wp_error( $snapshot );
			}
		}

		$result = self::update_post_content_with_integrity_guard(
			$post_id,
			$content,
			'tb',
			"Theme Builder layout #{$post_id}",
			(string) $post->post_content
		);

		if ( is_wp_error( $result ) ) {
			if ( null !== $snapshot ) {
				$snapshot = self::rollback_snapshot_mark_from_write_error( $snapshot, $result );
				$result   = self::rollback_snapshot_error_with_summary( $result, $snapshot );
			}
			return self::envelope_from_content_write_error( $result );
		}

		self::invalidate_divi_cache( $post_id );
		if ( null !== $snapshot ) {
			$snapshot = self::rollback_snapshot_mark_post_write( $snapshot, 'write_applied', $content );
		}

		return self::envelope_success( self::rollback_snapshot_add_to_response( [
			'success' => true,
			'id'      => $post_id,
			'type'    => $post->post_type,
			'message' => "Layout '{$post->post_title}' updated.",
		], $snapshot ) );
	}

	/**
	 * Insert one or more serialized Divi blocks into a Theme Builder layout.
	 */
	public static function tb_layout_block_insert( $request ) {
		$post_id         = absint( $request['id'] );
		$content         = $request->get_param( 'content' );
		$position        = sanitize_key( (string) ( $request->get_param( 'position' ) ?? 'append' ) );
		$parent_selector = trim( (string) $request->get_param( 'parent_selector' ) );
		$parent_path     = trim( (string) $request->get_param( 'parent_path' ) );
		$dry_run         = (bool) $request->get_param( 'dry_run' );
		$backup          = self::rollback_snapshot_requested( $request );
		$post            = get_post( $post_id );

		$valid_types = [ 'et_header_layout', 'et_body_layout', 'et_footer_layout' ];
		if ( ! $post || ! in_array( $post->post_type, $valid_types, true ) ) {
			return self::envelope_error(
				'not_found',
				"Theme Builder layout #{$post_id} not found.",
				'Run diviops_tb_template_list to discover valid header_layout_id / body_layout_id / footer_layout_id values.',
				404,
				[ 'layout_id' => $post_id ]
			);
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return self::envelope_error(
				'forbidden',
				"Cannot edit Theme Builder layout #{$post_id}.",
				'Authenticate as a user with edit rights to this layout.',
				403,
				[ 'layout_id' => $post_id ]
			);
		}
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return self::envelope_error(
				'invalid_input',
				'content must be a non-empty string of serialized Divi block markup.',
				null,
				400,
				[ 'field' => 'content', 'received_type' => gettype( $content ) ]
			);
		}
		if ( ! in_array( $position, [ 'append', 'prepend', 'before', 'after' ], true ) ) {
			return self::envelope_error(
				'invalid_input',
				'position must be one of append, prepend, before, or after.',
				null,
				400,
				[ 'field' => 'position', 'received' => $position ]
			);
		}
		if ( ( '' === $parent_selector && '' === $parent_path ) || ( '' !== $parent_selector && '' !== $parent_path ) ) {
			return self::envelope_error(
				'invalid_input',
				'Provide exactly one of parent_selector or parent_path.',
				'Use parent_selector for a unique block-type/adminLabel match, or parent_path for a zero-based parsed-tree path such as "0.1.2".',
				400,
				[ 'fields' => [ 'parent_selector', 'parent_path' ] ]
			);
		}

		$inserted = self::parse_divi_blocks_for_insert( $content, 'content' );
		if ( is_wp_error( $inserted ) ) {
			return self::envelope_from_wp_error( $inserted );
		}

		// Sidecar enrichment records which attr positions held {} in the
		// stored markup, so the pre-serialize restore below can undo core's
		// assoc-decode {} → [] collapse layout-wide (#903; same guard as the
		// module ops in #901).
		$stored_content = (string) $post->post_content;
		$blocks         = self::enrich_blocks_with_empty_object_paths( parse_blocks( $stored_content ), $stored_content );
		if ( '' !== $parent_path ) {
			$target = self::find_tb_block_by_path( $blocks, $parent_path );
		} else {
			$target = self::find_tb_block_by_selector( $blocks, $parent_selector );
		}
		if ( is_wp_error( $target ) ) {
			return self::envelope_from_wp_error( $target );
		}

		$inserted_count = count( $inserted );
		$insert_at      = 'append' === $position
			? count( $target['children'] )
			: ( 'prepend' === $position ? 0 : ( 'before' === $position ? $target['index'] : $target['index'] + 1 ) );
		$scope          = in_array( $position, [ 'append', 'prepend' ], true ) ? 'children' : 'siblings';
		$idempotency_at = 'append' === $position ? max( 0, $insert_at - $inserted_count ) : $insert_at;
		$already_there  = self::tb_insert_sequence_matches( 'children' === $scope ? $target['children'] : $target['siblings'], $inserted, $idempotency_at );
		if ( ! $already_there && in_array( $position, [ 'append', 'prepend' ], true ) ) {
			$already_there = self::tb_stable_labeled_sequence_exists( $target['children'], $inserted )
				|| self::tb_stable_labeled_sequence_exists_deep( $blocks, $inserted );
		}
		$target_summary = [
			'layout_id'       => $post_id,
			'layout_type'     => $post->post_type,
			'layout_title'    => (string) $post->post_title,
			'parent_path'     => $target['path'],
			'parent_selector' => $parent_selector,
			'block_name'      => $target['block_name'],
			'admin_label'     => $target['admin_label'],
		];

		$plan = [
			'kind'   => 'tb_layout.block_insert',
			'target' => "{$post->post_type}#{$post_id}/{$target['path']}",
			'before' => [
				'layout_bytes' => strlen( (string) $post->post_content ),
				'child_count'  => count( $target['children'] ),
			],
			'after'  => [
				'position'             => $position,
				'insertion_scope'      => $scope,
				'inserted_block_count' => $inserted_count,
				'insert_at'            => $insert_at,
				'noop'                 => $already_there,
			],
		];

		if ( $dry_run ) {
			$extra = [ 'target' => $target_summary ];
			if ( $backup ) {
				$extra['backup'] = self::rollback_snapshot_plan_for_post_write( $post, 'diviops_tb_layout_block_insert', [ 'tool_operation' => 'tb_layout.block_insert', 'target' => $target_summary, 'position' => $position, 'inserted_block_count' => $inserted_count ] );
			}
			return self::dry_run_response(
				$already_there
					? "Theme Builder layout #{$post_id} already contains the requested block sequence at {$target['path']} ({$position}) — no-op."
					: "Would insert {$inserted_count} block(s) into Theme Builder layout #{$post_id} at {$target['path']} ({$position}).",
				[ $plan ],
				[],
				$extra
			);
		}

		if ( $already_there ) {
			$snapshot = $backup ? self::rollback_snapshot_noop_for_post_write( $post, 'diviops_tb_layout_block_insert', [ 'tool_operation' => 'tb_layout.block_insert', 'target' => $target_summary, 'position' => $position, 'inserted_block_count' => $inserted_count ] ) : null;
			return self::envelope_success( self::rollback_snapshot_add_to_response( [
				'success'              => true,
				'noop'                 => true,
				'id'                   => $post_id,
				'type'                 => $post->post_type,
				'target'               => $target_summary,
				'position'             => $position,
				'inserted_block_count' => $inserted_count,
				'message'              => 'Requested block sequence already exists at target.',
			], $snapshot ) );
		}

		try {
			self::apply_tb_block_insert( $blocks, $target['path'], $position, $inserted );
		} catch ( \RuntimeException $e ) {
			return self::envelope_error(
				'divi_error',
				$e->getMessage(),
				'Re-save the layout through the Visual Builder to regenerate canonical block placeholders, then retry.',
				500,
				[ 'layout_id' => $post_id ]
			);
		}

		$new_content = serialize_blocks( self::restore_blocks_empty_objects( $blocks ) );
		$normalized  = self::normalize_and_validate_divi_markup_before_write( $new_content, 'final_layout' );
		if ( is_wp_error( $normalized ) ) {
			return self::envelope_from_wp_error( $normalized );
		}
		$new_content = $normalized['content'];
		$current_normalized = self::normalize_divi_full_content_for_write( (string) $post->post_content );
		if ( ! empty( $current_normalized['ok'] ) && $new_content === $current_normalized['content'] ) {
			$snapshot = $backup ? self::rollback_snapshot_noop_for_post_write( $post, 'diviops_tb_layout_block_insert', [ 'tool_operation' => 'tb_layout.block_insert', 'target' => $target_summary, 'position' => $position, 'inserted_block_count' => $inserted_count ] ) : null;
			return self::envelope_success( self::rollback_snapshot_add_to_response( [
				'success'              => true,
				'noop'                 => true,
				'id'                   => $post_id,
				'type'                 => $post->post_type,
				'target'               => $target_summary,
				'position'             => $position,
				'inserted_block_count' => $inserted_count,
				'message'              => 'Requested block sequence already exists at target.',
			], $snapshot ) );
		}

		$snapshot = null;
		if ( $backup ) {
			$snapshot = self::rollback_snapshot_create_for_post_write( $post, 'diviops_tb_layout_block_insert', [ 'tool_operation' => 'tb_layout.block_insert', 'target' => $target_summary, 'position' => $position, 'inserted_block_count' => $inserted_count ] );
			if ( is_wp_error( $snapshot ) ) {
				return self::envelope_from_wp_error( $snapshot );
			}
		}

		$result = self::update_post_content_with_integrity_guard(
			$post_id,
			$new_content,
			'tb',
			"Theme Builder layout #{$post_id} block insert",
			(string) $post->post_content
		);
		if ( is_wp_error( $result ) ) {
			if ( null !== $snapshot ) {
				$snapshot = self::rollback_snapshot_mark_from_write_error( $snapshot, $result );
				$result   = self::rollback_snapshot_error_with_summary( $result, $snapshot );
			}
			return self::envelope_from_content_write_error( $result );
		}

		self::invalidate_divi_cache( $post_id );
		if ( null !== $snapshot ) {
			$snapshot = self::rollback_snapshot_mark_post_write( $snapshot, 'write_applied', $new_content );
		}

		return self::envelope_success( self::rollback_snapshot_add_to_response( [
			'success'              => true,
			'noop'                 => false,
			'id'                   => $post_id,
			'type'                 => $post->post_type,
			'target'               => $target_summary,
			'position'             => $position,
			'inserted_block_count' => $inserted_count,
			'before'               => [ 'bytes' => strlen( (string) $post->post_content ) ],
			'after'                => [ 'bytes' => strlen( $new_content ) ],
			'message'              => "Inserted {$inserted_count} block(s) into layout '{$post->post_title}'.",
		], $snapshot ) );
	}

	/**
	 * Parse insertion markup and reject shapes that serialize unreliably.
	 */
	private static function parse_divi_blocks_for_insert( string $content, string $field ) {
		$normalized = self::normalize_and_validate_divi_markup_before_write( $content, $field );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$content = $normalized['content'];
		// Enrich against the normalized request markup so {} buckets on the
		// INSERTED blocks survive their first write too. Filtering out the
		// empty freeform chunks below doesn't disturb the opener alignment —
		// freeform blocks produce no opener token (#903).
		$blocks = self::enrich_blocks_with_empty_object_paths( parse_blocks( $content ), $content );
		$out    = [];
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				$inner = implode( '', $block['innerContent'] ?? [] );
				if ( '' !== trim( $inner ) ) {
					return new WP_Error(
						'invalid_input',
						'content must contain serialized Divi blocks only; raw freeform HTML is not accepted at the top level.',
						[ 'status' => 400, 'hint' => 'Wrap HTML inside a Divi module such as divi/text before inserting.' ]
					);
				}
				continue;
			}
			if ( 0 !== strpos( (string) $block['blockName'], 'divi/' ) ) {
				return new WP_Error(
					'invalid_input',
					sprintf( "content contains non-Divi block '%s'.", (string) $block['blockName'] ),
					[ 'status' => 400 ]
				);
			}
			$out[] = $block;
		}
		if ( empty( $out ) ) {
			return new WP_Error(
				'invalid_input',
				'content did not parse into any Divi blocks.',
				[ 'status' => 400 ]
			);
		}
		return $out;
	}

	/**
	 * Run the shared full-content serialization guard and validate the
	 * resulting block tree before a Theme Builder write.
	 */
	private static function normalize_and_validate_divi_markup_before_write( string $content, string $field ) {
		$normalized = self::normalize_divi_full_content_for_write( $content );
		if ( empty( $normalized['ok'] ) ) {
			$error = $normalized['error'] ?? [];
			return new WP_Error(
				'invalid_input',
				$error['message'] ?? "{$field} contains unsafe Divi block attribute JSON.",
				[
					'status' => 400,
					'hint'   => $error['hint'] ?? 'Pass valid WordPress block markup. Raw HTML inside Divi block attributes is allowed, but malformed escapes must be corrected before writing.',
					'field'  => $field,
					'error'  => $error,
				]
			);
		}
		$content = $normalized['content'];

		$blocks = parse_blocks( $content );
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				$inner = implode( '', $block['innerContent'] ?? [] );
				if ( false !== strpos( $inner, '<!-- wp:divi/' ) ) {
					return new WP_Error(
						'invalid_input',
						$field . ' contains malformed Divi block comments that failed to parse.',
						[ 'status' => 400 ]
					);
				}
			}
		}

		$registry        = WP_Block_Type_Registry::get_instance();
		$container_types = [ 'divi/section', 'divi/row', 'divi/column', 'divi/group', 'divi/group-carousel', 'divi/dropdown' ];
		$errors          = [];
		$warnings        = [];
		$index           = 0;
		self::validate_block_tree( $blocks, $registry, $container_types, $errors, $warnings, $index );
		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'invalid_input',
				$field . ' failed Divi block validation.',
				[
					'status' => 400,
					'hint'   => 'Fix the reported block validation errors before writing.',
					'errors' => $errors,
				]
			);
		}
		return [
			'content' => $content,
			'changed' => (int) ( $normalized['changed'] ?? 0 ),
		];
	}

	private static function find_tb_block_by_path( array &$blocks, string $path ) {
		if ( ! preg_match( '/^\d+(?:\.\d+)*$/', $path ) ) {
			return new WP_Error(
				'invalid_input',
				'parent_path must be a zero-based dot path such as "0" or "0.1.2".',
				[ 'status' => 400 ]
			);
		}
		$parts    = array_map( 'intval', explode( '.', $path ) );
		$siblings = &$blocks;
		$block    = null;
		foreach ( $parts as $depth => $idx ) {
			if ( ! isset( $siblings[ $idx ] ) || ! is_array( $siblings[ $idx ] ) ) {
				return new WP_Error(
					'not_found',
					"parent_path '{$path}' does not identify a block in this layout.",
					[ 'status' => 404 ]
				);
			}
			$block = &$siblings[ $idx ];
			if ( $depth < count( $parts ) - 1 ) {
				if ( ! isset( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) ) {
					return new WP_Error(
						'not_found',
						"parent_path '{$path}' descends through a block with no children.",
						[ 'status' => 404 ]
					);
				}
				$siblings = &$block['innerBlocks'];
			}
		}
		return self::tb_target_payload( $siblings, $parts[ count( $parts ) - 1 ], $block, $path );
	}

	private static function find_tb_block_by_selector( array &$blocks, string $selector ) {
		$parsed = self::parse_tb_parent_selector( $selector );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$matches = [];
		self::collect_tb_selector_matches( $blocks, $parsed, $matches );
		if ( empty( $matches ) ) {
			return new WP_Error(
				'not_found',
				"parent_selector '{$selector}' did not match any block in this layout.",
				[ 'status' => 404 ]
			);
		}
		if ( count( $matches ) > 1 ) {
			return new WP_Error(
				'invalid_input',
				"parent_selector '{$selector}' matched " . count( $matches ) . ' blocks; use parent_path to choose one explicitly.',
				[ 'status' => 400 ]
			);
		}
		return $matches[0];
	}

	private static function parse_tb_parent_selector( string $selector ) {
		if ( ! preg_match( '/^(divi\/[a-z0-9_-]+)(?:\[adminLabel=(["\'])(.*?)\2\])?$/i', $selector, $m ) ) {
			return new WP_Error(
				'invalid_input',
				'parent_selector must look like divi/group or divi/group[adminLabel="Legal Col"].',
				[ 'status' => 400 ]
			);
		}
		return [
			'block_name'  => $m[1],
			'admin_label' => array_key_exists( 3, $m ) ? $m[3] : null,
		];
	}

	private static function collect_tb_selector_matches( array &$blocks, array $selector, array &$matches, string $prefix = '' ) {
		$count = count( $blocks );
		for ( $i = 0; $i < $count; $i++ ) {
			$block = &$blocks[ $i ];
			if ( empty( $block['blockName'] ) ) {
				unset( $block );
				continue;
			}
			$path  = '' === $prefix ? (string) $i : $prefix . '.' . $i;
			$label = self::tb_block_admin_label( $block );
			if (
				(string) $block['blockName'] === $selector['block_name']
				&& ( null === $selector['admin_label'] || $label === $selector['admin_label'] )
			) {
				$matches[] = self::tb_target_payload( $blocks, $i, $block, $path );
			}
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect_tb_selector_matches( $block['innerBlocks'], $selector, $matches, $path );
			}
			unset( $block );
		}
	}

	private static function tb_target_payload( array &$siblings, int $index, array &$block, string $path ) {
		if ( ! isset( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = [];
		}
		return [
			'path'        => $path,
			'index'       => $index,
			'block_name'  => (string) ( $block['blockName'] ?? '' ),
			'admin_label' => self::tb_block_admin_label( $block ),
			'siblings'    => $siblings,
			'children'    => $block['innerBlocks'],
		];
	}

	private static function tb_block_admin_label( array $block ): string {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
		$label = $attrs['module']['meta']['adminLabel']['desktop']['value'] ?? '';
		if ( '' === $label ) {
			$label = $attrs['meta']['adminLabel']['desktop']['value'] ?? '';
		}
		return is_string( $label ) ? $label : '';
	}

	private static function tb_insert_sequence_matches( array $haystack, array $needle, int $offset ): bool {
		if ( $offset < 0 || $offset + count( $needle ) > count( $haystack ) ) {
			return false;
		}
		foreach ( $needle as $i => $block ) {
			if ( ! self::tb_blocks_equivalent( $haystack[ $offset + $i ], $block ) ) {
				return false;
			}
		}
		return true;
	}

	private static function tb_blocks_equivalent( array $a, array $b ): bool {
		return self::tb_block_normalized_for_equivalence( $a ) == self::tb_block_normalized_for_equivalence( $b );
	}

	/**
	 * Reduce a block (recursively) to the four keys that define insert
	 * equivalence. The previous shape only normalized the TOP level and
	 * compared children raw, which made equivalence sensitive to keys that
	 * don't affect serialization — including the #901 empty-object-path
	 * sidecars, present on stored-tree blocks but not (or with different
	 * paths) on request-parsed blocks. Applying the same key set at every
	 * depth keeps the comparison consistent with its top-level intent.
	 */
	private static function tb_block_normalized_for_equivalence( array $block ): array {
		$normalized = [
			'blockName'   => $block['blockName'] ?? null,
			'attrs'       => $block['attrs'] ?? [],
			'innerBlocks' => [],
			'innerHTML'   => $block['innerHTML'] ?? '',
		];
		foreach ( $block['innerBlocks'] ?? [] as $child ) {
			$normalized['innerBlocks'][] = is_array( $child ) ? self::tb_block_normalized_for_equivalence( $child ) : [];
		}
		return $normalized;
	}

	private static function tb_stable_labeled_sequence_exists( array $haystack, array $needle ): bool {
		if ( empty( $needle ) || count( $needle ) > count( $haystack ) ) {
			return false;
		}
		$needle_signatures = [];
		foreach ( $needle as $block ) {
			$signature = self::tb_stable_block_signature( $block );
			if ( null === $signature ) {
				return false;
			}
			$needle_signatures[] = $signature;
		}
		$limit = count( $haystack ) - count( $needle );
		for ( $offset = 0; $offset <= $limit; $offset++ ) {
			$matched = true;
			foreach ( $needle_signatures as $i => $signature ) {
				if ( self::tb_stable_block_signature( $haystack[ $offset + $i ] ) !== $signature ) {
					$matched = false;
					break;
				}
			}
			if ( $matched ) {
				return true;
			}
		}
		return false;
	}

	private static function tb_stable_labeled_sequence_exists_deep( array $blocks, array $needle ): bool {
		if ( self::tb_stable_labeled_sequence_exists( $blocks, $needle ) ) {
			return true;
		}
		foreach ( $blocks as $block ) {
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && self::tb_stable_labeled_sequence_exists_deep( $block['innerBlocks'], $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private static function tb_stable_block_signature( array $block ): ?string {
		$label = self::tb_block_admin_label( $block );
		if ( '' === $label ) {
			return null;
		}
		return (string) ( $block['blockName'] ?? '' ) . '|' . $label;
	}

	private static function apply_tb_block_insert( array &$blocks, string $path, string $position, array $inserted ): void {
		$parts = array_map( 'intval', explode( '.', $path ) );
		self::apply_tb_block_insert_at_path( $blocks, $parts, $position, $inserted );
	}

	private static function apply_tb_block_insert_at_path( array &$siblings, array $parts, string $position, array $inserted, ?array &$parent_block = null ): void {
		$idx = array_shift( $parts );
		if ( ! isset( $siblings[ $idx ] ) || ! is_array( $siblings[ $idx ] ) ) {
			throw new \RuntimeException( 'Target path disappeared while applying insertion.' );
		}
		$block = &$siblings[ $idx ];
		if ( ! empty( $parts ) ) {
			if ( ! isset( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) ) {
				throw new \RuntimeException( 'Target path descends through a block with no children.' );
			}
			self::apply_tb_block_insert_at_path( $block['innerBlocks'], $parts, $position, $inserted, $block );
			unset( $block );
			return;
		}

		if ( in_array( $position, [ 'append', 'prepend' ], true ) ) {
			if ( ! isset( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = [];
			}
			if ( ! isset( $block['innerContent'] ) || ! is_array( $block['innerContent'] ) ) {
				$block['innerContent'] = [];
			}
			$insert_at = 'append' === $position ? count( $block['innerBlocks'] ) : 0;
			array_splice( $block['innerBlocks'], $insert_at, 0, $inserted );
			self::splice_tb_inner_content_placeholders( $block, $insert_at, count( $inserted ) );
			unset( $block );
			return;
		}

		$insert_at = 'before' === $position ? $idx : $idx + 1;
		array_splice( $siblings, $insert_at, 0, $inserted );
		if ( null !== $parent_block ) {
			self::splice_tb_inner_content_placeholders( $parent_block, $insert_at, count( $inserted ) );
		}
		unset( $block );
	}

	private static function splice_tb_inner_content_placeholders( array &$parent_block, int $insert_at, int $count ): void {
		if ( $count <= 0 ) {
			return;
		}
		if ( ! isset( $parent_block['innerContent'] ) || ! is_array( $parent_block['innerContent'] ) ) {
			$parent_block['innerContent'] = [];
		}

		// Empty containers can parse as one HTML chunk containing both the
		// opening and closing tags. Split that shell so new child placeholders
		// serialize inside the container instead of after its closing tag.
		if ( count( $parent_block['innerBlocks'] ?? [] ) === $count && 1 === count( $parent_block['innerContent'] ) && is_string( $parent_block['innerContent'][0] ) ) {
			$html     = $parent_block['innerContent'][0];
			$first_gt = strpos( $html, '>' );
			$last_lt  = strrpos( $html, '<' );
			if ( false !== $first_gt && false !== $last_lt && $first_gt < $last_lt ) {
				$parent_block['innerContent'] = [
					substr( $html, 0, $first_gt + 1 ),
					substr( $html, $last_lt ),
				];
			}
		}

		$null_seen     = 0;
		$last_null_idx = -1;
		$ic_insert     = null;
		foreach ( $parent_block['innerContent'] as $ic_idx => $ic_item ) {
			if ( null === $ic_item ) {
				if ( $null_seen === $insert_at ) {
					$ic_insert = $ic_idx;
					break;
				}
				$last_null_idx = $ic_idx;
				$null_seen++;
			}
		}
		if ( null === $ic_insert ) {
			$ic_insert = count( $parent_block['innerContent'] ) > 1
				? count( $parent_block['innerContent'] ) - 1
				: ( -1 === $last_null_idx ? count( $parent_block['innerContent'] ) : $last_null_idx + 1 );
		}
		array_splice( $parent_block['innerContent'], $ic_insert, 0, array_fill( 0, $count, null ) );
	}

	/**
	 * Create a complete Theme Builder template with header/footer layouts.
	 */
	public static function tb_template_create( $request ) {
		$title          = sanitize_text_field( $request->get_param( 'title' ) );
		$condition      = sanitize_text_field( $request->get_param( 'condition' ) );
		$header_content = $request->get_param( 'header_content' ) ?? '';
		$footer_content = $request->get_param( 'footer_content' ) ?? '';
		if ( ! is_string( $header_content ) || ! is_string( $footer_content ) ) {
			return self::envelope_error(
				'invalid_input',
				'header_content and footer_content must be strings when provided.',
				null,
				400
			);
		}
		$post_types = [ 'et_theme_builder', 'et_template' ];
		if ( '' !== $header_content ) {
			$post_types[] = 'et_header_layout';
		}
		if ( '' !== $footer_content ) {
			$post_types[] = 'et_footer_layout';
		}
		$permission = self::published_post_types_permission_result( $post_types );
		if ( is_wp_error( $permission ) ) {
			return self::post_type_permission_refusal( $permission );
		}
		$shape = self::authoring_shape_preflight( [ $header_content, $footer_content ], 'tb_template_create', 'theme_builder_template', $request );
		if ( is_wp_error( $shape ) ) {
			return self::envelope_from_content_write_error( $shape );
		}

		// "default" (case-insensitive) and empty string are both treated as
		// the catch-all Default Website Template — the Divi router gates
		// this via `_et_default = '1'` with an empty `_et_use_on`, not via
		// any literal `_et_use_on` value. Storing the literal string
		// "default" in `_et_use_on` is a silent-failure shape: the meta
		// row is non-empty so the unassigned-branch never fires, but the
		// string isn't a recognized location grammar either, so no chrome
		// is ever emitted.
		$is_default_condition = ( '' === $condition || 0 === strcasecmp( $condition, 'default' ) );

		// Resolve the active Theme Builder master before the singleton
		// check — the check is scoped to templates linked from this
		// master's `_et_template` meta. Templates outside the active
		// master (e.g. on library-cloned masters carrying
		// `_et_library_theme_builder`, or templates orphaned from a
		// prior master) cannot shadow the router's default-pick and
		// must NOT block a valid create on the active master.
		$master_id              = self::find_active_master();
		$will_bootstrap_master  = 0 === $master_id;
		$master_template_ids    = self::get_master_template_ids( $master_id );

		// Singleton-default constraint, scoped to the active master.
		// Divi's TB UI treats "Default Website Template" as a singleton;
		// the router's enabled-template query at theme-builder.php
		// resolves by linked-list position BEFORE checking `_et_enabled`,
		// so an existing default linked ahead of the new one would
		// shadow it even when the existing default is disabled. Reject
		// any existing default in the active master's linked list,
		// regardless of `_et_enabled` status — matches the router's
		// position-first ordering and avoids the alternative
		// (re-positioning the new default to front of linked list,
		// which has a bigger blast radius). Caller resolves by trashing
		// the existing default via diviops_tb_template_trash or pinning
		// to a specific condition. Namespaced runtime code per the
		// namespace_gate_vs_runtime_codes convention; rejection rather
		// than silent flip per destructive_idempotent_silent (constraint
		// violation creating new state-truth conflict, not a no-op
		// repeat).
		if ( $is_default_condition && ! empty( $master_template_ids ) ) {
			$existing_default_id = 0;
			foreach ( $master_template_ids as $linked_id ) {
				if ( '1' === get_post_meta( $linked_id, '_et_default', true ) ) {
					$existing_default_id = $linked_id;
					break;
				}
			}
			if ( $existing_default_id > 0 ) {
				return self::envelope_error(
					'tb_template.default_already_exists',
					"A Default Website Template already exists in the active master (et_template #{$existing_default_id}).",
					'Trash the existing default via diviops_tb_template_trash, or pin this template to a specific condition (e.g. "singular:post_type:page:all", "homepage", "404") instead of "default".',
					409,
					[
						'existing_default_id' => $existing_default_id,
						'master_post_id'      => $master_id,
					]
				);
			}
		}

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			$changes = [];
			if ( $will_bootstrap_master ) {
				$changes[] = [
					'kind'   => 'tb_master.create',
					'target' => 'et_theme_builder',
					'after'  => [ 'post_title' => 'Theme Builder', 'post_status' => 'publish' ],
				];
			}
			$changes[] = [
				'kind'   => 'tb_template.create',
				'target' => 'et_template',
				'after'  => [
					'title'              => $title,
					'condition'          => $condition,
					'is_default'         => $is_default_condition,
					'header_bytes'       => strlen( $header_content ),
					'footer_bytes'       => strlen( $footer_content ),
					'will_create_header' => '' !== $header_content,
					'will_create_footer' => '' !== $footer_content,
				],
			];
			if ( '' !== $header_content ) {
				$changes[] = [
					'kind'   => 'tb_layout.create',
					'target' => 'et_header_layout',
					'after'  => [ 'title' => $title . ' Header Layout', 'bytes' => strlen( $header_content ) ],
				];
			}
			if ( '' !== $footer_content ) {
				$changes[] = [
					'kind'   => 'tb_layout.create',
					'target' => 'et_footer_layout',
					'after'  => [ 'title' => $title . ' Footer Layout', 'bytes' => strlen( $footer_content ) ],
				];
			}
			$master_clause = $will_bootstrap_master
				? 'under a freshly-bootstrapped Theme Builder master'
				: "under master #{$master_id}";
			$default_clause = $is_default_condition
				? ' as the Default Website Template'
				: " (condition='{$condition}')";
			return self::dry_run_response(
				"Would create Theme Builder template '{$title}'{$default_clause} {$master_clause}.",
				$changes
			);
		}

		// Auto-bootstrap the master post if missing. Side-effect matches
		// Divi's own lazy-creation at theme-builder.php:404-410
		// (`et_theme_builder_get_theme_builder_post_id`):
		// `post_type=et_theme_builder`, `post_status=publish`,
		// `post_title='Theme Builder'`. Identical shape so a subsequent
		// admin TB-screen visit picks up our master instead of creating
		// a second one.
		if ( $will_bootstrap_master ) {
			$master_id = wp_insert_post( [
				'post_type'   => 'et_theme_builder',
				'post_status' => 'publish',
				'post_title'  => 'Theme Builder',
			], true );
			if ( is_wp_error( $master_id ) ) {
				return self::envelope_from_wp_error( $master_id );
			}
		}

		$header_id = 0;
		$footer_id = 0;

		// Create header layout if content provided.
		if ( '' !== $header_content ) {
			$header_id = wp_insert_post( [
				'post_title'   => $title . ' Header Layout',
				'post_content' => wp_slash( $header_content ),
				'post_type'    => 'et_header_layout',
				'post_status'  => 'publish',
			], true );
			if ( is_wp_error( $header_id ) ) {
				return self::envelope_from_wp_error( $header_id );
			}
			self::initialize_divi_page_meta( $header_id );
		}

		// Create footer layout if content provided.
		if ( '' !== $footer_content ) {
			$footer_id = wp_insert_post( [
				'post_title'   => $title . ' Footer Layout',
				'post_content' => wp_slash( $footer_content ),
				'post_type'    => 'et_footer_layout',
				'post_status'  => 'publish',
			], true );
			if ( is_wp_error( $footer_id ) ) {
				return self::envelope_from_wp_error( $footer_id );
			}
			self::initialize_divi_page_meta( $footer_id );
		}

		// Create template post.
		$template_id = wp_insert_post( [
			'post_title'  => $title,
			'post_type'   => 'et_template',
			'post_status' => 'publish',
		], true );
		if ( is_wp_error( $template_id ) ) {
			return self::envelope_from_wp_error( $template_id );
		}

		// Set template meta. Default-condition templates use the
		// `_et_default = '1'` flag with an empty `_et_use_on` — that is
		// the exact shape Divi's TB router checks at theme-builder.php:1815-1820
		// for the "Default Website Template" catch-all. Specific-condition
		// templates use `_et_use_on` with the condition string.
		update_post_meta( $template_id, '_et_default', $is_default_condition ? '1' : '0' );
		update_post_meta( $template_id, '_et_enabled', '1' );
		update_post_meta( $template_id, '_et_header_layout_id', $header_id );
		update_post_meta( $template_id, '_et_header_layout_enabled', $header_id ? '1' : '0' );
		update_post_meta( $template_id, '_et_body_layout_id', '0' );
		update_post_meta( $template_id, '_et_body_layout_enabled', '1' );
		update_post_meta( $template_id, '_et_footer_layout_id', $footer_id );
		update_post_meta( $template_id, '_et_footer_layout_enabled', $footer_id ? '1' : '0' );
		if ( ! $is_default_condition ) {
			add_post_meta( $template_id, '_et_use_on', $condition );
		}

		// Link to Theme Builder master.
		add_post_meta( $master_id, '_et_template', $template_id );

		$payload = [
			'success'                  => true,
			'template_id'              => $template_id,
			'header_layout_id'         => $header_id,
			'footer_layout_id'         => $footer_id,
			'condition'                => $condition,
			'is_default'               => $is_default_condition,
			'master_post_id'           => $master_id,
			'master_post_bootstrapped' => $will_bootstrap_master,
			'message'                  => "Template '{$title}' created and linked to Theme Builder.",
		];

		return self::envelope_success( $payload );
	}

	/**
	 * Trash (or permanently delete) a Theme Builder template AND its linked
	 * header/body/footer layouts AND scrub the `_et_template` meta refs on
	 * the Theme Builder master post.
	 *
	 * Closes the orphan-meta gap left by `diviops_page_trash` (or wp-cli
	 * `post delete`) on a linked layout, which trashes the layout post but
	 * leaves stale `_et_template = <id>` rows on the master `et_theme_builder`
	 * post. UI deletion via the Divi Theme Builder cleans them; this typed
	 * wrapper brings the programmatic path to parity.
	 *
	 * Idempotency:
	 *   - Default trash mode: a repeat call after a successful cleanup returns
	 *     { ok: true, data: { ..., already_trashed: true } } — repeat-safe
	 *     semantics matching the `page_trash` precedent. The apply loop also
	 *     skips per-step trash calls on layouts/template that are already in
	 *     `trash`, so a partial prior cleanup (e.g. one linked layout already
	 *     trashed manually) still completes and scrubs the master meta.
	 *   - `force=true` is **one-shot**: `wp_delete_post` removes the post from
	 *     the DB, so a repeat call no longer sees the template (the not_found
	 *     gate fires before any idempotency path). Document the irreversibility
	 *     to callers; do not advertise `already_deleted`.
	 */
	public static function tb_template_trash( $request ) {
		$template_id = absint( $request['id'] );
		$force       = (bool) $request->get_param( 'force' );
		$dry_run     = (bool) $request->get_param( 'dry_run' );

		$post = get_post( $template_id );
		if ( ! $post || 'et_template' !== $post->post_type ) {
			return self::envelope_error(
				'not_found',
				"Theme Builder template #{$template_id} not found.",
				'Run diviops_tb_template_list to discover valid template IDs.',
				404,
				[ 'template_id' => $template_id ]
			);
		}
		if ( ! current_user_can( 'delete_post', $template_id ) ) {
			return self::envelope_error(
				'forbidden',
				"Cannot delete Theme Builder template #{$template_id}.",
				'Authenticate as a user with delete rights to this post.',
				403,
				[ 'template_id' => $template_id ]
			);
		}

		// Resolve linked layouts. Meta values may be stored as strings; cast.
		$header_id = (int) get_post_meta( $template_id, '_et_header_layout_id', true );
		$body_id   = (int) get_post_meta( $template_id, '_et_body_layout_id', true );
		$footer_id = (int) get_post_meta( $template_id, '_et_footer_layout_id', true );

		// Filter to layouts that actually exist (defensive against stale meta).
		$linked_layouts = [];
		foreach ( [
			[ 'role' => 'header', 'id' => $header_id, 'type' => 'et_header_layout' ],
			[ 'role' => 'body',   'id' => $body_id,   'type' => 'et_body_layout' ],
			[ 'role' => 'footer', 'id' => $footer_id, 'type' => 'et_footer_layout' ],
		] as $layout ) {
			if ( $layout['id'] <= 0 ) {
				continue;
			}
			$layout_post = get_post( $layout['id'] );
			if ( ! $layout_post ) {
				continue;
			}
			$linked_layouts[] = [
				'role'   => $layout['role'],
				'id'     => $layout['id'],
				'type'   => $layout_post->post_type,
				'title'  => (string) $layout_post->post_title,
				'status' => (string) $layout_post->post_status,
			];
		}

		// Resolve the active Theme Builder master via the shared helper —
		// same discovery shape (`_et_library_theme_builder NOT EXISTS`,
		// `suppress_filters => false`, ordered by date desc) used by
		// tb_template_create. Earlier versions of this route used a
		// loose lookup that could resolve to a library-cloned master on
		// sites carrying one, scrubbing `_et_template` meta on the
		// wrong post.
		$master_id = self::find_active_master();

		// Count master meta refs that name this template (used for plan + idempotent reporting).
		// Re-walk via get_post_meta() rather than helper-filter so the
		// `0` returns and duplicate-row scrubs in the apply loop stay
		// in lockstep with the live row count.
		$master_meta_refs = 0;
		if ( $master_id > 0 ) {
			$existing = get_post_meta( $master_id, '_et_template' );
			if ( is_array( $existing ) ) {
				foreach ( $existing as $ref ) {
					if ( (int) $ref === $template_id ) {
						$master_meta_refs++;
					}
				}
			}
		}

		$current_status  = (string) $post->post_status;
		$already_trashed = ( 'trash' === $current_status );
		// "Already trashed" only counts as already-cleaned-up when there are also
		// no orphan meta refs to scrub. If the template is in trash but the master
		// still carries `_et_template = <id>` refs, the cleanup wasn't atomic and
		// we should run the scrub on apply (idempotency only short-circuits when
		// the prior call actually finished).
		//
		// `force=true` skips the noop branch by design: `wp_delete_post` removed
		// the post on the prior successful call, so we'd never reach this code
		// (the not_found gate fires earlier). If someone calls force=true on an
		// already-trashed template, that's a legitimate "promote trash → delete"
		// transition and we run the apply path normally.
		$already_clean = ! $force && $already_trashed && 0 === $master_meta_refs;

		// Build dry-run plan / apply flow.
		if ( $already_clean ) {
			$end_state = 'trash';
			$action    = 'noop';
		} elseif ( $force ) {
			$end_state = 'deleted';
			$action    = 'delete';
		} else {
			$end_state = 'trash';
			$action    = 'trash';
		}

		$changes = [];
		if ( 'noop' === $action ) {
			$summary = "Theme Builder template #{$template_id} (title: '{$post->post_title}') is already trashed and master meta is clean — no-op.";
		} else {
			$verb     = $force ? 'permanently delete' : 'move to trash';
			$summary  = "Would {$verb} Theme Builder template #{$template_id} (title: '{$post->post_title}'), "
				. count( $linked_layouts ) . ' linked layout(s)'
				. ( $master_id > 0
					? ", and scrub {$master_meta_refs} _et_template meta ref(s) on master post #{$master_id}."
					: ' (no Theme Builder master post found — meta scrub skipped).' );

			$changes[] = [
				'kind'   => $action,
				'target' => "et_template#{$template_id}",
				'before' => [ 'status' => $current_status ],
				'after'  => [ 'status' => $end_state ],
			];
			foreach ( $linked_layouts as $layout ) {
				$changes[] = [
					'kind'   => $action,
					'target' => "{$layout['type']}#{$layout['id']}",
					'before' => [ 'status' => $layout['status'] ],
					'after'  => [ 'status' => $end_state ],
				];
			}
			if ( $master_id > 0 && $master_meta_refs > 0 ) {
				$changes[] = [
					'kind'   => 'meta.scrub',
					'target' => "et_theme_builder#{$master_id}/_et_template",
					'before' => [ 'refs_to_template' => $master_meta_refs ],
					'after'  => [ 'refs_to_template' => 0 ],
				];
			}
		}

		if ( $dry_run ) {
			return self::dry_run_response(
				$summary,
				$changes,
				[],
				[
					'template_id'      => $template_id,
					'title'            => (string) $post->post_title,
					'force'            => $force,
					'linked_layouts'   => array_map(
						static function ( $l ) {
							return [ 'role' => $l['role'], 'id' => $l['id'], 'type' => $l['type'], 'title' => $l['title'] ];
						},
						$linked_layouts
					),
					'master_id'        => $master_id,
					'master_meta_refs' => $master_meta_refs,
					'current_status'   => $current_status,
				]
			);
		}

		// Idempotent silent-success on already-clean targets — matches
		// the `page_trash` repeat-safe contract. Trash mode only: the
		// `force=true` retry path is unreachable here because the post
		// is gone from the DB after a prior force-delete (caught by the
		// not_found gate above).
		if ( 'noop' === $action ) {
			return self::envelope_success( [
				'template_id'              => $template_id,
				'title'                    => (string) $post->post_title,
				'status'                   => $current_status,
				'already_trashed'          => true,
				'linked_layouts'           => [],
				'master_id'                => $master_id,
				'master_meta_refs_removed' => 0,
			] );
		}

		// Apply. Pre-check each target's status before calling the WP destructor:
		// `wp_trash_post()` returns false on an already-trashed post (because it
		// short-circuits when post_status is already 'trash'), and treating that
		// false as a failure would break the partial-cleanup retry contract.
		// Skip-as-success when the target is already at the end-state.
		$layout_results = [];
		foreach ( $linked_layouts as $layout ) {
			$lid           = $layout['id'];
			$layout_status = $layout['status'];

			if ( ! $force && 'trash' === $layout_status ) {
				$layout_results[] = [
					'role'   => $layout['role'],
					'id'     => $lid,
					'type'   => $layout['type'],
					'status' => $end_state,
					'skipped'=> 'already_trashed',
				];
				continue;
			}

			$result  = $force ? wp_delete_post( $lid, true ) : wp_trash_post( $lid );
			$success = $force ? (bool) $result : ( false !== $result && ! is_null( $result ) );
			if ( ! $success || is_wp_error( $result ) ) {
				return self::envelope_error(
					'tb_template.command_failed',
					$force
						? "Failed to permanently delete linked layout #{$lid} ({$layout['type']})."
						: "Failed to trash linked layout #{$lid} ({$layout['type']}).",
					'Check WordPress error logs; resolve the failure and retry — the call is idempotent on already-trashed layouts in default (non-force) mode.',
					500,
					[
						'template_id'   => $template_id,
						'failed_step'   => 'layout_destroy',
						'failed_layout' => [
							'role' => $layout['role'],
							'id'   => $lid,
							'type' => $layout['type'],
						],
						'force'         => $force,
					]
				);
			}
			$layout_results[] = [
				'role'   => $layout['role'],
				'id'     => $lid,
				'type'   => $layout['type'],
				'status' => $end_state,
			];
		}

		// Trash/delete the template post itself. Same pre-check as the layout
		// loop: skip-as-success when already in trash and we're not promoting
		// to a hard delete.
		$template_skipped = false;
		if ( ! $force && 'trash' === $current_status ) {
			$template_skipped = true;
		} else {
			$tpl_result  = $force ? wp_delete_post( $template_id, true ) : wp_trash_post( $template_id );
			$tpl_success = $force ? (bool) $tpl_result : ( false !== $tpl_result && ! is_null( $tpl_result ) );
			if ( ! $tpl_success || is_wp_error( $tpl_result ) ) {
				return self::envelope_error(
					'tb_template.command_failed',
					$force
						? "Failed to permanently delete Theme Builder template #{$template_id}."
						: "Failed to trash Theme Builder template #{$template_id}.",
					'Check WordPress error logs; linked layouts may already be trashed (call is idempotent on retry).',
					500,
					[
						'template_id' => $template_id,
						'failed_step' => 'template_destroy',
						'force'       => $force,
					]
				);
			}
		}

		// Scrub orphan _et_template meta refs on the master post. We already
		// counted matching rows via $master_meta_refs above — if rows existed
		// and the delete returned falsy (or removed nothing), that's a real
		// failure and we surface it rather than silently succeed-with-stale-state.
		$master_meta_removed = 0;
		if ( $master_id > 0 && $master_meta_refs > 0 ) {
			// delete_post_meta with a value matches every row equal to that value.
			$ok = delete_post_meta( $master_id, '_et_template', $template_id );
			if ( $ok ) {
				$master_meta_removed = $master_meta_refs;
			} else {
				return self::envelope_error(
					'tb_template.command_failed',
					"Failed to scrub _et_template meta ref(s) for template #{$template_id} on master post #{$master_id}.",
					'Inspect the et_theme_builder master post meta directly; the linked layouts and template post may already be destroyed at this point — the meta scrub is the residual stale state to clear.',
					500,
					[
						'template_id'      => $template_id,
						'failed_step'      => 'meta_scrub',
						'master_id'        => $master_id,
						'master_meta_refs' => $master_meta_refs,
						'force'            => $force,
					]
				);
			}
		}

		self::invalidate_divi_cache( $template_id );
		foreach ( $linked_layouts as $layout ) {
			self::invalidate_divi_cache( $layout['id'] );
		}

		$success_payload = [
			'template_id'              => $template_id,
			'title'                    => (string) $post->post_title,
			'status'                   => $end_state,
			'linked_layouts'           => $layout_results,
			'master_id'                => $master_id,
			'master_meta_refs_removed' => $master_meta_removed,
		];
		if ( $template_skipped ) {
			$success_payload['template_skipped'] = 'already_trashed';
		}
		return self::envelope_success( $success_payload );
	}
}
