<?php
/** One existing SCF text value; no definition, layout or snapshot writes. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_SCF {
	private static function scf_text_provider_ready(): bool {
		$plugin  = 'secure-custom-fields/secure-custom-fields.php';
		$active  = (array) get_option( 'active_plugins', [] );
		$network = (array) get_site_option( 'active_sitewide_plugins', [] );
		if ( ! in_array( $plugin, $active, true ) && ! array_key_exists( $plugin, $network ) ) {
			return false;
		}
		foreach ( [ 'acf_get_setting', 'acf_get_field', 'acf_get_field_group', 'acf_get_field_group_visibility', 'acf_validate_value', 'update_field' ] as $function ) {
			if ( ! function_exists( $function ) ) {
				return false;
			}
		}
		return class_exists( 'SCF\\Meta\\Post', false ) && '6.9.4' === acf_get_setting( 'version' );
	}

	private static function scf_text_plain( $value ): bool {
		// This initial contract excludes empty/"0", markup and multiline values,
		// not because SCF cannot store them, but to keep the first workflow finite.
		return is_string( $value ) && '' !== trim( $value ) && '0' !== $value
			&& strlen( $value ) <= 4096 && 1 === preg_match( '//u', $value )
			&& ! preg_match( '/[<>\x00-\x1f\x7f]/', $value );
	}

	private static function scf_text_context( int $post_id, string $key ) {
		$post = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return self::envelope_error( 'scf.forbidden', 'An editable existing post is required.', null, 403 );
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || empty( $type->show_ui ) || ( ! empty( $type->_builtin ) && ! in_array( $post->post_type, [ 'post', 'page' ], true ) )
			|| preg_match( '/^(acf-|et_|wp_)/', $post->post_type ) || in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) ) {
			return self::envelope_error( 'scf.unsupported_post', 'Only an ordinary existing post or CPT record is supported.', null, 422 );
		}
		if ( ! self::scf_text_provider_ready() ) {
			return self::envelope_error( 'scf.unsupported_provider', 'This writer requires active SCF 6.9.4 and its field APIs.', 'Other SCF/ACF versions are not qualified by this contract.', 412 );
		}
		$field = acf_get_field( $key );
		if ( ! is_array( $field ) || $key !== ( $field['key'] ?? null ) || 'text' !== ( $field['type'] ?? null )
			|| ! preg_match( '/^[A-Za-z][A-Za-z0-9_]*$/D', $field['name'] ?? '' )
			|| ! empty( $field['conditional_logic'] ) || ! empty( $field['readonly'] ) || ! empty( $field['disabled'] ) ) {
			return self::envelope_error( 'scf.unsupported_field', 'An existing unconditional, editable top-level text field is required.', null, 422 );
		}
		if ( ! current_user_can( 'edit_post_meta', $post_id, $field['name'] ) ) {
			return self::envelope_error( 'scf.forbidden', 'Editing this field is not permitted.', null, 403 );
		}
		$group = acf_get_field_group( $field['parent'] ?? 0 );
		if ( ! is_array( $group ) || empty( $field['parent'] ) || empty( $group['active'] )
			|| ! in_array( (string) $field['parent'], [ (string) ( $group['ID'] ?? '' ), (string) ( $group['key'] ?? '' ) ], true )
			|| 0 !== strpos( $group['key'] ?? '', 'group_' )
			|| ! acf_get_field_group_visibility( $group, [ 'post_id' => $post_id, 'post_type' => $post->post_type ] ) ) {
			return self::envelope_error( 'scf.inapplicable_field', 'The field must belong directly to an active group applicable to this post.', null, 422 );
		}
		return [ 'field' => $field, 'group' => $group ];
	}

	private static function scf_text_persisted( int $post_id, string $name ): array {
		global $wpdb;
		// Bypass value/cache filters: read exactly the two persisted rows. Decode
		// WordPress metadata serialization once, as get_post_meta would do.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN (%s, %s) ORDER BY meta_id",
			$post_id, $name, '_' . $name
		), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'SCF persisted read unavailable.' );
		}
		$values = [];
		$refs   = [];
		foreach ( $rows as $row ) {
			if ( $name === $row['meta_key'] ) {
				$values[] = maybe_unserialize( $row['meta_value'] );
			} elseif ( '_' . $name === $row['meta_key'] ) {
				$refs[] = maybe_unserialize( $row['meta_value'] );
			}
		}
		return [ 'values' => $values, 'references' => $refs ];
	}

	public static function scf_text_value_update( $request ) {
		$post_id  = $request->get_param( 'post_id' );
		$key      = $request->get_param( 'field_key' );
		$expected = $request->get_param( 'expected_value' );
		$value    = $request->get_param( 'value' );
		$dry_run  = $request->get_param( 'dry_run' ) ?? true;
		if ( ! is_int( $post_id ) || $post_id < 1 || ! is_string( $key ) || ! preg_match( '/^field_[A-Za-z0-9_]+$/D', $key )
			|| ! self::scf_text_plain( $expected ) || ! self::scf_text_plain( $value ) || ! is_bool( $dry_run ) ) {
			return self::envelope_error( 'scf.invalid_input', 'Supply a positive post_id, exact field_key, and plain single-line expected_value/value strings (1-4096 UTF-8 bytes; empty and "0" excluded).', null, 400 );
		}
		$attempted = false;
		try {
			$context = self::scf_text_context( $post_id, $key );
			if ( ! is_array( $context ) ) {
				return $context;
			}
			$field  = $context['field'];
			$before = self::scf_text_persisted( $post_id, $field['name'] );
			if ( [ $key ] !== $before['references'] || 1 !== count( $before['values'] ) || ! self::scf_text_plain( $before['values'][0] ) ) {
				return self::envelope_error( 'scf.invalid_state', 'Exactly one existing text value and its matching field reference are required.', null, 409 );
			}
			if ( [ $expected ] !== $before['values'] ) {
				return self::envelope_error( 'scf.conflict', 'The stored value differs from expected_value.', 'Read and review current state before another request.', 409 );
			}
			$noop = $expected === $value;
			if ( ! $noop && ! acf_validate_value( $value, $field, 'acf[' . $key . ']' ) ) {
				return self::envelope_error( 'scf.validation_failed', 'SCF field validation rejected the replacement.', 'Review the configured field constraints in the native editor; provider messages are not exposed.', 422 );
			}
			// Validation/location hooks are provider code. Recheck their consumed
			// context and the expected state; this is not an atomic compare-and-swap.
			$fresh = self::scf_text_context( $post_id, $key );
			if ( ! is_array( $fresh ) ) {
				return $fresh;
			}
			if ( $fresh !== $context || self::scf_text_persisted( $post_id, $field['name'] ) !== $before ) {
				return self::envelope_error( 'scf.conflict', 'Field context or stored state changed during review.', 'Review again; no provider update was attempted.', 409 );
			}
			$data = [ 'post_id' => $post_id, 'field_key' => $key, 'dry_run' => $dry_run, 'noop' => $noop, 'write_applied' => false ];
			if ( $dry_run ) {
				$data['plan'] = [
					'summary' => $noop ? 'Text is unchanged.' : 'Update one existing SCF text value.',
					'changes' => $noop ? [] : [ [ 'kind' => 'scf.text_value_update', 'target' => 'post:' . $post_id . '/field:' . $key, 'field' => $key, 'before' => $expected, 'after' => $value ] ],
					'warnings' => [ 'Field validation only, not full native form save. Hooks may have side effects. No atomic CAS, snapshot backup, rollback or retry.' ],
				];
			} elseif ( ! $noop ) {
				$attempted = true;
				// REST JSON strings are unslashed; SCF forwards to update_metadata,
				// which unslashes once. Do not unslash the request or double-slash it.
				$provider_result = update_field( $key, wp_slash( $value ), $post_id );
				$after = self::scf_text_persisted( $post_id, $field['name'] );
				if ( [ 'values' => [ $value ], 'references' => [ $key ] ] !== $after ) {
					$unchanged = $after === $before;
					return self::envelope_error( $unchanged ? 'scf.write_failed' : 'scf.mutation_uncertain', 'The requested value/reference was not verified in storage.', 'No retry or rollback was attempted. Inspect the exact field before deciding recovery.', 500,
						[ 'provider_call_attempted' => true, 'readback_matches_before' => $unchanged, 'provider_returned_false' => false === $provider_result ] );
				}
				$data['write_applied'] = true;
				$data['readback_verified'] = true;
				$data['provider_returned_false'] = false === $provider_result;
			}
			return rest_ensure_response( [ 'ok' => true, 'data' => $data ] );
		} catch ( Throwable $error ) {
			return self::envelope_error( $attempted ? 'scf.mutation_uncertain' : 'scf.provider_failed', 'The SCF operation could not be verified.', 'No automatic retry or rollback. Provider diagnostics and field values are withheld.', 500,
				[ 'provider_call_attempted' => $attempted ] );
		}
	}
}
