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

	private static function normalize_storage_array( $value ): ?array {
		if ( is_array( $value ) || is_object( $value ) ) {
			return (array) $value;
		}
		return null;
	}

	/**
	 * Normalize Divi block comment attributes before full-content writes.
	 *
	 * WordPress block attributes may contain HTML strings, but the serialized
	 * comment JSON must use serialize_block_attributes() escaping for unsafe
	 * bytes such as `<`, `>`, `&`, `--`, backslashes, and escaped quotes. This
	 * guard accepts raw HTML and canonical JSON escapes, rejects pseudo-escapes
	 * like `u003c`, then rewrites Divi block opener attrs canonically.
	 *
	 * @param string $content Full block markup.
	 * @return array{ok:bool,content?:string,changed?:int,error?:array}
	 */
	private static function normalize_divi_full_content_for_write( string $content ): array {
		$pattern = '/<!--\s+(\/)?wp:([A-Za-z0-9_-]+\/[A-Za-z0-9_-]+)(.*?)(\/)?-->/s';
		$changed = 0;
		$error   = null;

		$normalized = preg_replace_callback(
			$pattern,
			static function ( array $matches ) use ( &$changed, &$error ) {
				if ( null !== $error ) {
					return $matches[0];
				}

				$is_closer = ! empty( $matches[1] );
				$block     = $matches[2];
				$tail      = $matches[3];

				if ( $is_closer || 0 !== strpos( $block, 'divi/' ) ) {
					return $matches[0];
				}

				$trimmed_tail    = trim( $tail );
				$is_self_closing = false;
				if ( '' !== $trimmed_tail && '/' === substr( $trimmed_tail, -1 ) ) {
					$is_self_closing = true;
					$trimmed_tail    = rtrim( substr( $trimmed_tail, 0, -1 ) );
				}

				if ( '' === $trimmed_tail ) {
					return '<!-- wp:' . $block . ( $is_self_closing ? ' /-->' : ' -->' );
				}

				if ( '{' !== substr( $trimmed_tail, 0, 1 ) || '}' !== substr( $trimmed_tail, -1 ) ) {
					$error = [
						'message' => 'Divi block attributes must be a JSON object.',
						'block'   => $block,
						'preview' => substr( $trimmed_tail, 0, 120 ),
					];
					return $matches[0];
				}

				$pseudo_escape = self::find_malformed_block_attr_escape( $trimmed_tail );
				if ( null !== $pseudo_escape ) {
					$error = [
						'message' => 'Divi block attributes contain a malformed JSON unicode escape.',
						'block'   => $block,
						'escape'  => $pseudo_escape,
						'hint'    => 'Use canonical JSON escapes like \\u003c or raw HTML that can be normalized, not u003c without the backslash.',
					];
					return $matches[0];
				}

				$attrs = json_decode( $trimmed_tail, true );
				if ( ! is_array( $attrs ) || JSON_ERROR_NONE !== json_last_error() ) {
					$error = [
						'message'    => 'Divi block attributes are not valid JSON.',
						'block'      => $block,
						'json_error' => json_last_error_msg(),
						'preview'    => substr( $trimmed_tail, 0, 120 ),
					];
					return $matches[0];
				}

				$encoded = self::serialize_block_attrs_canonical( $attrs );
				if ( null === $encoded ) {
					$error = [
						'message' => 'Divi block attributes could not be JSON-encoded safely.',
						'block'   => $block,
					];
					return $matches[0];
				}

				$new_comment = '<!-- wp:' . $block . ' ' . $encoded . ( $is_self_closing ? ' /-->' : ' -->' );
				if ( $new_comment !== $matches[0] ) {
					$changed++;
				}
				return $new_comment;
			},
			$content
		);

		if ( null !== $error ) {
			return [ 'ok' => false, 'error' => $error ];
		}
		if ( null === $normalized ) {
			return [
				'ok'    => false,
				'error' => [
					'message' => 'Unable to scan Divi block attributes for safe serialization.',
				],
			];
		}

		return [ 'ok' => true, 'content' => $normalized, 'changed' => $changed ];
	}

	/**
	 * Mirror core serialize_block_attributes() while keeping tests runnable
	 * against minimal WordPress stubs.
	 *
	 * @param array $attrs Block attributes.
	 * @return string|null
	 */
	private static function serialize_block_attrs_canonical( array $attrs ): ?string {
		if ( function_exists( 'serialize_block_attributes' ) ) {
			return serialize_block_attributes( $attrs );
		}

		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $attrs, $flags );
		} else {
			$encoded = json_encode( $attrs, $flags );
		}
		if ( false === $encoded ) {
			return null;
		}

		return strtr(
			$encoded,
			[
				'\\\\' => '\\u005c',
				'--'   => '\\u002d\\u002d',
				'<'    => '\\u003c',
				'>'    => '\\u003e',
				'&'    => '\\u0026',
				'\\"'  => '\\u0022',
			]
		);
	}

	/**
	 * Detect HTML-ish unicode pseudo-escapes missing their required backslash.
	 *
	 * @param string $json Raw block attribute JSON.
	 * @return string|null
	 */
	private static function find_malformed_block_attr_escape( string $json ): ?string {
		if ( preg_match( '/(?<!\\\\)u00(?:3c|3e|26|22|5c|2d)/i', $json, $match ) ) {
			return $match[0];
		}
		return null;
	}

	// ── Storage-path contract (#719) ────────────────────────────────
	//
	// The Divi 5.5.x storage-path landscape is materially more complex than a
	// single hardcoded option key. Per #719's CONTRACT REVISED 2026-05-19
	// banner, the agent implements a uniform "read-probe + write-canonical +
	// audit-aggregates" contract per surface:
	//
	//   READ  — probe documented candidate paths in priority order; return
	//           content from the FIRST non-empty path with `_meta.source_path`
	//           + `_meta.probed_paths`. Never silently merge.
	//   WRITE — always target the canonical 5.5.x path; surface
	//           `_meta.legacy_path_detected` when a legacy path also holds
	//           content. Never auto-migrate.
	//   AUDIT — aggregate across all candidate paths with per-entry
	//           `_meta.entry_sources = { path, provenance }` + warnings for
	//           ID collisions, shape inconsistencies, and reserved-store
	//           anomalies (e.g. `_ng` non-empty).
	//
	// `_ng` (`et_divi_builder_global_presets_ng`) is D4-legacy, out-of-band.
	// Divi 5 only touches it for deletion-sync; never CREATE/UPDATE. The
	// agent NEVER writes to `_ng` and NEVER uses it as a D5 READ fallback.
	// AUDIT surfaces non-empty `_ng` content with `provenance: "legacy_d4_ng"`
	// (distinct from any D5 provenance) so consumers cannot confuse it with
	// canonical D5 entries. Source: GlobalPreset.php:461, 921 (D5 side) +
	// includes/builder/feature/global-presets/Settings.php (D4 side). Runtime
	// corroboration: 5.5.1→5.5.2 migration test confirms `_ng` byte-
	// identical-empty across the transition (see #719 comment thread).

	/**
	 * Candidate storage paths for the D5 preset surface, in READ priority
	 * order. `_ng` is OUT-OF-BAND and not listed here — AUDIT consults it
	 * separately via `audit_d5_preset_storage()`.
	 *
	 * Path shape:
	 *   - "option_key" → top-level WP option
	 *   - "et_divi.<sub>" → nested under the et_divi umbrella option (read
	 *     via `et_get_option(<sub>)` when et_options_stored_in_one_row()
	 *     resolves true — the standard Divi storage routing). Inner key is
	 *     plain `builder_global_presets_d5` with NO `et_divi_` prefix; the
	 *     #719 original-body reference to `et_divi.et_divi_builder_global_presets_d5`
	 *     was incorrect, banner-corrected.
	 */
	private static function d5_preset_paths(): array {
		return [
			[ 'path' => 'et_divi_builder_global_presets_d5',  'provenance' => 'd5_top_level' ],
			[ 'path' => 'et_divi.builder_global_presets_d5',  'provenance' => 'd5_nested_scratchpad' ],
		];
	}

	/**
	 * Read raw content at a single storage-path descriptor.
	 *
	 * Returns the deserialized array (possibly empty), or `null` if the path
	 * holds no usable content. Callers distinguish "empty array seeded" from
	 * "path not present" by null-check.
	 */
	private static function read_storage_path( string $path_str ) {
		if ( false !== strpos( $path_str, '.' ) ) {
			// Nested under et_divi umbrella. Inner key is the part after the dot.
			$parts = explode( '.', $path_str, 2 );
			if ( 'et_divi' !== $parts[0] ) {
				return null;
			}
			$raw = et_get_option( $parts[1] );
			if ( empty( $raw ) ) {
				return null;
			}
			$val = maybe_unserialize( $raw );
			return self::normalize_storage_array( $val );
		}
		// Top-level option.
		$raw = get_option( $path_str, '' );
		if ( empty( $raw ) ) {
			return null;
		}
		$val = maybe_unserialize( $raw );
		return self::normalize_storage_array( $val );
	}

	/**
	 * Probe a path list in priority order and return the first non-empty
	 * content plus the canonical `_meta` shape.
	 *
	 * Shape:
	 *   [
	 *     'data'         => array,        // first non-empty content (or [] when all empty)
	 *     'source_path'  => string|null,  // path that yielded content (null when all empty)
	 *     'probed_paths' => string[],     // all paths probed, in priority order
	 *   ]
	 *
	 * "Non-empty" means a non-empty array after the per-path normalization
	 * in `read_storage_path()`. An empty array seeded at the canonical path
	 * (e.g. `update_option(..., [])` on first save then trash) does NOT
	 * stop the probe; the next path gets a chance.
	 */
	private static function probe_storage_paths( array $candidates ): array {
		$probed_paths = [];
		$source_path  = null;
		$data         = [];
		foreach ( $candidates as $c ) {
			$probed_paths[] = $c['path'];
			if ( null !== $source_path ) {
				continue;
			}
			$content = self::read_storage_path( $c['path'] );
			if ( null !== $content && ! empty( $content ) ) {
				$data        = $content;
				$source_path = $c['path'];
			}
		}
		return [
			'data'         => $data,
			'source_path'  => $source_path,
			'probed_paths' => $probed_paths,
		];
	}

	// ── Preset Management ───────────────────────────────────────────

	/**
	 * Get D5 presets via priority-ordered read-probe.
	 *
	 * Probes the canonical-then-legacy D5 paths (`d5_preset_paths()`) and
	 * returns content from the first non-empty path. `_ng` is OUT-OF-BAND
	 * (#719 banner) — never consulted by READ.
	 *
	 * For callers that need the source-path provenance and probed_paths
	 * envelope shape, use `get_d5_presets_with_meta()`. This bare helper
	 * returns just the data array for backward compatibility with existing
	 * callers that don't yet thread `_meta` through.
	 */
	private static function get_d5_presets() {
		$probe = self::probe_storage_paths( self::d5_preset_paths() );
		return $probe['data'];
	}

	/**
	 * Get D5 presets WITH `_meta` shape — data + source_path + probed_paths.
	 *
	 * Returns:
	 *   [
	 *     'data'         => array,        // content from first non-empty path (or [] when all empty)
	 *     'source_path'  => string|null,  // path that yielded content (null when all empty)
	 *     'probed_paths' => string[],     // all D5 paths probed, in priority order
	 *   ]
	 *
	 * Used by LIST surfaces and by WRITE handlers that need to detect a
	 * `legacy_path_detected` hint when writing to the canonical path.
	 */
	private static function get_d5_presets_with_meta(): array {
		return self::probe_storage_paths( self::d5_preset_paths() );
	}

	/**
	 * Detect any legacy storage path that holds content beyond the canonical
	 * write target. Returns the legacy path string (e.g.
	 * `"et_divi.builder_global_presets_d5"`) when present, else null.
	 *
	 * Used after a WRITE to surface `_meta.legacy_path_detected` — agents do
	 * NOT auto-migrate; surfacing state is the contract.
	 */
	private static function detect_d5_legacy_path(): ?string {
		$canonical = 'et_divi_builder_global_presets_d5';
		foreach ( self::d5_preset_paths() as $c ) {
			if ( $c['path'] === $canonical ) {
				continue;
			}
			$content = self::read_storage_path( $c['path'] );
			if ( null !== $content && ! empty( $content ) ) {
				return $c['path'];
			}
		}
		return null;
	}

	/**
	 * Save D5 presets — canonical-target write only (#719 banner).
	 *
	 * Writes to the canonical 5.5.x storage path:
	 * `et_divi_builder_global_presets_d5` (top-level WP option).
	 *
	 * Previous behavior dual-wrote to `et_divi.builder_global_presets_d5`
	 * via `et_update_option('builder_global_presets_d5', ...)` as well —
	 * that dual-write created two synchronized copies that drift on substrate
	 * upgrades. Banner-mandated reduction to canonical-only.
	 *
	 * Never writes to `_ng` (`et_divi_builder_global_presets_ng`) — that
	 * store is D4-legacy and out-of-band per #719 banner. Divi 5's
	 * GlobalPreset only touches `_ng` for deletion-sync (and only in-process
	 * within VB save flows), not for CREATE/UPDATE. The agent does NOT
	 * implement deletion-sync in this PR.
	 */
	private static function save_d5_presets( $d5 ) {
		update_option( 'et_divi_builder_global_presets_d5', $d5, false );
	}

	/**
	 * Attach top-level `_meta` keys to a WP_REST_Response envelope.
	 *
	 * Merges into any existing `_meta` (preserving keys not in `$extra`) so
	 * callers can layer their own routing-provenance fields onto the
	 * server-side `idempotent` enrichment without clobbering. Returns the
	 * mutated response by reference for chaining-ergonomic use.
	 *
	 * Used by write paths to thread `_meta.canonical_path` + the optional
	 * `_meta.legacy_path_detected` hint per the #719 contract WRITE shape.
	 */
	private static function attach_meta( $response, array $extra ) {
		if ( ! ( $response instanceof WP_REST_Response ) ) {
			return $response;
		}
		$body          = $response->get_data();
		$existing_meta = ( is_array( $body ) && isset( $body['_meta'] ) && is_array( $body['_meta'] ) )
			? $body['_meta']
			: [];
		$body['_meta'] = array_merge( $existing_meta, $extra );
		$response->set_data( $body );
		return $response;
	}

	/**
	 * Build the standard WRITE `_meta` payload for a D5-preset mutation.
	 *
	 * Always sets `canonical_path`; conditionally adds `legacy_path_detected`
	 * when a legacy D5 storage path also holds content. Does NOT trigger any
	 * write — purely advisory.
	 */
	private static function d5_preset_write_meta(): array {
		$meta = [ 'canonical_path' => 'et_divi_builder_global_presets_d5' ];
		$legacy = self::detect_d5_legacy_path();
		if ( null !== $legacy ) {
			$meta['legacy_path_detected'] = $legacy;
		}
		return $meta;
	}

	/**
	 * Flatten the D5 registry shape into audit rows keyed by actual preset UUID.
	 *
	 * D5 storage is bucketed as:
	 *   { module: { <moduleName>: { items: { <uuid>: <preset> } } },
	 *     group:  { <groupName>:  { items: { <uuid>: <preset> } } } }
	 *
	 * AUDIT provenance is per preset UUID, not per top-level bucket.
	 */
	private static function collect_d5_preset_audit_entries( array $registry ): array {
		$rows = [];
		foreach ( [ 'module', 'group' ] as $bucket ) {
			$bucket_items = self::normalize_storage_array( $registry[ $bucket ] ?? null );
			if ( null === $bucket_items ) {
				continue;
			}
			foreach ( $bucket_items as $bucket_key => $bucket_data ) {
				if ( ! is_string( $bucket_key ) ) {
					continue;
				}
				$bucket_data = self::normalize_storage_array( $bucket_data );
				if ( null === $bucket_data ) {
					continue;
				}
				$items = self::normalize_storage_array( $bucket_data['items'] ?? null );
				if ( null === $items ) {
					continue;
				}
				foreach ( $items as $preset_id => $entry ) {
					if ( ! is_string( $preset_id ) ) {
						continue;
					}
					$rows[] = [
						'id'         => $preset_id,
						'entry'      => self::normalize_storage_array( $entry ) ?? $entry,
						'bucket'     => $bucket,
						'bucket_key' => $bucket_key,
					];
				}
			}
		}
		return $rows;
	}

	/**
	 * Flatten the D4 `_ng` legacy shape into audit rows keyed by preset ID.
	 *
	 * D4 stores by module slug:
	 *   { <d4ModuleSlug>: { presets: { <presetId>: <preset> }, default: ... } }
	 */
	private static function collect_legacy_ng_preset_audit_entries( array $registry ): array {
		$rows = [];
		foreach ( $registry as $module_slug => $module_data ) {
			if ( ! is_string( $module_slug ) ) {
				continue;
			}
			$module_data = self::normalize_storage_array( $module_data );
			if ( null === $module_data ) {
				continue;
			}
			$presets = self::normalize_storage_array( $module_data['presets'] ?? null );
			if ( null === $presets ) {
				continue;
			}
			foreach ( $presets as $preset_id => $entry ) {
				if ( ! is_string( $preset_id ) ) {
					continue;
				}
				$rows[] = [
					'id'         => $preset_id,
					'entry'      => self::normalize_storage_array( $entry ) ?? $entry,
					'bucket'     => 'legacy_ng',
					'bucket_key' => $module_slug,
				];
			}
		}
		return $rows;
	}

	/**
	 * Aggregate AUDIT across all D5 preset storage paths PLUS the OUT-OF-BAND
	 * `_ng` (`et_divi_builder_global_presets_ng`) legacy D4 store.
	 *
	 * Returns:
	 *   [
	 *     'aggregated'    => array<string,mixed>,  // union of entries by ID (D5 entries; first-write-wins per priority)
	 *     'probed_paths'  => string[],             // all paths consulted (D5 priority list + _ng)
	 *     'entry_sources' => array<string,array{path:string,provenance:string}>,
	 *     'warnings'      => array<int,array{type:string,...}>,
	 *   ]
	 *
	 * Provenance vocabulary:
	 *   - "d5_top_level"          — canonical 5.5.x path
	 *   - "d5_nested_scratchpad"  — legacy nested migration scratchpad
	 *   - "legacy_d4_ng"          — D4-era `_ng` store (out-of-band)
	 *
	 * Warning vocabulary:
	 *   - "id_collision"       — same ID in two D5 paths with shape divergence
	 *   - "shape_inconsistency" — same ID, same value-shape topology mismatch
	 *   - "ng_non_empty"       — `_ng` holds content (anomaly; surface for manual review)
	 *
	 * `_ng` content is NEVER merged into the D5-aggregated entries; it is
	 * surfaced in its own `entry_sources` entries with `legacy_d4_ng`
	 * provenance + an `ng_non_empty` warning. Consumers filtering for
	 * canonical D5 by `provenance` cannot accidentally include `_ng` content.
	 */
	private static function audit_d5_preset_storage(): array {
		$aggregated    = [];
		$entry_sources = [];
		$warnings      = [];
		$probed_paths  = [];

		// D5 paths — priority-ordered. First write wins on ID collision; we
		// still record the collision in warnings so consumers can reconcile.
		foreach ( self::d5_preset_paths() as $c ) {
			$probed_paths[] = $c['path'];
			$content        = self::read_storage_path( $c['path'] );
			if ( null === $content || empty( $content ) ) {
				continue;
			}
			foreach ( self::collect_d5_preset_audit_entries( $content ) as $row ) {
				$id    = $row['id'];
				$entry = $row['entry'];
				if ( isset( $aggregated[ $id ] ) ) {
					$shape_match = self::entries_shape_match( $aggregated[ $id ], $entry );
					$warnings[]  = [
						'type'        => $shape_match ? 'id_collision' : 'shape_inconsistency',
						'id'          => $id,
						'first_path'  => $entry_sources[ $id ]['path'] ?? null,
						'second_path' => $c['path'],
						'bucket'      => $row['bucket'],
						'bucket_key'  => $row['bucket_key'],
					];
					continue; // First-write wins by priority.
				}
				$aggregated[ $id ]    = $entry;
				$entry_sources[ $id ] = [
					'path'       => $c['path'],
					'provenance' => $c['provenance'],
					'bucket'     => $row['bucket'],
					'bucket_key' => $row['bucket_key'],
				];
			}
		}

		// `_ng` — out-of-band D4-legacy store. Probe and surface separately.
		$ng_path        = 'et_divi_builder_global_presets_ng';
		$probed_paths[] = $ng_path;
		$ng_content     = self::read_storage_path( $ng_path );
		if ( null !== $ng_content && ! empty( $ng_content ) ) {
			$ng_entries = self::collect_legacy_ng_preset_audit_entries( $ng_content );
			$warnings[] = [
				'type'        => 'ng_non_empty',
				'path'        => $ng_path,
				'entry_count' => count( $ng_entries ),
				'hint'        => 'Legacy D4 preset store contains content. Divi 5 does not consume this for primary storage; surfaced for inventory only. Never merge into D5 entries.',
			];
			foreach ( $ng_entries as $row ) {
				// `_ng` entries get their OWN entry_sources record with the
				// legacy_d4_ng provenance. They do NOT enter $aggregated (which
				// is D5-only by contract).
				$entry_sources[ $row['id'] ] = [
					'path'       => $ng_path,
					'provenance' => 'legacy_d4_ng',
					'bucket'     => $row['bucket'],
					'bucket_key' => $row['bucket_key'],
				];
			}
		}

		return [
			'aggregated'    => $aggregated,
			'probed_paths'  => $probed_paths,
			'entry_sources' => $entry_sources,
			'warnings'      => $warnings,
		];
	}

	/**
	 * Compare two preset registry entries for structural shape match.
	 *
	 * Used by `audit_d5_preset_storage()` to discriminate `id_collision`
	 * (same id, same shape — caller can pick a winner) from
	 * `shape_inconsistency` (same id, different shape — caller must
	 * reconcile manually).
	 *
	 * Compares top-level keys only — preset entries are deeply nested and a
	 * recursive shape-diff would generate noise on cosmetic differences (e.g.
	 * key ordering) that don't materially affect rendering. If both entries
	 * carry the same top-level keys (regardless of value), they're treated
	 * as the same shape; structural divergence at the top level is the
	 * signal that matters for reconciliation.
	 */
	private static function entries_shape_match( $a, $b ): bool {
		$a = self::normalize_storage_array( $a );
		$b = self::normalize_storage_array( $b );
		if ( null === $a || null === $b ) {
			return false;
		}
		$a_keys = array_keys( $a );
		$b_keys = array_keys( $b );
		sort( $a_keys );
		sort( $b_keys );
		return $a_keys === $b_keys;
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
	 * Resolve the source-of-truth content string for tools that accept either
	 * an inline `content` string OR a `page_id` to read `post_content` from
	 * the database.
	 *
	 * Used by `render_block_markup` and `validate_blocks` (and any future tool
	 * adopting the exactly-one-of-{content,page_id} input contract).
	 *
	 * Contract:
	 *   - Both supplied OR neither supplied → `invalid_input` envelope (400).
	 *   - `page_id` must be a positive integer → `invalid_input` (400).
	 *   - Caller must hold `edit_post` capability on the page → `forbidden` (403).
	 *   - Post must exist → `not_found` (404).
	 *
	 * On success returns the resolved `$content` string. On failure returns
	 * a `WP_REST_Response` envelope ready to be returned from the handler.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return string|WP_REST_Response Resolved content string on success,
	 *                                 envelope error response on failure.
	 */
	private static function resolve_content_or_page_id( $request ) {
		$content = $request->get_param( 'content' );
		$page_id = $request->get_param( 'page_id' );

		$has_content = is_string( $content );
		$has_page_id = null !== $page_id;

		if ( $has_content && $has_page_id ) {
			return self::envelope_error(
				'invalid_input',
				'Provide exactly one of {content, page_id}, not both.',
				'Use `content` for ad-hoc/pre-save markup; use `page_id` to read post_content from the DB.',
				400
			);
		}
		if ( ! $has_content && ! $has_page_id ) {
			return self::envelope_error(
				'invalid_input',
				'Provide exactly one of {content, page_id}.',
				null,
				400
			);
		}

		if ( $has_page_id ) {
			$page_id = (int) $page_id;
			if ( $page_id <= 0 ) {
				return self::envelope_error(
					'invalid_input',
					'page_id must be a positive integer.',
					null,
					400
				);
			}
			// Existence check FIRST: `current_user_can('edit_post', $id)` on a
			// non-existent post returns false (no post to test against), which
			// would surface as `forbidden` and mask the real condition. Resolve
			// existence before capability so missing pages get `not_found` and
			// only real auth misses get `forbidden`.
			$post = get_post( $page_id );
			if ( ! $post ) {
				return self::envelope_error(
					'not_found',
					sprintf( 'Post %d not found.', $page_id ),
					null,
					404
				);
			}
			if ( ! current_user_can( 'edit_post', $page_id ) ) {
				return self::envelope_error(
					'forbidden',
					sprintf( 'Cannot edit post %d.', $page_id ),
					'The current user does not have edit_post capability for this page.',
					403
				);
			}
			return (string) $post->post_content;
		}

		return (string) $content;
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
