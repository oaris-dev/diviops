<?php
/** Bounded active-theme CSS REST adapter. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-custom-css-store.php';

trait DiviOps_Agent_Custom_CSS {
	public static function custom_css_get( $request ) {
		return self::custom_css_response( DiviOps_Custom_CSS_Store::read() );
	}
	public static function custom_css_upsert( $request ) {
		return self::custom_css_response( DiviOps_Custom_CSS_Store::upsert(
			$request->get_param( 'name' ), $request->get_param( 'css' ),
			$request->get_param( 'expected_checksum' ), $request->get_param( 'dry_run' ) ?? true
		) );
	}
	public static function custom_css_restore( $request ) {
		return self::custom_css_response( DiviOps_Custom_CSS_Store::restore(
			$request->get_param( 'snapshot_id' ), $request->get_param( 'expected_checksum' ),
			$request->get_param( 'dry_run' ) ?? true
		) );
	}
	private static function custom_css_response( array $result ) {
		if ( ! $result['ok'] ) {
			$code = $result['error'] ?? 'recovery_failed';
			$hints = [
				'initialize_css_in_native_editor' => 'Save the intended CSS in Divi Theme Options > General > Custom CSS, then read again. Verify canonical CSS and the legacy mirror agree before previewing. No CSS post or theme mapping is created by this tool.',
				'conflict' => 'Read current CSS and inspect the change before creating a new preview. Do not blindly retry a write.',
				'legacy_divergence' => 'Canonical CSS and the legacy mirror differ. Review the intended CSS, save it in Divi Theme Options > General > Custom CSS, then read again and verify both values agree. Customizer can overwrite the legacy mirror; this tool does not reconcile it automatically.',
				'busy' => 'Another CSS operation holds the lock. After a crashed process, operator inspection is required; there is no automatic lock takeover.',
				'write_failed' => 'Inspect recovered and record_verified. A failed response can include changed CSS or cache state; do not retry the original write.',
				'finalization_failed' => 'CSS may already be applied. Read state and retain the snapshot ID for explicit recovery; do not retry the original write.',
				'recovery_failed' => 'Inspect recovered and record_verified. Preserve the snapshot and inspect current state before further action.',
				'unsupported_hooks' => 'Only the native Divi CSS data callbacks are supported; do not disable unrelated plugins automatically.',
				'preprocessed_css' => 'This tool does not edit CSS managed by a preprocessor.',
			];
			$status = in_array( $code, [ 'write_failed', 'finalization_failed', 'recovery_failed', 'snapshot_failed' ], true ) ? 500 : ( 'forbidden' === $code ? 403 : ( in_array( $code, [ 'conflict', 'busy', 'legacy_divergence' ], true ) ? 409 : 400 ) );
			unset( $result['ok'], $result['error'] );
			return self::envelope_error( 'custom_css.' . $code, 'Custom CSS operation refused or incomplete: ' . $code . '.', $hints[$code] ?? 'Inspect the bounded CSS contract and current state before retrying.', $status, $result );
		}
		unset( $result['ok'] );
		if ( ! empty( $result['dry_run'] ) ) {
			$plan = $result['plan'] ?? [];
			$result['plan'] = array_merge( $plan, [
				'summary' => isset( $result['snapshot_id'] ) ? 'Preview restoring owned Custom CSS state.' : 'Preview one named Custom CSS block.',
				'changes' => ! empty( $plan['noop'] ) ? [] : [ [ 'kind' => 'custom_css', 'before' => $plan['edit']['before'] ?? $result['before'], 'after' => $plan['edit']['after'] ?? $result['after'] ] ],
			] );
		}
		return self::envelope_success( $result );
	}
}
