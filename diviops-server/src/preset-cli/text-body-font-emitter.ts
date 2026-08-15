/**
 * `divi/font-body` body-text group-preset emitter.
 *
 * Emits a byte-canonical Divi 5.5.x `type: "group"` `divi/font-body` preset
 * targeting `divi/text` at
 * `attrs.content.decoration.bodyFont.body.font.desktop.value.*`,
 * gated by the verified-attrs registry. Canonical shape:
 * `docs/verification/canonical-preset-shapes-per-module-type-2026-05-18.md`
 * (`divi/font-body` section), cross-checked against
 * `docs/verification/evidence/canonical-shape-dumps-2026-05-18/round-2-body-text-pattern-a.json`.
 *
 * Scope: Pattern A (Google Fonts) ONLY. Pattern B (local-hosted) has NO
 * registry entry for `divi/font-body` — the heading-vs-body shape
 * divergence question deferred per round-2 _meta. Selecting
 * `pattern: "local"` therefore resolves to a missing-variant registry-
 * absence error from `resolveEvidence` (the standard
 * `findPatternEntry → undefined → throw` shape). No special-cased
 * Pattern-B branch lives in this file — the registry IS the gate.
 *
 * Shape rules enforced here:
 *  - Emit-on-specification only — omitted params produce NO keys.
 *  - `attrs == styleAttrs == renderAttrs` at the route layer (the plugin's
 *    `/preset/create` route mirrors `attrs` into all three buckets to match
 *    VB save semantics); the CLI request body only carries `attrs`.
 *  - Wrapper chain: `content.decoration.bodyFont.body.font.desktop.value.*` —
 *    note the per-bucket divergence vs `divi/font` (which uses
 *    `title.decoration.font.font.desktop.value.*`). Sibling buckets do NOT
 *    share canonical keys (per `feedback_preset_map_per_module`).
 *  - Link color (`bodyFont.link.font.desktop.value.color`) is OUT OF SCOPE
 *    for this emitter — no `--link-color` option, no link emission. Needs
 *    a dedicated capture-backed follow-up before the CLI writes it.
 *  - Body-text emission on modules OTHER than `divi/text` is OUT OF SCOPE
 *    — Testimonial / Accordion-item / Slide / Blurb cells are
 *    `SCHEMA_OBSERVED` in the registry today (under the write threshold)
 *    and the gate will refuse them.
 */

import {
  loadRegistry,
  gateWriteAttr,
  type VerifiedAttrsRegistry,
} from "./registry.js";
import { normalizeColorValue } from "./variable-token.js";

export const TEXT_BODY_FONT_MODULE = "divi/text";
export const TEXT_BODY_FONT_GROUP_NAME = "divi/font-body";
export const TEXT_BODY_FONT_GROUP_ID = "designText";
export const TEXT_BODY_FONT_PATTERN_FAMILY = "divi/font-body";

/**
 * The verified pattern variants the CLI honors for `divi/font-body`.
 *
 * Only Pattern A is supported. Pattern B is included in the type so the
 * CLI surface mirrors `heading-font` (and so `--pattern local` parses
 * cleanly enough to reach the registry-gate refusal), but its registry
 * entry is intentionally absent — `resolveEvidence` will throw a
 * registry-absence error when the local variant is selected. There is NO
 * shape-level Pattern B encoding here (no weight-in-family heuristic, no
 * `weight`-refusal branch beyond what the missing-variant gate produces).
 */
export const TEXT_BODY_FONT_PATTERN_VARIANTS = {
  google: "google_fonts_pattern_a",
  local: "local_hosted_pattern_b",
} as const;

export type TextBodyFontPattern = "google" | "local";

export interface TextBodyFontEmitterInput {
  /** Required display name for the preset. */
  name: string;
  /**
   * Required pattern selector. There is no default. Only Pattern A is
   * supported; passing `"local"` resolves to a registry-absence refusal
   * (no Pattern B entry exists for `divi/font-body` today).
   */
  pattern: TextBodyFontPattern;
  /** Font family (Pattern A: plain family name, e.g. `"Inter"`). */
  family?: string;
  /** Font weight (numeric-string, e.g. `"400"`); emit-on-specification. */
  weight?: string;
  /** Font color — literal hex or bare/formed variable token. */
  color?: string;
  /** Font size — literal (e.g. `"16px"`) or already-formed `$variable(...)$`. */
  size?: string;
  /** Line height — optional, emit-on-specification only. */
  lineHeight?: string;
}

/** The composed canonical preset entry shape sent to `/preset/create`. */
export interface TextBodyFontPresetEntry {
  type: "group";
  module_name: string;
  group_name: string;
  group_id: string;
  pattern_variant: string;
  name: string;
  attrs: Record<string, unknown>;
}

/**
 * Compose the canonical
 * `attrs.content.decoration.bodyFont.body.font.desktop.value` bag from the
 * input. Emit-on-specification: only specified sub-fields produce keys.
 */
export function composeTextBodyFontAttrs(
  input: TextBodyFontEmitterInput,
): Record<string, unknown> {
  const value: Record<string, unknown> = {};
  if (input.family !== undefined) value.family = input.family;
  if (input.weight !== undefined) value.weight = input.weight;
  if (input.color !== undefined) value.color = normalizeColorValue(input.color);
  if (input.size !== undefined) value.size = input.size;
  if (input.lineHeight !== undefined) value.lineHeight = input.lineHeight;

  return {
    content: {
      decoration: {
        bodyFont: {
          body: {
            font: {
              desktop: {
                value,
              },
            },
          },
        },
      },
    },
  };
}

/**
 * Emit a canonical `divi/font-body` body-text group preset for `divi/text`.
 *
 * 1. Validate input shape (name, pattern).
 * 2. Compose `attrs.content.decoration.bodyFont.body.font.desktop.value.*`
 *    (emit-on-specification).
 * 3. Gate the chosen `(divi/font-body, pattern_variant)` cell on
 *    `divi/text` against the verified-attrs registry — throws an Error
 *    (registry-absence) when the variant is missing (Pattern B is
 *    missing by design), or `EvidenceGateError` when effective evidence
 *    is below `VB_PRESET_STORAGE_VERIFIED`.
 *
 * `styleAttrs` and `renderAttrs` are intentionally NOT part of this
 * entry: the plugin's `/preset/create` route mirrors the single `attrs`
 * bag into all three buckets, writing `attrs == styleAttrs == renderAttrs`
 * to match VB save semantics. The Round 2 fixture captures the post-write
 * storage shape (note: round-2 _meta records `renderAttrs_present: false`
 * for font-styling group presets, which is consistent with rounds 1a/1b —
 * the mirror happens at the route layer regardless).
 */
export function emitTextBodyFontGroupPreset(
  input: TextBodyFontEmitterInput,
  registry: VerifiedAttrsRegistry = loadRegistry(),
): TextBodyFontPresetEntry {
  if (!input.name || typeof input.name !== "string") {
    throw new Error("text-body-font emitter requires a non-empty `name`.");
  }
  if (input.pattern !== "google" && input.pattern !== "local") {
    throw new Error(
      `text-body-font emitter requires \`pattern\` to be "google" or "local"; got ${JSON.stringify(input.pattern)}. ` +
        `There is no default — Pattern A (google) is the only registry-verified variant for ` +
        `\`divi/font-body\` today; Pattern B (local) resolves to a registry-absence refusal.`,
    );
  }

  const attrs = composeTextBodyFontAttrs(input);

  // Sanity check: at least one styling field was specified. An empty
  // value bag is a usage error — there is nothing to write.
  const value = (attrs as any).content.decoration.bodyFont.body.font.desktop
    .value as Record<string, unknown> | undefined;
  if (!value || Object.keys(value).length === 0) {
    throw new Error(
      "text-body-font emitter produced an empty preset — pass at least one of " +
        "family, weight, color, size, or lineHeight.",
    );
  }

  // Registry gate: variant-aware. Pattern A evidence must NOT vouch for
  // Pattern B and vice versa — gateWriteAttr resolves by both family AND
  // variant, so a missing/under-verified variant entry throws here.
  // Pattern B has NO registry entry for `divi/font-body`, so
  // `--pattern local` lands on `resolveEvidence`'s "absent from
  // verified-attrs.json" throw natively — no special-cased branch needed.
  const patternVariant = TEXT_BODY_FONT_PATTERN_VARIANTS[input.pattern];
  gateWriteAttr(
    registry,
    TEXT_BODY_FONT_MODULE,
    TEXT_BODY_FONT_PATTERN_FAMILY,
    patternVariant,
  );

  return {
    type: "group",
    module_name: TEXT_BODY_FONT_MODULE,
    group_name: TEXT_BODY_FONT_GROUP_NAME,
    group_id: TEXT_BODY_FONT_GROUP_ID,
    pattern_variant: patternVariant,
    name: input.name,
    attrs,
  };
}

/**
 * Build the `POST /diviops/v1/preset/create` request body from a
 * text-body-font preset entry. Matches the body shape the
 * `diviops_preset_create` MCP tool posts — the CLI reuses the existing
 * route, it does not add one.
 *
 * `pattern_variant` is informational metadata on the in-memory entry and
 * is NOT sent over the wire — the registry-gate decision happens
 * client-side before the write.
 */
export function buildTextBodyFontPresetCreateBody(
  entry: TextBodyFontPresetEntry,
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
