/**
 * `divi/font` heading group-preset emitter.
 *
 * Emits a byte-canonical Divi 5.5.x `type: "group"` `divi/font` preset
 * targeting `divi/heading` at `attrs.title.decoration.font.font.desktop.value.*`,
 * gated by the verified-attrs registry. Canonical shape:
 * `docs/verification/canonical-preset-shapes-per-module-type-2026-05-18.md`
 * (`divi/font` section), cross-checked against
 * `docs/verification/evidence/canonical-shape-dumps-2026-05-18/round-1a-heading-h1-pattern-a-google.json`
 * and `…/round-1b-heading-h1-pattern-b-local.json`.
 *
 * Two verified Pattern variants live in the registry — they are NOT
 * interchangeable evidence. The caller MUST select one explicitly:
 *
 *  - Pattern A (Google Fonts CDN): plain family + explicit numeric weight.
 *    Registry entry: `divi/font` with `pattern_variant: "google_fonts_pattern_a"`.
 *    Verified evidence: round-1a fixture.
 *  - Pattern B (local-hosted, EU-GDPR): weight encoded into the family
 *    string ("Sora 700") and NO `weight` key at all in the preset.
 *    Registry entry: `divi/font` with `pattern_variant: "local_hosted_pattern_b"`.
 *    Verified evidence: round-1b fixture.
 *
 * Shape rules enforced here:
 *  - Emit-on-specification only — omitted params produce NO keys.
 *  - `attrs == styleAttrs == renderAttrs` at the route layer (the plugin's
 *    `/preset/create` route mirrors `attrs` into all three buckets to match
 *    VB save semantics); the CLI request body only carries `attrs`.
 *  - Double-font nesting: `title.decoration.font.font.desktop.value.*` —
 *    outer `font` is the decoration category, inner `font` is the attr
 *    name within that category.
 *  - Pattern B with an explicit `weight` is REFUSED — the verified Pattern
 *    B capture has no `weight` key and there is no registry entry
 *    authorizing local-hosted-with-explicit-weight.
 */

import {
  loadRegistry,
  gateWriteAttr,
  type VerifiedAttrsRegistry,
} from "./registry.js";
import { normalizeColorValue } from "./variable-token.js";

export const HEADING_FONT_MODULE = "divi/heading";
export const HEADING_FONT_GROUP_NAME = "divi/font";
export const HEADING_FONT_GROUP_ID = "designTitleText";
export const HEADING_FONT_PATTERN_FAMILY = "divi/font";

/** The two verified pattern variants for `divi/font` on `divi/heading`. */
export const HEADING_FONT_PATTERN_VARIANTS = {
  google: "google_fonts_pattern_a",
  local: "local_hosted_pattern_b",
} as const;

export type HeadingFontPattern = "google" | "local";

export interface HeadingFontEmitterInput {
  /** Required display name for the preset. */
  name: string;
  /**
   * Required pattern selector. There is no default — Pattern A and
   * Pattern B are distinct registry/evidence variants and the caller
   * must select intentionally.
   */
  pattern: HeadingFontPattern;
  /**
   * Font family.
   *  - Pattern A (google): plain family name, e.g. `"Inter"`.
   *  - Pattern B (local):  weight-encoded family name, e.g. `"Sora 700"`.
   */
  family?: string;
  /**
   * Font weight (numeric-string, e.g. `"700"`).
   *  - Pattern A: optional; emitted when specified.
   *  - Pattern B: PROHIBITED — passing a weight in `local` mode is a
   *    usage error (the verified Pattern B shape has no `weight` key,
   *    and there is no registry entry vouching for that combination).
   */
  weight?: string;
  /** Font color — literal hex or bare/formed variable token. */
  color?: string;
  /** Font size — literal (e.g. `"48px"`) or already-formed `$variable(...)$`. */
  size?: string;
  /** Line height — optional, emit-on-specification only. */
  lineHeight?: string;
}

/** The composed canonical preset entry shape sent to `/preset/create`. */
export interface HeadingFontPresetEntry {
  type: "group";
  module_name: string;
  group_name: string;
  group_id: string;
  pattern_variant: string;
  name: string;
  attrs: Record<string, unknown>;
}

/**
 * Raised when the caller passes a combination the registry does not
 * authorize for the current Track — distinct from `EvidenceGateError`
 * (registry-evidence shortfall) and `UsageError` (CLI arg parse).
 */
export class UnsupportedVariantCombinationError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "UnsupportedVariantCombinationError";
  }
}

/**
 * Compose the canonical `attrs.title.decoration.font.font.desktop.value`
 * bag from the input. Emit-on-specification: only specified sub-fields
 * produce keys.
 */
export function composeHeadingFontAttrs(
  input: HeadingFontEmitterInput,
): Record<string, unknown> {
  const value: Record<string, unknown> = {};
  if (input.family !== undefined) value.family = input.family;
  if (input.weight !== undefined) value.weight = input.weight;
  if (input.color !== undefined) value.color = normalizeColorValue(input.color);
  if (input.size !== undefined) value.size = input.size;
  if (input.lineHeight !== undefined) value.lineHeight = input.lineHeight;

  return {
    title: {
      decoration: {
        font: {
          font: {
            desktop: {
              value,
            },
          },
        },
      },
    },
  };
}

/**
 * Emit a canonical `divi/font` heading group preset.
 *
 * 1. Validate input shape (name, pattern, Pattern B + weight refusal).
 * 2. Compose `attrs.title.decoration.font.font.desktop.value.*`
 *    (emit-on-specification).
 * 3. Gate the chosen `(divi/font, pattern_variant)` cell on `divi/heading`
 *    against the verified-attrs registry — throws `EvidenceGateError`
 *    when effective evidence is below `VB_PRESET_STORAGE_VERIFIED`.
 *
 * `styleAttrs` and `renderAttrs` are intentionally NOT part of this
 * entry: the plugin's `/preset/create` route mirrors the single `attrs`
 * bag into all three buckets, writing `attrs == styleAttrs == renderAttrs`
 * to match VB save semantics — see `trait-preset.php` `preset_create`.
 * The Round 1a/1b fixtures capture the post-write storage shape.
 */
export function emitHeadingFontGroupPreset(
  input: HeadingFontEmitterInput,
  registry: VerifiedAttrsRegistry = loadRegistry(),
): HeadingFontPresetEntry {
  if (!input.name || typeof input.name !== "string") {
    throw new Error("Heading-font emitter requires a non-empty `name`.");
  }
  if (input.pattern !== "google" && input.pattern !== "local") {
    throw new Error(
      `Heading-font emitter requires \`pattern\` to be "google" or "local"; got ${JSON.stringify(input.pattern)}. ` +
        `There is no default — Pattern A (google) and Pattern B (local) are distinct registry/evidence variants.`,
    );
  }

  // Pattern B + explicit weight is out-of-scope for the current emitter. The
  // verified Pattern B capture proves `weight` is absent; no registry entry
  // currently authorizes a local-hosted-with-explicit-weight shape.
  if (input.pattern === "local" && input.weight !== undefined) {
    throw new UnsupportedVariantCombinationError(
      `Pattern B (local-hosted) does not support an explicit \`weight\` — the verified ` +
        `Pattern B shape has no \`weight\` key, and there is no registry variant ` +
        `authorizing local-hosted-with-explicit-weight. Encode the weight into the ` +
        `family string instead (e.g. \`family: "Sora 700"\`). A future registry entry ` +
        `can authorize that shape if VB evidence supports it.`,
    );
  }

  const attrs = composeHeadingFontAttrs(input);

  // Sanity check: at least one styling field was specified. An empty
  // value bag is a usage error — there is nothing to write.
  const value = (attrs.title as any).decoration.font.font.desktop.value as
    | Record<string, unknown>
    | undefined;
  if (!value || Object.keys(value).length === 0) {
    throw new Error(
      "Heading-font emitter produced an empty preset — pass at least one of " +
        "family, weight, color, size, or lineHeight.",
    );
  }

  // Registry gate: variant-aware. Pattern A evidence must NOT vouch for
  // Pattern B and vice versa — gateWriteAttr resolves by both family AND
  // variant, so a missing/under-verified variant entry throws here.
  const patternVariant = HEADING_FONT_PATTERN_VARIANTS[input.pattern];
  gateWriteAttr(
    registry,
    HEADING_FONT_MODULE,
    HEADING_FONT_PATTERN_FAMILY,
    patternVariant,
  );

  return {
    type: "group",
    module_name: HEADING_FONT_MODULE,
    group_name: HEADING_FONT_GROUP_NAME,
    group_id: HEADING_FONT_GROUP_ID,
    pattern_variant: patternVariant,
    name: input.name,
    attrs,
  };
}

/**
 * Build the `POST /diviops/v1/preset/create` request body from a heading
 * font preset entry. Matches the body shape the `diviops_preset_create`
 * MCP tool posts — the CLI reuses the existing route, it does not add one.
 *
 * `pattern_variant` is informational metadata on the in-memory entry and
 * is NOT sent over the wire — the registry-gate decision happens
 * client-side before the write.
 */
export function buildHeadingFontPresetCreateBody(
  entry: HeadingFontPresetEntry,
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
