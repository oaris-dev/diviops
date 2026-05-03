<?php
/**
 * Plugin Name: DiviOps Agent
 * Plugin URI: https://github.com/oaris-dev/diviops
 * Description: REST API bridge for DiviOps — connects Claude Code to your Divi 5 site for AI-powered page building and design management.
 * Version: 1.0.0-beta.40
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
	const VERSION = '1.0.0-beta.40';

	/**
	 * Minimum MCP server version this plugin is compatible with.
	 */
	const MIN_SERVER_VERSION = '0.1.0';

	/**
	 * REST namespace for all endpoints.
	 */
	const REST_NAMESPACE      = 'diviops/v1';
	const REASSIGN_MAX_PAGES  = 1000;
	const VARIABLES_SCAN_MAX_POSTS = 2000;

	/**
	 * Post types that can contain Divi block markup — scanned for
	 * preset / variable references. Kept in one place so the ref-scanner
	 * and the delete_variable SQL fast-path stay in lockstep.
	 *
	 * Excludes:
	 * - et_theme_builder / et_template — these are template ASSIGNMENT records
	 *   (which layout runs where, conditions, duplication metadata), not the
	 *   block markup itself. Verified empty post_content on every record.
	 * - wp_block / wp_template / wp_template_part — Gutenberg reusable blocks
	 *   and FSE templates, not in use on Divi-rendered pages.
	 */
	const SCANNABLE_POST_TYPES = [
		'page',
		'post',
		'et_header_layout',
		'et_body_layout',
		'et_footer_layout',
		'et_pb_layout',
		'et_pb_canvas',
	];

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

		// Allow disabling via bootstrap-resolved constant.
		if ( DIVIOPS_RATE_LIMIT_DISABLED ) {
			return $result;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $result; // Unauthenticated — permission callbacks will reject.
		}

		// Determine if this is a write operation.
		$method   = $request->get_method();
		$is_write = in_array( $method, [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true );

		// Bootstrap-resolved constants are the single source of truth.
		$read_limit  = (int) DIVIOPS_RATE_LIMIT_READ;
		$write_limit = (int) DIVIOPS_RATE_LIMIT_WRITE;

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

		register_rest_route( self::REST_NAMESPACE, '/global-color-delete', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'delete_global_color' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'gcid'  => [ 'required' => true,  'type' => 'string' ],
				'force' => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
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
				'priority'  => [ 'required' => false, 'type' => 'integer' ],
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

		register_rest_route( self::REST_NAMESPACE, '/preset-create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'preset_create' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'module_name'       => [ 'required' => true,  'type' => 'string' ],
				'name'              => [ 'required' => true,  'type' => 'string' ],
				'attrs'             => [ 'required' => true,  'type' => 'object' ],
				'type'              => [ 'required' => false, 'type' => 'string', 'default' => 'module' ],
				'group_name'        => [ 'required' => false, 'type' => 'string' ],
				'group_id'          => [ 'required' => false, 'type' => 'string' ],
				'primary_attr_name' => [ 'required' => false, 'type' => 'string' ],
				'make_default'      => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'priority'          => [ 'required' => false, 'type' => 'integer' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/preset-reassign', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'preset_reassign' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'old_uuid'     => [ 'required' => true,  'type' => 'string' ],
				'new_uuid'     => [ 'required' => true,  'type' => 'string' ],
				'page_ids'     => [
					'required' => false,
					'type'     => 'array',
					'items'    => [ 'type' => 'integer' ],
					'validate_callback' => static function ( $value ) {
						if ( ! is_array( $value ) ) {
							return new WP_Error( 'rest_invalid_param', 'page_ids must be an array of positive integers', [ 'status' => 400 ] );
						}
						foreach ( $value as $v ) {
							if ( ! is_numeric( $v ) || (int) $v <= 0 || (float) $v !== (float) (int) $v ) {
								return new WP_Error( 'rest_invalid_param', 'page_ids must contain only positive integers', [ 'status' => 400 ] );
							}
						}
						return true;
					},
					'sanitize_callback' => static function ( $value ) {
						return array_map( 'absint', (array) $value );
					},
				],
				'mode'         => [ 'required' => false, 'type' => 'string', 'default' => 'dry-run', 'enum' => [ 'dry-run', 'apply' ] ],
				'strip_inline' => [ 'required' => false, 'type' => 'boolean', 'default' => true ],
				'scope'        => [ 'required' => false, 'type' => 'string', 'default' => 'both', 'enum' => [ 'module', 'group', 'both' ] ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/preset-scan-orphans', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'preset_scan_orphans' ],
			// Admin-only: response includes page IDs + titles correlated to preset refs — inventory-leak risk.
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/preset-set-default', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'preset_set_default' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'preset_id' => [ 'required' => true,  'type' => 'string' ],
				'unset'     => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
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

		// Module state: lock / unlock / clone. Targeting follows the same
		// label/match_text/auto_index pattern as update_module so callers reuse
		// the same mental model.
		register_rest_route( self::REST_NAMESPACE, '/page/(?P<id>\d+)/lock-module', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'lock_module' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'id'         => [ 'required' => true ],
				'label'      => [ 'type' => 'string' ],
				'match_text' => [ 'type' => 'string' ],
				'auto_index' => [ 'type' => 'string' ],
				'occurrence' => [
					'default'           => 1,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/page/(?P<id>\d+)/unlock-module', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'unlock_module' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'id'         => [ 'required' => true ],
				'label'      => [ 'type' => 'string' ],
				'match_text' => [ 'type' => 'string' ],
				'auto_index' => [ 'type' => 'string' ],
				'occurrence' => [
					'default'           => 1,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/page/(?P<id>\d+)/clone-module', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'clone_module' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'id'         => [ 'required' => true ],
				'label'      => [ 'type' => 'string' ],
				'match_text' => [ 'type' => 'string' ],
				'auto_index' => [ 'type' => 'string' ],
				'occurrence' => [
					'default'           => 1,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				],
				'position'   => [
					'default'     => 'after',
					'type'        => 'string',
					'enum'        => [ 'before', 'after' ],
					'description' => 'Place the clone "before" or "after" the source module within its parent.',
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
				'type'              => [ 'required' => true, 'type' => 'string' ],
				'id'                => [ 'required' => false, 'type' => 'string' ],
				'label'             => [ 'required' => true, 'type' => 'string' ],
				// Not required at the route layer — callback validates that
				// either value OR fluid params (min+max or targets) is present
				// and returns the richer 400 (fluid_value_conflict / etc).
				'value'             => [ 'required' => false, 'type' => 'string' ],
				'min'               => [ 'required' => false, 'type' => 'string' ],
				'max'               => [ 'required' => false, 'type' => 'string' ],
				'targets'           => [ 'required' => false, 'type' => 'object' ],
				'output_unit'       => [ 'required' => false, 'type' => 'string' ],
				'root_font_size_px' => [ 'required' => false, 'type' => 'number' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/variable/delete', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'delete_variable' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'id'    => [ 'required' => true, 'type' => 'string' ],
				'force' => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/variables-scan-orphans', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'variables_scan_orphans' ],
			// Admin-only: response correlates variable IDs with page titles — inventory-leak risk (matches /preset-scan-orphans).
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/variables-create-fluid-system', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'create_fluid_system' ],
			// Admin-only: bulk write to the variable registry.
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'profile'           => [ 'required' => false, 'type' => 'string', 'default' => 'divi-default' ],
				'custom_anchors'    => [ 'required' => false, 'type' => 'object' ],
				'typography'        => [ 'required' => false, 'type' => 'object' ],
				'spacing'           => [ 'required' => false, 'type' => 'object' ],
				'radius'            => [ 'required' => false, 'type' => 'object' ],
				'namespace'         => [ 'required' => false, 'type' => 'string', 'default' => 'oa' ],
				'output_unit'       => [ 'required' => false, 'type' => 'string' ],
				'root_font_size_px' => [ 'required' => false, 'type' => 'number' ],
				'dry_run'           => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'overwrite'         => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/variables-used-on-page/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'variables_used_on_page' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'id' => [ 'required' => true, 'type' => 'integer' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/flush-static-cache', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'flush_static_cache' ],
			// Admin-only: performs filesystem deletes under wp-content/et-cache/.
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'post_id' => [ 'required' => false, 'type' => 'integer' ],
				'all'     => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'after'   => [ 'required' => false, 'type' => 'integer' ],
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
	 * The 5 customizer-bound default global color IDs. These are managed
	 * via WP Customizer and writing to them through the registry creates
	 * an entry that Divi's customizer-merge path then hides from registry
	 * reads (see GlobalData::get_global_colors at GlobalData.php:341 — it
	 * removes customizer-bound colors from the returned set). A write to
	 * one of these via MCP would silently no-op from a UI perspective.
	 */
	private static function customizer_locked_color_ids(): array {
		return [
			'gcid-primary-color',
			'gcid-secondary-color',
			'gcid-heading-color',
			'gcid-body-color',
			'gcid-link-color',
		];
	}

	/**
	 * Validate a global-color ID and return the canonical form, or a
	 * WP_Error explaining why it was rejected.
	 *
	 * Auto-prefixes `gcid-` if missing (caller convenience). Then enforces
	 * Divi's downstream regex constraints:
	 *
	 * - GlobalData.php:760 extracts IDs via `/--gcid-([0-9a-z-]*)/` so
	 *   anything outside `[0-9a-z-]` after the prefix breaks CSS-variable
	 *   resolution silently (id stored, $variable() lookup fails).
	 * - Style.php:935 emits CSS variable names directly from the ID so a
	 *   long ID generates a long var name; cap at 80 chars (matches the
	 *   uuid4 default).
	 * - Customizer-bound defaults (see customizer_locked_color_ids) are
	 *   rejected to prevent the silent registry-vs-customizer mismatch
	 *   between Divi's $variable() resolver and the Theme Customizer.
	 *
	 * Empty input returns the canonical generated form `gcid-<uuid4>`.
	 */
	private static function validate_global_color_id( $raw, bool $allow_customizer_locked = false ) {
		$raw = sanitize_text_field( (string) $raw );
		if ( '' === $raw ) {
			return 'gcid-' . wp_generate_uuid4();
		}
		// Auto-prefix.
		$id = ( 0 === strpos( $raw, 'gcid-' ) ) ? $raw : 'gcid-' . $raw;

		// Reserved customizer-bound defaults.
		if ( ! $allow_customizer_locked && in_array( $id, self::customizer_locked_color_ids(), true ) ) {
			return new WP_Error(
				'reserved_id',
				sprintf( "'%s' is bound to the WP Customizer (Theme Options → General → Color Schemes). Writes to it via the registry are hidden from VB. Edit through Customizer instead.", $id ),
				[ 'status' => 403 ]
			);
		}

		// Charset + length: the suffix after `gcid-` must match
		// `[0-9a-z-]{1,80}` (Divi's extraction regex + sane upper bound).
		$suffix = substr( $id, 5 );
		if ( ! preg_match( '/^[0-9a-z-]{1,80}$/', $suffix ) ) {
			return new WP_Error(
				'invalid_id',
				sprintf( "Color ID '%s' contains characters outside the allowed [0-9a-z-] set, or exceeds 80 chars after the 'gcid-' prefix. Divi's CSS-variable resolution at GlobalData.php:760 strips non-matching IDs silently.", $id ),
				[ 'status' => 400 ]
			);
		}
		return $id;
	}

	/**
	 * Sanitize a CSS color value for the global-colors registry.
	 *
	 * Accepts hex (#rgb/#rrggbb/#rrggbbaa) and CSS rgb()/rgba()/hsl()/hsla()
	 * functional notation — Divi's stock palette uses both (verified via live
	 * read: gcid-4907c8d4 stores 'rgba(0,0,0,0.3)'). `sanitize_hex_color()` is
	 * too restrictive on its own; we accept functional notation after a
	 * shape check.
	 *
	 * Returns the sanitized color string, or `null` if the input is empty or
	 * doesn't match either accepted shape. Callers should treat null as
	 * invalid input and surface a 400 to the user instead of silently writing
	 * a placeholder color into the palette.
	 */
	private static function sanitize_color( $raw ): ?string {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}
		// Hex fast-path.
		$hex = sanitize_hex_color( $raw );
		if ( null !== $hex && '' !== $hex ) {
			return $hex;
		}
		// Functional CSS color (rgb/rgba/hsl/hsla) — accept the shape, then
		// generic sanitize for any control characters.
		if ( preg_match( '/^(rgba?|hsla?)\s*\(\s*[0-9.\-,%\s\/]+\s*\)$/i', $raw ) ) {
			return sanitize_text_field( $raw );
		}
		// Reject anything else.
		return null;
	}

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

		// Add/update new colors. Shape mirrors Divi's canonical global-color
		// payload (verified via GlobalDataController:46-69 + live-read of stock
		// Divi-written colors): {color, folder, label, lastUpdated, status,
		// usedInPosts}. Divi's `sanitize_global_colors_data` accepts arbitrary
		// fields generically (no schema enforcement beyond gcid-* id + non-empty
		// color), so extra/missing fields don't break — but matching the
		// canonical shape keeps our writes indistinguishable from VB-written
		// ones and avoids surprising future Divi versions that may tighten the
		// schema. `usedInPosts` defaults to empty (Divi populates it on save);
		// `folder` defaults to '' (no folder).
		//
		// Merge semantics: when an `id` is supplied for an existing color,
		// PRESERVE existing fields not explicitly overwritten by the input.
		// This makes single-field updates (e.g. only `color`) safe — the
		// caller doesn't have to re-supply label/folder/status to keep them.
		// New colors (no id, or unknown id) seed defaults from scratch.
		$added = 0;
		foreach ( $new_colors as $idx => $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$id = self::validate_global_color_id( $c['id'] ?? '' );
			if ( is_wp_error( $id ) ) {
				// Annotate with the colors[] index so batch callers can
				// identify which entry failed validation.
				$err = $id;
				$err_data = (array) $err->get_error_data();
				$err_data['colors_index'] = $idx;
				return new WP_Error( $err->get_error_code(), sprintf( 'colors[%d]: %s', $idx, $err->get_error_message() ), $err_data );
			}
			$existing = isset( $color_map[ $id ] ) && is_array( $color_map[ $id ] )
				? $color_map[ $id ]
				: [];

			// Color: validate before write. New entries (no existing) MUST have
			// a valid color; updates (existing entry) can omit color to keep it.
			if ( isset( $c['color'] ) ) {
				$color = self::sanitize_color( $c['color'] );
				if ( null === $color ) {
					return new WP_Error(
						'invalid_color',
						sprintf( "colors[%d].color is not a valid CSS color (hex or rgba/hsla notation expected)", $idx ),
						[ 'status' => 400, 'received' => $c['color'] ]
					);
				}
			} elseif ( ! empty( $existing ) ) {
				$color = $existing['color'] ?? '#000000';
			} else {
				return new WP_Error(
					'missing_color',
					sprintf( "colors[%d] is missing required `color` field for new entry", $idx ),
					[ 'status' => 400 ]
				);
			}

			$label = array_key_exists( 'label', $c )
				? sanitize_text_field( $c['label'] )
				: ( $existing['label'] ?? '' );

			$folder = array_key_exists( 'folder', $c )
				? sanitize_text_field( $c['folder'] )
				: ( $existing['folder'] ?? '' );

			$status_raw = $c['status'] ?? ( $existing['status'] ?? 'active' );
			$status     = in_array( $status_raw, [ 'active', 'archived' ], true ) ? $status_raw : 'active';

			// Preserve usedInPosts on update — Divi tracks where each color is
			// referenced; our writer should never clobber that index. Read from
			// existing entry if present, else seed empty.
			$used_in_posts = isset( $existing['usedInPosts'] ) && is_array( $existing['usedInPosts'] )
				? $existing['usedInPosts']
				: [];

			$color_map[ $id ] = [
				'color'       => $color,
				'folder'      => $folder,
				'label'       => $label,
				'lastUpdated' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
				'status'      => $status,
				'usedInPosts' => $used_in_posts,
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
	 * Delete a global color from the registry by gcid.
	 *
	 * Mirrors the safety pattern of `delete_variable`: refuses to delete the
	 * 5 customizer-bound default colors (`gcid-primary-color`,
	 * `gcid-secondary-color`, `gcid-heading-color`, `gcid-body-color`,
	 * `gcid-link-color`) since those are managed via WP Customizer and
	 * removing them from the registry would break theme inheritance even if
	 * the customizer values stay set.
	 *
	 * Soft-warns when a color is referenced by posts (per its `usedInPosts`
	 * field) — caller must pass `force=true` to delete anyway. Note the
	 * `usedInPosts` index is maintained by Divi on save, so a color used by
	 * a page that was never opened in VB after the color was assigned via MCP
	 * may not be tracked there. The check is best-effort, not authoritative.
	 */
	public static function delete_global_color( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Requires admin capability', [ 'status' => 403 ] );
		}

		$gcid  = sanitize_text_field( $request->get_param( 'gcid' ) );
		$force = (bool) $request->get_param( 'force' );

		if ( '' === $gcid || 0 !== strpos( $gcid, 'gcid-' ) ) {
			return new WP_Error( 'invalid_gcid', 'gcid must be a non-empty string starting with "gcid-"', [ 'status' => 400 ] );
		}

		// Customizer-bound defaults — not deletable via MCP regardless of
		// `force`. Removing them from the registry breaks Divi's theme
		// inheritance because the customizer keeps the value but the
		// registry lookup falls through.
		if ( in_array( $gcid, self::customizer_locked_color_ids(), true ) ) {
			return new WP_Error(
				'customizer_locked',
				sprintf( "'%s' is bound to the WP Customizer (Theme Options → General → Color Schemes) and cannot be deleted via MCP. Edit it through Customizer instead.", $gcid ),
				[ 'status' => 403 ]
			);
		}

		$raw         = et_get_option( 'et_global_data' );
		$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
		if ( ! is_array( $global_data ) ) {
			$global_data = [];
		}
		$colors = isset( $global_data['global_colors'] ) && is_array( $global_data['global_colors'] )
			? $global_data['global_colors']
			: [];

		if ( ! isset( $colors[ $gcid ] ) ) {
			return new WP_Error( 'not_found', "Color '{$gcid}' not found in registry", [ 'status' => 404 ] );
		}

		$color = $colors[ $gcid ];
		$used  = isset( $color['usedInPosts'] ) && is_array( $color['usedInPosts'] ) ? $color['usedInPosts'] : [];

		// Live-reference soft-block (best-effort — see method docblock).
		if ( ! $force && ! empty( $used ) ) {
			return new WP_Error(
				'has_references',
				sprintf(
					"Color '%s' has %d live reference(s) tracked by Divi. Pass force=true to delete anyway; orphan refs will render as invalid CSS until the pages are re-saved through VB.",
					$gcid,
					count( $used )
				),
				[ 'status' => 409, 'used_in_posts' => $used ]
			);
		}

		unset( $colors[ $gcid ] );
		$global_data['global_colors'] = $colors;
		et_update_option( 'et_global_data', $global_data );

		return rest_ensure_response( [
			'success' => true,
			'deleted' => [
				'gcid'          => $gcid,
				'color'         => $color['color'] ?? '',
				'label'         => $color['label'] ?? '',
				'used_in_posts' => $used,
			],
			'message' => "Color '{$gcid}' deleted.",
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
				} else {
					// Custom border/bg/font styling on button requires enable:"on" to render fully.
					$button_deco = self::get_nested_array_value( $attrs, [ 'button', 'decoration' ], [] );
					$button_deco = is_array( $button_deco ) ? $button_deco : [];
					$has_custom_styling = isset( $button_deco['border'] ) || isset( $button_deco['background'] )
						|| isset( $button_deco['font'] ) || isset( $button_deco['boxShadow'] );
					if ( $has_custom_styling ) {
						$warnings[] = [
							'block'   => $name,
							'index'   => $index,
							'code'    => 'button_missing_enable',
							'message' => 'Button has custom border/background/font/boxShadow but button.decoration.button.desktop.value.enable is not "on" — custom styling may not render fully',
							'path'    => 'button.decoration.button.desktop.value.enable',
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

				// Content on wrong bucket (content.innerContent instead of button.innerContent).
				$wrong_bucket_content = self::get_nested_array_value( $attrs, [ 'content', 'innerContent', 'desktop', 'value' ] );
				if ( null !== $wrong_bucket_content && null === $btn_content ) {
					$errors[] = [
						'block'   => $name,
						'index'   => $index,
						'code'    => 'button_content_wrong_bucket',
						'message' => 'Button content is on content.innerContent.* but Button renders from button.innerContent.*. Without the correct bucket the button shows the default "Click Me" label and empty href.',
						'path'    => 'content.innerContent.desktop.value → button.innerContent.desktop.value',
					];
				}
			}

			// ── Heading checks (warnings) ───────────────────────────

			if ( 'divi/heading' === $name ) {
				$heading_level = self::get_nested_array_value( $attrs, [ 'title', 'decoration', 'font', 'font', 'desktop', 'value', 'headingLevel' ] );
				if ( null === $heading_level ) {
					$warnings[] = [
						'block'   => $name,
						'index'   => $index,
						'code'    => 'heading_missing_level',
						'message' => 'Heading has no headingLevel set — renders as h2 by default. Explicitly set "h1"–"h6" to match intent.',
						'path'    => 'title.decoration.font.font.desktop.value.headingLevel',
					];
				}
			}

			// ── Blurb checks (errors) ───────────────────────────────

			if ( 'divi/blurb' === $name ) {
				// Title must be a {text} object, not a plain string.
				$blurb_title = self::get_nested_array_value( $attrs, [ 'title', 'innerContent', 'desktop', 'value' ] );
				if ( null !== $blurb_title && is_string( $blurb_title ) ) {
					$errors[] = [
						'block'   => $name,
						'index'   => $index,
						'code'    => 'blurb_title_string',
						'message' => 'Blurb title.innerContent.desktop.value must be an object {"text": "..."}, not a plain string. Plain strings render as an empty title.',
						'path'    => 'title.innerContent.desktop.value',
					];
				}

				// Icon requires useIcon:"on" for the icon to render.
				$blurb_image_icon = self::get_nested_array_value( $attrs, [ 'imageIcon', 'innerContent', 'desktop', 'value' ], [] );
				$blurb_image_icon = is_array( $blurb_image_icon ) ? $blurb_image_icon : [];
				$has_icon_field   = isset( $blurb_image_icon['icon'] );
				$use_icon_flag    = $blurb_image_icon['useIcon'] ?? null;
				if ( $has_icon_field && 'on' !== $use_icon_flag ) {
					$errors[] = [
						'block'   => $name,
						'index'   => $index,
						'code'    => 'blurb_icon_missing_use_icon',
						'message' => 'Blurb has imageIcon.innerContent.desktop.value.icon but useIcon is not "on" — icon will not render. Icon mode requires useIcon:"on".',
						'path'    => 'imageIcon.innerContent.desktop.value.useIcon',
					];
				}
			}

			// ── ContactField checks (error) ─────────────────────────

			if ( 'divi/contact-field' === $name ) {
				// Every value under fieldItem.innerContent.<breakpoint>.<state> must be a STRING
				// (the label text). Writing it as an object/array (e.g. bundling fieldId,
				// fieldType, fieldTitle, required under one `value`) crashes Divi's render at
				// MultiViewUtils::populate_data_content with UnexpectedValueException —
				// "Expected a string value, but a array value was given". The walker at
				// MultiViewUtils.php:1220-1253 iterates every breakpoint + state in the
				// normalized innerContent bag, so a valid desktop.value paired with an object
				// at tablet.value or desktop.hover still aborts the entire post render (not
				// just the field).
				//
				// Field config (id, type, required, allowedSymbols, minLength, maxLength,
				// radioOptions, checkboxOptions, selectOptions, booleanCheckboxOptions) lives
				// at fieldItem.advanced.<key>.desktop.value individually, not bundled under
				// innerContent.
				$inner_content = self::get_nested_array_value( $attrs, [ 'fieldItem', 'innerContent' ] );
				if ( is_array( $inner_content ) ) {
					foreach ( $inner_content as $breakpoint => $state_values ) {
						if ( ! is_array( $state_values ) ) {
							continue;
						}
						foreach ( $state_values as $state => $value ) {
							if ( null !== $value && ! is_string( $value ) ) {
								$errors[] = [
									'block'   => $name,
									'index'   => $index,
									'code'    => 'field_item_content_object',
									'message' => sprintf(
										'ContactField fieldItem.innerContent.%s.%s must be a string (the label text). A non-string value at any breakpoint or state crashes Divi render at MultiViewUtils::populate_data_content. Put field config individually at fieldItem.advanced.{id,type,required,allowedSymbols,minLength,maxLength,radioOptions,checkboxOptions,selectOptions,booleanCheckboxOptions}.desktop.value.',
										(string) $breakpoint,
										(string) $state
									),
									'path'    => sprintf( 'fieldItem.innerContent.%s.%s', (string) $breakpoint, (string) $state ),
								];
							}
						}
					}
				}
			}

			// ── bodyFont double-nested path (error, any module) ─────
			// Canonical: content.decoration.bodyFont.body.font.desktop.value.*
			// Wrong:     content.decoration.bodyFont.bodyFont.desktop.value.* — silently ignored by renderer.
			if ( $is_divi_block ) {
				$body_font_wrong = self::get_nested_array_value( $attrs, [ 'content', 'decoration', 'bodyFont', 'bodyFont' ] );
				if ( null !== $body_font_wrong ) {
					$errors[] = [
						'block'   => $name,
						'index'   => $index,
						'code'    => 'body_font_double_nested',
						'message' => 'content.decoration.bodyFont.bodyFont.* is a non-canonical double-nested path with no renderer consumer. Use content.decoration.bodyFont.body.font.* — values under bodyFont.bodyFont are silently ignored, so colors and fonts fall through to defaults.',
						'path'    => 'content.decoration.bodyFont.bodyFont → content.decoration.bodyFont.body.font',
					];
				}
			}

			// ── flexType on non-column modules (warning) ────────────
			// flexType is a column-layout attr (24-unit grid) — generates et_pb_X_Y classes only on divi/column inside divi/row.
			// On other modules (blurb, group, text, etc.) the attr is silently dropped — no size is applied.
			if ( $is_divi_block && 'divi/column' !== $name && 'divi/column-inner' !== $name ) {
				$flex_type = self::get_nested_array_value( $attrs, [ 'module', 'decoration', 'layout', 'desktop', 'value', 'flexType' ] );
				if ( null !== $flex_type ) {
					$warnings[] = [
						'block'   => $name,
						'index'   => $index,
						'code'    => 'flextype_on_non_column',
						'message' => 'flexType on module.decoration.layout is a column-layout grid attribute and only works on divi/column inside divi/row. On other modules it is silently ignored — the block ends up with no width constraint. For flex children inside a group use module.decoration.sizing.desktop.value.width instead.',
						'path'    => 'module.decoration.layout.desktop.value.flexType → module.decoration.sizing.desktop.value.width',
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
	 * Collect preset UUIDs referenced in page/post block markup.
	 *
	 * Uses `parse_blocks()` + recursion into `innerBlocks` so the scan is
	 * structurally scoped: we only pick up UUIDs from `attrs.modulePreset`
	 * and `attrs.groupPreset.<slot>.presetId`. An earlier regex approach
	 * false-matched unrelated `"presetId"` keys that Divi uses elsewhere in
	 * block attrs (e.g. `module.decoration.interactions[].presetId`).
	 *
	 * `presetId` in `groupPreset` slots is accepted as both an array and a
	 * bare string — Divi accepts both via the stacking convention, and older
	 * or hand-edited blocks may serialize as a string.
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
			$content = $p->post_content;

			// Cheap string pre-check avoids parse_blocks() (O(content length)
			// tokenizer) on posts that can't possibly contain preset refs —
			// the audit is an admin-only op but preset_cleanup runs here too,
			// and large sites have thousands of non-Divi posts.
			if ( false === strpos( $content, '"modulePreset"' ) && false === strpos( $content, '"groupPreset"' ) ) {
				continue;
			}

			$blocks     = parse_blocks( $content );
			$page_uuids = [];
			$ref_count  = 0;
			self::walk_blocks_for_preset_refs( $blocks, $all_uuids, $page_uuids, $ref_count );

			if ( ! empty( $page_uuids ) ) {
				$per_page[ $p->ID ] = [
					'title'        => $p->post_title,
					'total_refs'   => $ref_count,
					'custom_uuids' => array_values( array_unique( $page_uuids ) ),
				];
			}
		}

		return [ 'all_uuids' => $all_uuids, 'per_page' => $per_page ];
	}

	/**
	 * Recursively walk a parsed-blocks tree collecting modulePreset +
	 * groupPreset.<slot>.presetId UUID references. Updates counters by ref.
	 *
	 * Empty strings and `'default'` sentinels are skipped so bogus entries
	 * (e.g. unset interaction presetId that slipped through in some other
	 * scope) can never inflate ref counts. `$ref_count` is only incremented
	 * when a container actually yielded at least one valid UUID, so
	 * `per_page[...]['total_refs']` stays consistent with `custom_uuids`.
	 */
	private static function walk_blocks_for_preset_refs( $blocks, &$all_uuids, &$page_uuids, &$ref_count ) {
		foreach ( $blocks as $block ) {
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];

			if ( isset( $attrs['modulePreset'] ) ) {
				// Accept both the canonical array form and the scalar string
				// form — the latter can appear in hand-edited or legacy block
				// markup, and matches the defensive pattern we use for
				// groupPreset.<slot>.presetId below.
				$uuids = is_array( $attrs['modulePreset'] )
					? $attrs['modulePreset']
					: [ $attrs['modulePreset'] ];
				$found = false;
				foreach ( $uuids as $uuid ) {
					if ( is_string( $uuid ) && '' !== $uuid && 'default' !== $uuid ) {
						$all_uuids[ $uuid ] = ( $all_uuids[ $uuid ] ?? 0 ) + 1;
						$page_uuids[]       = $uuid;
						$found              = true;
					}
				}
				if ( $found ) {
					$ref_count++;
				}
			}

			if ( isset( $attrs['groupPreset'] ) && is_array( $attrs['groupPreset'] ) ) {
				foreach ( $attrs['groupPreset'] as $slot ) {
					if ( ! is_array( $slot ) || ! isset( $slot['presetId'] ) ) {
						continue;
					}
					$ids   = is_array( $slot['presetId'] ) ? $slot['presetId'] : [ $slot['presetId'] ];
					$found = false;
					foreach ( $ids as $uuid ) {
						if ( is_string( $uuid ) && '' !== $uuid && 'default' !== $uuid ) {
							$all_uuids[ $uuid ] = ( $all_uuids[ $uuid ] ?? 0 ) + 1;
							$page_uuids[]       = $uuid;
							$found              = true;
						}
					}
					if ( $found ) {
						$ref_count++;
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk_blocks_for_preset_refs( $block['innerBlocks'], $all_uuids, $page_uuids, $ref_count );
			}
		}
	}

	/**
	 * Collect group-preset UUIDs referenced via the in-registry chain.
	 *
	 * Divi 5.3.0+ stores chain refs in two distinct shapes depending on the bucket:
	 * - Module-bucket presets: TOP-LEVEL `preset.groupPresets.<slot>.presetId` (plural).
	 *   Matches the REST schema at `GlobalPresetController.php:309` (declared as sibling of
	 *   `attrs`/`renderAttrs`/`styleAttrs`) and the reader path in `GlobalPreset.php:1486, 2274`.
	 *   The VB bundle's `generateNewPreset` assigns `m.groupPresets = i` at the preset root.
	 * - Group-bucket presets: NESTED `preset.attrs.groupPreset.<slot>.presetId` (singular).
	 *   Matches the reader at `GlobalPreset.php:1510, 2394` and the VB bundle's
	 *   `extractGroupPresetsFromAttrs` which reads `e?.groupPreset` off the attrs bag.
	 *
	 * Without walking both shapes, every chain-only group preset (font, border, box-shadow,
	 * spacing, button, etc.) reports `ref_count: 0` and gets flagged as orphaned by audit +
	 * cleanup workflows — even though deleting them silently breaks the module presets that
	 * pull them in. See issues #302 (5.2.1 version of this, pre-singular split) and #368
	 * (5.3.0+ dual-shape reconfirmation).
	 *
	 * `presetId` in either shape is sometimes a single string and sometimes an array (Divi
	 * accepts both via the stacking convention) — handle both.
	 */
	private static function collect_group_chain_refs( $d5 ) {
		$counts        = [];
		// Build `referenced_by` with the referencing UUID as KEY (not value)
		// so deduplication is O(1) isset() rather than O(N) in_array() inside
		// the nested walker. Flatten to indexed arrays at the end so the
		// returned shape matches consumer expectations.
		$referenced_by = [];
		foreach ( [ 'module', 'group' ] as $type ) {
			if ( ! isset( $d5[ $type ] ) ) {
				continue;
			}
			foreach ( (array) $d5[ $type ] as $info ) {
				$info  = (array) $info;
				$items = isset( $info['items'] ) ? (array) $info['items'] : [];
				foreach ( $items as $referencing_uuid => $preset ) {
					$slots = self::_extract_chain_slot_map( $preset, $type );
					foreach ( $slots as $slot ) {
						$slot = (array) $slot;
						if ( ! isset( $slot['presetId'] ) ) {
							continue;
						}
						$ids = is_array( $slot['presetId'] ) ? $slot['presetId'] : [ $slot['presetId'] ];
						foreach ( $ids as $gid ) {
							if ( ! is_string( $gid ) || '' === $gid || 'default' === $gid ) {
								continue;
							}
							$counts[ $gid ] = ( $counts[ $gid ] ?? 0 ) + 1;
							$referenced_by[ $gid ][ $referencing_uuid ] = true;
						}
					}
				}
			}
		}
		foreach ( $referenced_by as $gid => $set ) {
			$referenced_by[ $gid ] = array_keys( $set );
		}
		return [ 'counts' => $counts, 'referenced_by' => $referenced_by ];
	}

	/**
	 * Read a preset item's chain-ref slot map at the bucket's canonical location.
	 *
	 * Returned shape is the `{ <slot>: { presetId: <scalar|array>, groupName: <string> } }` map.
	 * Returns `[]` when no chain refs are present.
	 *
	 * Defensively casts each nested level from array-or-object — the D5 option can round-trip
	 * through JSON or a custom importer and land with stdClass at any depth.
	 */
	private static function _extract_chain_slot_map( $preset, string $bucket ): array {
		if ( ! is_array( $preset ) && ! is_object( $preset ) ) {
			return [];
		}
		$preset = (array) $preset;
		if ( 'module' === $bucket ) {
			if ( ! isset( $preset['groupPresets'] ) ) {
				return [];
			}
			if ( ! is_array( $preset['groupPresets'] ) && ! is_object( $preset['groupPresets'] ) ) {
				return [];
			}
			return (array) $preset['groupPresets'];
		}
		if ( 'group' === $bucket ) {
			$attrs = isset( $preset['attrs'] ) ? (array) $preset['attrs'] : [];
			if ( ! isset( $attrs['groupPreset'] ) ) {
				return [];
			}
			if ( ! is_array( $attrs['groupPreset'] ) && ! is_object( $attrs['groupPreset'] ) ) {
				return [];
			}
			return (array) $attrs['groupPreset'];
		}
		return [];
	}

	/**
	 * Write a preset item's chain-ref slot map back to its canonical location.
	 *
	 * Module-bucket only — the chain ref lives at top-level `groupPresets` and is not mirrored
	 * anywhere else. The group-bucket write is deliberately not handled here because the
	 * rewriter needs per-bag surgical control across attrs / styleAttrs / renderAttrs to
	 * preserve the dual-pass CSS lockstep (`preset_update` mirrors attrs → all three bags).
	 * See `_rewrite_registry_group_chains` for the group-bucket write path.
	 */
	private static function _write_chain_slot_map( array $preset, string $bucket, array $slot_map ): array {
		if ( 'module' === $bucket ) {
			$preset['groupPresets'] = $slot_map;
		}
		return $preset;
	}

	/**
	 * Recursively walk any PHP value collecting `gvid-` and `gcid-` references
	 * from $variable(...)$ tokens. Token shape (after parse_blocks decodes block attrs):
	 * `$variable({"type":"...","value":{"name":"gvid-XXXX","settings":{}}})$`
	 *
	 * Pre-check on the `$variable(` substring avoids running preg_match_all on
	 * every string leaf of every attr tree — most leaves are short values like
	 * px/color/url that can't possibly carry a variable token.
	 */
	private static function walk_value_for_variable_refs( $value, &$all_ids, &$local_ids ) {
		if ( is_string( $value ) ) {
			if ( false === strpos( $value, '$variable(' ) ) {
				return;
			}
			if ( preg_match_all( '/"name"\s*:\s*"(g[vc]id-[A-Za-z0-9_-]+)"/', $value, $m ) ) {
				foreach ( $m[1] as $id ) {
					$all_ids[ $id ] = ( $all_ids[ $id ] ?? 0 ) + 1;
					$local_ids[]    = $id;
				}
			}
			return;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $v ) {
				self::walk_value_for_variable_refs( $v, $all_ids, $local_ids );
			}
		}
	}

	/**
	 * Recursively walk a parsed-blocks tree, scanning each block's attrs for
	 * gvid-/gcid- references via walk_value_for_variable_refs.
	 */
	private static function walk_blocks_for_variable_refs( $blocks, &$all_ids, &$local_ids ) {
		foreach ( $blocks as $block ) {
			if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				self::walk_value_for_variable_refs( $block['attrs'], $all_ids, $local_ids );
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk_blocks_for_variable_refs( $block['innerBlocks'], $all_ids, $local_ids );
			}
		}
	}

	/**
	 * Collect every variable reference across all content surfaces.
	 *
	 * Scanned surfaces:
	 * - Pages + posts (post_content blocks)
	 * - Theme Builder layouts — header / body / footer (each stored as a
	 *   separate post type: et_header_layout / et_body_layout / et_footer_layout).
	 *   The et_theme_builder / et_template records that link these together
	 *   hold only assignment metadata (which layout runs where), not the
	 *   block markup itself — they're intentionally excluded from scanning
	 * - Divi Library items (et_pb_layout) — saved module/row/section markup
	 * - Canvas pages (et_pb_canvas)
	 * - Preset registry (et_divi_builder_global_presets_d5) — a preset's attrs /
	 *   styleAttrs / renderAttrs / groupPresets chain may all embed $variable()$
	 *   tokens when the preset was saved against a variable-bound control
	 *
	 * Capped at VARIABLES_SCAN_MAX_POSTS to avoid OOM / timeout on large
	 * sites. When the cap is hit, `scan_truncated` flags the response so
	 * callers know the orphan list may be incomplete.
	 *
	 * Returns:
	 *   all_ids         — { id => ref_count } aggregated across all surfaces
	 *   locations       — { id => [ { type, ... } ] } per-reference location records
	 *   scan_truncated  — true if the post cap was hit
	 *   scanned_posts   — number of posts actually scanned
	 */
	private static function collect_variable_refs() {
		$all_ids        = [];
		$locations      = [];
		$scan_truncated = false;

		// Pages + TB layouts + library + canvas. Fetch one sentinel row
		// past the cap so we can distinguish "site has exactly N posts" (no
		// truncation) from "site has more than N" (real truncation) without
		// paying for a SELECT FOUND_ROWS() pass. `no_found_rows` skips that
		// pass. Discard the sentinel before scanning so the scan scope stays
		// honest.
		$posts = get_posts( [
			'post_type'      => self::SCANNABLE_POST_TYPES,
			'post_status'    => [ 'publish', 'draft', 'private' ],
			'posts_per_page' => self::VARIABLES_SCAN_MAX_POSTS + 1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		] );

		if ( count( $posts ) > self::VARIABLES_SCAN_MAX_POSTS ) {
			$scan_truncated = true;
			$posts          = array_slice( $posts, 0, self::VARIABLES_SCAN_MAX_POSTS );
		}

		foreach ( $posts as $p ) {
			$content = $p->post_content;
			if ( false === strpos( $content, '$variable(' ) ) {
				continue;
			}
			$blocks    = parse_blocks( $content );
			$local_ids = [];
			self::walk_blocks_for_variable_refs( $blocks, $all_ids, $local_ids );

			foreach ( array_unique( $local_ids ) as $id ) {
				$locations[ $id ][] = [
					'type'    => $p->post_type,
					'post_id' => $p->ID,
					'title'   => $p->post_title,
				];
			}
		}

		// Preset registry.
		$d5 = self::get_d5_presets();
		foreach ( [ 'module', 'group' ] as $bucket ) {
			if ( ! isset( $d5[ $bucket ] ) ) {
				continue;
			}
			foreach ( (array) $d5[ $bucket ] as $mod => $info ) {
				$info  = (array) $info;
				$items = isset( $info['items'] ) ? (array) $info['items'] : [];
				foreach ( $items as $uuid => $preset ) {
					$preset    = (array) $preset;
					$local_ids = [];
					self::walk_value_for_variable_refs( $preset, $all_ids, $local_ids );
					foreach ( array_unique( $local_ids ) as $id ) {
						$locations[ $id ][] = [
							'type'        => 'preset',
							'bucket'      => $bucket,
							'module'      => $mod,
							'preset_uuid' => $uuid,
							'preset_name' => $preset['name'] ?? '',
						];
					}
				}
			}
		}

		return [
			'all_ids'        => $all_ids,
			'locations'      => $locations,
			'scan_truncated' => $scan_truncated,
			'scanned_posts'  => count( $posts ),
		];
	}

	/**
	 * Cheap existence check — "does this variable id appear anywhere?".
	 * No parse_blocks; a single SQL LIKE on `post_content` (scoped to the
	 * scannable post types + post_status and limited to 1 row — note
	 * `post_content` is not indexed in the stock WordPress schema, so the
	 * query still scans the matching rows, but the scope + LIMIT keep it
	 * cheap), plus a substring check on the preset registry option.
	 *
	 * Used by delete_variable to short-circuit the happy path: if nothing
	 * anywhere references the id, skip the expensive collect_variable_refs()
	 * call. False-positive tolerant — `$id` is distinctive (`g[vc]id-...`) so
	 * a literal occurrence in page content or the preset registry almost
	 * always corresponds to a real ref; on a hit we fall through to the
	 * full scan to produce an accurate 409 location list anyway.
	 */
	private static function variable_id_appears_anywhere( $id ) {
		global $wpdb;
		if ( '' === $id ) {
			return false;
		}

		// Needle is just the bare id — Divi stores tokens with unicode-escaped
		// quotes (`\u0022name\u0022:\u0022gvid-...\u0022`), and the preset
		// registry is serialized PHP (raw quotes), so any quoted-wrapper
		// pattern would mismatch one of the two surfaces. The `g[vc]id-`
		// prefix is distinctive enough that a literal occurrence of the id
		// in content/options almost always corresponds to a real ref;
		// positive hits fall through to the full parse_blocks scan which
		// rigorously confirms + locates. False-positive tolerant by design.
		$placeholders = implode( ',', array_fill( 0, count( self::SCANNABLE_POST_TYPES ), '%s' ) );
		$needle       = '%' . $wpdb->esc_like( $id ) . '%';

		// Content scan.
		$sql = $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->posts}
				WHERE post_status IN ('publish','draft','private')
					AND post_type IN ($placeholders)
					AND post_content LIKE %s
				LIMIT 1",
			array_merge( self::SCANNABLE_POST_TYPES, [ $needle ] )
		);
		if ( (bool) $wpdb->get_var( $sql ) ) {
			return true;
		}

		// Preset registry scan. `get_option` returns the already-unserialized
		// value when the option was stored via `update_option` (typical
		// Divi path → stored as array/object), so a naive is_string guard
		// would miss the array case and let preset-only references slip
		// past the fast-path — silently orphaning the preset on delete.
		//
		// Rather than materializing the whole structure via wp_json_encode
		// on every call (allocation + encoding pressure that scales with
		// registry size), walk the in-memory tree and strpos only string
		// leaves, returning on first hit. Early-exit keeps the fast-path
		// genuinely fast even on large preset registries.
		$raw = get_option( 'et_divi_builder_global_presets_d5', '' );
		if ( self::value_contains_substring( $raw, $id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Recursive early-exit substring check — walks any PHP value (string,
	 * array, object) and returns true at the first string leaf containing
	 * `$needle`. Used by `variable_id_appears_anywhere` to scan the preset
	 * registry without the allocation cost of serializing the whole tree.
	 */
	private static function value_contains_substring( $value, $needle ) {
		if ( '' === $needle ) {
			return false;
		}
		if ( is_string( $value ) ) {
			return false !== strpos( $value, $needle );
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $v ) {
				if ( self::value_contains_substring( $v, $needle ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Get the map of every defined variable ID from the Variable Manager.
	 * Colors live in `et_global_data.global_colors`; others live in
	 * `et_divi_global_variables` grouped by type. Divi's customizer-bound
	 * accent colors (gcid-primary-color, gcid-secondary-color, gcid-heading-color,
	 * gcid-body-color, gcid-link-color) resolve via a separate code path
	 * (`GlobalData::$customizer_colors`) and are intentionally excluded from
	 * et_global_colors on save — include them here so they don't false-positive
	 * as orphans on every stock Divi 5 install.
	 */
	private static function get_defined_variable_ids() {
		$ids = [];

		// Colors.
		$raw         = et_get_option( 'et_global_data' );
		$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
		$colors      = ( is_array( $global_data ) && is_array( $global_data['global_colors'] ?? null ) )
			? $global_data['global_colors']
			: [];
		foreach ( $colors as $id => $c ) {
			if ( is_array( $c ) ) {
				$ids[ $id ] = [
					'type'  => 'colors',
					'label' => $c['label'] ?? $id,
					'value' => $c['color'] ?? '',
				];
			}
		}

		// Divi customizer-bound colors. Source via the class property (not a
		// hardcoded list) so new customizer colors added by upstream Divi land
		// automatically. Guard with class_exists in case Divi 4 is active or
		// the class is namespaced differently in a future release. Tagged
		// with source='customizer' so variables_scan_orphans can exclude them
		// from the unused_variables bucket — they can't be deleted via
		// delete_variable (bound to theme options, not the Variable Manager),
		// so surfacing them as "deletion candidates" would mislead callers.
		if ( class_exists( '\ET\Builder\Packages\GlobalData\GlobalData' ) ) {
			$customizer = \ET\Builder\Packages\GlobalData\GlobalData::$customizer_colors ?? [];
			foreach ( (array) $customizer as $id => $meta ) {
				if ( isset( $ids[ $id ] ) ) {
					continue; // User override in global_colors wins.
				}
				$ids[ $id ] = [
					'type'   => 'colors',
					'label'  => $meta['label'] ?? $id,
					'value'  => $meta['default'] ?? '',
					'source' => 'customizer',
				];
			}
		}

		// Non-color types.
		$vars = get_option( 'et_divi_global_variables', [] );
		if ( is_array( $vars ) ) {
			foreach ( [ 'numbers', 'strings', 'images', 'links', 'fonts' ] as $type ) {
				if ( ! is_array( $vars[ $type ] ?? null ) ) {
					continue;
				}
				foreach ( $vars[ $type ] as $id => $v ) {
					if ( is_array( $v ) ) {
						$ids[ $id ] = [
							'type'  => $type,
							'label' => $v['label'] ?? $id,
							'value' => $v['value'] ?? '',
						];
					}
				}
			}
		}

		return $ids;
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
		$d5    = self::get_d5_presets();
		$refs  = self::collect_page_preset_refs();
		$chain = self::collect_group_chain_refs( $d5 );

		// Union of page-content refs and in-registry chain refs. A preset is
		// "referenced" — and therefore unsafe to delete — if either axis sees it.
		// Use `+` instead of array_merge: keys are UUIDs and array_merge would
		// re-index any that happen to be all-digit strings, silently dropping
		// the original UUID from the union.
		$referenced_uuids = array_keys( $refs['all_uuids'] + $chain['counts'] );

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
					$block_count = $refs['all_uuids'][ $pid ] ?? 0;
					$group_count = $chain['counts'][ $pid ] ?? 0;
					$is_ref      = $block_count > 0 || $group_count > 0;
					$is_default  = ( $info['default'] ?? '' ) === $pid;

					$entry = [
						'id'              => $pid,
						'module'          => $mod,
						'type'            => $type,
						'name'            => $name,
						'has_attrs'       => $has_content,
						'is_default'      => $is_default,
						'referenced'      => $is_ref,
						'ref_count'       => $block_count + $group_count,
						'block_ref_count' => $block_count,
						'group_ref_count' => $group_count,
					];
					if ( 'group' === $type && $group_count > 0 ) {
						// Type-agnostic name: collect_group_chain_refs walks both
						// `module` and `group` buckets, so the referencing UUID can
						// belong to either type. Consumers needing the type can
						// look the UUID up in the audit response.
						$entry['referenced_by_presets'] = $chain['referenced_by'][ $pid ] ?? [];
					}

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
		$chain      = self::collect_group_chain_refs( $d5 );

		// Treat a preset as "in use" if it's referenced by page content OR by
		// another preset's groupPresets chain. Without the chain union,
		// remove_orphans / dedup would silently delete load-bearing group presets
		// (font, border, box-shadow, spacing, button) that module presets wire in.
		// See issue #302. Use `+` rather than array_merge so all-digit UUID keys
		// don't get silently re-indexed out of the union. Keep as an assoc set
		// so membership tests inside the preset loops are O(1) via isset()
		// rather than O(N) via in_array().
		$referenced_set = $refs['all_uuids'] + $chain['counts'];

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
						$is_ref     = isset( $referenced_set[ $pid ] );
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
							$is_ref    = isset( $referenced_set[ $pid ] );
							$is_def    = $pid === $default_id;
							$keep_ref  = isset( $referenced_set[ $keeper ] );
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
					$is_ref      = isset( $referenced_set[ $pid ] );
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
		$preset_id    = sanitize_text_field( $request->get_param( 'preset_id' ) );
		$new_name     = $request->get_param( 'name' );
		$new_attrs    = $request->get_param( 'attrs' );
		$new_priority = $request->get_param( 'priority' );

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
					// Mirror attrs into styleAttrs + renderAttrs to match VB save semantics.
					// Divi renders preset-affected CSS via two parallel passes: Pass A emits
					// `.preset--module--{module}--{uuid}` rules from preset.attrs (low specificity);
					// Pass B emits `.et_pb_{module}_N` rules with body-level parent chain from
					// preset.renderAttrs (high specificity). When both are populated, Pass B wins
					// the cascade. Without this mirror, removing a breakpoint from attrs leaves a
					// stale renderAttrs entry whose higher-specificity rule keeps rendering.
					// Writing all three keys keeps the two passes in lockstep and matches how VB
					// persists preset edits.
					$preset['attrs']       = $new_attrs;
					$preset['styleAttrs']  = $new_attrs;
					$preset['renderAttrs'] = $new_attrs;
				}
				if ( null !== $new_priority && is_numeric( $new_priority ) ) {
					// Controls stacked-preset cascade order in Divi's render path
					// (GlobalPreset::get_merged_attrs sorts ascending — higher priority
					// merged later, wins). Default in Divi is 10 when omitted.
					$preset['priority'] = (int) $new_priority;
				}

				$preset['updated'] = time() * 1000;

				$found = [
					'id'     => $preset_id,
					'module' => $mod,
					'type'   => $type,
					'name'   => $preset['name'],
				];
				// Unset both live references before exiting the nested loop —
				// `break 2;` skips the post-loop `unset($info)` that PHP
				// otherwise needs for foreach-by-reference cleanup. `$preset`
				// (line 3863) is a second reference into `$info['items'][...]`
				// and needs the same treatment. Defensive against future edits
				// that reuse either symbol later in this method.
				unset( $preset );
				unset( $info );
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
				// Unset the live reference before exiting the nested loop —
				// `break 2;` skips the post-loop `unset($info)` that PHP
				// otherwise needs for foreach-by-reference cleanup. Defensive
				// against future edits that reuse `$info` later in this method.
				unset( $info );
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

	/**
	 * Set or clear the per-module/group default preset pointer.
	 *
	 * Walks both buckets to locate the preset and updates
	 * `$d5[type][bucket_key]['default']` to the preset's UUID. With
	 * `unset=true`, clears the default pointer to '' regardless of whether
	 * the preset is currently the default — set/clear semantics are explicit
	 * via the flag, not derived from current state.
	 *
	 * Defaults apply to NEW instances only; existing modules keep their
	 * current preset bindings. Use preset_reassign for retroactive swaps.
	 */
	public static function preset_set_default( $request ) {
		$preset_id = sanitize_text_field( $request->get_param( 'preset_id' ) );
		$do_unset  = rest_sanitize_boolean( $request->get_param( 'unset' ) ?? false );

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

				$preset         = (array) $info['items'][ $preset_id ];
				$was_default_id = $info['default'] ?? '';
				$new_default_id = $do_unset ? '' : $preset_id;
				$info['default'] = $new_default_id;

				$found = [
					'id'             => $preset_id,
					'module'         => $mod,
					'type'           => $type,
					'name'           => $preset['name'] ?? '',
					'was_default_id' => $was_default_id,
					'new_default_id' => $new_default_id,
					'is_default'     => '' !== $new_default_id,
				];
				// Unset the live reference before exiting the nested loop —
				// `break 2;` skips the post-loop `unset($info)` that PHP
				// otherwise needs for foreach-by-reference cleanup. Defensive
				// against future edits that reuse `$info` later in this method.
				unset( $info );
				break 2;
			}
			unset( $info );
		}

		if ( ! $found ) {
			return new WP_Error( 'not_found', "Preset '{$preset_id}' not found", [ 'status' => 404 ] );
		}

		self::save_d5_presets( $d5 );

		$msg = $do_unset
			? "Default preset cleared for {$found['type']}/{$found['module']}."
			: "Preset '{$preset_id}' is now the default for {$found['type']}/{$found['module']}.";

		return rest_ensure_response( [
			'success' => true,
			'preset'  => $found,
			'message' => $msg,
		] );
	}

	/**
	 * Create a new preset in the D5 registry.
	 *
	 * For type='module': writes to $d5['module'][module_name]['items'][uuid].
	 * For type='group': writes to $d5['group'][group_name]['items'][uuid] — requires group_name and group_id; primary_attr_name is optional.
	 */
	public static function preset_create( $request ) {
		$module_name  = sanitize_text_field( $request->get_param( 'module_name' ) );
		$name         = sanitize_text_field( $request->get_param( 'name' ) );
		$attrs        = $request->get_param( 'attrs' );
		$type         = sanitize_key( $request->get_param( 'type' ) ?: 'module' );
		$group_name   = sanitize_text_field( $request->get_param( 'group_name' ) ?? '' );
		$group_id     = sanitize_text_field( $request->get_param( 'group_id' ) ?? '' );
		$primary_attr = sanitize_text_field( $request->get_param( 'primary_attr_name' ) ?? '' );
		$make_default = rest_sanitize_boolean( $request->get_param( 'make_default' ) ?? false );
		$priority     = $request->get_param( 'priority' );

		if ( '' === $module_name || '' === $name || ! is_array( $attrs ) ) {
			return new WP_Error( 'bad_request', 'module_name, name, attrs are required', [ 'status' => 400 ] );
		}
		if ( ! in_array( $type, [ 'module', 'group' ], true ) ) {
			return new WP_Error( 'bad_request', "type must be 'module' or 'group'", [ 'status' => 400 ] );
		}
		if ( 'group' === $type && ( '' === $group_name || '' === $group_id ) ) {
			return new WP_Error( 'bad_request', "group presets require group_name and group_id", [ 'status' => 400 ] );
		}

		$d5  = self::get_d5_presets();
		$uid = wp_generate_uuid4();
		$now = round( microtime( true ) * 1000 );

		// Write all three attribute buckets in parallel to match VB save semantics.
		// See preset_update for the full Pass A / Pass B architecture note; the short
		// version here is that renderAttrs is what the high-specificity instance-class
		// CSS reads from, so populating it at create time keeps MCP-created presets
		// consistent with VB-created ones for any consumer that reads renderAttrs.
		$preset = [
			'id'          => $uid,
			'name'        => $name,
			'moduleName'  => $module_name,
			'attrs'       => $attrs,
			'styleAttrs'  => $attrs,
			'renderAttrs' => $attrs,
			'type'        => $type,
			'created'     => $now,
			'updated'     => $now,
		];
		if ( defined( 'ET_BUILDER_VERSION' ) && '' !== ET_BUILDER_VERSION ) {
			$preset['version'] = ET_BUILDER_VERSION;
		}
		if ( null !== $priority && is_numeric( $priority ) ) {
			$preset['priority'] = (int) $priority;
		}

		if ( 'group' === $type ) {
			$preset['groupName'] = $group_name;
			$preset['groupId']   = $group_id;
			if ( '' !== $primary_attr ) {
				$preset['primaryAttrName'] = $primary_attr;
			}
			$bucket_key = $group_name;
			$bucket     = 'group';
		} else {
			$bucket_key = $module_name;
			$bucket     = 'module';
		}

		$d5[ $bucket ]                                   = (array) ( $d5[ $bucket ] ?? [] );
		$d5[ $bucket ][ $bucket_key ]                    = (array) ( $d5[ $bucket ][ $bucket_key ] ?? [] );
		$d5[ $bucket ][ $bucket_key ]['items']           = (array) ( $d5[ $bucket ][ $bucket_key ]['items'] ?? [] );
		$d5[ $bucket ][ $bucket_key ]['default']         = $d5[ $bucket ][ $bucket_key ]['default'] ?? '';
		$d5[ $bucket ][ $bucket_key ]['items'][ $uid ]   = $preset;

		$was_default_id = $d5[ $bucket ][ $bucket_key ]['default'];
		if ( $make_default ) {
			$d5[ $bucket ][ $bucket_key ]['default'] = $uid;
		}

		self::save_d5_presets( $d5 );

		$response = [
			'success' => true,
			'preset'  => [
				'id'          => $uid,
				'name'        => $name,
				'module_name' => $module_name,
				'type'        => $type,
				'bucket_key'  => $bucket_key,
			],
		];
		if ( $make_default ) {
			$response['preset']['is_default']     = true;
			$response['preset']['was_default_id'] = $was_default_id;
		}
		return rest_ensure_response( $response );
	}

	/**
	 * Reassign preset UUID references across pages.
	 *
	 * Walks posts/pages and rewrites two kinds of references:
	 *   - `attrs.modulePreset[...]` arrays (stacked module presets) — always, when scope permits.
	 *   - `attrs.groupPreset.<slot>.presetId` (attribute-level group presets) — when scope permits.
	 *
	 * For group-bucket reassignments, also rewrites preset-registry chains at their canonical
	 * locations per bucket (see `_extract_chain_slot_map` for paths):
	 *   - Module-bucket presets:  top-level `groupPresets.<slot>.presetId`
	 *   - Group-bucket presets:   `attrs.groupPreset.<slot>.presetId` (singular)
	 * so downstream presets that pull in the old group preset keep rendering.
	 *
	 * `scope` controls which refs are considered ("module" | "group" | "both", default "both").
	 * Default "both" auto-selects based on new_uuid's bucket — the module/group distinction is an
	 * identity invariant (cross-bucket swaps are rejected), so there's no ambiguity.
	 *
	 * With `strip_inline=true` (default), strips inline attrs that duplicate the new preset's attrs:
	 *   - Module scope: strips from the block root, guarded by "post-swap modulePreset stack is singular
	 *     ([new_uuid])" — stacked presets keep inline so other presets in the stack can't silently override
	 *     through the freshly-stripped fields.
	 *   - Group scope: strips per-slot using Divi's `GlobalPresetItemGroup` class to resolve the preset's
	 *     attrs for the target module+slot (handles composite button groups, `-id-classes` suffix, explicit
	 *     `attrName` component mappings, cross-module name translation). Same singular-stack guard at the
	 *     slot level. Unmappable slots (missing class, unknown module) skip strip and emit a per-slot
	 *     advisory at `summary.strip_advisory_per_slot[<module>::<slot>]`; neighbor slots still strip.
	 *
	 * Dry-run (default) returns a summary of proposed changes without writing.
	 */
	public static function preset_reassign( $request ) {
		$old_uuid     = sanitize_text_field( $request->get_param( 'old_uuid' ) );
		$new_uuid     = sanitize_text_field( $request->get_param( 'new_uuid' ) );
		$mode         = sanitize_key( $request->get_param( 'mode' ) ?: 'dry-run' );
		$strip_inline = rest_sanitize_boolean( $request->get_param( 'strip_inline' ) ?? true );
		$scope        = sanitize_key( $request->get_param( 'scope' ) ?: 'both' );
		$page_ids     = $request->get_param( 'page_ids' );

		if ( '' === $old_uuid || '' === $new_uuid ) {
			return new WP_Error( 'bad_request', 'old_uuid and new_uuid are required', [ 'status' => 400 ] );
		}
		if ( ! in_array( $mode, [ 'dry-run', 'apply' ], true ) ) {
			return new WP_Error( 'bad_request', "mode must be 'dry-run' or 'apply'", [ 'status' => 400 ] );
		}
		if ( ! in_array( $scope, [ 'module', 'group', 'both' ], true ) ) {
			return new WP_Error( 'bad_request', "scope must be 'module', 'group', or 'both'", [ 'status' => 400 ] );
		}

		$d5 = self::get_d5_presets();

		// Locate a UUID in the D5 registry and return its bucket + module + entry.
		// Returns null when the UUID isn't registered (legitimate for old_uuid — may be dangling).
		$find_bucket = static function ( $uuid ) use ( $d5 ) {
			foreach ( [ 'module', 'group' ] as $bucket ) {
				if ( ! isset( $d5[ $bucket ] ) ) {
					continue;
				}
				foreach ( (array) $d5[ $bucket ] as $mod => $info ) {
					$info = (array) $info;
					if ( isset( $info['items'][ $uuid ] ) ) {
						return [ 'bucket' => $bucket, 'module' => $mod, 'entry' => (array) $info['items'][ $uuid ] ];
					}
				}
			}
			return null;
		};

		$new_hit = $find_bucket( $new_uuid );
		if ( null === $new_hit ) {
			return new WP_Error( 'not_found', "new_uuid '{$new_uuid}' does not exist in preset registry", [ 'status' => 404 ] );
		}
		$new_bucket = $new_hit['bucket'];
		$new_mod    = $new_hit['module'];
		$new_entry  = $new_hit['entry'];

		// old_uuid is allowed to be dangling (not in registry) — preserves the documented
		// "can be a dangling/orphan UUID" contract for orphan cleanup workflows.
		$old_hit    = $find_bucket( $old_uuid );
		$old_bucket = null !== $old_hit ? $old_hit['bucket'] : null;

		// Bucket-type validation: cross-bucket swaps (module preset ↔ group preset) would write
		// wrong-type UUIDs into modulePreset arrays / groupPreset slots. Always rejected.
		if ( null !== $old_bucket && $old_bucket !== $new_bucket ) {
			return new WP_Error(
				'bucket_mismatch',
				"Bucket mismatch: old_uuid is a {$old_bucket} preset, new_uuid is a {$new_bucket} preset. Cross-bucket swaps are not supported.",
				[ 'status' => 400 ]
			);
		}
		if ( 'module' === $scope && 'module' !== $new_bucket ) {
			return new WP_Error( 'scope_mismatch', "scope='module' requires new_uuid to be a module preset (got {$new_bucket})", [ 'status' => 400 ] );
		}
		if ( 'group' === $scope && 'group' !== $new_bucket ) {
			return new WP_Error( 'scope_mismatch', "scope='group' requires new_uuid to be a group preset (got {$new_bucket})", [ 'status' => 400 ] );
		}

		// Resolve "both" to the concrete branch determined by new_uuid's bucket — module/group are
		// disjoint identity spaces, so there's exactly one valid walk for this swap.
		$effective_scope = ( 'both' === $scope ) ? $new_bucket : $scope;

		// Merge styleAttrs + attrs for the inline-strip comparison bag. VB-created presets sometimes
		// populate only styleAttrs for CSS-generating fields; attrs wins on conflict (same precedence Divi uses).
		// Only used in module effective scope.
		$preset_style_attrs = is_array( $new_entry['styleAttrs'] ?? null ) ? $new_entry['styleAttrs'] : [];
		$preset_base_attrs  = is_array( $new_entry['attrs'] ?? null ) ? $new_entry['attrs'] : [];
		$preset_attrs       = self::_deep_merge( $preset_style_attrs, $preset_base_attrs );

		// Safety cap for full-site scans to avoid timeout/memory issues on large sites.
		// Also enforced when page_ids is explicitly supplied — reject oversized batches so callers chunk.
		$max_pages = self::REASSIGN_MAX_PAGES;
		$truncated = false;
		if ( is_array( $page_ids ) && ! empty( $page_ids ) ) {
			if ( count( $page_ids ) > $max_pages ) {
				return new WP_Error(
					'too_many_pages',
					"page_ids count (" . count( $page_ids ) . ") exceeds REASSIGN_MAX_PAGES ({$max_pages}). Chunk the request.",
					[ 'status' => 400 ]
				);
			}
			$query_args = [
				'post_type'      => [ 'page', 'post' ],
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'post__in'       => array_map( 'absint', $page_ids ),
				'posts_per_page' => -1,
			];
		} else {
			$query_args = [
				'post_type'      => [ 'page', 'post' ],
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'posts_per_page' => $max_pages + 1,
			];
		}
		$posts = get_posts( $query_args );
		if ( count( $posts ) > $max_pages ) {
			$posts     = array_slice( $posts, 0, $max_pages );
			$truncated = true;
		}

		$summary = [
			'scope'           => $effective_scope,
			'pages_scanned'   => count( $posts ),
			'pages_modified'  => 0,
			'uuid_swaps'      => 0,
			'module_swaps'    => 0,
			'group_swaps'     => 0,
			'chain_swaps'     => 0,
			'inline_stripped' => 0,
			'truncated'       => $truncated,
			'max_pages'       => $max_pages,
			'errors'          => [],
			'details'         => [],
		];
		// Per-slot advisories — populated during group-scope strip when a slot's target paths can't
		// be resolved (e.g. Divi's GlobalPresetItemGroup class unavailable, slot not registered).
		// Unmappable slots skip strip; other slots in the same walk are unaffected.
		$summary['strip_advisory_per_slot'] = [];

		foreach ( $posts as $p ) {
			$content = $p->post_content;

			// Fast-path: skip the expensive parse_blocks() when the raw content doesn't even mention old_uuid.
			// Only matters at scale — for a single-page targeted reassign this is a noop.
			if ( strpos( $content, $old_uuid ) === false ) {
				continue;
			}

			$module_swap_hits = 0;
			$group_swap_hits  = 0;
			$strip_hits       = 0;
			$per_page_details = [];

			// Parse WP blocks to rewrite safely.
			$blocks  = parse_blocks( $content );
			$rewrite = function ( array $blocks ) use ( &$rewrite, $old_uuid, $new_uuid, $preset_attrs, $new_entry, $strip_inline, $effective_scope, &$module_swap_hits, &$group_swap_hits, &$strip_hits, &$per_page_details, &$summary ) {
				foreach ( $blocks as $i => $block ) {
					$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
					if ( 'module' === $effective_scope && isset( $attrs['modulePreset'] ) && is_array( $attrs['modulePreset'] ) ) {
						// Replace every occurrence — modulePreset is a stacked-preset array, same UUID may appear multiple times.
						$block_swaps = 0;
						foreach ( $attrs['modulePreset'] as $idx => $uuid_value ) {
							if ( $old_uuid === $uuid_value ) {
								$attrs['modulePreset'][ $idx ] = $new_uuid;
								$block_swaps++;
							}
						}
						if ( $block_swaps > 0 ) {
							$module_swap_hits += $block_swaps;
							$detail = [
								'block'       => $block['blockName'] ?? '',
								'admin_label' => self::get_nested_array_value( $attrs, [ 'meta', 'adminLabel', 'desktop', 'value' ], '' ),
								'ref_type'    => 'module',
								'swaps'       => $block_swaps,
								'action'      => 'swap',
							];
							// Safe-strip guard: only strip inline attrs when the resulting preset stack is singular.
							// If other presets remain in modulePreset after the swap, they may intentionally override
							// fields — stripping inline could let them win and change rendering.
							$post_swap_stack = array_values( array_unique( $attrs['modulePreset'] ) );
							$is_singular_stack = ( 1 === count( $post_swap_stack ) && $new_uuid === $post_swap_stack[0] );

							if ( $strip_inline && $is_singular_stack && ! empty( $preset_attrs ) ) {
								$before_hash = md5( wp_json_encode( $attrs ) );
								$attrs = self::_strip_redundant_inline_attrs( $attrs, $preset_attrs );
								if ( md5( wp_json_encode( $attrs ) ) !== $before_hash ) {
									$strip_hits++;
									$detail['action'] = 'swap+strip';
								}
							} elseif ( $strip_inline && ! $is_singular_stack ) {
								$detail['strip_skipped'] = 'stacked_presets_present';
							}
							$per_page_details[] = $detail;
							$block['attrs'] = $attrs;
						}
					}

					if ( 'group' === $effective_scope && isset( $attrs['groupPreset'] ) && is_array( $attrs['groupPreset'] ) ) {
						// groupPreset is a slot map: { <slot>: { presetId: <scalar|array>, ... }, ... }.
						// presetId may be a scalar string or a stacked array — Divi accepts both shapes.
						$block_group_swaps = 0;
						$target_module     = (string) ( $block['blockName'] ?? '' );
						foreach ( $attrs['groupPreset'] as $slot_key => $slot ) {
							if ( ! is_array( $slot ) || ! isset( $slot['presetId'] ) ) {
								continue;
							}
							$ids_is_array = is_array( $slot['presetId'] );
							$ids          = $ids_is_array ? $slot['presetId'] : [ $slot['presetId'] ];
							$slot_swaps   = 0;
							foreach ( $ids as $idx => $uuid_value ) {
								if ( $old_uuid === $uuid_value ) {
									$ids[ $idx ] = $new_uuid;
									$slot_swaps++;
								}
							}
							if ( $slot_swaps > 0 ) {
								$slot['presetId']                  = $ids_is_array ? $ids : $ids[0];
								$attrs['groupPreset'][ $slot_key ] = $slot;
								$group_swap_hits                  += $slot_swaps;
								$block_group_swaps                += $slot_swaps;

								$detail = [
									'block'       => $block['blockName'] ?? '',
									'admin_label' => self::get_nested_array_value( $attrs, [ 'meta', 'adminLabel', 'desktop', 'value' ], '' ),
									'ref_type'    => 'group',
									'slot'        => (string) $slot_key,
									'swaps'       => $slot_swaps,
									'action'      => 'swap',
								];

								// Safe-strip guard: same singular-stack rule as module scope —
								// stacked presets on a slot may intentionally override the swapped preset's
								// values, so we only strip inline when the slot's presetId resolves to a
								// single unique UUID equal to new_uuid post-swap.
								$post_swap_ids = array_values( array_unique( $ids ) );
								$is_singular   = ( 1 === count( $post_swap_ids ) && $new_uuid === $post_swap_ids[0] );

								if ( $strip_inline && $is_singular && '' !== $target_module ) {
									// Resolve the preset's attrs as they'd apply to THIS module + slot — Divi's
									// own class handles slot→path mapping (composite button groups, -id-classes
									// suffix, cross-module name translation, explicit attrName component mappings).
									$resolved_preset_attrs = self::_resolve_group_preset_attrs_for_target(
										$new_entry,
										$target_module,
										(string) $slot_key
									);

									if ( null === $resolved_preset_attrs ) {
										// Mappable slots strip; unmappable slots skip and log — don't let one unknown
										// slot block strips for neighbor slots on the same module.
										$detail['strip_skipped'] = 'slot_unresolvable';
										$advisory_key            = $target_module . '::' . (string) $slot_key;
										if ( ! isset( $summary['strip_advisory_per_slot'][ $advisory_key ] ) ) {
											$summary['strip_advisory_per_slot'][ $advisory_key ] = 'GlobalPresetItemGroup returned no attrs for this module+slot — preset may be unregistered, class unavailable, or slot not exposed on target module. Swap applied; inline attrs unchanged for this slot.';
										}
									} else {
										$before_hash = md5( wp_json_encode( $attrs ) );
										$attrs       = self::_strip_redundant_inline_attrs( $attrs, $resolved_preset_attrs );
										if ( md5( wp_json_encode( $attrs ) ) !== $before_hash ) {
											$strip_hits++;
											$detail['action'] = 'swap+strip';
										}
									}
								} elseif ( $strip_inline && ! $is_singular ) {
									$detail['strip_skipped'] = 'stacked_presets_present';
								}

								$per_page_details[] = $detail;
							}
						}
						if ( $block_group_swaps > 0 ) {
							$block['attrs'] = $attrs;
						}
					}

					if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
						$block['innerBlocks'] = $rewrite( $block['innerBlocks'] );
					}
					$blocks[ $i ] = $block;
				}
				return $blocks;
			};
			$new_blocks = $rewrite( $blocks );

			$swap_hits = $module_swap_hits + $group_swap_hits;
			if ( $swap_hits > 0 ) {
				$summary['pages_modified']++;
				$summary['uuid_swaps']      += $swap_hits;
				$summary['module_swaps']    += $module_swap_hits;
				$summary['group_swaps']     += $group_swap_hits;
				$summary['inline_stripped'] += $strip_hits;
				$page_detail = [
					'page_id'      => $p->ID,
					'title'        => $p->post_title,
					'swaps'        => $swap_hits,
					'module_swaps' => $module_swap_hits,
					'group_swaps'  => $group_swap_hits,
					'strips'       => $strip_hits,
					'modules'      => $per_page_details,
				];

				if ( 'apply' === $mode ) {
					// Per-post capability gate — matches the pattern used by every other content-writing
					// endpoint in this plugin; defends against custom roles that hold manage_options but
					// are restricted on specific post types.
					if ( ! current_user_can( 'edit_post', $p->ID ) ) {
						$summary['errors'][] = [
							'page_id' => $p->ID,
							'title'   => $p->post_title,
							'error'   => 'Current user cannot edit this post',
						];
						$page_detail['update_error'] = 'Current user cannot edit this post';
					} else {
						$new_content   = serialize_blocks( $new_blocks );
						$update_result = wp_update_post(
							[
								'ID'           => $p->ID,
								'post_content' => wp_slash( $new_content ),
							],
							true
						);
						if ( is_wp_error( $update_result ) ) {
							$summary['errors'][] = [
								'page_id' => $p->ID,
								'title'   => $p->post_title,
								'error'   => $update_result->get_error_message(),
							];
							$page_detail['update_error'] = $update_result->get_error_message();
						} elseif ( 0 === (int) $update_result ) {
							$summary['errors'][] = [
								'page_id' => $p->ID,
								'title'   => $p->post_title,
								'error'   => 'wp_update_post returned 0 (no update performed)',
							];
							$page_detail['update_error'] = 'wp_update_post returned 0';
						} else {
							self::invalidate_divi_cache( $p->ID );
						}
					}
				}

				$summary['details'][] = $page_detail;
			}
		}

		// Registry chain rewrite — runs whenever effective scope is group, including when
		// old_uuid is dangling (not currently in the registry). A group preset's UUID may be
		// referenced from OTHER presets' chain slots — module presets via top-level
		// `groupPresets.<slot>.presetId`, or other group presets via `attrs.groupPreset.<slot>.presetId`.
		// Those chain refs can persist after the target preset was deleted —
		// collect_group_chain_refs() treats this case as valid for audit, so reassign must treat it
		// as rewritable for orphan-cleanup consistency. Skipping the rewrite when old_uuid is
		// dangling would leave stale chain refs behind after page-ref swaps, defeating the
		// advertised dangling-old-UUID workflow.
		//
		// Apply mode re-reads the registry immediately before the chain rewrite to minimize the
		// stale-overwrite window: our initial `$d5` was fetched before the page scan, which can
		// iterate up to REASSIGN_MAX_PAGES posts. A VB session mutating presets during that scan
		// would otherwise be clobbered. Dry-run uses the original $d5 since it never writes.
		$chain_details = [];
		if ( 'group' === $effective_scope ) {
			$chain_registry = ( 'apply' === $mode ) ? self::get_d5_presets() : $d5;
			$chain_result   = self::_rewrite_registry_group_chains( $chain_registry, $old_uuid, $new_uuid );
			$chain_swaps    = (int) $chain_result['swaps'];
			$chain_details  = $chain_result['details'];
			$summary['chain_swaps'] = $chain_swaps;

			if ( $chain_swaps > 0 && 'apply' === $mode ) {
				// Fold the chain-updated registry into the D5 storage. Atomic write — both
				// storage locations updated together by save_d5_presets().
				self::save_d5_presets( $chain_result['registry'] );
			}
		}
		if ( ! empty( $chain_details ) ) {
			$summary['chain_details'] = $chain_details;
		}

		$success = 'apply' !== $mode || empty( $summary['errors'] );
		return rest_ensure_response( [
			'success'      => $success,
			'mode'         => $mode,
			'scope'        => $scope,
			'strip_inline' => $strip_inline,
			'old_uuid'     => $old_uuid,
			'new_uuid'     => $new_uuid,
			'new_module'   => $new_mod,
			'summary'      => $summary,
		] );
	}

	/**
	 * Walk the D5 preset registry and rewrite chain refs pointing at $old_uuid to $new_uuid.
	 *
	 * Walks the canonical location per bucket:
	 * - Module-bucket presets: top-level `groupPresets.<slot>.presetId`. Divi's VB bundle
	 *   `generateNewPreset` places `groupPresets` at the preset root and never mirrors it
	 *   into the attrs bags, so a single read/write at the root is sufficient.
	 * - Group-bucket presets: `<bag>.groupPreset.<slot>.presetId` (singular) across all three
	 *   attribute bags (attrs / styleAttrs / renderAttrs). The plugin's own `preset_update`
	 *   mirrors the full attrs bag into styleAttrs + renderAttrs to maintain the dual-pass
	 *   CSS lockstep (Pass A from attrs, Pass B from renderAttrs). Post-5.3.2 both `attrs`
	 *   AND `renderAttrs` are merged into the render pipeline (`ModuleRegistration.php:352-372`),
	 *   so a stale UUID in any bag would actively render. Each bag is rewritten surgically
	 *   and only if it already carries the ref — no blind-mirror, so pre-broken lockstep
	 *   (refs in some bags but not others) isn't silently clobbered.
	 *
	 * User-facing swap count + per-preset details come from the authoritative `attrs` bag only
	 * for group presets (or the root slot for module presets); mirrored rewrites in
	 * styleAttrs / renderAttrs are silent so counts don't inflate 3x when lockstep holds.
	 *
	 * Returns the updated registry + swap count + per-preset details. Does NOT write — caller
	 * decides whether to persist based on mode.
	 *
	 * `presetId` may be a scalar string or an array (Divi accepts both via the stacking convention).
	 * Slot key is preserved; only matching presetId entries are rewritten.
	 */
	private static function _rewrite_registry_group_chains( array $d5, string $old_uuid, string $new_uuid ): array {
		$swaps   = 0;
		$details = [];
		foreach ( [ 'module', 'group' ] as $bucket ) {
			if ( ! isset( $d5[ $bucket ] ) ) {
				continue;
			}
			$bucket_modules = (array) $d5[ $bucket ];
			foreach ( $bucket_modules as $mod => $info ) {
				$info  = (array) $info;
				$items = isset( $info['items'] ) ? (array) $info['items'] : [];
				foreach ( $items as $preset_uuid => $preset ) {
					if ( ! is_array( $preset ) && ! is_object( $preset ) ) {
						continue;
					}
					$preset = (array) $preset;

					if ( 'module' === $bucket ) {
						// Single-location rewrite at the preset root.
						$slot_map = self::_extract_chain_slot_map( $preset, $bucket );
						if ( empty( $slot_map ) ) {
							continue;
						}
						$result = self::_swap_chain_refs_in_group_presets_map( $slot_map, $old_uuid, $new_uuid );
						if ( 0 === $result['swaps'] ) {
							continue;
						}
						$preset                = self::_write_chain_slot_map( $preset, $bucket, $result['map'] );
						$items[ $preset_uuid ] = $preset;
						foreach ( $result['slot_swaps'] as $slot_key => $slot_count ) {
							$swaps    += $slot_count;
							$details[] = [
								'bucket'        => $bucket,
								'module'        => (string) $mod,
								'referenced_by' => (string) $preset_uuid,
								'slot'          => (string) $slot_key,
								'swaps'         => $slot_count,
							];
						}
						continue;
					}

					// Group bucket — rewrite per-bag so the attrs / styleAttrs / renderAttrs
					// mirrors stay in lockstep with the Pass A / Pass B CSS emission.
					$any_mutated      = false;
					$attrs_slot_swaps = [];
					foreach ( [ 'attrs', 'styleAttrs', 'renderAttrs' ] as $bag_key ) {
						if ( ! isset( $preset[ $bag_key ] ) ) {
							continue;
						}
						if ( ! is_array( $preset[ $bag_key ] ) && ! is_object( $preset[ $bag_key ] ) ) {
							continue;
						}
						$bag = (array) $preset[ $bag_key ];
						if ( ! isset( $bag['groupPreset'] ) ) {
							continue;
						}
						if ( ! is_array( $bag['groupPreset'] ) && ! is_object( $bag['groupPreset'] ) ) {
							continue;
						}
						$slot_map = (array) $bag['groupPreset'];
						$result   = self::_swap_chain_refs_in_group_presets_map( $slot_map, $old_uuid, $new_uuid );
						if ( $result['swaps'] > 0 ) {
							$bag['groupPreset'] = $result['map'];
							$preset[ $bag_key ] = $bag;
							$any_mutated        = true;
							if ( 'attrs' === $bag_key ) {
								$attrs_slot_swaps = $result['slot_swaps'];
							}
						}
					}

					if ( $any_mutated ) {
						$items[ $preset_uuid ] = $preset;
						foreach ( $attrs_slot_swaps as $slot_key => $slot_count ) {
							$swaps    += $slot_count;
							$details[] = [
								'bucket'        => $bucket,
								'module'        => (string) $mod,
								'referenced_by' => (string) $preset_uuid,
								'slot'          => (string) $slot_key,
								'swaps'         => $slot_count,
							];
						}
					}
				}
				if ( isset( $info['items'] ) ) {
					$info['items']          = $items;
					$bucket_modules[ $mod ] = $info;
				}
			}
			$d5[ $bucket ] = $bucket_modules;
		}
		return [ 'swaps' => $swaps, 'details' => $details, 'registry' => $d5 ];
	}

	/**
	 * Swap `presetId` references inside a single chain-ref slot map.
	 *
	 * Consumed by `_rewrite_registry_group_chains` — accepts the slot map extracted from either
	 * canonical location (top-level `groupPresets` on module presets, `attrs.groupPreset` on
	 * group presets — see `_extract_chain_slot_map`). Returns the mutated map + total swap count
	 * + per-slot swap counts. Callers write the mutated map back via `_write_chain_slot_map`.
	 *
	 * Each slot is cast from array-or-object before reading — stdClass slots are a real shape on
	 * sites where the D5 option round-tripped through JSON or a custom importer.
	 */
	private static function _swap_chain_refs_in_group_presets_map( array $group_presets_map, string $old_uuid, string $new_uuid ): array {
		$swaps      = 0;
		$slot_swaps = [];
		foreach ( $group_presets_map as $slot_key => $slot ) {
			if ( ! is_array( $slot ) && ! is_object( $slot ) ) {
				continue;
			}
			$slot = (array) $slot;
			if ( ! isset( $slot['presetId'] ) ) {
				continue;
			}
			$ids_is_array    = is_array( $slot['presetId'] );
			$ids             = $ids_is_array ? $slot['presetId'] : [ $slot['presetId'] ];
			$this_slot_swaps = 0;
			foreach ( $ids as $idx => $uuid_value ) {
				if ( $old_uuid === $uuid_value ) {
					$ids[ $idx ] = $new_uuid;
					$this_slot_swaps++;
				}
			}
			if ( $this_slot_swaps > 0 ) {
				$slot['presetId']                       = $ids_is_array ? $ids : $ids[0];
				$group_presets_map[ $slot_key ]         = $slot;
				$swaps                                 += $this_slot_swaps;
				$slot_swaps[ (string) $slot_key ]       = $this_slot_swaps;
			}
		}
		return [ 'map' => $group_presets_map, 'swaps' => $swaps, 'slot_swaps' => $slot_swaps ];
	}

	/**
	 * Top-level block-attr keys that carry identity/binding data, not style — never strip these
	 * even if a caller happened to store matching values in preset attrs.
	 */
	private static function strip_reserved_keys(): array {
		return [
			'meta',                // adminLabel, module identity
			'modulePreset',        // preset reference itself
			'groupPreset',         // attribute-level preset references
			'dynamicOptionGroups', // Composable Settings tracking
			'id',
			'storeInstanceId',
			'name',
			'moduleName',
			'builderVersion',
		];
	}

	/**
	 * Deep-merge two arrays — $overrides wins on leaf conflicts. Used to build the inline-strip
	 * comparison bag by merging preset.styleAttrs + preset.attrs.
	 */
	private static function _deep_merge( $base, $overrides ) {
		if ( ! is_array( $base ) ) {
			return $overrides;
		}
		if ( ! is_array( $overrides ) ) {
			return $base;
		}
		foreach ( $overrides as $key => $val ) {
			if ( isset( $base[ $key ] ) && is_array( $base[ $key ] ) && is_array( $val ) ) {
				$base[ $key ] = self::_deep_merge( $base[ $key ], $val );
			} else {
				$base[ $key ] = $val;
			}
		}
		return $base;
	}

	/**
	 * Resolve a group preset's attrs as they would apply to a target module + slot.
	 *
	 * Delegates slot→target-path mapping to Divi's own `GlobalPresetItemGroup` class, which already
	 * handles every edge case we care about: composite button groups, `-id-classes` suffix, explicit
	 * `attrName` component mappings (FormField / checkbox / radio), cross-module attr-name translation,
	 * and dynamic option-group subtrees. Reimplementing would only invite drift — this call returns
	 * attrs in the exact shape they'd merge onto the target module's inline attrs at render time.
	 *
	 * Parity with Divi's render path — matches `GlobalPreset::get_selected_group_presets()` +
	 * `GlobalPreset::get_merged_attrs()`:
	 *   - Runs runtime preset migration via `_maybe_runtime_migrate_preset_data` before constructing
	 *     the item (Divi does this at both `GlobalPreset.php:2485` and `:2518`). Older-shape presets
	 *     get migrated to canonical paths so strip compares against the actual rendered tree.
	 *   - Merges all three bags — `styleAttrs + attrs + renderAttrs` — because `get_merged_attrs()`
	 *     at `GlobalPreset.php:3179` merges group presets' renderAttrs into the final bag alongside
	 *     attrs; fields stored only in renderAttrs still override module inline and must be stripped.
	 *
	 * Results are cached per-request keyed by preset UUID + target module + slot — the resolver is
	 * pure within a single page scan, and `preset_reassign` may hit the same (module, slot, preset)
	 * tuple across many blocks on one page.
	 *
	 * Returns null when Divi's class isn't loaded or the resolver returns empty (unknown module,
	 * slot not registered, etc.) — callers should emit a per-slot advisory in that case and skip
	 * strip for the unmappable slot only.
	 *
	 * @param array  $new_entry          Full preset registry entry (includes `attrs`, `styleAttrs`,
	 *                                   `renderAttrs`, `groupName`, `groupId`, `moduleName`, etc.)
	 * @param string $target_module_name The block's module name (e.g. "divi/heading") — where the
	 *                                   preset is being applied, may differ from the preset's source module.
	 * @param string $slot_id            The slot path from `attrs.groupPreset.<slot>` on the target module.
	 * @return array|null Resolved preset attrs deep-merged (styleAttrs then attrs then renderAttrs), or null on failure.
	 */
	private static function _resolve_group_preset_attrs_for_target( array $new_entry, string $target_module_name, string $slot_id ) {
		static $cache = [];

		$item_class    = '\ET\Builder\Packages\GlobalData\GlobalPresetItemGroup';
		$preset_class  = '\ET\Builder\Packages\GlobalData\GlobalPreset';
		if ( ! class_exists( $item_class ) || ! class_exists( $preset_class ) ) {
			return null;
		}

		$preset_id = isset( $new_entry['id'] ) && is_string( $new_entry['id'] ) ? $new_entry['id'] : '';
		$cache_key = $preset_id . '|' . $target_module_name . '|' . $slot_id;
		if ( '' !== $preset_id && array_key_exists( $cache_key, $cache ) ) {
			return $cache[ $cache_key ];
		}

		try {
			// Parity step 1 — runtime migration. Divi always runs this before constructing the item
			// (see GlobalPreset.php:2485 and :2518). Skipping it would compare against stale paths on
			// sites carrying pre-5.3.0 preset shapes (FocusFields, ComposibleOptions, PresetStack).
			$migrated = $new_entry;
			try {
				$method = new \ReflectionMethod( $preset_class, '_maybe_runtime_migrate_preset_data' );
				$method->setAccessible( true );
				$migrated = $method->invoke( null, $new_entry, $target_module_name );
				if ( ! is_array( $migrated ) ) {
					$migrated = $new_entry;
				}
			} catch ( \Throwable $migrate_e ) {
				// If reflection/migration fails (unexpected), fall through with the unmigrated entry.
				// Worst case: stale paths for legacy preset shapes — same as pre-fix behavior for that
				// subset, while fully-migrated sites still benefit.
				$migrated = $new_entry;
			}

			$item = new $item_class( [
				'data'       => $migrated,
				'isExist'    => true,
				'moduleName' => $target_module_name,
				'groupId'    => $slot_id,
			] );

			$resolved_attrs        = $item->get_data_attrs();
			$resolved_style_attrs  = $item->get_data_style_attrs();
			$resolved_render_attrs = $item->get_data_render_attrs();
		} catch ( \Throwable $e ) {
			if ( '' !== $preset_id ) {
				$cache[ $cache_key ] = null;
			}
			return null;
		}

		if ( ! is_array( $resolved_attrs ) ) {
			$resolved_attrs = [];
		}
		if ( ! is_array( $resolved_style_attrs ) ) {
			$resolved_style_attrs = [];
		}
		if ( ! is_array( $resolved_render_attrs ) ) {
			$resolved_render_attrs = [];
		}

		if ( empty( $resolved_attrs ) && empty( $resolved_style_attrs ) && empty( $resolved_render_attrs ) ) {
			if ( '' !== $preset_id ) {
				$cache[ $cache_key ] = null;
			}
			return null;
		}

		// Parity step 2 — merge all three bags. `GlobalPreset::get_merged_attrs()` at line 3179 does
		// `array_replace_recursive( $module_presets_attrs, $group_presets_attrs, $group_presets_render_attrs, $module_attrs )`
		// — group-preset renderAttrs merge into the final bag alongside attrs. Strip must see the
		// same union or fields stored only in renderAttrs silently survive strip_inline=true.
		$merged = self::_deep_merge( $resolved_style_attrs, $resolved_attrs );
		$merged = self::_deep_merge( $merged, $resolved_render_attrs );

		if ( '' !== $preset_id ) {
			$cache[ $cache_key ] = $merged;
		}

		return $merged;
	}

	/**
	 * Recursively remove attrs from $inline that are deep-equal to the value in $preset at the same path.
	 * Preserves unrelated branches. Top-level reserved keys (meta, modulePreset, etc.) are always preserved
	 * so preset_reassign never strips identity/binding data even if a caller wrote matching values into the preset.
	 */
	private static function _strip_redundant_inline_attrs( $inline, $preset, bool $is_root = true ) {
		if ( ! is_array( $inline ) || ! is_array( $preset ) ) {
			return $inline;
		}
		$reserved = $is_root ? self::strip_reserved_keys() : [];
		foreach ( $inline as $key => $val ) {
			if ( in_array( $key, $reserved, true ) ) {
				continue;
			}
			if ( ! array_key_exists( $key, $preset ) ) {
				continue;
			}
			if ( is_array( $val ) && is_array( $preset[ $key ] ) ) {
				$inline[ $key ] = self::_strip_redundant_inline_attrs( $val, $preset[ $key ], false );
				if ( is_array( $inline[ $key ] ) && empty( $inline[ $key ] ) ) {
					unset( $inline[ $key ] );
				}
			} elseif ( $val === $preset[ $key ] ) {
				unset( $inline[ $key ] );
			}
		}
		return $inline;
	}

	/**
	 * Scan page content for modulePreset UUIDs that do NOT exist in the D5 registry.
	 * Categorizes as dangling orphans or D4-legacy refs.
	 */
	public static function preset_scan_orphans( $request ) {
		$d5     = self::get_d5_presets();
		$refs   = self::collect_page_preset_refs();
		// Mirror get_presets: the option can be serialized-string on some environments.
		$legacy = et_get_option( 'builder_global_presets_ng', (object) [], '', true, false, '', '', true );
		$legacy = is_string( $legacy ) ? maybe_unserialize( $legacy ) : $legacy;
		$legacy = is_array( $legacy ) || is_object( $legacy ) ? (array) $legacy : [];

		$d5_uuids = [];
		foreach ( [ 'module', 'group' ] as $bucket ) {
			if ( ! isset( $d5[ $bucket ] ) ) {
				continue;
			}
			foreach ( (array) $d5[ $bucket ] as $mod => $info ) {
				$info  = (array) $info;
				$items = isset( $info['items'] ) ? (array) $info['items'] : [];
				foreach ( $items as $pid => $_ ) {
					$d5_uuids[ $pid ] = true;
				}
			}
		}

		$legacy_uuids = [];
		foreach ( $legacy as $mod => $module_presets ) {
			$module_presets = is_array( $module_presets ) ? (object) $module_presets : $module_presets;
			if ( ! is_object( $module_presets ) || empty( $module_presets->presets ) ) {
				continue;
			}
			foreach ( (array) $module_presets->presets as $pid => $_ ) {
				$legacy_uuids[ $pid ] = $mod;
			}
		}

		// Build uuid → pages[] index once (O(P) pre-pass) so orphan/legacy resolution is O(U) instead of O(U×P).
		// Dedup per (uuid,page_id) defensively: `custom_uuids` is already deduped per page in
		// collect_page_preset_refs, but keep this robust if that invariant ever changes.
		$uuid_to_pages = [];
		foreach ( $refs['per_page'] as $pid => $pinfo ) {
			$page_entry   = [ 'page_id' => $pid, 'title' => $pinfo['title'] ];
			$custom_uuids = array_unique( (array) ( $pinfo['custom_uuids'] ?? [] ) );
			foreach ( $custom_uuids as $uuid ) {
				$uuid_to_pages[ $uuid ][ $pid ] = $page_entry;
			}
		}
		foreach ( $uuid_to_pages as $uuid => $pages ) {
			$uuid_to_pages[ $uuid ] = array_values( $pages );
		}

		$orphans     = [];
		$legacy_refs = [];
		foreach ( $refs['all_uuids'] as $uuid => $count ) {
			if ( isset( $d5_uuids[ $uuid ] ) ) {
				continue;
			}
			$pages_with = $uuid_to_pages[ $uuid ] ?? [];
			if ( isset( $legacy_uuids[ $uuid ] ) ) {
				$legacy_refs[] = [
					'uuid'          => $uuid,
					'ref_count'     => $count,
					'legacy_module' => $legacy_uuids[ $uuid ],
					'pages'         => $pages_with,
				];
			} else {
				$orphans[] = [
					'uuid'      => $uuid,
					'ref_count' => $count,
					'pages'     => $pages_with,
				];
			}
		}

		return rest_ensure_response( [
			'orphan_count'         => count( $orphans ),
			'legacy_ref_count'     => count( $legacy_refs ),
			'total_referenced'     => count( $refs['all_uuids'] ),
			'total_in_registry'    => count( $d5_uuids ),
			'orphans'              => $orphans,
			'd4_legacy_candidates' => $legacy_refs,
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

	// ── Module state: lock / unlock / clone ────────────────────────

	/**
	 * Walk a parsed-blocks tree and apply $mutator to the matched block (or
	 * its parent siblings array / parent block) in place. PHP's foreach-by-
	 * reference + recursion + returning references is fragile, so we use a
	 * callback pattern: the mutator runs INSIDE the recursion, with the
	 * actual `&$block`, `&$siblings`, and `&$parent_block` references live.
	 *
	 * Mutator signature:
	 *   `function (array &$siblings, int $index, array &$block, ?array &$parent_block) : void`
	 *
	 * The optional `$parent_block` parameter is critical for clone-style
	 * operations: WordPress's `serialize_blocks()` only emits as many
	 * innerBlocks as there are `null` placeholders in the parent's
	 * `innerContent` array — so a clone that splices into `siblings`
	 * (= `parent_block.innerBlocks`) MUST also splice a `null` into
	 * `parent_block.innerContent`. For top-level blocks (no parent), the
	 * parameter is null and serialize uses the array directly.
	 *
	 * Modes:
	 *   - 'label':       match attrs.module.meta.adminLabel.desktop.value === $needle
	 *   - 'match_text':  case-insensitive substring search of serialized block
	 *   - 'auto_index':  "type:N" format (e.g. "text:5") — Nth occurrence of
	 *                    block name `divi/{type}` in document order
	 *
	 * Returns true if a match was found and the mutator ran; false otherwise.
	 */
	private static function walk_and_mutate(
		array &$blocks,
		string $mode,
		string $needle,
		int $occurrence,
		array &$counters,
		int &$match_count,
		callable $mutator,
		?array &$parent_block = null
	) {
		$count = count( $blocks );
		// Index by counter — must operate on $blocks[$i] not a foreach reference,
		// because array_splice inside the mutator would shift indices and
		// invalidate the foreach iterator.
		for ( $i = 0; $i < $count; $i++ ) {
			$block = &$blocks[ $i ];
			$name  = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];

			// Auto-index counter: count every block of each type in document order.
			if ( 0 === strpos( $name, 'divi/' ) ) {
				$short = substr( $name, 5 );
				if ( ! isset( $counters[ $short ] ) ) {
					$counters[ $short ] = 0;
				}
				$counters[ $short ]++;
			}

			// For 'match_text' mode, recurse FIRST (bottom-up search) so we
			// match the most specific block containing the text, not the outer
			// container that also contains it via descendants. Other modes use
			// document-order top-down which gives the auto_index counter the
			// same ordering semantics as get_page_layout.
			if ( 'match_text' === $mode && isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && ! empty( $block['innerBlocks'] ) ) {
				if ( self::walk_and_mutate(
					$block['innerBlocks'], $mode, $needle, $occurrence, $counters, $match_count, $mutator, $block
				) ) {
					unset( $block );
					return true;
				}
			}

			$matched = false;
			if ( 'label' === $mode ) {
				$label = $attrs['module']['meta']['adminLabel']['desktop']['value'] ?? '';
				if ( $label === $needle ) {
					$match_count++;
					if ( $match_count === $occurrence ) {
						$matched = true;
					}
				}
			} elseif ( 'auto_index' === $mode ) {
				$parts = explode( ':', $needle );
				if ( 2 === count( $parts ) && '' !== $parts[0] && ctype_digit( $parts[1] ) ) {
					$ai_type = $parts[0];
					$ai_n    = (int) $parts[1];
					$short   = 0 === strpos( $name, 'divi/' ) ? substr( $name, 5 ) : '';
					if ( $short === $ai_type && ( $counters[ $short ] ?? 0 ) === $ai_n ) {
						$matched = true;
					}
				}
			} elseif ( 'match_text' === $mode ) {
				// At this point recursion above already returned false for this
				// branch, so no descendant matched. Now check ONLY the current
				// block's own opening-comment markup, NOT its descendants.
				//
				// `serialize_block($block)` walks innerBlocks recursively (per
				// wp-includes/blocks.php:1717), which means a leaf match would
				// also count every ancestor that contains it via descendants —
				// double-counting that breaks the `occurrence` parameter and
				// can let `occurrence:2` mutate the parent container instead of
				// returning no_match.
				//
				// Workaround: temporarily strip innerBlocks/innerContent before
				// serializing, so we get only this block's own opening comment
				// (with attrs encoded). For self-closing leaf blocks this is
				// already the full markup; for containers, it's just the
				// `<!-- wp:divi/section ... -->` comment line, which won't
				// contain descendant text content like "Your content goes here".
				$shallow = $block;
				$shallow['innerBlocks']  = [];
				$shallow['innerContent'] = [];
				if ( false !== stripos( serialize_block( $shallow ), $needle ) ) {
					$match_count++;
					if ( $match_count === $occurrence ) {
						$matched = true;
					}
				}
			}

			if ( $matched ) {
				$mutator( $blocks, $i, $block, $parent_block );
				unset( $block );
				return true;
			}

			// For non-'match_text' modes, recurse AFTER the check (top-down
			// document order). Already done above for match_text.
			if ( 'match_text' !== $mode && isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && ! empty( $block['innerBlocks'] ) ) {
				if ( self::walk_and_mutate(
					$block['innerBlocks'], $mode, $needle, $occurrence, $counters, $match_count, $mutator, $block
				) ) {
					unset( $block );
					return true;
				}
			}
			unset( $block );
		}
		return false;
	}

	/**
	 * Resolve targeting params from the request to a (mode, needle, occurrence)
	 * triple. Returns a WP_Error on missing/invalid params.
	 */
	private static function resolve_module_target( $request ) {
		$label      = trim( (string) $request->get_param( 'label' ) );
		$match_text = trim( (string) $request->get_param( 'match_text' ) );
		$auto_index = trim( (string) $request->get_param( 'auto_index' ) );
		$occurrence = max( 1, absint( $request->get_param( 'occurrence' ) ?? 1 ) );

		if ( '' === $label && '' === $match_text && '' === $auto_index ) {
			return new WP_Error( 'missing_target', 'One of "label", "match_text", or "auto_index" is required', [ 'status' => 400 ] );
		}

		$mode   = '' !== $auto_index ? 'auto_index' : ( '' !== $label ? 'label' : 'match_text' );
		$needle = 'auto_index' === $mode ? $auto_index : ( 'label' === $mode ? $label : $match_text );
		return [ 'mode' => $mode, 'needle' => $needle, 'occurrence' => $occurrence ];
	}

	/**
	 * Load post + permission check shared by lock/unlock/clone. Returns
	 * a [post, blocks] pair or WP_Error.
	 */
	private static function load_post_for_module_op( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Page not found', [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Cannot edit this post', [ 'status' => 403 ] );
		}
		return [ 'post' => $post, 'blocks' => parse_blocks( $post->post_content ) ];
	}

	/**
	 * Save mutated blocks back to the post. Returns true on success or a
	 * WP_Error on failure.
	 */
	private static function save_mutated_blocks( $post, array $blocks ) {
		$new_content = serialize_blocks( $blocks );
		$result      = wp_update_post(
			[ 'ID' => $post->ID, 'post_content' => wp_slash( $new_content ) ],
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Lock a module so VB users cannot edit it. Sets `attrs.locked` to
	 * `{desktop: {value: "on"}}` per Divi's per-breakpoint convention
	 * (verified via VB-save probe). Locked modules render normally on
	 * frontend; only VB-side editing is gated.
	 */
	public static function lock_module( $request ) {
		$target = self::resolve_module_target( $request );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$loaded = self::load_post_for_module_op( $request );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$blocks            = $loaded['blocks'];
		$counters          = [];
		$match_count = 0;
		$captured          = [ 'block_name' => '', 'admin_label' => '', 'was_locked' => false ];

		$found = self::walk_and_mutate(
			$blocks, $target['mode'], $target['needle'], $target['occurrence'],
			$counters, $match_count,
			function ( array &$siblings, int $i, array &$block, ?array &$parent_block ) use ( &$captured ) {
				if ( ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
					$block['attrs'] = [];
				}
				$captured['was_locked']  = isset( $block['attrs']['locked']['desktop']['value'] )
					&& 'on' === $block['attrs']['locked']['desktop']['value'];
				$captured['block_name']  = $block['blockName'] ?? '';
				$captured['admin_label'] = $block['attrs']['module']['meta']['adminLabel']['desktop']['value'] ?? '';
				$block['attrs']['locked'] = [ 'desktop' => [ 'value' => 'on' ] ];
			}
		);

		if ( ! $found ) {
			return new WP_Error( 'no_match', sprintf( "No module found matching '%s' (mode=%s)", $target['needle'], $target['mode'] ), [ 'status' => 404 ] );
		}

		$saved = self::save_mutated_blocks( $loaded['post'], $blocks );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return rest_ensure_response( [
			'success' => true,
			'module'  => array_merge( $captured, [ 'is_locked' => true ] ),
			'message' => $captured['was_locked'] ? 'Module was already locked (re-confirmed).' : 'Module locked.',
		] );
	}

	/**
	 * Unlock a module by removing `attrs.locked` entirely. Matches Divi VB's
	 * convention: unlocked = attribute absent (NOT `{value: "off"}`). VB
	 * doesn't write a falsy value on unlock — it removes the field.
	 */
	public static function unlock_module( $request ) {
		$target = self::resolve_module_target( $request );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$loaded = self::load_post_for_module_op( $request );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$blocks            = $loaded['blocks'];
		$counters          = [];
		$match_count = 0;
		$captured          = [ 'block_name' => '', 'admin_label' => '', 'was_locked' => false ];

		$found = self::walk_and_mutate(
			$blocks, $target['mode'], $target['needle'], $target['occurrence'],
			$counters, $match_count,
			function ( array &$siblings, int $i, array &$block, ?array &$parent_block ) use ( &$captured ) {
				if ( ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
					$block['attrs'] = [];
				}
				$captured['was_locked']  = isset( $block['attrs']['locked']['desktop']['value'] )
					&& 'on' === $block['attrs']['locked']['desktop']['value'];
				$captured['block_name']  = $block['blockName'] ?? '';
				$captured['admin_label'] = $block['attrs']['module']['meta']['adminLabel']['desktop']['value'] ?? '';
				unset( $block['attrs']['locked'] );
			}
		);

		if ( ! $found ) {
			return new WP_Error( 'no_match', sprintf( "No module found matching '%s' (mode=%s)", $target['needle'], $target['mode'] ), [ 'status' => 404 ] );
		}

		$saved = self::save_mutated_blocks( $loaded['post'], $blocks );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return rest_ensure_response( [
			'success' => true,
			'module'  => array_merge( $captured, [ 'is_locked' => false ] ),
			'message' => $captured['was_locked'] ? 'Module unlocked.' : 'Module was not locked (no-op).',
		] );
	}

	/**
	 * Clone a module by deep-copying its block JSON and inserting it next
	 * to the source within the same parent container. `position` controls
	 * before/after placement (default "after").
	 *
	 * Module IDs are reassigned by Divi at render time from the block tree
	 * position, so the clone gets fresh IDs automatically — no need to
	 * mint UUIDs on our side.
	 */
	public static function clone_module( $request ) {
		$target = self::resolve_module_target( $request );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$loaded = self::load_post_for_module_op( $request );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$position = sanitize_key( (string) ( $request->get_param( 'position' ) ?? 'after' ) );
		if ( ! in_array( $position, [ 'before', 'after' ], true ) ) {
			$position = 'after';
		}

		$blocks            = $loaded['blocks'];
		$counters          = [];
		$match_count = 0;
		$captured          = [ 'block_name' => '', 'admin_label' => '' ];

		try {
			$found = self::walk_and_mutate(
				$blocks, $target['mode'], $target['needle'], $target['occurrence'],
				$counters, $match_count,
				function ( array &$siblings, int $i, array &$block, ?array &$parent_block ) use ( $position, &$captured ) {
					$captured['block_name']  = $block['blockName'] ?? '';
					$captured['admin_label'] = $block['attrs']['module']['meta']['adminLabel']['desktop']['value'] ?? '';
					// PHP arrays-of-arrays are copy-by-value — this IS a deep clone for
					// plain-array nodes (innerBlocks recursively included). No refs.
					$clone     = $block;
					$insert_at = ( 'after' === $position ) ? $i + 1 : $i;
					array_splice( $siblings, $insert_at, 0, [ $clone ] );

				// Critical: WordPress's serialize_blocks() emits innerBlocks based
				// on `null` placeholders in the parent's `innerContent`. When the
				// matched block lives inside another block (i.e. parent_block is
				// not null), we must splice a null placeholder into
				// $parent_block['innerContent'] at the SAME logical position as the
				// new innerBlocks entry — otherwise the clone is silently dropped
				// from the serialized output.
				//
				// `innerContent` is a flat array where strings are HTML chunks and
				// nulls mark innerBlock slots. To insert at position $insert_at in
				// `innerBlocks`, find the $insert_at'th null in innerContent and
				// splice a new null there (or after, to preserve any pre-block HTML
				// chunk).
				if ( null !== $parent_block && isset( $parent_block['innerContent'] ) && is_array( $parent_block['innerContent'] ) ) {
					$null_seen     = 0;
					$last_null_idx = -1;
					$ic_insert     = null;
					foreach ( $parent_block['innerContent'] as $ic_idx => $ic_item ) {
						if ( null === $ic_item ) {
							if ( $null_seen === $insert_at ) {
								// Insert at this null's position so the new placeholder
								// pairs with the cloned innerBlock at the same logical index.
								$ic_insert = $ic_idx;
								break;
							}
							$last_null_idx = $ic_idx;
							$null_seen++;
						}
					}

					// Sanity check: parent_block must have at least one null
					// placeholder, because we're cloning an EXISTING child block
					// (so parent.innerBlocks had ≥1 entry pre-splice, which
					// requires ≥1 null in innerContent for valid parsed input).
					// Zero nulls = malformed parsed block — error rather than
					// guess at insertion order.
					if ( -1 === $last_null_idx && null === $ic_insert ) {
						throw new \RuntimeException(
							sprintf(
								"Refusing to clone: parent block '%s' has innerBlocks but no `null` placeholders in innerContent (malformed parsed input). Cannot determine where to insert the new placeholder. Re-save the page through VB to regenerate canonical block markup, then retry.",
								$parent_block['blockName'] ?? 'unknown'
							)
						);
					}

					// Fallback: $insert_at is beyond the last existing null
					// (e.g. inserting at end). Place the new null IMMEDIATELY
					// after the last existing null, NOT at array end — trailing
					// HTML chunks after the last null must remain after our new
					// placeholder so serialization order is preserved.
					if ( null === $ic_insert ) {
						$ic_insert = $last_null_idx + 1;
					}
					array_splice( $parent_block['innerContent'], $ic_insert, 0, [ null ] );
				}
			}
			);
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'malformed_parent', $e->getMessage(), [ 'status' => 500 ] );
		}

		if ( ! $found ) {
			return new WP_Error( 'no_match', sprintf( "No module found matching '%s' (mode=%s)", $target['needle'], $target['mode'] ), [ 'status' => 404 ] );
		}

		$saved = self::save_mutated_blocks( $loaded['post'], $blocks );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return rest_ensure_response( [
			'success' => true,
			'cloned'  => array_merge( $captured, [ 'position' => $position ] ),
			'message' => sprintf( "Module cloned %s source.", $position ),
		] );
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
	 * Count Divi's customizer-bound colors (gcid-primary-color etc.). They
	 * render implicitly at the start of the Variable Manager but live in
	 * GlobalData::$customizer_colors — separate storage from the user palette
	 * at et_global_data.global_colors. New user variables must offset past
	 * this count to avoid colliding with the implicit first-slot defaults.
	 * Sourced via the class property so future upstream additions land
	 * automatically; class_exists guard protects against Divi 4 / namespace
	 * churn. Shared by create_variable and update_global_colors.
	 */
	private static function get_customizer_color_count() {
		if ( ! class_exists( '\ET\Builder\Packages\GlobalData\GlobalData' ) ) {
			return 0;
		}
		return count( (array) ( \ET\Builder\Packages\GlobalData\GlobalData::$customizer_colors ?? [] ) );
	}

	/**
	 * Parse a positive px value like "20px" → float. Used for viewport
	 * anchors (which are always px — a viewport width in rem is ambiguous
	 * because it depends on root font-size at evaluation time).
	 */
	private static function parse_fluid_px( $str ) {
		if ( ! is_string( $str ) ) {
			return null;
		}
		// Accept leading-dot CSS formats like ".5px" and "-.2px" alongside
		// the common "20px" / "1.25px" / "-5px" forms.
		if ( preg_match( '/^(-?\d*\.?\d+)px$/', trim( $str ), $m ) ) {
			return (float) $m[1];
		}
		return null;
	}

	/**
	 * Parse a viewport anchor like "320px" → positive float.
	 */
	private static function parse_fluid_viewport( $str ) {
		$n = self::parse_fluid_px( $str );
		return ( null !== $n && $n > 0 ) ? $n : null;
	}

	/**
	 * Parse a size value with its unit (px or rem) into a normalized
	 * { num, unit } pair. Used for fluid min/max/target values which may
	 * be either unit. Rem-to-px conversion happens later using the
	 * caller-declared root_font_size_px so the math remains correct on
	 * sites with non-16px root font-size.
	 */
	private static function parse_size_with_unit( $str ) {
		if ( ! is_string( $str ) ) {
			return null;
		}
		if ( preg_match( '/^(-?\d*\.?\d+)(px|rem)$/', trim( $str ), $m ) ) {
			return [ 'num' => (float) $m[1], 'unit' => $m[2] ];
		}
		return null;
	}

	/**
	 * Format a numeric value with up to 3 decimals, trailing zeros trimmed,
	 * and negative-zero normalized to "0". Shared by the size + slope
	 * formatters to keep decimal-emission consistent.
	 */
	private static function format_fluid_decimal( $num ) {
		$s = rtrim( rtrim( number_format( $num, 3, '.', '' ), '0' ), '.' );
		if ( '' === $s || '-0' === $s ) {
			$s = '0';
		}
		return $s;
	}

	/**
	 * Format a px value in the target output unit. Uses the caller-supplied
	 * root font-size (defaults to 16, the standard browser default) for
	 * px→rem conversion so sites with a non-standard `html { font-size }`
	 * can emit correct rem values.
	 */
	private static function format_fluid_size( $num_px, $output_unit, $root_font_size_px = 16.0 ) {
		$val = ( 'rem' === $output_unit ) ? $num_px / $root_font_size_px : $num_px;
		return self::format_fluid_decimal( $val ) . $output_unit;
	}

	/**
	 * Format a vw slope value — always in vw, independent of output_unit
	 * (vw is viewport-pixel-rooted, so the slope term does not carry a
	 * rem↔px conversion).
	 */
	private static function format_fluid_slope( $slope_abs ) {
		return self::format_fluid_decimal( $slope_abs ) . 'vw';
	}

	/**
	 * Build a clamp() formula from two (viewport, value) anchor points,
	 * both in px. Emits min/max ordered by value (not viewport) so negative
	 * slopes work. Collapses to a scalar when both values are equal. The
	 * slope term is inherently viewport-pixel-rooted (vw = 1% of viewport
	 * pixels), so when output_unit is rem the caller's root_font_size_px
	 * is used to keep the rem values arithmetically consistent with the vw
	 * slope. All internal math is in px.
	 */
	private static function build_fluid_clamp( $w1, $v1, $w2, $v2, $output_unit = 'px', $root_font_size_px = 16.0 ) {
		if ( abs( $w2 - $w1 ) < 0.01 ) {
			throw new \InvalidArgumentException( 'Fluid anchors cannot share the same viewport.' );
		}
		if ( abs( $v2 - $v1 ) < 0.01 ) {
			return self::format_fluid_size( $v1, $output_unit, $root_font_size_px );
		}
		$slope_vw = ( $v2 - $v1 ) / ( $w2 - $w1 ) * 100.0;
		$base_px  = $v1 - ( $slope_vw * $w1 / 100.0 );
		$min_v    = min( $v1, $v2 );
		$max_v    = max( $v1, $v2 );
		$op       = ( $slope_vw >= 0 ) ? '+' : '-';
		return sprintf(
			'clamp(%s, %s %s %s, %s)',
			self::format_fluid_size( $min_v, $output_unit, $root_font_size_px ),
			self::format_fluid_size( $base_px, $output_unit, $root_font_size_px ),
			$op,
			self::format_fluid_slope( abs( $slope_vw ) ),
			self::format_fluid_size( $max_v, $output_unit, $root_font_size_px )
		);
	}

	/**
	 * Generate a clamp() from min/max shorthand using default anchors 320px
	 * and 1920px (industry convention for fluid scales). Accepts px or rem
	 * inputs; rem values are converted to px internally using the caller-
	 * declared root_font_size_px before the slope math runs.
	 */
	private static function generate_fluid_clamp_from_minmax( $min_str, $max_str, $output_unit = 'px', $root_font_size_px = 16.0 ) {
		$min_p = self::parse_size_with_unit( $min_str );
		$max_p = self::parse_size_with_unit( $max_str );
		if ( null === $min_p ) {
			throw new \InvalidArgumentException( "Invalid min: '$min_str' — expected e.g. '20px' or '1.25rem'." );
		}
		if ( null === $max_p ) {
			throw new \InvalidArgumentException( "Invalid max: '$max_str' — expected e.g. '60px' or '3.75rem'." );
		}
		$min_px = ( 'rem' === $min_p['unit'] ) ? $min_p['num'] * $root_font_size_px : $min_p['num'];
		$max_px = ( 'rem' === $max_p['unit'] ) ? $max_p['num'] * $root_font_size_px : $max_p['num'];
		return self::build_fluid_clamp( 320.0, $min_px, 1920.0, $max_px, $output_unit, $root_font_size_px );
	}

	/**
	 * Generate a clamp() from explicit { viewport => value } targets.
	 * Requires exactly 2 entries. Viewport keys must be px; values may
	 * be px or rem. Rem values are converted to px internally using the
	 * caller-declared root_font_size_px before the slope math runs.
	 */
	private static function generate_fluid_clamp_from_targets( $targets, $output_unit = 'px', $root_font_size_px = 16.0 ) {
		if ( ! is_array( $targets ) || 2 !== count( $targets ) ) {
			throw new \InvalidArgumentException( "targets must contain exactly 2 entries keyed by viewport width (e.g. {'320px':'20px','1920px':'60px'})." );
		}
		$points = [];
		foreach ( $targets as $viewport => $value_str ) {
			$w_px = self::parse_fluid_viewport( (string) $viewport );
			if ( null === $w_px ) {
				throw new \InvalidArgumentException( "Invalid viewport key '$viewport' — expected px (e.g. '320px')." );
			}
			$v = self::parse_size_with_unit( (string) $value_str );
			if ( null === $v ) {
				throw new \InvalidArgumentException( "Invalid target value '$value_str' — expected e.g. '20px' or '1.25rem'." );
			}
			$v_px = ( 'rem' === $v['unit'] ) ? $v['num'] * $root_font_size_px : $v['num'];
			$points[] = [ 'w' => $w_px, 'v' => $v_px ];
		}
		return self::build_fluid_clamp( $points[0]['w'], $points[0]['v'], $points[1]['w'], $points[1]['v'], $output_unit, $root_font_size_px );
	}

	/**
	 * Detect whether a fluid-clamp request involves rem units, either in
	 * inputs (min/max/targets values) or in an explicit output_unit="rem".
	 * Any rem emission bakes a root-font-size assumption into the vw slope,
	 * so we require the caller to explicitly acknowledge that assumption
	 * via output_unit or root_font_size_px.
	 */
	private static function fluid_request_has_rem_involvement( $min, $max, $targets, $output_unit ) {
		if ( 'rem' === $output_unit ) {
			return true;
		}
		$candidates = [];
		if ( is_string( $min ) ) {
			$candidates[] = $min;
		}
		if ( is_string( $max ) ) {
			$candidates[] = $max;
		}
		if ( is_array( $targets ) ) {
			foreach ( $targets as $v ) {
				if ( is_string( $v ) ) {
					$candidates[] = $v;
				}
			}
		}
		foreach ( $candidates as $c ) {
			if ( false !== stripos( $c, 'rem' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Create a single variable in the Variable Manager.
	 * Type "colors" writes to et_divi.et_global_data.global_colors.
	 * Other types write to et_divi_global_variables.
	 *
	 * Fluid clamp() generation (type=numbers only):
	 * - `min` + `max` (px or rem strings) → clamp using default anchors 320px/1920px
	 * - `targets` object (px viewport keys, px or rem values) → clamp using the two anchors
	 * - `output_unit` ("rem" | "px") and `root_font_size_px` (number) are optional overrides.
	 *   Rem inputs or rem output require explicit opt-in via one of these params: rem emission
	 *   bakes a root-font-size assumption into the vw slope, so the caller must either accept
	 *   the 1rem=16px default (pass output_unit="rem") or declare the site's actual root
	 *   (pass root_font_size_px).
	 * - Mutually exclusive with `value`.
	 */
	public static function create_variable( $request ) {
		$type              = sanitize_text_field( $request->get_param( 'type' ) );
		$label             = sanitize_text_field( $request->get_param( 'label' ) );
		$value             = $request->get_param( 'value' );
		$min               = $request->get_param( 'min' );
		$max               = $request->get_param( 'max' );
		$targets           = $request->get_param( 'targets' );
		$output_unit       = $request->get_param( 'output_unit' );
		$root_font_size_px = $request->get_param( 'root_font_size_px' );

		$valid_types = [ 'colors', 'numbers', 'strings', 'images', 'links', 'fonts' ];
		if ( ! in_array( $type, $valid_types, true ) ) {
			return new WP_Error( 'invalid_type', 'Type must be one of: ' . implode( ', ', $valid_types ), [ 'status' => 400 ] );
		}

		$has_shorthand = ( null !== $min || null !== $max );
		$has_targets   = ( null !== $targets && [] !== $targets );
		$has_fluid     = $has_shorthand || $has_targets;

		// output_unit — only meaningful alongside fluid params; validate enum.
		if ( null !== $output_unit ) {
			if ( ! $has_fluid ) {
				return new WP_Error( 'output_unit_without_fluid', 'output_unit is only meaningful alongside min/max/targets. Remove it or add fluid params.', [ 'status' => 400 ] );
			}
			if ( 'rem' !== $output_unit && 'px' !== $output_unit ) {
				return new WP_Error( 'invalid_output_unit', "output_unit must be 'rem' or 'px', got '$output_unit'.", [ 'status' => 400 ] );
			}
		}

		// root_font_size_px — caller-declared root font-size for correct
		// rem↔px conversion when the site deviates from the 16px default.
		if ( null !== $root_font_size_px ) {
			if ( ! $has_fluid ) {
				return new WP_Error( 'root_font_size_px_without_fluid', 'root_font_size_px is only meaningful alongside min/max/targets. Remove it or add fluid params.', [ 'status' => 400 ] );
			}
			if ( ! is_numeric( $root_font_size_px ) || (float) $root_font_size_px <= 0 ) {
				return new WP_Error( 'invalid_root_font_size_px', "root_font_size_px must be a positive number (px), got '$root_font_size_px'.", [ 'status' => 400 ] );
			}
			$root_font_size_px = (float) $root_font_size_px;
		}

		if ( $has_fluid ) {
			if ( 'numbers' !== $type ) {
				return new WP_Error( 'invalid_fluid_type', 'Fluid clamp generation (min/max/targets) is only valid for type="numbers".', [ 'status' => 400 ] );
			}
			if ( null !== $value && '' !== $value ) {
				return new WP_Error( 'fluid_value_conflict', 'Cannot pass both value and min/max/targets — use one input mode.', [ 'status' => 400 ] );
			}
			if ( $has_shorthand && $has_targets ) {
				return new WP_Error( 'fluid_input_conflict', 'Cannot pass both min/max shorthand and targets — use one input mode.', [ 'status' => 400 ] );
			}
			if ( $has_shorthand && ( null === $min || null === $max ) ) {
				return new WP_Error( 'fluid_incomplete_shorthand', 'Shorthand requires both min and max.', [ 'status' => 400 ] );
			}

			// Rem involvement — either rem appears in inputs, or output_unit="rem".
			// Either form bakes a root-font-size assumption into the vw slope, so
			// we require the caller to explicitly opt in via output_unit or
			// root_font_size_px. All-px requests bypass this gate (emission is
			// px-only, root-agnostic, no ceremony required).
			$rem_involved = self::fluid_request_has_rem_involvement( $min, $max, $targets, $output_unit );
			if ( $rem_involved && null === $output_unit && null === $root_font_size_px ) {
				return new WP_Error(
					'rem_requires_explicit_opt_in',
					"rem inputs or rem outputs require explicit opt-in — pass output_unit='rem' to accept the 1rem=16px default, or root_font_size_px:N to declare your site's actual root font-size (e.g. 10 for a 62.5% reset, 20 for a 20px root). rem emission bakes a root-font-size assumption into the vw slope; opt-in makes that assumption auditable.",
					[ 'status' => 400 ]
				);
			}

			// Resolve effective output_unit + root. Four cases:
			//   (1) all-px inputs, no override → output_unit='px', root=16 (unused)
			//   (2) explicit output_unit given → honor it (rem or px)
			//   (3) rem-involved inputs, only root_font_size_px given → implies rem output
			//   (4) all-px inputs but root_font_size_px given → implies rem output
			//       (caller declared a root purely to trigger rem emission; matches the
			//       tool schema's documented "root_font_size_px alone implies rem" contract)
			$effective_output_unit = $output_unit;
			if ( null === $effective_output_unit ) {
				$effective_output_unit = ( $rem_involved || null !== $root_font_size_px ) ? 'rem' : 'px';
			}
			$effective_root = ( null === $root_font_size_px ) ? 16.0 : $root_font_size_px;

			try {
				$value = $has_targets
					? self::generate_fluid_clamp_from_targets( $targets, $effective_output_unit, $effective_root )
					: self::generate_fluid_clamp_from_minmax( (string) $min, (string) $max, $effective_output_unit, $effective_root );
			} catch ( \Exception $e ) {
				return new WP_Error( 'fluid_generation_failed', $e->getMessage(), [ 'status' => 400 ] );
			}
		}

		if ( ! is_scalar( $value ) ) {
			return new WP_Error( 'invalid_value', 'value must be a scalar string (or supply min/max/targets for type=numbers).', [ 'status' => 400 ] );
		}
		$value = (string) $value;

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

			// Offset past Divi's customizer-bound colors — see
			// get_customizer_color_count() for rationale.
			$max_order = max( self::get_customizer_color_count(), $max_order );

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
	 * Validate a caller-supplied name_prefix against the gvid- ID charset.
	 *
	 * Generated IDs follow `gvid-{namespace}-{prefix}-{n}` or
	 * `gvid-{namespace}-size-{prefix}{n}`. Divi's ID resolution at
	 * `GlobalData.php:760` strips any chars outside [a-z0-9-_] silently —
	 * the variable is created in the registry but $variable() lookups fail
	 * to resolve at render time. Reject up front rather than letting the
	 * silent-render-failure ship.
	 *
	 * Mirrors the same charset our diviops_add_global_color enforces.
	 *
	 * @return string The validated, lowercased prefix, or $default if input is null/empty.
	 * @throws \InvalidArgumentException if the prefix contains disallowed chars.
	 */
	private static function validate_name_prefix( $input, $field_name, $default ) {
		if ( null === $input || '' === $input ) {
			return $default;
		}
		if ( ! is_string( $input ) ) {
			throw new \InvalidArgumentException( "$field_name must be a string." );
		}
		$lower = strtolower( $input );
		if ( ! preg_match( '/^[a-z0-9_-]+$/', $lower ) ) {
			throw new \InvalidArgumentException( sprintf(
				"%s '%s' contains characters outside [a-z0-9-_]. Divi's \$variable() resolver strips disallowed chars silently, so the generated IDs would be created in the registry but fail to resolve at render time. Use only [a-z0-9-_].",
				$field_name,
				$input
			) );
		}
		return $lower;
	}

	/**
	 * Named modular-scale ratios. Mirrors common typography scales so AI
	 * callers can pass a memorable name instead of looking up a magic number.
	 * Numeric ratios are accepted directly via the schema and bypass this map.
	 */
	private static function modular_scale_ratio( $name ) {
		$ratios = [
			'minor-second'     => 1.067,
			'major-second'     => 1.125,
			'minor-third'      => 1.2,
			'major-third'      => 1.25,
			'perfect-fourth'   => 1.333,
			'augmented-fourth' => 1.414,
			'perfect-fifth'    => 1.5,
			'golden'           => 1.618,
		];
		return $ratios[ $name ] ?? null;
	}

	/**
	 * Resolve the (min_viewport_px, max_viewport_px) pair for a profile.
	 * - "divi-default": 360/1350 — matches Divi 5.4.0's Variable Generator Modal defaults
	 *   (rowMaxWidthPx 1080 / rowWidthPercent 80% = 1350 outer viewport).
	 * - "wide": 320/1920 — diviops convention covering wider device span; matches the
	 *   default anchors used by `create_variable`'s shorthand form.
	 * - "custom": caller supplies anchors via the `custom_anchors` field.
	 */
	private static function resolve_fluid_anchors( $profile, $custom_anchors ) {
		switch ( $profile ) {
			case 'divi-default':
				return [ 360.0, 1350.0 ];
			case 'wide':
				return [ 320.0, 1920.0 ];
			case 'custom':
				if ( ! is_array( $custom_anchors ) ) {
					throw new \InvalidArgumentException( 'profile="custom" requires custom_anchors: {min_viewport_px, max_viewport_px}.' );
				}
				$min_vp = isset( $custom_anchors['min_viewport_px'] ) ? (float) $custom_anchors['min_viewport_px'] : 0.0;
				$max_vp = isset( $custom_anchors['max_viewport_px'] ) ? (float) $custom_anchors['max_viewport_px'] : 0.0;
				if ( $min_vp <= 0 || $max_vp <= 0 || $max_vp <= $min_vp ) {
					throw new \InvalidArgumentException( 'custom_anchors must provide positive min_viewport_px and max_viewport_px with max > min.' );
				}
				return [ $min_vp, $max_vp ];
			default:
				throw new \InvalidArgumentException( "Unknown profile '$profile' — expected 'divi-default', 'wide', or 'custom'." );
		}
	}

	/**
	 * Compute the typography modular-scale chain.
	 *
	 * Step N's value = `base_px × ratio^(steps-N)`, so step 1 = LARGEST size
	 * and step `steps` = base body size. Mirrors HTML heading conventions
	 * (h1 = largest).
	 *
	 * Fluid behavior is opt-in via `fluid_growth`:
	 *   - fluid_growth = 1.0 (default) → each step is a fixed value (discrete token)
	 *   - fluid_growth > 1.0          → step N fluid-scales from
	 *                                    base_px × ratio^(steps-N)        at min_viewport
	 *                                 to base_px × ratio^(steps-N) × fluid_growth at max_viewport
	 *
	 * `max_ratio` is also accepted (advanced): if set, large-viewport value uses
	 * `base_px × max_ratio^(steps-N) × fluid_growth` so the scale chain itself
	 * widens at the large anchor (matches ET's per-breakpoint ratio pattern).
	 *
	 * @return array<int, array{id:string, value:string, label:string}>
	 */
	private static function compute_typography_scale( $cfg, $anchors, $output_unit, $root_font_size_px, $namespace ) {
		$base_px       = isset( $cfg['base_px'] ) ? (float) $cfg['base_px'] : 16.0;
		$steps         = isset( $cfg['steps'] ) ? (int) $cfg['steps'] : 6;
		$fluid_growth  = isset( $cfg['fluid_growth'] ) ? (float) $cfg['fluid_growth'] : 1.0;
		$name_prefix   = self::validate_name_prefix(
			$cfg['name_prefix'] ?? null,
			'typography.name_prefix',
			'h'
		);

		if ( $base_px <= 0 ) {
			throw new \InvalidArgumentException( 'typography.base_px must be a positive number (px).' );
		}
		if ( $steps < 1 || $steps > 20 ) {
			throw new \InvalidArgumentException( 'typography.steps must be between 1 and 20.' );
		}
		if ( $fluid_growth <= 0 ) {
			throw new \InvalidArgumentException( 'typography.fluid_growth must be a positive number (e.g. 1.0 for non-fluid, 1.25 for moderate growth).' );
		}

		$ratio     = self::resolve_modular_ratio( $cfg['ratio'] ?? 1.25 );
		$max_ratio = isset( $cfg['max_ratio'] ) ? self::resolve_modular_ratio( $cfg['max_ratio'] ) : $ratio;

		[ $min_vp, $max_vp ] = $anchors;

		$out = [];
		for ( $n = 1; $n <= $steps; $n++ ) {
			// Reverse step indexing so h1 = largest, hN = base.
			$exponent = $steps - $n;
			$small_px = $base_px * pow( $ratio, $exponent );
			$large_px = $base_px * pow( $max_ratio, $exponent ) * $fluid_growth;

			$value = self::build_fluid_clamp( $min_vp, $small_px, $max_vp, $large_px, $output_unit, $root_font_size_px );
			$id    = sprintf( 'gvid-%s-size-%s%d', $namespace, $name_prefix, $n );
			$out[] = [
				'id'    => $id,
				'value' => $value,
				'label' => sprintf( 'Size %s%d', strtoupper( $name_prefix ), $n ),
			];
		}
		return $out;
	}

	/**
	 * Resolve a ratio input — accepts a positive number or a named scale.
	 */
	private static function resolve_modular_ratio( $ratio_input ) {
		if ( is_numeric( $ratio_input ) ) {
			$r = (float) $ratio_input;
			if ( $r <= 0 ) {
				throw new \InvalidArgumentException( 'Modular ratio must be a positive number.' );
			}
			return $r;
		}
		if ( is_string( $ratio_input ) ) {
			$resolved = self::modular_scale_ratio( $ratio_input );
			if ( null !== $resolved ) {
				return $resolved;
			}
			throw new \InvalidArgumentException( "Unknown modular ratio name '$ratio_input'. Pass a number or one of: minor-second, major-second, minor-third, major-third, perfect-fourth, augmented-fourth, perfect-fifth, golden." );
		}
		throw new \InvalidArgumentException( 'Modular ratio must be a number or a named scale.' );
	}

	/**
	 * Compute a spacing/radius scale.
	 *
	 * Each step's "small" value lives on the chosen scale (linear or
	 * geometric) between min_px and max_px. Default behavior is discrete
	 * (each step emits a fixed value, not a clamp) — that matches how
	 * spacing/radius tokens are typically used in design systems.
	 *
	 * Fluid behavior is opt-in via `fluid_growth`:
	 *   - fluid_growth = 1.0 (default) → discrete: step N value is constant across viewports
	 *   - fluid_growth > 1.0          → step N value scales from `small` at min_viewport
	 *                                    to `small × fluid_growth` at max_viewport
	 *
	 * - `linear`: equal arithmetic spacing (min, ..., max) — best for spacing.
	 * - `geometric`: equal multiplicative spacing — best for radius/typography-like scales.
	 *
	 * @return array<int, array{id:string, value:string, label:string}>
	 */
	private static function compute_size_scale( $cfg, $bucket, $default_prefix, $anchors, $output_unit, $root_font_size_px, $namespace ) {
		$min_px       = isset( $cfg['min_px'] ) ? (float) $cfg['min_px'] : 0.0;
		$max_px       = isset( $cfg['max_px'] ) ? (float) $cfg['max_px'] : 0.0;
		$steps        = isset( $cfg['steps'] ) ? (int) $cfg['steps'] : 6;
		$scale        = isset( $cfg['scale'] ) ? (string) $cfg['scale'] : 'linear';
		$fluid_growth = isset( $cfg['fluid_growth'] ) ? (float) $cfg['fluid_growth'] : 1.0;
		$name_prefix  = self::validate_name_prefix(
			$cfg['name_prefix'] ?? null,
			"$bucket.name_prefix",
			$default_prefix
		);

		if ( $min_px < 0 || $max_px <= 0 ) {
			throw new \InvalidArgumentException( "$bucket.min_px must be ≥ 0 and $bucket.max_px must be > 0." );
		}
		if ( $max_px < $min_px ) {
			throw new \InvalidArgumentException( "$bucket.max_px must be ≥ $bucket.min_px." );
		}
		if ( $steps < 1 || $steps > 30 ) {
			throw new \InvalidArgumentException( "$bucket.steps must be between 1 and 30." );
		}
		if ( ! in_array( $scale, [ 'linear', 'geometric' ], true ) ) {
			throw new \InvalidArgumentException( "$bucket.scale must be 'linear' or 'geometric'." );
		}
		if ( 'geometric' === $scale && $min_px <= 0 ) {
			throw new \InvalidArgumentException( "$bucket.scale='geometric' requires min_px > 0 (geometric step from 0 is undefined)." );
		}
		if ( $fluid_growth <= 0 ) {
			throw new \InvalidArgumentException( "$bucket.fluid_growth must be a positive number (1.0 = discrete, > 1 = fluid)." );
		}

		[ $min_vp, $max_vp ] = $anchors;

		$out = [];
		for ( $n = 1; $n <= $steps; $n++ ) {
			if ( 1 === $steps ) {
				// Single step: the "scale" doesn't really apply — emit a clamp
				// that goes min→max across the viewport (most useful single-step
				// shape). fluid_growth is ignored in this case.
				$small_v = $min_px;
				$large_v = $max_px;
			} else {
				$t = ( $n - 1 ) / ( $steps - 1 );
				if ( 'linear' === $scale ) {
					$small_v = $min_px + $t * ( $max_px - $min_px );
				} else {
					$small_v = $min_px * pow( $max_px / $min_px, $t );
				}
				$large_v = $small_v * $fluid_growth;
			}

			$value = self::build_fluid_clamp( $min_vp, $small_v, $max_vp, $large_v, $output_unit, $root_font_size_px );
			$id    = sprintf( 'gvid-%s-%s-%d', $namespace, $name_prefix, $n );
			$out[] = [
				'id'    => $id,
				'value' => $value,
				'label' => sprintf( '%s %d', ucfirst( $name_prefix ), $n ),
			];
		}
		return $out;
	}

	/**
	 * Batch generator: emit a fluid typography + spacing + radius set in one call.
	 *
	 * Mirrors ET 5.4.0's Variable Generator Modal at the algorithm level
	 * (clamp() math is identical via build_fluid_clamp) but layers profile-
	 * selectable anchors over it: divi-default (360/1350) matches ET's defaults,
	 * wide (320/1920) matches the diviops convention, custom takes explicit
	 * anchors. Each category is independent and optional.
	 *
	 * Response shape (consistent across dry_run and persist modes):
	 *   - `created`: entries that would be (or were) written. With overwrite=false,
	 *     this contains only NEW entries; existing IDs land in `skipped` instead.
	 *     With overwrite=true, every plan entry lands here (`overwrote` flag
	 *     distinguishes update vs create).
	 *   - `skipped`: existing IDs not written this call (overwrite=false only).
	 *
	 * To audit the FULL computed plan (every entry regardless of existing IDs),
	 * call with overwrite=true + dry_run=true — that returns each generated
	 * entry under `created` with `overwrote: true|false` flagging which would
	 * be updates vs new writes. This is the recommended preflight pattern.
	 *
	 * Persistence is a single write to `et_divi_global_variables` so an invalid
	 * mid-batch step rolls back cleanly (no half-written registry).
	 */
	public static function create_fluid_system( $request ) {
		$profile           = sanitize_text_field( $request->get_param( 'profile' ) ?: 'divi-default' );
		$custom_anchors    = $request->get_param( 'custom_anchors' );
		$typography        = $request->get_param( 'typography' );
		$spacing           = $request->get_param( 'spacing' );
		$radius            = $request->get_param( 'radius' );
		$namespace_raw     = $request->get_param( 'namespace' );
		$output_unit       = $request->get_param( 'output_unit' );
		$root_font_size_px = $request->get_param( 'root_font_size_px' );
		$dry_run           = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false );
		$overwrite         = rest_sanitize_boolean( $request->get_param( 'overwrite' ) ?? false );

		// Namespace validation mirrors validate_name_prefix(): explicit reject
		// instead of sanitize_key()'s silent strip. sanitize_key() rewriting
		// "oa!" or "o a" to "oa" would alias bogus input onto the default
		// namespace; with overwrite=true that means silently rewriting the
		// WRONG token set. The reject path keeps the failure loud.
		try {
			$namespace = self::validate_name_prefix(
				( null === $namespace_raw || '' === $namespace_raw ) ? 'oa' : $namespace_raw,
				'namespace',
				'oa'
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'invalid_namespace', $e->getMessage(), [ 'status' => 400 ] );
		}

		// At least one category must be present.
		if ( ! is_array( $typography ) && ! is_array( $spacing ) && ! is_array( $radius ) ) {
			return new WP_Error( 'no_categories', 'At least one of typography/spacing/radius must be provided.', [ 'status' => 400 ] );
		}

		// Validate output_unit + root_font_size_px (same opt-in rules as create_variable).
		if ( null !== $output_unit && 'rem' !== $output_unit && 'px' !== $output_unit ) {
			return new WP_Error( 'invalid_output_unit', "output_unit must be 'rem' or 'px'.", [ 'status' => 400 ] );
		}
		if ( null !== $root_font_size_px ) {
			if ( ! is_numeric( $root_font_size_px ) || (float) $root_font_size_px <= 0 ) {
				return new WP_Error( 'invalid_root_font_size_px', 'root_font_size_px must be a positive number (px).', [ 'status' => 400 ] );
			}
			$root_font_size_px = (float) $root_font_size_px;
		}
		// rem emission requires explicit opt-in via output_unit='rem' or root_font_size_px.
		// Inputs to this batch tool are all numeric px (base_px / min_px / max_px) so the
		// rem-in-input gate doesn't apply here, but rem OUTPUT still bakes a root assumption.
		$effective_output_unit = $output_unit ?? ( null !== $root_font_size_px ? 'rem' : 'px' );
		$effective_root        = ( null === $root_font_size_px ) ? 16.0 : $root_font_size_px;

		// Resolve anchors.
		try {
			$anchors = self::resolve_fluid_anchors( $profile, $custom_anchors );
		} catch ( \Exception $e ) {
			return new WP_Error( 'invalid_profile', $e->getMessage(), [ 'status' => 400 ] );
		}

		// Compute each requested category.
		$plan = [];
		try {
			if ( is_array( $typography ) ) {
				$plan = array_merge( $plan, self::compute_typography_scale( $typography, $anchors, $effective_output_unit, $effective_root, $namespace ) );
			}
			if ( is_array( $spacing ) ) {
				$plan = array_merge( $plan, self::compute_size_scale( $spacing, 'spacing', 'space', $anchors, $effective_output_unit, $effective_root, $namespace ) );
			}
			if ( is_array( $radius ) ) {
				$plan = array_merge( $plan, self::compute_size_scale( $radius, 'radius', 'rounded', $anchors, $effective_output_unit, $effective_root, $namespace ) );
			}
		} catch ( \Exception $e ) {
			return new WP_Error( 'fluid_system_generation_failed', $e->getMessage(), [ 'status' => 400 ] );
		}

		// Ensure the plan has no internal ID collisions (e.g. typography
		// name_prefix overlapping with spacing name_prefix at the same step).
		$seen = [];
		foreach ( $plan as $entry ) {
			if ( isset( $seen[ $entry['id'] ] ) ) {
				return new WP_Error(
					'plan_id_collision',
					sprintf( "Two generated entries share ID '%s'. Adjust name_prefix in one of typography/spacing/radius to disambiguate.", $entry['id'] ),
					[ 'status' => 400 ]
				);
			}
			$seen[ $entry['id'] ] = true;
		}

		// Inspect existing registry to compute skipped vs to-create.
		$vars = get_option( 'et_divi_global_variables', [] );
		if ( ! is_array( $vars ) ) {
			$vars = [];
		}
		if ( ! is_array( $vars['numbers'] ?? null ) ) {
			$vars['numbers'] = [];
		}

		$created      = [];
		$skipped      = [];
		$max_order    = 0;
		if ( ! empty( $vars['numbers'] ) ) {
			$orders = array_column( $vars['numbers'], 'order' );
			if ( ! empty( $orders ) ) {
				$max_order = max( array_map( 'intval', $orders ) );
			}
		}

		foreach ( $plan as $entry ) {
			$id    = $entry['id'];
			$value = $entry['value'];
			$label = $entry['label'];

			$exists = isset( $vars['numbers'][ $id ] );
			if ( $exists && ! $overwrite ) {
				$skipped[] = [
					'id'     => $id,
					'reason' => 'exists',
					'value'  => $vars['numbers'][ $id ]['value'] ?? null,
				];
				continue;
			}

			// Preserve order on overwrite; assign a fresh order on create.
			$order = $exists
				? (int) ( $vars['numbers'][ $id ]['order'] ?? ++$max_order )
				: ++$max_order;

			$vars['numbers'][ $id ] = [
				'id'          => $id,
				'label'       => $label,
				'value'       => $value,
				'order'       => $order,
				'status'      => 'active',
				'lastUpdated' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
				'type'        => 'numbers',
			];
			$created[] = [
				'id'         => $id,
				'value'      => $value,
				'label'      => $label,
				'overwrote'  => $exists,
			];
		}

		if ( ! $dry_run && ! empty( $created ) ) {
			update_option( 'et_divi_global_variables', $vars );
		}

		return rest_ensure_response( [
			'success'      => true,
			'profile'      => $profile,
			'anchors'      => [ 'min_viewport_px' => $anchors[0], 'max_viewport_px' => $anchors[1] ],
			'output_unit'  => $effective_output_unit,
			'dry_run'      => $dry_run,
			'created'      => $created,
			'skipped'      => $skipped,
			'created_count' => count( $created ),
			'skipped_count' => count( $skipped ),
		] );
	}

	/**
	 * Delete a variable by ID. Auto-detects storage location from ID prefix.
	 *
	 * Reference-safety: by default, refuses to delete when live references
	 * exist (returns HTTP 409 with the reference locations). Pass `force=true`
	 * to delete anyway — callers that do so are responsible for orphan cleanup
	 * (run `variables_scan_orphans` afterwards). This prevents the silent-orphan
	 * class of bug where a delete leaves dangling `$variable(...)$` tokens in
	 * page/preset content that render as invalid CSS on the frontend.
	 */
	public static function delete_variable( $request ) {
		$id    = sanitize_text_field( $request->get_param( 'id' ) );
		$force = rest_sanitize_boolean( $request->get_param( 'force' ) ?? false );

		// Customizer-bound defaults (gcid-primary-color / gcid-link-color / etc.)
		// resolve via GlobalData::$customizer_colors and are bound to theme
		// options — not deletable via this endpoint. Reject with an explicit
		// 403 rather than letting the downstream 404 misrepresent this as
		// "variable doesn't exist" (it does; it just isn't under this tool's
		// jurisdiction).
		if ( class_exists( '\ET\Builder\Packages\GlobalData\GlobalData' ) ) {
			$customizer = \ET\Builder\Packages\GlobalData\GlobalData::$customizer_colors ?? [];
			if ( isset( $customizer[ $id ] ) ) {
				return new WP_Error(
					'forbidden',
					"Variable '$id' is a Divi customizer-bound default and cannot be deleted via this endpoint — it's managed through WP Customizer theme options.",
					[ 'status' => 403 ]
				);
			}
		}

		// Resolve storage bucket via prefix. Both lookups are O(1) array reads
		// against already-in-memory options — cheap to do first so a typo'd
		// id returns 404 without paying for a full-site parse_blocks scan.
		$is_color = 0 === strpos( $id, 'gcid-' );

		if ( $is_color ) {
			$raw         = et_get_option( 'et_global_data' );
			$global_data = ! empty( $raw ) ? maybe_unserialize( $raw ) : [];
			$colors      = is_array( $global_data ) && is_array( $global_data['global_colors'] ?? null )
				? $global_data['global_colors']
				: [];
			if ( ! isset( $colors[ $id ] ) ) {
				return new WP_Error( 'not_found', "Variable '$id' not found", [ 'status' => 404 ] );
			}
		} else {
			$vars = get_option( 'et_divi_global_variables', [] );
			if ( ! is_array( $vars ) ) {
				return new WP_Error( 'not_found', "Variable '$id' not found", [ 'status' => 404 ] );
			}
			$found_type = null;
			foreach ( [ 'numbers', 'strings', 'images', 'links', 'fonts' ] as $type ) {
				if ( is_array( $vars[ $type ] ?? null ) && isset( $vars[ $type ][ $id ] ) ) {
					$found_type = $type;
					break;
				}
			}
			if ( null === $found_type ) {
				return new WP_Error( 'not_found', "Variable '$id' not found", [ 'status' => 404 ] );
			}
		}

		// Two-tier ref check to keep the normal-case delete fast:
		//   1. Cheap SQL LIKE + preset-option substring scan — O(few ms)
		//      regardless of site size. Negative hit = definitely no refs,
		//      skip the full scan entirely (the common path for a caller
		//      who just ran variables_scan_orphans and is cleaning up).
		//   2. Positive hit falls through to collect_variable_refs() so the
		//      409 body carries accurate per-location records. The expensive
		//      scan only runs when we're genuinely blocking a live delete.
		if ( ! $force && self::variable_id_appears_anywhere( $id ) ) {
			$refs = self::collect_variable_refs();
			if ( isset( $refs['all_ids'][ $id ] ) ) {
				return new WP_Error(
					'has_references',
					sprintf(
						"Variable '%s' has %d live reference(s). Pass force=true to delete anyway; orphans will remain — run variables_scan_orphans to audit them afterwards.",
						$id,
						$refs['all_ids'][ $id ]
					),
					[
						'status'    => 409,
						'id'        => $id,
						'ref_count' => $refs['all_ids'][ $id ],
						'locations' => $refs['locations'][ $id ] ?? [],
					]
				);
			}
		}

		if ( $is_color ) {
			unset( $colors[ $id ] );
			$global_data['global_colors'] = $colors;
			et_update_option( 'et_global_data', $global_data );
		} else {
			unset( $vars[ $found_type ][ $id ] );
			update_option( 'et_divi_global_variables', $vars );
		}

		return rest_ensure_response( [ 'success' => true, 'deleted' => $id, 'forced' => $force ] );
	}

	/**
	 * Scan content surfaces for stale variable references + unused definitions.
	 *
	 * Orphans = ids referenced in pages / TB layouts / preset registry with no
	 * matching entry in the Variable Manager. Render as invalid CSS on the
	 * frontend (the `$variable()$` resolver falls through with no fallback),
	 * often only noticed via visual breakage.
	 *
	 * Unused = ids defined in the Variable Manager but referenced nowhere —
	 * deletion candidates; returned alongside orphans so one audit pass
	 * surfaces both hygiene signals.
	 *
	 * Shape mirrors preset_scan_orphans for consistency (orphan_count /
	 * orphans / per-ref location records).
	 */
	public static function variables_scan_orphans( $request ) {
		unset( $request );
		$refs    = self::collect_variable_refs();
		$defined = self::get_defined_variable_ids();

		$orphans          = [];
		$unused_variables = [];

		foreach ( $refs['all_ids'] as $id => $count ) {
			if ( isset( $defined[ $id ] ) ) {
				continue;
			}
			$orphans[] = [
				'id'        => $id,
				'ref_count' => $count,
				'locations' => $refs['locations'][ $id ] ?? [],
			];
		}

		foreach ( $defined as $id => $info ) {
			if ( isset( $refs['all_ids'][ $id ] ) ) {
				continue;
			}
			// Customizer-bound colors are defined (they resolve via theme
			// options) but not deletable via delete_variable, so they aren't
			// "deletion candidates" — skip them out of unused_variables.
			if ( 'customizer' === ( $info['source'] ?? '' ) ) {
				continue;
			}
			unset( $info['source'] ); // internal tag, don't leak to the response
			$unused_variables[] = array_merge( [ 'id' => $id ], $info );
		}

		$response = [
			'orphan_count'            => count( $orphans ),
			'unused_count'            => count( $unused_variables ),
			'total_unique_referenced' => count( $refs['all_ids'] ),
			'total_reference_count'   => array_sum( $refs['all_ids'] ),
			'total_in_registry'       => count( $defined ),
			'scanned_posts'           => $refs['scanned_posts'],
			'orphans'                 => $orphans,
			'unused_variables'        => $unused_variables,
		];

		// Surface scan-truncation only when it happens — keeps the response
		// clean on the normal case, and loud when the cap actually bit.
		if ( $refs['scan_truncated'] ) {
			$response['scan_truncated']         = true;
			$response['scan_truncation_limit']  = self::VARIABLES_SCAN_MAX_POSTS;
			$response['warning']                = sprintf(
				'Scanned the first %d posts only — site has more Divi content than the safety cap. Orphan list may be incomplete.',
				self::VARIABLES_SCAN_MAX_POSTS
			);
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Detect numeric/font variable IDs (gvid-*) the page actually emits.
	 *
	 * Mirrors the same content-stack assembly Divi performs at frontend render
	 * (FrontEnd.php:628-675) so the result matches the variable IDs Divi 5.4.0+
	 * uses to scope selective `:root{--gvid-*}` emission via
	 * `Style::get_global_numeric_and_fonts_vars_style($ids)`:
	 *
	 *   1. The post's own `post_content`
	 *   2. Theme Builder header/body/footer template content active for that post
	 *   3. Appended (above/below) canvas content for the post and each TB template
	 *   4. Interaction-target canvas content discovered in the assembled stack
	 *   5. Canvas-portal-referenced canvases (recursive, with the same
	 *      10-iteration safety cap Divi's frontend uses)
	 *   6. Presets referenced by the above (resolved via Divi's preset chain)
	 *
	 * The TB-template resolution uses `ET_Theme_Builder_Request::from_post( $post_id )`
	 * rather than the global-query-bound `from_current()`, so the answer is
	 * accurate from a REST request without simulating the singular query state.
	 *
	 * Canvas content uses the public OffCanvasHooks per-owner helpers
	 * (`get_canvas_content_for_appended`, `extract_interaction_target_ids_from_content`,
	 * `get_canvas_content_for_targets`, `get_canvas_content_for_canvas_portals`)
	 * instead of the convenience wrapper
	 * `get_all_appended_canvas_content_for_post_and_templates()`. The wrapper's
	 * inner helper bails on REST requests via `DynamicAssetsUtils::is_dynamic_front_end_request()`
	 * (REST_REQUEST + wp_is_json_request are explicit gates), which would
	 * silently drop canvas content from the scan and miss any gvid- IDs only
	 * referenced from canvas modules. The per-owner helpers have no REST gate.
	 *
	 * Canvas-portal IDs are extracted directly from the assembled stack with
	 * `DynamicAssetsUtils::extract_canvas_portal_canvas_ids_from_content()`
	 * because the same util's cached `canvas_portal_ids` field is also gated
	 * on `is_cacheable_request` (DynamicAssetsUtils.php:2736-2772) and would
	 * be empty in REST.
	 *
	 * NOTE: gvid-* only. Color variables (gcid-*) are emitted via a separate
	 * `GlobalData` color-block path that is NOT scoped per-page in 5.4.0, so
	 * `DetectFeature::get_page_global_variable_ids()` does not surface them and
	 * neither does this endpoint. Use `diviops_variables_scan_orphans` for
	 * site-wide gcid- coverage.
	 */
	public static function variables_used_on_page( $request ) {
		$post_id = (int) $request['id'];
		if ( $post_id <= 0 ) {
			return new WP_Error(
				'diviops_invalid_post_id',
				'post_id must be a positive integer.',
				[ 'status' => 400 ]
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'diviops_post_not_found',
				sprintf( 'Post %d not found.', $post_id ),
				[ 'status' => 404 ]
			);
		}

		if ( ! class_exists( '\\ET\\Builder\\FrontEnd\\Assets\\DetectFeature' ) ) {
			return new WP_Error(
				'diviops_divi_not_active',
				'Divi 5 (with DetectFeature class) is required for this endpoint.',
				[ 'status' => 500 ]
			);
		}

		// Build the combined main content: post_content + each TB template's
		// post_content, space-joined. This matches `FrontEnd.php:640-653`
		// exactly and is the same string Divi passes as `$main_content` to
		// `get_all_appended_canvas_content_for_post_and_templates()` at line 658.
		// Critically, this combined string — not a per-owner one — is what
		// every owner needs so interaction-target discovery is identical to
		// the frontend, and so the canvas-data static cache gets seeded
		// correctly (see the cache-seed call below).
		$post_main_content = is_string( $post->post_content ) ? $post->post_content : '';
		$content_stack     = $post_main_content;
		$combined_main     = $post_main_content;

		// Active TB header/body/footer templates for this specific post.
		$tb_template_ids = [];
		if ( class_exists( 'ET_Theme_Builder_Request' ) && function_exists( 'et_theme_builder_get_template_layouts' ) ) {
			$tb_request = \ET_Theme_Builder_Request::from_post( $post_id );
			$tb_layouts = et_theme_builder_get_template_layouts( $tb_request );

			$layout_post_types = [
				defined( 'ET_THEME_BUILDER_HEADER_LAYOUT_POST_TYPE' ) ? ET_THEME_BUILDER_HEADER_LAYOUT_POST_TYPE : null,
				defined( 'ET_THEME_BUILDER_BODY_LAYOUT_POST_TYPE' )   ? ET_THEME_BUILDER_BODY_LAYOUT_POST_TYPE   : null,
				defined( 'ET_THEME_BUILDER_FOOTER_LAYOUT_POST_TYPE' ) ? ET_THEME_BUILDER_FOOTER_LAYOUT_POST_TYPE : null,
			];

			foreach ( $layout_post_types as $layout_post_type ) {
				if ( null === $layout_post_type || empty( $tb_layouts[ $layout_post_type ] ) ) {
					continue;
				}
				$layout = $tb_layouts[ $layout_post_type ];
				if ( empty( $layout['override'] ) || empty( $layout['enabled'] ) ) {
					continue;
				}
				$layout_id = (int) ( $layout['id'] ?? 0 );
				if ( $layout_id <= 0 ) {
					continue;
				}
				$tb_template_ids[] = $layout_id;
				$tb_post           = get_post( $layout_id );
				if ( $tb_post instanceof WP_Post && ! empty( $tb_post->post_content ) ) {
					$content_stack .= ' ' . $tb_post->post_content;
					$combined_main .= ' ' . $tb_post->post_content;
				}
			}
		}

		// Appended + interaction-target + canvas-portal content for the post
		// and its TB templates. We can't use the convenience wrapper
		// `get_all_appended_canvas_content_for_post_and_templates()` here
		// because its inner helper (`get_all_appended_canvas_content`) bails
		// out on REST requests via `DynamicAssetsUtils::is_dynamic_front_end_request()`
		// (REST_REQUEST + wp_is_json_request are explicit gates), so canvas
		// content would silently never appear in the scan.
		//
		// Assemble it directly from the public OffCanvasHooks helpers, which
		// have no REST gating.
		//
		// CRITICAL: seed `DynamicAssetsUtils::get_all_canvas_data_for_post($owner_id, $combined_main)`
		// per owner BEFORE any other canvas helper runs for that owner.
		// `get_canvas_content_for_appended()` internally calls
		// `get_all_canvas_data_for_post($owner_id)` with an empty main_content
		// (OffCanvasHooks.php:2892), which would write the static cache
		// (keyed both by content-hash and by base "post_id_md5('')") with
		// `interaction_targets => []` — empty seed, no targets discoverable.
		// `get_canvas_content_for_targets()` later reads the same base key
		// (it also passes empty main_content) and would find no targets.
		// Seeding first with the same combined main Divi uses populates the
		// cache with `interaction_targets` so the later targets call works.
		// See DynamicAssetsUtils.php:2937-2965 (interaction_targets build) and
		// :2990-2995 (dual-key cache write).
		//
		// Canvas portal IDs need to be extracted ourselves: that same util's
		// `canvas_portal_ids` field is also gated behind `is_cacheable_request`
		// (DynamicAssetsUtils.php:2736-2772), so the cached `canvas_data`
		// returns an empty array for that field in REST. We walk the combined
		// main content with `extract_canvas_portal_canvas_ids_from_content()`
		// + recursive expansion via `get_canvas_content_for_canvas_portals()`,
		// matching the full pipeline at OffCanvasHooks.php:3004-3047 (incl.
		// the 10-iteration safety cap for nested portals).
		//
		// Interaction targets are extracted from `$combined_main` (matching
		// what Divi passes at OffCanvasHooks.php:3070/3087 — same combined
		// string for every owner) and filtered through
		// `canvas_block_content_contains_target` to drop targets already
		// satisfied on the main canvas (matches OffCanvasHooks.php:2980-3002).
		if ( class_exists( '\\ET\\Builder\\VisualBuilder\\OffCanvas\\OffCanvasHooks' ) ) {
			$canvas_owner_ids = array_values( array_unique( array_merge( [ $post_id ], $tb_template_ids ) ) );

			// Pre-extract + filter target IDs once — Divi passes the same
			// combined main to every owner, so the filtered set is identical
			// across the loop. Cheap to compute: extract is short-circuited
			// by `DetectFeature::has_interactions_enabled` and the filter is
			// two str_contains + a regex per target.
			$shared_filtered_target_ids = [];
			$shared_target_ids          = \ET\Builder\VisualBuilder\OffCanvas\OffCanvasHooks::extract_interaction_target_ids_from_content( $combined_main );
			if ( ! empty( $shared_target_ids ) ) {
				foreach ( $shared_target_ids as $target_id ) {
					if ( ! \ET\Builder\VisualBuilder\OffCanvas\OffCanvasHooks::canvas_block_content_contains_target( $combined_main, $target_id ) ) {
						$shared_filtered_target_ids[] = $target_id;
					}
				}
			}

			// Pre-seed the portal IDs that come from the combined main content
			// (matches Divi's `canvas_data['canvas_portal_ids']` which is built
			// from main + TB content at DynamicAssetsUtils.php:2749-2771). Same
			// for every owner since the main is the same.
			$shared_portal_ids_from_main = [];
			if ( str_contains( $combined_main, 'canvas-portal' ) ) {
				$shared_portal_ids_from_main = \ET\Builder\FrontEnd\Assets\DynamicAssetsUtils::extract_canvas_portal_canvas_ids_from_content( $combined_main );
			}

			foreach ( $canvas_owner_ids as $owner_id ) {
				// Seed the static canvas-data cache for this owner with the
				// combined main content so interaction_targets is populated
				// before any helper call below reads the base cache key.
				\ET\Builder\FrontEnd\Assets\DynamicAssetsUtils::get_all_canvas_data_for_post( $owner_id, $combined_main );

				// Per-owner local buffer — Divi's `get_all_appended_canvas_content`
				// uses a fresh `$all_canvas_content` per owner (line 2969) and
				// expands portals only from THAT buffer + the canvas_data's main-
				// derived portal IDs, calling `get_canvas_content_for_canvas_portals(
				// $ids, $owner_id)` against THIS owner's $post_id (line 3033).
				// Sharing portal-ID extraction across owners via the global
				// $content_stack would resolve a same-named portal ID against the
				// wrong owner and over-include canvases the frontend would not
				// fetch. Keep portal expansion strictly per-owner here.
				$owner_canvas_content = '';

				// Explicitly appended canvases (above/below).
				$appended = \ET\Builder\VisualBuilder\OffCanvas\OffCanvasHooks::get_canvas_content_for_appended( $owner_id );
				if ( ! empty( $appended ) ) {
					$owner_canvas_content .= $appended;
				}

				// Interaction-targeted canvases — same filtered set per owner.
				if ( ! empty( $shared_filtered_target_ids ) ) {
					$interaction = \ET\Builder\VisualBuilder\OffCanvas\OffCanvasHooks::get_canvas_content_for_targets( $shared_filtered_target_ids, $owner_id );
					if ( ! empty( $interaction ) ) {
						$owner_canvas_content .= $interaction;
					}
				}

				// Canvas-portal expansion (recursive, capped). Seed from the
				// shared main-derived IDs + portals discovered inside this
				// OWNER's local appended/interaction buffer — matches the
				// merge at OffCanvasHooks.php:3009-3017.
				$portal_ids_from_owner_buffer = [];
				if ( '' !== $owner_canvas_content && str_contains( $owner_canvas_content, 'canvas-portal' ) ) {
					$portal_ids_from_owner_buffer = \ET\Builder\FrontEnd\Assets\DynamicAssetsUtils::extract_canvas_portal_canvas_ids_from_content( $owner_canvas_content );
				}

				$portal_ids_to_expand = array_values( array_unique( array_merge( $shared_portal_ids_from_main, $portal_ids_from_owner_buffer ) ) );

				if ( ! empty( $portal_ids_to_expand ) ) {
					$expanded_portal_ids = [];
					$iteration_limit     = 10;
					$iteration           = 0;

					while ( $iteration < $iteration_limit ) {
						$portal_ids_to_process = array_values( array_diff( $portal_ids_to_expand, $expanded_portal_ids ) );
						if ( empty( $portal_ids_to_process ) ) {
							break;
						}

						++$iteration;
						$expanded_portal_ids = array_merge( $expanded_portal_ids, $portal_ids_to_process );

						$portal_content = \ET\Builder\VisualBuilder\OffCanvas\OffCanvasHooks::get_canvas_content_for_canvas_portals( $portal_ids_to_process, $owner_id );
						if ( empty( $portal_content ) ) {
							continue;
						}

						$owner_canvas_content .= $portal_content;

						if ( str_contains( $portal_content, 'canvas-portal' ) ) {
							$nested_portal_ids = \ET\Builder\FrontEnd\Assets\DynamicAssetsUtils::extract_canvas_portal_canvas_ids_from_content( $portal_content );
							if ( ! empty( $nested_portal_ids ) ) {
								$portal_ids_to_expand = array_unique( array_merge( $portal_ids_to_expand, $nested_portal_ids ) );
							}
						}
					}
				}

				// Merge this owner's collected canvas content into the global
				// stack for variable detection. The per-owner isolation only
				// applies to canvas content discovery (where $owner_id matters);
				// gvid- token detection runs against the union.
				if ( '' !== $owner_canvas_content ) {
					$content_stack .= ' ' . $owner_canvas_content;
				}
			}
		}

		$variable_ids = '' !== $content_stack
			? \ET\Builder\FrontEnd\Assets\DetectFeature::get_page_global_variable_ids( $content_stack )
			: [];

		// Sort so callers get a stable order (frontend cares about uniqueness, not order).
		sort( $variable_ids );

		return rest_ensure_response( [
			'post_id'         => $post_id,
			'variable_ids'    => $variable_ids,
			'count'           => count( $variable_ids ),
			'tb_template_ids' => $tb_template_ids,
		] );
	}

	/**
	 * Flush Divi's compiled static CSS cache.
	 *
	 * Divi writes compiled CSS to disk under wp-content/et-cache/; wp cache
	 * flush does NOT touch these files, so the frontend can keep serving
	 * stale CSS after a preset/variable/module mutation until the cache is
	 * cleared. This endpoint surfaces Divi's own clearer as an explicit tool.
	 *
	 * Exactly one selector is required — no default to 'all' to avoid an
	 * accidental site-wide flush:
	 *   - post_id: int > 0     — flush cache for one post
	 *   - all:     true        — flush every cached file
	 *   - after:   unix ts > 0 — flush cache for posts whose et-cache/{id}/
	 *                            dir has mtime > ts (iterated per-post)
	 *
	 * Backend selection:
	 *   - Prefers Divi's native ET_Core_PageResource::remove_static_resources
	 *     when available. Native mode additionally clears Theme Builder CSS
	 *     scattered across other post dirs, archive / taxonomy / home /
	 *     notfound CSS, object cache, module features cache, post features
	 *     cache, dynamic assets cache, Google Fonts cache, and post meta
	 *     caches. Scope is significantly broader than the numeric subdir
	 *     filesystem walk.
	 *   - Falls back to a targeted filesystem walk of et-cache/{post_id}/
	 *     when the Divi class is absent (Divi inactive, stripped builds).
	 *     Only numeric-named subdirs are touched in fallback mode —
	 *     siblings (.cache-cleared-at, global/, en_US/, notfound/, *.data)
	 *     are never removed.
	 *
	 * Idempotency:
	 *   - Missing cache root returns 200 with empty flushed list.
	 *   - Unwritable cache root returns 500 so callers don't silently no-op.
	 */
	public static function flush_static_cache( $request ) {
		$post_id_raw  = $request->get_param( 'post_id' );
		$has_post_id  = null !== $post_id_raw;
		$all          = rest_sanitize_boolean( $request->get_param( 'all' ) ?? false );
		$after_raw    = $request->get_param( 'after' );
		$has_after    = null !== $after_raw;

		$selectors_used = (int) $has_post_id + (int) $all + (int) $has_after;
		if ( 0 === $selectors_used ) {
			return new WP_Error(
				'diviops_flush_missing_selector',
				'Exactly one selector required: post_id, all, or after.',
				[ 'status' => 400 ]
			);
		}
		if ( $selectors_used > 1 ) {
			return new WP_Error(
				'diviops_flush_ambiguous_selector',
				'Only one of post_id, all, after may be provided per call.',
				[ 'status' => 400 ]
			);
		}

		$cache_root = self::resolve_et_cache_root();
		$use_native = class_exists( '\ET_Core_PageResource' )
			&& method_exists( '\ET_Core_PageResource', 'remove_static_resources' );

		// Writability gate:
		//   - Native mode: defer to Divi's own can_write_to_filesystem() — it
		//     accepts WP_Filesystem-backed environments (FTP/SSH-credentialed
		//     hosts) where is_writable() would return false even though Divi
		//     can still write. Matches the same gate Divi uses internally.
		//   - fs_fallback: we use direct unlink(), which genuinely needs OS
		//     write permission — is_writable() is the correct check here.
		if ( $use_native ) {
			if (
				method_exists( '\ET_Core_PageResource', 'can_write_to_filesystem' )
				&& ! \ET_Core_PageResource::can_write_to_filesystem()
			) {
				return new WP_Error(
					'diviops_flush_unwritable',
					'Divi reports the cache filesystem is not writable (ET_Core_PageResource::can_write_to_filesystem).',
					[ 'status' => 500, 'cache_root' => $cache_root ]
				);
			}
		} elseif ( is_dir( $cache_root ) && ! is_writable( $cache_root ) ) {
			return new WP_Error(
				'diviops_flush_unwritable',
				'et-cache directory exists but is not writable by the PHP process.',
				[ 'status' => 500, 'cache_root' => $cache_root ]
			);
		}

		// Missing cache dir: we intentionally don't early-return anymore.
		// Divi's resolver may have already recreated the dir as a side
		// effect (its singleton constructor runs WP_Filesystem with create),
		// and in any case:
		//   - Native mode: remove_static_resources safely runs on a missing
		//     dir (ensure_directory_exists + DONOTCACHEPAGE + site-wide
		//     cache purges still fire).
		//   - fs_fallback: flush_et_cache_dir's transient + post-meta
		//     invalidations fire unconditionally; numeric-post-id iteration
		//     naturally no-ops on a missing dir via is_dir guards in the
		//     helpers. sweep_non_post_divi_css likewise guards.

		if ( $has_post_id ) {
			$post_id = absint( $post_id_raw );
			if ( $post_id <= 0 ) {
				return new WP_Error(
					'diviops_flush_invalid_post_id',
					'post_id must be a positive integer.',
					[ 'status' => 400 ]
				);
			}

			// Snapshot the per-post dir size before the clear so we can report
			// bytes freed even in native mode (where the clearer itself returns
			// no counts). Lower bound — native mode also removes TB CSS in
			// other post dirs, which this snapshot does not account for.
			$pre = self::et_cache_dir_snapshot( $cache_root, $post_id );

			if ( $use_native ) {
				// preserve_vb_files=true mirrors Divi's own preset / global-data
				// / VB update call sites (GlobalPreset.php, GlobalData.php,
				// OffCanvasHooks.php) — keeps `-vb-*` runtime CSS so an open
				// Visual Builder isn't left unstyled after an external flush.
				// delete_files=true bypasses Divi's lazy .stale marker
				// strategy — matches the immediate-delete semantic users want.
				\ET_Core_PageResource::remove_static_resources(
					$post_id, 'all', false, 'all', true, true
				);
				$response = [
					'mode'        => 'post_id',
					'backend'     => 'divi_native',
					'cache_root'  => $cache_root,
					'flushed'     => [ $post_id ],
					'files_freed' => $pre['files'],
					'bytes_freed' => $pre['bytes'],
					'scope_note'  => 'Divi native clearer also removed matching Theme Builder CSS across other post dirs and purged object/module/post/dynamic-assets caches. Visual Builder (-vb-*) runtime CSS preserved to avoid unstyling an open VB session. files_freed / bytes_freed reflect the pre-delete snapshot of et-cache/' . $post_id . '/ only — they are a lower bound; the clearer may have freed more outside this dir.',
				];
				return rest_ensure_response( $response );
			}

			$result = self::flush_et_cache_dir( $cache_root, $post_id );
			$response = [
				'mode'        => 'post_id',
				'backend'     => 'fs_fallback',
				'cache_root'  => $cache_root,
				'flushed'     => $result['existed'] ? [ $post_id ] : [],
				'missing'     => $result['existed'] ? [] : [ $post_id ],
				'files_freed' => $result['files'],
				'bytes_freed' => $result['bytes'],
			];
			return rest_ensure_response( $response );
		}

		if ( $all ) {
			if ( $use_native ) {
				// Two-phase approach:
				//
				// Phase 1 — single-pass file sweep (no N × native_call):
				//   Walk et-cache/ once, delete every Divi CSS file
				//   (et-*.css / et-*.min.css) except those containing
				//   `-vb-` in the basename. Scales as O(total_files)
				//   rather than O(posts × total_files) that would result
				//   from per-post native clears. Scope covers numeric
				//   post dirs AND archive/taxonomy/home/notfound/global
				//   subtrees in one pass — matches the scope Divi's own
				//   _mark_global_cache_cleared(delete_files=true) covers
				//   while applying the VB-preserve filter Divi's mass
				//   path lacks.
				//
				// Phase 2 — site-wide WP cache purges, inlined:
				//   Deliberately NOT calling remove_static_resources with
				//   post_id='all' (or anything that triggers the global
				//   timestamp path). Writing .cache-cleared-at would
				//   immediately invalidate the `-vb-*` files we just
				//   kept in phase 1: is_file_stale() checks file mtime
				//   against that timestamp (PageResource.php:1604-1610)
				//   and any file older than the stamp is stale, including
				//   our preserved VB runtime CSS. That would defeat the
				//   whole point of the preserve-VB logic and unstyle an
				//   open Visual Builder session.
				//
				//   Instead, call the same site-wide purges Divi runs
				//   AFTER the path-specific branch in
				//   do_remove_static_resources, but skip the timestamp
				//   write. Phase 1's physical sweep already delivers
				//   frontend-level invalidation.
				//
				//   Both the sweep AND the DONOTCACHEPAGE sentinel write
				//   route through WP_Filesystem to match Divi's own
				//   deletion API (self::$wpfs->delete in
				//   _mark_global_cache_cleared). Direct unlink() would
				//   silently fail on managed FTP/SSH-credentialed hosts
				//   where can_write_to_filesystem() accepts but PHP
				//   itself lacks write permission.
				$wpfs = self::init_wp_filesystem();
				if ( ! $wpfs ) {
					return new WP_Error(
						'diviops_flush_fs_init_failed',
						'Failed to initialize WP_Filesystem for cache clear. The native backend requires it to delete files on hosts where Divi writes via FTP/SSH credentials.',
						[ 'status' => 500, 'cache_root' => $cache_root ]
					);
				}
				$pass1 = self::sweep_all_divi_css_preserving_vb( $cache_root, $wpfs );
				self::run_divi_site_wide_cache_purges();
				// Match Divi's post-clear behavior: write the
				// DONOTCACHEPAGE sentinel so page-cache plugins / CDNs
				// skip caching the first request while CSS regenerates
				// (PageResource.php:1367-1368 writes the same file).
				if ( is_dir( $cache_root ) ) {
					$wpfs->put_contents( $cache_root . '/DONOTCACHEPAGE', '' );
				}
				$response = [
					'mode'        => 'all',
					'backend'     => 'divi_native',
					'cache_root'  => $cache_root,
					'flushed'     => $pass1['post_ids'],
					'files_freed' => $pass1['files'],
					'bytes_freed' => $pass1['bytes'],
					'scope_note'  => 'Two-phase native clear: (1) WP_Filesystem-driven recursive sweep deleting Divi CSS (et-*.css) across numeric post dirs AND archive/taxonomy/home/notfound/global subtrees, skipping Visual Builder (-vb-*) runtime CSS to avoid unstyling an open VB session; (2) inlined site-wide purges — object cache, module features, post features, Google Fonts, dynamic assets, post meta caches. DONOTCACHEPAGE sentinel written to et-cache root to match Divi\'s post-clear convention (page-cache plugins skip caching the first regenerated request). .cache-cleared-at timestamp deliberately NOT written — Divi\'s is_file_stale() compares file mtime against it, so bumping would invalidate the preserved VB files. Phase 1\'s physical sweep covers frontend-level invalidation without needing the stamp.',
				];
				return rest_ensure_response( $response );
			}

			$pre_total = self::et_cache_total_snapshot( $cache_root );

			// Fallback: iterate numeric subdirs, then sweep root-level and
			// non-numeric subdir et-*.css files (archive/taxonomy/home/
			// notfound/search/global trees) to match the scope Divi's
			// native clearer covers in post_id='all' mode. The safety
			// invariant (only et-*.css basenames) matches Divi's own
			// _is_valid_divi_css_file filter in _mark_global_cache_cleared;
			// .data, .cache-cleared-at, DONOTCACHEPAGE are preserved.
			$flushed     = [];
			$files_freed = 0;
			$bytes_freed = 0;
			foreach ( self::et_cache_numeric_post_ids( $cache_root ) as $pid ) {
				$result = self::flush_et_cache_dir( $cache_root, $pid );
				if ( $result['existed'] ) {
					$flushed[]    = $pid;
					$files_freed += $result['files'];
					$bytes_freed += $result['bytes'];
				}
			}
			$non_post = self::sweep_non_post_divi_css( $cache_root );
			$files_freed += $non_post['files'];
			$bytes_freed += $non_post['bytes'];
			$response = [
				'mode'                  => 'all',
				'backend'               => 'fs_fallback',
				'cache_root'            => $cache_root,
				'flushed'               => $flushed,
				'skipped'               => [],
				'files_freed'           => $files_freed,
				'bytes_freed'           => $bytes_freed,
				'non_post_files_freed'  => $non_post['files'],
				'non_post_bytes_freed'  => $non_post['bytes'],
			];
			return rest_ensure_response( $response );
		}

		// --- after mode ---
		$after_ts = intval( $after_raw );
		if ( $after_ts <= 0 ) {
			return new WP_Error(
				'diviops_flush_invalid_after',
				'after must be a positive unix timestamp.',
				[ 'status' => 400 ]
			);
		}

		if ( $use_native ) {
			// Single-pass WP_Filesystem sweep of the whole et-cache tree,
			// filtering by file mtime > $after_ts. Replaces the prior
			// per-matched-post ET_Core_PageResource::remove_static_resources
			// loop — each of those calls does ~7 glob() scans of the cache
			// tree (PageResource.php:1268-1285), so runtime grew as
			// O(#matched × #total_files). The sweep runs in a single
			// O(#total_files) walk regardless of match count, which is the
			// behavior the `all` phase-1 path already uses.
			//
			// Semantic shift from the prior native path: the previous
			// implementation filtered at the post-dir granularity — if any
			// file inside et-cache/{pid}/ had mtime > cutoff, all CSS in
			// that dir (and related TB CSS across other dirs, via the
			// native clearer's cross-dir globs) was removed. The sweep
			// filters at the file granularity — only files strictly newer
			// than the cutoff are deleted. Older CSS co-located with a
			// recently-rewritten file survives. For the tool's stated use
			// case ("flushing entries touched since a known deployment or
			// mutation batch") this is the more literal semantic.
			//
			// `flushed` reports numeric post_ids whose files were actually
			// deleted (parent-dir membership tracked in the sweep).
			// `skipped` reports numeric post_ids that exist but had no
			// files pass the filter — preserved for response-shape parity
			// with the prior native + current fallback paths.
			$wpfs = self::init_wp_filesystem();
			if ( ! $wpfs ) {
				return new WP_Error(
					'diviops_flush_fs_init_failed',
					'Failed to initialize WP_Filesystem for cache clear. The native backend requires it to delete files on hosts where Divi writes via FTP/SSH credentials.',
					[ 'status' => 500, 'cache_root' => $cache_root ]
				);
			}
			$sweep       = self::sweep_all_divi_css_preserving_vb( $cache_root, $wpfs, $after_ts );
			$touched_set = array_flip( $sweep['post_ids'] );
			$skipped     = [];
			foreach ( self::et_cache_numeric_post_ids( $cache_root ) as $pid ) {
				if ( ! isset( $touched_set[ $pid ] ) ) {
					$skipped[] = $pid;
				}
			}
			sort( $skipped );

			// Per-touched-post invalidations — the prior per-match
			// remove_static_resources call did these inside Divi, and
			// dropping them leaves page caches / Divi post-meta caches
			// serving stale HTML even after the CSS file is gone. These
			// are cheap O(N) operations (no filesystem globs), so doing
			// them in a post-sweep loop preserves the end-to-end flush
			// semantic without reintroducing the scaling issue. Guarded
			// on the same class/function checks `run_divi_site_wide_cache_purges`
			// uses so stripped builds don't fatal.
			$has_clear_wp_cache  = function_exists( 'et_core_clear_wp_cache' );
			$has_meta_cache_clear = class_exists( '\ET_Core_PageResource' )
				&& method_exists( '\ET_Core_PageResource', 'clear_post_meta_caches' );
			foreach ( $sweep['post_ids'] as $pid ) {
				if ( $has_clear_wp_cache ) {
					et_core_clear_wp_cache( (string) $pid );
				}
				if ( $has_meta_cache_clear ) {
					\ET_Core_PageResource::clear_post_meta_caches( (string) $pid );
				}
			}

			// Divi feature caches (module-features, post-features,
			// Google Fonts, dynamic assets) are site-wide with no
			// per-post keys — the prior per-match native clearer ran
			// these N times as part of Divi's post-clear block. Call
			// once after the sweep to preserve the invalidation scope
			// without re-introducing the N × glob overhead. Gated on
			// `anything_flushed` so a no-op `after` call doesn't
			// force feature regeneration.
			$anything_flushed = $sweep['files'] > 0;
			if ( $anything_flushed ) {
				self::run_divi_feature_cache_purges();
			}

			// Write the DONOTCACHEPAGE sentinel once when anything was
			// actually flushed — matches Divi's post-clear behavior
			// (PageResource.php:1367-1368) so page-cache plugins / CDNs
			// skip caching the first regenerated request. Gate on
			// `files > 0` rather than `post_ids` non-empty: the sweep
			// also covers non-post subtrees (archive/taxonomy/home/
			// notfound/global), and deleting only those still warrants
			// the sentinel. Writing `.cache-cleared-at` is still
			// deliberately avoided — is_file_stale() would invalidate
			// the preserved `-vb-*` files against it.
			if ( $anything_flushed && is_dir( $cache_root ) ) {
				$wpfs->put_contents( $cache_root . '/DONOTCACHEPAGE', '' );
			}

			$response = [
				'mode'        => 'after',
				'backend'     => 'divi_native',
				'cache_root'  => $cache_root,
				'after'       => $after_ts,
				'flushed'     => $sweep['post_ids'],
				'skipped'     => $skipped,
				'files_freed' => $sweep['files'],
				'bytes_freed' => $sweep['bytes'],
			];
			// scope_note attaches to any non-empty flush so callers
			// understand broader side effects (invalidations, sentinel)
			// even when only non-post subtree files were deleted —
			// e.g. global/ CSS rewritten after the cutoff on a site
			// with no matching numeric posts. Same gate as the
			// sentinel write.
			if ( $anything_flushed ) {
				$response['scope_note'] = 'Single-pass WP_Filesystem sweep of et-cache/ deleting Divi CSS files (et-*.css) whose mtime > after, across numeric post dirs AND archive/taxonomy/home/notfound/global subtrees. Visual Builder (-vb-*) runtime CSS preserved to avoid unstyling an open VB session. Filter is per-file (not per-dir): older CSS co-located with a recently-rewritten file is left in place. Post-sweep invalidations: per-touched-post (et_core_clear_wp_cache + ET_Core_PageResource::clear_post_meta_caches, keyed to numeric post_ids) plus site-wide Divi feature-cache purges (ET_Builder_Module_Features / Post_Features / Google_Fonts_Feature / Dynamic_Assets_Feature — these have no per-post keys and were already run N times by the prior per-match native clearer). Non-post subtree deletions contribute to files_freed / bytes_freed but not flushed. DONOTCACHEPAGE sentinel written to cache root so page-cache plugins skip caching the first regenerated request. .cache-cleared-at timestamp deliberately NOT written — is_file_stale() would invalidate the preserved VB files against it.';
			}
			return rest_ensure_response( $response );
		}

		// fs_fallback path — Divi inactive / stripped build. Per-post
		// iteration is already O(total_files) here because flush_et_cache_dir
		// uses direct unlink() (no native glob loop), so it doesn't hit the
		// scaling issue the native path had. Kept as-is to preserve the
		// prior dir-mtime filter semantic + per-post transient / post_meta
		// invalidations that flush_et_cache_dir performs.
		$matched = [];
		$skipped = [];
		foreach ( self::et_cache_numeric_post_ids( $cache_root ) as $pid ) {
			// Dir mtime alone is unreliable: Divi rewrites compiled CSS via
			// put_contents() on deterministic filenames
			// (et-core-unified-tb-*-{post_id}.min.css etc.), which updates
			// the file's mtime but NOT the parent dir's mtime (dir mtime
			// only changes on create/delete/rename inside the dir). So a
			// page re-rendered in place after the cutoff would silently
			// land in `skipped`. Walk dir + contents and take the latest.
			$latest = self::et_cache_dir_latest_mtime( $cache_root, $pid );
			if ( false === $latest || $latest <= $after_ts ) {
				$skipped[] = $pid;
				continue;
			}
			$matched[] = $pid;
		}

		$files_freed = 0;
		$bytes_freed = 0;
		foreach ( $matched as $pid ) {
			$snap = self::et_cache_dir_snapshot( $cache_root, $pid );
			$files_freed += $snap['files'];
			$bytes_freed += $snap['bytes'];
			self::flush_et_cache_dir( $cache_root, $pid );
		}

		return rest_ensure_response( [
			'mode'        => 'after',
			'backend'     => 'fs_fallback',
			'cache_root'  => $cache_root,
			'after'       => $after_ts,
			'flushed'     => $matched,
			'skipped'     => $skipped,
			'files_freed' => $files_freed,
			'bytes_freed' => $bytes_freed,
		] );
	}

	/**
	 * Resolve Divi's compiled-CSS cache root. Prefers Divi's own resolver
	 * (ET_Core_PageResource::get_cache_directory → et_core_cache_dir()->path)
	 * which handles the ET_CORE_CACHE_DIR constant, the uploads-based
	 * fallback when WP_CONTENT_DIR isn't writable, and any multisite
	 * adjustments. Falls back to the wp-content default only when Divi
	 * is inactive (fs_fallback path).
	 *
	 * @return string Absolute filesystem path to the cache root.
	 */
	private static function resolve_et_cache_root() {
		if (
			class_exists( '\ET_Core_PageResource' )
			&& method_exists( '\ET_Core_PageResource', 'get_cache_directory' )
		) {
			$path = \ET_Core_PageResource::get_cache_directory();
			if ( is_string( $path ) && '' !== $path ) {
				return rtrim( $path, '/\\' );
			}
		}
		return WP_CONTENT_DIR . '/et-cache';
	}

	/**
	 * Snapshot size + file count of a per-post et-cache dir without deleting.
	 *
	 * @param string $cache_root
	 * @param int    $post_id
	 * @return array{existed: bool, files: int, bytes: int}
	 */
	private static function et_cache_dir_snapshot( $cache_root, $post_id ) {
		$dir    = $cache_root . '/' . intval( $post_id );
		$result = [ 'existed' => false, 'files' => 0, 'bytes' => 0 ];
		if ( ! is_dir( $dir ) ) {
			return $result;
		}
		$result['existed'] = true;
		foreach ( self::et_cache_walk_files( $dir ) as $file ) {
			$size = filesize( $file );
			if ( false !== $size ) {
				$result['bytes'] += $size;
			}
			$result['files']++;
		}
		return $result;
	}

	/**
	 * Recursively enumerate regular files under a directory. Divi's own
	 * clearer searches TB/WP-template CSS at multiple nesting depths under
	 * cache_dir (one, two, and three levels of subdir between the cache
	 * root and the file), so some site configurations produce nested
	 * cache layouts. Our per-post helpers must walk descendants rather
	 * than just direct children to avoid reporting a flush while leaving
	 * nested stale CSS behind.
	 *
	 * @param string $dir
	 * @return string[] Absolute file paths.
	 */
	private static function et_cache_walk_files( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return [];
		}
		$out = [];
		$it  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $it as $info ) {
			if ( $info->isFile() ) {
				$out[] = $info->getPathname();
			}
		}
		return $out;
	}

	/**
	 * Sum files + bytes across every numeric-named et-cache subdir, and
	 * return the list of post IDs seen. Used as a lower-bound "bytes freed"
	 * for `all` flushes; Divi's native clearer also touches sibling dirs +
	 * WP caches which this does not count. `post_ids` lets the native
	 * `all` path report `flushed` symmetrically with the other branches.
	 *
	 * @param string $cache_root
	 * @return array{files: int, bytes: int, post_ids: int[]}
	 */
	private static function et_cache_total_snapshot( $cache_root ) {
		$files    = 0;
		$bytes    = 0;
		$post_ids = self::et_cache_numeric_post_ids( $cache_root );
		foreach ( $post_ids as $pid ) {
			$snap   = self::et_cache_dir_snapshot( $cache_root, $pid );
			$files += $snap['files'];
			$bytes += $snap['bytes'];
		}
		return [ 'files' => $files, 'bytes' => $bytes, 'post_ids' => $post_ids ];
	}

	/**
	 * Return the most recent mtime across a per-post cache dir AND its
	 * contents. Dir mtime alone misses in-place file rewrites — Divi
	 * regenerates CSS via put_contents() on deterministic filenames, which
	 * bumps file mtime but not parent dir mtime.
	 *
	 * @param string $cache_root
	 * @param int    $post_id
	 * @return int|false Unix ts, or false if dir is missing / unreadable.
	 */
	private static function et_cache_dir_latest_mtime( $cache_root, $post_id ) {
		$dir = $cache_root . '/' . intval( $post_id );
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$latest = filemtime( $dir );
		if ( false === $latest ) {
			$latest = 0;
		}
		foreach ( self::et_cache_walk_files( $dir ) as $file ) {
			$m = filemtime( $file );
			if ( false !== $m && $m > $latest ) {
				$latest = $m;
			}
		}
		return $latest > 0 ? $latest : false;
	}

	/**
	 * Delete Divi CSS files that live outside numeric per-post dirs —
	 * root-level et-*.css files and the archive/taxonomy/home/notfound/
	 * search/global cache trees. Used only by the fs_fallback `all`
	 * branch, after per-post iteration; matches the scope Divi's native
	 * clearer covers in post_id='all' mode.
	 *
	 * Filter mirrors Divi's _is_valid_divi_css_file: basename starts with
	 * 'et-' AND ends with .css or .min.css. Preserves .data,
	 * .cache-cleared-at, DONOTCACHEPAGE, and any non-Divi files.
	 *
	 * Empty non-numeric subdirs are rmdir'd after sweeping so Divi can
	 * recreate them on the next render cycle.
	 *
	 * @param string $cache_root
	 * @return array{files: int, bytes: int}
	 */
	private static function sweep_non_post_divi_css( $cache_root ) {
		$result = [ 'files' => 0, 'bytes' => 0 ];
		if ( ! is_dir( $cache_root ) ) {
			return $result;
		}
		$entries = scandir( $cache_root );
		if ( false === $entries ) {
			return $result;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $cache_root . '/' . $entry;
			if ( is_file( $path ) ) {
				// Root-level et-*.css files.
				if ( self::is_divi_css_basename( $entry ) ) {
					$size = filesize( $path );
					if ( unlink( $path ) ) {
						$result['files']++;
						if ( false !== $size ) {
							$result['bytes'] += $size;
						}
					}
				}
				continue;
			}
			if ( ! is_dir( $path ) ) {
				continue;
			}
			// Skip numeric post dirs — those are handled by the per-post
			// iteration in the caller.
			if ( ctype_digit( $entry ) ) {
				continue;
			}
			// Walk non-numeric subdir (archive/, taxonomy/, home/, etc.),
			// delete et-*.css descendants, rmdir emptied dirs bottom-up.
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $it as $info ) {
				$child = $info->getPathname();
				if ( $info->isFile() ) {
					if ( ! self::is_divi_css_basename( $info->getFilename() ) ) {
						continue;
					}
					$size = filesize( $child );
					if ( unlink( $child ) ) {
						$result['files']++;
						if ( false !== $size ) {
							$result['bytes'] += $size;
						}
					}
				} elseif ( $info->isDir() ) {
					@rmdir( $child );
				}
			}
			@rmdir( $path );
		}
		return $result;
	}

	/**
	 * Lazily initialize the WP_Filesystem global and return it. Returns
	 * null if initialization fails (e.g. missing credentials on FTP/SSH
	 * hosts without saved creds). Used by the native `all` sweep so
	 * deletes go through the same API Divi itself uses
	 * (self::$wpfs->delete in _mark_global_cache_cleared) — direct
	 * unlink() would silently fail on hosts where Divi writes via
	 * credentials but PHP itself can't.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private static function init_wp_filesystem() {
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			return $wp_filesystem;
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( WP_Filesystem() && $wp_filesystem ) {
			return $wp_filesystem;
		}
		return null;
	}

	/**
	 * Run Divi's site-wide WP-cache purges — the block that normally runs
	 * AFTER the path-specific branch in do_remove_static_resources, but
	 * WITHOUT writing the .cache-cleared-at global timestamp.
	 *
	 * Why split: .cache-cleared-at immediately invalidates any file with
	 * older mtime via is_file_stale() (PageResource.php:1604-1610),
	 * including the `-vb-*` runtime CSS we deliberately keep in the
	 * native `all` phase-1 sweep. Writing the timestamp would silently
	 * undo the VB preservation and unstyle an open Visual Builder
	 * session on the next request.
	 *
	 * Each call is guarded: function_exists for et_core_clear_wp_cache,
	 * class_exists for the ET_Builder_* features (these can be missing
	 * on stripped builds even when ET_Core_PageResource is present).
	 * clear_post_meta_caches is a public static on ET_Core_PageResource
	 * so no separate guard beyond that class check is needed.
	 */
	private static function run_divi_site_wide_cache_purges() {
		if ( function_exists( 'et_core_clear_wp_cache' ) ) {
			et_core_clear_wp_cache( '' );
		}
		self::run_divi_feature_cache_purges();
		if (
			class_exists( '\ET_Core_PageResource' )
			&& method_exists( '\ET_Core_PageResource', 'clear_post_meta_caches' )
		) {
			\ET_Core_PageResource::clear_post_meta_caches( '' );
		}
	}

	/**
	 * Divi feature-cache purges — the module/post features, Google
	 * Fonts, and dynamic-assets caches that Divi maintains site-wide
	 * (no per-post keys exist for these; purges clear the whole cache
	 * and Divi lazily rebuilds on the next render).
	 *
	 * Split out from `run_divi_site_wide_cache_purges` so the native
	 * `after` branch can invoke just these without also running the
	 * post-keyed purges (`et_core_clear_wp_cache('')` /
	 * `clear_post_meta_caches('')`) — those are already covered
	 * per-touched-pid in the `after` path, and running them site-wide
	 * on top would over-invalidate on a partial flush.
	 *
	 * The prior per-match `ET_Core_PageResource::remove_static_resources`
	 * loop in `after` mode was invoking these as part of Divi's own
	 * post-clear block once per match; collapsing to a single
	 * site-wide call preserves the same invalidation scope without the
	 * N × glob overhead that motivated the sweep refactor.
	 */
	private static function run_divi_feature_cache_purges() {
		if ( class_exists( '\ET_Builder_Module_Features' ) ) {
			\ET_Builder_Module_Features::purge_cache();
		}
		if ( class_exists( '\ET_Builder_Post_Features' ) ) {
			\ET_Builder_Post_Features::purge_cache();
		}
		if ( class_exists( '\ET_Builder_Google_Fonts_Feature' ) ) {
			\ET_Builder_Google_Fonts_Feature::purge_cache();
		}
		if ( class_exists( '\ET_Builder_Dynamic_Assets_Feature' ) ) {
			\ET_Builder_Dynamic_Assets_Feature::purge_cache();
		}
	}

	/**
	 * Single-pass recursive walk of the entire et-cache tree, deleting
	 * every Divi CSS file (et-*.css / et-*.min.css) except those whose
	 * basename contains `-vb-` (VB runtime CSS preserved). Replaces the
	 * N × native-clear per-post loop for `all` mode — runtime was
	 * O(#post_ids × #total_files) because each ET_Core_PageResource call
	 * does ~7 glob scans of the whole tree. This helper does one walk
	 * regardless of cache size.
	 *
	 * Scope expansion over the old loop: previously archive/taxonomy/
	 * home/notfound/global CSS was only invalidated lazily via the
	 * `.cache-cleared-at` timestamp (pass 2). Now those files are
	 * physically deleted here alongside the per-post CSS, matching the
	 * scope Divi's own _mark_global_cache_cleared(delete_files=true)
	 * covers — but with the VB-preserve filter applied, which Divi's
	 * own mass path lacks.
	 *
	 * Rmdir empty directories bottom-up (CHILD_FIRST) so the cache tree
	 * collapses cleanly after the sweep.
	 *
	 * @param string              $cache_root
	 * @param \WP_Filesystem_Base $wpfs       Initialized WP_Filesystem instance — both deletes and rmdir routes through this so FTP/SSH-credentialed hosts work.
	 * @param int|null            $min_mtime  When non-null, only files with mtime strictly greater than this unix timestamp are deleted — used by `after` mode to avoid O(N × glob) per-post native calls. Files filtered out are left in place; their parent dirs may still be rmdir'd if unrelated deletions empty them. When null, every matching Divi CSS file is deleted (current `all` mode behavior).
	 * @return array{files: int, bytes: int, post_ids: int[]}
	 *   post_ids = numeric per-post dirs whose files were deleted
	 *   (useful for the `flushed` response field).
	 */
	private static function sweep_all_divi_css_preserving_vb( $cache_root, $wpfs, $min_mtime = null ) {
		$result = [ 'files' => 0, 'bytes' => 0, 'post_ids' => [] ];
		if ( ! is_dir( $cache_root ) ) {
			return $result;
		}
		$touched_post_ids = [];
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $cache_root, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $info ) {
			$path = $info->getPathname();
			if ( $info->isFile() ) {
				$basename = $info->getFilename();
				if ( ! self::is_divi_css_basename( $basename ) ) {
					continue;
				}
				// Preserve VB runtime CSS — matches Divi's
				// preserve_vb_files=true convention used across every
				// preset/global-data save path.
				if ( false !== strpos( $basename, '-vb-' ) ) {
					continue;
				}
				// `after` mode: skip files compiled at or before the
				// cutoff. getMTime() throws on unreadable files — the
				// surrounding iterator already filtered to readable
				// entries, but we guard defensively so a permission
				// race doesn't abort the whole sweep.
				if ( null !== $min_mtime ) {
					$mtime = @$info->getMTime();
					if ( false === $mtime || $mtime <= $min_mtime ) {
						continue;
					}
				}
				$size = filesize( $path );
				if ( $wpfs->delete( $path ) ) {
					$result['files']++;
					if ( false !== $size ) {
						$result['bytes'] += $size;
					}
					// Track numeric post dir from the first path segment
					// under cache_root. Divi can nest per-post files
					// deeper (e.g. taxonomy/category/name/et-...tb-{id}*
					// inside et-cache/{id}/), so a direct-parent check
					// under-reports touched posts. This walks the relative
					// path and claims the first numeric ancestor.
					$rel = substr( $path, strlen( $cache_root ) + 1 );
					$first_sep = strpos( $rel, '/' );
					$first = false === $first_sep ? '' : substr( $rel, 0, $first_sep );
					if ( '' !== $first && ctype_digit( $first ) ) {
						$touched_post_ids[ (int) $first ] = true;
					}
				}
			} elseif ( $info->isDir() ) {
				// Bottom-up rmdir via WP_Filesystem; $recursive=false
				// so non-empty dirs are no-ops (matches the @rmdir
				// behavior we had before). In `after` mode the dir
				// may still hold older untouched CSS — rmdir no-ops
				// and the dir is preserved, which is the correct
				// outcome for a filtered sweep.
				$wpfs->rmdir( $path, false );
			}
		}
		$result['post_ids'] = array_keys( $touched_post_ids );
		sort( $result['post_ids'] );
		return $result;
	}

	/**
	 * Is this basename a Divi-naming-convention CSS file? Mirrors Divi's
	 * ET_Core_PageResource::_is_valid_divi_css_file filter.
	 *
	 * @param string $basename
	 * @return bool
	 */
	private static function is_divi_css_basename( $basename ) {
		if ( 0 !== strpos( $basename, 'et-' ) ) {
			return false;
		}
		$len    = strlen( $basename );
		$ends_css     = $len >= 4 && substr( $basename, -4 ) === '.css';
		$ends_min_css = $len >= 8 && substr( $basename, -8 ) === '.min.css';
		return $ends_css || $ends_min_css;
	}

	/**
	 * Enumerate numeric-named subdirs of et-cache/ — our fallback + after
	 * iterator. Non-numeric siblings are skipped to preserve the "only
	 * per-post dirs" safety invariant in fs_fallback mode.
	 *
	 * @param string $cache_root
	 * @return int[]
	 */
	private static function et_cache_numeric_post_ids( $cache_root ) {
		if ( ! is_dir( $cache_root ) ) {
			return [];
		}
		$entries = scandir( $cache_root );
		if ( false === $entries ) {
			return [];
		}
		$ids = [];
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( ! ctype_digit( $entry ) ) {
				continue;
			}
			if ( ! is_dir( $cache_root . '/' . $entry ) ) {
				continue;
			}
			$ids[] = (int) $entry;
		}
		return $ids;
	}

	/**
	 * Remove a single per-post Divi cache dir and its CSS files (fallback
	 * path when ET_Core_PageResource is unavailable).
	 *
	 * Mirrors the existing invalidate_divi_cache helper to match behavior
	 * parity in environments without the native clearer:
	 *   1. Unlink *.css inside the numeric-named per-post dir
	 *   2. Touch post_modified to trigger Divi's style regeneration
	 *   3. Delete the `et_builder_css_{post_id}` transient
	 *   4. Delete the `_et_builder_module_features_cache` post meta
	 *
	 * Additionally rmdir's the now-empty dir to match the documented
	 * manual workaround `rm -rf wp-content/et-cache/{post_id}/`. Divi
	 * recreates the dir on the next render.
	 *
	 * @param string $cache_root Absolute path to et-cache/ (already validated).
	 * @param int    $post_id    Positive int — numeric subdir name to remove.
	 * @return array{existed: bool, files: int, bytes: int}
	 */
	private static function flush_et_cache_dir( $cache_root, $post_id ) {
		$dir    = $cache_root . '/' . intval( $post_id );
		$result = [ 'existed' => false, 'files' => 0, 'bytes' => 0 ];

		if ( is_dir( $dir ) ) {
			$result['existed'] = true;

			// Walk recursively — Divi's own clearer searches nested paths
			// (e.g. taxonomy/category/name/et-...tb-{id}*) and some site
			// configurations do produce nested cache files inside a post
			// dir. CHILD_FIRST so leaf files come out before their parent
			// dirs, letting us rmdir empties after the walk completes.
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $it as $info ) {
				$path = $info->getPathname();
				if ( $info->isFile() ) {
					// Measure before delete (size is unreadable after
					// unlink), but only count it toward `bytes` if the
					// unlink actually succeeded — otherwise the caller
					// sees phantom bytes for files still on disk.
					$size = filesize( $path );
					if ( unlink( $path ) ) {
						$result['files']++;
						if ( false !== $size ) {
							$result['bytes'] += $size;
						}
					}
				} elseif ( $info->isDir() ) {
					@rmdir( $path );
				}
			}

			// Remove the now-empty top-level dir. If something unexpected
			// lingers (hidden files, non-writable entries), rmdir silently
			// no-ops — the CSS files are gone, which is the
			// staleness-unblocking outcome users need.
			@rmdir( $dir );
		}

		// State-mutating invalidations only run when there was actually
		// something to flush. Bumping post_modified and deleting post meta
		// have observable side effects (feed order, sitemap modified-dates,
		// modified-date queries) — users explicitly calling a cache flush
		// on a post with no cache shouldn't have those side effects. The
		// existing invalidate_divi_cache helper (used by preset_update,
		// update_module, etc.) bumps unconditionally because those callers
		// are paired with a content change; this helper is a pure cache
		// flush with no implied content change.
		//
		// Transient deletion stays unconditional: delete_transient is a
		// no-op on a missing key, and it cleans up orphan transients from
		// deleted cache dirs.
		if ( $result['existed'] && get_post( $post_id ) ) {
			wp_update_post(
				[
					'ID'            => $post_id,
					'post_modified' => current_time( 'mysql' ),
				]
			);
			delete_post_meta( $post_id, '_et_builder_module_features_cache' );
		}
		delete_transient( 'et_builder_css_' . $post_id );

		return $result;
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
		$rate_disabled = (bool) DIVIOPS_RATE_LIMIT_DISABLED;
		$read_limit    = (int) DIVIOPS_RATE_LIMIT_READ;
		$write_limit   = (int) DIVIOPS_RATE_LIMIT_WRITE;

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

// Rate-limit constants — resolved once at bootstrap so these are the single
// source of truth at runtime. Placed after the class declaration so the
// class constants can serve as defaults. Precedence: wp-config.php constant >
// env var > class default. Empty / non-numeric env values fall through to the
// class default; an explicit numeric "0" is honored so operators can fully
// disable a bucket. When a wp-config.php constant is set, the env var is
// intentionally ignored (constant wins) — this is consistent across all three.
$diviops_env_disabled = getenv( 'DIVIOPS_RATE_LIMIT_DISABLED' );
$diviops_env_read     = getenv( 'DIVIOPS_RATE_LIMIT_READ' );
$diviops_env_write    = getenv( 'DIVIOPS_RATE_LIMIT_WRITE' );
defined( 'DIVIOPS_RATE_LIMIT_DISABLED' ) || define(
	'DIVIOPS_RATE_LIMIT_DISABLED',
	filter_var( $diviops_env_disabled, FILTER_VALIDATE_BOOLEAN )
);
defined( 'DIVIOPS_RATE_LIMIT_READ' ) || define(
	'DIVIOPS_RATE_LIMIT_READ',
	is_numeric( $diviops_env_read ) ? (int) $diviops_env_read : DiviOps_Agent::RATE_LIMIT_READ
);
defined( 'DIVIOPS_RATE_LIMIT_WRITE' ) || define(
	'DIVIOPS_RATE_LIMIT_WRITE',
	is_numeric( $diviops_env_write ) ? (int) $diviops_env_write : DiviOps_Agent::RATE_LIMIT_WRITE
);
unset( $diviops_env_disabled, $diviops_env_read, $diviops_env_write );

DiviOps_Agent::init();
