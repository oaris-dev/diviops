<?php
/**
 * Trait DiviOps_Agent_Meta
 *
 * Plugin meta surface: handshake, settings, theme options, icons, cache flush.
 *
 * Part of the diviops-agent monolith split (#220). Mixed into
 * DiviOps_Agent via `use` in diviops-agent.php — `self::` calls and
 * class constants resolve as if these methods lived directly on the
 * class.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_Meta {

	/**
	 * Get Divi global settings (theme options, customizer values).
	 *
	 * Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.
	 */
	public static function schema_get_settings( $request ) {
		$settings = [];

		// Theme options.
		$et_options = get_option( 'et_divi', [] );
		if ( is_array( $et_options ) ) {
			$settings['theme_options'] = $et_options;
		}

		// Key customizer values.
		$settings['site'] = [
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => get_site_url(),
			'language'    => get_locale(),
		];

		// Builder-specific settings.
		$settings['builder'] = [
			'version'        => defined( 'ET_BUILDER_PRODUCT_VERSION' ) ? ET_BUILDER_PRODUCT_VERSION : 'unknown',
			'is_divi5'       => true,
			'active_modules' => self::get_active_module_count(),
		];

		return self::envelope_success( $settings );
	}

	/**
	 * Update Divi theme options (fonts, colors, etc.).
	 */
	public static function update_theme_options( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Requires admin capability', [ 'status' => 403 ] );
		}

		$options  = $request->get_param( 'options' );
		if ( ! is_array( $options ) ) {
			return new WP_Error( 'invalid_options', 'options must be an object or associative array.', [ 'status' => 400 ] );
		}
		$allowed  = [
			'heading_font', 'body_font', 'accent_color', 'secondary_accent_color',
			'font_color', 'header_color', 'link_color',
			'heading_font_size', 'body_font_size',
		];
		$updated  = [];

		foreach ( $options as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$sanitized_value = sanitize_text_field( (string) $value );
			et_update_option( $key, $sanitized_value );
			$updated[ $key ] = $sanitized_value;
		}

		return rest_ensure_response( [
			'success' => true,
			'updated' => $updated,
			'message' => count( $updated ) . ' option(s) updated.',
		] );
	}

	/**
	 * Search Divi's icon catalog by keyword.
	 *
	 * Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.
	 * Errors:
	 *  - invalid_input (HTTP 400): `type` not in {all, fa, divi}
	 *  - not_found (HTTP 404): icon-list JSON file missing on this Divi install
	 *  - divi_error (HTTP 500): icon-list JSON failed to decode
	 */
	public static function search_icons( $request ) {
		$query = strtolower( sanitize_text_field( (string) $request['q'] ) );
		$type  = sanitize_key( (string) ( $request['type'] ?? 'all' ) ); // all, fa, divi
		$limit = min( absint( $request['limit'] ?? 10 ), 50 );
		if ( ! in_array( $type, [ 'all', 'fa', 'divi' ], true ) ) {
			return self::envelope_error(
				'invalid_input',
				'type must be one of: all, fa, divi',
				null,
				400
			);
		}

		$json_path = get_template_directory() . '/includes/builder/feature/icon-manager/full_icons_list.json';
		if ( ! file_exists( $json_path ) ) {
			return self::envelope_error(
				'not_found',
				'Icon list not found',
				'Verify the Divi theme is installed at the expected path; full_icons_list.json ships under includes/builder/feature/icon-manager/.',
				404
			);
		}

		$icons = json_decode( file_get_contents( $json_path ), true );
		if ( ! is_array( $icons ) ) {
			return self::envelope_error(
				'divi_error',
				'Icon list could not be decoded',
				null,
				500
			);
		}
		$results = [];

		foreach ( $icons as $icon ) {
			if ( ! is_array( $icon ) ) {
				continue;
			}
			// Filter by type.
			if ( 'fa' === $type && ! empty( $icon['is_divi_icon'] ) ) {
				continue;
			}
			if ( 'divi' === $type && empty( $icon['is_divi_icon'] ) ) {
				continue;
			}

			$search = strtolower( ( $icon['search_terms'] ?? '' ) . ' ' . ( $icon['name'] ?? '' ) );
			if ( strpos( $search, $query ) !== false ) {
				$results[] = [
					'name'    => $icon['name'],
					'unicode' => $icon['unicode'],
					'type'    => ! empty( $icon['is_divi_icon'] ) ? 'divi' : 'fa',
					'weight'  => (int) ( $icon['font_weight'] ?? 400 ),
					'styles'  => $icon['styles'] ?? [],
				];
			}

			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return self::envelope_success( [
			'query'   => $query,
			'count'   => count( $results ),
			'results' => $results,
		] );
	}

	/**
	 * Flush Divi's compiled static CSS cache.
	 *
	 * Divi writes compiled CSS to disk under wp-content/et-cache/; wp cache
	 * flush does NOT touch these files, so the frontend can keep serving
	 * stale CSS after a preset/variable/module mutation until the cache is
	 * cleared. This endpoint surfaces Divi's own clearer as an explicit tool.
	 *
	 * Exactly one selector is required — no default to 'all' to avoid an
	 * accidental site-wide flush:
	 *   - post_id: int > 0     — flush cache for one post
	 *   - all:     true        — flush every cached file
	 *   - after:   unix ts > 0 — flush cache for posts whose et-cache/{id}/
	 *                            dir has mtime > ts (iterated per-post)
	 *
	 * Backend selection:
	 *   - Prefers Divi's native ET_Core_PageResource::remove_static_resources
	 *     when available. Native mode additionally clears Theme Builder CSS
	 *     scattered across other post dirs, archive / taxonomy / home /
	 *     notfound CSS, object cache, module features cache, post features
	 *     cache, dynamic assets cache, Google Fonts cache, and post meta
	 *     caches. Scope is significantly broader than the numeric subdir
	 *     filesystem walk.
	 *   - Falls back to a targeted filesystem walk of et-cache/{post_id}/
	 *     when the Divi class is absent (Divi inactive, stripped builds).
	 *     Only numeric-named subdirs are touched in fallback mode —
	 *     siblings (.cache-cleared-at, global/, en_US/, notfound/, *.data)
	 *     are never removed.
	 *
	 * Idempotency:
	 *   - Missing cache root returns 200 with empty flushed list.
	 *   - Unwritable cache root returns 500 so callers don't silently no-op.
	 *
	 * Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.
	 * Errors:
	 *  - invalid_input (HTTP 400): no selector / multiple selectors / non-positive post_id
	 *    or after timestamp. Namespace-prefixed selector codes (`meta_flush_cache.*`)
	 *    were retired in favor of the standard `invalid_input` to keep the vocabulary
	 *    uniform; the message text disambiguates which selector check failed.
	 *  - meta_flush_cache.unwritable (HTTP 500): cache root exists but the active
	 *    backend cannot write to it (Divi's can_write_to_filesystem in native mode,
	 *    is_writable() in fs_fallback). Distinct code so callers can branch on
	 *    "filesystem refused" vs "command rejected" without parsing the message.
	 *  - meta_flush_cache.fs_init_failed (HTTP 500): WP_Filesystem could not be
	 *    initialized (FTP/SSH-credentialed hosts without saved creds). Native
	 *    backend only — fs_fallback uses direct unlink() and never trips this.
	 */
	public static function flush_static_cache( $request ) {
		$post_id_raw  = $request->get_param( 'post_id' );
		$has_post_id  = null !== $post_id_raw;
		$all          = rest_sanitize_boolean( $request->get_param( 'all' ) ?? false );
		$after_raw    = $request->get_param( 'after' );
		$has_after    = null !== $after_raw;

		$selectors_used = (int) $has_post_id + (int) $all + (int) $has_after;
		if ( 0 === $selectors_used ) {
			return self::envelope_error(
				'invalid_input',
				'Exactly one selector required: post_id, all, or after.',
				'Pass exactly one of post_id (positive int), all: true, or after (unix timestamp).',
				400
			);
		}
		if ( $selectors_used > 1 ) {
			return self::envelope_error(
				'invalid_input',
				'Only one of post_id, all, after may be provided per call.',
				null,
				400
			);
		}

		$cache_root = self::resolve_et_cache_root();
		$use_native = class_exists( '\ET_Core_PageResource' )
			&& method_exists( '\ET_Core_PageResource', 'remove_static_resources' );

		// Writability gate:
		//   - Native mode: defer to Divi's own can_write_to_filesystem() — it
		//     accepts WP_Filesystem-backed environments (FTP/SSH-credentialed
		//     hosts) where is_writable() would return false even though Divi
		//     can still write. Matches the same gate Divi uses internally.
		//   - fs_fallback: we use direct unlink(), which genuinely needs OS
		//     write permission — is_writable() is the correct check here.
		if ( $use_native ) {
			if (
				method_exists( '\ET_Core_PageResource', 'can_write_to_filesystem' )
				&& ! \ET_Core_PageResource::can_write_to_filesystem()
			) {
				return self::envelope_error(
					'meta_flush_cache.unwritable',
					'Divi reports the cache filesystem is not writable (ET_Core_PageResource::can_write_to_filesystem).',
					'Check filesystem permissions on ' . $cache_root . ' or the FTP/SSH credentials Divi uses for static-CSS writes.',
					500
				);
			}
		} elseif ( is_dir( $cache_root ) && ! is_writable( $cache_root ) ) {
			return self::envelope_error(
				'meta_flush_cache.unwritable',
				'et-cache directory exists but is not writable by the PHP process.',
				'Check filesystem permissions on ' . $cache_root . '.',
				500
			);
		}

		// Missing cache dir: we intentionally don't early-return anymore.
		// Divi's resolver may have already recreated the dir as a side
		// effect (its singleton constructor runs WP_Filesystem with create),
		// and in any case:
		//   - Native mode: remove_static_resources safely runs on a missing
		//     dir (ensure_directory_exists + DONOTCACHEPAGE + site-wide
		//     cache purges still fire).
		//   - fs_fallback: flush_et_cache_dir's transient + post-meta
		//     invalidations fire unconditionally; numeric-post-id iteration
		//     naturally no-ops on a missing dir via is_dir guards in the
		//     helpers. sweep_non_post_divi_css likewise guards.

		$dry_run = (bool) $request->get_param( 'dry_run' );

		if ( $has_post_id ) {
			$post_id = absint( $post_id_raw );
			if ( $post_id <= 0 ) {
				return self::envelope_error(
					'invalid_input',
					'post_id must be a positive integer.',
					null,
					400
				);
			}

			// Snapshot the per-post dir size before the clear so we can report
			// bytes freed even in native mode (where the clearer itself returns
			// no counts). Lower bound — native mode also removes TB CSS in
			// other post dirs, which this snapshot does not account for.
			$pre = self::et_cache_dir_snapshot( $cache_root, $post_id );

			if ( $dry_run ) {
				return self::dry_run_response(
					"Would flush et-cache for post #{$post_id} ({$pre['files']} file(s), {$pre['bytes']} bytes; backend=" . ( $use_native ? 'divi_native' : 'fs_fallback' ) . ').',
					[ [
						'kind'   => 'meta.flush_cache',
						'target' => "post#{$post_id}",
						'before' => [ 'files' => $pre['files'], 'bytes' => $pre['bytes'] ],
					] ]
				);
			}

			if ( $use_native ) {
				// preserve_vb_files=true mirrors Divi's own preset / global-data
				// / VB update call sites (GlobalPreset.php, GlobalData.php,
				// OffCanvasHooks.php) — keeps `-vb-*` runtime CSS so an open
				// Visual Builder isn't left unstyled after an external flush.
				// delete_files=true bypasses Divi's lazy .stale marker
				// strategy — matches the immediate-delete semantic users want.
				\ET_Core_PageResource::remove_static_resources(
					$post_id, 'all', false, 'all', true, true
				);
				$response = [
					'mode'        => 'post_id',
					'backend'     => 'divi_native',
					'cache_root'  => $cache_root,
					'flushed'     => [ $post_id ],
					'files_freed' => $pre['files'],
					'bytes_freed' => $pre['bytes'],
					'scope_note'  => 'Divi native clearer also removed matching Theme Builder CSS across other post dirs and purged object/module/post/dynamic-assets caches. Visual Builder (-vb-*) runtime CSS preserved to avoid unstyling an open VB session. files_freed / bytes_freed reflect the pre-delete snapshot of et-cache/' . $post_id . '/ only — they are a lower bound; the clearer may have freed more outside this dir.',
				];
				return self::envelope_success( $response );
			}

			$result = self::flush_et_cache_dir( $cache_root, $post_id );
			$response = [
				'mode'        => 'post_id',
				'backend'     => 'fs_fallback',
				'cache_root'  => $cache_root,
				'flushed'     => $result['existed'] ? [ $post_id ] : [],
				'missing'     => $result['existed'] ? [] : [ $post_id ],
				'files_freed' => $result['files'],
				'bytes_freed' => $result['bytes'],
			];
			return self::envelope_success( $response );
		}

		if ( $all ) {
			if ( $dry_run ) {
				$snap = self::et_cache_total_snapshot( $cache_root );
				$post_ids = self::et_cache_numeric_post_ids( $cache_root );
				return self::dry_run_response(
					"Would flush all et-cache entries (≈{$snap['files']} file(s), {$snap['bytes']} bytes across " . count( $post_ids ) . " post dir(s); backend=" . ( $use_native ? 'divi_native' : 'fs_fallback' ) . ').',
					[ [
						'kind'   => 'meta.flush_cache',
						'target' => 'et-cache/all',
						'before' => [
							'files'    => $snap['files'],
							'bytes'    => $snap['bytes'],
							'post_ids' => $post_ids,
						],
					] ]
				);
			}

			if ( $use_native ) {
				// Two-phase approach:
				//
				// Phase 1 — single-pass file sweep (no N × native_call):
				//   Walk et-cache/ once, delete every Divi CSS file
				//   (et-*.css / et-*.min.css) except those containing
				//   `-vb-` in the basename. Scales as O(total_files)
				//   rather than O(posts × total_files) that would result
				//   from per-post native clears. Scope covers numeric
				//   post dirs AND archive/taxonomy/home/notfound/global
				//   subtrees in one pass — matches the scope Divi's own
				//   _mark_global_cache_cleared(delete_files=true) covers
				//   while applying the VB-preserve filter Divi's mass
				//   path lacks.
				//
				// Phase 2 — site-wide WP cache purges, inlined:
				//   Deliberately NOT calling remove_static_resources with
				//   post_id='all' (or anything that triggers the global
				//   timestamp path). Writing .cache-cleared-at would
				//   immediately invalidate the `-vb-*` files we just
				//   kept in phase 1: is_file_stale() checks file mtime
				//   against that timestamp (PageResource.php:1604-1610)
				//   and any file older than the stamp is stale, including
				//   our preserved VB runtime CSS. That would defeat the
				//   whole point of the preserve-VB logic and unstyle an
				//   open Visual Builder session.
				//
				//   Instead, call the same site-wide purges Divi runs
				//   AFTER the path-specific branch in
				//   do_remove_static_resources, but skip the timestamp
				//   write. Phase 1's physical sweep already delivers
				//   frontend-level invalidation.
				//
				//   Both the sweep AND the DONOTCACHEPAGE sentinel write
				//   route through WP_Filesystem to match Divi's own
				//   deletion API (self::$wpfs->delete in
				//   _mark_global_cache_cleared). Direct unlink() would
				//   silently fail on managed FTP/SSH-credentialed hosts
				//   where can_write_to_filesystem() accepts but PHP
				//   itself lacks write permission.
				$wpfs = self::init_wp_filesystem();
				if ( ! $wpfs ) {
					return self::envelope_error(
						'meta_flush_cache.fs_init_failed',
						'Failed to initialize WP_Filesystem for cache clear. The native backend requires it to delete files on hosts where Divi writes via FTP/SSH credentials.',
						'On managed hosts, define FS_METHOD / saved FTP credentials in wp-config.php so WP_Filesystem can authenticate.',
						500
					);
				}
				$pass1 = self::sweep_all_divi_css_preserving_vb( $cache_root, $wpfs );
				self::run_divi_site_wide_cache_purges();
				// Match Divi's post-clear behavior: write the
				// DONOTCACHEPAGE sentinel so page-cache plugins / CDNs
				// skip caching the first request while CSS regenerates
				// (PageResource.php:1367-1368 writes the same file).
				if ( is_dir( $cache_root ) ) {
					$wpfs->put_contents( $cache_root . '/DONOTCACHEPAGE', '' );
				}
				$response = [
					'mode'        => 'all',
					'backend'     => 'divi_native',
					'cache_root'  => $cache_root,
					'flushed'     => $pass1['post_ids'],
					'files_freed' => $pass1['files'],
					'bytes_freed' => $pass1['bytes'],
					'scope_note'  => 'Two-phase native clear: (1) WP_Filesystem-driven recursive sweep deleting Divi CSS (et-*.css) across numeric post dirs AND archive/taxonomy/home/notfound/global subtrees, skipping Visual Builder (-vb-*) runtime CSS to avoid unstyling an open VB session; (2) inlined site-wide purges — object cache, module features, post features, Google Fonts, dynamic assets, post meta caches. DONOTCACHEPAGE sentinel written to et-cache root to match Divi\'s post-clear convention (page-cache plugins skip caching the first regenerated request). .cache-cleared-at timestamp deliberately NOT written — Divi\'s is_file_stale() compares file mtime against it, so bumping would invalidate the preserved VB files. Phase 1\'s physical sweep covers frontend-level invalidation without needing the stamp.',
				];
				return self::envelope_success( $response );
			}

			$pre_total = self::et_cache_total_snapshot( $cache_root );

			// Fallback: iterate numeric subdirs, then sweep root-level and
			// non-numeric subdir et-*.css files (archive/taxonomy/home/
			// notfound/search/global trees) to match the scope Divi's
			// native clearer covers in post_id='all' mode. The safety
			// invariant (only et-*.css basenames) matches Divi's own
			// _is_valid_divi_css_file filter in _mark_global_cache_cleared;
			// .data, .cache-cleared-at, DONOTCACHEPAGE are preserved.
			$flushed     = [];
			$files_freed = 0;
			$bytes_freed = 0;
			foreach ( self::et_cache_numeric_post_ids( $cache_root ) as $pid ) {
				$result = self::flush_et_cache_dir( $cache_root, $pid );
				if ( $result['existed'] ) {
					$flushed[]    = $pid;
					$files_freed += $result['files'];
					$bytes_freed += $result['bytes'];
				}
			}
			$non_post = self::sweep_non_post_divi_css( $cache_root );
			$files_freed += $non_post['files'];
			$bytes_freed += $non_post['bytes'];
			$response = [
				'mode'                  => 'all',
				'backend'               => 'fs_fallback',
				'cache_root'            => $cache_root,
				'flushed'               => $flushed,
				'skipped'               => [],
				'files_freed'           => $files_freed,
				'bytes_freed'           => $bytes_freed,
				'non_post_files_freed'  => $non_post['files'],
				'non_post_bytes_freed'  => $non_post['bytes'],
			];
			return self::envelope_success( $response );
		}

		// --- after mode ---
		$after_ts = intval( $after_raw );
		if ( $after_ts <= 0 ) {
			return self::envelope_error(
				'invalid_input',
				'after must be a positive unix timestamp.',
				null,
				400
			);
		}

		if ( $dry_run ) {
			return self::dry_run_response(
				"Would flush et-cache entries with mtime > {$after_ts} (full plan requires the actual sweep — backend=" . ( $use_native ? 'divi_native' : 'fs_fallback' ) . ').',
				[ [
					'kind'   => 'meta.flush_cache',
					'target' => 'et-cache/after',
					'after'  => [ 'after_ts' => $after_ts ],
				] ],
				[ 'after-mode dry_run reports the cutoff only — accurate file count requires the live mtime walk.' ]
			);
		}

		if ( $use_native ) {
			// Single-pass WP_Filesystem sweep of the whole et-cache tree,
			// filtering by file mtime > $after_ts. Replaces the prior
			// per-matched-post ET_Core_PageResource::remove_static_resources
			// loop — each of those calls does ~7 glob() scans of the cache
			// tree (PageResource.php:1268-1285), so runtime grew as
			// O(#matched × #total_files). The sweep runs in a single
			// O(#total_files) walk regardless of match count, which is the
			// behavior the `all` phase-1 path already uses.
			//
			// Semantic shift from the prior native path: the previous
			// implementation filtered at the post-dir granularity — if any
			// file inside et-cache/{pid}/ had mtime > cutoff, all CSS in
			// that dir (and related TB CSS across other dirs, via the
			// native clearer's cross-dir globs) was removed. The sweep
			// filters at the file granularity — only files strictly newer
			// than the cutoff are deleted. Older CSS co-located with a
			// recently-rewritten file survives. For the tool's stated use
			// case ("flushing entries touched since a known deployment or
			// mutation batch") this is the more literal semantic.
			//
			// `flushed` reports numeric post_ids whose files were actually
			// deleted (parent-dir membership tracked in the sweep).
			// `skipped` reports numeric post_ids that exist but had no
			// files pass the filter — preserved for response-shape parity
			// with the prior native + current fallback paths.
			$wpfs = self::init_wp_filesystem();
			if ( ! $wpfs ) {
				return self::envelope_error(
					'meta_flush_cache.fs_init_failed',
					'Failed to initialize WP_Filesystem for cache clear. The native backend requires it to delete files on hosts where Divi writes via FTP/SSH credentials.',
					'On managed hosts, define FS_METHOD / saved FTP credentials in wp-config.php so WP_Filesystem can authenticate.',
					500
				);
			}
			$sweep       = self::sweep_all_divi_css_preserving_vb( $cache_root, $wpfs, $after_ts );
			$touched_set = array_flip( $sweep['post_ids'] );
			$skipped     = [];
			foreach ( self::et_cache_numeric_post_ids( $cache_root ) as $pid ) {
				if ( ! isset( $touched_set[ $pid ] ) ) {
					$skipped[] = $pid;
				}
			}
			sort( $skipped );

			// Per-touched-post invalidations — the prior per-match
			// remove_static_resources call did these inside Divi, and
			// dropping them leaves page caches / Divi post-meta caches
			// serving stale HTML even after the CSS file is gone. These
			// are cheap O(N) operations (no filesystem globs), so doing
			// them in a post-sweep loop preserves the end-to-end flush
			// semantic without reintroducing the scaling issue. Guarded
			// on the same class/function checks `run_divi_site_wide_cache_purges`
			// uses so stripped builds don't fatal.
			$has_clear_wp_cache  = function_exists( 'et_core_clear_wp_cache' );
			$has_meta_cache_clear = class_exists( '\ET_Core_PageResource' )
				&& method_exists( '\ET_Core_PageResource', 'clear_post_meta_caches' );
			foreach ( $sweep['post_ids'] as $pid ) {
				if ( $has_clear_wp_cache ) {
					et_core_clear_wp_cache( (string) $pid );
				}
				if ( $has_meta_cache_clear ) {
					\ET_Core_PageResource::clear_post_meta_caches( (string) $pid );
				}
			}

			// Divi feature caches (module-features, post-features,
			// Google Fonts, dynamic assets) are site-wide with no
			// per-post keys — the prior per-match native clearer ran
			// these N times as part of Divi's post-clear block. Call
			// once after the sweep to preserve the invalidation scope
			// without re-introducing the N × glob overhead. Gated on
			// `anything_flushed` so a no-op `after` call doesn't
			// force feature regeneration.
			$anything_flushed = $sweep['files'] > 0;
			if ( $anything_flushed ) {
				self::run_divi_feature_cache_purges();
			}

			// Write the DONOTCACHEPAGE sentinel once when anything was
			// actually flushed — matches Divi's post-clear behavior
			// (PageResource.php:1367-1368) so page-cache plugins / CDNs
			// skip caching the first regenerated request. Gate on
			// `files > 0` rather than `post_ids` non-empty: the sweep
			// also covers non-post subtrees (archive/taxonomy/home/
			// notfound/global), and deleting only those still warrants
			// the sentinel. Writing `.cache-cleared-at` is still
			// deliberately avoided — is_file_stale() would invalidate
			// the preserved `-vb-*` files against it.
			if ( $anything_flushed && is_dir( $cache_root ) ) {
				$wpfs->put_contents( $cache_root . '/DONOTCACHEPAGE', '' );
			}

			$response = [
				'mode'        => 'after',
				'backend'     => 'divi_native',
				'cache_root'  => $cache_root,
				'after'       => $after_ts,
				'flushed'     => $sweep['post_ids'],
				'skipped'     => $skipped,
				'files_freed' => $sweep['files'],
				'bytes_freed' => $sweep['bytes'],
			];
			// scope_note attaches to any non-empty flush so callers
			// understand broader side effects (invalidations, sentinel)
			// even when only non-post subtree files were deleted —
			// e.g. global/ CSS rewritten after the cutoff on a site
			// with no matching numeric posts. Same gate as the
			// sentinel write.
			if ( $anything_flushed ) {
				$response['scope_note'] = 'Single-pass WP_Filesystem sweep of et-cache/ deleting Divi CSS files (et-*.css) whose mtime > after, across numeric post dirs AND archive/taxonomy/home/notfound/global subtrees. Visual Builder (-vb-*) runtime CSS preserved to avoid unstyling an open VB session. Filter is per-file (not per-dir): older CSS co-located with a recently-rewritten file is left in place. Post-sweep invalidations: per-touched-post (et_core_clear_wp_cache + ET_Core_PageResource::clear_post_meta_caches, keyed to numeric post_ids) plus site-wide Divi feature-cache purges (ET_Builder_Module_Features / Post_Features / Google_Fonts_Feature / Dynamic_Assets_Feature — these have no per-post keys and were already run N times by the prior per-match native clearer). Non-post subtree deletions contribute to files_freed / bytes_freed but not flushed. DONOTCACHEPAGE sentinel written to cache root so page-cache plugins skip caching the first regenerated request. .cache-cleared-at timestamp deliberately NOT written — is_file_stale() would invalidate the preserved VB files against it.';
			}
			return self::envelope_success( $response );
		}

		// fs_fallback path — Divi inactive / stripped build. Per-post
		// iteration is already O(total_files) here because flush_et_cache_dir
		// uses direct unlink() (no native glob loop), so it doesn't hit the
		// scaling issue the native path had. Kept as-is to preserve the
		// prior dir-mtime filter semantic + per-post transient / post_meta
		// invalidations that flush_et_cache_dir performs.
		$matched = [];
		$skipped = [];
		foreach ( self::et_cache_numeric_post_ids( $cache_root ) as $pid ) {
			// Dir mtime alone is unreliable: Divi rewrites compiled CSS via
			// put_contents() on deterministic filenames
			// (et-core-unified-tb-*-{post_id}.min.css etc.), which updates
			// the file's mtime but NOT the parent dir's mtime (dir mtime
			// only changes on create/delete/rename inside the dir). So a
			// page re-rendered in place after the cutoff would silently
			// land in `skipped`. Walk dir + contents and take the latest.
			$latest = self::et_cache_dir_latest_mtime( $cache_root, $pid );
			if ( false === $latest || $latest <= $after_ts ) {
				$skipped[] = $pid;
				continue;
			}
			$matched[] = $pid;
		}

		$files_freed = 0;
		$bytes_freed = 0;
		foreach ( $matched as $pid ) {
			$snap = self::et_cache_dir_snapshot( $cache_root, $pid );
			$files_freed += $snap['files'];
			$bytes_freed += $snap['bytes'];
			self::flush_et_cache_dir( $cache_root, $pid );
		}

		return self::envelope_success( [
			'mode'        => 'after',
			'backend'     => 'fs_fallback',
			'cache_root'  => $cache_root,
			'after'       => $after_ts,
			'flushed'     => $matched,
			'skipped'     => $skipped,
			'files_freed' => $files_freed,
			'bytes_freed' => $bytes_freed,
		] );
	}

	/**
	 * Resolve Divi's compiled-CSS cache root. Prefers Divi's own resolver
	 * (ET_Core_PageResource::get_cache_directory → et_core_cache_dir()->path)
	 * which handles the ET_CORE_CACHE_DIR constant, the uploads-based
	 * fallback when WP_CONTENT_DIR isn't writable, and any multisite
	 * adjustments. Falls back to the wp-content default only when Divi
	 * is inactive (fs_fallback path).
	 *
	 * @return string Absolute filesystem path to the cache root.
	 */
	private static function resolve_et_cache_root() {
		if (
			class_exists( '\ET_Core_PageResource' )
			&& method_exists( '\ET_Core_PageResource', 'get_cache_directory' )
		) {
			$path = \ET_Core_PageResource::get_cache_directory();
			if ( is_string( $path ) && '' !== $path ) {
				return rtrim( $path, '/\\' );
			}
		}
		return WP_CONTENT_DIR . '/et-cache';
	}

	/**
	 * Snapshot size + file count of a per-post et-cache dir without deleting.
	 *
	 * @param string $cache_root
	 * @param int    $post_id
	 * @return array{existed: bool, files: int, bytes: int}
	 */
	private static function et_cache_dir_snapshot( $cache_root, $post_id ) {
		$dir    = $cache_root . '/' . intval( $post_id );
		$result = [ 'existed' => false, 'files' => 0, 'bytes' => 0 ];
		if ( ! is_dir( $dir ) ) {
			return $result;
		}
		$result['existed'] = true;
		foreach ( self::et_cache_walk_files( $dir ) as $file ) {
			$size = filesize( $file );
			if ( false !== $size ) {
				$result['bytes'] += $size;
			}
			$result['files']++;
		}
		return $result;
	}

	/**
	 * Recursively enumerate regular files under a directory. Divi's own
	 * clearer searches TB/WP-template CSS at multiple nesting depths under
	 * cache_dir (one, two, and three levels of subdir between the cache
	 * root and the file), so some site configurations produce nested
	 * cache layouts. Our per-post helpers must walk descendants rather
	 * than just direct children to avoid reporting a flush while leaving
	 * nested stale CSS behind.
	 *
	 * @param string $dir
	 * @return string[] Absolute file paths.
	 */
	private static function et_cache_walk_files( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return [];
		}
		$out = [];
		$it  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $it as $info ) {
			if ( $info->isFile() ) {
				$out[] = $info->getPathname();
			}
		}
		return $out;
	}

	/**
	 * Sum files + bytes across every numeric-named et-cache subdir, and
	 * return the list of post IDs seen. Used as a lower-bound "bytes freed"
	 * for `all` flushes; Divi's native clearer also touches sibling dirs +
	 * WP caches which this does not count. `post_ids` lets the native
	 * `all` path report `flushed` symmetrically with the other branches.
	 *
	 * @param string $cache_root
	 * @return array{files: int, bytes: int, post_ids: int[]}
	 */
	private static function et_cache_total_snapshot( $cache_root ) {
		$files    = 0;
		$bytes    = 0;
		$post_ids = self::et_cache_numeric_post_ids( $cache_root );
		foreach ( $post_ids as $pid ) {
			$snap   = self::et_cache_dir_snapshot( $cache_root, $pid );
			$files += $snap['files'];
			$bytes += $snap['bytes'];
		}
		return [ 'files' => $files, 'bytes' => $bytes, 'post_ids' => $post_ids ];
	}

	/**
	 * Return the most recent mtime across a per-post cache dir AND its
	 * contents. Dir mtime alone misses in-place file rewrites — Divi
	 * regenerates CSS via put_contents() on deterministic filenames, which
	 * bumps file mtime but not parent dir mtime.
	 *
	 * @param string $cache_root
	 * @param int    $post_id
	 * @return int|false Unix ts, or false if dir is missing / unreadable.
	 */
	private static function et_cache_dir_latest_mtime( $cache_root, $post_id ) {
		$dir = $cache_root . '/' . intval( $post_id );
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$latest = filemtime( $dir );
		if ( false === $latest ) {
			$latest = 0;
		}
		foreach ( self::et_cache_walk_files( $dir ) as $file ) {
			$m = filemtime( $file );
			if ( false !== $m && $m > $latest ) {
				$latest = $m;
			}
		}
		return $latest > 0 ? $latest : false;
	}

	/**
	 * Delete Divi CSS files that live outside numeric per-post dirs —
	 * root-level et-*.css files and the archive/taxonomy/home/notfound/
	 * search/global cache trees. Used only by the fs_fallback `all`
	 * branch, after per-post iteration; matches the scope Divi's native
	 * clearer covers in post_id='all' mode.
	 *
	 * Filter mirrors Divi's _is_valid_divi_css_file: basename starts with
	 * 'et-' AND ends with .css or .min.css. Preserves .data,
	 * .cache-cleared-at, DONOTCACHEPAGE, and any non-Divi files.
	 *
	 * Empty non-numeric subdirs are rmdir'd after sweeping so Divi can
	 * recreate them on the next render cycle.
	 *
	 * @param string $cache_root
	 * @return array{files: int, bytes: int}
	 */
	private static function sweep_non_post_divi_css( $cache_root ) {
		$result = [ 'files' => 0, 'bytes' => 0 ];
		if ( ! is_dir( $cache_root ) ) {
			return $result;
		}
		$entries = scandir( $cache_root );
		if ( false === $entries ) {
			return $result;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $cache_root . '/' . $entry;
			if ( is_file( $path ) ) {
				// Root-level et-*.css files.
				if ( self::is_divi_css_basename( $entry ) ) {
					$size = filesize( $path );
					if ( unlink( $path ) ) {
						$result['files']++;
						if ( false !== $size ) {
							$result['bytes'] += $size;
						}
					}
				}
				continue;
			}
			if ( ! is_dir( $path ) ) {
				continue;
			}
			// Skip numeric post dirs — those are handled by the per-post
			// iteration in the caller.
			if ( ctype_digit( $entry ) ) {
				continue;
			}
			// Walk non-numeric subdir (archive/, taxonomy/, home/, etc.),
			// delete et-*.css descendants, rmdir emptied dirs bottom-up.
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $it as $info ) {
				$child = $info->getPathname();
				if ( $info->isFile() ) {
					if ( ! self::is_divi_css_basename( $info->getFilename() ) ) {
						continue;
					}
					$size = filesize( $child );
					if ( unlink( $child ) ) {
						$result['files']++;
						if ( false !== $size ) {
							$result['bytes'] += $size;
						}
					}
				} elseif ( $info->isDir() ) {
					@rmdir( $child );
				}
			}
			@rmdir( $path );
		}
		return $result;
	}

	/**
	 * Lazily initialize the WP_Filesystem global and return it. Returns
	 * null if initialization fails (e.g. missing credentials on FTP/SSH
	 * hosts without saved creds). Used by the native `all` sweep so
	 * deletes go through the same API Divi itself uses
	 * (self::$wpfs->delete in _mark_global_cache_cleared) — direct
	 * unlink() would silently fail on hosts where Divi writes via
	 * credentials but PHP itself can't.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private static function init_wp_filesystem() {
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			return $wp_filesystem;
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( WP_Filesystem() && $wp_filesystem ) {
			return $wp_filesystem;
		}
		return null;
	}

	/**
	 * Run Divi's site-wide WP-cache purges — the block that normally runs
	 * AFTER the path-specific branch in do_remove_static_resources, but
	 * WITHOUT writing the .cache-cleared-at global timestamp.
	 *
	 * Why split: .cache-cleared-at immediately invalidates any file with
	 * older mtime via is_file_stale() (PageResource.php:1604-1610),
	 * including the `-vb-*` runtime CSS we deliberately keep in the
	 * native `all` phase-1 sweep. Writing the timestamp would silently
	 * undo the VB preservation and unstyle an open Visual Builder
	 * session on the next request.
	 *
	 * Each call is guarded: function_exists for et_core_clear_wp_cache,
	 * class_exists for the ET_Builder_* features (these can be missing
	 * on stripped builds even when ET_Core_PageResource is present).
	 * clear_post_meta_caches is a public static on ET_Core_PageResource
	 * so no separate guard beyond that class check is needed.
	 */
	private static function run_divi_site_wide_cache_purges() {
		if ( function_exists( 'et_core_clear_wp_cache' ) ) {
			et_core_clear_wp_cache( '' );
		}
		self::run_divi_feature_cache_purges();
		if (
			class_exists( '\ET_Core_PageResource' )
			&& method_exists( '\ET_Core_PageResource', 'clear_post_meta_caches' )
		) {
			\ET_Core_PageResource::clear_post_meta_caches( '' );
		}
	}

	/**
	 * Divi feature-cache purges — the module/post features, Google
	 * Fonts, and dynamic-assets caches that Divi maintains site-wide
	 * (no per-post keys exist for these; purges clear the whole cache
	 * and Divi lazily rebuilds on the next render).
	 *
	 * Split out from `run_divi_site_wide_cache_purges` so the native
	 * `after` branch can invoke just these without also running the
	 * post-keyed purges (`et_core_clear_wp_cache('')` /
	 * `clear_post_meta_caches('')`) — those are already covered
	 * per-touched-pid in the `after` path, and running them site-wide
	 * on top would over-invalidate on a partial flush.
	 *
	 * The prior per-match `ET_Core_PageResource::remove_static_resources`
	 * loop in `after` mode was invoking these as part of Divi's own
	 * post-clear block once per match; collapsing to a single
	 * site-wide call preserves the same invalidation scope without the
	 * N × glob overhead that motivated the sweep refactor.
	 */
	private static function run_divi_feature_cache_purges() {
		if ( class_exists( '\ET_Builder_Module_Features' ) ) {
			\ET_Builder_Module_Features::purge_cache();
		}
		if ( class_exists( '\ET_Builder_Post_Features' ) ) {
			\ET_Builder_Post_Features::purge_cache();
		}
		if ( class_exists( '\ET_Builder_Google_Fonts_Feature' ) ) {
			\ET_Builder_Google_Fonts_Feature::purge_cache();
		}
		if ( class_exists( '\ET_Builder_Dynamic_Assets_Feature' ) ) {
			\ET_Builder_Dynamic_Assets_Feature::purge_cache();
		}
	}

	/**
	 * Single-pass recursive walk of the entire et-cache tree, deleting
	 * every Divi CSS file (et-*.css / et-*.min.css) except those whose
	 * basename contains `-vb-` (VB runtime CSS preserved). Replaces the
	 * N × native-clear per-post loop for `all` mode — runtime was
	 * O(#post_ids × #total_files) because each ET_Core_PageResource call
	 * does ~7 glob scans of the whole tree. This helper does one walk
	 * regardless of cache size.
	 *
	 * Scope expansion over the old loop: previously archive/taxonomy/
	 * home/notfound/global CSS was only invalidated lazily via the
	 * `.cache-cleared-at` timestamp (pass 2). Now those files are
	 * physically deleted here alongside the per-post CSS, matching the
	 * scope Divi's own _mark_global_cache_cleared(delete_files=true)
	 * covers — but with the VB-preserve filter applied, which Divi's
	 * own mass path lacks.
	 *
	 * Rmdir empty directories bottom-up (CHILD_FIRST) so the cache tree
	 * collapses cleanly after the sweep.
	 *
	 * @param string              $cache_root
	 * @param \WP_Filesystem_Base $wpfs       Initialized WP_Filesystem instance — both deletes and rmdir routes through this so FTP/SSH-credentialed hosts work.
	 * @param int|null            $min_mtime  When non-null, only files with mtime strictly greater than this unix timestamp are deleted — used by `after` mode to avoid O(N × glob) per-post native calls. Files filtered out are left in place; their parent dirs may still be rmdir'd if unrelated deletions empty them. When null, every matching Divi CSS file is deleted (current `all` mode behavior).
	 * @return array{files: int, bytes: int, post_ids: int[]}
	 *   post_ids = numeric per-post dirs whose files were deleted
	 *   (useful for the `flushed` response field).
	 */
	private static function sweep_all_divi_css_preserving_vb( $cache_root, $wpfs, $min_mtime = null ) {
		$result = [ 'files' => 0, 'bytes' => 0, 'post_ids' => [] ];
		if ( ! is_dir( $cache_root ) ) {
			return $result;
		}
		$touched_post_ids = [];
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $cache_root, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $info ) {
			$path = $info->getPathname();
			if ( $info->isFile() ) {
				$basename = $info->getFilename();
				if ( ! self::is_divi_css_basename( $basename ) ) {
					continue;
				}
				// Preserve VB runtime CSS — matches Divi's
				// preserve_vb_files=true convention used across every
				// preset/global-data save path.
				if ( false !== strpos( $basename, '-vb-' ) ) {
					continue;
				}
				// `after` mode: skip files compiled at or before the
				// cutoff. getMTime() throws on unreadable files — the
				// surrounding iterator already filtered to readable
				// entries, but we guard defensively so a permission
				// race doesn't abort the whole sweep.
				if ( null !== $min_mtime ) {
					$mtime = @$info->getMTime();
					if ( false === $mtime || $mtime <= $min_mtime ) {
						continue;
					}
				}
				$size = filesize( $path );
				if ( $wpfs->delete( $path ) ) {
					$result['files']++;
					if ( false !== $size ) {
						$result['bytes'] += $size;
					}
					// Track numeric post dir from the first path segment
					// under cache_root. Divi can nest per-post files
					// deeper (e.g. taxonomy/category/name/et-...tb-{id}*
					// inside et-cache/{id}/), so a direct-parent check
					// under-reports touched posts. This walks the relative
					// path and claims the first numeric ancestor.
					$rel = substr( $path, strlen( $cache_root ) + 1 );
					$first_sep = strpos( $rel, '/' );
					$first = false === $first_sep ? '' : substr( $rel, 0, $first_sep );
					if ( '' !== $first && ctype_digit( $first ) ) {
						$touched_post_ids[ (int) $first ] = true;
					}
				}
			} elseif ( $info->isDir() ) {
				// Bottom-up rmdir via WP_Filesystem; $recursive=false
				// so non-empty dirs are no-ops (matches the @rmdir
				// behavior we had before). In `after` mode the dir
				// may still hold older untouched CSS — rmdir no-ops
				// and the dir is preserved, which is the correct
				// outcome for a filtered sweep.
				$wpfs->rmdir( $path, false );
			}
		}
		$result['post_ids'] = array_keys( $touched_post_ids );
		sort( $result['post_ids'] );
		return $result;
	}

	/**
	 * Is this basename a Divi-naming-convention CSS file? Mirrors Divi's
	 * ET_Core_PageResource::_is_valid_divi_css_file filter.
	 *
	 * @param string $basename
	 * @return bool
	 */
	private static function is_divi_css_basename( $basename ) {
		if ( 0 !== strpos( $basename, 'et-' ) ) {
			return false;
		}
		$len    = strlen( $basename );
		$ends_css     = $len >= 4 && substr( $basename, -4 ) === '.css';
		$ends_min_css = $len >= 8 && substr( $basename, -8 ) === '.min.css';
		return $ends_css || $ends_min_css;
	}

	/**
	 * Enumerate numeric-named subdirs of et-cache/ — our fallback + after
	 * iterator. Non-numeric siblings are skipped to preserve the "only
	 * per-post dirs" safety invariant in fs_fallback mode.
	 *
	 * @param string $cache_root
	 * @return int[]
	 */
	private static function et_cache_numeric_post_ids( $cache_root ) {
		if ( ! is_dir( $cache_root ) ) {
			return [];
		}
		$entries = scandir( $cache_root );
		if ( false === $entries ) {
			return [];
		}
		$ids = [];
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( ! ctype_digit( $entry ) ) {
				continue;
			}
			if ( ! is_dir( $cache_root . '/' . $entry ) ) {
				continue;
			}
			$ids[] = (int) $entry;
		}
		return $ids;
	}

	/**
	 * Remove a single per-post Divi cache dir and its CSS files (fallback
	 * path when ET_Core_PageResource is unavailable).
	 *
	 * Mirrors the existing invalidate_divi_cache helper to match behavior
	 * parity in environments without the native clearer:
	 *   1. Unlink *.css inside the numeric-named per-post dir
	 *   2. Touch post_modified to trigger Divi's style regeneration
	 *   3. Delete the `et_builder_css_{post_id}` transient
	 *   4. Delete the `_et_builder_module_features_cache` post meta
	 *
	 * Additionally rmdir's the now-empty dir to match the documented
	 * manual workaround `rm -rf wp-content/et-cache/{post_id}/`. Divi
	 * recreates the dir on the next render.
	 *
	 * @param string $cache_root Absolute path to et-cache/ (already validated).
	 * @param int    $post_id    Positive int — numeric subdir name to remove.
	 * @return array{existed: bool, files: int, bytes: int}
	 */
	private static function flush_et_cache_dir( $cache_root, $post_id ) {
		$dir    = $cache_root . '/' . intval( $post_id );
		$result = [ 'existed' => false, 'files' => 0, 'bytes' => 0 ];

		if ( is_dir( $dir ) ) {
			$result['existed'] = true;

			// Walk recursively — Divi's own clearer searches nested paths
			// (e.g. taxonomy/category/name/et-...tb-{id}*) and some site
			// configurations do produce nested cache files inside a post
			// dir. CHILD_FIRST so leaf files come out before their parent
			// dirs, letting us rmdir empties after the walk completes.
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $it as $info ) {
				$path = $info->getPathname();
				if ( $info->isFile() ) {
					// Measure before delete (size is unreadable after
					// unlink), but only count it toward `bytes` if the
					// unlink actually succeeded — otherwise the caller
					// sees phantom bytes for files still on disk.
					$size = filesize( $path );
					if ( unlink( $path ) ) {
						$result['files']++;
						if ( false !== $size ) {
							$result['bytes'] += $size;
						}
					}
				} elseif ( $info->isDir() ) {
					@rmdir( $path );
				}
			}

			// Remove the now-empty top-level dir. If something unexpected
			// lingers (hidden files, non-writable entries), rmdir silently
			// no-ops — the CSS files are gone, which is the
			// staleness-unblocking outcome users need.
			@rmdir( $dir );
		}

		// State-mutating invalidations only run when there was actually
		// something to flush. Bumping post_modified and deleting post meta
		// have observable side effects (feed order, sitemap modified-dates,
		// modified-date queries) — users explicitly calling a cache flush
		// on a post with no cache shouldn't have those side effects. The
		// existing invalidate_divi_cache helper (used by preset_update,
		// module_update, etc.) bumps unconditionally because those callers
		// are paired with a content change; this helper is a pure cache
		// flush with no implied content change.
		//
		// Transient deletion stays unconditional: delete_transient is a
		// no-op on a missing key, and it cleans up orphan transients from
		// deleted cache dirs.
		if ( $result['existed'] && get_post( $post_id ) ) {
			wp_update_post(
				[
					'ID'            => $post_id,
					'post_modified' => current_time( 'mysql' ),
				]
			);
			delete_post_meta( $post_id, '_et_builder_module_features_cache' );
		}
		delete_transient( 'et_builder_css_' . $post_id );

		return $result;
	}

	// ── Handshake ────────────────────────────────────────────────────

	/**
	 * Version handshake — verifies MCP server and WP plugin compatibility.
	 *
	 * Returns plugin version, API capabilities, and Divi status.
	 * Returns HTTP 426 (Upgrade Required) if the server version is too old.
	 */
	public static function handshake( $request ) {
		$server_version = sanitize_text_field( (string) $request->get_param( 'mcp_server_version' ) );

		// Check if the MCP server meets minimum required version.
		if ( version_compare( $server_version, self::MIN_SERVER_VERSION, '<' ) ) {
			return new WP_Error(
				'upgrade_required',
				sprintf(
					'MCP server version %s is below the minimum required %s. Please update the MCP server.',
					$server_version,
					self::MIN_SERVER_VERSION
				),
				[ 'status' => 426 ]
			);
		}

		$divi_active  = function_exists( 'et_get_option' );
		$divi_version = $divi_active && defined( 'ET_BUILDER_PRODUCT_VERSION' )
			? ET_BUILDER_PRODUCT_VERSION
			: null;

		// Per-tool capability map (#486). Key = post-rename tool slug,
		// value = bool true. Server's requireCapability() gate looks up
		// each plugin-touching tool by its slug at handler entry, so older
		// plugins fail fast on missing tools while everything else works.
		$capabilities = [];
		foreach ( self::CAPABILITIES as $key ) {
			$capabilities[ $key ] = true;
		}

		return rest_ensure_response( [
			'compatible'     => true,
			'plugin_version' => self::VERSION,
			'min_server'     => self::MIN_SERVER_VERSION,
			'divi'           => [
				'active'  => $divi_active,
				'version' => $divi_version,
			],
			'capabilities'   => $capabilities,
		] );
	}
}
