/**
 * `divi/spacing` section group-preset emitter.
 *
 * Emits a byte-canonical Divi 5.6.0 `type: "group"` `divi/spacing` preset
 * targeting `divi/section` at
 * `attrs.module.decoration.spacing.desktop.value.{padding|margin}.*`,
 * gated by the verified-attrs registry. Canonical shape captured under
 * `docs/verification/evidence/canonical-shape-dumps-2026-05-23/round-5-spacing-section.json`.
 *
 * Scope: `divi/section` ONLY. Heading / text / button spacing cells remain
 * `SCHEMA_OBSERVED` and resolve to `EvidenceGateError` here per
 * `feedback_preset_map_per_module` (no cross-module shape carry-over).
 *
 * Shape rules enforced here (brief §4):
 *  - Sparse-emit per axis — only user-touched corners appear under
 *    `padding`/`margin`. Untouched corners are absent (not present-as-empty,
 *    not present-as-null).
 *  - Paired sync flags per axis — any spacing touch on an axis emits BOTH
 *    `syncVertical` AND `syncHorizontal` as siblings. Default `"off"` on
 *    both unless the caller explicitly toggled them.
 *  - Padding and margin are INDEPENDENT bags — passing only padding flags
 *    omits the margin bag entirely, and vice versa.
 *  - `attrs == styleAttrs == renderAttrs` at the route layer (the plugin's
 *    `/preset/create` route mirrors `attrs` into all three buckets to match
 *    VB save semantics); the CLI request body only carries `attrs`. The
 *    canonical capture fixture documents the post-write storage shape — do
 *    NOT add `styleAttrs` / `renderAttrs` keys to the emitter output.
 *  - `groupId: "designSpacing"` — the Composable Settings panel id, NOT a
 *    dotted attr path (the prior schema-inferred `module.decoration.spacing`
 *    note was a misread, now corrected).
 *  - `primaryAttrName: "module"` for section spacing.
 *  - Variable tokens (`$variable(...)` / bare `gvid-*`) in length flags are
 *    REFUSED — the canonical capture exercised literal CSS lengths only;
 *    variable-token shape needs its own capture before this emitter writes
 *    it.
 */

import {
  loadRegistry,
  gateWriteAttr,
  type VerifiedAttrsRegistry,
} from "./registry.js";

export const SPACING_GROUP_NAME = "divi/spacing";
export const SPACING_GROUP_ID = "designSpacing";
export const SPACING_PRIMARY_ATTR_NAME = "module";
export const SPACING_PATTERN_FAMILY = "divi/spacing";

/**
 * The single currently-supported module cell. The `--module` flag accepts
 * any string and routes through the registry gate (so the surface stays
 * stable when heading/text/button cells eventually promote), but only
 * `divi/section` is wired with fixtures + tests + canonical shape today.
 *
 * Promoting another module is NOT a free dispatch-clear via the registry
 * gate — each new cell needs a canonical-capture change landing first AND
 * a follow-up implementation/docs change.
 */
export const SPACING_SUPPORTED_MODULES = ["divi/section"] as const;

export type SpacingCornerInput = {
  top?: string;
  right?: string;
  bottom?: string;
  left?: string;
  syncVertical?: "on" | "off";
  syncHorizontal?: "on" | "off";
};

export interface SpacingEmitterInput {
  /** Required display name for the preset. */
  name: string;
  /**
   * Required module selector. Forward-compat-shaped; only `divi/section` is
   * wired today, everything else lands on `EvidenceGateError` via the
   * registry gate (or a SCHEMA_OBSERVED cell evidence-gate refusal).
   */
  module: string;
  /** Desktop padding corner-and-sync bag. */
  padding?: SpacingCornerInput;
  /** Desktop margin corner-and-sync bag. */
  margin?: SpacingCornerInput;
}

/** The composed canonical preset entry shape sent to `/preset/create`. */
export interface SpacingPresetEntry {
  type: "group";
  module_name: string;
  group_name: string;
  group_id: string;
  primary_attr_name: string;
  name: string;
  attrs: Record<string, unknown>;
}

/** Detect bare `gvid-*` / `gcid-*` variable token names. */
const BARE_VARIABLE_TOKEN_RE = /^(gvid|gcid)-/;

/**
 * Positive validator for CSS length values. Accepts only the v1 unit set
 * from the brief: integer or decimal numbers (including a leading minus
 * sign and bare `0`) followed by `px`, `rem`, `em`, `%`, `vw`, or `vh`.
 * The canonical capture exercised literal values only — variable tokens,
 * `var(...)`, `calc(...)`, and free-form strings like `banana` are all
 * refused before emission. Adding any new unit requires a brief update
 * (canonical-capture re-test if the unit changes server-side rendering).
 */
const LITERAL_CSS_LENGTH_RE = /^-?(?:\d+\.?\d*|\.\d+)(?:px|rem|em|%|vw|vh)$/;

/**
 * Validate that `value` is a literal CSS length and not a deferred-shape
 * token. The check runs on EVERY length flag before the emission path.
 *
 * Three refusal paths:
 *  1. `$variable(...)` token — deferred shape, needs its own capture.
 *  2. Bare `gvid-*` / `gcid-*` token name — same deferred shape.
 *  3. Anything else that does NOT match the v1 literal CSS length grammar
 *     (`px`, `rem`, `em`, `%`, `vw`, `vh`). Catches `banana`, `var(--x)`,
 *     `calc(8px + 2vw)`, etc. — none of which the canonical capture verified.
 *
 * The variable-token branches fire BEFORE the generic literal check so
 * the operator gets the precise "variable-token support deferred" hint
 * rather than the generic "not a literal CSS length" message.
 */
function assertLiteralLength(value: string, flagLabel: string): void {
  // Trim before pattern-matching so leading / trailing whitespace can't
  // bypass any prefix check (e.g. " gvid-space-1"). The raw value
  // appears in the error message so the operator sees exactly what was
  // passed.
  const trimmed = value.trim();
  if (trimmed.startsWith("$variable(")) {
    throw new Error(
      `${flagLabel} value ${JSON.stringify(value)} is a $variable() token. ` +
        `Variable-token support deferred — pending canonical capture. ` +
        `Canonical capture exercised literal CSS length values only (px / rem / em / % / vw / vh).`,
    );
  }
  if (BARE_VARIABLE_TOKEN_RE.test(trimmed)) {
    throw new Error(
      `${flagLabel} value ${JSON.stringify(value)} is a bare ${trimmed.split("-")[0]}-* variable token. ` +
        `Variable-token support deferred — pending canonical capture. ` +
        `Canonical capture exercised literal CSS length values only (px / rem / em / % / vw / vh).`,
    );
  }
  if (!LITERAL_CSS_LENGTH_RE.test(trimmed)) {
    throw new Error(
      `${flagLabel} value ${JSON.stringify(value)} is not a literal CSS length. ` +
        `v1 accepts only \`<number><unit>\` where unit is one of px / rem / em / % / vw / vh ` +
        `(e.g. "40px", "1.5rem", "100%"). The canonical capture exercised literal ` +
        `values only; broader value grammars (var(...), calc(...), etc.) need their own capture.`,
    );
  }
}

/**
 * Compose a single axis bag (padding or margin) from a corner input.
 *
 * Returns `undefined` when the axis input is entirely absent (the axis is
 * absent from the emitted attrs — sparse-emit at axis level). When ANY
 * corner is passed, both sync flags are emitted as paired siblings
 * (default `"off"` on each side unless the caller explicitly toggled
 * them).
 *
 * Sync-flag-only input on an axis with no touched corner is REFUSED with
 * an explicit error — silently no-op'ing a passed sync flag would be a
 * surprising footgun. If the caller wants to toggle sync, they must also
 * pass at least one corner on the same axis to anchor it.
 */
function composeAxis(
  input: SpacingCornerInput | undefined,
  flagPrefix: string,
): Record<string, unknown> | undefined {
  if (!input) return undefined;
  const corners = (["top", "right", "bottom", "left"] as const).filter(
    (c) => input[c] !== undefined,
  );
  if (corners.length === 0) {
    // Sync flag passed without any corner on the same axis → explicit
    // refusal. The paired sync flags only have meaning anchored to at
    // least one corner; emitting them alone would silently drop them.
    if (
      input.syncVertical !== undefined ||
      input.syncHorizontal !== undefined
    ) {
      throw new Error(
        `--${flagPrefix}-sync-vertical / --${flagPrefix}-sync-horizontal require at ` +
          `least one --${flagPrefix}-{top,right,bottom,left} corner on the same axis. ` +
          `Sync flags are paired-sibling metadata anchored to corners; emitting them ` +
          `alone would silently no-op.`,
      );
    }
    return undefined;
  }

  const bag: Record<string, unknown> = {};
  for (const c of corners) {
    const v = input[c] as string;
    if (typeof v !== "string" || v.length === 0) {
      throw new Error(
        `--${flagPrefix}-${c} requires a non-empty CSS length value.`,
      );
    }
    assertLiteralLength(v, `--${flagPrefix}-${c}`);
    bag[c] = v;
  }
  bag.syncVertical = input.syncVertical ?? "off";
  bag.syncHorizontal = input.syncHorizontal ?? "off";
  return bag;
}

/**
 * Compose the canonical
 * `attrs.module.decoration.spacing.desktop.value.{padding|margin}` bag.
 * Sparse-emit per axis; padding and margin are independent.
 *
 * Returns BOTH the full `attrs` tree (what the emitter ships) AND the
 * inner `value` bag — the caller does empty-preset validation against
 * `value` without re-walking the nested attrs tree (no `as any` deep
 * casts).
 */
export function composeSpacingAttrs(
  input: SpacingEmitterInput,
): { attrs: Record<string, unknown>; value: Record<string, unknown> } {
  const padding = composeAxis(input.padding, "padding");
  const margin = composeAxis(input.margin, "margin");

  const value: Record<string, unknown> = {};
  if (padding) value.padding = padding;
  if (margin) value.margin = margin;

  const attrs: Record<string, unknown> = {
    [SPACING_PRIMARY_ATTR_NAME]: {
      decoration: {
        spacing: {
          desktop: {
            value,
          },
        },
      },
    },
  };
  return { attrs, value };
}

/**
 * Emit a canonical `divi/spacing` section group preset.
 *
 * 1. Validate input shape (name, module, at least one corner across either
 *    axis).
 * 2. Reject variable-token values in any length flag (deferred until a
 *    canonical capture lands).
 * 3. Compose sparse-emit `attrs.module.decoration.spacing.desktop.value.*`
 *    with paired sync flags.
 * 4. Gate `(divi/spacing, <module>)` against the verified-attrs registry —
 *    throws `EvidenceGateError` when effective evidence is below
 *    `VB_PRESET_STORAGE_VERIFIED`.
 *
 * `styleAttrs` and `renderAttrs` are intentionally NOT part of this entry:
 * the plugin's `/preset/create` route mirrors the single `attrs` bag into
 * all three buckets at write time. The canonical capture fixture documents
 * the post-write storage shape (which is why both `attrs` and `styleAttrs`
 * appear there byte-identical); the CLI emits the request shape only.
 */
export function emitSpacingGroupPreset(
  input: SpacingEmitterInput,
  registry: VerifiedAttrsRegistry = loadRegistry(),
): SpacingPresetEntry {
  if (!input.name || typeof input.name !== "string") {
    throw new Error("spacing emitter requires a non-empty `name`.");
  }
  if (!input.module || typeof input.module !== "string") {
    throw new Error(
      "spacing emitter requires `module` — currently only `divi/section` is " +
        "wired (other module cells are SCHEMA_OBSERVED in the registry; " +
        "promoting them requires a canonical-capture change + a follow-up " +
        "implementation/docs change).",
    );
  }

  const { attrs, value } = composeSpacingAttrs(input);

  // Empty-input rejection: at least one corner across either axis MUST be
  // specified. An empty value bag is a usage error — there is nothing to
  // write, and the resulting preset would be the empty-shell VB authoring
  // footgun documented in the canonical capture.
  if (Object.keys(value).length === 0) {
    throw new Error(
      "spacing emitter produced an empty preset — pass at least one " +
        "padding or margin corner (--padding-top, --margin-bottom, etc.).",
    );
  }

  // Registry gate: dispatches off `(divi/spacing, <module>)`. Cells below
  // VB_PRESET_STORAGE_VERIFIED (currently divi/heading, divi/text,
  // divi/button — all SCHEMA_OBSERVED) throw EvidenceGateError natively
  // here. Unknown modules with no applicability cell resolve to
  // UNVERIFIED (0) and also throw.
  gateWriteAttr(registry, input.module, SPACING_PATTERN_FAMILY);

  // Implementation-supported-modules guard: even if a future registry-only
  // PR promotes another cell to VB_PRESET_STORAGE_VERIFIED, this emitter
  // must NOT silently start emitting it — heading/text/button cells need
  // their own per-module wrapper (the section cell uses `module.*`, but a
  // heading-spacing capture might surface `title.*` or a different
  // primaryAttrName). Per `feedback_preset_map_per_module`, never assume
  // cross-module shape carry-over. Promoting another module requires
  // adding it to `SPACING_SUPPORTED_MODULES` alongside the fixture +
  // tests + README updates that prove the per-module shape.
  if (!(SPACING_SUPPORTED_MODULES as readonly string[]).includes(input.module)) {
    throw new Error(
      `spacing emitter does not yet implement module "${input.module}". ` +
        `Registry evidence cleared the cell, but the per-module wrapper + ` +
        `attr shape have not been verified against a canonical capture ` +
        `(this emitter hard-codes the divi/section wrapper "module" and ` +
        `primaryAttrName "module"). Supported modules: ` +
        `${SPACING_SUPPORTED_MODULES.join(", ")}. Adding a new module ` +
        `requires a canonical-capture change landing first AND ` +
        `a follow-up implementation change extending this constant alongside ` +
        `fixtures + tests + README updates.`,
    );
  }

  return {
    type: "group",
    module_name: input.module,
    group_name: SPACING_GROUP_NAME,
    group_id: SPACING_GROUP_ID,
    primary_attr_name: SPACING_PRIMARY_ATTR_NAME,
    name: input.name,
    attrs,
  };
}

/**
 * Build the `POST /diviops/v1/preset/create` request body from a spacing
 * preset entry. Matches the body shape the `diviops_preset_create` MCP
 * tool posts — the CLI reuses the existing route, it does not add one.
 *
 * `primary_attr_name` IS sent on the wire (the plugin's `/preset/create`
 * route accepts it as an optional snake_case param and stores it as
 * `primaryAttrName` in the preset). The button / font / color emitters
 * omit it because their preset types do not carry it; the divi/spacing
 * cell does, per the canonical capture's load-bearing finding #2.
 */
export function buildSpacingPresetCreateBody(
  entry: SpacingPresetEntry,
  opts: { dry_run?: boolean } = {},
): Record<string, unknown> {
  const body: Record<string, unknown> = {
    module_name: entry.module_name,
    name: entry.name,
    attrs: entry.attrs,
    type: entry.type,
    group_name: entry.group_name,
    group_id: entry.group_id,
    primary_attr_name: entry.primary_attr_name,
  };
  if (opts.dry_run) body.dry_run = true;
  return body;
}
