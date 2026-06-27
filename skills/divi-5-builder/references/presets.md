# Divi 5 Preset System

## Table of Contents

- [Architecture](#architecture) — layers, variable tokens, preset types, storage format
- [How Presets Are Referenced](#how-presets-are-referenced-in-block-markup) — attribute-level, stacking, Composable Settings, module-level, cascade
- [oa Design System — Tokens](#oa-design-system--design-tokens) — naming, colors (35), font sizes (15), line heights (3), spacings (13), radii (6)
- [oa Design System — Presets](#oa-design-system--attribute-level-presets) — headings, body, color overrides, buttons, module-level
- [MCP Generation Examples](#mcp-generation-examples) — preset in blocks, color opacity
- [MCP Endpoints](#mcp-endpoints-for-presets) — audit, cleanup, update, delete
- [When to Use Presets vs Inline](#when-to-use-presets-vs-inline-styles)
- [Manifest Schema](#design-system-manifest-schema) — `.claude/design-system.json` structure

## Architecture

Divi 5 has two preset levels that work together with design token variables:

```
Layer 1: Variables ($variable()$ tokens)     ← atomic values (colors, sizes, spacing)
    ↓ referenced by
Layer 2: Attribute-Level Presets             ← shared style fragments (fonts, buttons)
    ↓ composed into / referenced by
Layer 3: Module-Level Presets               ← full component styles
    ↓ referenced in
Block Markup (groupPreset / modulePreset)   ← page generation
```

### Variable Tokens

Variables are stored in the Divi Variable Manager. 7 native types (Divi 5.7.4 added `gradients`):

| Type | ID prefix | Storage | Example |
|------|-----------|---------|---------|
| `colors` | `gcid-` | `et_divi.et_global_data.global_colors` | `gcid-oa-primary-500` → `#3a7a6a` |
| `numbers` | `gvid-` | `et_divi_global_variables.numbers` | `gvid-oa-size-h1` → `clamp(30px, 8vw, 100px)` |
| `fonts` | `--et_global_` | `et_divi_global_variables.fonts` | `--et_global_heading_font` → `Open Sans` |
| `strings` | `gvid-` | `et_divi_global_variables.strings` | Arbitrary text |
| `images` | `gvid-` | `et_divi_global_variables.images` | Base64 or URL |
| `links` | `gvid-` | `et_divi_global_variables.links` | URL values |
| `gradients` *(5.7.4)* | `gvid-` | `et_divi_global_variables.gradients` | structured `$variable({type:gradient,…settings…})$` token — see caveat below |

> **Gradient-variable value shape (5.7.4, VB-verified 2026-06-15):** a renderable `gradients` entry's stored `value` is itself a `$variable({"type":"gradient","value":{"name":"gradient","settings":{"enabled":"on","stops":[{position,color},…],"type":"linear|circular|elliptical|conic","direction","directionRadial","length","overlaysImage"}}})$` token — **not** a raw CSS `linear-gradient(…)` string. Divi defines the `--gvid-…` custom property only for the structured shape; a CSS-string value is referenced (`var(--gvid-…)`) but never defined, so any bound module renders nothing. Create gradient variables either in the VB Variable Manager **or** via `diviops_variable_create({type:"gradients", gradient:{stops:[…], type, direction, …}})` (diviops-agent ≥ 1.5.4 / server ≥ 1.5.28) — the server serializes this exact token. A raw CSS-string `value` is rejected.

**Token format in block attrs** — note the `$` on BOTH ends:
```json
"$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-oa-size-h1\",\"settings\":{}}})$"
```
- Colors use `"type":"color"`, gradient variables use `"type":"gradient"`, everything else uses `"type":"content"`
- **The trailing `$` is required** — without it, the token silently fails to resolve
- Resolved at render time: colors → HSL transform, numbers/fonts → `var(--name)`

**Storage byte forms (Divi 5.3.x).** Inside block-attrs JSON the inner quotes of `$variable({...})$` payloads can be stored as `\"` (2-byte VB-canonical) or `"` (6-byte JSON unicode escape). Both decode identically through Divi's outer block-attrs parse. Three pathological forms can leak in via callers and silently break renders or block parsing:
- `\\u0022` (7 bytes) — extra backslash; produced when a caller passes the 6-byte form through an extra JSON encoding layer (over-escape, render-only failure on certain attr paths like `module.decoration.background.color`, `spacing.margin`, `border.styles.color`, `layout.columnGap` — leaks literal `0022` into CSS)
- `\u005cu0022` (11 bytes) — backslash itself unicode-encoded (over-escape, same render-only failure mode); the form observed in the wild on Divi 5.3.x mass-corruption events
- bare `"` (1 byte) — under-escape; produced when an agent transcribes `section_get` markup (which emits inner quotes as `&quot;` HTML entities) and a layer in the agent → MCP → WP pipeline strips one level of escaping. The bare quote prematurely terminates the OUTER block-attrs string at parse time — the WP block parser silently drops ALL attrs from the affected module. Section appears to save (`success: true`) but renders empty / broken.

**MCP write tools normalize all three pathological forms inside `$variable(...)$` token regions** (`diviops-server/src/wp-client.ts`) — a `diviops_section_get → diviops_section_replace` round-trip on legacy broken bytes self-heals. Bytes outside token regions are preserved (so a string-variable value or a code sample documenting the broken form round-trips unchanged). The normalizer runs in two passes: first collapses over-escaped forms to canonical `\"`, then escapes any remaining bare `"` (a negative lookbehind skips already-canonical `\"` so the second pass is idempotent). For non-MCP-touched content (e.g., direct DB writes or custom PHP that bypasses the MCP), recover with a two-target `str_replace` that handles both pathological over-escape forms:

```php
$new = str_replace(
  ['\u005cu0022', '\\\\u0022'],   // 11-byte and 7-byte broken forms
  '\"',                          // canonical 2-byte
  $post->post_content
);
$wpdb->update($wpdb->posts,
  ['post_content' => $new],
  ['ID' => $id], ['%s'], ['%d']);
clean_post_cache($id);
```

**`wp_update_post` hazard (custom PHP / eval-files / non-MCP REST endpoints).** WP's `wp_insert_post` / `wp_update_post` runs `wp_unslash()` on `post_content`, stripping one level of backslashes from ALL `\uXXXX` escapes — not just variable-token quotes: `"` (variable expression `"`), `<` / `>` (HTML `<` / `>` in `content.innerContent`), `&` (HTML `&`), `/` (`/`). All become `u0022` / `u003c` / etc. (no leading backslash) — variable expressions silently fail to resolve and HTML tags render as literal text (e.g. `<p>` becomes the visible string `u003cpu003e`). Damage is irreversible without a pre-`wp_update_post` revision. **When writing variable-token-bearing markup from PHP, bypass `wp_update_post` — write directly via `$wpdb->update($wpdb->posts, ['post_content' => $new], ['ID' => $id], ['%s'], ['%d'])` followed by `clean_post_cache($id)`.** The diviops-agent plugin uses `wp_slash()` to compensate at REST endpoints; that path is safe. Recovery regex for stripped-backslash damage (run on storage): `preg_replace('/(?<!\\\\)u0022/', '\\u0022', $content)` — the negative lookbehind avoids double-prefixing already-correct tokens.

**VB round-trip pitfall (Divi 5.3.x).** When a user opens a section in the Visual Builder and saves, Divi may double-escape `"` inner quotes inside `$variable(...)$` payloads on certain attr paths (heading/text background, border, spacing) — `"` becomes `"` (over-escaped) in storage. Resolved variable expression then reads `$variable({"type":...})$` which fails Divi's inner JSON parse and renders empty / leaks literal `0022` into CSS. Symptom: variable token loses its background/border/margin and falls through to defaults after a VB save. Recovery: rewrite the section via `diviops_section_replace` (the MCP normalizer fixes it on write), or hand-fix the attrs option in VB. No reliable workaround at user-edit time — the VB save itself is the corrupting layer.

### Preset Types

**Module-level presets** (`type: "module"`) — stored under `module.*` in `et_divi_builder_global_presets_d5`. Apply to a specific module type. Referenced in block markup via `modulePreset`.

**Attribute-level presets** (`type: "group"`) — stored under `group.*`. Apply to a specific attribute group (font, button, border, etc.). **Shareable across module types.** Referenced in block markup via `groupPreset`.

### Storage Format

```
et_divi_builder_global_presets_d5 (option)
├── module
│   └── {moduleName}
│       ├── default: "preset-id"
│       └── items
│           └── {preset-id}
│               ├── type: "module"
│               ├── attrs / styleAttrs / renderAttrs
│               └── groupPresets: { ... }     ← references to attribute-level presets
└── group
    └── {groupName}                           ← e.g. "divi/font", "divi/font-body", "divi/spacing"
        ├── default: "preset-id"
        └── items
            └── {preset-id}
                ├── type: "group"
                ├── groupName: "divi/font"
                ├── groupId: "designTitleText"
                ├── primaryAttrName: "title"
                ├── attrs / styleAttrs / renderAttrs
```

**Attribute-level preset fields:**
- `groupName` — the VB component: `divi/font`, `divi/font-body`, `divi/button`, `divi/spacing`, etc.
- `groupId` — the slot identity. Pre-5.4.0 was always a short identifier (`designTitleText`, `designText`, `button`); from 5.4.0 onward, **Composable Settings group presets use the dotted attribute path** as `groupId` (e.g. `title.decoration.spacing`, `module.decoration.spacing`, `content.decoration.spacing`)
- `moduleName` — the module the preset was created on (informational, not a restriction)
- `attrs` — present whenever the preset has any styling
- `styleAttrs` — present when `attrs` has CSS-emitting fields. Strips structural-only fields (e.g. `headingLevel`)
- `renderAttrs` — present only when the preset carries attrs that need a per-instance render merge (Pass B). Most VB-saved presets do NOT have this key. Pure chain-ref-only group presets have neither `styleAttrs` nor `renderAttrs` — just a single `attrs` bucket containing `groupPreset.<slot>.presetId` chain refs

**Three observed VB-saved bucket shapes** *(VB-verified 2026-05-04)*:
- **`{attrs}` only** — pure chain-ref group presets with no own styling
- **`{attrs, styleAttrs}`** — most spacing/typography presets observed in this project
- **`{attrs, styleAttrs, renderAttrs}`** — observed on Text presets that carry `module.advanced.text.text.orientation` (one confirmed Pass-B-feeding case)

> The full trigger matrix for renderAttrs population is **not yet characterized**. We've confirmed `module.advanced.text.text.orientation` triggers it; other attrs likely do too (transform, sticky, dimensional overrides per the Pass B documentation below) but those have not been VB-observed in this project. Treat "missing renderAttrs" as the default and presence as the special case until the matrix is fully mapped.

MCP `diviops_preset_create` writes all three buckets uniformly (full-mirror — see "Full-mirror mitigation" below). This is intentional for Pass-B-consumer consistency, but the bucket-shape divergence from VB is a real difference. Diff/audit tools comparing VB-authored vs MCP-authored presets must not treat missing `renderAttrs` as drift — it's a structural choice on VB's side.

### CSS Emission (Dual-Pass)

Divi renders a preset-affected module's CSS via **two parallel passes** on every render:

| Pass | Selector | Source | Specificity |
|------|----------|--------|-------------|
| **A** | `.preset--module--{module}--{uuid}` | `preset.attrs` | low (single class) |
| **B** | `body #page-container .et_pb_section .et_pb_{module}_N` | `preset.renderAttrs` (merged into instance attrs) | high (parent chain) |

When both passes emit the same declaration, **Pass B wins** due to higher specificity.

#### When does Pass B emit?

Pass B emission is **conditional**, not universal. The full trigger matrix (module type × property type × value type × responsive keys) is not yet characterized — treat it as "verify, don't assume."

**Confirmed emission cases:**
- `divi/row` with responsive dimensional overrides in `renderAttrs` (e.g. phone-breakpoint gutter `calc()`) — concrete case from the stale-`renderAttrs` bug that motivated the full-mirror fix.
- `divi/button` with literal dimensional values (width, height) — reference case from upstream Divi investigation.

**Confirmed non-emission cases:**
- Module presets with CSS-variable values (`var(--space-*)`, `$variable()$` tokens) on layout / flex / padding properties. Observed across 6 adopted presets in a live project: Pass A alone carried, no Pass B rule rendered, no specificity conflict.

#### Full-mirror mitigation (already shipped)

`diviops_preset_create` and `diviops_preset_update` write `attrs = styleAttrs = renderAttrs` on every call. This keeps the two passes in lockstep: the "values disagree" branch that causes stale-Pass-B bugs is eliminated regardless of whether Pass B actually emits for a given combination. **Tools authored against the MCP surface don't need to predict Pass B emission to be correct.**

> **VB-divergence note** — VB writes a 1/2/3-bucket shape based on whether the preset carries Pass-B-emitting attrs (see "Three observed VB-saved bucket shapes" above). MCP's full-mirror is intentionally more conservative for Pass-B-consumer correctness, but means MCP-authored and VB-authored presets are not byte-equivalent on the registry side. Whether VB round-trips an MCP-authored preset cleanly (saves it back without rewriting the bucket shape) is **not yet verified** — see the "MCP-authoring of new shapes" caveat in the MCP Endpoints section.

The scenario where prediction still matters: **external** consumers relying on Pass B for CSS-specificity guarantees (e.g. "this preset should beat a theme stylesheet rule").

#### Guidance for downstream authors

- If you need Pass B specificity to beat a competing rule, **verify emission empirically** before shipping. Save the preset, render a real page that references it, then inspect the generated Divi stylesheet — either the on-disk static-cache file at `wp-content/et-cache/{post_id}/et-*.css` or the linked CSS payload via browser DevTools — and grep for a rule scoped to `.et_pb_{module}_N`. Note: `diviops_render_preview` only returns the rendered HTML; it does not exercise the static-cache CSS pipeline where Pass B rules are emitted, so it can't confirm emission.
- Don't generalize from the `divi/button` reference case — other module types emit Pass B unpredictably.
- Report new emission / non-emission cases upstream so the trigger matrix can grow over time.

## How Presets Are Referenced in Block Markup

### Attribute-level presets (VB-verified)

Top-level `groupPreset` key (singular, not plural):

```json
{
  "title": {"innerContent": {"desktop": {"value": "My Heading"}}},
  "builderVersion": "5.1.1",
  "groupPreset": {
    "designTitleText": {
      "presetId": ["<heading-h1>"],
      "groupName": "divi/font"
    }
  }
}
```

**Known groupId → groupName mappings (VB-verified):**

| groupId | groupName | Used for |
|---------|-----------|----------|
| `designTitleText` | `divi/font` | Heading/title font (Heading, Blurb, Accordion, etc.) |
| `designText` | `divi/font-body` | Body text font (Text, Blurb, etc.) |
| `button` | `divi/button` | Button styling (Button, CTA, etc.) |
| `module.decoration.spacing` | `divi/spacing` | Module-level spacing (margin/padding on the module wrapper) — Composable Settings, 5.4.0+ *(VB-verified 2026-05-04)* |
| `title.decoration.spacing` | `divi/spacing` | Inner-element spacing on Heading's title — Composable Settings, 5.4.0+ *(VB-verified 2026-05-04)* |
| `content.decoration.spacing` | `divi/spacing` | Inner-element spacing on Text's content — Composable Settings, 5.4.0+ *(VB-verified 2026-05-04)* |

**`divi/spacing` shares one bucket for both module-level and inner-element-level spacing** — distinguished by the dotted-path `groupId`. A `divi/spacing` preset created from the module-level Spacing panel (`module.decoration.spacing`) and one created from a Composable Settings inner-element panel (`title.decoration.spacing`) both land in the same `et_divi_builder_global_presets_d5.group["divi/spacing"].items` map; the user UI surfaces them in the same "Spacing" preset list. The attr nesting under `attrs` MUST mirror the `groupId` path — a `title.decoration.spacing` preset's `attrs` is `{title: {decoration: {spacing: {...}}}}`, NOT `{module: {decoration: {spacing: {...}}}}`.

> Other Composable Settings groups likely follow the same dotted-path pattern but only the three above are VB-verified to date. Treat any new dotted slot key as VB-canonical only after observing it in a VB-saved preset (`feedback_vb_first_verification`).

Multiple attribute presets can be combined on one module:
```json
"groupPreset": {
  "designTitleText": {"presetId": ["heading-preset-id"], "groupName": "divi/font"},
  "designText": {"presetId": ["body-preset-id"], "groupName": "divi/font-body"}
}
```

### Preset Stacking (VB-verified)

`presetId` is an **array** — multiple presets of the same group can be stacked. Later presets override earlier ones for overlapping attrs:

```json
"groupPreset": {
  "designTitleText": {"presetId": ["<heading-h2>", "<heading-light>"], "groupName": "divi/font"}
}
```
This stacks oa Heading H2 (size/weight/lineHeight) + oa Heading Light (color) — the color from the second preset overlays the first. Resolve `<role-key>` placeholders from `.claude/design-system.json`.

**Color modifier presets** are designed for stacking on dark backgrounds. Standard presets inherit page-context color (dark on light) — only stack the Light modifier when the section has a dark bg:

| Dark background heading | `"presetId": ["<size-preset>", "<heading-light>"]` |
|------------------------|-----------------------------------------------|
| Dark background body text | `"presetId": ["<text-preset>", "<text-light>"]` |

### Composable Settings — `dynamicOptionGroups` activation flag (5.4.0+) *(VB-verified 2026-05-04)*

Divi 5.4.0 introduced **Composable Settings** — per-element inner styling panels (Design Tab → "Heading Text" → Spacing on a Heading module, Design Tab → "Text" → Spacing on a Text module, etc.). To activate the inner-element panel for a given attribute group, the block must carry a `dynamicOptionGroups` top-level key (sibling of `module`, `title`, `content`, etc.) with a boolean leaf flag.

```json
{
  "module": {"decoration": {"spacing": {"desktop": {"value": {"margin": {"top": "70px", "bottom": "70px", "syncVertical": "on", "syncHorizontal": "off"}}}}}},
  "title": {
    "innerContent": {"desktop": {"value": "Your Title"}},
    "decoration": {"spacing": {"desktop": {"value": {"margin": {"top": "60px", "bottom": "60px", "syncVertical": "on", "syncHorizontal": "off"}}}}}
  },
  "builderVersion": "5.4.0",
  "dynamicOptionGroups": {
    "designTitleText": {"title": {"decoration": {"spacing": true}}}
  }
}
```

The `dynamicOptionGroups` tree mirrors the activated attr path: `designTitleText.title.decoration.spacing = true` activates the inner-element spacing panel under "Heading Text". Without this flag, VB does not surface the values in its UI even though they may render. **The flag travels with VB-saved presets** — preserved in both `attrs` and `styleAttrs`.

**`dynamicOptionGroups` is in the `preset_reassign` reserved-keys list** — strip-inline never removes it (`diviops-agent.php:strip_reserved_keys()`).

### Binding a Composable Settings preset to a block — dotted slot keys *(VB-verified 2026-05-04)*

When a `divi/spacing` (or any Composable Settings) group preset is bound to a block via VB, the block's `groupPreset` map uses the **dotted attribute path as the slot key** (matching the preset's `groupId`):

```json
"groupPreset": {
  "title.decoration.spacing": {
    "presetId": ["<spacing-preset-uuid>"],
    "groupName": "divi/spacing"
  }
}
```

Both the slot key (`title.decoration.spacing`) and the preset's `groupId` use the same dotted path — they must match for VB to resolve the binding. Multiple Composable Settings slots can coexist on one module:

```json
"groupPreset": {
  "designTitleText": {"presetId": ["<font-preset>"], "groupName": "divi/font"},
  "title.decoration.spacing": {"presetId": ["<spacing-preset>"], "groupName": "divi/spacing"},
  "module.decoration.spacing": {"presetId": ["<wrapper-spacing-preset>"], "groupName": "divi/spacing"}
}
```

### Cascade gotcha — inline-attr stripping behavior on preset apply *(partially VB-verified 2026-05-04, rule incomplete)*

The cascade order `inline > groupPreset > modulePreset > default` is the **resolution rule** when multiple sources have a value. Whether inline attrs are present at all after a VB preset-apply action is a **separate, behavior-dependent question** that we have NOT fully characterized.

**Confirmed observations on a Heading with `title.decoration.spacing.margin`:**

| Step | Inline attr before | Action | Inline attr after | Visible result |
|---|---|---|---|---|
| 1 | 60px (set in VB Spacing panel) | Apply MCP-authored preset (73px) via VB dropdown | **Still 60px** (NOT stripped) | 60px (inline wins) |
| 2 | 60px (still present from step 1) | Apply VB-authored preset (80px) via VB dropdown | **GONE** (stripped) | 80px (groupPreset Pass A wins) |

VB stripped the inline attr in step 2 but not step 1. **Why is unclear.** Possible factors:
- Step 1 added a NEW group-preset binding (slot was empty); step 2 REPLACED an existing binding
- The MCP-authored preset (step 1) had `renderAttrs` populated (full-mirror); the VB-authored preset (step 2) had no `renderAttrs` (VB-canonical 2-bucket shape)
- Some other UI state we haven't isolated

**Treat this as Divi 5.4.0 preset behavior that is not yet fully matured** — verify the inline-strip outcome empirically when the cascade resolution matters. Don't rely on "VB always strips inline" or "VB never strips inline" — both are false in different scenarios.

**Cascade resolution when only Pass A class CSS competes** *(VB-verified 2026-05-04)*. When inline attrs are absent and a VB-saved spacing preset (no `renderAttrs`) is applied:
- Pass B (instance class `.et_pb_heading_0`) emits NO rule for the inner-element margin
- Two Pass A rules compete with equal specificity (`0,2,2`):
  - `.preset--module--divi-heading--<uuid>` (from `modulePreset`)
  - `.preset--group--divi-heading--divi-spacing--<modhash>--<uuid>` (from `groupPreset`)
- **The `groupPreset` rule emits AFTER the `modulePreset` rule in the stylesheet**, so it wins by source-order on equal specificity
- Net effect: `groupPreset` value beats `modulePreset` value, matching the documented cascade order even though it's resolved via stylesheet ordering rather than CSS specificity

**Implication for diviops authoring**: when binding a preset programmatically via `module_update` to a previously-styled instance, **explicitly clear the matching inline attr** (set the path to `null` in the same call). Don't depend on VB's strip-on-apply behavior — it's inconsistent. `diviops_preset_reassign` with `strip_inline=true` is the safe path for batch consolidations: it strips inline only when the inline value deep-equals the new preset's value (preserving intentional divergence) AND the post-swap stack is singular.

### Inner-element vs module-level spacing emission *(VB-verified 2026-05-04)*

Composable Settings nested-spacing emits CSS on a **different DOM selector** than module-level spacing — the two are not redundant:

| Path | Selector (Heading example) | `!important`? |
|---|---|---|
| `module.decoration.spacing.margin` | `.et_pb_heading_0` (module wrapper) | yes |
| `title.decoration.spacing.margin` | `.et_pb_heading_0 .et_pb_heading_container h1...h6` (inner heading element) | no |

| Path | Selector (Text example) | `!important`? |
|---|---|---|
| `module.decoration.spacing.padding` | `.et_pb_text_0` (module wrapper) | yes |
| `content.decoration.spacing.padding` | `.et_pb_text_0 .et_pb_text_inner` (inner text container) | no |

Module-level uses `!important`; nested-element-level does NOT — downstream selectors can override the inner-element rule more easily than the wrapper rule. Keep this in mind when stacking spacing presets that target both layers.

### Known group-bucket inventory *(VB-verified 2026-05-04)*

The `et_divi_builder_global_presets_d5.group` map carries one bucket per `groupName`. VB-confirmed buckets (5.4.0):

| `groupName` | Slot identity (`groupId`) shape | Notes |
|---|---|---|
| `divi/font` | Short identifier (`designTitleText`) | Heading/title typography |
| `divi/font-body` | Short identifier (`designText`) | Body text typography |
| `divi/button` | Short identifier (`button`) | Button group preset |
| `divi/spacing` | Dotted attribute path (`module.decoration.spacing`, `title.decoration.spacing`, `content.decoration.spacing`) | Composable Settings spacing — module-level AND inner-element-level share this bucket |

Other group buckets (`divi/border`, etc.) likely exist but are not yet VB-verified in this project. Always observe a VB-saved preset before asserting a new bucket's existence (`feedback_vb_first_verification`).

### Module-level presets

Top-level `modulePreset` key:
```json
{"modulePreset": ["preset-uuid"], "builderVersion": "5.1.1"}
```
- `["default"]` or `["_initial"]` — use the module type's default preset
- `["uuid"]` — use a specific preset by ID
- Omit entirely to use the default preset

### Cascade order
Instance inline attrs > attribute-level preset (`groupPreset`) > module-level preset (`modulePreset`) > module type default preset > theme CSS defaults

## oa Design System — Design Tokens

> **Token names are canonical; values are reference.** The token names (`gcid-oa-primary-500`, `gvid-oa-size-h1`) and structure (3 color families, 15 font sizes, 13 spacings, 6 radii) are the canonical target every project should create during bootstrap. The hex/clamp values below are from a reference project — your project's actual values depend on its brand colors and are set during bootstrap Step 2. Inspect live values via `diviops_variable_list`.

### Naming convention
The reference catalog below uses explicit `oa`-prefixed IDs for collision avoidance. Existing Divi-created variables may instead have UUID-style IDs and only carry the `oa-*` meaning in `label`; `diviops_variable_list.prefix` filters IDs only, so audit semantic `oa` tokens by listing the relevant `type` and checking returned labels unless explicit `gcid-oa-*` / `gvid-oa-*` IDs are known to exist.

### Colors (35 variables)

3 color families × 11 shades (50-950) + white + black:

| Family | Base (500) | ID pattern |
|--------|-----------|------------|
| Primary (teal green) | `#3a7a6a` | `gcid-oa-primary-{shade}` |
| Secondary (gold) | `#d09b32` | `gcid-oa-secondary-{shade}` |
| Neutral (stone) | `#78716b` | `gcid-oa-neutral-{shade}` |

Shades: 50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950

Plus: `gcid-oa-white` (#ffffff), `gcid-oa-black` (#000000)

### Numbers — Font Sizes (15 variables)

| Token ID | Label | Value |
|----------|-------|-------|
| `gvid-oa-size-h1` | oa Heading H1 | `clamp(30px, 8vw, 100px)` |
| `gvid-oa-size-h1-small` | oa Heading H1 Small | `clamp(26px, 8vw, 80px)` |
| `gvid-oa-size-h2` | oa Heading H2 | `clamp(26px, 4vw, 40px)` |
| `gvid-oa-size-h2-small` | oa Heading H2 Small | `clamp(20px, 4vw, 30px)` |
| `gvid-oa-size-h3` | oa Heading H3 | `clamp(20px, 4vw, 30px)` |
| `gvid-oa-size-h3-small` | oa Heading H3 Small | `clamp(18px, 4vw, 24px)` |
| `gvid-oa-size-h4` | oa Heading H4 | `clamp(20px, 4vw, 24px)` |
| `gvid-oa-size-h4-small` | oa Heading H4 Small | `clamp(18px, 4vw, 20px)` |
| `gvid-oa-size-h5` | oa Heading H5 | `clamp(18px, 4vw, 20px)` |
| `gvid-oa-size-h5-small` | oa Heading H5 Small | `clamp(16px, 4vw, 18px)` |
| `gvid-oa-size-h6` | oa Heading H6 | `clamp(16px, 4vw, 18px)` |
| `gvid-oa-size-h6-small` | oa Heading H6 Small | `clamp(14px, 4vw, 16px)` |
| `gvid-oa-size-text` | oa Text Standard | `clamp(14px, 4vw, 16px)` |
| `gvid-oa-size-text-small` | oa Text Small | `clamp(12px, 4vw, 14px)` |
| `gvid-oa-size-text-big` | oa Text Big | `clamp(16px, 4vw, 20px)` |

### Numbers — Line Heights (3 variables)

| Token ID | Label | Value |
|----------|-------|-------|
| `gvid-oa-lh-tight` | oa Line Height Tight | `1.1em` |
| `gvid-oa-lh-normal` | oa Line Height Normal | `1.5em` |
| `gvid-oa-lh-relaxed` | oa Line Height Relaxed | `1.7em` |

### Numbers — Spacings (13 variables)

| Token ID | Label | Value |
|----------|-------|-------|
| `gvid-oa-space-1` | oa Space 1 | `0.25rem` |
| `gvid-oa-space-2` | oa Space 2 | `0.5rem` |
| `gvid-oa-space-3` | oa Space 3 | `0.75rem` |
| `gvid-oa-space-4` | oa Space 4 | `1rem` |
| `gvid-oa-space-5` | oa Space 5 | `1.25rem` |
| `gvid-oa-space-6` | oa Space 6 | `1.5rem` |
| `gvid-oa-space-7` | oa Space 7 | `1.75rem` |
| `gvid-oa-space-8` | oa Space 8 | `2rem` |
| `gvid-oa-space-9` | oa Space 9 | `2.25rem` |
| `gvid-oa-space-10` | oa Space 10 | `2.5rem` |
| `gvid-oa-space-11` | oa Space 11 | `2.75rem` |
| `gvid-oa-space-12` | oa Space 12 | `3rem` |
| `gvid-oa-space-16` | oa Space 16 | `4rem` |

### Numbers — Border Radii (6 variables)

| Token ID | Label | Value |
|----------|-------|-------|
| `gvid-oa-rounded` | oa Rounded | `0.25rem` |
| `gvid-oa-rounded-lg` | oa Rounded LG | `0.5rem` |
| `gvid-oa-rounded-xl` | oa Rounded XL | `0.75rem` |
| `gvid-oa-rounded-2xl` | oa Rounded 2XL | `1rem` |
| `gvid-oa-rounded-3xl` | oa Rounded 3XL | `1.5rem` |
| `gvid-oa-rounded-full` | oa Rounded Full | `999px` |

## oa Design System — Attribute-Level Presets

> **Canonical target model**: The preset names, role keys, and token references below define the **target state** every oa design system project should bootstrap toward. The preset UUIDs are site-specific — resolve via `.claude/design-system.json` or `diviops_preset_audit`. Projects that haven't bootstrapped yet won't have these presets; the agent falls back to inline styling.

### Heading Presets (`divi/font`, groupId: `designTitleText`)

| Preset | Role key | Weight | Size variable | Line Height variable |
|--------|----------|--------|--------------|---------------------|
| oa Heading H1 | `heading-h1` | 800 | `gvid-oa-size-h1` | `gvid-oa-lh-tight` |
| oa Heading H1 Small | `heading-h1-small` | 800 | `gvid-oa-size-h1-small` | `gvid-oa-lh-tight` |
| oa Heading H2 | `heading-h2` | 700 | `gvid-oa-size-h2` | `gvid-oa-lh-tight` |
| oa Heading H2 Small | `heading-h2-small` | 700 | `gvid-oa-size-h2-small` | `gvid-oa-lh-tight` |
| oa Heading H3 | `heading-h3` | 700 | `gvid-oa-size-h3` | `gvid-oa-lh-tight` |
| oa Heading H3 Small | `heading-h3-small` | 600 | `gvid-oa-size-h3-small` | `gvid-oa-lh-tight` |
| oa Heading H4 | `heading-h4` | 600 | `gvid-oa-size-h4` | `gvid-oa-lh-tight` |
| oa Heading H4 Small | `heading-h4-small` | 600 | `gvid-oa-size-h4-small` | `gvid-oa-lh-normal` |
| oa Heading H5 | `heading-h5` | 600 | `gvid-oa-size-h5` | `gvid-oa-lh-normal` |
| oa Heading H5 Small | `heading-h5-small` | 600 | `gvid-oa-size-h5-small` | `gvid-oa-lh-normal` |
| oa Heading H6 | `heading-h6` | 600 | `gvid-oa-size-h6` | `gvid-oa-lh-normal` |
| oa Heading H6 Small | `heading-h6-small` | 500 | `gvid-oa-size-h6-small` | `gvid-oa-lh-normal` |

### Body Text Presets (`divi/font-body`, groupId: `designText`)

| Preset | Role key | Size variable | Line Height variable | Notes |
|--------|----------|--------------|---------------------|-------|
| oa Text Standard | `text-standard` | `gvid-oa-size-text` | `gvid-oa-lh-relaxed` | Weight from global default |
| oa Text Small | `text-small` | `gvid-oa-size-text-small` | `gvid-oa-lh-normal` | Weight from global default |
| oa Text Big | `text-big` | `gvid-oa-size-text-big` | `gvid-oa-lh-relaxed` | Weight from global default |

### Color Override Presets (color only — compose with size presets)

| Preset | Role key | groupName | groupId | Color |
|--------|----------|-----------|---------|-------|
| oa Heading Light | `heading-light` | `divi/font` | `designTitleText` | `gcid-oa-white` |
| oa Text Light | `text-light` | `divi/font-body` | `designText` | `gcid-oa-white` (body + link) |

These are **color modifiers** — stack with a size preset when content is on a dark background. Standard presets intentionally omit color (inherits from page context), so most sections need no stacking. Only stack the Light preset for occasional dark sections.

### Button Presets (`divi/button`, groupId: `button`)

| Preset | Role key | Background | Text Color | Border / Radius |
|--------|----------|-----------|------------|-----------------|
| oa Button Primary | `button-primary` | `gcid-oa-primary-500` | `gcid-oa-white` | border: none, radius: `gvid-oa-rounded-xl` |
| oa Button Primary Outline | `button-primary-outline` | transparent | `gcid-oa-primary-500` | 1px `gcid-oa-primary-500`, radius: `gvid-oa-rounded-xl` |
| oa Button Secondary | `button-secondary` | `gcid-oa-secondary-500` | `gcid-oa-white` | border: none, radius: `gvid-oa-rounded-xl` |
| oa Button White | `button-white` | `gcid-oa-white` | `gcid-oa-neutral-900` | border: none, radius: `gvid-oa-rounded-xl` |

All button presets store visual styling at the **sibling-level** paths (`button.decoration.{font, background, border, boxShadow}`) — VB-verified Divi 5.4.0. **Padding lives on a scope-dependent path:** `module.decoration.spacing.padding` for inline buttons and `divi/button` module presets, `button.decoration.spacing.padding` for `divi/button` group presets (the `presetGroup` render path at `ButtonModule.php:633-644` merges the latter into module spacing via `array_replace_recursive`). No `enable: "on"` flag is required at `button.decoration.button.desktop.value` for custom styling to render. Render-relevant keys at that deep path are limited to `enable` (whose `"off"` value triggers a destructive *migration* — never write it without intent), `icon.*` (visible icon configuration), `padding` (icon-spacing gate, **not** a visible-padding emitter), and `alignment` (deprecated input forwarded to `decoration.sizing.alignment`). The `icon.enable: "off"` setting is what suppresses the default hover arrow on these presets.

### Hover-padding gate on Button group presets — broad scope, upstream-tracked

> **Scope correction (2026-05-04):** earlier framing called this a "narrow icon-off + chained-spacing-group" bug. **It is not narrow.** Empirically verified on Divi 5.4.0 via `file_put_contents` instrumentation of `spacing_icon_hover_style_declaration`: the gate misfires for **every `divi/button` group preset's Pass-A render** that doesn't carry `button.decoration.button.desktop.value.padding`, regardless of icon state, regardless of whether spacing is chained. Including Divi's auto-applied bucket-default group preset, which means **nearly every button on a fresh Divi 5 site** is affected unless workaround is applied. Tracked at the upstream-tracking issue (re-test on every Divi theme bump).

**The bug.** The gate at `Packages/Module/Options/Button/Style/StyleDeclarations.php:158-170` (`spacing_icon_hover_style_declaration`) emits `:hover{padding:0.3em 1em !important}` against the group-preset selector when `'off' === $enable && ! $has_desktop_padding`. For `presetGroup` Pass-A renders, `attrsFilter` at `ButtonModule.php:842` calls `ModuleUtils::remove_button_icon_attr_value` to strip the `icon` key (ET's stated reason: icon styles need spacing-group attrs unavailable at preset level). With `attrValue.icon` stripped, the gate falls through to `defaultAttrValue.icon.enable` — which in the Pass-A pipeline arrives as the literal string `"off"`, NOT from `_all_modules_default_render_attributes.php` (which has `"on"` at lines 436-448). Source of the synthesized `"off"` default is unidentified inside ET's render pipeline. The gate fires, the rule emits with selector `body #page-container .et_pb_section .preset--group--divi-button--divi-button--{token}--{presetId}:hover` (specificity `0,3,2`), and at hover it outranks the module preset's longhand padding rule (`0,2,0`) → padding collapses to ~`4.2px / 14px`.

**The bypass.** Add ONE single-corner value to the group preset's `button.decoration.button.desktop.value.padding`. Any single corner — value irrelevant; just flips `$has_desktop_padding` to `true` and the gate skips. Visible padding still comes from `module.decoration.spacing.padding` (module-preset path) or `button.decoration.spacing.padding` (group-preset path); the bypass corner has no rendering effect.

**The cleanest site-wide fix:** patch the bucket-default `divi/button` group preset (whatever Divi flagged `is_default: true` on this bucket — typically named "Button 1" with id around `xi0l0od6dn`) to carry the bypass corner once. One preset edit fixes every plain `modulePreset`-only button on the site without per-button bindings. Skip if the site uses custom `groupPreset.button` bindings on every button (then apply per-preset).

**Workable patterns** (any one avoids the gate, listed in order of preference):

0. **Site-wide bucket-default bypass** *(strongly preferred for production sites)* — patch the `divi/button` bucket-default group preset (the one with `is_default: true`) to carry a single corner: `button.decoration.button.desktop.value.padding.top: "0px"`. Eliminates the gate rule from per-post CSS for every plain `modulePreset`-only button site-wide. Single preset edit, zero per-button changes, zero rendering-side effects (the corner value never paints — it's gate-bypass only). Does not affect buttons that explicitly bind a custom `groupPreset.button` (those use their own preset, which separately needs the bypass — see pattern 2).

1. **Padding inline on the instance** — set `attrs.module.decoration.spacing.padding` on each button instance. The per-instance render pass merges it correctly. Empirically verified clean on Divi 5.3.3. Use when patterns 0 and 2 aren't viable (e.g. one-off override on a specific button).
2. **Padding inside the button group preset itself** (preferred for consolidation, **two-attr recipe**) — write padding on the `divi/button` group preset rather than chaining a separate `divi/spacing` preset. **Two attrs, two distinct roles** (VB-verified Divi 5.4.0):
    - **Visible-emit:** `button.decoration.spacing.desktop.value.padding` — the `spacing` styleProp pass at `ButtonModule.php:660-689` reads this in `presetGroup` mode (merged into module spacing via `array_replace_recursive` at lines 633-644) and emits the actual `padding: …` declarations against `_wrapper preset…, _wrapper preset…:hover`. This is what paints the button.
    - **Gate-bypass:** `button.decoration.button.desktop.value.padding` — any single corner makes `$has_desktop_padding` true at `StyleDeclarations.php:153-156` and skips the hover-gate's `0.3em 1em !important` fallback at line 160. Values here never emit visible CSS — they only flip the gate.

   **Both are required** when `icon.enable: "off"` and the visible padding lives in this preset. Writing only the deep-path attr produces a button with no visible padding (the recipe values are silently dropped — the spacing styleProp pass never sees them); writing only `button.decoration.spacing.padding` triggers the hover-gate and clobbers it on hover. Write the same per-corner values to both paths so the gate-bypass and the emitter agree:

```json
{
  "button": {
    "decoration": {
      "button": {
        "desktop": {
          "value": {
            "icon": {"onHover": "on", "enable": "off"},
            "padding": {
              "top": "$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-oa-space-2\",\"settings\":{}}})$",
              "bottom": "$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-oa-space-2\",\"settings\":{}}})$",
              "left": "$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-oa-space-4\",\"settings\":{}}})$",
              "right": "$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-oa-space-4\",\"settings\":{}}})$",
              "syncVertical": "on",
              "syncHorizontal": "on"
            }
          }
        }
      },
      "spacing": {
        "desktop": {
          "value": {
            "padding": {
              "top": "$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-oa-space-2\",\"settings\":{}}})$",
              "bottom": "$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-oa-space-2\",\"settings\":{}}})$",
              "left": "$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-oa-space-4\",\"settings\":{}}})$",
              "right": "$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-oa-space-4\",\"settings\":{}}})$",
              "syncVertical": "on",
              "syncHorizontal": "on"
            }
          }
        }
      }
    }
  }
}
```

   Pair with `diviops_meta_flush_cache` for any pages referencing the preset; the gate's `!important` rule (and the visible-emit rule) live in the per-post compiled CSS at `wp-content/et-cache/{post_id}/`, not in the preset JSON. (Note: Divi's VB does not currently draw a Spacing sub-panel under "Design → Button" for the deep-path attr even though the Composable Settings registry flags it, so the gate-bypass slot is reachable only via MCP. The visible-emit slot at `button.decoration.spacing.padding` is reachable from VB's standard Spacing panel under the button group.)

3. **Fold to a module preset** — when an entire button "look" is unique to one design context and won't be reused as a `groupPreset.button`, save it as a `divi/button` module preset (single inline-attrs bag, referenced via `modulePreset` not `groupPreset`). The module-preset render pass merges all scopes correctly and the gate never trips. Heavier than patterns 1 or 2; reach for it only when group-preset reuse isn't a goal.

**Where else to watch (unverified — audit candidates).** The narrow shape of this bug — a per-module declarator gates on cross-scope state, and the per-group CSS pass renders that group in isolation — could exist on other modules whose decoration tree has an enable/disable toggle. Verified so far: only the button-icon path. Suspected candidates pending audit:

- `divi/blurb` `imageIcon.advanced.useIcon` toggle paired with a sibling spacing group preset
- `divi/icon` border-on-icon toggle paired with a sibling border group preset
- `divi/image` border-radius via group preset paired with sizing chain (related but distinct from the verified rendering quirk: `divi/image` border-radius supplied by a preset alone — module preset OR Attribute-level preset — does not render unless reinforced inline; see the Image entry in [module-formats.md Exceptions Quick Reference](module-formats.md#exceptions-quick-reference))
- Per-module submit/CTA buttons with embedded icon controls (`post-nav`, `blog` read-more, `signup` submit)

When discovered, add to this list with the gate's source-file:line. Until verified, treat the analogous chain (toggle in one group, render-path-affected attr in a sibling group) as suspect.

### Module-Level Component Presets

| Preset | Role key | Module | Key styling |
|--------|----------|--------|-------------|
| oa Dark Section | `section-dark` | `divi/section` | bg: `gcid-oa-neutral-900`, padding: `gvid-oa-space-16` (tablet: `gvid-oa-space-12`) |
| oa Glass Card | `card-glass` | `divi/group` | bg: `gcid-oa-white` @ 5% opacity, border: 1px `gcid-oa-neutral-700`, radius: `gvid-oa-rounded-2xl`, padding: `gvid-oa-space-8` |
| oa Icon Badge | `icon-badge` | `divi/icon` | bg: `gcid-oa-primary-50`, radius: `gvid-oa-rounded-xl`, padding: `gvid-oa-space-3`, color: `gcid-oa-primary-500`, size: 32px |

Referenced via `modulePreset` (not `groupPreset`):
```json
{"modulePreset": ["<section-dark>"], "builderVersion": "5.1.1"}
```

### MCP Generation Examples

> Replace `<role-key>` placeholders with the actual UUID from `.claude/design-system.json` (e.g. `<heading-h1>` → look up `presets.heading-h1.id`).

**Heading with preset (zero inline font styling):**
```html
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Page Title"}}},"builderVersion":"5.1.1","groupPreset":{"designTitleText":{"presetId":["<heading-h1>"],"groupName":"divi/font"}}} /-->
```

**Text with preset:**
```html
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Body text here.</p>"}}},"builderVersion":"5.1.1","groupPreset":{"designText":{"presetId":["<text-standard>"],"groupName":"divi/font-body"}}} /-->
```

**Heading with stacked presets (size + color on dark bg):**
```html
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"White Heading"}}},"builderVersion":"5.1.1","groupPreset":{"designTitleText":{"presetId":["<heading-h2>","<heading-light>"],"groupName":"divi/font"}}} /-->
```
H2 size + Heading Light color — no inline font attrs needed at all.

**Button with preset (zero inline button styling):**
```html
<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Get Started"}}}},"builderVersion":"5.1.1","groupPreset":{"button":{"presetId":["<button-primary>"],"groupName":"divi/button"}}} /-->
```

> **Button innerContent format**: value must be `{"text": "..."}` object, NOT a plain string. A plain string renders as an invisible/empty button.

**Section with module preset:**
```html
<!-- wp:divi/section {"modulePreset":["<section-dark>"],"builderVersion":"5.1.1"} -->
```

### Color Variable Opacity

The `settings.opacity` field (0-100) on color variables controls alpha transparency:
```json
"$variable({\"type\":\"color\",\"value\":{\"name\":\"gcid-oa-white\",\"settings\":{\"opacity\":5}}})$"
```
This renders as `rgba(255,255,255,0.05)`. Used by the oa Glass Card preset for semi-transparent backgrounds.

### MCP Endpoints for Presets

- `GET /diviops/v1/preset/list` — Read all presets (D5 + legacy)
- `GET /diviops/v1/preset/audit` — Audit with referenced/unreferenced analysis
- `GET /diviops/v1/preset/scan-orphans` — List UUIDs referenced in pages but missing from the D5 registry; separates dangling orphans from D4-legacy candidates
- `POST /diviops/v1/preset/cleanup` — Remove orphans, rename, dedup (dry_run default)
- `POST /diviops/v1/preset/create` — Create a new preset (module or group). Supported module types include `divi/button`, `divi/heading`, `divi/text`, `divi/blurb`, `divi/section`, `divi/row`, `divi/column`, `divi/group`, and any other type Divi tracks in the D5 registry. The `attrs` shape differs by `type`: `module` uses the **full module top-level attrs tree** (e.g. `{module: {decoration: {...}}, content: {...}}`); `group` uses the **fragment for that attribute group only** (e.g. `{title: {decoration: {font: {...}}}}` for a font preset on a heading's designTitleText slot). Response payload returns the created UUID as `preset.id` (nested under a `preset` object)
- `POST /diviops/v1/preset/update` — Update single preset (name, attrs)

> **MCP-authoring of Composable Settings group presets** *(VB-verified 2026-05-04 except where noted)*:
> - ✅ **VB recognizes MCP-authored `divi/spacing` group presets with dotted `groupId`** — they appear in VB's preset dropdown for the matching slot
> - ✅ **VB binds them correctly** to a block via `groupPreset.<dotted-path>.presetId`
> - ✅ **Pass A preset-class CSS emits with the canonical selector format** (`.preset--group--divi-heading--divi-spacing--<modhash>--<uuid>`) matching VB-authored equivalents
> - ✅ **Applying the preset doesn't trigger a registry rewrite** — the MCP-written record (including full-mirror `renderAttrs`) survives the VB-side bind unchanged
> - ✅ **MCP-authored binding round-trips through a VB save unchanged** — verified on a fresh, no-inline Heading bound to `groupPreset["title.decoration.spacing"].presetId: ["yjh59i25br"]` via `section_replace`. Open in VB → save without edits → block markup re-emerges with identical keys/values (only JSON key order differs); rendered class and 80px CSS are preserved. **Implication: MCP can author Composable Settings preset bindings programmatically without depending on the user to "fix" them in VB**, provided the slot key is written in the canonical dotted-flat shape (see write-tool gotcha below).
> - ⚠️ **<!-- UNVERIFIED --> Whether VB rewrites the preset record on a direct preset edit-and-save** (preset manager → edit preset → save) is **not yet tested**. If VB strips MCP's full-mirror `renderAttrs` on resave, MCP-authored presets become byte-equivalent to VB-authored after the first user-driven preset edit. Test plan: open the preset in VB's preset manager, save without changes, dump and diff against pre-edit.
> - **Practical implication today**: MCP can author `divi/spacing` group presets and the user can apply them in VB without runtime issues. Just be aware that an inline attr on the block silently neutralizes the binding (see "Cascade gotcha" above) — clear the inline attr in the same write call when binding a preset programmatically.

> **`module_update` literal-dot key syntax for Composable Settings slots** *(verified 2026-05-04)*: by default `diviops_module_update` parses `attrs` keys as dot-notation paths. Composable Settings preset slots use **literal-dot keys** like `groupPreset["title.decoration.spacing"]` where the dots are part of the key, not path separators. Escape inner dots with `\.` to embed them literally:
>
> ```jsonc
> // ✅ CORRECT — backslash-escape the literal dots inside the slot key:
> {
>   "groupPreset.title\\.decoration\\.spacing.presetId":  ["yjh59i25br"],
>   "groupPreset.title\\.decoration\\.spacing.groupName": "divi/spacing"
> }
> // produces: "groupPreset": {"title.decoration.spacing": {"presetId": ["yjh59i25br"], "groupName": "divi/spacing"}}
>
> // ❌ WRONG — without the escape, dots split the path into nested objects:
> {"groupPreset.title.decoration.spacing.presetId": ["yjh59i25br"]}
> // produces: "groupPreset": {"title": {"decoration": {"spacing": {"presetId": [...]}}}} — silently ignored by Divi at render time
> ```
>
> The same pattern applies to all Composable Settings slots (`module\.decoration\.spacing`, `content\.decoration\.spacing`, etc.). Plain paths without literal dots — `modulePreset`, `dynamicOptionGroups.designTitleText.title.decoration.spacing`, `groupPreset.designTitleText.presetId` — work as always; the splitter only collapses `\.` when present, so the change is fully backward compatible.
- `POST /diviops/v1/preset/reassign` — Rewrite preset refs across pages from `old_uuid` → `new_uuid`. Covers both ref types: **module-level** (`attrs.modulePreset[...]`, stacked array) and **attribute-level** (`attrs.groupPreset.<slot>.presetId`). For group-bucket swaps, also rewrites **registry chain refs** (`attrs.groupPresets.<slot>.presetId` in other presets that pull in `old_uuid`). The `scope` param controls which ref types are walked:
  - `scope: "both"` (default) — auto-selects based on `new_uuid`'s bucket. Module and group identity are disjoint, so there's exactly one valid walk per swap
  - `scope: "module"` — walks `attrs.modulePreset` only. Rejects if `new_uuid` is a group preset
  - `scope: "group"` — walks `attrs.groupPreset.<slot>.presetId` + registry chain refs. Rejects if `new_uuid` is a module preset
  - Cross-bucket swaps (module preset ↔ group preset) are always rejected — writing a group UUID into a `modulePreset` array (or vice versa) would break rendering
  - Dry-run by default. **`modulePreset` array-form (`["uuid"]`) and `groupPreset.<slot>.presetId` scalar+array forms are both rewritten; legacy single-string `attrs.modulePreset: "uuid"` (D4-migrated content) is not rewritten — normalize to array form first if needed.**
  - `strip_inline: true` (default) — module-scope only. Recursively walks `attrs` and removes only the **per-attribute leaf values** that deep-equal the new preset's value at the same path; unrelated branches (admin label, custom CSS classes, `meta.*`, `dynamicOptionGroups`, etc.) are preserved. Inline-strip only fires when the post-swap `modulePreset` stack is singular `[new_uuid]` — stacked presets keep inline so other presets in the stack can't silently override through the freshly-stripped fields. **Group-scope inline strip is not yet implemented**; when requested with `scope: "group"` the `summary.strip_advisory` field notes the skip and UUID swaps proceed unchanged
  - Response `summary` fields: `scope` (effective scope — `"module"` or `"group"` — resolved from the input `scope` + `new_uuid` bucket), `uuid_swaps` (total page-ref swaps), `module_swaps` / `group_swaps` (breakdown), `chain_swaps` (registry chain refs rewritten), `inline_stripped`, `details[]` (per-page breakdown with `module_swaps`/`group_swaps` subtotals + `modules[]` listing `ref_type`, `slot`, `action`), `chain_details[]` (per-registry-preset breakdown when chains were rewritten)
- `POST /diviops/v1/preset/delete` — Delete single preset

### Consolidation workflow

Typical flow for normalizing repeated styling into a reusable preset, **when modules already share an existing preset UUID** (orphaned or otherwise):

1. `diviops_preset_scan_orphans` — identify UUIDs referenced in pages but missing from the registry (dangling deletions or D4-legacy refs)
2. `diviops_preset_create` — create a fresh named preset with the desired shared attrs (e.g. `module_name: "divi/column"`, `name: "White Card Surface"`, `attrs: { module: { decoration: { background: ..., spacing: ..., border: ... } } }`) — the created UUID is returned as `preset.id` in the response; use that for the next step's `new_uuid` parameter
3. `diviops_preset_reassign` with `mode: "dry-run"` — preview which pages/modules would swap the orphan UUID → `new_uuid` and which inline attrs would be stripped
4. `diviops_preset_reassign` with `mode: "apply"` — actually rewrite the pages; content goes through `parse_blocks` + `serialize_blocks` (no regex surgery) so only `modulePreset` arrays and redundant inline attrs are touched

**Purely-inline modules (no existing UUID)** have no automated batch migration path today. `diviops_preset_reassign` keys off `old_uuid`, so modules with no `modulePreset` entry can't be batch-attached to a freshly-created preset. The manual workflow:

1. `diviops_page_get` (or `diviops_page_get_layout`) — find the inline modules to consolidate; pick one as the seed
2. `diviops_preset_create` — create the preset using the seed module's inline attrs → the new preset UUID comes back as `preset.id` in the response
3. For each previously-inline module, call `diviops_module_update` with `attrs: { modulePreset: ["<preset.id>"] }` to attach the preset reference. Inline attrs that duplicate the preset's values can be cleared in the same call by setting them to `null`, or left in place (inline wins over preset, so duplicates are harmless but redundant)

A future tool (`preset_attach_inline` or similar) could automate step 3 by attribute-shape matching across pages — not implemented today.

**Attribute-level (`groupPreset`) consolidation** across pages is now supported directly by `diviops_preset_reassign` — pass a group-bucket `new_uuid` (with scope=`"both"` default) and the tool walks `attrs.groupPreset.<slot>.presetId` in page content plus `attrs.groupPresets.<slot>.presetId` registry chain refs in one call. Inline-strip for group scope is not yet implemented; UUID swaps happen, inline attrs are untouched (the summary includes a `strip_advisory` note when this applies). Manual follow-up with `diviops_module_update` is still required if you want to clear redundant inline attrs on the matching slot's decoration subtree.

### When to Use Presets vs Inline Styles

**If you're starting a new project, presets are optional.** DiviOps generates polished pages using hardcoded values from [design-guide.md](design-guide.md) patterns without any preset setup. See [SKILL.md "First time on a project?"](../SKILL.md#first-time-on-a-project-start-here) for the shortest path.

The guidance below applies once you've bootstrapped the `oa` system on a site (via SKILL.md's bootstrap workflow):

**Use attribute-level presets (`groupPreset`) when:**
- Typography: heading sizes, body text sizes — always use `oa Heading H*` / `oa Text *` presets
- Button styling: use `oa Button *` presets via `groupPreset.button`
- Any style shared across 3+ modules

**Use inline `$variable()$` tokens when:**
- Colors on non-preset-covered attributes (backgrounds, borders)
- Spacing values
- Border radius

**Use hardcoded values when:**
- One-off values that don't fit the design system
- Content-specific styling (animation delays, specific positioning)
- You haven't bootstrapped the design system yet (this is the default for new sites — all patterns in design-guide.md work with hardcoded values)

### Inline tokens as fallback, not duplication

When a module consumes a preset, **trust the preset to supply the values it covers — don't duplicate preset values inline.**

Inline `$variable()$` tokens are appropriate only when:
- **The preset doesn't cover that property** (e.g. preset sets background but you need a per-instance border color)
- **The renderer requires inline reinforcement** (e.g. image border-radius — the preset value alone doesn't render; see the Image entry in [module-formats.md Exceptions Quick Reference](module-formats.md#exceptions-quick-reference))
- **One-off override** for a specific instance that intentionally diverges from the preset

Why this matters: duplicating a preset's value inline doesn't break rendering, but it **fragments the source of truth**. A future preset edit (e.g. swapping `gcid-oa-neutral-950` for a new background token across the design system) won't propagate to instances that have inline overrides — you have to hunt them down in page markup. Keep the preset as the single source for any property it covers; reach for inline tokens only on the three exceptions above.

## Design System Manifest Schema

Each project stores a `.claude/design-system.json` file (NOT in the skill directory, NOT shipped to dist) that maps role keys to site-specific preset UUIDs. Generated by the bootstrap workflow in SKILL.md.

```json
{
  "$schema": "oa-design-system/v1",
  "project": "<project-name>",
  "bootstrapped": "<ISO-8601 timestamp>",
  "brand": {
    "primary":   { "name": "<color name>", "base": "<hex>" },
    "secondary": { "name": "<color name>", "base": "<hex>" },
    "neutral":   { "name": "<color name>", "base": "<hex>" }
  },
  "presets": {
    "<role-key>": { "id": "<divi-uuid>", "name": "oa <Preset Name>" }
  },
  "tokens": {
    "status": "none | partial | complete",
    "prefix": "oa",
    "counts": { "colors": 0, "numbers": 0 }
  }
}
```

**Role key convention**: lowercase preset name, drop `oa ` prefix, spaces to hyphens.
Example: `oa Heading H1 Small` → `heading-h1-small`
