<?php
/**
 * Plugin Name: DiviOps Agent
 * Plugin URI: https://github.com/oaris-dev/diviops
 * Description: REST API bridge for DiviOps — connects Claude Code to your Divi 5 site for AI-powered page building and design management.
 * Version: 1.5.16
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
require_once __DIR__ . '/includes/trait-authoring-shape.php';
require_once __DIR__ . '/includes/trait-compatibility.php';
require_once __DIR__ . '/includes/trait-core.php';
require_once __DIR__ . '/includes/trait-global-color.php';
require_once __DIR__ . '/includes/trait-global-font.php';
require_once __DIR__ . '/includes/trait-library.php';
require_once __DIR__ . '/includes/trait-meta.php';
require_once __DIR__ . '/includes/trait-module-schema.php';
require_once __DIR__ . '/includes/trait-menu.php';
require_once __DIR__ . '/includes/trait-page.php';
require_once __DIR__ . '/includes/trait-preset.php';
require_once __DIR__ . '/includes/trait-render.php';
require_once __DIR__ . '/includes/trait-rollback.php';
require_once __DIR__ . '/includes/trait-seo.php';
require_once __DIR__ . '/includes/trait-theme-builder.php';
require_once __DIR__ . '/includes/trait-validate.php';
require_once __DIR__ . '/includes/trait-variable.php';


class DiviOps_Agent {

	// ── Trait composition (see #220 split) ──────────────────────
	// Each trait contributes a slice of the REST surface. The traits
	// are required in the file-scope bootstrap below; methods on each
	// trait are mixed into this class.
	use DiviOps_Agent_Canvas;
	use DiviOps_Agent_AuthoringShape;
	use DiviOps_Agent_Compatibility;
	use DiviOps_Agent_Core;
	use DiviOps_Agent_GlobalColor;
	use DiviOps_Agent_GlobalFont;
	use DiviOps_Agent_Library;
	use DiviOps_Agent_Meta;
	use DiviOps_Agent_Menu;
	use DiviOps_Agent_ModuleSchema;
	use DiviOps_Agent_Page;
	use DiviOps_Agent_Preset;
	use DiviOps_Agent_Render;
	use DiviOps_Agent_Rollback;
	use DiviOps_Agent_SEO;
	use DiviOps_Agent_ThemeBuilder;
	use DiviOps_Agent_Validate;
	use DiviOps_Agent_Variable;

	/**
	 * Plugin version — surfaced in /handshake for self-diagnosis only;
	 * server no longer gates on it (capability map is the gate).
	 */
	const VERSION = '1.5.16';

	/**
	 * Minimum MCP server version this plugin is compatible with.
	 */
	const MIN_SERVER_VERSION = '1.1.0';

	/**
	 * Per-tool capability map emitted by /handshake.
	 *
	 * Each key is a post-rename MCP tool name slug (without the
	 * `diviops_` prefix) or a precise additive behavior capability for
	 * a backwards-compatible route extension. The server's `requireCapability(<key>)`
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
		'canvas_create', 'canvas_delete', 'canvas_duplicate', 'canvas_get', 'canvas_list', 'canvas_orphan_audit', 'canvas_update',
		// global colors / fonts
		'global_color_audit_storage', 'global_color_create', 'global_color_delete', 'global_color_list', 'global_color_update',
		'global_font_audit_storage', 'global_font_create', 'global_font_delete', 'global_font_list', 'global_font_update',
		// library
		'library_get', 'library_list', 'library_save',
		// meta
		'meta_find_icon', 'meta_flush_cache',
		// menu
		'menu_create', 'menu_get', 'menu_item_add_custom', 'menu_item_add_page', 'menu_list', 'menu_location_assign',
		// module
		'module_clone', 'module_get', 'module_lock', 'module_move', 'module_unlock', 'module_update',
		'module_clone_backup', 'module_lock_backup', 'module_move_backup', 'module_unlock_backup', 'module_update_backup',
		// page
		'page_create', 'page_get', 'page_get_layout', 'page_list',
		'page_trash', 'page_update_content', 'page_update_content_backup', 'page_update_content_expected_checksum', 'page_update_meta', 'page_update_status',
		// preset
		'preset_audit', 'preset_audit_storage', 'preset_cleanup', 'preset_create', 'preset_delete', 'preset_inspect', 'preset_registry_doctor',
		'preset_reassign', 'preset_scan_orphans', 'preset_set_default', 'preset_update',
		// render
		'render_preview',
		// rollback snapshots
		'rollback_snapshot_delete', 'rollback_snapshot_get', 'rollback_snapshot_list', 'rollback_snapshot_restore',
		// semantic SEO metadata
		'seo_provider_list', 'seo_metadata_get', 'seo_metadata_update',
		// schema
		'schema_get_module', 'schema_get_module_dump_all', 'schema_get_settings', 'schema_list_modules',
		// section
		'section_append', 'section_append_backup', 'section_get', 'section_remove', 'section_remove_backup', 'section_replace', 'section_replace_backup',
		// theme builder
		'cross_env_source_export_get', 'cross_env_target_context_get', 'cross_env_footer_layout_evidence',
		'tb_layout_block_insert', 'tb_layout_block_insert_backup', 'tb_layout_get', 'tb_layout_update', 'tb_layout_update_backup', 'tb_template_create', 'tb_template_list',
		'tb_template_trash',
		// validate
		'validate_blocks',
		// page_id overload on validate_blocks + render_preview (#700) —
		// single bundle key; both tools accept exactly-one of {content, page_id}.
		'validate_render_by_page_id',
		// variable
		'variable_create', 'variable_create_fluid_system', 'variable_delete',
		'variable_list', 'variable_scan_orphans', 'variable_used_on_page',
		// Sub-feature: structured `gradient` input on variable_create serializes
		// the canonical $variable(gradient) token (#921). Gated separately so a
		// new server fails loud against a plugin too old to serialize it.
		'variable_create_gradient',
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

	/** PHP 7.4-compatible constants consumed by the compatibility trait. */
	private const DIVI_POST_FILTER_PRICE_ROUTE = '/divi/v1/loop/product-price-range';
	private const DIVI_POST_FILTER_PRICE_NONCE_ROUTE = '/loop/product-price-range';
	private const DIVI_REST_NAMESPACE = 'divi/v1';
	private const DIVI_POST_FILTER_PRICE_METHOD = 'GET';
	private const DIVI_POST_FILTER_PRICE_CONTROLLER = 'ET\Builder\Packages\ModuleLibrary\PostFilterItem\PostFilterProductPriceRangeController';
	private const DIVI_USER_ROLE_CLASS = 'ET\Builder\Framework\UserRole\UserRole';

	/** PHP 7.4-compatible limits consumed by the authoring-shape trait. */
	private const AUTHORING_SHAPE_LIMITS = [
		'input_bytes' => 1048576, 'blocks' => 4096, 'depth' => 64, 'fields' => 8192,
		'string_bytes' => 1048576,
	];

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_filter( 'rest_endpoints', [ __CLASS__, 'repair_divi_post_filter_price_permission' ] );
		add_filter( 'rest_pre_dispatch', [ __CLASS__, 'check_rate_limit' ], 10, 3 );
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'wrap_rest_framework_validation_errors' ], 10, 3 );
		add_action( 'admin_menu', [ __CLASS__, 'register_admin_page' ] );
	}

	/**
	 * Wrap WordPress REST schema-validation errors in the DiviOps envelope.
	 *
	 * Route arg validation runs before endpoint callbacks, so typed args and
	 * enums can otherwise leak raw WP REST errors on `/diviops/v1/*` routes.
	 * Keep route schemas intact and normalize only framework validation errors.
	 *
	 * @param WP_REST_Response $response REST response after dispatch.
	 * @param WP_REST_Server   $server   REST server instance.
	 * @param WP_REST_Request  $request  Current request.
	 * @return WP_REST_Response
	 */
	public static function wrap_rest_framework_validation_errors( $response, $server, $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $response;
		}

		$route            = (string) $request->get_route();
		$namespace_prefix = '/' . self::REST_NAMESPACE;
		if ( $route !== $namespace_prefix && strpos( $route, $namespace_prefix . '/' ) !== 0 ) {
			return $response;
		}

		if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) ) {
			return $response;
		}

		$body = $response->get_data();
		if ( ! is_array( $body ) || ! isset( $body['code'] ) || ! isset( $body['message'] ) ) {
			return $response;
		}

		$wp_error_code = (string) $body['code'];
		if ( ! in_array( $wp_error_code, [ 'rest_invalid_param', 'rest_missing_callback_param' ], true ) ) {
			return $response;
		}

		$wp_error_data = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : [];
		$http_status   = isset( $wp_error_data['status'] ) ? (int) $wp_error_data['status'] : 400;
		$error_data    = [
			'wp_error_code' => $wp_error_code,
		];

		foreach ( $wp_error_data as $key => $value ) {
			if ( in_array( $key, [ 'status', 'hint' ], true ) ) {
				continue;
			}
			$error_data[ $key ] = $value;
		}

		return self::envelope_error(
			'invalid_input',
			(string) $body['message'],
			'Fix the request parameters named in error.data, then retry.',
			$http_status,
			$error_data
		);
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

	/**
	 * Statuses intentionally supported by DiviOps page create/status routes.
	 *
	 * WordPress core can register additional workflow statuses, but accepting
	 * every non-internal status here would bypass the status-specific contract
	 * enforced by WP_REST_Posts_Controller::handle_status_param().
	 *
	 * @return string[]
	 */
	private static function supported_page_statuses(): array {
		return [ 'draft', 'pending', 'publish', 'future', 'private' ];
	}

	private static function page_status_requires_publish_capability( string $status ): bool {
		return in_array( $status, [ 'publish', 'future', 'private' ], true );
	}

	/**
	 * Convert a route-style capability error into the canonical DiviOps refusal.
	 *
	 * @param WP_Error $error Permission error from a request-aware guard.
	 * @return WP_REST_Response
	 */
	private static function post_type_permission_refusal( $error ) {
		$data = is_array( $error->get_error_data() ) ? $error->get_error_data() : [];
		unset( $data['status'] );
		return self::envelope_error(
			'forbidden',
			(string) $error->get_error_message(),
			'Authenticate as a user with the required content capability, then retry.',
			403,
			empty( $data ) ? null : $data
		);
	}

	/**
	 * Require mapped creation and publishing capabilities for fixed-publish CPT writes.
	 *
	 * @param string[] $post_types Post types the operation will create.
	 * @return true|WP_Error
	 */
	private static function published_post_types_permission_result( array $post_types ) {
		foreach ( array_values( array_unique( $post_types ) ) as $post_type_name ) {
			$post_type = get_post_type_object( $post_type_name );
			if ( ! $post_type || ! isset( $post_type->cap->create_posts, $post_type->cap->publish_posts ) ) {
				return new WP_Error(
					'rest_cannot_create',
					'Sorry, this content type does not expose the capabilities required for creation.',
					[ 'status' => 403, 'post_type' => $post_type_name ]
				);
			}
			foreach ( [ 'create_posts', 'publish_posts' ] as $cap_key ) {
				$capability = (string) $post_type->cap->{$cap_key};
				if ( '' === $capability || ! current_user_can( $capability ) ) {
					return new WP_Error(
						'create_posts' === $cap_key ? 'rest_cannot_create' : 'rest_cannot_publish',
						'Sorry, you are not allowed to create published content of this type.',
						[
							'status'              => 403,
							'post_type'           => $post_type_name,
							'required_capability' => $capability,
						]
					);
				}
			}
		}

		return true;
	}

	private static function fixed_publish_route_permission( string $base_capability, array $post_types ) {
		if ( ! current_user_can( $base_capability ) ) {
			return new WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to perform this operation.', [ 'status' => 403 ] );
		}
		return self::published_post_types_permission_result( $post_types );
	}

	public static function check_canvas_create_permission() {
		return self::fixed_publish_route_permission( 'edit_pages', [ 'et_pb_canvas' ] );
	}

	public static function check_library_save_permission() {
		return self::fixed_publish_route_permission( 'manage_options', [ 'et_pb_layout' ] );
	}

	public static function check_tb_template_create_permission( $request ) {
		$post_types = [ 'et_theme_builder', 'et_template' ];
		$header_content = $request->get_param( 'header_content' );
		$footer_content = $request->get_param( 'footer_content' );
		if ( is_string( $header_content ) && '' !== $header_content ) {
			$post_types[] = 'et_header_layout';
		}
		if ( is_string( $footer_content ) && '' !== $footer_content ) {
			$post_types[] = 'et_footer_layout';
		}
		return self::fixed_publish_route_permission( 'manage_options', $post_types );
	}

	/**
	 * Resolve the request-aware page-create capability refusal, if any.
	 *
	 * @param WP_REST_Request|ArrayAccess $request REST-like request.
	 * @return true|WP_Error
	 */
	private static function page_create_permission_result( $request ) {
		$status = sanitize_key( (string) ( $request->get_param( 'status' ) ?? 'draft' ) );
		if ( ! in_array( $status, self::supported_page_statuses(), true ) ) {
			return new WP_Error(
				'rest_invalid_param',
				'Status is not supported for DiviOps page creation.',
				[ 'status' => 400 ]
			);
		}

		$post_type = get_post_type_object( 'page' );
		if ( ! $post_type || ! isset( $post_type->cap->create_posts ) ) {
			return new WP_Error(
				'rest_cannot_create',
				'The page post type does not expose the capabilities required for creation.',
				[ 'status' => 403 ]
			);
		}

		if ( ! current_user_can( $post_type->cap->create_posts ) ) {
			return new WP_Error(
				'rest_cannot_create',
				'Sorry, you are not allowed to create pages.',
				[
					'status'              => 403,
					'required_capability' => (string) $post_type->cap->create_posts,
				]
			);
		}

		$publish_capability = isset( $post_type->cap->publish_posts ) ? (string) $post_type->cap->publish_posts : '';
		if (
			self::page_status_requires_publish_capability( $status )
			&& ( '' === $publish_capability || ! current_user_can( $publish_capability ) )
		) {
			return new WP_Error(
				'rest_cannot_publish',
				'Sorry, you are not allowed to publish pages or create private pages.',
				[
					'status'              => 403,
					'required_capability' => $publish_capability,
					'requested_status'    => $status,
				]
			);
		}

		return true;
	}

	/**
	 * Request-aware REST permission callback for POST /page/create.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return true|WP_Error
	 */
	public static function check_page_create_permission( $request ) {
		return self::page_create_permission_result( $request );
	}

	/**
	 * Resolve page status-transition authority before a plan or mutation.
	 *
	 * @param WP_REST_Request|ArrayAccess $request REST-like request.
	 * @return true|WP_Error
	 */
	private static function page_update_status_permission_result( $request ) {
		$post_id = absint( $request['id'] ?? 0 );
		$status  = sanitize_key( (string) $request->get_param( 'status' ) );

		if ( ! in_array( $status, self::supported_page_statuses(), true ) ) {
			return new WP_Error(
				'rest_invalid_param',
				'Status is not supported for DiviOps page status updates.',
				[ 'status' => 400 ]
			);
		}

		$post = get_post( $post_id );
		if ( ! $post || 'page' !== (string) $post->post_type ) {
			return new WP_Error(
				'rest_cannot_edit',
				'Sorry, you are not allowed to edit this page.',
				[ 'status' => 403 ]
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_cannot_edit',
				'Sorry, you are not allowed to edit this page.',
				[ 'status' => 403 ]
			);
		}

		$post_type = get_post_type_object( 'page' );
		if ( ! $post_type ) {
			return new WP_Error(
				'rest_cannot_edit',
				'The page post type does not expose the capability required for status updates.',
				[ 'status' => 403 ]
			);
		}

		$publish_capability = isset( $post_type->cap->publish_posts ) ? (string) $post_type->cap->publish_posts : '';
		if (
			self::page_status_requires_publish_capability( $status )
			&& ( '' === $publish_capability || ! current_user_can( $publish_capability ) )
		) {
			return new WP_Error(
				'rest_cannot_publish',
				'Sorry, you are not allowed to publish pages or make pages private.',
				[
					'status'              => 403,
					'required_capability' => $publish_capability,
					'requested_status'    => $status,
				]
			);
		}

		return true;
	}

	/**
	 * Request-aware REST permission callback for POST /page/update-status/{id}.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return true|WP_Error
	 */
	public static function check_page_update_status_permission( $request ) {
		return self::page_update_status_permission_result( $request );
	}

	public static function check_authenticated_permission() {
		return get_current_user_id() > 0;
	}

	public static function check_admin_permission() {
		return current_user_can( 'manage_options' );
	}

	public static function check_menu_permission() {
		return current_user_can( 'edit_theme_options' );
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

		register_rest_route( self::REST_NAMESPACE, '/seo/provider/list', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'seo_provider_list' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/seo/metadata/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'seo_metadata_get' ],
			// Preserve the handler's canonical target-specific error envelope.
			'permission_callback' => [ __CLASS__, 'check_authenticated_permission' ],
			'args'                => [
				'id'       => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
				'provider' => [
					'required' => false,
					'type'     => 'string',
					'enum'     => [ 'auto', 'tsf' ],
					'default'  => 'auto',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/seo/metadata/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'seo_metadata_update' ],
			// The handler enforces edit_post before any provider payload read.
			'permission_callback' => [ __CLASS__, 'check_authenticated_permission' ],
			'args'                => [
				'id'                => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
				'provider'          => [
					'required' => false,
					'type'     => 'string',
					'enum'     => [ 'auto', 'tsf' ],
					'default'  => 'auto',
				],
				'expected_checksum' => [
					'required' => true,
					'type'     => 'string',
					'pattern'  => '^sha256:[a-f0-9]{64}$',
				],
				'changes'            => [
					'required' => true,
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 2,
					'items'    => [
						'type'                 => 'object',
						'required'             => [ 'field', 'action' ],
						'additionalProperties' => false,
						'properties'           => [
							'field'  => [ 'type' => 'string', 'enum' => [ 'seo_title', 'meta_description' ] ],
							'action' => [ 'type' => 'string', 'enum' => [ 'set', 'clear' ] ],
							'value'  => [ 'type' => 'string' ],
						],
					],
				],
				'dry_run'            => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

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

		register_rest_route( self::REST_NAMESPACE, '/preset/inspect/(?P<preset_id>[^/]+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'preset_inspect' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'preset_id' => [ 'required' => true, 'type' => 'string' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/preset/registry-doctor', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'preset_registry_doctor' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'repair'                 => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'clear_chunk_transients' => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'dry_run'                => [ 'required' => false, 'type' => 'boolean', 'default' => true ],
				'limit'                  => [ 'required' => false, 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 500 ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/rollback-snapshot/list', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'rollback_snapshot_list' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'target_kind' => [ 'required' => false, 'type' => 'string' ],
				'target_id'   => [ 'required' => false, 'type' => 'integer' ],
				'status'      => [ 'required' => false, 'type' => 'string' ],
				'limit'       => [ 'required' => false, 'type' => 'integer', 'default' => 20 ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/rollback-snapshot/get/(?P<snapshot_id>[^/]+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'rollback_snapshot_get' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'snapshot_id'   => [ 'required' => true, 'type' => 'string' ],
				'include_value' => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/rollback-snapshot/delete/(?P<snapshot_id>[^/]+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rollback_snapshot_delete' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'snapshot_id' => [ 'required' => true, 'type' => 'string' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/rollback-snapshot/restore/(?P<snapshot_id>[^/]+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rollback_snapshot_restore' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'snapshot_id' => [ 'required' => true, 'type' => 'string' ],
				'dry_run'     => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
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
				'page'        => [ 'required' => false, 'type' => 'integer', 'default' => 1 ],
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
			'permission_callback' => [ __CLASS__, 'check_library_save_permission' ],
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

		register_rest_route( self::REST_NAMESPACE, '/cross-env/target-context', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'cross_env_target_context_get' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'destination_id'        => [ 'required' => false ],
				'destination_kind'      => [ 'required' => false, 'type' => 'string', 'default' => 'tb_header_layout' ],
				'source_asset_hints'    => [ 'required' => false ],
				'source_upload_paths'   => [ 'required' => false ],
				'source_attachment_ids' => [ 'required' => false ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/cross-env/source-export', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'cross_env_source_export_get' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'source_id'   => [ 'required' => false ],
				'source_kind' => [ 'required' => false, 'type' => 'string', 'default' => 'tb_header_layout' ],
				'dry_run'     => [ 'required' => false, 'type' => 'boolean', 'default' => true ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/theme-builder/layout/update/(?P<id>\d+)', [
			'methods'             => 'PUT',
			'callback'            => [ __CLASS__, 'tb_layout_update' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'content' => [ 'required' => true, 'type' => 'string' ],
				'dry_run' => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'backup'  => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
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
				'backup'          => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/theme-builder/template/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'tb_template_create' ],
			'permission_callback' => [ __CLASS__, 'check_tb_template_create_permission' ],
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

		register_rest_route( self::REST_NAMESPACE, '/menu/list', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'menu_list' ],
			'permission_callback' => [ __CLASS__, 'check_menu_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/menu/get/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'menu_get' ],
			'permission_callback' => [ __CLASS__, 'check_menu_permission' ],
			'args'                => [
				'id' => [ 'required' => true, 'type' => 'integer' ],
			],
		] );

		// ── Write Operations ─────────────────────────────────────────

		register_rest_route( self::REST_NAMESPACE, '/menu/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'menu_create' ],
			'permission_callback' => [ __CLASS__, 'check_menu_permission' ],
			'args'                => [
				'name'    => [ 'required' => true, 'type' => 'string' ],
				'slug'    => [ 'required' => false, 'type' => 'string' ],
				'dry_run' => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/menu/item/add-page', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'menu_item_add_page' ],
			'permission_callback' => [ __CLASS__, 'check_menu_permission' ],
			'args'                => [
				'menu_id'        => [ 'required' => true, 'type' => 'integer' ],
				'page_id'        => [ 'required' => true, 'type' => 'integer' ],
				'label'          => [ 'required' => false, 'type' => 'string' ],
				'parent_item_id' => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
				'dry_run'        => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/menu/item/add-custom', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'menu_item_add_custom' ],
			'permission_callback' => [ __CLASS__, 'check_menu_permission' ],
			'args'                => [
				'menu_id'        => [ 'required' => true, 'type' => 'integer' ],
				'label'          => [ 'required' => true, 'type' => 'string' ],
				'url'            => [ 'required' => true, 'type' => 'string' ],
				'parent_item_id' => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
				'dry_run'        => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/menu/location/assign', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'menu_location_assign' ],
			'permission_callback' => [ __CLASS__, 'check_menu_permission' ],
			'args'                => [
				'menu_id'  => [ 'required' => true, 'type' => 'integer' ],
				'location' => [ 'required' => true, 'type' => 'string' ],
				'dry_run'  => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

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
				'expected_checksum' => [
					'required' => false,
					'type'     => 'string',
					'pattern'  => '^sha256:[a-f0-9]{64}$',
				],
				'dry_run' => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'backup'  => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/page/update-meta/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'page_update_meta' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'id'                => [ 'required' => true ],
				'title'             => [ 'required' => false, 'type' => 'string' ],
				'post_title'        => [ 'required' => false, 'type' => 'string' ],
				'slug'              => [ 'required' => false, 'type' => 'string' ],
				'post_name'         => [ 'required' => false, 'type' => 'string' ],
				'parent'            => [ 'required' => false, 'type' => 'integer' ],
				'post_parent'       => [ 'required' => false, 'type' => 'integer' ],
				'menu_order'        => [ 'required' => false, 'type' => 'integer' ],
				'preserve_old_slug' => [
					'required'    => false,
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'When true, record the previous slug in _wp_old_slug for published posts when slug changes.',
				],
				'dry_run'           => [
					'required'    => false,
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'When true, return the change plan without mutating state.',
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
			'permission_callback' => [ __CLASS__, 'check_page_update_status_permission' ],
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
				'dry_run'  => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'backup'   => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
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
				'dry_run'    => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'backup'     => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
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
				'dry_run'    => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'backup'     => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
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
					'description' => 'Text content to search for in innerContent (case-insensitive substring match, first match wins). Prefer auto_index for generic or repeated text; content-slot mismatches are rejected instead of silently storing never-rendered attrs.',
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
				'dry_run'     => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'backup'      => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/module/get/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'module_get' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'id'         => [ 'required' => true ],
				'label'      => [
					'required'    => false,
					'type'        => 'string',
					'description' => 'Admin label of the module to retrieve (exact match)',
				],
				'match_text' => [
					'required'    => false,
					'type'        => 'string',
					'description' => 'Text content to search for in module attrs/innerContent (case-insensitive substring match, first match wins)',
				],
				'auto_index' => [
					'required'    => false,
					'type'        => 'string',
					'description' => 'Auto-index target in "type:N" format (e.g. "text:5", "icon:3").',
				],
				'occurrence' => [
					'default'           => 1,
					'type'              => 'integer',
					'description'       => 'Which occurrence to target when multiple modules share the same label (1-based). Only used with label targeting.',
					'sanitize_callback' => 'absint',
				],
				'full'       => [
					'default'           => false,
					'type'              => 'boolean',
					'description'       => 'Include decoded attrs and raw serialized block markup for the matched module only.',
					'sanitize_callback' => 'rest_sanitize_boolean',
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
				'dry_run'  => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'backup'   => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
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
				'dry_run'    => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'backup'     => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
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
				'dry_run'    => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'backup'     => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
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
				'dry_run'    => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'backup'     => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
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
			'permission_callback' => [ __CLASS__, 'check_page_create_permission' ],
			'args'                => [
				'title'   => [ 'required' => true, 'type' => 'string' ],
				'content' => [ 'required' => false, 'type' => 'string', 'default' => '' ],
				'status'  => [
					'required' => false,
					'type'     => 'string',
					'default'  => 'draft',
					'enum'     => [ 'draft', 'pending', 'publish', 'future', 'private' ],
				],
				'dry_run' => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );

		// ── Canvas Operations ────────────────────────────────────────

		register_rest_route( self::REST_NAMESPACE, '/canvas/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'canvas_create' ],
			'permission_callback' => [ __CLASS__, 'check_canvas_create_permission' ],
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
				'page'           => [ 'required' => false, 'type' => 'integer', 'default' => 1 ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/canvas/orphan-audit', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'canvas_orphan_audit' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			'args'                => [
				'parent_page_id'  => [ 'required' => false, 'type' => 'integer' ],
				'include_global'  => [ 'required' => false, 'type' => 'boolean', 'default' => true ],
				'include_context' => [ 'required' => false, 'type' => 'boolean', 'default' => true ],
				'status'          => [ 'required' => false, 'type' => 'string', 'default' => 'any' ],
				'per_page'        => [ 'required' => false, 'type' => 'integer', 'default' => 100 ],
				'page'            => [ 'required' => false, 'type' => 'integer', 'default' => 1 ],
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
			'permission_callback' => [ __CLASS__, 'check_canvas_create_permission' ],
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
				// Structured gradient settings for type=gradients — the callback
				// serializes the canonical $variable(gradient) token (#921).
				'gradient'          => [ 'required' => false, 'type' => 'object' ],
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
				'post_id'                => [ 'required' => false, 'type' => 'integer' ],
				'all'                    => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'after'                  => [ 'required' => false, 'type' => 'integer' ],
				'cleanup_dynamic_assets' => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'cleanup_canvas_refs'    => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'dry_run'                => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
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

	private static function admin_rollback_snapshot_badge_style( array $snapshot ): string {
		if ( ! empty( $snapshot['cleanup']['deleted_at'] ) ) {
			return 'background:rgb(240,240,241);color:rgb(80,87,94);border:1px solid rgb(195,196,199);';
		}
		if ( ! empty( $snapshot['restore']['restored_at'] ) ) {
			return 'background:#e7f5ea;color:#0a6b24;border:1px solid #8bd19a;';
		}
		if ( ! empty( $snapshot['expired'] ) ) {
			return 'background:#fcf0f1;color:#8a2424;border:1px solid #e0a4a4;';
		}
		if ( ! empty( $snapshot['interrupted'] ) ) {
			return 'background:#fff8e5;color:#7a5600;border:1px solid #e5c46b;';
		}

		$status = sanitize_key( (string) ( $snapshot['status'] ?? '' ) );
		if ( 'write_applied' === $status ) {
			return 'background:#e7f5ea;color:#0a6b24;border:1px solid #8bd19a;';
		}
		if ( 'write_failed_restored' === $status ) {
			return 'background:rgb(232,240,254);color:rgb(23,78,166);border:1px solid rgb(158,192,255);';
		}
		if ( 'aborted_before_write' === $status ) {
			return 'background:#f6f7f7;color:#3c434a;border:1px solid #c3c4c7;';
		}
		return 'background:#fff8e5;color:#7a5600;border:1px solid #e5c46b;';
	}

	private static function admin_rollback_snapshot_badge_label( array $snapshot ): string {
		if ( ! empty( $snapshot['cleanup']['deleted_at'] ) ) {
			return __( 'deleted', 'diviops-agent' );
		}
		if ( ! empty( $snapshot['restore']['restored_at'] ) ) {
			return __( 'restored', 'diviops-agent' );
		}
		if ( ! empty( $snapshot['expired'] ) ) {
			return __( 'expired', 'diviops-agent' );
		}
		if ( ! empty( $snapshot['interrupted'] ) ) {
			return __( 'interrupted', 'diviops-agent' );
		}
		return str_replace( '_', ' ', sanitize_key( (string) ( $snapshot['status'] ?? 'created' ) ) );
	}

	private static function admin_rollback_snapshot_short_checksum( array $snapshot, string $phase ): string {
		$checksum = (string) ( $snapshot[ $phase ]['checksum'] ?? '' );
		if ( '' === $checksum ) {
			return '—';
		}
		$checksum = preg_replace( '/^sha256:/', '', $checksum );
		return substr( (string) $checksum, 0, 12 ) . '…';
	}

	private static function admin_rollback_snapshot_format_datetime( $value ): string {
		if ( empty( $value ) ) {
			return '—';
		}
		$timestamp = strtotime( (string) $value );
		if ( false === $timestamp ) {
			return '—';
		}
		$date_format = (string) get_option( 'date_format', 'Y-m-d' );
		$time_format = (string) get_option( 'time_format', 'H:i:s' );
		$format      = trim( $date_format . ' ' . $time_format );
		if ( '' === $format ) {
			$format = 'Y-m-d H:i:s';
		}
		return wp_date( $format, $timestamp );
	}

	private static function render_admin_rollback_snapshots_card( array $snapshots ): void {
		?>
		<div class="card" style="padding:16px 20px;grid-column:1/-1;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Rollback Backups', 'diviops-agent' ); ?></h2>
			<p class="description" style="margin-top:-4px;">
				<?php esc_html_e( 'Temporary rollback snapshots created by backup-enabled DiviOps content writes. These are not full site backups and this dashboard is read-only.', 'diviops-agent' ); ?>
			</p>
			<?php if ( empty( $snapshots ) ) : ?>
				<p style="margin:14px 0 0;"><?php esc_html_e( 'No rollback snapshots are currently visible for this user.', 'diviops-agent' ); ?></p>
			<?php else : ?>
				<div style="overflow-x:auto;margin-top:12px;">
					<table class="widefat striped" style="min-width:980px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Snapshot', 'diviops-agent' ); ?></th>
								<th><?php esc_html_e( 'Target', 'diviops-agent' ); ?></th>
								<th><?php esc_html_e( 'Operation', 'diviops-agent' ); ?></th>
								<th><?php esc_html_e( 'Status', 'diviops-agent' ); ?></th>
								<th><?php esc_html_e( 'Created / Expires', 'diviops-agent' ); ?></th>
								<th><?php esc_html_e( 'Created By', 'diviops-agent' ); ?></th>
								<th><?php esc_html_e( 'Checksums', 'diviops-agent' ); ?></th>
								<th><?php esc_html_e( 'State', 'diviops-agent' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $snapshots as $snapshot ) : ?>
								<?php
								$operation = self::rollback_snapshot_as_array( $snapshot['operation'] ?? [] );
								$target    = self::rollback_snapshot_as_array( $snapshot['target'] ?? [] );
								$created_by = self::rollback_snapshot_as_array( $snapshot['created_by'] ?? [] );
								$target_label = sprintf(
									'%s #%d',
									sanitize_key( (string) ( $target['post_type'] ?? $target['kind'] ?? 'post' ) ),
									absint( $target['id'] ?? 0 )
								);
								$operation_label = (string) ( $operation['tool_operation'] ?? $snapshot['tool'] ?? '' );
								if ( '' === $operation_label ) {
									$operation_label = '—';
								}
								$state_bits = [];
								if ( false === ( $target['exists'] ?? true ) ) {
									$state_bits[] = __( 'target missing', 'diviops-agent' );
								}
								if ( ! empty( $snapshot['restore']['restorable'] ) ) {
									$state_bits[] = __( 'restorable', 'diviops-agent' );
								}
								if ( ! empty( $snapshot['restore']['restored_at'] ) ) {
									$state_bits[] = __( 'restored', 'diviops-agent' );
								}
								if ( ! empty( $snapshot['cleanup']['deleted_at'] ) ) {
									$state_bits[] = __( 'deleted', 'diviops-agent' );
								}
								?>
								<tr>
									<td><code><?php echo esc_html( (string) ( $snapshot['snapshot_id'] ?? '' ) ); ?></code></td>
									<td><?php echo esc_html( $target_label ); ?></td>
									<td>
										<code><?php echo esc_html( $operation_label ); ?></code>
										<?php if ( ! empty( $snapshot['tool'] ) ) : ?>
											<br><span class="description"><?php echo esc_html( (string) $snapshot['tool'] ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<span style="display:inline-block;padding:2px 8px;border-radius:999px;<?php echo esc_attr( self::admin_rollback_snapshot_badge_style( $snapshot ) ); ?>">
											<?php echo esc_html( self::admin_rollback_snapshot_badge_label( $snapshot ) ); ?>
										</span>
									</td>
									<td>
										<?php echo esc_html( self::admin_rollback_snapshot_format_datetime( $snapshot['created_at'] ?? '' ) ); ?>
										<br><span class="description"><?php echo esc_html( self::admin_rollback_snapshot_format_datetime( $snapshot['expires_at'] ?? '' ) ); ?></span>
									</td>
									<td>
										<?php echo esc_html( (string) ( $created_by['login'] ?? '' ) ); ?>
										<?php if ( ! empty( $created_by['user_id'] ) ) : ?>
											<br><span class="description">#<?php echo esc_html( (string) absint( $created_by['user_id'] ) ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<span class="description"><?php esc_html_e( 'before', 'diviops-agent' ); ?></span> <code><?php echo esc_html( self::admin_rollback_snapshot_short_checksum( $snapshot, 'before' ) ); ?></code>
										<br><span class="description"><?php esc_html_e( 'after', 'diviops-agent' ); ?></span> <code><?php echo esc_html( self::admin_rollback_snapshot_short_checksum( $snapshot, 'after' ) ); ?></code>
									</td>
									<td><?php echo esc_html( empty( $state_bits ) ? __( 'active', 'diviops-agent' ) : implode( ', ', $state_bits ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
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

		$docs_url = 'https://diviops.com/docs/';
		$brand_logo_url   = plugins_url( 'assets/diviops-wordmark.svg', __FILE__ );
		$snapshot_request = new class() {
			public function get_param( $key ) {
				return 'limit' === $key ? 8 : null;
			}
		};
		$rollback_snapshots = self::rollback_snapshot_filtered_summaries( $snapshot_request );

		?>
		<div class="wrap">
			<h1 class="screen-reader-text"><?php esc_html_e( 'DiviOps Agent', 'diviops-agent' ); ?></h1>
			<div style="clear:both;margin:20px 0 24px;max-width:1120px;">
				<img src="<?php echo esc_url( $brand_logo_url ); ?>" alt="<?php esc_attr_e( 'DiviOps', 'diviops-agent' ); ?>" width="166" height="42" style="display:block;width:166px;max-width:100%;height:auto;" />
				<p style="margin:12px 0 0;max-width:760px;"><?php esc_html_e( 'AI agent bridge for Divi 5 — connects Claude Code, Codex, and other MCP clients to your WordPress site.', 'diviops-agent' ); ?></p>
				<p class="description" style="margin:8px 0 0;max-width:760px;"><?php esc_html_e( 'Divi is a registered trademark of Elegant Themes, Inc. DiviOps Agent is not affiliated with or endorsed by Elegant Themes.', 'diviops-agent' ); ?></p>
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
					<p><?php esc_html_e( 'Once the Free plugin is published on WordPress.org, WordPress delivers updates through the normal plugin update flow.', 'diviops-agent' ); ?></p>
					<ol style="margin-left:18px;">
						<li><?php esc_html_e( 'Open Dashboard → Updates or Plugins in WordPress admin.', 'diviops-agent' ); ?></li>
						<li><?php esc_html_e( 'Apply the available DiviOps Agent update.', 'diviops-agent' ); ?></li>
						<li><?php esc_html_e( 'Keep your Application Password and MCP client configuration unchanged.', 'diviops-agent' ); ?></li>
					</ol>
					<p>
						<a href="<?php echo esc_url( $docs_url ); ?>" target="_blank" rel="noopener noreferrer" class="button"><?php esc_html_e( 'Setup Guide', 'diviops-agent' ); ?></a>
					</p>
					<p class="description"><?php esc_html_e( 'The npm MCP server updates separately through npm or npx. Pro update and license access are managed by the Pro plugin.', 'diviops-agent' ); ?></p>
				</div>

				<?php self::render_admin_rollback_snapshots_card( $rollback_snapshots ); ?>

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
