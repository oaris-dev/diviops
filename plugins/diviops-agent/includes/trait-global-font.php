<?php
/**
 * Trait DiviOps_Agent_GlobalFont
 *
 * Global font registry read.
 *
 * Mixed into DiviOps_Agent via `use` in diviops-agent.php — `self::` calls and
 * class constants resolve as if these methods lived directly on the class.
 *
 * Single read handler routes through envelope_success.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_GlobalFont {

	/**
	 * Get global fonts.
	 */
	public static function global_font_list( $request ) {
		$global_fonts = et_get_option( 'et_global_fonts', [] );
		return self::envelope_success( $global_fonts );
	}
}
