<?php
/**
 * Plugin Name: DiviOps Agent
 * Plugin URI: https://github.com/oaris-dev/diviops
 * Description: REST API bridge for DiviOps — connects Claude Code to your Divi 5 site for AI-powered page building and design management.
 * Version: 1.5.0
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

// ── Trait includes (see #220 split) ─────────────────────────────
// Loaded before the class declaration so trait names resolve when
// the class declares `use ...;`. Each trait file has its own
// ABSPATH guard, so direct loading is rejected.
require_once __DIR__ . '/includes/trait-canvas.php';
require_once __DIR__ . '/includes/trait-core.php';
require_once __DIR__ . '/includes/trait-global-color.php';
require_once __DIR__ . '/includes/trait-global-font.php';
require_once __DIR__ . '/includes/trait-library.php';
require_once __DIR__ . '/includes/trait-meta.php';
require_once __DIR__ . '/includes/trait-module-schema.php';
require_once __DIR__ . '/includes/trait-page.php';
require_once __DIR__ . '/includes/trait-preset.php';
require_once __DIR__ . '/includes/trait-render.php';
require_once __DIR__ . '/includes/trait-theme-builder.php';
require_once __DIR__ . '/includes/trait-validate.php';
require_once __DIR__ . '/includes/trait-variable.php';


class DiviOps_Agent {

	// ── Trait composition (see #220 split) ──────────────────────
	// Each trait contributes a slice of the REST surface. The traits
	// are required in the file-scope bootstrap below; methods on each
	// trait are mixed into this class.
	use DiviOps_Agent_Canvas;
	use DiviOps_Agent_Core;
	use DiviOps_Agent_GlobalColor;
	use DiviOps_Agent_GlobalFont;
	use DiviOps_Agent_Library;
	use DiviOps_Agent_Meta;
	use DiviOps_Agent_ModuleSchema;
	use DiviOps_Agent_Page;
	use DiviOps_Agent_Preset;
	use DiviOps_Agent_Render;
	use DiviOps_Agent_ThemeBuilder;
	use DiviOps_Agent_Validate;
	use DiviOps_Agent_Variable;

	/**
	 * Plugin version — surfaced in /handshake for self-diagnosis only;
	 * server no longer gates on it (capability map is the gate).
	 */
	const VERSION = '1.5.0';

	/**
	 * Minimum MCP server version this plugin is compatible with.
	 */
	const MIN_SERVER_VERSION = '1.1.0';

	/**
	 * Per-tool capability map emitted by /handshake.
	 *
	 * Each key is a post-rename MCP tool name slug (without the
	 * `diviops_` prefix). The server's `requireCapability(<key>)`
	 * gate at every plugin-touching tool entry compares against this
	 * list. Tools the server adds in newer releases that aren't yet
	 * in this list will fail fast on older plugins with an "upgrade
	 * the diviops-agent plugin" hint, while every other tool keeps
	 * working — no global version floor.
	 *
	 * Server-local tools (wp-cli wrappers, in-memory templates,
	 * meta_ping/meta_info) don't appear here; the server skips the
	 * capability check for them.
	 *
	 * Maintenance: any new route added below must add its capability
	 * key here in the same PR.
	 */
	const CAPABILITIES = [
		// canvas
		'canvas_create', 'canvas_delete', 'canvas_duplicate', 'canvas_get', 'canvas_list', 'canvas_update',
		// global colors / fonts
		'global_color_audit_storage', 'global_color_create', 'global_color_delete', 'global_color_list', 'global_color_update',
		'global_font_audit_storage', 'global_font_create', 'global_font_delete', 'global_font_list', 'global_font_update',
		// library
		'library_get', 'library_list', 'library_save',
		// meta
		'meta_find_icon', 'meta_flush_cache',
		// module
		'module_clone', 'module_lock', 'module_move', 'module_unlock', 'module_update',
		// page
		'page_create', 'page_get', 'page_get_layout', 'page_list',
		'page_trash', 'page_update_content', 'page_update_status',
		// preset
		'preset_audit', 'preset_audit_storage', 'preset_cleanup', 'preset_create', 'preset_delete',
		'preset_reassign', 'preset_scan_orphans', 'preset_set_default', 'preset_update',
		// render
		'render_preview',
		// schema
		'schema_get_module', 'schema_get_module_dump_all', 'schema_get_settings', 'schema_list_modules',
		// section
		'section_append', 'section_get', 'section_remove', 'section_replace',
		// theme builder
		'tb_layout_block_insert', 'tb_layout_get', 'tb_layout_update', 'tb_template_create', 'tb_template_list',
		'tb_template_trash',
		// validate
		'validate_blocks',
		// page_id overload on validate_blocks + render_preview (#700) —
		// single bundle key; both tools accept exactly-one of {content, page_id}.
		'validate_render_by_page_id',
		// variable
		'variable_create', 'variable_create_fluid_system', 'variable_delete',
		'variable_list', 'variable_scan_orphans', 'variable_used_on_page',
		// Storage-path contract (#719). Single contract-level key advertises
		// implementation of the full read-probe + write-canonical + audit-
		// aggregates contract across preset / global_color / global_font
		// surfaces. Per-surface keys (preset_storage_multipath_v1,
		// global_color_storage_multipath_v1, global_font_storage_multipath_v1)
		// also emitted so consumers can detect partial implementations on
		// future plugins that ship the contract per-surface.
		'storage_multipath_probe_v1',
		'preset_storage_multipath_v1',
		'global_color_storage_multipath_v1',
		'global_font_storage_multipath_v1',
	];

	/**
	 * REST namespace for all endpoints.
	 */
	const REST_NAMESPACE      = 'diviops/v1';
	const REASSIGN_MAX_PAGES  = 1000;
	const VARIABLES_SCAN_MAX_POSTS = 2000;

	/**
	 * Post types that can contain Divi block markup — scanned for
	 * preset / variable references. Kept in one place so the ref-scanner
	 * and the variable_delete SQL fast-path stay in lockstep.
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

		register_rest_route( self::REST_NAMESPACE, '/page/list', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'page_list' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/page/get/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'page_get' ],
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

		register_rest_route( self::REST_NAMESPACE, '/page/get-layout/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'page_get_layout' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'full' => [
					'default'     => false,
					'type'        => 'boolean',
					'description' => 'Include full block attrs and raw content (default: false for slim targeting-only response)',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/schema/modules', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'schema_list_modules' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/schema/module/dump-all', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'schema_get_module_dump_all' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/schema/module/(?P<name>[a-zA-Z0-9_/-]+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'schema_get_module' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/schema/settings', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'schema_get_settings' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/global-color/list', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'global_color_list' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		// Storage-path contract (#719) — admin-only audit aggregator.
		// Surfaces per-entry provenance + warnings across all candidate
		// storage paths for the global_colors surface (D5 nested,
		// hypothetical top-level, and WP-customizer-bound defaults). Like
		// /preset/scan-orphans this is admin-only because the union
		// payload includes synthetic-id metadata derived from the
		// GlobalData class property, which carries inventory-leak
		// implications via Editor read access.
		register_rest_route( self::REST_NAMESPACE, '/global-color/audit-storage', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'global_color_audit_storage' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/global-font/list', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'global_font_list' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		// Storage-path contract (#719) — admin-only audit aggregator across
		// the gfid-* catalog (et_divi.et_global_data.global_fonts) AND the
		// `et_uploaded_fonts` local-hosted Pattern B surface.
		register_rest_route( self::REST_NAMESPACE, '/global-font/audit-storage', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'global_font_audit_storage' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
		] );

		// Global fonts CRUD — parallel to /global-color/* but with
		// gfid-* IDs stored under `et_global_data.global_fonts`. Distinct
		// from /variable/* which writes `gvid-*` fonts under
		// `et_global_data.global_variables.fonts` (variable manager surface).
		register_rest_route( self::REST_NAMESPACE, '/global-font/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'global_font_create' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'id'       => [ 'required' => false, 'type' => 'string' ],
				'family'   => [ 'required' => false, 'type' => 'string' ],
				'source'   => [ 'required' => false, 'type' => 'string' ],
				'weights'  => [ 'required' => false, 'type' => 'array' ],
				'subsets'  => [ 'required' => false, 'type' => 'array' ],
				'label'    => [ 'required' => false, 'type' => 'string' ],
				'fallback' => [ 'required' => false, 'type' => 'string' ],
				'status'   => [ 'required' => false, 'type' => 'string' ],
				'dry_run'  => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/global-font/update', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'global_font_update' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'id'       => [ 'required' => true,  'type' => 'string' ],
				'family'   => [ 'required' => false, 'type' => 'string' ],
				'source'   => [ 'required' => false, 'type' => 'string' ],
				'weights'  => [ 'required' => false, 'type' => 'array' ],
				'subsets'  => [ 'required' => false, 'type' => 'array' ],
				'label'    => [ 'required' => false, 'type' => 'string' ],
				'fallback' => [ 'required' => false, 'type' => 'string' ],
				'status'   => [ 'required' => false, 'type' => 'string' ],
				'dry_run'  => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/global-font/delete', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'global_font_delete' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'id'      => [ 'required' => true,  'type' => 'string' ],
				'force'   => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'dry_run' => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/global-color/upsert', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'global_color_upsert' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'colors' => [ 'required' => true, 'type' => 'array' ],
				'mode'   => [ 'required' => false, 'type' => 'string', 'default' => 'merge' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/global-color/delete', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'global_color_delete' ],
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

		register_rest_route( self::REST_NAMESPACE, '/preset/list', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'preset_list' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/preset/audit', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'preset_audit' ],
			// Admin-only: response includes per-preset page_refs (page IDs + titles correlated with preset usage) — inventory-leak risk via Editor read access. Symmetric with /preset/scan-orphans and /variable/scan-orphans (#501).
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
		] );

		// Storage-path contract (#719) — admin-only audit aggregator across
		// all candidate D5 preset paths PLUS the OUT-OF-BAND `_ng` legacy
		// D4 store. Distinct from /preset/audit (which audits preset
		// CONTENT — usage refs, orphans, defaults, etc.); this surface
		// audits preset STORAGE LOCATION with per-entry provenance and
		// `legacy_d4_ng` tagging. Admin-only for symmetry with the existing
		// /preset/audit gate.
		register_rest_route( self::REST_NAMESPACE, '/preset/audit-storage', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'preset_audit_storage' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/preset/cleanup', [
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

		register_rest_route( self::REST_NAMESPACE, '/preset/update', [
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

		register_rest_route( self::REST_NAMESPACE, '/preset/delete', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'preset_delete' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'preset_id' => [ 'required' => true,  'type' => 'string' ],
				'force'     => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/preset/create', [
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

		register_rest_route( self::REST_NAMESPACE, '/preset/reassign', [
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

		register_rest_route( self::REST_NAMESPACE, '/preset/scan-orphans', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'preset_scan_orphans' ],
			// Admin-only: response includes page IDs + titles correlated to preset refs — inventory-leak risk.
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/preset/set-default', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'preset_set_default' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				// Two addressing modes:
				//   1. preset_id (existing): set/clear default by walking items[] for that UUID.
				//   2. type+module (bucket-addressed clear): clear an orphan default pointer
				//      when preset_id no longer exists in items[]. Requires unset=true.
				'preset_id' => [ 'required' => false, 'type' => 'string' ],
				'type'      => [ 'required' => false, 'type' => 'string', 'enum' => [ 'module', 'group' ] ],
				'module'    => [ 'required' => false, 'type' => 'string' ],
				'unset'     => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

		// ── Library Operations ───────────────────────────────────────

		register_rest_route( self::REST_NAMESPACE, '/library/items', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'library_list' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'layout_type' => [ 'required' => false, 'type' => 'string', 'default' => '' ],
				'scope'       => [ 'required' => false, 'type' => 'string', 'default' => '' ],
				'per_page'    => [ 'required' => false, 'type' => 'integer', 'default' => 50 ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/library/item/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'library_get' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/library/save', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'library_save' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'title'       => [ 'required' => true, 'type' => 'string' ],
				'content'     => [ 'required' => true, 'type' => 'string' ],
				'layout_type' => [ 'required' => false, 'type' => 'string', 'default' => 'section' ],
				'scope'       => [ 'required' => false, 'type' => 'string', 'default' => 'non_global' ],
			],
		] );

		// ── Theme Builder Operations ────────────────────────────────

		register_rest_route( self::REST_NAMESPACE, '/theme-builder/template/list', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'tb_template_list' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'per_page' => [ 'type' => 'integer', 'default' => 50 ],
				'page'     => [ 'type' => 'integer', 'default' => 1 ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/theme-builder/layout/get/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'tb_layout_get' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/theme-builder/layout/update/(?P<id>\d+)', [
			'methods'             => 'PUT',
			'callback'            => [ __CLASS__, 'tb_layout_update' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'content' => [ 'required' => true, 'type' => 'string' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/theme-builder/layout/block-insert/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'tb_layout_block_insert' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'content'         => [ 'required' => true, 'type' => 'string' ],
				'parent_selector' => [ 'required' => false, 'type' => 'string' ],
				'parent_path'     => [ 'required' => false, 'type' => 'string' ],
				'position'        => [ 'required' => false, 'type' => 'string', 'default' => 'append' ],
				'dry_run'         => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/theme-builder/template/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'tb_template_create' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'title'          => [ 'required' => true, 'type' => 'string' ],
				'condition'      => [ 'required' => true, 'type' => 'string' ],
				'header_content' => [ 'required' => false, 'type' => 'string', 'default' => '' ],
				'footer_content' => [ 'required' => false, 'type' => 'string', 'default' => '' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/theme-builder/template/trash/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'tb_template_trash' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'id'      => [ 'required' => true ],
				'force'   => [
					'required'    => false,
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'When true, permanently delete (wp_delete_post). Default false moves to trash.',
				],
				'dry_run' => [
					'required'    => false,
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'When true, return the change plan without mutating state.',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/meta/find-icon', [
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

		register_rest_route( self::REST_NAMESPACE, '/page/update-content/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'page_update_content' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'id'      => [ 'required' => true ],
				'content' => [
					'required' => true,
					'type'     => 'string',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/page/set-meta/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'page_set_meta' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'id'       => [ 'required' => true ],
				'template' => [ 'required' => false, 'type' => 'string' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/page/trash/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'page_trash' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'id'      => [ 'required' => true ],
				'force'   => [
					'required'    => false,
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'When true, permanently delete (wp_delete_post). Default false moves to trash.',
				],
				'dry_run' => [
					'required'    => false,
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'When true, return the change plan without mutating state.',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/page/update-status/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'page_update_status' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'id'       => [ 'required' => true ],
				'status'   => [
					'required'    => true,
					'type'        => 'string',
					'enum'        => [ 'publish', 'draft', 'private', 'pending', 'future' ],
					'description' => 'Target post status.',
				],
				'date_gmt' => [
					'required'    => false,
					'type'        => 'string',
					'description' => 'Required when status="future" (ISO 8601 UTC). Future dates only.',
				],
				'dry_run'  => [
					'required'    => false,
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'When true, return the change plan without mutating state.',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/section/append/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'section_append' ],
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

		register_rest_route( self::REST_NAMESPACE, '/section/replace/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'section_replace' ],
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

		register_rest_route( self::REST_NAMESPACE, '/section/remove/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'section_remove' ],
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

		register_rest_route( self::REST_NAMESPACE, '/section/get/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'section_get' ],
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

		register_rest_route( self::REST_NAMESPACE, '/module/update/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'module_update' ],
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

		register_rest_route( self::REST_NAMESPACE, '/module/move/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'module_move' ],
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
		// label/match_text/auto_index pattern as module_update so callers reuse
		// the same mental model.
		register_rest_route( self::REST_NAMESPACE, '/module/lock/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'module_lock' ],
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

		register_rest_route( self::REST_NAMESPACE, '/module/unlock/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'module_unlock' ],
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

		register_rest_route( self::REST_NAMESPACE, '/module/clone/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'module_clone' ],
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

		// /render + /validate/blocks: accept EITHER `content` (inline markup)
		// OR `page_id` (load post_content from DB). Exactly-one contract is
		// enforced in the handler via self::resolve_content_or_page_id(); both
		// args are 'required' => false at the REST layer so the resolver can
		// emit the typed `invalid_input` envelope (which beats a generic
		// rest_missing_callback_param 400 from the REST framework).
		register_rest_route( self::REST_NAMESPACE, '/render', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'render_block_markup' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'content' => [
					'required'          => false,
					'type'              => 'string',
				],
				'page_id' => [
					'required' => false,
					'type'     => 'integer',
					// No `absint` sanitize: it coerces -1 → 1 (a valid post),
					// masking the negative-input case. We validate >0 in the
					// handler via self::resolve_content_or_page_id() to keep
					// the explicit invalid_input branch reachable.
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/validate/blocks', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'validate_blocks' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'content' => [
					'required'          => false,
					'type'              => 'string',
				],
				'page_id' => [
					'required' => false,
					'type'     => 'integer',
					// No `absint` sanitize: it coerces -1 → 1 (a valid post),
					// masking the negative-input case. We validate >0 in the
					// handler via self::resolve_content_or_page_id() to keep
					// the explicit invalid_input branch reachable.
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/page/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'page_create' ],
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
			'callback'            => [ __CLASS__, 'canvas_create' ],
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

		register_rest_route( self::REST_NAMESPACE, '/canvas/list', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'canvas_list' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'parent_page_id' => [ 'required' => false, 'type' => 'integer' ],
				'per_page'       => [ 'required' => false, 'type' => 'integer', 'default' => 50 ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/canvas/get/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'canvas_get' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/canvas/update/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'canvas_update' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'content'        => [ 'required' => false, 'type' => 'string' ],
				'title'          => [ 'required' => false, 'type' => 'string' ],
				'append_to_main' => [ 'required' => false, 'type' => 'string' ],
				'z_index'        => [ 'required' => false, 'type' => 'integer' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/canvas/delete/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'canvas_delete' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/canvas/duplicate/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'canvas_duplicate' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'title'   => [ 'required' => false, 'type' => 'string' ],
				'dry_run' => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

		// ── Variable Manager CRUD ──────────────────────────────────────
		register_rest_route( self::REST_NAMESPACE, '/variable/list', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'variable_list' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'type'   => [ 'required' => false, 'type' => 'string' ],
				'prefix' => [ 'required' => false, 'type' => 'string' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/variable/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'variable_create' ],
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
			'callback'            => [ __CLASS__, 'variable_delete' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'id'    => [ 'required' => true, 'type' => 'string' ],
				'force' => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/variable/scan-orphans', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'variable_scan_orphans' ],
			// Admin-only: response correlates variable IDs with page titles — inventory-leak risk (matches /preset/scan-orphans).
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/variable/create-fluid-system', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'variable_create_fluid_system' ],
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

		register_rest_route( self::REST_NAMESPACE, '/variable/used-on-page/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'variable_used_on_page' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'id' => [ 'required' => true, 'type' => 'integer' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/meta/flush-cache', [
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

	// ── Admin Settings Page ─────────────────────────────────────

	public static function register_admin_page() {
		add_menu_page(
			'DiviOps',
			'DiviOps',
			'manage_options',
			'diviops',
			[ __CLASS__, 'render_admin_page' ],
			self::admin_menu_icon(),
			81
		);
	}

	private static function admin_menu_icon(): string {
		$svg_path = plugin_dir_path( __FILE__ ) . 'assets/diviops-mark.svg';
		if ( ! is_readable( $svg_path ) ) {
			return 'dashicons-rest-api';
		}
		$svg = file_get_contents( $svg_path );
		if ( false === $svg ) {
			return 'dashicons-rest-api';
		}
		$svg = str_replace( 'fill="black"', 'fill="#f0f0f1"', $svg );
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
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

		// Pro status.
		$pro_active  = class_exists( 'DiviOps_Agent_Pro' );
		$pro_version = $pro_active && defined( 'DiviOps_Agent_Pro::VERSION' ) ? constant( 'DiviOps_Agent_Pro::VERSION' ) : null;
		$pro_url     = add_query_arg( [ 'page' => 'diviops-pro-license' ], admin_url( 'admin.php' ) );

		$free_release_url = 'https://github.com/oaris-dev/diviops/releases/latest';
		$docs_url         = 'https://diviops.com/docs/';
		$brand_logo_url   = plugins_url( 'assets/diviops-wordmark.svg', __FILE__ );

		?>
		<div class="wrap">
			<h1 class="screen-reader-text"><?php esc_html_e( 'DiviOps', 'diviops-agent' ); ?></h1>
			<div style="clear:both;margin:20px 0 24px;max-width:1120px;">
				<img src="<?php echo esc_url( $brand_logo_url ); ?>" alt="<?php esc_attr_e( 'DiviOps', 'diviops-agent' ); ?>" width="166" height="42" style="display:block;width:166px;max-width:100%;height:auto;" />
				<p style="margin:12px 0 0;max-width:760px;"><?php esc_html_e( 'AI agent bridge for Divi 5 — connects Claude Code, Codex, and other MCP clients to your WordPress site.', 'diviops-agent' ); ?></p>
			</div>

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

				<?php // ── Pro ── ?>
				<div class="card" style="padding:16px 20px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'DiviOps Pro', 'diviops-agent' ); ?></h2>
					<?php if ( $pro_active ) : ?>
						<p><span style="color:#46b450;">&#10003;</span> <?php esc_html_e( 'Active', 'diviops-agent' ); ?><?php echo $pro_version ? ' (v' . esc_html( $pro_version ) . ')' : ''; ?></p>
						<p class="description"><?php esc_html_e( 'Pro coverage slices and update/support licensing are managed separately.', 'diviops-agent' ); ?></p>
						<p><a href="<?php echo esc_url( $pro_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Manage Pro License', 'diviops-agent' ); ?></a></p>
					<?php else : ?>
						<p><span style="color:#999;">&#8212;</span> <?php esc_html_e( 'Not installed', 'diviops-agent' ); ?></p>
						<p class="description"><?php esc_html_e( 'Optional Pro plugin for paid coverage slices and Pro update access.', 'diviops-agent' ); ?></p>
					<?php endif; ?>
				</div>

				<?php // ── Updates ── ?>
				<div class="card" style="padding:16px 20px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Free Plugin Updates', 'diviops-agent' ); ?></h2>
					<p><?php esc_html_e( 'During beta, the Free WordPress plugin updates manually from the latest release ZIP. Native WordPress.org update notices are planned but not live yet.', 'diviops-agent' ); ?></p>
					<ol style="margin-left:18px;">
						<li><?php esc_html_e( 'Download the latest diviops-agent.zip.', 'diviops-agent' ); ?></li>
						<li><?php esc_html_e( 'Upload it in Plugins → Add New → Upload Plugin.', 'diviops-agent' ); ?></li>
						<li><?php esc_html_e( 'Choose Replace current with uploaded when WordPress asks.', 'diviops-agent' ); ?></li>
					</ol>
					<p>
						<a href="<?php echo esc_url( $free_release_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary"><?php esc_html_e( 'Latest Free Release', 'diviops-agent' ); ?></a>
						<a href="<?php echo esc_url( $docs_url ); ?>" target="_blank" rel="noopener noreferrer" class="button"><?php esc_html_e( 'Setup Guide', 'diviops-agent' ); ?></a>
					</p>
					<p class="description"><?php esc_html_e( 'Your Application Password and MCP client configuration stay unchanged after replacing the plugin ZIP.', 'diviops-agent' ); ?></p>
				</div>

			</div>

			<div style="margin-top:24px;">
				<h2><?php esc_html_e( 'Getting Started', 'diviops-agent' ); ?></h2>
				<p>
					<?php esc_html_e( 'DiviOps works through the MCP server. Install the server from npm, connect it with a WordPress Application Password, then test the connection from your AI client.', 'diviops-agent' ); ?>
				</p>
				<ol>
					<li><?php esc_html_e( 'Install the DiviOps skill bundle for your AI client.', 'diviops-agent' ); ?></li>
					<li>
						<?php esc_html_e( 'Register the MCP server:', 'diviops-agent' ); ?>
						<code>claude mcp add diviops-mysite --env WP_URL=https://example.com --env WP_USER=admin --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX -- npx -y --package @diviops/mcp-server diviops-mcp</code>
					</li>
					<li>
						<?php esc_html_e( 'Test: ask Claude Code to', 'diviops-agent' ); ?>
						<em>&ldquo;Use diviops_meta_ping to verify the MCP is working&rdquo;</em>
					</li>
				</ol>
				<p>
					<a href="https://diviops.com/docs/" target="_blank" rel="noopener noreferrer" class="button button-secondary"><?php esc_html_e( 'Documentation & Setup Guide', 'diviops-agent' ); ?></a>
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
