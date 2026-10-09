<?php
/** Fixed-field Rank Math adapter. No generic metadata or abilities proxy. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DiviOps_SEO_Rank_Math {
	const VERSION = '1.0.279';
	const PLUGIN = 'seo-by-rank-math/rank-math.php';
	const RECOVERY_PREFIX = 'diviops_seo_rm_';
	private const KEYS = [ 'canonical_url' => 'rank_math_canonical_url', 'noindex' => 'rank_math_robots' ];
	private const DIRECTIVES = [ 'index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' ];

	public static function discovery(): array {
		$listed = in_array( self::PLUGIN, (array) get_option( 'active_plugins', [] ), true ) || array_key_exists( self::PLUGIN, (array) get_site_option( 'active_sitewide_plugins', [] ) );
		$active = $listed && defined( 'RANK_MATH_VERSION' ) && class_exists( '\\RankMath\\Helper' );
		$path = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/' . self::PLUGIN : '';
		$version = $active ? (string) RANK_MATH_VERSION : null;
		if ( ! $active && is_file( $path ) && is_readable( $path ) ) {
			$header = file_get_contents( $path, false, null, 0, 8192 );
			if ( is_string( $header ) && preg_match( '/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $header, $match ) ) { $version = trim( $match[1] ); }
		}
		$runtime = $active && self::VERSION === $version && function_exists( 'rank_math' ) ? rank_math() : null;
		$initialized = is_object( $runtime ) && is_object( $runtime->registration ) && false === $runtime->registration->invalid && is_object( $runtime->manager ) && is_callable( [ $runtime->variables, 'setup' ] );
		$ready = $initialized && self::VERSION === $version && is_callable( [ '\\RankMath\\Helper', 'get_settings' ] ) && is_callable( [ '\\RankMath\\Helper', 'is_post_type_accessible' ] ) && method_exists( '\\RankMath\\Paper\\Singular', 'get_seo_meta' ) && is_callable( [ '\\RankMath\\Rest\\Sanitize', 'get' ] );
		return [
			'provider' => 'rank_math', 'name' => 'Rank Math', 'installed' => $active || is_file( $path ), 'active' => $active,
			'version' => $version, 'compatible' => self::VERSION === $version, 'readable' => $ready, 'writable' => $ready,
			'adapter' => 'source_assessed', 'runtime_verified' => false,
			'fields' => array_keys( self::KEYS ),
			'capabilities' => [ 'canonical_url' => [ 'read', 'set', 'clear' ], 'noindex' => [ 'read', 'set_noindex' ], 'operation_restore' => true ],
			'version_support' => [ 'exact' => self::VERSION ],
		];
	}

	/** Permission checks precede every payload and recovery-record read. */
	private static function authorize( int $id ): void {
		$post = get_post( $id );
		if ( ! $post ) { throw new RuntimeException( 'not_found' ); }
		if ( ! current_user_can( 'edit_post', $id ) || ! current_user_can( 'rank_math_onpage_general' ) || ! current_user_can( 'rank_math_onpage_advanced' ) ) { throw new RuntimeException( 'forbidden' ); }
		$provider = self::discovery();
		if ( ! $provider['active'] ) { throw new RuntimeException( 'provider_absent' ); }
		if ( ! $provider['readable'] ) { throw new RuntimeException( 'provider_incompatible' ); }
		if ( 'page' !== $post->post_type || ! in_array( $post->post_status, [ 'publish', 'draft', 'pending', 'private', 'future' ], true ) || ! \RankMath\Helper::is_post_type_accessible( 'page' ) ) { throw new RuntimeException( 'post_type_unsupported' ); }
	}

	private static function rows( int $id ): array {
		$rows = [];
		foreach ( self::KEYS as $field => $key ) { $rows[ $field ] = array_values( get_post_meta( $id, $key, false ) ); }
		self::validate_rows( $rows );
		return $rows;
	}

	private static function validate_rows( array $rows ): void {
		if ( array_keys( $rows ) !== array_keys( self::KEYS ) || count( $rows['canonical_url'] ) > 1 || count( $rows['noindex'] ) > 1 ) { throw new RuntimeException( 'stored_shape_unsupported' ); }
		if ( $rows['canonical_url'] && ! is_string( $rows['canonical_url'][0] ) ) { throw new RuntimeException( 'stored_shape_unsupported' ); }
		if ( $rows['noindex'] ) { self::directives( $rows['noindex'][0] ); }
	}

	private static function directives( $value ): array {
		if ( ! is_array( $value ) || array_values( $value ) !== $value || count( $value ) !== count( array_unique( $value, SORT_REGULAR ) ) ) { throw new RuntimeException( 'stored_shape_unsupported' ); }
		foreach ( $value as $directive ) {
			if ( ! is_string( $directive ) || ! in_array( $directive, self::DIRECTIVES, true ) ) { throw new RuntimeException( 'stored_shape_unsupported' ); }
		}
		if ( in_array( 'index', $value, true ) && in_array( 'noindex', $value, true ) ) { throw new RuntimeException( 'stored_shape_unsupported' ); }
		return $value;
	}

	private static function context( int $id ): array {
		$post = get_post( $id );
		return [
			'site' => home_url( '/' ), 'post_id' => $id, 'version' => self::VERSION,
			'permalink' => get_permalink( $id ), 'post_status' => $post->post_status, 'password_protected' => ! empty( $post->post_password ),
			'blog_public' => get_option( 'blog_public' ), 'show_on_front' => get_option( 'show_on_front' ), 'page_on_front' => get_option( 'page_on_front' ), 'page_for_posts' => get_option( 'page_for_posts' ),
			'custom_robots' => \RankMath\Helper::get_settings( 'titles.pt_page_custom_robots' ),
			'post_type_robots' => \RankMath\Helper::get_settings( 'titles.pt_page_robots' ),
			'global_robots' => \RankMath\Helper::get_settings( 'titles.robots_global' ),
			'noindex_password_protected' => \RankMath\Helper::get_settings( 'titles.noindex_password_protected' ),
			'noindex_paginated_pages' => \RankMath\Helper::get_settings( 'titles.noindex_paginated_pages' ),
		];
	}

	private static function digest( array $rows, array $context ): string {
		$json = wp_json_encode( [ 'rank_math_v1', $rows, $context ] );
		if ( ! is_string( $json ) ) { throw new RuntimeException( 'stored_shape_unsupported' ); }
		return 'sha256:' . hash( 'sha256', $json );
	}

	/** Configured inheritance only: never pin runtime restrictions or filters. */
	private static function configured_robots( array $rows, array $context ): array {
		if ( ! empty( $rows['noindex'][0] ) ) { return [ 'source' => 'explicit', 'directives' => self::directives( $rows['noindex'][0] ) ]; }
		if ( $context['custom_robots'] ) {
			$value = $context['post_type_robots'];
			// Rank Math robots_combine(..., true) supplies index/follow for an empty default.
			return [ 'source' => 'post_type', 'directives' => empty( $value ) ? [ 'index' ] : self::directives( $value ) ];
		}
		return [ 'source' => 'global', 'directives' => empty( $context['global_robots'] ) ? [ 'index' ] : self::directives( $context['global_robots'] ) ];
	}

	private static function fields( array $rows, array $context ): array {
		$robots = self::configured_robots( $rows, $context );
		return [
			'canonical_url' => [ 'explicit' => ! empty( $rows['canonical_url'] ), 'value' => $rows['canonical_url'][0] ?? null ],
			'noindex' => [ 'explicit' => ! empty( $rows['noindex'] ), 'mode' => $robots['source'], 'configured' => in_array( 'noindex', $robots['directives'], true ), 'directives' => $robots['directives'] ],
		];
	}

	public static function read( int $id ): array {
		try {
			self::authorize( $id );
			$rows = self::rows( $id ); $context = self::context( $id );
			$effective = ( new \RankMath\Paper\Singular() )->get_seo_meta( $id );
			return [ 'ok' => true, 'post_id' => $id, 'provider' => self::discovery(), 'checksum' => self::digest( $rows, $context ), 'fields' => self::fields( $rows, $context ),
				'computed' => [ 'canonical_url' => $effective['canonical'] ?? null, 'robots' => $effective['robots'] ?? null, 'source' => 'rank_math_singular_api', 'frontend_verified' => false, 'limits' => 'REST context; frontend filters/pagination may differ. Rank Math suppresses canonical output under noindex.' ],
				'cache' => 'read_only' ];
		} catch ( Throwable $error ) { return self::failure( $error ); }
	}

	private static function plan( $changes, array $before, array $context ): array {
		if ( ! is_array( $changes ) || array_values( $changes ) !== $changes || count( $changes ) < 1 || count( $changes ) > 2 ) { throw new RuntimeException( 'invalid_input' ); }
		$after = $before; $seen = []; $pinned = false;
		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) || ! isset( $change['field'], $change['action'] ) || ! is_string( $change['field'] ) || isset( $seen[ $change['field'] ] ) ) { throw new RuntimeException( 'invalid_input' ); }
			$field = $change['field']; $action = $change['action'];
			if ( ! isset( self::KEYS[ $field ] ) ) { throw new RuntimeException( 'field_unsupported' ); }
			$allowed = 'set' === $action ? [ 'field', 'action', 'value' ] : [ 'field', 'action' ];
			if ( array_diff( array_keys( $change ), $allowed ) || ! in_array( $action, [ 'set', 'clear' ], true ) ) { throw new RuntimeException( 'invalid_input' ); }
			$seen[ $field ] = true;
			if ( 'noindex' === $field ) {
				if ( 'set' !== $action || ( $change['value'] ?? null ) !== 'noindex' ) { throw new RuntimeException( 'field_operation_unsupported' ); }
				$robots = self::configured_robots( $before, $context );
				$pinned = 'explicit' !== $robots['source'];
				$directives = array_values( array_diff( $robots['directives'], [ 'index', 'noindex' ] ) );
				$directives[] = 'noindex';
				// Preserve the original row/order on an already-explicit no-op.
				$after[ $field ] = ! $pinned && in_array( 'noindex', $robots['directives'], true ) ? $before[ $field ] : [ $directives ];
			} elseif ( 'clear' === $action ) { $after[ $field ] = []; }
			else {
				$value = $change['value'] ?? null;
				if ( ! is_string( $value ) || strlen( $value ) > 2048 || ! preg_match( '//u', $value ) || preg_match( '/[\x00-\x20\x7f]/', $value ) || ! preg_match( '~\Ahttps?://~i', $value ) ) { throw new RuntimeException( 'invalid_url' ); }
				$parts = wp_parse_url( $value );
				if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || \RankMath\Rest\Sanitize::get()->sanitize( self::KEYS[ $field ], $value ) !== $value ) { throw new RuntimeException( 'invalid_url' ); }
				$after[ $field ] = [ $value ];
			}
		}
		return [ 'after' => $after, 'inherited_directives_pinned' => $pinned ];
	}

	/** Restore is explicit in the same tool, never in the content-snapshot API. */
	public static function update( int $id, $request ): array {
		$lock = ''; $record = null; $mutation = false;
		try {
			self::authorize( $id );
			$expected = $request->get_param( 'expected_checksum' );
			if ( ! is_string( $expected ) || ! preg_match( '/\Asha256:[a-f0-9]{64}\z/', $expected ) ) { throw new RuntimeException( 'invalid_input' ); }
			$before = self::rows( $id ); $context = self::context( $id );
			if ( ! hash_equals( self::digest( $before, $context ), $expected ) ) { throw new RuntimeException( 'metadata_drift' ); }
			$restore_id = $request->get_param( 'restore_snapshot_id' );
			$source = null;
			if ( null !== $restore_id ) {
				if ( null !== $request->get_param( 'changes' ) || ! is_string( $restore_id ) || ! preg_match( '/\Arm_[a-f0-9-]{36}\z/', $restore_id ) ) { throw new RuntimeException( 'invalid_input' ); }
				$source = get_option( self::RECOVERY_PREFIX . $restore_id );
				if ( ! is_array( $source ) || ( $source['post_id'] ?? 0 ) !== $id || ( $source['provider_version'] ?? '' ) !== self::VERSION || ( $source['site'] ?? '' ) !== home_url( '/' ) || ( $source['schema'] ?? 0 ) !== 1 ) { throw new RuntimeException( 'snapshot_unavailable' ); }
				self::validate_rows( $source['before'] ); self::validate_rows( $source['after'] );
				$after = $before;
				foreach ( self::KEYS as $field => $key ) {
					if ( $source['before'][ $field ] === $source['after'][ $field ] ) { continue; }
					if ( $before[ $field ] !== $source['after'][ $field ] && $before[ $field ] !== $source['before'][ $field ] ) { throw new RuntimeException( 'recovery_conflict' ); }
					$after[ $field ] = $source['before'][ $field ];
				}
				$plan = [ 'after' => $after, 'inherited_directives_pinned' => false ];
			} else { $plan = self::plan( $request->get_param( 'changes' ), $before, $context ); }
			$after = $plan['after']; $noop = $after === $before;
			$edits = [];
			foreach ( self::KEYS as $field => $key ) {
				if ( $before[ $field ] !== $after[ $field ] ) { $edits[] = [ 'kind' => 'seo_metadata.' . $field, 'before' => self::fields( $before, $context )[ $field ], 'after' => self::fields( $after, $context )[ $field ] ]; }
			}
			$preview = [ 'changes' => $edits, 'summary' => $source ? 'Restore recorded SEO fields; preserve untouched fields.' : 'Update canonical/noindex on one Rank Math page.', 'before' => self::fields( $before, $context ), 'after' => self::fields( $after, $context ), 'inherited_directives_pinned' => $plan['inherited_directives_pinned'], 'noop' => $noop, 'frontend_verified' => false, 'canonical_under_noindex' => 'suppressed_by_rank_math_frontend' ];
			if ( rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false ) || $noop ) { return [ 'ok' => true, 'dry_run' => rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false ), 'noop' => $noop, 'plan' => $preview, 'checksum' => $expected, 'proposed_checksum' => self::digest( $after, $context ), 'write_applied' => false ]; }
			$lock = 'diviops_seo_lock_' . $id;
			if ( ! add_option( $lock, wp_generate_uuid4(), '', 'no' ) ) { $lock = ''; throw new RuntimeException( 'busy' ); }
			if ( self::rows( $id ) !== $before || self::context( $id ) !== $context ) { throw new RuntimeException( 'metadata_drift' ); }
			$record = [ 'schema' => 1, 'snapshot_id' => 'rm_' . wp_generate_uuid4(), 'site' => home_url( '/' ), 'post_id' => $id, 'provider_version' => self::VERSION, 'before' => $before, 'after' => $after, 'created_at' => gmdate( 'c' ), 'status' => 'prepared' ];
			self::save_record( $record );
			if ( self::rows( $id ) !== $before || self::context( $id ) !== $context ) { throw new RuntimeException( 'metadata_drift' ); }
			$mutation = true;
			try {
				self::write_transition( $id, $before, $after );
				self::invalidate( $id );
				if ( self::rows( $id ) !== $after || self::context( $id ) !== $context ) { throw new RuntimeException( 'readback_mismatch' ); }
			} catch ( Throwable $error ) {
				$recovered = self::recover( $id, $before, $after );
				$record['status'] = $recovered ? 'recovered' : 'recovery_required';
				try { self::save_record( $record ); } catch ( Throwable $ignored ) { /* Original prepared record remains usable. */ }
				return [ 'ok' => false, 'error' => 'write_failed', 'snapshot_id' => $record['snapshot_id'], 'recovered' => $recovered, 'mutation_possible' => true ];
			}
			$record['status'] = 'applied'; self::save_record( $record );
			return [ 'ok' => true, 'post_id' => $id, 'provider' => 'rank_math', 'snapshot_id' => $record['snapshot_id'], 'before_checksum' => $expected, 'after_checksum' => self::digest( $after, $context ), 'checksum' => self::digest( $after, $context ), 'fields' => self::fields( $after, $context ), 'write_applied' => true, 'readback_verified' => true, 'frontend_verified' => false, 'cache' => 'provider_sitemap_invalidation_requested' ];
		} catch ( Throwable $error ) {
			return array_merge( self::failure( $error ), [ 'snapshot_id' => $record['snapshot_id'] ?? null, 'mutation_possible' => $mutation ] );
		} finally { if ( $lock ) { delete_option( $lock ); } }
	}

	private static function write_transition( int $id, array $from, array $to ): void {
		foreach ( self::KEYS as $field => $key ) {
			if ( $from[ $field ] === $to[ $field ] ) { continue; }
			if ( array_values( get_post_meta( $id, $key, false ) ) !== $from[ $field ] ) { throw new RuntimeException( 'recovery_conflict' ); }
			if ( ! $to[ $field ] ) { delete_post_meta( $id, $key ); }
			else { update_post_meta( $id, $key, wp_slash( $to[ $field ][0] ) ); }
			if ( array_values( get_post_meta( $id, $key, false ) ) !== $to[ $field ] ) { throw new RuntimeException( 'readback_mismatch' ); }
		}
	}

	private static function recover( int $id, array $before, array $after ): bool {
		try {
			$current = self::rows( $id ); $target = $current;
			foreach ( self::KEYS as $field => $key ) {
				if ( $before[ $field ] === $after[ $field ] ) { continue; }
				if ( $current[ $field ] !== $before[ $field ] && $current[ $field ] !== $after[ $field ] ) { return false; }
				$target[ $field ] = $before[ $field ];
			}
			self::write_transition( $id, $current, $target ); self::invalidate( $id );
			return self::rows( $id ) === $target;
		} catch ( Throwable $error ) { return false; }
	}

	private static function invalidate( int $id ): void {
		\RankMath\Sitemap\Cache_Watcher::invalidate_object_type( 'post', $id );
		\RankMath\Sitemap\Cache_Watcher::clear_queued();
	}
	private static function save_record( array $record ): void {
		$key = self::RECOVERY_PREFIX . $record['snapshot_id'];
		update_option( $key, $record, false );
		if ( get_option( $key ) !== $record ) { throw new RuntimeException( 'snapshot_failed' ); }
	}
	private static function failure( Throwable $error ): array {
		$known = [ 'not_found', 'forbidden', 'provider_absent', 'provider_incompatible', 'post_type_unsupported', 'stored_shape_unsupported', 'invalid_input', 'invalid_url', 'field_unsupported', 'field_operation_unsupported', 'metadata_drift', 'snapshot_unavailable', 'snapshot_failed', 'recovery_conflict', 'busy' ];
		return [ 'ok' => false, 'error' => in_array( $error->getMessage(), $known, true ) ? $error->getMessage() : 'provider_runtime_error' ];
	}
}
