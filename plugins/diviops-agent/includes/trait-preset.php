<?php
/**
 * Trait DiviOps_Agent_Preset
 *
 * Preset CRUD, audit, cleanup, reassign, set-default, scan-orphans.
 *
 * Part of the diviops-agent monolith split (#220). Mixed into
 * DiviOps_Agent via `use` in diviops-agent.php — `self::` calls and
 * class constants resolve as if these methods lived directly on the
 * class.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_Preset {

	/**
	 * Get all presets (Divi 5 + legacy Divi 4).
	 *
	 * Storage-path contract (#719): `divi5_presets` is sourced via the
	 * priority-ordered probe in `get_d5_presets_with_meta()` — top-level
	 * `et_divi_builder_global_presets_d5` first, then nested
	 * `et_divi.builder_global_presets_d5` legacy scratchpad. `_ng`
	 * (`et_divi_builder_global_presets_ng`) is OUT-OF-BAND per the banner
	 * — surfaced here under `legacy_presets` for inventory/diagnostic
	 * symmetry only; it is NOT a D5 READ fallback, NEVER written by
	 * create/update/delete, and tagged with `provenance: "legacy_d4_ng"`
	 * in the `diviops_preset_audit_storage` aggregate view.
	 *
	 * Response carries top-level `_meta.source_path` + `_meta.probed_paths`
	 * so callers can distinguish "actually empty" from "we probed the
	 * wrong place." `_meta.legacy_path_detected` is set when a legacy D5
	 * path also holds content (advisory; no auto-migration).
	 */
	public static function preset_list( $request ) {
		// Divi 5 presets — read-probe with provenance (#719 contract).
		$probe = self::get_d5_presets_with_meta();
		$d5    = $probe['data'];

		// Legacy Divi 4 / out-of-band `_ng` store. Surfaced for visibility;
		// per #719 banner this is NOT a D5 fallback — `audit_storage` tags
		// it with `legacy_d4_ng` provenance for unambiguous classification.
		$d4_raw = get_option( 'et_divi_builder_global_presets_ng', '' );
		$d4     = ! empty( $d4_raw ) ? maybe_unserialize( $d4_raw ) : [];

		// Also get from et_global_data presets.
		$global_raw     = et_get_option( 'et_global_data', '' );
		$global_data    = ! empty( $global_raw ) ? maybe_unserialize( $global_raw ) : [];
		$global_presets = is_array( $global_data ) ? ( $global_data['presets'] ?? [] ) : [];

		// Detect legacy-path-also-populated state for advisory _meta.
		$legacy_path = null;
		if ( 'et_divi_builder_global_presets_d5' === $probe['source_path'] ) {
			$legacy_path = self::detect_d5_legacy_path();
		}

		$meta = [
			'source_path'  => $probe['source_path'],
			'probed_paths' => $probe['probed_paths'],
		];
		if ( null !== $legacy_path ) {
			$meta['legacy_path_detected'] = $legacy_path;
		}

		$response = self::envelope_success( [
			'divi5_presets'  => $d5,
			'legacy_presets' => $d4,
			'global_presets' => $global_presets,
		] );
		// Top-level `_meta` is preserved through `serializeEnvelope` per the
		// envelope contract (envelope.ts:290-297). Attach after
		// envelope_success() so the merge on the server side enriches with
		// `idempotent` without clobbering our routing-provenance fields.
		$body         = $response->get_data();
		$body['_meta'] = $meta;
		$response->set_data( $body );
		return $response;
	}

	/**
	 * Audit the D5 preset storage landscape — aggregate across all candidate
	 * paths with per-entry provenance and warnings.
	 *
	 * Storage-path contract (#719): the aggregate union is D5-only — entries
	 * surfaced from `_ng` (`et_divi_builder_global_presets_ng`) appear ONLY
	 * via `_meta.entry_sources` with `provenance: "legacy_d4_ng"` and the
	 * top-level `ng_non_empty` warning. They never enter `aggregated[]`.
	 *
	 * Response shape:
	 *   {
	 *     ok: true,
	 *     data: {
	 *       aggregated:   { <id>: <entry>, ... }   // D5-only union
	 *     },
	 *     _meta: {
	 *       probed_paths:  [ ... ],
	 *       entry_sources: { <id>: { path, provenance } },
	 *       warnings:      [ { type, ... }, ... ]
	 *     }
	 *   }
	 */
	public static function preset_audit_storage( $request ) {
		$audit = self::audit_d5_preset_storage();

		$response = self::envelope_success( [
			'aggregated' => $audit['aggregated'],
		] );
		$body = $response->get_data();
		$body['_meta'] = [
			'probed_paths'  => $audit['probed_paths'],
			'entry_sources' => $audit['entry_sources'],
			'warnings'      => $audit['warnings'],
		];
		$response->set_data( $body );
		return $response;
	}

	/**
	 * Collect preset UUIDs referenced in page/post block markup.
	 *
	 * Uses `parse_blocks()` + recursion into `innerBlocks` so the scan is
	 * structurally scoped: we only pick up UUIDs from `attrs.modulePreset`
	 * and `attrs.groupPreset.<slot>.presetId`. An earlier regex approach
	 * false-matched unrelated `"presetId"` keys that Divi uses elsewhere in
	 * block attrs (e.g. `module.decoration.interactions[].presetId`).
	 *
	 * `presetId` in `groupPreset` slots is accepted as both an array and a
	 * bare string — Divi accepts both via the stacking convention, and older
	 * or hand-edited blocks may serialize as a string.
	 */
	private static function collect_page_preset_refs() {
		$posts = get_posts( [
			'post_type'      => [ 'page', 'post' ],
			'post_status'    => [ 'publish', 'draft', 'private' ],
			'posts_per_page' => -1,
		] );

		$all_uuids = [];
		$per_page  = [];

		foreach ( $posts as $p ) {
			$content = $p->post_content;

			// Cheap string pre-check avoids parse_blocks() (O(content length)
			// tokenizer) on posts that can't possibly contain preset refs —
			// the audit is an admin-only op but preset_cleanup runs here too,
			// and large sites have thousands of non-Divi posts.
			if ( false === strpos( $content, '"modulePreset"' ) && false === strpos( $content, '"groupPreset"' ) ) {
				continue;
			}

			$blocks     = parse_blocks( $content );
			$page_uuids = [];
			$ref_count  = 0;
			self::walk_blocks_for_preset_refs( $blocks, $all_uuids, $page_uuids, $ref_count );

			if ( ! empty( $page_uuids ) ) {
				$per_page[ $p->ID ] = [
					'title'        => $p->post_title,
					'total_refs'   => $ref_count,
					'custom_uuids' => array_values( array_unique( $page_uuids ) ),
				];
			}
		}

		return [ 'all_uuids' => $all_uuids, 'per_page' => $per_page ];
	}

	/**
	 * Recursively walk a parsed-blocks tree collecting modulePreset +
	 * groupPreset.<slot>.presetId UUID references. Updates counters by ref.
	 *
	 * Empty strings and `'default'` sentinels are skipped so bogus entries
	 * (e.g. unset interaction presetId that slipped through in some other
	 * scope) can never inflate ref counts. `$ref_count` is only incremented
	 * when a container actually yielded at least one valid UUID, so
	 * `per_page[...]['total_refs']` stays consistent with `custom_uuids`.
	 */
	private static function walk_blocks_for_preset_refs( $blocks, &$all_uuids, &$page_uuids, &$ref_count ) {
		foreach ( $blocks as $block ) {
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];

			if ( isset( $attrs['modulePreset'] ) ) {
				// Accept both the canonical array form and the scalar string
				// form — the latter can appear in hand-edited or legacy block
				// markup, and matches the defensive pattern we use for
				// groupPreset.<slot>.presetId below.
				$uuids = is_array( $attrs['modulePreset'] )
					? $attrs['modulePreset']
					: [ $attrs['modulePreset'] ];
				$found = false;
				foreach ( $uuids as $uuid ) {
					if ( is_string( $uuid ) && '' !== $uuid && 'default' !== $uuid ) {
						$all_uuids[ $uuid ] = ( $all_uuids[ $uuid ] ?? 0 ) + 1;
						$page_uuids[]       = $uuid;
						$found              = true;
					}
				}
				if ( $found ) {
					$ref_count++;
				}
			}

			if ( isset( $attrs['groupPreset'] ) && is_array( $attrs['groupPreset'] ) ) {
				foreach ( $attrs['groupPreset'] as $slot ) {
					if ( ! is_array( $slot ) || ! isset( $slot['presetId'] ) ) {
						continue;
					}
					$ids   = is_array( $slot['presetId'] ) ? $slot['presetId'] : [ $slot['presetId'] ];
					$found = false;
					foreach ( $ids as $uuid ) {
						if ( is_string( $uuid ) && '' !== $uuid && 'default' !== $uuid ) {
							$all_uuids[ $uuid ] = ( $all_uuids[ $uuid ] ?? 0 ) + 1;
							$page_uuids[]       = $uuid;
							$found              = true;
						}
					}
					if ( $found ) {
						$ref_count++;
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk_blocks_for_preset_refs( $block['innerBlocks'], $all_uuids, $page_uuids, $ref_count );
			}
		}
	}

	/**
	 * Collect group-preset UUIDs referenced via the in-registry chain.
	 *
	 * Divi 5.3.0+ stores chain refs in two distinct shapes depending on the bucket:
	 * - Module-bucket presets: TOP-LEVEL `preset.groupPresets.<slot>.presetId` (plural).
	 *   Matches the REST schema at `GlobalPresetController.php:309` (declared as sibling of
	 *   `attrs`/`renderAttrs`/`styleAttrs`) and the reader path in `GlobalPreset.php:1486, 2274`.
	 *   The VB bundle's `generateNewPreset` assigns `m.groupPresets = i` at the preset root.
	 * - Group-bucket presets: NESTED `preset.attrs.groupPreset.<slot>.presetId` (singular).
	 *   Matches the reader at `GlobalPreset.php:1510, 2394` and the VB bundle's
	 *   `extractGroupPresetsFromAttrs` which reads `e?.groupPreset` off the attrs bag.
	 *
	 * Without walking both shapes, every chain-only group preset (font, border, box-shadow,
	 * spacing, button, etc.) reports `ref_count: 0` and gets flagged as orphaned by audit +
	 * cleanup workflows — even though deleting them silently breaks the module presets that
	 * pull them in. The dual-shape walker is the canonical reference-count path; the prior
	 * single-shape walker missed every group-preset chain ref.
	 *
	 * `presetId` in either shape is sometimes a single string and sometimes an array (Divi
	 * accepts both via the stacking convention) — handle both.
	 */
	private static function collect_group_chain_refs( $d5 ) {
		$counts        = [];
		// Build `referenced_by` with the referencing UUID as KEY (not value)
		// so deduplication is O(1) isset() rather than O(N) in_array() inside
		// the nested walker. Flatten to indexed arrays at the end so the
		// returned shape matches consumer expectations.
		$referenced_by = [];
		foreach ( [ 'module', 'group' ] as $type ) {
			if ( ! isset( $d5[ $type ] ) ) {
				continue;
			}
			foreach ( (array) $d5[ $type ] as $info ) {
				$info  = (array) $info;
				$items = isset( $info['items'] ) ? (array) $info['items'] : [];
				foreach ( $items as $referencing_uuid => $preset ) {
					$slots = self::_extract_chain_slot_map( $preset, $type );
					foreach ( $slots as $slot ) {
						$slot = (array) $slot;
						if ( ! isset( $slot['presetId'] ) ) {
							continue;
						}
						$ids = is_array( $slot['presetId'] ) ? $slot['presetId'] : [ $slot['presetId'] ];
						foreach ( $ids as $gid ) {
							if ( ! is_string( $gid ) || '' === $gid || 'default' === $gid ) {
								continue;
							}
							$counts[ $gid ] = ( $counts[ $gid ] ?? 0 ) + 1;
							$referenced_by[ $gid ][ $referencing_uuid ] = true;
						}
					}
				}
			}
		}
		foreach ( $referenced_by as $gid => $set ) {
			$referenced_by[ $gid ] = array_keys( $set );
		}
		return [ 'counts' => $counts, 'referenced_by' => $referenced_by ];
	}

	/**
	 * Read a preset item's chain-ref slot map at the bucket's canonical location.
	 *
	 * Returned shape is the `{ <slot>: { presetId: <scalar|array>, groupName: <string> } }` map.
	 * Returns `[]` when no chain refs are present.
	 *
	 * Defensively casts each nested level from array-or-object — the D5 option can round-trip
	 * through JSON or a custom importer and land with stdClass at any depth.
	 */
	private static function _extract_chain_slot_map( $preset, string $bucket ): array {
		if ( ! is_array( $preset ) && ! is_object( $preset ) ) {
			return [];
		}
		$preset = (array) $preset;
		if ( 'module' === $bucket ) {
			if ( ! isset( $preset['groupPresets'] ) ) {
				return [];
			}
			if ( ! is_array( $preset['groupPresets'] ) && ! is_object( $preset['groupPresets'] ) ) {
				return [];
			}
			return (array) $preset['groupPresets'];
		}
		if ( 'group' === $bucket ) {
			$attrs = isset( $preset['attrs'] ) ? (array) $preset['attrs'] : [];
			if ( ! isset( $attrs['groupPreset'] ) ) {
				return [];
			}
			if ( ! is_array( $attrs['groupPreset'] ) && ! is_object( $attrs['groupPreset'] ) ) {
				return [];
			}
			return (array) $attrs['groupPreset'];
		}
		return [];
	}

	/**
	 * Write a preset item's chain-ref slot map back to its canonical location.
	 *
	 * Module-bucket only — the chain ref lives at top-level `groupPresets` and is not mirrored
	 * anywhere else. The group-bucket write is deliberately not handled here because the
	 * rewriter needs per-bag surgical control across attrs / styleAttrs / renderAttrs to
	 * preserve the dual-pass CSS lockstep (`preset_update` mirrors attrs → all three bags).
	 * See `_rewrite_registry_group_chains` for the group-bucket write path.
	 */
	private static function _write_chain_slot_map( array $preset, string $bucket, array $slot_map ): array {
		if ( 'module' === $bucket ) {
			$preset['groupPresets'] = $slot_map;
		}
		return $preset;
	}

	/**
	 * Detect spam preset names using generalized heuristics.
	 *
	 * A preset name is considered spam when it contains a repeated word or phrase
	 * (e.g. "Online Courses Online Courses Text") — a Divi bug that duplicates
	 * the module name prefix when presets are auto-created.
	 */
	private static function is_spam_preset_name( $name ) {
		if ( '' === $name ) {
			return false;
		}
		// Detect repeated word or multi-word phrases (e.g. "Button Button", "Online Courses Online Courses").
		if ( preg_match( '/\b([\p{L}\p{N}_]+(?:\s+[\p{L}\p{N}_]+){0,3})\s+\1\b/iu', $name ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Clean a spam preset name by collapsing repeated prefixes.
	 */
	private static function clean_spam_preset_name( $name ) {
		// Collapse all repeated word sequences at the start (e.g. "Online Courses Online Courses Online Courses Text" → "Online Courses Text").
		return trim( preg_replace( '/^((?:\S+\s+)*?\S+)(?:\s+\1\b)+/iu', '$1', $name ) );
	}

	/**
	 * Audit presets: categorize as spam/descriptive, referenced/unreferenced.
	 */
	public static function preset_audit( $request ) {
		$d5    = self::get_d5_presets();
		$refs  = self::collect_page_preset_refs();
		$chain = self::collect_group_chain_refs( $d5 );

		// Union of page-content refs and in-registry chain refs. A preset is
		// "referenced" — and therefore unsafe to delete — if either axis sees it.
		// Use `+` instead of array_merge: keys are UUIDs and array_merge would
		// re-index any that happen to be all-digit strings, silently dropping
		// the original UUID from the union.
		$referenced_uuids = array_keys( $refs['all_uuids'] + $chain['counts'] );

		$summary = [
			'total_presets'           => 0,
			'spam_referenced'         => [],
			'spam_unreferenced'       => [],
			'descriptive'             => [],
			'empty_defaults'          => [],
			'orphan_default_pointers' => [],
		];

		foreach ( [ 'module', 'group' ] as $type ) {
			if ( ! isset( $d5[ $type ] ) ) {
				continue;
			}
			foreach ( (array) $d5[ $type ] as $mod => $info ) {
				$info  = (array) $info;
				$items = isset( $info['items'] ) ? (array) $info['items'] : [];
				$summary['total_presets'] += count( $items );

				// Diagnostic: a `default` pointer that doesn't resolve to any
				// item in the same bucket. Caused by past unsafe deletes that
				// removed the record without clearing the pointer.
				// Render-safe (Divi falls back to internal defaults) but
				// blocks Divi's lazy recreate-on-VB-use path indefinitely.
				$default_id = $info['default'] ?? '';
				if ( '' !== $default_id && ! isset( $items[ $default_id ] ) ) {
					$summary['orphan_default_pointers'][] = [
						'type'      => $type,
						'module'    => $mod,
						'orphan_id' => $default_id,
					];
				}

				foreach ( $items as $pid => $preset ) {
					$preset      = (array) $preset;
					$name        = $preset['name'] ?? '';
					$has_content = ! empty( $preset['attrs'] ) || ! empty( $preset['styleAttrs'] );
					$is_spam     = self::is_spam_preset_name( $name );
					$block_count = $refs['all_uuids'][ $pid ] ?? 0;
					$group_count = $chain['counts'][ $pid ] ?? 0;
					$is_ref      = $block_count > 0 || $group_count > 0;
					$is_default  = ( $info['default'] ?? '' ) === $pid;

					$entry = [
						'id'              => $pid,
						'module'          => $mod,
						'type'            => $type,
						'name'            => $name,
						'has_attrs'       => $has_content,
						'is_default'      => $is_default,
						'referenced'      => $is_ref,
						'ref_count'       => $block_count + $group_count,
						'block_ref_count' => $block_count,
						'group_ref_count' => $group_count,
					];
					if ( 'group' === $type && $group_count > 0 ) {
						// Type-agnostic name: collect_group_chain_refs walks both
						// `module` and `group` buckets, so the referencing UUID can
						// belong to either type. Consumers needing the type can
						// look the UUID up in the audit response.
						$entry['referenced_by_presets'] = $chain['referenced_by'][ $pid ] ?? [];
					}

					if ( ! $has_content ) {
						$summary['empty_defaults'][] = $entry;
					} elseif ( $is_spam && $is_ref ) {
						$summary['spam_referenced'][] = $entry;
					} elseif ( $is_spam && ! $is_ref ) {
						$summary['spam_unreferenced'][] = $entry;
					} else {
						$summary['descriptive'][] = $entry;
					}
				}
			}
		}

		return self::envelope_success( [
			'total_presets'                 => $summary['total_presets'],
			'spam_referenced_count'         => count( $summary['spam_referenced'] ),
			'spam_unreferenced_count'       => count( $summary['spam_unreferenced'] ),
			'descriptive_count'             => count( $summary['descriptive'] ),
			'empty_default_count'           => count( $summary['empty_defaults'] ),
			'orphan_default_pointer_count'  => count( $summary['orphan_default_pointers'] ),
			'spam_referenced'               => $summary['spam_referenced'],
			'spam_unreferenced'             => $summary['spam_unreferenced'],
			'descriptive'                   => $summary['descriptive'],
			'orphan_default_pointers'       => $summary['orphan_default_pointers'],
			'page_refs'                     => $refs['per_page'],
			'total_referenced_uuids'        => count( $referenced_uuids ),
		] );
	}

	/**
	 * Cleanup presets. Modes:
	 * - Default: remove unreferenced spam presets, rename referenced spam names.
	 * - dedup=true: also remove duplicate presets with identical attrs.
	 * - action=rename_strip_prefix + prefix: strip a name prefix from all presets.
	 * - action=remove_orphans + scope=spam: remove unreferenced spam presets only.
	 * - action=remove_orphans + scope=all: remove all unreferenced non-default presets.
	 */
	public static function preset_cleanup( $request ) {
		$dry_run    = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? true );
		$dedup      = rest_sanitize_boolean( $request->get_param( 'dedup' ) ?? false );
		$action     = sanitize_key( (string) ( $request->get_param( 'action' ) ?? '' ) );
		$prefix     = sanitize_text_field( (string) ( $request->get_param( 'prefix' ) ?? '' ) );
		$scope_raw  = sanitize_key( (string) ( $request->get_param( 'scope' ) ?? '' ) );
		$scope      = in_array( $scope_raw, [ 'spam', 'all' ], true ) ? $scope_raw : 'spam';
		$d5         = self::get_d5_presets();
		$refs       = self::collect_page_preset_refs();
		$chain      = self::collect_group_chain_refs( $d5 );

		// Treat a preset as "in use" if it's referenced by page content OR by
		// another preset's groupPresets chain. Without the chain union,
		// remove_orphans / dedup would silently delete load-bearing group presets
		// (font, border, box-shadow, spacing, button) that module presets wire in.
		// Use `+` rather than array_merge so all-digit UUID keys
		// don't get silently re-indexed out of the union. Keep as an assoc set
		// so membership tests inside the preset loops are O(1) via isset()
		// rather than O(N) via in_array().
		$referenced_set = $refs['all_uuids'] + $chain['counts'];

		$removed  = [];
		$renamed  = [];
		$deduped  = [];
		$kept     = 0;
		$modified = false;

		// Action: rename_strip_prefix — strip a prefix from all preset names.
		if ( 'rename_strip_prefix' === $action && '' !== $prefix ) {
			$prefix_len = strlen( $prefix );
			foreach ( [ 'module', 'group' ] as $type ) {
				if ( ! isset( $d5[ $type ] ) ) {
					continue;
				}
				foreach ( $d5[ $type ] as $mod => &$info ) {
					if ( ! is_array( $info ) ) {
						$info = (array) $info;
					}
					if ( ! isset( $info['items'] ) || ! is_array( $info['items'] ) ) {
						continue;
					}
					foreach ( $info['items'] as $pid => &$preset ) {
						if ( ! is_array( $preset ) ) {
							$preset = (array) $preset;
						}
						$name = $preset['name'] ?? '';
						if ( 0 === strpos( $name, $prefix ) ) {
							$new_name = substr( $name, $prefix_len );
							if ( '' !== $new_name ) {
								$renamed[] = [
									'id'       => $pid,
									'module'   => $mod,
									'old_name' => $name,
									'new_name' => $new_name,
								];
								if ( ! $dry_run ) {
									$preset['name'] = $new_name;
									$modified       = true;
								}
							}
						}
						$kept++;
					}
					unset( $preset );
				}
				unset( $info );
			}

			if ( ! $dry_run && $modified ) {
				self::save_d5_presets( $d5 );
			}

			if ( $dry_run ) {
				return self::dry_run_response(
					"Would strip prefix '{$prefix}' from " . count( $renamed ) . ' preset name(s).',
					self::preset_cleanup_dry_run_changes( [], $renamed, [] ),
					[],
					[
						'action'        => $action,
						'prefix'        => $prefix,
						'renamed_count' => count( $renamed ),
						'kept_count'    => $kept,
						'renamed'       => $renamed,
					]
				);
			}

			return self::envelope_success( [
				'dry_run'       => $dry_run,
				'action'        => $action,
				'prefix'        => $prefix,
				'renamed_count' => count( $renamed ),
				'kept_count'    => $kept,
				'renamed'       => $renamed,
			] );
		}

		// Action: remove_orphans — remove unreferenced presets.
		// scope=spam (default): only spam-named orphans. scope=all: all non-default orphans.
		if ( 'remove_orphans' === $action ) {
			foreach ( [ 'module', 'group' ] as $type ) {
				if ( ! isset( $d5[ $type ] ) ) {
					continue;
				}
				foreach ( $d5[ $type ] as $mod => &$info ) {
					if ( ! is_array( $info ) ) {
						$info = (array) $info;
					}
					if ( ! isset( $info['items'] ) || ! is_array( $info['items'] ) ) {
						continue;
					}
					$default_id = $info['default'] ?? '';

					foreach ( $info['items'] as $pid => $preset ) {
						$preset     = (array) $preset;
						$name       = $preset['name'] ?? '';
						$is_ref     = isset( $referenced_set[ $pid ] );
						$is_default = $pid === $default_id;

						$should_remove = ! $is_ref && ! $is_default;
						if ( 'spam' === $scope ) {
							$should_remove = $should_remove && self::is_spam_preset_name( $name );
						}

						if ( $should_remove ) {
							$removed[] = [ 'id' => $pid, 'module' => $mod, 'name' => $name ];
							if ( ! $dry_run ) {
								unset( $info['items'][ $pid ] );
								$modified = true;
							}
						} else {
							$kept++;
						}
					}
				}
				unset( $info );
			}

			if ( ! $dry_run && $modified ) {
				self::save_d5_presets( $d5 );
			}

			if ( $dry_run ) {
				return self::dry_run_response(
					"Would remove " . count( $removed ) . " unreferenced {$scope} preset(s).",
					self::preset_cleanup_dry_run_changes( $removed, [], [] ),
					[],
					[
						'action'        => $action,
						'scope'         => $scope,
						'removed_count' => count( $removed ),
						'kept_count'    => $kept,
						'removed'       => $removed,
					]
				);
			}

			return self::envelope_success( [
				'dry_run'       => $dry_run,
				'action'        => $action,
				'scope'         => $scope,
				'removed_count' => count( $removed ),
				'kept_count'    => $kept,
				'removed'       => $removed,
			] );
		}

		foreach ( [ 'module', 'group' ] as $type ) {
			if ( ! isset( $d5[ $type ] ) ) {
				continue;
			}
			foreach ( $d5[ $type ] as $mod => &$info ) {
				if ( ! is_array( $info ) ) {
					$info = (array) $info;
				}
				if ( ! isset( $info['items'] ) || ! is_array( $info['items'] ) ) {
					continue;
				}

				$default_id = $info['default'] ?? '';

				// Dedup pass: hash attrs to find identical presets.
				$seen_hashes = [];
				if ( $dedup ) {
					foreach ( $info['items'] as $pid => $preset ) {
						$preset = (array) $preset;
						$attrs  = $preset['attrs'] ?? null;
						if ( ! $attrs ) {
							continue;
						}
						$hash = md5( wp_json_encode( $attrs ) );
						if ( isset( $seen_hashes[ $hash ] ) ) {
							$keeper    = $seen_hashes[ $hash ];
							$is_ref    = isset( $referenced_set[ $pid ] );
							$is_def    = $pid === $default_id;
							$keep_ref  = isset( $referenced_set[ $keeper ] );
							$keep_def  = $keeper === $default_id;

							// Remove the one that is NOT referenced/default.
							if ( ! $is_ref && ! $is_def ) {
								$deduped[] = [
									'id'      => $pid,
									'module'  => $mod,
									'name'    => $preset['name'] ?? '',
									'kept_id' => $keeper,
								];
								if ( ! $dry_run ) {
									unset( $info['items'][ $pid ] );
									$modified = true;
								}
								continue;
							} elseif ( ! $keep_ref && ! $keep_def ) {
								// Swap: current one is referenced, keeper is not.
								$deduped[] = [
									'id'      => $keeper,
									'module'  => $mod,
									'name'    => ( (array) $info['items'][ $keeper ] )['name'] ?? '',
									'kept_id' => $pid,
								];
								if ( ! $dry_run ) {
									unset( $info['items'][ $keeper ] );
									$modified = true;
								}
								$seen_hashes[ $hash ] = $pid;
								continue;
							}
							// Both referenced/default — keep both.
						} else {
							$seen_hashes[ $hash ] = $pid;
						}
					}
				}

				// Spam cleanup pass.
				foreach ( $info['items'] as $pid => &$preset ) {
					if ( ! is_array( $preset ) ) {
						$preset = (array) $preset;
					}
					$name        = $preset['name'] ?? '';
					$is_spam     = self::is_spam_preset_name( $name );
					$is_ref      = isset( $referenced_set[ $pid ] );
					$is_default  = $pid === $default_id;

					if ( $is_spam && ! $is_ref && ! $is_default ) {
						$removed[] = [ 'id' => $pid, 'module' => $mod, 'name' => $name ];
						if ( ! $dry_run ) {
							unset( $info['items'][ $pid ] );
							$modified = true;
						}
					} elseif ( $is_spam && ( $is_ref || $is_default ) ) {
						$clean_name = self::clean_spam_preset_name( $name );
						if ( $clean_name !== $name ) {
							$renamed[] = [
								'id'       => $pid,
								'module'   => $mod,
								'old_name' => $name,
								'new_name' => $clean_name,
							];
							if ( ! $dry_run ) {
								$preset['name'] = $clean_name;
								$modified       = true;
							}
						}
						$kept++;
					} else {
						$kept++;
					}
				}
				unset( $preset );
			}
			unset( $info );
		}

		if ( ! $dry_run && $modified ) {
			self::save_d5_presets( $d5 );
		}

		if ( $dry_run ) {
			return self::dry_run_response(
				sprintf(
					'Would clean presets: remove %d, rename %d, dedupe %d, keep %d.',
					count( $removed ),
					count( $renamed ),
					count( $deduped ),
					$kept
				),
				self::preset_cleanup_dry_run_changes( $removed, $renamed, $deduped ),
				[],
				[
					'removed_count'  => count( $removed ),
					'renamed_count'  => count( $renamed ),
					'deduped_count'  => count( $deduped ),
					'kept_count'     => $kept,
					'removed'        => $removed,
					'renamed'        => $renamed,
					'deduped'        => $deduped,
				]
			);
		}

		return self::envelope_success( [
			'dry_run'        => $dry_run,
			'removed_count'  => count( $removed ),
			'renamed_count'  => count( $renamed ),
			'deduped_count'  => count( $deduped ),
			'kept_count'     => $kept,
			'removed'        => $removed,
			'renamed'        => $renamed,
			'deduped'        => $deduped,
		] );
	}

	/**
	 * Build standard dry-run plan changes for preset_cleanup while preserving
	 * the legacy removed/renamed/deduped arrays as sibling metadata.
	 */
	private static function preset_cleanup_dry_run_changes( array $removed, array $renamed, array $deduped ): array {
		$changes = [];
		foreach ( $removed as $entry ) {
			$changes[] = [
				'kind'   => 'preset.delete',
				'target' => 'preset/' . ( $entry['module'] ?? 'unknown' ) . '/' . ( $entry['id'] ?? 'unknown' ),
				'before' => [
					'id'     => $entry['id'] ?? null,
					'module' => $entry['module'] ?? null,
					'name'   => $entry['name'] ?? '',
				],
				'after'  => null,
			];
		}
		foreach ( $renamed as $entry ) {
			$changes[] = [
				'kind'   => 'preset.rename',
				'target' => 'preset/' . ( $entry['module'] ?? 'unknown' ) . '/' . ( $entry['id'] ?? 'unknown' ),
				'before' => [ 'name' => $entry['old_name'] ?? '' ],
				'after'  => [ 'name' => $entry['new_name'] ?? '' ],
			];
		}
		foreach ( $deduped as $entry ) {
			$changes[] = [
				'kind'   => 'preset.delete_duplicate',
				'target' => 'preset/' . ( $entry['module'] ?? 'unknown' ) . '/' . ( $entry['id'] ?? 'unknown' ),
				'before' => [
					'id'      => $entry['id'] ?? null,
					'module'  => $entry['module'] ?? null,
					'name'    => $entry['name'] ?? '',
					'kept_id' => $entry['kept_id'] ?? null,
				],
				'after'  => null,
			];
		}
		return $changes;
	}

	/**
	 * Update a specific preset by ID.
	 */
	public static function preset_update( $request ) {
		$preset_id    = sanitize_text_field( $request->get_param( 'preset_id' ) );
		$new_name     = $request->get_param( 'name' );
		$new_attrs    = $request->get_param( 'attrs' );
		$new_priority = $request->get_param( 'priority' );

		$d5    = self::get_d5_presets();
		$found = false;

		foreach ( [ 'module', 'group' ] as $type ) {
			if ( ! isset( $d5[ $type ] ) ) {
				continue;
			}
			foreach ( $d5[ $type ] as $mod => &$info ) {
				if ( ! is_array( $info ) ) {
					$info = (array) $info;
				}
				if ( ! isset( $info['items'][ $preset_id ] ) ) {
					continue;
				}

				$preset = &$info['items'][ $preset_id ];
				if ( ! is_array( $preset ) ) {
					$preset = (array) $preset;
				}

				if ( null !== $new_name ) {
					$preset['name'] = sanitize_text_field( $new_name );
				}
				if ( null !== $new_attrs && is_array( $new_attrs ) ) {
					// Mirror attrs into styleAttrs + renderAttrs to match VB save semantics.
					// Divi renders preset-affected CSS via two parallel passes: Pass A emits
					// `.preset--module--{module}--{uuid}` rules from preset.attrs (low specificity);
					// Pass B emits `.et_pb_{module}_N` rules with body-level parent chain from
					// preset.renderAttrs (high specificity). When both are populated, Pass B wins
					// the cascade. Without this mirror, removing a breakpoint from attrs leaves a
					// stale renderAttrs entry whose higher-specificity rule keeps rendering.
					// Writing all three keys keeps the two passes in lockstep and matches how VB
					// persists preset edits.
					$preset['attrs']       = $new_attrs;
					$preset['styleAttrs']  = $new_attrs;
					$preset['renderAttrs'] = $new_attrs;
				}
				if ( null !== $new_priority && is_numeric( $new_priority ) ) {
					// Controls stacked-preset cascade order in Divi's render path
					// (GlobalPreset::get_merged_attrs sorts ascending — higher priority
					// merged later, wins). Default in Divi is 10 when omitted.
					$preset['priority'] = (int) $new_priority;
				}

				$preset['updated'] = time() * 1000;

				$found = [
					'id'     => $preset_id,
					'module' => $mod,
					'type'   => $type,
					'name'   => $preset['name'],
				];
				// Unset both live references before exiting the nested loop —
				// `break 2;` skips the post-loop `unset($info)` that PHP
				// otherwise needs for foreach-by-reference cleanup. `$preset`
				// (line 3863) is a second reference into `$info['items'][...]`
				// and needs the same treatment. Defensive against future edits
				// that reuse either symbol later in this method.
				unset( $preset );
				unset( $info );
				break 2;
			}
			unset( $info );
		}

		if ( ! $found ) {
			return self::envelope_error(
				'not_found',
				"Preset '{$preset_id}' not found",
				'Use diviops_preset_audit to discover valid preset IDs.',
				404
			);
		}

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			$fields = [];
			if ( null !== $new_name ) {
				$fields[] = 'name';
			}
			if ( null !== $new_attrs && is_array( $new_attrs ) ) {
				$fields[] = 'attrs+styleAttrs+renderAttrs';
			}
			if ( null !== $new_priority && is_numeric( $new_priority ) ) {
				$fields[] = 'priority';
			}
			$fields_desc = empty( $fields ) ? 'no fields (no-op)' : implode( ', ', $fields );
			return self::dry_run_response(
				"Would update preset '{$preset_id}' ({$found['type']}/{$found['module']}) — {$fields_desc}.",
				[ [
					'kind'   => 'preset.update',
					'target' => "preset/{$found['type']}/{$found['module']}/{$preset_id}",
					'after'  => [ 'fields' => $fields ],
				] ]
			);
		}

		self::save_d5_presets( $d5 );

		return self::attach_meta(
			self::envelope_success( [
				'success' => true,
				'preset'  => $found,
				'message' => "Preset '{$preset_id}' updated.",
			] ),
			self::d5_preset_write_meta()
		);
	}

	/**
	 * Delete a specific preset by ID.
	 */
	public static function preset_delete( $request ) {
		$preset_id = sanitize_text_field( $request->get_param( 'preset_id' ) );
		$force     = rest_sanitize_boolean( $request->get_param( 'force' ) ?? false );

		$d5             = self::get_d5_presets();
		$found          = false;
		$default_cleared = null;

		foreach ( [ 'module', 'group' ] as $type ) {
			if ( ! isset( $d5[ $type ] ) ) {
				continue;
			}
			foreach ( $d5[ $type ] as $mod => &$info ) {
				if ( ! is_array( $info ) ) {
					$info = (array) $info;
				}
				if ( ! isset( $info['items'][ $preset_id ] ) ) {
					continue;
				}

				$preset     = (array) $info['items'][ $preset_id ];
				$is_default = ( $info['default'] ?? '' ) === $preset_id;

				// Refuse-by-default if this preset is the registered default for
				// its bucket. Without this guard, the record disappears but the
				// parent's `default` pointer keeps referencing the deleted UUID,
				// leaving the registry in a stale-pointer state. Divi falls back
				// to internal defaults at render but does not lazy-recreate a
				// fresh blank — the orphan persists indefinitely.
				// `force=true` clears the pointer in the same write to opt out.
				if ( $is_default && ! $force ) {
					unset( $info );
					return self::envelope_error(
						'conflict',
						"Preset '{$preset_id}' is the registered default for {$type}/{$mod}.",
						'Clear the default pointer first via diviops_preset_set_default with unset=true, or pass force=true to delete and clear the pointer in the same write.',
						409,
						[
							'preset_id' => $preset_id,
							'type'      => $type,
							'module'    => $mod,
							'name'      => $preset['name'] ?? '',
							'reason'    => 'is_default',
						]
					);
				}

				$found = [
					'id'     => $preset_id,
					'module' => $mod,
					'type'   => $type,
					'name'   => $preset['name'] ?? '',
				];
				unset( $info['items'][ $preset_id ] );
				if ( $is_default ) {
					$info['default'] = '';
					$default_cleared = [ 'type' => $type, 'module' => $mod ];
				}
				// Unset the live reference before exiting the nested loop —
				// `break 2;` skips the post-loop `unset($info)` that PHP
				// otherwise needs for foreach-by-reference cleanup. Defensive
				// against future edits that reuse `$info` later in this method.
				unset( $info );
				break 2;
			}
			unset( $info );
		}

		if ( ! $found ) {
			return self::envelope_error(
				'not_found',
				"Preset '{$preset_id}' not found",
				'Use diviops_preset_audit to discover valid preset IDs.',
				404
			);
		}

		self::save_d5_presets( $d5 );

		$response = [
			'success' => true,
			'deleted' => $found,
			'message' => "Preset '{$preset_id}' deleted.",
		];
		if ( $default_cleared ) {
			$response['default_cleared'] = $default_cleared;
			$response['message'] .= " Default pointer for {$default_cleared['type']}/{$default_cleared['module']} cleared.";
		}

		return self::attach_meta(
			self::envelope_success( $response ),
			self::d5_preset_write_meta()
		);
	}

	/**
	 * Set or clear the per-module/group default preset pointer.
	 *
	 * Two addressing modes:
	 *
	 *   1. preset_id mode — walks both buckets to locate the preset, then
	 *      updates `$d5[type][bucket_key]['default']` to the preset's UUID
	 *      (or '' with unset=true). The resolved preset must exist in
	 *      `items[]`; missing preset returns 404.
	 *
	 *   2. type+module mode (bucket-addressed clear) — addresses the bucket
	 *      directly without walking items[]. Required when clearing an
	 *      orphan default pointer (UUID gone from items[] but `default`
	 *      still references it; surfaced via preset_audit's
	 *      `orphan_default_pointers`). Requires unset=true; setting a
	 *      default by bucket without naming a preset has no meaning.
	 *
	 * Defaults apply to NEW instances only; existing modules keep their
	 * current preset bindings. Use preset_reassign for retroactive swaps.
	 */
	public static function preset_set_default( $request ) {
		$preset_id    = sanitize_text_field( (string) $request->get_param( 'preset_id' ) );
		$req_type     = sanitize_key( (string) $request->get_param( 'type' ) );
		$req_module   = sanitize_text_field( (string) $request->get_param( 'module' ) );
		$do_unset     = rest_sanitize_boolean( $request->get_param( 'unset' ) ?? false );
		$dry_run      = (bool) $request->get_param( 'dry_run' );

		$d5 = self::get_d5_presets();

		// Bucket-addressed clear: type + module + unset=true. Used to repair
		// orphan default pointers where preset_id no longer exists in items[]
		// (the preset_id-walk path can't locate them — that's the very state
		// we need to clear). Refuse the bucket form when unset is false:
		// setting a bucket's default without naming a preset is meaningless.
		if ( '' === $preset_id && '' !== $req_type && '' !== $req_module ) {
			if ( ! $do_unset ) {
				return self::envelope_error(
					'invalid_input',
					'Bucket-addressed mode (type + module) requires unset=true. To set a default, pass preset_id.',
					null,
					400,
					[
						'field'    => 'unset',
						'expected' => true,
						'received' => $do_unset,
					]
				);
			}
			if ( ! isset( $d5[ $req_type ][ $req_module ] ) ) {
				return self::envelope_error(
					'not_found',
					"Bucket '{$req_type}/{$req_module}' not found in registry.",
					'Use diviops_preset_audit to discover valid type/module combinations.',
					404
				);
			}

			$bucket          = (array) $d5[ $req_type ][ $req_module ];
			$prev_default_id = $bucket['default'] ?? '';

			if ( $dry_run ) {
				return self::dry_run_response(
					"Would clear default-preset pointer for {$req_type}/{$req_module} (was: '{$prev_default_id}').",
					[ [
						'kind'   => 'preset.set_default',
						'target' => "preset/{$req_type}/{$req_module}",
						'before' => [ 'default' => $prev_default_id ],
						'after'  => [ 'default' => '' ],
					] ]
				);
			}

			$bucket['default']             = '';
			$d5[ $req_type ][ $req_module ] = $bucket;

			self::save_d5_presets( $d5 );

			return self::envelope_success( [
				'success' => true,
				'preset'  => [
					'id'             => '',
					'type'           => $req_type,
					'module'         => $req_module,
					'name'           => '',
					'was_default_id' => $prev_default_id,
					'new_default_id' => '',
					'is_default'     => false,
				],
				'message' => "Default preset cleared for {$req_type}/{$req_module}.",
			] );
		}

		if ( '' === $preset_id ) {
			return self::envelope_error(
				'invalid_input',
				'Either preset_id, or type + module + unset=true, is required.',
				null,
				400,
				[
					'requires' => [ 'preset_id|type+module+unset' ],
				]
			);
		}

		$found = false;

		foreach ( [ 'module', 'group' ] as $type ) {
			if ( ! isset( $d5[ $type ] ) ) {
				continue;
			}
			foreach ( $d5[ $type ] as $mod => &$info ) {
				if ( ! is_array( $info ) ) {
					$info = (array) $info;
				}
				if ( ! isset( $info['items'][ $preset_id ] ) ) {
					continue;
				}

				$preset         = (array) $info['items'][ $preset_id ];
				$was_default_id = $info['default'] ?? '';
				$new_default_id = $do_unset ? '' : $preset_id;
				$info['default'] = $new_default_id;

				$found = [
					'id'             => $preset_id,
					'module'         => $mod,
					'type'           => $type,
					'name'           => $preset['name'] ?? '',
					'was_default_id' => $was_default_id,
					'new_default_id' => $new_default_id,
					'is_default'     => '' !== $new_default_id,
				];
				// Unset the live reference before exiting the nested loop —
				// `break 2;` skips the post-loop `unset($info)` that PHP
				// otherwise needs for foreach-by-reference cleanup. Defensive
				// against future edits that reuse `$info` later in this method.
				unset( $info );
				break 2;
			}
			unset( $info );
		}

		if ( ! $found ) {
			return self::envelope_error(
				'not_found',
				"Preset '{$preset_id}' not found",
				'Use diviops_preset_audit to discover valid preset IDs. To clear an orphan default pointer (UUID gone from items[]), pass type + module + unset=true instead.',
				404
			);
		}

		if ( $dry_run ) {
			$verb = $do_unset ? 'clear' : 'set';
			return self::dry_run_response(
				$do_unset
					? "Would clear default-preset pointer for {$found['type']}/{$found['module']} (was: '{$found['was_default_id']}')."
					: "Would {$verb} default preset for {$found['type']}/{$found['module']} to '{$preset_id}' ('{$found['name']}', was: '{$found['was_default_id']}').",
				[ [
					'kind'   => 'preset.set_default',
					'target' => "preset/{$found['type']}/{$found['module']}",
					'before' => [ 'default' => $found['was_default_id'] ],
					'after'  => [ 'default' => $found['new_default_id'] ],
				] ]
			);
		}

		self::save_d5_presets( $d5 );

		$msg = $do_unset
			? "Default preset cleared for {$found['type']}/{$found['module']}."
			: "Preset '{$preset_id}' is now the default for {$found['type']}/{$found['module']}.";

		return self::attach_meta(
			self::envelope_success( [
				'success' => true,
				'preset'  => $found,
				'message' => $msg,
			] ),
			self::d5_preset_write_meta()
		);
	}

	/**
	 * Create a new preset in the D5 registry.
	 *
	 * For type='module': writes to $d5['module'][module_name]['items'][uuid].
	 * For type='group': writes to $d5['group'][group_name]['items'][uuid] — requires group_name and group_id; primary_attr_name is optional.
	 */
	public static function preset_create( $request ) {
		$module_name  = sanitize_text_field( $request->get_param( 'module_name' ) );
		$name         = sanitize_text_field( $request->get_param( 'name' ) );
		$attrs        = $request->get_param( 'attrs' );
		$type         = sanitize_key( $request->get_param( 'type' ) ?: 'module' );
		$group_name   = sanitize_text_field( $request->get_param( 'group_name' ) ?? '' );
		$group_id     = sanitize_text_field( $request->get_param( 'group_id' ) ?? '' );
		$primary_attr = sanitize_text_field( $request->get_param( 'primary_attr_name' ) ?? '' );
		$make_default = rest_sanitize_boolean( $request->get_param( 'make_default' ) ?? false );
		$priority     = $request->get_param( 'priority' );
		$dry_run      = (bool) $request->get_param( 'dry_run' );

		$missing_required = [];
		if ( '' === $module_name ) {
			$missing_required[] = 'module_name';
		}
		if ( '' === $name ) {
			$missing_required[] = 'name';
		}
		if ( ! is_array( $attrs ) ) {
			$missing_required[] = 'attrs';
		}
		if ( ! empty( $missing_required ) ) {
			return self::envelope_error(
				'invalid_input',
				'module_name, name, attrs are required.',
				null,
				400,
				[ 'missing' => $missing_required ]
			);
		}
		if ( ! in_array( $type, [ 'module', 'group' ], true ) ) {
			return self::envelope_error(
				'invalid_input',
				"type must be 'module' or 'group'.",
				null,
				400,
				[
					'field'    => 'type',
					'allowed'  => [ 'module', 'group' ],
					'received' => $type,
				]
			);
		}
		if ( 'group' === $type ) {
			$missing_group = [];
			if ( '' === $group_name ) {
				$missing_group[] = 'group_name';
			}
			if ( '' === $group_id ) {
				$missing_group[] = 'group_id';
			}
			if ( ! empty( $missing_group ) ) {
				return self::envelope_error(
					'invalid_input',
					'group presets require group_name and group_id.',
					null,
					400,
					[
						'rejected_field' => 'type',
						'received'       => 'group',
						'missing'        => $missing_group,
					]
				);
			}
		}

		$d5  = self::get_d5_presets();

		// Per-bucket uniqueness check. The bucket coords
		// — `(bucket, bucket_key)` — are the natural addressing scope: a
		// "Hero Title" font preset and a "Hero Title" button preset can
		// coexist (different buckets), but two "Hero Title" font presets
		// under `group/divi/font` cannot — the second would render as a
		// silent duplicate UUID under the same display name. Walk
		// `items` once before mint and 409 on collision.
		//
		// Defensive cast: bucket entries can arrive as stdClass after
		// maybe_unserialize() — get_d5_presets() only casts the outer
		// level. Mirrors the pattern used elsewhere in this trait (see
		// the `if ( ! is_array( $info ) ) { $info = (array) $info; }`
		// guard inside preset_update / preset_delete / preset_cleanup
		// foreach loops).
		$bucket_key  = ( 'group' === $type ) ? $group_name : $module_name;
		$bucket      = ( 'group' === $type ) ? 'group' : 'module';
		$bucket_info = (array) ( $d5[ $bucket ][ $bucket_key ] ?? [] );
		$existing    = (array) ( $bucket_info['items'] ?? [] );
		foreach ( $existing as $existing_uid => $existing_preset ) {
			$existing_preset = (array) $existing_preset;
			if ( ( $existing_preset['name'] ?? '' ) === $name ) {
				return self::envelope_error(
					'conflict',
					sprintf( "Preset named '%s' already exists in %s/%s.", $name, $bucket, $bucket_key ),
					'Use diviops_preset_update to change attrs on the existing preset, or pick a different name.',
					409,
					[
						'existing_preset_id' => (string) $existing_uid,
						'bucket'             => $bucket,
						'bucket_key'         => $bucket_key,
						'name'               => $name,
					]
				);
			}
		}

		if ( $dry_run ) {
			$existing_count = count( $existing );
			$prev_default   = $bucket_info['default'] ?? '';
			return self::dry_run_response(
				"Would create {$type} preset '{$name}' under {$bucket}/{$bucket_key} (existing items: {$existing_count}" . ( $make_default ? ", marking new preset as default" : '' ) . ").",
				[ [
					'kind'   => 'preset.create',
					'target' => "preset/{$bucket}/{$bucket_key}",
					'after'  => [
						'name'         => $name,
						'module_name'  => $module_name,
						'type'         => $type,
						'make_default' => $make_default,
						'priority'     => is_numeric( $priority ) ? (int) $priority : null,
						'group_name'   => 'group' === $type ? $group_name : null,
						'group_id'     => 'group' === $type ? $group_id : null,
					],
				] ],
				[],
				[
					'note' => 'UUID is generated at apply time; dry_run does not pre-allocate it.',
					'bucket_state' => [
						'existing_items' => $existing_count,
						'current_default' => $prev_default,
					],
				]
			);
		}

		$uid = wp_generate_uuid4();
		$now = round( microtime( true ) * 1000 );

		// Write all three attribute buckets in parallel to match VB save semantics.
		// See preset_update for the full Pass A / Pass B architecture note; the short
		// version here is that renderAttrs is what the high-specificity instance-class
		// CSS reads from, so populating it at create time keeps MCP-created presets
		// consistent with VB-created ones for any consumer that reads renderAttrs.
		$preset = [
			'id'          => $uid,
			'name'        => $name,
			'moduleName'  => $module_name,
			'attrs'       => $attrs,
			'styleAttrs'  => $attrs,
			'renderAttrs' => $attrs,
			'type'        => $type,
			'created'     => $now,
			'updated'     => $now,
		];
		if ( defined( 'ET_BUILDER_VERSION' ) && '' !== ET_BUILDER_VERSION ) {
			$preset['version'] = ET_BUILDER_VERSION;
		}
		if ( null !== $priority && is_numeric( $priority ) ) {
			$preset['priority'] = (int) $priority;
		}

		if ( 'group' === $type ) {
			$preset['groupName'] = $group_name;
			$preset['groupId']   = $group_id;
			if ( '' !== $primary_attr ) {
				$preset['primaryAttrName'] = $primary_attr;
			}
			$bucket_key = $group_name;
			$bucket     = 'group';
		} else {
			$bucket_key = $module_name;
			$bucket     = 'module';
		}

		$d5[ $bucket ]                                   = (array) ( $d5[ $bucket ] ?? [] );
		$d5[ $bucket ][ $bucket_key ]                    = (array) ( $d5[ $bucket ][ $bucket_key ] ?? [] );
		$d5[ $bucket ][ $bucket_key ]['items']           = (array) ( $d5[ $bucket ][ $bucket_key ]['items'] ?? [] );
		$d5[ $bucket ][ $bucket_key ]['default']         = $d5[ $bucket ][ $bucket_key ]['default'] ?? '';
		$d5[ $bucket ][ $bucket_key ]['items'][ $uid ]   = $preset;

		$was_default_id = $d5[ $bucket ][ $bucket_key ]['default'];
		if ( $make_default ) {
			$d5[ $bucket ][ $bucket_key ]['default'] = $uid;
		}

		self::save_d5_presets( $d5 );

		$response = [
			'success' => true,
			'preset'  => [
				'id'          => $uid,
				'name'        => $name,
				'module_name' => $module_name,
				'type'        => $type,
				'bucket_key'  => $bucket_key,
			],
		];
		if ( $make_default ) {
			$response['preset']['is_default']     = true;
			$response['preset']['was_default_id'] = $was_default_id;
		}
		return self::attach_meta(
			self::envelope_success( $response ),
			self::d5_preset_write_meta()
		);
	}

	/**
	 * Reassign preset UUID references across pages.
	 *
	 * Walks posts/pages and rewrites two kinds of references:
	 *   - `attrs.modulePreset[...]` arrays (stacked module presets) — always, when scope permits.
	 *   - `attrs.groupPreset.<slot>.presetId` (attribute-level group presets) — when scope permits.
	 *
	 * For group-bucket reassignments, also rewrites preset-registry chains at their canonical
	 * locations per bucket (see `_extract_chain_slot_map` for paths):
	 *   - Module-bucket presets:  top-level `groupPresets.<slot>.presetId`
	 *   - Group-bucket presets:   `attrs.groupPreset.<slot>.presetId` (singular)
	 * so downstream presets that pull in the old group preset keep rendering.
	 *
	 * `scope` controls which refs are considered ("module" | "group" | "both", default "both").
	 * Default "both" auto-selects based on new_uuid's bucket — the module/group distinction is an
	 * identity invariant (cross-bucket swaps are rejected), so there's no ambiguity.
	 *
	 * With `strip_inline=true` (default), strips inline attrs that duplicate the new preset's attrs:
	 *   - Module scope: strips from the block root, guarded by "post-swap modulePreset stack is singular
	 *     ([new_uuid])" — stacked presets keep inline so other presets in the stack can't silently override
	 *     through the freshly-stripped fields.
	 *   - Group scope: strips per-slot using Divi's `GlobalPresetItemGroup` class to resolve the preset's
	 *     attrs for the target module+slot (handles composite button groups, `-id-classes` suffix, explicit
	 *     `attrName` component mappings, cross-module name translation). Same singular-stack guard at the
	 *     slot level. Unmappable slots (missing class, unknown module) skip strip and emit a per-slot
	 *     advisory at `summary.strip_advisory_per_slot[<module>::<slot>]`; neighbor slots still strip.
	 *
	 * Dry-run (default) returns a summary of proposed changes without writing.
	 */
	public static function preset_reassign( $request ) {
		$old_uuid     = sanitize_text_field( $request->get_param( 'old_uuid' ) );
		$new_uuid     = sanitize_text_field( $request->get_param( 'new_uuid' ) );
		$mode         = sanitize_key( $request->get_param( 'mode' ) ?: 'dry-run' );
		$strip_inline = rest_sanitize_boolean( $request->get_param( 'strip_inline' ) ?? true );
		$scope        = sanitize_key( $request->get_param( 'scope' ) ?: 'both' );
		$page_ids     = $request->get_param( 'page_ids' );

		$missing = [];
		if ( '' === $old_uuid ) {
			$missing[] = 'old_uuid';
		}
		if ( '' === $new_uuid ) {
			$missing[] = 'new_uuid';
		}
		if ( ! empty( $missing ) ) {
			return self::envelope_error(
				'invalid_input',
				'old_uuid and new_uuid are required.',
				null,
				400,
				[ 'missing' => $missing ]
			);
		}
		if ( ! in_array( $mode, [ 'dry-run', 'apply' ], true ) ) {
			return self::envelope_error(
				'invalid_input',
				"mode must be 'dry-run' or 'apply'.",
				null,
				400,
				[
					'field'    => 'mode',
					'allowed'  => [ 'dry-run', 'apply' ],
					'received' => $mode,
				]
			);
		}
		if ( ! in_array( $scope, [ 'module', 'group', 'both' ], true ) ) {
			return self::envelope_error(
				'invalid_input',
				"scope must be 'module', 'group', or 'both'.",
				null,
				400,
				[
					'field'    => 'scope',
					'allowed'  => [ 'module', 'group', 'both' ],
					'received' => $scope,
				]
			);
		}

		$d5 = self::get_d5_presets();

		// Locate a UUID in the D5 registry and return its bucket + module + entry.
		// Returns null when the UUID isn't registered (legitimate for old_uuid — may be dangling).
		$find_bucket = static function ( $uuid ) use ( $d5 ) {
			foreach ( [ 'module', 'group' ] as $bucket ) {
				if ( ! isset( $d5[ $bucket ] ) ) {
					continue;
				}
				foreach ( (array) $d5[ $bucket ] as $mod => $info ) {
					$info = (array) $info;
					if ( isset( $info['items'][ $uuid ] ) ) {
						return [ 'bucket' => $bucket, 'module' => $mod, 'entry' => (array) $info['items'][ $uuid ] ];
					}
				}
			}
			return null;
		};

		$new_hit = $find_bucket( $new_uuid );
		if ( null === $new_hit ) {
			return self::envelope_error(
				'not_found',
				"new_uuid '{$new_uuid}' does not exist in preset registry.",
				'Use diviops_preset_audit to discover registered preset UUIDs.',
				404
			);
		}
		$new_bucket = $new_hit['bucket'];
		$new_mod    = $new_hit['module'];
		$new_entry  = $new_hit['entry'];

		// old_uuid is allowed to be dangling (not in registry) — preserves the documented
		// "can be a dangling/orphan UUID" contract for orphan cleanup workflows.
		$old_hit    = $find_bucket( $old_uuid );
		$old_bucket = null !== $old_hit ? $old_hit['bucket'] : null;

		// Bucket-type validation: cross-bucket swaps (module preset ↔ group preset) would write
		// wrong-type UUIDs into modulePreset arrays / groupPreset slots. Always rejected.
		if ( null !== $old_bucket && $old_bucket !== $new_bucket ) {
			return self::envelope_error(
				'preset.bucket_mismatch',
				"Bucket mismatch: old_uuid is a {$old_bucket} preset, new_uuid is a {$new_bucket} preset. Cross-bucket swaps are not supported.",
				'Pick a new_uuid in the same bucket as old_uuid, or use diviops_preset_audit to discover candidates.',
				400,
				[
					'old_bucket' => $old_bucket,
					'new_bucket' => $new_bucket,
				]
			);
		}
		if ( 'module' === $scope && 'module' !== $new_bucket ) {
			return self::envelope_error(
				'preset.scope_mismatch',
				"scope='module' requires new_uuid to be a module preset (got {$new_bucket}).",
				null,
				400,
				[
					'scope'      => $scope,
					'new_bucket' => $new_bucket,
				]
			);
		}
		if ( 'group' === $scope && 'group' !== $new_bucket ) {
			return self::envelope_error(
				'preset.scope_mismatch',
				"scope='group' requires new_uuid to be a group preset (got {$new_bucket}).",
				null,
				400,
				[
					'scope'      => $scope,
					'new_bucket' => $new_bucket,
				]
			);
		}

		// Resolve "both" to the concrete branch determined by new_uuid's bucket — module/group are
		// disjoint identity spaces, so there's exactly one valid walk for this swap.
		$effective_scope = ( 'both' === $scope ) ? $new_bucket : $scope;

		// Merge styleAttrs + attrs for the inline-strip comparison bag. VB-created presets sometimes
		// populate only styleAttrs for CSS-generating fields; attrs wins on conflict (same precedence Divi uses).
		// Only used in module effective scope.
		$preset_style_attrs = is_array( $new_entry['styleAttrs'] ?? null ) ? $new_entry['styleAttrs'] : [];
		$preset_base_attrs  = is_array( $new_entry['attrs'] ?? null ) ? $new_entry['attrs'] : [];
		$preset_attrs       = self::_deep_merge( $preset_style_attrs, $preset_base_attrs );

		// Safety cap for full-site scans to avoid timeout/memory issues on large sites.
		// Also enforced when page_ids is explicitly supplied — reject oversized batches so callers chunk.
		$max_pages = self::REASSIGN_MAX_PAGES;
		$truncated = false;
		if ( is_array( $page_ids ) && ! empty( $page_ids ) ) {
			if ( count( $page_ids ) > $max_pages ) {
				return self::envelope_error(
					'preset.too_many_pages',
					'page_ids count (' . count( $page_ids ) . ") exceeds REASSIGN_MAX_PAGES ({$max_pages}).",
					'Chunk the request — split page_ids into batches of at most REASSIGN_MAX_PAGES.',
					400,
					[
						'received'  => count( $page_ids ),
						'max_pages' => $max_pages,
					]
				);
			}
			$query_args = [
				'post_type'      => [ 'page', 'post' ],
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'post__in'       => array_map( 'absint', $page_ids ),
				'posts_per_page' => -1,
			];
		} else {
			$query_args = [
				'post_type'      => [ 'page', 'post' ],
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'posts_per_page' => $max_pages + 1,
			];
		}
		$posts = get_posts( $query_args );
		if ( count( $posts ) > $max_pages ) {
			$posts     = array_slice( $posts, 0, $max_pages );
			$truncated = true;
		}

		$summary = [
			'scope'           => $effective_scope,
			'pages_scanned'   => count( $posts ),
			'pages_modified'  => 0,
			'uuid_swaps'      => 0,
			'module_swaps'    => 0,
			'group_swaps'     => 0,
			'chain_swaps'     => 0,
			'inline_stripped' => 0,
			'truncated'       => $truncated,
			'max_pages'       => $max_pages,
			'errors'          => [],
			'details'         => [],
		];
		// Per-slot advisories — populated during group-scope strip when a slot's target paths can't
		// be resolved (e.g. Divi's GlobalPresetItemGroup class unavailable, slot not registered).
		// Unmappable slots skip strip; other slots in the same walk are unaffected.
		$summary['strip_advisory_per_slot'] = [];

		foreach ( $posts as $p ) {
			$content = $p->post_content;

			// Fast-path: skip the expensive parse_blocks() when the raw content doesn't even mention old_uuid.
			// Only matters at scale — for a single-page targeted reassign this is a noop.
			if ( strpos( $content, $old_uuid ) === false ) {
				continue;
			}

			$module_swap_hits = 0;
			$group_swap_hits  = 0;
			$strip_hits       = 0;
			$per_page_details = [];

			// Parse WP blocks to rewrite safely.
			$blocks  = parse_blocks( $content );
			$rewrite = function ( array $blocks ) use ( &$rewrite, $old_uuid, $new_uuid, $preset_attrs, $new_entry, $strip_inline, $effective_scope, &$module_swap_hits, &$group_swap_hits, &$strip_hits, &$per_page_details, &$summary ) {
				foreach ( $blocks as $i => $block ) {
					$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
					if ( 'module' === $effective_scope && isset( $attrs['modulePreset'] ) && is_array( $attrs['modulePreset'] ) ) {
						// Replace every occurrence — modulePreset is a stacked-preset array, same UUID may appear multiple times.
						$block_swaps = 0;
						foreach ( $attrs['modulePreset'] as $idx => $uuid_value ) {
							if ( $old_uuid === $uuid_value ) {
								$attrs['modulePreset'][ $idx ] = $new_uuid;
								$block_swaps++;
							}
						}
						if ( $block_swaps > 0 ) {
							$module_swap_hits += $block_swaps;
							$detail = [
								'block'       => $block['blockName'] ?? '',
								'admin_label' => self::get_nested_array_value( $attrs, [ 'meta', 'adminLabel', 'desktop', 'value' ], '' ),
								'ref_type'    => 'module',
								'swaps'       => $block_swaps,
								'action'      => 'swap',
							];
							// Safe-strip guard: only strip inline attrs when the resulting preset stack is singular.
							// If other presets remain in modulePreset after the swap, they may intentionally override
							// fields — stripping inline could let them win and change rendering.
							$post_swap_stack = array_values( array_unique( $attrs['modulePreset'] ) );
							$is_singular_stack = ( 1 === count( $post_swap_stack ) && $new_uuid === $post_swap_stack[0] );

							if ( $strip_inline && $is_singular_stack && ! empty( $preset_attrs ) ) {
								$before_hash = md5( wp_json_encode( $attrs ) );
								$attrs = self::_strip_redundant_inline_attrs( $attrs, $preset_attrs );
								if ( md5( wp_json_encode( $attrs ) ) !== $before_hash ) {
									$strip_hits++;
									$detail['action'] = 'swap+strip';
								}
							} elseif ( $strip_inline && ! $is_singular_stack ) {
								$detail['strip_skipped'] = 'stacked_presets_present';
							}
							$per_page_details[] = $detail;
							$block['attrs'] = $attrs;
						}
					}

					if ( 'group' === $effective_scope && isset( $attrs['groupPreset'] ) && is_array( $attrs['groupPreset'] ) ) {
						// groupPreset is a slot map: { <slot>: { presetId: <scalar|array>, ... }, ... }.
						// presetId may be a scalar string or a stacked array — Divi accepts both shapes.
						$block_group_swaps = 0;
						$target_module     = (string) ( $block['blockName'] ?? '' );
						foreach ( $attrs['groupPreset'] as $slot_key => $slot ) {
							if ( ! is_array( $slot ) || ! isset( $slot['presetId'] ) ) {
								continue;
							}
							$ids_is_array = is_array( $slot['presetId'] );
							$ids          = $ids_is_array ? $slot['presetId'] : [ $slot['presetId'] ];
							$slot_swaps   = 0;
							foreach ( $ids as $idx => $uuid_value ) {
								if ( $old_uuid === $uuid_value ) {
									$ids[ $idx ] = $new_uuid;
									$slot_swaps++;
								}
							}
							if ( $slot_swaps > 0 ) {
								$slot['presetId']                  = $ids_is_array ? $ids : $ids[0];
								$attrs['groupPreset'][ $slot_key ] = $slot;
								$group_swap_hits                  += $slot_swaps;
								$block_group_swaps                += $slot_swaps;

								$detail = [
									'block'       => $block['blockName'] ?? '',
									'admin_label' => self::get_nested_array_value( $attrs, [ 'meta', 'adminLabel', 'desktop', 'value' ], '' ),
									'ref_type'    => 'group',
									'slot'        => (string) $slot_key,
									'swaps'       => $slot_swaps,
									'action'      => 'swap',
								];

								// Safe-strip guard: same singular-stack rule as module scope —
								// stacked presets on a slot may intentionally override the swapped preset's
								// values, so we only strip inline when the slot's presetId resolves to a
								// single unique UUID equal to new_uuid post-swap.
								$post_swap_ids = array_values( array_unique( $ids ) );
								$is_singular   = ( 1 === count( $post_swap_ids ) && $new_uuid === $post_swap_ids[0] );

								if ( $strip_inline && $is_singular && '' !== $target_module ) {
									// Resolve the preset's attrs as they'd apply to THIS module + slot — Divi's
									// own class handles slot→path mapping (composite button groups, -id-classes
									// suffix, cross-module name translation, explicit attrName component mappings).
									$resolved_preset_attrs = self::_resolve_group_preset_attrs_for_target(
										$new_entry,
										$target_module,
										(string) $slot_key
									);

									if ( null === $resolved_preset_attrs ) {
										// Mappable slots strip; unmappable slots skip and log — don't let one unknown
										// slot block strips for neighbor slots on the same module.
										$detail['strip_skipped'] = 'slot_unresolvable';
										$advisory_key            = $target_module . '::' . (string) $slot_key;
										if ( ! isset( $summary['strip_advisory_per_slot'][ $advisory_key ] ) ) {
											$summary['strip_advisory_per_slot'][ $advisory_key ] = 'GlobalPresetItemGroup returned no attrs for this module+slot — preset may be unregistered, class unavailable, or slot not exposed on target module. Swap applied; inline attrs unchanged for this slot.';
										}
									} else {
										$before_hash = md5( wp_json_encode( $attrs ) );
										$attrs       = self::_strip_redundant_inline_attrs( $attrs, $resolved_preset_attrs );
										if ( md5( wp_json_encode( $attrs ) ) !== $before_hash ) {
											$strip_hits++;
											$detail['action'] = 'swap+strip';
										}
									}
								} elseif ( $strip_inline && ! $is_singular ) {
									$detail['strip_skipped'] = 'stacked_presets_present';
								}

								$per_page_details[] = $detail;
							}
						}
						if ( $block_group_swaps > 0 ) {
							$block['attrs'] = $attrs;
						}
					}

					if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
						$block['innerBlocks'] = $rewrite( $block['innerBlocks'] );
					}
					$blocks[ $i ] = $block;
				}
				return $blocks;
			};
			$new_blocks = $rewrite( $blocks );

			$swap_hits = $module_swap_hits + $group_swap_hits;
			if ( $swap_hits > 0 ) {
				$summary['pages_modified']++;
				$summary['uuid_swaps']      += $swap_hits;
				$summary['module_swaps']    += $module_swap_hits;
				$summary['group_swaps']     += $group_swap_hits;
				$summary['inline_stripped'] += $strip_hits;
				$page_detail = [
					'page_id'      => $p->ID,
					'title'        => $p->post_title,
					'swaps'        => $swap_hits,
					'module_swaps' => $module_swap_hits,
					'group_swaps'  => $group_swap_hits,
					'strips'       => $strip_hits,
					'modules'      => $per_page_details,
				];

				if ( 'apply' === $mode ) {
					// Per-post capability gate — matches the pattern used by every other content-writing
					// endpoint in this plugin; defends against custom roles that hold manage_options but
					// are restricted on specific post types.
					if ( ! current_user_can( 'edit_post', $p->ID ) ) {
						$summary['errors'][] = [
							'page_id' => $p->ID,
							'title'   => $p->post_title,
							'error'   => 'Current user cannot edit this post',
						];
						$page_detail['update_error'] = 'Current user cannot edit this post';
					} else {
						$new_content   = serialize_blocks( $new_blocks );
						$update_result = wp_update_post(
							[
								'ID'           => $p->ID,
								'post_content' => wp_slash( $new_content ),
							],
							true
						);
						if ( is_wp_error( $update_result ) ) {
							$summary['errors'][] = [
								'page_id' => $p->ID,
								'title'   => $p->post_title,
								'error'   => $update_result->get_error_message(),
							];
							$page_detail['update_error'] = $update_result->get_error_message();
						} elseif ( 0 === (int) $update_result ) {
							$summary['errors'][] = [
								'page_id' => $p->ID,
								'title'   => $p->post_title,
								'error'   => 'wp_update_post returned 0 (no update performed)',
							];
							$page_detail['update_error'] = 'wp_update_post returned 0';
						} else {
							self::invalidate_divi_cache( $p->ID );
						}
					}
				}

				$summary['details'][] = $page_detail;
			}
		}

		// Registry chain rewrite — runs whenever effective scope is group, including when
		// old_uuid is dangling (not currently in the registry). A group preset's UUID may be
		// referenced from OTHER presets' chain slots — module presets via top-level
		// `groupPresets.<slot>.presetId`, or other group presets via `attrs.groupPreset.<slot>.presetId`.
		// Those chain refs can persist after the target preset was deleted —
		// collect_group_chain_refs() treats this case as valid for audit, so reassign must treat it
		// as rewritable for orphan-cleanup consistency. Skipping the rewrite when old_uuid is
		// dangling would leave stale chain refs behind after page-ref swaps, defeating the
		// advertised dangling-old-UUID workflow.
		//
		// Apply mode re-reads the registry immediately before the chain rewrite to minimize the
		// stale-overwrite window: our initial `$d5` was fetched before the page scan, which can
		// iterate up to REASSIGN_MAX_PAGES posts. A VB session mutating presets during that scan
		// would otherwise be clobbered. Dry-run uses the original $d5 since it never writes.
		$chain_details = [];
		if ( 'group' === $effective_scope ) {
			$chain_registry = ( 'apply' === $mode ) ? self::get_d5_presets() : $d5;
			$chain_result   = self::_rewrite_registry_group_chains( $chain_registry, $old_uuid, $new_uuid );
			$chain_swaps    = (int) $chain_result['swaps'];
			$chain_details  = $chain_result['details'];
			$summary['chain_swaps'] = $chain_swaps;

			if ( $chain_swaps > 0 && 'apply' === $mode ) {
				// Fold the chain-updated registry into the D5 storage. Atomic write — both
				// storage locations updated together by save_d5_presets().
				self::save_d5_presets( $chain_result['registry'] );
			}
		}
		if ( ! empty( $chain_details ) ) {
			$summary['chain_details'] = $chain_details;
		}

		if ( 'apply' !== $mode ) {
			$changes = [];
			foreach ( ( $summary['details'] ?? [] ) as $detail ) {
				$changes[] = [
					'kind'   => 'preset.reassign_page_refs',
					'target' => 'page#' . ( $detail['page_id'] ?? 'unknown' ),
					'before' => [
						'preset_id' => $old_uuid,
						'scope'     => $effective_scope,
					],
					'after'  => [
						'preset_id'    => $new_uuid,
						'swaps'        => $detail['swaps'] ?? 0,
						'strips'       => $detail['strips'] ?? 0,
						'module_swaps' => $detail['module_swaps'] ?? 0,
						'group_swaps'  => $detail['group_swaps'] ?? 0,
					],
				];
			}
			foreach ( ( $chain_details ?? [] ) as $detail ) {
				$changes[] = [
					'kind'   => 'preset.reassign_registry_chain',
					'target' => 'preset/' . ( $detail['bucket'] ?? 'unknown' ) . '/' . ( $detail['module'] ?? 'unknown' ) . '/' . ( $detail['referenced_by'] ?? 'unknown' ),
					'before' => [
						'preset_id' => $old_uuid,
						'slot'      => $detail['slot'] ?? null,
					],
					'after'  => [
						'preset_id' => $new_uuid,
						'swaps'     => $detail['swaps'] ?? 0,
					],
				];
			}

			$warnings = [];
			if ( ! empty( $summary['truncated'] ) ) {
				$warnings[] = 'Preset reassign scan was truncated to ' . ( $summary['max_pages'] ?? 0 ) . ' posts; chunk with page_ids before applying.';
			}
			foreach ( ( $summary['strip_advisory_per_slot'] ?? [] ) as $slot_key => $message ) {
				$warnings[] = $slot_key . ': ' . $message;
			}

			return self::dry_run_response(
				sprintf(
					'Would reassign preset refs %s -> %s: %d page(s), %d page ref swap(s), %d registry chain swap(s), %d inline strip(s).',
					$old_uuid,
					$new_uuid,
					$summary['pages_modified'] ?? 0,
					$summary['uuid_swaps'] ?? 0,
					$summary['chain_swaps'] ?? 0,
					$summary['inline_stripped'] ?? 0
				),
				$changes,
				$warnings,
				[
					'success'      => true,
					'mode'         => $mode,
					'scope'        => $scope,
					'strip_inline' => $strip_inline,
					'old_uuid'     => $old_uuid,
					'new_uuid'     => $new_uuid,
					'new_module'   => $new_mod,
					'summary'      => $summary,
				]
			);
		}

		$success = 'apply' !== $mode || empty( $summary['errors'] );
		return self::envelope_success( [
			'success'      => $success,
			'mode'         => $mode,
			'scope'        => $scope,
			'strip_inline' => $strip_inline,
			'old_uuid'     => $old_uuid,
			'new_uuid'     => $new_uuid,
			'new_module'   => $new_mod,
			'summary'      => $summary,
		] );
	}

	/**
	 * Walk the D5 preset registry and rewrite chain refs pointing at $old_uuid to $new_uuid.
	 *
	 * Walks the canonical location per bucket:
	 * - Module-bucket presets: top-level `groupPresets.<slot>.presetId`. Divi's VB bundle
	 *   `generateNewPreset` places `groupPresets` at the preset root and never mirrors it
	 *   into the attrs bags, so a single read/write at the root is sufficient.
	 * - Group-bucket presets: `<bag>.groupPreset.<slot>.presetId` (singular) across all three
	 *   attribute bags (attrs / styleAttrs / renderAttrs). The plugin's own `preset_update`
	 *   mirrors the full attrs bag into styleAttrs + renderAttrs to maintain the dual-pass
	 *   CSS lockstep (Pass A from attrs, Pass B from renderAttrs). Post-5.3.2 both `attrs`
	 *   AND `renderAttrs` are merged into the render pipeline (`ModuleRegistration.php:352-372`),
	 *   so a stale UUID in any bag would actively render. Each bag is rewritten surgically
	 *   and only if it already carries the ref — no blind-mirror, so pre-broken lockstep
	 *   (refs in some bags but not others) isn't silently clobbered.
	 *
	 * User-facing swap count + per-preset details come from the authoritative `attrs` bag only
	 * for group presets (or the root slot for module presets); mirrored rewrites in
	 * styleAttrs / renderAttrs are silent so counts don't inflate 3x when lockstep holds.
	 *
	 * Returns the updated registry + swap count + per-preset details. Does NOT write — caller
	 * decides whether to persist based on mode.
	 *
	 * `presetId` may be a scalar string or an array (Divi accepts both via the stacking convention).
	 * Slot key is preserved; only matching presetId entries are rewritten.
	 */
	private static function _rewrite_registry_group_chains( array $d5, string $old_uuid, string $new_uuid ): array {
		$swaps   = 0;
		$details = [];
		foreach ( [ 'module', 'group' ] as $bucket ) {
			if ( ! isset( $d5[ $bucket ] ) ) {
				continue;
			}
			$bucket_modules = (array) $d5[ $bucket ];
			foreach ( $bucket_modules as $mod => $info ) {
				$info  = (array) $info;
				$items = isset( $info['items'] ) ? (array) $info['items'] : [];
				foreach ( $items as $preset_uuid => $preset ) {
					if ( ! is_array( $preset ) && ! is_object( $preset ) ) {
						continue;
					}
					$preset = (array) $preset;

					if ( 'module' === $bucket ) {
						// Single-location rewrite at the preset root.
						$slot_map = self::_extract_chain_slot_map( $preset, $bucket );
						if ( empty( $slot_map ) ) {
							continue;
						}
						$result = self::_swap_chain_refs_in_group_presets_map( $slot_map, $old_uuid, $new_uuid );
						if ( 0 === $result['swaps'] ) {
							continue;
						}
						$preset                = self::_write_chain_slot_map( $preset, $bucket, $result['map'] );
						$items[ $preset_uuid ] = $preset;
						foreach ( $result['slot_swaps'] as $slot_key => $slot_count ) {
							$swaps    += $slot_count;
							$details[] = [
								'bucket'        => $bucket,
								'module'        => (string) $mod,
								'referenced_by' => (string) $preset_uuid,
								'slot'          => (string) $slot_key,
								'swaps'         => $slot_count,
							];
						}
						continue;
					}

					// Group bucket — rewrite per-bag so the attrs / styleAttrs / renderAttrs
					// mirrors stay in lockstep with the Pass A / Pass B CSS emission.
					$any_mutated      = false;
					$attrs_slot_swaps = [];
					foreach ( [ 'attrs', 'styleAttrs', 'renderAttrs' ] as $bag_key ) {
						if ( ! isset( $preset[ $bag_key ] ) ) {
							continue;
						}
						if ( ! is_array( $preset[ $bag_key ] ) && ! is_object( $preset[ $bag_key ] ) ) {
							continue;
						}
						$bag = (array) $preset[ $bag_key ];
						if ( ! isset( $bag['groupPreset'] ) ) {
							continue;
						}
						if ( ! is_array( $bag['groupPreset'] ) && ! is_object( $bag['groupPreset'] ) ) {
							continue;
						}
						$slot_map = (array) $bag['groupPreset'];
						$result   = self::_swap_chain_refs_in_group_presets_map( $slot_map, $old_uuid, $new_uuid );
						if ( $result['swaps'] > 0 ) {
							$bag['groupPreset'] = $result['map'];
							$preset[ $bag_key ] = $bag;
							$any_mutated        = true;
							if ( 'attrs' === $bag_key ) {
								$attrs_slot_swaps = $result['slot_swaps'];
							}
						}
					}

					if ( $any_mutated ) {
						$items[ $preset_uuid ] = $preset;
						foreach ( $attrs_slot_swaps as $slot_key => $slot_count ) {
							$swaps    += $slot_count;
							$details[] = [
								'bucket'        => $bucket,
								'module'        => (string) $mod,
								'referenced_by' => (string) $preset_uuid,
								'slot'          => (string) $slot_key,
								'swaps'         => $slot_count,
							];
						}
					}
				}
				if ( isset( $info['items'] ) ) {
					$info['items']          = $items;
					$bucket_modules[ $mod ] = $info;
				}
			}
			$d5[ $bucket ] = $bucket_modules;
		}
		return [ 'swaps' => $swaps, 'details' => $details, 'registry' => $d5 ];
	}

	/**
	 * Swap `presetId` references inside a single chain-ref slot map.
	 *
	 * Consumed by `_rewrite_registry_group_chains` — accepts the slot map extracted from either
	 * canonical location (top-level `groupPresets` on module presets, `attrs.groupPreset` on
	 * group presets — see `_extract_chain_slot_map`). Returns the mutated map + total swap count
	 * + per-slot swap counts. Callers write the mutated map back via `_write_chain_slot_map`.
	 *
	 * Each slot is cast from array-or-object before reading — stdClass slots are a real shape on
	 * sites where the D5 option round-tripped through JSON or a custom importer.
	 */
	private static function _swap_chain_refs_in_group_presets_map( array $group_presets_map, string $old_uuid, string $new_uuid ): array {
		$swaps      = 0;
		$slot_swaps = [];
		foreach ( $group_presets_map as $slot_key => $slot ) {
			if ( ! is_array( $slot ) && ! is_object( $slot ) ) {
				continue;
			}
			$slot = (array) $slot;
			if ( ! isset( $slot['presetId'] ) ) {
				continue;
			}
			$ids_is_array    = is_array( $slot['presetId'] );
			$ids             = $ids_is_array ? $slot['presetId'] : [ $slot['presetId'] ];
			$this_slot_swaps = 0;
			foreach ( $ids as $idx => $uuid_value ) {
				if ( $old_uuid === $uuid_value ) {
					$ids[ $idx ] = $new_uuid;
					$this_slot_swaps++;
				}
			}
			if ( $this_slot_swaps > 0 ) {
				$slot['presetId']                       = $ids_is_array ? $ids : $ids[0];
				$group_presets_map[ $slot_key ]         = $slot;
				$swaps                                 += $this_slot_swaps;
				$slot_swaps[ (string) $slot_key ]       = $this_slot_swaps;
			}
		}
		return [ 'map' => $group_presets_map, 'swaps' => $swaps, 'slot_swaps' => $slot_swaps ];
	}

	/**
	 * Top-level block-attr keys that carry identity/binding data, not style — never strip these
	 * even if a caller happened to store matching values in preset attrs.
	 */
	private static function strip_reserved_keys(): array {
		return [
			'meta',                // adminLabel, module identity
			'modulePreset',        // preset reference itself
			'groupPreset',         // attribute-level preset references
			'dynamicOptionGroups', // Composable Settings tracking
			'id',
			'storeInstanceId',
			'name',
			'moduleName',
			'builderVersion',
		];
	}

	/**
	 * Resolve a group preset's attrs as they would apply to a target module + slot.
	 *
	 * Delegates slot→target-path mapping to Divi's own `GlobalPresetItemGroup` class, which already
	 * handles every edge case we care about: composite button groups, `-id-classes` suffix, explicit
	 * `attrName` component mappings (FormField / checkbox / radio), cross-module attr-name translation,
	 * and dynamic option-group subtrees. Reimplementing would only invite drift — this call returns
	 * attrs in the exact shape they'd merge onto the target module's inline attrs at render time.
	 *
	 * Parity with Divi's render path — matches `GlobalPreset::get_selected_group_presets()` +
	 * `GlobalPreset::get_merged_attrs()`:
	 *   - Runs runtime preset migration via `_maybe_runtime_migrate_preset_data` before constructing
	 *     the item (Divi does this at both `GlobalPreset.php:2485` and `:2518`). Older-shape presets
	 *     get migrated to canonical paths so strip compares against the actual rendered tree.
	 *   - Merges all three bags — `styleAttrs + attrs + renderAttrs` — because `get_merged_attrs()`
	 *     at `GlobalPreset.php:3179` merges group presets' renderAttrs into the final bag alongside
	 *     attrs; fields stored only in renderAttrs still override module inline and must be stripped.
	 *
	 * Results are cached per-request keyed by preset UUID + target module + slot — the resolver is
	 * pure within a single page scan, and `preset_reassign` may hit the same (module, slot, preset)
	 * tuple across many blocks on one page.
	 *
	 * Returns null when Divi's class isn't loaded or the resolver returns empty (unknown module,
	 * slot not registered, etc.) — callers should emit a per-slot advisory in that case and skip
	 * strip for the unmappable slot only.
	 *
	 * @param array  $new_entry          Full preset registry entry (includes `attrs`, `styleAttrs`,
	 *                                   `renderAttrs`, `groupName`, `groupId`, `moduleName`, etc.)
	 * @param string $target_module_name The block's module name (e.g. "divi/heading") — where the
	 *                                   preset is being applied, may differ from the preset's source module.
	 * @param string $slot_id            The slot path from `attrs.groupPreset.<slot>` on the target module.
	 * @return array|null Resolved preset attrs deep-merged (styleAttrs then attrs then renderAttrs), or null on failure.
	 */
	private static function _resolve_group_preset_attrs_for_target( array $new_entry, string $target_module_name, string $slot_id ) {
		static $cache = [];

		$item_class    = '\ET\Builder\Packages\GlobalData\GlobalPresetItemGroup';
		$preset_class  = '\ET\Builder\Packages\GlobalData\GlobalPreset';
		if ( ! class_exists( $item_class ) || ! class_exists( $preset_class ) ) {
			return null;
		}

		$preset_id = isset( $new_entry['id'] ) && is_string( $new_entry['id'] ) ? $new_entry['id'] : '';
		$cache_key = $preset_id . '|' . $target_module_name . '|' . $slot_id;
		if ( '' !== $preset_id && array_key_exists( $cache_key, $cache ) ) {
			return $cache[ $cache_key ];
		}

		try {
			// Parity step 1 — runtime migration. Divi always runs this before constructing the item
			// (see GlobalPreset.php:2485 and :2518). Skipping it would compare against stale paths on
			// sites carrying pre-5.3.0 preset shapes (FocusFields, ComposibleOptions, PresetStack).
			$migrated = $new_entry;
			try {
				$method = new \ReflectionMethod( $preset_class, '_maybe_runtime_migrate_preset_data' );
				$method->setAccessible( true );
				$migrated = $method->invoke( null, $new_entry, $target_module_name );
				if ( ! is_array( $migrated ) ) {
					$migrated = $new_entry;
				}
			} catch ( \Throwable $migrate_e ) {
				// If reflection/migration fails (unexpected), fall through with the unmigrated entry.
				// Worst case: stale paths for legacy preset shapes — same as pre-fix behavior for that
				// subset, while fully-migrated sites still benefit.
				$migrated = $new_entry;
			}

			$item = new $item_class( [
				'data'       => $migrated,
				'isExist'    => true,
				'moduleName' => $target_module_name,
				'groupId'    => $slot_id,
			] );

			$resolved_attrs        = $item->get_data_attrs();
			$resolved_style_attrs  = $item->get_data_style_attrs();
			$resolved_render_attrs = $item->get_data_render_attrs();
		} catch ( \Throwable $e ) {
			if ( '' !== $preset_id ) {
				$cache[ $cache_key ] = null;
			}
			return null;
		}

		if ( ! is_array( $resolved_attrs ) ) {
			$resolved_attrs = [];
		}
		if ( ! is_array( $resolved_style_attrs ) ) {
			$resolved_style_attrs = [];
		}
		if ( ! is_array( $resolved_render_attrs ) ) {
			$resolved_render_attrs = [];
		}

		if ( empty( $resolved_attrs ) && empty( $resolved_style_attrs ) && empty( $resolved_render_attrs ) ) {
			if ( '' !== $preset_id ) {
				$cache[ $cache_key ] = null;
			}
			return null;
		}

		// Parity step 2 — merge all three bags. `GlobalPreset::get_merged_attrs()` at line 3179 does
		// `array_replace_recursive( $module_presets_attrs, $group_presets_attrs, $group_presets_render_attrs, $module_attrs )`
		// — group-preset renderAttrs merge into the final bag alongside attrs. Strip must see the
		// same union or fields stored only in renderAttrs silently survive strip_inline=true.
		$merged = self::_deep_merge( $resolved_style_attrs, $resolved_attrs );
		$merged = self::_deep_merge( $merged, $resolved_render_attrs );

		if ( '' !== $preset_id ) {
			$cache[ $cache_key ] = $merged;
		}

		return $merged;
	}

	/**
	 * Recursively remove attrs from $inline that are deep-equal to the value in $preset at the same path.
	 * Preserves unrelated branches. Top-level reserved keys (meta, modulePreset, etc.) are always preserved
	 * so preset_reassign never strips identity/binding data even if a caller wrote matching values into the preset.
	 */
	private static function _strip_redundant_inline_attrs( $inline, $preset, bool $is_root = true ) {
		if ( ! is_array( $inline ) || ! is_array( $preset ) ) {
			return $inline;
		}
		$reserved = $is_root ? self::strip_reserved_keys() : [];
		foreach ( $inline as $key => $val ) {
			if ( in_array( $key, $reserved, true ) ) {
				continue;
			}
			if ( ! array_key_exists( $key, $preset ) ) {
				continue;
			}
			if ( is_array( $val ) && is_array( $preset[ $key ] ) ) {
				$inline[ $key ] = self::_strip_redundant_inline_attrs( $val, $preset[ $key ], false );
				if ( is_array( $inline[ $key ] ) && empty( $inline[ $key ] ) ) {
					unset( $inline[ $key ] );
				}
			} elseif ( $val === $preset[ $key ] ) {
				unset( $inline[ $key ] );
			}
		}
		return $inline;
	}

	/**
	 * Scan page content for modulePreset UUIDs that do NOT exist in the D5 registry.
	 * Categorizes as dangling orphans or D4-legacy refs.
	 */
	public static function preset_scan_orphans( $request ) {
		$d5     = self::get_d5_presets();
		$refs   = self::collect_page_preset_refs();
		// Mirror preset_list: the option can be serialized-string on some environments.
		$legacy = et_get_option( 'builder_global_presets_ng', (object) [], '', true, false, '', '', true );
		$legacy = is_string( $legacy ) ? maybe_unserialize( $legacy ) : $legacy;
		$legacy = is_array( $legacy ) || is_object( $legacy ) ? (array) $legacy : [];

		$d5_uuids = [];
		foreach ( [ 'module', 'group' ] as $bucket ) {
			if ( ! isset( $d5[ $bucket ] ) ) {
				continue;
			}
			foreach ( (array) $d5[ $bucket ] as $mod => $info ) {
				$info  = (array) $info;
				$items = isset( $info['items'] ) ? (array) $info['items'] : [];
				foreach ( $items as $pid => $_ ) {
					$d5_uuids[ $pid ] = true;
				}
			}
		}

		$legacy_uuids = [];
		foreach ( $legacy as $mod => $module_presets ) {
			$module_presets = is_array( $module_presets ) ? (object) $module_presets : $module_presets;
			if ( ! is_object( $module_presets ) || empty( $module_presets->presets ) ) {
				continue;
			}
			foreach ( (array) $module_presets->presets as $pid => $_ ) {
				$legacy_uuids[ $pid ] = $mod;
			}
		}

		// Build uuid → pages[] index once (O(P) pre-pass) so orphan/legacy resolution is O(U) instead of O(U×P).
		// Dedup per (uuid,page_id) defensively: `custom_uuids` is already deduped per page in
		// collect_page_preset_refs, but keep this robust if that invariant ever changes.
		$uuid_to_pages = [];
		foreach ( $refs['per_page'] as $pid => $pinfo ) {
			$page_entry   = [ 'page_id' => $pid, 'title' => $pinfo['title'] ];
			$custom_uuids = array_unique( (array) ( $pinfo['custom_uuids'] ?? [] ) );
			foreach ( $custom_uuids as $uuid ) {
				$uuid_to_pages[ $uuid ][ $pid ] = $page_entry;
			}
		}
		foreach ( $uuid_to_pages as $uuid => $pages ) {
			$uuid_to_pages[ $uuid ] = array_values( $pages );
		}

		$orphans     = [];
		$legacy_refs = [];
		foreach ( $refs['all_uuids'] as $uuid => $count ) {
			if ( isset( $d5_uuids[ $uuid ] ) ) {
				continue;
			}
			$pages_with = $uuid_to_pages[ $uuid ] ?? [];
			if ( isset( $legacy_uuids[ $uuid ] ) ) {
				$legacy_refs[] = [
					'uuid'          => $uuid,
					'ref_count'     => $count,
					'legacy_module' => $legacy_uuids[ $uuid ],
					'pages'         => $pages_with,
				];
			} else {
				$orphans[] = [
					'uuid'      => $uuid,
					'ref_count' => $count,
					'pages'     => $pages_with,
				];
			}
		}

		return self::envelope_success( [
			'orphan_count'         => count( $orphans ),
			'legacy_ref_count'     => count( $legacy_refs ),
			'total_referenced'     => count( $refs['all_uuids'] ),
			'total_in_registry'    => count( $d5_uuids ),
			'orphans'              => $orphans,
			'd4_legacy_candidates' => $legacy_refs,
		] );
	}
}
