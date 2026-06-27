<?php
/**
 * Trait DiviOps_Agent_Core
 *
 * Cross-namespace utilities (presets store, cache invalidation, deep merge).
 *
 * Part of the diviops-agent monolith split (#220). Mixed into
 * DiviOps_Agent via `use` in diviops-agent.php — `self::` calls and
 * class constants resolve as if these methods lived directly on the
 * class.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_Core {

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

	private static function normalize_storage_array( $value ): ?array {
		if ( is_array( $value ) || is_object( $value ) ) {
			return (array) $value;
		}
		return null;
	}

	/**
	 * Normalize Divi block comment attributes before full-content writes.
	 *
	 * WordPress block attributes may contain HTML strings, but the serialized
	 * comment JSON must use serialize_block_attributes() escaping for unsafe
	 * bytes such as `<`, `>`, `&`, `--`, backslashes, and escaped quotes. This
	 * guard accepts raw HTML and canonical JSON escapes, rejects pseudo-escapes
	 * like `u003c`, then rewrites Divi block opener attrs canonically.
	 *
	 * @param string $content Full block markup.
	 * @return array{ok:bool,content?:string,changed?:int,error?:array}
	 */
	private static function normalize_divi_full_content_for_write( string $content ): array {
		$pattern = '/<!--\s+(\/)?wp:([A-Za-z0-9_-]+\/[A-Za-z0-9_-]+)(.*?)(\/)?-->/s';
		$changed = 0;
		$error   = null;

		$normalized = preg_replace_callback(
			$pattern,
			static function ( array $matches ) use ( &$changed, &$error ) {
				if ( null !== $error ) {
					return $matches[0];
				}

				$is_closer = ! empty( $matches[1] );
				$block     = $matches[2];
				$tail      = $matches[3];

				if ( $is_closer || 0 !== strpos( $block, 'divi/' ) ) {
					return $matches[0];
				}

				// The canonical ` /-->` self-close slash lands in group 4 (it sits
				// immediately before `-->`); a whitespace-separated `/ -->` variant
				// leaves the slash at the end of the lazy tail instead. Honor both,
				// or every self-closing block re-serializes as an opener and the
				// block tree scrambles (openers > closers).
				$trimmed_tail    = trim( $tail );
				$is_self_closing = ! empty( $matches[4] );
				if ( '' !== $trimmed_tail && '/' === substr( $trimmed_tail, -1 ) ) {
					$is_self_closing = true;
					$trimmed_tail    = rtrim( substr( $trimmed_tail, 0, -1 ) );
				}

				if ( '' === $trimmed_tail ) {
					return '<!-- wp:' . $block . ( $is_self_closing ? ' /-->' : ' -->' );
				}

				if ( '{' !== substr( $trimmed_tail, 0, 1 ) || '}' !== substr( $trimmed_tail, -1 ) ) {
					$error = [
						'message' => 'Divi block attributes must be a JSON object.',
						'block'   => $block,
						'preview' => substr( $trimmed_tail, 0, 120 ),
					];
					return $matches[0];
				}

				$pseudo_escape = self::find_malformed_block_attr_escape( $trimmed_tail );
				if ( null !== $pseudo_escape ) {
					$error = [
						'message' => 'Divi block attributes contain a malformed JSON unicode escape.',
						'block'   => $block,
						'escape'  => $pseudo_escape,
						'hint'    => 'Use canonical JSON escapes like \\u003c or raw HTML that can be normalized, not u003c without the backslash.',
					];
					return $matches[0];
				}

				// Decode WITHOUT assoc: PHP's assoc decode collapses empty JSON
				// objects to empty arrays, so a re-encode mutates `"decoration":{}`
				// into `"decoration":[]`. stdClass round-trips object/array
				// distinction byte-faithfully.
				$attrs = json_decode( $trimmed_tail );
				if ( ! is_object( $attrs ) || JSON_ERROR_NONE !== json_last_error() ) {
					$error = [
						'message'    => 'Divi block attributes are not valid JSON.',
						'block'      => $block,
						'json_error' => json_last_error_msg(),
						'preview'    => substr( $trimmed_tail, 0, 120 ),
					];
					return $matches[0];
				}

				$encoded = self::serialize_block_attrs_canonical( $attrs );
				if ( null === $encoded ) {
					$error = [
						'message' => 'Divi block attributes could not be JSON-encoded safely.',
						'block'   => $block,
					];
					return $matches[0];
				}

				$new_comment = '<!-- wp:' . $block . ' ' . $encoded . ( $is_self_closing ? ' /-->' : ' -->' );
				if ( $new_comment !== $matches[0] ) {
					$changed++;
				}
				return $new_comment;
			},
			$content
		);

		if ( null !== $error ) {
			return [ 'ok' => false, 'error' => $error ];
		}
		if ( null === $normalized ) {
			return [
				'ok'    => false,
				'error' => [
					'message' => 'Unable to scan Divi block attributes for safe serialization.',
				],
			];
		}

		return [ 'ok' => true, 'content' => $normalized, 'changed' => $changed ];
	}

	/**
	 * Reject obviously unsafe full Divi markup before a content write.
	 *
	 * This intentionally stays parser-free: page_update_content() must keep
	 * accepting serialized markup in standalone smoke tests that do not load
	 * WordPress' block parser, while still catching the failure class where
	 * self-closing markers are stripped or block comments are mis-nested.
	 *
	 * @param string $content Full block markup after canonical attr normalization.
	 * @param string $field   Request field name for error payloads.
	 * @return true|WP_Error
	 */
	private static function assert_divi_full_content_safe_for_write( string $content, string $field = 'content' ) {
		$counts     = self::divi_content_marker_counts( $content );
		$validation = self::validate_divi_marker_sequence( $content );
		if ( $counts['container_openers'] !== $counts['closers'] || empty( $validation['ok'] ) ) {
			return new WP_Error(
				'invalid_input',
				'Divi block markup has unbalanced or mis-nested opener/closer markers.',
				[
					'status' => 400,
					'hint'   => 'Check for stripped self-closing markers or missing closing block comments before writing full post_content.',
					'field'  => $field,
					'counts' => $counts,
					'marker' => $validation,
				]
			);
		}

		return true;
	}

	/**
	 * Write post_content, immediately read it back, and revert on byte drift.
	 *
	 * @param int    $post_id          Target post id.
	 * @param string $content          Canonical content expected after the write.
	 * @param string $error_namespace  Namespace for the corruption error code.
	 * @param string $target_label     Human-readable target for messages/data.
	 * @param string $previous_content Original content to restore on mismatch.
	 * @return true|WP_Error
	 */
	private static function update_post_content_with_integrity_guard( int $post_id, string $content, string $error_namespace, string $target_label, string $previous_content ) {
		$preflight = self::assert_divi_full_content_safe_for_write( $content, 'content' );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}

		$result = wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $content ),
		], true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$readback = get_post( $post_id );
		$stored   = $readback && isset( $readback->post_content ) ? (string) $readback->post_content : null;
		if ( $stored === $content ) {
			return true;
		}

		$reverted     = false;
		$revert_error = null;
		$revert       = wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $previous_content ),
		], true );
		if ( is_wp_error( $revert ) ) {
			$revert_error = [
				'code'    => $revert->get_error_code(),
				'message' => $revert->get_error_message(),
			];
		} else {
			$after_revert = get_post( $post_id );
			$reverted     = $after_revert && isset( $after_revert->post_content ) && (string) $after_revert->post_content === $previous_content;
		}

		return new WP_Error(
			$error_namespace . '.content_write_corruption',
			"Refused {$target_label} content write because WordPress readback did not match the requested content.",
			[
				'status' => 500,
				'hint'   => $reverted
					? 'The original content was restored. Inspect the corruption diagnostics before retrying this full-content write.'
					: 'Automatic restore did not verify cleanly. Restore the original content from backup or revision before retrying.',
				'target' => [
					'post_id' => $post_id,
					'label'   => $target_label,
				],
				'bytes'  => [
					'expected' => strlen( $content ),
					'stored'   => is_string( $stored ) ? strlen( $stored ) : null,
					'previous' => strlen( $previous_content ),
				],
				'issues' => self::diagnose_divi_content_write_drift( $content, is_string( $stored ) ? $stored : '' ),
				'revert' => [
					'attempted' => true,
					'verified'  => $reverted,
					'error'     => $revert_error,
				],
			]
		);
	}

	/**
	 * Marker counts used by the write guard and diagnostics.
	 *
	 * @param string $content Full block markup.
	 * @return array{openers:int,self_closers:int,container_openers:int,closers:int}
	 */
	private static function divi_content_marker_counts( string $content ): array {
		$openers      = preg_match_all( '/<!--\s+wp:divi\//', $content );
		$self_closers = preg_match_all( '/<!--\s+wp:divi\/(?:(?!-->).)*?\/-->/s', $content );
		$closers      = preg_match_all( '/<!--\s+\/wp:divi\//', $content );

		return [
			'openers'           => (int) $openers,
			'self_closers'      => (int) $self_closers,
			'container_openers' => max( 0, (int) $openers - (int) $self_closers ),
			'closers'           => (int) $closers,
		];
	}

	/**
	 * Validate Divi opener/closer order without using parse_blocks().
	 *
	 * @param string $content Full block markup.
	 * @return array<string,mixed>
	 */
	private static function validate_divi_marker_sequence( string $content ): array {
		$matched = preg_match_all(
			'/<!--\s+(\/)?wp:divi\/([A-Za-z0-9_-]+)(?:(?!-->).)*?(\/)?-->/s',
			$content,
			$matches,
			PREG_SET_ORDER | PREG_OFFSET_CAPTURE
		);
		if ( false === $matched ) {
			return [ 'ok' => false, 'reason' => 'scan_failed' ];
		}

		$stack = [];
		foreach ( $matches as $match ) {
			$token      = $match[0][0];
			$offset     = $match[0][1];
			$is_closer  = ! empty( $match[1][0] );
			$type       = (string) $match[2][0];
			$self_close = ! $is_closer && ! empty( $match[3][0] );

			if ( $self_close ) {
				continue;
			}
			if ( ! $is_closer ) {
				$stack[] = [ 'type' => $type, 'offset' => $offset ];
				continue;
			}
			if ( empty( $stack ) ) {
				return [
					'ok'      => false,
					'reason'  => 'unexpected_closer',
					'actual'  => $type,
					'offset'  => $offset,
					'preview' => substr( $token, 0, 120 ),
				];
			}

			$expected = array_pop( $stack );
			if ( $expected['type'] !== $type ) {
				return [
					'ok'              => false,
					'reason'          => 'mismatched_closer',
					'expected'        => $expected['type'],
					'expected_offset' => $expected['offset'],
					'actual'          => $type,
					'offset'          => $offset,
					'preview'         => substr( $token, 0, 120 ),
				];
			}
		}

		if ( ! empty( $stack ) ) {
			$unclosed = end( $stack );
			return [
				'ok'     => false,
				'reason' => 'unclosed_block',
				'type'   => $unclosed['type'],
				'offset' => $unclosed['offset'],
			];
		}

		return [ 'ok' => true ];
	}

	/**
	 * Summarize content drift without echoing large post_content strings.
	 *
	 * @param string $expected Expected canonical content.
	 * @param string $stored   Immediate readback content.
	 * @return array<int,array<string,mixed>>
	 */
	private static function diagnose_divi_content_write_drift( string $expected, string $stored ): array {
		$issues          = [];
		$expected_counts = self::divi_content_marker_counts( $expected );
		$stored_counts   = self::divi_content_marker_counts( $stored );

		if ( strlen( $stored ) > max( strlen( $expected ) * 2, strlen( $expected ) + 65536 ) ) {
			$issues[] = [
				'type'           => 'runaway_growth',
				'expected_bytes' => strlen( $expected ),
				'stored_bytes'   => strlen( $stored ),
			];
		}
		if ( $expected_counts !== $stored_counts ) {
			$issues[] = [
				'type'     => 'marker_count_drift',
				'expected' => $expected_counts,
				'stored'   => $stored_counts,
			];
		}
		if ( $stored_counts['container_openers'] !== $stored_counts['closers'] ) {
			$issues[] = [
				'type'   => 'opener_closer_imbalance',
				'stored' => $stored_counts,
			];
		}
		if ( false !== strpos( $expected, '/-->' ) && false === strpos( $stored, '/-->' ) ) {
			$issues[] = [ 'type' => 'self_closing_marker_stripped' ];
		}
		if ( substr_count( $expected, ':{}' ) > substr_count( $stored, ':{}' ) || substr_count( $stored, ':[]' ) > substr_count( $expected, ':[]' ) ) {
			$issues[] = [
				'type'              => 'empty_object_drift',
				'expected_objects'  => substr_count( $expected, ':{}' ),
				'stored_objects'    => substr_count( $stored, ':{}' ),
				'expected_arrays'   => substr_count( $expected, ':[]' ),
				'stored_arrays'     => substr_count( $stored, ':[]' ),
			];
		}

		if ( empty( $issues ) ) {
			$offset = 0;
			$limit  = min( strlen( $expected ), strlen( $stored ) );
			while ( $offset < $limit && $expected[ $offset ] === $stored[ $offset ] ) {
				$offset++;
			}
			$issues[] = [
				'type'                  => 'byte_mismatch',
				'first_mismatch_offset' => $offset,
				'expected_bytes'        => strlen( $expected ),
				'stored_bytes'          => strlen( $stored ),
			];
		}

		return $issues;
	}

	/**
	 * Mirror core serialize_block_attributes() while keeping tests runnable
	 * against minimal WordPress stubs.
	 *
	 * @param object|array $attrs Block attributes. Callers pass the stdClass
	 *                            tree from a non-assoc json_decode so empty
	 *                            objects re-encode as `{}`, not `[]`.
	 * @return string|null
	 */
	private static function serialize_block_attrs_canonical( $attrs ): ?string {
		if ( function_exists( 'serialize_block_attributes' ) ) {
			return serialize_block_attributes( $attrs );
		}

		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $attrs, $flags );
		} else {
			$encoded = json_encode( $attrs, $flags );
		}
		if ( false === $encoded ) {
			return null;
		}

		return strtr(
			$encoded,
			[
				'\\\\' => '\\u005c',
				'--'   => '\\u002d\\u002d',
				'<'    => '\\u003c',
				'>'    => '\\u003e',
				'&'    => '\\u0026',
				'\\"'  => '\\u0022',
			]
		);
	}

	/**
	 * Detect HTML-ish unicode pseudo-escapes missing their required backslash.
	 *
	 * @param string $json Raw block attribute JSON.
	 * @return string|null
	 */
	private static function find_malformed_block_attr_escape( string $json ): ?string {
		if ( preg_match( '/(?<!\\\\)u00(?:3c|3e|26|22|5c|2d)/i', $json, $match ) ) {
			return $match[0];
		}
		return null;
	}

	// ── Block-tree empty-object round-trip guard (#901) ─────────────
	//
	// Core parse_blocks() decodes block attrs with assoc=true, so nested
	// empty JSON objects ("decoration":{}) collapse to PHP [] and
	// serialize_blocks() re-emits them as []. PHP render is unaffected
	// (Divi's BlockParser assoc-decodes too), but [] is non-canonical in
	// stored markup and type-visible to the Visual Builder's JS parser.
	// Tools that round-trip a parsed tree through serialize_blocks()
	// record the empty-object attr paths per block at parse time (sidecar
	// key on the block entry, ignored by serialize_blocks) and restore
	// stdClass at those paths right before re-serialization so
	// wp_json_encode emits {} again. Sidecars travel with array copies,
	// so cloned/moved blocks are repaired too.

	/**
	 * Sidecar key attached to parsed block entries. serialize_blocks()
	 * only reads blockName/attrs/innerBlocks/innerContent, so the key is
	 * inert if it ever leaks through — but restore_blocks_empty_objects()
	 * strips it anyway.
	 */
	private static function empty_object_paths_key(): string {
		return '__diviops_empty_object_paths';
	}

	/**
	 * Scan serialized content for block opener comments in document order.
	 *
	 * The pattern is a verbatim copy of the token grammar in
	 * WP_Block_Parser::next_token() (wp-includes/class-wp-block-parser.php),
	 * so scanned openers align 1:1 with the preorder of parse_blocks()
	 * output (freeform HTML chunks produce no token and no named block).
	 *
	 * @param string $content Serialized block markup.
	 * @return array<int,array{name:string,attrs:?string}> Openers in document order.
	 */
	private static function scan_block_opener_attrs( string $content ): array {
		$pattern = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';
		if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			return [];
		}
		$openers = [];
		foreach ( $matches as $m ) {
			if ( ! empty( $m['closer'] ) ) {
				continue;
			}
			// Implicit namespace resolves to core/ — mirrors WP_Block_Parser.
			$namespace = ! empty( $m['namespace'] ) ? $m['namespace'] : 'core/';
			$openers[] = [
				'name'  => $namespace . $m['name'],
				'attrs' => isset( $m['attrs'] ) && '' !== $m['attrs'] ? trim( $m['attrs'] ) : null,
			];
		}
		return $openers;
	}

	/**
	 * Collect attr paths whose value is an empty JSON object, from an
	 * assoc=false decode (stdClass distinguishes {} from []).
	 *
	 * @param mixed $value  Decoded attrs (objects + arrays).
	 * @param array $prefix Current path prefix (internal).
	 * @return array<int,array<int,string|int>> Paths as key lists, depth >= 1.
	 */
	private static function collect_empty_object_paths( $value, array $prefix = [] ): array {
		$out = [];
		self::collect_empty_object_paths_recursive( $value, $prefix, $out );
		return $out;
	}

	/**
	 * Recursive worker for collect_empty_object_paths().
	 *
	 * @param mixed $value Decoded attrs (objects + arrays).
	 * @param array $prefix Current path prefix.
	 * @param array $out Collected paths, appended by reference.
	 * @return void
	 */
	private static function collect_empty_object_paths_recursive( $value, array $prefix, array &$out ): void {
		if ( is_object( $value ) ) {
			$vars = get_object_vars( $value );
			if ( empty( $vars ) ) {
				// Depth 0 ({} as the whole attrs) is excluded: core's own
				// serialize_blocks() omits empty attrs entirely either way.
				if ( ! empty( $prefix ) ) {
					$out[] = $prefix;
				}
				return;
			}
			foreach ( $vars as $k => $v ) {
				$next_prefix   = $prefix;
				$next_prefix[] = (string) $k;
				self::collect_empty_object_paths_recursive( $v, $next_prefix, $out );
			}
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$next_prefix   = $prefix;
				$next_prefix[] = $k;
				self::collect_empty_object_paths_recursive( $v, $next_prefix, $out );
			}
		}
	}

	/**
	 * Restore stdClass at each recorded path whose current value is still
	 * an empty array. Paths invalidated by mutation (parent replaced or
	 * removed, value now non-empty) are skipped — a recorded {} that a
	 * mutator filled with real data stays whatever the mutator wrote.
	 *
	 * @param array $attrs Block attrs after mutation.
	 * @param array $paths Paths from collect_empty_object_paths().
	 * @return array Attrs with stdClass markers at surviving empty positions.
	 */
	private static function restore_empty_objects( array $attrs, array $paths ): array {
		foreach ( $paths as $path ) {
			if ( ! is_array( $path ) || empty( $path ) ) {
				continue;
			}
			$last = array_pop( $path );
			$ref  = &$attrs;
			$ok   = true;
			foreach ( $path as $key ) {
				if ( ! is_array( $ref ) || ! array_key_exists( $key, $ref ) ) {
					$ok = false;
					break;
				}
				$ref = &$ref[ $key ];
			}
			if ( $ok && is_array( $ref ) && array_key_exists( $last, $ref ) && [] === $ref[ $last ] ) {
				$ref[ $last ] = new stdClass();
			}
			unset( $ref );
		}
		return $attrs;
	}

	/**
	 * Attach empty-object-path sidecars to a freshly parsed tree by aligning
	 * the preorder walk with the scanned opener tokens of the SAME content.
	 *
	 * Returns the enriched tree, or the original tree untouched when
	 * alignment fails (count or block-name mismatch at any position) —
	 * fallback is today's lossy behavior, never worse.
	 *
	 * @param array  $blocks  parse_blocks() output for $content.
	 * @param string $content The exact content that was parsed.
	 * @return array
	 */
	private static function enrich_blocks_with_empty_object_paths( array $blocks, string $content ): array {
		$openers = self::scan_block_opener_attrs( $content );
		if ( empty( $openers ) ) {
			return $blocks;
		}
		$enriched = $blocks;
		$index    = 0;
		if ( ! self::attach_empty_object_paths_walk( $enriched, $openers, $index ) || $index !== count( $openers ) ) {
			return $blocks;
		}
		return $enriched;
	}

	/**
	 * Preorder recursion for enrich_blocks_with_empty_object_paths().
	 * Freeform chunks (null blockName) consume no opener token.
	 */
	private static function attach_empty_object_paths_walk( array &$blocks, array $openers, int &$index ): bool {
		foreach ( $blocks as &$block ) {
			$name = isset( $block['blockName'] ) ? $block['blockName'] : null;
			if ( null !== $name && '' !== $name ) {
				if ( ! isset( $openers[ $index ] ) || $openers[ $index ]['name'] !== $name ) {
					return false;
				}
				$json = $openers[ $index ]['attrs'];
				$index++;
				if ( null !== $json ) {
					$decoded = json_decode( $json );
					if ( is_object( $decoded ) ) {
						$paths = self::collect_empty_object_paths( $decoded );
						if ( ! empty( $paths ) ) {
							$block[ self::empty_object_paths_key() ] = $paths;
						}
					}
				}
			}
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && ! empty( $block['innerBlocks'] ) ) {
				if ( ! self::attach_empty_object_paths_walk( $block['innerBlocks'], $openers, $index ) ) {
					return false;
				}
			}
		}
		unset( $block );
		return true;
	}

	/**
	 * Strip sidecars and restore {} markers across a mutated tree — call
	 * immediately before serialize_blocks().
	 *
	 * @param array $blocks Mutated tree (possibly carrying sidecars).
	 * @return array Tree ready for serialize_blocks().
	 */
	private static function restore_blocks_empty_objects( array $blocks ): array {
		$key = self::empty_object_paths_key();
		foreach ( $blocks as &$block ) {
			if ( array_key_exists( $key, $block ) ) {
				$paths = $block[ $key ];
				unset( $block[ $key ] );
				if ( is_array( $paths ) && isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
					$block['attrs'] = self::restore_empty_objects( $block['attrs'], $paths );
				}
			}
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::restore_blocks_empty_objects( $block['innerBlocks'] );
			}
		}
		unset( $block );
		return $blocks;
	}

	// ── Storage-path contract (#719) ────────────────────────────────
	//
	// The Divi 5.5.x storage-path landscape is materially more complex than a
	// single hardcoded option key. Per #719's CONTRACT REVISED 2026-05-19
	// banner, the agent implements a uniform "read-probe + write-canonical +
	// audit-aggregates" contract per surface:
	//
	//   READ  — probe documented candidate paths in priority order; return
	//           content from the FIRST non-empty path with `_meta.source_path`
	//           + `_meta.probed_paths`. Never silently merge.
	//   WRITE — always target the canonical 5.5.x path; surface
	//           `_meta.legacy_path_detected` when a legacy path also holds
	//           content. Never auto-migrate.
	//   AUDIT — aggregate across all candidate paths with per-entry
	//           `_meta.entry_sources = { path, provenance }` + warnings for
	//           ID collisions, shape inconsistencies, and reserved-store
	//           anomalies (e.g. `_ng` non-empty).
	//
	// `_ng` (`et_divi_builder_global_presets_ng`) is D4-legacy, out-of-band.
	// Divi 5 only touches it for deletion-sync; never CREATE/UPDATE. The
	// agent NEVER writes to `_ng` and NEVER uses it as a D5 READ fallback.
	// AUDIT surfaces non-empty `_ng` content with `provenance: "legacy_d4_ng"`
	// (distinct from any D5 provenance) so consumers cannot confuse it with
	// canonical D5 entries. Source: GlobalPreset.php:461, 921 (D5 side) +
	// includes/builder/feature/global-presets/Settings.php (D4 side). Runtime
	// corroboration: 5.5.1→5.5.2 migration test confirms `_ng` byte-
	// identical-empty across the transition (see #719 comment thread).

	/**
	 * Candidate storage paths for the D5 preset surface, in READ priority
	 * order. `_ng` is OUT-OF-BAND and not listed here — AUDIT consults it
	 * separately via `audit_d5_preset_storage()`.
	 *
	 * Path shape:
	 *   - "option_key" → top-level WP option
	 *   - "et_divi.<sub>" → nested under the et_divi umbrella option (read
	 *     via `et_get_option(<sub>)` when et_options_stored_in_one_row()
	 *     resolves true — the standard Divi storage routing). Inner key is
	 *     plain `builder_global_presets_d5` with NO `et_divi_` prefix; the
	 *     #719 original-body reference to `et_divi.et_divi_builder_global_presets_d5`
	 *     was incorrect, banner-corrected.
	 */
	private static function d5_preset_paths(): array {
		return [
			[ 'path' => 'et_divi_builder_global_presets_d5',  'provenance' => 'd5_top_level' ],
			[ 'path' => 'et_divi.builder_global_presets_d5',  'provenance' => 'd5_nested_scratchpad' ],
		];
	}

	/**
	 * Read raw content at a single storage-path descriptor.
	 *
	 * Returns the deserialized array (possibly empty), or `null` if the path
	 * holds no usable content. Callers distinguish "empty array seeded" from
	 * "path not present" by null-check.
	 */
	private static function read_storage_path( string $path_str ) {
		if ( false !== strpos( $path_str, '.' ) ) {
			// Nested under et_divi umbrella. Inner key is the part after the dot.
			$parts = explode( '.', $path_str, 2 );
			if ( 'et_divi' !== $parts[0] ) {
				return null;
			}
			$raw = et_get_option( $parts[1] );
			if ( empty( $raw ) ) {
				return null;
			}
			$val = maybe_unserialize( $raw );
			return self::normalize_storage_array( $val );
		}
		// Top-level option.
		$raw = get_option( $path_str, '' );
		if ( empty( $raw ) ) {
			return null;
		}
		$val = maybe_unserialize( $raw );
		return self::normalize_storage_array( $val );
	}

	/**
	 * Probe a path list in priority order and return the first non-empty
	 * content plus the canonical `_meta` shape.
	 *
	 * Shape:
	 *   [
	 *     'data'         => array,        // first non-empty content (or [] when all empty)
	 *     'source_path'  => string|null,  // path that yielded content (null when all empty)
	 *     'probed_paths' => string[],     // all paths probed, in priority order
	 *   ]
	 *
	 * "Non-empty" means a non-empty array after the per-path normalization
	 * in `read_storage_path()`. An empty array seeded at the canonical path
	 * (e.g. `update_option(..., [])` on first save then trash) does NOT
	 * stop the probe; the next path gets a chance.
	 */
	private static function probe_storage_paths( array $candidates ): array {
		$probed_paths = [];
		$source_path  = null;
		$data         = [];
		foreach ( $candidates as $c ) {
			$probed_paths[] = $c['path'];
			if ( null !== $source_path ) {
				continue;
			}
			$content = self::read_storage_path( $c['path'] );
			if ( null !== $content && ! empty( $content ) ) {
				$data        = $content;
				$source_path = $c['path'];
			}
		}
		return [
			'data'         => $data,
			'source_path'  => $source_path,
			'probed_paths' => $probed_paths,
		];
	}

	// ── Preset Management ───────────────────────────────────────────

	/**
	 * Get D5 presets via priority-ordered read-probe.
	 *
	 * Probes the canonical-then-legacy D5 paths (`d5_preset_paths()`) and
	 * returns content from the first non-empty path. `_ng` is OUT-OF-BAND
	 * (#719 banner) — never consulted by READ.
	 *
	 * For callers that need the source-path provenance and probed_paths
	 * envelope shape, use `get_d5_presets_with_meta()`. This bare helper
	 * returns just the data array for backward compatibility with existing
	 * callers that don't yet thread `_meta` through.
	 */
	private static function get_d5_presets() {
		$probe = self::probe_storage_paths( self::d5_preset_paths() );
		return $probe['data'];
	}

	/**
	 * Get D5 presets WITH `_meta` shape — data + source_path + probed_paths.
	 *
	 * Returns:
	 *   [
	 *     'data'         => array,        // content from first non-empty path (or [] when all empty)
	 *     'source_path'  => string|null,  // path that yielded content (null when all empty)
	 *     'probed_paths' => string[],     // all D5 paths probed, in priority order
	 *   ]
	 *
	 * Used by LIST surfaces and by WRITE handlers that need to detect a
	 * `legacy_path_detected` hint when writing to the canonical path.
	 */
	private static function get_d5_presets_with_meta(): array {
		return self::probe_storage_paths( self::d5_preset_paths() );
	}

	/**
	 * Detect any legacy storage path that holds content beyond the canonical
	 * write target. Returns the legacy path string (e.g.
	 * `"et_divi.builder_global_presets_d5"`) when present, else null.
	 *
	 * Used after a WRITE to surface `_meta.legacy_path_detected` — agents do
	 * NOT auto-migrate; surfacing state is the contract.
	 */
	private static function detect_d5_legacy_path(): ?string {
		$canonical = 'et_divi_builder_global_presets_d5';
		foreach ( self::d5_preset_paths() as $c ) {
			if ( $c['path'] === $canonical ) {
				continue;
			}
			$content = self::read_storage_path( $c['path'] );
			if ( null !== $content && ! empty( $content ) ) {
				return $c['path'];
			}
		}
		return null;
	}

	/**
	 * Save D5 presets — canonical-target write only (#719 banner).
	 *
	 * Writes to the canonical 5.5.x storage path:
	 * `et_divi_builder_global_presets_d5` (top-level WP option).
	 *
	 * Previous behavior dual-wrote to `et_divi.builder_global_presets_d5`
	 * via `et_update_option('builder_global_presets_d5', ...)` as well —
	 * that dual-write created two synchronized copies that drift on substrate
	 * upgrades. Banner-mandated reduction to canonical-only.
	 *
	 * Never writes to `_ng` (`et_divi_builder_global_presets_ng`) — that
	 * store is D4-legacy and out-of-band per #719 banner. Divi 5's
	 * GlobalPreset only touches `_ng` for deletion-sync (and only in-process
	 * within VB save flows), not for CREATE/UPDATE. The agent does NOT
	 * implement deletion-sync in this PR.
	 */
	private static function save_d5_presets( $d5 ) {
		update_option( 'et_divi_builder_global_presets_d5', $d5, false );
	}

	/**
	 * Attach top-level `_meta` keys to a WP_REST_Response envelope.
	 *
	 * Merges into any existing `_meta` (preserving keys not in `$extra`) so
	 * callers can layer their own routing-provenance fields onto the
	 * server-side `idempotent` enrichment without clobbering. Returns the
	 * mutated response by reference for chaining-ergonomic use.
	 *
	 * Used by write paths to thread `_meta.canonical_path` + the optional
	 * `_meta.legacy_path_detected` hint per the #719 contract WRITE shape.
	 */
	private static function attach_meta( $response, array $extra ) {
		if ( ! ( $response instanceof WP_REST_Response ) ) {
			return $response;
		}
		$body          = $response->get_data();
		$existing_meta = ( is_array( $body ) && isset( $body['_meta'] ) && is_array( $body['_meta'] ) )
			? $body['_meta']
			: [];
		$body['_meta'] = array_merge( $existing_meta, $extra );
		$response->set_data( $body );
		return $response;
	}

	/**
	 * Build the standard WRITE `_meta` payload for a D5-preset mutation.
	 *
	 * Always sets `canonical_path`; conditionally adds `legacy_path_detected`
	 * when a legacy D5 storage path also holds content. Does NOT trigger any
	 * write — purely advisory.
	 */
	private static function d5_preset_write_meta(): array {
		$meta = [ 'canonical_path' => 'et_divi_builder_global_presets_d5' ];
		$legacy = self::detect_d5_legacy_path();
		if ( null !== $legacy ) {
			$meta['legacy_path_detected'] = $legacy;
		}
		return $meta;
	}

	/**
	 * Flatten the D5 registry shape into audit rows keyed by actual preset UUID.
	 *
	 * D5 storage is bucketed as:
	 *   { module: { <moduleName>: { items: { <uuid>: <preset> } } },
	 *     group:  { <groupName>:  { items: { <uuid>: <preset> } } } }
	 *
	 * AUDIT provenance is per preset UUID, not per top-level bucket.
	 */
	private static function collect_d5_preset_audit_entries( array $registry ): array {
		$rows = [];
		foreach ( [ 'module', 'group' ] as $bucket ) {
			$bucket_items = self::normalize_storage_array( $registry[ $bucket ] ?? null );
			if ( null === $bucket_items ) {
				continue;
			}
			foreach ( $bucket_items as $bucket_key => $bucket_data ) {
				if ( ! is_string( $bucket_key ) ) {
					continue;
				}
				$bucket_data = self::normalize_storage_array( $bucket_data );
				if ( null === $bucket_data ) {
					continue;
				}
				$items = self::normalize_storage_array( $bucket_data['items'] ?? null );
				if ( null === $items ) {
					continue;
				}
				foreach ( $items as $preset_id => $entry ) {
					if ( ! is_string( $preset_id ) ) {
						continue;
					}
					$rows[] = [
						'id'         => $preset_id,
						'entry'      => self::normalize_storage_array( $entry ) ?? $entry,
						'bucket'     => $bucket,
						'bucket_key' => $bucket_key,
					];
				}
			}
		}
		return $rows;
	}

	/**
	 * Flatten the D4 `_ng` legacy shape into audit rows keyed by preset ID.
	 *
	 * D4 stores by module slug:
	 *   { <d4ModuleSlug>: { presets: { <presetId>: <preset> }, default: ... } }
	 */
	private static function collect_legacy_ng_preset_audit_entries( array $registry ): array {
		$rows = [];
		foreach ( $registry as $module_slug => $module_data ) {
			if ( ! is_string( $module_slug ) ) {
				continue;
			}
			$module_data = self::normalize_storage_array( $module_data );
			if ( null === $module_data ) {
				continue;
			}
			$presets = self::normalize_storage_array( $module_data['presets'] ?? null );
			if ( null === $presets ) {
				continue;
			}
			foreach ( $presets as $preset_id => $entry ) {
				if ( ! is_string( $preset_id ) ) {
					continue;
				}
				$rows[] = [
					'id'         => $preset_id,
					'entry'      => self::normalize_storage_array( $entry ) ?? $entry,
					'bucket'     => 'legacy_ng',
					'bucket_key' => $module_slug,
				];
			}
		}
		return $rows;
	}

	/**
	 * Aggregate AUDIT across all D5 preset storage paths PLUS the OUT-OF-BAND
	 * `_ng` (`et_divi_builder_global_presets_ng`) legacy D4 store.
	 *
	 * Returns:
	 *   [
	 *     'aggregated'    => array<string,mixed>,  // union of entries by ID (D5 entries; first-write-wins per priority)
	 *     'probed_paths'  => string[],             // all paths consulted (D5 priority list + _ng)
	 *     'entry_sources' => array<string,array{path:string,provenance:string}>,
	 *     'warnings'      => array<int,array{type:string,...}>,
	 *   ]
	 *
	 * Provenance vocabulary:
	 *   - "d5_top_level"          — canonical 5.5.x path
	 *   - "d5_nested_scratchpad"  — legacy nested migration scratchpad
	 *   - "legacy_d4_ng"          — D4-era `_ng` store (out-of-band)
	 *
	 * Warning vocabulary:
	 *   - "id_collision"       — same ID in two D5 paths with shape divergence
	 *   - "shape_inconsistency" — same ID, same value-shape topology mismatch
	 *   - "ng_non_empty"       — `_ng` holds content (anomaly; surface for manual review)
	 *
	 * `_ng` content is NEVER merged into the D5-aggregated entries; it is
	 * surfaced in its own `entry_sources` entries with `legacy_d4_ng`
	 * provenance + an `ng_non_empty` warning. Consumers filtering for
	 * canonical D5 by `provenance` cannot accidentally include `_ng` content.
	 */
	private static function audit_d5_preset_storage(): array {
		$aggregated    = [];
		$entry_sources = [];
		$warnings      = [];
		$probed_paths  = [];

		// D5 paths — priority-ordered. First write wins on ID collision; we
		// still record the collision in warnings so consumers can reconcile.
		foreach ( self::d5_preset_paths() as $c ) {
			$probed_paths[] = $c['path'];
			$content        = self::read_storage_path( $c['path'] );
			if ( null === $content || empty( $content ) ) {
				continue;
			}
			foreach ( self::collect_d5_preset_audit_entries( $content ) as $row ) {
				$id    = $row['id'];
				$entry = $row['entry'];
				if ( isset( $aggregated[ $id ] ) ) {
					$shape_match = self::entries_shape_match( $aggregated[ $id ], $entry );
					$warnings[]  = [
						'type'        => $shape_match ? 'id_collision' : 'shape_inconsistency',
						'id'          => $id,
						'first_path'  => $entry_sources[ $id ]['path'] ?? null,
						'second_path' => $c['path'],
						'bucket'      => $row['bucket'],
						'bucket_key'  => $row['bucket_key'],
					];
					continue; // First-write wins by priority.
				}
				$aggregated[ $id ]    = $entry;
				$entry_sources[ $id ] = [
					'path'       => $c['path'],
					'provenance' => $c['provenance'],
					'bucket'     => $row['bucket'],
					'bucket_key' => $row['bucket_key'],
				];
			}
		}

		// `_ng` — out-of-band D4-legacy store. Probe and surface separately.
		$ng_path        = 'et_divi_builder_global_presets_ng';
		$probed_paths[] = $ng_path;
		$ng_content     = self::read_storage_path( $ng_path );
		if ( null !== $ng_content && ! empty( $ng_content ) ) {
			$ng_entries = self::collect_legacy_ng_preset_audit_entries( $ng_content );
			$warnings[] = [
				'type'        => 'ng_non_empty',
				'path'        => $ng_path,
				'entry_count' => count( $ng_entries ),
				'hint'        => 'Legacy D4 preset store contains content. Divi 5 does not consume this for primary storage; surfaced for inventory only. Never merge into D5 entries.',
			];
			foreach ( $ng_entries as $row ) {
				// `_ng` entries get their OWN entry_sources record with the
				// legacy_d4_ng provenance. They do NOT enter $aggregated (which
				// is D5-only by contract).
				$entry_sources[ $row['id'] ] = [
					'path'       => $ng_path,
					'provenance' => 'legacy_d4_ng',
					'bucket'     => $row['bucket'],
					'bucket_key' => $row['bucket_key'],
				];
			}
		}

		return [
			'aggregated'    => $aggregated,
			'probed_paths'  => $probed_paths,
			'entry_sources' => $entry_sources,
			'warnings'      => $warnings,
		];
	}

	/**
	 * Compare two preset registry entries for structural shape match.
	 *
	 * Used by `audit_d5_preset_storage()` to discriminate `id_collision`
	 * (same id, same shape — caller can pick a winner) from
	 * `shape_inconsistency` (same id, different shape — caller must
	 * reconcile manually).
	 *
	 * Compares top-level keys only — preset entries are deeply nested and a
	 * recursive shape-diff would generate noise on cosmetic differences (e.g.
	 * key ordering) that don't materially affect rendering. If both entries
	 * carry the same top-level keys (regardless of value), they're treated
	 * as the same shape; structural divergence at the top level is the
	 * signal that matters for reconciliation.
	 */
	private static function entries_shape_match( $a, $b ): bool {
		$a = self::normalize_storage_array( $a );
		$b = self::normalize_storage_array( $b );
		if ( null === $a || null === $b ) {
			return false;
		}
		$a_keys = array_keys( $a );
		$b_keys = array_keys( $b );
		sort( $a_keys );
		sort( $b_keys );
		return $a_keys === $b_keys;
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
						if ( function_exists( 'wp_delete_file' ) ) {
							wp_delete_file( $file );
						} else {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Minimal standalone/bootstrap fallback when WordPress file helpers are unavailable.
							@unlink( $file );
						}
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

	// ── Envelope helpers ─────────────────────────────────────────────
	//
	// Standard response shape for every diviops-agent route:
	//   success: { ok: true,  data: <payload> }
	//   error:   { ok: false, error: { code, message, hint? } }
	//
	// Adoption is per-namespace. Routes that have not adopted yet still
	// emit their legacy raw shapes; the helper coexists with those during
	// the rollout window.
	//
	// HTTP status stays orthogonal to envelope shape: 200 on ok:true,
	// the real upstream status (typically 400/404/409/412/500) on ok:false.

	/**
	 * Wrap a payload in the success envelope.
	 *
	 * If `$data` is already shaped as `{ok: true, data: ...}` (e.g. from a
	 * dry_run plan response built before this helper existed), pass it
	 * through unchanged so we never double-wrap.
	 *
	 * @param mixed $data         Payload to put under `data`.
	 * @param int   $http_status  HTTP status (default 200).
	 * @return WP_REST_Response
	 */
	private static function envelope_success( $data, int $http_status = 200 ) {
		if (
			is_array( $data )
			&& array_key_exists( 'ok', $data )
			&& true === $data['ok']
			&& array_key_exists( 'data', $data )
			&& 2 === count( $data )
		) {
			$body = $data;
		} else {
			$body = [ 'ok' => true, 'data' => $data ];
		}
		$response = new WP_REST_Response( $body, $http_status );
		return $response;
	}

	/**
	 * Build the error envelope.
	 *
	 * @param string      $code         Machine-readable code from the diviops vocabulary
	 *                                  (not_found, invalid_input, wp_error, divi_error,
	 *                                  capability_missing, validation_failed, conflict)
	 *                                  or a namespace-prefixed extension (`<namespace>.<reason>`).
	 * @param string      $message      Human-readable description.
	 * @param string|null $hint         Optional remediation hint (next-call suggestion, fix step).
	 * @param int         $http_status  HTTP status (default 400). Use 404 for not_found,
	 *                                  409 for conflict, 412 for capability_missing,
	 *                                  500 for wp_error/divi_error if upstream is server-side.
	 * @param mixed       $data         Optional structured payload attached to the error envelope
	 *                                  (per the `error.data` extension documented in
	 *                                  diviops-server/src/envelope.ts and
	 *                                  references/tools.md "Response shape"). Pass `null` to omit.
	 *                                  Used for failure modes that carry machine-readable detail
	 *                                  beyond a code/message pair — e.g. `divi_error` from
	 *                                  render/validate exceptions sets `data = { detail: <full> }`
	 *                                  while the message is truncated for inline display.
	 * @return WP_REST_Response
	 */
	private static function envelope_error( string $code, string $message, ?string $hint = null, int $http_status = 400, $data = null ) {
		$error = [
			'code'    => $code,
			'message' => $message,
		];
		if ( null !== $hint && '' !== $hint ) {
			$error['hint'] = $hint;
		}
		if ( null !== $data ) {
			$error['data'] = $data;
		}
		$response = new WP_REST_Response(
			[ 'ok' => false, 'error' => $error ],
			$http_status
		);
		return $response;
	}

	/**
	 * True when the current REST user may inspect a post-backed object.
	 *
	 * Free read routes keep their coarse route-level `edit_posts` gate, but raw
	 * or parsed object content needs the same row-level boundary as writes.
	 *
	 * @param mixed $post_or_id WP_Post-like object or post id.
	 * @return bool
	 */
	private static function post_object_id( $post_or_id ): int {
		if ( is_object( $post_or_id ) ) {
			return isset( $post_or_id->ID ) ? (int) $post_or_id->ID : 0;
		}

		return absint( $post_or_id );
	}

	private static function can_inspect_post_object( $post_or_id ): bool {
		$post_id      = self::post_object_id( $post_or_id );
		$check_target = is_object( $post_or_id ) ? $post_or_id : $post_id;

		return $post_id > 0 && current_user_can( 'edit_post', $check_target );
	}

	private static function get_request_page( $request ): int {
		return max( 1, absint( $request->get_param( 'page' ) ?? 1 ) );
	}

	private static function prime_inspectable_post_caches( array $post_ids ): void {
		if ( ! empty( $post_ids ) && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $post_ids, false, false );
		}
	}

	private static function filter_inspectable_post_objects( array $posts ): array {
		$ids = [];
		foreach ( $posts as $post_or_id ) {
			if ( is_object( $post_or_id ) ) {
				$post = $post_or_id;
			} else {
				$post = get_post( $post_or_id );
			}
			if ( $post && self::can_inspect_post_object( $post ) ) {
				$ids[] = (int) $post->ID;
			}
		}
		return $ids;
	}

	/**
	 * Query matching post IDs, apply exact object-inspection permissions, then paginate.
	 *
	 * Core's query-level capability filters are too coarse for these REST
	 * inventories: the contract is the same row-level `edit_post` check used by
	 * raw object reads, and pagination metadata must describe the filtered set.
	 *
	 * @param array $query_args WP_Query args for the candidate set.
	 * @param int   $per_page   Page size.
	 * @param int   $page_num   1-based page number.
	 * @return array{ids:array,total:int,total_pages:int,truncated:bool,scanned:int}
	 */
	private static function query_inspectable_post_ids(
		array $query_args,
		int $per_page,
		int $page_num = 1
	): array {
		$per_page = max( 1, $per_page );
		$page_num = max( 1, $page_num );
		// Use WP's editable query filter as a coarse prefilter, then still
		// enforce the exact per-object edit_post check below.
		unset( $query_args['paged'], $query_args['perm'], $query_args['offset'], $query_args['nopaging'] );
		$query_args['fields'] = 'ids';
		$query_args['perm']   = 'editable';

		$scan_per_page  = 500;
		$max_candidates = 5000;
		$scan_page      = 1;
		$scanned        = 0;
		$truncated      = false;
		$ids            = [];

		$query_args['posts_per_page'] = $scan_per_page;
		$query_args['no_found_rows']  = true;

		do {
			$query_args['paged'] = $scan_page++;
			$query               = new WP_Query( $query_args );
			$posts               = (array) $query->posts;

			if ( empty( $posts ) ) {
				break;
			}
			$scanned += count( $posts );
			self::prime_inspectable_post_caches( $posts );
			$ids = array_merge( $ids, self::filter_inspectable_post_objects( $posts ) );
			if ( $scanned >= $max_candidates && count( $posts ) === $scan_per_page ) {
				$truncated = true;
				break;
			}
		} while ( count( $posts ) === $scan_per_page );

		$total  = count( $ids );
		$offset = ( $page_num - 1 ) * $per_page;

		return [
			'ids'         => array_slice( $ids, $offset, $per_page ),
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'truncated'   => $truncated,
			'scanned'     => $scanned,
		];
	}

	/**
	 * Canonical row-level read denial for post-backed Free read surfaces.
	 *
	 * @param int    $post_id     Target post id.
	 * @param string $target_kind Human/machine target kind, e.g. page, module.
	 * @return WP_REST_Response
	 */
	private static function envelope_object_read_forbidden( int $post_id, string $target_kind = 'post' ) {
		return self::envelope_error(
			'forbidden',
			sprintf( 'Cannot inspect %s #%d.', $target_kind, $post_id ),
			'Authenticate as a user with edit_post capability for this object.',
			403,
			[
				'target_kind' => $target_kind,
				'post_id'     => $post_id,
			]
		);
	}

	/**
	 * Adapt a `WP_Error` to the envelope shape.
	 *
	 * - code    = `WP_Error::get_error_code()` (or `wp_error` fallback)
	 * - message = `WP_Error::get_error_message()`
	 * - hint    = `WP_Error::get_error_data()['hint']` if set
	 * - status  = `WP_Error::get_error_data()['status']` if set, else 500
	 *
	 * Use at REST-handler boundaries when an upstream call returned a
	 * `WP_Error` rather than throwing; preserves the upstream code so
	 * envelope consumers can dispatch on familiar WP error slugs.
	 *
	 * @param WP_Error $error
	 * @return WP_REST_Response
	 */
	private static function envelope_from_wp_error( $error ) {
		$code        = $error->get_error_code();
		$message     = $error->get_error_message();
		$data        = $error->get_error_data();
		$hint        = is_array( $data ) && isset( $data['hint'] ) ? (string) $data['hint'] : null;
		$http_status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
		return self::envelope_error(
			'' !== (string) $code ? (string) $code : 'wp_error',
			(string) $message,
			$hint,
			$http_status
		);
	}

	/**
	 * Adapt content-write guard errors while preserving diagnostics.
	 *
	 * @param WP_Error $error
	 * @return WP_REST_Response
	 */
	private static function envelope_from_content_write_error( $error ) {
		$code        = (string) $error->get_error_code();
		$message     = (string) $error->get_error_message();
		$data        = $error->get_error_data();
		$hint        = is_array( $data ) && isset( $data['hint'] ) ? (string) $data['hint'] : null;
		$http_status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;

		if ( is_array( $data ) ) {
			unset( $data['status'], $data['hint'] );
			if ( empty( $data ) ) {
				$data = null;
			}
		} else {
			$data = null;
		}

		return self::envelope_error(
			'' !== $code ? $code : 'wp_error',
			$message,
			$hint,
			$http_status,
			$data
		);
	}

	/**
	 * Resolve the source-of-truth content string for tools that accept either
	 * an inline `content` string OR a `page_id` to read `post_content` from
	 * the database.
	 *
	 * Used by `render_block_markup` and `validate_blocks` (and any future tool
	 * adopting the exactly-one-of-{content,page_id} input contract).
	 *
	 * Contract:
	 *   - Both supplied OR neither supplied → `invalid_input` envelope (400).
	 *   - `page_id` must be a positive integer → `invalid_input` (400).
	 *   - Caller must hold `edit_post` capability on the page → `forbidden` (403).
	 *   - Post must exist → `not_found` (404).
	 *
	 * On success returns the resolved `$content` string. On failure returns
	 * a `WP_REST_Response` envelope ready to be returned from the handler.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return string|WP_REST_Response Resolved content string on success,
	 *                                 envelope error response on failure.
	 */
	private static function resolve_content_or_page_id( $request ) {
		$content = $request->get_param( 'content' );
		$page_id = $request->get_param( 'page_id' );

		$has_content = is_string( $content );
		$has_page_id = null !== $page_id;

		if ( $has_content && $has_page_id ) {
			return self::envelope_error(
				'invalid_input',
				'Provide exactly one of {content, page_id}, not both.',
				'Use `content` for ad-hoc/pre-save markup; use `page_id` to read post_content from the DB.',
				400
			);
		}
		if ( ! $has_content && ! $has_page_id ) {
			return self::envelope_error(
				'invalid_input',
				'Provide exactly one of {content, page_id}.',
				null,
				400
			);
		}

		if ( $has_page_id ) {
			$page_id = (int) $page_id;
			if ( $page_id <= 0 ) {
				return self::envelope_error(
					'invalid_input',
					'page_id must be a positive integer.',
					null,
					400
				);
			}
			// Existence check FIRST: `current_user_can('edit_post', $id)` on a
			// non-existent post returns false (no post to test against), which
			// would surface as `forbidden` and mask the real condition. Resolve
			// existence before capability so missing pages get `not_found` and
			// only real auth misses get `forbidden`.
			$post = get_post( $page_id );
			if ( ! $post ) {
				return self::envelope_error(
					'not_found',
					sprintf( 'Post %d not found.', $page_id ),
					null,
					404
				);
			}
			if ( ! current_user_can( 'edit_post', $page_id ) ) {
				return self::envelope_error(
					'forbidden',
					sprintf( 'Cannot edit post %d.', $page_id ),
					'The current user does not have edit_post capability for this page.',
					403
				);
			}
			return (string) $post->post_content;
		}

		return (string) $content;
	}

	/**
	 * Translate a `WP_Error` from a private resolver helper into the canonical
	 * envelope shape used by `module_*` / `section_*` handlers.
	 *
	 * The helpers (`resolve_module_target`, `find_block`, `extract_section`,
	 * `find_and_replace_section`) emit a per-helper vocabulary
	 * (`module_not_found`, `section_not_found`, `block_not_found`, `no_match`,
	 * `invalid_occurrence`, `missing_target`, `ambiguous_target`,
	 * `unsupported_selector`, `invalid_auto_index`, `parse_error`) for
	 * backward compatibility of the private API.
	 *
	 * Handlers normalize that vocabulary onto the canonical envelope codes:
	 *
	 *   not_found     ← *_not_found / no_match  (with `target_kind` discriminator)
	 *   invalid_input ← invalid_*, missing_target, ambiguous_target,
	 *                   unsupported_selector  (with structured `error.data`)
	 *   divi_error    ← parse_error  (HTTP 500)
	 *
	 * `forbidden` is preserved as distinct from `capability_missing` (the
	 * latter is a handshake-layer signal; the former is a row-level
	 * WordPress auth signal).
	 *
	 * @param WP_Error $error       The helper-returned error.
	 * @param string   $target_kind The discriminator for the search miss
	 *                              ("module", "section", "block"). Forwarded
	 *                              into `error.data.target_kind` when the code
	 *                              is one of the search-miss family. Ignored
	 *                              for codes outside that family.
	 * @param int      $page_id     Page id forwarded into `error.data.page_id`.
	 * @return WP_REST_Response
	 */
	private static function envelope_from_helper_error( $error, string $target_kind, int $page_id = 0 ) {
		$code    = (string) $error->get_error_code();
		$message = (string) $error->get_error_message();
		$data    = $error->get_error_data();
		$context       = is_array( $data ) && isset( $data['context'] )       ? (string) $data['context']       : '';
		$target_mode   = is_array( $data ) && isset( $data['target_mode'] )   ? (string) $data['target_mode']   : '';
		$target_value  = is_array( $data ) && array_key_exists( 'target_value', $data ) ? $data['target_value'] : null;
		$received      = is_array( $data ) && array_key_exists( 'received', $data )      ? $data['received']      : null;
		$total_matches = is_array( $data ) && array_key_exists( 'total_matches', $data ) ? $data['total_matches'] : null;
		$fields_provided = null;
		if ( is_array( $data ) && isset( $data['fields_provided'] ) && is_array( $data['fields_provided'] ) ) {
			$fields_provided = $data['fields_provided'];
		}

		// Search-miss family — collapse onto canonical not_found with discriminator.
		// Q1 contract: { target_kind, target_mode, target_value, page_id, context? }.
		if ( in_array( $code, [ 'module_not_found', 'block_not_found', 'section_not_found', 'no_match' ], true ) ) {
			$err_data = [ 'target_kind' => $target_kind ];
			if ( '' !== $target_mode ) {
				$err_data['target_mode'] = $target_mode;
			}
			if ( null !== $target_value ) {
				$err_data['target_value'] = $target_value;
			}
			if ( $page_id > 0 ) {
				$err_data['page_id'] = $page_id;
			}
			if ( '' !== $context ) {
				$err_data['context'] = $context;
			}
			return self::envelope_error(
				'not_found',
				$message,
				'Use diviops_page_get_layout to verify available admin labels and auto_index targets.',
				404,
				$err_data
			);
		}

		// Field-level rejections — collapse onto invalid_input.
		// invalid_occurrence preserves received + total_matches symmetrically with
		// the direct module_update path so clients can write generic handlers.
		if ( in_array( $code, [ 'missing_target', 'ambiguous_target', 'unsupported_selector', 'invalid_auto_index', 'invalid_occurrence' ], true ) ) {
			$err_data = [ 'reason' => $code ];
			if ( '' !== $context ) {
				$err_data['context'] = $context;
			}
			if ( null !== $fields_provided ) {
				$err_data['fields_provided'] = array_values( $fields_provided );
			}
			if ( 'invalid_occurrence' === $code ) {
				$err_data['field']         = 'occurrence';
				$err_data['target_kind']   = $target_kind;
				if ( '' !== $target_mode ) {
					$err_data['target_mode'] = $target_mode;
				}
				if ( null !== $target_value ) {
					$err_data['target_value'] = $target_value;
				}
				if ( null !== $received ) {
					$err_data['received'] = $received;
				}
				if ( null !== $total_matches ) {
					$err_data['total_matches'] = $total_matches;
				}
			}
			return self::envelope_error(
				'invalid_input',
				$message,
				null,
				400,
				$err_data
			);
		}

		// Divi-side parse failures (malformed content) — surface as divi_error.
		if ( 'parse_error' === $code ) {
			return self::envelope_error(
				'divi_error',
				$message,
				'Re-save the page through the Visual Builder to regenerate canonical block markup.',
				500,
				$page_id > 0 ? [ 'page_id' => $page_id ] : null
			);
		}

		// Anything else — fall through to the generic adapter (preserves the
		// upstream code as-is).
		return self::envelope_from_wp_error( $error );
	}

	/**
	 * Translate `load_post_for_module_op`'s `not_found` / `forbidden` errors
	 * into the canonical envelope shape with structured `error.data`.
	 *
	 * `load_post_for_module_op` is shared by `module_lock`, `module_unlock`,
	 * and `module_clone`; this helper centralizes their identical post-load
	 * error normalization so each handler stays one-line on the unhappy path.
	 */
	private static function envelope_post_load_error( $error, int $page_id ) {
		$code = (string) $error->get_error_code();
		if ( 'not_found' === $code ) {
			return self::envelope_error(
				'not_found',
				"Page #{$page_id} not found.",
				'Verify the page id via diviops_page_list.',
				404,
				[ 'target_kind' => 'page', 'page_id' => $page_id ]
			);
		}
		if ( 'forbidden' === $code ) {
			return self::envelope_error(
				'forbidden',
				"Cannot edit page #{$page_id}.",
				'Authenticate as a user with edit rights to this post.',
				403,
				[ 'page_id' => $page_id ]
			);
		}
		return self::envelope_from_wp_error( $error );
	}

	/**
	 * Multibyte-safe truncation for envelope error messages.
	 *
	 * Render/validate exception messages can carry multi-kilobyte payloads
	 * (full stack traces, embedded block JSON dumps) and arrive as UTF-8
	 * (translated strings via `__()` flow through `WP_Error::get_error_message()`).
	 * MCP clients displaying `error.message` inline don't want that volume,
	 * so we cap at `$max` characters and stash the full detail in
	 * `error.data.detail` for callers that need it.
	 *
	 * Multibyte-safe (`mb_strlen` / `mb_substr` over `strlen` / `substr`) so a
	 * mid-codepoint cut never produces invalid UTF-8 in the truncated output.
	 *
	 * @param string $message Source message, possibly long and possibly UTF-8.
	 * @param int    $max     Character cap (default 500). Ellipsis is appended when truncation occurs.
	 * @return string         Original on-fit; otherwise `mb_substr(..., 0, $max) . '…'`.
	 */
	private static function truncate_envelope_message( string $message, int $max = 500 ): string {
		if ( mb_strlen( $message ) <= $max ) {
			return $message;
		}
		return mb_substr( $message, 0, $max ) . '…';
	}

	// ── dry_run plan helper ────────────────────────────────────────

	/**
	 * Build a standard dry_run plan response — { ok: true, data: { dry_run: true, plan: { summary, changes[, warnings ] } } }.
	 *
	 * Every conformant write handler returns this shape when `dry_run: true`. Extra
	 * envelope keys (e.g. preview metadata) may be added via `$extra`, but the plan slot
	 * itself stays uniform so callers can pattern-match without per-tool branching.
	 *
	 * Routes through `envelope_success` so the wire shape is byte-identical
	 * to the pre-envelope dry_run shape — single emit point, no double-wrap.
	 *
	 * @param string $summary  One-line human-readable description.
	 * @param array  $changes  Array of { kind, target, before?, after? } entries.
	 * @param array  $warnings Optional non-fatal advisories the apply path would surface.
	 * @param array|object $extra Optional sibling keys to merge into `data` (alongside dry_run+plan).
	 */
	private static function dry_run_response( $summary, $changes = [], $warnings = [], $extra = [] ) {
		$extra = (array) $extra;
		$plan = [
			'summary' => (string) $summary,
			'changes' => array_values( $changes ),
		];
		if ( ! empty( $warnings ) ) {
			$plan['warnings'] = array_values( $warnings );
		}
		$data = array_merge(
			[
				'dry_run' => true,
				'plan'    => $plan,
			],
			$extra
		);
		return self::envelope_success( $data );
	}
}
