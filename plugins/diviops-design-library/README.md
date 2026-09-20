# DiviOps Design Library

WordPress plugin providing modern design effects for Divi 5 pages. CSS animations, glass morphism, Three.js WebGL shaders, and scroll-triggered effects.

## Upgrade From The Previous Plugin Name

1. Deactivate the old `Divi Design Library` plugin.
2. Install or copy `diviops-design-library/`.
3. Activate `DiviOps Design Library`.

## Candidate 1.0.0-beta.24

Following the published beta.23 release, beta.24 adds the opt-in native Image
reveal described below. This remains the Free/GPL Design Library, not a paid
Design Library Pro artifact. The existing FAQ correction, other effects,
Three.js r128 and native toggle timing are unchanged. Candidate source
preparation does not publish or enable the effect on any site. The complete
beta.24 package is not yet qualified; the accepted bounded effect evidence
below is not whole-package release qualification.

## What It Provides

### CSS Classes (add via VB Advanced > CSS Classes)

| Class | Effect |
|-------|--------|
| `ddl-animate ddl-fade-up` | Fade in from below |
| `ddl-animate ddl-fade-in` | Fade in |
| `ddl-animate ddl-scale-in` | Scale up from 90% |
| `ddl-animate ddl-slide-left` | Slide in from left |
| `ddl-animate ddl-slide-right` | Slide in from right |
| `ddl-delay-1` to `ddl-delay-6` | Stagger delays (0.1s increments) |
| `ddl-image-reveal` | Native Image only: one 650ms left-to-right wipe when loaded and visible |
| `ddl-glass` | Glass morphism (dark) |
| `ddl-glass-light` | Glass morphism (light) |
| `ddl-hover-lift` | Lift on hover (-4px + shadow) |
| `ddl-gradient-animated` | Animated gradient background |
| `ddl-gradient-text` | Static gradient text |
| `ddl-gradient-text-animated` | Animated gradient text |
| `ddl-text-stroke` | Light text outline (stroke) |
| `ddl-text-stroke-dark` | Dark text outline (stroke) |
| `ddl-pulse-dot` | Pulsing green indicator |

### Native Image Reveal

Use `ddl-image-reveal` on a below-fold native Divi Image module (`.et_pb_image`).
In VB, add a `class` custom attribute targeting the main module. Programmatically,
append this entry to `module.decoration.attributes.desktop.value.attributes[]`
(use a unique `id`, preserving existing attributes):

```json
{
  "id": "image-reveal-class",
  "name": "class",
  "value": "ddl-image-reveal",
  "adminLabel": "Image reveal",
  "targetElement": "main"
}
```

Do not use the block `className` attribute. Do not combine with `ddl-animate`,
stagger classes, or Divi native entrance animation. This recipe is not for
backgrounds, galleries, Blurbs, Fullwidth Images, or arbitrary wrappers.

The actual image receives one horizontal clip-path wipe after successful native
image load and viewport entry. Native `src`, `srcset`, `alt`, links, focus wrappers
and layout are untouched; there is no zoom, overlay or decorative control. The
650ms non-looping animation needs no pause UI. It does not repeat on re-entry;
observers and listeners are cleaned up when finished. Failed images retain the
normal native failure with no retries.

The baseline is fully visible, with no hidden pending state. Missing JavaScript,
IntersectionObserver or clip-path support leaves a static image. Reduced motion
skips initialization; CSS immediately cancels an active wipe if the preference
changes. Visual Builder contexts (`#et-fb-app` / `.et-fb`) stay static and visible.
Modules added dynamically in VB are unsupported and remain static; reload the
frontend to initialize newly saved modules.

Evidence scope: accepted bounded native Divi 5.13 proof covers desktop, phone,
reduced motion and a Visual Builder save round trip. That proof used beta.22
plus the image-reveal effect delta, not the complete beta.24 package. Synthetic
fixtures provide additional isolated checks, not broader live compatibility
qualification. The complete beta.24 package is not yet qualified; the bounded
proof does not establish whole-package upgrade or release readiness.

Run the isolated browser check with an existing installed Chromium and the
repository's development `playwright-core` (no WordPress or downloads):

```sh
IMAGE_REVEAL_OUTPUT=/absolute/fresh/evidence node scripts/test-image-reveal.mjs
```

`IMAGE_REVEAL_CHROMIUM` and `IMAGE_REVEAL_PLAYWRIGHT_MODULE` select existing local
executables/modules. The default image is a generated four-color test bitmap;
`IMAGE_REVEAL_IMAGE=/absolute/local-image.webp` (or PNG, matching the fixture's
1672:700 aspect ratio) supplies retained visual-review media. The check emits an
offline preview and desktop/phone captures. It covers one natural completion,
once-only behavior, delayed/failed images, preserved attributes, linked-image
focus, reduced-motion changes, missing APIs/JS and synthetic Builder contexts.
Private media is not shipped; the existing browser CI job uses the bitmap.

### Three.js WebGL
- Three.js r128 bundled locally (no CDN)
- Loaded when post meta `_divi_design_threejs` is exactly `'1'`, or when page content contains one of these case-sensitive markers: `webgl`, `THREE`, `shader`, `three.js`
- Use with Code module for custom shader heroes

### Selected Native FAQ Accessibility

Default off. On a singular Divi builder page, set post meta
`_divi_design_faq_a11y` to exactly the string `'1'`, then add the CSS class
`ddl-faq-a11y` to each native Toggle module that needs the correction.
Both opt-ins are required. No settings page or new MCP tool is added.

The selected heading retains its content and receives a real button with
keyboard focus, `aria-controls` and expanded state following Divi's settled
open/close classes. Divi still owns clicks and its unchanged animation duration.
The asset depends on Divi's `divi-script-library-toggle` script. Known
Accessibility Tweaks toggle attributes are normalized on selected modules,
including delayed addon initialization; unselected modules are untouched.

Static native Toggles only: no Accordion, Visual Builder, interactive title
descendants or unrecognized existing control semantics. Unsupported markup or
missing native functions is left alone. This is not an accessibility audit or
a general compatibility layer. Do not opt in modules managed by another
control implementation.

Isolated fixtures cover tracked Divi 5.12.1 (native script identical to retained
5.11.0/5.11.1) and the retained Accessibility Tweaks 2.1.1 script. A bounded
staging draft on WordPress 7.1, Divi 5.11.0, Accessibility Tweaks 2.1.1 and
Sidebar 2.1.1 passed desktop/phone keyboard, pointer, focus and settled-state
checks, plus one native Visual Builder save preserving decoded attributes.
The test used reviewed source files, not a whole-plugin candidate upgrade;
original content and files were restored. It is not universal screen-reader
qualification or a compatibility claim for other version combinations.

To disable, remove the page meta or module class and reload the page. This
restores the native/addon baseline, including any original accessibility bug;
it does not mutate stored module content. Retire the correction when the same
fixture passes with an upstream fix and without this asset.

Repository checks (the scripts and vendor fixtures are not in this plugin):

```sh
php tests/design-library-faq-enqueue-test.php
FAQ_OUTPUT=/absolute/fresh/evidence node scripts/test-faq-a11y.mjs
```

The browser check uses the repository's existing `playwright-core` development
dependency and installed Chromium. `FAQ_CHROMIUM` and `FAQ_PLAYWRIGHT_MODULE`
can select existing local executables/modules; nothing is downloaded by the
test. Supply `FAQ_ADDON=/absolute/path/accessibility-tweaks.js` for the complete
pinned addon matrix; otherwise only native cases run and the report says so.
The addon is checksum-verified, not bundled. `--baseline` records the original
behavior instead of testing the correction. All requests are fulfilled with
local assets, with unexpected requests blocked.

### Gooey Text Morphing
- SVG `feColorMatrix` filter for liquid text transitions
- IntersectionObserver for scroll-triggered class toggling
- No external dependencies

## How Effects Are Applied

1. **Via VB**: Add CSS classes in module Advanced > Custom Attributes
2. **Via MCP**: Use `module.decoration.attributes` to add classes programmatically
3. **Via freeForm CSS**: Section-level custom CSS for animations/keyframes

## Files

```
diviops-design-library/
├── diviops-design-library.php    # Plugin registration, CSS output, script enqueueing
└── assets/
    └── js/
        ├── design-fx.js       # IntersectionObserver + gooey SVG injection
        ├── faq-toggle-a11y.js # Explicit page/module FAQ opt-in (CSS in assets/css/)
        └── three.min.js       # Three.js r128 (bundled)
```

## Conditional Loading
- FAQ JS/CSS loads only for the explicit singular Divi page opt-in, outside the Visual Builder; the JS additionally requires the module class
- CSS is always printed (lightweight, no external requests)
- `design-fx.js` loads on all frontend pages
- `three.min.js` only loads through the explicit `_divi_design_threejs = '1'` post-meta opt-in or the documented content markers
