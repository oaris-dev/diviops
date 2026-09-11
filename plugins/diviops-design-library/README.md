# DiviOps Design Library

WordPress plugin providing modern design effects for Divi 5 pages. CSS animations, glass morphism, Three.js WebGL shaders, and scroll-triggered effects.

## Upgrade From The Previous Plugin Name

1. Deactivate the old `Divi Design Library` plugin.
2. Install or copy `diviops-design-library/`.
3. Activate `DiviOps Design Library`.

## Candidate 1.0.0-beta.23

Prepares the default-off, selected native FAQ accessibility correction below.
This is the existing Free/GPL Design Library, not a paid Design Library Pro
artifact. Existing effects, Three.js r128 and native toggle timing are unchanged.
Candidate preparation does not publish or enable the correction on any site.

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
| `ddl-glass` | Glass morphism (dark) |
| `ddl-glass-light` | Glass morphism (light) |
| `ddl-hover-lift` | Lift on hover (-4px + shadow) |
| `ddl-gradient-animated` | Animated gradient background |
| `ddl-gradient-text` | Static gradient text |
| `ddl-gradient-text-animated` | Animated gradient text |
| `ddl-text-stroke` | Light text outline (stroke) |
| `ddl-text-stroke-dark` | Dark text outline (stroke) |
| `ddl-pulse-dot` | Pulsing green indicator |

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
