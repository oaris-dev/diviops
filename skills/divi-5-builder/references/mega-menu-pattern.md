# Mega Menu Pattern (Divi 5)

Accessible, semantic mega menu using native Divi 5 modules. Discovered from VB prototype on header layout #349.

## Core Concept: Module Nesting

Divi 5 allows nesting modules inside other modules — the key enabler for semantic mega menus. A `divi/text` with `elementType: "li"` can contain a trigger `divi/text` (`elementType: "button"`) and a `divi/dropdown` as children. This produces the correct `<li> > <button> + <div>` HTML structure without custom code.

This is one possible approach — other module combinations could achieve similar results. The pattern can be improved and styled further.

**Lesson learned**: `divi/text` with `elementType: "li"` is more reliable than `divi/link` for menu items. The link module had empty-text rendering issues. Use text modules for all nav items, with padding for click targets.

## Key Modules Used

| Module | Role | Semantic HTML |
|--------|------|--------------|
| `divi/group` | Nav container (`<ul>`) | `elementType: "ul"` + `htmlBefore: <nav aria-label="...">` |
| `divi/text` | Menu item with dropdown | `elementType: "li"` |
| `divi/text` | Trigger button | `elementType: "button"` |
| `divi/link` | Simple menu link | `htmlBefore: <li role="none">` / `htmlAfter: </li>` |
| `divi/dropdown` | Dropdown panel (NEW) | Custom attributes for `role="menu"`, `aria-hidden` |
| `divi/image` | Category thumbnails | Inside dropdown grid items |

## Structure

```
Section (position: absolute, z-index: 10)
└── Row
    └── Column
        ├── Image (logo)
        └── Group [nav] (elementType: "ul", htmlBefore: <nav aria-label="Hauptnavigation">)
            ├── Link [simple item] (htmlBefore: <li role="none">, role="menuitem")
            ├── Text [menu item with dropdown] (elementType: "li")
            │   ├── Text [trigger] (elementType: "button", text: "Beratung ▼")
            │   └── Dropdown (showOn: hover, position: floating, direction: below)
            │       └── Group (elementType: "ul", role="menu")
            │           ├── Group (elementType: "li") → Image + Group(links)
            │           ├── Group (elementType: "li") → Image + Group(links)
            │           └── ...
            └── Text [menu item with dropdown] (elementType: "li")
                ├── Text [trigger] (elementType: "button", text: "Shop ▼")
                └── Dropdown (forceVisible: "whileEditingElement")
                    └── ...
```

## Dropdown Module Format

New module `divi/dropdown` — a container that shows/hides based on trigger interaction.

```json
{
  "module": {
    "meta": {
      "meta": {
        "forceVisible": {
          "desktop": {"value": "whileInBuilder"}
        }
      }
    },
    "advanced": {
      "dropdown": {
        "desktop": {
          "value": {
            "position": "floating",
            "showOn": "hover",
            "direction": "below",
            "alignment": "end"
          }
        }
      },
      "flexColumnStructure": {
        "desktop": {"value": "css-grid-grids_5"}
      }
    },
    "decoration": {
      "layout": {
        "desktop": {
          "value": {
            "gridColumnWidths": "equal",
            "gridColumnCount": "2",
            "flexDirection": "row",
            "flexWrap": "wrap",
            "alignItems": "flex-start"
          }
        }
      },
      "sizing": {
        "desktop": {
          "value": {
            "maxWidth": "500px",
            "width": "500px",
            "flexType": "none"
          }
        }
      },
      "background": {"desktop": {"value": {"color": "#ffffff"}}},
      "boxShadow": {
        "desktop": {
          "value": {
            "horizontal": "0px",
            "vertical": "2px",
            "blur": "18px",
            "spread": "0px",
            "position": "outer",
            "color": "rgba(0,0,0,0.1)",
            "style": "preset1"
          }
        }
      },
      "border": {
        "desktop": {
          "value": {
            "radius": {"topLeft": "1rem", "topRight": "1rem", "bottomLeft": "1rem", "bottomRight": "1rem", "sync": "on"}
          }
        }
      },
      "attributes": {
        "desktop": {
          "value": {
            "attributes": [
              {"name": "id", "value": "megamenu-id", "targetElement": "main"},
              {"name": "role", "value": "menu", "targetElement": "main"},
              {"name": "aria-hidden", "value": "true", "targetElement": "main"}
            ]
          }
        }
      }
    }
  },
  "builderVersion": "5.1.1"
}
```

### Dropdown Settings

| Setting | Values | Purpose |
|---------|--------|---------|
| `position` | `floating`, `inline` | Floating = absolute overlay, inline = pushes content |
| `showOn` | `hover`, `click` | Trigger interaction |
| `direction` | `below`, `above`, `left`, `right` | Opening direction |
| `alignment` | `start`, `center`, `end` | Horizontal alignment relative to trigger |

### Force Visible (VB editing)

```json
"meta": {
  "meta": {
    "forceVisible": {
      "desktop": {"value": "whileEditingElement"}
    }
  }
}
```
Values: `"whileInBuilder"` (always visible in VB), `"whileEditingElement"` (visible only when editing this element)

Note: nested under `module.meta.meta` (double meta), not `module.meta`.

## Trigger Button Pattern

```json
{
  "module": {
    "advanced": {
      "html": {"desktop": {"value": {"elementType": "button"}}}
    }
  },
  "content": {
    "innerContent": {"desktop": {"value": "\u003cp\u003eBeratung \u25bc\u003c/p\u003e"}}
  },
  "builderVersion": "5.1.1"
}
```
Uses `elementType: "button"` — renders as `<button>` for keyboard accessibility.

## Link Module (divi/link)

```json
{
  "module": {
    "advanced": {
      "html": {
        "desktop": {
          "value": {
            "htmlBefore": "\u003cli role=\u0022none\u0022\u003e",
            "htmlAfter": "\u003c/li\u003e"
          }
        }
      }
    },
    "decoration": {
      "attributes": {
        "desktop": {
          "value": {
            "attributes": [
              {"name": "role", "value": "menuitem", "targetElement": "main"}
            ]
          }
        }
      }
    }
  },
  "link": {
    "innerContent": {
      "desktop": {
        "value": {
          "text": "Kontakt",
          "url": "#",
          "target": "off"
        }
      }
    }
  },
  "builderVersion": "5.1.1"
}
```

## ARIA Accessibility Pattern

| Element | ARIA Attribute | Purpose |
|---------|---------------|---------|
| `<nav>` | `aria-label="Hauptnavigation"` | Identifies navigation landmark |
| `<li>` (link wrapper) | `role="none"` | Removes implicit list item role |
| `<a>` (link) | `role="menuitem"` | Identifies as menu item |
| Dropdown panel | `role="menu"` | Identifies as submenu |
| Dropdown panel | `aria-hidden="true"` | Hidden from screen readers when closed |
| Trigger | `elementType: "button"` | Keyboard-accessible trigger |

## Grid Layout in Dropdown

The dropdown uses CSS Grid (not flex) for the mega menu columns:
```json
"flexColumnStructure": {"desktop": {"value": "css-grid-grids_5"}},
"layout": {
  "desktop": {
    "value": {
      "gridColumnWidths": "equal",
      "gridColumnCount": "2"
    }
  }
}
```
Grid must be set on ALL breakpoints (desktop, tablet, phone, etc.) when using the dropdown module.
