<?php
/**
 * Trait DiviOps_Agent_GlobalColor
 *
 * Global color CRUD + ID/value validation.
 *
 * Mixed into DiviOps_Agent via `use` in diviops-agent.php — `self::` calls and
 * class constants resolve as if these methods lived directly on the class.
 *
 * All four handlers route through envelope_success / envelope_error.
 * Mapping:
 *   - input-shape rejections (mode outside allowlist, malformed id charset/length,
 *     non-CSS-color value, missing color on a new entry, malformed gcid on delete,
 *     non-array colors payload) collapse onto `invalid_input` with structured
 *     `error.data` documenting the failed field. Multi-entry create/update calls
 *     annotate `error.data.colors_index` so batch callers can identify which
 *     entry failed.
 *   - WP customizer-bound color defaults (gcid-primary-color / gcid-secondary-color
 *     / gcid-heading-color / gcid-body-color / gcid-link-color) reject with
 *     `variable.customizer_default_immutable` (HTTP 403). Same identity (and same
 *     code) as the variable_delete precedent — these gcids straddle both surfaces
 *     because Divi 5.4+ unified color globals into the variable manager while
 *     preserving the customizer-binding semantics for the five legacy defaults.
 *   - global_color_delete on a color with live `usedInPosts` references returns
 *     `conflict` (HTTP 409) with `error.data = { id, ref_count, used_in_posts }`.
 *     Fourth concrete `conflict` adoption (after canvas_duplicate / library_save /
 *     variable_delete). Note `used_in_posts` is Divi's own index, populated on
 *     VB save — it may lag behind a color recently assigned via MCP. The shape
 *     is pass-through (Divi-maintained), distinct from variable_delete's
 *     locations[] which we actively build via parse_blocks.
 *   - capability check (`current_user_can('manage_options')`) keeps a `WP_Error`
 *     return — the REST framework promotes it via wp-client.ts's parseThrownError
 *     into an envelope error so callers see HTTP 403 unchanged. Matches the
 *     canvas adoption precedent.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_GlobalColor {

	/**
	 * Candidate storage paths for the global_colors surface, in READ priority
	 * order. Per #719 contract, the LIST handler probes these in order and
	 * returns content from the first non-empty path.
	 *
	 * Path 1 is what `et_get_option('et_global_data')` reads on
	 * one-row-stored substrates (which is the standard 5.x layout); kept as
	 * an explicit probe entry so the `_meta.source_path` provenance is
	 * unambiguous to consumers regardless of how Divi happens to resolve
	 * `et_get_option` on a given substrate. Path 2 is the standalone
	 * top-level `et_global_data` option — not observed on either tested
	 * 5.5.x substrate, but kept as a defensive probe in case a non-row-
	 * stored layout writes it.
	 */
	private static function global_color_paths(): array {
		return [
			[ 'path' => 'et_divi.et_global_data.global_colors', 'provenance' => 'et_divi_nested' ],
			[ 'path' => 'et_global_data.global_colors',         'provenance' => 'top_level' ],
		];
	}

	/**
	 * Probe a `et_divi.et_global_data.<sub>` style nested path.
	 *
	 * Returns the deserialized array (possibly empty) or null when the path
	 * holds no usable content. Distinguishes "key absent" from "empty array
	 * seeded" via null-vs-`[]`.
	 */
	private static function read_et_global_data_subkey( string $sub_key ) {
		$raw         = et_get_option( 'et_global_data' );
		$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : null;
		$global_data = self::normalize_storage_array( $global_data );
		if ( null === $global_data ) {
			return null;
		}
		if ( ! array_key_exists( $sub_key, $global_data ) ) {
			return null;
		}
		$value = $global_data[ $sub_key ];
		return self::normalize_storage_array( $value );
	}

	/**
	 * Read one configured global-color path.
	 */
	private static function read_global_color_path( string $path ) {
		if ( 'et_divi.et_global_data.global_colors' === $path ) {
			return self::read_et_global_data_subkey( 'global_colors' );
		}
		if ( 'et_global_data.global_colors' === $path ) {
			$raw = get_option( 'et_global_data', '' );
			if ( empty( $raw ) ) {
				return null;
			}
			$top = self::normalize_storage_array( maybe_unserialize( $raw ) );
			if ( null === $top ) {
				return null;
			}
			return self::normalize_storage_array( $top['global_colors'] ?? null );
		}
		return null;
	}

	/**
	 * Current WP Customizer color values, keyed by synthetic gcid.
	 *
	 * Divi's own GlobalData::get_customizer_colors() reads
	 * et_get_option($option_name, $default); using the default map alone would
	 * ignore actual site-level Customizer selections.
	 */
	private static function customizer_color_entries(): array {
		if ( ! class_exists( '\ET\Builder\Packages\GlobalData\GlobalData' ) ) {
			return [];
		}
		$src = \ET\Builder\Packages\GlobalData\GlobalData::$customizer_colors ?? [];
		$out = [];
		foreach ( (array) $src as $id => $meta ) {
			if ( ! is_string( $id ) || '' === $id || ! is_array( $meta ) ) {
				continue;
			}
			$option_name = isset( $meta['option_name'] ) ? (string) $meta['option_name'] : '';
			$default     = isset( $meta['default'] ) ? (string) $meta['default'] : '';
			$value       = '' !== $option_name ? et_get_option( $option_name, $default ) : $default;
			$out[ $id ]  = [
				'color'       => sanitize_text_field( (string) $value ),
				'folder'      => 'customizer',
				'label'       => $meta['label'] ?? $id,
				'lastUpdated' => null,
				'status'      => 'active',
				'usedInPosts' => [],
				'source'      => 'wp_customizer',
				'managed_by'  => 'wp_customizer',
			];
		}
		return $out;
	}

	/**
	 * Get global colors via the documented priority-ordered probe.
	 *
	 * Storage-path contract (#719): per AC #7, returns user-added `gcid-oa-*`
	 * colors from `et_divi.et_global_data.global_colors` on substrates where
	 * that path holds them. Per AC #8, also surfaces WP-customizer-default
	 * colors from `et_divi.{accent_color, header_color, font_color, link_color}`
	 * with synthetic `gcid-{primary, secondary, heading, body, link}-color`
	 * IDs (sourced via the GlobalData::$customizer_colors class property so
	 * future upstream additions land automatically — matches the
	 * `variable_list` pattern at trait-variable.php:319-332).
	 *
	 * Response shape (always top-level `_meta`):
	 *   {
	 *     ok: true,
	 *     data: { colors: { <gcid>: <record>, ... }, customizer: { <gcid>: <record>, ... } },
	 *     _meta: {
	 *       source_path:   "et_divi.et_global_data.global_colors" | "et_global_data.global_colors" | null,
	 *       probed_paths:  [ ... ],
	 *       customizer_source: "et_divi.<slot>" (always present when customizer-bound colors exist)
	 *     }
	 *   }
	 *
	 * Response carries the `colors` map as a JSON object regardless of
	 * emptiness (`(object) []` cast) so consumers can deserialize against
	 * a fixed schema.
	 */
	public static function global_color_list( $request ) {
		$probed_paths = [];
		$source_path  = null;
		$colors       = [];
		foreach ( self::global_color_paths() as $c ) {
			$probed_paths[] = $c['path'];
			if ( null !== $source_path ) {
				continue;
			}
			$content = self::read_global_color_path( $c['path'] );
			if ( null !== $content && ! empty( $content ) ) {
				$colors      = $content;
				$source_path = $c['path'];
			}
		}

		// Customizer-bound defaults — parallel surface, always merged when
		// the GlobalData class exposes them. Per AC #8, surface with synthetic
		// `gcid-{primary,...}-color` IDs. These are sourced from the class
		// property at trait-variable.php:319-332 so the pin-defaults pattern
		// stays in lockstep with `variable_list`.
		$customizer = self::customizer_color_entries();

		$response = self::envelope_success( [
			'colors'     => (object) $colors,
			'customizer' => (object) $customizer,
		] );
		$body          = $response->get_data();
		$meta          = [
			'source_path'  => $source_path,
			'probed_paths' => $probed_paths,
		];
		if ( ! empty( $customizer ) ) {
			$meta['customizer_source'] = 'et_divi.{accent_color, header_color, font_color, link_color, ...}';
		}
		$body['_meta'] = $meta;
		$response->set_data( $body );
		return $response;
	}

	/**
	 * Audit the global_colors storage landscape — aggregate across all
	 * candidate paths with per-entry provenance and warnings.
	 *
	 * Storage-path contract (#719): probes every `et_global_data.global_colors`
	 * candidate path PLUS the customizer-bound defaults at
	 * `et_divi.{accent_color, header_color, font_color, link_color, ...}`.
	 * Entries surfaced from the customizer get `provenance: "wp_customizer"`
	 * distinct from D5 entries. Per-path collisions surface as warnings;
	 * first-write wins by priority into `aggregated`.
	 */
	public static function global_color_audit_storage( $request ) {
		$aggregated    = [];
		$entry_sources = [];
		$warnings      = [];
		$probed_paths  = [];

		foreach ( self::global_color_paths() as $c ) {
			$probed_paths[] = $c['path'];
			$content        = self::read_global_color_path( $c['path'] );
			if ( null === $content || empty( $content ) ) {
				continue;
			}
			foreach ( $content as $id => $entry ) {
				if ( ! is_string( $id ) ) {
					continue;
				}
				if ( isset( $aggregated[ $id ] ) ) {
					$shape_match = self::entries_shape_match( $aggregated[ $id ], $entry );
					$warnings[]  = [
						'type'        => $shape_match ? 'id_collision' : 'shape_inconsistency',
						'id'          => $id,
						'first_path'  => $entry_sources[ $id ]['path'] ?? null,
						'second_path' => $c['path'],
					];
					continue;
				}
				$aggregated[ $id ]    = $entry;
				$entry_sources[ $id ] = [
					'path'       => $c['path'],
					'provenance' => $c['provenance'],
				];
			}
		}

		// Customizer-bound defaults — parallel surface with its own provenance.
		$customizer_path = 'et_divi.{accent_color, header_color, font_color, link_color, ...}';
		$probed_paths[]  = $customizer_path;
		foreach ( self::customizer_color_entries() as $id => $entry ) {
			if ( isset( $aggregated[ $id ] ) ) {
				// User override in global_colors wins by D5 priority; record
				// the customizer as a parallel source for full audit symmetry.
				$warnings[] = [
					'type'        => 'id_collision',
					'id'          => $id,
					'first_path'  => $entry_sources[ $id ]['path'] ?? null,
					'second_path' => $customizer_path,
					'note'        => 'User palette overrides the customizer default; audit surfaces both for inventory.',
				];
				continue;
			}
			$aggregated[ $id ]    = $entry;
			$entry_sources[ $id ] = [
				'path'       => $customizer_path,
				'provenance' => 'wp_customizer',
			];
		}

		$response = self::envelope_success( [
			'aggregated' => (object) $aggregated,
		] );
		$body          = $response->get_data();
		$body['_meta'] = [
			'probed_paths'  => $probed_paths,
			'entry_sources' => $entry_sources,
			'warnings'      => $warnings,
		];
		$response->set_data( $body );
		return $response;
	}

	/**
	 * The 5 customizer-bound default global color IDs. These are managed
	 * via WP Customizer and writing to them through the registry creates
	 * an entry that Divi's customizer-merge path then hides from registry
	 * reads (see GlobalData::get_global_colors at GlobalData.php:341 — it
	 * removes customizer-bound colors from the returned set). A write to
	 * one of these via MCP would silently no-op from a UI perspective.
	 */
	private static function customizer_locked_color_ids(): array {
		return [
			'gcid-primary-color',
			'gcid-secondary-color',
			'gcid-heading-color',
			'gcid-body-color',
			'gcid-link-color',
		];
	}

	/**
	 * Validate a global-color ID and return the canonical form, or a
	 * WP_Error explaining why it was rejected.
	 *
	 * Auto-prefixes `gcid-` if missing (caller convenience). Then enforces
	 * Divi's downstream regex constraints:
	 *
	 * - GlobalData.php:760 extracts IDs via `/--gcid-([0-9a-z-]*)/` so
	 *   anything outside `[0-9a-z-]` after the prefix breaks CSS-variable
	 *   resolution silently (id stored, $variable() lookup fails).
	 * - Style.php:935 emits CSS variable names directly from the ID so a
	 *   long ID generates a long var name; cap at 80 chars (matches the
	 *   uuid4 default).
	 * - Customizer-bound defaults (see customizer_locked_color_ids) are
	 *   rejected to prevent the silent registry-vs-customizer mismatch
	 *   between Divi's $variable() resolver and the Theme Customizer.
	 *
	 * Empty input returns the canonical generated form `gcid-<uuid4>`.
	 */
	private static function validate_global_color_id( $raw, bool $allow_customizer_locked = false ) {
		$raw = sanitize_text_field( (string) $raw );
		if ( '' === $raw ) {
			return 'gcid-' . wp_generate_uuid4();
		}
		// Auto-prefix.
		$id = ( 0 === strpos( $raw, 'gcid-' ) ) ? $raw : 'gcid-' . $raw;

		// Reserved customizer-bound defaults.
		if ( ! $allow_customizer_locked && in_array( $id, self::customizer_locked_color_ids(), true ) ) {
			return new WP_Error(
				'reserved_id',
				sprintf( "'%s' is bound to the WP Customizer (Theme Options → General → Color Schemes). Writes to it via the registry are hidden from VB. Edit through Customizer instead.", $id ),
				[ 'status' => 403 ]
			);
		}

		// Charset + length: the suffix after `gcid-` must match
		// `[0-9a-z-]{1,80}` (Divi's extraction regex + sane upper bound).
		$suffix = substr( $id, 5 );
		if ( ! preg_match( '/^[0-9a-z-]{1,80}$/', $suffix ) ) {
			return new WP_Error(
				'invalid_id',
				sprintf( "Color ID '%s' contains characters outside the allowed [0-9a-z-] set, or exceeds 80 chars after the 'gcid-' prefix. Divi's CSS-variable resolution at GlobalData.php:760 strips non-matching IDs silently.", $id ),
				[ 'status' => 400 ]
			);
		}
		return $id;
	}

	/**
	 * Sanitize a CSS color value for the global-colors registry.
	 *
	 * Accepts hex (#rgb/#rrggbb/#rrggbbaa) and CSS rgb()/rgba()/hsl()/hsla()
	 * functional notation — Divi's stock palette uses both (verified via live
	 * read: gcid-4907c8d4 stores 'rgba(0,0,0,0.3)'). `sanitize_hex_color()` is
	 * too restrictive on its own; we accept functional notation after a
	 * shape check.
	 *
	 * Returns the sanitized color string, or `null` if the input is empty or
	 * doesn't match either accepted shape. Callers should treat null as
	 * invalid input and surface a 400 to the user instead of silently writing
	 * a placeholder color into the palette.
	 */
	private static function sanitize_color( $raw ): ?string {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}
		// Hex fast-path.
		$hex = sanitize_hex_color( $raw );
		if ( null !== $hex && '' !== $hex ) {
			return $hex;
		}
		// Functional CSS color (rgb/rgba/hsl/hsla) — accept the shape, then
		// generic sanitize for any control characters.
		if ( preg_match( '/^(rgba?|hsla?)\s*\(\s*[0-9.\-,%\s\/]+\s*\)$/i', $raw ) ) {
			return sanitize_text_field( $raw );
		}
		// Reject anything else.
		return null;
	}

	/**
	 * Upsert global colors in Divi's VB settings (creates new + updates existing).
	 *
	 * Colors array: [{"id":"gcid-my-color","label":"My Color","color":"#ff0000"}]
	 * Omit `id` on an entry to mint a new one (server returns the canonical
	 * `gcid-<uuid4>`); pass an existing id to update that color in place.
	 * Mode: "merge" (add/update, keep existing) or "replace" (replace all).
	 */
	public static function global_color_upsert( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Requires admin capability', [ 'status' => 403 ] );
		}

		$new_colors = $request->get_param( 'colors' );
		$mode       = sanitize_key( (string) ( $request->get_param( 'mode' ) ?? 'merge' ) );
		if ( ! in_array( $mode, [ 'merge', 'replace' ], true ) ) {
			return self::envelope_error(
				'invalid_input',
				'mode must be "merge" or "replace".',
				null,
				400,
				[ 'field' => 'mode', 'allowed' => [ 'merge', 'replace' ], 'received' => $mode ]
			);
		}
		if ( ! is_array( $new_colors ) ) {
			return self::envelope_error(
				'invalid_input',
				'colors must be an array of color definitions.',
				null,
				400,
				[ 'field' => 'colors', 'expected' => 'array of color definitions' ]
			);
		}

		// Divi 5 stores global colors in et_global_data option.
		$raw = et_get_option( 'et_global_data' );
		$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
		if ( ! is_array( $global_data ) ) {
			$global_data = [];
		}
		$existing = $global_data['global_colors'] ?? [];
		// Stable pre-write registry snapshot for dry_run diff. The `$existing`
		// variable below gets reassigned per-entry inside the loop, so capture
		// the registry-level shape under a separate name here.
		$registry_before = is_array( $existing ) ? $existing : [];

		// Build color map from existing (keyed by gcid-*).
		$color_map = [];
		if ( 'merge' === $mode && is_array( $existing ) ) {
			$color_map = $existing;
		}

		// Add/update new colors. Shape mirrors Divi's canonical global-color
		// payload (verified via GlobalDataController:46-69 + live-read of stock
		// Divi-written colors): {color, folder, label, lastUpdated, status,
		// usedInPosts}. Divi's `sanitize_global_colors_data` accepts arbitrary
		// fields generically (no schema enforcement beyond gcid-* id + non-empty
		// color), so extra/missing fields don't break — but matching the
		// canonical shape keeps our writes indistinguishable from VB-written
		// ones and avoids surprising future Divi versions that may tighten the
		// schema. `usedInPosts` defaults to empty (Divi populates it on save);
		// `folder` defaults to '' (no folder).
		//
		// Merge semantics: when an `id` is supplied for an existing color,
		// PRESERVE existing fields not explicitly overwritten by the input.
		// This makes single-field updates (e.g. only `color`) safe — the
		// caller doesn't have to re-supply label/folder/status to keep them.
		// New colors (no id, or unknown id) seed defaults from scratch.
		$added = 0;
		foreach ( $new_colors as $idx => $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$id = self::validate_global_color_id( $c['id'] ?? '' );
			if ( is_wp_error( $id ) ) {
				// Annotate with the colors[] index so batch callers can identify
				// which entry failed validation. Map the helper's WP_Error code
				// onto the envelope vocabulary:
				//   - reserved_id (customizer-bound default) → variable.customizer_default_immutable
				//     (HTTP 403) — same identity as variable_delete's customizer
				//     guard; reuse the namespace-prefixed code so dispatch on it
				//     works uniformly across both deletion and write attempts.
				//   - invalid_id (charset/length violation) → invalid_input with
				//     { field, expected, received }.
				$wp_err     = $id;
				$wp_code    = $wp_err->get_error_code();
				$wp_message = $wp_err->get_error_message();
				$wp_data    = (array) $wp_err->get_error_data();
				$wp_status  = isset( $wp_data['status'] ) ? (int) $wp_data['status'] : 400;
				$received   = sanitize_text_field( (string) ( $c['id'] ?? '' ) );

				if ( 'reserved_id' === $wp_code ) {
					return self::envelope_error(
						'variable.customizer_default_immutable',
						sprintf( 'colors[%d]: %s', $idx, $wp_message ),
						'Edit the corresponding theme option via WP Customizer (Theme Options → General → Color Schemes) instead of writing through this tool.',
						403,
						[
							'id'           => $received,
							'managed_by'   => 'wp_customizer',
							'colors_index' => $idx,
						]
					);
				}

				return self::envelope_error(
					'invalid_input',
					sprintf( 'colors[%d]: %s', $idx, $wp_message ),
					null,
					$wp_status,
					[
						'field'        => sprintf( 'colors[%d].id', $idx ),
						'expected'     => 'gcid-<suffix> where suffix matches [0-9a-z-]{1,80}',
						'received'     => $received,
						'colors_index' => $idx,
					]
				);
			}
			$existing = isset( $color_map[ $id ] ) && is_array( $color_map[ $id ] )
				? $color_map[ $id ]
				: [];

			// Color: validate before write. New entries (no existing) MUST have
			// a valid color; updates (existing entry) can omit color to keep it.
			if ( isset( $c['color'] ) ) {
				$color = self::sanitize_color( $c['color'] );
				if ( null === $color ) {
					return self::envelope_error(
						'invalid_input',
						sprintf( 'colors[%d].color is not a valid CSS color (hex or rgba/hsla notation expected).', $idx ),
						null,
						400,
						[
							'field'        => sprintf( 'colors[%d].color', $idx ),
							'expected'     => 'hex (#rgb/#rrggbb/#rrggbbaa) or functional rgba()/hsla() notation',
							'received'     => is_scalar( $c['color'] ) ? (string) $c['color'] : null,
							'colors_index' => $idx,
						]
					);
				}
			} elseif ( ! empty( $existing ) ) {
				$color = $existing['color'] ?? '#000000';
			} else {
				return self::envelope_error(
					'invalid_input',
					sprintf( 'colors[%d] is missing required `color` field for new entry.', $idx ),
					null,
					400,
					[
						'field'        => sprintf( 'colors[%d].color', $idx ),
						'missing'      => 'color',
						'colors_index' => $idx,
					]
				);
			}

			$label = array_key_exists( 'label', $c )
				? sanitize_text_field( $c['label'] )
				: ( $existing['label'] ?? '' );

			$folder = array_key_exists( 'folder', $c )
				? sanitize_text_field( $c['folder'] )
				: ( $existing['folder'] ?? '' );

			$status_raw = $c['status'] ?? ( $existing['status'] ?? 'active' );
			$status     = in_array( $status_raw, [ 'active', 'archived' ], true ) ? $status_raw : 'active';

			// Preserve usedInPosts on update — Divi tracks where each color is
			// referenced; our writer should never clobber that index. Read from
			// existing entry if present, else seed empty.
			$used_in_posts = isset( $existing['usedInPosts'] ) && is_array( $existing['usedInPosts'] )
				? $existing['usedInPosts']
				: [];

			$color_map[ $id ] = [
				'color'       => $color,
				'folder'      => $folder,
				'label'       => $label,
				'lastUpdated' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
				'status'      => $status,
				'usedInPosts' => $used_in_posts,
			];
			$added++;
		}

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			$changes = [];
			foreach ( $new_colors as $idx => $c ) {
				if ( ! is_array( $c ) ) {
					continue;
				}
				$id = $c['id'] ?? '';
				$is_new = ( '' === $id ) || ! isset( $registry_before[ $id ] );
				$changes[] = [
					'kind'   => $is_new ? 'global_color.create' : 'global_color.update',
					'target' => $is_new ? 'global_color' : "global_color/{$id}",
					'before' => $is_new ? null : ( $registry_before[ $id ] ?? null ),
					'after'  => [
						'color'  => $c['color']  ?? null,
						'label'  => $c['label']  ?? null,
						'folder' => $c['folder'] ?? null,
						'status' => $c['status'] ?? null,
					],
				];
			}
			$created = array_filter( $changes, static fn( $ch ) => 'global_color.create' === $ch['kind'] );
			$updated = array_filter( $changes, static fn( $ch ) => 'global_color.update' === $ch['kind'] );
			return self::dry_run_response(
				sprintf( "Would upsert %d color(s): %d new + %d existing (mode=%s).", count( $changes ), count( $created ), count( $updated ), $mode ),
				$changes
			);
		}

		// Save back.
		$global_data['global_colors'] = $color_map;
		et_update_option( 'et_global_data', $global_data );

		return self::attach_meta(
			self::envelope_success( [
				'count'   => count( $color_map ),
				'added'   => $added,
				'colors'  => $color_map,
			] ),
			[ 'canonical_path' => 'et_divi.et_global_data.global_colors' ]
		);
	}

	/**
	 * Delete a global color from the registry by gcid.
	 *
	 * Mirrors the safety pattern of `variable_delete`: refuses to delete the
	 * 5 customizer-bound default colors (`gcid-primary-color`,
	 * `gcid-secondary-color`, `gcid-heading-color`, `gcid-body-color`,
	 * `gcid-link-color`) since those are managed via WP Customizer and
	 * removing them from the registry would break theme inheritance even if
	 * the customizer values stay set.
	 *
	 * Soft-warns when a color is referenced anywhere in `post_content` across
	 * pages / TB layouts / library / canvas / preset registry — caller must
	 * pass `force=true` to delete anyway. Reuses the parse_blocks scan from
	 * `variable_delete` (`collect_variable_refs`) so MCP-authored content is
	 * detected reliably; Divi's own `usedInPosts` index is VB-save-bound and
	 * silently misses headless writes.
	 */
	public static function global_color_delete( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Requires admin capability', [ 'status' => 403 ] );
		}

		$gcid  = sanitize_text_field( $request->get_param( 'gcid' ) );
		$force = (bool) $request->get_param( 'force' );

		if ( '' === $gcid || 0 !== strpos( $gcid, 'gcid-' ) ) {
			return self::envelope_error(
				'invalid_input',
				'gcid must be a non-empty string starting with "gcid-".',
				null,
				400,
				[ 'field' => 'gcid', 'expected' => 'gcid-<suffix>', 'received' => $gcid ]
			);
		}

		// Customizer-bound defaults — not deletable via MCP regardless of
		// `force`. Removing them from the registry breaks Divi's theme
		// inheritance because the customizer keeps the value but the
		// registry lookup falls through. Reuses the namespace-prefixed
		// `variable.customizer_default_immutable` code from the variable_delete
		// precedent — same identity (gcid-primary-color etc.) across both
		// surfaces, so callers branch on one code uniformly.
		if ( in_array( $gcid, self::customizer_locked_color_ids(), true ) ) {
			return self::envelope_error(
				'variable.customizer_default_immutable',
				sprintf( "'%s' is bound to the WP Customizer (Theme Options → General → Color Schemes) and cannot be deleted via this tool.", $gcid ),
				'Edit the corresponding theme option via WP Customizer instead of attempting deletion.',
				403,
				[ 'id' => $gcid, 'managed_by' => 'wp_customizer' ]
			);
		}

		$raw         = et_get_option( 'et_global_data' );
		$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
		if ( ! is_array( $global_data ) ) {
			$global_data = [];
		}
		$colors = isset( $global_data['global_colors'] ) && is_array( $global_data['global_colors'] )
			? $global_data['global_colors']
			: [];

		if ( ! isset( $colors[ $gcid ] ) ) {
			return self::envelope_error(
				'not_found',
				"Color '{$gcid}' not found in registry.",
				'Run diviops_global_color_list to see existing gcids.',
				404
			);
		}

		$color = $colors[ $gcid ];

		// Live-reference soft-block via parse_blocks scan — mirrors
		// variable_delete's contract. Two-tier: cheap SQL LIKE +
		// preset-option substring scan first; only fall through to the
		// full collect_variable_refs() walk on a positive hit so the 409
		// body carries accurate per-location records. The walker's regex
		// already matches both gvid- and gcid- prefixes
		// (`/g[vc]id-[A-Za-z0-9_-]+/`) — same scan surfaces, same logic.
		// Cache the fast-path result so the dry_run branch below can reuse
		// it without re-issuing the SQL/option scan.
		$ref_count       = 0;
		$locations       = [];
		$refs            = null;
		$appears         = self::variable_id_appears_anywhere( $gcid );
		if ( ! $force && $appears ) {
			$refs = self::collect_variable_refs();
			if ( isset( $refs['all_ids'][ $gcid ] ) ) {
				return self::envelope_error(
					'conflict',
					sprintf(
						"Color '%s' has %d live reference(s). Pass force=true to delete anyway; orphan refs will render as invalid CSS until the pages are re-authored.",
						$gcid,
						$refs['all_ids'][ $gcid ]
					),
					'Pass force=true to override, or remove references first; run diviops_variable_scan_orphans afterwards if forced.',
					409,
					[
						'id'             => $gcid,
						'ref_count'      => $refs['all_ids'][ $gcid ],
						'locations'      => $refs['locations'][ $gcid ] ?? [],
						'scan_truncated' => $refs['scan_truncated'],
						'scanned_posts'  => $refs['scanned_posts'],
					]
				);
			}
		}

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			// Resolve ref data for the preview — when force=true skipped the
			// fast-path branch above $refs is still null; reuse the cached
			// $appears probe to decide whether the full scan is worth
			// running. force=false-with-zero-refs falls through with
			// $refs unset; treat as zero refs.
			if ( null === $refs && $appears ) {
				$refs = self::collect_variable_refs();
			}
			if ( null !== $refs ) {
				$ref_count = $refs['all_ids'][ $gcid ] ?? 0;
				$locations = $refs['locations'][ $gcid ] ?? [];
			}
			$label = $color['label'] ?? '';
			$value = $color['color'] ?? '';
			return self::dry_run_response(
				"Would delete global color '{$gcid}' ('{$label}', {$value}). {$ref_count} live reference(s).",
				[ [
					'kind'   => 'global_color.delete',
					'target' => "global_color/{$gcid}",
					'before' => [
						'color'     => $value,
						'label'     => $label,
						'ref_count' => $ref_count,
						'locations' => $locations,
					],
				] ]
			);
		}

		unset( $colors[ $gcid ] );
		$global_data['global_colors'] = $colors;
		et_update_option( 'et_global_data', $global_data );

		return self::attach_meta(
			self::envelope_success( [
				'deleted' => [
					'gcid'   => $gcid,
					'color'  => $color['color'] ?? '',
					'label'  => $color['label'] ?? '',
					'forced' => $force,
				],
				'message' => "Color '{$gcid}' deleted.",
			] ),
			[ 'canonical_path' => 'et_divi.et_global_data.global_colors' ]
		);
	}
}
