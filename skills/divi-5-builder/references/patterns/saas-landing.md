# Pattern: SaaS Landing Page

A dark-theme, conversion-focused landing page with 6 sections. Proven structure from page 756 (OARIS Studio).

## Section Structure

```
1. Hero          — eyebrow + H1 + subtitle + 2 CTAs + social proof
2. Features      — eyebrow + H2 + 3-column icon cards (Group flex)
3. Split         — terminal/code card (left) + benefits list (right)
4. Stats         — 3 animated number counters in flex row
5. Reviews       — eyebrow + H2 + 3 review cards (Group flex)
6. Final CTA     — H2 + subtitle + divider + 2 CTAs
```

## Module counts
- 6 sections, 8 rows, 8 columns
- 27 text modules, 8 headings, 4 buttons, 3 icons, 3 number counters, 1 divider
- 18 groups (flex containers for cards, rows, CTAs)
- ~54 modules total

## Animation pattern
- **Hero**: stagger per element — eyebrow (0ms) → heading (200ms) → subtitle (400ms) → CTAs (600ms) → social proof (800ms). Style: `fade`, not `slide` (avoids white flash before bg loads)
- **Feature cards**: `slide-bottom` with 0/200/400ms delays, `intensity.slide: "15%"`
- **Split section**: opposing directions — left card `slide-left`, right content `slide-right`
- **Stats**: `fade` with 0/150/300ms delays
- **Reviews**: `slide-bottom` with 0/200/400ms
- **Final CTA**: `fade` only
- **Reduced motion**: Divi respects the OS `prefers-reduced-motion` setting. Animations are automatically reduced/disabled for users who enable it — no extra configuration needed.

## Section blueprints

### 1. Hero
```
Section (bg: dark, padding: 120px top/bottom, NO section animation)
└── Row (maxWidth: 800px, centered)
    └── Column
        ├── Text [eyebrow] — uppercase, small, primary color, letter-spacing 3px
        ├── Heading [H1] — groupPreset: oa Heading H1, white color override
        ├── Text [subtitle] — groupPreset: oa Text Big, muted color
        ├── Group [CTAs] — flex row, gap 16px, phone: column
        │   ├── Button [primary] — solid bg, white text, rounded
        │   └── Button [ghost] — transparent bg, subtle border
        └── Group [social proof] — flex row, center, gap 8px
            ├── Text [stars] — ⭐⭐⭐⭐⭐
            └── Text [metrics] — small, muted
```

### 2. Features (3-column cards)
```
Section (bg: slightly lighter dark, padding: 100px)
├── Row (centered)
│   └── Column
│       ├── Text [eyebrow] — "FEATURES"
│       └── Heading [H2] — groupPreset: oa Heading H2
└── Row
    └── Column
        └── Group [card row] — flex row, gap 3.5%, wrap, phone: column
            ├── Group [card] — flexType 8_24, glass bg, rounded, padding, flex column
            │   ├── Icon — primary color, bg tint, rounded badge
            │   ├── Heading [H3] — white, 20px
            │   └── Text — muted, 15px
            ├── Group [card] — same structure, delay 200ms
            └── Group [card] — same structure, delay 400ms
```

### 3. Split (code + benefits)
```
Section (bg: dark, padding: 100px)
└── Row
    └── Column
        └── Group [split row] — flex row, gap 48px, phone: column
            ├── Group [terminal card] — flexType 12_24, dark bg, rounded, monospace
            │   └── Text — Roboto Mono, pre whitespace via CSS
            └── Group [benefits] — flexType 12_24, flex column
                ├── Text [eyebrow]
                ├── Heading [H2]
                ├── Group [benefit] — flex row, gap 12px
                │   ├── Text [✅]
                │   └── Text [description]
                ├── Group [benefit] — same
                └── Group [benefit] — same
```

### 4. Stats
```
Section (bg: gradient or accent, padding: 80px)
└── Row
    └── Column
        └── Group [stats row] — flex row, justify center, gap 64px, phone: column
            ├── Number Counter — large number, label below
            ├── Number Counter — delay 150ms
            └── Number Counter — delay 300ms
```

### 5. Reviews (3-column cards)
```
Section (bg: dark, padding: 100px)
├── Row
│   └── Column
│       ├── Text [eyebrow] — "REVIEWS"
│       └── Heading [H2]
└── Row
    └── Column
        └── Group [review row] — flex row, gap 3.5%, phone: column
            ├── Group [review card] — flexType 8_24, glass bg, padding, flex column
            │   ├── Text [stars] — ⭐⭐⭐⭐⭐
            │   ├── Text [quote] — italic, muted
            │   └── Text [author] — small, primary color
            ├── Group [review card] — delay 200ms
            └── Group [review card] — delay 400ms
```

### 6. Final CTA
```
Section (bg: gradient primary→secondary, padding: 100px)
└── Row (maxWidth: 800px)
    └── Column — centered text
        ├── Heading [H2] — white
        ├── Text — muted white
        ├── Divider — accent color, short width
        └── Group [CTAs] — flex row, center, gap 16px
            ├── Button [primary] — white bg, dark text (inverted)
            └── Button [ghost] — transparent, white border
```

## Using with presets

When `oa` presets are available, reference them instead of inline font styling. Resolve `<role-key>` placeholders from `.claude/design-system.json`:

```jsonc
// Heading with preset — no inline size/weight/lineHeight needed
"groupPreset": {"designTitleText": {"presetId": ["<heading-h1>"], "groupName": "divi/font"}}

// Body text with preset
"groupPreset": {"designText": {"presetId": ["<text-standard>"], "groupName": "divi/font-body"}}
```

Override only what's unique per instance: color, animation delay, content.

## Customization points

Replace these to adapt the pattern for any SaaS product:
- **Hero**: H1 text, subtitle, CTA labels, social proof metrics
- **Features**: 3 card titles + descriptions + icons
- **Split**: code/demo content, 3 benefit descriptions
- **Stats**: 3 numbers + labels
- **Reviews**: 3 quotes + names + roles
- **CTA**: heading, subtitle, button labels
- **Colors**: swap primary/secondary in the design tokens
