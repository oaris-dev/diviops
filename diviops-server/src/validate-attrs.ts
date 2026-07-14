import {
  type DiviopsFailure,
  ErrorCodes,
  serializeEnvelope,
} from "./envelope.js";

/**
 * Isolation-rule validator: bans cross-system var(--alias) refs in Divi
 * content and module attrs. See SKILL.md rule 8 and references/module-formats.md
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

/**
 * Decode JSON Unicode escapes that WordPress block serialization preserves in
 * raw comment markup. Escapes preceded by an odd number of extra backslashes
 * represent a literal `\\uXXXX` sequence after JSON parsing and stay untouched.
 */
function decodeJsonUnicodeEscapes(value: string): string {
  return value.replace(
    /\\u([0-9a-fA-F]{4})/g,
    (match, hex: string, offset: number, input: string) => {
      let precedingBackslashes = 0;
      for (
        let index = offset - 1;
        index >= 0 && input[index] === "\\";
        index -= 1
      ) {
        precedingBackslashes += 1;
      }
      if (precedingBackslashes % 2 === 1) return match;
      return String.fromCharCode(Number.parseInt(hex, 16));
    },
  );
}

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
  const normalizedValue = decodeJsonUnicodeEscapes(value);
  const hits: ForeignVarRef[] = [];
  VAR_REF_RE.lastIndex = 0;
  let m: RegExpExecArray | null;
  while ((m = VAR_REF_RE.exec(normalizedValue)) !== null) {
    const alias = m[1];
    if (ALLOWED_PREFIXES.some((p) => alias.startsWith(p))) continue;
    hits.push({ alias, snippet: `var(--${alias})`, location });
  }
  return hits;
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  if (typeof value !== "object" || value === null) return false;
  const prototype = Object.getPrototypeOf(value);
  return prototype === Object.prototype || prototype === null;
}

function childLocation(parent: string | undefined, key: string): string {
  return parent ? `${parent}.${key}` : key;
}

/**
 * Recursively scan JSON-like values while preserving stable object and array
 * locations. Unsupported object types are ignored rather than coerced.
 */
export function scanValueForForeignVarRefs(
  value: unknown,
  location?: string,
): ForeignVarRef[] {
  const hits: ForeignVarRef[] = [];

  function scan(current: unknown, currentLocation?: string): void {
    if (typeof current === "string") {
      hits.push(...findForeignVarRefs(current, currentLocation));
      return;
    }
    if (Array.isArray(current)) {
      for (let index = 0; index < current.length; index += 1) {
        scan(current[index], `${currentLocation ?? ""}[${index}]`);
      }
      return;
    }
    if (!isPlainObject(current)) return;

    for (const [key, child] of Object.entries(current)) {
      scan(child, childLocation(currentLocation, key));
    }
  }

  scan(value, location);
  return hits;
}

/** Scan only the raw content/attrs fields selected by a writer callsite. */
export function scanWriterPayloadForForeignVarRefs(
  payload: Record<string, unknown>,
): ForeignVarRef[] {
  return scanValueForForeignVarRefs(payload);
}

export function scanAttrsForForeignVarRefs(
  attrs: Record<string, unknown>,
): ForeignVarRef[] {
  return scanValueForForeignVarRefs(attrs, "attrs");
}

function uniqueForeignVarRefs(hits: ForeignVarRef[]): ForeignVarRef[] {
  const uniq = new Map<string, ForeignVarRef>();
  for (const hit of hits) {
    const key = `${hit.snippet}::${hit.location ?? ""}`;
    if (!uniq.has(key)) uniq.set(key, hit);
  }
  return [...uniq.values()];
}

export function formatIsolationError(
  tool: string,
  hits: ForeignVarRef[],
): string {
  const uniqueHits = uniqueForeignVarRefs(hits);
  const lines = [
    `Isolation-rule violation in ${tool}: module attrs cannot reference non-Divi CSS aliases.`,
    "",
    "Offending refs:",
    ...uniqueHits.map(
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

export function isolationFailure(
  tool: string,
  hits: ForeignVarRef[],
): DiviopsFailure {
  return {
    ok: false,
    error: {
      code: ErrorCodes.INVALID_INPUT,
      message: `Isolation-rule violation in ${tool}: Divi content and attrs cannot reference non-Divi CSS aliases.`,
      hint:
        "Use var(--gcid-*) or var(--gvid-*), or register the token in Divi Variable Manager and use Divi's canonical $variable(...)$ reference.",
      data: {
        reason: "foreign_css_variable_ref",
        tool,
        refs: uniqueForeignVarRefs(hits),
      },
    },
  };
}

export function isolationErrorResult(tool: string, hits: ForeignVarRef[]) {
  return {
    content: [
      {
        type: "text" as const,
        text: serializeEnvelope(isolationFailure(tool, hits), tool),
      },
    ],
  };
}

export function writerIsolationErrorResult(
  tool: string,
  payload: Record<string, unknown>,
) {
  const hits = scanWriterPayloadForForeignVarRefs(payload);
  return hits.length > 0 ? isolationErrorResult(tool, hits) : null;
}
