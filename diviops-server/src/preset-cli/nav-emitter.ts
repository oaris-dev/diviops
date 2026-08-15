/**
 * `diviops-preset nav` block-markup emitter.
 *
 * Emits small, Free-safe Divi 5 navigation skeletons from a compact JSON spec.
 * This MVP is dry-run only: it writes no WordPress content and does not add
 * MCP/plugin routes. The output is serialized Divi block markup with the
 * high-signal nav reliability contracts encoded up front:
 *
 * - one leaf `divi/text` button trigger with `aria-controls`;
 * - one `divi/dropdown` panel with the matching custom `id`;
 * - `forceVisible:"whileInBuilder"` for Visual Builder editability;
 * - semantic submenu list shape: dropdown as `ul`, links wrapped with
 *   `htmlBefore:"<li>"` / `htmlAfter:"</li>"`;
 * - non-empty boolean-style custom attrs;
 * - wrapper-level tap-highlight suppression and a CSS-only floating-width
 *   guard, because Divi floating dropdowns can ignore VB sizing controls.
 */

export interface NavLinkSpec {
  label: string;
  url: string;
  target?: "off" | "on" | "_self" | "_blank";
}

interface ParsedNavLinkSpec {
  label: string;
  url: string;
  target: "off" | "on";
}

interface ParsedNavSectionSpec {
  label: string;
  links: ParsedNavLinkSpec[];
}

interface ParsedNavSpec {
  label?: string;
  desktopLabel?: string;
  mobileLabel?: string;
  idPrefix?: string;
  items: Array<ParsedNavLinkSpec | ParsedNavSectionSpec>;
  desktopItems?: Array<ParsedNavLinkSpec | ParsedNavSectionSpec>;
  mobileItems?: Array<ParsedNavLinkSpec | ParsedNavSectionSpec>;
}

export interface NavItemSpec {
  label: string;
  url?: string;
  target?: NavLinkSpec["target"];
  links?: NavLinkSpec[];
}

export interface NavSpec {
  label?: string;
  desktopLabel?: string;
  mobileLabel?: string;
  idPrefix?: string;
  items?: NavItemSpec[];
  desktopItems?: NavItemSpec[];
  mobileItems?: NavItemSpec[];
}

export type NavEmitterMode = "drawer" | "responsive_split";

export interface NavEmitterInput {
  name: string;
  spec: NavSpec;
  mode?: NavEmitterMode;
}

export interface NavMarkupEntry {
  type: "block_markup";
  format: "divi_block_markup";
  name: string;
  markup: string;
  notes: string[];
}

const BUILDER_VERSION = "5.7.4";

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return !!value && typeof value === "object" && !Array.isArray(value);
}

function assertNonEmptyString(value: unknown, field: string): string {
  if (typeof value !== "string" || value.trim() === "") {
    throw new Error(`nav spec requires non-empty ${field}.`);
  }
  return value.trim();
}

function normalizeTarget(target: NavLinkSpec["target"] | undefined): "off" | "on" {
  if (target === undefined || target === "off" || target === "_self") {
    return "off";
  }
  if (target === "on" || target === "_blank") {
    return "on";
  }
  throw new Error(
    `nav spec link target must be "off", "on", "_self", or "_blank"; got ${JSON.stringify(target)}.`,
  );
}

function slugifyId(value: string): string {
  const slug = value
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9_-]+/g, "-")
    .replace(/^-+|-+$/g, "");
  return slug || "nav";
}

function textContent(html: string): Record<string, unknown> {
  return {
    content: {
      innerContent: {
        desktop: {
          value: html,
        },
      },
    },
  };
}

function htmlAttrs(value: Record<string, unknown>): Record<string, unknown> {
  return {
    module: {
      advanced: {
        html: {
          desktop: {
            value,
          },
        },
      },
    },
  };
}

function mergeObjects(
  ...objects: Array<Record<string, unknown>>
): Record<string, unknown> {
  const merged: Record<string, unknown> = {};
  for (const object of objects) {
    for (const [key, value] of Object.entries(object)) {
      if (isPlainObject(value) && isPlainObject(merged[key])) {
        merged[key] = mergeObjects(merged[key] as Record<string, unknown>, value);
      } else {
        merged[key] = value;
      }
    }
  }
  return merged;
}

function customAttributes(
  attributes: Array<Record<string, string>>,
): Record<string, unknown> {
  return {
    module: {
      decoration: {
        attributes: {
          desktop: {
            value: {
              attributes: attributes.map((attr) => ({
                targetElement: "main",
                ...attr,
              })),
            },
          },
        },
      },
    },
  };
}

function withBuilderVersion(attrs: Record<string, unknown>): Record<string, unknown> {
  return { ...attrs, builderVersion: BUILDER_VERSION };
}

function escapeBlockJson(json: string): string {
  return json
    .replace(/\\\\/g, "\\u005c")
    .replace(/\\"/g, "\\u0022")
    .replace(/&/g, "\\u0026")
    .replace(/</g, "\\u003c")
    .replace(/>/g, "\\u003e")
    .replace(/--/g, "\\u002d\\u002d");
}

function serializeAttrs(attrs: Record<string, unknown>): string {
  return escapeBlockJson(JSON.stringify(withBuilderVersion(attrs)));
}

function serializeBlock(
  name: string,
  attrs: Record<string, unknown>,
  innerBlocks: string[] = [],
): string {
  const blockName = `divi/${name}`;
  const serializedAttrs = serializeAttrs(attrs);
  if (innerBlocks.length === 0) {
    return `<!-- wp:${blockName} ${serializedAttrs} /-->`;
  }

  return [
    `<!-- wp:${blockName} ${serializedAttrs} -->`,
    ...innerBlocks,
    `<!-- /wp:${blockName} -->`,
  ].join("\n");
}

type VisibilityValue = "on" | "off";

function disabledOnAttrs(values: {
  desktop: VisibilityValue;
  tablet: VisibilityValue;
  phone: VisibilityValue;
}): Record<string, unknown> {
  return {
    module: {
      decoration: {
        disabledOn: {
          desktop: { value: values.desktop },
          tablet: { value: values.tablet },
          phone: { value: values.phone },
        },
      },
    },
  };
}

function rootNavBlock(
  label: string,
  panelId: string,
  children: string[],
  opts: {
    customAttributes?: Array<Record<string, string>>;
    extraAttrs?: Record<string, unknown>;
  } = {},
): string {
  const css =
    "selector{-webkit-tap-highlight-color:transparent;}" +
    "selector a:focus-visible,selector button:focus-visible{outline:2px solid currentColor;outline-offset:3px;}" +
    "selector .et_pb_dropdown,selector .et_pb_dropdown_content{width:clamp(16rem,calc(100vw - 2rem),32rem)!important;min-width:0!important;max-width:none!important;}";

  return serializeBlock(
    "group",
    mergeObjects(
      htmlAttrs({ elementType: "nav" }),
      customAttributes([
        { name: "aria-label", value: label },
        { name: "data-diviops-nav", value: "true" },
        ...(opts.customAttributes ?? []),
      ]),
      opts.extraAttrs ?? {},
      {
        module: {
          decoration: {
            layout: {
              desktop: {
                value: {
                  display: "flex",
                  flexDirection: "column",
                  gap: "0px",
                },
              },
            },
          },
        },
        css: {
          desktop: {
            value: {
              freeForm: css,
            },
          },
        },
      },
    ),
    [
      serializeBlock(
        "text",
        mergeObjects(
          htmlAttrs({ elementType: "button" }),
          customAttributes([
            { name: "aria-controls", value: panelId },
            { name: "aria-expanded", value: "false" },
            { name: "data-diviops-nav-trigger", value: "true" },
          ]),
          textContent(`<span>${escapeHtml(label)}</span>`),
        ),
      ),
      ...children,
    ],
  );
}

function responsiveNavShellBlock(children: string[]): string {
  return serializeBlock(
    "group",
    mergeObjects(
      htmlAttrs({ elementType: "div" }),
      customAttributes([{ name: "data-diviops-responsive-nav", value: "true" }]),
      {
        module: {
          decoration: {
            layout: {
              desktop: {
                value: {
                  display: "flex",
                  flexDirection: "row",
                  alignItems: "center",
                  gap: "16px",
                },
              },
              tablet: {
                value: {
                  flexDirection: "column",
                  alignItems: "stretch",
                  gap: "0px",
                },
              },
            },
          },
        },
      },
    ),
    children,
  );
}

function dropdownBlock(panelId: string, children: string[]): string {
  return serializeBlock(
    "dropdown",
    mergeObjects(
      htmlAttrs({ elementType: "ul" }),
      customAttributes([
        { name: "id", value: panelId },
        { name: "role", value: "menu" },
        { name: "aria-hidden", value: "true" },
      ]),
      {
        module: {
          meta: {
            meta: {
              forceVisible: {
                desktop: {
                  value: "whileInBuilder",
                },
              },
            },
          },
          advanced: {
            dropdown: {
              desktop: {
                value: {
                  showOn: "click",
                  position: "floating",
                  direction: "below",
                  alignment: "start",
                },
              },
            },
          },
          decoration: {
            layout: {
              desktop: {
                value: {
                  display: "flex",
                  flexDirection: "column",
                  gap: "0px",
                },
              },
            },
          },
        },
      },
    ),
    children,
  );
}

function linkBlock(link: ParsedNavLinkSpec): string {
  return serializeBlock(
    "link",
    mergeObjects(
      htmlAttrs({
        htmlBefore: '<li role="none">',
        htmlAfter: "</li>",
      }),
      customAttributes([{ name: "role", value: "menuitem" }]),
      {
        link: {
          innerContent: {
            desktop: {
              value: {
                text: link.label,
                url: link.url,
                target: link.target,
              },
            },
          },
        },
      },
    ),
  );
}

function sectionLabelBlock(label: string): string {
  return serializeBlock(
    "text",
    mergeObjects(
      htmlAttrs({ elementType: "li" }),
      customAttributes([{ name: "data-diviops-nav-section", value: "true" }]),
      textContent(`<span>${escapeHtml(label)}</span>`),
    ),
  );
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function parseLinkSpec(raw: unknown, field: string): ParsedNavLinkSpec {
  if (!isPlainObject(raw)) {
    throw new Error(`nav spec ${field} must be an object.`);
  }
  const label = assertNonEmptyString(raw.label, `${field}.label`);
  const url = assertNonEmptyString(raw.url, `${field}.url`);
  const target = normalizeTarget(raw.target as NavLinkSpec["target"] | undefined);
  return { label, url, target };
}

function parseNavItems(
  itemsRaw: unknown,
  field: string,
): Array<ParsedNavLinkSpec | ParsedNavSectionSpec> {
  if (!Array.isArray(itemsRaw) || itemsRaw.length === 0) {
    throw new Error(`nav spec requires a non-empty ${field} array.`);
  }
  return itemsRaw.map(
    (itemRaw, itemIndex): ParsedNavLinkSpec | ParsedNavSectionSpec => {
      if (!isPlainObject(itemRaw)) {
        throw new Error(`nav spec ${field}[${itemIndex}] must be an object.`);
      }
      const label = assertNonEmptyString(
        itemRaw.label,
        `${field}[${itemIndex}].label`,
      );
      if (Array.isArray(itemRaw.links)) {
        if (itemRaw.links.length === 0) {
          throw new Error(`nav spec ${field}[${itemIndex}].links must not be empty.`);
        }
        return {
          label,
          links: itemRaw.links.map((link, linkIndex) =>
            parseLinkSpec(link, `${field}[${itemIndex}].links[${linkIndex}]`),
          ),
        };
      }

      return parseLinkSpec(itemRaw, `${field}[${itemIndex}]`);
    },
  );
}

export function parseNavSpec(raw: unknown): ParsedNavSpec {
  if (!isPlainObject(raw)) {
    throw new Error("nav spec must be a JSON object.");
  }

  const items = raw.items !== undefined ? parseNavItems(raw.items, "items") : undefined;
  const desktopItems =
    raw.desktopItems !== undefined
      ? parseNavItems(raw.desktopItems, "desktopItems")
      : undefined;
  const mobileItems =
    raw.mobileItems !== undefined
      ? parseNavItems(raw.mobileItems, "mobileItems")
      : undefined;

  if (!items && !desktopItems && !mobileItems) {
    throw new Error("nav spec requires a non-empty items array.");
  }

  const spec: ParsedNavSpec = {
    items: items ?? desktopItems ?? mobileItems ?? [],
  };

  if (raw.label !== undefined) {
    spec.label = assertNonEmptyString(raw.label, "label");
  }
  if (raw.desktopLabel !== undefined) {
    spec.desktopLabel = assertNonEmptyString(raw.desktopLabel, "desktopLabel");
  }
  if (raw.mobileLabel !== undefined) {
    spec.mobileLabel = assertNonEmptyString(raw.mobileLabel, "mobileLabel");
  }
  if (raw.idPrefix !== undefined) {
    spec.idPrefix = assertNonEmptyString(raw.idPrefix, "idPrefix");
  }
  if (desktopItems) {
    spec.desktopItems = desktopItems;
  }
  if (mobileItems) {
    spec.mobileItems = mobileItems;
  }

  return spec;
}

export function parseNavSpecJson(json: string): ParsedNavSpec {
  let parsed: unknown;
  try {
    parsed = JSON.parse(json);
  } catch (err) {
    throw new Error(
      `nav spec must be valid JSON: ${err instanceof Error ? err.message : String(err)}`,
    );
  }
  return parseNavSpec(parsed);
}

export function emitNavBlockMarkup(input: NavEmitterInput): NavMarkupEntry {
  const name = assertNonEmptyString(input.name, "name");
  const spec = parseNavSpec(input.spec);
  const mode = input.mode ?? "drawer";
  const label = spec.label ?? name;
  const idPrefix = slugifyId(spec.idPrefix ?? name);
  const panelId = `${idPrefix}-panel`;

  const buildListChildren = (
    items: Array<ParsedNavLinkSpec | ParsedNavSectionSpec>,
  ): string[] => {
    const listChildren: string[] = [];
    for (const item of items) {
    if ("links" in item) {
      listChildren.push(sectionLabelBlock(item.label));
      for (const link of item.links) {
        listChildren.push(linkBlock(link));
      }
    } else {
      listChildren.push(linkBlock(item));
    }
  }
    return listChildren;
  };

  if (mode === "responsive_split") {
    const desktopPanelId = `${idPrefix}-desktop-panel`;
    const mobilePanelId = `${idPrefix}-mobile-panel`;
    const desktopLabel = spec.desktopLabel ?? `${label} desktop`;
    const mobileLabel = spec.mobileLabel ?? `${label} mobile`;
    const desktopItems = spec.desktopItems ?? spec.items;
    const mobileItems = spec.mobileItems ?? spec.items;

    const markup = responsiveNavShellBlock([
      rootNavBlock(
        desktopLabel,
        desktopPanelId,
        [dropdownBlock(desktopPanelId, buildListChildren(desktopItems))],
        {
          customAttributes: [{ name: "data-diviops-nav-unit", value: "desktop" }],
          extraAttrs: disabledOnAttrs({
            desktop: "off",
            tablet: "on",
            phone: "on",
          }),
        },
      ),
      rootNavBlock(
        mobileLabel,
        mobilePanelId,
        [dropdownBlock(mobilePanelId, buildListChildren(mobileItems))],
        {
          customAttributes: [{ name: "data-diviops-nav-unit", value: "mobile" }],
          extraAttrs: disabledOnAttrs({
            desktop: "on",
            tablet: "off",
            phone: "off",
          }),
        },
      ),
    ]);

    return {
      type: "block_markup",
      format: "divi_block_markup",
      name,
      markup,
      notes: [
        "Dry-run only: no WordPress write path is invoked.",
        "Responsive split output contains two sibling nav units: desktop is disabled on tablet/phone, and mobile is disabled on desktop.",
        "The split uses native Divi disabledOn visibility and emits no breakpoint JavaScript.",
        "Use targeted create/clone/tree-surgery flows for placement; do not route this through whole-layout update paths.",
        "Floating dropdown width is controlled by emitted CSS; Divi VB sizing controls may not constrain floating panels.",
        "If custom runtime code observes style and writes style, guard unrendered dropdowns with getClientRects().length and make style writes idempotent.",
      ],
    };
  }

  const markup = rootNavBlock(label, panelId, [
    dropdownBlock(panelId, buildListChildren(spec.items)),
  ]);

  return {
    type: "block_markup",
    format: "divi_block_markup",
    name,
    markup,
    notes: [
      "Dry-run only: no WordPress write path is invoked.",
      "Use targeted create/clone/tree-surgery flows for placement; do not route this through whole-layout update paths.",
      "Floating dropdown width is controlled by emitted CSS; Divi VB sizing controls may not constrain floating panels.",
      "If custom runtime code observes style and writes style, guard unrendered dropdowns with getClientRects().length and make style writes idempotent.",
    ],
  };
}

export function buildNavMarkupBody(
  entry: NavMarkupEntry,
  opts: { dry_run?: boolean } = {},
): Record<string, unknown> {
  const body: Record<string, unknown> = {
    type: entry.type,
    format: entry.format,
    name: entry.name,
    markup: entry.markup,
    notes: entry.notes,
  };
  if (opts.dry_run) body.dry_run = true;
  return body;
}
