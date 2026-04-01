<?php
/**
 * Plugin Name: DiviOps Design Library
 * Plugin URI: https://github.com/oaris-dev/diviops
 * Description: Modern design effects for Divi 5 with Three.js, CSS animations, and reusable design elements.
 * Version: 1.0.0-beta.22
 * Author: oaris.de
 * Author URI: https://oaris.de
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: diviops-design-library
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiviOps_Design_Library {

	const VERSION = '1.0.0-beta.22';

	/**
	 * Three.js version to bundle.
	 */
	const THREEJS_VERSION = 'r128';

	/**
	 * Initialize the plugin.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_scripts' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue' ] );
		add_action( 'wp_head',            [ __CLASS__, 'print_css_variables' ] );
	}

	/**
	 * Register scripts (but don't enqueue yet).
	 */
	public static function register_scripts() {
		$base_url = plugin_dir_url( __FILE__ ) . 'assets/js/';

		// Three.js — only loaded when needed.
		wp_register_script(
			'threejs',
			$base_url . 'three.min.js',
			[],
			self::THREEJS_VERSION,
			[ 'in_footer' => true ]
		);

		// Design library helpers — CSS animations, intersection observer, etc.
		wp_register_script(
			'divi-design-fx',
			$base_url . 'design-fx.js',
			[],
			self::VERSION,
			[ 'in_footer' => true ]
		);
	}

	/**
	 * Conditionally enqueue scripts based on page meta or global setting.
	 *
	 * Pages opt-in via:
	 * - Post meta: _divi_design_threejs = '1'
	 * - Post meta: _divi_design_fx = '1'
	 * - Or always load design-fx if the page uses Divi builder.
	 */
	public static function maybe_enqueue() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		// Always load design-fx on Divi pages (lightweight).
		if ( function_exists( 'et_pb_is_pagebuilder_used' ) && et_pb_is_pagebuilder_used( $post_id ) ) {
			wp_enqueue_script( 'divi-design-fx' );
		}

		// Three.js only when explicitly requested.
		if ( get_post_meta( $post_id, '_divi_design_threejs', true ) === '1' ) {
			wp_enqueue_script( 'threejs' );
		}

		// Also check if the content contains Three.js markers.
		// Divi stores HTML in block attributes with unicode escapes, so check broadly.
		$content = get_post_field( 'post_content', $post_id );
		if ( strpos( $content, 'webgl' ) !== false ||
		     strpos( $content, 'THREE' ) !== false ||
		     strpos( $content, 'shader' ) !== false ||
		     strpos( $content, 'three.js' ) !== false ) {
			wp_enqueue_script( 'threejs' );
		}
	}

	/**
	 * Print CSS custom properties and base animation styles.
	 */
	public static function print_css_variables() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		if ( ! function_exists( 'et_pb_is_pagebuilder_used' ) || ! et_pb_is_pagebuilder_used( $post_id ) ) {
			return;
		}

		?>
		<style id="diviops-design-library">
			/* ===== Entrance Animations ===== */
			@keyframes ddl-fade-up {
				from { opacity: 0; transform: translateY(30px); }
				to   { opacity: 1; transform: translateY(0); }
			}
			@keyframes ddl-fade-in {
				from { opacity: 0; }
				to   { opacity: 1; }
			}
			@keyframes ddl-scale-in {
				from { opacity: 0; transform: scale(0.9); }
				to   { opacity: 1; transform: scale(1); }
			}
			@keyframes ddl-slide-left {
				from { opacity: 0; transform: translateX(40px); }
				to   { opacity: 1; transform: translateX(0); }
			}
			@keyframes ddl-slide-right {
				from { opacity: 0; transform: translateX(-40px); }
				to   { opacity: 1; transform: translateX(0); }
			}

			/* Apply via CSS class — elements start hidden, animate when .ddl-visible is added */
			.ddl-animate {
				opacity: 0;
			}
			/* VB override — show all elements in the Visual Builder so they remain editable.
			   Only reset opacity and animation — do NOT reset transform (would break intentional transforms). */
			#et-fb-app .ddl-animate,
			.et-fb .ddl-animate,
			body.et-fb .ddl-animate {
				opacity: 1 !important;
				animation: none !important;
			}
			.ddl-animate.ddl-visible {
				animation-duration: 0.8s;
				animation-fill-mode: both;
				animation-timing-function: cubic-bezier(0.16, 1, 0.3, 1);
			}
			.ddl-fade-up.ddl-visible    { animation-name: ddl-fade-up; }
			.ddl-fade-in.ddl-visible     { animation-name: ddl-fade-in; }
			.ddl-scale-in.ddl-visible    { animation-name: ddl-scale-in; }
			.ddl-slide-left.ddl-visible  { animation-name: ddl-slide-left; }
			.ddl-slide-right.ddl-visible { animation-name: ddl-slide-right; }

			/* Stagger delays — add ddl-delay-1 through ddl-delay-6 */
			.ddl-delay-1.ddl-visible { animation-delay: 0.1s; }
			.ddl-delay-2.ddl-visible { animation-delay: 0.2s; }
			.ddl-delay-3.ddl-visible { animation-delay: 0.3s; }
			.ddl-delay-4.ddl-visible { animation-delay: 0.4s; }
			.ddl-delay-5.ddl-visible { animation-delay: 0.5s; }
			.ddl-delay-6.ddl-visible { animation-delay: 0.6s; }

			/* ===== Animated Gradient Background ===== */
			@keyframes ddl-gradient-shift {
				0%   { background-position: 0% 50%; }
				50%  { background-position: 100% 50%; }
				100% { background-position: 0% 50%; }
			}
			.ddl-gradient-animated {
				background-size: 200% 200% !important;
				animation: ddl-gradient-shift 8s ease infinite;
			}

			/* ===== Glass Morphism ===== */
			.ddl-glass {
				backdrop-filter: blur(12px) saturate(1.5);
				-webkit-backdrop-filter: blur(12px) saturate(1.5);
				background: rgba(255, 255, 255, 0.08) !important;
				border: 1px solid rgba(255, 255, 255, 0.12) !important;
			}
			.ddl-glass-light {
				backdrop-filter: blur(12px) saturate(1.5);
				-webkit-backdrop-filter: blur(12px) saturate(1.5);
				background: rgba(255, 255, 255, 0.7) !important;
				border: 1px solid rgba(255, 255, 255, 0.5) !important;
			}

			/* ===== Pulse indicator ===== */
			@keyframes ddl-pulse {
				0%   { transform: scale(1); opacity: 0.75; }
				100% { transform: scale(2.5); opacity: 0; }
			}
			.ddl-pulse-dot {
				position: relative;
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 12px;
				height: 12px;
			}
			.ddl-pulse-dot::before {
				content: '';
				position: absolute;
				width: 100%;
				height: 100%;
				border-radius: 50%;
				background: currentColor;
				animation: ddl-pulse 1.5s ease-out infinite;
			}
			.ddl-pulse-dot::after {
				content: '';
				position: relative;
				width: 8px;
				height: 8px;
				border-radius: 50%;
				background: currentColor;
			}

			/* ===== Marquee (reusable) ===== */
			@keyframes ddl-marquee {
				0%   { transform: translateX(0); }
				100% { transform: translateX(-50%); }
			}
			.ddl-marquee-track {
				overflow: hidden;
			}
			.ddl-marquee-scroll {
				display: inline-flex !important;
				flex-wrap: nowrap !important;
				animation: ddl-marquee var(--ddl-marquee-speed, 30s) linear infinite;
			}
			.ddl-marquee-scroll > * {
				flex-shrink: 0;
				white-space: nowrap;
			}

			/* ===== Gradient Text ===== */
			.ddl-gradient-text {
				background: linear-gradient(135deg, #6366f1 0%, #ec4899 50%, #f59e0b 100%);
				-webkit-background-clip: text;
				-webkit-text-fill-color: transparent;
				background-clip: text;
			}
			.ddl-gradient-text-animated {
				background: linear-gradient(135deg, #6366f1, #ec4899, #f59e0b, #10b981, #6366f1);
				background-size: 300% 300%;
				-webkit-background-clip: text;
				-webkit-text-fill-color: transparent;
				background-clip: text;
				animation: ddl-gradient-shift 6s ease infinite;
			}

			/* ===== Text Stroke / Outline ===== */
			.ddl-text-stroke {
				color: transparent !important;
				-webkit-text-stroke: 2px rgba(255,255,255,0.25);
			}
			.ddl-text-stroke-dark {
				color: transparent !important;
				-webkit-text-stroke: 2px rgba(0,0,0,0.15);
			}

			/* ===== Gooey Text Morph (SVG filter) ===== */
			.ddl-gooey-wrap {
				position: relative;
				display: flex;
				align-items: center;
				justify-content: center;
				filter: url(#ddl-gooey-filter);
			}
			.ddl-gooey-text {
				position: absolute;
				display: inline-block;
				font-weight: 900;
				text-align: center;
				user-select: none;
			}

			/* ===== Hover lift effect ===== */
			.ddl-hover-lift {
				transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.3s ease;
			}
			.ddl-hover-lift:hover {
				transform: translateY(-4px);
				box-shadow: 0 12px 40px rgba(0, 0, 0, 0.12);
			}
		</style>
		<?php
	}
}

DiviOps_Design_Library::init();
