# DiviOps Design Library — CSS Effects & Three.js

Plugin: `wp-content/plugins/diviops-design-library/`
Provides modern design effects via CSS classes, IntersectionObserver JS, and Three.js.

## How to Apply CSS Classes in Divi 5

**Use `module.decoration.attributes`** — this is the VB-native method. Do NOT use the `className` block attribute (it exists in the schema but Divi 5 ignores it for rendering).

```json
{
  "module": {
    "decoration": {
      "attributes": {
        "desktop": {
          "value": {
            "attributes": [{
              "id": "da1",
              "name": "class",
              "value": "ddl-animate ddl-fade-up ddl-delay-2",
              "adminLabel": "class ddl-animate ddl-fade-up ddl-delay-2",
              "targetElement": "main"
            }]
          }
        }
      }
    }
  }
}
```

Each attribute entry needs: `id` (unique), `name` ("class"), `value` (space-separated classes), `adminLabel` (for VB display), `targetElement` ("main").

## CSS Classes

### Entrance Animations (scroll-triggered)
| Class | Effect |
|-------|--------|
| `ddl-animate ddl-fade-up` | Fade up on scroll into view |
| `ddl-animate ddl-scale-in` | Scale up on scroll into view |
| `ddl-animate ddl-slide-left` | Slide from right on scroll |
| `ddl-animate ddl-slide-right` | Slide from left on scroll |

### Stagger Delays (combine with above)
| Class | Delay |
|-------|-------|
| `ddl-delay-1` | 0.1s |
| `ddl-delay-2` | 0.2s |
| `ddl-delay-3` | 0.3s |
| `ddl-delay-4` | 0.4s |
| `ddl-delay-5` | 0.5s |
| `ddl-delay-6` | 0.6s |

### Visual Effects
| Class | Effect |
|-------|--------|
| `ddl-glass` | Glass morphism (dark): backdrop-filter blur + semi-transparent bg |
| `ddl-glass-light` | Glass morphism (light variant) |
| `ddl-gradient-animated` | Animated background gradient (set `background-size: 200%`) |
| `ddl-hover-lift` | Lift + shadow on hover |
| `ddl-pulse-dot` | Pulsing indicator dot |

### Marquee (continuous scrolling)
| Class | Where | Purpose |
|-------|-------|---------|
| `ddl-marquee-track` | Outer container | Sets overflow hidden |
| `ddl-marquee-scroll` | Inner wrapper | Applies scroll animation |

### VB Safety
`ddl-animate` starts elements at `opacity: 0` — invisible in the Visual Builder where IntersectionObserver may not fire. The plugin includes a VB override that resets `opacity: 1 !important` and `animation: none !important` inside VB contexts (`#et-fb-app`, `.et-fb`, `body.et-fb`). Intentional transforms are NOT reset — only visibility and animation playback.

### When to Use ddl-* vs Divi Native
- **Divi native `animation`**: simple entrance effects (fade, slide, zoom) — 80% of cases
- **`ddl-*` classes**: staggered siblings, glass morphism, animated gradients, marquee
- **Divi native `scroll`**: parallax, scroll-driven opacity/scale/rotation
- **Three.js**: WebGL backgrounds — section-contained (multi-section) or full-page (single-section)

## Three.js Integration

### Setup
Three.js r128 is bundled locally in the plugin. Auto-loaded when page content contains keywords: `THREE`, `shader`, `webgl`, `three.js`, `canvas`.

### Two Patterns: Full-Page vs Section-Contained

| Pattern | Canvas position | Use when |
|---------|----------------|----------|
| **Section-contained** | `position: absolute` | Multi-section pages (most common) |
| **Full-page** | `position: fixed` | Single-section hero pages only |

### Section-Contained Pattern (multi-section pages) <!-- verified 2026-03-21 -->

Canvas injected via `htmlBefore` on the **first Row** (not Section).

**Critical**: Section's `htmlBefore` injects *outside* the section element (as a sibling). Row's `htmlBefore` injects *inside* the section — canvas becomes a child and can position relative to it.

```
Section (class: my-hero, freeForm CSS for positioning)
├── Row (htmlBefore: <canvas> + <script>)
│   └── Column
│       └── Content modules
```

**CSS (freeForm on section):**
```css
.my-hero.et_pb_section { position: relative }
.my-hero > .et_pb_row { position: relative; z-index: 2 }
```

**Canvas (inline styles on the element):**
```html
<canvas id="my-canvas" style="position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:0"></canvas>
```

**Script sizing** — use section dimensions, not viewport:
```js
var sec = el.closest('.et_pb_section');
renderer.setSize(sec.offsetWidth, sec.offsetHeight);
```

**Rules:**
- Do NOT use `overflow: hidden` on the section (clips the canvas)
- Do NOT put canvas in Section's `htmlBefore` (injects outside)
- Sections are always 100vw — `width: 100%` on canvas = full width

### Full-Page Pattern (single-section hero pages)

```
Section (bg: #000, minHeight: 100vh, class: shader-hero)
├── Row (Code module with canvas + script)
```

```css
.shader-hero canvas.shader-bg { position: fixed; top: 0; left: 0; width: 100vw !important; height: 100vh !important; pointer-events: none }
.shader-hero > .et_pb_row { position: relative; z-index: 2 }
```

### Common Rules (both patterns):
- Script polling: `if(typeof THREE==='undefined'){setTimeout(fn,100);return;}`
- Use `THREE.ShaderMaterial` (not `RawShaderMaterial`) for WebGL2 compatibility
- Guard double-init: `if(el.dataset.init==='1')return;`
- Fragment shader as single-line string with `\\n` joins

### Tested Shader Variants
1. **Chromatic Wave** — animated light refraction lines (from 21st.dev WebGLShader)
2. **Shader Lines** — mosaic vertical color lines with pixelated look
3. **Shader Animation** — expanding diamond/ring patterns with RGB separation

### CSS Animated Gradient (Alternative to WebGL)
For multi-section pages where WebGL is overkill:
```css
@keyframes gradient-shift{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
.my-class.et_pb_section{background-image:linear-gradient(-45deg,#0f172a,#4f46e5,#7c3aed,#1e1b4b,#0f172a)!important;background-size:400% 400%!important;animation:gradient-shift 8s ease infinite}
```
Use `background-image` (not `background` shorthand) and chain `.et_pb_section` for specificity.

## Gooey Text Morphing

Pure CSS + JS effect using SVG `feColorMatrix` filter. No libraries needed.

### How it works:
1. SVG filter with `values="1 0 0 0 0 0 1 0 0 0 0 0 1 0 0 0 0 0 255 -140"` cranks alpha contrast
2. Two overlapping `<span>` elements blur/fade between words
3. The filter makes blur boundaries merge into organic blob shapes

### Implementation:
Use a Code module with the SVG filter + two spans + JS animation loop.
Words array is customizable: `var texts=['Design','Engineering','Is','Awesome'];`
Parameters: `morphTime` (transition duration), `cooldownTime` (pause between words).

## Section Video Background

Divi sections support native video backgrounds:
```json
"background": {"desktop": {"value": {"color": "#000", "video": {"mp4": "", "webm": "http://site.local/wp-content/uploads/video.webm"}}}}
```
