<?php
/**
 * Trait DiviOps_Agent_Variable
 *
 * Variable CRUD, fluid system generator, orphan scan, page usage.
 *
 * Mixed into DiviOps_Agent via `use` in diviops-agent.php — `self::` calls and
 * class constants resolve as if these methods lived directly on the class.
 *
 * All six handlers route through envelope_success / envelope_error. The
 * variable_* namespace is the most error-vocabulary-rich surface in the
 * envelope rollout — variable_create alone has 9 distinct rejection paths.
 * The mapping collapses input-shape rejections onto `invalid_input` with
 * structured `error.data` documenting the failed field; algorithmic
 * clamp() failures preserve their distinction under namespace-prefixed
 * codes (`variable.fluid_generation_failed`, `variable.fluid_system_generation_failed`).
 * variable_delete on a variable with live references returns `conflict`
 * (HTTP 409) with `error.data = { id, ref_count, locations[] }` — third
 * concrete `conflict` adoption (after canvas_duplicate / library_save).
 * Customizer-bound color defaults (gcid-primary-color etc.) reject with
 * `variable.customizer_default_immutable` (HTTP 403).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marker exception for input-shape rejections raised from inside the
 * fluid-clamp helpers (parse_size_with_unit failures, viewport parse
 * failures, bounds violations on typography/spacing/radius config).
 *
 * Distinguishes input-shape errors from genuine algorithmic failures
 * (degenerate viewport range, NaN-producing math) at the catch site so
 * the envelope routes correctly:
 *   - DiviOps_Variable_Input_Exception → `invalid_input` envelope
 *   - any other Exception              → `variable.fluid_*_failed` envelope
 *
 * Extends \InvalidArgumentException so any legacy `instanceof` checks
 * (or PHP-level Throwable handlers) still treat it as one.
 */
if ( ! class_exists( 'DiviOps_Variable_Input_Exception' ) ) {
	class DiviOps_Variable_Input_Exception extends \InvalidArgumentException {}
}

trait DiviOps_Agent_Variable {

	/**
	 * Recursively walk any PHP value collecting `gvid-` and `gcid-` references
	 * from $variable(...)$ tokens. Token shape (after parse_blocks decodes block attrs):
	 * `$variable({"type":"...","value":{"name":"gvid-XXXX","settings":{}}})$`
	 *
	 * Pre-check on the `$variable(` substring avoids running preg_match_all on
	 * every string leaf of every attr tree — most leaves are short values like
	 * px/color/url that can't possibly carry a variable token.
	 */
	private static function walk_value_for_variable_refs( $value, &$all_ids, &$local_ids ) {
		if ( is_string( $value ) ) {
			if ( false === strpos( $value, '$variable(' ) ) {
				return;
			}
			if ( preg_match_all( '/"name"\s*:\s*"(g[vc]id-[A-Za-z0-9_-]+)"/', $value, $m ) ) {
				foreach ( $m[1] as $id ) {
					$all_ids[ $id ] = ( $all_ids[ $id ] ?? 0 ) + 1;
					$local_ids[]    = $id;
				}
			}
			return;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $v ) {
				self::walk_value_for_variable_refs( $v, $all_ids, $local_ids );
			}
		}
	}

	/**
	 * Recursively walk a parsed-blocks tree, scanning each block's attrs for
	 * gvid-/gcid- references via walk_value_for_variable_refs.
	 */
	private static function walk_blocks_for_variable_refs( $blocks, &$all_ids, &$local_ids ) {
		foreach ( $blocks as $block ) {
			if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				self::walk_value_for_variable_refs( $block['attrs'], $all_ids, $local_ids );
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk_blocks_for_variable_refs( $block['innerBlocks'], $all_ids, $local_ids );
			}
		}
	}

	/**
	 * Collect every variable reference across all content surfaces.
	 *
	 * Scanned surfaces:
	 * - Pages + posts (post_content blocks)
	 * - Theme Builder layouts — header / body / footer (each stored as a
	 *   separate post type: et_header_layout / et_body_layout / et_footer_layout).
	 *   The et_theme_builder / et_template records that link these together
	 *   hold only assignment metadata (which layout runs where), not the
	 *   block markup itself — they're intentionally excluded from scanning
	 * - Divi Library items (et_pb_layout) — saved module/row/section markup
	 * - Canvas pages (et_pb_canvas)
	 * - Preset registry (et_divi_builder_global_presets_d5) — a preset's attrs /
	 *   styleAttrs / renderAttrs / groupPresets chain may all embed $variable()$
	 *   tokens when the preset was saved against a variable-bound control
	 *
	 * Capped at VARIABLES_SCAN_MAX_POSTS to avoid OOM / timeout on large
	 * sites. When the cap is hit, `scan_truncated` flags the response so
	 * callers know the orphan list may be incomplete.
	 *
	 * Returns:
	 *   all_ids         — { id => ref_count } aggregated across all surfaces
	 *   locations       — { id => [ { type, ... } ] } per-reference location records
	 *   scan_truncated  — true if the post cap was hit
	 *   scanned_posts   — number of posts actually scanned
	 */
	private static function collect_variable_refs() {
		$all_ids        = [];
		$locations      = [];
		$scan_truncated = false;

		// Pages + TB layouts + library + canvas. Fetch one sentinel row
		// past the cap so we can distinguish "site has exactly N posts" (no
		// truncation) from "site has more than N" (real truncation) without
		// paying for a SELECT FOUND_ROWS() pass. `no_found_rows` skips that
		// pass. Discard the sentinel before scanning so the scan scope stays
		// honest.
		$posts = get_posts( [
			'post_type'      => self::SCANNABLE_POST_TYPES,
			'post_status'    => [ 'publish', 'draft', 'private' ],
			'posts_per_page' => self::VARIABLES_SCAN_MAX_POSTS + 1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		] );

		if ( count( $posts ) > self::VARIABLES_SCAN_MAX_POSTS ) {
			$scan_truncated = true;
			$posts          = array_slice( $posts, 0, self::VARIABLES_SCAN_MAX_POSTS );
		}

		foreach ( $posts as $p ) {
			$content = $p->post_content;
			if ( false === strpos( $content, '$variable(' ) ) {
				continue;
			}
			$blocks    = parse_blocks( $content );
			$local_ids = [];
			self::walk_blocks_for_variable_refs( $blocks, $all_ids, $local_ids );

			foreach ( array_unique( $local_ids ) as $id ) {
				$locations[ $id ][] = [
					'type'    => $p->post_type,
					'post_id' => $p->ID,
					'title'   => $p->post_title,
				];
			}
		}

		// Preset registry.
		$d5 = self::get_d5_presets();
		foreach ( [ 'module', 'group' ] as $bucket ) {
			if ( ! isset( $d5[ $bucket ] ) ) {
				continue;
			}
			foreach ( (array) $d5[ $bucket ] as $mod => $info ) {
				$info  = (array) $info;
				$items = isset( $info['items'] ) ? (array) $info['items'] : [];
				foreach ( $items as $uuid => $preset ) {
					$preset    = (array) $preset;
					$local_ids = [];
					self::walk_value_for_variable_refs( $preset, $all_ids, $local_ids );
					foreach ( array_unique( $local_ids ) as $id ) {
						$locations[ $id ][] = [
							'type'        => 'preset',
							'bucket'      => $bucket,
							'module'      => $mod,
							'preset_uuid' => $uuid,
							'preset_name' => $preset['name'] ?? '',
						];
					}
				}
			}
		}

		return [
			'all_ids'        => $all_ids,
			'locations'      => $locations,
			'scan_truncated' => $scan_truncated,
			'scanned_posts'  => count( $posts ),
		];
	}

	/**
	 * Cheap existence check — "does this variable id appear anywhere?".
	 * No parse_blocks; a single SQL LIKE on `post_content` (scoped to the
	 * scannable post types + post_status and limited to 1 row — note
	 * `post_content` is not indexed in the stock WordPress schema, so the
	 * query still scans the matching rows, but the scope + LIMIT keep it
	 * cheap), plus a substring check on the preset registry option.
	 *
	 * Used by variable_delete to short-circuit the happy path: if nothing
	 * anywhere references the id, skip the expensive collect_variable_refs()
	 * call. False-positive tolerant — `$id` is distinctive (`g[vc]id-...`) so
	 * a literal occurrence in page content or the preset registry almost
	 * always corresponds to a real ref; on a hit we fall through to the
	 * full scan to produce an accurate 409 location list anyway.
	 */
	private static function variable_id_appears_anywhere( $id ) {
		global $wpdb;
		if ( '' === $id ) {
			return false;
		}

		// Needle is just the bare id — Divi stores tokens with unicode-escaped
		// quotes (`\u0022name\u0022:\u0022gvid-...\u0022`), and the preset
		// registry is serialized PHP (raw quotes), so any quoted-wrapper
		// pattern would mismatch one of the two surfaces. The `g[vc]id-`
		// prefix is distinctive enough that a literal occurrence of the id
		// in content/options almost always corresponds to a real ref;
		// positive hits fall through to the full parse_blocks scan which
		// rigorously confirms + locates. False-positive tolerant by design.
		$placeholders = implode( ',', array_fill( 0, count( self::SCANNABLE_POST_TYPES ), '%s' ) );
		$needle       = '%' . $wpdb->esc_like( $id ) . '%';

		// Content scan.
		$sql = $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->posts}
				WHERE post_status IN ('publish','draft','private')
					AND post_type IN ($placeholders)
					AND post_content LIKE %s
				LIMIT 1",
			array_merge( self::SCANNABLE_POST_TYPES, [ $needle ] )
		);
		if ( (bool) $wpdb->get_var( $sql ) ) {
			return true;
		}

		// Preset registry scan. `get_option` returns the already-unserialized
		// value when the option was stored via `update_option` (typical
		// Divi path → stored as array/object), so a naive is_string guard
		// would miss the array case and let preset-only references slip
		// past the fast-path — silently orphaning the preset on delete.
		//
		// Rather than materializing the whole structure via wp_json_encode
		// on every call (allocation + encoding pressure that scales with
		// registry size), walk the in-memory tree and strpos only string
		// leaves, returning on first hit. Early-exit keeps the fast-path
		// genuinely fast even on large preset registries.
		$raw = get_option( 'et_divi_builder_global_presets_d5', '' );
		if ( self::value_contains_substring( $raw, $id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Recursive early-exit substring check — walks any PHP value (string,
	 * array, object) and returns true at the first string leaf containing
	 * `$needle`. Used by `variable_id_appears_anywhere` to scan the preset
	 * registry without the allocation cost of serializing the whole tree.
	 */
	private static function value_contains_substring( $value, $needle ) {
		if ( '' === $needle ) {
			return false;
		}
		if ( is_string( $value ) ) {
			return false !== strpos( $value, $needle );
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $v ) {
				if ( self::value_contains_substring( $v, $needle ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Get the map of every defined variable ID from the Variable Manager.
	 * Colors live in `et_global_data.global_colors`; others live in
	 * `et_divi_global_variables` grouped by type. Divi's customizer-bound
	 * accent colors (gcid-primary-color, gcid-secondary-color, gcid-heading-color,
	 * gcid-body-color, gcid-link-color) resolve via a separate code path
	 * (`GlobalData::$customizer_colors`) and are intentionally excluded from
	 * et_global_colors on save — include them here so they don't false-positive
	 * as orphans on every stock Divi 5 install.
	 */
	private static function get_defined_variable_ids() {
		$ids = [];

		// Colors.
		$raw         = et_get_option( 'et_global_data' );
		$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
		$colors      = ( is_array( $global_data ) && is_array( $global_data['global_colors'] ?? null ) )
			? $global_data['global_colors']
			: [];
		foreach ( $colors as $id => $c ) {
			if ( is_array( $c ) ) {
				$ids[ $id ] = [
					'type'  => 'colors',
					'label' => $c['label'] ?? $id,
					'value' => $c['color'] ?? '',
				];
			}
		}

		// Divi customizer-bound colors. Source via the class property (not a
		// hardcoded list) so new customizer colors added by upstream Divi land
		// automatically. Guard with class_exists in case Divi 4 is active or
		// the class is namespaced differently in a future release. Tagged
		// with source='customizer' so variable_scan_orphans can exclude them
		// from the unused_variables bucket — they can't be deleted via
		// variable_delete (bound to theme options, not the Variable Manager),
		// so surfacing them as "deletion candidates" would mislead callers.
		if ( class_exists( '\ET\Builder\Packages\GlobalData\GlobalData' ) ) {
			$customizer = \ET\Builder\Packages\GlobalData\GlobalData::$customizer_colors ?? [];
			foreach ( (array) $customizer as $id => $meta ) {
				if ( isset( $ids[ $id ] ) ) {
					continue; // User override in global_colors wins.
				}
				$ids[ $id ] = [
					'type'   => 'colors',
					'label'  => $meta['label'] ?? $id,
					'value'  => $meta['default'] ?? '',
					'source' => 'customizer',
				];
			}
		}

		// Non-color types.
		$vars = get_option( 'et_divi_global_variables', [] );
		if ( is_array( $vars ) ) {
			foreach ( [ 'numbers', 'strings', 'images', 'links', 'fonts' ] as $type ) {
				if ( ! is_array( $vars[ $type ] ?? null ) ) {
					continue;
				}
				foreach ( $vars[ $type ] as $id => $v ) {
					if ( is_array( $v ) ) {
						$ids[ $id ] = [
							'type'  => $type,
							'label' => $v['label'] ?? $id,
							'value' => $v['value'] ?? '',
						];
					}
				}
			}
		}

		return $ids;
	}

	// ── Variable Manager CRUD ──────────────────────────────────────

	/**
	 * List all variables, optionally filtered by type or ID prefix.
	 * Colors come from et_divi.et_global_data.global_colors.
	 * Numbers/strings/images/links/fonts come from et_divi_global_variables.
	 */
	public static function variable_list( $request ) {
		$filter_type   = sanitize_key( (string) ( $request->get_param( 'type' ) ?? '' ) );
		$filter_prefix = sanitize_text_field( (string) ( $request->get_param( 'prefix' ) ?? '' ) );
		$result        = [];

		$valid_types = [ 'colors', 'numbers', 'strings', 'images', 'links', 'fonts' ];
		if ( $filter_type && ! in_array( $filter_type, $valid_types, true ) ) {
			return self::envelope_error(
				'invalid_input',
				'type must be one of: ' . implode( ', ', $valid_types ) . '.',
				null,
				400,
				[ 'field' => 'type', 'allowed' => $valid_types, 'received' => $filter_type ]
			);
		}

		// Inactive/archived sort last. Status fields seen on stored data: 'active', 'inactive',
		// 'archived', 'temporary' (per Divi GlobalData::convert_global_colors_data + sanitize
		// paths). Anything not 'active' is demoted; entries with no `status` default to active.
		$is_active = static fn( array $v ): bool => ( $v['status'] ?? 'active' ) === 'active';

		// Five WP/customizer-default global colors live alongside user palette in
		// et_global_data['global_colors'] but Divi never assigns them an `order` field —
		// their canonical position is fixed by the source map at GlobalData.php:43-49 and
		// $customizer_colors:58-84. Pin them to the top of the colors bucket so consumers
		// don't see them demoted to the missing-order tail.
		$wp_default_color_order = [
			'gcid-primary-color'   => 1,
			'gcid-secondary-color' => 2,
			'gcid-heading-color'   => 3,
			'gcid-body-color'      => 4,
			'gcid-link-color'      => 5,
		];

		// Comparator for non-color buckets (numbers/strings/images/links/fonts).
		// Active first, then numeric `order` ascending, then no-order legacy entries,
		// then inactive/archived. Stable label tiebreak.
		$sort_by_order = static function ( array $a, array $b ) use ( $is_active ): int {
			$a_active = $is_active( $a ) ? 0 : 1;
			$b_active = $is_active( $b ) ? 0 : 1;
			if ( $a_active !== $b_active ) {
				return $a_active <=> $b_active;
			}
			$a_has = isset( $a['order'] ) && is_numeric( $a['order'] );
			$b_has = isset( $b['order'] ) && is_numeric( $b['order'] );
			if ( $a_has && $b_has ) {
				$cmp = (float) $a['order'] <=> (float) $b['order'];
				if ( 0 !== $cmp ) {
					return $cmp;
				}
			} elseif ( $a_has !== $b_has ) {
				return $a_has ? -1 : 1;
			}
			return strcmp( (string) ( $a['label'] ?? '' ), (string) ( $b['label'] ?? '' ) );
		};

		// Comparator for colors — WP-defaults pin first, then delegate to $sort_by_order.
		$sort_colors = static function ( array $a, array $b ) use ( $sort_by_order, $wp_default_color_order ): int {
			$a_default = $wp_default_color_order[ $a['__id'] ?? '' ] ?? null;
			$b_default = $wp_default_color_order[ $b['__id'] ?? '' ] ?? null;
			if ( null !== $a_default && null !== $b_default ) {
				return $a_default <=> $b_default;
			}
			if ( null !== $a_default ) {
				return -1;
			}
			if ( null !== $b_default ) {
				return 1;
			}
			return $sort_by_order( $a, $b );
		};

		// Colors (separate storage).
		if ( ! $filter_type || 'colors' === $filter_type ) {
			$raw         = et_get_option( 'et_global_data' );
			$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
			$colors      = is_array( $global_data ) ? ( $global_data['global_colors'] ?? [] ) : [];

			$colors = array_filter( $colors, 'is_array' );
			// Stamp __id on a local copy so the comparator can recognize WP defaults without
			// closing over the foreach key. The field is not emitted in the response — see the
			// explicit projection below.
			array_walk( $colors, static function ( &$c, $id ): void {
				$c['__id'] = $id;
			} );
			uasort( $colors, $sort_colors );

			foreach ( $colors as $id => $c ) {
				if ( $filter_prefix && 0 !== strpos( $id, $filter_prefix ) ) {
					continue;
				}
				$result[] = [
					'id'          => $id,
					'type'        => 'colors',
					'label'       => $c['label'] ?? $id,
					'value'       => $c['color'] ?? '',
					'order'       => $c['order'] ?? ( $wp_default_color_order[ $id ] ?? null ),
					'status'      => $c['status'] ?? 'active',
					'lastUpdated' => $c['lastUpdated'] ?? null,
				];
			}
		}

		// Non-color types from et_divi_global_variables.
		$vars      = get_option( 'et_divi_global_variables', [] );
		if ( ! is_array( $vars ) ) {
			$vars = [];
		}
		$var_types  = [ 'numbers', 'strings', 'images', 'links', 'fonts' ];

		foreach ( $var_types as $type ) {
			if ( $filter_type && $filter_type !== $type ) {
				continue;
			}
			if ( ! is_array( $vars[ $type ] ?? null ) ) {
				continue;
			}
			$bucket = array_filter( $vars[ $type ], 'is_array' );
			uasort( $bucket, $sort_by_order );

			foreach ( $bucket as $id => $v ) {
				if ( $filter_prefix && 0 !== strpos( $id, $filter_prefix ) ) {
					continue;
				}
				$result[] = [
					'id'          => $id,
					'type'        => $type,
					'label'       => $v['label'] ?? $id,
					'value'       => $v['value'] ?? '',
					'order'       => $v['order'] ?? null,
					'status'      => $v['status'] ?? 'active',
					'lastUpdated' => $v['lastUpdated'] ?? null,
				];
			}
		}

		return self::envelope_success( [
			'count'     => count( $result ),
			'variables' => $result,
		] );
	}

	/**
	 * Count Divi's customizer-bound colors (gcid-primary-color etc.). They
	 * render implicitly at the start of the Variable Manager but live in
	 * GlobalData::$customizer_colors — separate storage from the user palette
	 * at et_global_data.global_colors. New user variables must offset past
	 * this count to avoid colliding with the implicit first-slot defaults.
	 * Sourced via the class property so future upstream additions land
	 * automatically; class_exists guard protects against Divi 4 / namespace
	 * churn. Shared by variable_create and global_color_upsert.
	 */
	private static function get_customizer_color_count() {
		if ( ! class_exists( '\ET\Builder\Packages\GlobalData\GlobalData' ) ) {
			return 0;
		}
		return count( (array) ( \ET\Builder\Packages\GlobalData\GlobalData::$customizer_colors ?? [] ) );
	}

	/**
	 * Parse a positive px value like "20px" → float. Used for viewport
	 * anchors (which are always px — a viewport width in rem is ambiguous
	 * because it depends on root font-size at evaluation time).
	 */
	private static function parse_fluid_px( $str ) {
		if ( ! is_string( $str ) ) {
			return null;
		}
		// Accept leading-dot CSS formats like ".5px" and "-.2px" alongside
		// the common "20px" / "1.25px" / "-5px" forms.
		if ( preg_match( '/^(-?\d*\.?\d+)px$/', trim( $str ), $m ) ) {
			return (float) $m[1];
		}
		return null;
	}

	/**
	 * Parse a viewport anchor like "320px" → positive float.
	 */
	private static function parse_fluid_viewport( $str ) {
		$n = self::parse_fluid_px( $str );
		return ( null !== $n && $n > 0 ) ? $n : null;
	}

	/**
	 * Parse a size value with its unit (px or rem) into a normalized
	 * { num, unit } pair. Used for fluid min/max/target values which may
	 * be either unit. Rem-to-px conversion happens later using the
	 * caller-declared root_font_size_px so the math remains correct on
	 * sites with non-16px root font-size.
	 */
	private static function parse_size_with_unit( $str ) {
		if ( ! is_string( $str ) ) {
			return null;
		}
		if ( preg_match( '/^(-?\d*\.?\d+)(px|rem)$/', trim( $str ), $m ) ) {
			return [ 'num' => (float) $m[1], 'unit' => $m[2] ];
		}
		return null;
	}

	/**
	 * Format a numeric value with up to 3 decimals, trailing zeros trimmed,
	 * and negative-zero normalized to "0". Shared by the size + slope
	 * formatters to keep decimal-emission consistent.
	 */
	private static function format_fluid_decimal( $num ) {
		$s = rtrim( rtrim( number_format( $num, 3, '.', '' ), '0' ), '.' );
		if ( '' === $s || '-0' === $s ) {
			$s = '0';
		}
		return $s;
	}

	/**
	 * Format a px value in the target output unit. Uses the caller-supplied
	 * root font-size (defaults to 16, the standard browser default) for
	 * px→rem conversion so sites with a non-standard `html { font-size }`
	 * can emit correct rem values.
	 */
	private static function format_fluid_size( $num_px, $output_unit, $root_font_size_px = 16.0 ) {
		$val = ( 'rem' === $output_unit ) ? $num_px / $root_font_size_px : $num_px;
		return self::format_fluid_decimal( $val ) . $output_unit;
	}

	/**
	 * Format a vw slope value — always in vw, independent of output_unit
	 * (vw is viewport-pixel-rooted, so the slope term does not carry a
	 * rem↔px conversion).
	 */
	private static function format_fluid_slope( $slope_abs ) {
		return self::format_fluid_decimal( $slope_abs ) . 'vw';
	}

	/**
	 * Build a clamp() formula from two (viewport, value) anchor points,
	 * both in px. Emits min/max ordered by value (not viewport) so negative
	 * slopes work. Collapses to a scalar when both values are equal. The
	 * slope term is inherently viewport-pixel-rooted (vw = 1% of viewport
	 * pixels), so when output_unit is rem the caller's root_font_size_px
	 * is used to keep the rem values arithmetically consistent with the vw
	 * slope. All internal math is in px.
	 */
	private static function build_fluid_clamp( $w1, $v1, $w2, $v2, $output_unit = 'px', $root_font_size_px = 16.0 ) {
		if ( abs( $w2 - $w1 ) < 0.01 ) {
			// Genuine algorithmic failure: anchors collapsed to a single
			// viewport, so the slope is undefined. Raise a plain
			// \InvalidArgumentException (NOT the input-shape marker) so
			// the catch block routes this to `variable.fluid_*_failed`
			// rather than `invalid_input`.
			throw new \InvalidArgumentException( 'Fluid anchors cannot share the same viewport.' );
		}
		if ( abs( $v2 - $v1 ) < 0.01 ) {
			return self::format_fluid_size( $v1, $output_unit, $root_font_size_px );
		}
		$slope_vw = ( $v2 - $v1 ) / ( $w2 - $w1 ) * 100.0;
		$base_px  = $v1 - ( $slope_vw * $w1 / 100.0 );
		$min_v    = min( $v1, $v2 );
		$max_v    = max( $v1, $v2 );
		$op       = ( $slope_vw >= 0 ) ? '+' : '-';
		return sprintf(
			'clamp(%s, %s %s %s, %s)',
			self::format_fluid_size( $min_v, $output_unit, $root_font_size_px ),
			self::format_fluid_size( $base_px, $output_unit, $root_font_size_px ),
			$op,
			self::format_fluid_slope( abs( $slope_vw ) ),
			self::format_fluid_size( $max_v, $output_unit, $root_font_size_px )
		);
	}

	/**
	 * Generate a clamp() from min/max shorthand using default anchors 320px
	 * and 1920px (industry convention for fluid scales). Accepts px or rem
	 * inputs; rem values are converted to px internally using the caller-
	 * declared root_font_size_px before the slope math runs.
	 */
	private static function generate_fluid_clamp_from_minmax( $min_str, $max_str, $output_unit = 'px', $root_font_size_px = 16.0 ) {
		$min_p = self::parse_size_with_unit( $min_str );
		$max_p = self::parse_size_with_unit( $max_str );
		if ( null === $min_p ) {
			throw new \DiviOps_Variable_Input_Exception( "Invalid min: '$min_str' — expected e.g. '20px' or '1.25rem'." );
		}
		if ( null === $max_p ) {
			throw new \DiviOps_Variable_Input_Exception( "Invalid max: '$max_str' — expected e.g. '60px' or '3.75rem'." );
		}
		$min_px = ( 'rem' === $min_p['unit'] ) ? $min_p['num'] * $root_font_size_px : $min_p['num'];
		$max_px = ( 'rem' === $max_p['unit'] ) ? $max_p['num'] * $root_font_size_px : $max_p['num'];
		return self::build_fluid_clamp( 320.0, $min_px, 1920.0, $max_px, $output_unit, $root_font_size_px );
	}

	/**
	 * Generate a clamp() from explicit { viewport => value } targets.
	 * Requires exactly 2 entries. Viewport keys must be px; values may
	 * be px or rem. Rem values are converted to px internally using the
	 * caller-declared root_font_size_px before the slope math runs.
	 */
	private static function generate_fluid_clamp_from_targets( $targets, $output_unit = 'px', $root_font_size_px = 16.0 ) {
		if ( ! is_array( $targets ) || 2 !== count( $targets ) ) {
			throw new \DiviOps_Variable_Input_Exception( "targets must contain exactly 2 entries keyed by viewport width (e.g. {'320px':'20px','1920px':'60px'})." );
		}
		$points = [];
		foreach ( $targets as $viewport => $value_str ) {
			$w_px = self::parse_fluid_viewport( (string) $viewport );
			if ( null === $w_px ) {
				throw new \DiviOps_Variable_Input_Exception( "Invalid viewport key '$viewport' — expected px (e.g. '320px')." );
			}
			$v = self::parse_size_with_unit( (string) $value_str );
			if ( null === $v ) {
				throw new \DiviOps_Variable_Input_Exception( "Invalid target value '$value_str' — expected e.g. '20px' or '1.25rem'." );
			}
			$v_px = ( 'rem' === $v['unit'] ) ? $v['num'] * $root_font_size_px : $v['num'];
			$points[] = [ 'w' => $w_px, 'v' => $v_px ];
		}
		return self::build_fluid_clamp( $points[0]['w'], $points[0]['v'], $points[1]['w'], $points[1]['v'], $output_unit, $root_font_size_px );
	}

	/**
	 * Detect whether a fluid-clamp request involves rem units, either in
	 * inputs (min/max/targets values) or in an explicit output_unit="rem".
	 * Any rem emission bakes a root-font-size assumption into the vw slope,
	 * so we require the caller to explicitly acknowledge that assumption
	 * via output_unit or root_font_size_px.
	 */
	private static function fluid_request_has_rem_involvement( $min, $max, $targets, $output_unit ) {
		if ( 'rem' === $output_unit ) {
			return true;
		}
		$candidates = [];
		if ( is_string( $min ) ) {
			$candidates[] = $min;
		}
		if ( is_string( $max ) ) {
			$candidates[] = $max;
		}
		if ( is_array( $targets ) ) {
			foreach ( $targets as $v ) {
				if ( is_string( $v ) ) {
					$candidates[] = $v;
				}
			}
		}
		foreach ( $candidates as $c ) {
			if ( false !== stripos( $c, 'rem' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Create a single variable in the Variable Manager.
	 * Type "colors" writes to et_divi.et_global_data.global_colors.
	 * Other types write to et_divi_global_variables.
	 *
	 * Fluid clamp() generation (type=numbers only):
	 * - `min` + `max` (px or rem strings) → clamp using default anchors 320px/1920px
	 * - `targets` object (px viewport keys, px or rem values) → clamp using the two anchors
	 * - `output_unit` ("rem" | "px") and `root_font_size_px` (number) are optional overrides.
	 *   Rem inputs or rem output require explicit opt-in via one of these params: rem emission
	 *   bakes a root-font-size assumption into the vw slope, so the caller must either accept
	 *   the 1rem=16px default (pass output_unit="rem") or declare the site's actual root
	 *   (pass root_font_size_px).
	 * - Mutually exclusive with `value`.
	 */
	public static function variable_create( $request ) {
		$type              = sanitize_text_field( $request->get_param( 'type' ) );
		$label             = sanitize_text_field( $request->get_param( 'label' ) );
		$value             = $request->get_param( 'value' );
		$min               = $request->get_param( 'min' );
		$max               = $request->get_param( 'max' );
		$targets           = $request->get_param( 'targets' );
		$output_unit       = $request->get_param( 'output_unit' );
		$root_font_size_px = $request->get_param( 'root_font_size_px' );

		$valid_types = [ 'colors', 'numbers', 'strings', 'images', 'links', 'fonts' ];
		if ( ! in_array( $type, $valid_types, true ) ) {
			return self::envelope_error(
				'invalid_input',
				'type must be one of: ' . implode( ', ', $valid_types ) . '.',
				null,
				400,
				[ 'field' => 'type', 'allowed' => $valid_types, 'received' => $type ]
			);
		}

		$has_shorthand = ( null !== $min || null !== $max );
		$has_targets   = ( null !== $targets && [] !== $targets );
		$has_fluid     = $has_shorthand || $has_targets;

		// output_unit — only meaningful alongside fluid params; validate enum.
		if ( null !== $output_unit ) {
			if ( ! $has_fluid ) {
				return self::envelope_error(
					'invalid_input',
					'output_unit is only meaningful alongside min/max/targets. Remove it or add fluid params.',
					null,
					400,
					[ 'rejected_field' => 'output_unit', 'missing' => [ 'min', 'max', 'targets' ] ]
				);
			}
			if ( 'rem' !== $output_unit && 'px' !== $output_unit ) {
				return self::envelope_error(
					'invalid_input',
					"output_unit must be 'rem' or 'px', got '$output_unit'.",
					null,
					400,
					[ 'field' => 'output_unit', 'allowed' => [ 'rem', 'px' ], 'received' => $output_unit ]
				);
			}
		}

		// root_font_size_px — caller-declared root font-size for correct
		// rem↔px conversion when the site deviates from the 16px default.
		if ( null !== $root_font_size_px ) {
			if ( ! $has_fluid ) {
				return self::envelope_error(
					'invalid_input',
					'root_font_size_px is only meaningful alongside min/max/targets. Remove it or add fluid params.',
					null,
					400,
					[ 'rejected_field' => 'root_font_size_px', 'missing' => [ 'min', 'max', 'targets' ] ]
				);
			}
			if ( ! is_numeric( $root_font_size_px ) || (float) $root_font_size_px <= 0 ) {
				return self::envelope_error(
					'invalid_input',
					"root_font_size_px must be a positive number (px), got '$root_font_size_px'.",
					null,
					400,
					[ 'field' => 'root_font_size_px', 'expected' => 'positive number (px)', 'received' => $root_font_size_px ]
				);
			}
			$root_font_size_px = (float) $root_font_size_px;
		}

		if ( $has_fluid ) {
			if ( 'numbers' !== $type ) {
				return self::envelope_error(
					'invalid_input',
					'Fluid clamp generation (min/max/targets) is only valid for type="numbers".',
					null,
					400,
					[ 'field' => 'type', 'allowed' => [ 'numbers' ], 'received' => $type, 'context' => 'fluid_only' ]
				);
			}
			if ( null !== $value && '' !== $value ) {
				return self::envelope_error(
					'invalid_input',
					'Cannot pass both value and min/max/targets — use one input mode.',
					null,
					400,
					[ 'conflict' => [ 'value', 'fluid_inputs' ] ]
				);
			}
			if ( $has_shorthand && $has_targets ) {
				return self::envelope_error(
					'invalid_input',
					'Cannot pass both min/max shorthand and targets — use one input mode.',
					null,
					400,
					[ 'conflict' => [ 'shorthand', 'targets' ] ]
				);
			}
			if ( $has_shorthand && ( null === $min || null === $max ) ) {
				$missing = [];
				if ( null === $min ) {
					$missing[] = 'min';
				}
				if ( null === $max ) {
					$missing[] = 'max';
				}
				return self::envelope_error(
					'invalid_input',
					'Shorthand requires both min and max.',
					null,
					400,
					[ 'missing' => $missing ]
				);
			}

			// Rem involvement — either rem appears in inputs, or output_unit="rem".
			// Either form bakes a root-font-size assumption into the vw slope, so
			// we require the caller to explicitly opt in via output_unit or
			// root_font_size_px. All-px requests bypass this gate (emission is
			// px-only, root-agnostic, no ceremony required).
			$rem_involved = self::fluid_request_has_rem_involvement( $min, $max, $targets, $output_unit );
			if ( $rem_involved && null === $output_unit && null === $root_font_size_px ) {
				return self::envelope_error(
					'invalid_input',
					"rem inputs or rem outputs require explicit opt-in — pass output_unit='rem' to accept the 1rem=16px default, or root_font_size_px:N to declare your site's actual root font-size (e.g. 10 for a 62.5% reset, 20 for a 20px root). rem emission bakes a root-font-size assumption into the vw slope; opt-in makes that assumption auditable.",
					"Pass output_unit='rem' to accept the 1rem=16px default, or root_font_size_px:N to declare your site's actual root font-size.",
					400,
					[ 'requires' => [ 'output_unit|root_font_size_px' ] ]
				);
			}

			// Resolve effective output_unit + root. Four cases:
			//   (1) all-px inputs, no override → output_unit='px', root=16 (unused)
			//   (2) explicit output_unit given → honor it (rem or px)
			//   (3) rem-involved inputs, only root_font_size_px given → implies rem output
			//   (4) all-px inputs but root_font_size_px given → implies rem output
			//       (caller declared a root purely to trigger rem emission; matches the
			//       tool schema's documented "root_font_size_px alone implies rem" contract)
			$effective_output_unit = $output_unit;
			if ( null === $effective_output_unit ) {
				$effective_output_unit = ( $rem_involved || null !== $root_font_size_px ) ? 'rem' : 'px';
			}
			$effective_root = ( null === $root_font_size_px ) ? 16.0 : $root_font_size_px;

			try {
				$value = $has_targets
					? self::generate_fluid_clamp_from_targets( $targets, $effective_output_unit, $effective_root )
					: self::generate_fluid_clamp_from_minmax( (string) $min, (string) $max, $effective_output_unit, $effective_root );
			} catch ( \DiviOps_Variable_Input_Exception $e ) {
				// Input-shape rejection raised from inside the helpers
				// (parse_size_with_unit / parse_fluid_viewport / 2-entry
				// targets check). Route to invalid_input with structured
				// error.data documenting the failed field.
				return self::envelope_error(
					'invalid_input',
					$e->getMessage(),
					null,
					400,
					[
						'field'    => $has_targets ? 'targets' : 'min/max',
						'expected' => $has_targets
							? 'object keyed by px viewport (e.g. {"320px":"20px","1920px":"60px"})'
							: 'px or rem string (e.g. "20px" or "1.25rem")',
						'received' => $has_targets ? $targets : [ 'min' => $min, 'max' => $max ],
					]
				);
			} catch ( \Exception $e ) {
				// Genuine algorithmic failure: build_fluid_clamp produced
				// no clamp on syntactically-valid input (degenerate
				// viewport range, NaN-producing math).
				return self::envelope_error(
					'variable.fluid_generation_failed',
					$e->getMessage(),
					'Check that min/max/targets produce non-degenerate viewport and value ranges.',
					400,
					[ 'min' => $min, 'max' => $max, 'targets' => $targets, 'reason' => $e->getMessage() ]
				);
			}
		}

		if ( ! is_scalar( $value ) ) {
			return self::envelope_error(
				'invalid_input',
				'value must be a scalar string (or supply min/max/targets for type=numbers).',
				null,
				400,
				[
					'field'    => 'value',
					'expected' => 'scalar string',
					'received' => null === $value ? 'null' : ( is_array( $value ) ? 'array' : gettype( $value ) ),
				]
			);
		}
		$value = (string) $value;

		$dry_run = (bool) $request->get_param( 'dry_run' );

		if ( 'colors' === $type ) {
			$raw_id = $request->get_param( 'id' );
			$id     = '' !== (string) $raw_id ? sanitize_text_field( $raw_id ) : ( $dry_run ? 'gcid-<auto>' : 'gcid-' . wp_generate_password( 8, false ) );
			if ( 0 !== strpos( $id, 'gcid-' ) ) {
				return self::envelope_error(
					'invalid_input',
					"Color variable ID must start with 'gcid-', got '$id'.",
					null,
					400,
					[ 'field' => 'id', 'expected' => "string starting with 'gcid-'", 'received' => $id ]
				);
			}

			$color = sanitize_hex_color( $value );
			if ( ! $color ) {
				return self::envelope_error(
					'invalid_input',
					"Invalid hex color value: '$value'.",
					null,
					400,
					[ 'field' => 'value', 'expected' => 'hex color (e.g. #3a7a6a)', 'received' => $value ]
				);
			}

			if ( $dry_run ) {
				return self::dry_run_response(
					"Would create color variable '{$label}' (id: {$id}, value: {$color}).",
					[ [
						'kind'   => 'variable.create',
						'target' => "variable/colors/{$id}",
						'after'  => [ 'id' => $id, 'label' => $label, 'value' => $color, 'type' => 'colors' ],
					] ]
				);
			}

			$raw         = et_get_option( 'et_global_data' );
			$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
			if ( ! is_array( $global_data ) ) {
				$global_data = [];
			}
			$colors = is_array( $global_data['global_colors'] ?? null ) ? $global_data['global_colors'] : [];

			$max_order = 0;
			if ( ! empty( $colors ) ) {
				$orders = array_column( $colors, 'order' );
				if ( ! empty( $orders ) ) {
					$max_order = max( array_map( 'intval', $orders ) );
				}
			}

			// Offset past Divi's customizer-bound colors — see
			// get_customizer_color_count() for rationale.
			$max_order = max( self::get_customizer_color_count(), $max_order );

			$colors[ $id ] = [
				'color'       => $color,
				'status'      => 'active',
				'label'       => $label,
				'order'       => (string) ( $max_order + 1 ),
				'lastUpdated' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			];

			$global_data['global_colors'] = $colors;
			et_update_option( 'et_global_data', $global_data );

			return self::envelope_success( [
				'success' => true,
				'id'      => $id,
				'type'    => 'colors',
				'label'   => $label,
				'value'   => $color,
			] );
		}

		// Non-color types.
		$raw_id = $request->get_param( 'id' );
		$id     = '' !== (string) $raw_id ? sanitize_text_field( $raw_id ) : ( $dry_run ? 'gvid-<auto>' : 'gvid-' . wp_generate_password( 8, false ) );
		if ( 0 !== strpos( $id, 'gvid-' ) ) {
			return self::envelope_error(
				'invalid_input',
				"Non-color variable ID must start with 'gvid-', got '$id'.",
				null,
				400,
				[ 'field' => 'id', 'expected' => "string starting with 'gvid-'", 'received' => $id ]
			);
		}

		$vars = get_option( 'et_divi_global_variables', [] );
		if ( ! is_array( $vars ) ) {
			$vars = [];
		}
		if ( ! is_array( $vars[ $type ] ?? null ) ) {
			$vars[ $type ] = [];
		}

		// Type-specific sanitization.
		$sanitized_value = $value;
		if ( in_array( $type, [ 'images', 'links' ], true ) ) {
			$sanitized_value = esc_url_raw( $value );
		} else {
			$sanitized_value = sanitize_text_field( $value );
		}

		// Use max existing order to avoid collisions after deletions.
		$max_order = 0;
		if ( ! empty( $vars[ $type ] ) ) {
			$orders = array_column( $vars[ $type ], 'order' );
			if ( ! empty( $orders ) ) {
				$max_order = max( array_map( 'intval', $orders ) );
			}
		}

		if ( $dry_run ) {
			return self::dry_run_response(
				"Would create {$type} variable '{$label}' (id: {$id}, value: {$sanitized_value}).",
				[ [
					'kind'   => 'variable.create',
					'target' => "variable/{$type}/{$id}",
					'after'  => [ 'id' => $id, 'type' => $type, 'label' => $label, 'value' => $sanitized_value ],
				] ]
			);
		}

		$vars[ $type ][ $id ] = [
			'id'          => $id,
			'label'       => $label,
			'value'       => $sanitized_value,
			'order'       => $max_order + 1,
			'status'      => 'active',
			'lastUpdated' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			'type'        => $type,
		];

		update_option( 'et_divi_global_variables', $vars );

		return self::envelope_success( [
			'success' => true,
			'id'      => $id,
			'type'    => $type,
			'label'   => $label,
			'value'   => $sanitized_value,
		] );
	}

	/**
	 * Validate a caller-supplied name_prefix against the gvid- ID charset.
	 *
	 * Generated IDs follow `gvid-{namespace}-{prefix}-{n}` or
	 * `gvid-{namespace}-size-{prefix}{n}`. Divi's ID resolution at
	 * `GlobalData.php:760` strips any chars outside [a-z0-9-_] silently —
	 * the variable is created in the registry but $variable() lookups fail
	 * to resolve at render time. Reject up front rather than letting the
	 * silent-render-failure ship.
	 *
	 * Charset is [a-z0-9_-] — Divi's $variable() resolver at GlobalData.php:760
	 * silently strips chars outside this set during ID extraction.
	 *
	 * @return string The validated, lowercased prefix, or $default if input is null/empty.
	 * @throws \InvalidArgumentException if the prefix contains disallowed chars.
	 */
	private static function validate_name_prefix( $input, $field_name, $default ) {
		if ( null === $input || '' === $input ) {
			return $default;
		}
		if ( ! is_string( $input ) ) {
			throw new \DiviOps_Variable_Input_Exception( "$field_name must be a string." );
		}
		$lower = strtolower( $input );
		if ( ! preg_match( '/^[a-z0-9_-]+$/', $lower ) ) {
			throw new \DiviOps_Variable_Input_Exception( sprintf(
				"%s '%s' contains characters outside [a-z0-9-_]. Divi's \$variable() resolver strips disallowed chars silently, so the generated IDs would be created in the registry but fail to resolve at render time. Use only [a-z0-9-_].",
				$field_name,
				$input
			) );
		}
		return $lower;
	}

	/**
	 * Named modular-scale ratios. Mirrors common typography scales so AI
	 * callers can pass a memorable name instead of looking up a magic number.
	 * Numeric ratios are accepted directly via the schema and bypass this map.
	 */
	private static function modular_scale_ratio( $name ) {
		$ratios = [
			'minor-second'     => 1.067,
			'major-second'     => 1.125,
			'minor-third'      => 1.2,
			'major-third'      => 1.25,
			'perfect-fourth'   => 1.333,
			'augmented-fourth' => 1.414,
			'perfect-fifth'    => 1.5,
			'golden'           => 1.618,
		];
		return $ratios[ $name ] ?? null;
	}

	/**
	 * Resolve the (min_viewport_px, max_viewport_px) pair for a profile.
	 * - "divi-default": 360/1350 — matches Divi 5.4.0's Variable Generator Modal defaults
	 *   (rowMaxWidthPx 1080 / rowWidthPercent 80% = 1350 outer viewport).
	 * - "wide": 320/1920 — diviops convention covering wider device span; matches the
	 *   default anchors used by `variable_create`'s shorthand form.
	 * - "custom": caller supplies anchors via the `custom_anchors` field.
	 */
	private static function resolve_fluid_anchors( $profile, $custom_anchors ) {
		switch ( $profile ) {
			case 'divi-default':
				return [ 360.0, 1350.0 ];
			case 'wide':
				return [ 320.0, 1920.0 ];
			case 'custom':
				if ( ! is_array( $custom_anchors ) ) {
					throw new \DiviOps_Variable_Input_Exception( 'profile="custom" requires custom_anchors: {min_viewport_px, max_viewport_px}.' );
				}
				$min_vp = isset( $custom_anchors['min_viewport_px'] ) ? (float) $custom_anchors['min_viewport_px'] : 0.0;
				$max_vp = isset( $custom_anchors['max_viewport_px'] ) ? (float) $custom_anchors['max_viewport_px'] : 0.0;
				if ( $min_vp <= 0 || $max_vp <= 0 || $max_vp <= $min_vp ) {
					throw new \DiviOps_Variable_Input_Exception( 'custom_anchors must provide positive min_viewport_px and max_viewport_px with max > min.' );
				}
				return [ $min_vp, $max_vp ];
			default:
				throw new \DiviOps_Variable_Input_Exception( "Unknown profile '$profile' — expected 'divi-default', 'wide', or 'custom'." );
		}
	}

	/**
	 * Compute the typography modular-scale chain.
	 *
	 * Step N's value = `base_px × ratio^(steps-N)`, so step 1 = LARGEST size
	 * and step `steps` = base body size. Mirrors HTML heading conventions
	 * (h1 = largest).
	 *
	 * Fluid behavior is opt-in via `fluid_growth`:
	 *   - fluid_growth = 1.0 (default) → each step is a fixed value (discrete token)
	 *   - fluid_growth > 1.0          → step N fluid-scales from
	 *                                    base_px × ratio^(steps-N)        at min_viewport
	 *                                 to base_px × ratio^(steps-N) × fluid_growth at max_viewport
	 *
	 * `max_ratio` is also accepted (advanced): if set, large-viewport value uses
	 * `base_px × max_ratio^(steps-N) × fluid_growth` so the scale chain itself
	 * widens at the large anchor (matches ET's per-breakpoint ratio pattern).
	 *
	 * @return array<int, array{id:string, value:string, label:string}>
	 */
	private static function compute_typography_scale( $cfg, $anchors, $output_unit, $root_font_size_px, $namespace ) {
		$base_px       = isset( $cfg['base_px'] ) ? (float) $cfg['base_px'] : 16.0;
		$steps         = isset( $cfg['steps'] ) ? (int) $cfg['steps'] : 6;
		$fluid_growth  = isset( $cfg['fluid_growth'] ) ? (float) $cfg['fluid_growth'] : 1.0;
		$name_prefix   = self::validate_name_prefix(
			$cfg['name_prefix'] ?? null,
			'typography.name_prefix',
			'h'
		);

		if ( $base_px <= 0 ) {
			throw new \DiviOps_Variable_Input_Exception( 'typography.base_px must be a positive number (px).' );
		}
		if ( $steps < 1 || $steps > 20 ) {
			throw new \DiviOps_Variable_Input_Exception( 'typography.steps must be between 1 and 20.' );
		}
		if ( $fluid_growth <= 0 ) {
			throw new \DiviOps_Variable_Input_Exception( 'typography.fluid_growth must be a positive number (e.g. 1.0 for non-fluid, 1.25 for moderate growth).' );
		}

		$ratio     = self::resolve_modular_ratio( $cfg['ratio'] ?? 1.25 );
		$max_ratio = isset( $cfg['max_ratio'] ) ? self::resolve_modular_ratio( $cfg['max_ratio'] ) : $ratio;

		[ $min_vp, $max_vp ] = $anchors;

		$out = [];
		for ( $n = 1; $n <= $steps; $n++ ) {
			// Reverse step indexing so h1 = largest, hN = base.
			$exponent = $steps - $n;
			$small_px = $base_px * pow( $ratio, $exponent );
			$large_px = $base_px * pow( $max_ratio, $exponent ) * $fluid_growth;

			$value = self::build_fluid_clamp( $min_vp, $small_px, $max_vp, $large_px, $output_unit, $root_font_size_px );
			$id    = sprintf( 'gvid-%s-size-%s%d', $namespace, $name_prefix, $n );
			$out[] = [
				'id'    => $id,
				'value' => $value,
				'label' => sprintf( 'Size %s%d', strtoupper( $name_prefix ), $n ),
			];
		}
		return $out;
	}

	/**
	 * Resolve a ratio input — accepts a positive number or a named scale.
	 */
	private static function resolve_modular_ratio( $ratio_input ) {
		if ( is_numeric( $ratio_input ) ) {
			$r = (float) $ratio_input;
			if ( $r <= 0 ) {
				throw new \DiviOps_Variable_Input_Exception( 'Modular ratio must be a positive number.' );
			}
			return $r;
		}
		if ( is_string( $ratio_input ) ) {
			$resolved = self::modular_scale_ratio( $ratio_input );
			if ( null !== $resolved ) {
				return $resolved;
			}
			throw new \DiviOps_Variable_Input_Exception( "Unknown modular ratio name '$ratio_input'. Pass a number or one of: minor-second, major-second, minor-third, major-third, perfect-fourth, augmented-fourth, perfect-fifth, golden." );
		}
		throw new \DiviOps_Variable_Input_Exception( 'Modular ratio must be a number or a named scale.' );
	}

	/**
	 * Compute a spacing/radius scale.
	 *
	 * Each step's "small" value lives on the chosen scale (linear or
	 * geometric) between min_px and max_px. Default behavior is discrete
	 * (each step emits a fixed value, not a clamp) — that matches how
	 * spacing/radius tokens are typically used in design systems.
	 *
	 * Fluid behavior is opt-in via `fluid_growth`:
	 *   - fluid_growth = 1.0 (default) → discrete: step N value is constant across viewports
	 *   - fluid_growth > 1.0          → step N value scales from `small` at min_viewport
	 *                                    to `small × fluid_growth` at max_viewport
	 *
	 * - `linear`: equal arithmetic spacing (min, ..., max) — best for spacing.
	 * - `geometric`: equal multiplicative spacing — best for radius/typography-like scales.
	 *
	 * @return array<int, array{id:string, value:string, label:string}>
	 */
	private static function compute_size_scale( $cfg, $bucket, $default_prefix, $anchors, $output_unit, $root_font_size_px, $namespace ) {
		$min_px       = isset( $cfg['min_px'] ) ? (float) $cfg['min_px'] : 0.0;
		$max_px       = isset( $cfg['max_px'] ) ? (float) $cfg['max_px'] : 0.0;
		$steps        = isset( $cfg['steps'] ) ? (int) $cfg['steps'] : 6;
		$scale        = isset( $cfg['scale'] ) ? (string) $cfg['scale'] : 'linear';
		$fluid_growth = isset( $cfg['fluid_growth'] ) ? (float) $cfg['fluid_growth'] : 1.0;
		$name_prefix  = self::validate_name_prefix(
			$cfg['name_prefix'] ?? null,
			"$bucket.name_prefix",
			$default_prefix
		);

		if ( $min_px < 0 || $max_px <= 0 ) {
			throw new \DiviOps_Variable_Input_Exception( "$bucket.min_px must be ≥ 0 and $bucket.max_px must be > 0." );
		}
		if ( $max_px < $min_px ) {
			throw new \DiviOps_Variable_Input_Exception( "$bucket.max_px must be ≥ $bucket.min_px." );
		}
		if ( $steps < 1 || $steps > 30 ) {
			throw new \DiviOps_Variable_Input_Exception( "$bucket.steps must be between 1 and 30." );
		}
		if ( ! in_array( $scale, [ 'linear', 'geometric' ], true ) ) {
			throw new \DiviOps_Variable_Input_Exception( "$bucket.scale must be 'linear' or 'geometric'." );
		}
		if ( 'geometric' === $scale && $min_px <= 0 ) {
			throw new \DiviOps_Variable_Input_Exception( "$bucket.scale='geometric' requires min_px > 0 (geometric step from 0 is undefined)." );
		}
		if ( $fluid_growth <= 0 ) {
			throw new \DiviOps_Variable_Input_Exception( "$bucket.fluid_growth must be a positive number (1.0 = discrete, > 1 = fluid)." );
		}

		[ $min_vp, $max_vp ] = $anchors;

		$out = [];
		for ( $n = 1; $n <= $steps; $n++ ) {
			if ( 1 === $steps ) {
				// Single step: the "scale" doesn't really apply — emit a clamp
				// that goes min→max across the viewport (most useful single-step
				// shape). fluid_growth is ignored in this case.
				$small_v = $min_px;
				$large_v = $max_px;
			} else {
				$t = ( $n - 1 ) / ( $steps - 1 );
				if ( 'linear' === $scale ) {
					$small_v = $min_px + $t * ( $max_px - $min_px );
				} else {
					$small_v = $min_px * pow( $max_px / $min_px, $t );
				}
				$large_v = $small_v * $fluid_growth;
			}

			$value = self::build_fluid_clamp( $min_vp, $small_v, $max_vp, $large_v, $output_unit, $root_font_size_px );
			$id    = sprintf( 'gvid-%s-%s-%d', $namespace, $name_prefix, $n );
			$out[] = [
				'id'    => $id,
				'value' => $value,
				'label' => sprintf( '%s %d', ucfirst( $name_prefix ), $n ),
			];
		}
		return $out;
	}

	/**
	 * Batch generator: emit a fluid typography + spacing + radius set in one call.
	 *
	 * Mirrors ET 5.4.0's Variable Generator Modal at the algorithm level
	 * (clamp() math is identical via build_fluid_clamp) but layers profile-
	 * selectable anchors over it: divi-default (360/1350) matches ET's defaults,
	 * wide (320/1920) matches the diviops convention, custom takes explicit
	 * anchors. Each category is independent and optional.
	 *
	 * Response shape (consistent across dry_run and persist modes):
	 *   - `created`: entries that would be (or were) written. With overwrite=false,
	 *     this contains only NEW entries; existing IDs land in `skipped` instead.
	 *     With overwrite=true, every plan entry lands here (`overwrote` flag
	 *     distinguishes update vs create).
	 *   - `skipped`: existing IDs not written this call (overwrite=false only).
	 *
	 * To audit the FULL computed plan (every entry regardless of existing IDs),
	 * call with overwrite=true + dry_run=true — that returns each generated
	 * entry under `created` with `overwrote: true|false` flagging which would
	 * be updates vs new writes. This is the recommended preflight pattern.
	 *
	 * Persistence is a single write to `et_divi_global_variables` so an invalid
	 * mid-batch step rolls back cleanly (no half-written registry).
	 */
	public static function variable_create_fluid_system( $request ) {
		$profile           = sanitize_text_field( $request->get_param( 'profile' ) ?: 'divi-default' );
		$custom_anchors    = $request->get_param( 'custom_anchors' );
		$typography        = $request->get_param( 'typography' );
		$spacing           = $request->get_param( 'spacing' );
		$radius            = $request->get_param( 'radius' );
		$namespace_raw     = $request->get_param( 'namespace' );
		$output_unit       = $request->get_param( 'output_unit' );
		$root_font_size_px = $request->get_param( 'root_font_size_px' );
		$dry_run           = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false );
		$overwrite         = rest_sanitize_boolean( $request->get_param( 'overwrite' ) ?? false );

		// Namespace validation mirrors validate_name_prefix(): explicit reject
		// instead of sanitize_key()'s silent strip. sanitize_key() rewriting
		// "oa!" or "o a" to "oa" would alias bogus input onto the default
		// namespace; with overwrite=true that means silently rewriting the
		// WRONG token set. The reject path keeps the failure loud.
		try {
			$namespace = self::validate_name_prefix(
				( null === $namespace_raw || '' === $namespace_raw ) ? 'oa' : $namespace_raw,
				'namespace',
				'oa'
			);
		} catch ( \Exception $e ) {
			return self::envelope_error(
				'invalid_input',
				$e->getMessage(),
				null,
				400,
				[ 'field' => 'namespace', 'expected' => '[a-z0-9_-]+', 'received' => $namespace_raw ]
			);
		}

		// At least one category must be present.
		if ( ! is_array( $typography ) && ! is_array( $spacing ) && ! is_array( $radius ) ) {
			return self::envelope_error(
				'invalid_input',
				'At least one of typography/spacing/radius must be provided.',
				null,
				400,
				[ 'requires' => [ 'typography|spacing|radius' ] ]
			);
		}

		// Validate output_unit + root_font_size_px (same opt-in rules as variable_create).
		if ( null !== $output_unit && 'rem' !== $output_unit && 'px' !== $output_unit ) {
			return self::envelope_error(
				'invalid_input',
				"output_unit must be 'rem' or 'px'.",
				null,
				400,
				[ 'field' => 'output_unit', 'allowed' => [ 'rem', 'px' ], 'received' => $output_unit ]
			);
		}
		if ( null !== $root_font_size_px ) {
			if ( ! is_numeric( $root_font_size_px ) || (float) $root_font_size_px <= 0 ) {
				return self::envelope_error(
					'invalid_input',
					'root_font_size_px must be a positive number (px).',
					null,
					400,
					[ 'field' => 'root_font_size_px', 'expected' => 'positive number (px)', 'received' => $root_font_size_px ]
				);
			}
			$root_font_size_px = (float) $root_font_size_px;
		}
		// rem emission requires explicit opt-in via output_unit='rem' or root_font_size_px.
		// Inputs to this batch tool are all numeric px (base_px / min_px / max_px) so the
		// rem-in-input gate doesn't apply here, but rem OUTPUT still bakes a root assumption.
		$effective_output_unit = $output_unit ?? ( null !== $root_font_size_px ? 'rem' : 'px' );
		$effective_root        = ( null === $root_font_size_px ) ? 16.0 : $root_font_size_px;

		// Resolve anchors.
		try {
			$anchors = self::resolve_fluid_anchors( $profile, $custom_anchors );
		} catch ( \Exception $e ) {
			return self::envelope_error(
				'invalid_input',
				$e->getMessage(),
				null,
				400,
				[ 'field' => 'profile', 'allowed' => [ 'divi-default', 'wide', 'custom' ], 'received' => $profile ]
			);
		}

		// Compute each requested category.
		$plan = [];
		try {
			if ( is_array( $typography ) ) {
				$plan = array_merge( $plan, self::compute_typography_scale( $typography, $anchors, $effective_output_unit, $effective_root, $namespace ) );
			}
			if ( is_array( $spacing ) ) {
				$plan = array_merge( $plan, self::compute_size_scale( $spacing, 'spacing', 'space', $anchors, $effective_output_unit, $effective_root, $namespace ) );
			}
			if ( is_array( $radius ) ) {
				$plan = array_merge( $plan, self::compute_size_scale( $radius, 'radius', 'rounded', $anchors, $effective_output_unit, $effective_root, $namespace ) );
			}
		} catch ( \DiviOps_Variable_Input_Exception $e ) {
			// Input-shape rejection from inside compute_typography_scale /
			// compute_size_scale / resolve_modular_ratio / validate_name_prefix
			// (out-of-bound base_px / steps / fluid_growth, unknown ratio name,
			// invalid scale, name_prefix charset). Route to invalid_input.
			return self::envelope_error(
				'invalid_input',
				$e->getMessage(),
				'Adjust the failing category (typography/spacing/radius) bounds — see error.data for context.',
				400,
				[ 'categories' => array_values( array_filter( [
					is_array( $typography ) ? 'typography' : null,
					is_array( $spacing )    ? 'spacing'    : null,
					is_array( $radius )     ? 'radius'     : null,
				] ) ) ]
			);
		} catch ( \Exception $e ) {
			// Genuine algorithmic failure: build_fluid_clamp produced
			// no clamp on validated config (e.g. computed values
			// converged to a degenerate slope).
			return self::envelope_error(
				'variable.fluid_system_generation_failed',
				$e->getMessage(),
				'Adjust the failing category (typography/spacing/radius) — see error.data for the offending profile + reason.',
				400,
				[ 'profile' => $profile, 'categories' => array_values( array_filter( [
					is_array( $typography ) ? 'typography' : null,
					is_array( $spacing )    ? 'spacing'    : null,
					is_array( $radius )     ? 'radius'     : null,
				] ) ), 'reason' => $e->getMessage() ]
			);
		}

		// Ensure the plan has no internal ID collisions (e.g. typography
		// name_prefix overlapping with spacing name_prefix at the same step).
		// This is a within-plan collision driven by the caller's overlapping
		// name_prefix config — pure input-validation, NOT a registry conflict
		// (existing-id collisions against et_divi_global_variables land in
		// the `skipped[]` array below, not here). Standard invalid_input
		// shape: { field, received, conflict, allowed }.
		$seen = [];
		foreach ( $plan as $entry ) {
			if ( isset( $seen[ $entry['id'] ] ) ) {
				return self::envelope_error(
					'invalid_input',
					sprintf( "Two generated entries share ID '%s'. Adjust name_prefix in one of typography/spacing/radius to disambiguate.", $entry['id'] ),
					null,
					400,
					[
						'field'    => 'name_prefix',
						'received' => [
							'typography' => $typography['name_prefix'] ?? null,
							'spacing'    => $spacing['name_prefix']    ?? null,
							'radius'     => $radius['name_prefix']     ?? null,
						],
						'conflict' => 'within_plan_id_collision',
						'colliding_id' => $entry['id'],
					]
				);
			}
			$seen[ $entry['id'] ] = true;
		}

		// Inspect existing registry to compute skipped vs to-create.
		$vars = get_option( 'et_divi_global_variables', [] );
		if ( ! is_array( $vars ) ) {
			$vars = [];
		}
		if ( ! is_array( $vars['numbers'] ?? null ) ) {
			$vars['numbers'] = [];
		}

		$created      = [];
		$skipped      = [];
		$max_order    = 0;
		if ( ! empty( $vars['numbers'] ) ) {
			$orders = array_column( $vars['numbers'], 'order' );
			if ( ! empty( $orders ) ) {
				$max_order = max( array_map( 'intval', $orders ) );
			}
		}

		foreach ( $plan as $entry ) {
			$id    = $entry['id'];
			$value = $entry['value'];
			$label = $entry['label'];

			$exists = isset( $vars['numbers'][ $id ] );
			if ( $exists && ! $overwrite ) {
				$skipped[] = [
					'id'     => $id,
					'reason' => 'exists',
					'value'  => $vars['numbers'][ $id ]['value'] ?? null,
				];
				continue;
			}

			// Preserve order on overwrite; assign a fresh order on create.
			$order = $exists
				? (int) ( $vars['numbers'][ $id ]['order'] ?? ++$max_order )
				: ++$max_order;

			$vars['numbers'][ $id ] = [
				'id'          => $id,
				'label'       => $label,
				'value'       => $value,
				'order'       => $order,
				'status'      => 'active',
				'lastUpdated' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
				'type'        => 'numbers',
			];
			$created[] = [
				'id'         => $id,
				'value'      => $value,
				'label'      => $label,
				'overwrote'  => $exists,
			];
		}

		if ( ! $dry_run && ! empty( $created ) ) {
			update_option( 'et_divi_global_variables', $vars );
		}

		return self::envelope_success( [
			'success'      => true,
			'profile'      => $profile,
			'anchors'      => [ 'min_viewport_px' => $anchors[0], 'max_viewport_px' => $anchors[1] ],
			'output_unit'  => $effective_output_unit,
			'dry_run'      => $dry_run,
			'created'      => $created,
			'skipped'      => $skipped,
			'created_count' => count( $created ),
			'skipped_count' => count( $skipped ),
		] );
	}

	/**
	 * Delete a variable by ID. Auto-detects storage location from ID prefix.
	 *
	 * Reference-safety: by default, refuses to delete when live references
	 * exist (returns HTTP 409 with the reference locations). Pass `force=true`
	 * to delete anyway — callers that do so are responsible for orphan cleanup
	 * (run `variable_scan_orphans` afterwards). This prevents the silent-orphan
	 * class of bug where a delete leaves dangling `$variable(...)$` tokens in
	 * page/preset content that render as invalid CSS on the frontend.
	 */
	public static function variable_delete( $request ) {
		$id    = sanitize_text_field( $request->get_param( 'id' ) );
		$force = rest_sanitize_boolean( $request->get_param( 'force' ) ?? false );

		// Customizer-bound defaults (gcid-primary-color / gcid-link-color / etc.)
		// resolve via GlobalData::$customizer_colors and are bound to theme
		// options — not deletable via this endpoint. Reject with an explicit
		// 403 rather than letting the downstream 404 misrepresent this as
		// "variable doesn't exist" (it does; it just isn't under this tool's
		// jurisdiction).
		if ( class_exists( '\ET\Builder\Packages\GlobalData\GlobalData' ) ) {
			$customizer = \ET\Builder\Packages\GlobalData\GlobalData::$customizer_colors ?? [];
			if ( isset( $customizer[ $id ] ) ) {
				return self::envelope_error(
					'variable.customizer_default_immutable',
					"Variable '$id' is a Divi customizer-bound default and cannot be deleted via this endpoint — it's managed through WP Customizer theme options.",
					'Edit the corresponding theme option via WP Customizer instead of attempting deletion.',
					403,
					[ 'id' => $id, 'managed_by' => 'wp_customizer' ]
				);
			}
		}

		// Resolve storage bucket via prefix. Both lookups are O(1) array reads
		// against already-in-memory options — cheap to do first so a typo'd
		// id returns 404 without paying for a full-site parse_blocks scan.
		$is_color = 0 === strpos( $id, 'gcid-' );

		if ( $is_color ) {
			$raw         = et_get_option( 'et_global_data' );
			$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
			$colors      = is_array( $global_data ) && is_array( $global_data['global_colors'] ?? null )
				? $global_data['global_colors']
				: [];
			if ( ! isset( $colors[ $id ] ) ) {
				return self::envelope_error(
					'not_found',
					"Variable '$id' not found.",
					'Use diviops_variable_list to enumerate existing IDs.',
					404
				);
			}
		} else {
			$vars = get_option( 'et_divi_global_variables', [] );
			if ( ! is_array( $vars ) ) {
				return self::envelope_error(
					'not_found',
					"Variable '$id' not found.",
					'Use diviops_variable_list to enumerate existing IDs.',
					404
				);
			}
			$found_type = null;
			foreach ( [ 'numbers', 'strings', 'images', 'links', 'fonts' ] as $type ) {
				if ( is_array( $vars[ $type ] ?? null ) && isset( $vars[ $type ][ $id ] ) ) {
					$found_type = $type;
					break;
				}
			}
			if ( null === $found_type ) {
				return self::envelope_error(
					'not_found',
					"Variable '$id' not found.",
					'Use diviops_variable_list to enumerate existing IDs.',
					404
				);
			}
		}

		// Two-tier ref check to keep the normal-case delete fast:
		//   1. Cheap SQL LIKE + preset-option substring scan — O(few ms)
		//      regardless of site size. Negative hit = definitely no refs,
		//      skip the full scan entirely (the common path for a caller
		//      who just ran variable_scan_orphans and is cleaning up).
		//   2. Positive hit falls through to collect_variable_refs() so the
		//      409 body carries accurate per-location records. The expensive
		//      scan only runs when we're genuinely blocking a live delete.
		if ( ! $force && self::variable_id_appears_anywhere( $id ) ) {
			$refs = self::collect_variable_refs();
			if ( isset( $refs['all_ids'][ $id ] ) ) {
				return self::envelope_error(
					'conflict',
					sprintf(
						"Variable '%s' has %d live reference(s). Pass force=true to delete anyway; orphans will remain — run diviops_variable_scan_orphans to audit them afterwards.",
						$id,
						$refs['all_ids'][ $id ]
					),
					'Pass force=true to override, or remove references first; run diviops_variable_scan_orphans afterwards if forced.',
					409,
					[
						'id'        => $id,
						'ref_count' => $refs['all_ids'][ $id ],
						'locations' => $refs['locations'][ $id ] ?? [],
					]
				);
			}
		}

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			$bucket = $is_color ? 'colors' : $found_type;
			$entry  = $is_color ? ( $colors[ $id ] ?? null ) : ( $vars[ $found_type ][ $id ] ?? null );
			return self::dry_run_response(
				"Would delete {$bucket} variable '{$id}' (label: '" . ( $entry['label'] ?? '' ) . "', value: '" . ( $entry['value'] ?? $entry['color'] ?? '' ) . "').",
				[ [
					'kind'   => 'variable.delete',
					'target' => "variable/{$bucket}/{$id}",
					'before' => $entry,
				] ]
			);
		}

		if ( $is_color ) {
			unset( $colors[ $id ] );
			$global_data['global_colors'] = $colors;
			et_update_option( 'et_global_data', $global_data );
		} else {
			unset( $vars[ $found_type ][ $id ] );
			update_option( 'et_divi_global_variables', $vars );
		}

		return self::envelope_success( [ 'success' => true, 'deleted' => $id, 'forced' => $force ] );
	}

	/**
	 * Scan content surfaces for stale variable references + unused definitions.
	 *
	 * Orphans = ids referenced in pages / TB layouts / preset registry with no
	 * matching entry in the Variable Manager. Render as invalid CSS on the
	 * frontend (the `$variable()$` resolver falls through with no fallback),
	 * often only noticed via visual breakage.
	 *
	 * Unused = ids defined in the Variable Manager but referenced nowhere —
	 * deletion candidates; returned alongside orphans so one audit pass
	 * surfaces both hygiene signals.
	 *
	 * Shape mirrors preset_scan_orphans for consistency (orphan_count /
	 * orphans / per-ref location records).
	 */
	public static function variable_scan_orphans( $request ) {
		unset( $request );
		$refs    = self::collect_variable_refs();
		$defined = self::get_defined_variable_ids();

		$orphans          = [];
		$unused_variables = [];

		foreach ( $refs['all_ids'] as $id => $count ) {
			if ( isset( $defined[ $id ] ) ) {
				continue;
			}
			$orphans[] = [
				'id'        => $id,
				'ref_count' => $count,
				'locations' => $refs['locations'][ $id ] ?? [],
			];
		}

		foreach ( $defined as $id => $info ) {
			if ( isset( $refs['all_ids'][ $id ] ) ) {
				continue;
			}
			// Customizer-bound colors are defined (they resolve via theme
			// options) but not deletable via variable_delete, so they aren't
			// "deletion candidates" — skip them out of unused_variables.
			if ( 'customizer' === ( $info['source'] ?? '' ) ) {
				continue;
			}
			unset( $info['source'] ); // internal tag, don't leak to the response
			$unused_variables[] = array_merge( [ 'id' => $id ], $info );
		}

		$response = [
			'orphan_count'            => count( $orphans ),
			'unused_count'            => count( $unused_variables ),
			'total_unique_referenced' => count( $refs['all_ids'] ),
			'total_reference_count'   => array_sum( $refs['all_ids'] ),
			'total_in_registry'       => count( $defined ),
			'scanned_posts'           => $refs['scanned_posts'],
			'orphans'                 => $orphans,
			'unused_variables'        => $unused_variables,
		];

		// Surface scan-truncation only when it happens — keeps the response
		// clean on the normal case, and loud when the cap actually bit.
		if ( $refs['scan_truncated'] ) {
			$response['scan_truncated']         = true;
			$response['scan_truncation_limit']  = self::VARIABLES_SCAN_MAX_POSTS;
			$response['warning']                = sprintf(
				'Scanned the first %d posts only — site has more Divi content than the safety cap. Orphan list may be incomplete.',
				self::VARIABLES_SCAN_MAX_POSTS
			);
		}

		return self::envelope_success( $response );
	}

	/**
	 * Detect numeric/font variable IDs (gvid-*) the page actually emits.
	 *
	 * Mirrors the same content-stack assembly Divi performs at frontend render
	 * (FrontEnd.php:628-675) so the result matches the variable IDs Divi 5.4.0+
	 * uses to scope selective `:root{--gvid-*}` emission via
	 * `Style::get_global_numeric_and_fonts_vars_style($ids)`:
	 *
	 *   1. The post's own `post_content`
	 *   2. Theme Builder header/body/footer template content active for that post
	 *   3. Appended (above/below) canvas content for the post and each TB template
	 *   4. Interaction-target canvas content discovered in the assembled stack
	 *   5. Canvas-portal-referenced canvases (recursive, with the same
	 *      10-iteration safety cap Divi's frontend uses)
	 *   6. Presets referenced by the above (resolved via Divi's preset chain)
	 *
	 * The TB-template resolution uses `ET_Theme_Builder_Request::from_post( $post_id )`
	 * rather than the global-query-bound `from_current()`, so the answer is
	 * accurate from a REST request without simulating the singular query state.
	 *
	 * Canvas content uses the public OffCanvasHooks per-owner helpers
	 * (`get_canvas_content_for_appended`, `extract_interaction_target_ids_from_content`,
	 * `get_canvas_content_for_targets`, `get_canvas_content_for_canvas_portals`)
	 * instead of the convenience wrapper
	 * `get_all_appended_canvas_content_for_post_and_templates()`. The wrapper's
	 * inner helper bails on REST requests via `DynamicAssetsUtils::is_dynamic_front_end_request()`
	 * (REST_REQUEST + wp_is_json_request are explicit gates), which would
	 * silently drop canvas content from the scan and miss any gvid- IDs only
	 * referenced from canvas modules. The per-owner helpers have no REST gate.
	 *
	 * Canvas-portal IDs are extracted directly from the assembled stack with
	 * `DynamicAssetsUtils::extract_canvas_portal_canvas_ids_from_content()`
	 * because the same util's cached `canvas_portal_ids` field is also gated
	 * on `is_cacheable_request` (DynamicAssetsUtils.php:2736-2772) and would
	 * be empty in REST.
	 *
	 * NOTE: gvid-* only. Color variables (gcid-*) are emitted via a separate
	 * `GlobalData` color-block path that is NOT scoped per-page in 5.4.0, so
	 * `DetectFeature::get_page_global_variable_ids()` does not surface them and
	 * neither does this endpoint. Use `diviops_variable_scan_orphans` for
	 * site-wide gcid- coverage.
	 */
	public static function variable_used_on_page( $request ) {
		$post_id = (int) $request['id'];
		if ( $post_id <= 0 ) {
			return self::envelope_error(
				'invalid_input',
				'post_id must be a positive integer.',
				null,
				400,
				[ 'field' => 'post_id', 'expected' => 'positive integer', 'received' => $request['id'] ?? null ]
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return self::envelope_error(
				'not_found',
				sprintf( 'Post %d not found.', $post_id ),
				'Use diviops_page_list to find a valid page ID.',
				404
			);
		}

		if ( ! class_exists( '\\ET\\Builder\\FrontEnd\\Assets\\DetectFeature' ) ) {
			return self::envelope_error(
				'wp_error',
				'Divi 5 (with DetectFeature class) is required for this endpoint.',
				'Activate the Divi 5 theme.',
				500
			);
		}

		// Build the combined main content: post_content + each TB template's
		// post_content, space-joined. This matches `FrontEnd.php:640-653`
		// exactly and is the same string Divi passes as `$main_content` to
		// `get_all_appended_canvas_content_for_post_and_templates()` at line 658.
		// Critically, this combined string — not a per-owner one — is what
		// every owner needs so interaction-target discovery is identical to
		// the frontend, and so the canvas-data static cache gets seeded
		// correctly (see the cache-seed call below).
		$post_main_content = is_string( $post->post_content ) ? $post->post_content : '';
		$content_stack     = $post_main_content;
		$combined_main     = $post_main_content;

		// Active TB header/body/footer templates for this specific post.
		$tb_template_ids = [];
		if ( class_exists( 'ET_Theme_Builder_Request' ) && function_exists( 'et_theme_builder_get_template_layouts' ) ) {
			$tb_request = \ET_Theme_Builder_Request::from_post( $post_id );
			$tb_layouts = et_theme_builder_get_template_layouts( $tb_request );

			$layout_post_types = [
				defined( 'ET_THEME_BUILDER_HEADER_LAYOUT_POST_TYPE' ) ? ET_THEME_BUILDER_HEADER_LAYOUT_POST_TYPE : null,
				defined( 'ET_THEME_BUILDER_BODY_LAYOUT_POST_TYPE' )   ? ET_THEME_BUILDER_BODY_LAYOUT_POST_TYPE   : null,
				defined( 'ET_THEME_BUILDER_FOOTER_LAYOUT_POST_TYPE' ) ? ET_THEME_BUILDER_FOOTER_LAYOUT_POST_TYPE : null,
			];

			foreach ( $layout_post_types as $layout_post_type ) {
				if ( null === $layout_post_type || empty( $tb_layouts[ $layout_post_type ] ) ) {
					continue;
				}
				$layout = $tb_layouts[ $layout_post_type ];
				if ( empty( $layout['override'] ) || empty( $layout['enabled'] ) ) {
					continue;
				}
				$layout_id = (int) ( $layout['id'] ?? 0 );
				if ( $layout_id <= 0 ) {
					continue;
				}
				$tb_template_ids[] = $layout_id;
				$tb_post           = get_post( $layout_id );
				if ( $tb_post instanceof WP_Post && ! empty( $tb_post->post_content ) ) {
					$content_stack .= ' ' . $tb_post->post_content;
					$combined_main .= ' ' . $tb_post->post_content;
				}
			}
		}

		// Appended + interaction-target + canvas-portal content for the post
		// and its TB templates. We can't use the convenience wrapper
		// `get_all_appended_canvas_content_for_post_and_templates()` here
		// because its inner helper (`get_all_appended_canvas_content`) bails
		// out on REST requests via `DynamicAssetsUtils::is_dynamic_front_end_request()`
		// (REST_REQUEST + wp_is_json_request are explicit gates), so canvas
		// content would silently never appear in the scan.
		//
		// Assemble it directly from the public OffCanvasHooks helpers, which
		// have no REST gating.
		//
		// CRITICAL: seed `DynamicAssetsUtils::get_all_canvas_data_for_post($owner_id, $combined_main)`
		// per owner BEFORE any other canvas helper runs for that owner.
		// `get_canvas_content_for_appended()` internally calls
		// `get_all_canvas_data_for_post($owner_id)` with an empty main_content
		// (OffCanvasHooks.php:2892), which would write the static cache
		// (keyed both by content-hash and by base "post_id_md5('')") with
		// `interaction_targets => []` — empty seed, no targets discoverable.
		// `get_canvas_content_for_targets()` later reads the same base key
		// (it also passes empty main_content) and would find no targets.
		// Seeding first with the same combined main Divi uses populates the
		// cache with `interaction_targets` so the later targets call works.
		// See DynamicAssetsUtils.php:2937-2965 (interaction_targets build) and
		// :2990-2995 (dual-key cache write).
		//
		// Canvas portal IDs need to be extracted ourselves: that same util's
		// `canvas_portal_ids` field is also gated behind `is_cacheable_request`
		// (DynamicAssetsUtils.php:2736-2772), so the cached `canvas_data`
		// returns an empty array for that field in REST. We walk the combined
		// main content with `extract_canvas_portal_canvas_ids_from_content()`
		// + recursive expansion via `get_canvas_content_for_canvas_portals()`,
		// matching the full pipeline at OffCanvasHooks.php:3004-3047 (incl.
		// the 10-iteration safety cap for nested portals).
		//
		// Interaction targets are extracted from `$combined_main` (matching
		// what Divi passes at OffCanvasHooks.php:3070/3087 — same combined
		// string for every owner) and filtered through
		// `canvas_block_content_contains_target` to drop targets already
		// satisfied on the main canvas (matches OffCanvasHooks.php:2980-3002).
		if ( class_exists( '\\ET\\Builder\\VisualBuilder\\OffCanvas\\OffCanvasHooks' ) ) {
			$canvas_owner_ids = array_values( array_unique( array_merge( [ $post_id ], $tb_template_ids ) ) );

			// Pre-extract + filter target IDs once — Divi passes the same
			// combined main to every owner, so the filtered set is identical
			// across the loop. Cheap to compute: extract is short-circuited
			// by `DetectFeature::has_interactions_enabled` and the filter is
			// two str_contains + a regex per target.
			$shared_filtered_target_ids = [];
			$shared_target_ids          = \ET\Builder\VisualBuilder\OffCanvas\OffCanvasHooks::extract_interaction_target_ids_from_content( $combined_main );
			if ( ! empty( $shared_target_ids ) ) {
				foreach ( $shared_target_ids as $target_id ) {
					if ( ! \ET\Builder\VisualBuilder\OffCanvas\OffCanvasHooks::canvas_block_content_contains_target( $combined_main, $target_id ) ) {
						$shared_filtered_target_ids[] = $target_id;
					}
				}
			}

			// Pre-seed the portal IDs that come from the combined main content
			// (matches Divi's `canvas_data['canvas_portal_ids']` which is built
			// from main + TB content at DynamicAssetsUtils.php:2749-2771). Same
			// for every owner since the main is the same.
			$shared_portal_ids_from_main = [];
			if ( str_contains( $combined_main, 'canvas-portal' ) ) {
				$shared_portal_ids_from_main = \ET\Builder\FrontEnd\Assets\DynamicAssetsUtils::extract_canvas_portal_canvas_ids_from_content( $combined_main );
			}

			foreach ( $canvas_owner_ids as $owner_id ) {
				// Seed the static canvas-data cache for this owner with the
				// combined main content so interaction_targets is populated
				// before any helper call below reads the base cache key.
				\ET\Builder\FrontEnd\Assets\DynamicAssetsUtils::get_all_canvas_data_for_post( $owner_id, $combined_main );

				// Per-owner local buffer — Divi's `get_all_appended_canvas_content`
				// uses a fresh `$all_canvas_content` per owner (line 2969) and
				// expands portals only from THAT buffer + the canvas_data's main-
				// derived portal IDs, calling `get_canvas_content_for_canvas_portals(
				// $ids, $owner_id)` against THIS owner's $post_id (line 3033).
				// Sharing portal-ID extraction across owners via the global
				// $content_stack would resolve a same-named portal ID against the
				// wrong owner and over-include canvases the frontend would not
				// fetch. Keep portal expansion strictly per-owner here.
				$owner_canvas_content = '';

				// Explicitly appended canvases (above/below).
				$appended = \ET\Builder\VisualBuilder\OffCanvas\OffCanvasHooks::get_canvas_content_for_appended( $owner_id );
				if ( ! empty( $appended ) ) {
					$owner_canvas_content .= $appended;
				}

				// Interaction-targeted canvases — same filtered set per owner.
				if ( ! empty( $shared_filtered_target_ids ) ) {
					$interaction = \ET\Builder\VisualBuilder\OffCanvas\OffCanvasHooks::get_canvas_content_for_targets( $shared_filtered_target_ids, $owner_id );
					if ( ! empty( $interaction ) ) {
						$owner_canvas_content .= $interaction;
					}
				}

				// Canvas-portal expansion (recursive, capped). Seed from the
				// shared main-derived IDs + portals discovered inside this
				// OWNER's local appended/interaction buffer — matches the
				// merge at OffCanvasHooks.php:3009-3017.
				$portal_ids_from_owner_buffer = [];
				if ( '' !== $owner_canvas_content && str_contains( $owner_canvas_content, 'canvas-portal' ) ) {
					$portal_ids_from_owner_buffer = \ET\Builder\FrontEnd\Assets\DynamicAssetsUtils::extract_canvas_portal_canvas_ids_from_content( $owner_canvas_content );
				}

				$portal_ids_to_expand = array_values( array_unique( array_merge( $shared_portal_ids_from_main, $portal_ids_from_owner_buffer ) ) );

				if ( ! empty( $portal_ids_to_expand ) ) {
					$expanded_portal_ids = [];
					$iteration_limit     = 10;
					$iteration           = 0;

					while ( $iteration < $iteration_limit ) {
						$portal_ids_to_process = array_values( array_diff( $portal_ids_to_expand, $expanded_portal_ids ) );
						if ( empty( $portal_ids_to_process ) ) {
							break;
						}

						++$iteration;
						$expanded_portal_ids = array_merge( $expanded_portal_ids, $portal_ids_to_process );

						$portal_content = \ET\Builder\VisualBuilder\OffCanvas\OffCanvasHooks::get_canvas_content_for_canvas_portals( $portal_ids_to_process, $owner_id );
						if ( empty( $portal_content ) ) {
							continue;
						}

						$owner_canvas_content .= $portal_content;

						if ( str_contains( $portal_content, 'canvas-portal' ) ) {
							$nested_portal_ids = \ET\Builder\FrontEnd\Assets\DynamicAssetsUtils::extract_canvas_portal_canvas_ids_from_content( $portal_content );
							if ( ! empty( $nested_portal_ids ) ) {
								$portal_ids_to_expand = array_unique( array_merge( $portal_ids_to_expand, $nested_portal_ids ) );
							}
						}
					}
				}

				// Merge this owner's collected canvas content into the global
				// stack for variable detection. The per-owner isolation only
				// applies to canvas content discovery (where $owner_id matters);
				// gvid- token detection runs against the union.
				if ( '' !== $owner_canvas_content ) {
					$content_stack .= ' ' . $owner_canvas_content;
				}
			}
		}

		$variable_ids = '' !== $content_stack
			? \ET\Builder\FrontEnd\Assets\DetectFeature::get_page_global_variable_ids( $content_stack )
			: [];

		// Sort so callers get a stable order (frontend cares about uniqueness, not order).
		sort( $variable_ids );

		return self::envelope_success( [
			'post_id'         => $post_id,
			'variable_ids'    => $variable_ids,
			'count'           => count( $variable_ids ),
			'tb_template_ids' => $tb_template_ids,
		] );
	}
}
