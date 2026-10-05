# Mega Menu Pattern (Divi 5)

Native, editable disclosure navigation with scoped styling and state/keyboard integration requiring target verification. Native structure alone is not an accessibility or Visual Builder certification.

## Core Concept: Module Nesting

Divi 5 allows nesting modules inside other modules — the key enabler for semantic mega menus. A dropdown item can use a `divi/text` wrapper with `elementType: "li"` containing a leaf `divi/text` trigger (`elementType: "button"`) and a sibling `divi/dropdown` panel. This produces the useful `<li> > <button> + <div>` shape with native modules; responsive styling and state/focus behavior still need verification.

Simple navigation links should stay anchors. Use `divi/link` for real links and wrap it with `htmlBefore: "<li>"` / `htmlAfter: "</li>"`; do not set `divi/link` itself to `elementType: "li"` because that destroys the anchor. Use `divi/text` for button triggers, headings, labels, and non-link wrappers.

## Key Modules Used

| Module | Role | Semantic HTML |
|--------|------|--------------|
| `divi/group` | Nav container (`<ul>`) | `elementType: "ul"` + `htmlBefore: <nav aria-label="...">` / `htmlAfter: </nav>` |
| `divi/text` | Menu item with dropdown | `elementType: "li"` |
| `divi/text` | Leaf trigger button | `elementType: "button"` + `aria-controls` |
| `divi/link` | Simple menu link | `htmlBefore: <li>` / `htmlAfter: </li>`; keep the anchor |
| `divi/dropdown` | Disclosure panel | custom `id` matching trigger `aria-controls`; ordinary navigation content |
| `divi/image` | Category thumbnails | Inside dropdown grid items |

## Structure

```
Section (position: absolute, z-index: 10)
└── Row
    └── Column
        ├── Image (logo)
        └── Group [nav] (elementType: "ul", htmlBefore: <nav aria-label="Hauptnavigation">, htmlAfter: </nav>)
            ├── Link [simple item] (htmlBefore: <li>, htmlAfter: </li>)
            ├── Text [menu item with dropdown] (elementType: "li")
            │   ├── Text [trigger] (elementType: "button", aria-controls="nav-panel-beratung")
            │   └── Dropdown (id="nav-panel-beratung", forceVisible: "whileInBuilder")
            │       └── Group (elementType: "ul")
            │           ├── Group (elementType: "li") → Image + Group(links)
            │           ├── Group (elementType: "li") → Image + Group(links)
            │           └── ...
            └── Text [menu item with dropdown] (elementType: "li")
                ├── Text [trigger] (elementType: "button", aria-controls="nav-panel-shop")
                └── Dropdown (id="nav-panel-shop", forceVisible: "whileInBuilder")
                    └── ...
```

## Dropdown Module Format

`divi/dropdown` is a container that shows/hides based on trigger interaction. The JSON examples below are historical 5.1.1 **path schematics**, not complete current-target payloads. Inspect the target's schema, installed version and existing header before adapting them; replace sample IDs, URLs, sizes and `builderVersion`, and validate the assembled blocks. No marketing-specific ID, destination or asset is required by this recipe.

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
            "showOn": "click",
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
            "display": "grid",
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
              {"name": "id", "value": "nav-panel-beratung", "targetElement": "main"}
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
| `showOn` | `hover`, `click` | Use `click` for this disclosure recipe; hover is not its keyboard/touch contract |
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
              {"name": "type", "value": "button", "targetElement": "main"},
              {"name": "aria-expanded", "value": "false", "targetElement": "main"}
            ]
          }
        }
      }
    }
  },
  "content": {
    "innerContent": {"desktop": {"value": "Beratung"}}
  },
  "builderVersion": "5.1.1"
}
```
The `aria-controls` value must match the controlled dropdown or panel `id` exactly and be unique within the rendered header. If it drifts, Divi's runtime can silently no-op. Give the mobile-menu button its own controlled navigation-container ID. Keep a decorative chevron inside the same button (span or pseudo-element), not a separately clickable sibling icon. Do not nest a literal button inside a module already rendered as a button.

## Link Module (divi/link)

Use `divi/link` for actual links, but wrap it with list-item HTML. Do not use `elementType: "li"` on `divi/link`.

```json
{
  "module": {
    "advanced": {
      "html": {
        "desktop": {
          "value": {
            "htmlBefore": "\u003cli\u003e",
            "htmlAfter": "\u003c/li\u003e"
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
          "linkUrl": "{{destination_url}}",
          "linkTarget": "off"
        }
      }
    }
  },
  "builderVersion": "5.1.1"
}
```

## ARIA Accessibility Pattern

Use ordinary navigation/disclosure semantics, not an ARIA application menu. Do not add `role="menu"`, `menuitem` or `none` to these examples; those roles require a different, complete keyboard contract. Keep normal Tab navigation to real anchors. Closed panels must not expose focusable links; verify actual visibility and accessibility-tree state rather than treating authored `aria-expanded="false"` as live synchronization. If a target adds `aria-hidden`, its state owner must update it too; do not leave a static hidden attribute on an open panel.

| Element | ARIA Attribute | Purpose |
|---------|---------------|---------|
| `<nav>` | `aria-label="Hauptnavigation"` | Identifies navigation landmark |
| `<li>` / `<a href>` | Native list/link semantics | Keeps ordinary navigation and link behavior |
| Trigger button | `aria-controls="nav-panel-id"` | Pairs trigger to panel |
| Dropdown panel | `id="nav-panel-id"` | Must match trigger `aria-controls` |
| Trigger button | `aria-expanded="false/true"` | Must track the actual closed/open panel state |
| Trigger | `elementType: "button"` | Keyboard-accessible trigger |

Custom attributes with empty values do not render. For boolean-style flags, use a non-empty value such as `"true"` or `"false"` instead of `""`.

## Grid Layout in Dropdown

The dropdown uses CSS Grid (not flex) for the mega menu columns:
```json
"flexColumnStructure": {"desktop": {"value": "css-grid-grids_5"}},
"layout": {
  "desktop": {
    "value": {
      "display": "grid",
      "gridColumnWidths": "equal",
      "gridColumnCount": "2"
    }
  }
}
```
Set the intended grid on the panel or its inner native Group, then verify its effective desktop/tablet/phone values. A two-column desktop panel can become one column inline on tablet/phone; do not force the desktop column count onto every breakpoint.

## Responsive Visibility Split

Choose the responsive composition to match the approved reference. Two sibling navigation units are one option:

- Desktop mega menu: visible on desktop, disabled on tablet and phone.
- Mobile drawer or accordion: disabled on desktop, visible on tablet and phone.

Use native Divi `disabledOn` for that split. Do not add breakpoint JavaScript when Divi visibility settings are enough. For Theme Builder header groups, `module.decoration.disabledOn.phone.value = "on"` is the verified hide mechanism; `module.decoration.layout.phone.value.display = "none"` can exist without hiding the group.

The inspected marketing composition instead reused one native navigation tree: desktop floating panels, with tablet/phone categories expanding inline under a Menu button. Do not combine both architectures by accident or duplicate panel IDs. Its scoped breakpoint listener reset open state; it was not a replacement for native responsive positioning.

## Reference-Aware Adaptation

Before authoring, record this small mapping from the approved reference to the destination. Use [preset inspection](presets.md) and [native attribute paths](module-formats.md), not guessed token IDs or a new registry.

| Input | Map explicitly |
|-------|----------------|
| Navigation content | Category labels/order, compact vs multi-column groups, link title/description and approved destination for every entry; direct links remain links |
| Identity and ownership | Header/root selector, unique mobile/panel IDs and trigger pairs, native runtime version, existing helper selectors and one owner per control |
| Styling | Existing color/spacing/type/radius tokens or approved local values; panel widths, viewport gutters, focus outline, icon size and transformed-chevron inset |
| Assets | Approved logo/icon family, usage rights and destination media URLs/IDs; no automatic reuse of source marketing assets or asset generation |
| Responsive behavior | Actual target breakpoints, floating alignment/anchor, inline columns, scroll limits and reset behavior; verify inherited phone values |

Compose Section > Row > Column with a native Image logo, a Text mobile button and a Group navigation container. Each expandable category is a Group containing one Text button and a sibling Dropdown; panel headings, entry copy/anchors and optional images remain native Text/Image/Group content. A compact category can use one column while richer categories use two. For text-and-description links, the inspected proof used one real anchor inside each native Text module, including a decorative image with empty alt; this is not an independently editable Image module. Choose separate native Image modules when that editability is required, without duplicating the link's accessible name. Do not replace the entire navigation with page-sized HTML.

For the inspected desktop configuration, `module.advanced.dropdown.desktop.value` used `position: "floating"`, `showOn: "click"`, `direction: "below"`: compact panel `horizontalMode: "trigger"` / `alignment: "start"`, wide panels `horizontalMode: "row"` / `alignment: "end"`. Check those paths/options on the actual target. Tablet used `position: "inline"`, zero offset and full-width sizing; phone inherited those native values and used the collapsed CSS layout. Example widths were 400px compact and 760px wide, constrained by viewport gutters; these are reference measurements, not universal defaults. Keep fixed breakpoint font sizes; scope any required CSS to the destination header.

### One State/Keyboard Owner

- Inventory existing navigation helpers before adding anything. The earlier mobile-menu work and its site-local helper are not guaranteed to be installed or to match new IDs. Reuse/adapt one compatible owner; do not install competing click togglers or duplicate Escape/outside listeners. Native Divi owns category opening/positioning; a bounded supplement owns only missing state/focus behavior and, where needed, the separate mobile-container toggle.
- Synchronize `aria-expanded` from actual panel state after pointer/keyboard activation, outside dismissal and sibling closure. In the inspected implementation, native sibling closing changed classes without reliably emitting the hide event, so a click-only or hide-event-only ARIA update was insufficient.
- That implementation observed panel class changes with `MutationObserver` and called `panel.__dropdownInstance?.hide()` for dismissal. The private instance property and hidden-class signal are **inspected, version-bound integration details**, not documented stable Divi APIs. Verify initialization, visibility signals and close behavior on the target before relying on them; missing instance access must not be reported as a successful close. Do not copy this into a new generic menu engine.
- Scope initialization to one header root, make attachment idempotent, and exclude the Visual Builder/editor surface. Escape closes an open category and returns focus to its trigger; a subsequent Escape can close the mobile container and return focus to Menu. Outside interaction, focus leaving the navigation, and breakpoint changes must leave visible state and ARIA consistent. Do not move focus back on every outside click or interfere with normal link navigation.
- Preserve native single-open categories for this recipe. Multi-open accordions require a separately agreed interaction model; the existing focusout caveat alone does not prevent native synchronous sibling closure.

### Transformed Icon Clearance

Measure the chevron's **transformed** bounds in both directions against the scrolling/clipping ancestor, not just its CSS width. The inspected 7px rotated chevron extended 1.45px beyond the 390px mobile menu boundary; an 8px right inset produced 6.55px clearance. This was an authored-CSS correction, not evidence of a DiviOps renderer defect. Map an appropriate inline-end inset for the destination geometry/direction; 7px and 8px are tested example dimensions, not mandated sizing. Include focus outlines in the clipping review and keep the full label/chevron row a single tap target.

## Adaptation Review Checklist

Run site checks only with target-owner authorization; record unperformed checks rather than inheriting a prior proof.

- [ ] Confirm approved content, URLs, token/reference mapping, assets, helper ownership and destination header assignments. Preserve unrelated header/menu records; publication remains a separate decision.
- [ ] Validate assembled native blocks, then use the authorized backup/checksum-protected write and saved-state readback. Check labels, trigger/panel ID uniqueness and exact pairing. Structural success does not establish visual or keyboard correctness.
- [ ] At desktop and 820px/390px (plus the target's breakpoint boundaries), open each panel: viewport containment, no horizontal overflow, correct floating/inline behavior, usable scroll area, all images loaded, transformed chevrons and visible focus unclipped. Test resize with a panel open.
- [ ] Test pointer/tap, **Enter and Space separately**, Tab to links, ordinary link navigation, Escape from both trigger and link, and sensible focus return. Check actual visibility plus `aria-expanded` in both directions after outside click, sibling opening and repeated activation. Check closed content is not keyboard-reachable.
- [ ] In an authorized VB session, confirm panel reachability/normal-flow editing, edit representative native settings and save/reopen/read back. Keep frontend-only drawer CSS out of Builder mode or supply scoped edit-surface overrides; a no-op save is not exhaustive setting-edit proof.
- [ ] Remove preview-only global-header hiding, post-wrapper stacking and context-height rules before any authorized shared-header integration. Recheck the actual header with its existing helpers; never deploy the isolated preview stylesheet verbatim.
- [ ] Record exact target/runtime, tested viewports/actions, custom CSS/JS, saved-state evidence and remaining gaps. Do not claim zero custom code, automatic navigation generation or full accessibility certification.

## Evidence Boundary

The 16 September 2026 marketing `REVIEW.md`, `menu-map.json`, saved `preview-content.html` / `header-integration.html` and read-only generator inspection support this extraction. The saved preview contains **47 blocks**, including preview context and one Code module for scoped CSS/JS; the integration artifact contains 41. Section/Row/Column/Group/Text/Image/Dropdown content remains native. The proof used one compact category and two two-column categories, switching to inline panels on tablet/phone. It was not entirely preset-driven or zero-custom-code.

The review records desktop 1314px, tablet 820px and phone 390px checks, Enter, Tab, Escape/focus return, outside and sibling closing, and the measured chevron repair. It later records a separately authorized production header rollout; the marketing header is **not** still untouched. That chronology does not resolve the separate earlier mobile-helper issue's production gate by assumption. This guidance update performs no site writes and creates **no fresh staging example from the recipe**. Space was not explicitly recorded for this new composition, nor was exhaustive VB setting-edit or screen-reader testing. The older nested-dropdown proof and the separate mobile helper's bounded no-op-save/Enter/Space evidence must not be relabeled as certification of this megamenu or future adaptations.

## Runtime Caveats

- Floating dropdown width often needs an explicit width/max-width CSS guard on the wrapper or dropdown. Native floating positioning does not always constrain the panel.
- A transformed sticky ancestor can become the containing block for a fixed overlay and shift it off-screen. Inspect the actual ancestor transform before choosing fixed positioning; use an appropriate non-transformed anchor or in-header positioning instead of blindly copying a fixed drawer.
- Set `-webkit-tap-highlight-color: transparent` once on the header/menu wrapper so Divi wrapper elements inherit it. Applying it only to the visible button or link can leave touch-browser highlights on parent wrappers.
- Divi dropdown siblings are natively single-open. Multi-open accordion behavior requires a runtime enforcer; a focusout-only guard does not stop Divi's synchronous sibling close.
- When resolving or gating stored Divi markup, match serialized block names and attrs, not rendered CSS classes. For example, a legacy off-canvas drawer should be detected from stored markers such as `wp:divi/toggle` and `"mode":"absolute"`; rendered classes such as `et_pb_toggle` or `et_pb_section--absolute` are frontend output and may not exist in `post_content`.
