<?php
/**
 * Trait DiviOps_Agent_Render
 *
 * Block markup → frontend HTML rendering.
 *
 * Part of the diviops-agent monolith split (#220). Mixed into
 * DiviOps_Agent via `use` in diviops-agent.php — `self::` calls and
 * class constants resolve as if these methods lived directly on the
 * class.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_Render {

	/**
	 * Render block markup to HTML.
	 *
	 * Envelope-adopted. Errors map to:
	 *   - invalid_input — content is not a string
	 *   - divi_error    — parse_blocks / render_block threw a Throwable
	 *
	 * Render exceptions can produce multi-kilobyte messages (full stack
	 * traces, embedded block JSON dumps). We cap `error.message` at 500
	 * chars for inline display by MCP clients and stash the full detail in
	 * `error.data.detail` for callers that need it.
	 */
	public static function render_block_markup( $request ) {
		$resolved = self::resolve_content_or_page_id( $request );
		if ( $resolved instanceof WP_REST_Response ) {
			return $resolved;
		}
		$content = $resolved;

		try {
			$blocks = parse_blocks( $content );
			$html   = '';
			foreach ( $blocks as $block ) {
				$html .= render_block( $block );
			}
		} catch ( \Throwable $e ) {
			$full_detail = $e->getMessage();
			return self::envelope_error(
				'divi_error',
				self::truncate_envelope_message( $full_detail ),
				'Check the block markup for malformed comments or invalid attributes.',
				500,
				[ 'detail' => $full_detail ]
			);
		}

		return self::envelope_success( [
			'rendered_html' => $html,
		] );
	}
}
