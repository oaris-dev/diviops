<?php
/**
 * Trait DiviOps_Agent_Page
 *
 * Page CRUD, section ops, module mutation/move/lock/clone, block helpers.
 *
 * Part of the diviops-agent monolith split (#220). Mixed into
 * DiviOps_Agent via `use` in diviops-agent.php — `self::` calls and
 * class constants resolve as if these methods lived directly on the
 * class.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_Page {

	// ── Callbacks ────────────────────────────────────────────────────

	/**
	 * List all pages/posts that use the Divi Builder.
	 */
	public static function page_list( $request ) {
		$post_type = sanitize_key( (string) ( $request->get_param( 'post_type' ) ?? 'page' ) );
		$per_page  = min( absint( $request->get_param( 'per_page' ) ?? 50 ), 100 );
		$page_num  = max( absint( $request->get_param( 'page' ) ?? 1 ), 1 );
		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			$post_type = 'page';
		}

		$query = new WP_Query( [
			'post_type'      => $post_type,
			'post_status'    => [ 'publish', 'draft', 'private' ],
			'posts_per_page' => $per_page,
			'paged'          => $page_num,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		] );

		$results = [];
		foreach ( $query->posts as $post ) {
			$results[] = [
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'status'   => $post->post_status,
				'url'      => get_permalink( $post->ID ),
				'modified' => $post->post_modified,
				'has_divi' => self::post_uses_divi( $post ),
			];
		}

		return self::envelope_success( [
			'results'     => $results,
			'total'       => $query->found_posts,
			'total_pages' => $query->max_num_pages,
		] );
	}

	/**
	 * Get a single page with its metadata.
	 */
	public static function page_get( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return self::envelope_error(
				'not_found',
				"Page #{$post_id} not found.",
				'Verify the page id via diviops_page_list.',
				404,
				[ 'page_id' => $post_id ]
			);
		}

		return self::envelope_success( [
			'id'           => $post->ID,
			'title'        => $post->post_title,
			'status'       => $post->post_status,
			'url'          => get_permalink( $post->ID ),
			'modified'     => $post->post_modified,
			'post_type'    => $post->post_type,
			'has_divi'     => self::post_uses_divi( $post ),
			'content_raw'  => $post->post_content,
		] );
	}

	/**
	 * Get the parsed block tree for a page — the core layout structure.
	 */
	public static function page_get_layout( $request ) {
		$post_id = absint( $request['id'] );
		$full    = rest_sanitize_boolean( $request->get_param( 'full' ) ?? false );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return self::envelope_error(
				'not_found',
				"Page #{$post_id} not found.",
				'Verify the page id via diviops_page_list.',
				404,
				[ 'page_id' => $post_id ]
			);
		}

		$content = $post->post_content;

		// Parse WordPress blocks (Divi 5 uses the block format).
		$blocks   = parse_blocks( $content );
		$counters = [];

		// Flatten for readability while preserving hierarchy.
		$layout = self::parse_block_tree( $blocks, 0, $counters, $full );

		$response = [
			'page_id'    => $post->ID,
			'page_title' => $post->post_title,
			'layout'     => $layout,
		];

		// Only include raw content in full mode (can be 100KB+).
		if ( $full ) {
			$response['raw'] = $content;
		}

		return self::envelope_success( $response );
	}

	/**
	 * Set page template and other meta.
	 */
	public static function page_set_meta( $request ) {
		$post_id  = (int) $request['id'];
		$post     = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Page not found', [ 'status' => 404 ] );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Cannot edit this post', [ 'status' => 403 ] );
		}

		$template = $request->get_param( 'template' );
		if ( $template ) {
			update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( $template ) );
		}

		return rest_ensure_response( [
			'success'  => true,
			'page_id'  => $post_id,
			'template' => get_post_meta( $post_id, '_wp_page_template', true ),
		] );
	}

	/**
	 * Update page content with Divi block markup.
	 */
	public static function page_update_content( $request ) {
		$post_id = absint( $request['id'] );
		$content = $request->get_param( 'content' );
		$dry_run = (bool) $request->get_param( 'dry_run' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return self::envelope_error(
				'not_found',
				"Page #{$post_id} not found.",
				'Verify the page id via diviops_page_list.',
				404,
				[ 'page_id' => $post_id ]
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return self::envelope_error(
				'forbidden',
				"Cannot edit page #{$post_id}.",
				'Authenticate as a user with edit rights to this post.',
				403,
				[ 'page_id' => $post_id ]
			);
		}
		if ( ! is_string( $content ) ) {
			return self::envelope_error(
				'invalid_input',
				'content must be a string of Divi block markup.',
				'Pass content as a string. See diviops_page_get_layout for the expected shape.',
				400,
				[ 'field' => 'content', 'received_type' => gettype( $content ) ]
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

		if ( $dry_run ) {
			$old_len = strlen( (string) $post->post_content );
			$new_len = strlen( $content );
			return self::dry_run_response(
				"Would replace post_content on page #{$post_id} ('{$post->post_title}') ({$old_len}→{$new_len} bytes).",
				[ [
					'kind'   => 'page.update_content',
					'target' => "page#{$post_id}",
					'before' => [ 'bytes' => $old_len ],
					'after'  => [ 'bytes' => $new_len ],
				] ]
			);
		}

		// Use wp_slash() instead of wp_kses_post() because block comment
		// attributes contain HTML strings (e.g. <h1>...</h1> in innerContent)
		// that wp_kses_post() would entity-encode, breaking the block parser.
		// This mirrors how the block editor itself saves content.
		$result = wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $content ),
		], true );

		if ( is_wp_error( $result ) ) {
			return self::envelope_from_wp_error( $result );
		}

		// Mirror Divi's own page creation flow once Divi block content exists.
		if ( self::content_uses_divi( $content ) ) {
			self::initialize_divi_page_meta( $post_id );
		}

		self::invalidate_divi_cache( $post_id );

		return self::envelope_success( [
			'success' => true,
			'page_id' => $post_id,
			'message' => 'Content updated successfully.',
		] );
	}

	/**
	 * Create a new page.
	 */
	public static function page_create( $request ) {
		$title   = sanitize_text_field( $request->get_param( 'title' ) );
		$content = $request->get_param( 'content' ) ?? '';
		$status  = sanitize_key( (string) ( $request->get_param( 'status' ) ?? 'draft' ) );
		$dry_run = (bool) $request->get_param( 'dry_run' );
		if ( ! is_string( $content ) ) {
			return self::envelope_error(
				'invalid_input',
				'content must be a string of Divi block markup.',
				'Pass content as a string. See diviops_page_get_layout for the expected shape.',
				400,
				[ 'field' => 'content', 'received_type' => gettype( $content ) ]
			);
		}
		$allowed_statuses = get_post_stati( [ 'internal' => false ] );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			return self::envelope_error(
				'invalid_input',
				'status must be a valid public WordPress post status.',
				'Pass status as one of the allowed values.',
				400,
				[
					'field'    => 'status',
					'allowed'  => array_values( $allowed_statuses ),
					'received' => $status,
				]
			);
		}

		if ( $dry_run ) {
			return self::dry_run_response(
				"Would create page (post_type=page, status={$status}, title='{$title}', " . strlen( $content ) . " bytes content).",
				[ [
					'kind'   => 'page.create',
					'target' => 'page',
					'after'  => [
						'title'   => $title,
						'status'  => $status,
						'bytes'   => strlen( $content ),
					],
				] ]
			);
		}

		$post_id = wp_insert_post( [
			'post_title'   => $title,
			'post_content' => wp_slash( $content ),
			'post_status'  => $status,
			'post_type'    => 'page',
		], true );

		if ( is_wp_error( $post_id ) ) {
			return self::envelope_from_wp_error( $post_id );
		}

		// New MCP-created pages should behave like Divi-created pages by default.
		self::initialize_divi_page_meta( $post_id );

		return self::envelope_success( [
			'success' => true,
			'page_id' => $post_id,
			'url'     => get_permalink( $post_id ),
			'edit_url' => admin_url( "post.php?post={$post_id}&action=edit" ),
		] );
	}

	/**
	 * Append a section to existing page content.
	 */
	public static function section_append( $request ) {
		$post_id  = absint( $request['id'] );
		$content  = $request->get_param( 'content' );
		$position = sanitize_key( (string) ( $request->get_param( 'position' ) ?? 'end' ) );
		$dry_run  = (bool) $request->get_param( 'dry_run' );
		$post     = get_post( $post_id );

		if ( ! $post ) {
			return self::envelope_error(
				'not_found',
				"Page #{$post_id} not found.",
				'Verify the page id via diviops_page_list.',
				404,
				[ 'target_kind' => 'page', 'page_id' => $post_id ]
			);
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return self::envelope_error(
				'forbidden',
				"Cannot edit page #{$post_id}.",
				'Authenticate as a user with edit rights to this post.',
				403,
				[ 'page_id' => $post_id ]
			);
		}
		if ( ! is_string( $content ) ) {
			return self::envelope_error(
				'invalid_input',
				'content must be a string of Divi section markup.',
				null,
				400,
				[ 'field' => 'content', 'received_type' => gettype( $content ) ]
			);
		}
		if ( ! in_array( $position, [ 'start', 'end' ], true ) ) {
			return self::envelope_error(
				'invalid_input',
				'position must be "start" or "end".',
				null,
				400,
				[ 'field' => 'position', 'allowed' => [ 'start', 'end' ], 'received' => $position ]
			);
		}

		if ( $dry_run ) {
			return self::dry_run_response(
				"Would append section to page #{$post_id} ('{$post->post_title}') at position '{$position}' (" . strlen( $content ) . " bytes).",
				[ [
					'kind'   => 'section.append',
					'target' => "page#{$post_id}",
					'after'  => [
						'position' => $position,
						'bytes'    => strlen( $content ),
					],
				] ]
			);
		}

		$existing = $post->post_content;

		// Strip the placeholder wrapper if present, we'll re-add it.
		$inner = $existing;
		$has_placeholder = false !== strpos( $existing, '<!-- wp:divi/placeholder -->' );
		if ( $has_placeholder ) {
			$inner = preg_replace( '/^\s*<!-- wp:divi\/placeholder -->\s*/', '', $inner );
			$inner = preg_replace( '/\s*<!-- \/wp:divi\/placeholder -->\s*$/', '', $inner );
		}

		// Also strip placeholder from incoming content.
		$new_section = preg_replace( '/^\s*<!-- wp:divi\/placeholder -->\s*/', '', $content );
		$new_section = preg_replace( '/\s*<!-- \/wp:divi\/placeholder -->\s*$/', '', $new_section );

		if ( 'start' === $position ) {
			$inner = $new_section . $inner;
		} else {
			$inner = $inner . $new_section;
		}

		// Re-wrap in placeholder.
		$final = '<!-- wp:divi/placeholder -->' . $inner . '<!-- /wp:divi/placeholder -->';

		$result = wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $final ),
		], true );

		if ( is_wp_error( $result ) ) {
			return self::envelope_from_wp_error( $result );
		}

		self::invalidate_divi_cache( $post_id );

		return self::envelope_success( [
			'success'  => true,
			'page_id'  => $post_id,
			'position' => $position,
			'message'  => 'Section appended successfully.',
		] );
	}

	/**
	 * Replace a section identified by admin label or text content.
	 */
	public static function section_replace( $request ) {
		$post_id = absint( $request['id'] );
		$content = $request->get_param( 'content' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return self::envelope_error(
				'not_found',
				"Page #{$post_id} not found.",
				'Verify the page id via diviops_page_list.',
				404,
				[ 'target_kind' => 'page', 'page_id' => $post_id ]
			);
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return self::envelope_error(
				'forbidden',
				"Cannot edit page #{$post_id}.",
				'Authenticate as a user with edit rights to this post.',
				403,
				[ 'page_id' => $post_id ]
			);
		}

		// Section tools accept only label + match_text (no auto_index). Same
		// missing/ambiguous validation contract as module tools.
		$target = self::resolve_module_target( $request, [ 'allow_auto_index' => false ] );
		if ( is_wp_error( $target ) ) {
			return self::envelope_from_helper_error( $target, 'section', $post_id );
		}
		$label      = 'label'      === $target['mode'] ? $target['needle'] : '';
		$match_text = 'match_text' === $target['mode'] ? $target['needle'] : '';
		$occurrence = $target['occurrence'];

		if ( ! is_string( $content ) ) {
			return self::envelope_error(
				'invalid_input',
				'content must be a string of Divi section markup.',
				null,
				400,
				[ 'field' => 'content', 'received_type' => gettype( $content ) ]
			);
		}

		$dry_run = (bool) $request->get_param( 'dry_run' );

		$existing = $post->post_content;
		$result   = self::find_and_replace_section( $existing, $label, $content, $match_text, $occurrence );

		if ( is_wp_error( $result ) ) {
			return self::envelope_from_helper_error( $result, 'section', $post_id );
		}

		if ( $dry_run ) {
			$display_target = '' !== $label ? $label : "text:{$match_text}";
			return self::dry_run_response(
				"Would replace section '{$display_target}' on page #{$post_id} ('{$post->post_title}') (occurrence {$occurrence}, {$result['total_matches']} match(es)).",
				[ [
					'kind'   => 'section.replace',
					'target' => "page#{$post_id}",
					'before' => [
						'matched_by'    => '' !== $label ? 'label' : 'text',
						'target'        => $display_target,
						'occurrence'    => $occurrence,
						'total_matches' => $result['total_matches'],
					],
					'after'  => [ 'bytes' => strlen( $content ) ],
				] ]
			);
		}

		$update = wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $result['content'] ),
		], true );

		if ( is_wp_error( $update ) ) {
			return self::envelope_from_wp_error( $update );
		}

		self::invalidate_divi_cache( $post_id );

		$target   = '' !== $label ? $label : "text:{$match_text}";
		$response = [
			'success'    => true,
			'page_id'    => $post_id,
			'matched_by' => '' !== $label ? 'label' : 'text',
			'target'     => $target,
			'message'    => "Section '{$target}' replaced successfully.",
		];

		if ( $result['total_matches'] > 1 ) {
			$response['occurrence']    = $occurrence;
			$response['total_matches'] = $result['total_matches'];
		}

		return self::envelope_success( $response );
	}

	/**
	 * Remove a section identified by admin label or text content.
	 */
	public static function section_remove( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return self::envelope_error(
				'not_found',
				"Page #{$post_id} not found.",
				'Verify the page id via diviops_page_list.',
				404,
				[ 'target_kind' => 'page', 'page_id' => $post_id ]
			);
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return self::envelope_error(
				'forbidden',
				"Cannot edit page #{$post_id}.",
				'Authenticate as a user with edit rights to this post.',
				403,
				[ 'page_id' => $post_id ]
			);
		}

		$target = self::resolve_module_target( $request, [ 'allow_auto_index' => false ] );
		if ( is_wp_error( $target ) ) {
			return self::envelope_from_helper_error( $target, 'section', $post_id );
		}
		$label      = 'label'      === $target['mode'] ? $target['needle'] : '';
		$match_text = 'match_text' === $target['mode'] ? $target['needle'] : '';
		$occurrence = $target['occurrence'];

		$dry_run = (bool) $request->get_param( 'dry_run' );

		$existing = $post->post_content;
		$result   = self::find_and_replace_section( $existing, $label, '', $match_text, $occurrence );

		// section_remove kickoff Q2: targeting modes for sections (label,
		// match_text) cannot reliably distinguish "already removed" from
		// "never existed" because match_text re-resolves and label uniqueness
		// is not guaranteed post-mutation. Emit the canonical not_found so
		// the audit row stays at idempotent: true (the side-effect — section
		// is gone — is the same regardless of how many times we call) without
		// pretending we have identity-preserving repeat-call detection.
		if ( is_wp_error( $result ) ) {
			return self::envelope_from_helper_error( $result, 'section', $post_id );
		}

		if ( $dry_run ) {
			$display_target = '' !== $label ? $label : "text:{$match_text}";
			return self::dry_run_response(
				"Would remove section '{$display_target}' from page #{$post_id} ('{$post->post_title}') (occurrence {$occurrence}, {$result['total_matches']} match(es)).",
				[ [
					'kind'   => 'section.remove',
					'target' => "page#{$post_id}",
					'before' => [
						'matched_by'    => '' !== $label ? 'label' : 'text',
						'target'        => $display_target,
						'occurrence'    => $occurrence,
						'total_matches' => $result['total_matches'],
					],
				] ]
			);
		}

		$update = wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $result['content'] ),
		], true );

		if ( is_wp_error( $update ) ) {
			return self::envelope_from_wp_error( $update );
		}

		self::invalidate_divi_cache( $post_id );

		$target   = '' !== $label ? $label : "text:{$match_text}";
		$response = [
			'success'    => true,
			'page_id'    => $post_id,
			'matched_by' => '' !== $label ? 'label' : 'text',
			'target'     => $target,
			'message'    => "Section '{$target}' removed successfully.",
		];

		if ( $result['total_matches'] > 1 ) {
			$response['occurrence']    = $occurrence;
			$response['total_matches'] = $result['total_matches'];
		}

		return self::envelope_success( $response );
	}

	// ── Helpers ──────────────────────────────────────────────────────

	/**
	 * Get a single section's markup by admin label or text content.
	 */
	public static function section_get( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return self::envelope_error(
				'not_found',
				"Page #{$post_id} not found.",
				'Verify the page id via diviops_page_list.',
				404,
				[ 'target_kind' => 'page', 'page_id' => $post_id ]
			);
		}

		$target = self::resolve_module_target( $request, [ 'allow_auto_index' => false ] );
		if ( is_wp_error( $target ) ) {
			return self::envelope_from_helper_error( $target, 'section', $post_id );
		}
		$label      = 'label'      === $target['mode'] ? $target['needle'] : '';
		$match_text = 'match_text' === $target['mode'] ? $target['needle'] : '';
		$occurrence = $target['occurrence'];

		$content = $post->post_content;
		$result  = self::extract_section( $content, $label, $match_text, $occurrence );

		if ( is_wp_error( $result ) ) {
			return self::envelope_from_helper_error( $result, 'section', $post_id );
		}

		$target   = '' !== $label ? $label : "text:{$match_text}";
		$response = [
			'page_id'    => $post_id,
			'matched_by' => '' !== $label ? 'label' : 'text',
			'target'     => $target,
			'markup'     => $result['markup'],
		];

		if ( $result['total_matches'] > 1 ) {
			$response['occurrence']    = $occurrence;
			$response['total_matches'] = $result['total_matches'];
			$response['warning']       = "Multiple sections ({$result['total_matches']}) match {$target}. Use 'occurrence' param to target a specific one.";
		}

		return self::envelope_success( $response );
	}

	/**
	 * Split a `module_update` attr path on `.`, treating `\.` as a literal dot in a key segment.
	 *
	 * Backslash-escape lets callers express literal-dot keys that some Divi 5 attribute shapes
	 * require — notably Composable Settings preset slots like `groupPreset["title.decoration.spacing"]`,
	 * where the slot key is itself a dotted string and must not be split into nested segments.
	 *
	 * Examples:
	 *   "content.decoration.background"            → ["content", "decoration", "background"]
	 *   "groupPreset.title\\.decoration\\.spacing" → ["groupPreset", "title.decoration.spacing"]
	 *
	 * @param string $path Dot-separated attr path; segments may use `\.` to embed a literal dot.
	 * @return string[]    The split segments with `\.` collapsed back to `.`.
	 */
	private static function split_dot_path( $path ) {
		// Fast path — no escapes possible without a backslash. Avoids the
		// regex engine entirely on the overwhelmingly common case (every
		// existing caller writes plain dot-paths).
		if ( false === strpos( $path, '\\' ) ) {
			return explode( '.', $path );
		}

		// Negative lookbehind: split on `.` only when not preceded by `\`.
		$segments = preg_split( '/(?<!\\\\)\\./', $path );
		if ( ! is_array( $segments ) ) {
			// preg_split returns false on PCRE failure (e.g. backtrack limits).
			// Treat the whole path as a single segment so the caller's write
			// still lands somewhere visible rather than crashing on null.
			return array( $path );
		}

		return array_map(
			static function ( $segment ) {
				return str_replace( '\\.', '.', $segment );
			},
			$segments
		);
	}

	/**
	 * Update a specific module's attributes by admin label, text content, or auto_index.
	 * Attrs use dot notation: "content.decoration.headingFont.h2.font.desktop.value.color" => "#ff0000"
	 *
	 * For paths whose segments contain literal dots (e.g. Composable Settings preset slots like
	 * `groupPreset["title.decoration.spacing"]`), escape the inner dots with `\.` —
	 * `groupPreset.title\\.decoration\\.spacing.presetId` writes the literal-dot key intact.
	 *
	 * Targeting modes (in priority order):
	 * 1. auto_index — match by type:N counter (e.g. "text:5", "icon:3")
	 * 2. label — match by meta.adminLabel (exact), with optional occurrence
	 * 3. match_text — match by innerContent text (substring, first match)
	 */
	public static function module_update( $request ) {
		$post_id = absint( $request['id'] );
		$attrs   = $request->get_param( 'attrs' );
		$dry_run = (bool) $request->get_param( 'dry_run' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return self::envelope_error(
				'not_found',
				"Page #{$post_id} not found.",
				'Verify the page id via diviops_page_list.',
				404,
				[ 'target_kind' => 'page', 'page_id' => $post_id ]
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return self::envelope_error(
				'forbidden',
				"Cannot edit page #{$post_id}.",
				'Authenticate as a user with edit rights to this post.',
				403,
				[ 'page_id' => $post_id ]
			);
		}

		// Selector validation (missing / ambiguous / malformed auto_index)
		// is centralized in resolve_module_target().
		$target = self::resolve_module_target( $request );
		if ( is_wp_error( $target ) ) {
			return self::envelope_from_helper_error( $target, 'module', $post_id );
		}

		if ( ! is_array( $attrs ) ) {
			return self::envelope_error(
				'invalid_input',
				'attrs must be an object or associative array.',
				null,
				400,
				[ 'field' => 'attrs', 'received_type' => gettype( $attrs ) ]
			);
		}

		// Map helper output back to the local variables the rest of this
		// handler uses. Kept rather than rewriting downstream to minimize
		// risk; the inline parsing block is replaced, not the walk.
		$label      = 'label'      === $target['mode'] ? $target['needle'] : '';
		$match_text = 'match_text' === $target['mode'] ? $target['needle'] : '';
		$auto_index = 'auto_index' === $target['mode'] ? $target['needle'] : '';
		$occurrence = $target['occurrence'];
		// Internal mode tag — preserves the existing `'text'` vs `'match_text'`
		// distinction used by the walk below.
		$mode = 'match_text' === $target['mode'] ? 'text' : $target['mode'];

		$content = $post->post_content;

		// Build the search needle for label mode.
		$needle = 'label' === $mode
			? '"adminLabel":{"desktop":{"value":"' . $label . '"}}'
			: '';

		// For auto_index, parse "type:N" format. Validation happened inside
		// resolve_module_target(); this only splits the already-validated string.
		$ai_type   = '';
		$ai_target = 0;
		if ( 'auto_index' === $mode ) {
			$parts     = explode( ':', $auto_index );
			$ai_type   = $parts[0];
			$ai_target = (int) $parts[1];
		}

		// Scan all blocks in document order (matching get_page_layout's auto_index counting).
		$all_matches   = []; // For label mode: collect all matches.
		$found_match   = null; // The single match to apply.
		$type_counters = []; // For auto_index mode.

		$prefix_len = strlen( self::BLOCK_PREFIX );
		$offset     = 0;
		while ( false !== ( $pos = strpos( $content, self::BLOCK_PREFIX, $offset ) ) ) {
			// Find the block type name — ends at space, / (self-closing), or --> (bare close).
			$search_from   = $pos + $prefix_len;
			$space_pos     = strpos( $content, ' ', $search_from );
			$slash_pos     = strpos( $content, '/', $search_from );
			$comment_close = strpos( $content, '-->', $search_from );

			$type_end = min(
				false !== $space_pos     ? $space_pos     : PHP_INT_MAX,
				false !== $slash_pos     ? $slash_pos     : PHP_INT_MAX,
				false !== $comment_close ? $comment_close : PHP_INT_MAX
			);
			if ( PHP_INT_MAX === $type_end ) {
				break;
			}
			$type = substr( $content, $search_from, $type_end - $search_from );

			// Track auto_index counters per type (document order) — count ALL blocks
			// including those without JSON attrs, to match parse_blocks() counting.
			if ( ! isset( $type_counters[ $type ] ) ) {
				$type_counters[ $type ] = 0;
			}
			$type_counters[ $type ]++;

			// Blocks without JSON attrs can't be updated, but still count for auto_index.
			$next_char = isset( $content[ $type_end + 1 ] ) ? $content[ $type_end + 1 ] : '';
			$has_json  = ( ' ' === $content[ $type_end ] && '{' === $next_char );
			if ( ! $has_json ) {
				// Skip to end of comment for non-JSON blocks.
				$skip_end = strpos( $content, '-->', $pos );
				$offset   = $skip_end ? $skip_end + 3 : $type_end;
				continue;
			}

			$self_close = strpos( $content, '/-->', $pos );
			$container  = strpos( $content, '-->', $pos );

			if ( false === $container ) {
				break;
			}

			$is_self_closing = ( $self_close !== false && $self_close <= $container + 1 );
			$comment_end     = $is_self_closing ? $self_close + 4 : $container + 3;
			$comment         = substr( $content, $pos, $comment_end - $pos );

			$match_info = [
				'pos'             => $pos,
				'comment_end'     => $comment_end,
				'comment'         => $comment,
				'type'            => $type,
				'is_self_closing' => $is_self_closing,
			];

			if ( 'auto_index' === $mode ) {
				if ( $type === $ai_type && $type_counters[ $type ] === $ai_target ) {
					$found_match = $match_info;
					break;
				}
			} elseif ( 'label' === $mode ) {
				if ( false !== strpos( $comment, $needle ) ) {
					$all_matches[] = $match_info;
				}
			} else {
				// Text matching: first match in document order wins.
				if ( false !== stripos( $comment, $match_text ) ) {
					$found_match = $match_info;
					break;
				}
			}

			$offset = $comment_end;
		}

		// For label mode, apply occurrence.
		$total_matches = 0;
		if ( 'label' === $mode ) {
			$total_matches = count( $all_matches );
			if ( 0 === $total_matches ) {
				return self::envelope_error(
					'not_found',
					"No module found with admin label '{$label}' on page #{$post_id}.",
					'Use diviops_page_get_layout to verify available admin labels.',
					404,
					[ 'target_kind' => 'module', 'target_mode' => 'label', 'target_value' => $label, 'page_id' => $post_id ]
				);
			}
			if ( $occurrence < 1 || $occurrence > $total_matches ) {
				return self::envelope_error(
					'invalid_input',
					"Requested occurrence {$occurrence} but only {$total_matches} module(s) match label '{$label}'.",
					null,
					400,
					[
						'field'         => 'occurrence',
						'reason'        => 'invalid_occurrence',
						'target_kind'   => 'module',
						'target_mode'   => 'label',
						'target_value'  => $label,
						'received'      => $occurrence,
						'total_matches' => $total_matches,
					]
				);
			}
			$found_match = $all_matches[ $occurrence - 1 ];
		}

		if ( ! $found_match ) {
			$target_desc = 'auto_index' === $mode ? $auto_index : "text '{$match_text}'";
			return self::envelope_error(
				'not_found',
				"No module found matching {$target_desc} on page #{$post_id}.",
				'Use diviops_page_get_layout to verify available auto_index targets and module text.',
				404,
				[ 'target_kind' => 'module', 'target_mode' => $mode, 'target_value' => $mode === 'auto_index' ? $auto_index : $match_text, 'page_id' => $post_id ]
			);
		}

		// Extract JSON attrs from the matched block.
		$comment         = $found_match['comment'];
		$is_self_closing = $found_match['is_self_closing'];
		$type            = $found_match['type'];
		$pos             = $found_match['pos'];
		$comment_end     = $found_match['comment_end'];

		$json_start = strpos( $comment, '{' );
		$json_end   = $is_self_closing
			? strrpos( $comment, '}', strrpos( $comment, '/-->' ) - strlen( $comment ) )
			: strrpos( $comment, '}', strrpos( $comment, '-->' ) - strlen( $comment ) );

		if ( false === $json_start || false === $json_end ) {
			return self::envelope_error(
				'divi_error',
				'Could not parse block attributes on the matched module.',
				'Re-save the page through the Visual Builder to regenerate canonical block markup.',
				500,
				[ 'page_id' => $post_id, 'block_type' => $type ]
			);
		}

		$json_str    = substr( $comment, $json_start, $json_end - $json_start + 1 );
		$block_attrs = json_decode( $json_str, true );

		// Record which positions held {} before the assoc decode collapsed
		// them to [] — restored after the dot-path mutation so the re-encode
		// keeps the block's untouched empty buckets canonical (#901).
		$empty_object_paths = [];
		$objects_decoded    = json_decode( $json_str );
		if ( is_object( $objects_decoded ) ) {
			$empty_object_paths = self::collect_empty_object_paths( $objects_decoded );
		}

		if ( ! is_array( $block_attrs ) ) {
			return self::envelope_error(
				'divi_error',
				'Could not parse block attributes on the matched module.',
				'Re-save the page through the Visual Builder to regenerate canonical block markup.',
				500,
				[ 'page_id' => $post_id, 'block_type' => $type ]
			);
		}

		// Capture before-state per path so dry_run can surface the would-change.
		$before_values = [];
		if ( $dry_run ) {
			foreach ( $attrs as $path => $_unused ) {
				$keys = self::split_dot_path( $path );
				$cur  = $block_attrs;
				foreach ( $keys as $key ) {
					if ( is_array( $cur ) && array_key_exists( $key, $cur ) ) {
						$cur = $cur[ $key ];
					} else {
						$cur = null;
						break;
					}
				}
				$before_values[ $path ] = $cur;
			}
		}

		// Apply dot-notation attrs.
		foreach ( $attrs as $path => $value ) {
			$keys = self::split_dot_path( $path );
			$ref  = &$block_attrs;
			foreach ( $keys as $i => $key ) {
				if ( $i === count( $keys ) - 1 ) {
					$ref[ $key ] = $value;
				} else {
					if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
						$ref[ $key ] = [];
					}
					$ref = &$ref[ $key ];
				}
			}
			unset( $ref );
		}

		if ( $dry_run ) {
			$target_desc = 'auto_index' === $mode ? $auto_index : ( 'label' === $mode ? $label : "text:{$match_text}" );
			$changes     = [];
			foreach ( $attrs as $path => $value ) {
				$changes[] = [
					'kind'   => 'module.update',
					'target' => "page#{$post_id}/{$type}/{$target_desc}#{$path}",
					'before' => $before_values[ $path ] ?? null,
					'after'  => $value,
				];
			}
			return self::dry_run_response(
				"Would update " . count( $attrs ) . " attr path(s) on module '{$target_desc}' (page #{$post_id}, type {$type}).",
				$changes
			);
		}

		// Re-encode and replace.
		if ( ! empty( $empty_object_paths ) ) {
			$block_attrs = self::restore_empty_objects( $block_attrs, $empty_object_paths );
		}
		$new_json    = wp_json_encode( $block_attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$prefix      = '<!-- wp:divi/' . $type . ' ';
		$suffix      = $is_self_closing ? ' /-->' : ' -->';
		$new_comment = $prefix . $new_json . $suffix;

		$content = substr_replace( $content, $new_comment, $pos, $comment_end - $pos );

		// Save.
		$result = wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $content ),
		], true );

		if ( is_wp_error( $result ) ) {
			return self::envelope_from_wp_error( $result );
		}

		self::invalidate_divi_cache( $post_id );

		// Build response.
		$target_desc = '';
		$matched_by  = $mode;
		if ( 'auto_index' === $mode ) {
			$target_desc = $auto_index;
		} elseif ( 'label' === $mode ) {
			$target_desc = $label;
		} else {
			$target_desc = "text:{$match_text}";
		}

		$response = [
			'success'    => true,
			'page_id'    => $post_id,
			'matched_by' => $matched_by,
			'target'     => $target_desc,
			'updated'    => array_keys( $attrs ),
			'message'    => "Module '{$target_desc}' updated successfully.",
		];

		if ( 'label' === $mode && $total_matches > 1 ) {
			$response['occurrence']    = $occurrence;
			$response['total_matches'] = $total_matches;
		}

		return self::envelope_success( $response );
	}

	/**
	 * Find a block by label, match_text, or auto_index and return its full bounds.
	 *
	 * Returns the block's start/end positions in the content string, including
	 * inner blocks and closing tag for container blocks.
	 *
	 * @param string $content    Page content.
	 * @param string $label      Admin label (exact match). Empty to skip.
	 * @param string $match_text Text search (case-insensitive substring). Empty to skip.
	 * @param string $auto_index Auto-index in "type:N" format. Empty to skip.
	 * @param int    $occurrence Which label match to target (1-based).
	 * @return array|WP_Error    ['start', 'end', 'type', 'matched_by', 'target_desc'] or WP_Error.
	 */
	private static function find_block( $content, $label, $match_text, $auto_index, $occurrence = 1 ) {
		// Determine targeting mode.
		$mode = '';
		if ( '' !== $auto_index ) {
			$mode = 'auto_index';
		} elseif ( '' !== $label ) {
			$mode = 'label';
		} elseif ( '' !== $match_text ) {
			$mode = 'text';
		} else {
			return new WP_Error( 'missing_target', 'One of "label", "match_text", or "auto_index" is required', [ 'status' => 400 ] );
		}

		$needle = 'label' === $mode
			? '"adminLabel":{"desktop":{"value":"' . $label . '"}}'
			: '';

		$ai_type   = '';
		$ai_target = 0;
		if ( 'auto_index' === $mode ) {
			$parts = explode( ':', $auto_index );
			if ( 2 !== count( $parts ) || '' === $parts[0] || ! ctype_digit( $parts[1] ) || (int) $parts[1] < 1 ) {
				return new WP_Error( 'invalid_auto_index', "auto_index must be 'type:N' format with N >= 1", [ 'status' => 400 ] );
			}
			$ai_type   = $parts[0];
			$ai_target = (int) $parts[1];
		}

		$prefix_len    = strlen( self::BLOCK_PREFIX );
		$offset        = 0;
		$type_counters = [];
		$all_matches   = [];
		$found_match   = null;

		while ( false !== ( $pos = strpos( $content, self::BLOCK_PREFIX, $offset ) ) ) {
			$search_from   = $pos + $prefix_len;
			$space_pos     = strpos( $content, ' ', $search_from );
			$slash_pos     = strpos( $content, '/', $search_from );
			$comment_close = strpos( $content, '-->', $search_from );

			$type_end = min(
				false !== $space_pos     ? $space_pos     : PHP_INT_MAX,
				false !== $slash_pos     ? $slash_pos     : PHP_INT_MAX,
				false !== $comment_close ? $comment_close : PHP_INT_MAX
			);
			if ( PHP_INT_MAX === $type_end ) {
				break;
			}
			$type = substr( $content, $search_from, $type_end - $search_from );

			if ( ! isset( $type_counters[ $type ] ) ) {
				$type_counters[ $type ] = 0;
			}
			$type_counters[ $type ]++;

			// Determine if self-closing or container.
			$self_close = strpos( $content, '/-->', $pos );
			$container  = strpos( $content, '-->', $pos );
			if ( false === $container ) {
				break;
			}
			$is_self_closing = ( false !== $self_close && $self_close <= $container + 1 );
			$comment_end     = $is_self_closing ? $self_close + 4 : $container + 3;
			$comment         = substr( $content, $pos, $comment_end - $pos );

			// Calculate full block end (including inner blocks + closing tag for containers).
			$block_end = $comment_end;
			if ( ! $is_self_closing ) {
				$close_tag     = '<!-- /wp:divi/' . $type . ' -->';
				$close_tag_len = strlen( $close_tag );
				$open_tag      = '<!-- wp:divi/' . $type;
				$open_tag_len  = strlen( $open_tag );
				$depth         = 1;
				$scan          = $comment_end;
				$len           = strlen( $content );

				while ( $depth > 0 && $scan < $len ) {
					$next_open  = strpos( $content, $open_tag, $scan );
					$next_close = strpos( $content, $close_tag, $scan );
					if ( false === $next_close ) {
						break;
					}
					// Validate $next_open is the exact type (not a prefix of a longer name).
					if ( false !== $next_open && $next_open < $next_close ) {
						$char_after = $content[ $next_open + $open_tag_len ] ?? '';
						if ( ' ' === $char_after || '{' === $char_after ) {
							$depth++;
						}
						$scan = $next_open + $open_tag_len;
					} else {
						$depth--;
						if ( 0 === $depth ) {
							$block_end = $next_close + $close_tag_len;
						}
						$scan = $next_close + $close_tag_len;
					}
				}

				// If closing tag was never found, the content is malformed.
				if ( $depth > 0 ) {
					return new WP_Error( 'parse_error', "Malformed content: no closing tag found for {$type} block", [ 'status' => 500 ] );
				}
			}

			$match_info = [
				'start' => $pos,
				'end'   => $block_end,
				'type'  => $type,
			];

			if ( 'auto_index' === $mode ) {
				if ( $type === $ai_type && $type_counters[ $type ] === $ai_target ) {
					$found_match = $match_info;
					break;
				}
			} elseif ( 'label' === $mode ) {
				if ( false !== strpos( $comment, $needle ) ) {
					$all_matches[] = $match_info;
				}
			} else {
				// Search opening comment only (not full block content). This targets
				// leaf modules by their attrs/text, consistent with module_update.
				// Searching full content would match parent containers before children.
				if ( false !== stripos( $comment, $match_text ) ) {
					$found_match = $match_info;
					break;
				}
			}

			$offset = $comment_end;
		}

		// For label mode, apply occurrence.
		if ( 'label' === $mode ) {
			if ( empty( $all_matches ) ) {
				return new WP_Error(
					'block_not_found',
					"No block found with admin label '{$label}'",
					[ 'status' => 404, 'target_mode' => 'label', 'target_value' => $label ]
				);
			}
			if ( $occurrence < 1 || $occurrence > count( $all_matches ) ) {
				return new WP_Error(
					'invalid_occurrence',
					"Requested occurrence {$occurrence} but only " . count( $all_matches ) . " block(s) match label '{$label}'",
					[
						'status'        => 400,
						'target_mode'   => 'label',
						'target_value'  => $label,
						'received'      => $occurrence,
						'total_matches' => count( $all_matches ),
					]
				);
			}
			$found_match = $all_matches[ $occurrence - 1 ];
		}

		if ( ! $found_match ) {
			$target_desc  = 'auto_index' === $mode ? $auto_index : ( 'label' === $mode ? $label : "text '{$match_text}'" );
			$target_value = 'auto_index' === $mode ? $auto_index : ( 'label' === $mode ? $label : $match_text );
			$emit_mode    = 'text' === $mode ? 'match_text' : $mode;
			return new WP_Error(
				'block_not_found',
				"No block found matching {$target_desc}",
				[ 'status' => 404, 'target_mode' => $emit_mode, 'target_value' => $target_value ]
			);
		}

		// Build target description.
		if ( 'auto_index' === $mode ) {
			$target_desc = $auto_index;
		} elseif ( 'label' === $mode ) {
			$target_desc = $label;
		} else {
			$target_desc = "text:{$match_text}";
		}

		return array_merge( $found_match, [
			'matched_by'  => $mode,
			'target_desc' => $target_desc,
		] );
	}

	/**
	 * Move a module to a new position on the page.
	 */
	public static function module_move( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return self::envelope_error(
				'not_found',
				"Page #{$post_id} not found.",
				'Verify the page id via diviops_page_list.',
				404,
				[ 'target_kind' => 'page', 'page_id' => $post_id ]
			);
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return self::envelope_error(
				'forbidden',
				"Cannot edit page #{$post_id}.",
				'Authenticate as a user with edit rights to this post.',
				403,
				[ 'page_id' => $post_id ]
			);
		}

		$position = sanitize_key( (string) $request->get_param( 'position' ) );
		if ( ! in_array( $position, [ 'before', 'after' ], true ) ) {
			return self::envelope_error(
				'invalid_input',
				'position must be "before" or "after".',
				null,
				400,
				[ 'field' => 'position', 'allowed' => [ 'before', 'after' ], 'received' => $position ]
			);
		}

		// Source + target selectors share the same validation contract as
		// single-target tools: missing/ambiguous/malformed auto_index all
		// rejected with 400 + context tag so the caller knows which side
		// failed (matters when both sides are passed and only one is bad).
		$source_target = self::resolve_module_target( $request, [
			'label_param'      => 'source_label',
			'match_text_param' => 'source_match_text',
			'auto_index_param' => 'source_auto_index',
			'occurrence_param' => 'source_occurrence',
			'context'          => 'source',
		] );
		if ( is_wp_error( $source_target ) ) {
			return self::envelope_from_helper_error( $source_target, 'module', $post_id );
		}

		$target_target = self::resolve_module_target( $request, [
			'label_param'      => 'target_label',
			'match_text_param' => 'target_match_text',
			'auto_index_param' => 'target_auto_index',
			'occurrence_param' => 'target_occurrence',
			'context'          => 'target',
		] );
		if ( is_wp_error( $target_target ) ) {
			return self::envelope_from_helper_error( $target_target, 'module', $post_id );
		}

		// Map helper output back to the local variables find_block() takes.
		$src_label      = 'label'      === $source_target['mode'] ? $source_target['needle'] : '';
		$src_match_text = 'match_text' === $source_target['mode'] ? $source_target['needle'] : '';
		$src_auto_index = 'auto_index' === $source_target['mode'] ? $source_target['needle'] : '';
		$src_occurrence = $source_target['occurrence'];

		$tgt_label      = 'label'      === $target_target['mode'] ? $target_target['needle'] : '';
		$tgt_match_text = 'match_text' === $target_target['mode'] ? $target_target['needle'] : '';
		$tgt_auto_index = 'auto_index' === $target_target['mode'] ? $target_target['needle'] : '';
		$tgt_occurrence = $target_target['occurrence'];

		$content = $post->post_content;

		// Find both blocks in the original content.
		// Context tag must merge into existing data — `WP_Error::add_data` overwrites
		// `error_data[$code]` rather than merging, so a bare `add_data([ 'context' => ... ])`
		// would drop the helper's structured fields (received, total_matches, target_mode,
		// target_value) onto `additional_data` where envelope_from_helper_error doesn't read.
		$source = self::find_block( $content, $src_label, $src_match_text, $src_auto_index, $src_occurrence );
		if ( is_wp_error( $source ) ) {
			$source->add_data( array_merge( (array) $source->get_error_data(), [ 'context' => 'source' ] ) );
			return self::envelope_from_helper_error( $source, 'module', $post_id );
		}

		$target = self::find_block( $content, $tgt_label, $tgt_match_text, $tgt_auto_index, $tgt_occurrence );
		if ( is_wp_error( $target ) ) {
			$target->add_data( array_merge( (array) $target->get_error_data(), [ 'context' => 'target' ] ) );
			return self::envelope_from_helper_error( $target, 'module', $post_id );
		}

		// Validate: source and target must not overlap.
		if ( $source['start'] < $target['end'] && $target['start'] < $source['end'] ) {
			return self::envelope_error(
				'module.overlap',
				'Source and target blocks overlap — cannot move a block inside itself.',
				'Pick distinct source and target modules; verify with diviops_page_get_layout.',
				400,
				[ 'page_id' => $post_id ]
			);
		}

		$dry_run = (bool) $request->get_param( 'dry_run' );

		// No-op detection. When source is already in the requested position,
		// the apply path returns the legacy `{success, page_id, message, ...}`
		// shape kept for caller compatibility. Under dry_run we route the
		// no-op through the standard plan envelope instead — callers expect
		// uniform `{ ok, data: { dry_run, plan } }` regardless of whether the
		// move would actually rewrite content.
		$is_noop = ( 'before' === $position && $source['end'] === $target['start'] )
			|| ( 'after' === $position && $target['end'] === $source['start'] );

		if ( $dry_run ) {
			if ( $is_noop ) {
				return self::dry_run_response(
					"Module '{$source['target_desc']}' ({$source['type']}) is already {$position} '{$target['target_desc']}' on page #{$post_id} — would be a no-op.",
					[ [
						'kind'   => 'module.move',
						'target' => "page#{$post_id}",
						'before' => [
							'source'      => $source['target_desc'],
							'source_type' => $source['type'],
							'position'    => $position,
							'target'      => $target['target_desc'],
						],
						'after'  => [ 'noop' => true ],
					] ]
				);
			}
			return self::dry_run_response(
				"Would move '{$source['target_desc']}' ({$source['type']}) {$position} '{$target['target_desc']}' ({$target['type']}) on page #{$post_id}.",
				[ [
					'kind'   => 'module.move',
					'target' => "page#{$post_id}",
					'before' => [
						'source'      => $source['target_desc'],
						'source_type' => $source['type'],
					],
					'after'  => [
						'position'    => $position,
						'target'      => $target['target_desc'],
						'target_type' => $target['type'],
					],
				] ]
			);
		}

		if ( $is_noop ) {
			return self::envelope_success( [
				'success' => true,
				'page_id' => $post_id,
				'message' => 'Module is already in the requested position (no change).',
				'source'  => $source['target_desc'],
				'target'  => $target['target_desc'],
				'noop'    => true,
			] );
		}

		// Extract source markup.
		$source_markup = substr( $content, $source['start'], $source['end'] - $source['start'] );
		$source_len    = $source['end'] - $source['start'];

		// Determine raw insertion point.
		$insert_pos = 'before' === $position ? $target['start'] : $target['end'];

		// Remove source and adjust insertion point if source precedes it.
		$content = substr( $content, 0, $source['start'] ) . substr( $content, $source['end'] );
		if ( $source['start'] < $insert_pos ) {
			$insert_pos -= $source_len;
		}

		// Insert source markup at adjusted position.
		$content = substr( $content, 0, $insert_pos ) . $source_markup . substr( $content, $insert_pos );

		// Save.
		$result = wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $content ),
		], true );

		if ( is_wp_error( $result ) ) {
			return self::envelope_from_wp_error( $result );
		}

		self::invalidate_divi_cache( $post_id );

		return self::envelope_success( [
			'success'    => true,
			'page_id'    => $post_id,
			'source'     => $source['target_desc'],
			'source_type' => $source['type'],
			'target'     => $target['target_desc'],
			'target_type' => $target['type'],
			'position'   => $position,
			'message'    => "Moved '{$source['target_desc']}' ({$source['type']}) {$position} '{$target['target_desc']}' ({$target['type']}).",
		] );
	}

	/**
	 * Find all sections matching by label or text content.
	 *
	 * @param string $content    Page content.
	 * @param string $label      Admin label to match (exact). Empty to skip.
	 * @param string $match_text Text to search for in section content (case-insensitive substring). Empty to skip.
	 * @return array Array of ['start' => int, 'end' => int] positions.
	 */
	private static function find_all_sections( $content, $label = '', $match_text = '' ) {
		$needle  = '' !== $label ? '"adminLabel":{"desktop":{"value":"' . $label . '"}}' : '';
		$results = [];
		$offset  = 0;

		$open_len  = strlen( self::SECTION_OPEN );
		$close_len = strlen( self::SECTION_CLOSE );

		// Match sections with or without JSON attrs.
		while ( false !== ( $pos = strpos( $content, self::SECTION_OPEN, $offset ) ) ) {
			// Ensure this is 'divi/section', not a longer name like 'divi/section-special'.
			// Valid chars after the tag name: ' ' (bare) or '{' (has JSON attrs).
			if ( isset( $content[ $pos + $open_len ] ) && ' ' !== $content[ $pos + $open_len ] && '{' !== $content[ $pos + $open_len ] ) {
				$offset = $pos + $open_len;
				continue;
			}

			$comment_end = strpos( $content, '-->', $pos );
			if ( false === $comment_end ) {
				break;
			}
			$comment = substr( $content, $pos, $comment_end - $pos + 3 );

			// For label mode, check the opening comment first (short-circuit).
			if ( '' !== $needle && false === strpos( $comment, $needle ) ) {
				$offset = $comment_end + 3;
				continue;
			}

			// Find closing tag by counting nested sections.
			$opening_end = $comment_end + 3;
			$depth       = 1;
			$scan        = $opening_end;
			$len         = strlen( $content );
			$section_end = false;

			while ( $depth > 0 && $scan < $len ) {
				$next_open  = strpos( $content, self::SECTION_OPEN, $scan );
				$next_close = strpos( $content, self::SECTION_CLOSE, $scan );
				if ( false === $next_close ) {
					break;
				}
				if ( false !== $next_open && $next_open < $next_close ) {
					$depth++;
					$scan = $next_open + $open_len;
				} else {
					$depth--;
					if ( 0 === $depth ) {
						$section_end = $next_close + $close_len;
					}
					$scan = $next_close + $close_len;
				}
			}

			if ( false !== $section_end ) {
				// Label mode already matched above. Text mode checks full section.
				$is_match = '' !== $needle; // Label already confirmed.
				if ( ! $is_match && '' !== $match_text ) {
					$section_content = substr( $content, $pos, $section_end - $pos );
					$is_match = false !== stripos( $section_content, $match_text );
				}

				if ( $is_match ) {
					$results[] = [ 'start' => $pos, 'end' => $section_end ];
				}
			}

			$offset = $comment_end + 3;
		}

		return $results;
	}

	/**
	 * Get the Nth matching section (1-based). Returns markup + total_matches or WP_Error.
	 *
	 * @param string $content    Page content.
	 * @param string $label      Admin label (exact match). Empty to skip.
	 * @param string $match_text Text search (case-insensitive substring). Empty to skip.
	 * @param int    $occurrence Which match to return (1-based).
	 */
	private static function extract_section( $content, $label = '', $match_text = '', $occurrence = 1 ) {
		$matches      = self::find_all_sections( $content, $label, $match_text );
		$target       = '' !== $label ? "label '{$label}'" : "text '{$match_text}'";
		$target_mode  = '' !== $label ? 'label' : 'match_text';
		$target_value = '' !== $label ? $label : $match_text;

		if ( empty( $matches ) ) {
			return new WP_Error(
				'section_not_found',
				"No section found matching {$target}",
				[ 'status' => 404, 'target_mode' => $target_mode, 'target_value' => $target_value ]
			);
		}

		if ( $occurrence < 1 || $occurrence > count( $matches ) ) {
			return new WP_Error(
				'invalid_occurrence',
				"Requested occurrence {$occurrence} but only " . count( $matches ) . " section(s) match {$target}",
				[
					'status'        => 400,
					'target_mode'   => $target_mode,
					'target_value'  => $target_value,
					'received'      => $occurrence,
					'total_matches' => count( $matches ),
				]
			);
		}

		$match = $matches[ $occurrence - 1 ];
		$markup = substr( $content, $match['start'], $match['end'] - $match['start'] );

		return [
			'markup'        => $markup,
			'total_matches' => count( $matches ),
		];
	}

	/**
	 * Replace or remove the Nth matching section (1-based).
	 *
	 * @param string $content     Page content.
	 * @param string $label       Admin label (exact). Empty to skip.
	 * @param string $replacement New section markup (empty string to remove).
	 * @param string $match_text  Text search (case-insensitive substring). Empty to skip.
	 * @param int    $occurrence  Which match to target (1-based).
	 * @return array|WP_Error ['content' => string, 'total_matches' => int] or WP_Error.
	 */
	private static function find_and_replace_section( $content, $label, $replacement, $match_text = '', $occurrence = 1 ) {
		$matches      = self::find_all_sections( $content, $label, $match_text );
		$target       = '' !== $label ? "label '{$label}'" : "text '{$match_text}'";
		$target_mode  = '' !== $label ? 'label' : 'match_text';
		$target_value = '' !== $label ? $label : $match_text;

		if ( empty( $matches ) ) {
			return new WP_Error(
				'section_not_found',
				"No section found matching {$target}",
				[ 'status' => 404, 'target_mode' => $target_mode, 'target_value' => $target_value ]
			);
		}

		if ( $occurrence < 1 || $occurrence > count( $matches ) ) {
			return new WP_Error(
				'invalid_occurrence',
				"Requested occurrence {$occurrence} but only " . count( $matches ) . " section(s) match {$target}",
				[
					'status'        => 400,
					'target_mode'   => $target_mode,
					'target_value'  => $target_value,
					'received'      => $occurrence,
					'total_matches' => count( $matches ),
				]
			);
		}

		$match  = $matches[ $occurrence - 1 ];
		$before = substr( $content, 0, $match['start'] );
		$after  = substr( $content, $match['end'] );

		return [
			'content'       => $before . $replacement . $after,
			'total_matches' => count( $matches ),
		];
	}

	/**
	 * Check if a post uses Divi builder (has divi/* blocks).
	 */
	private static function post_uses_divi( $post ) {
		return (bool) preg_match( '/<!-- wp:divi\//', $post->post_content );
	}

	/**
	 * Check if incoming content contains Divi block markup.
	 */
	private static function content_uses_divi( $content ) {
		return is_string( $content ) && false !== strpos( $content, '<!-- wp:divi/' );
	}

	/**
	 * Seed the minimum metadata Divi expects for builder-backed pages.
	 *
	 * This mirrors Divi's own onboarding and page creation helpers.
	 */
	private static function initialize_divi_page_meta( $post_id ) {
		update_post_meta( $post_id, '_et_pb_use_builder', 'on' );
		update_post_meta( $post_id, '_et_pb_use_divi_5', 'on' );
		update_post_meta( $post_id, '_et_pb_page_layout', 'et_full_width_page' );
		update_post_meta( $post_id, '_et_pb_built_for_post_type', 'page' );
		// Uses default page.php template (with header/footer).
		// et_full_width_page layout removes the sidebar.
		// For blank (no header/footer), set template to 'page-template-blank.php' via page_set_meta.
	}

	/**
	 * Parse block tree into a flat/nested structure with targeting metadata.
	 *
	 * @param array $blocks     Parsed blocks from parse_blocks().
	 * @param int   $depth      Current nesting depth.
	 * @param array $counters   Per-type sequential counters for auto_index.
	 * @param bool  $full       Include full attrs (true) or targeting metadata only (false).
	 */
	private static function parse_block_tree( $blocks, $depth = 0, &$counters = [], $full = false ) {
		$result = [];

		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue; // Skip freeform/empty blocks.
			}

			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];

			// Extract admin label if present.
			$admin_label = self::get_nested_array_value( $attrs, [ 'module', 'meta', 'adminLabel', 'desktop', 'value' ], '' );
			if ( '' === $admin_label ) {
				$admin_label = self::get_nested_array_value( $attrs, [ 'meta', 'adminLabel', 'desktop', 'value' ], '' );
			}

			// Extract text content preview for targeting.
			$text_preview = '';
			$inner_content_paths = [
				[ 'content', 'innerContent', 'desktop', 'value' ],
				[ 'title', 'innerContent', 'desktop', 'value' ],
				[ 'button', 'innerContent', 'desktop', 'value', 'text' ],
			];
			foreach ( $inner_content_paths as $path ) {
				$val = self::get_nested_array_value( $attrs, $path );
				if ( is_string( $val ) && '' !== $val ) {
					$text_preview = wp_strip_all_tags( html_entity_decode( $val ) );
					$text_preview = mb_substr( trim( $text_preview ), 0, 50 );
					break;
				}
			}

			// Generate auto-index for this block type.
			$short_name = str_replace( 'divi/', '', $block['blockName'] );
			if ( ! isset( $counters[ $short_name ] ) ) {
				$counters[ $short_name ] = 0;
			}
			$counters[ $short_name ]++;
			$auto_index = $short_name . ':' . $counters[ $short_name ];

			$item = [
				'block_name'   => $block['blockName'],
				'depth'        => $depth,
				'admin_label'  => $admin_label,
				'text_preview' => $text_preview,
				'auto_index'   => $auto_index,
			];

			// Only include full attrs in full mode.
			if ( $full ) {
				$item['attrs'] = $attrs;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$item['inner_blocks'] = self::parse_block_tree( $block['innerBlocks'], $depth + 1, $counters, $full );
			}

			$result[] = $item;
		}

		return $result;
	}

	/**
	 * Count registered Divi modules.
	 */
	private static function get_active_module_count() {
		$registry = WP_Block_Type_Registry::get_instance();
		$count    = 0;
		foreach ( array_keys( $registry->get_all_registered() ) as $name ) {
			if ( 0 === strpos( $name, 'divi/' ) ) {
				$count++;
			}
		}
		return $count;
	}

	// ── Module state: lock / unlock / clone ────────────────────────

	/**
	 * Walk a parsed-blocks tree and apply $mutator to the matched block (or
	 * its parent siblings array / parent block) in place. PHP's foreach-by-
	 * reference + recursion + returning references is fragile, so we use a
	 * callback pattern: the mutator runs INSIDE the recursion, with the
	 * actual `&$block`, `&$siblings`, and `&$parent_block` references live.
	 *
	 * Mutator signature:
	 *   `function (array &$siblings, int $index, array &$block, ?array &$parent_block) : void`
	 *
	 * The optional `$parent_block` parameter is critical for clone-style
	 * operations: WordPress's `serialize_blocks()` only emits as many
	 * innerBlocks as there are `null` placeholders in the parent's
	 * `innerContent` array — so a clone that splices into `siblings`
	 * (= `parent_block.innerBlocks`) MUST also splice a `null` into
	 * `parent_block.innerContent`. For top-level blocks (no parent), the
	 * parameter is null and serialize uses the array directly.
	 *
	 * Modes:
	 *   - 'label':       match attrs.module.meta.adminLabel.desktop.value === $needle
	 *   - 'match_text':  case-insensitive substring search of serialized block
	 *   - 'auto_index':  "type:N" format (e.g. "text:5") — Nth occurrence of
	 *                    block name `divi/{type}` in document order
	 *
	 * Returns true if a match was found and the mutator ran; false otherwise.
	 */
	private static function walk_and_mutate(
		array &$blocks,
		string $mode,
		string $needle,
		int $occurrence,
		array &$counters,
		int &$match_count,
		callable $mutator,
		?array &$parent_block = null
	) {
		$count = count( $blocks );
		// Index by counter — must operate on $blocks[$i] not a foreach reference,
		// because array_splice inside the mutator would shift indices and
		// invalidate the foreach iterator.
		for ( $i = 0; $i < $count; $i++ ) {
			$block = &$blocks[ $i ];
			$name  = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];

			// Auto-index counter: count every block of each type in document order.
			if ( 0 === strpos( $name, 'divi/' ) ) {
				$short = substr( $name, 5 );
				if ( ! isset( $counters[ $short ] ) ) {
					$counters[ $short ] = 0;
				}
				$counters[ $short ]++;
			}

			// For 'match_text' mode, recurse FIRST (bottom-up search) so we
			// match the most specific block containing the text, not the outer
			// container that also contains it via descendants. Other modes use
			// document-order top-down which gives the auto_index counter the
			// same ordering semantics as get_page_layout.
			if ( 'match_text' === $mode && isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && ! empty( $block['innerBlocks'] ) ) {
				if ( self::walk_and_mutate(
					$block['innerBlocks'], $mode, $needle, $occurrence, $counters, $match_count, $mutator, $block
				) ) {
					unset( $block );
					return true;
				}
			}

			$matched = false;
			if ( 'label' === $mode ) {
				$label = $attrs['module']['meta']['adminLabel']['desktop']['value'] ?? '';
				if ( $label === $needle ) {
					$match_count++;
					if ( $match_count === $occurrence ) {
						$matched = true;
					}
				}
			} elseif ( 'auto_index' === $mode ) {
				$parts = explode( ':', $needle );
				if ( 2 === count( $parts ) && '' !== $parts[0] && ctype_digit( $parts[1] ) ) {
					$ai_type = $parts[0];
					$ai_n    = (int) $parts[1];
					$short   = 0 === strpos( $name, 'divi/' ) ? substr( $name, 5 ) : '';
					if ( $short === $ai_type && ( $counters[ $short ] ?? 0 ) === $ai_n ) {
						$matched = true;
					}
				}
			} elseif ( 'match_text' === $mode ) {
				// At this point recursion above already returned false for this
				// branch, so no descendant matched. Now check ONLY the current
				// block's own opening-comment markup, NOT its descendants.
				//
				// `serialize_block($block)` walks innerBlocks recursively (per
				// wp-includes/blocks.php:1717), which means a leaf match would
				// also count every ancestor that contains it via descendants —
				// double-counting that breaks the `occurrence` parameter and
				// can let `occurrence:2` mutate the parent container instead of
				// returning no_match.
				//
				// Workaround: temporarily strip innerBlocks/innerContent before
				// serializing, so we get only this block's own opening comment
				// (with attrs encoded). For self-closing leaf blocks this is
				// already the full markup; for containers, it's just the
				// `<!-- wp:divi/section ... -->` comment line, which won't
				// contain descendant text content like "Your content goes here".
				$shallow = $block;
				$shallow['innerBlocks']  = [];
				$shallow['innerContent'] = [];
				if ( false !== stripos( serialize_block( $shallow ), $needle ) ) {
					$match_count++;
					if ( $match_count === $occurrence ) {
						$matched = true;
					}
				}
			}

			if ( $matched ) {
				$mutator( $blocks, $i, $block, $parent_block );
				unset( $block );
				return true;
			}

			// For non-'match_text' modes, recurse AFTER the check (top-down
			// document order). Already done above for match_text.
			if ( 'match_text' !== $mode && isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && ! empty( $block['innerBlocks'] ) ) {
				if ( self::walk_and_mutate(
					$block['innerBlocks'], $mode, $needle, $occurrence, $counters, $match_count, $mutator, $block
				) ) {
					unset( $block );
					return true;
				}
			}
			unset( $block );
		}
		return false;
	}

	/**
	 * Resolve the targeting mode + needle for a single-block selector.
	 *
	 * Reads `label` / `match_text` / `auto_index` (and `occurrence`) from the
	 * REST request and returns a `[mode, needle, occurrence]` triple, OR a
	 * `WP_Error` with the appropriate 400.
	 *
	 * Validation rules:
	 *   - At least one selector must be present (`missing_target`)
	 *   - At most one selector may be present (`ambiguous_target`) — silent
	 *     priority is a footgun for callers who set two selectors thinking
	 *     they'll AND, when in fact one wins and the other is ignored.
	 *   - When `auto_index` is used, it must match `type:N` with non-empty
	 *     `type` and `N >= 1` (`invalid_auto_index`). Catches malformed input
	 *     at the targeting layer rather than letting it fall through to a
	 *     misleading `404 no_match`.
	 *
	 * Param-name remapping via $opts lets `module_move` reuse this helper for
	 * its `source_*` / `target_*` selector pairs, and lets section ops opt
	 * out of `auto_index` (which they don't accept):
	 *
	 *   $opts = [
	 *     'label_param'      => 'source_label',       // default 'label'
	 *     'match_text_param' => 'source_match_text',  // default 'match_text'
	 *     'auto_index_param' => 'source_auto_index',  // default 'auto_index'
	 *     'occurrence_param' => 'source_occurrence',  // default 'occurrence'
	 *     'allow_auto_index' => false,                // default true
	 *     'context'          => 'source',             // for error messages — default ''
	 *   ];
	 *
	 * Returns:
	 *   [ 'mode' => 'label'|'match_text'|'auto_index',
	 *     'needle' => string,
	 *     'occurrence' => int >= 1 ]
	 *   OR WP_Error with status 400.
	 */
	private static function resolve_module_target( $request, array $opts = [] ) {
		$label_param      = $opts['label_param']      ?? 'label';
		$match_text_param = $opts['match_text_param'] ?? 'match_text';
		$auto_index_param = $opts['auto_index_param'] ?? 'auto_index';
		$occurrence_param = $opts['occurrence_param'] ?? 'occurrence';
		$allow_auto_index = $opts['allow_auto_index'] ?? true;
		$context          = $opts['context']          ?? '';
		$ctx_suffix       = '' !== $context ? " ({$context})" : '';

		// Mirror the per-handler sanitize_text_field that the inline parsing
		// did before centralization (strips control chars, tags, extra
		// whitespace). Removing it would be a behavior regression for callers
		// who relied on the normalization — labels are typed as 'string' at
		// the route layer but not auto-sanitized.
		$label      = sanitize_text_field( (string) $request->get_param( $label_param ) );
		$match_text = sanitize_text_field( (string) $request->get_param( $match_text_param ) );
		$auto_index_raw = sanitize_text_field( (string) $request->get_param( $auto_index_param ) );
		$auto_index = $allow_auto_index ? $auto_index_raw : '';
		$occurrence = max( 1, absint( $request->get_param( $occurrence_param ) ?? 1 ) );

		// When auto_index is not allowed (section ops), still check the raw
		// param so a caller passing `{label: "foo", auto_index: "text:1"}`
		// gets an explicit "auto_index not supported here" error instead of
		// silently ignoring the auto_index and proceeding with label.
		// Re-introduces the same silent-priority footgun the helper exists to
		// prevent if we don't catch it here.
		if ( ! $allow_auto_index && '' !== $auto_index_raw ) {
			return new WP_Error(
				'unsupported_selector',
				sprintf(
					"%s is not supported for this tool%s. Pass only \"label\" or \"match_text\".",
					$auto_index_param,
					$ctx_suffix
				),
				[ 'status' => 400 ]
			);
		}

		// Count non-empty selectors. Reject both 0 and >1 with distinct codes
		// so callers get a precise diagnostic.
		$selectors_provided = (int) ( '' !== $label ) + (int) ( '' !== $match_text ) + (int) ( '' !== $auto_index );

		if ( 0 === $selectors_provided ) {
			$allowed = $allow_auto_index
				? '"label", "match_text", or "auto_index"'
				: '"label" or "match_text"';
			return new WP_Error(
				'missing_target',
				sprintf( 'One of %s is required%s.', $allowed, $ctx_suffix ),
				[ 'status' => 400 ]
			);
		}

		if ( $selectors_provided > 1 ) {
			$provided = [];
			if ( '' !== $label )      { $provided[] = $label_param; }
			if ( '' !== $match_text ) { $provided[] = $match_text_param; }
			if ( '' !== $auto_index ) { $provided[] = $auto_index_param; }
			return new WP_Error(
				'ambiguous_target',
				sprintf(
					"Multiple selectors provided%s: %s. Pass exactly one — silent priority would mask the conflict.",
					$ctx_suffix,
					implode( ', ', $provided )
				),
				[ 'status' => 400 ]
			);
		}

		// Auto-index format validation: must be `type:N` with non-empty type
		// and integer N >= 1. Without this, malformed input (e.g. "text",
		// "text:abc", ":5") silently reaches the walk and surfaces as a
		// misleading `no_match` 404 instead of the actual input error.
		if ( '' !== $auto_index ) {
			$parts = explode( ':', $auto_index );
			if ( 2 !== count( $parts ) || '' === $parts[0] || ! ctype_digit( $parts[1] ) || (int) $parts[1] < 1 ) {
				return new WP_Error(
					'invalid_auto_index',
					sprintf(
						"%s '%s' must be 'type:N' format with non-empty type and N >= 1 (e.g. 'text:5')%s.",
						$auto_index_param,
						$auto_index,
						$ctx_suffix
					),
					[ 'status' => 400 ]
				);
			}
		}

		$mode   = '' !== $auto_index ? 'auto_index' : ( '' !== $label ? 'label' : 'match_text' );
		$needle = 'auto_index' === $mode ? $auto_index : ( 'label' === $mode ? $label : $match_text );
		return [ 'mode' => $mode, 'needle' => $needle, 'occurrence' => $occurrence ];
	}

	/**
	 * Load post + permission check shared by lock/unlock/clone. Returns
	 * a [post, blocks] pair or WP_Error.
	 */
	private static function load_post_for_module_op( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Page not found', [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Cannot edit this post', [ 'status' => 403 ] );
		}
		// Sidecar enrichment records which attr positions held {} in the
		// stored markup, so save_mutated_blocks() can undo core's
		// assoc-decode {} → [] collapse on re-serialization (#901).
		$content = (string) $post->post_content;
		return [
			'post'   => $post,
			'blocks' => self::enrich_blocks_with_empty_object_paths( parse_blocks( $content ), $content ),
		];
	}

	/**
	 * Save mutated blocks back to the post. Returns true on success or a
	 * WP_Error on failure.
	 */
	private static function save_mutated_blocks( $post, array $blocks ) {
		$new_content = serialize_blocks( self::restore_blocks_empty_objects( $blocks ) );
		$result      = wp_update_post(
			[ 'ID' => $post->ID, 'post_content' => wp_slash( $new_content ) ],
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Lock a module so VB users cannot edit it. Sets `attrs.locked` to
	 * `{desktop: {value: "on"}}` per Divi's per-breakpoint convention
	 * (verified via VB-save probe). Locked modules render normally on
	 * frontend; only VB-side editing is gated.
	 */
	public static function module_lock( $request ) {
		$post_id = absint( $request['id'] );
		$target  = self::resolve_module_target( $request );
		if ( is_wp_error( $target ) ) {
			return self::envelope_from_helper_error( $target, 'module', $post_id );
		}
		$loaded = self::load_post_for_module_op( $request );
		if ( is_wp_error( $loaded ) ) {
			return self::envelope_post_load_error( $loaded, $post_id );
		}

		$blocks            = $loaded['blocks'];
		$counters          = [];
		$match_count = 0;
		$captured          = [ 'block_name' => '', 'admin_label' => '', 'was_locked' => false ];

		$found = self::walk_and_mutate(
			$blocks, $target['mode'], $target['needle'], $target['occurrence'],
			$counters, $match_count,
			function ( array &$siblings, int $i, array &$block, ?array &$parent_block ) use ( &$captured ) {
				if ( ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
					$block['attrs'] = [];
				}
				$captured['was_locked']  = isset( $block['attrs']['locked']['desktop']['value'] )
					&& 'on' === $block['attrs']['locked']['desktop']['value'];
				$captured['block_name']  = $block['blockName'] ?? '';
				$captured['admin_label'] = $block['attrs']['module']['meta']['adminLabel']['desktop']['value'] ?? '';
				$block['attrs']['locked'] = [ 'desktop' => [ 'value' => 'on' ] ];
			}
		);

		if ( ! $found ) {
			return self::envelope_error(
				'not_found',
				sprintf( "No module found matching '%s' (mode=%s) on page #%d.", $target['needle'], $target['mode'], $loaded['post']->ID ),
				'Use diviops_page_get_layout to verify available targets.',
				404,
				[
					'target_kind'  => 'module',
					'target_mode'  => $target['mode'],
					'target_value' => $target['needle'],
					'page_id'      => $loaded['post']->ID,
				]
			);
		}

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			$desc = $captured['admin_label'] ?: $target['needle'];
			return self::dry_run_response(
				$captured['was_locked']
					? "Module '{$desc}' ({$captured['block_name']}) is already locked — would be a no-op."
					: "Would lock module '{$desc}' ({$captured['block_name']}).",
				[ [
					'kind'   => 'module.lock',
					'target' => "page#{$loaded['post']->ID}/{$captured['block_name']}/{$desc}",
					'before' => [ 'is_locked' => $captured['was_locked'] ],
					'after'  => [ 'is_locked' => true ],
				] ]
			);
		}

		$saved = self::save_mutated_blocks( $loaded['post'], $blocks );
		if ( is_wp_error( $saved ) ) {
			return self::envelope_from_wp_error( $saved );
		}

		return self::envelope_success( [
			'success' => true,
			'module'  => array_merge( $captured, [ 'is_locked' => true ] ),
			'message' => $captured['was_locked'] ? 'Module was already locked (re-confirmed).' : 'Module locked.',
		] );
	}

	/**
	 * Unlock a module by removing `attrs.locked` entirely. Matches Divi VB's
	 * convention: unlocked = attribute absent (NOT `{value: "off"}`). VB
	 * doesn't write a falsy value on unlock — it removes the field.
	 */
	public static function module_unlock( $request ) {
		$post_id = absint( $request['id'] );
		$target  = self::resolve_module_target( $request );
		if ( is_wp_error( $target ) ) {
			return self::envelope_from_helper_error( $target, 'module', $post_id );
		}
		$loaded = self::load_post_for_module_op( $request );
		if ( is_wp_error( $loaded ) ) {
			return self::envelope_post_load_error( $loaded, $post_id );
		}

		$blocks            = $loaded['blocks'];
		$counters          = [];
		$match_count = 0;
		$captured          = [ 'block_name' => '', 'admin_label' => '', 'was_locked' => false ];

		$found = self::walk_and_mutate(
			$blocks, $target['mode'], $target['needle'], $target['occurrence'],
			$counters, $match_count,
			function ( array &$siblings, int $i, array &$block, ?array &$parent_block ) use ( &$captured ) {
				if ( ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
					$block['attrs'] = [];
				}
				$captured['was_locked']  = isset( $block['attrs']['locked']['desktop']['value'] )
					&& 'on' === $block['attrs']['locked']['desktop']['value'];
				$captured['block_name']  = $block['blockName'] ?? '';
				$captured['admin_label'] = $block['attrs']['module']['meta']['adminLabel']['desktop']['value'] ?? '';
				unset( $block['attrs']['locked'] );
			}
		);

		if ( ! $found ) {
			return self::envelope_error(
				'not_found',
				sprintf( "No module found matching '%s' (mode=%s) on page #%d.", $target['needle'], $target['mode'], $loaded['post']->ID ),
				'Use diviops_page_get_layout to verify available targets.',
				404,
				[
					'target_kind'  => 'module',
					'target_mode'  => $target['mode'],
					'target_value' => $target['needle'],
					'page_id'      => $loaded['post']->ID,
				]
			);
		}

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			$desc = $captured['admin_label'] ?: $target['needle'];
			return self::dry_run_response(
				$captured['was_locked']
					? "Would unlock module '{$desc}' ({$captured['block_name']})."
					: "Module '{$desc}' ({$captured['block_name']}) is not locked — would be a no-op.",
				[ [
					'kind'   => 'module.unlock',
					'target' => "page#{$loaded['post']->ID}/{$captured['block_name']}/{$desc}",
					'before' => [ 'is_locked' => $captured['was_locked'] ],
					'after'  => [ 'is_locked' => false ],
				] ]
			);
		}

		$saved = self::save_mutated_blocks( $loaded['post'], $blocks );
		if ( is_wp_error( $saved ) ) {
			return self::envelope_from_wp_error( $saved );
		}

		return self::envelope_success( [
			'success' => true,
			'module'  => array_merge( $captured, [ 'is_locked' => false ] ),
			'message' => $captured['was_locked'] ? 'Module unlocked.' : 'Module was not locked (no-op).',
		] );
	}

	/**
	 * Clone a module by deep-copying its block JSON and inserting it next
	 * to the source within the same parent container. `position` controls
	 * before/after placement (default "after").
	 *
	 * Module IDs are reassigned by Divi at render time from the block tree
	 * position, so the clone gets fresh IDs automatically — no need to
	 * mint UUIDs on our side.
	 */
	public static function module_clone( $request ) {
		$post_id = absint( $request['id'] );
		$target  = self::resolve_module_target( $request );
		if ( is_wp_error( $target ) ) {
			return self::envelope_from_helper_error( $target, 'module', $post_id );
		}
		$loaded = self::load_post_for_module_op( $request );
		if ( is_wp_error( $loaded ) ) {
			return self::envelope_post_load_error( $loaded, $post_id );
		}

		$position = sanitize_key( (string) ( $request->get_param( 'position' ) ?? 'after' ) );
		if ( ! in_array( $position, [ 'before', 'after' ], true ) ) {
			$position = 'after';
		}

		$blocks            = $loaded['blocks'];
		$counters          = [];
		$match_count = 0;
		$captured          = [ 'block_name' => '', 'admin_label' => '' ];

		try {
			$found = self::walk_and_mutate(
				$blocks, $target['mode'], $target['needle'], $target['occurrence'],
				$counters, $match_count,
				function ( array &$siblings, int $i, array &$block, ?array &$parent_block ) use ( $position, &$captured ) {
					$captured['block_name']  = $block['blockName'] ?? '';
					$captured['admin_label'] = $block['attrs']['module']['meta']['adminLabel']['desktop']['value'] ?? '';
					// PHP arrays-of-arrays are copy-by-value — this IS a deep clone for
					// plain-array nodes (innerBlocks recursively included). No refs.
					$clone     = $block;
					$insert_at = ( 'after' === $position ) ? $i + 1 : $i;
					array_splice( $siblings, $insert_at, 0, [ $clone ] );

				// Critical: WordPress's serialize_blocks() emits innerBlocks based
				// on `null` placeholders in the parent's `innerContent`. When the
				// matched block lives inside another block (i.e. parent_block is
				// not null), we must splice a null placeholder into
				// $parent_block['innerContent'] at the SAME logical position as the
				// new innerBlocks entry — otherwise the clone is silently dropped
				// from the serialized output.
				//
				// `innerContent` is a flat array where strings are HTML chunks and
				// nulls mark innerBlock slots. To insert at position $insert_at in
				// `innerBlocks`, find the $insert_at'th null in innerContent and
				// splice a new null there (or after, to preserve any pre-block HTML
				// chunk).
				if ( null !== $parent_block && isset( $parent_block['innerContent'] ) && is_array( $parent_block['innerContent'] ) ) {
					$null_seen     = 0;
					$last_null_idx = -1;
					$ic_insert     = null;
					foreach ( $parent_block['innerContent'] as $ic_idx => $ic_item ) {
						if ( null === $ic_item ) {
							if ( $null_seen === $insert_at ) {
								// Insert at this null's position so the new placeholder
								// pairs with the cloned innerBlock at the same logical index.
								$ic_insert = $ic_idx;
								break;
							}
							$last_null_idx = $ic_idx;
							$null_seen++;
						}
					}

					// Sanity check: parent_block must have at least one null
					// placeholder, because we're cloning an EXISTING child block
					// (so parent.innerBlocks had ≥1 entry pre-splice, which
					// requires ≥1 null in innerContent for valid parsed input).
					// Zero nulls = malformed parsed block — error rather than
					// guess at insertion order.
					if ( -1 === $last_null_idx && null === $ic_insert ) {
						throw new \RuntimeException(
							sprintf(
								"Refusing to clone: parent block '%s' has innerBlocks but no `null` placeholders in innerContent (malformed parsed input). Cannot determine where to insert the new placeholder. Re-save the page through VB to regenerate canonical block markup, then retry.",
								$parent_block['blockName'] ?? 'unknown'
							)
						);
					}

					// Fallback: $insert_at is beyond the last existing null
					// (e.g. inserting at end). Place the new null IMMEDIATELY
					// after the last existing null, NOT at array end — trailing
					// HTML chunks after the last null must remain after our new
					// placeholder so serialization order is preserved.
					if ( null === $ic_insert ) {
						$ic_insert = $last_null_idx + 1;
					}
					array_splice( $parent_block['innerContent'], $ic_insert, 0, [ null ] );
				}
			}
			);
		} catch ( \RuntimeException $e ) {
			return self::envelope_error(
				'divi_error',
				$e->getMessage(),
				'Re-save the page through the Visual Builder to regenerate canonical block markup, then retry.',
				500,
				[ 'page_id' => $loaded['post']->ID, 'reason' => 'malformed_parent' ]
			);
		}

		if ( ! $found ) {
			return self::envelope_error(
				'not_found',
				sprintf( "No module found matching '%s' (mode=%s) on page #%d.", $target['needle'], $target['mode'], $loaded['post']->ID ),
				'Use diviops_page_get_layout to verify available targets.',
				404,
				[
					'target_kind'  => 'module',
					'target_mode'  => $target['mode'],
					'target_value' => $target['needle'],
					'page_id'      => $loaded['post']->ID,
				]
			);
		}

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			$desc = $captured['admin_label'] ?: $target['needle'];
			return self::dry_run_response(
				"Would clone module '{$desc}' ({$captured['block_name']}) {$position} source on page #{$loaded['post']->ID}.",
				[ [
					'kind'   => 'module.clone',
					'target' => "page#{$loaded['post']->ID}/{$captured['block_name']}/{$desc}",
					'after'  => [ 'position' => $position ],
				] ]
			);
		}

		$saved = self::save_mutated_blocks( $loaded['post'], $blocks );
		if ( is_wp_error( $saved ) ) {
			return self::envelope_from_wp_error( $saved );
		}

		return self::envelope_success( [
			'success' => true,
			'cloned'  => array_merge( $captured, [ 'position' => $position ] ),
			'message' => sprintf( "Module cloned %s source.", $position ),
		] );
	}

	/**
	 * Trash (or permanently delete) a page.
	 *
	 * Replaces the wp-cli `post delete --force=0|1` route for AI-agent callers — typed
	 * input, deterministic envelope, dry-run preview. Backs `diviops_page_trash`.
	 */
	public static function page_trash( $request ) {
		$post_id = absint( $request['id'] );
		$force   = (bool) $request->get_param( 'force' );
		$dry_run = (bool) $request->get_param( 'dry_run' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return self::envelope_error(
				'not_found',
				"Page #{$post_id} not found.",
				'Verify the page id via diviops_page_list.',
				404,
				[ 'page_id' => $post_id ]
			);
		}
		if ( ! current_user_can( 'delete_post', $post_id ) ) {
			return self::envelope_error(
				'forbidden',
				"Cannot delete page #{$post_id}.",
				'Authenticate as a user with delete rights to this post.',
				403,
				[ 'page_id' => $post_id ]
			);
		}

		$current_status = (string) $post->post_status;
		$title          = (string) $post->post_title;
		$already_trash  = ( 'trash' === $current_status );

		// Idempotency + dry-run plan.
		if ( $force ) {
			$summary  = "Would permanently delete page #{$post_id} (title: '{$title}', current status: {$current_status}).";
			$action   = 'delete';
			$end_state = 'deleted';
		} elseif ( $already_trash ) {
			$summary  = "Page #{$post_id} (title: '{$title}') is already in trash — no-op.";
			$action   = 'noop';
			$end_state = 'trash';
		} else {
			$summary  = "Would move page #{$post_id} (title: '{$title}', current status: {$current_status}) to trash.";
			$action   = 'trash';
			$end_state = 'trash';
		}

		if ( $dry_run ) {
			return self::dry_run_response(
				$summary,
				[
					[
						'kind'   => $action,
						'target' => "page#{$post_id}",
						'before' => $current_status,
						'after'  => $end_state,
					],
				],
				[],
				[
					'id'    => $post_id,
					'title' => $title,
				]
			);
		}

		if ( $force ) {
			$result = wp_delete_post( $post_id, true );
			if ( ! $result ) {
				return self::envelope_error(
					'wp_error',
					"Failed to permanently delete page #{$post_id}.",
					'wp_delete_post returned false; check WordPress error logs.',
					500,
					[ 'page_id' => $post_id ]
				);
			}
			self::invalidate_divi_cache( $post_id );
			return self::envelope_success( [
				'id'     => $post_id,
				'title'  => $title,
				'status' => 'deleted',
			] );
		}

		// Idempotent success on already-trashed posts (per Q3, Option B):
		// repeat-safe semantics for AI-agent retries; the `already_trashed`
		// flag preserves the no-op signal for callers who care.
		if ( $already_trash ) {
			return self::envelope_success( [
				'id'              => $post_id,
				'title'           => $title,
				'status'          => 'trash',
				'already_trashed' => true,
			] );
		}

		$result = wp_trash_post( $post_id );
		if ( ! $result ) {
			return self::envelope_error(
				'wp_error',
				"Failed to trash page #{$post_id}.",
				'wp_trash_post returned false; check WordPress error logs.',
				500,
				[ 'page_id' => $post_id ]
			);
		}
		self::invalidate_divi_cache( $post_id );
		return self::envelope_success( [
			'id'     => $post_id,
			'title'  => $title,
			'status' => 'trash',
		] );
	}

	/**
	 * Update a page's post_status (publish/draft/private/pending/future).
	 *
	 * Replaces the wp-cli `post update --post_status=...` route for AI-agent callers.
	 * Validates the status enum; for `future`, requires a `date_gmt` in the future and
	 * writes both `post_date_gmt` and `post_date` (site-tz). For non-future statuses,
	 * a stale future date is cleared so re-publishing doesn't bounce the post back into
	 * the scheduler.
	 */
	public static function page_update_status( $request ) {
		$post_id  = absint( $request['id'] );
		$status   = sanitize_key( (string) $request->get_param( 'status' ) );
		$date_gmt = $request->get_param( 'date_gmt' );
		$dry_run  = (bool) $request->get_param( 'dry_run' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return self::envelope_error(
				'not_found',
				"Page #{$post_id} not found.",
				'Verify the page id via diviops_page_list.',
				404,
				[ 'page_id' => $post_id ]
			);
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return self::envelope_error(
				'forbidden',
				"Cannot edit page #{$post_id}.",
				'Authenticate as a user with edit rights to this post.',
				403,
				[ 'page_id' => $post_id ]
			);
		}

		$allowed = [ 'publish', 'draft', 'private', 'pending', 'future' ];
		if ( ! in_array( $status, $allowed, true ) ) {
			return self::envelope_error(
				'invalid_input',
				"status must be one of: " . implode( ', ', $allowed ) . '.',
				'Pass status as one of the allowed values.',
				400,
				[ 'field' => 'status', 'allowed' => $allowed, 'received' => $status ]
			);
		}

		$date_gmt = is_string( $date_gmt ) ? trim( $date_gmt ) : '';

		if ( 'future' === $status ) {
			if ( '' === $date_gmt ) {
				return self::envelope_error(
					'invalid_input',
					"date_gmt is required when status='future' (ISO 8601 UTC, e.g. '2026-06-01T09:00:00Z').",
					'Pass date_gmt alongside status=future.',
					400,
					[ 'field' => 'date_gmt', 'requires' => [ "status='future' implies non-empty date_gmt" ] ]
				);
			}
			$ts = strtotime( $date_gmt );
			if ( false === $ts ) {
				return self::envelope_error(
					'invalid_input',
					"date_gmt could not be parsed as a date: '{$date_gmt}'.",
					'Pass date_gmt as ISO 8601 UTC (e.g. 2026-06-01T09:00:00Z).',
					400,
					[ 'field' => 'date_gmt', 'received' => $date_gmt ]
				);
			}
			// Mirror core's actual scheduling rule (wp-includes/post.php:4712-4716):
			// future is silently demoted to publish when (post_date_gmt - now) < MINUTE_IN_SECONDS.
			// Reject below the same threshold so the endpoint contract matches reality.
			if ( $ts - time() < MINUTE_IN_SECONDS ) {
				return self::envelope_error(
					'invalid_input',
					"date_gmt must be at least 60 seconds in the future for status='future'.",
					'Schedule at least one minute in the future to avoid core silently demoting to publish.',
					400,
					[ 'field' => 'date_gmt', 'received' => $date_gmt, 'min_lead_seconds' => 60 ]
				);
			}
		}

		$current_status   = (string) $post->post_status;
		$current_date_gmt = (string) $post->post_date_gmt;
		$title            = (string) $post->post_title;
		$noop             = ( $current_status === $status ) && ( 'future' !== $status || gmdate( 'Y-m-d H:i:s', strtotime( $date_gmt ) ) === $current_date_gmt );

		// Build the update args.
		$update = [
			'ID'          => $post_id,
			'post_status' => $status,
		];
		$scheduled_for = null;
		if ( 'future' === $status ) {
			$ts                       = strtotime( $date_gmt );
			$update['post_date_gmt']  = gmdate( 'Y-m-d H:i:s', $ts );
			$update['post_date']      = get_date_from_gmt( $update['post_date_gmt'] );
			// Required by core (wp-includes/post.php:5261-5276): when the existing
			// post_date_gmt is '0000-00-00 00:00:00' (default for never-scheduled
			// drafts), wp_update_post sets $clear_date=true and overwrites our
			// post_date with current_time() unless edit_date is truthy in args.
			$update['edit_date']      = true;
			$scheduled_for            = $update['post_date_gmt'];
		} elseif ( 'publish' === $status && ! empty( $current_date_gmt ) && strtotime( $current_date_gmt ) > time() ) {
			// Clear a previously-set future date so re-publishing takes effect now.
			$now                     = current_time( 'mysql', true );
			$update['post_date_gmt'] = $now;
			$update['post_date']     = get_date_from_gmt( $now );
		}

		$summary  = $noop
			? "Page #{$post_id} (title: '{$title}') already has status='{$status}'" . ( $scheduled_for ? " scheduled for {$scheduled_for}" : '' ) . ' — no-op.'
			: "Would update page #{$post_id} (title: '{$title}') status: '{$current_status}' → '{$status}'" . ( $scheduled_for ? " (scheduled for {$scheduled_for} UTC)" : '' ) . '.';

		if ( $dry_run ) {
			return self::dry_run_response(
				$summary,
				$noop ? [] : [
					[
						'kind'   => 'update_status',
						'target' => "page#{$post_id}",
						'before' => $current_status,
						'after'  => $status,
					],
				],
				[],
				[
					'id'             => $post_id,
					'title'          => $title,
					'scheduled_for'  => $scheduled_for,
				]
			);
		}

		if ( $noop ) {
			return self::envelope_success( [
				'id'            => $post_id,
				'title'         => $title,
				'status'        => $status,
				'modified_gmt'  => (string) $post->post_modified_gmt,
				'scheduled_for' => $scheduled_for,
				'noop'          => true,
			] );
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return self::envelope_from_wp_error( $result );
		}

		self::invalidate_divi_cache( $post_id );

		$updated = get_post( $post_id );
		return self::envelope_success( [
			'id'            => $post_id,
			'title'         => (string) $updated->post_title,
			'status'        => (string) $updated->post_status,
			'modified_gmt'  => (string) $updated->post_modified_gmt,
			'scheduled_for' => 'future' === $status ? (string) $updated->post_date_gmt : null,
		] );
	}
}
