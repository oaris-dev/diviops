<?php
/**
 * Rollback snapshot storage and read/delete tools.
 *
 * Typed storage inspection, cleanup, backup, and restore support.
 *
 * @package DiviOpsAgent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_Rollback {
	private static function rollback_snapshot_option_prefix(): string {
		return 'diviops_rollback_snapshot_';
	}

	private static function rollback_snapshot_created_stale_seconds(): int {
		$minute = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;
		return 15 * $minute;
	}

	private static function rollback_snapshot_validate_id( $snapshot_id ) {
		$snapshot_id = sanitize_text_field( (string) $snapshot_id );
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{2,127}$/', $snapshot_id ) ) {
			return false;
		}
		return $snapshot_id;
	}

	private static function rollback_snapshot_option_name( string $snapshot_id ): string {
		return self::rollback_snapshot_option_prefix() . $snapshot_id;
	}

	private static function rollback_snapshot_id_from_option_name( string $option_name ) {
		$prefix = self::rollback_snapshot_option_prefix();
		if ( 0 !== strpos( $option_name, $prefix ) ) {
			return false;
		}
		return self::rollback_snapshot_validate_id( substr( $option_name, strlen( $prefix ) ) );
	}

	private static function rollback_snapshot_current_user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	private static function rollback_snapshot_user_login(): ?string {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return null;
		}
		$user = wp_get_current_user();
		if ( ! is_object( $user ) || empty( $user->user_login ) ) {
			return null;
		}
		return (string) $user->user_login;
	}

	private static function rollback_snapshot_expiry_seconds(): int {
		$day = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		return 7 * $day;
	}

	private static function rollback_snapshot_requested( $request ): bool {
		$value = $request->get_param( 'backup' ) ?? false;
		if ( function_exists( 'rest_sanitize_boolean' ) ) {
			return rest_sanitize_boolean( $value );
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( (string) $value ), [ '1', 'true', 'yes', 'on' ], true );
	}

	private static function rollback_snapshot_now(): string {
		return gmdate( 'c' );
	}

	private static function rollback_snapshot_checksum( string $value ): string {
		return 'sha256:' . hash( 'sha256', $value );
	}

	private static function rollback_snapshot_generate_id( int $post_id, string $tool ): string {
		$seed = $tool . '|' . $post_id . '|' . microtime( true ) . '|' . wp_rand();
		return 'snap_' . gmdate( 'YmdHis' ) . '_' . substr( hash( 'sha256', $seed ), 0, 16 );
	}

	private static function rollback_snapshot_side_effect_meta_keys(): array {
		return [
			'_et_pb_use_builder',
			'_et_pb_use_divi_5',
			'_et_pb_page_layout',
			'_et_pb_built_for_post_type',
		];
	}

	private static function rollback_snapshot_capture_side_effects( int $post_id ): array {
		$post_meta = [];
		foreach ( self::rollback_snapshot_side_effect_meta_keys() as $key ) {
			$exists = function_exists( 'metadata_exists' )
				? metadata_exists( 'post', $post_id, $key )
				: '' !== (string) get_post_meta( $post_id, $key, true );
			$post_meta[ $key ] = [
				'exists' => (bool) $exists,
				'value'  => $exists ? get_post_meta( $post_id, $key, true ) : null,
			];
		}

		return [ 'post_meta' => $post_meta ];
	}

	private static function rollback_snapshot_target_from_post( $post ): array {
		return [
			'kind'      => 'post',
			'id'        => (int) $post->ID,
			'post_type' => sanitize_key( (string) ( $post->post_type ?? '' ) ),
		];
	}

	private static function rollback_snapshot_before_from_post( $post ): array {
		$value = (string) ( $post->post_content ?? '' );
		return [
			'checksum'     => self::rollback_snapshot_checksum( $value ),
			'byte_length'  => strlen( $value ),
			'value'        => $value,
			'side_effects' => self::rollback_snapshot_capture_side_effects( (int) $post->ID ),
		];
	}

	private static function rollback_snapshot_plan_for_post_write( $post, string $tool, array $operation ): array {
		$before = self::rollback_snapshot_before_from_post( $post );
		unset( $before['value'] );

		return [
			'requested'  => true,
			'created'    => false,
			'dry_run'    => true,
			'tool'       => $tool,
			'operation'  => (object) $operation,
			'target'     => self::rollback_snapshot_target_from_post( $post ),
			'before'     => $before,
			'created_at' => null,
			'expires_at' => null,
		];
	}

	private static function rollback_snapshot_noop_for_post_write( $post, string $tool, array $operation ): array {
		$plan = self::rollback_snapshot_plan_for_post_write( $post, $tool, $operation );
		$plan['dry_run'] = false;
		$plan['reason']  = 'noop';
		return $plan;
	}

	private static function rollback_snapshot_create_for_post_write( $post, string $tool, array $operation ) {
		$snapshot_id = self::rollback_snapshot_generate_id( (int) $post->ID, $tool );
		$created_at  = self::rollback_snapshot_now();
		$expires_at  = gmdate( 'c', time() + self::rollback_snapshot_expiry_seconds() );
		$record      = [
			'schema_version' => 1,
			'snapshot_id'    => $snapshot_id,
			'status'         => 'created',
			'created_at'     => $created_at,
			'expires_at'     => $expires_at,
			'created_by'     => [
				'user_id' => self::rollback_snapshot_current_user_id(),
				'login'   => self::rollback_snapshot_user_login(),
			],
			'tool'           => $tool,
			'operation'      => array_merge( [ 'dry_run' => false ], $operation ),
			'target'         => self::rollback_snapshot_target_from_post( $post ),
			'before'         => self::rollback_snapshot_before_from_post( $post ),
			'after'          => [
				'checksum'     => null,
				'byte_length'  => null,
				'side_effects' => null,
			],
			'restore'        => [ 'restorable' => true, 'restored_at' => null, 'restored_by' => null ],
			'cleanup'        => [ 'deleted_at' => null, 'deleted_by' => null ],
		];

		$option_name = self::rollback_snapshot_option_name( $snapshot_id );
		$added       = add_option( $option_name, $record, '', 'no' );
		if ( ! $added ) {
			return new WP_Error(
				'rollback_snapshot.storage_failed',
				'Could not create rollback snapshot before content write.',
				[
					'status' => 500,
					'hint'   => 'Retry after confirming the WordPress options table is writable.',
					'target' => self::rollback_snapshot_target_from_post( $post ),
				]
			);
		}

		return $record;
	}

	private static function rollback_snapshot_summary_for_record( array $record ): array {
		if ( array_key_exists( 'created', $record ) && false === $record['created'] && empty( $record['snapshot_id'] ) ) {
			$record['requested'] = true;
			return $record;
		}

		$summary = self::rollback_snapshot_normalize_record( $record, self::rollback_snapshot_option_name( (string) $record['snapshot_id'] ), $record );
		if ( null === $summary ) {
			return [
				'requested'   => true,
				'created'     => true,
				'snapshot_id' => (string) ( $record['snapshot_id'] ?? '' ),
				'status'      => (string) ( $record['status'] ?? '' ),
			];
		}
		$summary['requested'] = true;
		$summary['created']   = true;
		return $summary;
	}

	private static function rollback_snapshot_mark_post_write( array $record, string $status, ?string $after_content = null ): array {
		$record['status'] = $status;
		if ( null !== $after_content ) {
			$record['after'] = [
				'checksum'     => self::rollback_snapshot_checksum( $after_content ),
				'byte_length'  => strlen( $after_content ),
				'side_effects' => self::rollback_snapshot_capture_side_effects( (int) $record['target']['id'] ),
			];
		}
		update_option( self::rollback_snapshot_option_name( (string) $record['snapshot_id'] ), $record, false );
		return $record;
	}

	private static function rollback_snapshot_mark_from_write_error( array $record, $error ): array {
		$code   = is_object( $error ) && method_exists( $error, 'get_error_code' ) ? (string) $error->get_error_code() : '';
		$status = false !== strpos( $code, '.content_write_corruption' ) ? 'write_failed_restored' : 'aborted_before_write';
		$current = get_post( (int) $record['target']['id'] );
		$after   = $current && isset( $current->post_content ) ? (string) $current->post_content : null;
		return self::rollback_snapshot_mark_post_write( $record, $status, $after );
	}

	private static function rollback_snapshot_error_with_summary( $error, array $record ) {
		$data = is_object( $error ) && method_exists( $error, 'get_error_data' ) && is_array( $error->get_error_data() )
			? $error->get_error_data()
			: [];
		$data['backup'] = self::rollback_snapshot_summary_for_record( $record );
		return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	private static function rollback_snapshot_add_to_response( array $response, ?array $record ): array {
		if ( null !== $record ) {
			$response['backup'] = self::rollback_snapshot_summary_for_record( $record );
		}
		return $response;
	}

	private static function rollback_snapshot_created_by_current_user( array $record ): bool {
		$user_id = self::rollback_snapshot_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		$created_by = self::rollback_snapshot_as_array( $record['created_by'] ?? [] );
		return $user_id === (int) ( $created_by['user_id'] ?? 0 );
	}

	private static function rollback_snapshot_as_array( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			return get_object_vars( $value );
		}
		return [];
	}

	private static function rollback_snapshot_raw_byte_size( $raw_value, array $record ): int {
		if ( is_string( $raw_value ) ) {
			return strlen( $raw_value );
		}
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $record ) : json_encode( $record );
		return is_string( $json ) ? strlen( $json ) : 0;
	}

	private static function rollback_snapshot_normalize_record( array $record, string $option_name, $raw_value = null, ?string $autoload = null ) {
		$option_snapshot_id = self::rollback_snapshot_id_from_option_name( $option_name );
		$snapshot_id        = self::rollback_snapshot_validate_id( $record['snapshot_id'] ?? $option_snapshot_id );
		if ( false === $option_snapshot_id || false === $snapshot_id || $option_snapshot_id !== $snapshot_id ) {
			return null;
		}

		$target = self::rollback_snapshot_as_array( $record['target'] ?? [] );
		$target_id = absint( $target['id'] ?? 0 );
		if ( $target_id <= 0 ) {
			return null;
		}
		$target_kind = sanitize_key( (string) ( $target['kind'] ?? 'post' ) );
		if ( '' === $target_kind ) {
			$target_kind = 'post';
		}

		$status = sanitize_key( (string) ( $record['status'] ?? 'created' ) );
		if ( '' === $status ) {
			$status = 'created';
		}

		$created_at = sanitize_text_field( (string) ( $record['created_at'] ?? '' ) );
		$expires_at = sanitize_text_field( (string) ( $record['expires_at'] ?? '' ) );
		$created_ts = '' !== $created_at ? strtotime( $created_at ) : false;
		$expires_ts = '' !== $expires_at ? strtotime( $expires_at ) : false;
		$now = time();

		$created_by = self::rollback_snapshot_as_array( $record['created_by'] ?? [] );
		$before     = self::rollback_snapshot_as_array( $record['before'] ?? [] );
		$after      = self::rollback_snapshot_as_array( $record['after'] ?? [] );
		$restore    = self::rollback_snapshot_as_array( $record['restore'] ?? [] );
		$cleanup    = self::rollback_snapshot_as_array( $record['cleanup'] ?? [] );

		return [
			'schema_version' => absint( $record['schema_version'] ?? 1 ),
			'snapshot_id'    => $snapshot_id,
			'status'         => $status,
			'created_at'     => $created_at,
			'expires_at'     => $expires_at,
			'expired'        => false !== $expires_ts && $expires_ts < $now,
			'interrupted'    => 'created' === $status && false !== $created_ts && ( $now - $created_ts ) > self::rollback_snapshot_created_stale_seconds(),
			'created_by'     => [
				'user_id' => absint( $created_by['user_id'] ?? 0 ),
				'login'   => sanitize_text_field( (string) ( $created_by['login'] ?? '' ) ),
			],
			'tool'           => sanitize_key( (string) ( $record['tool'] ?? '' ) ),
			'operation'      => (object) self::rollback_snapshot_as_array( $record['operation'] ?? [] ),
			'target'         => [
				'kind'      => $target_kind,
				'id'        => $target_id,
				'post_type' => sanitize_key( (string) ( $target['post_type'] ?? '' ) ),
			],
			'before'         => [
				'checksum'    => sanitize_text_field( (string) ( $before['checksum'] ?? '' ) ),
				'byte_length' => isset( $before['byte_length'] ) ? absint( $before['byte_length'] ) : null,
				'has_value'   => array_key_exists( 'value', $before ),
			],
			'after'          => [
				'checksum'    => sanitize_text_field( (string) ( $after['checksum'] ?? '' ) ),
				'byte_length' => isset( $after['byte_length'] ) ? absint( $after['byte_length'] ) : null,
			],
			'restore'        => [
				'restorable'  => (bool) ( $restore['restorable'] ?? false ),
				'restored_at' => isset( $restore['restored_at'] ) ? sanitize_text_field( (string) $restore['restored_at'] ) : null,
			],
			'cleanup'        => [
				'deleted_at' => isset( $cleanup['deleted_at'] ) ? sanitize_text_field( (string) $cleanup['deleted_at'] ) : null,
			],
			'option'         => [
				'name'      => $option_name,
				'autoload'  => null === $autoload ? null : sanitize_key( (string) $autoload ),
				'byte_size' => self::rollback_snapshot_raw_byte_size( $raw_value, $record ),
			],
		];
	}

	private static function rollback_snapshot_target_post( array $summary ) {
		return function_exists( 'get_post' ) ? get_post( (int) $summary['target']['id'] ) : null;
	}

	private static function rollback_snapshot_target_access( array $summary ): array {
		$post = self::rollback_snapshot_target_post( $summary );
		if ( $post ) {
			return [
				'exists'  => true,
				'allowed' => self::can_inspect_post_object( $post ),
				'creator' => self::rollback_snapshot_created_by_current_user( $summary ),
				'admin'   => current_user_can( 'manage_options' ),
			];
		}

		$creator = self::rollback_snapshot_created_by_current_user( $summary );
		$admin   = current_user_can( 'manage_options' );
		return [
			'exists'  => false,
			'allowed' => $creator || $admin,
			'creator' => $creator,
			'admin'   => $admin,
		];
	}

	private static function rollback_snapshot_scan_records( int $scan_limit = 250 ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->options ) ) {
			return [];
		}

		$like = $wpdb->esc_like( self::rollback_snapshot_option_prefix() ) . '%';
		$scan_limit = max( 1, min( 1000, $scan_limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- `$wpdb->options` is the WP options table name; values remain prepared.
		$sql = $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d", $like, $scan_limit );
		$rows = $wpdb->get_results( $sql );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$records = [];
		foreach ( $rows as $row ) {
			$option_name  = is_array( $row ) ? (string) ( $row['option_name'] ?? '' ) : (string) ( $row->option_name ?? '' );
			$option_value = is_array( $row ) ? ( $row['option_value'] ?? null ) : ( $row->option_value ?? null );
			$autoload     = is_array( $row ) ? ( $row['autoload'] ?? null ) : ( $row->autoload ?? null );
			$value        = maybe_unserialize( $option_value );
			if ( ! is_array( $value ) ) {
				continue;
			}
			$summary = self::rollback_snapshot_normalize_record( $value, $option_name, $option_value, null === $autoload ? null : (string) $autoload );
			if ( null !== $summary ) {
				$records[] = $summary;
			}
		}

		usort(
			$records,
			static function ( $a, $b ) {
				return strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) );
			}
		);

		return $records;
	}

	private static function rollback_snapshot_filtered_summaries( $request ): array {
		$target_kind = sanitize_key( (string) ( $request->get_param( 'target_kind' ) ?? '' ) );
		$target_id   = absint( $request->get_param( 'target_id' ) ?? 0 );
		$status      = sanitize_key( (string) ( $request->get_param( 'status' ) ?? '' ) );
		$limit       = absint( $request->get_param( 'limit' ) ?? 20 );
		$limit       = max( 1, min( 100, $limit ) );

		$rows = [];
		foreach ( self::rollback_snapshot_scan_records() as $summary ) {
			if ( '' !== $target_kind && $target_kind !== $summary['target']['kind'] ) {
				continue;
			}
			if ( $target_id > 0 && $target_id !== (int) $summary['target']['id'] ) {
				continue;
			}
			if ( '' !== $status && $status !== $summary['status'] ) {
				continue;
			}

			$access = self::rollback_snapshot_target_access( $summary );
			if ( ! $access['allowed'] ) {
				continue;
			}
			$summary['target']['exists'] = $access['exists'];
			if ( ! $access['exists'] ) {
				$summary['payload_redacted'] = true;
				$summary['warning']          = 'target_missing_metadata_only';
			}
			$rows[] = $summary;
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}

		return $rows;
	}

	public static function rollback_snapshot_list( $request ) {
		$snapshots = self::rollback_snapshot_filtered_summaries( $request );
		return self::envelope_success( [
			'snapshots' => $snapshots,
			'count'     => count( $snapshots ),
		] );
	}

	public static function rollback_snapshot_get( $request ) {
		$snapshot_id = self::rollback_snapshot_validate_id( $request->get_param( 'snapshot_id' ) );
		if ( false === $snapshot_id ) {
			return self::envelope_error( 'invalid_input', 'Invalid rollback snapshot id.', 'Use the snapshot_id returned by diviops_rollback_snapshot_list.', 400 );
		}

		$option_name = self::rollback_snapshot_option_name( $snapshot_id );
		$record      = get_option( $option_name, null );
		if ( ! is_array( $record ) ) {
			return self::envelope_error( 'not_found', 'Rollback snapshot not found.', null, 404, [ 'snapshot_id' => $snapshot_id ] );
		}
		$summary = self::rollback_snapshot_normalize_record( $record, $option_name, $record );
		if ( null === $summary ) {
			return self::envelope_error( 'invalid_input', 'Rollback snapshot record is malformed.', null, 400, [ 'snapshot_id' => $snapshot_id ] );
		}

		$access = self::rollback_snapshot_target_access( $summary );
		if ( ! $access['allowed'] ) {
			return self::envelope_error( 'forbidden', 'You cannot inspect this rollback snapshot target.', null, 403, [ 'snapshot_id' => $snapshot_id, 'target' => $summary['target'] ] );
		}

		$include_value = rest_sanitize_boolean( $request->get_param( 'include_value' ) ?? false );
		$summary['target']['exists'] = $access['exists'];
		$data = [ 'snapshot' => $summary ];

		if ( ! $access['exists'] ) {
			$data['snapshot']['payload_redacted'] = true;
			$data['snapshot']['warning']          = 'target_missing_payload_redacted';
			return self::envelope_success( $data );
		}

		if ( $include_value ) {
			$before = self::rollback_snapshot_as_array( $record['before'] ?? [] );
			$after  = self::rollback_snapshot_as_array( $record['after'] ?? [] );
			$data['snapshot']['before']['value'] = $before['value'] ?? null;
			if ( isset( $before['side_effects'] ) ) {
				$data['snapshot']['before']['side_effects'] = $before['side_effects'];
			}
			if ( isset( $after['side_effects'] ) ) {
				$data['snapshot']['after']['side_effects'] = $after['side_effects'];
			}
		}

		return self::envelope_success( $data );
	}

	public static function rollback_snapshot_delete( $request ) {
		$snapshot_id = self::rollback_snapshot_validate_id( $request->get_param( 'snapshot_id' ) );
		if ( false === $snapshot_id ) {
			return self::envelope_error( 'invalid_input', 'Invalid rollback snapshot id.', 'Use the snapshot_id returned by diviops_rollback_snapshot_list.', 400 );
		}

		$option_name = self::rollback_snapshot_option_name( $snapshot_id );
		$record      = get_option( $option_name, null );
		if ( ! is_array( $record ) ) {
			return self::envelope_error( 'not_found', 'Rollback snapshot not found.', null, 404, [ 'snapshot_id' => $snapshot_id ] );
		}
		$summary = self::rollback_snapshot_normalize_record( $record, $option_name, $record );
		if ( null === $summary ) {
			return self::envelope_error( 'invalid_input', 'Rollback snapshot record is malformed.', null, 400, [ 'snapshot_id' => $snapshot_id ] );
		}

		$access = self::rollback_snapshot_target_access( $summary );
		if ( ! $access['allowed'] ) {
			return self::envelope_error( 'forbidden', 'You cannot delete this rollback snapshot.', null, 403, [ 'snapshot_id' => $snapshot_id, 'target' => $summary['target'] ] );
		}

		$deleted = delete_option( $option_name );
		return self::envelope_success( [
			'snapshot_id' => $snapshot_id,
			'deleted'     => (bool) $deleted,
			'target'      => [
				'kind'   => $summary['target']['kind'],
				'id'     => $summary['target']['id'],
				'exists' => $access['exists'],
			],
			'deleted_by'  => [
				'user_id' => self::rollback_snapshot_current_user_id(),
				'login'   => self::rollback_snapshot_user_login(),
			],
		] );
	}

	private static function rollback_snapshot_supported_target( $post ): bool {
		return is_object( $post ) && in_array(
			(string) ( $post->post_type ?? '' ),
			[ 'post', 'page', 'et_header_layout', 'et_body_layout', 'et_footer_layout' ],
			true
		);
	}

	private static function rollback_snapshot_normalize_nested_value( $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		ksort( $value );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::rollback_snapshot_normalize_nested_value( $item );
		}
		return $value;
	}

	private static function rollback_snapshot_side_effects_equal( array $expected, array $actual ): bool {
		return self::rollback_snapshot_normalize_nested_value( $expected['post_meta'] ?? [] ) === self::rollback_snapshot_normalize_nested_value( $actual['post_meta'] ?? [] );
	}

	private static function rollback_snapshot_restore_side_effects( int $post_id, array $side_effects ) {
		$captured = self::rollback_snapshot_as_array( $side_effects['post_meta'] ?? [] );
		foreach ( self::rollback_snapshot_side_effect_meta_keys() as $key ) {
			if ( ! array_key_exists( $key, $captured ) ) {
				continue;
			}
			$state = self::rollback_snapshot_as_array( $captured[ $key ] );
			if ( ! empty( $state['exists'] ) ) {
				update_post_meta( $post_id, $key, $state['value'] ?? null );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}

		$readback = self::rollback_snapshot_capture_side_effects( $post_id );
		if ( ! self::rollback_snapshot_side_effects_equal( $side_effects, $readback ) ) {
			return new WP_Error(
				'rollback_snapshot.side_effect_readback_failed',
				'Rollback content was written, but captured Divi post-meta did not verify after restore.',
				[ 'status' => 500, 'side_effects' => [ 'expected' => $side_effects, 'readback' => $readback ] ]
			);
		}
		return $readback;
	}

	public static function rollback_snapshot_restore( $request ) {
		$snapshot_id = self::rollback_snapshot_validate_id( $request->get_param( 'snapshot_id' ) );
		if ( false === $snapshot_id ) {
			return self::envelope_error( 'invalid_input', 'Invalid rollback snapshot id.', 'Use the snapshot_id returned by diviops_rollback_snapshot_list.', 400 );
		}

		$option_name = self::rollback_snapshot_option_name( $snapshot_id );
		$record      = get_option( $option_name, null );
		if ( ! is_array( $record ) ) {
			return self::envelope_error( 'not_found', 'Rollback snapshot not found.', null, 404, [ 'snapshot_id' => $snapshot_id ] );
		}
		$summary = self::rollback_snapshot_normalize_record( $record, $option_name, $record );
		if ( null === $summary ) {
			return self::envelope_error( 'invalid_input', 'Rollback snapshot record is malformed.', null, 400, [ 'snapshot_id' => $snapshot_id ] );
		}

		// Do not read or copy before.value until the live target's row-level edit gate passes.
		$post = self::rollback_snapshot_target_post( $summary );
		if ( ! $post ) {
			return self::envelope_error( 'not_found', 'Rollback snapshot target no longer exists.', null, 404, [ 'snapshot_id' => $snapshot_id, 'target' => $summary['target'] ] );
		}
		if ( ! self::can_inspect_post_object( $post ) ) {
			return self::envelope_error( 'forbidden', 'You cannot restore this rollback snapshot target.', null, 403, [ 'snapshot_id' => $snapshot_id, 'target' => $summary['target'] ] );
		}
		if ( ! self::rollback_snapshot_supported_target( $post ) ) {
			return self::envelope_error( 'invalid_input', 'This rollback snapshot target type is not supported by the restore MVP.', null, 400, [ 'snapshot_id' => $snapshot_id, 'post_type' => (string) $post->post_type ] );
		}

		$before = self::rollback_snapshot_as_array( $record['before'] ?? [] );
		$after  = self::rollback_snapshot_as_array( $record['after'] ?? [] );
		if ( empty( $summary['restore']['restorable'] ) || ! array_key_exists( 'value', $before ) || empty( $after['checksum'] ) ) {
			return self::envelope_error( 'conflict', 'Rollback snapshot is not in a restorable state.', null, 409, [ 'snapshot_id' => $snapshot_id, 'status' => $summary['status'] ] );
		}

		$current_content      = (string) ( $post->post_content ?? '' );
		$current_checksum     = self::rollback_snapshot_checksum( $current_content );
		$current_side_effects = self::rollback_snapshot_capture_side_effects( (int) $post->ID );
		$after_side_effects   = self::rollback_snapshot_as_array( $after['side_effects'] ?? [] );
		$content_drift        = ! hash_equals( (string) $after['checksum'], $current_checksum );
		$side_effect_drift    = ! empty( $after_side_effects ) && ! self::rollback_snapshot_side_effects_equal( $after_side_effects, $current_side_effects );
		if ( $content_drift || $side_effect_drift ) {
			return self::envelope_error(
				'conflict',
				'Rollback refused because the target changed after the snapshot write.',
				'Inspect the drift and create a fresh guarded backup before any later restore attempt. This MVP has no force override.',
				409,
				[
					'snapshot_id' => $snapshot_id,
					'drift'       => [
						'content'      => $content_drift,
						'side_effects' => $side_effect_drift,
						'expected_after_checksum' => (string) $after['checksum'],
						'current_checksum'        => $current_checksum,
						'expected_after_side_effects' => $after_side_effects,
						'current_side_effects'        => $current_side_effects,
					],
				]
			);
		}

		$restore_content = (string) $before['value'];
		if ( rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false ) ) {
			return self::dry_run_response(
				"Would restore rollback snapshot {$snapshot_id} to {$post->post_type}#{$post->ID}.",
				[ [ 'kind' => 'rollback_snapshot.restore', 'target' => "{$post->post_type}#{$post->ID}", 'before' => [ 'checksum' => $current_checksum ], 'after' => [ 'checksum' => self::rollback_snapshot_checksum( $restore_content ) ] ] ]
			);
		}

		$result = self::update_post_content_with_integrity_guard(
			(int) $post->ID,
			$restore_content,
			'rollback_snapshot',
			"rollback snapshot {$snapshot_id}",
			$current_content
		);
		if ( is_wp_error( $result ) ) {
			return self::envelope_from_content_write_error( $result );
		}

		$side_effect_readback = self::rollback_snapshot_restore_side_effects( (int) $post->ID, self::rollback_snapshot_as_array( $before['side_effects'] ?? [] ) );
		if ( is_wp_error( $side_effect_readback ) ) {
			$error_data = $side_effect_readback->get_error_data();
			self::invalidate_divi_cache( (int) $post->ID );
			return self::envelope_error(
				'rollback_snapshot.side_effect_readback_failed',
				'Rollback content was written, but captured Divi post-meta did not verify after restore.',
				'The target content changed; inspect the side-effect diagnostics before retrying.',
				500,
				[
					'snapshot_id'       => $snapshot_id,
					'record_id'         => (int) $post->ID,
					'committed'         => true,
					'changed_fields'    => [ 'post_content', 'post_meta' ],
					'prior_checksum'    => $current_checksum,
					'intended_checksum' => self::rollback_snapshot_checksum( $restore_content ),
					'side_effects'      => is_array( $error_data ) ? ( $error_data['side_effects'] ?? null ) : null,
				]
			);
		}

		$readback          = get_post( (int) $post->ID );
		$readback_content  = $readback && isset( $readback->post_content ) ? (string) $readback->post_content : '';
		$restored_checksum = self::rollback_snapshot_checksum( $readback_content );
		$expected_checksum = self::rollback_snapshot_checksum( $restore_content );
		if ( ! hash_equals( $expected_checksum, $restored_checksum ) ) {
			self::invalidate_divi_cache( (int) $post->ID );
			return self::envelope_error(
				'readback_failed',
				'Rollback restore readback checksum did not match after the content write.',
				'The target content may have changed; inspect checksums before retrying.',
				500,
				[
					'snapshot_id'       => $snapshot_id,
					'record_id'         => (int) $post->ID,
					'committed'         => true,
					'changed_fields'    => [ 'post_content', 'post_meta' ],
					'prior_checksum'    => $current_checksum,
					'expected_checksum' => $expected_checksum,
					'restored_checksum' => $restored_checksum,
				]
			);
		}

		self::invalidate_divi_cache( (int) $post->ID );
		$record['status']                            = 'restore_applied';
		$record['restore']                           = self::rollback_snapshot_as_array( $record['restore'] ?? [] );
		$record['restore']['restored_at']            = self::rollback_snapshot_now();
		$record['restore']['restored_by']            = [ 'user_id' => self::rollback_snapshot_current_user_id(), 'login' => self::rollback_snapshot_user_login() ];
		$record['restore']['prior_current_checksum'] = $current_checksum;
		$record['restore']['restored_checksum']      = $restored_checksum;
		update_option( $option_name, $record, false );

		return self::envelope_success( [
			'snapshot_id'           => $snapshot_id,
			'target'                => [ 'kind' => 'post', 'id' => (int) $post->ID, 'post_type' => (string) $post->post_type ],
			'prior_current_checksum' => $current_checksum,
			'restored_checksum'      => $restored_checksum,
			'readback'               => [ 'verified' => true, 'checksum' => $restored_checksum, 'byte_length' => strlen( $readback_content ), 'side_effects' => $side_effect_readback ],
			'cache'                  => [ 'invalidated' => true, 'post_id' => (int) $post->ID ],
			'status'                 => [ 'before' => $summary['status'], 'after' => 'restore_applied', 'restored_at' => $record['restore']['restored_at'] ],
			'force'                  => [ 'supported' => false, 'used' => false ],
			'restore_backup'         => [ 'created' => false, 'deferred' => true, 'reason' => 'Strict checksum binding prevents intervening-state overwrite in this MVP.' ],
		] );
	}
}
