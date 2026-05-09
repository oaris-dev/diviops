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
 *   - conflict      — canvas_duplicate with explicit `title` colliding with an existing
 *                     canvas under the same parent. Carries
 *                     `error.data = { existing_canvas_id, parent_page_id }` for callers
 *                     that want to retrieve / re-rename the conflicting canvas
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

		$query    = new WP_Query( $query_args );
		$canvases = [];

		foreach ( $query->posts as $post ) {
			$canvases[] = [
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
			'canvases' => $canvases,
			'total'    => $query->found_posts,
		] );
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
