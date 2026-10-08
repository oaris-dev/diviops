<?php
/** CSS-specific storage service, used by the bounded REST adapter. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-custom-css-plan.php';

final class DiviOps_Custom_CSS_Store {
	const PREFIX = 'diviops_rollback_snapshot_';
	const LOCK = 'diviops_custom_css_write_lock';

	public static function read(): array {
		return self::context();
	}

	/** Preview is pure after reading context. Commits always require its complete state digest. */
	public static function upsert( string $name, string $css, string $expected, bool $dry_run = true ): array {
		$context = self::context();
		if ( ! $context['ok'] ) { return $context; }
		$before = $context['state'];
		if ( ! hash_equals( $context['checksum'], $expected ) ) { return self::error( 'conflict' ); }
		if ( ( $before['mirror_exists'] ? $before['mirror'] : '' ) !== $before['css'] ) { return self::error( 'legacy_divergence' ); }
		$plan = DiviOps_Custom_CSS_Plan::upsert( $before['css'], $name, $css );
		if ( ! $plan['ok'] ) { return $plan; }
		if ( $dry_run || $plan['noop'] ) { return [ 'ok' => true, 'dry_run' => $dry_run, 'plan' => $plan ]; }
		$lock = wp_generate_uuid4();
		if ( ! add_option( self::LOCK, $lock, '', 'no' ) ) { return self::error( 'busy' ); }
		try {
			$fresh = self::context();
			if ( ! $fresh['ok'] || $fresh['state'] !== $before ) { return self::error( 'conflict' ); }
			$record = self::snapshot( $before, $plan['css'], $name );
			$key = self::PREFIX . $record['snapshot_id'];
			if ( ! add_option( $key, $record, '', 'no' ) || ! self::stored( $record ) ) { return self::error( 'snapshot_failed' ); }
			// Snapshot persistence may run hooks. Recheck before the first content write.
			$fresh = self::context();
			if ( ! $fresh['ok'] || $fresh['state'] !== $before ) { return self::error( 'conflict', $record['snapshot_id'] ); }
			self::write_css( $plan['css'], $before['stylesheet'] );
			$after = self::context();
			$wanted = $before;
			$wanted['css'] = $plan['css']; $wanted['mirror_exists'] = true; $wanted['mirror'] = $plan['css'];
			if ( $after['ok'] && $after['state'] === $wanted ) {
				$record['status'] = 'write_applied'; $record['css_state']['after'] = $wanted;
				$record['after'] = [ 'checksum' => self::digest( $plan['css'] ), 'byte_length' => strlen( $plan['css'] ) ];
				if ( ! self::save( $record ) ) { return self::error( 'finalization_failed', $record['snapshot_id'], true ); }
				return [ 'ok' => true, 'snapshot_id' => $record['snapshot_id'], 'checksum' => self::state_digest( $wanted ), 'state' => $wanted ];
			}
			$recovered = self::recover( $record );
			$record['status'] = $recovered ? 'write_recovered' : 'recovery_required';
			$finalized = self::save( $record );
			return [ 'ok' => false, 'error' => 'write_failed', 'snapshot_id' => $record['snapshot_id'], 'recovered' => $recovered, 'record_verified' => $finalized, 'cache_may_be_invalidated' => true ];
		} finally {
			if ( get_option( self::LOCK ) === $lock ) { delete_option( self::LOCK ); }
		}
	}

	/** Explicit recovery also supports interrupted attempts, without guessing post identities. */
	public static function restore( string $id, string $expected, bool $dry_run = true ): array {
		$current = self::context();
		if ( ! $current['ok'] ) { return $current; }
		if ( ! hash_equals( $current['checksum'], $expected ) ) { return self::error( 'conflict' ); }
		if ( ! preg_match( '/\Acss_[a-f0-9-]{36}\z/', $id ) ) { return self::error( 'invalid_snapshot' ); }
		$record = get_option( self::PREFIX . $id );
		if ( ! is_array( $record ) || ( $record['snapshot_id'] ?? '' ) !== $id || ( $record['tool'] ?? '' ) !== 'diviops_custom_css_upsert' || ( $record['css_state']['version'] ?? 0 ) !== 1 || empty( $record['expires_at'] ) || false === strtotime( $record['expires_at'] ) || strtotime( $record['expires_at'] ) < time() ) { return self::error( 'invalid_snapshot' ); }
		if ( ! self::recoverable( $record, $current['state'] ) ) { return self::error( 'conflict', $id ); }
		if ( $dry_run ) { return [ 'ok' => true, 'dry_run' => true, 'snapshot_id' => $id, 'before' => $current['state'], 'after' => $record['css_state']['before'] ]; }
		$lock = wp_generate_uuid4();
		if ( ! add_option( self::LOCK, $lock, '', 'no' ) ) { return self::error( 'busy' ); }
		try {
			$fresh = self::context();
			if ( ! $fresh['ok'] || $fresh['state'] !== $current['state'] ) { return self::error( 'conflict', $id ); }
			$recovered = self::recover( $record );
			$record['status'] = $recovered ? 'css_restored' : 'recovery_required';
			$verified = self::save( $record );
			return [ 'ok' => $recovered && $verified, 'recovered' => $recovered, 'record_verified' => $verified, 'snapshot_id' => $id, 'cache_may_be_invalidated' => true ];
		} finally {
			if ( get_option( self::LOCK ) === $lock ) { delete_option( self::LOCK ); }
		}
	}

	private static function context(): array {
		global $shortname, $wp_filter;
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_css' ) ) { return self::error( 'forbidden' ); }
		if ( 'divi' !== $shortname || ! function_exists( 'et_options_stored_in_one_row' ) || ! et_options_stored_in_one_row() || ! function_exists( 'wp_update_custom_css_post' ) ) { return self::error( 'unsupported_storage' ); }
		$callbacks = $wp_filter['update_custom_css_data']->callbacks ?? [];
		$seen = [];
		foreach ( $callbacks as $priority => $entries ) {
			foreach ( $entries as $entry ) {
				$fn = $entry['function'];
				if ( 10 !== $priority || ! is_string( $fn ) || ! in_array( $fn, [ 'et_back_sync_custom_css_options', 'et_update_custom_css_data_cb' ], true ) || 1 !== $entry['accepted_args'] ) { return self::error( 'unsupported_hooks' ); }
				$seen[] = $fn;
			}
		}
		if ( $seen !== [ 'et_back_sync_custom_css_options', 'et_update_custom_css_data_cb' ] ) { return self::error( 'unsupported_hooks' ); }
		$stylesheet = get_stylesheet();
		// A query avoids wp_get_custom_css_post's lazy theme-mod write during previews.
		$posts = get_posts( [ 'post_type' => 'custom_css', 'post_status' => get_post_stati(), 'name' => sanitize_title( $stylesheet ), 'numberposts' => 2, 'cache_results' => false, 'suppress_filters' => true ] ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- Inspect the canonical internal CSS post, without frontend query filters changing its identity.
		if ( 1 !== count( $posts ) || 'publish' !== $posts[0]->post_status || (int) get_theme_mod( 'custom_css_post_id' ) !== (int) $posts[0]->ID ) { return self::error( 'initialize_css_in_native_editor' ); }
		$post = $posts[0];
		if ( $post->post_title !== $stylesheet || $post->post_name !== sanitize_title( $stylesheet ) ) { return self::error( 'unsupported_post_identity' ); }
		if ( '' !== $post->post_content_filtered ) { return self::error( 'preprocessed_css' ); }
		wp_cache_delete( 'et_divi', 'options' ); wp_cache_delete( 'alloptions', 'options' );
		$bag = get_option( 'et_divi' );
		if ( ! is_array( $bag ) || ( array_key_exists( 'divi_custom_css', $bag ) && ! is_string( $bag['divi_custom_css'] ) ) ) { return self::error( 'unsupported_storage' ); }
		$mirror = $bag['divi_custom_css'] ?? null;
		if ( strlen( $post->post_content ) > DiviOps_Custom_CSS_Plan::MAX_BYTES || ( null !== $mirror && strlen( $mirror ) > DiviOps_Custom_CSS_Plan::MAX_BYTES ) ) { return self::error( 'too_large' ); }
		$state = [ 'stylesheet' => $stylesheet, 'post_id' => (int) $post->ID, 'css' => $post->post_content, 'filtered' => '', 'mirror_exists' => null !== $mirror, 'mirror' => $mirror ];
		return [ 'ok' => true, 'state' => $state, 'checksum' => self::state_digest( $state ) ];
	}

	private static function write_css( string $css, string $stylesheet ): void {
		global $et_theme_options;
		$et_theme_options = get_option( 'et_divi' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Refresh Divi's existing cache before its native mirror callback.
		try { wp_update_custom_css_post( $css, [ 'stylesheet' => $stylesheet, 'preprocessed' => '' ] ); }
		catch ( Throwable $error ) { /* Readback, not an exception, determines the mutated surfaces. */ }
	}

	private static function recoverable( array $record, array $state ): bool {
		$before = $record['css_state']['before'] ?? null;
		if ( ! in_array( $record['status'] ?? '', [ 'created', 'write_applied', 'write_recovered', 'recovery_required', 'css_restored' ], true ) ) { return false; }
		$planned = $record['css_state']['planned'] ?? null;
		if ( ( $record['schema_version'] ?? 0 ) !== 1 || ( $record['target']['post_type'] ?? '' ) !== 'custom_css' || ( $record['target']['id'] ?? null ) !== $state['post_id'] ) { return false; }
		if ( ! is_array( $before ) || array_keys( $before ) !== array_keys( $state ) || ! is_string( $planned ) || ! is_string( $before['css'] ) ) { return false; }
		if ( ! is_string( $before['stylesheet'] ) || ! is_int( $before['post_id'] ) || '' !== $before['filtered'] || ! is_bool( $before['mirror_exists'] ) || ( $before['mirror_exists'] ? ! is_string( $before['mirror'] ) || $before['mirror'] !== $before['css'] : null !== $before['mirror'] || '' !== $before['css'] ) ) { return false; }
		if ( strlen( $planned ) > DiviOps_Custom_CSS_Plan::MAX_BYTES || strlen( $before['css'] ) > DiviOps_Custom_CSS_Plan::MAX_BYTES || ( $record['before']['checksum'] ?? '' ) !== self::digest( $before['css'] ) || ( $record['css_state']['planned_checksum'] ?? '' ) !== self::digest( $planned ) ) { return false; }
		if ( $state['stylesheet'] !== $before['stylesheet'] || $state['post_id'] !== $before['post_id'] || $state['filtered'] !== $before['filtered'] ) { return false; }
		if ( 'write_applied' === $record['status'] ) {
			$after = $before; $after['css'] = $planned; $after['mirror_exists'] = true; $after['mirror'] = $planned;
			return ( $record['css_state']['after'] ?? null ) === $after && $state === $after;
		}
		return in_array( $state['css'], [ $before['css'], $planned ], true ) && ( [ $state['mirror_exists'], $state['mirror'] ] === [ $before['mirror_exists'], $before['mirror'] ] || [ $state['mirror_exists'], $state['mirror'] ] === [ true, $planned ] );
	}

	private static function recover( array $record ): bool {
		$current = self::context();
		if ( ! $current['ok'] || ! self::recoverable( $record, $current['state'] ) ) { return false; }
		$before = $record['css_state']['before'];
		if ( $current['state'] === $before ) { return true; }
		self::write_css( $before['css'], $before['stylesheet'] );
		$readback = self::context();
		$wanted = $before; $wanted['mirror_exists'] = true; $wanted['mirror'] = $before['css'];
		if ( ! $readback['ok'] || $readback['state'] !== $wanted ) { return false; }
		if ( ! $before['mirror_exists'] ) {
			// Current bag only: never restore siblings from a historical option snapshot.
			global $et_theme_options;
			$bag = get_option( 'et_divi' ); unset( $bag['divi_custom_css'] );
			update_option( 'et_divi', $bag ); $et_theme_options = $bag; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Keep Divi's existing cache synchronized with the restored owned field.
		}
		$readback = self::context();
		return $readback['ok'] && $readback['state'] === $before;
	}

	private static function snapshot( array $before, string $planned, string $name ): array {
		return [
			'schema_version' => 1, 'snapshot_id' => 'css_' . wp_generate_uuid4(), 'status' => 'created',
			'created_at' => gmdate( 'c' ), 'expires_at' => gmdate( 'c', time() + 7 * 86400 ),
			'created_by' => [ 'user_id' => get_current_user_id(), 'login' => null ],
			'tool' => 'diviops_custom_css_upsert', 'operation' => [ 'name' => $name ],
			'target' => [ 'kind' => 'post', 'id' => $before['post_id'], 'post_type' => 'custom_css' ],
			'before' => [ 'value' => $before['css'], 'checksum' => self::digest( $before['css'] ), 'byte_length' => strlen( $before['css'] ) ],
			'after' => [], 'restore' => [ 'restorable' => false ], 'cleanup' => [],
			'css_state' => [ 'version' => 1, 'before' => $before, 'planned' => $planned, 'planned_checksum' => self::digest( $planned ) ],
		];
	}

	private static function save( array $record ): bool {
		update_option( self::PREFIX . $record['snapshot_id'], $record, false );
		return self::stored( $record );
	}
	private static function stored( array $record ): bool {
		return get_option( self::PREFIX . $record['snapshot_id'] ) === $record;
	}
	private static function digest( string $value ): string { return 'sha256:' . hash( 'sha256', $value ); }
	private static function state_digest( array $state ): string { return self::digest( serialize( $state ) ); }
	private static function error( string $code, ?string $id = null, bool $mutated = false ): array {
		return [ 'ok' => false, 'error' => $code, 'snapshot_id' => $id, 'mutation_possible' => $mutated ];
	}
}
