# Divi 5 Module Attribute Formats

Structured as a 3-tier classification: universal decoration (Tier 1), shared pattern families (Tier 2), and module-specific unique paths (Tier 3). All modules share the same base — only exceptions and unique content paths are documented per module.

## Table of Contents

- [Tier 1 — Common Decoration](#tier-1--common-decoration-all-modules) — border, background, spacing, sizing, animation, scroll
  - [Key rules](#key-rules) — hover nesting, responsive, defaults, sync fields
  - [Universal Element Decoration](#universal-element-decoration-composable-settings) — any element, not just module
  - [Verification depth](#verification-depth)
  - [Dividers](#dividers-section-only-vb-verified-2026-03-23) — section-only divider attrs
  - [Default Value Resolution](#default-value-resolution)
  - [Gradient / Video / Pattern / Mask background](#gradient-background)
- [innerContent Variants](#innercontent-variants) — text vs button vs icon content format
- [Attribute Tree Layout: Top-Level vs `module.*`](#attribute-tree-layout-top-level-vs-module) — silent-fail guard
- [Design Token References in Attrs](#design-token-references-in-attrs-canonical-variable-only) — canonical `$variable()$` only, ban cross-system `var()`
- [Exceptions Quick Reference](#exceptions-quick-reference) — modules that break standard patterns
- Tier 2 — Pattern Families (Pro) — font families, icon family, container cascade
- Tier 3 — Module Reference (Pro) — per-module element maps + surprises
- Advanced Module Attributes (Pro)
- Global Color Variables (Pro)
- Loop & Dynamic Content (Pro)
- Interactions (Pro)

## Tier 1 — Common Decoration (all modules)

Every Divi module supports `module.decoration.*` for visual styling. This is the universal base — document it once, applies everywhere.

```json
{
  "module": {
    "decoration": {
      "border": {
        "desktop": {
          "value": {
            "radius": {"topLeft": "12px", "topRight": "12px", "bottomLeft": "12px", "bottomRight": "12px", "sync": "on"},
            "styles": {"all": {"width": "2px", "color": "#6366f1"}}
          },
          "hover": {"styles": {"all": {"color": "#f59e0b"}}}
        }
      },
      "background": {
        "desktop": {
          "value": {
            "color": "#0f172a",
            "gradient": {"enabled": "on", "stops": [{"position": "0", "color": "#0f172a"}, {"position": "100", "color": "#1e3a5f"}]},
            "image": {"url": "https://example.com/image.jpg"}
          },
          "hover": {"color": "#1e293b"}
        }
      },
      "spacing": {
        "desktop": {"value": {"padding": {"top": "20px", "bottom": "20px", "left": "20px", "right": "20px", "syncVertical": "on", "syncHorizontal": "on"}, "margin": {"top": "10px", "syncVertical": "off", "syncHorizontal": "off"}}},
        "tablet": {"value": {"padding": {"top": "15px", "bottom": "15px", "left": "15px", "right": "15px", "syncVertical": "on", "syncHorizontal": "on"}}}
      },
      "sizing": {"desktop": {"value": {"maxWidth": "800px", "width": "42rem", "flexType": "8_24"}}},
      "overflow": {"desktop": {"value": {"x": "hidden", "y": "hidden"}}},
      "animation": {"desktop": {"value": {"style": "slide", "direction": "left", "duration": "800ms", "delay": "200ms", "speedCurve": "ease-in-out", "intensity": {"slide": "20%"}, "repeat": "once", "startingOpacity": "5%"}}},
      "scroll": {"desktop": {"value": {"verticalMotion": {"enable": "on", "offset": {"start": "2", "mid": "0", "end": "-2"}, "viewport": {"bottom": "0", "end": "50", "start": "50", "top": "100"}}, "motionTriggerStart": "middle"}}}
    }
  },
  "builderVersion": "5.1.1"
}
```

### Key rules
- **Hover (decoration blocks)**: in `*.decoration.*` paths, hover goes as `desktop.hover` sibling of `value` — top-level `hover` is silently ignored. Exception: `icon.advanced.color.desktop.hover` is a scalar value, not an object
- **Responsive**: add `tablet`/`phone` siblings to `desktop` — tablet inherits desktop, phone inherits tablet
- **Defaults omitted**: VB only exports values that differ from the active preset. Missing keys are resolved via the full cascade (preset → render attrs → printed style attrs → theme CSS), not errors. See "Default Value Resolution" below
- **Sync fields**: `syncVertical`/`syncHorizontal` control VB's paired editing UI
- **Gap**: use `columnGap` + `rowGap` separately, never single `gap`
- **flexType**: 24-unit grid for flex child sizing (`"8_24"` = 1/3, `"12_24"` = 1/2) — do NOT use flexGrow/flexBasis
- **Animation styles**: `fade`, `slide`, `bounce`, `zoom`, `flip`, `fold`, `roll`
- **Animation direction**: VB label = entrance direction (`"left"` = slides in from left)
- **Animation `intensity`**: nested by style name — `intensity.slide: "20%"`, `intensity.bounce: "30%"`, etc.
- **Animation `speedCurve`**: CSS-style with hyphens — `"ease-in-out"`, `"ease-in"`, `"ease-out"`, `"linear"` (different from transition's camelCase `"easeInOut"`)
- **Animation `repeat`**: `"once"` or `"loop"` (string, not boolean)
- **Scroll effects**: 6 types — `verticalMotion`, `horizontalMotion`, `rotating`, `scaling`, `fade`, `blur`
- **Scroll offset units vary**: vertical/horizontal = unitless, rotating = `°`, scaling/fade = `%`, blur = `px`
- **Scroll `motionTriggerStart`**: `"top"`, `"middle"` (default), `"bottom"` — shared across all effects
- **Scroll + animation**: scroll effects override entrance animation when both active

### Universal Element Decoration (Composable Settings)

Since Divi 5.1.1, decoration groups are universally available on **any element** via Composable Settings (`dynamicSubgroupHost`). The `module.decoration.*` pattern documented above applies identically to any named element:

> `{element}.decoration.{background, border, sizing, spacing, boxShadow, filters, animation, transform, ...}`

**Examples**: `button.decoration.background`, `imageIcon.decoration.sizing`, `tab.decoration.font.font`, `arrows.decoration.border`, `openToggle.decoration.background`

**Implication for Tier 3**: Per-module docs only list **element names**, **innerContent shapes**, and **surprises** (non-standard fields or paths that break the universal pattern). Standard decoration on any element is assumed — never repeated.

**`dynamicOptionGroups`**: When a user **dynamically adds a design sub-group** via the Composable Settings "+" affordance (on elements with `dynamicSubgroupHost: true`), a top-level `dynamicOptionGroups` key is written to track what was enabled. Format: `{"element": {"groupName": {"decoration": {"groupType": true}}}}`. Example (Button, layout sub-group added to `button` element): `{"button": {"button": {"decoration": {"layout": true}}}}`. Informational only — decoration paths work regardless. Applying values to existing default groups (e.g. box shadow on the Module wrapper) does NOT write this key.

### Verification depth

| Decoration option | Status | Notes |
|-------------------|--------|-------|
| `border` (radius, styles, hover) | ✅ Verified | 13+ modules confirmed |
| `background` (color, gradient, image) | ✅ Verified | gradient requires `enabled: "on"`, position as strings |
| `background` (video, pattern, masks) | ✅ Verified | Full structures documented below: video (5 attrs), pattern (24 styles, 10 attrs), mask (23 styles, 11 attrs) |
| `spacing` (padding, margin, sync) | ✅ Verified | 13+ modules confirmed |
| `sizing` (width, height, maxWidth) | ✅ Verified | Image exception: uses `module.advanced.sizing` |
| `sizing.flexType` (column sizing) | ✅ Verified | 24-unit grid: `"8_24"` = 1/3, `"12_24"` = 1/2 — use on flex children, NOT flexGrow/flexBasis |
| `overflow` (x, y) | ✅ Verified | Section, Row, Column, Group |
| `animation` (full depth) | ✅ Verified | style, direction, duration, delay, speedCurve, `intensity.{style}` (nested by style name), repeat (`"loop"`/`"once"`), startingOpacity |
| `scroll` (all 6 effects) | ✅ Verified | 6 effects: verticalMotion, horizontalMotion, rotating, scaling, fade, blur. Each: `{enable, offset: {start,mid,end}, viewport: {bottom,end,start,top}}`. `motionTriggerStart`: `"top"`/`"middle"`/`"bottom"` |
| `boxShadow` | ✅ Verified | 7 props: horizontal, vertical, blur, spread, position, color, style. `position: "inner"` = inset, `"outer"` = outset. Hover sparse |
| `filters` | ✅ Verified | 8 props: brightness, blur, contrast, saturate, opacity, invert, sepia, hueRotate (camelCase). All strings with units |
| `transform` | ✅ Verified | Sub-objects: scale, rotate, translate, skew, origin. Each has x/y (rotate also z). Scale uses `%` not decimal. `linked: "on"/"off"` |
| `position` + `zIndex` | ✅ Verified | `position.mode`, `position.origin.absolute`, `position.offset.vertical/horizontal`. **zIndex is separate**: `decoration.zIndex` |
| `transition` | ✅ Verified | duration (`"400ms"`), delay (`"200ms"`), speedCurve (`"easeInOut"` camelCase) |
| `customCSS` | ✅ Verified | **Top-level `css` key** (not inside `module`). Selectors: `mainElement`, `before`, `after`. Responsive: `css.tablet.value.*` |
| `semanticHTML` | ✅ Verified | `module.advanced.html.desktop.value.elementType` — 22 tags available. `htmlBefore`/`htmlAfter` for raw HTML/wrapper injection |
| `interactions` | ✅ Verified | VB roundtrip confirmed. `module.decoration.interactions.desktop.value.interactions[]` + `interactionTrigger`/`interactionTarget` markers. |
| `disabledOn` | ✅ Verified | `module.decoration.disabledOn.{desktop,tablet,phone}.value` — `"on"`/`"off"` per breakpoint |
| `dividers` (Section only) | ✅ Verified | `module.advanced.dividers.{top,bottom}` — 26 shapes, 6 settings. See Dividers section below |

### Dividers (Section only) *(VB-verified 2026-03-23)*

Decorative shape dividers at top/bottom of Sections. Path: `module.advanced.dividers.{top,bottom}`.

```json
"dividers": {
  "top": {"desktop": {"value": {"style": "wave", "height": "120px", "color": "#6366f1", "repeat": "1x", "flip": [], "arrangement": "below"}}},
  "bottom": {"desktop": {"value": {"style": "mountains", "height": "80px", "color": "#1e293b", "repeat": "1x", "flip": ["horizontal"], "arrangement": "below"}}}
}
```

**Settings:**

| Setting | Type | Default | Values |
|---------|------|---------|--------|
| `style` | string | `"none"` | 26 shapes: `arrow`, `arrow2`, `arrow3`, `asymmetric`–`asymmetric4`, `clouds`, `clouds2`, `curve`, `curve2`, `graph`–`graph4`, `mountains`, `mountains2`, `ramp`, `ramp2`, `slant`, `slant2`, `triangle`, `wave`, `wave2`, `waves`, `waves2` |
| `height` | string | `"100px"` | CSS value (e.g. `"80px"`, `"5%"`) |
| `color` | string | auto | Hex, rgba, or `$variable()$`. When omitted, resolved from context (adjacent section background) |
| `repeat` | string | `"1x"` | Number + `x` suffix (e.g. `"2x"`, `"0.5x"`). Ignored when shape is non-repeatable (clouds, clouds2, triangle) |
| `flip` | array | `[]` | `["horizontal"]`, `["vertical"]`, or `["horizontal", "vertical"]` |
| `arrangement` | string | `"below"` | `"below"` (z-index 1) or `"above"` (z-index 10). Fullwidth Sections always use z-index 10 regardless |

- **Section only** — Row, Column, Group do NOT support dividers
- Responsive: add `tablet`/`phone` breakpoints as usual
- Non-repeatable shapes (clouds, clouds2, triangle) use `background-size: cover`

### Default Value Resolution

VB saves only values that differ from the active preset. Divi resolves styling through a 4-layer cascade:

```
Module instance (block JSON — explicit overrides only)
    ↓ fallback
Presets (two types: module presets + attribute-level presets)
    ↓ fallback
_all_modules_default_render_attributes.php (structural defaults: heading levels, toggle states)
    ↓ fallback
_all_modules_default_printed_style_attributes.php (default CSS styles generated per module)
    ↓ fallback
Divi theme CSS (base visual defaults: font-size, color, line-height, margins)
```

**Two types of presets:**
1. **Module presets** — apply to the whole module (e.g. "Dark" for Text). Only work on the module type they were created for. The module type's default preset is used implicitly when `modulePreset` is omitted.
2. **Attribute-level presets** — apply to specific attribute groups (e.g. a font preset, border preset). **Shareable across different module types** — a font preset from Text can be reused on Heading, Blurb, etc.

**`modulePreset` reference** (top-level block key):
- `"modulePreset": ["uuid"]` — primary form: array of one or more preset UUIDs (stacked; later entries override earlier)
- `"modulePreset": "uuid"` — legacy/unmigrated form: single string
- `"modulePreset": "default"` / `"_initial"` — sentinel values meaning "use the module type's default preset"
- Omit entirely to use the default preset

**Practical rules for MCP:**
- A bare module with no decoration attrs is valid — presets + CSS defaults handle styling
- Setting explicit values that match defaults is harmless (just increases JSON size)
- Do NOT strip defaults in MCP — we'd need the full cascade knowledge, which is fragile
- When comparing MCP output to VB output, "missing" attrs are preset defaults, not bugs

**Text alignment** uses `module.advanced.text.text.desktop.value.orientation` (not `textAlign`):
- Values: `"left"`, `"center"`, `"right"`, `"justify"`

### Gradient background
```json
{"module":{"decoration":{"background":{"desktop":{"value":{"gradient":{"enabled":"on","stops":[{"position":"0","color":"#7c3aed"},{"position":"100","color":"#2563eb"}]}}}}}}}
```
- **`enabled: "on"`** is REQUIRED — without it the gradient silently fails
- **`position`**: strings (`"0"`, `"50"`, `"100"`) — VB exports strings, not numbers
- **`type`** — VB-verified enum `"linear"` / `"circular"` / `"elliptical"` / `"conic"` (default linear), **not** `"radial"`. `circular`→`radial-gradient(circle at …)`, `elliptical`→`radial-gradient(ellipse at …)`, `conic`→`conic-gradient(from <direction> at …)`. Render-verified Divi 5.7.4 (2026-06-15).
- `direction`: CSS angle (`"135deg"`, `"180deg"`) — used by linear + conic; defaults to `"180deg"`
- `directionRadial`: position keyword (`"center"`, `"top left"`, …) — used by circular/elliptical/conic; defaults to `"center"`
- `stops[]`: array of `{position, color}` (min 2)
- Works on any module with `decoration.background`
- Gradient + color coexist (gradient on top); `gradient.overlaysImage: "on"` places gradient above image
- `gradient.repeat: "off"` — repeat toggle
- **Preset binding (Divi 5.7+)**: the canonical *preset-map* key for a background gradient is now `…background__gradient` (subName `gradient`, binds the whole gradient object), replacing the pre-5.7 `…background__gradient.stops` (subName `gradient.stops`, which bound only the stops array). The sibling `gradient.*` preset keys (`enabled`, `type`, `direction`, `directionRadial`, `repeat`, `length`, `overlaysImage`) are unchanged. The module-attr **value path above is unchanged** — author gradients at `…background.<breakpoint>.<state>.gradient.{enabled,stops[],…}` exactly as shown; only the preset-binding key shape moved. The new whole-object `gradient` slot also backs Divi 5.7's gradient global variables (a single slot can now carry a `gvid-…` reference).
- **Gradient global variables — value-shape caveat (5.7.4, VB-verified 2026-06-15):** to reference a gradient variable, the VB sets `gradient.stops` to a string token `"$variable({\"type\":\"gradient\",\"value\":{\"name\":\"gvid-…\",\"settings\":{}}})$"` (keep `enabled:"on"`). Divi resolves it **only** when the referenced gvid is stored in its canonical structured shape (its `value` is itself a `$variable({type:gradient,value:{name:"gradient",settings:{stops[],type,direction,…}}})$` token). A gradient variable whose stored `value` is a plain CSS string (e.g. `linear-gradient(…)`) is referenced via `var(--gvid-…)` but never defined → renders nothing. Create gradient variables through the VB Variable Manager **or** via `diviops_variable_create({type:"gradients", gradient:{stops:[…], type, direction, …}})` (diviops-agent ≥ 1.5.4 / server ≥ 1.5.28), which serializes this exact token; a raw CSS-string `value` is rejected.

### Video background
```json
{"module":{"decoration":{"background":{"desktop":{"value":{"video":{"mp4":"","webm":"https://example.com/video.webm","width":"","height":"650","allowPlayerPause":"on"}}}}}}}
```
- `mp4`/`webm`: separate URL fields (at least one required)
- `width`/`height`: strings, no units (pixels implied)
- `allowPlayerPause`: `"on"`/`"off"` — pause when another video plays
- `pauseOutsideViewport`: `"on"` (default, omitted when default)
- No poster image on Text modules (Video module may differ)

### Pattern background
```json
{"module":{"decoration":{"background":{"desktop":{"value":{"pattern":{"enabled":"on","style":"diamonds","color":"rgba(99, 102, 241, 0.15)","transform":["flipVertical"],"size":"cover","repeatOrigin":"right top","horizontalOffset":"1%","verticalOffset":"1%","repeat":"space","blend":"overlay"}}}}}}}
```
- **`enabled: "on"`** is REQUIRED
- **24 styles**: 3d-diamonds, checkerboard, confetti, crosses, cubes, diagonal-stripes, diagonal-stripes-2, diamonds, honeycomb, inverted-chevrons, inverted-chevrons-2, ogees, pills, pinwheel, polka-dots (default), scallops, shippo, smiles, squares, triangles, tufted, waves, zig-zag, zig-zag-2
- `transform`: array — any combination of `"flipVertical"`, `"flipHorizontal"`, `"rotate"`, `"invert"`
- `size`: `"cover"`, `"contain"`, `"stretch"`, or `"custom"` (use `width` and `height` fields for custom dimensions)
- `blend`: CSS blend mode — normal, multiply, screen, overlay, darken, lighten, color-dodge, color-burn, hard-light, soft-light, difference, exclusion, hue, saturation, color, luminosity
- `repeat`: `"repeat"`, `"space"`, `"no-repeat"`, etc.
- `repeatOrigin`: CSS position string (`"right top"`, `"center center"`)

### Mask background
```json
{"module":{"decoration":{"background":{"desktop":{"value":{"mask":{"enabled":"on","style":"wave","color":"rgba(0, 0, 0, 0.8)","transform":["flipHorizontal","invert"],"aspectRatio":"square","size":"cover","height":"100%","position":"center bottom","horizontalOffset":"1%","verticalOffset":"1%","blend":"multiply"}}}}}}}
```
- **`enabled: "on"`** is REQUIRED
- **23 styles**: arch, bean, blades, caret, chevrons, corner-blob, corner-lake, corner-paint, corner-pill, corner-square, diagonal, diagonal-bars, diagonal-bars-2, diagonal-pills, ellipse, floating-squares, honeycomb, layer-blob (default), paint, rock-stack, square-stripes, triangles, wave
- `transform`: array — any combination of `"flipHorizontal"`, `"flipVertical"`, `"rotate"`, `"invert"`
- `aspectRatio`: `"square"`, `"landscape"`, `"portrait"`
- `size`: `"cover"`, `"contain"`, `"stretch"`, `"custom"` (with `width` and `height` values)
- `position`: CSS position string (`"center bottom"`, `"left top"`)
- `blend`: same 16 CSS blend modes as pattern

## innerContent Variants

| Type | Example modules | Format |
|------|-----------------|--------|
| HTML string | Text, Accordion content, Slide content | `"<p>HTML</p>"` |
| Plain string | Heading title, Author name, Job title | `"Plain text"` |
| Object `{text, linkUrl, linkTarget}` | Testimonial company | `{"text": "Corp", "linkUrl": "#", "linkTarget": "on"}` |
| Object `{url}` | Testimonial portrait | `{"url": "https://example.com/photo.jpg"}` |
| Object `{src, id, alt, ...}` | Image, Slide image | `{"src": "https://...", "id": "49", "alt": "Desc"}` |
| Object `{text, linkUrl}` | Button | `{"text": "Click", "linkUrl": "#"}` |
| Object `{unicode, type, weight, url}` | Icon | `{"unicode": "&#xf0eb;", "type": "fa", "weight": "900"}` |

## Attribute Tree Layout: Top-Level vs `module.*`

Divi's block `attrs` object splits across two tree levels, and **which level is authoritative is per-group** — there is no uniform rule. Writing to the wrong level causes `diviops_module_update` to return `success`, but Divi reads from the other path, so the write is silently ignored on render. The VB re-render shows the old value; the tool reports no error. If you don't read-back verify, this burns debug time before you notice.

**Known top-level keys** (siblings of `module` — write here, NOT under `module.*`):

| Top-level key | What it configures | Wrong path (silent fail) |
|---|---|---|
| `css.{breakpoint}.value.{mainElement,before,after}` | Custom CSS override (per-module selectors) | `module.css.*` |
| `css.{breakpoint}.value.freeForm` | Module-scoped free-form CSS with `selector` token replacement | `module.css.*` |
| `content` (or `innerContent` per module) | Module content payload (text/button/icon/etc. — shape varies per module) | `module.content`, `module.innerContent` |
| `modulePreset` | Preset ID array (stacked presets — array, not single string) | `module.modulePreset` |
| `groupPreset.{slot}.presetId` | Group-level preset refs (array of preset IDs per slot — stackable, not single string; slot keys are camelCase like `designTitleText`, `designText`, `button`, etc. — each slot also carries a sibling `groupName` value like `"divi/font"` or `"divi/button"` identifying the group type) | `module.groupPreset` |
| `dynamicOptionGroups` | Composable Settings sub-group tracking (5.1.1+) | `module.dynamicOptionGroups` |
| `builderVersion` | Auto-migration trigger version | `module.builderVersion` |

**Known nested keys** (write under `module.*` — NOT at top-level):

| Nested key | What it configures | Wrong path (silent fail) |
|---|---|---|
| `module.meta.adminLabel.{breakpoint}.value` | VB admin label (layer list) | `meta.adminLabel`, `module.adminLabel` |
| `module.decoration.*` | All visual styling (border, background, spacing, sizing, layout, overflow, animation, scroll, transform, filters, boxShadow, ...) | `decoration.*` (top-level) |
| `module.advanced.*` | HTML output + behavior (elementType, htmlBefore/After, link, position, sticky, visibility, transition, order, ...) | `advanced.*` (top-level) |

**Non-module elements** follow the same pattern under their own element name — e.g. `button.decoration.*`, `imageIcon.decoration.sizing`, `fieldItem.advanced.type`. The split is `{element}.*` (nested) vs the small fixed set of top-level siblings listed above.

**Verification pattern** — when in doubt, read back after write:

1. `diviops_module_update` → returns `success`
2. `diviops_page_get_layout` → fetch the same block
3. Confirm the value landed at your target path. If it landed at a different path, or isn't present at all, you picked the wrong level.

The module renderer reads from the authoritative location per the tables above. Mismatches fall through to the pre-existing value (or the group default) — which is why the VB still shows the old state even though the tool claimed success.

## Design Token References in Attrs: Canonical `$variable()$` Only

Module attrs hold literal CSS values or canonical `$variable({...})$` tokens — nothing else. A hand-authored `var(--arbitrary-alias)` inside an attr value is a cross-system reference: it depends on a CSS variable some external stylesheet must declare. If that declaration is missing, the CSS spec says the property falls through to its initial value (0 for padding, browser default for color). The write succeeds, the renderer emits the ref as-is, and the page silently breaks.

**Isolation rule**: Divi owns the `gcid-*` / `gvid-*` namespace. Variable Manager tokens auto-emit into `:root` on every page. Modules reference them via canonical `$variable({...})$`; the renderer rewrites to `var(--gvid-*)` / `var(--gcid-*)` at emission time with the matching `:root` declaration always present. Child-theme CSS lives on its own track for non-Divi surfaces — neither side `var()`s across the boundary.

| Attr value | Result |
|---|---|
| `"80px"`, `"#ff0000"`, `"clamp(2rem, 5vw, 4rem)"` | Literal — emitted as-is. |
| `$variable({"type":"content","value":{"name":"gvid-oa-space-4","settings":{}}})$` | Canonical — resolves to `var(--gvid-oa-space-4)`; `:root { --gvid-oa-space-4: <value> }` auto-emitted. |
| `"var(--gcid-oa-primary-500)"` / `"var(--gvid-oa-space-4)"` | Tolerated (Divi-owned prefix, resolves via `:root`) but non-canonical — prefer `$variable({...})$`. |
| `"var(--space-3)"` or any `var(--<non-gvid-non-gcid>)` | **Banned.** Silent-failure class — falls through to the property's initial value. |
| `$variable(gvid-xxx)$` (shorthand, bare ID) | **Does not resolve.** The canonical token must wrap a JSON payload; the shorthand emits literally into CSS and the browser drops the declaration. |

Need a semantic name? Register it inside Divi as a `gvid-*` / `gcid-*` in the Variable Manager (e.g. `gvid-oa-space-hero-xl`) and reference via `$variable({...})$`. Don't layer a child-theme alias on top.

## Exceptions Quick Reference

**These modules break the standard `module.decoration.*` pattern. Getting these wrong causes silent failures.**

| Module | What's different | Correct path | Wrong pattern (silent fail) |
|--------|-----------------|--------------|--------------------------|
| **Heading** | Explicit heading level required | `title.decoration.font.font.desktop.value.headingLevel: "h1"` | omitting → renders as `<h2>` |
| **Button** | Content bucket & shape | `button.innerContent.desktop.value: {text, linkUrl}` | `content.innerContent.*` OR plain string → default "Click Me" |
| **Button** | Border/bg/font on button root | `button.decoration.{border,background,font}` (sibling-level — VB-verified Divi 5.4.0; NO `enable: "on"` flag required) | `module.decoration.border` OR visual styling at `button.decoration.button.desktop.value.{backgroundColor,textColor,font,...}` — see footnote[^button-deep-path] |
| **Button** | Padding on button (scope-dependent) | `module.decoration.spacing.padding` (inline / `divi/button` module preset) — `button.decoration.spacing.padding` (`divi/button` group preset; `presetGroup` render path merges it into module spacing at `ButtonModule.php:633-644`) | `button.decoration.button.desktop.value.padding` — that path is an icon-spacing gate, **not** a visible-padding emitter; values do not emit visible CSS (see footnote[^button-deep-path]). Required as gate-bypass on every `divi/button` group preset that doesn't carry padding here, otherwise the hover-gate clobbers visible padding — see [presets.md → Hover-padding gate on Button group presets](presets.md#hover-padding-gate-on-button-group-presets-broad-scope-upstream-tracked) |
| **Button** | Sizing on button element (5.1.1+) | `button.decoration.sizing` | `module.decoration.sizing` |
| **Button** | Alignment inside sizing (5.1.1+) | `button.decoration.sizing.desktop.value.alignment` | `module.advanced.alignment` (schema only, not saved) |
| **Button** | Icon enable required | `button.decoration.button.desktop.value.icon.enable: "off"` | omitting `icon.enable` → hover arrow icon |
| **Blurb** | Title shape is an object | `title.innerContent.desktop.value: {text: "..."}` | plain string → title silently absent from rendered HTML |
| **Blurb** | Icon requires useIcon flag | `imageIcon.innerContent.desktop.value.useIcon: "on"` | setting `icon` without `useIcon: "on"` → empty `<span>` |
| **Text/Blurb** | Body font triple-nesting | `content.decoration.bodyFont.body.font.desktop.value.*` | `bodyFont.bodyFont.*` → color/size silently ignored |
| **Flex children only** | `flexType` 24-unit grid path is `module.decoration.sizing.desktop.value.flexType` | on `divi/column` / `divi/column-inner` inside `divi/row` whose `module.decoration.layout.desktop.value.display = "flex"`, OR on Group children inside a Group with `display: "flex"` | `decoration.layout.flexType` (wrong path — byte-stored but semantically dropped); on a default `display: block` row the renderer ignores `flexType` and emits legacy `et_pb_column_N_M` classes by column count → 3×`"8_24"` columns render full-width stacked, not side-by-side (Divi 5.4.1 verified 2026-05-09); on blurb/text/image with no flex parent → silently dropped, use `module.decoration.sizing.width` instead |
| **Image** | Spacing/sizing on advanced | `module.advanced.{spacing,sizing}` | `module.decoration.{spacing,sizing}` |
| **Image** | Border on image element | `image.decoration.border` | `module.decoration.border` |
| **Image** | Border-radius from preset alone doesn't render | reinforce inline on `image.decoration.border.desktop.value.radius` (same path as preset) | preset only → square corners on frontend (image-specific quirk) |
| **Icon** | Border/bg on module only | `module.decoration.{border,background}` | `icon.decoration.{border,background}` |
| **Video** | No module background | `overlay.decoration.background` | `module.decoration.background` |
| **Social Media Follow** | Custom icon size lives under `icon.advanced`, gated by `useSize` toggle | `icon.advanced.useSize: "on"` + `icon.advanced.size: "<value>"` (`"96px"`, `"$variable({...})$"`, `"calc(2rem + 1vw)"`, `"clamp(48px, 5vw, 96px)"`, `"var(--gvid-...)"`, or length keywords — all accepted at parity per Divi 5.3.3) | omitting `useSize` (size is ignored) or assuming a numeric-only field (pre-5.3.3 silently dropped math/var/keyword) |
| **Breadcrumbs (5.4.0+)** | `trail.advanced.htmlTag` is the only `trail.advanced.*` key the renderer reads | `trail.advanced.htmlTag.desktop.value`: `"nav"` (default) \| `"div"` \| `"span"` \| `"p"` | other `trail.advanced.*` paths silently inert at render — `trail` is content-config only, not style-registered |
| **Breadcrumbs (5.4.0+)** | Per-item element backgrounds restricted (no mask/pattern/video, no parallax) | put rich backgrounds on `module.decoration.background` instead | setting `mask`/`pattern`/`video`/parallax on `breadcrumb.decoration.background`, `breadcrumbLink.decoration.background`, `home.decoration.background`, or `separator.decoration.background` → silently dropped |

---

## Tier 2 — Pattern Families (Pro)

The Pro version includes shared pattern documentation for:
- Font Family A (bodyFont) and Font Family B (element.decoration.font.font)
- Font Text Effects (gradient/image fill, stroke) *(Divi 5.7+)*
- Icon Family (element.decoration.icon)
- Container Cascade (children.module.decoration)
- Module Link

## Tier 3 — Module Reference (Pro)

The Pro version includes per-module element maps (elements, innerContent shapes, surprises) for 20+ VB-verified modules:
- Structure: Section, Row, Column, Group
- Content: Text, Button, Image, Icon, Blurb, Heading, Divider
- Interactive: Slider, Accordion, Tabs, Toggle, Testimonial, Number Counter, Video, Contact Form, Countdown Timer, Code, Lottie
- Plus: Full Composite Example, Advanced Module Attributes (boxShadow, filters, transform, position, sticky, visibility, transition, scroll, animation, order), Global Color Variables, Loop & Dynamic Content, Interactions

Upgrade to Pro: https://diviops.com

<!-- BEGIN GENERATED:header -->

## Generated path index

> Generated mechanically by `diviops-server/scripts/regen-module-formats.mjs` from `diviops_schema_get_module` dump-all output. Each module block lives between `BEGIN GENERATED:module:divi/<slug>` / `END GENERATED:module:divi/<slug>` HTML-comment sentinels (see `diviops-server/CONTRIBUTING.md` for the full convention). Do **not** edit between sentinels — edits are clobbered on regen.

> Generated against Divi `5.8.0`, schema `af7c9d795e77…`.

Per CLAUDE.md "Suite architecture coherence": schema dump is the canonical index; VB-verified prose above is the canonical interpretation. The two sections are complementary, not competing — prose explains surprises, this index enumerates paths exhaustively. On conflicts, the prose above wins (per `feedback_vb_first_verification`).

<!-- END GENERATED:header -->

<!-- BEGIN GENERATED:module:divi/accordion -->

<!-- TIER: free -->
#### `divi/accordion`

- **closedToggle** — `closedToggle.decoration.background`, `closedToggle.decoration.font`
- **closedToggleIcon** — `closedToggleIcon.decoration.icon`
- **content** — `content.decoration.bodyFont`
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_
- **openToggle** — `openToggle.decoration.background`, `openToggle.decoration.font`
- **title** — `title.decoration.font`

<!-- END GENERATED:module:divi/accordion -->

<!-- BEGIN GENERATED:module:divi/blurb -->

<!-- TIER: free -->
#### `divi/blurb`

- **content** — `content.decoration.bodyFont` _(+innerContent)_
- **contentContainer** — `contentContainer.decoration.sizing`
- **imageIcon** — `imageIcon.decoration.animation`, `imageIcon.decoration.background`, `imageIcon.decoration.spacing` _(+innerContent, +advanced)_
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_
- **title** — `title.decoration.font` _(+innerContent)_

<!-- END GENERATED:module:divi/blurb -->

<!-- BEGIN GENERATED:module:divi/button -->

<!-- TIER: free -->
#### `divi/button`

- **button** — `button.decoration.background`, `button.decoration.border`, `button.decoration.boxShadow`, `button.decoration.button`, `button.decoration.font`, `button.decoration.sizing`, `button.decoration.spacing` _(+innerContent)_
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_

<!-- END GENERATED:module:divi/button -->

<!-- BEGIN GENERATED:module:divi/code -->

<!-- TIER: free -->
#### `divi/code`

- **content** — _(no decoration groups)_ _(+innerContent)_
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_

<!-- END GENERATED:module:divi/code -->

<!-- BEGIN GENERATED:module:divi/column -->

<!-- TIER: free -->
#### `divi/column`

- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_

<!-- END GENERATED:module:divi/column -->

<!-- BEGIN GENERATED:module:divi/contact-form -->

<!-- TIER: free -->
#### `divi/contact-form`

- **button** — `button.decoration.background`, `button.decoration.border`, `button.decoration.boxShadow`, `button.decoration.button`, `button.decoration.font`, `button.decoration.sizing`, `button.decoration.spacing` _(+innerContent)_
- **captcha** — `captcha.decoration.font`
- **checkbox** — _(no decoration groups)_ _(+advanced)_
- **email** — _(no decoration groups)_ _(+innerContent, +advanced)_
- **field** — _(no decoration groups)_ _(+advanced)_
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_
- **radio** — _(no decoration groups)_ _(+advanced)_
- **redirect** — _(no decoration groups)_ _(+innerContent, +advanced)_
- **title** — `title.decoration.font` _(+innerContent)_

<!-- END GENERATED:module:divi/contact-form -->

<!-- BEGIN GENERATED:module:divi/countdown-timer -->

<!-- TIER: free -->
#### `divi/countdown-timer`

- **content** — _(no decoration groups)_ _(+advanced)_
- **label** — `label.decoration.font`
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_
- **number** — `number.decoration.font`
- **separator** — `separator.decoration.font`
- **title** — `title.decoration.font` _(+innerContent)_

<!-- END GENERATED:module:divi/countdown-timer -->

<!-- BEGIN GENERATED:module:divi/divider -->

<!-- TIER: free -->
#### `divi/divider`

- **divider** — _(no decoration groups)_ _(+advanced)_
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_

<!-- END GENERATED:module:divi/divider -->

<!-- BEGIN GENERATED:module:divi/group -->

<!-- TIER: free -->
#### `divi/group`

- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_

<!-- END GENERATED:module:divi/group -->

<!-- BEGIN GENERATED:module:divi/heading -->

<!-- TIER: free -->
#### `divi/heading`

- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_
- **title** — `title.decoration.font` _(+innerContent)_

<!-- END GENERATED:module:divi/heading -->

<!-- BEGIN GENERATED:module:divi/icon -->

<!-- TIER: free -->
#### `divi/icon`

- **icon** — _(no decoration groups)_ _(+innerContent, +advanced)_
- **iconLink** — _(no decoration groups)_
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_

<!-- END GENERATED:module:divi/icon -->

<!-- BEGIN GENERATED:module:divi/image -->

<!-- TIER: free -->
#### `divi/image`

- **image** — `image.decoration.border`, `image.decoration.boxShadow`, `image.decoration.fit` _(+innerContent, +advanced)_
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_

<!-- END GENERATED:module:divi/image -->

<!-- BEGIN GENERATED:module:divi/instagram-feed -->

<!-- TIER: free -->
#### `divi/instagram-feed`

- **feed** — `feed.decoration.background`, `feed.decoration.border`, `feed.decoration.boxShadow`, `feed.decoration.layout`, `feed.decoration.sizing`, `feed.decoration.spacing` _(+innerContent, +advanced)_
- **followButton** — `followButton.decoration.background`, `followButton.decoration.border`, `followButton.decoration.boxShadow`, `followButton.decoration.button`, `followButton.decoration.font`, `followButton.decoration.sizing`, `followButton.decoration.spacing` _(+innerContent, +advanced)_
- **item** — `item.decoration.background`, `item.decoration.border`, `item.decoration.boxShadow`, `item.decoration.sizing`, `item.decoration.spacing`
- **media** — `media.decoration.image`
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_

<!-- END GENERATED:module:divi/instagram-feed -->

<!-- BEGIN GENERATED:module:divi/lottie -->

<!-- TIER: free -->
#### `divi/lottie`

- **lottie** — _(no decoration groups)_ _(+innerContent)_
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_

<!-- END GENERATED:module:divi/lottie -->

<!-- BEGIN GENERATED:module:divi/number-counter -->

<!-- TIER: free -->
#### `divi/number-counter`

- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_
- **number** — `number.decoration.font` _(+innerContent, +advanced)_
- **title** — `title.decoration.font` _(+innerContent)_

<!-- END GENERATED:module:divi/number-counter -->

<!-- BEGIN GENERATED:module:divi/row -->

<!-- TIER: free -->
#### `divi/row`

- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_

<!-- END GENERATED:module:divi/row -->

<!-- BEGIN GENERATED:module:divi/section -->

<!-- TIER: free -->
#### `divi/section`

- **column1** — `column1.decoration.background`, `column1.decoration.spacing` _(+advanced)_
- **column2** — `column2.decoration.background`, `column2.decoration.spacing` _(+advanced)_
- **column3** — `column3.decoration.background`, `column3.decoration.spacing` _(+advanced)_
- **innerSizing** — `innerSizing.decoration.sizing`
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_

<!-- END GENERATED:module:divi/section -->

<!-- BEGIN GENERATED:module:divi/slider -->

<!-- TIER: free -->
#### `divi/slider`

- **arrows** — _(no decoration groups)_ _(+advanced)_
- **button** — `button.decoration.button`
- **children** — `children.decoration.background`, `children.decoration.border` _(+advanced)_
- **content** — `content.decoration.bodyFont`, `content.decoration.sizing`
- **dotNav** — `dotNav.decoration.background`
- **image** — `image.decoration.image` _(+advanced)_
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_
- **pagination** — _(no decoration groups)_ _(+advanced)_
- **title** — `title.decoration.font`

<!-- END GENERATED:module:divi/slider -->

<!-- BEGIN GENERATED:module:divi/svg -->

<!-- TIER: free -->
#### `divi/svg`

- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_
- **svg** — _(no decoration groups)_ _(+innerContent, +advanced)_

<!-- END GENERATED:module:divi/svg -->

<!-- BEGIN GENERATED:module:divi/table-of-contents -->

<!-- TIER: free -->
#### `divi/table-of-contents`

- **emptyState** — `emptyState.decoration.font` _(+innerContent)_
- **list** — `list.decoration.font` _(+innerContent, +advanced)_
- **list1** — `list1.decoration.font`
- **list2** — `list2.decoration.font`
- **list3** — `list3.decoration.font`
- **list4** — `list4.decoration.font`
- **list5** — `list5.decoration.font`
- **list6** — `list6.decoration.font`
- **marker** — `marker.decoration.font`
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_
- **title** — `title.decoration.font` _(+innerContent)_

<!-- END GENERATED:module:divi/table-of-contents -->

<!-- BEGIN GENERATED:module:divi/tabs -->

<!-- TIER: free -->
#### `divi/tabs`

- **activeTab** — `activeTab.decoration.background`, `activeTab.decoration.font`
- **content** — `content.decoration.background`, `content.decoration.bodyFont`
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_
- **tab** — `tab.decoration.background`, `tab.decoration.font`

<!-- END GENERATED:module:divi/tabs -->

<!-- BEGIN GENERATED:module:divi/testimonial -->

<!-- TIER: free -->
#### `divi/testimonial`

- **author** — `author.decoration.font` _(+innerContent)_
- **company** — `company.decoration.font` _(+innerContent)_
- **content** — `content.decoration.bodyFont` _(+innerContent)_
- **jobTitle** — `jobTitle.decoration.font` _(+innerContent)_
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_
- **portrait** — `portrait.decoration.image` _(+innerContent)_
- **quoteIcon** — `quoteIcon.decoration.background`, `quoteIcon.decoration.icon`
- **testimonialDescription** — _(no decoration groups)_

<!-- END GENERATED:module:divi/testimonial -->

<!-- BEGIN GENERATED:module:divi/text -->

<!-- TIER: free -->
#### `divi/text`

- **content** — `content.decoration.bodyFont`, `content.decoration.headingFont` _(+innerContent)_
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_

<!-- END GENERATED:module:divi/text -->

<!-- BEGIN GENERATED:module:divi/timeline -->

<!-- TIER: free -->
#### `divi/timeline`

- **card** — `card.decoration.background`, `card.decoration.border`, `card.decoration.boxShadow`, `card.decoration.layout`, `card.decoration.sizing`, `card.decoration.spacing`
- **cardEven** — `cardEven.decoration.background`, `cardEven.decoration.border`, `cardEven.decoration.boxShadow`, `cardEven.decoration.layout`, `cardEven.decoration.sizing`, `cardEven.decoration.spacing`
- **children** — _(no decoration groups)_ _(+advanced)_
- **connector** — `connector.decoration.background`, `connector.decoration.border`, `connector.decoration.boxShadow`, `connector.decoration.sizing`, `connector.decoration.spacing`
- **content** — `content.decoration.bodyFont`
- **contentEven** — `contentEven.decoration.bodyFont`
- **date** — `date.decoration.font`
- **dateEven** — `dateEven.decoration.font`
- **item** — `item.decoration.background`, `item.decoration.border`, `item.decoration.boxShadow`, `item.decoration.sizing`, `item.decoration.spacing`
- **itemEven** — `itemEven.decoration.background`, `itemEven.decoration.border`, `itemEven.decoration.boxShadow`, `itemEven.decoration.sizing`, `itemEven.decoration.spacing`
- **marker** — `marker.decoration.background`, `marker.decoration.border`, `marker.decoration.boxShadow`, `marker.decoration.icon`, `marker.decoration.sizing`, `marker.decoration.spacing` _(+advanced)_
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_
- **spacer** — `spacer.decoration.background`, `spacer.decoration.border`, `spacer.decoration.boxShadow`, `spacer.decoration.layout`, `spacer.decoration.sizing`, `spacer.decoration.spacing`
- **spacerEven** — `spacerEven.decoration.background`, `spacerEven.decoration.border`, `spacerEven.decoration.boxShadow`, `spacerEven.decoration.layout`, `spacerEven.decoration.sizing`, `spacerEven.decoration.spacing`
- **title** — `title.decoration.font`
- **titleEven** — `titleEven.decoration.font`
- **track** — `track.decoration.background`, `track.decoration.border`, `track.decoration.boxShadow`, `track.decoration.sizing`, `track.decoration.spacing`

<!-- END GENERATED:module:divi/timeline -->

<!-- BEGIN GENERATED:module:divi/timeline-item -->

<!-- TIER: free -->
#### `divi/timeline-item`

- **card** — `card.decoration.background`, `card.decoration.border`, `card.decoration.boxShadow`, `card.decoration.layout`, `card.decoration.sizing`, `card.decoration.spacing`
- **connector** — `connector.decoration.background`, `connector.decoration.border`, `connector.decoration.boxShadow`, `connector.decoration.sizing`, `connector.decoration.spacing`
- **content** — `content.decoration.bodyFont` _(+innerContent, +advanced)_
- **date** — `date.decoration.font` _(+innerContent)_
- **marker** — `marker.decoration.background`, `marker.decoration.border`, `marker.decoration.boxShadow`, `marker.decoration.icon`, `marker.decoration.sizing`, `marker.decoration.spacing` _(+innerContent, +advanced)_
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_
- **spacer** — `spacer.decoration.background`, `spacer.decoration.border`, `spacer.decoration.boxShadow`, `spacer.decoration.layout`, `spacer.decoration.sizing`, `spacer.decoration.spacing` _(+advanced)_
- **title** — `title.decoration.font` _(+innerContent)_

<!-- END GENERATED:module:divi/timeline-item -->

<!-- BEGIN GENERATED:module:divi/toggle -->

<!-- TIER: free -->
#### `divi/toggle`

- **closedTitle** — `closedTitle.decoration.font` _(+innerContent)_
- **closedToggle** — `closedToggle.decoration.background`
- **closedToggleIcon** — `closedToggleIcon.decoration.icon`
- **content** — `content.decoration.bodyFont` _(+innerContent)_
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_
- **openToggle** — `openToggle.decoration.background`, `openToggle.decoration.font`
- **openToggleIcon** — `openToggleIcon.decoration.icon`
- **title** — `title.decoration.font` _(+innerContent)_

<!-- END GENERATED:module:divi/toggle -->

<!-- BEGIN GENERATED:module:divi/tooltip -->

<!-- TIER: free -->
#### `divi/tooltip`

- **content** — `content.decoration.bodyFont` _(+innerContent)_
- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_

<!-- END GENERATED:module:divi/tooltip -->

<!-- BEGIN GENERATED:module:divi/video -->

<!-- TIER: free -->
#### `divi/video`

- **module** — `module.decoration.animation`, `module.decoration.attributes`, `module.decoration.background`, `module.decoration.border`, `module.decoration.boxShadow`, `module.decoration.conditions`, `module.decoration.disabledOn`, `module.decoration.filters`, `module.decoration.interactions`, `module.decoration.layout`, `module.decoration.order`, `module.decoration.overflow`, `module.decoration.position`, `module.decoration.scroll`, `module.decoration.sizing`, `module.decoration.spacing`, `module.decoration.sticky`, `module.decoration.transform`, `module.decoration.transition`, `module.decoration.zIndex` _(+advanced)_
- **overlay** — `overlay.decoration.background` _(+innerContent)_
- **playIcon** — `playIcon.decoration.icon`
- **video** — _(no decoration groups)_ _(+innerContent)_

<!-- END GENERATED:module:divi/video -->



[^button-deep-path]: Render-relevant keys at `button.decoration.button.desktop.value.*` are limited to `enable` (migration trigger — `"off"` causes Divi to strip `button.decoration`; never write without intent), `icon.*` (visible icon configuration), `padding` (icon-spacing gate — does **not** emit visible padding CSS, but values do flip the hover-gate behavior at `StyleDeclarations.php:153-160`; required as gate-bypass on every `divi/button` group preset that doesn't carry padding here, see [presets.md → Hover-padding gate on Button group presets](presets.md#hover-padding-gate-on-button-group-presets-broad-scope-upstream-tracked)), and `alignment` (deprecated, forwarded to `decoration.sizing.alignment`). Anything else (e.g. `backgroundColor`, `textColor`, `font`) parses, validates, saves, then no-ops at render. VB-verified Divi 5.4.0.
