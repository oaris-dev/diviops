<?php
/**
 * Trait DiviOps_Agent_GlobalFont
 *
 * Global font registry CRUD + ID validation.
 *
 * Mixed into DiviOps_Agent via `use` in diviops-agent.php — `self::` calls and
 * class constants resolve as if these methods lived directly on the class.
 *
 * Storage: `et_global_data['global_fonts']` — parallel to `global_colors`.
 * IDs use the `gfid-` prefix (mirrors the `gcid-` / `gvid-` pattern). Divi
 * itself does not consume this option key today (the native Divi 5 surfaces
 * for typography variables are `et_global_data['global_variables']['fonts']`
 * via `gvid-*` IDs through the variable manager, plus the legacy
 * `heading_font` / `body_font` customizer options); this registry is the
 * DiviOps-controlled font catalog that presets reference via canonical
 * slugs (e.g. `gfid-oa-sora`). Architecturally clean parallel to
 * `global_color_*` — leaves `gvid-*` fonts to `diviops_variable_*`.
 *
 * All four handlers route through envelope_success / envelope_error.
 * Mapping:
 *   - input-shape rejections (malformed gfid charset/length, invalid source
 *     enum, non-array weights, missing required `family` on a new entry,
 *     malformed gfid on delete) collapse onto `invalid_input` with
 *     structured `error.data` documenting the failed field.
 *   - global_font_delete on a font with live `gfid-*` references in
 *     page content / TB layouts / library / canvas / preset registry
 *     returns `conflict` (HTTP 409) with `error.data = { id, ref_count,
 *     locations[], scan_truncated, scanned_posts }` — mirrors the
 *     variable_delete locations[] shape (we build it via parse_blocks
 *     rather than relying on a Divi-maintained index, since `gfid-*` is
 *     not a native Divi namespace).
 *   - capability check (`current_user_can('manage_options')`) keeps a
 *     `WP_Error` return — REST framework promotes via parseThrownError.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_GlobalFont {

	/**
	 * List global fonts.
	 *
	 * Response shape (always-normalized):
	 *   { ok: true, data: { count: <int>, fonts: { <gfid>: <record>, ... } } }
	 *
	 * Always emits `{count, fonts}` — never the bare option value. Empty
	 * substrate (option missing or set to `false`/non-array) returns
	 * `{count: 0, fonts: {}}` so callers can distinguish empty-state
	 * (`count === 0`) from unimplemented-state (whole tool 404s) without
	 * inspecting transport details. Earlier list-only versions returned the raw
	 * `et_get_option('et_global_fonts', [])` payload which fell through to
	 * `false` on some substrates (option-default mishandling deep in
	 * `et_get_option`'s arg overload). Defensive normalization here is the
	 * canonical fix.
	 */
	public static function global_font_list( $request ) {
		// Google fonts catalog (gfid-* records) — primary surface.
		$gfid_path = 'et_divi.et_global_data.global_fonts';
		$raw         = et_get_option( 'et_global_data' );
		$global_data = self::normalize_storage_array( ! empty( $raw ) ? maybe_unserialize( $raw ) : [] ) ?? [];
		$fonts       = self::normalize_storage_array( $global_data['global_fonts'] ?? null ) ?? [];

		// Locally-hosted fonts (Pattern B / EU-GDPR) — parallel surface at
		// the top-level `et_uploaded_fonts` option. Per `reference_local_hosted_fonts_eu_pattern`
		// these are populated by the divi-oafontz MIME expander + WP file-upload
		// flow; they are distinct from gfid-* (Google CDN catalog) and from
		// gvid-* (variable manager). Surface them under their own `uploaded_fonts`
		// key with explicit `_meta` discrimination per #719 AC #9.
		$uploaded_path = 'et_uploaded_fonts';
		$uploaded_raw  = get_option( 'et_uploaded_fonts', '' );
		$uploaded      = [];
		if ( ! empty( $uploaded_raw ) ) {
			$decoded = maybe_unserialize( $uploaded_raw );
			$uploaded = self::normalize_storage_array( $decoded ) ?? [];
		}

		// Cast to object so the JSON shape stays consistent across states.
		// PHP serializes empty arrays as `[]` and associative arrays as `{}`,
		// which would silently change the field's type between empty and
		// populated substrates. The `fonts` field is a keyed map (gfid → record);
		// emit it as an object always so callers can deserialize against a
		// fixed schema.
		$response = self::envelope_success( [
			'count'          => count( $fonts ),
			'fonts'          => (object) $fonts,
			'uploaded_count' => count( $uploaded ),
			'uploaded_fonts' => (object) $uploaded,
		] );
		$body          = $response->get_data();
		$body['_meta'] = [
			'source_path'  => empty( $fonts ) ? null : $gfid_path,
			'probed_paths' => [ $gfid_path, $uploaded_path ],
			'sources'      => [
				'fonts'          => [ 'path' => $gfid_path,     'provenance' => 'gfid_catalog' ],
				'uploaded_fonts' => [ 'path' => $uploaded_path, 'provenance' => 'uploaded_local' ],
			],
		];
		$response->set_data( $body );
		return $response;
	}

	/**
	 * Audit the global_fonts storage landscape — aggregate across the gfid-*
	 * Google catalog AND the `et_uploaded_fonts` local-hosted surface.
	 *
	 * Storage-path contract (#719): the two surfaces are parallel (not
	 * priority-ordered) and disjoint by key namespace — gfid-* vs upload-id
	 * keys — so entries are aggregated with explicit provenance and a
	 * collision warning is logged only if a literal id appears in both
	 * (which would be a contract violation upstream).
	 */
	public static function global_font_audit_storage( $request ) {
		$aggregated    = [];
		$entry_sources = [];
		$warnings      = [];
		$probed_paths  = [];

		// gfid-* catalog.
		$gfid_path      = 'et_divi.et_global_data.global_fonts';
		$probed_paths[] = $gfid_path;
		$raw            = et_get_option( 'et_global_data' );
		$global_data    = self::normalize_storage_array( ! empty( $raw ) ? maybe_unserialize( $raw ) : [] ) ?? [];
		$gfid_fonts     = self::normalize_storage_array( $global_data['global_fonts'] ?? null ) ?? [];
		foreach ( $gfid_fonts as $id => $entry ) {
			if ( ! is_string( $id ) ) {
				continue;
			}
			$aggregated[ $id ]    = $entry;
			$entry_sources[ $id ] = [
				'path'       => $gfid_path,
				'provenance' => 'gfid_catalog',
			];
		}

		// et_uploaded_fonts surface.
		$uploaded_path  = 'et_uploaded_fonts';
		$probed_paths[] = $uploaded_path;
		$uploaded_raw   = get_option( 'et_uploaded_fonts', '' );
		if ( ! empty( $uploaded_raw ) ) {
			$uploaded = self::normalize_storage_array( maybe_unserialize( $uploaded_raw ) );
			if ( null !== $uploaded ) {
				foreach ( $uploaded as $id => $entry ) {
					if ( ! is_string( $id ) ) {
						continue;
					}
					if ( isset( $aggregated[ $id ] ) ) {
						$warnings[] = [
							'type'        => 'id_collision',
							'id'          => $id,
							'first_path'  => $entry_sources[ $id ]['path'] ?? null,
							'second_path' => $uploaded_path,
							'note'        => 'Same id appears in both gfid-* catalog and et_uploaded_fonts — upstream contract violation; verify substrate.',
						];
						continue;
					}
					$aggregated[ $id ]    = $entry;
					$entry_sources[ $id ] = [
						'path'       => $uploaded_path,
						'provenance' => 'uploaded_local',
					];
				}
			}
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
	 * Validate a global-font ID and return the canonical form, or a
	 * WP_Error explaining why it was rejected.
	 *
	 * Auto-prefixes `gfid-` if missing (caller convenience). Then enforces
	 * the same charset/length constraint as `gcid-` / `gvid-` since the
	 * `gfid-` namespace is parallel:
	 *   - suffix matches `[0-9a-z-]{1,80}` (mirrors the variable extraction
	 *     regex semantics so future $variable()$ token usage of `gfid-`
	 *     stays consistent with how Divi parses `g[vc]id-` today).
	 *
	 * Empty input returns the canonical generated form `gfid-<uuid4>`.
	 */
	private static function validate_global_font_id( $raw ) {
		$raw = sanitize_text_field( (string) $raw );
		if ( '' === $raw ) {
			return 'gfid-' . wp_generate_uuid4();
		}
		$id = ( 0 === strpos( $raw, 'gfid-' ) ) ? $raw : 'gfid-' . $raw;
		$suffix = substr( $id, 5 );
		if ( ! preg_match( '/^[0-9a-z-]{1,80}$/', $suffix ) ) {
			return new WP_Error(
				'invalid_id',
				sprintf( "Font ID '%s' contains characters outside the allowed [0-9a-z-] set, or exceeds 80 chars after the 'gfid-' prefix.", $id ),
				[ 'status' => 400 ]
			);
		}
		return $id;
	}

	/**
	 * Sanitize a font family name. Trims whitespace, strips control chars
	 * via sanitize_text_field. CSS-quotable names ('Sora', "Inter", etc.)
	 * are accepted verbatim — Divi consumers wrap in single quotes when
	 * emitting CSS so the stored form is the bare family name.
	 */
	private static function sanitize_font_family( $raw ): ?string {
		$family = trim( (string) $raw );
		if ( '' === $family ) {
			return null;
		}
		$family = sanitize_text_field( $family );
		return '' === $family ? null : $family;
	}

	/**
	 * Sanitize an array of font weights. Accepts integers 100..900 (in
	 * 100-step increments — standard CSS font-weight numeric values) and
	 * the string keywords `normal` / `bold` / `lighter` / `bolder`.
	 * Returns the sanitized list (unique, in input order) or `null` if
	 * any entry is invalid.
	 */
	private static function sanitize_weights( $raw ): ?array {
		if ( null === $raw ) {
			return [];
		}
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$valid_numeric = [ 100, 200, 300, 400, 500, 600, 700, 800, 900 ];
		$valid_strings = [ 'normal', 'bold', 'lighter', 'bolder' ];
		$out           = [];
		foreach ( $raw as $w ) {
			if ( is_int( $w ) || ( is_string( $w ) && ctype_digit( $w ) ) ) {
				$n = (int) $w;
				if ( ! in_array( $n, $valid_numeric, true ) ) {
					return null;
				}
				if ( ! in_array( $n, $out, true ) ) {
					$out[] = $n;
				}
			} elseif ( is_string( $w ) ) {
				$s = strtolower( trim( $w ) );
				if ( ! in_array( $s, $valid_strings, true ) ) {
					return null;
				}
				if ( ! in_array( $s, $out, true ) ) {
					$out[] = $s;
				}
			} else {
				return null;
			}
		}
		return $out;
	}

	/**
	 * Allowed `source` enum for global_font_create. Matches the ratified
	 * the ratified contract; expand later as needs surface.
	 */
	private static function valid_font_sources(): array {
		return [ 'google', 'system', 'custom' ];
	}

	/**
	 * Allowed `subset` values. Permissive list covering the common Google
	 * Fonts subsets; sanitization just guards against control chars +
	 * length cap, no strict allowlist (Google adds new subsets regularly
	 * and a stale allowlist would reject valid input).
	 */
	private static function sanitize_subsets( $raw ): ?array {
		if ( null === $raw ) {
			return [];
		}
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$out = [];
		foreach ( $raw as $s ) {
			if ( ! is_string( $s ) ) {
				return null;
			}
			$s = sanitize_key( trim( $s ) );
			if ( '' === $s || strlen( $s ) > 64 ) {
				return null;
			}
			if ( ! in_array( $s, $out, true ) ) {
				$out[] = $s;
			}
		}
		return $out;
	}

	/**
	 * Build the canonical record shape for a global font.
	 *
	 * Shape:
	 *   {
	 *     family:      string,                          // CSS family name (e.g. "Sora")
	 *     source:      'google'|'system'|'custom',
	 *     weights:     int[]|string[],                   // [400, 700] or ['normal','bold']
	 *     subsets:     string[],                         // ['latin', 'latin-ext']
	 *     label:       string,                           // human-readable display label
	 *     fallback:    string,                           // CSS fallback chain (e.g. "sans-serif")
	 *     status:      'active'|'archived',
	 *     lastUpdated: ISO-8601-UTC-with-ms,
	 *   }
	 *
	 * Merge semantics: when updating an existing record, fields not
	 * supplied in `$input` fall back to `$existing` (preserved); only
	 * `lastUpdated` is always bumped.
	 *
	 * Returns a `WP_Error` on validation failure (carries `code` +
	 * `message` + `data.field` so the caller can convert to an
	 * `invalid_input` envelope).
	 */
	private static function build_font_record( array $input, array $existing ) {
		// Family: required for new entries, preserved on update if omitted.
		if ( array_key_exists( 'family', $input ) ) {
			$family = self::sanitize_font_family( $input['family'] );
			if ( null === $family ) {
				return new WP_Error( 'invalid_family', 'family must be a non-empty string.', [ 'field' => 'family' ] );
			}
		} elseif ( ! empty( $existing ) && isset( $existing['family'] ) ) {
			$family = $existing['family'];
		} else {
			return new WP_Error( 'missing_family', 'family is required for new font entries.', [ 'field' => 'family', 'missing' => 'family' ] );
		}

		// Source: required for new entries, preserved on update if omitted.
		if ( array_key_exists( 'source', $input ) ) {
			$source = is_string( $input['source'] ) ? strtolower( trim( $input['source'] ) ) : '';
			if ( ! in_array( $source, self::valid_font_sources(), true ) ) {
				return new WP_Error(
					'invalid_source',
					sprintf( "source must be one of: %s.", implode( ', ', self::valid_font_sources() ) ),
					[ 'field' => 'source', 'allowed' => self::valid_font_sources(), 'received' => is_scalar( $input['source'] ) ? (string) $input['source'] : null ]
				);
			}
		} elseif ( ! empty( $existing ) && isset( $existing['source'] ) ) {
			$source = $existing['source'];
		} else {
			return new WP_Error( 'missing_source', 'source is required for new font entries.', [ 'field' => 'source', 'missing' => 'source' ] );
		}

		// Weights — optional; default to existing or [].
		if ( array_key_exists( 'weights', $input ) ) {
			$weights = self::sanitize_weights( $input['weights'] );
			if ( null === $weights ) {
				return new WP_Error(
					'invalid_weights',
					'weights must be an array of integers 100..900 (in 100-step increments) or keyword strings normal/bold/lighter/bolder.',
					[ 'field' => 'weights' ]
				);
			}
		} else {
			$weights = $existing['weights'] ?? [];
		}

		// Subsets — optional; default to existing or [].
		if ( array_key_exists( 'subsets', $input ) ) {
			$subsets = self::sanitize_subsets( $input['subsets'] );
			if ( null === $subsets ) {
				return new WP_Error( 'invalid_subsets', 'subsets must be an array of non-empty strings (each <=64 chars).', [ 'field' => 'subsets' ] );
			}
		} else {
			$subsets = $existing['subsets'] ?? [];
		}

		$label = array_key_exists( 'label', $input )
			? sanitize_text_field( (string) $input['label'] )
			: ( $existing['label'] ?? $family );

		$fallback = array_key_exists( 'fallback', $input )
			? sanitize_text_field( (string) $input['fallback'] )
			: ( $existing['fallback'] ?? '' );

		$status_raw = $input['status'] ?? ( $existing['status'] ?? 'active' );
		$status     = in_array( $status_raw, [ 'active', 'archived' ], true ) ? $status_raw : 'active';

		return [
			'family'      => $family,
			'source'      => $source,
			'weights'     => $weights,
			'subsets'     => $subsets,
			'label'       => $label,
			'fallback'    => $fallback,
			'status'      => $status,
			'lastUpdated' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
		];
	}

	/**
	 * Convert a `WP_Error` returned by `build_font_record` into a typed
	 * envelope error response. Keeps the error-vocabulary mapping in one
	 * place so create + update branch identically.
	 */
	private static function font_build_error_to_envelope( WP_Error $err, $id_for_data = null ) {
		$code = $err->get_error_code();
		$data = (array) $err->get_error_data();
		$field = $data['field'] ?? null;

		// Required-field-missing — keep the dedicated `missing` discriminator
		// so callers can branch invalid-shape vs missing-required without
		// parsing the message.
		if ( in_array( $code, [ 'missing_family', 'missing_source' ], true ) ) {
			$payload = [ 'field' => $field, 'missing' => $data['missing'] ?? $field ];
			if ( null !== $id_for_data ) {
				$payload['id'] = $id_for_data;
			}
			return self::envelope_error(
				'invalid_input',
				$err->get_error_message(),
				null,
				400,
				$payload
			);
		}

		// Source enum violation — surface allowed + received so the caller
		// can render a hint without re-parsing the message.
		if ( 'invalid_source' === $code ) {
			$payload = [
				'field'    => $field,
				'allowed'  => $data['allowed'] ?? self::valid_font_sources(),
				'received' => $data['received'] ?? null,
			];
			if ( null !== $id_for_data ) {
				$payload['id'] = $id_for_data;
			}
			return self::envelope_error( 'invalid_input', $err->get_error_message(), null, 400, $payload );
		}

		// Generic shape violation (family / weights / subsets).
		$payload = [ 'field' => $field ];
		if ( null !== $id_for_data ) {
			$payload['id'] = $id_for_data;
		}
		return self::envelope_error( 'invalid_input', $err->get_error_message(), null, 400, $payload );
	}

	/**
	 * Create a new global font.
	 *
	 * Mints a fresh `gfid-<uuid>` if `id` is omitted; otherwise uses the
	 * supplied id (validated against the gfid charset/length rules).
	 *
	 * Returns `conflict` (HTTP 409) when the supplied id already exists —
	 * unlike `global_color_upsert` (merge-mode), create is strict: callers
	 * should use `global_font_update` to modify an existing record.
	 */
	public static function global_font_create( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Requires admin capability', [ 'status' => 403 ] );
		}

		$id_raw = (string) ( $request->get_param( 'id' ) ?? '' );
		$id     = self::validate_global_font_id( $id_raw );
		if ( is_wp_error( $id ) ) {
			return self::envelope_error(
				'invalid_input',
				$id->get_error_message(),
				null,
				400,
				[ 'field' => 'id', 'expected' => 'gfid-<suffix> where suffix matches [0-9a-z-]{1,80}', 'received' => sanitize_text_field( $id_raw ) ]
			);
		}

		$raw         = et_get_option( 'et_global_data' );
		$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
		if ( ! is_array( $global_data ) ) {
			$global_data = [];
		}
		$fonts = isset( $global_data['global_fonts'] ) && is_array( $global_data['global_fonts'] )
			? $global_data['global_fonts']
			: [];

		// Strict create: collision on existing id returns 409 conflict.
		// Distinguishes from update semantics (which strict-updates by id).
		if ( isset( $fonts[ $id ] ) ) {
			return self::envelope_error(
				'conflict',
				sprintf( "Font '%s' already exists. Use diviops_global_font_update to modify, or pick a different id.", $id ),
				'Use diviops_global_font_update to modify an existing font.',
				409,
				[ 'id' => $id, 'existing' => $fonts[ $id ] ]
			);
		}

		$input = [
			'family'   => $request->get_param( 'family' ),
			'source'   => $request->get_param( 'source' ),
			'weights'  => $request->get_param( 'weights' ),
			'subsets'  => $request->get_param( 'subsets' ),
			'label'    => $request->get_param( 'label' ),
			'fallback' => $request->get_param( 'fallback' ),
			'status'   => $request->get_param( 'status' ),
		];
		// Strip nulls so build_font_record can distinguish "omitted" from
		// "present-but-null" (array_key_exists checks).
		$input = array_filter( $input, static fn( $v ) => null !== $v );

		$record = self::build_font_record( $input, [] );
		if ( is_wp_error( $record ) ) {
			return self::font_build_error_to_envelope( $record, $id );
		}

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			return self::dry_run_response(
				sprintf( "Would create global font '%s' (family=%s, source=%s).", $id, $record['family'], $record['source'] ),
				[ [
					'kind'   => 'global_font.create',
					'target' => "global_font/{$id}",
					'before' => null,
					'after'  => $record,
				] ]
			);
		}

		$fonts[ $id ]                = $record;
		$global_data['global_fonts'] = $fonts;
		et_update_option( 'et_global_data', $global_data );

		return self::attach_meta(
			self::envelope_success( [
				'id'    => $id,
				'font'  => $record,
			] ),
			[ 'canonical_path' => 'et_divi.et_global_data.global_fonts' ]
		);
	}

	/**
	 * Update an existing global font (strict — id must exist).
	 *
	 * Partial update: only supplied fields are written; omitted fields are
	 * preserved from the existing record. `lastUpdated` is bumped every
	 * write. `family` cannot be re-validated to empty (the build_font_record
	 * helper rejects null/empty); rename via delete + create instead.
	 */
	public static function global_font_update( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Requires admin capability', [ 'status' => 403 ] );
		}

		$id_raw = (string) ( $request->get_param( 'id' ) ?? '' );
		if ( '' === $id_raw ) {
			return self::envelope_error(
				'invalid_input',
				'id is required for global_font_update.',
				null,
				400,
				[ 'field' => 'id', 'missing' => 'id' ]
			);
		}
		$id = self::validate_global_font_id( $id_raw );
		if ( is_wp_error( $id ) ) {
			return self::envelope_error(
				'invalid_input',
				$id->get_error_message(),
				null,
				400,
				[ 'field' => 'id', 'expected' => 'gfid-<suffix> where suffix matches [0-9a-z-]{1,80}', 'received' => sanitize_text_field( $id_raw ) ]
			);
		}

		$raw         = et_get_option( 'et_global_data' );
		$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
		if ( ! is_array( $global_data ) ) {
			$global_data = [];
		}
		$fonts = isset( $global_data['global_fonts'] ) && is_array( $global_data['global_fonts'] )
			? $global_data['global_fonts']
			: [];

		if ( ! isset( $fonts[ $id ] ) ) {
			return self::envelope_error(
				'not_found',
				sprintf( "Font '%s' not found in registry.", $id ),
				'Run diviops_global_font_list to see existing gfids, or use diviops_global_font_create to add it.',
				404,
				[ 'id' => $id ]
			);
		}

		$existing = (array) $fonts[ $id ];

		$input = [
			'family'   => $request->get_param( 'family' ),
			'source'   => $request->get_param( 'source' ),
			'weights'  => $request->get_param( 'weights' ),
			'subsets'  => $request->get_param( 'subsets' ),
			'label'    => $request->get_param( 'label' ),
			'fallback' => $request->get_param( 'fallback' ),
			'status'   => $request->get_param( 'status' ),
		];
		$input = array_filter( $input, static fn( $v ) => null !== $v );

		$record = self::build_font_record( $input, $existing );
		if ( is_wp_error( $record ) ) {
			return self::font_build_error_to_envelope( $record, $id );
		}

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			return self::dry_run_response(
				sprintf( "Would update global font '%s'.", $id ),
				[ [
					'kind'   => 'global_font.update',
					'target' => "global_font/{$id}",
					'before' => $existing,
					'after'  => $record,
				] ]
			);
		}

		$fonts[ $id ]                = $record;
		$global_data['global_fonts'] = $fonts;
		et_update_option( 'et_global_data', $global_data );

		return self::attach_meta(
			self::envelope_success( [
				'id'   => $id,
				'font' => $record,
			] ),
			[ 'canonical_path' => 'et_divi.et_global_data.global_fonts' ]
		);
	}

	/**
	 * Delete a global font from the registry by gfid.
	 *
	 * Live-reference check: scans pages / TB layouts / library / canvas /
	 * preset registry for the literal `gfid-*` ID and returns `conflict`
	 * (HTTP 409) with the locations payload when references exist. Pass
	 * `force=true` to override.
	 *
	 * Unlike `global_color_delete`, there are no customizer-bound `gfid-*`
	 * defaults to protect — the customizer-bound font defaults live under
	 * `heading_font` / `body_font` (plain WP options) which are not part
	 * of this registry.
	 */
	public static function global_font_delete( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Requires admin capability', [ 'status' => 403 ] );
		}

		$id_raw = (string) ( $request->get_param( 'id' ) ?? '' );
		$force  = (bool) $request->get_param( 'force' );

		if ( '' === $id_raw || 0 !== strpos( $id_raw, 'gfid-' ) ) {
			return self::envelope_error(
				'invalid_input',
				'id must be a non-empty string starting with "gfid-".',
				null,
				400,
				[ 'field' => 'id', 'expected' => 'gfid-<suffix>', 'received' => $id_raw ]
			);
		}
		$id = $id_raw;

		$raw         = et_get_option( 'et_global_data' );
		$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
		if ( ! is_array( $global_data ) ) {
			$global_data = [];
		}
		$fonts = isset( $global_data['global_fonts'] ) && is_array( $global_data['global_fonts'] )
			? $global_data['global_fonts']
			: [];

		if ( ! isset( $fonts[ $id ] ) ) {
			return self::envelope_error(
				'not_found',
				"Font '{$id}' not found in registry.",
				'Run diviops_global_font_list to see existing gfids.',
				404,
				[ 'id' => $id ]
			);
		}

		$font = $fonts[ $id ];

		// Reference-safety scan — only run on apply (not dry_run, unless
		// force=false). Mirror the global_color_delete pattern: cheap
		// fast-path probe + full collect on positive hit. Reuses the
		// generic appears-anywhere helper from trait-variable (matches any
		// substring); the dedicated full walker for gfid is new (the
		// variable walker's regex is g[vc]id- only).
		$refs    = null;
		$appears = self::variable_id_appears_anywhere( $id );
		if ( ! $force && $appears ) {
			$refs = self::collect_global_font_refs();
			if ( isset( $refs['all_ids'][ $id ] ) ) {
				return self::envelope_error(
					'conflict',
					sprintf(
						"Font '%s' has %d live reference(s). Pass force=true to delete anyway; orphan refs will fall back to the browser default until pages are re-authored.",
						$id,
						$refs['all_ids'][ $id ]
					),
					'Pass force=true to override, or remove the references first.',
					409,
					[
						'id'             => $id,
						'ref_count'      => $refs['all_ids'][ $id ],
						'locations'      => $refs['locations'][ $id ] ?? [],
						'scan_truncated' => $refs['scan_truncated'],
						'scanned_posts'  => $refs['scanned_posts'],
					]
				);
			}
		}

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			$ref_count = 0;
			$locations = [];
			if ( null === $refs && $appears ) {
				$refs = self::collect_global_font_refs();
			}
			if ( null !== $refs ) {
				$ref_count = $refs['all_ids'][ $id ] ?? 0;
				$locations = $refs['locations'][ $id ] ?? [];
			}
			$label  = $font['label'] ?? '';
			$family = $font['family'] ?? '';
			return self::dry_run_response(
				"Would delete global font '{$id}' ('{$label}', family={$family}). {$ref_count} live reference(s).",
				[ [
					'kind'   => 'global_font.delete',
					'target' => "global_font/{$id}",
					'before' => [
						'family'    => $family,
						'label'     => $label,
						'ref_count' => $ref_count,
						'locations' => $locations,
					],
				] ]
			);
		}

		unset( $fonts[ $id ] );
		$global_data['global_fonts'] = $fonts;
		et_update_option( 'et_global_data', $global_data );

		return self::attach_meta(
			self::envelope_success( [
				'deleted' => [
					'gfid'   => $id,
					'family' => $font['family'] ?? '',
					'label'  => $font['label'] ?? '',
					'forced' => $force,
				],
				'message' => "Font '{$id}' deleted.",
			] ),
			[ 'canonical_path' => 'et_divi.et_global_data.global_fonts' ]
		);
	}

	/**
	 * Collect every `gfid-*` reference across content surfaces. Parallel
	 * to `collect_variable_refs` but with a `gfid-` regex (the variable
	 * walker matches `g[vc]id-` only, so we duplicate the walker rather
	 * than widening its regex and impacting variable scan semantics).
	 *
	 * Scanned surfaces match `collect_variable_refs`:
	 *   - pages / TB layouts / library / canvas (post_content blocks)
	 *   - preset registry (`et_divi_builder_global_presets_d5`)
	 *
	 * Returns same shape as `collect_variable_refs`:
	 *   { all_ids: {id => count}, locations: {id => [{type,...}]},
	 *     scan_truncated: bool, scanned_posts: int }
	 */
	public static function collect_global_font_refs() {
		$all_ids        = [];
		$locations      = [];
		$scan_truncated = false;

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
			if ( false === strpos( $content, 'gfid-' ) ) {
				continue;
			}
			$blocks    = parse_blocks( $content );
			$local_ids = [];
			self::walk_blocks_for_gfid_refs( $blocks, $all_ids, $local_ids );

			foreach ( array_unique( $local_ids ) as $id ) {
				$locations[ $id ][] = [
					'type'    => $p->post_type,
					'post_id' => $p->ID,
					'title'   => $p->post_title,
				];
			}
		}

		// Preset registry — same path as collect_variable_refs.
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
					self::walk_value_for_gfid_refs( $preset, $all_ids, $local_ids );
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
	 * Walk any PHP value collecting `gfid-` references. Mirrors
	 * `walk_value_for_variable_refs` shape — string leaves with the
	 * `gfid-` substring run through a regex that matches the canonical
	 * `gfid-[A-Za-z0-9_-]+` form (charset matches `validate_global_font_id`'s
	 * gate, plus uppercase/underscore tolerance because the appears-anywhere
	 * test is permissive — narrow false-positives that surface here are
	 * caught by the strict id lookup in `global_font_delete`'s
	 * `isset($refs['all_ids'][$id])` check).
	 */
	public static function walk_value_for_gfid_refs( $value, &$all_ids, &$local_ids ) {
		if ( is_string( $value ) ) {
			if ( false === strpos( $value, 'gfid-' ) ) {
				return;
			}
			// Match the quoted form only — `$variable()$` tokens in attrs
			// surface as `"name":"gfid-..."`. This mirrors the precedent at
			// trait-variable.php:62 (walk_value_for_variable_refs) which scans
			// `"name":"g[vc]id-..."` for color + variable refs and explicitly
			// does NOT scan bare substrings. Bare-substring scanning was
			// considered for CSS / freeform-string refs but Codex review on PR
			// #708 flagged a `\b` word-boundary collision with valid `gfid-`
			// suffixes ending in `-` (e.g. `gfid-foo-` would match as
			// `gfid-foo`, missing the live reference and silently passing
			// `_delete`). Resolving by matching the existing walker's narrower
			// surface: quoted-only. If bare-string CSS coverage is needed
			// later, add it across all three walkers (gfid + gvid + gcid)
			// symmetrically with char-class boundaries `(?<![A-Za-z0-9_-])`
			// rather than `\b`.
			if ( preg_match_all( '/"name"\s*:\s*"(gfid-[A-Za-z0-9_-]+)"/', $value, $m ) ) {
				foreach ( $m[1] as $id ) {
					if ( '' === $id ) {
						continue;
					}
					$all_ids[ $id ] = ( $all_ids[ $id ] ?? 0 ) + 1;
					$local_ids[]    = $id;
				}
			}
			return;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $v ) {
				self::walk_value_for_gfid_refs( $v, $all_ids, $local_ids );
			}
		}
	}

	/**
	 * Recursively walk a parsed-blocks tree, scanning each block's attrs
	 * for `gfid-` references via walk_value_for_gfid_refs.
	 */
	public static function walk_blocks_for_gfid_refs( $blocks, &$all_ids, &$local_ids ) {
		foreach ( $blocks as $block ) {
			if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				self::walk_value_for_gfid_refs( $block['attrs'], $all_ids, $local_ids );
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk_blocks_for_gfid_refs( $block['innerBlocks'], $all_ids, $local_ids );
			}
		}
	}
}
