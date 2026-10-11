<?php
/** Persistent recovery for TSF text operations; the legacy writer remains authoritative. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DiviOps_SEO_TSF_Text_Recovery {
	const PREFIX = 'diviops_seo_tsf_text_';
	const KEYS = [ 'seo_title' => '_genesis_title', 'meta_description' => '_genesis_description' ];

	private static function context( int $id, string $version ): array {
		return [ 'site' => home_url( '/' ), 'post_id' => $id, 'post_type' => get_post( $id )->post_type, 'version' => $version ];
	}

	private static function validate_rows( array $rows ): void {
		foreach ( self::KEYS as $key ) {
			if ( ! isset( $rows[ $key ] ) || ! is_array( $rows[ $key ] ) || array_values( $rows[ $key ] ) !== $rows[ $key ] ) { throw new RuntimeException( 'stored_shape_unsupported' ); }
			foreach ( $rows[ $key ] as $value ) { if ( ! is_string( $value ) ) { throw new RuntimeException( 'stored_shape_unsupported' ); } }
		}
	}

	/** Snapshots can restore only text fields actually changed by the recorded operation. */
	public static function restore_target( array $current, array $before, array $after ): array {
		self::validate_rows( $current ); self::validate_rows( $before ); self::validate_rows( $after );
		if ( array_keys( $current ) !== array_keys( $before ) || array_keys( $before ) !== array_keys( $after ) ) { throw new RuntimeException( 'snapshot_unavailable' ); }
		$target = $current;
		foreach ( $before as $key => $rows ) {
			if ( $rows === $after[ $key ] ) { continue; }
			if ( ! in_array( $key, self::KEYS, true ) ) { throw new RuntimeException( 'snapshot_unavailable' ); }
			if ( $current[ $key ] !== $rows && $current[ $key ] !== $after[ $key ] ) { throw new RuntimeException( 'recovery_conflict' ); }
			$target[ $key ] = $rows;
		}
		return $target;
	}

	public static function target( int $id, string $version, $snapshot_id, array $current ): array {
		if ( ! is_string( $snapshot_id ) || ! preg_match( '/\Atsft_[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/', $snapshot_id ) ) { throw new RuntimeException( 'invalid_input' ); }
		$source = get_option( self::PREFIX . $snapshot_id );
		if ( ! is_array( $source ) || ( $source['schema'] ?? null ) !== 1 || ( $source['type'] ?? null ) !== 'tsf_text'
			|| ( $source['snapshot_id'] ?? null ) !== $snapshot_id || ( $source['context'] ?? null ) !== self::context( $id, $version )
			|| ! in_array( $source['status'] ?? null, [ 'prepared', 'applied', 'recovered', 'recovery_required' ], true )
			|| ! is_array( $source['before'] ?? null ) || ! is_array( $source['after'] ?? null ) ) { throw new RuntimeException( 'snapshot_unavailable' ); }
		return self::restore_target( $current, $source['before'], $source['after'] );
	}

	/** Exact restoration bypasses sanitization so empty rows and old templates survive. */
	public static function write_exact( int $id, array $before, array $after ): void {
		foreach ( self::KEYS as $key ) {
			if ( $before[ $key ] === $after[ $key ] ) { continue; }
			if ( array_values( get_post_meta( $id, $key, false ) ) !== $before[ $key ] ) { throw new RuntimeException( 'recovery_conflict' ); }
			delete_post_meta( $id, $key );
			foreach ( $after[ $key ] as $value ) { add_post_meta( $id, $key, wp_slash( $value ) ); }
			if ( array_values( get_post_meta( $id, $key, false ) ) !== $after[ $key ] ) { throw new RuntimeException( 'readback_mismatch' ); }
		}
		tsf()->data()->plugin()->post()->refresh_static_properties();
	}

	private static function save_record( array $record, bool $initial = false ): void {
		$key = self::PREFIX . $record['snapshot_id'];
		if ( $initial ) {
			if ( ! add_option( $key, $record, '', 'no' ) ) { throw new RuntimeException( 'snapshot_failed' ); }
		} else { update_option( $key, $record, false ); }
		if ( get_option( $key ) !== $record ) { throw new RuntimeException( 'snapshot_failed' ); }
	}

	/** Runs only non-noop mutations. No new checksum semantics or automatic replay. */
	public static function execute( int $id, string $version, array $before, array $after, bool $restore, callable $apply, callable $failure, ?array $operation = null, $expected_context = null ) {
		$lock = ''; $record = null; $mutation = false;
		try {
			self::validate_rows( $before ); self::validate_rows( $after );
			$context = self::context( $id, $version );
			$lock = 'diviops_seo_lock_' . $id;
			if ( ! add_option( $lock, wp_generate_uuid4(), '', 'no' ) ) { $lock = ''; throw new RuntimeException( 'busy' ); }
			if ( $operation && ( $previous = DiviOps_SEO_Operation::find( $operation ) ) ) { return $failure( $previous ); }
			if ( DiviOps_SEO_TSF_Adapter::read_registered_state( $id ) !== $before ) { throw new RuntimeException( 'metadata_drift' ); }
			DiviOps_SEO_Operation::check_text_context( $id, 'tsf', $before, [], $expected_context );
			$record = [ 'schema' => 1, 'type' => 'tsf_text', 'snapshot_id' => 'tsft_' . wp_generate_uuid4(), 'context' => $context,
				'before' => $before, 'after' => $after, 'created_at' => gmdate( 'c' ), 'status' => 'prepared' ];
			if ( $operation ) { $record['snapshot_id'] = DiviOps_SEO_Operation::snapshot_id( $operation ); $record['operation'] = $operation; }
			self::save_record( $record, true );
			if ( DiviOps_SEO_TSF_Adapter::read_registered_state( $id ) !== $before || self::context( $id, $version ) !== $context ) { throw new RuntimeException( 'metadata_drift' ); }
			DiviOps_SEO_Operation::check_text_context( $id, 'tsf', $before, [], $expected_context );
			$mutation = true;
			$response = $apply();
			$body = $response->get_data();
			$record['status'] = $body['ok'] ? 'applied' : ( DiviOps_SEO_TSF_Adapter::read_registered_state( $id ) === $before ? 'recovered' : 'recovery_required' );
			self::save_record( $record );
			if ( $body['ok'] ) { $body['data']['snapshot_id'] = $record['snapshot_id']; }
			else { $body['error']['data']['snapshot_id'] = $record['snapshot_id']; }
			$response->set_data( $body );
			return $response;
		} catch ( Throwable $error ) {
			// The legacy writer handles its own immediate full-state rollback. Exact restores
			// recover only their owned rows and must never overwrite a later sibling edit.
			if ( $mutation && $restore && 'prepared' === $record['status'] ) {
				try {
					$current = DiviOps_SEO_TSF_Adapter::read_registered_state( $id );
					self::write_exact( $id, $current, self::restore_target( $current, $before, $after ) );
				} catch ( Throwable $ignored ) { /* Retain the snapshot for explicit recovery. */ }
			}
			if ( $record && 'prepared' === $record['status'] && $mutation ) {
				try {
					$record['status'] = DiviOps_SEO_TSF_Adapter::read_registered_state( $id ) === $before ? 'recovered' : 'recovery_required';
					self::save_record( $record );
				} catch ( Throwable $ignored ) { /* Durable prepared record remains available. */ }
			}
			$known = [ 'busy', 'metadata_drift', 'snapshot_failed', 'stored_shape_unsupported', 'recovery_conflict', 'readback_mismatch', 'operation_conflict', 'operation_unavailable' ];
			return $failure( [ 'ok' => false, 'error' => 'readback_mismatch' === $error->getMessage() ? 'write_failed' : ( in_array( $error->getMessage(), $known, true ) ? $error->getMessage() : 'provider_runtime_error' ),
				'snapshot_id' => $record['snapshot_id'] ?? null, 'mutation_possible' => $mutation ] );
		} finally { if ( $lock ) { delete_option( $lock ); } }
	}
}
