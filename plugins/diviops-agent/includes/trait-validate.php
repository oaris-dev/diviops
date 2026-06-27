<?php
/**
 * Trait DiviOps_Agent_Validate
 *
 * Block tree validation + per-module rules.
 *
 * Part of the diviops-agent monolith split (#220). Mixed into
 * DiviOps_Agent via `use` in diviops-agent.php — `self::` calls and
 * class constants resolve as if these methods lived directly on the
 * class.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_Validate {

	/**
	 * Validate Divi block markup. Checks structure, required attributes,
	 * and known pitfalls (button padding, gradient format, etc.).
	 *
	 * Envelope-adopted. Tool-level errors map to:
	 *   - invalid_input — content is not a string
	 *   - divi_error    — walker threw a Throwable
	 *
	 * NOTE: shape errors detected in the markup surface as success-branch
	 * `data.errors[]` entries — NOT as `validation_failed` envelopes. The
	 * findings array IS the success payload, not an error. `validation_failed`
	 * is reserved for future tools that pre-check input via this validator
	 * and short-circuit on failure.
	 */
	public static function validate_blocks( $request ) {
		$resolved = self::resolve_content_or_page_id( $request );
		if ( $resolved instanceof WP_REST_Response ) {
			return $resolved;
		}
		$content = $resolved;

		try {
			$blocks   = parse_blocks( $content );
			$registry = WP_Block_Type_Registry::get_instance();

			$errors   = [];
			$warnings = [];
			$index    = 0;

			$container_types = [ 'divi/section', 'divi/row', 'divi/column', 'divi/group', 'divi/group-carousel', 'divi/dropdown' ];

			self::validate_block_tree( $blocks, $registry, $container_types, $errors, $warnings, $index );
		} catch ( \Throwable $e ) {
			$full_detail = $e->getMessage();
			return self::envelope_error(
				'divi_error',
				self::truncate_envelope_message( $full_detail ),
				'The validator hit an unexpected error walking the block tree. Try simplifying the markup to isolate the failing block.',
				500,
				[ 'detail' => $full_detail ]
			);
		}

		return self::envelope_success( [
			'valid'        => empty( $errors ),
			'total_blocks' => $index,
			'errors'       => $errors,
			'warnings'     => $warnings,
		] );
	}

	/**
	 * Recursively validate a block tree.
	 */
	private static function validate_block_tree( $blocks, $registry, $container_types, &$errors, &$warnings, &$index, &$nav_refs = null, $responsive_parent = null ) {
		$owns_nav_refs = false;
		if ( null === $nav_refs ) {
			$nav_refs      = [
				'ids'           => [],
				'aria_controls' => [],
			];
			$owns_nav_refs = true;
		}

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

			// ── Responsive flex-card checks (warnings) ──────────────

			if ( $is_divi_block && is_array( $responsive_parent ) ) {
				self::validate_responsive_flex_child( $name, $attrs, $index, $responsive_parent, $warnings );
			}

			// ── Navigation reliability checks (errors + warnings) ───

			if ( $is_divi_block ) {
				$element_type = self::get_divi_html_value( $attrs, 'elementType' );
				if ( 'divi/link' === $name && 'li' === $element_type ) {
					$errors[] = [
						'block'   => $name,
						'index'   => $index,
						'code'    => 'nav_link_elementtype_li',
						'message' => 'divi/link with elementType:"li" destroys the anchor. Use htmlBefore:"<li>" and htmlAfter:"</li>" around the link instead.',
						'path'    => 'module.advanced.html.desktop.value.elementType',
					];
				}

				foreach ( self::extract_divi_custom_attributes( $attrs ) as $custom_attr ) {
					$attr_name = $custom_attr['name'];
					$attr_val  = $custom_attr['value'];
					$path      = $custom_attr['path'];

					if ( '' === $attr_val ) {
						$warnings[] = [
							'block'   => $name,
							'index'   => $index,
							'code'    => 'nav_empty_custom_attribute',
							'message' => sprintf(
								'Custom attribute "%s" has an empty string value. Divi drops empty-valued custom attrs at render; use a non-empty value such as "true" for boolean data flags.',
								$attr_name
							),
							'path'    => $path . '.value',
						];
					}

					if ( 'id' === $attr_name && is_string( $attr_val ) && '' !== trim( $attr_val ) ) {
						$nav_refs['ids'][ trim( $attr_val ) ] = true;
					}

					if ( 'aria-controls' === $attr_name && is_string( $attr_val ) && '' !== $attr_val ) {
						foreach ( preg_split( '/\s+/', trim( $attr_val ) ) as $controlled_id ) {
							if ( '' === $controlled_id ) {
								continue;
							}
							$nav_refs['aria_controls'][] = [
								'id'    => $controlled_id,
								'block' => $name,
								'index' => $index,
								'path'  => $path . '.value',
							];
						}
					}
				}
			}

			// ── Button checks (errors + warnings) ───────────────────

			if ( 'divi/button' === $name ) {
				$button_deco       = self::get_nested_array_value( $attrs, [ 'button', 'decoration' ], [] );
				$button_deco       = is_array( $button_deco ) ? $button_deco : [];
				$has_sibling_style = isset( $button_deco['border'] ) || isset( $button_deco['background'] )
					|| isset( $button_deco['font'] ) || isset( $button_deco['boxShadow'] );

				// Warn about unrecognized keys at button.decoration.button.desktop.value.*
				// Source-verified render-relevant keys at this path (Divi 5.4.0):
				//   - `enable`   — read by Migration/ComposibleOptionsMigration.php:563
				//                  ('off' triggers a destructive migration that strips
				//                  button.decoration). Not a styling input.
				//   - `icon`     — full icon subtree (settings/placement/onHover/enable);
				//                  read by ButtonStyle and StyleDeclarations.
				//   - `padding`  — read as a *gate* by Style/StyleDeclarations.php:73,
				//                  76-77 to suppress icon-spacing autoinjection. Not a
				//                  visible-padding emitter; padding for layout lives at
				//                  module.decoration.spacing.
				//   - `alignment` — deprecated input (Conversion/DeprecatedAttributeMapping.php:962)
				//                  forwarded to decoration.sizing.alignment.
				// Anything outside this set has no render consumer here — parses, saves,
				// validates clean, and is silently dropped at render. The motivating case
				// was a button authored with `backgroundColor`, `textColor`, `font` keys
				// at this depth; canonical styling lives at the sibling-level paths
				// button.decoration.{font, background, border, boxShadow}.
				$btn_value_node = self::get_nested_array_value( $attrs, [ 'button', 'decoration', 'button', 'desktop', 'value' ] );
				if ( is_array( $btn_value_node ) ) {
					$allowed_keys = array_flip( [ 'enable', 'icon', 'padding', 'alignment' ] );
					$unrecognized = array_diff_key( $btn_value_node, $allowed_keys );
					if ( ! empty( $unrecognized ) ) {
						$keys = array_keys( $unrecognized );
						sort( $keys );
						$warnings[] = [
							'block'   => $name,
							'index'   => $index,
							'code'    => 'button_no_render_consumer',
							'message' => sprintf(
								'Unrecognized keys at button.decoration.button.desktop.value: [%s]. Render-relevant keys here are limited to enable, icon, padding, alignment. Move visual styling to button.decoration.{font,background,border,boxShadow} and layout padding to module.decoration.spacing.',
								implode( ', ', $keys )
							),
							'path'    => 'button.decoration.button.desktop.value → button.decoration',
						];
					}
				}

				// Warn when custom styling is present but the default hover arrow will show.
				// Triggers when button has visible customization (sibling-level styling) but
				// icon.enable !== "off". Without the explicit suppression, Divi's CSS shows
				// the default arrow on hover. Only fires when the user is clearly customizing,
				// to avoid noise on default-styled buttons.
				if ( $has_sibling_style ) {
					$icon_enable = self::get_nested_array_value( $attrs, [ 'button', 'decoration', 'button', 'desktop', 'value', 'icon', 'enable' ] );
					if ( 'off' !== $icon_enable ) {
						$warnings[] = [
							'block'   => $name,
							'index'   => $index,
							'code'    => 'button_missing_icon_enable',
							'message' => 'Button has custom styling but icon.enable is not "off" — Divi will show the default arrow icon on hover.',
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

			// ── flexType on the wrong decoration bucket (warning, any module) ──
			// Canonical: module.decoration.sizing.desktop.value.flexType (Sizing subName,
			//            verified at Packages/Module/Options/Sizing/SizingPresetAttrsMap.php:124-128).
			// Wrong:     module.decoration.layout.{breakpoint}.value.flexType — Layout registers
			//            no flexType subName, so the value is byte-stored but semantically dropped
			//            at render. Applies on every breakpoint identically.
			if ( $is_divi_block ) {
				foreach ( [ 'desktop', 'tablet', 'phone' ] as $bp ) {
					$wrong_flex = self::get_nested_array_value( $attrs, [ 'module', 'decoration', 'layout', $bp, 'value', 'flexType' ] );
					if ( null !== $wrong_flex ) {
						$warnings[] = [
							'block'   => $name,
							'index'   => $index,
							'code'    => 'flextype_wrong_path',
							'message' => sprintf(
								'flexType on module.decoration.layout.%s.value is the wrong decoration bucket — Layout has no flexType subName, so the value is byte-stored but dropped at render. Move to module.decoration.sizing.%s.value.flexType (the canonical Sizing path). Note: flexType is a 24-unit grid for flex children — the parent row/group must also have module.decoration.layout.desktop.value.display = "flex" or it falls back to legacy et_pb_column_N_M classes by count.',
								$bp,
								$bp
							),
							'path'    => sprintf( 'module.decoration.layout.%s.value.flexType → module.decoration.sizing.%s.value.flexType', $bp, $bp ),
						];
					}
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
				$child_responsive_parent = self::build_responsive_flex_parent_context( $name, $attrs, $index, $container_types );
				self::validate_block_tree( $block['innerBlocks'], $registry, $container_types, $errors, $warnings, $index, $nav_refs, $child_responsive_parent );
			}
		}

		if ( $owns_nav_refs ) {
			self::validate_nav_aria_references( $nav_refs, $warnings );
		}
	}

	private static function build_responsive_flex_parent_context( string $name, array $attrs, int $index, array $container_types ): ?array {
		if ( ! in_array( $name, $container_types, true ) ) {
			return null;
		}

		$has_flex_signal   = false;
		$stack_breakpoints = [];

		foreach ( [ 'desktop', 'tablet', 'phone' ] as $breakpoint ) {
			$layout = self::get_nested_array_value( $attrs, [ 'module', 'decoration', 'layout', $breakpoint, 'value' ], [] );
			$layout = is_array( $layout ) ? $layout : [];
			if ( self::layout_has_flex_signal( $layout ) ) {
				$has_flex_signal = true;
			}
		}

		if ( ! $has_flex_signal ) {
			return null;
		}

		foreach ( [ 'tablet', 'phone' ] as $breakpoint ) {
			$display = self::get_inherited_responsive_value( $attrs, [ 'module', 'decoration', 'layout' ], $breakpoint, 'display' );
			if ( in_array( $display, [ 'block', 'grid' ], true ) ) {
				continue;
			}

			$flex_direction = self::get_inherited_responsive_value( $attrs, [ 'module', 'decoration', 'layout' ], $breakpoint, 'flexDirection', 'row' );
			if ( in_array( $flex_direction, [ 'column', 'column-reverse' ], true ) ) {
				$stack_breakpoints[] = $breakpoint;
			}
		}

		if ( empty( $stack_breakpoints ) ) {
			return null;
		}

		return [
			'block'             => $name,
			'index'             => $index,
			'stack_breakpoints' => $stack_breakpoints,
		];
	}

	private static function layout_has_flex_signal( array $layout ): bool {
		if ( 'flex' === ( $layout['display'] ?? null ) ) {
			return true;
		}

		foreach ( [ 'flexDirection', 'flexWrap', 'justifyContent', 'alignItems', 'alignContent', 'gap', 'rowGap', 'columnGap' ] as $key ) {
			if ( isset( $layout[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function get_inherited_responsive_value( array $attrs, array $path_prefix, string $breakpoint, string $key, $default = null ) {
		$breakpoints = [ 'desktop' ];
		if ( 'tablet' === $breakpoint ) {
			$breakpoints = [ 'tablet', 'desktop' ];
		} elseif ( 'phone' === $breakpoint ) {
			$breakpoints = [ 'phone', 'tablet', 'desktop' ];
		}

		foreach ( $breakpoints as $candidate ) {
			$value = self::get_nested_array_value( $attrs, array_merge( $path_prefix, [ $candidate, 'value', $key ] ) );
			if ( null !== $value ) {
				return $value;
			}
		}

		return $default;
	}

	private static function validate_responsive_flex_child( string $name, array $attrs, int $index, array $parent, array &$warnings ): void {
		$desktop_flex_type = self::get_nested_array_value( $attrs, [ 'module', 'decoration', 'sizing', 'desktop', 'value', 'flexType' ] );
		if ( ! self::is_fractional_flex_type( $desktop_flex_type ) ) {
			return;
		}

		foreach ( $parent['stack_breakpoints'] ?? [] as $breakpoint ) {
			if ( self::has_full_width_flex_protection( $attrs, (string) $breakpoint ) ) {
				continue;
			}

			$warnings[] = [
				'block'        => $name,
				'index'        => $index,
				'code'         => 'responsive_flex_child_missing_full_width',
				'message'      => sprintf(
					'%s at index %d uses desktop flexType "%s", but parent %s at index %d stacks at %s without a matching child full-width override. Add module.decoration.sizing.%s.value.flexType = "24_24", or width/maxWidth = "100%%" at the same breakpoint.',
					$name,
					$index,
					(string) $desktop_flex_type,
					(string) ( $parent['block'] ?? '(parent)' ),
					(int) ( $parent['index'] ?? 0 ),
					(string) $breakpoint,
					(string) $breakpoint
				),
				'path'         => sprintf( 'module.decoration.sizing.%s.value', (string) $breakpoint ),
				'breakpoint'   => (string) $breakpoint,
				'parent_block' => (string) ( $parent['block'] ?? '' ),
				'parent_index' => (int) ( $parent['index'] ?? 0 ),
			];
		}
	}

	private static function is_fractional_flex_type( $value ): bool {
		if ( ! is_string( $value ) || ! preg_match( '/^([0-9]+)_24$/', $value, $matches ) ) {
			return false;
		}

		$units = (int) $matches[1];
		return $units > 0 && $units < 24;
	}

	private static function has_full_width_flex_protection( array $attrs, string $breakpoint ): bool {
		$flex_type = self::get_inherited_responsive_value( $attrs, [ 'module', 'decoration', 'sizing' ], $breakpoint, 'flexType' );
		if ( '24_24' === $flex_type ) {
			return true;
		}

		$width     = self::get_inherited_responsive_value( $attrs, [ 'module', 'decoration', 'sizing' ], $breakpoint, 'width' );
		$max_width = self::get_inherited_responsive_value( $attrs, [ 'module', 'decoration', 'sizing' ], $breakpoint, 'maxWidth' );

		if ( ! self::is_full_width_css_value( $width ) ) {
			return false;
		}

		return self::is_unconstrained_max_width_value( $max_width )
			|| self::is_full_width_css_value( $max_width );
	}

	private static function is_full_width_css_value( $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}

		return '100%' === trim( (string) preg_replace( '/\s*!\s*important/i', '', $value ) );
	}

	private static function is_unconstrained_max_width_value( $value ): bool {
		if ( null === $value ) {
			return true;
		}

		if ( ! is_string( $value ) ) {
			return false;
		}

		return in_array( strtolower( trim( $value ) ), [ '', 'auto', 'none' ], true );
	}

	/**
	 * Read Divi's canonical HTML customization values, with legacy fallback for
	 * older hand-authored markup that stored elementType under decoration attrs.
	 */
	private static function get_divi_html_value( array $attrs, string $key ) {
		$value = self::get_nested_array_value( $attrs, [ 'module', 'advanced', 'html', 'desktop', 'value', $key ] );
		if ( null !== $value ) {
			return $value;
		}

		return self::get_nested_array_value( $attrs, [ 'module', 'decoration', 'attributes', 'desktop', 'value', $key ] );
	}

	/**
	 * Extract custom attributes from known Divi storage shapes only.
	 *
	 * @return array<int,array{name:string,value:mixed,path:string}>
	 */
	private static function extract_divi_custom_attributes( array $attrs ): array {
		$found = [];
		foreach ( [ 'desktop', 'tablet', 'phone' ] as $breakpoint ) {
			$custom_attrs = self::get_nested_array_value( $attrs, [ 'module', 'decoration', 'attributes', $breakpoint, 'value', 'attributes' ] );
			if ( ! is_array( $custom_attrs ) ) {
				continue;
			}

			foreach ( $custom_attrs as $attr_index => $custom_attr ) {
				if ( ! is_array( $custom_attr ) || ! array_key_exists( 'name', $custom_attr ) || ! array_key_exists( 'value', $custom_attr ) ) {
					continue;
				}
				if ( ! is_string( $custom_attr['name'] ) || '' === $custom_attr['name'] ) {
					continue;
				}

				$found[] = [
					'name'  => $custom_attr['name'],
					'value' => $custom_attr['value'],
					'path'  => sprintf( 'module.decoration.attributes.%s.value.attributes[%s]', $breakpoint, (string) $attr_index ),
				];
			}
		}

		return $found;
	}

	private static function validate_nav_aria_references( array $nav_refs, array &$warnings ): void {
		$ids = is_array( $nav_refs['ids'] ?? null ) ? $nav_refs['ids'] : [];
		foreach ( $nav_refs['aria_controls'] ?? [] as $ref ) {
			if ( ! is_array( $ref ) || ! isset( $ref['id'] ) || isset( $ids[ $ref['id'] ] ) ) {
				continue;
			}

			$warnings[] = [
				'block'   => $ref['block'] ?? '(unknown)',
				'index'   => $ref['index'] ?? 0,
				'code'    => 'nav_unresolved_aria_controls',
				'message' => sprintf(
					'aria-controls references "%s", but no matching custom attribute id was found in the submitted content. The Divi dropdown trigger will silently no-op when the pair does not resolve.',
					(string) $ref['id']
				),
				'path'    => $ref['path'] ?? 'module.decoration.attributes.*.value.attributes[].value',
			];
		}
	}
}
