/**
 * Verified-attrs registry consumption for the preset-emitter CLI.
 *
 * Loads `diviops-server/data/verified-attrs.json` and resolves the
 * EFFECTIVE evidence level per `(module, pattern-family)` cell using the
 * `min()` rule documented in `docs/verification/README.md`:
 *
 *   effective_evidence = min(pattern_evidence_level,
 *                            applicability[<module>].cell_evidence_level)
 *
 * A missing `applicability[<module>]` resolves to `UNVERIFIED` (0) — never
 * to the pattern level. No invisible inheritance crosses the write
 * threshold.
 *
 * This module is the consumer side of the verified-attrs registry
 * contract. It does NOT mutate the registry; if a needed cell is absent
 * or under-verified, the caller fails and the gap is filed as a backlog
 * candidate.
 */

import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * Effective evidence required for a write emitter to emit an attr.
 * `VB_PRESET_STORAGE_VERIFIED` has numeric ordering 4.
 */
export const WRITE_EMITTER_THRESHOLD_LEVEL = "VB_PRESET_STORAGE_VERIFIED";

export interface ApplicabilityCell {
  wrapper?: string;
  preset_type?: string;
  group_name?: string;
  group_id?: string;
  cell_evidence_level?: string;
  cell_divi_version?: string;
  verified_at?: string;
  source?: string;
  caveats?: string[];
}

export interface Tier12Entry {
  pattern_family: string;
  pattern_variant?: string;
  pattern_evidence_level: string;
  pattern_evidence_source?: string;
  applicability?: Record<string, ApplicabilityCell>;
}

export interface VerifiedAttrsRegistry {
  schema_version?: string;
  registry_version?: string;
  evidence_level_ordering: Record<string, number>;
  tier1?: Tier12Entry[];
  tier2?: Tier12Entry[];
  tier3?: unknown[];
}

/** Resolution of a single `(module, pattern-family[, pattern-variant])` cell against the registry. */
export interface EvidenceResolution {
  patternFamily: string;
  /** The matched entry's `pattern_variant`, if any. */
  patternVariant?: string;
  module: string;
  /** Numeric pattern-level evidence (0–5). */
  patternLevel: number;
  patternLevelName: string;
  /** Numeric cell-level evidence (0–5); 0 when applicability[<module>] is absent. */
  cellLevel: number;
  cellLevelName: string;
  /** min(patternLevel, cellLevel). */
  effectiveLevel: number;
  effectiveLevelName: string;
  /** True when applicability[<module>] is missing — cellLevel is the 0-fallback. */
  applicabilityMissing: boolean;
  /** Best-available durable source string for error messages. */
  source: string;
  /** The applicability cell, if present (carries group_name / group_id / wrapper). */
  cell?: ApplicabilityCell;
}

let cachedRegistry: VerifiedAttrsRegistry | null = null;

/**
 * Load and cache `data/verified-attrs.json`. The path is resolved relative
 * to the compiled module location (`dist/preset-cli/registry.js` →
 * `dist/../data/verified-attrs.json`), and also tolerates the `src/` layout
 * for direct ts-node-style execution / tests.
 */
export function loadRegistry(explicitPath?: string): VerifiedAttrsRegistry {
  if (!explicitPath && cachedRegistry) return cachedRegistry;

  const candidates = explicitPath
    ? [explicitPath]
    : [
        // dist/preset-cli/registry.js -> dist/../data
        join(__dirname, "..", "..", "data", "verified-attrs.json"),
        // src/preset-cli/registry.ts -> src/../data
        join(__dirname, "..", "data", "verified-attrs.json"),
      ];

  let lastErr: unknown = null;
  for (const p of candidates) {
    try {
      const raw = readFileSync(p, "utf8");
      const parsed = JSON.parse(raw) as VerifiedAttrsRegistry;
      if (!explicitPath) cachedRegistry = parsed;
      return parsed;
    } catch (err) {
      lastErr = err;
    }
  }
  throw new Error(
    `Could not load verified-attrs.json (tried: ${candidates.join(", ")}). ${
      lastErr instanceof Error ? lastErr.message : String(lastErr)
    }`,
  );
}

/** Reset the module-level cache. Used by tests. */
export function resetRegistryCache(): void {
  cachedRegistry = null;
}

function levelNumber(
  registry: VerifiedAttrsRegistry,
  name: string | undefined,
): number {
  if (!name) return 0;
  const n = registry.evidence_level_ordering[name];
  return typeof n === "number" ? n : 0;
}

function levelName(
  registry: VerifiedAttrsRegistry,
  num: number,
): string {
  for (const [name, n] of Object.entries(registry.evidence_level_ordering)) {
    if (n === num) return name;
  }
  return "UNVERIFIED";
}

/**
 * Find a Tier 1 / Tier 2 entry by `pattern_family` and (optionally)
 * `pattern_variant`. Tier 2 is searched first, then Tier 1.
 *
 * Some pattern families (e.g. `divi/font`) carry multiple entries that
 * differ ONLY by `pattern_variant` (`google_fonts_pattern_a` vs
 * `local_hosted_pattern_b`). When `patternVariant` is supplied, ONLY an
 * entry whose `pattern_variant` matches exactly is returned — a Pattern-A
 * entry must not vouch for a Pattern-B caller, and vice versa.
 *
 * When `patternVariant` is omitted, the legacy behavior holds: the first
 * matching `pattern_family` is returned regardless of variant — only safe
 * for families with a single entry. New variant-aware callers should pass
 * the variant explicitly.
 */
export function findPatternEntry(
  registry: VerifiedAttrsRegistry,
  patternFamily: string,
  patternVariant?: string,
): Tier12Entry | undefined {
  const tiers = [registry.tier2 ?? [], registry.tier1 ?? []];
  for (const tier of tiers) {
    const hit = tier.find((e) => {
      if (e.pattern_family !== patternFamily) return false;
      if (patternVariant !== undefined) {
        return e.pattern_variant === patternVariant;
      }
      return true;
    });
    if (hit) return hit;
  }
  return undefined;
}

/**
 * Resolve effective evidence for a `(module, pattern-family[, pattern-variant])` cell.
 *
 * Throws if the pattern family (or, when supplied, the specific variant)
 * is entirely absent from the registry — that is an unrecoverable gap
 * (the CLI must fail, not guess).
 */
export function resolveEvidence(
  registry: VerifiedAttrsRegistry,
  module: string,
  patternFamily: string,
  patternVariant?: string,
): EvidenceResolution {
  const entry = findPatternEntry(registry, patternFamily, patternVariant);
  if (!entry) {
    const variantSuffix =
      patternVariant !== undefined ? ` (variant "${patternVariant}")` : "";
    throw new Error(
      `Pattern family "${patternFamily}"${variantSuffix} is absent from verified-attrs.json. ` +
        `The CLI cannot emit an unregistered attr. File this as a registry-gap backlog candidate.`,
    );
  }

  const patternLevel = levelNumber(registry, entry.pattern_evidence_level);

  // Missing applicability[<module>] resolves to UNVERIFIED (0) — no
  // invisible inheritance from the pattern level.
  const cell = entry.applicability?.[module];
  const applicabilityMissing = !cell;
  const cellLevel = cell ? levelNumber(registry, cell.cell_evidence_level) : 0;

  const effectiveLevel = Math.min(patternLevel, cellLevel);

  const source =
    cell?.source ??
    entry.pattern_evidence_source ??
    "verified-attrs.json (no source field)";

  return {
    patternFamily,
    patternVariant: entry.pattern_variant,
    module,
    patternLevel,
    patternLevelName: entry.pattern_evidence_level,
    cellLevel,
    cellLevelName: cell?.cell_evidence_level ?? "UNVERIFIED",
    effectiveLevel,
    effectiveLevelName: levelName(registry, effectiveLevel),
    applicabilityMissing,
    source,
    cell,
  };
}

/** Numeric ordering of the write-emitter threshold against a registry. */
export function writeThresholdNumber(registry: VerifiedAttrsRegistry): number {
  return levelNumber(registry, WRITE_EMITTER_THRESHOLD_LEVEL);
}

/**
 * Gate a write-targeted attr. Returns the resolution when it clears the
 * `VB_PRESET_STORAGE_VERIFIED` threshold; throws an `EvidenceGateError`
 * naming the attr, its effective level, and the registry source otherwise.
 */
export class EvidenceGateError extends Error {
  constructor(
    public readonly patternFamily: string,
    public readonly module: string,
    public readonly resolution: EvidenceResolution,
    public readonly thresholdNumber: number,
    public readonly thresholdName: string,
  ) {
    const variantTag =
      resolution.patternVariant !== undefined
        ? ` variant "${resolution.patternVariant}"`
        : "";
    super(
      `Evidence-gate refusal: attr family "${patternFamily}"${variantTag} on module "${module}" ` +
        `has EFFECTIVE evidence ${resolution.effectiveLevelName} (${resolution.effectiveLevel}), ` +
        `below the write threshold ${thresholdName} (${thresholdNumber}). ` +
        (resolution.applicabilityMissing
          ? `applicability["${module}"] is absent from the registry entry — resolved to UNVERIFIED (0). `
          : `pattern=${resolution.patternLevelName}(${resolution.patternLevel}), ` +
            `cell=${resolution.cellLevelName}(${resolution.cellLevel}). `) +
        `Registry source: ${resolution.source}. ` +
        `The CLI will not emit an under-verified attr — file a registry-gap backlog candidate.`,
    );
    this.name = "EvidenceGateError";
  }
}

export function gateWriteAttr(
  registry: VerifiedAttrsRegistry,
  module: string,
  patternFamily: string,
  patternVariant?: string,
): EvidenceResolution {
  const resolution = resolveEvidence(
    registry,
    module,
    patternFamily,
    patternVariant,
  );
  const thresholdNumber = writeThresholdNumber(registry);
  if (resolution.effectiveLevel < thresholdNumber) {
    throw new EvidenceGateError(
      patternFamily,
      module,
      resolution,
      thresholdNumber,
      WRITE_EMITTER_THRESHOLD_LEVEL,
    );
  }
  return resolution;
}
