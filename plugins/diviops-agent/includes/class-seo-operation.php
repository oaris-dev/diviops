<?php
/** Bounded text-operation correlation inside the existing provider recovery records. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DiviOps_SEO_Operation {
	/** Public semantic evidence; provider keys and unrelated state never leave Free. */
	public static function text_evidence( int $id, string $provider, array $rows, array $context = [] ): array {
		$keys = 'tsf' === $provider ? DiviOps_SEO_TSF_Text_Recovery::KEYS : [ 'seo_title' => 'seo_title', 'meta_description' => 'meta_description' ];
		$text = [];
		foreach ( $keys as $field => $key ) { $text[ $field ] = $rows[ $key ]; unset( $rows[ $key ] ); }
		if ( 'tsf' === $provider ) {
			$context = [ get_post( $id ), get_option( 'autodescription-site-settings' ), get_option( 'blogname' ), get_option( 'blogdescription' ), get_option( 'blog_public' ), get_option( 'show_on_front' ), get_option( 'page_on_front' ), get_option( 'page_for_posts' ), get_permalink( $id ) ];
		}
		$json = wp_json_encode( self::canonical( [ $context, $rows ] ) );
		if ( ! is_string( $json ) ) { throw new RuntimeException( 'stored_shape_unsupported' ); }
		return [ 'contract' => 'seo-text-evidence-v1', 'rows' => $text, 'context_checksum' => 'sha256:' . hash( 'sha256', $json ) ];
	}

	/** Optional stronger context guard for reviewed callers; legacy checksums stay unchanged. */
	public static function check_text_context( int $id, string $provider, array $rows, array $context, $expected ): void {
		if ( null !== $expected && ( ! is_string( $expected ) || $expected !== self::text_evidence( $id, $provider, $rows, $context )['context_checksum'] ) ) { throw new RuntimeException( 'metadata_drift' ); }
	}

	/** Keys are site/provider scoped; target, version and exact input are immutable bindings. */
	public static function identity( int $id, string $provider, string $version, $key, $request = null ): array {
		if ( ! is_string( $key ) || ! preg_match( '/\A[a-zA-Z0-9][a-zA-Z0-9_-]{15,63}\z/', $key ) || ! in_array( $provider, [ 'tsf', 'rank_math' ], true ) ) { throw new RuntimeException( 'invalid_input' ); }
		$operation = [ 'schema' => 1, 'key_hash' => hash( 'sha256', $key ), 'binding' => [ 'site' => home_url( '/' ), 'provider' => $provider, 'post_id' => $id, 'post_type' => get_post( $id )->post_type, 'version' => $version ] ];
		if ( null !== $request ) {
			$changes = $request->get_param( 'changes' ); $restore = $request->get_param( 'restore_snapshot_id' );
			if ( null === $restore ) {
				if ( ! is_array( $changes ) || ! $changes || count( $changes ) > 2 ) { throw new RuntimeException( 'invalid_input' ); }
				foreach ( $changes as $change ) { if ( ! is_array( $change ) || ! in_array( $change['field'] ?? null, [ 'seo_title', 'meta_description' ], true ) ) { throw new RuntimeException( 'operation_scope_unsupported' ); } }
			} elseif ( null !== $changes || ! is_string( $restore ) || ! preg_match( 'tsf' === $provider ? '/\Atsft_[a-f0-9-]{36}\z/' : '/\Arm_[a-f0-9-]{36}\z/', $restore ) ) { throw new RuntimeException( 'operation_scope_unsupported' ); }
			$input = [ 'checksum' => $request->get_param( 'expected_checksum' ), 'changes' => $changes, 'restore' => $restore ];
			if ( null !== $request->get_param( 'expected_text_context' ) ) { $input['text_context'] = $request->get_param( 'expected_text_context' ); }
			$json = wp_json_encode( self::canonical( $input ) );
			if ( ! is_string( $json ) ) { throw new RuntimeException( 'invalid_input' ); }
			$operation['request_hash'] = hash( 'sha256', $json );
		}
		return $operation;
	}

	private static function canonical( $value ) {
		if ( is_array( $value ) ) { if ( array_values( $value ) !== $value ) { ksort( $value ); } return array_map( [ __CLASS__, 'canonical' ], $value ); }
		return $value;
	}

	public static function snapshot_id( array $operation ): string {
		$hash = hash( 'sha256', wp_json_encode( [ 'seo_operation_v1', $operation['binding']['site'], $operation['binding']['provider'], $operation['key_hash'] ] ) );
		return ( 'tsf' === $operation['binding']['provider'] ? 'tsft_' : 'rm_' ) . substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-' . substr( $hash, 12, 4 ) . '-' . substr( $hash, 16, 4 ) . '-' . substr( $hash, 20, 12 );
	}

	/** Read only: never derive a completed outcome from matching live values or unlock a worker. */
	public static function find( array $operation ): ?array {
		$id = self::snapshot_id( $operation ); $tsf = 'tsf' === $operation['binding']['provider'];
		$missing = new stdClass();
		$source = get_option( ( $tsf ? DiviOps_SEO_TSF_Text_Recovery::PREFIX : DiviOps_SEO_Rank_Math::RECOVERY_PREFIX ) . $id, $missing );
		if ( $missing === $source ) { return null; }
		$stored = is_array( $source ) ? ( $source['operation'] ?? null ) : null;
		if ( ! is_array( $stored ) || ( $stored['schema'] ?? null ) !== 1 || ( $stored['key_hash'] ?? null ) !== $operation['key_hash'] || ( $stored['binding'] ?? null ) !== $operation['binding']
			|| ! is_string( $stored['request_hash'] ?? null ) || ! preg_match( '/\A[a-f0-9]{64}\z/', $stored['request_hash'] ) ) { throw new RuntimeException( 'operation_conflict' ); }
		if ( isset( $operation['request_hash'] ) && $operation['request_hash'] !== $stored['request_hash'] ) { throw new RuntimeException( 'operation_conflict' ); }
		$binding = $operation['binding'];
		$valid = $tsf ? ( ( $source['schema'] ?? null ) === 1 && ( $source['type'] ?? null ) === 'tsf_text' && ( $source['context'] ?? null ) === [ 'site' => $binding['site'], 'post_id' => $binding['post_id'], 'post_type' => $binding['post_type'], 'version' => $binding['version'] ] )
			: ( ( $source['schema'] ?? null ) === 2 && ( $source['site'] ?? null ) === $binding['site'] && ( $source['post_id'] ?? null ) === $binding['post_id'] && ( $source['provider_version'] ?? null ) === $binding['version'] );
		if ( ! $valid || ( $source['snapshot_id'] ?? null ) !== $id || ! in_array( $source['status'] ?? null, [ 'prepared', 'applied', 'recovered', 'recovery_required' ], true ) || ! is_array( $source['before'] ?? null ) || ! is_array( $source['after'] ?? null ) ) { throw new RuntimeException( 'operation_unavailable' ); }
		$keys = $tsf ? [ '_genesis_title', '_genesis_description' ] : [ 'seo_title', 'meta_description' ];
		if ( array_keys( $source['before'] ) !== array_keys( $source['after'] ) ) { throw new RuntimeException( 'operation_unavailable' ); }
		foreach ( $keys as $key ) { foreach ( [ 'before', 'after' ] as $side ) {
			$rows = $source[ $side ][ $key ] ?? null;
			if ( ! is_array( $rows ) || array_values( $rows ) !== $rows || ( ! $tsf && count( $rows ) > 1 ) ) { throw new RuntimeException( 'operation_unavailable' ); }
			foreach ( $rows as $value ) { if ( ! is_string( $value ) ) { throw new RuntimeException( 'operation_unavailable' ); } }
		} }
		foreach ( $source['before'] as $key => $rows ) { if ( ! in_array( $key, $keys, true ) && $rows !== $source['after'][ $key ] ) { throw new RuntimeException( 'operation_unavailable' ); } }
		return self::summary( $operation, $source );
	}

	public static function summary( array $operation, ?array $record = null ): array {
		$missing = new stdClass();
		$locked = $missing !== get_option( 'diviops_seo_lock_' . $operation['binding']['post_id'], $missing );
		return [ 'ok' => true, 'write_applied' => false, 'operation' => [ 'outcome' => null === $record ? 'not_recorded' : ( 'prepared' === $record['status'] ? 'uncertain' : $record['status'] ),
			'snapshot_id' => $record['snapshot_id'] ?? null, 'reference' => self::snapshot_id( $operation ), 'request_hash' => $record['operation']['request_hash'] ?? null, 'historical' => true, 'replayed' => false, 'lock_held' => $locked,
			'next_action' => $locked || ( $record && in_array( $record['status'], [ 'prepared', 'recovery_required' ], true ) ) ? 'stop_and_inspect' : 'read_current_metadata', 'runtime_verified' => false ] ];
	}
}
