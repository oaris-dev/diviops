<?php
/** Read-only, bounded staff recipe evidence shared by Free inspection and Pro apply. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class DiviOps_Cross_Env_Staff_Body {
	const CONDITION = 'singular:post_type:diviops_staff:all';
	public static function canonical( $value ): string {
		$sort = static function ( $v ) use ( &$sort ) {
			if ( is_object( $v ) ) { $v = get_object_vars( $v ); }
			if ( ! is_array( $v ) ) { return $v; }
			if ( array_keys( $v ) !== range( 0, count( $v ) - 1 ) && [] !== $v ) { ksort( $v, SORT_STRING ); }
			return array_map( $sort, $v );
		};
		return (string) json_encode( $sort( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
	public static function proof( array $evidence ): array {
		return [ 'evidence' => $evidence, 'digest' => [ 'algorithm' => 'sha256', 'computed' => hash( 'sha256', self::canonical( $evidence ) ) ] ];
	}
	private static function refuse() {
		return new WP_Error( 'cross_env.staff_body_unsupported', 'Staff body requires the exact native recipe and current isolated staff prerequisites.', [ 'status' => 409 ] );
	}
	public static function source( string $markup ) {
		if ( ! function_exists( 'parse_blocks' ) ) { return self::refuse(); }
		$expected = [
			'divi/image:image.innerContent.desktop.value.src' => [ 'type' => 'content', 'value' => [ 'name' => 'post_featured_image', 'settings' => [ 'thumbnail_size' => 'large' ] ] ],
			'divi/heading:title.innerContent.desktop.value' => [ 'type' => 'content', 'value' => [ 'name' => 'post_title', 'settings' => [ 'before' => '', 'after' => '' ] ] ],
			'divi/text:content.innerContent.desktop.value' => [ 'type' => 'content', 'value' => [ 'name' => 'post_meta_key', 'settings' => [ 'before' => '', 'after' => '', 'select_meta_key' => 'custom_meta_diviops_staff_role', 'meta_key' => '', 'date_format' => 'default', 'custom_date_format' => '', 'enable_html' => 'off' ] ] ],
		];
		$found = []; $counts = []; $valid = true;
		$attrs = static function ( $value, string $path, string $name ) use ( &$attrs, &$found, &$valid, $expected ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $key => $nested ) {
					if ( preg_match( '/loop|post.?id|context|dynamic|modulePreset|groupPreset|globalModule/i', (string) $key ) ) { $valid = false; }
					$attrs( $nested, '' === $path ? (string) $key : $path . '.' . $key, $name );
				}
			} elseif ( is_string( $value ) && false !== strpos( $value, '$variable' ) ) {
				$key = $name . ':' . $path;
				$token = '$variable(' === substr( $value, 0, 10 ) && ')$' === substr( $value, -2 ) ? json_decode( substr( $value, 10, -2 ), true ) : null;
				if ( ! isset( $expected[ $key ] ) || isset( $found[ $key ] ) || self::canonical( $token ) !== self::canonical( $expected[ $key ] ) ) { $valid = false; }
				$found[ $key ] = $token;
			}
		};
		$walk = static function ( array $blocks ) use ( &$walk, &$valid, &$counts, $attrs ) {
			foreach ( $blocks as $block ) {
				$name = $block['blockName'] ?? '';
				if ( '' === $name && '' === trim( $block['innerHTML'] ?? '' ) ) { continue; }
				if ( ! in_array( $name, [ 'divi/placeholder', 'divi/section', 'divi/row', 'divi/column', 'divi/group', 'divi/image', 'divi/heading', 'divi/text', 'divi/post-content' ], true ) || '' !== trim( $block['innerHTML'] ?? '' ) ) { $valid = false; }
				$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
				$attrs( $block['attrs'] ?? [], '', $name );
				$walk( $block['innerBlocks'] ?? [] );
			}
		};
		$walk( parse_blocks( $markup ) );
		foreach ( [ 'divi/image', 'divi/heading', 'divi/text', 'divi/post-content' ] as $name ) { if ( 1 !== ( $counts[ $name ] ?? 0 ) ) { $valid = false; } }
		if ( ! $valid || self::canonical( $found ) !== self::canonical( $expected ) ) { return self::refuse(); }
		return self::proof( [ 'schema' => 'diviops.cross_env.staff_body.source.v1', 'context' => 'current_post', 'bindings' => $expected, 'post_content_count' => 1 ] );
	}
	public static function target( array $linkage ) {
		if ( ! function_exists( 'post_type_exists' ) || ! post_type_exists( 'diviops_staff' ) || ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) { return self::refuse(); }
		$fields = [];
		foreach ( acf_get_field_groups( [ 'post_type' => 'diviops_staff' ] ) as $group ) {
			if ( empty( $group['active'] ) ) { continue; }
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				if ( 'diviops_staff_role' !== ( $field['name'] ?? '' ) && 'field_diviops_staff_role' !== ( $field['key'] ?? '' ) ) { continue; }
				if ( ! empty( $field['ID'] ) ) {
					$field_post = get_post( $field['ID'] );
					if ( ! $field_post || 'trash' === $field_post->post_status ) { return self::refuse(); }
				}
				$location = $group['location'] ?? [];
				$staff_branch = [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'diviops_staff' ] ];
				$has_staff_branch = false;
				foreach ( is_array( $location ) ? $location : [] as $branch ) {
					if ( self::canonical( $branch ) === self::canonical( $staff_branch ) ) { $has_staff_branch = true; }
				}
				if ( ! $has_staff_branch || 'text' !== ( $field['type'] ?? '' ) || 'diviops_staff_role' !== ( $field['name'] ?? '' ) || 'field_diviops_staff_role' !== ( $field['key'] ?? '' ) || ! empty( $field['conditional_logic'] ) || empty( $group['key'] ) ) { return self::refuse(); }
				$fields[] = [ 'key' => $field['key'], 'name' => $field['name'], 'type' => 'text', 'group_key' => $group['key'], 'group_active' => true, 'location' => $location, 'selector' => 'custom_meta_diviops_staff_role' ];
			}
		}
		$link = $linkage['links'][0] ?? [];
		if ( 1 !== count( $fields ) || 'tb_body_layout' !== ( $linkage['destination_kind'] ?? '' ) || 'et_body_layout' !== ( $linkage['destination_post_type'] ?? '' ) || empty( $linkage['active_master_id'] ) || 1 !== count( $linkage['links'] ?? [] ) || 'body' !== ( $link['slot'] ?? '' ) || ( $link['layout_id'] ?? 0 ) !== ( $linkage['destination_id'] ?? null ) || true !== ( $link['layout_enabled'] ?? null ) || true !== ( $link['template_enabled'] ?? null ) || false !== ( $link['template_default'] ?? null ) || [ self::CONDITION ] !== ( $link['conditions'] ?? null ) || [] !== ( $link['exclusions'] ?? null ) ) { return self::refuse(); }
		$slots = [];
		if ( [ self::CONDITION ] !== get_post_meta( $link['template_id'], '_et_use_on', false ) || [] !== get_post_meta( $link['template_id'], '_et_exclude_from', false ) ) { return self::refuse(); }
		foreach ( $linkage['master_template_ids'] as $template_id ) {
			if ( $template_id !== $link['template_id'] && '1' === (string) get_post_meta( $template_id, '_et_enabled', true ) && in_array( self::CONDITION, (array) get_post_meta( $template_id, '_et_use_on', false ), true ) ) { return self::refuse(); }
		}
		foreach ( [ 'header', 'body', 'footer' ] as $slot ) {
			$id = absint( get_post_meta( $link['template_id'], '_et_' . $slot . '_layout_id', true ) );
			$post = $id ? get_post( $id ) : null;
			$slots[ $slot ] = [ 'id' => $id, 'enabled' => (string) get_post_meta( $link['template_id'], '_et_' . $slot . '_layout_enabled', true ), 'global' => (string) get_post_meta( $link['template_id'], '_et_' . $slot . '_layout_global', true ) ];
			if ( 'body' !== $slot ) { $slots[ $slot ]['checksum'] = $post ? hash( 'sha256', (string) $post->post_content ) : null; }
		}
		if ( $slots['body']['id'] !== $linkage['destination_id'] || '1' === $slots['body']['global'] ) { return self::refuse(); }
		return self::proof( [ 'schema' => 'diviops.cross_env.staff_body.target.v1', 'post_type' => 'diviops_staff', 'registered' => true, 'field' => $fields[0], 'linkage' => $linkage, 'slots' => $slots ] );
	}
}
