# Design Guide — Copy-Paste Patterns

Patterns for generating high-quality Divi 5 pages. Use alongside [module-formats.md](module-formats.md) for exact attr paths and [presets.md](presets.md) for design tokens.

## Design Thinking — Before You Code

Before generating any page, make three decisions:

1. **Aesthetic direction** — Choose a clear visual identity: dark/moody, light/airy, brutalist, glassmorphism, editorial, organic, playful, luxury. Commit to it fully. Bold maximalism and refined minimalism both work — the key is intentionality.

2. **One memorable element** — Every page needs one thing someone will remember: an animated hero, a striking color contrast, an unexpected layout, a scroll-triggered reveal. Design around this anchor.

3. **Variety** — Never converge on the same choices across pages. Vary color families, heading weights, section rhythms, card styles. If the last page used dark sections with purple accents, try light sections with teal accents next.

**Design quality checklist:**
- Typography hierarchy is clear (H1 > H2 > H3 visually distinct, not just smaller)
- Color palette has a dominant + accent, not evenly distributed
- Spacing creates rhythm (generous whitespace between sections, tighter within)
- Animation is purposeful (entrance cascade on hero, subtle scroll effects on content, not random)
- Responsive works (stack on mobile, reduce sizes on tablet)

Use `oa` design tokens ([presets.md](presets.md)) for consistent sizing, spacing, and colors. Override per-instance only for deliberate variation.

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
      "phone": {"value": {"flexDirection": "column"}}
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
    "sizing": {"desktop": {"value": {"flexType": "8_24"}}},
    "background": {"desktop": {"value": {"color": "rgba(255,255,255,0.05)"}, "hover": {"color": "rgba(255,255,255,0.08)"}}},
    "border": {"desktop": {"value": {"radius": {"topLeft": "16px", "topRight": "16px", "bottomLeft": "16px", "bottomRight": "16px", "sync": "on"}, "styles": {"all": {"width": "1px", "color": "rgba(255,255,255,0.1)"}}}}},
    "spacing": {"desktop": {"value": {"padding": {"top": "32px", "bottom": "32px", "left": "32px", "right": "32px", "syncVertical": "on", "syncHorizontal": "on"}}}},
    "animation": {"desktop": {"value": {"style": "fade", "delay": "0ms"}}}
  }
}
```

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

> **Note**: `flexType` handles gap-aware sizing internally — do NOT also set `width` or `flexBasis`. Use `flexType` alone on each child Group.

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
{"title":{"innerContent":{"desktop":{"value":"Page Title"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"$variable({\"type\":\"color\",\"value\":{\"name\":\"gcid-oa-white\",\"settings\":{}}})"}}}}}},"groupPreset":{"designTitleText":{"presetId":["<heading-h1>"],"groupName":"divi/font"}}}
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
