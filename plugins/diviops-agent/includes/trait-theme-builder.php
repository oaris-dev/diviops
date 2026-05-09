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
 *   - not_found     — layout_id resolves to no post or to a non-TB-layout post type
 *   - invalid_input — content / header_content / footer_content not a string
 *   - wp_error      — Theme Builder master post missing, or wp_insert_post / wp_update_post returned WP_Error
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_ThemeBuilder {

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

		return self::envelope_success( [
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'type'        => $post->post_type,
			'content_raw' => $post->post_content,
		] );
	}

	/**
	 * Update a Theme Builder layout's block markup content.
	 */
	public static function tb_layout_update( $request ) {
		$post_id = absint( $request['id'] );
		$content = $request->get_param( 'content' );
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

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			return self::dry_run_response(
				"Would replace post_content on {$post->post_type} #{$post_id} ('{$post->post_title}') (" . strlen( (string) $post->post_content ) . "→" . strlen( $content ) . ' bytes).',
				[ [
					'kind'   => 'tb_layout.update',
					'target' => "{$post->post_type}#{$post_id}",
					'before' => [ 'bytes' => strlen( (string) $post->post_content ) ],
					'after'  => [ 'bytes' => strlen( $content ) ],
				] ]
			);
		}

		$result = wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $content ),
		], true );

		if ( is_wp_error( $result ) ) {
			return self::envelope_from_wp_error( $result );
		}

		self::invalidate_divi_cache( $post_id );

		return self::envelope_success( [
			'success' => true,
			'id'      => $post_id,
			'type'    => $post->post_type,
			'message' => "Layout '{$post->post_title}' updated.",
		] );
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

		// Find the Theme Builder master post.
		$master = get_posts( [
			'post_type'      => 'et_theme_builder',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
		] );
		if ( empty( $master ) ) {
			return self::envelope_error(
				'wp_error',
				'Theme Builder master post not found.',
				'Open the Divi Theme Builder in WP admin once to initialize the master et_theme_builder post.',
				500
			);
		}
		$master_id = $master[0]->ID;

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			$changes = [ [
				'kind'   => 'tb_template.create',
				'target' => 'et_template',
				'after'  => [
					'title'              => $title,
					'condition'          => $condition,
					'header_bytes'       => strlen( $header_content ),
					'footer_bytes'       => strlen( $footer_content ),
					'will_create_header' => '' !== $header_content,
					'will_create_footer' => '' !== $footer_content,
				],
			] ];
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
			return self::dry_run_response(
				"Would create Theme Builder template '{$title}' (condition='{$condition}') under master #{$master_id}.",
				$changes
			);
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

		// Set template meta.
		update_post_meta( $template_id, '_et_default', '0' );
		update_post_meta( $template_id, '_et_enabled', '1' );
		update_post_meta( $template_id, '_et_header_layout_id', $header_id );
		update_post_meta( $template_id, '_et_header_layout_enabled', $header_id ? '1' : '0' );
		update_post_meta( $template_id, '_et_body_layout_id', '0' );
		update_post_meta( $template_id, '_et_body_layout_enabled', '1' );
		update_post_meta( $template_id, '_et_footer_layout_id', $footer_id );
		update_post_meta( $template_id, '_et_footer_layout_enabled', $footer_id ? '1' : '0' );
		add_post_meta( $template_id, '_et_use_on', $condition );

		// Link to Theme Builder master.
		add_post_meta( $master_id, '_et_template', $template_id );

		return self::envelope_success( [
			'success'          => true,
			'template_id'      => $template_id,
			'header_layout_id' => $header_id,
			'footer_layout_id' => $footer_id,
			'condition'        => $condition,
			'message'          => "Template '{$title}' created and linked to Theme Builder.",
		] );
	}
}
