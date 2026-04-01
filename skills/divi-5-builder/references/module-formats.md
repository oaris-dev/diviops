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

**`dynamicOptionGroups`**: When non-default decoration groups are activated in VB, a top-level `dynamicOptionGroups` key tracks what was enabled. Format: `{"element": {"groupName": {"decoration": {"groupType": true}}}}`. Informational only — decoration paths work regardless.

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
- `direction`: CSS angle (`"135deg"`, `"180deg"`) — optional, defaults to `"180deg"`
- `stops`: array of `{position, color}` (min 2)
- Works on any module with `decoration.background`
- Gradient + color coexist (gradient on top); `gradient.overlaysImage: "on"` places gradient above image
- `gradient.repeat: "off"` — repeat toggle

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

## Exceptions Quick Reference

**These modules break the standard `module.decoration.*` pattern. Getting these wrong causes silent failures.**

| Module | What's different | Correct path | Wrong pattern (silent fail) |
|--------|-----------------|--------------|--------------------------|
| **Button** | Border/bg/font on button root | `button.decoration.{border,background,font}` | `module.decoration.border` |
| **Button** | Sizing on button element (5.1.1+) | `button.decoration.sizing` | `module.decoration.sizing` |
| **Button** | Alignment inside sizing (5.1.1+) | `button.decoration.sizing.desktop.value.alignment` | `module.advanced.alignment` (schema only, not saved) |
| **Button** | Icon enable required | `button.decoration.button.desktop.value.icon.enable: "off"` | omitting `icon.enable` |
| **Image** | Spacing/sizing on advanced | `module.advanced.{spacing,sizing}` | `module.decoration.{spacing,sizing}` |
| **Image** | Border on image element | `image.decoration.border` | `module.decoration.border` |
| **Icon** | Border/bg on module only | `module.decoration.{border,background}` | `icon.decoration.{border,background}` |
| **Video** | No module background | `overlay.decoration.background` | `module.decoration.background` |

---

## Tier 2 — Pattern Families (Pro)

The Pro version includes shared pattern documentation for:
- Font Family A (bodyFont) and Font Family B (element.decoration.font.font)
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
