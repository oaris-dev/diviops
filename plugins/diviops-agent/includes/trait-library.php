<?php
/**
 * Trait DiviOps_Agent_Library
 *
 * Library list/get/save.
 *
 * Mixed into DiviOps_Agent via `use` in diviops-agent.php — `self::` calls and
 * class constants resolve as if these methods lived directly on the class.
 *
 * All three handlers route through envelope_success / envelope_error.
 * library_save runs a title-uniqueness check scoped to (layout_type, scope)
 * and returns `conflict` (HTTP 409) with
 * `error.data = { existing_library_id, layout_type, scope }` on collision —
 * mirroring the precedent set by canvas_duplicate.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_Library {

	// ── Library Operations ──────────────────────────────────────────

	/**
	 * Safely get a taxonomy term slug for a post, returning '' on error.
	 */
	private static function get_term_slug( $post_id, $taxonomy ) {
		$terms = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'slugs' ] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}
		return $terms[0];
	}

	/**
	 * Find an existing et_pb_layout post matching ($title, $layout_type, $scope).
	 *
	 * Uniqueness is scoped to (layout_type, scope) so a "Hero" section and a "Hero"
	 * row coexist (different design intent), but a second "Hero" section under the
	 * same scope collides. Returns the colliding post ID, or 0 if none.
	 */
	private static function library_existing_id_by_title( $title, $layout_type, $scope ) {
		$args = [
			'post_type'      => 'et_pb_layout',
			'post_status'    => 'any',
			'title'          => $title,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => [
				'relation' => 'AND',
				[
					'taxonomy' => 'layout_type',
					'field'    => 'slug',
					'terms'    => $layout_type,
				],
				[
					'taxonomy' => 'scope',
					'field'    => 'slug',
					'terms'    => $scope,
				],
			],
		];
		$query = new WP_Query( $args );
		if ( ! empty( $query->posts ) ) {
			return (int) $query->posts[0];
		}
		return 0;
	}

	/**
	 * List Divi Library items.
	 */
	public static function library_list( $request ) {
		$layout_type = sanitize_key( (string) ( $request->get_param( 'layout_type' ) ?? '' ) );
		$scope       = sanitize_key( (string) ( $request->get_param( 'scope' ) ?? '' ) );
		$per_page    = max( 1, min( absint( $request->get_param( 'per_page' ) ?? 50 ), 100 ) );
		$page_num    = self::get_request_page( $request );

		$args = [
			'post_type'      => 'et_pb_layout',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		];

		$tax_query = [];
		if ( '' !== $layout_type ) {
			$tax_query[] = [
				'taxonomy' => 'layout_type',
				'field'    => 'slug',
				'terms'    => sanitize_text_field( $layout_type ),
			];
		}
		if ( '' !== $scope ) {
			$tax_query[] = [
				'taxonomy' => 'scope',
				'field'    => 'slug',
				'terms'    => sanitize_text_field( $scope ),
			];
		}
		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query;
		}

		$page    = self::query_inspectable_post_ids( $args, $per_page, $page_num );
		$library_ids = $page['ids'];
		if ( ! empty( $library_ids ) ) {
			update_object_term_cache( $library_ids, 'post' );
		}
		$results = [];

		foreach ( $library_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$results[] = [
				'id'          => $post->ID,
				'title'       => $post->post_title,
				'layout_type' => self::get_term_slug( $post->ID, 'layout_type' ),
				'scope'       => self::get_term_slug( $post->ID, 'scope' ),
				'modified'    => $post->post_modified,
			];
		}

		return self::envelope_success( [
			'results'     => $results,
			'total'       => $page['total'],
			'total_pages' => $page['total_pages'],
			'truncated'   => $page['truncated'],
			'scanned'     => $page['scanned'],
		] );
	}

	/**
	 * Get a single Divi Library item's content.
	 */
	public static function library_get( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post || 'et_pb_layout' !== $post->post_type ) {
			return self::envelope_error(
				'not_found',
				"Library item #{$post_id} not found.",
				'Use diviops_library_list to find a valid item ID.',
				404
			);
		}
		if ( ! self::can_inspect_post_object( $post ) ) {
			return self::envelope_object_read_forbidden( $post_id, 'library_item' );
		}

		return self::envelope_success( [
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'layout_type' => self::get_term_slug( $post->ID, 'layout_type' ),
			'scope'       => self::get_term_slug( $post->ID, 'scope' ),
			'modified'    => $post->post_modified,
			'content_raw' => $post->post_content,
		] );
	}

	/**
	 * Save block markup to Divi Library.
	 */
	public static function library_save( $request ) {
		$title       = sanitize_text_field( $request->get_param( 'title' ) );
		$content     = $request->get_param( 'content' );
		$layout_type = sanitize_text_field( $request->get_param( 'layout_type' ) );
		$scope       = sanitize_text_field( $request->get_param( 'scope' ) );

		// Validate against allowed values.
		$allowed_types  = [ 'section', 'row', 'module' ];
		$allowed_scopes = [ 'global', 'non_global' ];
		if ( ! is_string( $content ) ) {
			return self::envelope_error(
				'invalid_input',
				'content must be a string of Divi block markup.',
				null,
				400
			);
		}
		if ( ! in_array( $layout_type, $allowed_types, true ) ) {
			return self::envelope_error(
				'invalid_input',
				'layout_type must be one of: ' . implode( ', ', $allowed_types ) . '.',
				null,
				400
			);
		}
		if ( ! in_array( $scope, $allowed_scopes, true ) ) {
			return self::envelope_error(
				'invalid_input',
				'scope must be one of: ' . implode( ', ', $allowed_scopes ) . '.',
				null,
				400
			);
		}
		if ( '' === $title ) {
			return self::envelope_error(
				'invalid_input',
				'title is required and must be a non-empty string.',
				null,
				400
			);
		}

		// Title-uniqueness check, scoped to (layout_type, scope).
		$existing_id = self::library_existing_id_by_title( $title, $layout_type, $scope );
		if ( $existing_id ) {
			return self::envelope_error(
				'conflict',
				"A library item titled '{$title}' already exists under (layout_type={$layout_type}, scope={$scope}) with ID {$existing_id}.",
				'Use a different title or delete the existing library item first.',
				409,
				[
					'existing_library_id' => $existing_id,
					'layout_type'         => $layout_type,
					'scope'               => $scope,
				]
			);
		}

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			return self::dry_run_response(
				"Would create et_pb_layout '{$title}' (layout_type={$layout_type}, scope={$scope}, " . strlen( $content ) . " bytes).",
				[ [
					'kind'   => 'library.save',
					'target' => 'et_pb_layout',
					'after'  => [
						'title'       => $title,
						'layout_type' => $layout_type,
						'scope'       => $scope,
						'bytes'       => strlen( $content ),
					],
				] ]
			);
		}

		$post_id = wp_insert_post( [
			'post_title'   => $title,
			'post_content' => wp_slash( $content ),
			'post_type'    => 'et_pb_layout',
			'post_status'  => 'publish',
		], true );

		if ( is_wp_error( $post_id ) ) {
			return self::envelope_from_wp_error( $post_id );
		}

		// Mark as Divi 5 format.
		update_post_meta( $post_id, '_et_pb_use_divi_5', 'on' );

		// Set layout type and scope taxonomies.
		$type_result  = wp_set_object_terms( $post_id, $layout_type, 'layout_type' );
		$scope_result = wp_set_object_terms( $post_id, $scope, 'scope' );

		if ( is_wp_error( $type_result ) || is_wp_error( $scope_result ) ) {
			wp_delete_post( $post_id, true );
			return self::envelope_error(
				'wp_error',
				'Failed to set library taxonomies.',
				null,
				500
			);
		}

		return self::envelope_success( [
			'success'     => true,
			'id'          => $post_id,
			'title'       => $title,
			'layout_type' => $layout_type,
			'scope'       => $scope,
			'message'     => "Saved to Divi Library as '{$title}'.",
		] );
	}
}
