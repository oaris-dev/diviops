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
 *   - wp_error      — Theme Builder master post missing, or wp_insert_post /
 *                     wp_update_post returned WP_Error
 *   - tb_template.command_failed — wp_trash_post / wp_delete_post returned a
 *                     falsy / WP_Error result during tb_template_trash, OR
 *                     delete_post_meta returned falsy after we counted matching
 *                     rows (residual stale meta). error.data.failed_step is one
 *                     of: 'layout_destroy', 'template_destroy', 'meta_scrub'
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

		// Resolve the Theme Builder master post (the one that carries _et_template
		// meta refs). Match the discovery shape used by tb_template_create.
		$master = get_posts( [
			'post_type'      => 'et_theme_builder',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
		] );
		$master_id = ! empty( $master ) ? (int) $master[0]->ID : 0;

		// Count master meta refs that name this template (used for plan + idempotent reporting).
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
