# DiviOps Visuals

WordPress plugin providing modern design effects for Divi 5 pages. CSS animations, glass morphism, Three.js WebGL shaders, and scroll-triggered effects.

## Name and installation identity

**DiviOps Visuals** is the Free companion previously displayed as **DiviOps
Design Library**. This is a display-name change: keep the installed
`diviops-design-library/` directory and `diviops-design-library.zip` package name.
Existing users do not need to deactivate, reinstall or migrate content for the
name change. Older delivered packages may still show the previous display name.
The PHP class, script handles, `ddl-*` classes and meta keys are unchanged.

If migrating the much older `Divi Design Library` installation with a different
plugin directory, reconcile that installation separately; this display change
is not an automatic directory migration.

## Version 1.0.0-beta.26 candidate

Displays the Free companion as **DiviOps Visuals**. Technical installation
identity, effects, Three.js core and GLB runtime assets are unchanged from the
published beta.25 package. This candidate changes version metadata and guidance;
source preparation does not establish publication or installation.

For customers receiving the private distribution's beta.24 copy, the upgrade
also includes the beta.25 foundation described below. Review existing Three.js
consumers against the migration notes before installation. This is not a new
paid plugin, an automatic migration or an automatic update feed.

### Foundation introduced in beta.25

The Free/GPL companion retains its existing effects and upgrades the local
Three.js core from r128 to official `three@0.186.1` (r186). An optional static GLB
profile uses that same core. Existing custom Three.js consumers need the migration
review below before installing; a matching plugin version alone does not establish
matching files for earlier candidate builds.

The engine, supported GLB profile and existing effects remain Free. Maintained
professional recipes and coordinated workflows are the proposed visual value
within **DiviOps Pro**; this plugin does not create another paid product or
require a commercial key. A new configuration dashboard, addon installer and
automatic update feed are not supplied by this naming change.

### Verification scope

The reviewed Avocado model with embedded PNGs passed real browser render
comparisons, fallback and disposal checks. The corrected texture-role guard also
passed eighteen refusal cases and three safe-reuse cases. An earlier candidate
passed a disposable native Divi 5.13.1 draft save/reopen check; that evidence predates
the texture-role correction and is not exact-package native qualification of this
build. No model or fixture is bundled here.

These checks do not establish arbitrary-model or JPEG rendering compatibility,
all legacy consumers, production performance, whole-site upgrade readiness or
creative approval. Installation and consumer migration require their own review.

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
qualification. That bounded proof does not establish whole-package or site
upgrade readiness for beta.25 or later packages.

### Three.js WebGL
- Official Three.js `0.186.1` core (r186), bundled locally (no CDN)
- Loaded when post meta `_divi_design_threejs` is exactly `'1'`, or when page content contains one of these case-sensitive markers: `webgl`, `THREE`, `shader`, `three.js`
- Use with Code module for custom shader heroes

Modern upstream packages no longer ship the old global build. This plugin's
`three.min.js` is a reproducible IIFE build of the unmodified official core
exports. It preserves the existing synchronous classic `window.THREE`
namespace, `threejs` WordPress handle, footer order and local asset path. The
script cache version includes the full patch version. It is not an r128 API
compatibility shim. The core excludes addons, WebGPU and TSL. The optional GLB
wrapper below is a separate addon using the same core; no WebGPU claim is made.

Build provenance, source integrity, output checksum and exact tool version are
recorded in `assets/js/three.build.json`; the complete upstream MIT license is
preserved in `assets/js/three.LICENSE.txt`. The addon manifest is
`assets/js/diviops-glb.build.json`. These records do not substitute for per-site
rendering checks.

#### Migration review before upgrading a site

- `WebGLRenderer` requires WebGL2. Keep readable static/SVG fallbacks when
  creation fails, on phones, for reduced motion and in Builder contexts.
- Replace `renderer.outputEncoding = THREE.sRGBEncoding` with
  `renderer.outputColorSpace = THREE.SRGBColorSpace`. Texture `encoding` is now
  `colorSpace`; color textures need the appropriate color-space annotation.
- Color management is enabled by default. CSS/hex colors are already converted
  from sRGB by `new THREE.Color(...)`; remove the old manual
  `convertSRGBToLinear()` on those inputs to avoid double conversion.
- Standard/physical lighting changed after r128. Inspect lights and materials
  visually; the upstream r155 guidance suggests multiplying legacy ambient,
  hemisphere, directional and light-map intensities by `Math.PI` as a starting
  point, while point/spot attenuation requires separate review.
- Remove or update hard-coded revision-128 gates, renamed/removed APIs and
  custom shader chunks. Test the actual saved Code modules, not only a new demo.
- Do not add a second Three.js runtime or enable a legacy global mode to hide
  migration errors. Keep client initialization conditional and clean up owned
  geometries, materials, listeners, observers and frame loops.

References: [official release](https://github.com/mrdoob/three.js/releases/tag/r186),
[official patch source](https://github.com/mrdoob/three.js/commit/9b4a2ac29c63ccb43fd51c5661f2f873ac2c39b8),
[migration guide](https://github.com/mrdoob/three.js/wiki/Migration-Guide),
[lighting migration](https://discourse.threejs.org/t/updates-to-lighting-in-three-js-r155/53733).

### Optional static GLB profile

Set page meta `_divi_design_glb` to exactly `static-v1` on an authorized singular
page. Core opt-in, content markers, filenames and generic `1` do not enable the
addon. Automatic loading is suppressed in the Visual Builder. A trusted script
may instead declare WordPress handle `diviops-glb` as a dependency; it depends on
`threejs`. Arrange execution after these synchronous footer scripts. Do not assume
the core is ready when an inline Code module first parses.

The classic `DiviOpsGLB` global exposes:

- `PROFILE` and `LIMITS`: fixed profile identity and admission limits.
- `inspectGLB(arrayBuffer)`: validates a copy and returns a summary without
  decoding, rendering or fetching. It is not a general glTF validator.
- `parseGLB(arrayBuffer, { sha256, source, license })`: copies the supplied bytes,
  validates them, checks the expected lowercase SHA256 and returns a promise for
  `{ scene, summary, provenance, dispose }`. Source and license are declarations
  from your asset review; the wrapper does not fetch the source URL or verify
  licensing for you. The helper refuses a missing, wrong or replaced core.

Obtain bytes through a separately reviewed acquisition path and enforce download
limits before buffering. Record the original source, exact hash, license and
required attribution for every model. There is no URL loader, asset catalog,
upload endpoint, automatic converter or finished viewer in this plugin.

The accepted subset is self-contained GLB v2 with one JSON and one BIN chunk,
glTF 2.0, one scene, static triangle meshes, core metallic/roughness PBR materials
and embedded PNG/JPEG images. Position/normal/tangent, UV0 and vertex colors
use the supported bounded accessor formats. JPEG is accepted by source preflight
but has no completed real-image qualification in this release's evidence.

Refused features include all URIs (including data URIs), optional or required
extensions, custom extras, animation, skins, morphs, sparse accessors, multiple
scenes, lines/points, cameras/lights, UV1, unsupported matrices and external
codecs such as Draco/KTX2/meshopt. Valid general glTF files can therefore be
unsupported here. Review any conversion and preserve attribution rather than
silently stripping features.

| Admission limit | Maximum |
| --- | ---: |
| GLB / JSON bytes | 8 MiB / 1 MiB |
| Nodes / meshes / primitives | 256 / 128 / 256 |
| Tree / JSON depth | 32 |
| Stored vertices | 300,000 |
| Stored and instanced triangles | 100,000 |
| Embedded images / texture edge | 16 / 2,048 pixels |
| Total image pixels / material texture-reference pixels | 16,777,216 each |

These limits are admission policy, not performance promises. An extension or
`.glb` filename does not prove compliance, and image headers do not prove decoding.

A texture index may be reused within color slots (base color/emissive) or within
data slots (normal/occlusion/metallic-roughness). Reuse across those roles is
refused across all materials before decoding. Separate texture indices can share
one image while retaining independent color spaces.

### Scene ownership and fallback

A consumer owns its host, renderer, animation loop, visibility/offscreen policy,
listeners and fallback. Keep essential headings, copy, links and controls in
accessible native HTML. Check capability and motion preferences before mounting;
use a readable static representation for missing WebGL2, initialization failure,
phone/reduced-motion policy and Builder contexts. Stop unnecessary hidden or
offscreen work, avoid duplicate mounts and handle failures without loading a
second engine. This plugin does not supply a generic scene scheduler.

The GLB result owns the resources it parsed. Remove `result.scene` from your
consumer scene before calling `result.dispose()`. Disposal is idempotent and
releases captured geometries, materials, textures and ImageBitmaps, including
nodes detached after parsing. It does not release resources added by the caller;
do not share parsed resources with longer-lived scenes. Dispose a late async
result without mounting if the consumer has already unmounted. Dispose your
renderer and all separately owned resources/listeners as well.

Before a site cutover, inventory saved Code modules, Theme Builder bodies,
drafts and custom plugin/theme scripts. Back up complete plugin files and saved
content, and prepare one reviewed runtime-plus-consumer rollback. The full
beta.25 package includes existing Image reveal and FAQ behavior; a core-only
comparison is not a full site qualification. Restore both plugin and consumers
if their paired upgrade fails. Publishing this package does not authorize a
site installation or a Home design change.

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

### Gooey Text Morphing
- SVG `feColorMatrix` filter for liquid text transitions
- IntersectionObserver for scroll-triggered class toggling
- No external dependencies

## How Effects Are Applied

1. **Via VB**: Add CSS classes in module Advanced > Custom Attributes
2. **Via MCP**: Use `module.decoration.attributes` to add classes programmatically
3. **Via freeForm CSS**: Section-level custom CSS for animations/keyframes

## Files

```text
diviops-design-library/
├── README.md
├── diviops-design-library.php
└── assets/
    ├── css/faq-toggle-a11y.css
    └── js/
        ├── design-fx.js
        ├── faq-toggle-a11y.js
        ├── three.min.js
        ├── three.LICENSE.txt
        ├── three.build.json
        ├── diviops-glb.min.js
        └── diviops-glb.build.json
```

## Conditional Loading

- FAQ JS/CSS requires explicit singular Divi page opt-in, outside the Visual
  Builder; the JS additionally requires the module class.
- The plugin prints its CSS from `wp_head`; `design-fx.js` loads on singular
  Divi builder pages.
- Core loads through `_divi_design_threejs = '1'` or the documented content
  markers. GLB opt-in also loads its core dependency.
- The GLB addon requires the exact `_divi_design_glb = 'static-v1'` page opt-in
  outside the Visual Builder, or explicit enqueue by a trusted consumer.
  Mobile or reduced-motion fallback does not by itself prevent asset downloads.
