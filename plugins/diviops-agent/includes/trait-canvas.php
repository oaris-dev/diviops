<?php
/**
 * Trait DiviOps_Agent_Canvas
 *
 * Canvas (et_pb_canvas) CRUD.
 *
 * Part of the diviops-agent monolith split (#220). Mixed into
 * DiviOps_Agent via `use` in diviops-agent.php — `self::` calls and
 * class constants resolve as if these methods lived directly on the
 * class.
 *
 * Envelope-adopted. Every route returns:
 *   success: { ok: true,  data: <payload> }
 *   error:   { ok: false, error: { code, message, hint? [, data?] } }
 *
 * Error code mapping for this namespace:
 *   - not_found     — canvas_post_id resolves to no post or non-`et_pb_canvas` post type;
 *                     canvas_create with missing parent_page_id
 *   - invalid_input — canvas_id format violation, non-string content/title,
 *                     append_to_main outside {above, below, ""}, no-op canvas_update payload
 *   - conflict      — canvas_create or canvas_duplicate with `title` colliding with
 *                     an existing canvas under the same parent_page_id. Carries
 *                     `error.data = { existing_canvas_id, parent_page_id, title }` for
 *                     callers that want to retrieve / re-rename the conflicting canvas.
 *                     Mirrors diviops_preset_create's uniqueness contract.
 *   - wp_error      — wp_insert_post / wp_update_post / wp_delete_post returned WP_Error
 *                     (or wp_delete_post returned false)
 *   - capability_missing actually surfaces upstream as the REST framework's
 *     `rest_forbidden` (403); legacy `forbidden` strings are kept as-is and
 *     promoted into the envelope by `wp-client.ts` so callers see `403`
 *     unchanged.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_Canvas {

	// ── Canvas Operations ───────────────────────────────────────────

	/**
	 * Create a canvas (et_pb_canvas post) linked to a parent page.
	 */
	public static function canvas_create( $request ) {
		$permission = self::published_post_types_permission_result( [ 'et_pb_canvas' ] );
		if ( is_wp_error( $permission ) ) {
			return self::post_type_permission_refusal( $permission );
		}

		$title          = sanitize_text_field( $request->get_param( 'title' ) );
		$parent_page_id = absint( $request->get_param( 'parent_page_id' ) );
		$content        = $request->get_param( 'content' );
		$append_to_main = sanitize_key( (string) ( $request->get_param( 'append_to_main' ) ?? '' ) );
		$z_index        = $request->get_param( 'z_index' );

		// Validate canvas_id format if provided, otherwise auto-generate.
		$raw_canvas_id = $request->get_param( 'canvas_id' );
		if ( ! empty( $raw_canvas_id ) ) {
			$canvas_id = sanitize_text_field( $raw_canvas_id );
			if ( ! preg_match( '/^[A-Za-z0-9-]+$/', $canvas_id ) ) {
				return self::envelope_error(
					'invalid_input',
					'canvas_id must contain only letters, numbers, and hyphens.',
					null,
					400
				);
			}
		} else {
			$canvas_id = wp_generate_uuid4();
		}

		$parent = get_post( $parent_page_id );
		if ( ! $parent ) {
			return self::envelope_error(
				'not_found',
				"Parent page #{$parent_page_id} not found.",
				'Run diviops_page_list to discover valid parent_page_id values.',
				404
			);
		}
		if ( ! current_user_can( 'edit_post', $parent_page_id ) ) {
			return new WP_Error( 'forbidden', 'Cannot edit this parent page', [ 'status' => 403 ] );
		}
		if ( null !== $content && ! is_string( $content ) ) {
			return self::envelope_error(
				'invalid_input',
				'content must be a string of Divi block markup.',
				null,
				400
			);
		}

		// Validate append_to_main value.
		if ( $append_to_main && ! in_array( $append_to_main, [ 'above', 'below' ], true ) ) {
			return self::envelope_error(
				'invalid_input',
				'append_to_main must be "above" or "below".',
				null,
				400
			);
		}

		// Wrap content in placeholder if it contains Divi blocks but no placeholder wrapper.
		if ( $content && false !== strpos( $content, '<!-- wp:divi/' ) && false === strpos( $content, '<!-- wp:divi/placeholder' ) ) {
			$content = "<!-- wp:divi/placeholder -->\n{$content}\n<!-- /wp:divi/placeholder -->";
		}

		// Uniqueness probe — mirror preset_create's contract.
		// (parent_page_id, title) is the duplicate-key tuple; matches the
		// idempotent-create semantics across the suite (preset_create,
		// library_save with mode='create', canvas_duplicate on explicit
		// title). Probe runs whether dry_run or not, so dry_run preview
		// still surfaces the conflict before pretending to insert.
		// Reuses the same helper canvas_duplicate uses, so both create-paths
		// share one SQL-backed lookup instead of diverging implementations.
		$existing_id = self::canvas_existing_id_by_title( $title, $parent_page_id );
		if ( $existing_id ) {
			return self::envelope_error(
				'conflict',
				sprintf( "A canvas titled '%s' already exists under parent page #%d.", $title, $parent_page_id ),
				'Use diviops_canvas_update to modify the existing canvas, or pick a different title.',
				409,
				[
					'existing_canvas_id' => $existing_id,
					'parent_page_id'     => $parent_page_id,
					'title'              => $title,
				]
			);
		}

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			return self::dry_run_response(
				"Would create canvas '{$title}' (canvas_id: {$canvas_id}) under parent page #{$parent_page_id}.",
				[ [
					'kind'   => 'canvas.create',
					'target' => "page#{$parent_page_id}",
					'after'  => [
						'title'          => $title,
						'canvas_id'      => $canvas_id,
						'parent_page_id' => $parent_page_id,
						'append_to_main' => $append_to_main ?: null,
						'z_index'        => null !== $z_index ? (int) $z_index : null,
						'bytes'          => is_string( $content ) ? strlen( $content ) : 0,
					],
				] ]
			);
		}

		$post_id = wp_insert_post( [
			'post_title'   => $title,
			'post_content' => wp_slash( $content ),
			'post_status'  => 'publish',
			'post_type'    => 'et_pb_canvas',
		], true );

		if ( is_wp_error( $post_id ) ) {
			return self::envelope_from_wp_error( $post_id );
		}

		update_post_meta( $post_id, '_divi_canvas_id', $canvas_id );
		update_post_meta( $post_id, '_divi_canvas_parent_post_id', $parent_page_id );
		update_post_meta( $post_id, '_divi_canvas_created_at', gmdate( 'c' ) );

		if ( $append_to_main ) {
			update_post_meta( $post_id, '_divi_canvas_append_to_main', $append_to_main );
		}
		if ( null !== $z_index ) {
			update_post_meta( $post_id, '_divi_canvas_z_index', (int) $z_index );
		}

		// Set Divi builder meta.
		update_post_meta( $post_id, '_et_pb_use_builder', 'on' );
		update_post_meta( $post_id, '_et_pb_use_divi_5', 'on' );

		self::invalidate_canvas_parent_cache( $parent_page_id );

		return self::envelope_success( [
			'success'        => true,
			'canvas_post_id' => $post_id,
			'canvas_id'      => $canvas_id,
			'parent_page_id' => $parent_page_id,
			'message'        => "Canvas '{$title}' created and linked to page {$parent_page_id}.",
		] );
	}

	/**
	 * Duplicate a canvas (deep copy of post_content + canvas-specific meta).
	 *
	 * Source canvas untouched. New canvas gets a fresh _divi_canvas_id UUID
	 * (post-meta level identity) and post-level slug. Default copy name is
	 * "<source title> (Copy)" with auto-suffix on collision (Copy 2, Copy 3, …)
	 * so repeat-call ergonomics stay clean. Explicit `title` collisions return
	 * 409 — that's a deliberate naming intent the caller stated, not something
	 * to silently sanitize away.
	 */
	public static function canvas_duplicate( $request ) {
		$permission = self::published_post_types_permission_result( [ 'et_pb_canvas' ] );
		if ( is_wp_error( $permission ) ) {
			return self::post_type_permission_refusal( $permission );
		}

		$source_id = absint( $request['id'] );
		$source    = get_post( $source_id );

		if ( ! $source || 'et_pb_canvas' !== $source->post_type ) {
			return self::envelope_error(
				'not_found',
				"Canvas #{$source_id} not found.",
				'Run diviops_canvas_list to discover valid canvas_post_id values.',
				404
			);
		}
		if ( ! current_user_can( 'edit_post', $source_id ) ) {
			return new WP_Error( 'forbidden', 'Cannot read source canvas.', [ 'status' => 403 ] );
		}

		$parent_page_id = (int) get_post_meta( $source_id, '_divi_canvas_parent_post_id', true );
		if ( $parent_page_id && ! current_user_can( 'edit_post', $parent_page_id ) ) {
			return new WP_Error( 'forbidden', 'Cannot edit canvas parent page.', [ 'status' => 403 ] );
		}

		$dry_run         = (bool) $request->get_param( 'dry_run' );
		$requested_title = $request->get_param( 'title' );
		if ( null !== $requested_title && ! is_string( $requested_title ) ) {
			return self::envelope_error(
				'invalid_input',
				'title must be a string when provided.',
				null,
				400
			);
		}

		$source_title = (string) $source->post_title;
		$title_explicit = ( null !== $requested_title && '' !== $requested_title );

		if ( $title_explicit ) {
			$new_title = sanitize_text_field( $requested_title );
			$existing_id = self::canvas_existing_id_by_title( $new_title, $parent_page_id );
			if ( $existing_id ) {
				return self::envelope_error(
					'conflict',
					"A canvas titled '{$new_title}' already exists under parent page #{$parent_page_id}.",
					'Omit the title parameter to auto-suffix (e.g. "<source title> (Copy 2)"), or pass a unique title.',
					409,
					[
						'existing_canvas_id' => $existing_id,
						'parent_page_id'     => $parent_page_id ?: null,
						'title'              => $new_title,
					]
				);
			}
		} else {
			$new_title = self::canvas_next_copy_title( $source_title, $parent_page_id );
		}

		if ( $dry_run ) {
			return self::dry_run_response(
				"Would duplicate canvas #{$source_id} ('{$source_title}') as '{$new_title}'.",
				[ [
					'kind'   => 'create',
					'target' => 'canvas',
					'after'  => [
						'title'          => $new_title,
						'source_id'      => $source_id,
						'parent_page_id' => $parent_page_id ?: null,
					],
				] ]
			);
		}

		$new_post_id = wp_insert_post( [
			'post_title'   => $new_title,
			'post_content' => wp_slash( (string) $source->post_content ),
			'post_status'  => 'publish',
			'post_type'    => 'et_pb_canvas',
		], true );

		if ( is_wp_error( $new_post_id ) ) {
			return self::envelope_from_wp_error( $new_post_id );
		}

		// Fresh identity at the meta level — never copy _divi_canvas_id.
		$new_canvas_id = wp_generate_uuid4();
		update_post_meta( $new_post_id, '_divi_canvas_id', $new_canvas_id );
		update_post_meta( $new_post_id, '_divi_canvas_created_at', gmdate( 'c' ) );

		// Preserve parent + display meta from source (append_to_main, z_index).
		if ( $parent_page_id ) {
			update_post_meta( $new_post_id, '_divi_canvas_parent_post_id', $parent_page_id );
		}
		$append_to_main = get_post_meta( $source_id, '_divi_canvas_append_to_main', true );
		if ( $append_to_main ) {
			update_post_meta( $new_post_id, '_divi_canvas_append_to_main', $append_to_main );
		}
		$z_index = get_post_meta( $source_id, '_divi_canvas_z_index', true );
		if ( '' !== $z_index && null !== $z_index ) {
			update_post_meta( $new_post_id, '_divi_canvas_z_index', (int) $z_index );
		}

		// Builder flags (canvas_create writes these unconditionally).
		update_post_meta( $new_post_id, '_et_pb_use_builder', 'on' );
		update_post_meta( $new_post_id, '_et_pb_use_divi_5', 'on' );

		self::invalidate_canvas_parent_cache( $parent_page_id );

		$new_post = get_post( $new_post_id );
		return self::envelope_success( [
			'id'             => $new_post_id,
			'title'          => $new_title,
			'slug'           => $new_post ? $new_post->post_name : '',
			'canvas_id'      => $new_canvas_id,
			'parent_page_id' => $parent_page_id ?: null,
			'source_id'      => $source_id,
		] );
	}

	/**
	 * Invalidate a canvas-parent page's caches so Divi picks up the
	 * canvas-set change on next render. Drops the cached
	 * `_divi_dynamic_assets_canvases_used` list and runs the trait's
	 * Divi-cache flush. No-op on zero/missing parent so callers don't
	 * need their own guard. Used by every canvas mutation path
	 * (create/update/delete/duplicate).
	 */
	private static function invalidate_canvas_parent_cache( $parent_page_id ) {
		$parent_page_id = (int) $parent_page_id;
		if ( $parent_page_id <= 0 ) {
			return;
		}
		delete_post_meta( $parent_page_id, '_divi_dynamic_assets_canvases_used' );
		self::invalidate_divi_cache( $parent_page_id );
	}

	/**
	 * Does another canvas under the same parent (or globally if parent is 0)
	 * already use this title? Used for explicit-title collision detection.
	 */
	private static function canvas_title_exists_in_parent( $title, $parent_page_id ) {
		return null !== self::canvas_existing_id_by_title( $title, $parent_page_id );
	}

	/**
	 * Look up an existing canvas's post ID by exact title under the same parent
	 * (or globally if parent is 0). Returns the int post ID or null.
	 *
	 * Used for the `conflict` envelope on `canvas_duplicate` so the error.data
	 * payload can carry `existing_canvas_id` — callers can then choose to
	 * retrieve the colliding canvas, rename, or skip.
	 */
	private static function canvas_existing_id_by_title( $title, $parent_page_id ) {
		$args = [
			'post_type'      => 'et_pb_canvas',
			'post_status'    => 'any',
			'title'          => $title,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];
		if ( $parent_page_id ) {
			$args['meta_query'] = [ [
				'key'   => '_divi_canvas_parent_post_id',
				'value' => (int) $parent_page_id,
				'type'  => 'NUMERIC',
			] ];
		}
		$query = new WP_Query( $args );
		return empty( $query->posts ) ? null : (int) $query->posts[0];
	}

	/**
	 * Compute the next non-colliding "(Copy [N])" title for a duplicate
	 * within the source's parent scope. Strips an existing trailing
	 * "(Copy)"/"(Copy N)" before appending so chains stay clean
	 * (Hero → Hero (Copy) → Hero (Copy 2), not Hero (Copy) (Copy)).
	 */
	private static function canvas_next_copy_title( $source_title, $parent_page_id ) {
		$base = preg_replace( '/\s*\(Copy(?:\s+\d+)?\)\s*$/u', '', (string) $source_title );
		$base = '' === $base ? (string) $source_title : $base;

		$candidate = "{$base} (Copy)";
		if ( ! self::canvas_title_exists_in_parent( $candidate, $parent_page_id ) ) {
			return $candidate;
		}
		// Cap at 100 to keep the loop bounded — far past any realistic count.
		for ( $n = 2; $n <= 100; $n++ ) {
			$candidate = "{$base} (Copy {$n})";
			if ( ! self::canvas_title_exists_in_parent( $candidate, $parent_page_id ) ) {
				return $candidate;
			}
		}
		// Pathological: 100 collisions. Fall back to UUID suffix so the
		// duplicate still succeeds rather than 500ing the caller.
		return $base . ' (Copy ' . substr( wp_generate_uuid4(), 0, 8 ) . ')';
	}

	/**
	 * List canvases, optionally filtered by parent page.
	 */
	public static function canvas_list( $request ) {
		$parent_page_id = $request->get_param( 'parent_page_id' );
		$parent_page_id = null !== $parent_page_id ? absint( $parent_page_id ) : null;
		$per_page       = max( 1, min( 100, absint( $request->get_param( 'per_page' ) ?? 50 ) ) );
		$page_num       = self::get_request_page( $request );

		$query_args = [
			'post_type'      => 'et_pb_canvas',
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		if ( $parent_page_id ) {
			$query_args['meta_query'] = [ [
				'key'   => '_divi_canvas_parent_post_id',
				'value' => (int) $parent_page_id,
				'type'  => 'NUMERIC',
			] ];
		}

		$page     = self::query_inspectable_post_ids( $query_args, $per_page, $page_num );
		$page_ids = $page['ids'];
		if ( ! empty( $page_ids ) ) {
			update_meta_cache( 'post', $page_ids );
		}
		$canvas_rows = [];

		foreach ( $page_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$canvas_rows[] = [
				'canvas_post_id' => $post->ID,
				'title'          => $post->post_title,
				'canvas_id'      => get_post_meta( $post->ID, '_divi_canvas_id', true ),
				'parent_page_id' => (int) get_post_meta( $post->ID, '_divi_canvas_parent_post_id', true ),
				'append_to_main' => get_post_meta( $post->ID, '_divi_canvas_append_to_main', true ) ?: null,
				'z_index'        => get_post_meta( $post->ID, '_divi_canvas_z_index', true ) ?: null,
				'status'         => $post->post_status,
				'modified'       => $post->post_modified,
			];
		}

		return self::envelope_success( [
			'canvases'    => $canvas_rows,
			'total'       => $page['total'],
			'total_pages' => $page['total_pages'],
			'truncated'   => $page['truncated'],
			'scanned'     => $page['scanned'],
		] );
	}

	/**
	 * Read-only audit for off-canvas posts and their reference evidence.
	 *
	 * This intentionally does not delete, cleanup, remap, or mutate cache state.
	 * Ambiguous evidence returns `unknown` so callers do not over-trust cleanup
	 * candidates.
	 */
	public static function canvas_orphan_audit( $request ) {
		$parent_page_id = $request->get_param( 'parent_page_id' );
		$parent_page_id = null !== $parent_page_id ? absint( $parent_page_id ) : null;
		$per_page        = max( 1, min( 100, absint( $request->get_param( 'per_page' ) ?? 100 ) ) );
		$page_num        = self::get_request_page( $request );
		$status_param    = $request->get_param( 'status' );
		$status          = sanitize_key( is_scalar( $status_param ) ? (string) $status_param : 'any' );
		$include_global  = self::canvas_audit_request_bool( $request, 'include_global', true );
		$include_context = self::canvas_audit_request_bool( $request, 'include_context', true );

		$allowed_statuses = [ 'any', 'publish', 'draft', 'pending', 'private', 'future', 'trash' ];
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			return self::envelope_error(
				'invalid_input',
				'status must be one of: any, publish, draft, pending, private, future, trash.',
				null,
				400
			);
		}

		$query_args = [
			'post_type'      => 'et_pb_canvas',
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		];

		if ( $parent_page_id ) {
			$query_args['meta_query'] = [ [
				'key'   => '_divi_canvas_parent_post_id',
				'value' => (int) $parent_page_id,
				'type'  => 'NUMERIC',
			] ];
		}

		$page                 = self::query_inspectable_post_ids( $query_args, $per_page, $page_num );
		if ( ! empty( $page['ids'] ) ) {
			update_meta_cache( 'post', $page['ids'] );
		}
		$auditable_posts      = array_values(
			array_filter( array_map( 'get_post', $page['ids'] ) )
		);
		$canvas_ids           = self::canvas_audit_collect_canvas_ids( $auditable_posts );
		$canvas_post_ids      = self::canvas_audit_collect_post_ids( $auditable_posts );
		$candidate_result     = self::canvas_audit_reference_candidates( $canvas_ids, $canvas_post_ids );
		$candidates           = array_values( array_filter(
			$candidate_result['posts'],
			static function ( $post ) {
				return self::can_inspect_post_object( $post );
			}
		) );
		$candidates_truncated = (bool) $candidate_result['truncated'];
		$candidates_meta      = self::canvas_audit_prefetch_candidate_meta( $candidates );
		self::canvas_audit_prime_parent_post_cache( $auditable_posts );
		$canvases   = [];
		$references = [];
		$unknowns   = [];
		$summary    = [
			'total'         => 0,
			'referenced'    => 0,
			'likely_orphan' => 0,
			'unknown'       => 0,
		];

		foreach ( $auditable_posts as $post ) {
			$row = self::canvas_audit_row( $post, $candidates, $candidates_meta, $include_context, $candidates_truncated );
			if ( ! $include_global && empty( $row['parent_page_id'] ) && empty( $row['parent_context'] ) && empty( $row['append_to_main'] ) ) {
				continue;
			}

			$summary['total']++;
			$summary[ $row['verdict'] ]++;

			foreach ( $row['references'] as $reference ) {
				$references[] = array_merge(
					[
						'canvas_post_id' => $row['canvas_post_id'],
						'canvas_id'      => $row['canvas_id'],
					],
					$reference
				);
			}
			foreach ( $row['unknowns'] as $unknown ) {
				$unknowns[] = array_merge(
					[
						'canvas_post_id' => $row['canvas_post_id'],
						'canvas_id'      => $row['canvas_id'],
					],
					$unknown
				);
			}

			$canvases[] = $row;
		}

		return self::envelope_success( [
			'canvases'   => $canvases,
			'references' => $references,
			'unknowns'   => $unknowns,
			'summary'    => $summary,
			'query'      => [
				'parent_page_id'  => $parent_page_id ?: null,
				'include_global'  => $include_global,
				'include_context' => $include_context,
				'status'          => $status,
				'per_page'        => $per_page,
				'post_scan_truncated' => $page['truncated'],
				'post_scan_scanned'   => $page['scanned'],
				'candidate_scan_truncated' => $candidates_truncated,
			],
		] );
	}

	private static function canvas_audit_request_bool( $request, $key, $default ) {
		$value = $request->get_param( $key );
		if ( null === $value ) {
			return (bool) $default;
		}
		if ( ! is_scalar( $value ) && ! is_bool( $value ) ) {
			return (bool) $default;
		}
		if ( function_exists( 'rest_sanitize_boolean' ) ) {
			return rest_sanitize_boolean( $value );
		}
		return is_bool( $value ) ? $value : in_array( strtolower( (string) $value ), [ '1', 'true', 'yes', 'on' ], true );
	}

	private static function canvas_audit_collect_canvas_ids( array $canvas_posts ) {
		$canvas_ids = [];
		foreach ( $canvas_posts as $post ) {
			$post_id = (int) ( $post->ID ?? 0 );
			if ( $post_id <= 0 ) {
				continue;
			}
			$canvas_id_meta = get_post_meta( $post_id, '_divi_canvas_id', true );
			$canvas_id      = is_scalar( $canvas_id_meta ) ? trim( (string) $canvas_id_meta ) : '';
			if ( '' !== $canvas_id && preg_match( '/^[A-Za-z0-9-]+$/', $canvas_id ) ) {
				$canvas_ids[] = $canvas_id;
			}
		}
		return array_values( array_unique( $canvas_ids ) );
	}

	private static function canvas_audit_collect_post_ids( array $posts ) {
		$post_ids = [];
		foreach ( $posts as $post ) {
			$post_id = (int) ( $post->ID ?? 0 );
			if ( $post_id > 0 ) {
				$post_ids[] = $post_id;
			}
		}
		return array_values( array_unique( $post_ids ) );
	}

	private static function canvas_audit_reference_candidates( array $canvas_ids, array $canvas_post_ids = [] ) {
		if ( empty( $canvas_ids ) ) {
			return [
				'posts'     => [],
				'truncated' => false,
			];
		}

		$post_types = [ 'post', 'page' ];
		if ( function_exists( 'get_post_types' ) ) {
			$public_post_types = get_post_types( [ 'public' => true ], 'names' );
			if ( is_array( $public_post_types ) ) {
				$post_types = array_values( $public_post_types );
			}
		}
		$post_types = array_values( array_unique( array_merge(
			$post_types,
			[ 'et_header_layout', 'et_body_layout', 'et_footer_layout', 'et_pb_layout', 'et_template', 'et_pb_canvas' ]
		) ) );

		global $wpdb;
		if ( $wpdb && isset( $wpdb->posts, $wpdb->postmeta ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_col' ) ) {
			$batch_size            = min( 100, max( 1, (int) apply_filters( 'diviops_canvas_audit_candidate_query_batch_size', 50 ) ) );
			$post_type_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
			$meta_keys             = [ '_divi_off_canvas_data', '_divi_dynamic_assets_canvases_used' ];
			$meta_key_placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
			$excluded_ids          = array_values( array_unique( array_filter( array_map( 'intval', $canvas_post_ids ) ) ) );
			$exclude_clause        = '';
			$exclude_args          = [];
			if ( ! empty( $excluded_ids ) ) {
				$exclude_clause = ' AND p.ID NOT IN (' . implode( ', ', array_fill( 0, count( $excluded_ids ), '%d' ) ) . ')';
				$exclude_args   = $excluded_ids;
			}

			$max_candidates = max( 1, (int) apply_filters( 'diviops_canvas_audit_max_candidates', 500 ) );
			$ids            = [];
			foreach ( array_chunk( $canvas_ids, $batch_size ) as $batch ) {
				$likes = [];
				foreach ( $batch as $canvas_id ) {
					$escaped = method_exists( $wpdb, 'esc_like' )
						? $wpdb->esc_like( $canvas_id )
						: addcslashes( $canvas_id, '_%\\' );
					$likes[] = '%' . $escaped . '%';
				}
				if ( empty( $likes ) ) {
					continue;
				}
				$content_conditions = implode( ' OR ', array_fill( 0, count( $likes ), 'p.post_content LIKE %s' ) );
				$meta_conditions    = implode( ' OR ', array_fill( 0, count( $likes ), 'pm.meta_value LIKE %s' ) );
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names and dynamic IN/LIKE placeholder lists are assembled from fixed plugin-owned arrays and prepared immediately below.
				$sql = "
					SELECT p.ID
					FROM {$wpdb->posts} p
					WHERE p.post_type IN ({$post_type_placeholders})
						AND p.post_status <> 'auto-draft'
						{$exclude_clause}
						AND (
							{$content_conditions}
							OR EXISTS (
								SELECT 1
								FROM {$wpdb->postmeta} pm
								WHERE pm.post_id = p.ID
									AND pm.meta_key IN ({$meta_key_placeholders})
									AND ({$meta_conditions})
							)
					)
					ORDER BY p.post_modified DESC
					LIMIT %d
				";
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$args = array_merge( $post_types, $exclude_args, $likes, $meta_keys, $likes, [ $max_candidates + 1 ] );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query text is assembled above from trusted table names and placeholder fragments, then prepared with the matching values here.
				$prepared_sql = $wpdb->prepare( $sql, $args );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared on the previous line; direct bounded query is needed to audit serialized canvas references across posts and postmeta.
				$ids          = array_merge( $ids, array_map( 'intval', (array) $wpdb->get_col( $prepared_sql ) ) );
				$ids  = array_values( array_unique( $ids ) );
				if ( count( $ids ) > $max_candidates ) {
					break;
				}
			}
			$truncated = count( $ids ) > $max_candidates;
			if ( $truncated ) {
				$ids = array_slice( $ids, 0, $max_candidates );
			}
			if ( ! empty( $ids ) && function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $ids, false, true );
			}
			$posts = [];
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( $post ) {
					$posts[] = $post;
				}
			}
			return [
				'posts'     => $posts,
				'truncated' => $truncated,
			];
		}

		$max_candidates = max( 1, (int) apply_filters( 'diviops_canvas_audit_max_candidates', 500 ) );
		$query = new WP_Query( [
			'post_type'      => $post_types,
			'post_status'    => 'any',
			'posts_per_page' => $max_candidates + 1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );
		$posts     = is_array( $query->posts ) ? $query->posts : [];
		$truncated = count( $posts ) > $max_candidates;
		if ( $truncated ) {
			$posts = array_slice( $posts, 0, $max_candidates );
		}
		return [
			'posts'     => $posts,
			'truncated' => $truncated,
		];
	}

	private static function canvas_audit_prefetch_candidate_meta( array $candidates ) {
		$meta = [];
		$post_ids = [];
		foreach ( $candidates as $candidate ) {
			$post_id = (int) ( $candidate->ID ?? 0 );
			if ( $post_id > 0 ) {
				$post_ids[] = $post_id;
			}
		}
		if ( ! empty( $post_ids ) && function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'post', array_values( array_unique( $post_ids ) ) );
		}

		foreach ( $candidates as $candidate ) {
			$post_id = (int) ( $candidate->ID ?? 0 );
			if ( $post_id <= 0 ) {
				continue;
			}
			$off_canvas_data = get_post_meta( $post_id, '_divi_off_canvas_data', true );
			$off_canvas_decoded = null;
			$off_canvas_json_valid = null;
			if ( is_string( $off_canvas_data ) && '' !== trim( $off_canvas_data ) ) {
				$off_canvas_decoded = json_decode( $off_canvas_data, true );
				$off_canvas_json_valid = JSON_ERROR_NONE === json_last_error();
				if ( ! $off_canvas_json_valid ) {
					$off_canvas_decoded = null;
				}
			}
			$meta[ $post_id ] = [
				'off_canvas_data'       => $off_canvas_data,
				'off_canvas_decoded'    => $off_canvas_decoded,
				'off_canvas_json_valid' => $off_canvas_json_valid,
				'dynamic_assets'        => get_post_meta( $post_id, '_divi_dynamic_assets_canvases_used', true ),
			];
		}
		return $meta;
	}

	private static function canvas_audit_prime_parent_post_cache( array $canvas_posts ) {
		$parent_post_ids = [];
		foreach ( $canvas_posts as $post ) {
			$post_id = (int) ( $post->ID ?? 0 );
			if ( $post_id <= 0 ) {
				continue;
			}
			$parent_id = (int) get_post_meta( $post_id, '_divi_canvas_parent_post_id', true );
			if ( $parent_id > 0 ) {
				$parent_post_ids[] = $parent_id;
			}
		}
		if ( ! empty( $parent_post_ids ) && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_values( array_unique( $parent_post_ids ) ), false, true );
		}
	}

	private static function canvas_audit_row( $post, array $candidates, array $candidates_meta, $include_context, $candidate_scan_truncated = false ) {
		$canvas_post_id = (int) $post->ID;
		$canvas_id_meta = get_post_meta( $canvas_post_id, '_divi_canvas_id', true );
		$canvas_id      = is_scalar( $canvas_id_meta ) ? trim( (string) $canvas_id_meta ) : '';
		$parent_id_meta = get_post_meta( $canvas_post_id, '_divi_canvas_parent_post_id', true );
		$parent_page_id = is_scalar( $parent_id_meta ) ? (int) $parent_id_meta : 0;
		$context_meta   = get_post_meta( $canvas_post_id, '_divi_canvas_parent_context', true );
		$parent_context = is_scalar( $context_meta ) ? trim( (string) $context_meta ) : '';
		$append_meta    = get_post_meta( $canvas_post_id, '_divi_canvas_append_to_main', true );
		$append_to_main = is_scalar( $append_meta ) ? trim( (string) $append_meta ) : '';
		$z_index        = get_post_meta( $canvas_post_id, '_divi_canvas_z_index', true );
		$content        = (string) ( $post->post_content ?? '' );
		$references     = [];
		$unknowns       = [];
		$reasons        = [];

		if ( '' === $canvas_id ) {
			$unknowns[] = [
				'kind'   => 'missing_canvas_id',
				'reason' => '_divi_canvas_id is empty.',
			];
			$reasons[] = 'missing_canvas_id';
		} elseif ( ! preg_match( '/^[A-Za-z0-9-]+$/', $canvas_id ) ) {
			$unknowns[] = [
				'kind'   => 'malformed_canvas_id',
				'value'  => $canvas_id,
				'reason' => '_divi_canvas_id contains unsupported characters.',
			];
			$reasons[] = 'malformed_canvas_id';
		}

		if ( $parent_page_id > 0 ) {
			$parent = get_post( $parent_page_id );
			if ( $parent && self::can_inspect_post_object( $parent ) ) {
				$references[] = [
					'kind'           => 'parent_post_meta',
					'strength'       => 'authoritative',
					'source_post_id' => $parent_page_id,
					'source_type'    => $parent->post_type ?? null,
					'evidence'       => '_divi_canvas_parent_post_id',
				];
				$reasons[] = 'parent_post_meta';
			} else {
				$unknowns[] = [
					'kind'           => 'stale_parent_post_id',
					'source_post_id' => $parent_page_id,
					'evidence'       => '_divi_canvas_parent_post_id',
					'reason'         => 'Canvas points at a parent post that is not readable.',
				];
				$reasons[] = 'stale_parent_post_id';
			}
		}

		if ( '' !== $parent_context ) {
			if ( $include_context ) {
				$references[] = [
					'kind'     => 'parent_context_meta',
					'strength' => 'authoritative',
					'context'  => $parent_context,
					'evidence' => '_divi_canvas_parent_context',
				];
				$reasons[] = 'parent_context_meta';
			} else {
				$unknowns[] = [
					'kind'    => 'context_skipped',
					'context' => $parent_context,
					'reason'  => 'include_context=false; context meta was not used for the verdict.',
				];
				$reasons[] = 'context_skipped';
			}
		}

		if ( '' !== $append_to_main ) {
			if ( in_array( $append_to_main, [ 'above', 'below' ], true ) ) {
				$references[] = [
					'kind'     => 'append_to_main_meta',
					'strength' => 'authoritative',
					'value'    => $append_to_main,
					'evidence' => '_divi_canvas_append_to_main',
				];
				$reasons[] = 'append_to_main_meta';
			} else {
				$unknowns[] = [
					'kind'     => 'malformed_append_to_main',
					'value'    => $append_to_main,
					'evidence' => '_divi_canvas_append_to_main',
					'reason'   => 'append_to_main must be above or below.',
				];
				$reasons[] = 'malformed_append_to_main';
			}
		}

		if ( '' !== $canvas_id && preg_match( '/^[A-Za-z0-9-]+$/', $canvas_id ) ) {
			self::canvas_audit_scan_candidates( $canvas_id, $canvas_post_id, $candidates, $candidates_meta, $references, $unknowns, $reasons );
			if ( false !== strpos( $content, 'canvasId' ) || false !== strpos( $content, 'canvas-portal' ) ) {
				$unknowns[] = [
					'kind'   => 'nested_canvas_reference',
					'reason' => 'Canvas content appears to contain another canvas reference; nested canvas ownership is ambiguous.',
				];
				$reasons[] = 'nested_canvas_reference';
			}
		}

		$has_authoritative = false;
		$has_advisory      = false;
		foreach ( $references as $reference ) {
			if ( 'authoritative' === ( $reference['strength'] ?? null ) ) {
				$has_authoritative = true;
			} else {
				$has_advisory = true;
			}
		}

		if ( ! empty( $unknowns ) ) {
			$verdict    = 'unknown';
			$confidence = 0;
		} elseif ( $has_authoritative ) {
			$verdict    = 'referenced';
			$confidence = 100;
		} elseif ( $has_advisory ) {
			$verdict     = 'unknown';
			$confidence  = 50;
			$reasons[]   = 'only_advisory_references';
			$unknowns[]  = [
				'kind'   => 'only_advisory_references',
				'reason' => 'Only cache-derived or weak references were found.',
			];
		} else {
			if ( $candidate_scan_truncated ) {
				$verdict    = 'unknown';
				$confidence = 0;
				$reasons[]  = 'candidate_scan_truncated';
				$unknowns[] = [
					'kind'   => 'candidate_scan_truncated',
					'reason' => 'Reference candidate scan reached the configured cap; this canvas cannot be safely classified as orphan.',
				];
			} else {
				$verdict    = 'likely_orphan';
				$confidence = 80;
				$reasons[]  = 'no_reference_evidence';
			}
		}

		return [
			'canvas_post_id'   => $canvas_post_id,
			'canvas_id'        => $canvas_id ?: null,
			'title'            => (string) ( $post->post_title ?? '' ),
			'status'           => (string) ( $post->post_status ?? '' ),
			'modified'         => (string) ( $post->post_modified ?? '' ),
			'parent_page_id'   => $parent_page_id ?: null,
			'parent_context'   => $parent_context ?: null,
			'append_to_main'   => $append_to_main ?: null,
			'z_index'          => is_scalar( $z_index ) && '' !== (string) $z_index ? (int) $z_index : null,
			'content_checksum' => hash( 'sha256', $content ),
			'verdict'          => $verdict,
			'confidence'       => $confidence,
			'reasons'          => array_values( array_unique( $reasons ) ),
			'references'       => $references,
			'unknowns'         => $unknowns,
		];
	}

	private static function canvas_audit_scan_candidates( $canvas_id, $canvas_post_id, array $candidates, array $candidates_meta, array &$references, array &$unknowns, array &$reasons ) {
		foreach ( $candidates as $candidate ) {
			$source_post_id = (int) ( $candidate->ID ?? 0 );
			if ( $source_post_id <= 0 || $source_post_id === $canvas_post_id ) {
				continue;
			}
			$source_type = (string) ( $candidate->post_type ?? '' );
			$content     = (string) ( $candidate->post_content ?? '' );

			if ( self::canvas_audit_content_contains_id( $content, $canvas_id ) ) {
				if ( 'et_pb_canvas' === $source_type ) {
					if ( $source_post_id !== $canvas_post_id ) {
						$unknowns[] = [
							'kind'           => 'nested_canvas_reference',
							'source_post_id' => $source_post_id,
							'source_type'    => $source_type,
							'reason'         => 'Another canvas content references this canvas; nested canvas ownership is ambiguous.',
						];
						$reasons[] = 'nested_canvas_reference';
					}
					continue;
				}

				$is_strong = false !== strpos( $content, 'divi/canvas-portal' )
					|| false !== strpos( $content, 'canvasId' )
					|| false !== strpos( $content, 'canvas_id' );
				$references[] = [
					'kind'           => $is_strong ? 'stored_canvas_reference' : 'stored_content_uuid_mention',
					'strength'       => $is_strong ? 'authoritative' : 'advisory',
					'source_post_id' => $source_post_id,
					'source_type'    => $source_type,
					'evidence'       => $is_strong ? 'post_content_canvas_reference' : 'post_content_uuid_mention',
				];
				$reasons[] = $is_strong ? 'stored_canvas_reference' : 'stored_content_uuid_mention';
			}

			$meta_entry = $candidates_meta[ $source_post_id ] ?? [];
			if ( self::canvas_audit_off_canvas_data_contains( $meta_entry, $canvas_id ) ) {
				if ( false === ( $meta_entry['off_canvas_json_valid'] ?? null ) ) {
					$unknowns[] = [
						'kind'           => 'malformed_off_canvas_data',
						'source_post_id' => $source_post_id,
						'source_type'    => $source_type,
						'evidence'       => '_divi_off_canvas_data',
						'reason'         => '_divi_off_canvas_data mentions this canvas but is not valid JSON.',
					];
					$reasons[] = 'malformed_off_canvas_data';
				} else {
					$references[] = [
						'kind'           => 'off_canvas_data_meta',
						'strength'       => 'authoritative',
						'source_post_id' => $source_post_id,
						'source_type'    => $source_type,
						'evidence'       => '_divi_off_canvas_data',
					];
					$reasons[] = 'off_canvas_data_meta';
				}
			}

				$dynamic_assets_canvases = $meta_entry['dynamic_assets'] ?? '';
			if ( self::canvas_audit_value_contains( $dynamic_assets_canvases, $canvas_id, true ) ) {
				$references[] = [
					'kind'           => 'dynamic_assets_canvases_used',
					'strength'       => 'advisory',
					'source_post_id' => $source_post_id,
					'source_type'    => $source_type,
					'evidence'       => '_divi_dynamic_assets_canvases_used',
				];
				$reasons[] = 'dynamic_assets_canvases_used';
			}
		}
	}

	private static function canvas_audit_content_contains_id( $content, $canvas_id ) {
		if ( '' === $canvas_id ) {
			return false;
		}
		$content = (string) $content;
		$canvas_id = (string) $canvas_id;
		if ( false === strpos( $content, $canvas_id ) ) {
			return false;
		}
		return 1 === preg_match( '/(?<![A-Za-z0-9-])' . preg_quote( $canvas_id, '/' ) . '(?![A-Za-z0-9-])/', $content );
	}

	private static function canvas_audit_off_canvas_data_contains( array $meta_entry, $needle ) {
		if ( '' === $needle ) {
			return false;
		}
		$value = $meta_entry['off_canvas_data'] ?? '';
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			if ( true === ( $meta_entry['off_canvas_json_valid'] ?? null ) ) {
				return self::canvas_audit_value_contains( $meta_entry['off_canvas_decoded'] ?? null, $needle, true );
			}
			return self::canvas_audit_content_contains_id( $value, $needle );
		}
		return self::canvas_audit_value_contains( $value, $needle, true );
	}

	private static function canvas_audit_value_contains( $value, $needle, $exact = false ) {
		if ( '' === $needle ) {
			return false;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $child ) {
				if ( self::canvas_audit_value_contains( $child, $needle, $exact ) ) {
					return true;
				}
			}
			return false;
		}
		if ( $exact ) {
			return ! is_bool( $value ) && (string) $value === (string) $needle;
		}
		return ! is_bool( $value ) && false !== strpos( (string) $value, (string) $needle );
	}

	/**
	 * Get a canvas's content and metadata.
	 */
	public static function canvas_get( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post || 'et_pb_canvas' !== $post->post_type ) {
			return self::envelope_error(
				'not_found',
				"Canvas #{$post_id} not found.",
				'Run diviops_canvas_list to discover valid canvas_post_id values.',
				404
			);
		}
		if ( ! self::can_inspect_post_object( $post ) ) {
			return self::envelope_object_read_forbidden( $post_id, 'canvas' );
		}

		return self::envelope_success( [
			'canvas_post_id' => $post->ID,
			'title'          => $post->post_title,
			'canvas_id'      => get_post_meta( $post->ID, '_divi_canvas_id', true ),
			'parent_page_id' => (int) get_post_meta( $post->ID, '_divi_canvas_parent_post_id', true ),
			'append_to_main' => get_post_meta( $post->ID, '_divi_canvas_append_to_main', true ) ?: null,
			'z_index'        => get_post_meta( $post->ID, '_divi_canvas_z_index', true ) ?: null,
			'content'        => $post->post_content,
			'status'         => $post->post_status,
			'modified'       => $post->post_modified,
		] );
	}

	/**
	 * Update a canvas's content and/or metadata.
	 */
	public static function canvas_update( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post || 'et_pb_canvas' !== $post->post_type ) {
			return self::envelope_error(
				'not_found',
				"Canvas #{$post_id} not found.",
				'Run diviops_canvas_list to discover valid canvas_post_id values.',
				404
			);
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Cannot edit this canvas', [ 'status' => 403 ] );
		}

		$update_args = [ 'ID' => $post_id ];
		$content        = $request->get_param( 'content' );
		$title          = $request->get_param( 'title' );
		$append_to_main = $request->get_param( 'append_to_main' );
		$z_index        = $request->get_param( 'z_index' );

		// Reject no-op payloads. The handler structure independently null-checks
		// each field, so a payload with no actionable params would silently
		// return success without touching the canvas. Catch the mistake at the
		// boundary instead.
		if ( null === $content && null === $title && null === $append_to_main && null === $z_index ) {
			return self::envelope_error(
				'invalid_input',
				'canvas_update requires at least one of: content, title, append_to_main, z_index.',
				'For rename-only edits, pass `{title}`. For metadata-only edits, pass `{append_to_main}` and/or `{z_index}` without `content`.',
				400
			);
		}

		if ( null !== $content && ! is_string( $content ) ) {
			return self::envelope_error(
				'invalid_input',
				'content must be a string of Divi block markup.',
				null,
				400
			);
		}
		if ( null !== $title && ! is_scalar( $title ) ) {
			return self::envelope_error(
				'invalid_input',
				'title must be a string when provided.',
				null,
				400
			);
		}

		if ( null !== $content ) {
			// Wrap content in placeholder if needed (same logic as canvas_create).
			if ( $content && false !== strpos( $content, '<!-- wp:divi/' ) && false === strpos( $content, '<!-- wp:divi/placeholder' ) ) {
				$content = "<!-- wp:divi/placeholder -->\n{$content}\n<!-- /wp:divi/placeholder -->";
			}
			$update_args['post_content'] = wp_slash( $content );
		}
		if ( null !== $title ) {
			$update_args['post_title'] = sanitize_text_field( $title );
		}

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			$fields = [];
			if ( null !== $content ) {
				$fields['content_bytes'] = strlen( (string) $content );
			}
			if ( null !== $title ) {
				$fields['title'] = sanitize_text_field( $title );
			}
			if ( null !== $append_to_main ) {
				$fields['append_to_main'] = sanitize_key( (string) $append_to_main );
			}
			if ( null !== $z_index ) {
				$fields['z_index'] = (int) $z_index;
			}
			return self::dry_run_response(
				"Would update canvas #{$post_id} ('{$post->post_title}') — fields: " . implode( ', ', array_keys( $fields ) ) . '.',
				[ [
					'kind'   => 'canvas.update',
					'target' => "canvas#{$post_id}",
					'after'  => $fields,
				] ]
			);
		}

		if ( count( $update_args ) > 1 ) {
			$result = wp_update_post( $update_args, true );
			if ( is_wp_error( $result ) ) {
				return self::envelope_from_wp_error( $result );
			}
		}

		if ( null !== $append_to_main ) {
			$append_to_main = sanitize_key( (string) $append_to_main );
			if ( '' === $append_to_main ) {
				delete_post_meta( $post_id, '_divi_canvas_append_to_main' );
			} elseif ( in_array( $append_to_main, [ 'above', 'below' ], true ) ) {
				update_post_meta( $post_id, '_divi_canvas_append_to_main', $append_to_main );
			} else {
				return self::envelope_error(
					'invalid_input',
					'append_to_main must be "above", "below", or "" to clear.',
					null,
					400
				);
			}
		}

		if ( null !== $z_index ) {
			update_post_meta( $post_id, '_divi_canvas_z_index', (int) $z_index );
		}

		$parent_page_id = (int) get_post_meta( $post_id, '_divi_canvas_parent_post_id', true );
		self::invalidate_canvas_parent_cache( $parent_page_id );

		return self::envelope_success( [
			'success'        => true,
			'canvas_post_id' => $post_id,
			'message'        => 'Canvas updated successfully.',
		] );
	}

	/**
	 * Delete a canvas.
	 */
	public static function canvas_delete( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post || 'et_pb_canvas' !== $post->post_type ) {
			return self::envelope_error(
				'not_found',
				"Canvas #{$post_id} not found.",
				'Run diviops_canvas_list to discover valid canvas_post_id values.',
				404
			);
		}
		if ( ! current_user_can( 'delete_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Cannot delete this canvas', [ 'status' => 403 ] );
		}

		$parent_page_id = (int) get_post_meta( $post_id, '_divi_canvas_parent_post_id', true );

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			return self::dry_run_response(
				"Would permanently delete canvas #{$post_id} ('{$post->post_title}', parent page #{$parent_page_id}).",
				[ [
					'kind'   => 'canvas.delete',
					'target' => "canvas#{$post_id}",
					'before' => [
						'title'          => $post->post_title,
						'parent_page_id' => $parent_page_id,
					],
				] ]
			);
		}

		$deleted = wp_delete_post( $post_id, true );
		if ( ! $deleted ) {
			return self::envelope_error(
				'wp_error',
				'Failed to delete canvas',
				null,
				500
			);
		}

		self::invalidate_canvas_parent_cache( $parent_page_id );

		return self::envelope_success( [
			'success'                => true,
			'deleted_canvas_post_id' => $post_id,
			'parent_page_id'         => $parent_page_id,
			'message'                => 'Canvas deleted.',
		] );
	}
}
