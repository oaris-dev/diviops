<?php
/**
 * Trait DiviOps_Agent_ModuleSchema
 *
 * Module registry + per-module schema introspection.
 *
 * Part of the diviops-agent monolith split (#220). Mixed into
 * DiviOps_Agent via `use` in diviops-agent.php — `self::` calls and
 * class constants resolve as if these methods lived directly on the
 * class.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_ModuleSchema {

	/**
	 * List all registered Divi modules with basic info.
	 *
	 * Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.
	 */
	public static function schema_list_modules( $request ) {
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

		return self::envelope_success( $modules );
	}

	/**
	 * Dump every registered Divi module's schema in one call.
	 *
	 * Build-time call for the skill regen pipeline. Walks the
	 * block-type registry once and returns each `divi/*` block's
	 * full attributes alongside a `schema_version` hash so consumers
	 * can short-circuit when nothing changed.
	 *
	 * `schema_version` is SHA-1 over the concatenated contents of
	 * every `*PresetAttrsMap.php` file in `Packages/ModuleLibrary/`.
	 * That set is the canonical attr-path index — its byte-stable
	 * hash is what changes only when canonical paths change.
	 *
	 * Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.
	 */
	public static function schema_get_module_dump_all( $request ) {
		$registry = WP_Block_Type_Registry::get_instance();
		$all      = $registry->get_all_registered();
		$modules  = [];

		foreach ( $all as $name => $block_type ) {
			if ( 0 !== strpos( $name, 'divi/' ) ) {
				continue;
			}

			$modules[ $name ] = [
				'name'        => $block_type->name,
				'title'       => $block_type->title ?? '',
				'category'    => $block_type->category ?? '',
				'description' => $block_type->description ?? '',
				'attributes'  => $block_type->attributes ?? [],
				'supports'    => $block_type->supports ?? [],
			];
		}

		ksort( $modules );

		return self::envelope_success( [
			'schema_version' => self::schema_preset_attrs_map_hash(),
			'divi_version'   => self::resolve_divi_version(),
			'modules'        => $modules,
		] );
	}

	/**
	 * Resolve the Divi build version, preferring the builder constant
	 * over the core fallback. Single source of truth for both the
	 * dump-all response payload and the schema-hash cache key, so the
	 * two never diverge on a site that defines only ET_CORE_VERSION.
	 */
	private static function resolve_divi_version() {
		if ( defined( 'ET_BUILDER_VERSION' ) && ET_BUILDER_VERSION ) {
			return ET_BUILDER_VERSION;
		}
		if ( defined( 'ET_CORE_VERSION' ) && ET_CORE_VERSION ) {
			return ET_CORE_VERSION;
		}
		return '';
	}

	/**
	 * Hash every `*PresetAttrsMap.php` under `Packages/`.
	 *
	 * Walks `Packages/` (not just `Packages/ModuleLibrary/`) because
	 * shared option maps live under `Packages/Module/Options/**` and are
	 * imported by per-module maps — e.g. TextPresetAttrsMap pulls in
	 * LoopPresetAttrsMap. Hashing only ModuleLibrary would miss real
	 * canonical-path changes when a shared option map shifts and let the
	 * regen pipeline short-circuit on a stale hash.
	 *
	 * The hash mixes in each file's path *relative to* the Packages
	 * root, not its absolute path. That keeps the hash environment-
	 * stable across local / staging / prod installs with byte-
	 * identical theme files but different filesystem roots.
	 *
	 * Cached per-request via a static (PresetAttrsMap files don't
	 * change between requests within a PHP process) and across
	 * requests via a transient keyed by Divi build version + the
	 * directory mtime, so a theme update or file edit invalidates it
	 * automatically.
	 */
	private static function schema_preset_attrs_map_hash() {
		static $cached_hash = null;
		if ( null !== $cached_hash ) {
			return $cached_hash;
		}

		$packages_root = get_theme_file_path( 'includes/builder-5/server/Packages' );
		if ( ! is_dir( $packages_root ) ) {
			$cached_hash = '';
			return $cached_hash;
		}

		$cache_key = 'diviops_schema_attrs_map_hash_' . md5(
			'Packages|' . self::resolve_divi_version() . '|' . self::dir_latest_mtime( $packages_root )
		);
		$transient = get_transient( $cache_key );
		if ( is_string( $transient ) && '' !== $transient ) {
			$cached_hash = $transient;
			return $cached_hash;
		}

		$root_len = strlen( $packages_root ) + 1; // +1 to drop the trailing slash too
		$files    = [];
		$it       = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $packages_root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$abs = $file->getPathname();
			if ( substr( $abs, -strlen( 'PresetAttrsMap.php' ) ) !== 'PresetAttrsMap.php' ) {
				continue;
			}
			// Use forward-slash relative paths so Windows installs hash
			// the same way as Unix ones (otherwise DIRECTORY_SEPARATOR
			// would couple the hash to the host OS).
			$rel     = substr( $abs, $root_len );
			$rel     = str_replace( DIRECTORY_SEPARATOR, '/', $rel );
			$files[] = [ 'rel' => $rel, 'abs' => $abs ];
		}
		usort( $files, static function ( $a, $b ) { return strcmp( $a['rel'], $b['rel'] ); } );

		$ctx = hash_init( 'sha1' );
		foreach ( $files as $file ) {
			hash_update( $ctx, $file['rel'] . "\n" );
			hash_update_file( $ctx, $file['abs'] );
		}
		$cached_hash = hash_final( $ctx );

		// HOUR_IN_SECONDS, not MINUTE — the cache key already incorporates
		// the directory's latest mtime, so any *PresetAttrsMap.php edit
		// (theme update, manual file change) invalidates the entry on the
		// next request regardless of TTL. The TTL is only the upper bound
		// for "no on-disk change happened, just expire and re-hash."
		set_transient( $cache_key, $cached_hash, HOUR_IN_SECONDS );
		return $cached_hash;
	}

	/**
	 * Latest mtime of any file under $dir, recursive. Cheap signal for
	 * "did the on-disk schema change since last request" — don't need
	 * full file content here, just an invalidation hint.
	 */
	private static function dir_latest_mtime( $dir ) {
		$latest = 0;
		$it     = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$mtime = $file->getMTime();
			if ( $mtime > $latest ) {
				$latest = $mtime;
			}
		}
		return $latest;
	}

	/**
	 * Get full schema/attributes for a specific module.
	 *
	 * Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.
	 * Errors:
	 *  - not_found (HTTP 404): the module name does not resolve in the
	 *    Divi block-type registry. Hint suggests `diviops_schema_list_modules`.
	 */
	public static function schema_get_module( $request ) {
		$name = sanitize_text_field( (string) $request['name'] );

		// Normalize: accept "text" or "divi/text".
		if ( 0 !== strpos( $name, 'divi/' ) ) {
			$name = 'divi/' . $name;
		}

		$registry   = WP_Block_Type_Registry::get_instance();
		$block_type = $registry->get_registered( $name );

		if ( ! $block_type ) {
			return self::envelope_error(
				'not_found',
				"Module '{$name}' not found",
				'Run diviops_schema_list_modules to see registered Divi modules.',
				404
			);
		}

		return self::envelope_success( [
			'name'        => $block_type->name,
			'title'       => $block_type->title ?? '',
			'category'    => $block_type->category ?? '',
			'description' => $block_type->description ?? '',
			'attributes'  => $block_type->attributes ?? [],
			'supports'    => $block_type->supports ?? [],
		] );
	}
}
