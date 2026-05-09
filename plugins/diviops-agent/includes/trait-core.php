<?php
/**
 * Trait DiviOps_Agent_Core
 *
 * Cross-namespace utilities (presets store, cache invalidation, deep merge).
 *
 * Part of the diviops-agent monolith split (#220). Mixed into
 * DiviOps_Agent via `use` in diviops-agent.php — `self::` calls and
 * class constants resolve as if these methods lived directly on the
 * class.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_Core {

	private static function get_nested_array_value( $source, $path, $default = null ) {
		$value = $source;
		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return $default;
			}
			$value = $value[ $key ];
		}

		return $value;
	}

	// ── Preset Management ───────────────────────────────────────────

	/**
	 * Get D5 presets from the standalone WP option.
	 */
	private static function get_d5_presets() {
		$raw = get_option( 'et_divi_builder_global_presets_d5', '' );
		$d5  = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
		return is_array( $d5 ) || is_object( $d5 ) ? (array) $d5 : [];
	}

	/**
	 * Save D5 presets to both storage locations.
	 */
	private static function save_d5_presets( $d5 ) {
		update_option( 'et_divi_builder_global_presets_d5', $d5, false );
		et_update_option( 'builder_global_presets_d5', $d5 );
	}

	/**
	 * Deep-merge two arrays — $overrides wins on leaf conflicts. Used to build the inline-strip
	 * comparison bag by merging preset.styleAttrs + preset.attrs.
	 */
	private static function _deep_merge( $base, $overrides ) {
		if ( ! is_array( $base ) ) {
			return $overrides;
		}
		if ( ! is_array( $overrides ) ) {
			return $base;
		}
		foreach ( $overrides as $key => $val ) {
			if ( isset( $base[ $key ] ) && is_array( $base[ $key ] ) && is_array( $val ) ) {
				$base[ $key ] = self::_deep_merge( $base[ $key ], $val );
			} else {
				$base[ $key ] = $val;
			}
		}
		return $base;
	}

	/**
	 * Invalidate Divi's static CSS cache for a post so style changes render immediately.
	 */
	private static function invalidate_divi_cache( $post_id ) {
		// Delete Divi's static CSS files for this post.
		$cache_dir = WP_CONTENT_DIR . '/et-cache/' . intval( $post_id );
		if ( is_dir( $cache_dir ) ) {
			$files = glob( $cache_dir . '/*' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						unlink( $file );
					}
				}
			}
		}

		// Touch the post modified date to trigger Divi's style regeneration.
		wp_update_post( [
			'ID'            => $post_id,
			'post_modified' => current_time( 'mysql' ),
		] );

		// Clear Divi's transient caches regardless of touch result.
		delete_transient( 'et_builder_css_' . $post_id );
		delete_post_meta( $post_id, '_et_builder_module_features_cache' );
	}

	// ── Envelope helpers ─────────────────────────────────────────────
	//
	// Standard response shape for every diviops-agent route:
	//   success: { ok: true,  data: <payload> }
	//   error:   { ok: false, error: { code, message, hint? } }
	//
	// Adoption is per-namespace. Routes that have not adopted yet still
	// emit their legacy raw shapes; the helper coexists with those during
	// the rollout window.
	//
	// HTTP status stays orthogonal to envelope shape: 200 on ok:true,
	// the real upstream status (typically 400/404/409/412/500) on ok:false.

	/**
	 * Wrap a payload in the success envelope.
	 *
	 * If `$data` is already shaped as `{ok: true, data: ...}` (e.g. from a
	 * dry_run plan response built before this helper existed), pass it
	 * through unchanged so we never double-wrap.
	 *
	 * @param mixed $data         Payload to put under `data`.
	 * @param int   $http_status  HTTP status (default 200).
	 * @return WP_REST_Response
	 */
	private static function envelope_success( $data, int $http_status = 200 ) {
		if (
			is_array( $data )
			&& array_key_exists( 'ok', $data )
			&& true === $data['ok']
			&& array_key_exists( 'data', $data )
			&& 2 === count( $data )
		) {
			$body = $data;
		} else {
			$body = [ 'ok' => true, 'data' => $data ];
		}
		$response = new WP_REST_Response( $body, $http_status );
		return $response;
	}

	/**
	 * Build the error envelope.
	 *
	 * @param string      $code         Machine-readable code from the diviops vocabulary
	 *                                  (not_found, invalid_input, wp_error, divi_error,
	 *                                  capability_missing, validation_failed, conflict)
	 *                                  or a namespace-prefixed extension (`<namespace>.<reason>`).
	 * @param string      $message      Human-readable description.
	 * @param string|null $hint         Optional remediation hint (next-call suggestion, fix step).
	 * @param int         $http_status  HTTP status (default 400). Use 404 for not_found,
	 *                                  409 for conflict, 412 for capability_missing,
	 *                                  500 for wp_error/divi_error if upstream is server-side.
	 * @param mixed       $data         Optional structured payload attached to the error envelope
	 *                                  (per the `error.data` extension documented in
	 *                                  diviops-server/src/envelope.ts and
	 *                                  references/tools.md "Response shape"). Pass `null` to omit.
	 *                                  Used for failure modes that carry machine-readable detail
	 *                                  beyond a code/message pair — e.g. `divi_error` from
	 *                                  render/validate exceptions sets `data = { detail: <full> }`
	 *                                  while the message is truncated for inline display.
	 * @return WP_REST_Response
	 */
	private static function envelope_error( string $code, string $message, ?string $hint = null, int $http_status = 400, $data = null ) {
		$error = [
			'code'    => $code,
			'message' => $message,
		];
		if ( null !== $hint && '' !== $hint ) {
			$error['hint'] = $hint;
		}
		if ( null !== $data ) {
			$error['data'] = $data;
		}
		$response = new WP_REST_Response(
			[ 'ok' => false, 'error' => $error ],
			$http_status
		);
		return $response;
	}

	/**
	 * Adapt a `WP_Error` to the envelope shape.
	 *
	 * - code    = `WP_Error::get_error_code()` (or `wp_error` fallback)
	 * - message = `WP_Error::get_error_message()`
	 * - hint    = `WP_Error::get_error_data()['hint']` if set
	 * - status  = `WP_Error::get_error_data()['status']` if set, else 500
	 *
	 * Use at REST-handler boundaries when an upstream call returned a
	 * `WP_Error` rather than throwing; preserves the upstream code so
	 * envelope consumers can dispatch on familiar WP error slugs.
	 *
	 * @param WP_Error $error
	 * @return WP_REST_Response
	 */
	private static function envelope_from_wp_error( $error ) {
		$code        = $error->get_error_code();
		$message     = $error->get_error_message();
		$data        = $error->get_error_data();
		$hint        = is_array( $data ) && isset( $data['hint'] ) ? (string) $data['hint'] : null;
		$http_status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
		return self::envelope_error(
			'' !== (string) $code ? (string) $code : 'wp_error',
			(string) $message,
			$hint,
			$http_status
		);
	}

	/**
	 * Translate a `WP_Error` from a private resolver helper into the canonical
	 * envelope shape used by `module_*` / `section_*` handlers.
	 *
	 * The helpers (`resolve_module_target`, `find_block`, `extract_section`,
	 * `find_and_replace_section`) emit a per-helper vocabulary
	 * (`module_not_found`, `section_not_found`, `block_not_found`, `no_match`,
	 * `invalid_occurrence`, `missing_target`, `ambiguous_target`,
	 * `unsupported_selector`, `invalid_auto_index`, `parse_error`) for
	 * backward compatibility of the private API.
	 *
	 * Handlers normalize that vocabulary onto the canonical envelope codes:
	 *
	 *   not_found     ← *_not_found / no_match  (with `target_kind` discriminator)
	 *   invalid_input ← invalid_*, missing_target, ambiguous_target,
	 *                   unsupported_selector  (with structured `error.data`)
	 *   divi_error    ← parse_error  (HTTP 500)
	 *
	 * `forbidden` is preserved as distinct from `capability_missing` (the
	 * latter is a handshake-layer signal; the former is a row-level
	 * WordPress auth signal).
	 *
	 * @param WP_Error $error       The helper-returned error.
	 * @param string   $target_kind The discriminator for the search miss
	 *                              ("module", "section", "block"). Forwarded
	 *                              into `error.data.target_kind` when the code
	 *                              is one of the search-miss family. Ignored
	 *                              for codes outside that family.
	 * @param int      $page_id     Page id forwarded into `error.data.page_id`.
	 * @return WP_REST_Response
	 */
	private static function envelope_from_helper_error( $error, string $target_kind, int $page_id = 0 ) {
		$code    = (string) $error->get_error_code();
		$message = (string) $error->get_error_message();
		$data    = $error->get_error_data();
		$context       = is_array( $data ) && isset( $data['context'] )       ? (string) $data['context']       : '';
		$target_mode   = is_array( $data ) && isset( $data['target_mode'] )   ? (string) $data['target_mode']   : '';
		$target_value  = is_array( $data ) && array_key_exists( 'target_value', $data ) ? $data['target_value'] : null;
		$received      = is_array( $data ) && array_key_exists( 'received', $data )      ? $data['received']      : null;
		$total_matches = is_array( $data ) && array_key_exists( 'total_matches', $data ) ? $data['total_matches'] : null;

		// Search-miss family — collapse onto canonical not_found with discriminator.
		// Q1 contract: { target_kind, target_mode, target_value, page_id, context? }.
		if ( in_array( $code, [ 'module_not_found', 'block_not_found', 'section_not_found', 'no_match' ], true ) ) {
			$err_data = [ 'target_kind' => $target_kind ];
			if ( '' !== $target_mode ) {
				$err_data['target_mode'] = $target_mode;
			}
			if ( null !== $target_value ) {
				$err_data['target_value'] = $target_value;
			}
			if ( $page_id > 0 ) {
				$err_data['page_id'] = $page_id;
			}
			if ( '' !== $context ) {
				$err_data['context'] = $context;
			}
			return self::envelope_error(
				'not_found',
				$message,
				'Use diviops_page_get_layout to verify available admin labels and auto_index targets.',
				404,
				$err_data
			);
		}

		// Field-level rejections — collapse onto invalid_input.
		// invalid_occurrence preserves received + total_matches symmetrically with
		// the direct module_update path so clients can write generic handlers.
		if ( in_array( $code, [ 'missing_target', 'ambiguous_target', 'unsupported_selector', 'invalid_auto_index', 'invalid_occurrence' ], true ) ) {
			$err_data = [ 'reason' => $code ];
			if ( '' !== $context ) {
				$err_data['context'] = $context;
			}
			if ( 'invalid_occurrence' === $code ) {
				$err_data['field']         = 'occurrence';
				$err_data['target_kind']   = $target_kind;
				if ( '' !== $target_mode ) {
					$err_data['target_mode'] = $target_mode;
				}
				if ( null !== $target_value ) {
					$err_data['target_value'] = $target_value;
				}
				if ( null !== $received ) {
					$err_data['received'] = $received;
				}
				if ( null !== $total_matches ) {
					$err_data['total_matches'] = $total_matches;
				}
			}
			return self::envelope_error(
				'invalid_input',
				$message,
				null,
				400,
				$err_data
			);
		}

		// Divi-side parse failures (malformed content) — surface as divi_error.
		if ( 'parse_error' === $code ) {
			return self::envelope_error(
				'divi_error',
				$message,
				'Re-save the page through the Visual Builder to regenerate canonical block markup.',
				500,
				$page_id > 0 ? [ 'page_id' => $page_id ] : null
			);
		}

		// Anything else — fall through to the generic adapter (preserves the
		// upstream code as-is).
		return self::envelope_from_wp_error( $error );
	}

	/**
	 * Translate `load_post_for_module_op`'s `not_found` / `forbidden` errors
	 * into the canonical envelope shape with structured `error.data`.
	 *
	 * `load_post_for_module_op` is shared by `module_lock`, `module_unlock`,
	 * and `module_clone`; this helper centralizes their identical post-load
	 * error normalization so each handler stays one-line on the unhappy path.
	 */
	private static function envelope_post_load_error( $error, int $page_id ) {
		$code = (string) $error->get_error_code();
		if ( 'not_found' === $code ) {
			return self::envelope_error(
				'not_found',
				"Page #{$page_id} not found.",
				'Verify the page id via diviops_page_list.',
				404,
				[ 'target_kind' => 'page', 'page_id' => $page_id ]
			);
		}
		if ( 'forbidden' === $code ) {
			return self::envelope_error(
				'forbidden',
				"Cannot edit page #{$page_id}.",
				'Authenticate as a user with edit rights to this post.',
				403,
				[ 'page_id' => $page_id ]
			);
		}
		return self::envelope_from_wp_error( $error );
	}

	/**
	 * Multibyte-safe truncation for envelope error messages.
	 *
	 * Render/validate exception messages can carry multi-kilobyte payloads
	 * (full stack traces, embedded block JSON dumps) and arrive as UTF-8
	 * (translated strings via `__()` flow through `WP_Error::get_error_message()`).
	 * MCP clients displaying `error.message` inline don't want that volume,
	 * so we cap at `$max` characters and stash the full detail in
	 * `error.data.detail` for callers that need it.
	 *
	 * Multibyte-safe (`mb_strlen` / `mb_substr` over `strlen` / `substr`) so a
	 * mid-codepoint cut never produces invalid UTF-8 in the truncated output.
	 *
	 * @param string $message Source message, possibly long and possibly UTF-8.
	 * @param int    $max     Character cap (default 500). Ellipsis is appended when truncation occurs.
	 * @return string         Original on-fit; otherwise `mb_substr(..., 0, $max) . '…'`.
	 */
	private static function truncate_envelope_message( string $message, int $max = 500 ): string {
		if ( mb_strlen( $message ) <= $max ) {
			return $message;
		}
		return mb_substr( $message, 0, $max ) . '…';
	}

	// ── dry_run plan helper ────────────────────────────────────────

	/**
	 * Build a standard dry_run plan response — { ok: true, data: { dry_run: true, plan: { summary, changes[, warnings ] } } }.
	 *
	 * Every conformant write handler returns this shape when `dry_run: true`. Extra
	 * envelope keys (e.g. preview metadata) may be added via `$extra`, but the plan slot
	 * itself stays uniform so callers can pattern-match without per-tool branching.
	 *
	 * Routes through `envelope_success` so the wire shape is byte-identical
	 * to the pre-envelope dry_run shape — single emit point, no double-wrap.
	 *
	 * @param string $summary  One-line human-readable description.
	 * @param array  $changes  Array of { kind, target, before?, after? } entries.
	 * @param array  $warnings Optional non-fatal advisories the apply path would surface.
	 * @param array  $extra    Optional sibling keys to merge into `data` (alongside dry_run+plan).
	 */
	private static function dry_run_response( $summary, $changes = [], $warnings = [], $extra = [] ) {
		$plan = [
			'summary' => (string) $summary,
			'changes' => array_values( $changes ),
		];
		if ( ! empty( $warnings ) ) {
			$plan['warnings'] = array_values( $warnings );
		}
		$data = array_merge(
			[
				'dry_run' => true,
				'plan'    => $plan,
			],
			$extra
		);
		return self::envelope_success( $data );
	}
}
