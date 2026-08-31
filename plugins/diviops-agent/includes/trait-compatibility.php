<?php
/**
 * Narrow compatibility repairs for verified upstream Divi defects.
 *
 * @package DiviOps_Agent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_Compatibility {
	/**
	 * Replace only Divi's verified broken product-price permission callback.
	 *
	 * Divi 5.10.0 and 5.10.1 call a missing UserRole::can_edit_posts()
	 * method. The exact handler and runtime signature checks make this repair
	 * self-retiring when Divi adds that method or changes the route callback.
	 *
	 * @param array $endpoints WordPress REST endpoint map.
	 * @return array
	 */
	public static function repair_divi_post_filter_price_permission( $endpoints ) {
		if ( ! is_array( $endpoints ) || ! self::needs_divi_post_filter_price_permission_repair() ) {
			return $endpoints;
		}

		$route = self::DIVI_POST_FILTER_PRICE_ROUTE;
		if ( ! isset( $endpoints[ $route ] ) || ! is_array( $endpoints[ $route ] ) ) {
			return $endpoints;
		}

		$controller_callback = [ self::DIVI_POST_FILTER_PRICE_CONTROLLER, 'index' ];
		$broken_callback     = [ self::DIVI_POST_FILTER_PRICE_CONTROLLER, 'index_permission' ];
		$matching_handlers   = [];
		foreach ( $endpoints[ $route ] as $index => $handler ) {
			if (
				! is_array( $handler )
				|| $controller_callback !== ( $handler['callback'] ?? null )
				|| $broken_callback !== ( $handler['permission_callback'] ?? null )
			) {
				continue;
			}
			$matching_handlers[] = $index;
		}

		if ( 1 !== count( $matching_handlers ) ) {
			return $endpoints;
		}

		$index = reset( $matching_handlers );
		$endpoints[ $route ][ $index ]['permission_callback'] = [ __CLASS__, 'check_divi_post_filter_price_permission' ];

		return $endpoints;
	}

	/**
	 * Preserve the intended VB and post-edit authority for price discovery.
	 *
	 * Divi 5.10.x skips its ET nonce filter for prefixed routes, so this exact
	 * replacement restores the route-specific nonce check before authority.
	 *
	 * @param mixed $request WordPress REST request supplied to the callback.
	 * @return bool
	 */
	public static function check_divi_post_filter_price_permission( $request = null ) {
		if ( ! self::verify_divi_post_filter_price_nonce( $request ) ) {
			return false;
		}

		$role_class = self::DIVI_USER_ROLE_CLASS;
		if ( ! class_exists( $role_class ) || ! is_callable( [ $role_class, 'can_current_user_use_visual_builder' ] ) ) {
			return false;
		}

		return (bool) $role_class::can_current_user_use_visual_builder()
			&& current_user_can( 'edit_posts' );
	}

	/**
	 * Restore Divi's intended nonce boundary for the exact repaired route.
	 *
	 * @param mixed $request WordPress REST request supplied to the callback.
	 * @return bool
	 */
	private static function verify_divi_post_filter_price_nonce( $request ) {
		if (
			! is_object( $request )
			|| ! is_callable( [ $request, 'get_route' ] )
			|| ! is_callable( [ $request, 'get_method' ] )
			|| ! is_callable( [ $request, 'get_header' ] )
			|| self::DIVI_POST_FILTER_PRICE_ROUTE !== $request->get_route()
			|| self::DIVI_POST_FILTER_PRICE_METHOD !== strtoupper( (string) $request->get_method() )
			|| ! function_exists( 'wp_verify_nonce' )
		) {
			return false;
		}

		$rest_controller = 'ET\Builder\Framework\Controllers\RESTController';
		if ( ! class_exists( $rest_controller ) || ! is_callable( [ $rest_controller, 'get_nonce_name' ] ) ) {
			return false;
		}

		$nonce = $request->get_header( 'X-ET-Nonce' );
		if ( ! is_string( $nonce ) || '' === $nonce ) {
			return false;
		}

		$nonce_name = $rest_controller::get_nonce_name(
			self::DIVI_REST_NAMESPACE,
			self::DIVI_POST_FILTER_PRICE_NONCE_ROUTE,
			self::DIVI_POST_FILTER_PRICE_METHOD
		);

		return is_string( $nonce_name ) && '' !== $nonce_name && false !== wp_verify_nonce( $nonce, $nonce_name );
	}

	/**
	 * Determine whether the exact upstream defect is present.
	 *
	 * @return bool
	 */
	private static function needs_divi_post_filter_price_permission_repair() {
		$version = self::divi_runtime_version_for_compatibility();
		if ( '' === $version || version_compare( $version, '5.10.0', '<' ) ) {
			return false;
		}

		$controller = self::DIVI_POST_FILTER_PRICE_CONTROLLER;
		$role_class = self::DIVI_USER_ROLE_CLASS;

		return class_exists( $controller )
			&& is_callable( [ $controller, 'index_permission' ] )
			&& class_exists( $role_class )
			&& is_callable( [ $role_class, 'can_current_user_use_visual_builder' ] )
			&& ! is_callable( [ $role_class, 'can_edit_posts' ] );
	}

	/**
	 * Resolve the active Divi version without loading theme files directly.
	 *
	 * @return string
	 */
	private static function divi_runtime_version_for_compatibility() {
		if ( defined( 'ET_BUILDER_PRODUCT_VERSION' ) && ET_BUILDER_PRODUCT_VERSION ) {
			return (string) ET_BUILDER_PRODUCT_VERSION;
		}
		if ( defined( 'ET_BUILDER_VERSION' ) && ET_BUILDER_VERSION ) {
			return (string) ET_BUILDER_VERSION;
		}
		if ( defined( 'ET_CORE_VERSION' ) && ET_CORE_VERSION ) {
			return (string) ET_CORE_VERSION;
		}
		return '';
	}
}
