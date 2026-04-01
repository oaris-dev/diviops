# DiviOps Design Library

WordPress plugin providing modern design effects for Divi 5 pages. CSS animations, glass morphism, Three.js WebGL shaders, and scroll-triggered effects.

## Upgrade From The Previous Plugin Name

1. Deactivate the old `Divi Design Library` plugin.
2. Install or copy `diviops-design-library/`.
3. Activate `DiviOps Design Library`.

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
| `ddl-stroke-text` | Text outline (stroke) |
| `ddl-pulse-dot` | Pulsing green indicator |

### Three.js WebGL
- Three.js r128 bundled locally (no CDN)
- Auto-loaded when page content contains `webgl`, `shader`, or `three-js`
- Use with Code module for custom shader heroes

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
        └── three.min.js       # Three.js r128 (bundled)
```

## Conditional Loading
- CSS is always printed (lightweight, no external requests)
- `design-fx.js` loads on all frontend pages
- `three.min.js` only loads when page content contains WebGL-related keywords
