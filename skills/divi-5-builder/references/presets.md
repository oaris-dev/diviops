# Divi 5 Preset System

## Table of Contents

- [Architecture](#architecture) — layers, variable tokens, preset types, storage format
- [How Presets Are Referenced](#how-presets-are-referenced-in-block-markup) — attribute-level, stacking, module-level, cascade
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

Variables are stored in the Divi Variable Manager. 6 native types:

| Type | ID prefix | Storage | Example |
|------|-----------|---------|---------|
| `colors` | `gcid-` | `et_divi.et_global_data.global_colors` | `gcid-oa-primary-500` → `#3a7a6a` |
| `numbers` | `gvid-` | `et_divi_global_variables.numbers` | `gvid-oa-size-h1` → `clamp(30px, 8vw, 100px)` |
| `fonts` | `--et_global_` | `et_divi_global_variables.fonts` | `--et_global_heading_font` → `Open Sans` |
| `strings` | `gvid-` | `et_divi_global_variables.strings` | Arbitrary text |
| `images` | `gvid-` | `et_divi_global_variables.images` | Base64 or URL |
| `links` | `gvid-` | `et_divi_global_variables.links` | URL values |

**Token format in block attrs** — note the `$` on BOTH ends:
```json
"$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-oa-size-h1\",\"settings\":{}}})$"
```
- Colors use `"type":"color"`, everything else uses `"type":"content"`
- **The trailing `$` is required** — without it, the token silently fails to resolve
- Resolved at render time: colors → HSL transform, numbers/fonts → `var(--name)`

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
    └── {groupName}                           ← e.g. "divi/font", "divi/font-body"
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
- `groupName` — the VB component: `divi/font`, `divi/font-body`, `divi/button`, etc.
- `groupId` — the VB panel section: `designTitleText`, `designText`, `designButton`, etc.
- `moduleName` — the module it was created on (informational, not a restriction)
- `attrs` — full attributes (render + style)
- `styleAttrs` — only CSS-generating attributes
- `renderAttrs` — only HTML-affecting attributes (e.g. headingLevel)

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

> **Token names are canonical; values are reference.** The token names (`gcid-oa-primary-500`, `gvid-oa-size-h1`) and structure (3 color families, 15 font sizes, 13 spacings, 6 radii) are the canonical target every project should create during bootstrap. The hex/clamp values below are from a reference project — your project's actual values depend on its brand colors and are set during bootstrap Step 2. Inspect live values via `diviops_list_variables`.

### Naming convention
All tokens use the `oa` prefix for filterability and collision avoidance.

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

All button presets include `button.decoration.button.desktop.value.enable: "on"` (required to activate custom styling).

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

- `GET /divi-mcp/v1/presets` — Read all presets (D5 + legacy)
- `GET /divi-mcp/v1/preset-audit` — Audit with referenced/unreferenced analysis
- `POST /divi-mcp/v1/preset-cleanup` — Remove orphans, rename, dedup (dry_run default)
- `POST /divi-mcp/v1/preset-update` — Update single preset (name, attrs)
- `POST /divi-mcp/v1/preset-delete` — Delete single preset

### When to Use Presets vs Inline Styles

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
