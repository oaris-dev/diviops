<?php
/** TSF 5.1.4 canonical/noindex operations, isolated from the existing text contract. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DiviOps_SEO_TSF_Indexing {
	const VERSION = '5.1.4';
	const RECOVERY_PREFIX = 'diviops_seo_tsf_';
	private const KEYS = [ 'canonical_url' => '_genesis_canonical_uri', 'noindex' => '_genesis_noindex' ];

	public static function ready(): bool {
		if ( ! defined( 'THE_SEO_FRAMEWORK_VERSION' ) || self::VERSION !== THE_SEO_FRAMEWORK_VERSION || ! function_exists( 'tsf' ) ) { return false; }
		try {
			return is_callable( [ tsf()->data()->plugin()->post(), 'save_meta' ] )
				&& is_callable( [ tsf()->data()->plugin(), 'get_options' ] )
				&& is_callable( [ tsf()->uri(), 'get_custom_canonical_url' ] )
				&& is_callable( [ tsf()->uri(), 'get_canonical_url' ] )
				&& is_callable( [ tsf()->robots(), 'get_generated_meta' ] )
				&& is_callable( [ tsf()->sitemap()->cache(), 'clear_sitemap_caches' ] );
		} catch ( Throwable $error ) { return false; }
	}

	private static function authorize( int $id ): void {
		$post = get_post( $id );
		if ( ! $post ) { throw new RuntimeException( 'not_found' ); }
		if ( ! current_user_can( 'edit_post', $id ) ) { throw new RuntimeException( 'forbidden' ); }
		$provider = DiviOps_SEO_TSF_Adapter::discovery();
		if ( ! $provider['active'] || ! $provider['readable'] || ! self::ready() ) { throw new RuntimeException( 'provider_incompatible' ); }
		if ( 'page' !== $post->post_type || ! in_array( $post->post_status, [ 'publish', 'draft', 'pending', 'private', 'future' ], true ) || ! DiviOps_SEO_TSF_Adapter::supports_post_type( 'page' ) ) { throw new RuntimeException( 'post_type_unsupported' ); }
	}

	private static function rows( int $id ): array {
		$rows = DiviOps_SEO_TSF_Adapter::read_registered_state( $id );
		foreach ( self::KEYS as $field => $key ) {
			if ( ! isset( $rows[ $key ] ) || count( $rows[ $key ] ) > 1 ) { throw new RuntimeException( 'stored_shape_unsupported' ); }
			if ( $rows[ $key ] && ( ! is_string( $rows[ $key ][0] ) || ( 'noindex' === $field && ! in_array( $rows[ $key ][0], [ '-1', '0', '1' ], true ) ) ) ) { throw new RuntimeException( 'stored_shape_unsupported' ); }
		}
		return $rows;
	}

	private static function context( int $id ): array {
		$post = get_post( $id );
		return [
			'site' => home_url( '/' ), 'post_id' => $id, 'version' => self::VERSION,
			'permalink' => get_permalink( $id ), 'status' => $post->post_status, 'password_protected' => ! empty( $post->post_password ),
			'blog_public' => get_option( 'blog_public' ), 'show_on_front' => get_option( 'show_on_front' ), 'page_on_front' => get_option( 'page_on_front' ), 'page_for_posts' => get_option( 'page_for_posts' ),
			// Bind raw settings as well as provider-resolved defaults, including headless/filter context.
			'raw_options' => defined( 'THE_SEO_FRAMEWORK_SITE_OPTIONS' ) ? get_option( THE_SEO_FRAMEWORK_SITE_OPTIONS ) : null,
			'options' => tsf()->data()->plugin()->get_options(),
		];
	}

	private static function digest( array $rows, array $context ): string {
		$json = wp_json_encode( [ 'tsf_indexing_v1', $rows, $context ] );
		if ( ! is_string( $json ) ) { throw new RuntimeException( 'stored_shape_unsupported' ); }
		return 'sha256:' . hash( 'sha256', $json );
	}

	private static function fields( array $rows ): array {
		$value = $rows[ self::KEYS['noindex'] ][0] ?? null;
		return [
			'canonical_url' => [ 'explicit' => (bool) $rows[ self::KEYS['canonical_url'] ], 'value' => $rows[ self::KEYS['canonical_url'] ][0] ?? null ],
			'noindex' => [ 'explicit' => null !== $value, 'value' => $value, 'mode' => '1' === $value ? 'noindex' : ( '-1' === $value ? 'force_index' : 'provider_default' ) ],
		];
	}

	public static function read( int $id ): array {
		try {
			self::authorize( $id );
			$rows = self::rows( $id ); $context = self::context( $id );
			return [ 'ok' => true, 'checksum' => self::digest( $rows, $context ), 'fields' => self::fields( $rows ),
				'computed' => [ 'canonical_url' => tsf()->uri()->get_canonical_url( [ 'id' => $id ] ), 'custom_canonical_url' => tsf()->uri()->get_custom_canonical_url( [ 'id' => $id ] ), 'robots' => tsf()->robots()->get_generated_meta( [ 'id' => $id ] ), 'source' => 'tsf_explicit_id_api', 'frontend_verified' => false ],
				'limits' => 'Frontend query and filters may differ. TSF retains a custom canonical under noindex; only generated fallback is suppressed. Homepage canonical may override the page value.',
			];
		} catch ( Throwable $error ) { return self::failure( $error ); }
	}

	private static function plan( $changes, array $before ): array {
		if ( ! is_array( $changes ) || array_values( $changes ) !== $changes || count( $changes ) < 1 || count( $changes ) > 2 ) { throw new RuntimeException( 'invalid_input' ); }
		$after = $before; $seen = [];
		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) || ! isset( $change['field'], $change['action'] ) || ! is_string( $change['field'] ) || isset( $seen[ $change['field'] ] ) ) { throw new RuntimeException( 'invalid_input' ); }
			$field = $change['field']; $action = $change['action'];
			if ( ! isset( self::KEYS[ $field ] ) ) { throw new RuntimeException( 'field_unsupported' ); }
			if ( array_diff( array_keys( $change ), 'set' === $action ? [ 'field', 'action', 'value' ] : [ 'field', 'action' ] ) ) { throw new RuntimeException( 'invalid_input' ); }
			$seen[ $field ] = true; $key = self::KEYS[ $field ];
			if ( 'noindex' === $field ) {
				if ( 'reset_default' === $action ) { $after[ $key ] = []; }
				elseif ( 'set' === $action && ( $change['value'] ?? null ) === 'noindex' ) { $after[ $key ] = [ '1' ]; }
				else { throw new RuntimeException( 'field_operation_unsupported' ); }
			} elseif ( 'clear' === $action ) { $after[ $key ] = []; }
			elseif ( 'set' === $action ) {
				$value = $change['value'] ?? null;
				if ( ! is_string( $value ) || strlen( $value ) > 2048 || ! preg_match( '//u', $value ) || preg_match( '/[\x00-\x20\x7f]/', $value ) || ! preg_match( '~\Ahttps?://~i', $value ) ) { throw new RuntimeException( 'invalid_url' ); }
				$parts = wp_parse_url( $value );
				if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || sanitize_url( $value, [ 'https', 'http' ] ) !== $value ) { throw new RuntimeException( 'invalid_url' ); }
				$after[ $key ] = [ $value ];
			} else { throw new RuntimeException( 'field_operation_unsupported' ); }
		}
		return $after;
	}

	/** Restore only operation-owned fields; never turn a snapshot into an arbitrary meta writer. */
	private static function restore_target( array $current, array $before, array $after ): array {
		if ( array_keys( $current ) !== array_keys( $before ) || array_keys( $before ) !== array_keys( $after ) ) { throw new RuntimeException( 'recovery_conflict' ); }
		$target = $current;
		foreach ( self::KEYS as $key ) {
			if ( $before[ $key ] === $after[ $key ] ) { continue; }
			if ( $current[ $key ] !== $before[ $key ] && $current[ $key ] !== $after[ $key ] ) { throw new RuntimeException( 'recovery_conflict' ); }
			$target[ $key ] = $before[ $key ];
		}
		return $target;
	}

	public static function update( int $id, $request ): array {
		$lock = ''; $record = null; $mutation = false;
		try {
			self::authorize( $id );
			$expected = $request->get_param( 'expected_checksum' );
			if ( ! is_string( $expected ) || ! preg_match( '/\Asha256:[a-f0-9]{64}\z/', $expected ) ) { throw new RuntimeException( 'invalid_input' ); }
			$before = self::rows( $id ); $context = self::context( $id );
			if ( ! hash_equals( self::digest( $before, $context ), $expected ) ) { throw new RuntimeException( 'metadata_drift' ); }
			$restore_id = $request->get_param( 'restore_snapshot_id' );
			if ( null !== $restore_id ) {
				if ( null !== $request->get_param( 'changes' ) || ! is_string( $restore_id ) || ! preg_match( '/\Atsf_[a-f0-9-]{36}\z/', $restore_id ) ) { throw new RuntimeException( 'invalid_input' ); }
				$source = get_option( self::RECOVERY_PREFIX . $restore_id );
				if ( ! is_array( $source ) || ( $source['post_id'] ?? 0 ) !== $id || ( $source['version'] ?? '' ) !== self::VERSION || ( $source['site'] ?? '' ) !== home_url( '/' ) || ( $source['schema'] ?? 0 ) !== 1 ) { throw new RuntimeException( 'snapshot_unavailable' ); }
				$after = self::restore_target( $before, $source['before'], $source['after'] );
			} else { $after = self::plan( $request->get_param( 'changes' ), $before ); }
			$noop = $before === $after;
			if ( ! $restore_id && ! $noop ) {
				// Refuse known native normalization before risking unrelated exact rows.
				$defaults = tsf()->data()->plugin()->post()->get_default_meta( $id );
				foreach ( $before as $key => $values ) {
					if ( $before[ $key ] !== $after[ $key ] ) { continue; }
					if ( count( $values ) > 1 && count( array_unique( $values, SORT_REGULAR ) ) > 1 ) { throw new RuntimeException( 'stored_shape_unsupported' ); }
					if ( $values && ( '' === $values[0] || ( 0 === ( $defaults[ $key ] ?? null ) && '0' === $values[0] ) ) ) { throw new RuntimeException( 'stored_shape_unsupported' ); }
				}
			}
			$edits = [];
			foreach ( self::KEYS as $field => $key ) { if ( $before[ $key ] !== $after[ $key ] ) { $edits[] = [ 'kind' => 'seo_metadata.' . $field, 'before' => self::fields( $before )[ $field ], 'after' => self::fields( $after )[ $field ] ]; } }
			$preview = [ 'changes' => $edits, 'summary' => $restore_id ? 'Restore recorded TSF indexing fields; preserve untouched fields.' : 'Update canonical/noindex on one TSF page.', 'before' => self::fields( $before ), 'after' => self::fields( $after ), 'inherited_directives_pinned' => false, 'canonical_shadowed_by_homepage' => 'page' === $context['show_on_front'] && (int) $context['page_on_front'] === $id && ! empty( $context['options']['homepage_canonical'] ), 'canonical_under_noindex' => 'custom_retained_generated_fallback_suppressed', 'frontend_verified' => false ];
			$dry = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false );
			if ( $dry || $noop ) { return [ 'ok' => true, 'dry_run' => $dry, 'noop' => $noop, 'plan' => $preview, 'checksum' => $expected, 'proposed_checksum' => self::digest( $after, $context ), 'write_applied' => false ]; }
			$lock = 'diviops_seo_lock_' . $id;
			if ( ! add_option( $lock, wp_generate_uuid4(), '', 'no' ) ) { $lock = ''; throw new RuntimeException( 'busy' ); }
			if ( self::rows( $id ) !== $before || self::context( $id ) !== $context ) { throw new RuntimeException( 'metadata_drift' ); }
			$record = [ 'schema' => 1, 'snapshot_id' => 'tsf_' . wp_generate_uuid4(), 'site' => home_url( '/' ), 'post_id' => $id, 'version' => self::VERSION, 'before' => $before, 'after' => $after, 'created_at' => gmdate( 'c' ), 'status' => 'prepared' ];
			self::save_record( $record );
			if ( self::rows( $id ) !== $before || self::context( $id ) !== $context ) { throw new RuntimeException( 'metadata_drift' ); }
			$mutation = true;
			try {
				if ( $restore_id ) { self::write_exact( $id, $before, $after ); }
				else {
					// One native save, using all current registered values; never seed inherited robots defaults.
					$data = [];
					foreach ( $after as $key => $values ) { $data[ $key ] = $values[0] ?? $defaults[ $key ]; }
					tsf()->data()->plugin()->post()->save_meta( $id, $data );
				}
				self::invalidate();
				if ( self::rows( $id ) !== $after || self::context( $id ) !== $context ) { throw new RuntimeException( 'readback_mismatch' ); }
			} catch ( Throwable $error ) {
				$recovered = false;
				try {
					$current = self::rows( $id );
					$target = self::restore_target( $current, $before, $after );
					self::write_exact( $id, $current, $target );
					// Collateral provider/filter changes are not attributed to this operation or overwritten.
					$recovered = self::rows( $id ) === $before;
				} catch ( Throwable $ignored ) { /* Conditional recovery requires operator attention. */ }
				try { self::invalidate(); } catch ( Throwable $ignored ) { $recovered = false; }
				$record['status'] = $recovered ? 'recovered' : 'recovery_required';
				try { self::save_record( $record ); } catch ( Throwable $ignored ) { /* Prepared record remains. */ }
				return [ 'ok' => false, 'error' => 'write_failed', 'snapshot_id' => $record['snapshot_id'], 'recovered' => $recovered, 'mutation_possible' => true ];
			}
			$record['status'] = 'applied'; self::save_record( $record );
			return [ 'ok' => true, 'snapshot_id' => $record['snapshot_id'], 'checksum' => self::digest( $after, $context ), 'fields' => self::fields( $after ), 'write_applied' => true, 'readback_verified' => true, 'frontend_verified' => false, 'cache' => 'provider_sitemap_invalidation_requested' ];
		} catch ( Throwable $error ) { return array_merge( self::failure( $error ), [ 'snapshot_id' => $record['snapshot_id'] ?? null, 'mutation_possible' => $mutation ] ); }
		finally { if ( $lock ) { delete_option( $lock ); } }
	}

	private static function write_exact( int $id, array $from, array $to ): void {
		foreach ( self::KEYS as $key ) {
			if ( $from[ $key ] === $to[ $key ] ) { continue; }
			if ( array_values( get_post_meta( $id, $key, false ) ) !== $from[ $key ] ) { throw new RuntimeException( 'recovery_conflict' ); }
			delete_post_meta( $id, $key );
			foreach ( $to[ $key ] as $value ) { add_post_meta( $id, $key, wp_slash( $value ) ); }
			if ( array_values( get_post_meta( $id, $key, false ) ) !== $to[ $key ] ) { throw new RuntimeException( 'readback_mismatch' ); }
		}
	}

	private static function invalidate(): void {
		tsf()->data()->plugin()->post()->refresh_static_properties();
		tsf()->sitemap()->cache()->clear_sitemap_caches();
	}
	private static function save_record( array $record ): void {
		$key = self::RECOVERY_PREFIX . $record['snapshot_id'];
		update_option( $key, $record, false );
		if ( get_option( $key ) !== $record ) { throw new RuntimeException( 'snapshot_failed' ); }
	}
	private static function failure( Throwable $error ): array {
		$known = [ 'not_found', 'forbidden', 'provider_incompatible', 'post_type_unsupported', 'stored_shape_unsupported', 'invalid_input', 'invalid_url', 'field_unsupported', 'field_operation_unsupported', 'metadata_drift', 'snapshot_unavailable', 'snapshot_failed', 'recovery_conflict', 'busy' ];
		return [ 'ok' => false, 'error' => in_array( $error->getMessage(), $known, true ) ? $error->getMessage() : 'provider_runtime_error' ];
	}
}
