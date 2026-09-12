# Design Guide — Copy-Paste Patterns

Patterns for generating high-quality Divi 5 pages. Use alongside [module-formats.md](module-formats.md) for exact attr paths and [presets.md](presets.md) for design tokens.

## Design Thinking — Before You Code

Before generating any page, make three decisions:

1. **Aesthetic direction** — Choose a clear visual identity: dark/moody, light/airy, brutalist, glassmorphism, editorial, organic, playful, luxury. Commit to it fully. Bold maximalism and refined minimalism both work — the key is intentionality.

2. **One memorable element** — Every page needs one thing someone will remember: an animated hero, a striking color contrast, an unexpected layout, a scroll-triggered reveal. Design around this anchor.

3. **Variety** — For a new visual direction, vary color families, heading weights, section rhythms and card styles intentionally. When extending an approved existing site/page, preserve recurring styles unless the brief calls for an explicit intentional variation; novelty alone is not a reason to restyle them.

**Design quality checklist:**
- Typography hierarchy is clear (H1 > H2 > H3 visually distinct, not just smaller)
- Color palette has a dominant + accent, not evenly distributed
- Spacing creates rhythm (generous whitespace between sections, tighter within)
- Animation is purposeful (entrance cascade on hero, subtle scroll effects on content, not random)
- Responsive works (stack on mobile, reduce sizes on tablet)

Use `oa` design tokens ([presets.md](presets.md)) for consistent sizing, spacing, and colors. Override per-instance only for deliberate variation.

### Authoring review handoff

Use these portable roles sequentially in one agent, or hand off between available
specialists; no particular model or runtime is required. This is generic Free
native authoring guidance, with inline values or existing tokens/presets, not a
paid workflow prerequisite.

| Role | Handoff |
| --- | --- |
| Designer | Identify the approved reference page/section and permitted changes. Compare recurring typography, buttons and eyebrows; record the reference values or explicit intentional variations, plus which native layer owns each gap. |
| Builder | Make only scoped, native-first page edits; preserve default/shared presets. Hand off the changed section/module labels, persisted-attribute readback and unresolved visual questions. A page correction does not authorize shared-style mutation. |
| Reviewer | Compare the result with the brief/reference using the checks below. Return acceptance, concrete corrections for the Builder, or pending checks; do not silently edit during review. |

- **Section boundaries:** Inspect adjoining section bottom + top padding at desktop/phone, plus tablet when it has distinct values or behavior, together with nested row/column/module padding, margins and layout gaps. Judge the cumulative distance, not each value in isolation; identify the layer to correct.
- **Repeated components:** Compare icon-to-heading and heading-to-body gaps across every repeated group and nearby related content. Check divider presence, thickness, rendered width, horizontal insets, color and space above/below; parent gaps can compound with internal spacing.
- **Reference consistency:** Compare recurring font size/weight/color, button geometry/states and eyebrow treatment with the approved page, not just semantic tags or preset names. Keep a difference only when it serves the agreed hierarchy; do not impose a universal style recipe.
- **New links:** Inspect the actual new anchor in normal and hover states, including typography, color/decoration and alignment within its container. A correct href or paragraph style readback does not show that a link has the intended treatment; check inherited body typography and paragraph alignment explicitly.

Report structural validation/persisted readback separately from visual acceptance.
When authorized, inspect rendered desktop/phone output (plus tablet when it has
distinct values or behavior) and actual hover behavior;
HTML previews alone are not live interaction proof. If access or authorization is
missing, name the exact page/elements and mark those checks pending. Report any
VB edit/save check separately; do not infer it from frontend appearance.

#### Bad/good review example: agenda and preparation

Illustrative authoring review, not an executed proof, renderer-bug claim or
universal pixel/color threshold. The page has three agenda groups plus preparation.

**Bad:** "Blocks validate and readback matches, so the page passes." This misses
adjoining section padding of 84px + 84px on desktop and 52px + 52px on phone;
the agenda groups have a 1px `#e2e8f0` divider, 30px top padding, 14px internal
icon/heading/body gaps and a 26px parent gap, while preparation lacks the divider
and uses 26px internal gaps. It also accepts accidental 18px grey eyebrows where
the approved page uses 13px, weight 700, blue, and a new link inheriting body
typography and the paragraph's unintended alignment without checking its hover.

**Good:** "Structural/readback checks pass; visual acceptance needs corrections
or explicit approval of these differences." Propose a page-local boundary
correction such as 26px + 0px (26px total rather than 168px desktop / 104px phone),
with desktop/phone review. Match preparation's internal gaps from 26px to 14px and
add the matching agenda divider, checking rendered width/insets across all four
groups; a different treatment needs explicit design intent. Reuse the approved
eyebrow treatment and inspect the new link's own normal/hover typography and
alignment. After the Builder's scoped
correction, recheck readback and rendered results; retain any unperformed live
checks as pending rather than claiming a visual pass.

### Contrast and Readability

Every text element must be readable against its background. Getting this wrong is the most common design failure.

**Rules:**
1. **Dark background** → white or light text (`#ffffff`, `neutral-100`–`neutral-300`). Never use mid-grays above `neutral-400` (too dark to read).
2. **Light background** → dark text (`neutral-600`–`neutral-900`). Never use light grays below `neutral-400` (too light to read).
3. **Gradient backgrounds** — evaluate contrast against the *lightest* stop color (worst case). If the gradient goes `#0f172a` → `#334155`, check text readability against `#334155`.
4. **Semi-transparent text** — never go below `rgba(x,x,x,0.5)` for body text. Kickers and secondary labels can go to `0.4` minimum.
5. **Button text vs button background** — always verify. A `#6366f1` button needs white text, not dark text.
6. **Hover states** — check contrast on hover too. A white button that hovers to light yellow with white text becomes unreadable.
7. **Image/video backgrounds** — add an overlay (`rgba(0,0,0,0.4)`+) or use text shadow before placing text on images.

**Quick reference:**

| Background | Heading color | Body color | Accent/kicker |
|------------|--------------|------------|---------------|
| Dark (`neutral-800`+) | `#ffffff` | `neutral-200`–`neutral-300` | `primary-400` or lighter |
| Light (`neutral-50`–`neutral-200`) | `neutral-900` | `neutral-600`–`neutral-700` | `primary-600` or darker |
| Gradient | Check against lightest stop | Same rule | Same rule |
| Image | White + overlay | Short labels only; avoid body text even with overlay | White or accent with overlay |

### Style-to-Token Mapping

Pick an aesthetic direction, then use its token column. **Do NOT default to Dark Minimal every time.**

| Token | Dark Minimal | Light Airy | Bold Vibrant | Editorial | Glassmorphism |
|-------|-------------|------------|-------------|-----------|---------------|
| **Section bg** | `neutral-900` | `neutral-50` | `primary-800` | `white` | `neutral-950` |
| **Alt section bg** | `neutral-800` | `white` | `primary-900` | `neutral-50` | `neutral-900` |
| **Heading color** | `white` | `neutral-900` | `white` | `neutral-900` | `white` |
| **Body color** | `neutral-200` | `neutral-700` | `neutral-100` | `neutral-600` | `neutral-300` |
| **Accent** | `primary-400` | `primary-500` | `secondary-500` | `primary-600` | `primary-300` |
| **Accent hover** | `primary-300` | `primary-600` | `secondary-400` | `primary-500` | `primary-200` |
| **Heading preset** | oa Heading H1 + oa Heading Light | oa Heading H1 | oa Heading H1 + oa Heading Light | oa Heading H1 Small | oa Heading H1 + oa Heading Light |
| **Body preset** | oa Text Standard + oa Text Light | oa Text Standard | oa Text Standard + oa Text Light | oa Text Big | oa Text Standard + oa Text Light |
| **Button** | oa Button Primary | oa Button Primary | oa Button Secondary | oa Button Primary Outline | oa Button White |
| **Alt button** | oa Button White | oa Button Primary Outline | oa Button White | oa Button Primary | oa Button Primary Outline |
| **Radius** | `rounded-xl` | `rounded-2xl` | `rounded-lg` | `rounded` | `rounded-3xl` |
| **Card border** | 1px `neutral-700` | 1px `neutral-200` | none | 1px `neutral-300` | 1px `neutral-700` |
| **Card bg** | `white` @ 5% opacity | `white` | `primary-700` | `neutral-50` | `white` @ 5% opacity |
| **Section padding** | `space-16` | `space-16` | `space-12` | `space-16` | `space-12` |
| **Stack light preset** | Yes | No | Yes | No | Yes |
| **Module preset** | oa Dark Section | — | — | — | — |

The table uses shorthand token names (e.g., `primary-400`, `rounded-xl`). To get full token IDs, add the `oa` prefix: colors → `gcid-oa-{name}`, numbers → `gvid-oa-{name}`. See [presets.md](presets.md) for the complete ID list and preset UUIDs.

**How to use**: After choosing an aesthetic, read down its column for every design decision. Mix aesthetics sparingly — e.g., a mostly Light Airy page with one Dark Minimal CTA section for contrast.

## Multi-Column Layout (Group-Based)

**Use Groups for multi-column layouts, not Row with multiple columns.** Divi's column CSS conflicts with `display: flex` on rows, causing columns to stack.

### 3-Column Card Grid

Parent Group — flex row container:
```jsonc
// Outer Group: flex row with percentage gap
"module": {
  "decoration": {
    "layout": {
      "desktop": {"value": {"display": "flex", "flexDirection": "row", "alignItems": "stretch", "columnGap": "3.5%", "rowGap": "24px", "flexWrap": "wrap"}},
      "phone": {"value": {"display": "flex", "flexDirection": "column", "alignItems": "stretch", "rowGap": "20px"}}
    }
  }
}
```

Each child Group — sized via `flexType`:
```jsonc
// Each card: flexType controls width (8/24 = 33%)
"module": {
  "decoration": {
    "layout": {"desktop": {"value": {"display": "flex", "flexDirection": "column", "rowGap": "16px"}}},
    "sizing": {"desktop": {"value": {"flexType": "8_24"}}, "phone": {"value": {"flexType": "24_24", "width": "100%", "maxWidth": "100%"}}},
    "background": {"desktop": {"value": {"color": "rgba(255,255,255,0.05)"}, "hover": {"color": "rgba(255,255,255,0.08)"}}},
    "border": {"desktop": {"value": {"radius": {"topLeft": "16px", "topRight": "16px", "bottomLeft": "16px", "bottomRight": "16px", "sync": "on"}, "styles": {"all": {"width": "1px", "color": "rgba(255,255,255,0.1)"}}}}},
    "spacing": {"desktop": {"value": {"padding": {"top": "32px", "bottom": "32px", "left": "32px", "right": "32px", "syncVertical": "on", "syncHorizontal": "on"}}}},
    "animation": {"desktop": {"value": {"style": "fade", "delay": "0ms"}}}
  }
}
```

### Responsive card-grid rule *(verified 2026-05-28)*

Desktop multi-column Groups must include explicit phone stacking. Block validation catches malformed markup and known path traps, but it cannot prove that cards are visually full-width on a phone viewport.

- Parent Group phone layout: `display: "flex"`, `flexDirection: "column"`, `alignItems: "stretch"`, and a sensible `rowGap`.
- Child card Groups phone sizing: `module.decoration.sizing.phone.value.flexType = "24_24"`; add `width: "100%"` and `maxWidth: "100%"` when the card also carries width or max-width constraints.
- Verify the saved page in a mobile viewport after `diviops_validate_blocks` passes. Do not treat validator success as responsive acceptance.

### Column sizing reference <!-- VB-verified: 2026-03-21 -->

Divi uses a **24-unit grid** for flex child sizing. Path: `module.decoration.sizing.desktop.value.flexType`

| flexType | Fraction | Width | VB label |
|----------|----------|-------|----------|
| `"4_24"` | 4/24 | ~17% | 1/6 |
| `"6_24"` | 6/24 | 25% | 1/4 |
| `"8_24"` | 8/24 | ~33% | 1/3 |
| `"12_24"` | 12/24 | 50% | 1/2 |
| `"16_24"` | 16/24 | ~67% | 2/3 |
| `"18_24"` | 18/24 | 75% | 3/4 |
| `"24_24"` | 24/24 | 100% | Full |

Common layouts:

| Layout | Child flexTypes |
|--------|----------------|
| 3 equal columns | `"8_24"` + `"8_24"` + `"8_24"` |
| 2 equal columns | `"12_24"` + `"12_24"` |
| 4 equal columns | `"6_24"` × 4 |
| Sidebar + content | `"8_24"` + `"16_24"` |
| Content + sidebar | `"16_24"` + `"8_24"` |

> **Note**: `flexType` handles gap-aware sizing internally on desktop grids — do NOT also set desktop `width` or `flexBasis`. Use `flexType` alone for column sizing; add phone `width` / `maxWidth` only when clearing prior width constraints for mobile stacking.

### Section/Row/Column as simple containers

Always keep these minimal when using Group layouts:
```jsonc
// Section, Row, Column — just display block, no flex
"module": {"decoration": {"layout": {"desktop": {"value": {"display": "block"}}}}}
```

### Centering elements with maxWidth

Any module with `maxWidth` in a block parent aligns left by default. Add auto margins:
```jsonc
"spacing": {"desktop": {"value": {"margin": {"left": "auto", "right": "auto", "syncHorizontal": "off"}}}}
```

## Native-First Layout Fixes (advisory)

For Divi-owned layout issues, map the behavior to native Divi settings before adding CSS. CSS is the last resort when the native setting cannot express the behavior; broad selectors and `!important` require an explicit rationale in the work notes.

### Theme Builder footer bottom crop/tightness *(verified 2026-05-28)*

If a Global Footer looks cropped or too tight at the bottom, first change the root footer Section's native bottom padding:

- VB path (operator mapping; not stamped VB-verified here): `Theme Builder > Global Footer > Section: Global Footer > Design > Spacing > Padding > Bottom`
- Attrs: `module.decoration.spacing.desktop.value.padding.bottom`, `module.decoration.spacing.tablet.value.padding.bottom`, `module.decoration.spacing.phone.value.padding.bottom`
- Avoid broad `.et-l--footer` CSS for native spacing problems; it hides the real editable setting from future VB users.

### Theme Builder mobile header nav hiding *(VB-verified 2026-05-28)*

Hide a mobile nav/link Group with Divi's native visibility control:

- VB path: `Theme Builder > Global Header + Footer > Global Header > Nav Links group > Advanced > Visibility > Disable On > Phone`
- Attr: `module.decoration.disabledOn.phone.value = "on"`
- Do not rely on `module.decoration.layout.phone.value.display = "none"` for this case. That value can exist in block attrs without hiding the Group on the frontend.

## Service-Page Authoring Lessons

Context: 2026-09-10 service-page demonstration, frontend checked on installed Divi 5.12.1.
Existing Free authoring tools sufficed; no missing API or new paid primitive was
demonstrated. Apply these lessons when the design calls for repeated process rows
or an inline enquiry reveal, not as a requirement to add a form or helper to every page.

### Matching repeated rows

Content-sized flex children with `space-between` let different heading lengths
shift column starts between rows. Give corresponding number, heading and copy
modules matching native responsive widths across the repeated rows, sized for
their content rather than a universal percentage recipe. For a stacked
tablet/phone layout, override those child widths at the same breakpoints (for
example, `width: "100%"` where appropriate) and check existing max-width constraints.
Inspect desktop column left edges and stacked tablet/phone alignment and overflow;
valid block structure alone does not establish visual alignment.

### One native form, inline reveal

For a service CTA leading to an existing enquiry panel, keep one native Contact
Form and native fields in that panel. Use `addVisibility` plus `scrollToElement`
on the primary CTA so repeated activation keeps it open; reserve
`toggleVisibility` for a separate disclosure control. A stored-visible panel with
native `load` / `removeVisibility` can progressively hide it when interaction
JavaScript runs. Stored visibility alone is not proof of a working no-JS fallback.

Native visibility does not establish persistent labels, announced expanded state
or keyboard focus. When runtime checks are authorized, test actual Enter/Space
activation, repeated primary activation, close/reopen state (`aria-expanded`),
focus on reveal, persistent required-field labels, retained input and exactly one
form. Recheck after responsive DOM replacement: state, focus and draft submit
precautions must operate on current nodes, not a cached node from initial load.
If an intentional page-local helper fills observed gaps, keep it scoped and
inspect native event propagation on the target. Do not copy page-specific IDs or
helper code as a generic popup/accessibility guarantee.

### Keep evidence separate

The 2026-09-10 demonstration establishes these limits:

- **Presentation:** desktop/tablet/phone frontend review and process alignment passed on installed Divi 5.12.1. Check offer/proof/image provenance independently of block validation; identify generated design-study imagery as illustration, not client work, and do not invent clients, conversion uplift or measured time savings.
- **Native edit/save:** one representative native Heading edit was saved in VB while retaining draft status; parsed readback showed only that heading leaf changed and other attributes/Code content preserved. Final helper corrections and final sizing were frontend-checked after that save, not separately VB-saved. This is not universal VB certification or a fresh independent review of the final helper.
- **Fallback/delivery:** no-JS behavior was source-inspected only, not browser-tested with JavaScript disabled. No mail submission, controlled error-path or delivery test was performed. Hidden/disabled send controls and client-side submission prevention are draft precautions, not server-side mail isolation or a security guarantee. Presentation approval does not authorize publication or sending mail.

## Animation Staggering

Apply entrance animations with incrementing delays for a polished reveal:

```jsonc
// Card 1: immediate
"animation": {"desktop": {"value": {"style": "fade", "duration": "800ms", "delay": "0ms", "startingOpacity": "0%", "speedCurve": "ease-out"}}}

// Card 2: 150ms delay
"animation": {"desktop": {"value": {"style": "fade", "duration": "800ms", "delay": "150ms", "startingOpacity": "0%", "speedCurve": "ease-out"}}}

// Card 3: 300ms delay
"animation": {"desktop": {"value": {"style": "fade", "duration": "800ms", "delay": "300ms", "startingOpacity": "0%", "speedCurve": "ease-out"}}}

// Card 4: 450ms delay
"animation": {"desktop": {"value": {"style": "fade", "duration": "800ms", "delay": "450ms", "startingOpacity": "0%", "speedCurve": "ease-out"}}}
```

### Slide with direction

```jsonc
"animation": {"desktop": {"value": {"style": "slide", "direction": "bottom", "duration": "800ms", "delay": "200ms", "intensity": {"slide": "10%"}, "startingOpacity": "0%", "speedCurve": "ease-out"}}}
```

### Where to apply animations

| Element | Animation | Delay pattern |
|---------|-----------|---------------|
| Hero heading | `fade`, 0ms | First visible |
| Hero subtitle | `fade`, 200ms | After heading |
| Hero CTA buttons | `fade`, 400ms | After subtitle |
| Section headings | `fade`, 0ms | On scroll into view |
| Feature cards | `fade`, 0ms/150ms/300ms | Stagger left to right |
| Stats counters | `fade`, 0ms/150ms/300ms | Stagger left to right |
| Review cards | `fade`, 0ms/150ms/300ms | Stagger left to right |
| Split section image | `slide` from left, 0ms | On scroll |
| Split section content | `fade`, 200ms | After image |

## Hover States

### Card hover (Group)

```jsonc
"background": {"desktop": {"value": {"color": "rgba(255,255,255,0.05)"}, "hover": {"color": "rgba(255,255,255,0.08)"}}},
"border": {"desktop": {"value": {"styles": {"all": {"color": "rgba(255,255,255,0.1)"}}}, "hover": {"styles": {"all": {"color": "rgba(124,58,237,0.4)"}}}}}
```

### Button hover

```jsonc
// Primary button
"background": {"desktop": {"value": {"color": "#7c3aed"}, "hover": {"color": "#6d28d9"}}}

// Ghost/outline button
"background": {"desktop": {"value": {"color": "rgba(255,255,255,0.08)"}, "hover": {"color": "rgba(255,255,255,0.12)"}}},
"font": {"font": {"desktop": {"value": {"color": "rgba(226,232,240,0.9)"}, "hover": {"color": "#ffffff"}}}}
```

### Icon hover

```jsonc
"icon": {"advanced": {"color": {"desktop": {"value": "#6366f1", "hover": "#ffffff"}}}}
```

## Stats Section (Number Counter)

Use `divi/number-counter` — it animates counting on scroll. Do NOT use `divi/text` with static numbers.

```jsonc
// Parent Group: flex row, centered
{"module": {"decoration": {"layout": {"desktop": {"value": {"display": "flex", "flexDirection": "row", "alignItems": "center", "justifyContent": "center", "columnGap": "64px", "rowGap": "32px", "flexWrap": "wrap"}}}}}}

// Each counter
{"module": {"decoration": {"animation": {"desktop": {"value": {"style": "fade", "delay": "0ms"}}}}},
 "title": {"innerContent": {"desktop": {"value": "Verified Modules"}}, "decoration": {"font": {"font": {"desktop": {"value": {"color": "rgba(148,163,184,0.6)", "size": "13px", "weight": "600", "letterSpacing": "2px", "style": ["uppercase"]}}}}}},
 "number": {"innerContent": {"desktop": {"value": "16"}}, "advanced": {"enablePercentSign": {"desktop": {"value": "off"}}}, "decoration": {"font": {"font": {"desktop": {"value": {"color": "#a78bfa", "size": "48px", "weight": "800"}}}}}}}
```

## Review Card Pattern

Stars + quote (italic) + author name inline. Use Group with flex column:

```jsonc
// Review card Group
{"module": {"decoration": {
  "layout": {"desktop": {"value": {"display": "flex", "flexDirection": "column", "rowGap": "12px"}}},
  "background": {"desktop": {"value": {"color": "rgba(255,255,255,0.05)"}}},
  "border": {"desktop": {"value": {"radius": {"topLeft": "16px", "topRight": "16px", "bottomLeft": "16px", "bottomRight": "16px", "sync": "on"}, "styles": {"all": {"width": "1px", "color": "rgba(255,255,255,0.08)"}}}}},
  "spacing": {"desktop": {"value": {"padding": {"top": "24px", "bottom": "24px", "left": "24px", "right": "24px", "syncVertical": "on", "syncHorizontal": "on"}}}},
  "sizing": {"desktop": {"value": {"flexType": "8_24"}}},
  "animation": {"desktop": {"value": {"style": "fade", "delay": "0ms"}}}
}}}

```

Inside the review card Group, add 3 Text modules:

Stars:
```jsonc
{"content": {"innerContent": {"desktop": {"value": "\u003cp\u003e⭐⭐⭐⭐⭐\u003c/p\u003e"}}, "decoration": {"bodyFont": {"body": {"font": {"desktop": {"value": {"size": "16px"}}}}}}}}
```

Quote (italic):
```jsonc
{"content": {"innerContent": {"desktop": {"value": "\u003cp\u003e\u201cYour testimonial quote here.\u201d\u003c/p\u003e"}}, "decoration": {"bodyFont": {"body": {"font": {"desktop": {"value": {"color": "rgba(226,232,240,0.85)", "size": "15px", "lineHeight": "1.7em", "style": ["italic"]}}}}}}}}
```

Author + role (single text module):
```jsonc
{"content": {"innerContent": {"desktop": {"value": "\u003cp\u003e\u003cstrong style=\"color:#fff\"\u003eJane Smith\u003c/strong\u003e \u00b7 Frontend Developer\u003c/p\u003e"}}, "decoration": {"bodyFont": {"body": {"font": {"desktop": {"value": {"color": "rgba(148,163,184,0.6)", "size": "13px"}}}}}}}}
```

## Gradient Hero

CSS animation on the section — add via `css.desktop.value.freeForm` and a custom class:

```css
@keyframes gradient-shift {
  0%   { background-position: 0% 50%; }
  50%  { background-position: 100% 50%; }
  100% { background-position: 0% 50%; }
}

.hero-grad.et_pb_section {
  background-image: linear-gradient(-45deg, #0f172a, #312e81, #7c3aed, #4f46e5, #0f172a) !important;
  background-size: 400% 400% !important;
  animation: gradient-shift 12s ease infinite;
}
```

> **Note**: When using in `css.desktop.value.freeForm`, minify the CSS (remove line breaks) since the freeForm field is a single string.

Apply the class via custom attributes:
```jsonc
"attributes": {"desktop": {"value": {"attributes": [{"id": "hero-cls", "name": "class", "value": "hero-grad", "adminLabel": "class hero-grad", "targetElement": "main"}]}}}
```

## Responsive Overrides

Always include tablet/phone adjustments:

```jsonc
// Font size reduction
"font": {"desktop": {"value": {"size": "48px"}}, "tablet": {"value": {"size": "36px"}}, "phone": {"value": {"size": "28px"}}}

// Padding reduction
"spacing": {"desktop": {"value": {"padding": {"top": "100px", "bottom": "100px"}}}, "tablet": {"value": {"padding": {"top": "60px", "bottom": "60px"}}}}

// Stack columns on phone
"layout": {"desktop": {"value": {"flexDirection": "row"}}, "phone": {"value": {"flexDirection": "column"}}}
```

## Kicker / Eyebrow Labels

A kicker (also called eyebrow, overline, or pre-header) is a short label above a heading that categorizes the section. It is NOT a heading — getting this wrong breaks visual hierarchy.

### Identification checklist

Before styling text as a kicker, verify ALL four:
1. **Short** — typically 1-3 words (e.g. "Features", "How It Works", "Testimonials")
2. **Categorical** — labels what the section is about, not what it says
3. **Not the primary message** — the heading below carries the main content
4. **Appears above a heading** — never stands alone as the section's only text

If any rule fails, it's a heading, not a kicker. A common mistake: treating the main CTA heading as a kicker and shrinking it to tiny uppercase text.

### Module and tag

Use `divi/text` with `<p>` tag — never `<h1>`-`<h6>`. Kickers are decorative labels, not semantic headings. Using heading tags pollutes the page's SEO/accessibility hierarchy.

### Styling pattern
```jsonc
// Kicker above a section heading
"content": {"innerContent": {"desktop": {"value": "\u003cp\u003eFeatures\u003c/p\u003e"}},
  "decoration": {"bodyFont": {"body": {"font": {"desktop": {"value": {"color": "#7c3aed", "size": "13px", "weight": "700", "letterSpacing": "3px", "style": ["uppercase"]}}}}}}}
```

Common styling: small size (12-14px), bold weight (600-700), uppercase, wide letter-spacing (2-4px), accent color. Adjust to match the project's design system.

## Preset-Driven Generation

When the oa design system is set up (see [presets.md](presets.md)), use `groupPreset` references instead of inline font styling. This reduces token count and ensures design consistency.

### Before (inline — ~250 chars per heading)
```jsonc
{"title":{"innerContent":{"desktop":{"value":"Page Title"}},"decoration":{"font":{"font":{"desktop":{"value":{"weight":"800","size":"clamp(30px, 8vw, 100px)","lineHeight":"1.1em","color":"#ffffff"}}}}}}}
```

### After (preset — ~180 chars, no size/weight/lineHeight attrs)
```jsonc
{"title":{"innerContent":{"desktop":{"value":"Page Title"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"$variable({\"type\":\"color\",\"value\":{\"name\":\"gcid-oa-white\",\"settings\":{}}})$"}}}}}},"groupPreset":{"designTitleText":{"presetId":["<heading-h1>"],"groupName":"divi/font"}}}
```

Size, weight, and line height come from the preset. Color uses a `$variable()$` token. Per-instance overrides (like animation delay) can still be added inline.

### Available presets — Quick lookup

Resolve preset role keys to UUIDs via `.claude/design-system.json`. Full catalog with weights, tokens, and markup examples: [presets.md](presets.md).

| Category | groupId | groupName | Role keys |
|----------|---------|-----------|-----------|
| Headings | `designTitleText` | `divi/font` | `heading-h1` through `heading-h6-small`, `heading-light` |
| Body text | `designText` | `divi/font-body` | `text-standard`, `text-small`, `text-big`, `text-light` |
| Buttons | `button` | `divi/button` | `button-primary`, `button-primary-outline`, `button-secondary`, `button-white` |
| Module-level | (via `modulePreset`) | — | `section-dark`, `card-glass`, `icon-badge` |

**Common needs**: Hero heading → `heading-h1`, section heading → `heading-h2`, card heading → `heading-h4`, body text → `text-standard`, primary CTA → `button-primary`, dark section → `section-dark`.
