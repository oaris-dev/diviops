# Minimal Valid Snippets

Copy-paste-ready Divi 5 block snippets — the **smallest shape that renders correctly**. Each snippet includes every key required for render; omit any listed key and the module silently falls through to a wrong default (empty text, wrong heading level, missing icon, default "Click Me" button, etc.).

These snippets are the defensive pair to the `diviops_validate_blocks` semantic rules (plugin v1.0.0-beta.35+). Each one passes validation clean.

Wrap any of these in a `divi/column` → `divi/row` → `divi/section` → `divi/placeholder` tree before saving. Minimal container scaffolding is at the bottom of this file.

---

## `divi/heading`

Explicit `headingLevel` is required — without it the heading renders as `<h2>` regardless of semantic intent.

```
<!-- wp:divi/heading {
  "builderVersion": "5.1.1",
  "title": {
    "innerContent": { "desktop": { "value": "My Heading" } },
    "decoration": {
      "font": {
        "font": {
          "desktop": { "value": { "headingLevel": "h1" } }
        }
      }
    }
  }
} /-->
```

- **`headingLevel`** lives at `title.decoration.font.font.desktop.value.headingLevel`. Note the **double `font` nesting** (Font Family B pattern) — this isn't a typo.
- Allowed: `"h1"` through `"h6"`.
- `title.innerContent.desktop.value` is a **plain string** on Heading (different from Blurb, which uses `{text}` object).

---

## `divi/text`

Font color uses the triple-nested `bodyFont.body.font.*` shape (Font Family A). Writing `bodyFont.bodyFont.*` is a silent failure — the renderer has no consumer for that path, so color/size fall through to defaults.

```
<!-- wp:divi/text {
  "builderVersion": "5.1.1",
  "content": {
    "innerContent": { "desktop": { "value": "\u003cp\u003eBody copy goes here.\u003c/p\u003e" } },
    "decoration": {
      "bodyFont": {
        "body": {
          "font": {
            "desktop": { "value": { "color": "#1f2937", "size": "16px" } }
          }
        }
      }
    }
  }
} /-->
```

- **`bodyFont.body.font.desktop.value.*`** — use `body.font`, not `bodyFont.bodyFont`.
- `innerContent` is an HTML string (supports `<p>`, `<ul>`, `<strong>`, etc.).
- `divi/text` with no `innerContent` renders as an invisible empty block — include the value even for placeholder text.

---

## `divi/button`

Three silent-failure traps to avoid.

```
<!-- wp:divi/button {
  "builderVersion": "5.4.0",
  "button": {
    "innerContent": {
      "desktop": { "value": { "text": "Get Started", "linkUrl": "#target" } }
    },
    "decoration": {
      "button": {
        "desktop": { "value": { "icon": { "enable": "off" } } }
      }
    }
  }
} /-->
```

- **Content lives on `button.innerContent.*`**, NOT `content.innerContent.*`. Wrong bucket → button shows default "Click Me" label with empty href.
- **`innerContent.desktop.value` is an object** `{text, linkUrl}`, NOT a plain string. Plain string → empty button.
- **`icon.enable: "off"`** suppresses the default hover arrow. Omit and Divi shows an arrow icon on hover.
- **No `enable: "on"` flag is needed** for inline custom styling to render. VB-verified (Divi 5.4.0): `button.decoration.button.desktop.value.enable` is absent on inline-styled buttons saved by VB. The render path doesn't read it (`Packages/Module/Options/Button/ButtonComponent.php` only consumes `icon.settings`). Writing `enable: "off"` is *destructive* — it triggers a migration that strips `button.decoration` (`Migration/ComposibleOptionsMigration.php:563`).
- **Don't write visual styling keys at `button.decoration.button.desktop.value.*`** (e.g. `backgroundColor`, `textColor`, `font`). Render-relevant keys at this depth are limited to `enable`, `icon`, `padding` (icon-spacing gate, **not** visible-padding emitter — but required as gate-bypass on every `divi/button` group preset that doesn't carry padding here, otherwise the hover-gate clobbers the visible padding; see [presets.md](presets.md#hover-padding-gate-on-button-group-presets-broad-scope-upstream-tracked)), and `alignment` (deprecated). Visual styling outside that set is silently dropped. Validator rule: `button_no_render_consumer`.

Custom styling lives on **sibling-level paths**: `button.decoration.{border, background, font, boxShadow}` for visual styling. Padding is **scope-dependent**: `module.decoration.spacing.padding` for inline buttons / `divi/button` module presets, `button.decoration.spacing.padding` for `divi/button` group presets (the `presetGroup` render path at `ButtonModule.php:633-644` merges the latter into module spacing via `array_replace_recursive`).

---

## `divi/blurb`

Two silent-failure traps — title shape and icon-mode flag.

```
<!-- wp:divi/blurb {
  "builderVersion": "5.1.1",
  "title": {
    "innerContent": {
      "desktop": { "value": { "text": "Feature Name" } }
    }
  },
  "content": {
    "innerContent": { "desktop": { "value": "\u003cp\u003eShort description of the feature.\u003c/p\u003e" } }
  },
  "imageIcon": {
    "innerContent": {
      "desktop": {
        "value": {
          "useIcon": "on",
          "icon": { "unicode": "&#xf0e7;", "type": "fa", "weight": "900" }
        }
      }
    }
  }
} /-->
```

- **Title is an object** `{text}`, NOT a plain string. Plain string → title silently absent from rendered HTML.
- **`useIcon: "on"` is required** when `icon` is set. Without it, the `.et-pb-icon` span renders empty — icon absent.
- Default title tag is `<h4>`. Override via `title.decoration.font.font.desktop.value.headingLevel` (same double-`font` shape as Heading).
- Icon `unicode` is the HTML-entity form `"&#xNNNN;"` (e.g. `"&#xf0e7;"` for FontAwesome bolt; Divi built-ins also use `&#xNNNN;`). Paste `diviops_meta_find_icon`'s `unicode` field verbatim.
- Swap to image mode: omit `useIcon` and set `imageIcon.innerContent.desktop.value` to `{src, id, alt, titleText}`.

---

## `divi/icon`

```
<!-- wp:divi/icon {
  "builderVersion": "5.1.1",
  "icon": {
    "innerContent": {
      "desktop": {
        "value": { "unicode": "&#xf0e7;", "type": "fa", "weight": "900" }
      }
    },
    "advanced": {
      "color": { "desktop": { "value": "#6366f1" } },
      "size": { "desktop": { "value": "48px" } }
    }
  }
} /-->
```

- Icon metadata: `unicode` + `type` (`"fa"` for FontAwesome, `"divi"` for Divi built-in) + `weight` (FA: `"400"` regular, `"900"` solid).
- **`unicode` is the HTML-entity form `"&#xNNNN;"`** — paste `diviops_meta_find_icon`'s `unicode` field verbatim (it returns `"&#xf0e7;"`, not a raw `\u` glyph). *(verified 2026-06-04)*
- **Use the native `divi/icon` module for icons — do NOT substitute a text/SVG carrier.** Native `divi/icon` is what makes Divi **enqueue the FontAwesome webfont**, and it injects the glyph as `.et-pb-icon` textContent client-side. Substitutes fail on this stack: a bare `<span style="font-family:FontAwesome">` renders a tofu box (nothing triggers the FA enqueue → font absent → fallback), and inline `<svg>` is `kses`-stripped on save from **both** `divi/text` and `divi/code` innerContent. *(verified live 2026-06-04, Divi 5.6.2)*
- **`render_preview` cannot verify icons** — `.et-pb-icon` is empty in `render_preview`/server HTML because the glyph is injected **client-side**. An empty span there is normal, not a broken module. Verify icons on the live frontend (see [SKILL.md → Design Quality Checklist](../SKILL.md#design-quality-checklist) → glyph coverage).
- Border/background go on `module.decoration` (NOT `icon.decoration.border/background` — that creates a non-VB-editable inner ring).

---

## `divi/image`

```
<!-- wp:divi/image {
  "builderVersion": "5.1.1",
  "image": {
    "innerContent": {
      "desktop": {
        "value": {
          "src": "https://example.com/image.jpg",
          "alt": "Descriptive alt text",
          "titleText": "Image title",
          "id": 0
        }
      }
    }
  }
} /-->
```

- **Exception**: `divi/image` uses `module.advanced` for sizing/spacing (NOT `module.decoration`). Most other modules use `module.decoration`.
- `src` is required for render. `alt` + `titleText` are recommended for a11y.
- `id` is the WordPress attachment ID. Use `0` for external URLs.

---

## `divi/contact-field`

The field-config split is the hard part here: the **label** is a plain string at `fieldItem.innerContent.desktop.value`, while `id`, `type`, `required`, and input constraints each live as **separate** `fieldItem.advanced.<key>.desktop.value` entries. Bundling them into one object at `innerContent` crashes Divi render — see the Gotcha note below.

`divi/contact-field` must live inside a `divi/contact-form`, which must live inside the usual Column → Row → Section → placeholder tree.

```
<!-- wp:divi/contact-form {"builderVersion":"5.3.2"} -->
<!-- wp:divi/contact-field {
  "builderVersion": "5.3.2",
  "fieldItem": {
    "innerContent": { "desktop": { "value": "Your Name" } },
    "advanced": {
      "id":       { "desktop": { "value": "name" } },
      "type":     { "desktop": { "value": "input" } },
      "required": { "desktop": { "value": "on" } }
    }
  }
} /-->
<!-- wp:divi/contact-field {
  "builderVersion": "5.3.2",
  "fieldItem": {
    "innerContent": { "desktop": { "value": "Your Email" } },
    "advanced": {
      "id":       { "desktop": { "value": "email" } },
      "type":     { "desktop": { "value": "email" } },
      "required": { "desktop": { "value": "on" } }
    }
  }
} /-->
<!-- /wp:divi/contact-form -->
```

- **`fieldItem.innerContent.desktop.value` is a plain string** (the label). Writing it as an object crashes the whole page — `MultiViewUtils::populate_data_content` throws `UnexpectedValueException`. See [SKILL.md → Module Gotchas](../SKILL.md#module-gotchas-silent-failures) → ContactField entry. Validator: `field_item_content_object` (error).
- **Field config is per-key under `fieldItem.advanced`** — each of `id`, `type`, `required`, `allowedSymbols`, `minLength`, `maxLength`, `radioOptions`, `checkboxOptions`, `selectOptions`, `booleanCheckboxOptions` has its own `.desktop.value`, NOT one `value` object combining them.
- **`type`** accepts `"input"` (default single-line text), `"email"`, `"text"` (textarea), `"select"`, `"radio"`, `"checkbox"`. Omit for `"input"`.
- **`id`** is used both as the field `name=` and the form-handler key — should be lowercase-alphanumeric. VB enforces page-wide uniqueness on save.

---

## Container Scaffolding

Any content module (Heading, Text, Button, Blurb, Icon, Image) must live inside a Column → Row → Section → placeholder tree. Minimum shape:

```
<!-- wp:divi/placeholder -->
<!-- wp:divi/section {
  "builderVersion": "5.1.1",
  "module": {
    "decoration": {
      "layout": { "desktop": { "value": { "display": "block" } } }
    }
  }
} -->
<!-- wp:divi/row {
  "builderVersion": "5.1.1",
  "module": {
    "decoration": {
      "layout": { "desktop": { "value": { "display": "block" } } }
    }
  }
} -->
<!-- wp:divi/column {
  "builderVersion": "5.1.1",
  "module": {
    "decoration": {
      "layout": { "desktop": { "value": { "display": "flex", "flexDirection": "column" } } }
    }
  }
} -->

<!-- YOUR CONTENT MODULES HERE -->

<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
<!-- /wp:divi/placeholder -->
```

- **Section / Row / Column / Group containers require a `layout.display` value** — either `"block"` or a flex value. Omitting `display` on a container is a validator warning (containers without layout display are a silent a11y/render surprise).
- Content modules (Heading, Text, Button, Blurb, Icon, Image) do NOT require `layout.display` — they render fine without it.

---

## Multi-Card Flex Row (Blurb/Group children)

A common pattern: 3 cards in a row. **Do not use `flexType: "8_24"` on the blurbs** — that's a column-layout grid attribute and is silently ignored on non-column modules. Use `module.decoration.sizing.desktop.value.width` instead:

```
<!-- wp:divi/group {
  "builderVersion": "5.1.1",
  "module": {
    "decoration": {
      "layout": {
        "desktop": {
          "value": {
            "display": "flex",
            "flexDirection": "row",
            "columnGap": "32px",
            "flexWrap": "wrap",
            "justifyContent": "center"
          }
        }
      }
    }
  }
} -->
<!-- wp:divi/blurb {
  "builderVersion": "5.1.1",
  "title": { "innerContent": { "desktop": { "value": { "text": "Card A" } } } },
  "content": { "innerContent": { "desktop": { "value": "\u003cp\u003eDescription.\u003c/p\u003e" } } },
  "imageIcon": {
    "innerContent": { "desktop": { "value": { "useIcon": "on", "icon": { "unicode": "&#xf0e7;", "type": "fa", "weight": "900" } } } }
  },
  "module": {
    "decoration": {
      "sizing": {
        "desktop": { "value": { "width": "calc(33.333% - 22px)" } },
        "tablet":  { "value": { "width": "calc(50% - 16px)" } },
        "phone":   { "value": { "width": "100%" } }
      }
    }
  }
} /-->
<!-- Repeat for Card B and Card C -->
<!-- /wp:divi/group -->
```

- **Desktop 3-up, tablet 2-up, phone stacked** via per-breakpoint `sizing.width` values.
- `calc(33.333% - 22px)` accounts for two 32px gaps distributed across three items: `2 × 32 / 3 ≈ 22`.
- Group parent needs `flexWrap: "wrap"` so tablet/phone breakpoints can rewrap.

---

## Validation

All snippets above pass `diviops_validate_blocks` with `valid: true, errors: [], warnings: []` on plugin v1.0.0-beta.35+. The validator catches the specific silent-failure patterns these snippets avoid — see [SKILL.md → Module Gotchas](../SKILL.md#module-gotchas-silent-failures) for the full list.
