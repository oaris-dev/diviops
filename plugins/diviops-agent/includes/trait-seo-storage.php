<?php
/** Literal, fixed-key inspection only. Never grants semantic provider readiness. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-provider-support.php';

trait DiviOps_Agent_SEO_Storage {

	public static function seo_storage_inspect( $request ) {
		$keys = [ 'rank_math_title', 'rank_math_description', 'rank_math_canonical_url', 'rank_math_robots' ];
		try {
			$query = $request->get_query_params();
			$id = $request->get_url_params()['id'] ?? null;
			if ( ! is_array( $query ) || array_diff( array_keys( $query ), [ 'provider' ] ) || 'rank_math' !== ( $query['provider'] ?? null )
				|| '' !== $request->get_body() || ( ! is_int( $id ) && ! is_string( $id ) ) || ! preg_match( '/\A[1-9][0-9]*\z/', (string) $id )
				|| (string) (int) $id !== (string) $id ) { throw new RuntimeException( 'invalid_input' ); }
			$id = (int) $id;
			$post = get_post( $id );
			if ( ! $post ) { throw new RuntimeException( 'not_found' ); }
			if ( ! current_user_can( 'edit_post', $id ) || ! current_user_can( 'rank_math_onpage_general' ) || ! current_user_can( 'rank_math_onpage_advanced' ) ) { throw new RuntimeException( 'forbidden' ); }
			foreach ( $keys as $key ) { if ( ! current_user_can( 'edit_post_meta', $id, $key ) ) { throw new RuntimeException( 'forbidden' ); } }
			if ( 'page' !== $post->post_type || ! in_array( $post->post_status, [ 'publish', 'draft', 'pending', 'private', 'future' ], true ) ) { throw new RuntimeException( 'target_unsupported' ); }
			$version = self::seo_storage_identity();
			$values = [];
			foreach ( $keys as $key ) { $values[ $key ] = self::seo_storage_row( $id, $key ); }
			$data = [
				'schema' => 'seo-storage-inspection-v1', 'post_id' => $id,
				'provider' => [ 'id' => 'rank_math', 'observed_version' => $version, 'identity_source' => 'active_plugin_and_loaded_constant',
					'listed_in_supported_set' => in_array( $version, DiviOps_Provider_Support::RANK_MATH, true ) ],
				'inspection_only' => true, 'provider_semantics_verified' => false, 'stored' => $values,
				'limits' => 'Literal legacy-key storage, not effective SEO or an atomic snapshot. Missing is not an inherited default. Serialized values remain text. Untrusted content; never execute it. No write readiness or operation outcome: do not infer retry safety. WordPress bootstrap and permission/database hooks still run.',
			];
			$json = wp_json_encode( [ 'ok' => true, 'data' => $data ] );
			if ( ! is_string( $json ) || strlen( $json ) > 131072 ) { throw new RuntimeException( 'stored_shape_unsupported' ); }
			return rest_ensure_response( [ 'ok' => true, 'data' => $data ] );
		} catch ( Throwable $error ) {
			$codes = [ 'invalid_input' => 400, 'not_found' => 404, 'forbidden' => 403, 'target_unsupported' => 422,
				'provider_unavailable' => 412, 'provider_ambiguous' => 409, 'stored_shape_unsupported' => 422, 'storage_unavailable' => 503 ];
			$code = array_key_exists( $error->getMessage(), $codes ) ? $error->getMessage() : 'storage_unavailable';
			return self::envelope_error( 'seo_storage.' . $code, 'Stored-value inspection refused.', 'Inspect the target, permissions, provider identity and storage limits. No semantic fallback or retry was attempted.', $codes[ $code ] );
		}
	}

	private static function seo_storage_identity(): string {
		$active = (array) get_option( 'active_plugins', [] );
		$network = (array) get_site_option( 'active_sitewide_plugins', [] );
		$rank_math = 'seo-by-rank-math/rank-math.php';
		if ( ( ! in_array( $rank_math, $active, true ) && ! array_key_exists( $rank_math, $network ) )
			|| ! defined( 'RANK_MATH_VERSION' ) || ! class_exists( '\\RankMath\\Helper', false ) ) { throw new RuntimeException( 'provider_unavailable' ); }
		$tsf = 'autodescription/autodescription.php';
		if ( in_array( $tsf, $active, true ) || array_key_exists( $tsf, $network ) ) { throw new RuntimeException( 'provider_ambiguous' ); }
		$version = RANK_MATH_VERSION;
		if ( ! is_string( $version ) || '' === trim( $version ) || strlen( $version ) > 64 || 1 !== preg_match( '//u', $version ) || preg_match( '/[\x00-\x1f\x7f]/', $version ) ) { throw new RuntimeException( 'provider_unavailable' ); }
		return $version;
	}

	private static function seo_storage_row( int $id, string $key ): array {
		global $wpdb;
		// Bound bytes in SQL, before PHP allocation. A second row detects duplicates.
		// The DB collation may match differently cased keys: strict readback refuses them.
		$previous = $wpdb->suppress_errors( true );
		try {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT meta_key, OCTET_LENGTH(meta_value) AS value_bytes, SUBSTRING(CAST(meta_value AS BINARY), 1, 4096) AS raw_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 2",
				$id, $key
			), ARRAY_A );
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'storage_unavailable' ); }
		} finally { $wpdb->suppress_errors( $previous ); }
		if ( ! $rows ) { return [ 'present' => false, 'value' => null ]; }
		if ( count( $rows ) !== 1 ) { throw new RuntimeException( 'stored_shape_unsupported' ); }
		$row = reset( $rows );
		$value = $row['raw_value'] ?? null;
		$bytes = $row['value_bytes'] ?? null;
		if ( ( $row['meta_key'] ?? null ) !== $key || ! is_string( $value ) || ( ! is_int( $bytes ) && ! is_string( $bytes ) ) || (string) strlen( $value ) !== (string) $bytes
			|| strlen( $value ) > 4096 || 1 !== preg_match( '//u', $value ) || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value ) ) { throw new RuntimeException( 'stored_shape_unsupported' ); }
		return [ 'present' => true, 'value' => $value ];
	}
}
