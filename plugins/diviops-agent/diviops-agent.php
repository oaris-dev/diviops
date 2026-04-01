<?php
/**
 * Plugin Name: DiviOps Agent
 * Plugin URI: https://github.com/oaris-dev/diviops
 * Description: REST API bridge for DiviOps — connects Claude Code to your Divi 5 site for AI-powered page building and design management.
 * Version: 1.0.0-beta.23
 * Author: oaris.de
 * Author URI: https://oaris.de
 * Text Domain: diviops-agent
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiviOps_Agent {

	/**
	 * Plugin version — used for handshake compatibility checks.
	 */
	const VERSION = '1.0.0-beta.23';

	/**
	 * Minimum MCP server version this plugin is compatible with.
	 */
	const MIN_SERVER_VERSION = '0.1.0';

	/**
	 * REST namespace for all endpoints.
	 */
	const REST_NAMESPACE = 'diviops/v1';

	/** Block comment tag constants for section parsing. */
	const SECTION_OPEN  = '<!-- wp:divi/section';
	const SECTION_CLOSE = '<!-- /wp:divi/section -->';
	const BLOCK_PREFIX  = '<!-- wp:divi/';

	/**
	 * Default rate limits (requests per minute).
	 */
	const RATE_LIMIT_READ  = 120;
	const RATE_LIMIT_WRITE = 30;

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_filter( 'rest_pre_dispatch', [ __CLASS__, 'check_rate_limit' ], 10, 3 );
		add_action( 'admin_menu', [ __CLASS__, 'register_admin_page' ] );
	}

	/**
	 * Rate limit check via rest_pre_dispatch filter.
	 *
	 * Uses WordPress transients for per-user request counting.
	 * Only applies to diviops/v1 endpoints.
	 *
	 * Configurable via:
	 *   - DIVIOPS_RATE_LIMIT_READ  constant or env var (default: 120/min)
	 *   - DIVIOPS_RATE_LIMIT_WRITE constant or env var (default: 30/min)
	 *   - DIVIOPS_RATE_LIMIT_DISABLED constant or env var (disables entirely)
	 *   - 'diviops_rate_limits' filter (receives ['read' => int, 'write' => int])
	 *
	 * @param mixed            $result  Response to replace the requested one.
	 * @param WP_REST_Server   $server  Server instance.
	 * @param WP_REST_Request  $request Current request.
	 * @return mixed|WP_Error
	 */
	public static function check_rate_limit( $result, $server, $request ) {
		// Only apply to our namespace.
		$route = $request->get_route();
		if ( strpos( $route, '/' . self::REST_NAMESPACE ) !== 0 ) {
			return $result;
		}

		// Allow disabling via constant or env var.
		if (
			( defined( 'DIVIOPS_RATE_LIMIT_DISABLED' ) && DIVIOPS_RATE_LIMIT_DISABLED ) ||
			filter_var( getenv( 'DIVIOPS_RATE_LIMIT_DISABLED' ), FILTER_VALIDATE_BOOLEAN )
		) {
			return $result;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $result; // Unauthenticated — permission callbacks will reject.
		}

		// Determine if this is a write operation.
		$method   = $request->get_method();
		$is_write = in_array( $method, [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true );

		// Resolve limits: constant > env var > default.
		$read_limit  = (int) ( defined( 'DIVIOPS_RATE_LIMIT_READ' ) ? DIVIOPS_RATE_LIMIT_READ : ( getenv( 'DIVIOPS_RATE_LIMIT_READ' ) ?: self::RATE_LIMIT_READ ) );
		$write_limit = (int) ( defined( 'DIVIOPS_RATE_LIMIT_WRITE' ) ? DIVIOPS_RATE_LIMIT_WRITE : ( getenv( 'DIVIOPS_RATE_LIMIT_WRITE' ) ?: self::RATE_LIMIT_WRITE ) );

		$limits = apply_filters( 'diviops_rate_limits', [
			'read'  => $read_limit,
			'write' => $write_limit,
		] );
		if ( ! is_array( $limits ) || ! isset( $limits['read'], $limits['write'] ) ) {
			$limits = [ 'read' => $read_limit, 'write' => $write_limit ];
		}

		$limit         = $is_write ? (int) $limits['write'] : (int) $limits['read'];
		$bucket        = $is_write ? 'write' : 'read';
		$transient_key = "diviops_rl_{$bucket}_{$user_id}";
		$now           = time();

		$data = get_transient( $transient_key );
		if ( false === $data || ! is_array( $data ) || ! isset( $data['count'], $data['window_start'] ) ) {
			// First request or corrupted transient — start new window.
			set_transient( $transient_key, [ 'count' => 1, 'window_start' => $now ], 60 );
			return $result;
		}

		// Reset window if 60s have elapsed.
		$elapsed = $now - (int) $data['window_start'];
		if ( $elapsed >= 60 ) {
			set_transient( $transient_key, [ 'count' => 1, 'window_start' => $now ], 60 );
			return $result;
		}

		$data['count']++;
		$remaining_ttl = max( 1, 60 - $elapsed );

		if ( $data['count'] > $limit ) {
			$retry_after = $remaining_ttl;

			$response = new WP_REST_Response( [
				'code'    => 'diviops_rate_limit_exceeded',
				'message' => sprintf(
					'Rate limit exceeded: %d %s requests/minute. Retry after %d seconds.',
					$limit,
					$bucket,
					$retry_after
				),
				'data'    => [ 'status' => 429 ],
			], 429 );
			$response->header( 'Retry-After', $retry_after );
			$response->header( 'X-RateLimit-Limit', $limit );
			$response->header( 'X-RateLimit-Remaining', 0 );
			$response->header( 'X-RateLimit-Reset', (int) $data['window_start'] + 60 );

			return $response;
		}

		set_transient( $transient_key, $data, $remaining_ttl );

		return $result;
	}

	/**
	 * Permission tiers (all require Application Password auth):
	 *
	 * check_read_permission   — edit_posts      — read pages, modules, settings, icons, preset reads
	 * check_write_permission  — edit_pages      — page creation and content modification
	 * check_admin_permission  — manage_options  — theme options, preset cleanup/update/delete, library save
	 */
	public static function check_read_permission() {
		return current_user_can( 'edit_posts' );
	}

	public static function check_write_permission() {
		return current_user_can( 'edit_pages' );
	}

	public static function check_admin_permission() {
		return current_user_can( 'manage_options' );
	}

	private static function get_nested_array_value( $source, $path, $default = null ) {
		$value = $source;
		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return $default;
			}
			$value = $value[ $key ];
		}

		return $value;
	}

	public static function register_routes() {

		// ── Handshake (always available, even without Divi) ──────────
		register_rest_route( self::REST_NAMESPACE, '/handshake', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handshake' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'mcp_server_version' => [ 'required' => true, 'type' => 'string' ],
			],
		] );

		// Divi availability guard — still requires auth to avoid exposing plugin status.
		if ( ! function_exists( 'et_get_option' ) ) {
			register_rest_route( self::REST_NAMESPACE, '/(?P<path>.*)', [
				'methods'             => WP_REST_Server::ALLMETHODS,
				'callback'            => function () {
					return new WP_Error(
						'divi_unavailable',
						'Divi theme is not active. Activate Divi before using the MCP agent.',
						[ 'status' => 503 ]
					);
				},
				'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			] );
			return;
		}

		// ── Read Operations ──────────────────────────────────────────

		register_rest_route( self::REST_NAMESPACE, '/pages', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_pages' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/page/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_page' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'id' => [
					'required'          => true,
					'validate_callback' => function ( $param ) {
						return is_numeric( $param );
					},
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/page/(?P<id>\d+)/layout', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_page_layout' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'full' => [
					'default'     => false,
					'type'        => 'boolean',
					'description' => 'Include full block attrs and raw content (default: false for slim targeting-only response)',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/modules', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_modules' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/module/(?P<name>[a-zA-Z0-9_/-]+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_module_schema' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/settings', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_settings' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/global-colors', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_global_colors' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/global-fonts', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_global_fonts' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/global-colors', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'update_global_colors' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'colors' => [ 'required' => true, 'type' => 'array' ],
				'mode'   => [ 'required' => false, 'type' => 'string', 'default' => 'merge' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/theme-options', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'update_theme_options' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'options' => [ 'required' => true, 'type' => 'object' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/presets', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_presets' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/preset-audit', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'preset_audit' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/preset-cleanup', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'preset_cleanup' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'dry_run' => [ 'type' => 'boolean', 'default' => true ],
				'dedup'   => [ 'type' => 'boolean', 'default' => false ],
				'action'  => [ 'type' => 'string', 'default' => '' ],
				'prefix'  => [ 'type' => 'string', 'default' => '' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/preset-update', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'preset_update' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'preset_id' => [ 'required' => true, 'type' => 'string' ],
				'name'      => [ 'required' => false, 'type' => 'string' ],
				'attrs'     => [ 'required' => false, 'type' => 'object' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/preset-delete', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'preset_delete' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'preset_id' => [ 'required' => true, 'type' => 'string' ],
			],
		] );

		// ── Library Operations ───────────────────────────────────────

		register_rest_route( self::REST_NAMESPACE, '/library', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_library' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'layout_type' => [ 'required' => false, 'type' => 'string', 'default' => '' ],
				'scope'       => [ 'required' => false, 'type' => 'string', 'default' => '' ],
				'per_page'    => [ 'required' => false, 'type' => 'integer', 'default' => 50 ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/library/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_library_item' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/library/save', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'save_to_library' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'title'       => [ 'required' => true, 'type' => 'string' ],
				'content'     => [ 'required' => true, 'type' => 'string' ],
				'layout_type' => [ 'required' => false, 'type' => 'string', 'default' => 'section' ],
				'scope'       => [ 'required' => false, 'type' => 'string', 'default' => 'non_global' ],
			],
		] );

		// ── Theme Builder Operations ────────────────────────────────

		register_rest_route( self::REST_NAMESPACE, '/theme-builder/templates', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_tb_templates' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'per_page' => [ 'type' => 'integer', 'default' => 50 ],
				'page'     => [ 'type' => 'integer', 'default' => 1 ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/theme-builder/layout/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_tb_layout' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/theme-builder/layout/(?P<id>\d+)', [
			'methods'             => 'PUT',
			'callback'            => [ __CLASS__, 'update_tb_layout' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'content' => [ 'required' => true, 'type' => 'string' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/theme-builder/template', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'create_tb_template' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'title'          => [ 'required' => true, 'type' => 'string' ],
				'condition'      => [ 'required' => true, 'type' => 'string' ],
				'header_content' => [ 'required' => false, 'type' => 'string', 'default' => '' ],
				'footer_content' => [ 'required' => false, 'type' => 'string', 'default' => '' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/icons/search', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'search_icons' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'q'    => [ 'required' => true, 'type' => 'string' ],
				'type' => [ 'required' => false, 'type' => 'string', 'default' => 'all' ],
				'limit' => [ 'required' => false, 'type' => 'integer', 'default' => 10 ],
			],
		] );

		// ── Write Operations ─────────────────────────────────────────

		register_rest_route( self::REST_NAMESPACE, '/page/(?P<id>\d+)/content', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'update_page_content' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'id'      => [ 'required' => true ],
				'content' => [
					'required' => true,
					'type'     => 'string',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/page/(?P<id>\d+)/meta', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'set_page_meta' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'id'       => [ 'required' => true ],
				'template' => [ 'required' => false, 'type' => 'string' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/page/(?P<id>\d+)/append', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'append_section' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'id'      => [ 'required' => true ],
				'content' => [
					'required'    => true,
					'type'        => 'string',
					'description' => 'Divi section block markup to append (<!-- wp:divi/section ...-->...<!-- /wp:divi/section -->)',
				],
				'position' => [
					'required' => false,
					'type'     => 'string',
					'default'  => 'end',
					'enum'     => [ 'start', 'end' ],
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/page/(?P<id>\d+)/replace-section', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'replace_section' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'id'         => [ 'required' => true ],
				'label'      => [
					'type'        => 'string',
					'description' => 'Admin label of the section to replace',
				],
				'match_text' => [
					'type'        => 'string',
					'description' => 'Text to search for in section content (case-insensitive substring)',
				],
				'occurrence' => [
					'default'           => 1,
					'type'              => 'integer',
					'description'       => 'Which occurrence to target when multiple sections match (1-based)',
					'sanitize_callback' => 'absint',
				],
				'content'    => [
					'required'    => true,
					'type'        => 'string',
					'description' => 'New section block markup to replace the matched section',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/page/(?P<id>\d+)/remove-section', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'remove_section' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'id'         => [ 'required' => true ],
				'label'      => [
					'type'        => 'string',
					'description' => 'Admin label of the section to remove',
				],
				'match_text' => [
					'type'        => 'string',
					'description' => 'Text to search for in section content (case-insensitive substring)',
				],
				'occurrence' => [
					'default'           => 1,
					'type'              => 'integer',
					'description'       => 'Which occurrence to target when multiple sections match (1-based)',
					'sanitize_callback' => 'absint',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/page/(?P<id>\d+)/get-section', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_section' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'id'         => [ 'required' => true ],
				'label'      => [
					'type'        => 'string',
					'description' => 'Admin label of the section to retrieve',
				],
				'match_text' => [
					'type'        => 'string',
					'description' => 'Text to search for in section content (case-insensitive substring)',
				],
				'occurrence' => [
					'default'           => 1,
					'type'              => 'integer',
					'description'       => 'Which occurrence to target when multiple sections match (1-based)',
					'sanitize_callback' => 'absint',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/page/(?P<id>\d+)/update-module', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'update_module' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'id'         => [ 'required' => true ],
				'label'      => [
					'required'    => false,
					'type'        => 'string',
					'description' => 'Admin label of the module to update (exact match)',
				],
				'match_text'  => [
					'required'    => false,
					'type'        => 'string',
					'description' => 'Text content to search for in innerContent (case-insensitive substring match, first match wins)',
				],
				'auto_index'  => [
					'required'    => false,
					'type'        => 'string',
					'description' => 'Auto-index target in "type:N" format (e.g. "text:5", "icon:3"). Takes priority over label and match_text.',
				],
				'occurrence'  => [
					'default'           => 1,
					'type'              => 'integer',
					'description'       => 'Which occurrence to target when multiple modules share the same label (1-based). Only used with label targeting.',
					'sanitize_callback' => 'absint',
				],
				'attrs'       => [
					'required'    => true,
					'type'        => 'object',
					'description' => 'Attribute key-value pairs to merge (dot notation)',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/page/(?P<id>\d+)/move-module', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'move_module' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'id' => [ 'required' => true ],
				'source_label' => [
					'type'        => 'string',
					'description' => 'Admin label of the module to move (exact match)',
				],
				'source_match_text' => [
					'type'        => 'string',
					'description' => 'Text to search for in source module (case-insensitive substring)',
				],
				'source_auto_index' => [
					'type'        => 'string',
					'description' => 'Auto-index of the module to move in "type:N" format (e.g. "text:3")',
				],
				'source_occurrence' => [
					'default'           => 1,
					'type'              => 'integer',
					'description'       => 'Which occurrence when multiple sources match by label (1-based)',
					'sanitize_callback' => 'absint',
				],
				'target_label' => [
					'type'        => 'string',
					'description' => 'Admin label of the reference module (exact match)',
				],
				'target_match_text' => [
					'type'        => 'string',
					'description' => 'Text to search for in target module (case-insensitive substring)',
				],
				'target_auto_index' => [
					'type'        => 'string',
					'description' => 'Auto-index of the reference module in "type:N" format (e.g. "text:5")',
				],
				'target_occurrence' => [
					'default'           => 1,
					'type'              => 'integer',
					'description'       => 'Which occurrence when multiple targets match by label (1-based)',
					'sanitize_callback' => 'absint',
				],
				'position' => [
					'required'    => true,
					'type'        => 'string',
					'description' => 'Where to place the source relative to the target: "before" or "after"',
					'enum'        => [ 'before', 'after' ],
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/render', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'render_block_markup' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'content' => [
					'required' => true,
					'type'     => 'string',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/validate', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'validate_blocks' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'content' => [
					'required' => true,
					'type'     => 'string',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/page/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'create_page' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'title'   => [ 'required' => true, 'type' => 'string' ],
				'content' => [ 'required' => false, 'type' => 'string', 'default' => '' ],
				'status'  => [ 'required' => false, 'type' => 'string', 'default' => 'draft' ],
			],
		] );

		// ── Canvas Operations ────────────────────────────────────────

		register_rest_route( self::REST_NAMESPACE, '/canvas/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'create_canvas' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'title'          => [ 'required' => true, 'type' => 'string' ],
				'parent_page_id' => [ 'required' => true, 'type' => 'integer' ],
				'content'        => [ 'required' => false, 'type' => 'string', 'default' => '' ],
				'canvas_id'      => [ 'required' => false, 'type' => 'string' ],
				'append_to_main' => [ 'required' => false, 'type' => 'string' ],
				'z_index'        => [ 'required' => false, 'type' => 'integer' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/canvases', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_canvases' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'parent_page_id' => [ 'required' => false, 'type' => 'integer' ],
				'per_page'       => [ 'required' => false, 'type' => 'integer', 'default' => 50 ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/canvas/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_canvas' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/canvas/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'update_canvas' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'content'        => [ 'required' => false, 'type' => 'string' ],
				'title'          => [ 'required' => false, 'type' => 'string' ],
				'append_to_main' => [ 'required' => false, 'type' => 'string' ],
				'z_index'        => [ 'required' => false, 'type' => 'integer' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/canvas/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ __CLASS__, 'delete_canvas' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
		] );

		// ── Variable Manager CRUD ──────────────────────────────────────
		register_rest_route( self::REST_NAMESPACE, '/variables', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_variables' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'type'   => [ 'required' => false, 'type' => 'string' ],
				'prefix' => [ 'required' => false, 'type' => 'string' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/variable/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'create_variable' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'type'  => [ 'required' => true, 'type' => 'string' ],
				'id'    => [ 'required' => false, 'type' => 'string' ],
				'label' => [ 'required' => true, 'type' => 'string' ],
				'value' => [ 'required' => true, 'type' => 'string' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/variable/delete', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'delete_variable' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'id' => [ 'required' => true, 'type' => 'string' ],
			],
		] );
	}

	// ── Callbacks ────────────────────────────────────────────────────

	/**
	 * List all pages/posts that use the Divi Builder.
	 */
	public static function get_pages( $request ) {
		$post_type = sanitize_key( (string) ( $request->get_param( 'post_type' ) ?? 'page' ) );
		$per_page  = min( absint( $request->get_param( 'per_page' ) ?? 50 ), 100 );
		$page_num  = max( absint( $request->get_param( 'page' ) ?? 1 ), 1 );
		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			$post_type = 'page';
		}

		$query = new WP_Query( [
			'post_type'      => $post_type,
			'post_status'    => [ 'publish', 'draft', 'private' ],
			'posts_per_page' => $per_page,
			'paged'          => $page_num,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		] );

		$results = [];
		foreach ( $query->posts as $post ) {
			$results[] = [
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'status'   => $post->post_status,
				'url'      => get_permalink( $post->ID ),
				'modified' => $post->post_modified,
				'has_divi' => self::post_uses_divi( $post ),
			];
		}

		return rest_ensure_response( [
			'results'     => $results,
			'total'       => $query->found_posts,
			'total_pages' => $query->max_num_pages,
		] );
	}

	/**
	 * Get a single page with its metadata.
	 */
	public static function get_page( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Page not found', [ 'status' => 404 ] );
		}

		return rest_ensure_response( [
			'id'           => $post->ID,
			'title'        => $post->post_title,
			'status'       => $post->post_status,
			'url'          => get_permalink( $post->ID ),
			'modified'     => $post->post_modified,
			'post_type'    => $post->post_type,
			'has_divi'     => self::post_uses_divi( $post ),
			'content_raw'  => $post->post_content,
		] );
	}

	/**
	 * Get the parsed block tree for a page — the core layout structure.
	 */
	public static function get_page_layout( $request ) {
		$post_id = absint( $request['id'] );
		$full    = rest_sanitize_boolean( $request->get_param( 'full' ) ?? false );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Page not found', [ 'status' => 404 ] );
		}

		$content = $post->post_content;

		// Parse WordPress blocks (Divi 5 uses the block format).
		$blocks   = parse_blocks( $content );
		$counters = [];

		// Flatten for readability while preserving hierarchy.
		$layout = self::parse_block_tree( $blocks, 0, $counters, $full );

		$response = [
			'page_id'    => $post->ID,
			'page_title' => $post->post_title,
			'layout'     => $layout,
		];

		// Only include raw content in full mode (can be 100KB+).
		if ( $full ) {
			$response['raw'] = $content;
		}

		return rest_ensure_response( $response );
	}

	/**
	 * List all registered Divi modules with basic info.
	 */
	public static function get_modules( $request ) {
		$registry = WP_Block_Type_Registry::get_instance();
		$all      = $registry->get_all_registered();
		$modules  = [];

		foreach ( $all as $name => $block_type ) {
			if ( 0 !== strpos( $name, 'divi/' ) ) {
				continue;
			}

			$modules[] = [
				'name'        => $name,
				'title'       => $block_type->title ?? $name,
				'category'    => $block_type->category ?? '',
				'description' => $block_type->description ?? '',
				'supports'    => $block_type->supports ?? [],
			];
		}

		return rest_ensure_response( $modules );
	}

	/**
	 * Get full schema/attributes for a specific module.
	 */
	public static function get_module_schema( $request ) {
		$name = sanitize_text_field( (string) $request['name'] );

		// Normalize: accept "text" or "divi/text".
		if ( 0 !== strpos( $name, 'divi/' ) ) {
			$name = 'divi/' . $name;
		}

		$registry   = WP_Block_Type_Registry::get_instance();
		$block_type = $registry->get_registered( $name );

		if ( ! $block_type ) {
			return new WP_Error( 'not_found', "Module '{$name}' not found", [ 'status' => 404 ] );
		}

		return rest_ensure_response( [
			'name'        => $block_type->name,
			'title'       => $block_type->title ?? '',
			'category'    => $block_type->category ?? '',
			'description' => $block_type->description ?? '',
			'attributes'  => $block_type->attributes ?? [],
			'supports'    => $block_type->supports ?? [],
		] );
	}

	/**
	 * Get Divi global settings (theme options, customizer values).
	 */
	public static function get_settings( $request ) {
		$settings = [];

		// Theme options.
		$et_options = get_option( 'et_divi', [] );
		if ( is_array( $et_options ) ) {
			$settings['theme_options'] = $et_options;
		}

		// Key customizer values.
		$settings['site'] = [
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => get_site_url(),
			'language'    => get_locale(),
		];

		// Builder-specific settings.
		$settings['builder'] = [
			'version'        => defined( 'ET_BUILDER_PRODUCT_VERSION' ) ? ET_BUILDER_PRODUCT_VERSION : 'unknown',
			'is_divi5'       => true,
			'active_modules' => self::get_active_module_count(),
		];

		return rest_ensure_response( $settings );
	}

	/**
	 * Get global colors.
	 */
	public static function get_global_colors( $request ) {
		$raw = et_get_option( 'et_global_data' );
		$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
		$colors = is_array( $global_data ) ? ( $global_data['global_colors'] ?? [] ) : [];
		return rest_ensure_response( $colors );
	}

	/**
	 * Get global fonts.
	 */
	public static function get_global_fonts( $request ) {
		$global_fonts = et_get_option( 'et_global_fonts', [] );
		return rest_ensure_response( $global_fonts );
	}

	/**
	 * Search icons by keyword. Returns matching icons with their unicode, type, and weight.
	 */
	/**
	 * Update global colors in Divi's VB settings.
	 * Colors array: [{"id":"gcid-my-color","label":"My Color","color":"#ff0000"}]
	 * Mode: "merge" (add/update, keep existing) or "replace" (replace all)
	 */
	public static function update_global_colors( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Requires admin capability', [ 'status' => 403 ] );
		}

		$new_colors = $request->get_param( 'colors' );
		$mode       = sanitize_key( (string) ( $request->get_param( 'mode' ) ?? 'merge' ) );
		if ( ! in_array( $mode, [ 'merge', 'replace' ], true ) ) {
			return new WP_Error( 'invalid_mode', 'Mode must be "merge" or "replace"', [ 'status' => 400 ] );
		}
		if ( ! is_array( $new_colors ) ) {
			return new WP_Error( 'invalid_colors', 'colors must be an array of color definitions.', [ 'status' => 400 ] );
		}

		// Divi 5 stores global colors in et_global_data option.
		$raw = et_get_option( 'et_global_data' );
		$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
		if ( ! is_array( $global_data ) ) {
			$global_data = [];
		}
		$existing = $global_data['global_colors'] ?? [];

		// Build color map from existing (keyed by gcid-*).
		$color_map = [];
		if ( 'merge' === $mode && is_array( $existing ) ) {
			$color_map = $existing;
		}

		// Add/update new colors.
		$added = 0;
		$order = count( $color_map ) + 1;
		foreach ( $new_colors as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$id    = sanitize_text_field( $c['id'] ?? 'gcid-' . wp_generate_password( 8, false ) );
			$label = sanitize_text_field( $c['label'] ?? $id );
			$color = sanitize_hex_color( $c['color'] ?? '#000000' ) ?: '#000000';

			$color_map[ $id ] = [
				'color'       => $color,
				'status'      => 'active',
				'label'       => $label,
				'order'       => (string) $order++,
				'lastUpdated' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			];
			$added++;
		}

		// Save back.
		$global_data['global_colors'] = $color_map;
		et_update_option( 'et_global_data', $global_data );

		return rest_ensure_response( [
			'success' => true,
			'count'   => count( $color_map ),
			'added'   => $added,
			'colors'  => $color_map,
		] );
	}

	/**
	 * Update Divi theme options (fonts, colors, etc.).
	 */
	public static function update_theme_options( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Requires admin capability', [ 'status' => 403 ] );
		}

		$options  = $request->get_param( 'options' );
		if ( ! is_array( $options ) ) {
			return new WP_Error( 'invalid_options', 'options must be an object or associative array.', [ 'status' => 400 ] );
		}
		$allowed  = [
			'heading_font', 'body_font', 'accent_color', 'secondary_accent_color',
			'font_color', 'header_color', 'link_color',
			'heading_font_size', 'body_font_size',
		];
		$updated  = [];

		foreach ( $options as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$sanitized_value = sanitize_text_field( (string) $value );
			et_update_option( $key, $sanitized_value );
			$updated[ $key ] = $sanitized_value;
		}

		return rest_ensure_response( [
			'success' => true,
			'updated' => $updated,
			'message' => count( $updated ) . ' option(s) updated.',
		] );
	}

	/**
	 * Get all presets (Divi 5 + legacy Divi 4).
	 */
	public static function get_presets( $request ) {
		// Divi 5 presets.
		$d5_raw = et_get_option( 'builder_global_presets_d5', '', '', true, false, '', '', true );
		$d5     = ! empty( $d5_raw ) ? maybe_unserialize( $d5_raw ) : [];

		// Legacy Divi 4 presets.
		$d4_raw = et_get_option( 'builder_global_presets_ng', (object) [], '', true, false, '', '', true );
		$d4     = ! empty( $d4_raw ) ? maybe_unserialize( $d4_raw ) : [];

		// Also get from et_global_data presets.
		$global_raw  = et_get_option( 'et_global_data', '' );
		$global_data = ! empty( $global_raw ) ? maybe_unserialize( $global_raw ) : [];
		$global_presets = is_array( $global_data ) ? ( $global_data['presets'] ?? [] ) : [];

		return rest_ensure_response( [
			'divi5_presets'  => $d5,
			'legacy_presets' => $d4,
			'global_presets' => $global_presets,
		] );
	}

	public static function search_icons( $request ) {
		$query = strtolower( sanitize_text_field( (string) $request['q'] ) );
		$type  = sanitize_key( (string) ( $request['type'] ?? 'all' ) ); // all, fa, divi
		$limit = min( absint( $request['limit'] ?? 10 ), 50 );
		if ( ! in_array( $type, [ 'all', 'fa', 'divi' ], true ) ) {
			return new WP_Error( 'invalid_type', 'type must be one of: all, fa, divi', [ 'status' => 400 ] );
		}

		$json_path = get_template_directory() . '/includes/builder/feature/icon-manager/full_icons_list.json';
		if ( ! file_exists( $json_path ) ) {
			return new WP_Error( 'not_found', 'Icon list not found', [ 'status' => 404 ] );
		}

		$icons = json_decode( file_get_contents( $json_path ), true );
		if ( ! is_array( $icons ) ) {
			return new WP_Error( 'parse_error', 'Icon list could not be decoded', [ 'status' => 500 ] );
		}
		$results = [];

		foreach ( $icons as $icon ) {
			if ( ! is_array( $icon ) ) {
				continue;
			}
			// Filter by type.
			if ( 'fa' === $type && ! empty( $icon['is_divi_icon'] ) ) {
				continue;
			}
			if ( 'divi' === $type && empty( $icon['is_divi_icon'] ) ) {
				continue;
			}

			$search = strtolower( ( $icon['search_terms'] ?? '' ) . ' ' . ( $icon['name'] ?? '' ) );
			if ( strpos( $search, $query ) !== false ) {
				$results[] = [
					'name'    => $icon['name'],
					'unicode' => $icon['unicode'],
					'type'    => ! empty( $icon['is_divi_icon'] ) ? 'divi' : 'fa',
					'weight'  => (int) ( $icon['font_weight'] ?? 400 ),
					'styles'  => $icon['styles'] ?? [],
				];
			}

			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return rest_ensure_response( [
			'query'   => $query,
			'count'   => count( $results ),
			'results' => $results,
		] );
	}

	/**
	 * Set page template and other meta.
	 */
	public static function set_page_meta( $request ) {
		$post_id  = (int) $request['id'];
		$post     = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Page not found', [ 'status' => 404 ] );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Cannot edit this post', [ 'status' => 403 ] );
		}

		$template = $request->get_param( 'template' );
		if ( $template ) {
			update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( $template ) );
		}

		return rest_ensure_response( [
			'success'  => true,
			'page_id'  => $post_id,
			'template' => get_post_meta( $post_id, '_wp_page_template', true ),
		] );
	}

	/**
	 * Update page content with Divi block markup.
	 */
	public static function update_page_content( $request ) {
		$post_id = absint( $request['id'] );
		$content = $request->get_param( 'content' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Page not found', [ 'status' => 404 ] );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Cannot edit this post', [ 'status' => 403 ] );
		}
		if ( ! is_string( $content ) ) {
			return new WP_Error( 'invalid_content', 'content must be a string of Divi block markup.', [ 'status' => 400 ] );
		}

		// Use wp_slash() instead of wp_kses_post() because block comment
		// attributes contain HTML strings (e.g. <h1>...</h1> in innerContent)
		// that wp_kses_post() would entity-encode, breaking the block parser.
		// This mirrors how the block editor itself saves content.
		$result = wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $content ),
		], true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Mirror Divi's own page creation flow once Divi block content exists.
		if ( self::content_uses_divi( $content ) ) {
			self::initialize_divi_page_meta( $post_id );
		}

		self::invalidate_divi_cache( $post_id );

		return rest_ensure_response( [
			'success' => true,
			'page_id' => $post_id,
			'message' => 'Content updated successfully.',
		] );
	}

	/**
	 * Render block markup to HTML.
	 */
	public static function render_block_markup( $request ) {
		$content = $request->get_param( 'content' );
		if ( ! is_string( $content ) ) {
			return new WP_Error( 'invalid_content', 'content must be a string of block markup.', [ 'status' => 400 ] );
		}
		$blocks  = parse_blocks( $content );
		$html    = '';

		foreach ( $blocks as $block ) {
			$html .= render_block( $block );
		}

		return rest_ensure_response( [
			'rendered_html' => $html,
		] );
	}

	/**
	 * Validate Divi block markup. Checks structure, required attributes,
	 * and known pitfalls (button padding, gradient format, etc.).
	 */
	public static function validate_blocks( $request ) {
		$content  = $request->get_param( 'content' );
		if ( ! is_string( $content ) ) {
			return new WP_Error( 'invalid_content', 'content must be a string of block markup.', [ 'status' => 400 ] );
		}
		$blocks   = parse_blocks( $content );
		$registry = WP_Block_Type_Registry::get_instance();

		$errors   = [];
		$warnings = [];
		$index    = 0;

		$container_types = [ 'divi/section', 'divi/row', 'divi/column', 'divi/group', 'divi/group-carousel', 'divi/dropdown' ];

		self::validate_block_tree( $blocks, $registry, $container_types, $errors, $warnings, $index );

		return rest_ensure_response( [
			'valid'        => empty( $errors ),
			'total_blocks' => $index,
			'errors'       => $errors,
			'warnings'     => $warnings,
		] );
	}

	/**
	 * Recursively validate a block tree.
	 */
	private static function validate_block_tree( $blocks, $registry, $container_types, &$errors, &$warnings, &$index ) {
		foreach ( $blocks as $block ) {
			$name  = $block['blockName'] ?? null;
			$attrs = $block['attrs'] ?? [];

			// Freeform blocks (blockName null) that contain divi markup
			// indicate a parse failure — malformed block comment syntax.
			if ( empty( $name ) ) {
				$inner = implode( '', $block['innerContent'] ?? [] );
				if ( false !== strpos( $inner, '<!-- wp:divi/' ) ) {
					$errors[] = [
						'block'   => '(freeform)',
						'index'   => $index + 1,
						'code'    => 'parse_failure',
						'message' => 'Malformed block comment — contains divi markup but failed to parse',
					];
				}
				continue;
			}

			$index++;

			$is_divi_block = 0 === strpos( $name, 'divi/' ) && 'divi/placeholder' !== $name;

			// ── Structural checks (errors) ─────────────────────────

			if ( $is_divi_block ) {
				// Unknown block type.
				if ( ! $registry->get_registered( $name ) ) {
					$errors[] = [
						'block'   => $name,
						'index'   => $index,
						'code'    => 'unknown_block_type',
						'message' => "Block type '{$name}' is not registered",
					];
				}

				// Missing builderVersion.
				if ( ! isset( $attrs['builderVersion'] ) ) {
					$errors[] = [
						'block'   => $name,
						'index'   => $index,
						'code'    => 'missing_builder_version',
						'message' => 'Missing builderVersion attribute',
					];
				}

				// Missing layout display on containers — skip if flex properties imply it.
				if ( in_array( $name, $container_types, true ) ) {
					$layout  = self::get_nested_array_value( $attrs, [ 'module', 'decoration', 'layout', 'desktop', 'value' ], [] );
					$layout  = is_array( $layout ) ? $layout : [];
					$display = $layout['display'] ?? null;
					if ( null === $display ) {
						$has_flex = isset( $layout['flexWrap'] ) || isset( $layout['flexDirection'] )
							|| isset( $layout['justifyContent'] ) || isset( $layout['alignItems'] )
							|| isset( $layout['alignContent'] ) || isset( $layout['flexType'] )
							|| isset( $layout['columnGap'] ) || isset( $layout['rowGap'] ) || isset( $layout['gap'] );
						if ( ! $has_flex ) {
							$warnings[] = [
								'block'   => $name,
								'index'   => $index,
								'code'    => 'missing_layout_display',
								'message' => 'Container missing layout display declaration',
								'path'    => 'module.decoration.layout.desktop.value.display',
							];
						}
					}
				}
			}

			// ── Button checks (errors + warnings) ───────────────────

			if ( 'divi/button' === $name ) {
				$btn_enable = self::get_nested_array_value( $attrs, [ 'button', 'decoration', 'button', 'desktop', 'value', 'enable' ] );
				if ( 'on' === $btn_enable ) {
					$icon_enable = self::get_nested_array_value( $attrs, [ 'button', 'decoration', 'button', 'desktop', 'value', 'icon', 'enable' ] );
					if ( 'off' !== $icon_enable ) {
						$warnings[] = [
							'block'   => $name,
							'index'   => $index,
							'code'    => 'button_missing_icon_enable',
							'message' => 'Button has enable:"on" but missing icon.enable:"off" — will show arrow icon on hover',
							'path'    => 'button.decoration.button.desktop.value.icon.enable',
						];
					}
				}

				// Padding on wrong path.
				$btn_spacing = self::get_nested_array_value( $attrs, [ 'button', 'decoration', 'spacing' ] );
				if ( null !== $btn_spacing ) {
					$warnings[] = [
						'block'   => $name,
						'index'   => $index,
						'code'    => 'button_padding_wrong_path',
						'message' => 'Button padding should be on module.decoration.spacing, not button.decoration.spacing',
						'path'    => 'button.decoration.spacing',
					];
				}

				// innerContent must be {text} object, not plain string.
				$btn_content = self::get_nested_array_value( $attrs, [ 'button', 'innerContent', 'desktop', 'value' ] );
				if ( null !== $btn_content && is_string( $btn_content ) ) {
					$errors[] = [
						'block'   => $name,
						'index'   => $index,
						'code'    => 'button_innercontent_string',
						'message' => 'Button innerContent.desktop.value must be an object {"text": "..."}, not a plain string. Plain strings render as empty buttons.',
						'path'    => 'button.innerContent.desktop.value',
					];
				}
			}

			// ── Empty text module (error) ───────────────────────────

			if ( 'divi/text' === $name ) {
				$text_content = self::get_nested_array_value( $attrs, [ 'content', 'innerContent', 'desktop', 'value' ] );
				if ( ! $text_content ) {
					$errors[] = [
						'block'   => $name,
						'index'   => $index,
						'code'    => 'empty_text_module',
						'message' => 'Text module has no content.innerContent — will render as invisible empty block',
					];
				}
			}

			// ── Gradient checks (warnings) ──────────────────────────

			$bg_sources = [
				'module' => self::get_nested_array_value( $attrs, [ 'module', 'decoration', 'background', 'desktop', 'value' ], [] ),
				'button' => self::get_nested_array_value( $attrs, [ 'button', 'decoration', 'background', 'desktop', 'value' ], [] ),
			];
			foreach ( $bg_sources as $source => $bg ) {
				if ( ! isset( $bg['gradient'] ) ) {
					continue;
				}
				$gradient   = $bg['gradient'];
				$path_prefix = $source . '.decoration.background.desktop.value.gradient';

				if ( ! isset( $gradient['enabled'] ) || 'on' !== $gradient['enabled'] ) {
					$warnings[] = [
						'block'   => $name,
						'index'   => $index,
						'code'    => 'gradient_missing_enabled',
						'message' => 'Gradient missing enabled:"on" — will not render',
						'path'    => $path_prefix . '.enabled',
					];
				}

				$stops = $gradient['stops'] ?? [];
				if ( is_array( $stops ) ) {
					foreach ( $stops as $stop ) {
						// VB exports position as numeric strings ("0", "100") — that's valid.
						// Only warn on non-numeric strings like "50%", "center".
						if ( isset( $stop['position'] ) && is_string( $stop['position'] ) && ! is_numeric( $stop['position'] ) ) {
							$warnings[] = [
								'block'   => $name,
								'index'   => $index,
								'code'    => 'gradient_string_position',
								'message' => 'Gradient stop position should be numeric ("50") not a unit string ("50%")',
								'path'    => $path_prefix . '.stops[].position',
							];
							break;
						}
					}
				}
			}

			// ── Hover format check ──────────────────────────────────
			// Correct:   "background": {"desktop": {"value": {...}, "hover": {...}}}
			// Wrong:     "background": {"desktop": {...}, "hover": {"value": {...}}}
			$decoration_paths = [
				[ 'module', 'decoration' ],
				[ 'button', 'decoration' ],
				[ 'icon', 'decoration' ],
			];
			foreach ( $decoration_paths as $deco_path ) {
				$deco = $attrs;
				foreach ( $deco_path as $key ) {
					if ( ! is_array( $deco ) || ! isset( $deco[ $key ] ) ) {
						$deco = null;
						break;
					}
					$deco = $deco[ $key ];
				}
				if ( ! is_array( $deco ) ) {
					continue;
				}
				foreach ( [ 'background', 'border', 'boxShadow' ] as $prop ) {
					if ( ! is_array( $deco[ $prop ] ?? null ) ) {
						continue;
					}
					$prop_val   = $deco[ $prop ];
					$has_top    = isset( $prop_val['hover'] );
					$desktop    = is_array( $prop_val['desktop'] ?? null ) ? $prop_val['desktop'] : [];
					$has_nested = isset( $desktop['hover'] );
					if ( $has_top && ! $has_nested ) {
						$warnings[] = [
							'block'   => $name,
							'index'   => $index,
							'code'    => 'hover_wrong_path',
							'message' => "Hover on {$prop} uses top-level 'hover' (ignored by CSS). Move to 'desktop.hover'",
							'path'    => implode( '.', $deco_path ) . ".{$prop}.hover → should be .{$prop}.desktop.hover",
						];
					}
				}
			}

			// ── Icon: warn if icon.decoration.border/background used ─
			if ( 'divi/icon' === $name ) {
				$icon      = is_array( $attrs['icon'] ?? null ) ? $attrs['icon'] : [];
				$icon_deco = is_array( $icon['decoration'] ?? null ) ? $icon['decoration'] : [];
				if ( ! empty( $icon_deco['border'] ) || ! empty( $icon_deco['background'] ) ) {
					$warnings[] = [
						'block'   => $name,
						'index'   => $index,
						'code'    => 'icon_decoration_not_editable',
						'message' => 'icon.decoration.border/background creates a non-VB-editable inner ring. Use module.decoration instead',
						'path'    => 'icon.decoration → move to module.decoration',
					];
				}
			}

			// ── Recurse into inner blocks ───────────────────────────

			if ( ! empty( $block['innerBlocks'] ) ) {
				self::validate_block_tree( $block['innerBlocks'], $registry, $container_types, $errors, $warnings, $index );
			}
		}
	}

	/**
	 * Create a new page.
	 */
	public static function create_page( $request ) {
		$title   = sanitize_text_field( $request->get_param( 'title' ) );
		$content = $request->get_param( 'content' ) ?? '';
		$status  = sanitize_key( (string) ( $request->get_param( 'status' ) ?? 'draft' ) );
		if ( ! is_string( $content ) ) {
			return new WP_Error( 'invalid_content', 'content must be a string of Divi block markup.', [ 'status' => 400 ] );
		}
		if ( ! in_array( $status, get_post_stati( [ 'internal' => false ] ), true ) ) {
			return new WP_Error( 'invalid_status', 'status must be a valid public WordPress post status.', [ 'status' => 400 ] );
		}

		$post_id = wp_insert_post( [
			'post_title'   => $title,
			'post_content' => wp_slash( $content ),
			'post_status'  => $status,
			'post_type'    => 'page',
		], true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// New MCP-created pages should behave like Divi-created pages by default.
		self::initialize_divi_page_meta( $post_id );

		return rest_ensure_response( [
			'success' => true,
			'page_id' => $post_id,
			'url'     => get_permalink( $post_id ),
			'edit_url' => admin_url( "post.php?post={$post_id}&action=edit" ),
		] );
	}

	/**
	 * Append a section to existing page content.
	 */
	public static function append_section( $request ) {
		$post_id  = absint( $request['id'] );
		$content  = $request->get_param( 'content' );
		$position = sanitize_key( (string) ( $request->get_param( 'position' ) ?? 'end' ) );
		$post     = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Page not found', [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Cannot edit this post', [ 'status' => 403 ] );
		}
		if ( ! is_string( $content ) ) {
			return new WP_Error( 'invalid_content', 'content must be a string of Divi section markup.', [ 'status' => 400 ] );
		}
		if ( ! in_array( $position, [ 'start', 'end' ], true ) ) {
			return new WP_Error( 'invalid_position', 'position must be "start" or "end".', [ 'status' => 400 ] );
		}

		$existing = $post->post_content;

		// Strip the placeholder wrapper if present, we'll re-add it.
		$inner = $existing;
		$has_placeholder = false !== strpos( $existing, '<!-- wp:divi/placeholder -->' );
		if ( $has_placeholder ) {
			$inner = preg_replace( '/^\s*<!-- wp:divi\/placeholder -->\s*/', '', $inner );
			$inner = preg_replace( '/\s*<!-- \/wp:divi\/placeholder -->\s*$/', '', $inner );
		}

		// Also strip placeholder from incoming content.
		$new_section = preg_replace( '/^\s*<!-- wp:divi\/placeholder -->\s*/', '', $content );
		$new_section = preg_replace( '/\s*<!-- \/wp:divi\/placeholder -->\s*$/', '', $new_section );

		if ( 'start' === $position ) {
			$inner = $new_section . $inner;
		} else {
			$inner = $inner . $new_section;
		}

		// Re-wrap in placeholder.
		$final = '<!-- wp:divi/placeholder -->' . $inner . '<!-- /wp:divi/placeholder -->';

		$result = wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $final ),
		], true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::invalidate_divi_cache( $post_id );

		return rest_ensure_response( [
			'success'  => true,
			'page_id'  => $post_id,
			'position' => $position,
			'message'  => 'Section appended successfully.',
		] );
	}

	/**
	 * Replace a section identified by admin label or text content.
	 */
	public static function replace_section( $request ) {
		$post_id    = absint( $request['id'] );
		$label      = sanitize_text_field( $request->get_param( 'label' ) ?? '' );
		$match_text = sanitize_text_field( $request->get_param( 'match_text' ) ?? '' );
		$content    = $request->get_param( 'content' );
		$occurrence = max( 1, absint( $request->get_param( 'occurrence' ) ?? 1 ) );
		$post       = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Page not found', [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Cannot edit this post', [ 'status' => 403 ] );
		}
		if ( '' === $label && '' === $match_text ) {
			return new WP_Error( 'missing_target', 'Either "label" or "match_text" is required', [ 'status' => 400 ] );
		}
		if ( ! is_string( $content ) ) {
			return new WP_Error( 'invalid_content', 'content must be a string of Divi section markup.', [ 'status' => 400 ] );
		}

		$existing = $post->post_content;
		$result   = self::find_and_replace_section( $existing, $label, $content, $match_text, $occurrence );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$update = wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $result['content'] ),
		], true );

		if ( is_wp_error( $update ) ) {
			return $update;
		}

		self::invalidate_divi_cache( $post_id );

		$target   = '' !== $label ? $label : "text:{$match_text}";
		$response = [
			'success'    => true,
			'page_id'    => $post_id,
			'matched_by' => '' !== $label ? 'label' : 'text',
			'target'     => $target,
			'message'    => "Section '{$target}' replaced successfully.",
		];

		if ( $result['total_matches'] > 1 ) {
			$response['occurrence']    = $occurrence;
			$response['total_matches'] = $result['total_matches'];
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Remove a section identified by admin label or text content.
	 */
	public static function remove_section( $request ) {
		$post_id    = absint( $request['id'] );
		$label      = sanitize_text_field( $request->get_param( 'label' ) ?? '' );
		$match_text = sanitize_text_field( $request->get_param( 'match_text' ) ?? '' );
		$occurrence = max( 1, absint( $request->get_param( 'occurrence' ) ?? 1 ) );
		$post       = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Page not found', [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Cannot edit this post', [ 'status' => 403 ] );
		}
		if ( '' === $label && '' === $match_text ) {
			return new WP_Error( 'missing_target', 'Either "label" or "match_text" is required', [ 'status' => 400 ] );
		}

		$existing = $post->post_content;
		$result   = self::find_and_replace_section( $existing, $label, '', $match_text, $occurrence );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$update = wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $result['content'] ),
		], true );

		if ( is_wp_error( $update ) ) {
			return $update;
		}

		self::invalidate_divi_cache( $post_id );

		$target   = '' !== $label ? $label : "text:{$match_text}";
		$response = [
			'success'    => true,
			'page_id'    => $post_id,
			'matched_by' => '' !== $label ? 'label' : 'text',
			'target'     => $target,
			'message'    => "Section '{$target}' removed successfully.",
		];

		if ( $result['total_matches'] > 1 ) {
			$response['occurrence']    = $occurrence;
			$response['total_matches'] = $result['total_matches'];
		}

		return rest_ensure_response( $response );
	}

	// ── Library Operations ──────────────────────────────────────────

	/**
	 * Safely get a taxonomy term slug for a post, returning '' on error.
	 */
	private static function get_term_slug( $post_id, $taxonomy ) {
		$terms = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'slugs' ] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}
		return $terms[0];
	}

	/**
	 * List Divi Library items.
	 */
	public static function list_library( $request ) {
		$layout_type = sanitize_key( (string) ( $request->get_param( 'layout_type' ) ?? '' ) );
		$scope       = sanitize_key( (string) ( $request->get_param( 'scope' ) ?? '' ) );
		$per_page    = max( 1, min( absint( $request->get_param( 'per_page' ) ?? 50 ), 100 ) );

		$args = [
			'post_type'      => 'et_pb_layout',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		];

		$tax_query = [];
		if ( '' !== $layout_type ) {
			$tax_query[] = [
				'taxonomy' => 'layout_type',
				'field'    => 'slug',
				'terms'    => sanitize_text_field( $layout_type ),
			];
		}
		if ( '' !== $scope ) {
			$tax_query[] = [
				'taxonomy' => 'scope',
				'field'    => 'slug',
				'terms'    => sanitize_text_field( $scope ),
			];
		}
		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query;
		}

		$query   = new WP_Query( $args );
		$results = [];

		foreach ( $query->posts as $post ) {
			$results[] = [
				'id'          => $post->ID,
				'title'       => $post->post_title,
				'layout_type' => self::get_term_slug( $post->ID, 'layout_type' ),
				'scope'       => self::get_term_slug( $post->ID, 'scope' ),
				'modified'    => $post->post_modified,
			];
		}

		return rest_ensure_response( [
			'results' => $results,
			'total'   => $query->found_posts,
		] );
	}

	/**
	 * Get a single Divi Library item's content.
	 */
	public static function get_library_item( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post || 'et_pb_layout' !== $post->post_type ) {
			return new WP_Error( 'not_found', 'Library item not found', [ 'status' => 404 ] );
		}

		return rest_ensure_response( [
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'layout_type' => self::get_term_slug( $post->ID, 'layout_type' ),
			'scope'       => self::get_term_slug( $post->ID, 'scope' ),
			'modified'    => $post->post_modified,
			'content_raw' => $post->post_content,
		] );
	}

	/**
	 * Save block markup to Divi Library.
	 */
	public static function save_to_library( $request ) {
		$title       = sanitize_text_field( $request->get_param( 'title' ) );
		$content     = $request->get_param( 'content' );
		$layout_type = sanitize_text_field( $request->get_param( 'layout_type' ) );
		$scope       = sanitize_text_field( $request->get_param( 'scope' ) );

		// Validate against allowed values.
		$allowed_types  = [ 'section', 'row', 'module' ];
		$allowed_scopes = [ 'global', 'non_global' ];
		if ( ! is_string( $content ) ) {
			return new WP_Error( 'invalid_content', 'content must be a string of Divi block markup.', [ 'status' => 400 ] );
		}
		if ( ! in_array( $layout_type, $allowed_types, true ) ) {
			return new WP_Error( 'invalid_type', 'layout_type must be: ' . implode( ', ', $allowed_types ), [ 'status' => 400 ] );
		}
		if ( ! in_array( $scope, $allowed_scopes, true ) ) {
			return new WP_Error( 'invalid_scope', 'scope must be: ' . implode( ', ', $allowed_scopes ), [ 'status' => 400 ] );
		}

		$post_id = wp_insert_post( [
			'post_title'   => $title,
			'post_content' => wp_slash( $content ),
			'post_type'    => 'et_pb_layout',
			'post_status'  => 'publish',
		], true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Mark as Divi 5 format.
		update_post_meta( $post_id, '_et_pb_use_divi_5', 'on' );

		// Set layout type and scope taxonomies.
		$type_result  = wp_set_object_terms( $post_id, $layout_type, 'layout_type' );
		$scope_result = wp_set_object_terms( $post_id, $scope, 'scope' );

		if ( is_wp_error( $type_result ) || is_wp_error( $scope_result ) ) {
			wp_delete_post( $post_id, true );
			return new WP_Error( 'taxonomy_error', 'Failed to set library taxonomies', [ 'status' => 500 ] );
		}

		return rest_ensure_response( [
			'success'     => true,
			'id'          => $post_id,
			'title'       => $title,
			'layout_type' => $layout_type,
			'scope'       => $scope,
			'message'     => "Saved to Divi Library as '{$title}'.",
		] );
	}

	// ── Theme Builder Operations ────────────────────────────────────

	/**
	 * List all Theme Builder templates with their conditions and layout IDs.
	 */
	public static function list_tb_templates( $request ) {
		$per_page = max( 1, min( absint( $request->get_param( 'per_page' ) ?? 50 ), 100 ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ?? 1 ) );

		$query = new WP_Query( [
			'post_type'      => 'et_template',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		] );

		// Prime post meta cache to avoid N+1 queries.
		if ( $query->posts ) {
			update_post_caches( $query->posts, 'et_template', false, true );
		}

		$results = [];
		foreach ( $query->posts as $post ) {
			$use_on      = get_post_meta( $post->ID, '_et_use_on' );
			$exclude     = get_post_meta( $post->ID, '_et_exclude_from' );
			$is_default  = '1' === get_post_meta( $post->ID, '_et_default', true );
			$is_enabled  = '1' === get_post_meta( $post->ID, '_et_enabled', true );

			$results[] = [
				'id'                    => $post->ID,
				'title'                 => $post->post_title,
				'is_default'            => $is_default,
				'enabled'               => $is_enabled,
				'conditions'            => $use_on,
				'exclusions'            => $exclude,
				'header_layout_id'      => (int) get_post_meta( $post->ID, '_et_header_layout_id', true ),
				'header_layout_enabled' => '1' === get_post_meta( $post->ID, '_et_header_layout_enabled', true ),
				'body_layout_id'        => (int) get_post_meta( $post->ID, '_et_body_layout_id', true ),
				'body_layout_enabled'   => '1' === get_post_meta( $post->ID, '_et_body_layout_enabled', true ),
				'footer_layout_id'      => (int) get_post_meta( $post->ID, '_et_footer_layout_id', true ),
				'footer_layout_enabled' => '1' === get_post_meta( $post->ID, '_et_footer_layout_enabled', true ),
			];
		}

		return rest_ensure_response( [
			'results'     => $results,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		] );
	}

	/**
	 * Get a Theme Builder layout's content (header, body, or footer).
	 */
	public static function get_tb_layout( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		$valid_types = [ 'et_header_layout', 'et_body_layout', 'et_footer_layout' ];
		if ( ! $post || ! in_array( $post->post_type, $valid_types, true ) ) {
			return new WP_Error( 'not_found', 'Theme Builder layout not found', [ 'status' => 404 ] );
		}

		return rest_ensure_response( [
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'type'        => $post->post_type,
			'content_raw' => $post->post_content,
		] );
	}

	/**
	 * Update a Theme Builder layout's block markup content.
	 */
	public static function update_tb_layout( $request ) {
		$post_id = absint( $request['id'] );
		$content = $request->get_param( 'content' );
		$post    = get_post( $post_id );

		$valid_types = [ 'et_header_layout', 'et_body_layout', 'et_footer_layout' ];
		if ( ! $post || ! in_array( $post->post_type, $valid_types, true ) ) {
			return new WP_Error( 'not_found', 'Theme Builder layout not found', [ 'status' => 404 ] );
		}
		if ( ! is_string( $content ) ) {
			return new WP_Error( 'invalid_content', 'content must be a string of Divi block markup.', [ 'status' => 400 ] );
		}

		$result = wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $content ),
		], true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::invalidate_divi_cache( $post_id );

		return rest_ensure_response( [
			'success' => true,
			'id'      => $post_id,
			'type'    => $post->post_type,
			'message' => "Layout '{$post->post_title}' updated.",
		] );
	}

	/**
	 * Create a complete Theme Builder template with header/footer layouts.
	 */
	public static function create_tb_template( $request ) {
		$title          = sanitize_text_field( $request->get_param( 'title' ) );
		$condition      = sanitize_text_field( $request->get_param( 'condition' ) );
		$header_content = $request->get_param( 'header_content' ) ?? '';
		$footer_content = $request->get_param( 'footer_content' ) ?? '';
		if ( ! is_string( $header_content ) || ! is_string( $footer_content ) ) {
			return new WP_Error( 'invalid_content', 'header_content and footer_content must be strings when provided.', [ 'status' => 400 ] );
		}

		// Find the Theme Builder master post.
		$master = get_posts( [
			'post_type'      => 'et_theme_builder',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
		] );
		if ( empty( $master ) ) {
			return new WP_Error( 'no_theme_builder', 'Theme Builder master post not found', [ 'status' => 500 ] );
		}
		$master_id = $master[0]->ID;

		$header_id = 0;
		$footer_id = 0;

		// Create header layout if content provided.
		if ( '' !== $header_content ) {
			$header_id = wp_insert_post( [
				'post_title'   => $title . ' Header Layout',
				'post_content' => wp_slash( $header_content ),
				'post_type'    => 'et_header_layout',
				'post_status'  => 'publish',
			], true );
			if ( is_wp_error( $header_id ) ) {
				return $header_id;
			}
			self::initialize_divi_page_meta( $header_id );
		}

		// Create footer layout if content provided.
		if ( '' !== $footer_content ) {
			$footer_id = wp_insert_post( [
				'post_title'   => $title . ' Footer Layout',
				'post_content' => wp_slash( $footer_content ),
				'post_type'    => 'et_footer_layout',
				'post_status'  => 'publish',
			], true );
			if ( is_wp_error( $footer_id ) ) {
				return $footer_id;
			}
			self::initialize_divi_page_meta( $footer_id );
		}

		// Create template post.
		$template_id = wp_insert_post( [
			'post_title'  => $title,
			'post_type'   => 'et_template',
			'post_status' => 'publish',
		], true );
		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		// Set template meta.
		update_post_meta( $template_id, '_et_default', '0' );
		update_post_meta( $template_id, '_et_enabled', '1' );
		update_post_meta( $template_id, '_et_header_layout_id', $header_id );
		update_post_meta( $template_id, '_et_header_layout_enabled', $header_id ? '1' : '0' );
		update_post_meta( $template_id, '_et_body_layout_id', '0' );
		update_post_meta( $template_id, '_et_body_layout_enabled', '1' );
		update_post_meta( $template_id, '_et_footer_layout_id', $footer_id );
		update_post_meta( $template_id, '_et_footer_layout_enabled', $footer_id ? '1' : '0' );
		add_post_meta( $template_id, '_et_use_on', $condition );

		// Link to Theme Builder master.
		add_post_meta( $master_id, '_et_template', $template_id );

		return rest_ensure_response( [
			'success'          => true,
			'template_id'      => $template_id,
			'header_layout_id' => $header_id,
			'footer_layout_id' => $footer_id,
			'condition'        => $condition,
			'message'          => "Template '{$title}' created and linked to Theme Builder.",
		] );
	}

	// ── Canvas Operations ───────────────────────────────────────────

	/**
	 * Create a canvas (et_pb_canvas post) linked to a parent page.
	 */
	public static function create_canvas( $request ) {
		$title          = sanitize_text_field( $request->get_param( 'title' ) );
		$parent_page_id = absint( $request->get_param( 'parent_page_id' ) );
		$content        = $request->get_param( 'content' );
		$append_to_main = sanitize_key( (string) ( $request->get_param( 'append_to_main' ) ?? '' ) );
		$z_index        = $request->get_param( 'z_index' );

		// Validate canvas_id format if provided, otherwise auto-generate.
		$raw_canvas_id = $request->get_param( 'canvas_id' );
		if ( ! empty( $raw_canvas_id ) ) {
			$canvas_id = sanitize_text_field( $raw_canvas_id );
			if ( ! preg_match( '/^[A-Za-z0-9-]+$/', $canvas_id ) ) {
				return new WP_Error( 'invalid_canvas_id', 'canvas_id must contain only letters, numbers, and hyphens.', [ 'status' => 400 ] );
			}
		} else {
			$canvas_id = wp_generate_uuid4();
		}

		$parent = get_post( $parent_page_id );
		if ( ! $parent ) {
			return new WP_Error( 'not_found', 'Parent page not found', [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $parent_page_id ) ) {
			return new WP_Error( 'forbidden', 'Cannot edit this parent page', [ 'status' => 403 ] );
		}
		if ( null !== $content && ! is_string( $content ) ) {
			return new WP_Error( 'invalid_content', 'content must be a string of Divi block markup.', [ 'status' => 400 ] );
		}

		// Validate append_to_main value.
		if ( $append_to_main && ! in_array( $append_to_main, [ 'above', 'below' ], true ) ) {
			return new WP_Error( 'invalid_param', 'append_to_main must be "above" or "below".', [ 'status' => 400 ] );
		}

		// Wrap content in placeholder if it contains Divi blocks but no placeholder wrapper.
		if ( $content && false !== strpos( $content, '<!-- wp:divi/' ) && false === strpos( $content, '<!-- wp:divi/placeholder' ) ) {
			$content = "<!-- wp:divi/placeholder -->\n{$content}\n<!-- /wp:divi/placeholder -->";
		}

		$post_id = wp_insert_post( [
			'post_title'   => $title,
			'post_content' => wp_slash( $content ),
			'post_status'  => 'publish',
			'post_type'    => 'et_pb_canvas',
		], true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_divi_canvas_id', $canvas_id );
		update_post_meta( $post_id, '_divi_canvas_parent_post_id', $parent_page_id );
		update_post_meta( $post_id, '_divi_canvas_created_at', gmdate( 'c' ) );

		if ( $append_to_main ) {
			update_post_meta( $post_id, '_divi_canvas_append_to_main', $append_to_main );
		}
		if ( null !== $z_index ) {
			update_post_meta( $post_id, '_divi_canvas_z_index', (int) $z_index );
		}

		// Set Divi builder meta.
		update_post_meta( $post_id, '_et_pb_use_builder', 'on' );
		update_post_meta( $post_id, '_et_pb_use_divi_5', 'on' );

		// Invalidate parent page cache so Divi detects the new canvas.
		delete_post_meta( $parent_page_id, '_divi_dynamic_assets_canvases_used' );
		self::invalidate_divi_cache( $parent_page_id );

		return rest_ensure_response( [
			'success'        => true,
			'canvas_post_id' => $post_id,
			'canvas_id'      => $canvas_id,
			'parent_page_id' => $parent_page_id,
			'message'        => "Canvas '{$title}' created and linked to page {$parent_page_id}.",
		] );
	}

	/**
	 * List canvases, optionally filtered by parent page.
	 */
	public static function list_canvases( $request ) {
		$parent_page_id = $request->get_param( 'parent_page_id' );
		$parent_page_id = null !== $parent_page_id ? absint( $parent_page_id ) : null;
		$per_page       = max( 1, min( 100, absint( $request->get_param( 'per_page' ) ?? 50 ) ) );

		$query_args = [
			'post_type'      => 'et_pb_canvas',
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		if ( $parent_page_id ) {
			$query_args['meta_query'] = [ [
				'key'   => '_divi_canvas_parent_post_id',
				'value' => (int) $parent_page_id,
				'type'  => 'NUMERIC',
			] ];
		}

		$query    = new WP_Query( $query_args );
		$canvases = [];

		foreach ( $query->posts as $post ) {
			$canvases[] = [
				'canvas_post_id' => $post->ID,
				'title'          => $post->post_title,
				'canvas_id'      => get_post_meta( $post->ID, '_divi_canvas_id', true ),
				'parent_page_id' => (int) get_post_meta( $post->ID, '_divi_canvas_parent_post_id', true ),
				'append_to_main' => get_post_meta( $post->ID, '_divi_canvas_append_to_main', true ) ?: null,
				'z_index'        => get_post_meta( $post->ID, '_divi_canvas_z_index', true ) ?: null,
				'status'         => $post->post_status,
				'modified'       => $post->post_modified,
			];
		}

		return rest_ensure_response( [
			'canvases' => $canvases,
			'total'    => $query->found_posts,
		] );
	}

	/**
	 * Get a canvas's content and metadata.
	 */
	public static function get_canvas( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post || 'et_pb_canvas' !== $post->post_type ) {
			return new WP_Error( 'not_found', 'Canvas not found', [ 'status' => 404 ] );
		}

		return rest_ensure_response( [
			'canvas_post_id' => $post->ID,
			'title'          => $post->post_title,
			'canvas_id'      => get_post_meta( $post->ID, '_divi_canvas_id', true ),
			'parent_page_id' => (int) get_post_meta( $post->ID, '_divi_canvas_parent_post_id', true ),
			'append_to_main' => get_post_meta( $post->ID, '_divi_canvas_append_to_main', true ) ?: null,
			'z_index'        => get_post_meta( $post->ID, '_divi_canvas_z_index', true ) ?: null,
			'content'        => $post->post_content,
			'status'         => $post->post_status,
			'modified'       => $post->post_modified,
		] );
	}

	/**
	 * Update a canvas's content and/or metadata.
	 */
	public static function update_canvas( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post || 'et_pb_canvas' !== $post->post_type ) {
			return new WP_Error( 'not_found', 'Canvas not found', [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Cannot edit this canvas', [ 'status' => 403 ] );
		}

		$update_args = [ 'ID' => $post_id ];
		$content = $request->get_param( 'content' );
		$title   = $request->get_param( 'title' );
		if ( null !== $content && ! is_string( $content ) ) {
			return new WP_Error( 'invalid_content', 'content must be a string of Divi block markup.', [ 'status' => 400 ] );
		}
		if ( null !== $title && ! is_scalar( $title ) ) {
			return new WP_Error( 'invalid_title', 'title must be a string when provided.', [ 'status' => 400 ] );
		}

		if ( null !== $content ) {
			// Wrap content in placeholder if needed (same logic as create_canvas).
			if ( $content && false !== strpos( $content, '<!-- wp:divi/' ) && false === strpos( $content, '<!-- wp:divi/placeholder' ) ) {
				$content = "<!-- wp:divi/placeholder -->\n{$content}\n<!-- /wp:divi/placeholder -->";
			}
			$update_args['post_content'] = wp_slash( $content );
		}
		if ( null !== $title ) {
			$update_args['post_title'] = sanitize_text_field( $title );
		}

		if ( count( $update_args ) > 1 ) {
			$result = wp_update_post( $update_args, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$append_to_main = $request->get_param( 'append_to_main' );
		if ( null !== $append_to_main ) {
			$append_to_main = sanitize_key( (string) $append_to_main );
			if ( '' === $append_to_main ) {
				delete_post_meta( $post_id, '_divi_canvas_append_to_main' );
			} elseif ( in_array( $append_to_main, [ 'above', 'below' ], true ) ) {
				update_post_meta( $post_id, '_divi_canvas_append_to_main', $append_to_main );
			} else {
				return new WP_Error( 'invalid_param', 'append_to_main must be "above", "below", or "" to clear.', [ 'status' => 400 ] );
			}
		}

		$z_index = $request->get_param( 'z_index' );
		if ( null !== $z_index ) {
			update_post_meta( $post_id, '_divi_canvas_z_index', (int) $z_index );
		}

		// Invalidate parent page cache.
		$parent_page_id = (int) get_post_meta( $post_id, '_divi_canvas_parent_post_id', true );
		if ( $parent_page_id ) {
			delete_post_meta( $parent_page_id, '_divi_dynamic_assets_canvases_used' );
			self::invalidate_divi_cache( $parent_page_id );
		}

		return rest_ensure_response( [
			'success'        => true,
			'canvas_post_id' => $post_id,
			'message'        => 'Canvas updated successfully.',
		] );
	}

	/**
	 * Delete a canvas.
	 */
	public static function delete_canvas( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post || 'et_pb_canvas' !== $post->post_type ) {
			return new WP_Error( 'not_found', 'Canvas not found', [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'delete_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Cannot delete this canvas', [ 'status' => 403 ] );
		}

		$parent_page_id = (int) get_post_meta( $post_id, '_divi_canvas_parent_post_id', true );

		$deleted = wp_delete_post( $post_id, true );
		if ( ! $deleted ) {
			return new WP_Error( 'delete_failed', 'Failed to delete canvas', [ 'status' => 500 ] );
		}

		// Invalidate parent page cache.
		if ( $parent_page_id ) {
			delete_post_meta( $parent_page_id, '_divi_dynamic_assets_canvases_used' );
			self::invalidate_divi_cache( $parent_page_id );
		}

		return rest_ensure_response( [
			'success'              => true,
			'deleted_canvas_post_id' => $post_id,
			'parent_page_id'       => $parent_page_id,
			'message'              => 'Canvas deleted.',
		] );
	}

	// ── Preset Management ───────────────────────────────────────────

	/**
	 * Get D5 presets from the standalone WP option.
	 */
	private static function get_d5_presets() {
		$raw = get_option( 'et_divi_builder_global_presets_d5', '' );
		$d5  = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
		return is_array( $d5 ) || is_object( $d5 ) ? (array) $d5 : [];
	}

	/**
	 * Save D5 presets to both storage locations.
	 */
	private static function save_d5_presets( $d5 ) {
		update_option( 'et_divi_builder_global_presets_d5', $d5, false );
		et_update_option( 'builder_global_presets_d5', $d5 );
	}

	/**
	 * Collect all modulePreset UUIDs referenced across all posts/pages.
	 */
	private static function collect_page_preset_refs() {
		$posts = get_posts( [
			'post_type'      => [ 'page', 'post' ],
			'post_status'    => [ 'publish', 'draft', 'private' ],
			'posts_per_page' => -1,
		] );

		$all_uuids = [];
		$per_page  = [];

		foreach ( $posts as $p ) {
			preg_match_all( '/"modulePreset":\s*(\[[^\]]*\])/', $p->post_content, $matches );
			if ( empty( $matches[1] ) ) {
				continue;
			}

			$page_uuids = [];
			foreach ( $matches[1] as $m ) {
				$decoded = json_decode( $m, true );
				if ( ! is_array( $decoded ) ) {
					continue;
				}
				foreach ( $decoded as $uuid ) {
					if ( 'default' !== $uuid ) {
						$all_uuids[ $uuid ] = ( $all_uuids[ $uuid ] ?? 0 ) + 1;
						$page_uuids[]       = $uuid;
					}
				}
			}

			if ( ! empty( $page_uuids ) ) {
				$per_page[ $p->ID ] = [
					'title'        => $p->post_title,
					'total_refs'   => count( $matches[1] ),
					'custom_uuids' => array_values( array_unique( $page_uuids ) ),
				];
			}
		}

		return [ 'all_uuids' => $all_uuids, 'per_page' => $per_page ];
	}

	/**
	 * Detect spam preset names using generalized heuristics.
	 *
	 * A preset name is considered spam when it contains a repeated word or phrase
	 * (e.g. "Online Courses Online Courses Text") — a Divi bug that duplicates
	 * the module name prefix when presets are auto-created.
	 */
	private static function is_spam_preset_name( $name ) {
		if ( '' === $name ) {
			return false;
		}
		// Detect repeated word or multi-word phrases (e.g. "Button Button", "Online Courses Online Courses").
		if ( preg_match( '/\b([\p{L}\p{N}_]+(?:\s+[\p{L}\p{N}_]+){0,3})\s+\1\b/iu', $name ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Clean a spam preset name by collapsing repeated prefixes.
	 */
	private static function clean_spam_preset_name( $name ) {
		// Collapse all repeated word sequences at the start (e.g. "Online Courses Online Courses Online Courses Text" → "Online Courses Text").
		return trim( preg_replace( '/^((?:\S+\s+)*?\S+)(?:\s+\1\b)+/iu', '$1', $name ) );
	}

	/**
	 * Audit presets: categorize as spam/descriptive, referenced/unreferenced.
	 */
	public static function preset_audit( $request ) {
		$d5   = self::get_d5_presets();
		$refs = self::collect_page_preset_refs();

		$referenced_uuids = array_keys( $refs['all_uuids'] );

		$summary = [
			'total_presets'       => 0,
			'spam_referenced'    => [],
			'spam_unreferenced'  => [],
			'descriptive'        => [],
			'empty_defaults'     => [],
		];

		foreach ( [ 'module', 'group' ] as $type ) {
			if ( ! isset( $d5[ $type ] ) ) {
				continue;
			}
			foreach ( (array) $d5[ $type ] as $mod => $info ) {
				$info  = (array) $info;
				$items = isset( $info['items'] ) ? (array) $info['items'] : [];
				$summary['total_presets'] += count( $items );

				foreach ( $items as $pid => $preset ) {
					$preset      = (array) $preset;
					$name        = $preset['name'] ?? '';
					$has_content = ! empty( $preset['attrs'] ) || ! empty( $preset['styleAttrs'] );
					$is_spam     = self::is_spam_preset_name( $name );
					$is_ref      = in_array( $pid, $referenced_uuids, true );
					$is_default  = ( $info['default'] ?? '' ) === $pid;

					$entry = [
						'id'         => $pid,
						'module'     => $mod,
						'type'       => $type,
						'name'       => $name,
						'has_attrs'  => $has_content,
						'is_default' => $is_default,
						'referenced' => $is_ref,
						'ref_count'  => $refs['all_uuids'][ $pid ] ?? 0,
					];

					if ( ! $has_content ) {
						$summary['empty_defaults'][] = $entry;
					} elseif ( $is_spam && $is_ref ) {
						$summary['spam_referenced'][] = $entry;
					} elseif ( $is_spam && ! $is_ref ) {
						$summary['spam_unreferenced'][] = $entry;
					} else {
						$summary['descriptive'][] = $entry;
					}
				}
			}
		}

		return rest_ensure_response( [
			'total_presets'           => $summary['total_presets'],
			'spam_referenced_count'   => count( $summary['spam_referenced'] ),
			'spam_unreferenced_count' => count( $summary['spam_unreferenced'] ),
			'descriptive_count'       => count( $summary['descriptive'] ),
			'empty_default_count'     => count( $summary['empty_defaults'] ),
			'spam_referenced'         => $summary['spam_referenced'],
			'spam_unreferenced'       => $summary['spam_unreferenced'],
			'descriptive'             => $summary['descriptive'],
			'page_refs'               => $refs['per_page'],
			'total_referenced_uuids'  => count( $referenced_uuids ),
		] );
	}

	/**
	 * Cleanup presets. Modes:
	 * - Default: remove unreferenced spam presets, rename referenced spam names.
	 * - dedup=true: also remove duplicate presets with identical attrs.
	 * - action=rename_strip_prefix + prefix: strip a name prefix from all presets.
	 * - action=remove_orphans + scope=spam: remove unreferenced spam presets only.
	 * - action=remove_orphans + scope=all: remove all unreferenced non-default presets.
	 */
	public static function preset_cleanup( $request ) {
		$dry_run    = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? true );
		$dedup      = rest_sanitize_boolean( $request->get_param( 'dedup' ) ?? false );
		$action     = sanitize_key( (string) ( $request->get_param( 'action' ) ?? '' ) );
		$prefix     = sanitize_text_field( (string) ( $request->get_param( 'prefix' ) ?? '' ) );
		$scope_raw  = sanitize_key( (string) ( $request->get_param( 'scope' ) ?? '' ) );
		$scope      = in_array( $scope_raw, [ 'spam', 'all' ], true ) ? $scope_raw : 'spam';
		$d5         = self::get_d5_presets();
		$refs       = self::collect_page_preset_refs();

		$referenced_uuids = array_keys( $refs['all_uuids'] );

		$removed  = [];
		$renamed  = [];
		$deduped  = [];
		$kept     = 0;
		$modified = false;

		// Action: rename_strip_prefix — strip a prefix from all preset names.
		if ( 'rename_strip_prefix' === $action && '' !== $prefix ) {
			$prefix_len = strlen( $prefix );
			foreach ( [ 'module', 'group' ] as $type ) {
				if ( ! isset( $d5[ $type ] ) ) {
					continue;
				}
				foreach ( $d5[ $type ] as $mod => &$info ) {
					if ( ! is_array( $info ) ) {
						$info = (array) $info;
					}
					if ( ! isset( $info['items'] ) || ! is_array( $info['items'] ) ) {
						continue;
					}
					foreach ( $info['items'] as $pid => &$preset ) {
						if ( ! is_array( $preset ) ) {
							$preset = (array) $preset;
						}
						$name = $preset['name'] ?? '';
						if ( 0 === strpos( $name, $prefix ) ) {
							$new_name = substr( $name, $prefix_len );
							if ( '' !== $new_name ) {
								$renamed[] = [
									'id'       => $pid,
									'module'   => $mod,
									'old_name' => $name,
									'new_name' => $new_name,
								];
								if ( ! $dry_run ) {
									$preset['name'] = $new_name;
									$modified       = true;
								}
							}
						}
						$kept++;
					}
					unset( $preset );
				}
				unset( $info );
			}

			if ( ! $dry_run && $modified ) {
				self::save_d5_presets( $d5 );
			}

			return rest_ensure_response( [
				'dry_run'       => $dry_run,
				'action'        => $action,
				'prefix'        => $prefix,
				'renamed_count' => count( $renamed ),
				'kept_count'    => $kept,
				'renamed'       => $renamed,
			] );
		}

		// Action: remove_orphans — remove unreferenced presets.
		// scope=spam (default): only spam-named orphans. scope=all: all non-default orphans.
		if ( 'remove_orphans' === $action ) {
			foreach ( [ 'module', 'group' ] as $type ) {
				if ( ! isset( $d5[ $type ] ) ) {
					continue;
				}
				foreach ( $d5[ $type ] as $mod => &$info ) {
					if ( ! is_array( $info ) ) {
						$info = (array) $info;
					}
					if ( ! isset( $info['items'] ) || ! is_array( $info['items'] ) ) {
						continue;
					}
					$default_id = $info['default'] ?? '';

					foreach ( $info['items'] as $pid => $preset ) {
						$preset     = (array) $preset;
						$name       = $preset['name'] ?? '';
						$is_ref     = in_array( $pid, $referenced_uuids, true );
						$is_default = $pid === $default_id;

						$should_remove = ! $is_ref && ! $is_default;
						if ( 'spam' === $scope ) {
							$should_remove = $should_remove && self::is_spam_preset_name( $name );
						}

						if ( $should_remove ) {
							$removed[] = [ 'id' => $pid, 'module' => $mod, 'name' => $name ];
							if ( ! $dry_run ) {
								unset( $info['items'][ $pid ] );
								$modified = true;
							}
						} else {
							$kept++;
						}
					}
				}
				unset( $info );
			}

			if ( ! $dry_run && $modified ) {
				self::save_d5_presets( $d5 );
			}

			return rest_ensure_response( [
				'dry_run'       => $dry_run,
				'action'        => $action,
				'scope'         => $scope,
				'removed_count' => count( $removed ),
				'kept_count'    => $kept,
				'removed'       => $removed,
			] );
		}

		foreach ( [ 'module', 'group' ] as $type ) {
			if ( ! isset( $d5[ $type ] ) ) {
				continue;
			}
			foreach ( $d5[ $type ] as $mod => &$info ) {
				if ( ! is_array( $info ) ) {
					$info = (array) $info;
				}
				if ( ! isset( $info['items'] ) || ! is_array( $info['items'] ) ) {
					continue;
				}

				$default_id = $info['default'] ?? '';

				// Dedup pass: hash attrs to find identical presets.
				$seen_hashes = [];
				if ( $dedup ) {
					foreach ( $info['items'] as $pid => $preset ) {
						$preset = (array) $preset;
						$attrs  = $preset['attrs'] ?? null;
						if ( ! $attrs ) {
							continue;
						}
						$hash = md5( wp_json_encode( $attrs ) );
						if ( isset( $seen_hashes[ $hash ] ) ) {
							$keeper    = $seen_hashes[ $hash ];
							$is_ref    = in_array( $pid, $referenced_uuids, true );
							$is_def    = $pid === $default_id;
							$keep_ref  = in_array( $keeper, $referenced_uuids, true );
							$keep_def  = $keeper === $default_id;

							// Remove the one that is NOT referenced/default.
							if ( ! $is_ref && ! $is_def ) {
								$deduped[] = [
									'id'      => $pid,
									'module'  => $mod,
									'name'    => $preset['name'] ?? '',
									'kept_id' => $keeper,
								];
								if ( ! $dry_run ) {
									unset( $info['items'][ $pid ] );
									$modified = true;
								}
								continue;
							} elseif ( ! $keep_ref && ! $keep_def ) {
								// Swap: current one is referenced, keeper is not.
								$deduped[] = [
									'id'      => $keeper,
									'module'  => $mod,
									'name'    => ( (array) $info['items'][ $keeper ] )['name'] ?? '',
									'kept_id' => $pid,
								];
								if ( ! $dry_run ) {
									unset( $info['items'][ $keeper ] );
									$modified = true;
								}
								$seen_hashes[ $hash ] = $pid;
								continue;
							}
							// Both referenced/default — keep both.
						} else {
							$seen_hashes[ $hash ] = $pid;
						}
					}
				}

				// Spam cleanup pass.
				foreach ( $info['items'] as $pid => &$preset ) {
					if ( ! is_array( $preset ) ) {
						$preset = (array) $preset;
					}
					$name        = $preset['name'] ?? '';
					$is_spam     = self::is_spam_preset_name( $name );
					$is_ref      = in_array( $pid, $referenced_uuids, true );
					$is_default  = $pid === $default_id;

					if ( $is_spam && ! $is_ref && ! $is_default ) {
						$removed[] = [ 'id' => $pid, 'module' => $mod, 'name' => $name ];
						if ( ! $dry_run ) {
							unset( $info['items'][ $pid ] );
							$modified = true;
						}
					} elseif ( $is_spam && ( $is_ref || $is_default ) ) {
						$clean_name = self::clean_spam_preset_name( $name );
						if ( $clean_name !== $name ) {
							$renamed[] = [
								'id'       => $pid,
								'module'   => $mod,
								'old_name' => $name,
								'new_name' => $clean_name,
							];
							if ( ! $dry_run ) {
								$preset['name'] = $clean_name;
								$modified       = true;
							}
						}
						$kept++;
					} else {
						$kept++;
					}
				}
				unset( $preset );
			}
			unset( $info );
		}

		if ( ! $dry_run && $modified ) {
			self::save_d5_presets( $d5 );
		}

		return rest_ensure_response( [
			'dry_run'        => $dry_run,
			'removed_count'  => count( $removed ),
			'renamed_count'  => count( $renamed ),
			'deduped_count'  => count( $deduped ),
			'kept_count'     => $kept,
			'removed'        => $removed,
			'renamed'        => $renamed,
			'deduped'        => $deduped,
		] );
	}

	/**
	 * Update a specific preset by ID.
	 */
	public static function preset_update( $request ) {
		$preset_id = sanitize_text_field( $request->get_param( 'preset_id' ) );
		$new_name  = $request->get_param( 'name' );
		$new_attrs = $request->get_param( 'attrs' );

		$d5    = self::get_d5_presets();
		$found = false;

		foreach ( [ 'module', 'group' ] as $type ) {
			if ( ! isset( $d5[ $type ] ) ) {
				continue;
			}
			foreach ( $d5[ $type ] as $mod => &$info ) {
				if ( ! is_array( $info ) ) {
					$info = (array) $info;
				}
				if ( ! isset( $info['items'][ $preset_id ] ) ) {
					continue;
				}

				$preset = &$info['items'][ $preset_id ];
				if ( ! is_array( $preset ) ) {
					$preset = (array) $preset;
				}

				if ( null !== $new_name ) {
					$preset['name'] = sanitize_text_field( $new_name );
				}
				if ( null !== $new_attrs && is_array( $new_attrs ) ) {
					$preset['attrs']      = $new_attrs;
					$preset['styleAttrs'] = $new_attrs;
				}

				$preset['updated'] = time() * 1000;

				$found = [
					'id'     => $preset_id,
					'module' => $mod,
					'type'   => $type,
					'name'   => $preset['name'],
				];
				break 2;
			}
			unset( $info );
		}

		if ( ! $found ) {
			return new WP_Error( 'not_found', "Preset '{$preset_id}' not found", [ 'status' => 404 ] );
		}

		self::save_d5_presets( $d5 );

		return rest_ensure_response( [
			'success' => true,
			'preset'  => $found,
			'message' => "Preset '{$preset_id}' updated.",
		] );
	}

	/**
	 * Delete a specific preset by ID.
	 */
	public static function preset_delete( $request ) {
		$preset_id = sanitize_text_field( $request->get_param( 'preset_id' ) );

		$d5    = self::get_d5_presets();
		$found = false;

		foreach ( [ 'module', 'group' ] as $type ) {
			if ( ! isset( $d5[ $type ] ) ) {
				continue;
			}
			foreach ( $d5[ $type ] as $mod => &$info ) {
				if ( ! is_array( $info ) ) {
					$info = (array) $info;
				}
				if ( ! isset( $info['items'][ $preset_id ] ) ) {
					continue;
				}

				$preset = (array) $info['items'][ $preset_id ];
				$found  = [
					'id'     => $preset_id,
					'module' => $mod,
					'type'   => $type,
					'name'   => $preset['name'] ?? '',
				];
				unset( $info['items'][ $preset_id ] );
				break 2;
			}
			unset( $info );
		}

		if ( ! $found ) {
			return new WP_Error( 'not_found', "Preset '{$preset_id}' not found", [ 'status' => 404 ] );
		}

		self::save_d5_presets( $d5 );

		return rest_ensure_response( [
			'success' => true,
			'deleted' => $found,
			'message' => "Preset '{$preset_id}' deleted.",
		] );
	}

	// ── Helpers ──────────────────────────────────────────────────────

	/**
	 * Get a single section's markup by admin label or text content.
	 */
	public static function get_section( $request ) {
		$post_id    = absint( $request['id'] );
		$label      = sanitize_text_field( $request->get_param( 'label' ) ?? '' );
		$match_text = sanitize_text_field( $request->get_param( 'match_text' ) ?? '' );
		$occurrence = max( 1, absint( $request->get_param( 'occurrence' ) ?? 1 ) );
		$post       = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Page not found', [ 'status' => 404 ] );
		}

		if ( '' === $label && '' === $match_text ) {
			return new WP_Error( 'missing_target', 'Either "label" or "match_text" is required', [ 'status' => 400 ] );
		}

		$content = $post->post_content;
		$result  = self::extract_section( $content, $label, $match_text, $occurrence );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$target   = '' !== $label ? $label : "text:{$match_text}";
		$response = [
			'page_id'    => $post_id,
			'matched_by' => '' !== $label ? 'label' : 'text',
			'target'     => $target,
			'markup'     => $result['markup'],
		];

		if ( $result['total_matches'] > 1 ) {
			$response['occurrence']    = $occurrence;
			$response['total_matches'] = $result['total_matches'];
			$response['warning']       = "Multiple sections ({$result['total_matches']}) match {$target}. Use 'occurrence' param to target a specific one.";
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Update a specific module's attributes by admin label, text content, or auto_index.
	 * Attrs use dot notation: "content.decoration.headingFont.h2.font.desktop.value.color" => "#ff0000"
	 *
	 * Targeting modes (in priority order):
	 * 1. auto_index — match by type:N counter (e.g. "text:5", "icon:3")
	 * 2. label — match by meta.adminLabel (exact), with optional occurrence
	 * 3. match_text — match by innerContent text (substring, first match)
	 */
	public static function update_module( $request ) {
		$post_id    = absint( $request['id'] );
		$label      = $request->get_param( 'label' );
		$match_text = $request->get_param( 'match_text' );
		$auto_index = $request->get_param( 'auto_index' );
		$occurrence = max( 1, absint( $request->get_param( 'occurrence' ) ?? 1 ) );
		$attrs      = $request->get_param( 'attrs' );
		$post       = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Page not found', [ 'status' => 404 ] );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Cannot edit this post', [ 'status' => 403 ] );
		}

		if ( empty( $label ) && empty( $match_text ) && empty( $auto_index ) ) {
			return new WP_Error( 'missing_target', 'One of "label", "match_text", or "auto_index" is required', [ 'status' => 400 ] );
		}
		if ( ! is_array( $attrs ) ) {
			return new WP_Error( 'invalid_attrs', 'attrs must be an object or associative array.', [ 'status' => 400 ] );
		}

		$label      = ! empty( $label ) ? sanitize_text_field( $label ) : '';
		$match_text = ! empty( $match_text ) ? sanitize_text_field( $match_text ) : '';
		$auto_index = ! empty( $auto_index ) ? sanitize_text_field( $auto_index ) : '';

		$content = $post->post_content;

		// Determine targeting mode.
		$mode = '';
		if ( ! empty( $auto_index ) ) {
			$mode = 'auto_index';
		} elseif ( ! empty( $label ) ) {
			$mode = 'label';
		} else {
			$mode = 'text';
		}

		// Build the search needle for label mode.
		$needle = 'label' === $mode
			? '"adminLabel":{"desktop":{"value":"' . $label . '"}}'
			: '';

		// For auto_index, parse "type:N" format.
		$ai_type   = '';
		$ai_target = 0;
		if ( 'auto_index' === $mode ) {
			$parts = explode( ':', $auto_index );
			if ( 2 !== count( $parts ) || '' === $parts[0] || ! ctype_digit( $parts[1] ) || (int) $parts[1] < 1 ) {
				return new WP_Error( 'invalid_auto_index', "auto_index must be 'type:N' format with N >= 1 (e.g. 'text:5')", [ 'status' => 400 ] );
			}
			$ai_type   = $parts[0];
			$ai_target = (int) $parts[1];
		}

		// Scan all blocks in document order (matching get_page_layout's auto_index counting).
		$all_matches   = []; // For label mode: collect all matches.
		$found_match   = null; // The single match to apply.
		$type_counters = []; // For auto_index mode.

		$prefix_len = strlen( self::BLOCK_PREFIX );
		$offset     = 0;
		while ( false !== ( $pos = strpos( $content, self::BLOCK_PREFIX, $offset ) ) ) {
			// Find the block type name — ends at space, / (self-closing), or --> (bare close).
			$search_from   = $pos + $prefix_len;
			$space_pos     = strpos( $content, ' ', $search_from );
			$slash_pos     = strpos( $content, '/', $search_from );
			$comment_close = strpos( $content, '-->', $search_from );

			$type_end = min(
				false !== $space_pos     ? $space_pos     : PHP_INT_MAX,
				false !== $slash_pos     ? $slash_pos     : PHP_INT_MAX,
				false !== $comment_close ? $comment_close : PHP_INT_MAX
			);
			if ( PHP_INT_MAX === $type_end ) {
				break;
			}
			$type = substr( $content, $search_from, $type_end - $search_from );

			// Track auto_index counters per type (document order) — count ALL blocks
			// including those without JSON attrs, to match parse_blocks() counting.
			if ( ! isset( $type_counters[ $type ] ) ) {
				$type_counters[ $type ] = 0;
			}
			$type_counters[ $type ]++;

			// Blocks without JSON attrs can't be updated, but still count for auto_index.
			$next_char = isset( $content[ $type_end + 1 ] ) ? $content[ $type_end + 1 ] : '';
			$has_json  = ( ' ' === $content[ $type_end ] && '{' === $next_char );
			if ( ! $has_json ) {
				// Skip to end of comment for non-JSON blocks.
				$skip_end = strpos( $content, '-->', $pos );
				$offset   = $skip_end ? $skip_end + 3 : $type_end;
				continue;
			}

			$self_close = strpos( $content, '/-->', $pos );
			$container  = strpos( $content, '-->', $pos );

			if ( false === $container ) {
				break;
			}

			$is_self_closing = ( $self_close !== false && $self_close <= $container + 1 );
			$comment_end     = $is_self_closing ? $self_close + 4 : $container + 3;
			$comment         = substr( $content, $pos, $comment_end - $pos );

			$match_info = [
				'pos'             => $pos,
				'comment_end'     => $comment_end,
				'comment'         => $comment,
				'type'            => $type,
				'is_self_closing' => $is_self_closing,
			];

			if ( 'auto_index' === $mode ) {
				if ( $type === $ai_type && $type_counters[ $type ] === $ai_target ) {
					$found_match = $match_info;
					break;
				}
			} elseif ( 'label' === $mode ) {
				if ( false !== strpos( $comment, $needle ) ) {
					$all_matches[] = $match_info;
				}
			} else {
				// Text matching: first match in document order wins.
				if ( false !== stripos( $comment, $match_text ) ) {
					$found_match = $match_info;
					break;
				}
			}

			$offset = $comment_end;
		}

		// For label mode, apply occurrence.
		$total_matches = 0;
		if ( 'label' === $mode ) {
			$total_matches = count( $all_matches );
			if ( 0 === $total_matches ) {
				return new WP_Error( 'module_not_found', "No module found with admin label '{$label}'", [ 'status' => 404 ] );
			}
			if ( $occurrence < 1 || $occurrence > $total_matches ) {
				return new WP_Error(
					'invalid_occurrence',
					"Requested occurrence {$occurrence} but only {$total_matches} module(s) match label '{$label}'",
					[ 'status' => 400 ]
				);
			}
			$found_match = $all_matches[ $occurrence - 1 ];
		}

		if ( ! $found_match ) {
			$target_desc = 'auto_index' === $mode ? $auto_index : "text '{$match_text}'";
			return new WP_Error( 'module_not_found', "No module found matching {$target_desc}", [ 'status' => 404 ] );
		}

		// Extract JSON attrs from the matched block.
		$comment         = $found_match['comment'];
		$is_self_closing = $found_match['is_self_closing'];
		$type            = $found_match['type'];
		$pos             = $found_match['pos'];
		$comment_end     = $found_match['comment_end'];

		$json_start = strpos( $comment, '{' );
		$json_end   = $is_self_closing
			? strrpos( $comment, '}', strrpos( $comment, '/-->' ) - strlen( $comment ) )
			: strrpos( $comment, '}', strrpos( $comment, '-->' ) - strlen( $comment ) );

		if ( false === $json_start || false === $json_end ) {
			return new WP_Error( 'parse_error', 'Could not parse block attributes', [ 'status' => 500 ] );
		}

		$json_str    = substr( $comment, $json_start, $json_end - $json_start + 1 );
		$block_attrs = json_decode( $json_str, true );

		if ( ! is_array( $block_attrs ) ) {
			return new WP_Error( 'parse_error', 'Could not parse block attributes', [ 'status' => 500 ] );
		}

		// Apply dot-notation attrs.
		foreach ( $attrs as $path => $value ) {
			$keys = explode( '.', $path );
			$ref  = &$block_attrs;
			foreach ( $keys as $i => $key ) {
				if ( $i === count( $keys ) - 1 ) {
					$ref[ $key ] = $value;
				} else {
					if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
						$ref[ $key ] = [];
					}
					$ref = &$ref[ $key ];
				}
			}
			unset( $ref );
		}

		// Re-encode and replace.
		$new_json    = wp_json_encode( $block_attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$prefix      = '<!-- wp:divi/' . $type . ' ';
		$suffix      = $is_self_closing ? ' /-->' : ' -->';
		$new_comment = $prefix . $new_json . $suffix;

		$content = substr_replace( $content, $new_comment, $pos, $comment_end - $pos );

		// Save.
		$result = wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $content ),
		], true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::invalidate_divi_cache( $post_id );

		// Build response.
		$target_desc = '';
		$matched_by  = $mode;
		if ( 'auto_index' === $mode ) {
			$target_desc = $auto_index;
		} elseif ( 'label' === $mode ) {
			$target_desc = $label;
		} else {
			$target_desc = "text:{$match_text}";
		}

		$response = [
			'success'    => true,
			'page_id'    => $post_id,
			'matched_by' => $matched_by,
			'target'     => $target_desc,
			'updated'    => array_keys( $attrs ),
			'message'    => "Module '{$target_desc}' updated successfully.",
		];

		if ( 'label' === $mode && $total_matches > 1 ) {
			$response['occurrence']    = $occurrence;
			$response['total_matches'] = $total_matches;
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Find a block by label, match_text, or auto_index and return its full bounds.
	 *
	 * Returns the block's start/end positions in the content string, including
	 * inner blocks and closing tag for container blocks.
	 *
	 * @param string $content    Page content.
	 * @param string $label      Admin label (exact match). Empty to skip.
	 * @param string $match_text Text search (case-insensitive substring). Empty to skip.
	 * @param string $auto_index Auto-index in "type:N" format. Empty to skip.
	 * @param int    $occurrence Which label match to target (1-based).
	 * @return array|WP_Error    ['start', 'end', 'type', 'matched_by', 'target_desc'] or WP_Error.
	 */
	private static function find_block( $content, $label, $match_text, $auto_index, $occurrence = 1 ) {
		// Determine targeting mode.
		$mode = '';
		if ( '' !== $auto_index ) {
			$mode = 'auto_index';
		} elseif ( '' !== $label ) {
			$mode = 'label';
		} elseif ( '' !== $match_text ) {
			$mode = 'text';
		} else {
			return new WP_Error( 'missing_target', 'One of "label", "match_text", or "auto_index" is required', [ 'status' => 400 ] );
		}

		$needle = 'label' === $mode
			? '"adminLabel":{"desktop":{"value":"' . $label . '"}}'
			: '';

		$ai_type   = '';
		$ai_target = 0;
		if ( 'auto_index' === $mode ) {
			$parts = explode( ':', $auto_index );
			if ( 2 !== count( $parts ) || '' === $parts[0] || ! ctype_digit( $parts[1] ) || (int) $parts[1] < 1 ) {
				return new WP_Error( 'invalid_auto_index', "auto_index must be 'type:N' format with N >= 1", [ 'status' => 400 ] );
			}
			$ai_type   = $parts[0];
			$ai_target = (int) $parts[1];
		}

		$prefix_len    = strlen( self::BLOCK_PREFIX );
		$offset        = 0;
		$type_counters = [];
		$all_matches   = [];
		$found_match   = null;

		while ( false !== ( $pos = strpos( $content, self::BLOCK_PREFIX, $offset ) ) ) {
			$search_from   = $pos + $prefix_len;
			$space_pos     = strpos( $content, ' ', $search_from );
			$slash_pos     = strpos( $content, '/', $search_from );
			$comment_close = strpos( $content, '-->', $search_from );

			$type_end = min(
				false !== $space_pos     ? $space_pos     : PHP_INT_MAX,
				false !== $slash_pos     ? $slash_pos     : PHP_INT_MAX,
				false !== $comment_close ? $comment_close : PHP_INT_MAX
			);
			if ( PHP_INT_MAX === $type_end ) {
				break;
			}
			$type = substr( $content, $search_from, $type_end - $search_from );

			if ( ! isset( $type_counters[ $type ] ) ) {
				$type_counters[ $type ] = 0;
			}
			$type_counters[ $type ]++;

			// Determine if self-closing or container.
			$self_close = strpos( $content, '/-->', $pos );
			$container  = strpos( $content, '-->', $pos );
			if ( false === $container ) {
				break;
			}
			$is_self_closing = ( false !== $self_close && $self_close <= $container + 1 );
			$comment_end     = $is_self_closing ? $self_close + 4 : $container + 3;
			$comment         = substr( $content, $pos, $comment_end - $pos );

			// Calculate full block end (including inner blocks + closing tag for containers).
			$block_end = $comment_end;
			if ( ! $is_self_closing ) {
				$close_tag     = '<!-- /wp:divi/' . $type . ' -->';
				$close_tag_len = strlen( $close_tag );
				$open_tag      = '<!-- wp:divi/' . $type;
				$open_tag_len  = strlen( $open_tag );
				$depth         = 1;
				$scan          = $comment_end;
				$len           = strlen( $content );

				while ( $depth > 0 && $scan < $len ) {
					$next_open  = strpos( $content, $open_tag, $scan );
					$next_close = strpos( $content, $close_tag, $scan );
					if ( false === $next_close ) {
						break;
					}
					// Validate $next_open is the exact type (not a prefix of a longer name).
					if ( false !== $next_open && $next_open < $next_close ) {
						$char_after = $content[ $next_open + $open_tag_len ] ?? '';
						if ( ' ' === $char_after || '{' === $char_after ) {
							$depth++;
						}
						$scan = $next_open + $open_tag_len;
					} else {
						$depth--;
						if ( 0 === $depth ) {
							$block_end = $next_close + $close_tag_len;
						}
						$scan = $next_close + $close_tag_len;
					}
				}

				// If closing tag was never found, the content is malformed.
				if ( $depth > 0 ) {
					return new WP_Error( 'parse_error', "Malformed content: no closing tag found for {$type} block", [ 'status' => 500 ] );
				}
			}

			$match_info = [
				'start' => $pos,
				'end'   => $block_end,
				'type'  => $type,
			];

			if ( 'auto_index' === $mode ) {
				if ( $type === $ai_type && $type_counters[ $type ] === $ai_target ) {
					$found_match = $match_info;
					break;
				}
			} elseif ( 'label' === $mode ) {
				if ( false !== strpos( $comment, $needle ) ) {
					$all_matches[] = $match_info;
				}
			} else {
				// Search opening comment only (not full block content). This targets
				// leaf modules by their attrs/text, consistent with update_module.
				// Searching full content would match parent containers before children.
				if ( false !== stripos( $comment, $match_text ) ) {
					$found_match = $match_info;
					break;
				}
			}

			$offset = $comment_end;
		}

		// For label mode, apply occurrence.
		if ( 'label' === $mode ) {
			if ( empty( $all_matches ) ) {
				return new WP_Error( 'block_not_found', "No block found with admin label '{$label}'", [ 'status' => 404 ] );
			}
			if ( $occurrence < 1 || $occurrence > count( $all_matches ) ) {
				return new WP_Error(
					'invalid_occurrence',
					"Requested occurrence {$occurrence} but only " . count( $all_matches ) . " block(s) match label '{$label}'",
					[ 'status' => 400 ]
				);
			}
			$found_match = $all_matches[ $occurrence - 1 ];
		}

		if ( ! $found_match ) {
			$target_desc = 'auto_index' === $mode ? $auto_index : ( 'label' === $mode ? $label : "text '{$match_text}'" );
			return new WP_Error( 'block_not_found', "No block found matching {$target_desc}", [ 'status' => 404 ] );
		}

		// Build target description.
		if ( 'auto_index' === $mode ) {
			$target_desc = $auto_index;
		} elseif ( 'label' === $mode ) {
			$target_desc = $label;
		} else {
			$target_desc = "text:{$match_text}";
		}

		return array_merge( $found_match, [
			'matched_by'  => $mode,
			'target_desc' => $target_desc,
		] );
	}

	/**
	 * Move a module to a new position on the page.
	 */
	public static function move_module( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Page not found', [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Cannot edit this post', [ 'status' => 403 ] );
		}

		$position = sanitize_key( (string) $request->get_param( 'position' ) );
		if ( ! in_array( $position, [ 'before', 'after' ], true ) ) {
			return new WP_Error( 'invalid_position', 'Position must be "before" or "after"', [ 'status' => 400 ] );
		}

		// Source targeting params.
		$src_label      = sanitize_text_field( $request->get_param( 'source_label' ) ?? '' );
		$src_match_text = sanitize_text_field( $request->get_param( 'source_match_text' ) ?? '' );
		$src_auto_index = sanitize_text_field( $request->get_param( 'source_auto_index' ) ?? '' );
		$src_occurrence = max( 1, absint( $request->get_param( 'source_occurrence' ) ?? 1 ) );

		// Target targeting params.
		$tgt_label      = sanitize_text_field( $request->get_param( 'target_label' ) ?? '' );
		$tgt_match_text = sanitize_text_field( $request->get_param( 'target_match_text' ) ?? '' );
		$tgt_auto_index = sanitize_text_field( $request->get_param( 'target_auto_index' ) ?? '' );
		$tgt_occurrence = max( 1, absint( $request->get_param( 'target_occurrence' ) ?? 1 ) );

		$content = $post->post_content;

		// Find both blocks in the original content.
		$source = self::find_block( $content, $src_label, $src_match_text, $src_auto_index, $src_occurrence );
		if ( is_wp_error( $source ) ) {
			$source->add_data( [ 'context' => 'source' ] );
			return $source;
		}

		$target = self::find_block( $content, $tgt_label, $tgt_match_text, $tgt_auto_index, $tgt_occurrence );
		if ( is_wp_error( $target ) ) {
			$target->add_data( [ 'context' => 'target' ] );
			return $target;
		}

		// Validate: source and target must not overlap.
		if ( $source['start'] < $target['end'] && $target['start'] < $source['end'] ) {
			return new WP_Error( 'overlap', 'Source and target blocks overlap — cannot move a block inside itself', [ 'status' => 400 ] );
		}

		// Check for no-op.
		if ( 'before' === $position && $source['end'] === $target['start'] ) {
			return rest_ensure_response( [
				'success' => true,
				'page_id' => $post_id,
				'message' => 'Module is already in the requested position (no change).',
				'source'  => $source['target_desc'],
				'target'  => $target['target_desc'],
			] );
		}
		if ( 'after' === $position && $target['end'] === $source['start'] ) {
			return rest_ensure_response( [
				'success' => true,
				'page_id' => $post_id,
				'message' => 'Module is already in the requested position (no change).',
				'source'  => $source['target_desc'],
				'target'  => $target['target_desc'],
			] );
		}

		// Extract source markup.
		$source_markup = substr( $content, $source['start'], $source['end'] - $source['start'] );
		$source_len    = $source['end'] - $source['start'];

		// Determine raw insertion point.
		$insert_pos = 'before' === $position ? $target['start'] : $target['end'];

		// Remove source and adjust insertion point if source precedes it.
		$content = substr( $content, 0, $source['start'] ) . substr( $content, $source['end'] );
		if ( $source['start'] < $insert_pos ) {
			$insert_pos -= $source_len;
		}

		// Insert source markup at adjusted position.
		$content = substr( $content, 0, $insert_pos ) . $source_markup . substr( $content, $insert_pos );

		// Save.
		$result = wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $content ),
		], true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::invalidate_divi_cache( $post_id );

		return rest_ensure_response( [
			'success'    => true,
			'page_id'    => $post_id,
			'source'     => $source['target_desc'],
			'source_type' => $source['type'],
			'target'     => $target['target_desc'],
			'target_type' => $target['type'],
			'position'   => $position,
			'message'    => "Moved '{$source['target_desc']}' ({$source['type']}) {$position} '{$target['target_desc']}' ({$target['type']}).",
		] );
	}

	/**
	 * Invalidate Divi's static CSS cache for a post so style changes render immediately.
	 */
	private static function invalidate_divi_cache( $post_id ) {
		// Delete Divi's static CSS files for this post.
		$cache_dir = WP_CONTENT_DIR . '/et-cache/' . intval( $post_id );
		if ( is_dir( $cache_dir ) ) {
			$files = glob( $cache_dir . '/*' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						unlink( $file );
					}
				}
			}
		}

		// Touch the post modified date to trigger Divi's style regeneration.
		wp_update_post( [
			'ID'            => $post_id,
			'post_modified' => current_time( 'mysql' ),
		] );

		// Clear Divi's transient caches regardless of touch result.
		delete_transient( 'et_builder_css_' . $post_id );
		delete_post_meta( $post_id, '_et_builder_module_features_cache' );
	}

	/**
	 * Find all sections matching by label or text content.
	 *
	 * @param string $content    Page content.
	 * @param string $label      Admin label to match (exact). Empty to skip.
	 * @param string $match_text Text to search for in section content (case-insensitive substring). Empty to skip.
	 * @return array Array of ['start' => int, 'end' => int] positions.
	 */
	private static function find_all_sections( $content, $label = '', $match_text = '' ) {
		$needle  = '' !== $label ? '"adminLabel":{"desktop":{"value":"' . $label . '"}}' : '';
		$results = [];
		$offset  = 0;

		$open_len  = strlen( self::SECTION_OPEN );
		$close_len = strlen( self::SECTION_CLOSE );

		// Match sections with or without JSON attrs.
		while ( false !== ( $pos = strpos( $content, self::SECTION_OPEN, $offset ) ) ) {
			// Ensure this is 'divi/section', not a longer name like 'divi/section-special'.
			// Valid chars after the tag name: ' ' (bare) or '{' (has JSON attrs).
			if ( isset( $content[ $pos + $open_len ] ) && ' ' !== $content[ $pos + $open_len ] && '{' !== $content[ $pos + $open_len ] ) {
				$offset = $pos + $open_len;
				continue;
			}

			$comment_end = strpos( $content, '-->', $pos );
			if ( false === $comment_end ) {
				break;
			}
			$comment = substr( $content, $pos, $comment_end - $pos + 3 );

			// For label mode, check the opening comment first (short-circuit).
			if ( '' !== $needle && false === strpos( $comment, $needle ) ) {
				$offset = $comment_end + 3;
				continue;
			}

			// Find closing tag by counting nested sections.
			$opening_end = $comment_end + 3;
			$depth       = 1;
			$scan        = $opening_end;
			$len         = strlen( $content );
			$section_end = false;

			while ( $depth > 0 && $scan < $len ) {
				$next_open  = strpos( $content, self::SECTION_OPEN, $scan );
				$next_close = strpos( $content, self::SECTION_CLOSE, $scan );
				if ( false === $next_close ) {
					break;
				}
				if ( false !== $next_open && $next_open < $next_close ) {
					$depth++;
					$scan = $next_open + $open_len;
				} else {
					$depth--;
					if ( 0 === $depth ) {
						$section_end = $next_close + $close_len;
					}
					$scan = $next_close + $close_len;
				}
			}

			if ( false !== $section_end ) {
				// Label mode already matched above. Text mode checks full section.
				$is_match = '' !== $needle; // Label already confirmed.
				if ( ! $is_match && '' !== $match_text ) {
					$section_content = substr( $content, $pos, $section_end - $pos );
					$is_match = false !== stripos( $section_content, $match_text );
				}

				if ( $is_match ) {
					$results[] = [ 'start' => $pos, 'end' => $section_end ];
				}
			}

			$offset = $comment_end + 3;
		}

		return $results;
	}

	/**
	 * Get the Nth matching section (1-based). Returns markup + total_matches or WP_Error.
	 *
	 * @param string $content    Page content.
	 * @param string $label      Admin label (exact match). Empty to skip.
	 * @param string $match_text Text search (case-insensitive substring). Empty to skip.
	 * @param int    $occurrence Which match to return (1-based).
	 */
	private static function extract_section( $content, $label = '', $match_text = '', $occurrence = 1 ) {
		$matches = self::find_all_sections( $content, $label, $match_text );
		$target  = '' !== $label ? "label '{$label}'" : "text '{$match_text}'";

		if ( empty( $matches ) ) {
			return new WP_Error( 'section_not_found', "No section found matching {$target}", [ 'status' => 404 ] );
		}

		if ( $occurrence < 1 || $occurrence > count( $matches ) ) {
			return new WP_Error(
				'invalid_occurrence',
				"Requested occurrence {$occurrence} but only " . count( $matches ) . " section(s) match {$target}",
				[ 'status' => 400 ]
			);
		}

		$match = $matches[ $occurrence - 1 ];
		$markup = substr( $content, $match['start'], $match['end'] - $match['start'] );

		return [
			'markup'        => $markup,
			'total_matches' => count( $matches ),
		];
	}

	/**
	 * Replace or remove the Nth matching section (1-based).
	 *
	 * @param string $content     Page content.
	 * @param string $label       Admin label (exact). Empty to skip.
	 * @param string $replacement New section markup (empty string to remove).
	 * @param string $match_text  Text search (case-insensitive substring). Empty to skip.
	 * @param int    $occurrence  Which match to target (1-based).
	 * @return array|WP_Error ['content' => string, 'total_matches' => int] or WP_Error.
	 */
	private static function find_and_replace_section( $content, $label, $replacement, $match_text = '', $occurrence = 1 ) {
		$matches = self::find_all_sections( $content, $label, $match_text );
		$target  = '' !== $label ? "label '{$label}'" : "text '{$match_text}'";

		if ( empty( $matches ) ) {
			return new WP_Error(
				'section_not_found',
				"No section found matching {$target}",
				[ 'status' => 404 ]
			);
		}

		if ( $occurrence < 1 || $occurrence > count( $matches ) ) {
			return new WP_Error(
				'invalid_occurrence',
				"Requested occurrence {$occurrence} but only " . count( $matches ) . " section(s) match {$target}",
				[ 'status' => 400 ]
			);
		}

		$match  = $matches[ $occurrence - 1 ];
		$before = substr( $content, 0, $match['start'] );
		$after  = substr( $content, $match['end'] );

		return [
			'content'       => $before . $replacement . $after,
			'total_matches' => count( $matches ),
		];
	}

	/**
	 * Check if a post uses Divi builder (has divi/* blocks).
	 */
	private static function post_uses_divi( $post ) {
		return (bool) preg_match( '/<!-- wp:divi\//', $post->post_content );
	}

	/**
	 * Check if incoming content contains Divi block markup.
	 */
	private static function content_uses_divi( $content ) {
		return is_string( $content ) && false !== strpos( $content, '<!-- wp:divi/' );
	}

	/**
	 * Seed the minimum metadata Divi expects for builder-backed pages.
	 *
	 * This mirrors Divi's own onboarding and page creation helpers.
	 */
	private static function initialize_divi_page_meta( $post_id ) {
		update_post_meta( $post_id, '_et_pb_use_builder', 'on' );
		update_post_meta( $post_id, '_et_pb_use_divi_5', 'on' );
		update_post_meta( $post_id, '_et_pb_page_layout', 'et_full_width_page' );
		update_post_meta( $post_id, '_et_pb_built_for_post_type', 'page' );
		// Uses default page.php template (with header/footer).
		// et_full_width_page layout removes the sidebar.
		// For blank (no header/footer), set template to 'page-template-blank.php' via set_page_meta.
	}

	/**
	 * Parse block tree into a flat/nested structure with targeting metadata.
	 *
	 * @param array $blocks     Parsed blocks from parse_blocks().
	 * @param int   $depth      Current nesting depth.
	 * @param array $counters   Per-type sequential counters for auto_index.
	 * @param bool  $full       Include full attrs (true) or targeting metadata only (false).
	 */
	private static function parse_block_tree( $blocks, $depth = 0, &$counters = [], $full = false ) {
		$result = [];

		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue; // Skip freeform/empty blocks.
			}

			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];

			// Extract admin label if present.
			$admin_label = self::get_nested_array_value( $attrs, [ 'module', 'meta', 'adminLabel', 'desktop', 'value' ], '' );
			if ( '' === $admin_label ) {
				$admin_label = self::get_nested_array_value( $attrs, [ 'meta', 'adminLabel', 'desktop', 'value' ], '' );
			}

			// Extract text content preview for targeting.
			$text_preview = '';
			$inner_content_paths = [
				[ 'content', 'innerContent', 'desktop', 'value' ],
				[ 'title', 'innerContent', 'desktop', 'value' ],
				[ 'button', 'innerContent', 'desktop', 'value', 'text' ],
			];
			foreach ( $inner_content_paths as $path ) {
				$val = self::get_nested_array_value( $attrs, $path );
				if ( is_string( $val ) && '' !== $val ) {
					$text_preview = wp_strip_all_tags( html_entity_decode( $val ) );
					$text_preview = mb_substr( trim( $text_preview ), 0, 50 );
					break;
				}
			}

			// Generate auto-index for this block type.
			$short_name = str_replace( 'divi/', '', $block['blockName'] );
			if ( ! isset( $counters[ $short_name ] ) ) {
				$counters[ $short_name ] = 0;
			}
			$counters[ $short_name ]++;
			$auto_index = $short_name . ':' . $counters[ $short_name ];

			$item = [
				'block_name'   => $block['blockName'],
				'depth'        => $depth,
				'admin_label'  => $admin_label,
				'text_preview' => $text_preview,
				'auto_index'   => $auto_index,
			];

			// Only include full attrs in full mode.
			if ( $full ) {
				$item['attrs'] = $attrs;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$item['inner_blocks'] = self::parse_block_tree( $block['innerBlocks'], $depth + 1, $counters, $full );
			}

			$result[] = $item;
		}

		return $result;
	}

	/**
	 * Count registered Divi modules.
	 */
	private static function get_active_module_count() {
		$registry = WP_Block_Type_Registry::get_instance();
		$count    = 0;
		foreach ( array_keys( $registry->get_all_registered() ) as $name ) {
			if ( 0 === strpos( $name, 'divi/' ) ) {
				$count++;
			}
		}
		return $count;
	}

	// ── Variable Manager CRUD ──────────────────────────────────────

	/**
	 * List all variables, optionally filtered by type or ID prefix.
	 * Colors come from et_divi.et_global_data.global_colors.
	 * Numbers/strings/images/links/fonts come from et_divi_global_variables.
	 */
	public static function list_variables( $request ) {
		$filter_type   = sanitize_key( (string) ( $request->get_param( 'type' ) ?? '' ) );
		$filter_prefix = sanitize_text_field( (string) ( $request->get_param( 'prefix' ) ?? '' ) );
		$result        = [];

		$valid_types = [ 'colors', 'numbers', 'strings', 'images', 'links', 'fonts' ];
		if ( $filter_type && ! in_array( $filter_type, $valid_types, true ) ) {
			return new WP_Error( 'invalid_type', 'Type must be one of: ' . implode( ', ', $valid_types ), [ 'status' => 400 ] );
		}

		// Colors (separate storage).
		if ( ! $filter_type || 'colors' === $filter_type ) {
			$raw         = et_get_option( 'et_global_data' );
			$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
			$colors      = is_array( $global_data ) ? ( $global_data['global_colors'] ?? [] ) : [];

			foreach ( $colors as $id => $c ) {
				if ( ! is_array( $c ) ) {
					continue;
				}
				if ( $filter_prefix && 0 !== strpos( $id, $filter_prefix ) ) {
					continue;
				}
				$result[] = [
					'id'    => $id,
					'type'  => 'colors',
					'label' => $c['label'] ?? $id,
					'value' => $c['color'] ?? '',
				];
			}
		}

		// Non-color types from et_divi_global_variables.
		$vars      = get_option( 'et_divi_global_variables', [] );
		if ( ! is_array( $vars ) ) {
			$vars = [];
		}
		$var_types  = [ 'numbers', 'strings', 'images', 'links', 'fonts' ];

		foreach ( $var_types as $type ) {
			if ( $filter_type && $filter_type !== $type ) {
				continue;
			}
			if ( ! is_array( $vars[ $type ] ?? null ) ) {
				continue;
			}
			foreach ( $vars[ $type ] as $id => $v ) {
				if ( ! is_array( $v ) ) {
					continue;
				}
				if ( $filter_prefix && 0 !== strpos( $id, $filter_prefix ) ) {
					continue;
				}
				$result[] = [
					'id'    => $id,
					'type'  => $type,
					'label' => $v['label'] ?? $id,
					'value' => $v['value'] ?? '',
				];
			}
		}

		return rest_ensure_response( [
			'count'     => count( $result ),
			'variables' => $result,
		] );
	}

	/**
	 * Create a single variable in the Variable Manager.
	 * Type "colors" writes to et_divi.et_global_data.global_colors.
	 * Other types write to et_divi_global_variables.
	 */
	public static function create_variable( $request ) {
		$type  = sanitize_text_field( $request->get_param( 'type' ) );
		$label = sanitize_text_field( $request->get_param( 'label' ) );
		$value = $request->get_param( 'value' );
		if ( ! is_scalar( $value ) ) {
			return new WP_Error( 'invalid_value', 'value must be a scalar string.', [ 'status' => 400 ] );
		}
		$value = (string) $value;

		$valid_types = [ 'colors', 'numbers', 'strings', 'images', 'links', 'fonts' ];
		if ( ! in_array( $type, $valid_types, true ) ) {
			return new WP_Error( 'invalid_type', 'Type must be one of: ' . implode( ', ', $valid_types ), [ 'status' => 400 ] );
		}

		if ( 'colors' === $type ) {
			$id = sanitize_text_field( $request->get_param( 'id' ) ?: 'gcid-' . wp_generate_password( 8, false ) );
			if ( 0 !== strpos( $id, 'gcid-' ) ) {
				return new WP_Error( 'invalid_id', "Color variable ID must start with 'gcid-', got '$id'", [ 'status' => 400 ] );
			}

			$color = sanitize_hex_color( $value );
			if ( ! $color ) {
				return new WP_Error( 'invalid_color', "Invalid hex color value: '$value'", [ 'status' => 400 ] );
			}

			$raw         = et_get_option( 'et_global_data' );
			$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
			if ( ! is_array( $global_data ) ) {
				$global_data = [];
			}
			$colors = is_array( $global_data['global_colors'] ?? null ) ? $global_data['global_colors'] : [];

			$max_order = 0;
			if ( ! empty( $colors ) ) {
				$orders = array_column( $colors, 'order' );
				if ( ! empty( $orders ) ) {
					$max_order = max( array_map( 'intval', $orders ) );
				}
			}

			$colors[ $id ] = [
				'color'       => $color,
				'status'      => 'active',
				'label'       => $label,
				'order'       => (string) ( $max_order + 1 ),
				'lastUpdated' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			];

			$global_data['global_colors'] = $colors;
			et_update_option( 'et_global_data', $global_data );

			return rest_ensure_response( [
				'success' => true,
				'id'      => $id,
				'type'    => 'colors',
				'label'   => $label,
				'value'   => $color,
			] );
		}

		// Non-color types.
		$id = sanitize_text_field( $request->get_param( 'id' ) ?: 'gvid-' . wp_generate_password( 8, false ) );
		if ( 0 !== strpos( $id, 'gvid-' ) ) {
			return new WP_Error( 'invalid_id', "Non-color variable ID must start with 'gvid-', got '$id'", [ 'status' => 400 ] );
		}

		$vars = get_option( 'et_divi_global_variables', [] );
		if ( ! is_array( $vars ) ) {
			$vars = [];
		}
		if ( ! is_array( $vars[ $type ] ?? null ) ) {
			$vars[ $type ] = [];
		}

		// Type-specific sanitization.
		$sanitized_value = $value;
		if ( in_array( $type, [ 'images', 'links' ], true ) ) {
			$sanitized_value = esc_url_raw( $value );
		} else {
			$sanitized_value = sanitize_text_field( $value );
		}

		// Use max existing order to avoid collisions after deletions.
		$max_order = 0;
		if ( ! empty( $vars[ $type ] ) ) {
			$orders = array_column( $vars[ $type ], 'order' );
			if ( ! empty( $orders ) ) {
				$max_order = max( array_map( 'intval', $orders ) );
			}
		}

		$vars[ $type ][ $id ] = [
			'id'          => $id,
			'label'       => $label,
			'value'       => $sanitized_value,
			'order'       => $max_order + 1,
			'status'      => 'active',
			'lastUpdated' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			'type'        => $type,
		];

		update_option( 'et_divi_global_variables', $vars );

		return rest_ensure_response( [
			'success' => true,
			'id'      => $id,
			'type'    => $type,
			'label'   => $label,
			'value'   => $sanitized_value,
		] );
	}

	/**
	 * Delete a variable by ID. Auto-detects storage location from ID prefix.
	 */
	public static function delete_variable( $request ) {
		$id = sanitize_text_field( $request->get_param( 'id' ) );

		// Colors: gcid-* prefix.
		if ( 0 === strpos( $id, 'gcid-' ) ) {
			$raw         = et_get_option( 'et_global_data' );
			$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
			if ( ! is_array( $global_data ) ) {
				return new WP_Error( 'not_found', "Variable '$id' not found", [ 'status' => 404 ] );
			}
			$colors = is_array( $global_data['global_colors'] ?? null ) ? $global_data['global_colors'] : [];

			if ( ! isset( $colors[ $id ] ) ) {
				return new WP_Error( 'not_found', "Variable '$id' not found", [ 'status' => 404 ] );
			}

			unset( $colors[ $id ] );
			$global_data['global_colors'] = $colors;
			et_update_option( 'et_global_data', $global_data );

			return rest_ensure_response( [ 'success' => true, 'deleted' => $id ] );
		}

		// Non-color types: gvid-* prefix.
		$vars = get_option( 'et_divi_global_variables', [] );
		if ( ! is_array( $vars ) ) {
			return new WP_Error( 'not_found', "Variable '$id' not found", [ 'status' => 404 ] );
		}

		$var_types = [ 'numbers', 'strings', 'images', 'links', 'fonts' ];
		foreach ( $var_types as $type ) {
			if ( is_array( $vars[ $type ] ?? null ) && isset( $vars[ $type ][ $id ] ) ) {
				unset( $vars[ $type ][ $id ] );
				update_option( 'et_divi_global_variables', $vars );
				return rest_ensure_response( [ 'success' => true, 'deleted' => $id ] );
			}
		}

		return new WP_Error( 'not_found', "Variable '$id' not found", [ 'status' => 404 ] );
	}

	// ── Handshake ────────────────────────────────────────────────────

	/**
	 * Version handshake — verifies MCP server and WP plugin compatibility.
	 *
	 * Returns plugin version, API capabilities, and Divi status.
	 * Returns HTTP 426 (Upgrade Required) if the server version is too old.
	 */
	public static function handshake( $request ) {
		$server_version = sanitize_text_field( (string) $request->get_param( 'mcp_server_version' ) );

		// Check if the MCP server meets minimum required version.
		if ( version_compare( $server_version, self::MIN_SERVER_VERSION, '<' ) ) {
			return new WP_Error(
				'upgrade_required',
				sprintf(
					'MCP server version %s is below the minimum required %s. Please update the MCP server.',
					$server_version,
					self::MIN_SERVER_VERSION
				),
				[ 'status' => 426 ]
			);
		}

		$divi_active  = function_exists( 'et_get_option' );
		$divi_version = $divi_active && defined( 'ET_BUILDER_PRODUCT_VERSION' )
			? ET_BUILDER_PRODUCT_VERSION
			: null;

		return rest_ensure_response( [
			'compatible'     => true,
			'plugin_version' => self::VERSION,
			'min_server'     => self::MIN_SERVER_VERSION,
			'divi'           => [
				'active'  => $divi_active,
				'version' => $divi_version,
			],
			'capabilities'   => [
				'pages',
				'modules',
				'presets',
				'library',
				'theme_builder',
				'canvas',
				'variables',
			],
		] );
	}

	// ── Admin Settings Page ─────────────────────────────────────

	public static function register_admin_page() {
		add_menu_page(
			'DiviOps',
			'DiviOps',
			'manage_options',
			'diviops',
			[ __CLASS__, 'render_admin_page' ],
			'dashicons-rest-api',
			81
		);
	}

	public static function render_admin_page() {
		$divi_active   = function_exists( 'et_get_option' );
		$divi_version  = $divi_active && defined( 'ET_BUILDER_PRODUCT_VERSION' ) ? ET_BUILDER_PRODUCT_VERSION : null;
		$rest_url      = rest_url( self::REST_NAMESPACE );
		$rate_disabled = ( defined( 'DIVIOPS_RATE_LIMIT_DISABLED' ) && DIVIOPS_RATE_LIMIT_DISABLED )
			|| filter_var( getenv( 'DIVIOPS_RATE_LIMIT_DISABLED' ), FILTER_VALIDATE_BOOLEAN );
		$read_limit    = (int) ( defined( 'DIVIOPS_RATE_LIMIT_READ' ) ? DIVIOPS_RATE_LIMIT_READ : ( getenv( 'DIVIOPS_RATE_LIMIT_READ' ) ?: self::RATE_LIMIT_READ ) );
		$write_limit   = (int) ( defined( 'DIVIOPS_RATE_LIMIT_WRITE' ) ? DIVIOPS_RATE_LIMIT_WRITE : ( getenv( 'DIVIOPS_RATE_LIMIT_WRITE' ) ?: self::RATE_LIMIT_WRITE ) );

		$limits = apply_filters( 'diviops_rate_limits', [
			'read'  => $read_limit,
			'write' => $write_limit,
		] );
		if ( is_array( $limits ) && isset( $limits['read'], $limits['write'] ) ) {
			$read_limit  = (int) $limits['read'];
			$write_limit = (int) $limits['write'];
		}

		// Design Library status.
		$ddl_active  = class_exists( 'DiviOps_Design_Library' );
		$ddl_version = $ddl_active && defined( 'DiviOps_Design_Library::VERSION' ) ? DiviOps_Design_Library::VERSION : null;

		?>
		<div class="wrap">
			<h1>DiviOps</h1>
			<p>AI agent bridge for Divi 5 &mdash; connects Claude Code to your WordPress site.</p>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:20px;margin-top:20px;">

				<?php // ── Connection Status ── ?>
				<div class="card" style="padding:16px 20px;">
					<h2 style="margin-top:0;">Connection Status</h2>
					<table class="widefat striped" style="border:0;">
						<tbody>
							<tr>
								<td><strong>Plugin Version</strong></td>
								<td><?php echo esc_html( self::VERSION ); ?></td>
							</tr>
							<tr>
								<td><strong>Divi Theme</strong></td>
								<td>
									<?php if ( $divi_active ) : ?>
										<span style="color:#46b450;">&#10003;</span> Active
										<?php echo $divi_version ? '(v' . esc_html( $divi_version ) . ')' : ''; ?>
									<?php else : ?>
										<span style="color:#dc3232;">&#10007;</span> Not active &mdash; activate Divi to use MCP tools
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<td><strong>REST Namespace</strong></td>
								<td><code><?php echo esc_html( self::REST_NAMESPACE ); ?></code></td>
							</tr>
							<tr>
								<td><strong>REST URL</strong></td>
								<td><code style="word-break:break-all;"><?php echo esc_url( $rest_url ); ?></code></td>
							</tr>
						</tbody>
					</table>
				</div>

				<?php // ── Rate Limiting ── ?>
				<div class="card" style="padding:16px 20px;">
					<h2 style="margin-top:0;">Rate Limiting</h2>
					<table class="widefat striped" style="border:0;">
						<tbody>
							<tr>
								<td><strong>Status</strong></td>
								<td>
									<?php if ( $rate_disabled ) : ?>
										<span style="color:#f0b849;">&#9888;</span> Disabled
									<?php else : ?>
										<span style="color:#46b450;">&#10003;</span> Active
									<?php endif; ?>
								</td>
							</tr>
							<?php if ( ! $rate_disabled ) : ?>
							<tr>
								<td><strong>Read Limit</strong></td>
								<td><?php echo esc_html( $read_limit ); ?> requests/minute</td>
							</tr>
							<tr>
								<td><strong>Write Limit</strong></td>
								<td><?php echo esc_html( $write_limit ); ?> requests/minute</td>
							</tr>
							<?php endif; ?>
						</tbody>
					</table>
					<p class="description" style="margin-top:10px;">
						Configure via <code>DIVIOPS_RATE_LIMIT_READ</code> / <code>DIVIOPS_RATE_LIMIT_WRITE</code> constants or the <code>diviops_rate_limits</code> filter.
					</p>
				</div>

				<?php // ── Capabilities ── ?>
				<div class="card" style="padding:16px 20px;">
					<h2 style="margin-top:0;">Capabilities</h2>
					<?php
					$caps = [
						'Pages'         => $divi_active,
						'Modules'       => $divi_active,
						'Presets'       => $divi_active,
						'Library'       => $divi_active,
						'Theme Builder' => $divi_active,
						'Canvas'        => $divi_active,
						'Variables'     => $divi_active,
						'WP-CLI'        => defined( 'DIVIOPS_WP_CLI_PATH' ) || getenv( 'WP_PATH' ) || getenv( 'WP_CLI_CMD' ),
					];
					?>
					<ul style="margin:0;padding:0;list-style:none;">
						<?php foreach ( $caps as $name => $ok ) : ?>
						<li style="padding:4px 0;">
							<?php echo $ok ? '<span style="color:#46b450;">&#10003;</span>' : '<span style="color:#dc3232;">&#10007;</span>'; ?>
							<?php echo esc_html( $name ); ?>
						</li>
						<?php endforeach; ?>
					</ul>
				</div>

				<?php // ── Design Library ── ?>
				<div class="card" style="padding:16px 20px;">
					<h2 style="margin-top:0;">Design Library</h2>
					<?php if ( $ddl_active ) : ?>
						<p><span style="color:#46b450;">&#10003;</span> Active<?php echo $ddl_version ? ' (v' . esc_html( $ddl_version ) . ')' : ''; ?></p>
						<p class="description">CSS animations, glass effects, Three.js WebGL shaders.</p>
					<?php else : ?>
						<p><span style="color:#999;">&#8212;</span> Not installed</p>
						<p class="description">Optional plugin for CSS entrance animations (<code>ddl-fade-up</code>, <code>ddl-scale-in</code>) and Three.js WebGL shader backgrounds.</p>
					<?php endif; ?>
				</div>

			</div>

			<div style="margin-top:24px;">
				<h2>Getting Started</h2>
				<p>
					DiviOps works through Claude Code &mdash; it provides 43 MCP tools for reading and writing Divi 5 pages programmatically.
				</p>
				<ol>
					<li>Install the <strong>divi-5-builder</strong> skill: <code>claude plugin marketplace add oaris-dev/diviops</code> then <code>claude plugin install divi-5-builder@diviops</code></li>
					<li>Register the MCP server: <code>claude mcp add diviops-mysite -- env WP_URL=... WP_USER=... WP_APP_PASSWORD=... node /path/to/diviops-server/dist/index.js</code></li>
					<li>Test: ask Claude Code to <em>&ldquo;Use diviops_test_connection to verify the MCP is working&rdquo;</em></li>
				</ol>
				<p>
					<a href="https://github.com/oaris-dev/diviops" target="_blank" rel="noopener noreferrer" class="button button-secondary">Documentation &amp; Setup Guide</a>
				</p>
			</div>
		</div>
		<?php
	}
}

DiviOps_Agent::init();
