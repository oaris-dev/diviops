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
| `ddl-text-stroke` | Light text outline (stroke) |
| `ddl-text-stroke-dark` | Dark text outline (stroke) |

### Marquee (continuous scrolling)
| Class | Where | Purpose |
|-------|-------|---------|
| `ddl-marquee-track` | Outer container | Sets overflow hidden |
| `ddl-marquee-scroll` | Inner wrapper | Applies scroll animation (`translateX(0) → -50%`) |

#### Marquee composition rules

These apply to any seamless marquee built on a 2× duplicated track scrolled with `translateX(-50%)` — whether using the `ddl-marquee-*` classes above, hand-rolled CSS, or any other variant of the same loop math.

**1. Track `column-gap` / `row-gap` must be `0`.** Divi 5 Groups with `flexDirection: row` inherit a default 30px `column-gap` even when not explicitly set. The `ddl-marquee-scroll` class clears this automatically (`gap: 0 !important`); hand-rolled implementations must set it explicitly. Any non-zero gap on the track produces a `0.5 × gap` snap-back at every loop wrap because the placement of inter-item gaps is asymmetric (3 internal gaps in half 1, 3 internal gaps in half 2, 1 gap between the halves, 0 gap at the wrap point). Spacing between items must come from item-level padding/margin, not from the parent's `gap`. For hand-rolled cases: either set `module.decoration.layout.desktop.value.columnGap: "0px"` on the Group track or add `gap: 0 !important` to the freeForm CSS.

**2. The two halves must be visually identical, not just equal in width.** Width parity keeps the loop math correct, but every rendered visual property must mirror across halves: opacity, color, font weight, letter-spacing, transforms, hover styles. If you change a property on `Item 1` you must also change it on `Item 1 (dup)` — otherwise the rendered output cycles between two visual states at every wrap.

**3. Responsive overrides mirror across halves.** Any breakpoint override (tablet/phone font-size, padding, icon-size) must be applied to BOTH the original and the `(dup)`. Asymmetric breakpoint values break the loop only at that viewport width, which is easy to miss without explicit breakpoint testing.

**4. Motion controls (WCAG 2.2.2 + 2.3.3) — pause, reduce-motion, AND content-reachability.** The motion behavior is one half of the a11y picture; content reachability is the other. A track with `overflow: hidden` that's wider than the viewport hides unique items behind the clip edge — and freezing the animation under `prefers-reduced-motion` would leave those items permanently unreachable unless a fallback exposes them.

The `ddl-marquee-*` helper covers all three:
- `animation-play-state: paused` on `:hover` / `:focus-within` (interactive pause)
- `animation: none` on the inner scroll wrapper under `prefers-reduced-motion: reduce` (motion gone)
- `overflow-x: auto` on the outer track under `prefers-reduced-motion: reduce` (the critical fallback — users can horizontally scroll to reach every unique item, even when content exceeds viewport width)

Hand-rolled marquees must include the equivalent CSS:

```css
.ddl-marquee-scroll:hover,
.ddl-marquee-scroll:focus-within { animation-play-state: paused; }
@media (prefers-reduced-motion: reduce) {
  .ddl-marquee-track  { overflow-x: auto; }   /* without this, unique items past viewport are unreachable */
  .ddl-marquee-scroll { animation: none; }
}
```

Replace `.ddl-marquee-track` and `.ddl-marquee-scroll` with whatever classes your hand-rolled wrapper uses.

Plus screen-reader hygiene via `module.decoration.attributes.desktop.value.attributes[]` (helper class does NOT add these — apply them manually in both cases):
- All `(dup)` text/icon modules → `aria-hidden="true"` (prevents SR reading the same phrase twice)
- All decorative icons → `aria-hidden="true"`
- Half-1 originals → leave readable, no attribute change

Skip: `role="marquee"` (deprecated), `aria-live="off"` (default, noise), `role="presentation"` on the track (would strip semantics from half-1 children).

**5. Oversized typography → set `line-height ≥ 1.3em`.** When marquees use very large type (e.g. 100px+) inside an `overflow: hidden` track, glyph ascenders can clip at the padding-box edge. A generous `line-height` on text items bakes vertical breathing room into each item without changing track structure.

### VB Safety
`ddl-animate` starts elements at `opacity: 0` — invisible in the Visual Builder where IntersectionObserver may not fire. The plugin includes a VB override that resets `opacity: 1 !important` and `animation: none !important` inside VB contexts (`#et-fb-app`, `.et-fb`, `body.et-fb`). Intentional transforms are NOT reset — only visibility and animation playback.

### When to Use ddl-* vs Divi Native
- **Divi native `animation`**: simple entrance effects (fade, slide, zoom) — 80% of cases
- **`ddl-*` classes**: staggered siblings, glass morphism, animated gradients, marquee
- **Divi native `scroll`**: parallax, scroll-driven opacity/scale/rotation
- **Three.js**: WebGL backgrounds — section-contained (multi-section) or full-page (single-section)

## Three.js Integration

### Setup
Three.js r128 is bundled locally in the plugin. It loads when post meta `_divi_design_threejs` is exactly `'1'`, or when page content contains one of these case-sensitive markers: `webgl`, `THREE`, `shader`, `three.js`.

### One canonical pattern: canvas absolute-scoped to its section <!-- VB-verified 2026-06-03 -->

There is **one correct way** to put a full-section WebGL background in Divi 5, and it works for both single-section heroes and multi-section pages. The canvas is `position: absolute` inside a `position: relative` section, sized to the **section** (not the viewport), so it fills the hero and scrolls away with it.

**Structure — carry the canvas via the first Row's `htmlBefore`:**
```
Section (class: my-hero — position:relative;overflow:hidden via freeForm)
├── Row (htmlBefore: <canvas> + <script>)   ← injects as a DIRECT child of the section
│   └── Column
│       └── Content modules (heading, text, button …)
```

Row `htmlBefore` injects the canvas as a **direct child of `.et_pb_section`**, so `el.closest('.et_pb_section')` resolves and the canvas's containing block is the section. This is the load-bearing reason to use the Row (not the Section, not a Code module — see traps below).

**Canonical `htmlBefore` attr path:** `module.advanced.html.desktop.value.htmlBefore` — i.e. `html → desktop → value → htmlBefore`. NOT `html.htmlBefore.desktop.value` (inverted shape saves clean + validates but renders nothing — a silent no-op).

**Section freeForm CSS:**
```css
.my-hero.et_pb_section { position: relative; overflow: hidden }
.my-hero canvas#my-canvas { position: absolute; top: 0; left: 0; width: 100%!important; height: 100%!important; pointer-events: none; z-index: 0; display: block }
.my-hero > .et_pb_row { position: relative; z-index: 2 }   /* content above the canvas */
```
> **`overflow: hidden` on the section is REQUIRED here** (it clips the absolute canvas to the section box). This reverses older guidance — it was only "don't" for the obsolete viewport-fill approach.

**Script — size off the SECTION + ResizeObserver (never the window):**
```js
var el = document.querySelector('canvas#my-canvas'); // the canvas this script injected
var host = el.closest('.et_pb_section');             // the canvas's section
function size(){ renderer.setSize(host.offsetWidth, host.offsetHeight, false); }
size();
new ResizeObserver(size).observe(host);              // tracks section growth, not just window resize
window.addEventListener('resize', size);
```
> Resolve `el` from the DOM (the canvas you just injected) — don't assume an ambient `el`. `document.currentScript` also works since the inline `<script>` runs during parse, but a `querySelector` on the canvas id is what the verified pages use and survives being moved out of an inline context.

That's it. Single-section hero or 10-section page — the same pattern. You never need `position: fixed`.

### ⚠️ Anti-pattern: `position: fixed` + `100vw/100vh` (the #1 broken-shader bug)

Legacy/AI-authored shader pages frequently do this:
```css
/* DON'T */
.hero canvas { position: fixed; top: 0; left: 0; width: 100vw!important; height: 100vh!important }
```
with the renderer sized off `window.innerWidth/innerHeight`. It *looks* fine on a single screen, which is why it gets reached for — but it's wrong:

- **Glued to the viewport.** The shader does not scroll with its section; it stays pinned to the screen.
- **Bleeds over later sections.** On any page with content below the hero, the fixed canvas paints over that content as you scroll (verified: a fixed hero shader covering the section beneath it).
- **Sized to the window, not the section.**
- **Reads as an unwanted "parallax".** A common variant pairs the `fixed` canvas with `clip-path: inset(0 0 0 0)` (and/or `background-attachment: fixed`) on the hero. The clip windows the *stationary* canvas to the *moving* hero box, so on scroll the shader appears to slide underneath the content — a parallax effect nobody asked for. Same root cause (`fixed` + window sizing), just dressed up; the fix is the same (verified on `effects-…-hero` page id 159, 2026-06-03).

**Repair recipe (verified on 4 legacy pages, 2026-06-03):**
1. `position: fixed → absolute`; `100vw/100vh → 100%`; add `position: relative; overflow: hidden` to the section. **Also drop any `clip-path: inset(…)` and `background-attachment: fixed`** — once the canvas is `absolute` and section-scoped, the clip/attachment hacks are exactly what produced the parallax artifact.
2. Renderer: size off `host = el.closest('.et_pb_section')` (offsetWidth/Height) instead of `window`; add a `ResizeObserver(host)`.
3. **Gotcha that step 1 exposes:** `fixed` ignores all ancestors, so it was *masking* a nesting problem. If the canvas is carried by a **Code module inside a column**, switching to `absolute` makes it anchor to `.et_pb_code_inner` — which Divi renders `position: relative` and 0-height — so the canvas collapses to ~0 height / column width instead of the section. Fix by **moving the canvas to the Row's `htmlBefore`** (direct section child — preferred), or, if you must keep it in a Code module, neutralize the intermediate wrappers:
   ```css
   .my-hero .et_pb_column, .my-hero .et_pb_code, .my-hero .et_pb_code_inner { position: static!important }
   ```

### Carrier traps (where the canvas lands depends on the host)

A container's `htmlBefore` does **not** always inject the canvas inside that container:

| Carrier | Canvas lands… | Full-section shader? |
|---|---|---|
| **Row `htmlBefore`** | direct child of `.et_pb_section` | ✅ BEST — fills section, scopes correctly |
| **Column `htmlBefore`** | inside the **Row's flex container, as a sibling of the columns** — NOT inside the column | ⚠️ breaks column-scoped use (see card section); OK only if you want a row-level overlay |
| **Section `htmlBefore`** | **outside** the section (into `.et_builder_inner_content`) | ❌ orphan canvas above the page; `closest('.et_pb_section')` misses |
| **Code module inside column** | inside `.et_pb_code_inner` (relative, 0-height) | ⚠️ works only if you neutralize wrappers (see anti-pattern gotcha) |

### Card / sub-element shaders (scope a shader to a card, not a section) <!-- VB-verified 2026-06-03 -->

To run an independent shader inside a **card** (a column or a Group), the canvas must be inside that box and scoped to it. Column `htmlBefore` does NOT work — it injects the canvas into the row's flex container (sibling of the columns), so `closest('.et_pb_column')` returns null (shader never boots) AND the stray canvas becomes an extra flex item that collapses the cards. **Carry the canvas with a `divi/code` module placed FIRST inside the card** — that lands it inside `.et_pb_column` / `.et_pb_group`, scoped to the card.

```
Row (display:flex)                        ← flex row so flexType sizing applies
├── Column / Group  (class: card — position:relative;overflow:hidden;flexType 8_24)
│   ├── Code module: <canvas> + <script host=closest('.et_pb_column' | '.et_pb_group')>
│   ├── (neutralize the code wrapper so it adds 0 height)
│   └── content (eyebrow, title, body)
```
```css
.cards .card { position: relative!important; overflow: hidden; min-height: 360px }
.cards .card .et_pb_code, .cards .card .et_pb_code_inner { position: static!important; height: 0!important; flex: 0 0 0!important }
.cards .card canvas { position: absolute!important; inset: 0; width: 100%!important; height: 100%!important; pointer-events: none; z-index: 0 }
.cards .card .et_pb_text, .cards .card .et_pb_heading { position: relative; z-index: 2 }  /* + a scrim for legibility */
```
The shader's `host = el.closest('.et_pb_column')` (or `.et_pb_group`); size off `host.offsetWidth/Height`. A Group-based card is one self-contained, library-saveable unit (shader + copy travel together). Each card needs a **distinct canvas id** and its own fragment-shader string — a shared id collides (only the first boots).

### Responsive: flip direction AND width at the SAME breakpoint <!-- VB-verified 2026-06-03 -->

For multi-card grids (flex row + `flexType` children), a phone-only override is NOT enough. Divi breakpoints: tablet = 768–980px, phone = ≤767px. If the row's `flexDirection: column` is set at **tablet** but each child's full-width `flexType: 24_24` is only at **phone**, the 768–980px band renders stacked-but-1/3-narrow (stranded slivers). Set BOTH at the same breakpoint:
```jsonc
// parent row: flip direction at tablet — layout lives under module.decoration
"module": { "decoration": {
  "layout": { "desktop": {"value":{"display":"flex","flexDirection":"row"}}, "tablet": {"value":{"flexDirection":"column"}} }
} }
// each child column/Group: full width at tablet (not just phone) — sizing lives under module.decoration
"module": { "decoration": {
  "sizing": { "desktop": {"value":{"flexType":"8_24"}}, "tablet": {"value":{"flexType":"24_24"}}, "phone": {"value":{"flexType":"24_24"}} }
} }
```
> Both keys are nested under `module.decoration` — i.e. the real paths are `module.decoration.layout.<bp>.value.flexDirection` and `module.decoration.sizing.<bp>.value.flexType`. Top-level `layout`/`sizing` keys are silently ignored.
Plus a belt-and-suspenders net: `@media(max-width:980px){ .card{ width:100%!important; max-width:100%!important } }`.

### `<script>` survival + distribution caveat

The inline `<script>` in a Code module or `htmlBefore` is stored verbatim **only if the saving user has `unfiltered_html`** (admin, non-multisite). For lower-capability editors, `wp_kses_post` strips `<script>` on save and the canvas sits blank. The inline carrier is the only approach that's identical on local and remote (MCP) sites, so it's the documented default. A parameterized loader (one enqueued script + `data-effect` registry, no inline `<script>`) would be capability-independent and reusable, but that runtime belongs in the Design Library plugin — not a per-page inline script or a local-only mu-plugin. (Deferred.)

### Common rules (all patterns)
- Script polling: `if(typeof THREE==='undefined'){setTimeout(fn,100);return;}`
- Guard double-init: `if(el.dataset.init==='1')return; el.dataset.init='1';`
- Use `THREE.ShaderMaterial` (not `RawShaderMaterial`) for WebGL2 compatibility
- Fragment shader as a string with `\n` line joins (e.g. `[...lines].join(String.fromCharCode(10))`)
- Size off the **section/card host**, never `window` — and observe it with `ResizeObserver`

### Tested shader variants
1. **Chromatic Wave / Aurora** — layered sine line-field, blue-violet refraction
2. **Shader Lines** — mosaic vertical color streaks with chromatic fringing
3. **Shader Animation** — expanding diamond/ring patterns with RGB separation
4. **WebGL Arc** — RGB-split glowing horizon arc
5. **Plasma** — interfering sine plasma (teal/cyan/magenta)
6. **Metaballs / Gradient Orbs** — drifting soft blobs (warm amber/pink)

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
