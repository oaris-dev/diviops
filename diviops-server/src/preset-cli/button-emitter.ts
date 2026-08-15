/**
 * `divi/button` group-preset emitter.
 *
 * Emits a byte-canonical Divi 5.5.x `type: "group"` button preset at
 * `attrs.button.decoration.{background, border, font}`, gated by the
 * verified-attrs registry. Shape canon:
 * `docs/verification/canonical-preset-shapes-per-module-type-2026-05-18.md`
 * (`divi/button` section), cross-checked against
 * `docs/verification/evidence/canonical-shape-dumps-2026-05-18/round-3-button-canonical-complete.json`.
 *
 * Shape rules enforced here (brief §3):
 *  - Emit-on-specification only — omitted params produce NO keys.
 *  - Hover shape is one wrapper shallower: `background.desktop.hover.color`
 *    (NO `value` wrapper). Desktop is `background.desktop.value.color`.
 *  - Border radius vocabulary `{topLeft, topRight, bottomLeft, bottomRight,
 *    sync}`.
 *  - Font double-nesting: `font.font.desktop.value.{family, weight, color,
 *    size}`.
 *  - `$variable()` color tokens use the `{name, settings}` object shape
 *    with the trailing `)$`.
 *  - Do NOT emit `attrs.font`/`attrs.spacing` top-level, `renderAttrs`, or
 *    `button.decoration.button.desktop.value.*` — unless
 *    `bypass_hover_padding_gate: true`, which adds only
 *    `button.decoration.button.desktop.value.padding.top: "0px"`.
 */

import {
  loadRegistry,
  gateWriteAttr,
  type VerifiedAttrsRegistry,
} from "./registry.js";
import { normalizeColorValue } from "./variable-token.js";

export const BUTTON_MODULE = "divi/button";
export const BUTTON_GROUP_NAME = "divi/button";
export const BUTTON_GROUP_ID = "button";

/** Pattern families the button emitter touches in the registry. */
export const BUTTON_PATTERN_FAMILIES = {
  background: "divi/button.background",
  border: "divi/button.border",
  font: "divi/button.font",
} as const;

export interface ButtonRadiusInput {
  topLeft?: string;
  topRight?: string;
  bottomLeft?: string;
  bottomRight?: string;
  /** Explicit override; otherwise auto-derived (see deriveRadiusSync). */
  sync?: "on" | "off";
}

export interface ButtonBorderStylesInput {
  width?: string;
  style?: string;
  color?: string;
}

export interface ButtonFontInput {
  family?: string;
  weight?: string;
  color?: string;
  size?: string;
}

export interface ButtonEmitterInput {
  /** Required display name for the preset. */
  name: string;
  /** Desktop background color — literal hex or bare/formed variable token. */
  bg_color?: string;
  /** Hover background color — literal hex or bare/formed variable token. */
  bg_color_hover?: string;
  /** Border radius composable widget. */
  radius?: ButtonRadiusInput;
  /** Outline-button border styles (`styles.all`). */
  border?: ButtonBorderStylesInput;
  /** Button font. */
  font?: ButtonFontInput;
  /** Opt-in hover-padding-gate bypass corner (default false). */
  bypass_hover_padding_gate?: boolean;
}

/** The composed canonical preset entry shape sent to `/preset/create`. */
export interface ButtonPresetEntry {
  type: "group";
  module_name: string;
  group_name: string;
  group_id: string;
  name: string;
  attrs: Record<string, unknown>;
}

/**
 * Derive the radius `sync` flag when the caller did not pass it explicitly.
 *
 * Per Rule 1a in `docs/verification/canonical-preset-shapes-per-module-type-2026-05-18.md`:
 * composable-widget sync flags default to `"off"` when any sub-field is
 * touched. Auto-deriving `"on"` is only correct when ALL FOUR corners are
 * specified AND identical — a partial corner set (or a single corner) must
 * derive `"off"`, never `"on"` (a 1-element set is trivially "all equal").
 * An explicitly-passed `radius.sync` always wins.
 */
function deriveRadiusSync(radius: ButtonRadiusInput): "on" | "off" {
  if (radius.sync === "on" || radius.sync === "off") return radius.sync;
  const corners = [
    radius.topLeft,
    radius.topRight,
    radius.bottomLeft,
    radius.bottomRight,
  ].filter((v): v is string => typeof v === "string");
  if (corners.length === 0) return "off";
  return corners.length === 4 && corners.every((v) => v === corners[0])
    ? "on"
    : "off";
}

function isPlainObject(v: unknown): v is Record<string, unknown> {
  return (
    !!v && typeof v === "object" && !Array.isArray(v)
  );
}

/** Deep clone via structuredClone with a JSON fallback. */
export function deepClone<T>(value: T): T {
  if (typeof structuredClone === "function") return structuredClone(value);
  return JSON.parse(JSON.stringify(value)) as T;
}

/**
 * Compose the canonical `attrs.button.decoration.*` bag from the input.
 * Emit-on-specification: only specified sub-fields produce keys.
 */
export function composeButtonAttrs(
  input: ButtonEmitterInput,
): Record<string, unknown> {
  const decoration: Record<string, unknown> = {};

  // --- background -----------------------------------------------------
  if (input.bg_color !== undefined || input.bg_color_hover !== undefined) {
    const desktop: Record<string, unknown> = {};
    if (input.bg_color !== undefined) {
      desktop.value = { color: normalizeColorValue(input.bg_color) };
    }
    if (input.bg_color_hover !== undefined) {
      // Hover is one wrapper shallower — NO `value` between hover and color.
      desktop.hover = { color: normalizeColorValue(input.bg_color_hover) };
    }
    decoration.background = { desktop };
  }

  // --- border ---------------------------------------------------------
  const borderValue: Record<string, unknown> = {};
  if (input.radius) {
    const r = input.radius;
    const radius: Record<string, unknown> = {};
    if (r.topLeft !== undefined) radius.topLeft = r.topLeft;
    if (r.topRight !== undefined) radius.topRight = r.topRight;
    if (r.bottomLeft !== undefined) radius.bottomLeft = r.bottomLeft;
    if (r.bottomRight !== undefined) radius.bottomRight = r.bottomRight;
    if (Object.keys(radius).length > 0) {
      // Composable widget: `sync` emits alongside any corner.
      radius.sync = deriveRadiusSync(r);
      borderValue.radius = radius;
    }
  }
  if (input.border) {
    const b = input.border;
    const all: Record<string, unknown> = {};
    if (b.width !== undefined) all.width = b.width;
    if (b.style !== undefined) all.style = b.style;
    if (b.color !== undefined) all.color = normalizeColorValue(b.color);
    if (Object.keys(all).length > 0) {
      borderValue.styles = { all };
    }
  }
  if (Object.keys(borderValue).length > 0) {
    decoration.border = { desktop: { value: borderValue } };
  }

  // --- font (double-nested font.font) --------------------------------
  if (input.font) {
    const f = input.font;
    const value: Record<string, unknown> = {};
    if (f.family !== undefined) value.family = f.family;
    if (f.weight !== undefined) value.weight = f.weight;
    if (f.color !== undefined) value.color = normalizeColorValue(f.color);
    if (f.size !== undefined) value.size = f.size;
    if (Object.keys(value).length > 0) {
      decoration.font = { font: { desktop: { value } } };
    }
  }

  // --- hover-padding-gate bypass (opt-in only) -----------------------
  if (input.bypass_hover_padding_gate === true) {
    decoration.button = { desktop: { value: { padding: { top: "0px" } } } };
  }

  return { button: { decoration } };
}

/**
 * Gate every pattern family the composed attrs touch against the registry.
 * Throws `EvidenceGateError` if any touched family is below the
 * `VB_PRESET_STORAGE_VERIFIED` threshold. The `divi/button` decoration
 * slot (`button.decoration.button.*`) used by the bypass corner is part
 * of the `divi/button.border`/baseline write surface; it is not separately
 * gated because the bypass corner is an explicit opt-in workaround, not a
 * styling attr — but the three styling families ARE gated.
 */
export function gateButtonAttrs(
  attrs: Record<string, unknown>,
  registry: VerifiedAttrsRegistry,
): void {
  const decoration = (
    isPlainObject(attrs.button) ? attrs.button.decoration : undefined
  ) as Record<string, unknown> | undefined;
  if (!isPlainObject(decoration)) return;

  if (decoration.background !== undefined) {
    gateWriteAttr(registry, BUTTON_MODULE, BUTTON_PATTERN_FAMILIES.background);
  }
  if (decoration.border !== undefined) {
    gateWriteAttr(registry, BUTTON_MODULE, BUTTON_PATTERN_FAMILIES.border);
  }
  if (decoration.font !== undefined) {
    gateWriteAttr(registry, BUTTON_MODULE, BUTTON_PATTERN_FAMILIES.font);
  }
}

/**
 * Emit a canonical `divi/button` group preset.
 *
 * 1. Compose `attrs.button.decoration.*` (emit-on-specification).
 * 2. Gate every touched styling family against the verified-attrs
 *    registry — throws if any is below `VB_PRESET_STORAGE_VERIFIED`.
 * 3. Return the canonical preset entry. `styleAttrs` and `renderAttrs` are
 *    intentionally NOT part of this entry — the plugin's `/preset/create`
 *    route mirrors the single `attrs` bag into all three buckets, writing
 *    `attrs == styleAttrs == renderAttrs` to match VB save semantics
 *    (see `trait-preset.php` `preset_create`).
 */
export function emitButtonGroupPreset(
  input: ButtonEmitterInput,
  registry: VerifiedAttrsRegistry = loadRegistry(),
): ButtonPresetEntry {
  if (!input.name || typeof input.name !== "string") {
    throw new Error("Button emitter requires a non-empty `name`.");
  }

  const attrs = composeButtonAttrs(input);

  const decoration = (attrs.button as Record<string, unknown>).decoration as
    | Record<string, unknown>
    | undefined;
  if (!decoration || Object.keys(decoration).length === 0) {
    throw new Error(
      "Button emitter produced an empty preset — pass at least one of " +
        "bg_color, bg_color_hover, radius, border, or font.",
    );
  }

  gateButtonAttrs(attrs, registry);

  return {
    type: "group",
    module_name: BUTTON_MODULE,
    group_name: BUTTON_GROUP_NAME,
    group_id: BUTTON_GROUP_ID,
    name: input.name,
    attrs,
  };
}

/**
 * Build the `POST /diviops/v1/preset/create` request body from a preset
 * entry. Matches the body shape the `diviops_preset_create` MCP tool
 * posts — the CLI reuses the existing route, it does not add one.
 */
export function buildPresetCreateBody(
  entry: ButtonPresetEntry,
  opts: { dry_run?: boolean } = {},
): Record<string, unknown> {
  const body: Record<string, unknown> = {
    module_name: entry.module_name,
    name: entry.name,
    attrs: entry.attrs,
    type: entry.type,
    group_name: entry.group_name,
    group_id: entry.group_id,
  };
  if (opts.dry_run) body.dry_run = true;
  return body;
}
