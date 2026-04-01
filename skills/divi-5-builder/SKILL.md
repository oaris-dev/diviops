---
name: divi-5-builder
description: Use this skill when users want to build, edit, or design pages and layouts on Divi 5 WordPress sites. Triggers include creating landing pages, hero sections, testimonial cards, pricing tables, feature grids, blog listing pages with post loops and pagination; adding or modifying sections on existing pages by page ID; setting up design systems with tokens and presets; theme builder templates for headers, footers, and post/page body layouts; mega menus and site navigation; saving to or loading from the Divi library; auditing or cleaning up presets. Handles animations, hover effects, responsive design, and dynamic content. Also covers special effects like WebGL shader backgrounds and advanced CSS animations via the DiviOps Design Library plugin. Do NOT use for custom PHP/plugins, child theme development, standalone CSS authoring, SQL queries, WooCommerce configuration, SEO setup, standalone Three.js projects without WordPress, or non-Divi builders like Elementor.
compatibility: Requires divi-mcp MCP server connected to a WordPress site with Divi 5 and the diviops-agent plugin active.
metadata:
  author: oaris-dev
  version: "1.0"
  divi-version: "5.1.1"
---

# Divi 5 Builder Skill

Build modern, VB-editable Divi 5 pages programmatically via MCP tools.

## Reference Files

Read the right file for the task at hand — don't load everything.

| Task | Read first |
|------|-----------|
| Using MCP tools & targeting | [tools.md](references/tools.md) |
| Creating/editing pages | [design-guide.md](references/design-guide.md) → [module-formats.md](references/module-formats.md) |
| Module attribute paths | [module-formats.md](references/module-formats.md) (Tier 1 free — Tier 2 patterns + Tier 3 per-module are Pro) |
| Adding CSS classes to modules | [design-effects.md](references/design-effects.md) — uses `module.decoration.attributes`, NOT `className` |
| CSS effects & WebGL shaders | [design-effects.md](references/design-effects.md) |
| Mega menus & navigation | [mega-menu-pattern.md](references/mega-menu-pattern.md) |
| Presets & cleanup | [presets.md](references/presets.md) |
| Design system setup | [SKILL.md](#design-system-lifecycle) (below) → [presets.md](references/presets.md) |
| Page templates | [patterns/](references/patterns/) — SaaS landing, more coming |

## Workflow Best Practices

1. **Build incrementally**: `create_page` → `append_section` × N
2. **Always label sections**: `meta.adminLabel` on every section
3. **Label key modules**: Add admin labels to modules you might edit later
4. **Validate before saving**: Use `diviops_validate_blocks` before `diviops_update_page_content`, `diviops_update_tb_layout`, or `diviops_save_to_library`
5. **Use `diviops_find_icon`**: Don't guess icon codes — search by keyword
6. **Prefer VB-native**: Use Divi attributes over CSS whenever possible
7. **Font inheritance**: Set global fonts via theme options, skip explicit `family` on modules
8. **Use semantic HTML**: Set `elementType` for SEO/accessibility (`header`, `nav`, `main`, `article`, `footer`)
9. **Always use section/row/column structure**: Wrapperless top-level modules lose styling
10. **Cache invalidation**: All write tools auto-invalidate Divi's CSS cache. If styles appear stale, hard-refresh the browser.

### Design Quality Checklist
When generating pages, ALWAYS apply:
- **Entrance animations** on visible modules (`fade`/`slide` with staggered `delay`: 0ms, 150ms, 300ms, 450ms)
- **Hover states** on cards, buttons, icons (use `desktop.hover` format)
- **Responsive overrides** (tablet/phone: padding, font sizes, `flexDirection: column`)
- **Use `divi/number-counter`** for stats — animates counting on scroll (not plain text)
- **Use Group flex** for multi-column layouts with `flexType` sizing (not Row with multiple columns)
- **Minimum**: 8+ animations per page, hover on every interactive element
- See [design-guide.md](references/design-guide.md) for copy-paste patterns and recipes

## Design System Lifecycle

The `oa` design system uses universal token names (`gcid-oa-primary-500`, `gvid-oa-size-h1`) and preset names (`oa Heading H1`, `oa Button Primary`) across all projects. What differs per site is the **values** behind tokens and the **UUIDs** Divi assigns to presets.

### Manifest: `.claude/design-system.json`

A per-project file that maps preset **role keys** (e.g. `heading-h1`, `button-primary`) to site-specific UUIDs. NOT shipped with the skill — lives in the project's `.claude/` directory. Read this file before generating any page that uses presets.

Role key convention: lowercase preset name, drop `oa ` prefix, spaces to hyphens (e.g. `oa Heading H1` → `heading-h1`).

### Project states

| State | oa tokens? | oa presets? | Manifest? | Agent behavior |
|-------|-----------|-------------|-----------|----------------|
| **Fresh site** | No | No | No | Inline values; suggest full bootstrap |
| **Branded, not normalized** | No (has project-local colors) | No | No | Inline values; suggest bootstrap with brand mapping |
| **Partially bootstrapped** | Some | No | No | Use available tokens inline; suggest completing bootstrap |
| **Tokens complete, presets pending** | Yes | No | No | Tokens via `$variable()`; inline font/button styling; suggest VB preset creation |
| **Fully bootstrapped** | Yes | Yes | Yes | Full preset-driven generation via manifest |
| **Bootstrapped, stale manifest** | Yes | Yes | Outdated | Re-run Step 1 audit + Step 4 manifest regeneration |

### Bootstrap workflow

Run when starting a new project or when the manifest is missing.

**Step 1 — Audit existing site:**
1. `diviops_list_variables` with `prefix: "gcid-oa-"` — check for oa color tokens
2. `diviops_list_variables` with `prefix: "gvid-oa-"` — check for oa number tokens
3. `diviops_preset_audit` — check for oa-prefixed presets

**Step 2 — Create tokens (if missing):**
1. Ask user for brand colors: primary, secondary, neutral (name + hex base)
2. Generate shade scales (50-950) for each family
3. Create color tokens via `diviops_create_variable` — ~35 calls
4. Create number tokens (font sizes, spacings, radii, line heights) — ~37 calls (~72 total)

**Step 3 — Create presets (if missing):**
Presets must be built in the Visual Builder (no programmatic creation).
1. Provide user a checklist of presets to create (see [presets.md](references/presets.md) for the full catalog)
2. After each batch, run `diviops_preset_audit` to verify and capture UUIDs

**Step 4 — Generate manifest:**
1. Match preset names to role keys (e.g. "oa Heading H1" → `heading-h1`)
2. Confirm token counts from `diviops_list_variables`
3. Write `.claude/design-system.json` (see [presets.md](references/presets.md) for schema)

**Step 5 — Generate project docs (optional):**
Write `.claude/instructions/design-system.md` with brand-specific guidance: aesthetic direction, color personality, design conventions.

### Resolving preset UUIDs at generation time

1. Read `.claude/design-system.json` → look up `presets.<role-key>.id` (fast path)
2. If manifest missing → `diviops_preset_audit`, match by name, build in-memory map
3. If no oa presets found → use inline styling (design-guide.md patterns work without presets)

## Critical Block Format Rules

1. **Always wrap in `divi/placeholder`**: `<!-- wp:divi/placeholder -->...<!-- /wp:divi/placeholder -->`
2. **Always include `builderVersion`**: `"builderVersion":"5.1.1"` on every block
3. **Self-closing blocks**: Use `<!-- wp:divi/text {...} /-->` (with `/-->`) for leaf modules
4. **HTML in innerContent**: Use unicode escapes: `\u003cp\u003e` not `<p>`
5. **Layout display on containers**: Section, Row, Column, Group need `"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}}` — content modules (Text, Button, Icon) don't require it
6. **Admin labels on important modules**: `"meta":{"adminLabel":{"desktop":{"value":"My Label"}}}` — required for granular editing

## Module Gotchas (Silent Failures)

Full attribute paths in [module-formats.md](references/module-formats.md) Tier 3 (Pro). These are the traps:

- **Button**: border/bg/font on `button.decoration` (NOT `module.decoration`). Sizing + alignment on `button.decoration.sizing` (5.1.1+, alignment inside sizing object)
- **Icon**: border/bg on `module.decoration` only — `icon.decoration.border/background` creates non-VB-editable inner ring
- **Image**: spacing/sizing on `module.advanced` (NOT `module.decoration`)
- **Blurb**: icon size at `imageIcon.decoration.sizing.desktop.value.iconFontSize` (5.1.1+, was `imageIcon.advanced.width`). Full decoration now on `imageIcon.decoration.{background,border,animation,...}`
- **Group**: gap is `columnGap` + `rowGap` (NOT single `gap`); column sizing via `flexType` 24-unit grid (NOT `flexGrow`/`flexBasis`)
- **Contact Form**: all fonts use double `font.font` nesting; field labels use `fieldItem.innerContent` (NOT `title.innerContent`)
- **CSS classes**: via `module.decoration.attributes.desktop.value.attributes[]` array
- **freeForm CSS**: top-level `css.desktop.value.freeForm` — sibling of `module`, NOT inside it

## VB-Safe Rules

Rules for generating content that remains editable in the Visual Builder:

### Dynamic Content (`$variable()$`)
| Rule | Detail |
|------|--------|
| **One `$variable()$` per field** | Multiple variables inline render on frontend but VB destroys on save |
| **Use `before`/`after` settings** | `settings: {"before":"Artikel: "}` for prefix/suffix — VB shows editable fields |
| **Never nest variables** | `$variable()$` inside `before`/`after` fields doesn't resolve |
| **Never paste in text field** | VB auto-converts to chip, loses surrounding text on save |

### Attributes
| Rule | Detail |
|------|--------|
| **Prefer VB-native attrs over CSS** | CSS-only styling can't be edited in VB |
| **`inline-flex` requires CSS** | VB only offers flex/grid/block |
| **`white-space: nowrap` requires CSS** | No VB equivalent |
| **Position mode**: `position.desktop.value.mode` | Use `"absolute"`, `"relative"`, `"fixed"` — NOT `position.position` |
| **Icon: border/background on `module.decoration` only** | Don't use `icon.decoration.border/background` — creates a non-VB-editable inner border ring. Use `module.decoration` for all visual styling |
| **Hover format**: `desktop.hover` not top-level | `"background":{"desktop":{"value":{...},"hover":{"color":"#f59e0b"}}}` — hover is a sibling of `value` inside `desktop`, NOT a sibling of `desktop`. Top-level `hover` is silently ignored |
| **Parent context affects sizing** | Column `alignItems: "stretch"` makes children full-width. Use `"center"` if child modules should respect their own sizing/padding |
| **Don't hardcode `display: "block"`** | VB can set `flex`, `block`, `inline-flex`, `grid`. Only set display when needed, and use what the design requires |
| **Color formats** | All valid: hex (`#6366f1`), rgba (`rgba(99,102,241,0.1)`), hsl, CSS variables, global color `$variable()$` |

### CSS Specificity
- Use `background-image` NOT `background` shorthand (Divi's `background-color: !important` wins)
- Chain selectors: `.my-class.et_pb_section` for specificity parity
- Divi's critical inline CSS can override freeForm CSS — use specific selectors

## Loop & Dynamic Content

Build post/product/CPT listing pages. See [module-formats.md](references/module-formats.md) for `$variable()$` syntax.

### Workflow: Skeleton → User Binds
1. Generate card layout with loop enabled, styled, responsive
2. Placeholder text with `BIND:` admin labels on each module
3. User opens VB → binds each module to dynamic content
4. Optionally: inject `$variable()$` bindings programmatically after confirmation

### Loop Essentials
- **Custom loopId**: any string, e.g. `"loop-blog-01"`
- **subTypes format**: `[{"label":"Posts","value":"post"}]` — label+value objects, not plain strings
- **Any container**: Group, Column, Row, or Section can be the loop container
- **Custom post types**: `{"label":"Projects","value":"project"}` — all dynamic vars work the same
- **Pagination**: `divi/post-nav` after loop container with matching `targetLoop`

## Theme Builder

Manage headers, footers, and body templates per page/post type.

Key points:
- Templates use `et_template` post type with separate `et_header_layout` / `et_footer_layout` posts
- Assignment via `_et_use_on` condition meta (e.g. `singular:post_type:page:id:243`)
- `diviops_create_tb_template` handles the full recipe: layout posts + template + link to master
- Layout content is standard Divi block markup — same format as pages
- **Critical**: `_et_pb_use_divi_5: on` required on all layout posts (handled by `initialize_divi_page_meta`)

## Design Patterns

See [design-guide.md](references/design-guide.md) for copy-paste JSON patterns:
- Multi-column card grids (Group-based, flexType sizing)
- Animation staggering, hover states, responsive overrides
- Stats sections (number-counter), review cards, eyebrow labels
- Gradient hero (CSS animation), marquee (continuous scroll)

For mega menus: [mega-menu-pattern.md](references/mega-menu-pattern.md)
For WebGL shaders and DiviOps Design Library effects: [design-effects.md](references/design-effects.md)

## Presets

Divi 5 stores module presets in `builder_global_presets_d5`. See [presets.md](references/presets.md) for full architecture.

Key points:
- Presets provide default styles per module type — modules inherit without explicit attrs
- Referenced in blocks via `"modulePreset": ["preset-uuid"]` or `["default"]`
- Preset management endpoints: `preset-audit`, `preset-cleanup`, `preset-update`, `preset-delete`

## Known Limitations

- `$variable()` for global colors works for rendering but may not show in VB color picker
- Button hover has hardcoded CSS: `.et_pb_button:hover { padding: .3em 2em .3em .7em }` — use CSS override
- `divi/link` module has rendering issues — use `divi/text` with `elementType: "li"` for nav items instead
- Icon module: `icon.decoration.border` and `icon.decoration.background` render correctly but are not editable in VB settings panel — use `module.decoration.border` and `module.decoration.background` instead
- Large pages (50+ modules) need slim layout mode — `diviops_get_page_layout` returns targeting metadata only by default
