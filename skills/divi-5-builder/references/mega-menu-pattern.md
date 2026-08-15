# Mega Menu Pattern (Divi 5)

Accessible, semantic mega menu using native Divi 5 modules.

## Core Concept: Module Nesting

Divi 5 allows nesting modules inside other modules — the key enabler for semantic mega menus. A dropdown item can use a `divi/text` wrapper with `elementType: "li"` containing a leaf `divi/text` trigger (`elementType: "button"`) and a sibling `divi/dropdown` panel. This produces the useful `<li> > <button> + <div>` shape without custom code.

Simple navigation links should stay anchors. Use `divi/link` for real links and wrap it with `htmlBefore: "<li>"` / `htmlAfter: "</li>"`; do not set `divi/link` itself to `elementType: "li"` because that destroys the anchor. Use `divi/text` for button triggers, headings, labels, and non-link wrappers.

## Key Modules Used

| Module | Role | Semantic HTML |
|--------|------|--------------|
| `divi/group` | Nav container (`<ul>`) | `elementType: "ul"` + `htmlBefore: <nav aria-label="...">` / `htmlAfter: </nav>` |
| `divi/text` | Menu item with dropdown | `elementType: "li"` |
| `divi/text` | Leaf trigger button | `elementType: "button"` + `aria-controls` |
| `divi/link` | Simple menu link | `htmlBefore: <li role="none">` / `htmlAfter: </li>` |
| `divi/dropdown` | Dropdown panel | custom `id` matching trigger `aria-controls`, plus `role="menu"` |
| `divi/image` | Category thumbnails | Inside dropdown grid items |

## Structure

```
Section (position: absolute, z-index: 10)
└── Row
    └── Column
        ├── Image (logo)
        └── Group [nav] (elementType: "ul", htmlBefore: <nav aria-label="Hauptnavigation">, htmlAfter: </nav>)
            ├── Link [simple item] (htmlBefore: <li role="none">, role="menuitem")
            ├── Text [menu item with dropdown] (elementType: "li")
            │   ├── Text [trigger] (elementType: "button", aria-controls="nav-panel-beratung")
            │   └── Dropdown (id="nav-panel-beratung", forceVisible: "whileInBuilder")
            │       └── Group (elementType: "ul", role="menu")
            │           ├── Group (elementType: "li") → Image + Group(links)
            │           ├── Group (elementType: "li") → Image + Group(links)
            │           └── ...
            └── Text [menu item with dropdown] (elementType: "li")
                ├── Text [trigger] (elementType: "button", aria-controls="nav-panel-shop")
                └── Dropdown (id="nav-panel-shop", forceVisible: "whileInBuilder")
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
              {"name": "id", "value": "nav-panel-beratung", "targetElement": "main"},
              {"name": "role", "value": "menu", "targetElement": "main"},
              {"name": "aria-hidden", "value": "true", "targetElement": "main"},
              {"name": "data-diviops-dropdown", "value": "true", "targetElement": "main"}
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
      "desktop": {"value": "whileInBuilder"}
    }
  }
}
```
Use `"whileInBuilder"` for dropdown contents that need to stay reachable in the Visual Builder. It keeps the panel editable in VB without leaking forced visibility to the frontend. `"whileEditingElement"` can leave nested menu content hard to select during header editing.

Note: nested under `module.meta.meta` (double meta), not `module.meta`.

## Trigger Button Pattern

`elementType: "button"` renders reliably on leaf modules such as `divi/text` and `divi/icon`. Parent/group containers can drop the behavior. Put the click target on one leaf trigger and pair it to the controlled panel with `aria-controls`.

```json
{
  "module": {
    "advanced": {
      "html": {"desktop": {"value": {"elementType": "button"}}}
    },
    "decoration": {
      "attributes": {
        "desktop": {
          "value": {
            "attributes": [
              {"name": "aria-controls", "value": "nav-panel-beratung", "targetElement": "main"},
              {"name": "aria-expanded", "value": "false", "targetElement": "main"}
            ]
          }
        }
      }
    }
  },
  "content": {
    "innerContent": {"desktop": {"value": "\u003cp\u003eBeratung \u25bc\u003c/p\u003e"}}
  },
  "builderVersion": "5.1.1"
}
```
The `aria-controls` value must match the controlled dropdown or panel `id` exactly. If it drifts, Divi's runtime can silently no-op.

## Link Module (divi/link)

Use `divi/link` for actual links, but wrap it with list-item HTML. Do not use `elementType: "li"` on `divi/link`.

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
  "content": {
    "innerContent": {
      "desktop": {
        "value": {
          "text": "Kontakt",
          "linkUrl": "#",
          "linkTarget": "off"
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
| Trigger button | `aria-controls="nav-panel-id"` | Pairs trigger to panel |
| Dropdown panel | `role="menu"` | Identifies as submenu |
| Dropdown panel | `id="nav-panel-id"` | Must match trigger `aria-controls` |
| Dropdown panel | `aria-hidden="true"` | Hidden from screen readers when closed |
| Trigger | `elementType: "button"` | Keyboard-accessible trigger |

Custom attributes with empty values do not render. For boolean-style flags, use a non-empty value such as `"true"` or `"false"` instead of `""`.

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

## Responsive Visibility Split

For production headers, prefer two sibling navigation units:

- Desktop mega menu: visible on desktop, disabled on tablet and phone.
- Mobile drawer or accordion: disabled on desktop, visible on tablet and phone.

Use native Divi `disabledOn` for that split. Do not add breakpoint JavaScript when Divi visibility settings are enough. For Theme Builder header groups, `module.decoration.disabledOn.phone.value = "on"` is the verified hide mechanism; `module.decoration.layout.phone.value.display = "none"` can exist without hiding the group.

## Runtime Caveats

- Floating dropdown width often needs an explicit width/max-width CSS guard on the wrapper or dropdown. Native floating positioning does not always constrain the panel.
- Set `-webkit-tap-highlight-color: transparent` once on the header/menu wrapper so Divi wrapper elements inherit it. Applying it only to the visible button or link can leave touch-browser highlights on parent wrappers.
- Divi dropdown siblings are natively single-open. Multi-open accordion behavior requires a runtime enforcer; a focusout-only guard does not stop Divi's synchronous sibling close.
- When resolving or gating stored Divi markup, match serialized block names and attrs, not rendered CSS classes. For example, a legacy off-canvas drawer should be detected from stored markers such as `wp:divi/toggle` and `"mode":"absolute"`; rendered classes such as `et_pb_toggle` or `et_pb_section--absolute` are frontend output and may not exist in `post_content`.
