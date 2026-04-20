/**
 * Isolation-rule validator: bans cross-system var(--alias) refs in Divi
 * module attrs. See SKILL.md rule 8 and references/module-formats.md
 * §"Design Token References in Attrs".
 *
 * CSS spec: var(--undeclared-name) with no fallback falls through to the
 * property's initial value (0 for padding, browser default for color).
 * Tool reports success, renderer emits ref as-is, page silently breaks.
 *
 * Only var() refs to gcid-* / gvid-* pass — Divi-owned namespaces that
 * auto-resolve via :root. Any other alias is rejected.
 */

const VAR_REF_RE = /var\(\s*--([A-Za-z_][A-Za-z0-9_-]*)/g;
const ALLOWED_PREFIXES = ["gcid-", "gvid-"];

export interface ForeignVarRef {
  alias: string;
  snippet: string;
  location?: string;
}

export function findForeignVarRefs(
  value: unknown,
  location?: string,
): ForeignVarRef[] {
  if (typeof value !== "string" || value.length === 0) return [];
  const hits: ForeignVarRef[] = [];
  VAR_REF_RE.lastIndex = 0;
  let m: RegExpExecArray | null;
  while ((m = VAR_REF_RE.exec(value)) !== null) {
    const alias = m[1];
    if (ALLOWED_PREFIXES.some((p) => alias.startsWith(p))) continue;
    hits.push({ alias, snippet: `var(--${alias})`, location });
  }
  return hits;
}

export function scanAttrsForForeignVarRefs(
  attrs: Record<string, unknown>,
): ForeignVarRef[] {
  const hits: ForeignVarRef[] = [];
  for (const [path, value] of Object.entries(attrs)) {
    hits.push(...findForeignVarRefs(value, path));
  }
  return hits;
}

export function formatIsolationError(
  tool: string,
  hits: ForeignVarRef[],
): string {
  const uniq = new Map<string, ForeignVarRef>();
  for (const h of hits) {
    const k = `${h.snippet}::${h.location ?? ""}`;
    if (!uniq.has(k)) uniq.set(k, h);
  }
  const lines = [
    `Isolation-rule violation in ${tool}: module attrs cannot reference non-Divi CSS aliases.`,
    "",
    "Offending refs:",
    ...[...uniq.values()].map(
      (h) => `  - ${h.snippet}${h.location ? `  (at ${h.location})` : ""}`,
    ),
    "",
    "Allowed: var(--gcid-*) and var(--gvid-*) (Divi-owned, auto-emitted to :root).",
    'Canonical form: $variable({"type":"content","value":{"name":"gvid-your-token","settings":{}}})$',
    "",
    "Fix: register the token inside Divi Variable Manager (readable ID is fine, e.g. gvid-oa-space-3) and reference via $variable({...})$. See SKILL.md rule 8 / references/module-formats.md#design-token-references-in-attrs-canonical-variable-only.",
  ];
  return lines.join("\n");
}

export function isolationErrorResult(tool: string, hits: ForeignVarRef[]) {
  return {
    content: [
      { type: "text" as const, text: formatIsolationError(tool, hits) },
    ],
    isError: true,
  };
}
