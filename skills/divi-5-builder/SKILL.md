---
name: divi-5-builder
description: Use this skill when users want to build, edit, or design pages and layouts on Divi 5 WordPress sites. Triggers include creating landing pages, hero sections, testimonial cards, pricing tables, feature grids, blog listing pages with post loops and pagination; adding or modifying sections on existing pages by page ID; setting up design systems with tokens and presets; theme builder templates for headers, footers, and post/page body layouts; mega menus and site navigation; saving to or loading from the Divi library; auditing or cleaning up presets. Handles animations, hover effects, responsive design, and dynamic content. Also covers special effects like WebGL shader backgrounds and advanced CSS animations via the DiviOps Design Library plugin. Do NOT use for custom PHP/plugins, child theme development, standalone CSS authoring, SQL queries, WooCommerce configuration, SEO setup, standalone Three.js projects without WordPress, or non-Divi builders like Elementor.
compatibility: Requires diviops-mcp MCP server connected to a WordPress site with Divi 5 and the diviops-agent plugin active.
metadata:
  author: oaris-dev
  version: "1.0"
  divi-version: "5.2.0"
---

# Divi 5 Builder Skill

Build modern, VB-editable Divi 5 pages programmatically via MCP tools.

## Reference Files

Read the right file for the task at hand — don't load everything.

| Task | Read first |
|------|-----------|
| Using MCP tools & targeting | [tools.md](references/tools.md) |
| Creating/editing pages | [design-guide.md](references/design-guide.md) → [module-formats.md](references/module-formats.md) |
| Copy-paste minimum-valid block snippets | [minimal-snippets.md](references/minimal-snippets.md) (Heading, Text, Button, Blurb, Icon, Image) |
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

### Verification convention

Skill docs label findings by evidence quality. **Runtime acceptance ≠ VB compatibility** — a path can render correctly via MCP write but get rewritten or rejected on VB save. When citing or extending these docs, preserve the existing tier:

| Marker | Meaning | When to use |
|---|---|---|
| **`*(VB-verified YYYY-MM-DD)*`** | User saved the shape in VB; observed in registry/markup as-written | Canonical. Safe to author from MCP and expect VB round-trip. |
| **`*(verified YYYY-MM-DD)*`** or **`*(empirically verified ...)*`** | Frontend renders correctly OR runtime accepts the shape, but VB round-trip not exercised | Use the path, but flag the limitation. Schema-canonical path may differ. Cross-check `*PresetAttrsMap.php` if possible. |
| **`<!-- UNVERIFIED -->`** | Neither VB-tested nor render-confirmed | Mark explicitly. Don't ship downstream tooling that depends on it. |

The two real failure modes this prevents (`feedback_schema_canonical_path`, `feedback_vb_first_verification`):
- A path that renders but is **not VB-editable** — downstream user opens VB, value silently re-resolves to default
- A path that runtime accepts but **VB rewrites on save** — the very next user save undoes our write

Upgrade tier by VB round-trip: have the user save the shape in VB, then dump the registry/block markup. If the shape is preserved exactly, stamp `(VB-verified DATE)`. If VB rewrote it, document the canonical shape (what VB wrote) and the runtime-permissive variant separately.

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

### How DiviOps handles styling

Every page you generate uses one of three tiers for any given style value:

| Tier | What it is | When to use |
|------|-----------|-------------|
| **Inline values** | Colors, sizes, fonts hardcoded in each block | Always works. Default. |
| **Token variables** | Divi global tokens referenced via `$variable({...payload...})$` (full JSON payload required, trailing `$` is load-bearing — see [presets.md](references/presets.md#variable-tokens)) | When global tokens exist in Divi |
| **Presets** | Module-level style templates referenced via UUID (`modulePreset: [uuid]` or `groupPreset.<slot>.presetId: [uuid]`) | When presets exist + a manifest maps roles to UUIDs |

**DiviOps generates working, design-complete pages at any tier.** Tokens and presets are consistency optimizations for projects that need them — they are NOT prerequisites.

### First time on a project? Start here

**You don't need to set anything up.** Page generation works out of the box with inline values using patterns from [design-guide.md](references/design-guide.md). Skip the rest of this section unless you want design-system reuse across many pages.

Come back here when:
- You want colors/sizes shared across many pages without duplicating values → bootstrap tokens
- You want a reusable design system with our `oa` token + preset naming → run the full bootstrap workflow

### Runtime resolution cascade (how pages are actually styled)

Every page generation follows this cascade — you'll land at whichever tier your project has set up:

1. Read `.claude/design-system.json` → look up `presets.<role-key>.id` (fast path, if the manifest exists)
2. If manifest missing → `diviops_preset_audit`, match presets by name, build an in-memory map (if `oa`-prefixed presets exist)
3. If no `oa` presets found → inline styling from [design-guide.md](references/design-guide.md) patterns

Most first-time runs hit step 3 and that's fine — output is still polished, animated, responsive, VB-editable.

### About the `oa` convention (optional)

The `oa` design system uses universal token names (`gcid-oa-primary-500`, `gvid-oa-size-h1`) and preset names (`oa Heading H1`, `oa Button Primary`) across all projects. What differs per site is the **values** behind tokens and the **UUIDs** Divi assigns to presets.

If you prefer different names or no prefix, skip bootstrap entirely — inline styling doesn't need `oa` or any other convention.

### Manifest: `.claude/design-system.json`

When bootstrap is complete, this per-project file maps preset **role keys** (e.g. `heading-h1`, `button-primary`) to site-specific UUIDs. NOT shipped with the skill — lives in the project's `.claude/` directory. Generated by step 4 of the bootstrap workflow below.

Role key convention: lowercase preset name, drop `oa ` prefix, spaces to hyphens (e.g. `oa Heading H1` → `heading-h1`).

### Project state reference

Use this to figure out which cascade path you're on without reading code:

| State | oa tokens? | oa presets? | Manifest? | DiviOps behavior |
|-------|-----------|-------------|-----------|------------------|
| **Fresh site** (default, most common) | No | No | No | Inline values via design-guide.md — no action needed |
| Branded, not normalized | No (has project-local colors) | No | No | Inline values; full bootstrap suggested if you want tokens |
| Partially bootstrapped | Some | No | No | Use available tokens inline; complete bootstrap when ready |
| Tokens complete, presets pending | Yes | No | No | Tokens via `$variable()$`; inline font/button styling |
| Fully bootstrapped | Yes | Yes | Yes | Full preset-driven generation via manifest |
| Bootstrapped, stale manifest | Yes | Yes | Outdated | Re-run Step 1 audit + Step 4 manifest regeneration |

### Bootstrap workflow (optional, power-user)

Run this only when you want the full token + preset setup for a site. **Not required for page generation** — inline styling works without any of it.

This is a real commitment: ~72 API calls for tokens alone, plus ~24 `diviops_preset_create` calls to seed the oa preset catalog. Bootstrap once you're sure you want design-system consistency across many pages on this site.

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
Use `diviops_preset_create` to write each preset to the D5 registry programmatically. The `attrs` bag shape differs by `type`:

- `type: "module"` (default) — `attrs` is the **full module top-level attrs tree**, same shape as block-markup `attrs` (e.g. `{module: {decoration: {...}}, content: {...}}`). Supply `module_name` (e.g. `divi/button`, `divi/column`, `divi/heading`).
- `type: "group"` — `attrs` is the **fragment for the attribute group only**, not the full module tree. For a font preset, that's the font-relevant subtree (e.g. `{title: {decoration: {font: {...}}}}` or similar — exact path depends on the group). Supply `module_name` plus `group_name` (the VB component, e.g. `divi/font`, `divi/font-body`, `divi/button`) and `group_id` (the VB panel section, e.g. `designTitleText`, `designText`, `designButton`). When unsure of the canonical shape for a given `group_name`, inspect an existing group preset via `diviops_preset_audit` and copy its `attrs` structure.

1. Walk the oa preset catalog in [presets.md](references/presets.md) and issue one `diviops_preset_create` call per entry (~24 calls). Capture each returned UUID.
2. (Alternative) If you prefer a manual VB flow, create presets in the Visual Builder and then run `diviops_preset_audit` to discover UUIDs.

**Step 4 — Generate manifest:**
1. Match preset names to role keys (e.g. "oa Heading H1" → `heading-h1`)
2. Confirm token counts from `diviops_list_variables`
3. Write `.claude/design-system.json` (see [presets.md](references/presets.md) for schema)

**Step 5 — Generate project docs (optional):**
Write `.claude/instructions/design-system.md` with brand-specific guidance: aesthetic direction, color personality, design conventions.

## Critical Block Format Rules

1. **Always wrap in `divi/placeholder`**: `<!-- wp:divi/placeholder -->...<!-- /wp:divi/placeholder -->`
2. **Always include `builderVersion`**: `"builderVersion":"5.1.1"` on every block
3. **Self-closing blocks**: Use `<!-- wp:divi/text {...} /-->` (with `/-->`) for leaf modules
4. **HTML in innerContent**: Use unicode escapes: `\u003cp\u003e` not `<p>`
5. **Layout display on containers**: Section, Row, Column, Group need `"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}}` — content modules (Text, Button, Icon) don't require it
6. **Admin labels on important modules**: `"meta":{"adminLabel":{"desktop":{"value":"My Label"}}}` — required for granular editing
7. **`$variable()$` trailing `$` is load-bearing**: tokens must end with `)$`, not just `)`. Writing `$variable({...})` (no trailing `$`) silently fails to resolve at render time. Full payload format + examples: [presets.md → Variable Tokens](references/presets.md#variable-tokens).
8. **Module attrs must not contain `var(--<custom-alias>)`**: attr values hold literal CSS or canonical `$variable({...})$` tokens. Hand-authored `var()` refs to non-Divi aliases depend on external CSS that may not exist — if the alias is undeclared, CSS spec falls through to the property's initial value (0 for padding, browser default for color) and the page silently breaks. Full rule + tolerated patterns: [module-formats.md → Design Token References in Attrs](references/module-formats.md#design-token-references-in-attrs-canonical-variable-only).

## Module Gotchas (Silent Failures)

Full attribute paths in [module-formats.md](references/module-formats.md) Tier 3 (Pro). **Copy-paste minimum-valid snippets** for each content module: [minimal-snippets.md](references/minimal-snippets.md). Run [`diviops_validate_blocks`](references/tools.md) to catch the top 8 silent-failure patterns before write — each one below maps to a validator rule.

**Content-shape traps** (block renders but with wrong/missing content):

- **Heading**: explicit `headingLevel` required — omit and Divi renders `<h2>` regardless of semantic intent. Lives at `title.decoration.font.font.desktop.value.headingLevel` (double-`font` nesting, Font Family B). Allowed `"h1"`–`"h6"`. Validator: `heading_missing_level` (warn).
- **Button content**: lives on `button.innerContent.desktop.value`, NOT `content.innerContent.*`. Must be an **object** `{text, linkUrl}`, not a plain string. Either wrong bucket or string shape → button renders as default "Click Me" with empty href. Validators: `button_content_wrong_bucket` (error), `button_innercontent_string` (error).
- **Button styling lives at sibling-level paths**: `button.decoration.{font, background, border, boxShadow}` for visual styling, plus `module.decoration.spacing` for padding. **No `enable` flag is required** — VB-verified (Divi 5.4.0, page 1332): inline-styled buttons render correctly with no `button.decoration.button.desktop.value.enable` key. Render-relevant keys at `button.decoration.button.desktop.value.*` are limited to: `enable` (migration trigger — `"off"` causes Divi to strip `button.decoration`; never write without intent), `icon.*` (visible icon config — set `icon.enable: "off"` to suppress the default hover arrow), `padding` (icon-spacing gate, not visible-padding emitter — set layout padding at `module.decoration.spacing` instead), and `alignment` (deprecated input forwarded to `decoration.sizing.alignment`). Anything outside this set (e.g. `backgroundColor`, `textColor`, `font`) has no consumer here — parses, validates, saves, then no-ops at render. Validators: `button_no_render_consumer` (warn — flags unrecognized keys at this depth), `button_missing_icon_enable` (warn — fires when sibling-level styling exists without explicit icon suppression).
- **Blurb title**: `title.innerContent.desktop.value` is an **object** `{text}`, NOT a plain string. Plain string → title silently absent from rendered HTML. Validator: `blurb_title_string` (error).
- **Blurb icon**: when `imageIcon.innerContent.desktop.value.icon` is set, `useIcon: "on"` is required — without it the `.et-pb-icon` span renders empty. Validator: `blurb_icon_missing_use_icon` (error).
- **Body font path**: `content.decoration.bodyFont.body.font.*` (Font Family A, triple-nested). Writing `bodyFont.bodyFont.*` is a silent failure — no renderer consumer, values fall through to defaults (often black text on a dark background → invisible). Validator: `body_font_double_nested` (error).
- **flexType only on columns**: `module.decoration.layout.desktop.value.flexType` is a column-layout 24-unit grid attribute — only `divi/column`/`divi/column-inner` inside `divi/row` consume it. On blurbs, groups, text, etc. it's silently dropped, leaving the block with no width constraint. For flex children inside a group use `module.decoration.sizing.desktop.value.width` with per-breakpoint values. Validator: `flextype_on_non_column` (warn).
- **ContactField `fieldItem` label must be a string**: `fieldItem.innerContent.desktop.value` is a **plain string** (the label text, e.g. `"Your Name"`). Writing it as an object (bundling `fieldId`/`fieldType`/`required`/etc. under one value) is NOT a silent failure — it throws `UnexpectedValueException` in Divi's `MultiViewUtils::populate_data_content` and **crashes the entire post render** (white-screen critical error). Field config lives individually at `fieldItem.advanced.{id, type, required, allowedSymbols, minLength, maxLength, radioOptions, checkboxOptions, selectOptions}.desktop.value`. See [module-formats.md → Contact Field](references/module-formats.md#contact-field) for the full attr split. Validator: `field_item_content_object` (error).

**Attribute-path traps** (module renders but styling lands on the wrong element):

- **Button styling paths**: border/bg/font on `button.decoration` (NOT `module.decoration`). Sizing + alignment on `button.decoration.sizing` (5.1.1+, alignment inside sizing object). Padding/margin stay on `module.decoration.spacing`.
- **Icon**: border/bg on `module.decoration` only — `icon.decoration.border/background` creates a non-VB-editable inner ring.
- **Image**: spacing/sizing on `module.advanced` (NOT `module.decoration`) — exception among content modules.
- **Blurb icon size**: `imageIcon.decoration.sizing.desktop.value.iconFontSize` (5.1.1+, was `imageIcon.advanced.width`). Full decoration now on `imageIcon.decoration.{background,border,animation,...}`.
- **Group**: gap is `columnGap` + `rowGap` (NOT single `gap`); column sizing via `flexType` 24-unit grid ONLY on column modules (see flexType trap above).
- **Contact Form**: all fonts use double `font.font` nesting; field labels use `fieldItem.innerContent` (NOT `title.innerContent`).
- **CSS classes**: via `module.decoration.attributes.desktop.value.attributes[]` array.
- **freeForm CSS**: top-level `css.desktop.value.freeForm` — sibling of `module`, NOT inside it.

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
