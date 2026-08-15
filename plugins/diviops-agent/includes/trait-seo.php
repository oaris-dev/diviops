<?php
/**
 * Trait DiviOps_Agent_SEO
 *
 * Provider-neutral, allowlisted SEO metadata operations. The first adapter is
 * intentionally limited to The SEO Framework and two explicit text fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Narrow adapter for The SEO Framework's public post-metadata API.
 *
 * Provider storage keys stay private to this class. Callers can address only
 * the semantic field names exposed by DiviOps_Agent_SEO.
 */
final class DiviOps_SEO_TSF_Adapter {
	const ID                    = 'tsf';
	const NAME                  = 'The SEO Framework';
	const PLUGIN_FILE           = 'autodescription/autodescription.php';
	const MIN_VERSION           = '5.1.4';
	const MAX_VERSION_EXCLUSIVE = '6.0.0';

	private const FIELD_KEYS = [
		'seo_title'       => '_genesis_title',
		'meta_description' => '_genesis_description',
	];

	/**
	 * Return installation, activation, compatibility, and API evidence without
	 * loading an inactive provider.
	 */
	public static function discovery(): array {
		$path           = self::plugin_path();
		$active_plugins = function_exists( 'get_option' ) ? (array) get_option( 'active_plugins', [] ) : [];
		$network_plugins = function_exists( 'get_site_option' ) ? (array) get_site_option( 'active_sitewide_plugins', [] ) : [];
		$listed_active    = in_array( self::PLUGIN_FILE, $active_plugins, true ) || array_key_exists( self::PLUGIN_FILE, $network_plugins );
		$active           = $listed_active && function_exists( 'tsf' ) && defined( 'THE_SEO_FRAMEWORK_PRESENT' );
		$installed        = $active || is_file( $path );
		$version          = $active && defined( 'THE_SEO_FRAMEWORK_VERSION' )
			? (string) THE_SEO_FRAMEWORK_VERSION
			: self::inactive_version( $path );
		$compatible = null !== $version && version_compare( $version, self::MIN_VERSION, '>=' )
			&& version_compare( $version, self::MAX_VERSION_EXCLUSIVE, '<' );
		$api_ready = $active && self::api_ready();

		return [
			'provider'          => self::ID,
			'name'              => self::NAME,
			'installed'         => $installed,
			'active'            => $active,
			'version'           => $version,
			'compatible'        => $compatible,
			'adapter'           => $api_ready && $compatible ? 'runtime_verified' : ( $installed ? 'unavailable' : 'not_installed' ),
			'readable'          => $api_ready && $compatible,
			'writable'          => $api_ready && $compatible,
			'fields'            => array_keys( self::FIELD_KEYS ),
			'capabilities'      => [
				'explicit_metadata_read'  => $api_ready && $compatible,
				'explicit_metadata_write' => $api_ready && $compatible,
				'explicit_metadata_clear' => $api_ready && $compatible,
				'effective_read'          => $api_ready && $compatible,
			],
			'version_support'   => [
				'minimum'              => self::MIN_VERSION,
				'maximum_exclusive'    => self::MAX_VERSION_EXCLUSIVE,
				'validated_at_version' => self::MIN_VERSION,
			],
			'provider_api'      => 'tsf()->data()->plugin()->post()',
			'automatic_content' => false,
		];
	}

	public static function supports_post_type( string $post_type ): bool {
		return (bool) tsf()->post_type()->is_supported( $post_type );
	}

	/**
	 * Read exact explicit state for every V1 semantic field.
	 */
	public static function read_fields( int $post_id ): array {
		$post_api = tsf()->data()->plugin()->post();
		$fields   = [];

		foreach ( self::FIELD_KEYS as $field => $provider_key ) {
			$explicit = metadata_exists( 'post', $post_id, $provider_key );
			$value     = $post_api->get_meta_item( $provider_key, $post_id );
			$fields[ $field ] = [
				'explicit' => $explicit,
				'value'    => is_scalar( $value ) ? (string) $value : '',
			];
		}

		return $fields;
	}

	/**
	 * Capture exact stored rows for every provider-registered post field.
	 * Keys and values remain private adapter state and are never caller-visible.
	 */
	public static function read_registered_state( int $post_id ): array {
		$post_api = tsf()->data()->plugin()->post();
		$defaults = (array) $post_api->get_default_meta( $post_id );
		$state    = [];

		foreach ( array_keys( $defaults ) as $provider_key ) {
			$state[ $provider_key ] = array_values( get_post_meta( $post_id, $provider_key, false ) );
		}

		return $state;
	}

	/**
	 * Apply the intended V1 field state to a private registered-state snapshot.
	 */
	public static function with_v1_state( array $registered_state, array $v1_state ): array {
		foreach ( self::FIELD_KEYS as $field => $provider_key ) {
			if ( ! $v1_state[ $field ]['explicit'] ) {
				$registered_state[ $provider_key ] = [];
				continue;
			}

			$row_count = max( 1, count( $registered_state[ $provider_key ] ?? [] ) );
			$registered_state[ $provider_key ] = array_fill( 0, $row_count, (string) $v1_state[ $field ]['value'] );
		}

		return $registered_state;
	}

	/**
	 * Restore the exact private provider snapshot after a failed provider save.
	 */
	public static function restore_registered_state( int $post_id, array $state ): void {
		foreach ( $state as $provider_key => $values ) {
			delete_post_meta( $post_id, $provider_key );
			foreach ( $values as $value ) {
				add_post_meta( $post_id, $provider_key, $value );
			}
		}

		// Direct exact restoration must invalidate TSF's request-local memo before
		// provider readback verifies the restored state.
		tsf()->data()->plugin()->post()->refresh_static_properties();
	}

	public static function registered_states_equal( array $left, array $right ): bool {
		return $left === $right;
	}

	public static function registered_state_diff_count( array $left, array $right ): int {
		$count = 0;
		foreach ( array_unique( array_merge( array_keys( $left ), array_keys( $right ) ) ) as $provider_key ) {
			if ( ( $left[ $provider_key ] ?? null ) !== ( $right[ $provider_key ] ?? null ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Run the provider's public sanitizer without writing.
	 */
	public static function sanitize( string $value ): string {
		return (string) tsf()->sanitize()->metadata_content( $value );
	}

	/**
	 * Set or clear one fixed semantic field through the provider lifecycle.
	 */
	public static function write_field( int $post_id, string $field, bool $explicit, string $value = '' ): void {
		if ( ! isset( self::FIELD_KEYS[ $field ] ) ) {
			throw new InvalidArgumentException( 'Unsupported semantic SEO field.' );
		}

		// TSF's save_meta lifecycle deletes empty values; this is its public
		// delete/empty path and never accepts a caller-controlled storage key.
		tsf()->data()->plugin()->post()->update_single_meta_item(
			self::FIELD_KEYS[ $field ],
			$explicit ? $value : '',
			$post_id
		);
	}

	/**
	 * Read provider-computed output. This is evidence only and is never written.
	 */
	public static function effective_fields( int $post_id ): array {
		return [
			'seo_title'       => (string) tsf()->title()->get_title( [ 'id' => $post_id ] ),
			'meta_description' => (string) tsf()->description()->get_description( [ 'id' => $post_id ] ),
		];
	}

	private static function plugin_path(): string {
		if ( ! defined( 'WP_PLUGIN_DIR' ) || '' === (string) WP_PLUGIN_DIR ) {
			return '';
		}

		return rtrim( (string) WP_PLUGIN_DIR, '/\\' ) . '/' . self::PLUGIN_FILE;
	}

	/**
	 * Read only the inactive plugin header. Never require/include the provider.
	 */
	private static function inactive_version( string $path ): ?string {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$header = file_get_contents( $path, false, null, 0, 8192 );
		if ( ! is_string( $header ) ) {
			return null;
		}
		if ( preg_match( '/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $header, $matches ) ) {
			return trim( (string) $matches[1] );
		}

		return null;
	}

	private static function api_ready(): bool {
		try {
			$root     = tsf();
			$post_api = $root->data()->plugin()->post();
			return is_object( $post_api )
				&& is_callable( [ $post_api, 'get_meta_item' ] )
				&& is_callable( [ $post_api, 'get_default_meta' ] )
				&& is_callable( [ $post_api, 'update_single_meta_item' ] )
				&& is_callable( [ $post_api, 'refresh_static_properties' ] )
				&& is_callable( [ $root->sanitize(), 'metadata_content' ] )
				&& is_callable( [ $root->post_type(), 'is_supported' ] )
				&& is_callable( [ $root->title(), 'get_title' ] )
				&& is_callable( [ $root->description(), 'get_description' ] );
		} catch ( Throwable $error ) {
			return false;
		}
	}
}

trait DiviOps_Agent_SEO {
	public static function seo_provider_list( $request ) {
		return self::envelope_success( [
			'providers' => [ DiviOps_SEO_TSF_Adapter::discovery() ],
			'count'     => 1,
			'selection' => [
				'default' => 'auto',
				'auto'    => 'Exactly one active, compatible supported adapter is required.',
			],
		] );
	}

	public static function seo_metadata_get( $request ) {
		$post_id = absint( $request['id'] ?? 0 );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( $post_id <= 0 ) {
			return self::envelope_error(
				'invalid_input',
				'post_id must be a positive integer.',
				'Pass a positive WordPress post ID.',
				400,
				[ 'field' => 'post_id' ]
			);
		}
		if ( ! $post ) {
			return self::envelope_error(
				'not_found',
				"Post #{$post_id} was not found.",
				'Confirm the target ID before requesting stored SEO metadata.',
				404,
				[ 'post_id' => $post_id ]
			);
		}

		// This row-level gate must run before adapter state reads any explicit
		// provider payload.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return self::envelope_error(
				'forbidden',
				"Cannot inspect explicit SEO metadata for post #{$post_id}.",
				'Authenticate as a user with edit rights to this post.',
				403,
				[ 'post_id' => $post_id, 'payload_exposed' => false ]
			);
		}

		$provider = self::seo_resolve_provider( $request->get_param( 'provider' ) ?? 'auto' );
		if ( $provider instanceof WP_REST_Response ) {
			return $provider;
		}

		$supported = self::seo_require_supported_post_type( $post );
		if ( $supported instanceof WP_REST_Response ) {
			return $supported;
		}

		try {
			$state     = DiviOps_SEO_TSF_Adapter::read_fields( $post_id );
			$effective = DiviOps_SEO_TSF_Adapter::effective_fields( $post_id );
		} catch ( Throwable $error ) {
			return self::seo_provider_runtime_error( $error, $post_id );
		}

		return self::envelope_success( self::seo_read_payload( $post, $provider, $state, $effective ) );
	}

	public static function seo_metadata_update( $request ) {
		$post_id = absint( $request['id'] ?? 0 );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( $post_id <= 0 ) {
			return self::envelope_error( 'invalid_input', 'post_id must be a positive integer.', 'Pass a positive WordPress post ID.', 400, [ 'field' => 'post_id' ] );
		}
		if ( ! $post ) {
			return self::envelope_error( 'not_found', "Post #{$post_id} was not found.", 'Confirm the target ID before updating SEO metadata.', 404, [ 'post_id' => $post_id ] );
		}

		// Authoritative payload/write boundary. No provider value is read first.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return self::envelope_error(
				'forbidden',
				"Cannot update explicit SEO metadata for post #{$post_id}.",
				'Authenticate as a user with edit rights to this post.',
				403,
				[ 'post_id' => $post_id, 'payload_exposed' => false, 'mutated' => false ]
			);
		}

		$provider = self::seo_resolve_provider( $request->get_param( 'provider' ) ?? 'auto' );
		if ( $provider instanceof WP_REST_Response ) {
			return $provider;
		}
		$supported = self::seo_require_supported_post_type( $post );
		if ( $supported instanceof WP_REST_Response ) {
			return $supported;
		}

		$schema = self::seo_validate_update_request( $request );
		if ( isset( $schema['error'] ) ) {
			return self::seo_validation_error_response( $schema['error'] );
		}

		try {
			$before                  = DiviOps_SEO_TSF_Adapter::read_fields( $post_id );
			$before_registered_state = DiviOps_SEO_TSF_Adapter::read_registered_state( $post_id );
		} catch ( Throwable $error ) {
			return self::seo_provider_runtime_error( $error, $post_id );
		}

		$before_checksum   = self::seo_checksum( $post_id, $provider['version'], $before );
		$expected_checksum = (string) $request->get_param( 'expected_checksum' );
		if ( ! hash_equals( $before_checksum, $expected_checksum ) ) {
			return self::envelope_error(
				'seo.metadata_drift',
				"SEO metadata changed before post #{$post_id} could be updated.",
				'Re-read diviops_seo_metadata_get and retry with its checksum. There is no force path.',
				409,
				[
					'post_id'           => $post_id,
					'provider'          => DiviOps_SEO_TSF_Adapter::ID,
					'expected_checksum' => $expected_checksum,
					'current_checksum'  => $before_checksum,
					'mutated'           => false,
				]
			);
		}

		$prepared = self::seo_prepare_changes( $schema['changes'], $before );
		if ( isset( $prepared['error'] ) ) {
			return self::seo_validation_error_response( $prepared['error'] );
		}

		$target                  = $prepared['target'];
		$change_evidence         = $prepared['changes'];
		$after_checksum          = self::seo_checksum( $post_id, $provider['version'], $target );
		$target_registered_state = DiviOps_SEO_TSF_Adapter::with_v1_state( $before_registered_state, $target );
		$dry_run                = (bool) $request->get_param( 'dry_run' );
		$noop                   = self::seo_states_equal( $before, $target );

		if ( $dry_run ) {
			$plan_changes = [];
			foreach ( $change_evidence as $change ) {
				$plan_changes[] = [
					'kind'   => 'seo.metadata.' . $change['action'],
					'target' => "post#{$post_id}:tsf:" . $change['field'],
					'before' => $change['before'],
					'after'  => $change['after'],
				];
			}

			return self::dry_run_response(
				$noop
					? "SEO metadata on post #{$post_id} already matches the requested explicit state."
					: sprintf( 'Would update %d explicit TSF SEO field(s) on post #%d.', count( $change_evidence ), $post_id ),
				$plan_changes,
				[],
				[
					'provider'          => [ 'id' => DiviOps_SEO_TSF_Adapter::ID, 'version' => $provider['version'] ],
					'current_checksum'  => $before_checksum,
					'proposed_checksum' => $after_checksum,
					'noop'              => $noop,
					'write_applied'     => false,
				]
			);
		}

		if ( $noop ) {
			return self::envelope_success( self::seo_update_success_payload(
				$post,
				$provider,
				$before_checksum,
				$after_checksum,
				$change_evidence,
				$before,
				true,
				false
			) );
		}

		$touched = array_column( $change_evidence, 'field' );
		try {
			foreach ( $touched as $field ) {
				DiviOps_SEO_TSF_Adapter::write_field(
					$post_id,
					$field,
					(bool) $target[ $field ]['explicit'],
					(string) $target[ $field ]['value']
				);
			}
			$readback                  = DiviOps_SEO_TSF_Adapter::read_fields( $post_id );
			$registered_state_readback = DiviOps_SEO_TSF_Adapter::read_registered_state( $post_id );
		} catch ( Throwable $error ) {
			return self::seo_apply_failure_with_rollback(
				$post_id,
				$before,
				$before_registered_state,
				$target_registered_state,
				$error,
				null,
				null
			);
		}

		if ( ! self::seo_states_equal( $readback, $target )
			|| ! DiviOps_SEO_TSF_Adapter::registered_states_equal( $registered_state_readback, $target_registered_state ) ) {
			return self::seo_apply_failure_with_rollback(
				$post_id,
				$before,
				$before_registered_state,
				$target_registered_state,
				null,
				$readback,
				$registered_state_readback
			);
		}

		return self::envelope_success( self::seo_update_success_payload(
			$post,
			$provider,
			$before_checksum,
			self::seo_checksum( $post_id, $provider['version'], $readback ),
			$change_evidence,
			$readback,
			false,
			true
		) );
	}

	private static function seo_resolve_provider( $requested ) {
		if ( ! is_string( $requested ) || ! in_array( $requested, [ 'auto', DiviOps_SEO_TSF_Adapter::ID ], true ) ) {
			return self::envelope_error(
				'seo.provider_unsupported',
				'The requested SEO provider has no accepted DiviOps adapter.',
				'Use provider "auto" or "tsf". No generic postmeta fallback exists.',
				400,
				[ 'provider' => is_scalar( $requested ) ? (string) $requested : null ]
			);
		}

		$provider = DiviOps_SEO_TSF_Adapter::discovery();
		if ( ! $provider['installed'] || ! $provider['active'] ) {
			return self::envelope_error(
				'seo.provider_absent',
				$provider['installed']
					? 'The SEO Framework is installed but inactive.'
					: 'The SEO Framework is not installed.',
				'Install and activate a supported TSF version through normal WordPress administration, then retry. DiviOps never loads an inactive provider.',
				412,
				[
					'provider'  => DiviOps_SEO_TSF_Adapter::ID,
					'installed' => (bool) $provider['installed'],
					'active'    => (bool) $provider['active'],
					'version'   => $provider['version'],
				]
			);
		}
		if ( ! $provider['compatible'] || ! $provider['readable'] || ! $provider['writable'] ) {
			return self::envelope_error(
				'seo.provider_incompatible',
				'The active The SEO Framework version does not expose the validated DiviOps adapter contract.',
				'Use a supported 5.x release at or above 5.1.4, then retry.',
				412,
				[
					'provider'        => DiviOps_SEO_TSF_Adapter::ID,
					'version'         => $provider['version'],
					'version_support' => $provider['version_support'],
					'api_ready'       => (bool) ( $provider['readable'] && $provider['writable'] ),
				]
			);
		}

		return $provider;
	}

	private static function seo_require_supported_post_type( $post ) {
		try {
			$supported = DiviOps_SEO_TSF_Adapter::supports_post_type( (string) $post->post_type );
		} catch ( Throwable $error ) {
			return self::seo_provider_runtime_error( $error, (int) $post->ID );
		}
		if ( ! $supported ) {
			return self::envelope_error(
				'seo.post_type_unsupported',
				"The SEO Framework does not support post type '{$post->post_type}' for this target.",
				'Choose a provider-supported public post or page.',
				422,
				[ 'post_id' => (int) $post->ID, 'post_type' => (string) $post->post_type ]
			);
		}
		return true;
	}

	private static function seo_validate_update_request( $request ): array {
		$expected_checksum = $request->get_param( 'expected_checksum' );
		if ( ! is_string( $expected_checksum ) || 1 !== preg_match( '/^sha256:[a-f0-9]{64}$/', $expected_checksum ) ) {
			return [ 'error' => [
				'code'    => 'invalid_input',
				'message' => 'expected_checksum must be a lowercase SHA-256 checksum from diviops_seo_metadata_get.',
				'hint'    => 'Read the target first, then pass its exact checksum.',
				'data'    => [ 'field' => 'expected_checksum', 'mutated' => false ],
			] ];
		}

		$changes = $request->get_param( 'changes' );
		if ( ! is_array( $changes ) || count( $changes ) < 1 || count( $changes ) > 2 || array_values( $changes ) !== $changes ) {
			return [ 'error' => [
				'code'    => 'invalid_input',
				'message' => 'changes must be a list containing one or two strict operation objects.',
				'hint'    => 'Pass one operation per supported semantic field.',
				'data'    => [ 'field' => 'changes' ],
			] ];
		}

		$normalized = [];
		$seen       = [];
		foreach ( $changes as $index => $change ) {
			if ( ! is_array( $change ) || array_values( $change ) === $change ) {
				return [ 'error' => [
					'code'    => 'invalid_input',
					'message' => "changes[{$index}] must be an object.",
					'hint'    => 'Use {field, action, value?}; arrays and objects as values are forbidden.',
					'data'    => [ 'field' => "changes[{$index}]" ],
				] ];
			}

			$field  = $change['field'] ?? null;
			$action = $change['action'] ?? null;
			if ( ! is_string( $field ) || ! in_array( $field, self::seo_fields(), true ) ) {
				return [ 'error' => self::seo_field_error( $index, $field ) ];
			}
			if ( isset( $seen[ $field ] ) ) {
				return [ 'error' => [
					'code'    => 'invalid_input',
					'message' => "Duplicate operation for semantic field '{$field}'.",
					'hint'    => 'Pass at most one operation for each semantic field.',
					'data'    => [ 'field' => $field, 'first_index' => $seen[ $field ], 'duplicate_index' => $index ],
				] ];
			}
			if ( ! is_string( $action ) || ! in_array( $action, [ 'set', 'clear' ], true ) ) {
				return [ 'error' => [
					'code'    => 'invalid_input',
					'message' => "changes[{$index}].action must be set or clear.",
					'hint'    => 'Use set with a string value, or clear without value.',
					'data'    => [ 'field' => "changes[{$index}].action" ],
				] ];
			}

			$allowed = 'set' === $action ? [ 'field', 'action', 'value' ] : [ 'field', 'action' ];
			$unknown = array_values( array_diff( array_keys( $change ), $allowed ) );
			if ( ! empty( $unknown ) ) {
				return [ 'error' => [
					'code'    => 'invalid_input',
					'message' => "changes[{$index}] contains unknown or action-incompatible properties.",
					'hint'    => 'set accepts exactly field, action, value; clear accepts exactly field and action.',
					'data'    => [ 'field' => "changes[{$index}]", 'unknown_properties' => $unknown ],
				] ];
			}
			if ( array_values( array_keys( $change ) ) !== array_values( array_unique( array_keys( $change ) ) ) ) {
				return [ 'error' => [ 'code' => 'invalid_input', 'message' => "changes[{$index}] contains duplicate properties.", 'data' => [ 'field' => "changes[{$index}]" ] ] ];
			}
			if ( 'set' === $action && ! array_key_exists( 'value', $change ) ) {
				return [ 'error' => [
					'code'    => 'invalid_input',
					'message' => "changes[{$index}].value is required for set.",
					'hint'    => 'Pass a plain-text string value.',
					'data'    => [ 'field' => "changes[{$index}].value" ],
				] ];
			}

			$seen[ $field ] = $index;
			$normalized[]   = [
				'field'  => $field,
				'action' => $action,
				'value'  => $change['value'] ?? null,
			];
		}

		return [ 'changes' => $normalized ];
	}

	private static function seo_field_error( int $index, $field ): array {
		return [
			'code'    => 'seo.field_unsupported',
			'message' => "changes[{$index}].field is outside the V1 semantic allowlist.",
			'hint'    => 'Use only seo_title or meta_description. Raw metadata keys are forbidden.',
			'data'    => [ 'field' => is_scalar( $field ) ? (string) $field : null, 'allowed' => self::seo_fields() ],
		];
	}

	private static function seo_prepare_changes( array $changes, array $before ): array {
		$target   = $before;
		$evidence = [];

		foreach ( $changes as $change ) {
			$field = $change['field'];
			if ( 'clear' === $change['action'] ) {
				$target[ $field ] = [ 'explicit' => false, 'value' => '' ];
				$evidence[]       = [
					'field'           => $field,
					'action'          => 'clear',
					'before'          => $before[ $field ],
					'after'           => $target[ $field ],
					'sanitized_value' => null,
				];
				continue;
			}

			$validation = self::seo_validate_plain_text( $change['value'], $field );
			if ( isset( $validation['error'] ) ) {
				return $validation;
			}
			try {
				$sanitized = DiviOps_SEO_TSF_Adapter::sanitize( $validation['value'] );
			} catch ( Throwable $error ) {
				return [ 'error' => [
					'code'    => 'wp_error',
					'message' => 'The SEO Framework sanitizer failed before mutation.',
					'hint'    => 'Confirm the active provider is healthy and retry.',
					'status'  => 500,
					'data'    => [ 'field' => $field, 'mutated' => false, 'detail' => self::seo_safe_error_message( $error ) ],
				] ];
			}
			if ( '' === $sanitized ) {
				return [ 'error' => [
					'code'    => 'invalid_input',
					'message' => "Provider sanitization produced an empty {$field} value.",
					'hint'    => 'Use action clear to remove explicit metadata.',
					'data'    => [ 'field' => $field, 'mutated' => false ],
				] ];
			}

			$target[ $field ] = [ 'explicit' => true, 'value' => $sanitized ];
			$evidence[]       = [
				'field'           => $field,
				'action'          => 'set',
				'requested_value' => $validation['value'],
				'sanitized_value' => $sanitized,
				'before'          => $before[ $field ],
				'after'           => $target[ $field ],
			];
		}

		return [ 'target' => $target, 'changes' => $evidence ];
	}

	private static function seo_validate_plain_text( $value, string $field ): array {
		if ( ! is_string( $value ) ) {
			return [ 'error' => [
				'code'    => 'seo.serialized_value_unsupported',
				'message' => "{$field} must be a scalar plain-text string.",
				'hint'    => 'Arrays, objects, resources, and serialized values are forbidden.',
				'data'    => [ 'field' => $field, 'received_type' => gettype( $value ), 'mutated' => false ],
			] ];
		}
		if ( 1 !== preg_match( '//u', $value ) ) {
			return [ 'error' => [
				'code'    => 'invalid_input',
				'message' => "{$field} must contain valid UTF-8.",
				'hint'    => 'Re-encode the value as valid UTF-8 before retrying.',
				'data'    => [ 'field' => $field, 'reason' => 'invalid_encoding', 'mutated' => false ],
			] ];
		}
		if ( preg_match( '/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', $value ) ) {
			return [ 'error' => [
				'code'    => 'invalid_input',
				'message' => "{$field} contains control characters.",
				'hint'    => 'Pass one line of plain text without control bytes.',
				'data'    => [ 'field' => $field, 'reason' => 'control_character', 'mutated' => false ],
			] ];
		}
		if ( self::seo_looks_serialized( $value ) ) {
			return [ 'error' => [
				'code'    => 'seo.serialized_value_unsupported',
				'message' => "{$field} looks like serialized PHP data.",
				'hint'    => 'Pass literal plain text, never a serialized value.',
				'data'    => [ 'field' => $field, 'mutated' => false ],
			] ];
		}
		if ( self::seo_looks_like_markup( $value ) ) {
			return [ 'error' => [
				'code'    => 'invalid_input',
				'message' => "{$field} contains HTML or markup-looking input.",
				'hint'    => 'Pass plain text only; DiviOps refuses markup before provider sanitization.',
				'data'    => [ 'field' => $field, 'reason' => 'markup', 'mutated' => false ],
			] ];
		}
		if ( self::seo_has_dynamic_tokens( $value ) ) {
			return [ 'error' => [
				'code'    => 'seo.dynamic_value_unsupported',
				'message' => "{$field} contains an unresolved dynamic or replacement token.",
				'hint'    => 'Resolve Divi, global-variable, shortcode, template, and provider variables to explicit plain text first.',
				'data'    => [ 'field' => $field, 'mutated' => false ],
			] ];
		}
		if ( self::seo_looks_secret_like( $value ) ) {
			return [ 'error' => [
				'code'    => 'seo.secret_like_value',
				'message' => "{$field} resembles a credential or private key.",
				'hint'    => 'Do not store credentials in SEO metadata.',
				'data'    => [ 'field' => $field, 'mutated' => false ],
			] ];
		}

		$limits = 'seo_title' === $field
			? [ 'characters' => 512, 'bytes' => 2048 ]
			: [ 'characters' => 2048, 'bytes' => 8192 ];
		$chars  = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : preg_match_all( '/./us', $value, $unused );
		if ( false === $chars || $chars > $limits['characters'] || strlen( $value ) > $limits['bytes'] ) {
			return [ 'error' => [
				'code'    => 'invalid_input',
				'message' => "{$field} exceeds the transport safety ceiling.",
				'hint'    => sprintf( 'Limit this field to %d Unicode characters and %d UTF-8 bytes.', $limits['characters'], $limits['bytes'] ),
				'data'    => [ 'field' => $field, 'limits' => $limits, 'mutated' => false ],
			] ];
		}

		return [ 'value' => $value ];
	}

	private static function seo_looks_serialized( string $value ): bool {
		if ( function_exists( 'is_serialized' ) && is_serialized( $value ) ) {
			return true;
		}
		return 1 === preg_match( '/^(?:a|O|C|s|i|b|d|N):(?:\d+[:;]|\{)/s', trim( $value ) );
	}

	private static function seo_looks_like_markup( string $value ): bool {
		return 1 === preg_match( '/(?:<\s*\/?\s*[A-Za-z][^>]*>|<\s*[!?][^>]*>|&lt;\s*\/?\s*[A-Za-z][^&]*&gt;|<!--|-->)/i', $value );
	}

	private static function seo_has_dynamic_tokens( string $value ): bool {
		return 1 === preg_match(
			'/(?:\$variable\s*\(|@ET-DC@[^@]*@|var\(\s*--|\{\{.*?\}\}|\{%.*?%\}|\[(?:[A-Za-z][A-Za-z0-9_-]*)(?:\s[^\]]*)?\]|%%?[A-Za-z][A-Za-z0-9_.-]*%%?)/is',
			$value
		);
	}

	private static function seo_looks_secret_like( string $value ): bool {
		return 1 === preg_match( '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|\b(?:sk|api|token|secret)[_-][A-Za-z0-9_-]{24,}\b/i', $value );
	}

	private static function seo_checksum( int $post_id, string $provider_version, array $state ): string {
		$fields = [];
		foreach ( self::seo_fields() as $field ) {
			$fields[] = [
				'field'        => $field,
				'explicit'     => (bool) $state[ $field ]['explicit'],
				'stored_value' => (string) $state[ $field ]['value'],
			];
		}
		$payload = [
			'post_id'  => $post_id,
			'provider' => [ 'id' => DiviOps_SEO_TSF_Adapter::ID, 'version' => $provider_version ],
			'fields'   => $fields,
		];
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return 'sha256:' . hash( 'sha256', (string) $json );
	}

	private static function seo_states_equal( array $left, array $right ): bool {
		return self::seo_states_equal_for_fields( $left, $right, self::seo_fields() );
	}

	private static function seo_states_equal_for_fields( array $left, array $right, array $fields ): bool {
		foreach ( $fields as $field ) {
			if ( ! isset( $left[ $field ], $right[ $field ] ) ) {
				return false;
			}
			if ( (bool) $left[ $field ]['explicit'] !== (bool) $right[ $field ]['explicit'] ) {
				return false;
			}
			if ( (string) $left[ $field ]['value'] !== (string) $right[ $field ]['value'] ) {
				return false;
			}
		}
		return true;
	}

	private static function seo_read_payload( $post, array $provider, array $state, array $effective ): array {
		return [
			'post'      => self::seo_post_evidence( $post ),
			'provider'  => [
				'id'           => DiviOps_SEO_TSF_Adapter::ID,
				'name'         => DiviOps_SEO_TSF_Adapter::NAME,
				'version'      => $provider['version'],
				'adapter'      => $provider['adapter'],
				'capabilities' => $provider['capabilities'],
			],
			'fields'    => self::seo_field_rows( $state, $effective, 'verified_current_request' ),
			'checksum'  => self::seo_checksum( (int) $post->ID, $provider['version'], $state ),
			'lifecycle' => [
				'provider_refresh'                  => 'tsf_public_php_api',
				'effective_output_verified'          => true,
				'effective_output_verification_mode' => 'current_read_request',
			],
			'cache'     => [ 'divi' => 'not_applicable', 'provider' => 'read_only' ],
		];
	}

	private static function seo_update_success_payload( $post, array $provider, string $before_checksum, string $after_checksum, array $changes, array $state, bool $noop, bool $write_applied ): array {
		return [
			'post'                                 => self::seo_post_evidence( $post ),
			'provider'                             => [ 'id' => DiviOps_SEO_TSF_Adapter::ID, 'version' => $provider['version'], 'adapter' => $provider['adapter'] ],
			'before_checksum'                      => $before_checksum,
			'after_checksum'                       => $after_checksum,
			'changes'                              => $changes,
			'fields'                               => self::seo_field_rows( $state, null, 'followup_request_required' ),
			'noop'                                 => $noop,
			'write_applied'                        => $write_applied,
			'readback_verified'                    => true,
			'provider_state_readback_verified'    => true,
			'rollback'                             => [ 'attempted' => false, 'verified' => false ],
			'lifecycle'                            => [
				'provider_write'                    => $write_applied ? 'tsf_update_single_meta_item' : 'not_applicable_noop',
				'provider_refresh'                  => $write_applied ? 'tsf_save_meta_lifecycle' : 'not_applicable_noop',
				'effective_output_verified'          => false,
				'effective_output_verification_mode' => 'followup_get_required',
			],
			'cache'                                => [
				'divi'     => 'not_applicable',
				'provider' => $write_applied ? 'provider_lifecycle_managed' : 'not_touched',
			],
		];
	}

	private static function seo_field_rows( array $state, ?array $effective, string $effective_status ): array {
		$rows = [];
		foreach ( self::seo_fields() as $field ) {
			$rows[] = [
				'field'                   => $field,
				'supported'               => true,
				'writable'                => true,
				'explicit'                => (bool) $state[ $field ]['explicit'],
				'stored_value'            => (string) $state[ $field ]['value'],
				'effective_value'         => null === $effective ? null : (string) $effective[ $field ],
				'effective_status'        => $effective_status,
				'dynamic_tokens_detected' => self::seo_has_dynamic_tokens( (string) $state[ $field ]['value'] ),
			];
		}
		return $rows;
	}

	private static function seo_post_evidence( $post ): array {
		$published = 'publish' === (string) $post->post_status;
		$permalink = function_exists( 'get_permalink' ) ? get_permalink( (int) $post->ID ) : null;
		$canonical = null;
		if ( $published && function_exists( 'wp_get_canonical_url' ) ) {
			$canonical = wp_get_canonical_url( (int) $post->ID ) ?: null;
		}

		return [
			'id'                 => (int) $post->ID,
			'post_type'          => (string) $post->post_type,
			'status'             => (string) $post->post_status,
			'permalink'          => $permalink ?: null,
			'canonical_identity' => $canonical,
		];
	}

	private static function seo_apply_failure_with_rollback( int $post_id, array $before, array $before_registered_state, array $target_registered_state, ?Throwable $cause, ?array $mismatched_readback, ?array $registered_state_readback ) {
		$rollback = self::seo_restore_before_state( $post_id, $before, $before_registered_state );
		$data     = [
			'post_id'                              => $post_id,
			'mutated'                              => true,
			'readback_verified'                    => false,
			'provider_state_readback_verified'    => false,
			'rollback'                             => $rollback,
			'cache'                                => [ 'divi' => 'not_applicable', 'provider' => 'provider_lifecycle_managed' ],
		];
		if ( null !== $cause ) {
			$data['provider_error'] = self::seo_safe_error_message( $cause );
		}
		if ( null !== $mismatched_readback ) {
			$data['readback'] = self::seo_field_rows( $mismatched_readback, null, 'readback_mismatch' );
		}
		if ( null !== $registered_state_readback ) {
			$data['provider_state_changed_field_count'] = DiviOps_SEO_TSF_Adapter::registered_state_diff_count(
				$target_registered_state,
				$registered_state_readback
			);
		}

		if ( ! $rollback['verified'] ) {
			return self::envelope_error(
				'seo.rollback_failed',
				'SEO metadata apply failed and request-local restoration did not verify.',
				'Stop writes and inspect the target through diviops_seo_metadata_get before any manual recovery.',
				500,
				$data
			);
		}

		return self::envelope_error(
			'seo.readback_mismatch',
			'SEO metadata did not match the requested sanitized state after apply; the before-state was restored and verified.',
			'Re-read the target and provider health before retrying.',
			500,
			$data
		);
	}

	private static function seo_restore_before_state( int $post_id, array $before, array $before_registered_state ): array {
		$result = [
			'attempted'                          => true,
			'verified'                           => false,
			'fields'                             => self::seo_fields(),
			'provider_registered_state_restored' => false,
		];
		try {
			DiviOps_SEO_TSF_Adapter::restore_registered_state( $post_id, $before_registered_state );
			$restored                  = DiviOps_SEO_TSF_Adapter::read_fields( $post_id );
			$registered_state_restored = DiviOps_SEO_TSF_Adapter::read_registered_state( $post_id );
			$result['provider_registered_state_restored'] = DiviOps_SEO_TSF_Adapter::registered_states_equal(
				$registered_state_restored,
				$before_registered_state
			);
			$result['verified'] = self::seo_states_equal( $restored, $before )
				&& $result['provider_registered_state_restored'];
			if ( ! $result['verified'] ) {
				$result['reason'] = 'restored_state_mismatch';
			}
		} catch ( Throwable $error ) {
			$result['reason'] = 'provider_restore_error';
			$result['detail'] = self::seo_safe_error_message( $error );
		}
		return $result;
	}

	private static function seo_validation_error_response( array $error ) {
		return self::envelope_error(
			$error['code'] ?? 'invalid_input',
			$error['message'] ?? 'SEO metadata input is invalid.',
			$error['hint'] ?? 'Fix the named field and retry.',
			$error['status'] ?? 400,
			$error['data'] ?? null
		);
	}

	private static function seo_provider_runtime_error( Throwable $error, int $post_id ) {
		return self::envelope_error(
			'wp_error',
			'The SEO Framework adapter could not complete the requested operation.',
			'Confirm the active provider is healthy and compatible, then retry.',
			500,
			[ 'post_id' => $post_id, 'provider' => DiviOps_SEO_TSF_Adapter::ID, 'detail' => self::seo_safe_error_message( $error ) ]
		);
	}

	private static function seo_safe_error_message( Throwable $error ): string {
		$message = trim( $error->getMessage() );
		return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 240 ) : substr( $message, 0, 240 );
	}

	private static function seo_fields(): array {
		return [ 'seo_title', 'meta_description' ];
	}
}
