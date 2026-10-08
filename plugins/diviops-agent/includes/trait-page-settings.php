<?php
/** Named page background settings; deliberately not a general metadata writer. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait DiviOps_Agent_PageSettings {
	private static function page_settings_keys() {
		return [
			'content_area_background' => '_et_pb_content_area_background_color',
			'section_background' => '_et_pb_section_background_color',
		];
	}

	private static function page_settings_context( $id ) {
		$post = get_post( $id );
		if ( ! $post ) { return self::envelope_error( 'not_found', 'Page not found.', '', 404 ); }
		if ( ! current_user_can( 'edit_post', $id ) ) { return self::envelope_error( 'forbidden', 'Page settings require edit permission.', '', 403 ); }
		if ( 'page' !== $post->post_type ) { return self::envelope_error( 'invalid_input', 'Only ordinary WordPress pages are supported.', '', 400 ); }
		if ( ! is_callable( [ 'ET_Builder_Settings', 'get_instance' ] ) || ! is_callable( [ 'ET_Builder_Settings', 'get_fields' ] ) || ! function_exists( 'et_sanitize_alpha_color' ) ) {
			return self::envelope_error( 'capability_missing', 'Native Divi page settings are unavailable.', '', 409 );
		}
		$fields = ET_Builder_Settings::get_fields( 'page' );
		if ( ! is_array( $fields ) ) {
			if ( ! function_exists( 'et_load_shortcode_framework' ) ) { return self::envelope_error( 'capability_missing', 'Native Divi settings loader is unavailable.', '', 409 ); }
			try {
				// Match et_builder_settings_init(): field definitions need shortcode helpers first.
				et_load_shortcode_framework();
				ET_Builder_Settings::get_instance();
				$fields = ET_Builder_Settings::get_fields( 'page' );
			} catch ( \Throwable $error ) {
				return self::envelope_error( 'capability_missing', 'Native Divi page settings could not initialize.', 'Check the Divi installation before retrying.', 409 );
			}
		}
		$defaults = [];
		foreach ( self::page_settings_keys() as $name => $key ) {
			$default = $fields[ substr( $key, 1 ) ]['default'] ?? null;
			if ( ! is_string( $default ) ) { return self::envelope_error( 'capability_missing', 'Native background defaults are unavailable.', '', 409 ); }
			$defaults[ $name ] = $default;
		}
		return $defaults;
	}

	/** Preserve physical presence, including an explicitly stored empty/default value. */
	private static function page_settings_snapshot( $id ) {
		$state = [];
		foreach ( self::page_settings_keys() as $name => $key ) {
			$rows = get_post_meta( $id, $key, false );
			if ( ! is_array( $rows ) || count( $rows ) > 1 || ( 1 === count( $rows ) && ( ! is_string( $rows[0] ) || strlen( $rows[0] ) > 8192 ) ) ) {
				return self::envelope_error( 'page_settings.ambiguous_state', 'Background metadata is malformed or duplicated.', 'Inspect the named field before editing.', 409, [ 'field' => $name ] );
			}
			$state[ $name ] = [ 'present' => 1 === count( $rows ), 'value' => $rows[0] ?? null ];
		}
		return $state;
	}

	private static function page_settings_checksum( $id, $state ) {
		return 'sha256:' . hash( 'sha256', wp_json_encode( [ 'page_id' => $id, 'settings' => $state ] ) );
	}

	private static function page_settings_payload( $id, $state, $defaults ) {
		return [ 'page_id' => $id, 'settings' => $state, 'defaults' => $defaults, 'settings_checksum' => self::page_settings_checksum( $id, $state ), 'value_semantics' => 'Stored values and native defaults, not computed browser colors.' ];
	}

	public static function page_settings_get( $request ) {
		$id = absint( $request['id'] );
		$defaults = self::page_settings_context( $id );
		if ( ! is_array( $defaults ) ) { return $defaults; }
		$state = self::page_settings_snapshot( $id );
		return is_array( $state ) ? self::envelope_success( self::page_settings_payload( $id, $state, $defaults ) ) : $state;
	}

	/** Validate a finite literal-color grammar before Divi's permissive sanitizer. */
	private static function page_settings_color( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 128 ) { return false; }
		$color = strtolower( trim( $value ) );
		if ( 'transparent' === $color ) { $color = 'rgba(0,0,0,0)'; }
		if ( preg_match( '/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/D', $color ) ) {
			return et_sanitize_alpha_color( $color );
		}
		if ( ! preg_match( '/^(rgb|rgba)\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*(0(?:\.\d+)?|1(?:\.0+)?|\.\d+))?\s*\)$/D', $color, $m ) ) { return false; }
		if ( ( 'rgba' === $m[1] ) !== isset( $m[5] ) || max( (int) $m[2], (int) $m[3], (int) $m[4] ) > 255 ) { return false; }
		return et_sanitize_alpha_color( $color );
	}

	private static function page_settings_has_draft( $id ) {
		foreach ( self::page_settings_keys() as $key ) {
			if ( metadata_exists( 'post', $id, $key . '_draft' ) ) { return true; }
		}
		return false;
	}

	/** Restore only keys attempted by this invocation; verify rather than trusting WP booleans. */
	private static function page_settings_restore( $id, $before, $attempted ) {
		foreach ( $attempted as $name ) {
			$key = self::page_settings_keys()[ $name ];
			if ( $before[ $name ]['present'] ) { update_post_meta( $id, $key, wp_slash( $before[ $name ]['value'] ) ); }
			else { delete_post_meta( $id, $key ); }
		}
		return self::page_settings_snapshot( $id ) === $before;
	}

	public static function page_settings_update( $request ) {
		$id = absint( $request['id'] );
		$defaults = self::page_settings_context( $id );
		if ( ! is_array( $defaults ) ) { return $defaults; }
		$input = $request->get_param( 'settings' );
		if ( is_object( $input ) ) { $input = get_object_vars( $input ); }
		if ( ! is_array( $input ) || ! $input || array_diff( array_keys( $input ), array_keys( self::page_settings_keys() ) ) ) {
			return self::envelope_error( 'invalid_input', 'Provide one or both named background settings.', 'Omit a field to preserve it; use null to clear it.', 400 );
		}
		$expected = $request->get_param( 'expected_checksum' );
		if ( ! is_string( $expected ) || ! preg_match( '/^sha256:[a-f0-9]{64}$/D', $expected ) ) { return self::envelope_error( 'invalid_input', 'An exact settings checksum is required.', 'Read page settings first.', 400 ); }
		$before = self::page_settings_snapshot( $id );
		if ( ! is_array( $before ) ) { return $before; }
		if ( ! hash_equals( self::page_settings_checksum( $id, $before ), $expected ) ) { return self::envelope_error( 'page_settings.drift', 'Page settings changed.', 'Read settings and preview again.', 409 ); }
		if ( self::page_settings_has_draft( $id ) ) { return self::envelope_error( 'page_settings.draft_conflict', 'Native background draft metadata exists.', 'Save or discard the background draft in Divi before editing.', 409 ); }
		$after = $before;
		foreach ( $input as $name => $value ) {
			if ( null === $value ) { $after[ $name ] = [ 'present' => false, 'value' => null ]; }
			else {
				$color = self::page_settings_color( $value );
				if ( false === $color ) { return self::envelope_error( 'invalid_input', 'Unsupported background color.', 'Use hex, bounded rgb()/rgba(), transparent, or null to clear.', 400, [ 'field' => $name ] ); }
				$after[ $name ] = [ 'present' => true, 'value' => $color ];
			}
		}
		$changes = [];
		foreach ( $after as $name => $value ) {
			if ( $before[ $name ] !== $value ) { $changes[ $name ] = [ 'before' => $before[ $name ], 'after' => $value ]; }
		}
		$plan = [ 'page_id' => $id, 'changes' => $changes, 'before' => self::page_settings_payload( $id, $before, $defaults ), 'after' => self::page_settings_payload( $id, $after, $defaults ), 'cache' => $changes ? [ 'post_id' => $id, 'cleanup_dynamic_assets' => true, 'cleanup_canvas_refs' => false ] : null ];
		if ( rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false ) ) {
			$preview_changes = [];
			foreach ( $changes as $name => $change ) {
				$preview_changes[] = [ 'kind' => 'page.settings.background', 'target' => "page#{$id}/{$name}", 'before' => $change['before'], 'after' => $change['after'] ];
			}
			return self::dry_run_response( "Would update page #{$id} backgrounds (" . count( $changes ) . ' changed fields).', $preview_changes, [], [ 'before' => $plan['before'], 'after' => $plan['after'], 'cache' => $plan['cache'] ] );
		}
		if ( ! $changes ) { return self::envelope_success( [ 'changed' => false, 'settings' => $plan['after'], 'cache' => null ] ); }
		// This is an optimistic check, not a database transaction or editor lock.
		if ( self::page_settings_snapshot( $id ) !== $before || self::page_settings_has_draft( $id ) ) { return self::envelope_error( 'page_settings.drift', 'Page settings or drafts changed before apply.', 'Read and preview again.', 409 ); }
		$attempted = [];
		try {
			foreach ( $changes as $name => $change ) {
				$attempted[] = $name;
				$key = self::page_settings_keys()[ $name ];
				if ( $change['after']['present'] ) { update_post_meta( $id, $key, wp_slash( $change['after']['value'] ) ); }
				else { delete_post_meta( $id, $key ); }
			}
			if ( self::page_settings_snapshot( $id ) !== $after ) { throw new \RuntimeException( 'settings_readback_failed' ); }
		} catch ( \Throwable $error ) {
			try { $restored = self::page_settings_restore( $id, $before, $attempted ); } catch ( \Throwable $restore_error ) { $restored = false; }
			return self::envelope_error( 'page_settings.write_failed', 'Background write/readback failed.', 'Read settings before another attempt.', 500, [ 'restored' => $restored ] );
		}
		$cache_request = new \WP_REST_Request( 'POST' );
		$cache_request->set_param( 'post_id', $id );
		$cache_request->set_param( 'cleanup_dynamic_assets', true );
		try {
			$cache = self::flush_static_cache( $cache_request );
			$cache_data = $cache->get_data();
			if ( empty( $cache_data['ok'] ) ) { throw new \RuntimeException( 'cache_refresh_failed' ); }
		} catch ( \Throwable $error ) {
			return self::envelope_error( 'page_settings.cache_refresh_failed', 'Settings were saved, but cache refresh failed.', 'Read settings; retry the post-scoped cache flush, not the write.', 500, [ 'settings_applied' => true, 'settings' => $plan['after'] ] );
		}
		return self::envelope_success( [ 'changed' => true, 'settings' => $plan['after'], 'cache' => $cache_data['data'] ] );
	}
}
