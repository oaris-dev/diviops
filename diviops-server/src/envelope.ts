/**
 * Standardized response envelope (#489).
 *
 * Every diviops tool — server-local or plugin-routed — returns:
 *   success: { ok: true,  data: T }
 *   error:   { ok: false, error: { code: string, message: string, hint?: string } }
 *
 * Adoption is per-namespace (#489 + per-namespace follow-ups). Tools that
 * have not adopted yet still pass legacy raw payloads through to consumers;
 * the helpers here coexist with that shape during the rollout window.
 *
 * HTTP status stays orthogonal to envelope shape on the wire — the plugin
 * emits 200 on ok:true and the real status (400/404/409/412/500) on ok:false.
 * The server-side `wp.request` raises on non-2xx today, so error envelopes
 * arrive as thrown errors carrying the JSON body; `wrapResponse` is the
 * normalizer that maps both branches to the typed `DiviopsResponse<T>`.
 */

export type DiviopsSuccess<T> = { ok: true; data: T };

export type DiviopsErrorBody = {
  code: string;
  message: string;
  hint?: string;
  /**
   * Optional structured payload attached to the error envelope. Used by
   * tools whose failure mode carries machine-readable detail beyond a
   * code/message pair — e.g. `meta_wp_cli` exposes
   * `error.data = { exit_code, stdout, stderr }` so callers can branch
   * on the wp-cli exit code without parsing the message string. Tools
   * that don't need it omit the field entirely.
   *
   * Naming convention: when a namespace-specific error code attaches
   * `data`, list the field shape in the tool's description and in
   * tools.md "Response shape" so consumers know what to expect per
   * code without runtime probing.
   */
  data?: unknown;
};

export type DiviopsFailure = { ok: false; error: DiviopsErrorBody };

export type DiviopsResponse<T> = DiviopsSuccess<T> | DiviopsFailure;

/**
 * Standard error code vocabulary. Plugin and server emit only these codes
 * for the matching condition; namespace-specific extensions use the
 * `<namespace>.<reason>` form (e.g. `library.import_conflict`).
 */
export const ErrorCodes = {
  /** Target ID does not resolve. HTTP 404. */
  NOT_FOUND: "not_found",
  /** Schema violation, malformed args. HTTP 400. */
  INVALID_INPUT: "invalid_input",
  /** Underlying WordPress error (wraps WP_Error or REST framework error). HTTP 500. */
  WP_ERROR: "wp_error",
  /** Divi-specific error (block parser, validator, etc.). HTTP 500. */
  DIVI_ERROR: "divi_error",
  /** Plugin version below required for this tool (#486 handshake miss). HTTP 412. */
  CAPABILITY_MISSING: "capability_missing",
  /** validate_blocks-detected shape error in submitted markup. HTTP 400. */
  VALIDATION_FAILED: "validation_failed",
  /** Uniqueness collision (#542 / #543). HTTP 409. */
  CONFLICT: "conflict",
} as const;

export type ErrorCode = (typeof ErrorCodes)[keyof typeof ErrorCodes] | string;

/**
 * Typed throw used inside server-local handlers to short-circuit with a
 * specific envelope error code. `wrapResponse` catches it and re-emits.
 */
export class DiviopsError extends Error {
  public readonly code: string;
  public readonly hint?: string;
  public readonly data?: unknown;

  constructor(code: string, message: string, hint?: string, data?: unknown) {
    super(message);
    this.name = "DiviopsError";
    this.code = code;
    this.hint = hint;
    this.data = data;
  }
}

/**
 * Throw a `DiviopsError`. Helper for ergonomics — `withCode(...)` reads
 * better at call sites than `throw new DiviopsError(...)` and matches the
 * kickoff's helper-API sketch. Pass `data` when the failure carries a
 * structured payload (e.g. wp-cli exit code + captured streams).
 */
export function withCode(
  code: string,
  message: string,
  hint?: string,
  data?: unknown,
): never {
  throw new DiviopsError(code, message, hint, data);
}

/**
 * Type guard: is `value` already shaped as a `DiviopsResponse`?
 *
 * Plugin-routed tools return the envelope; server-local tools may return
 * raw values. `wrapResponse` uses this to avoid double-wrapping.
 */
export function isEnveloped(value: unknown): value is DiviopsResponse<unknown> {
  if (typeof value !== "object" || value === null) return false;
  const v = value as Record<string, unknown>;
  if (v.ok === true && "data" in v) return true;
  if (v.ok === false && typeof v.error === "object" && v.error !== null) {
    const e = v.error as Record<string, unknown>;
    return typeof e.code === "string" && typeof e.message === "string";
  }
  return false;
}

/**
 * Run an async producer and normalize its outcome to a `DiviopsResponse<T>`.
 *
 * Behavior:
 *  - Producer returns an already-enveloped value → pass through unchanged
 *    (no double-wrap). Plugin-routed tools land here.
 *  - Producer returns a raw value → wrap as `{ok: true, data: <value>}`.
 *    Server-local tools land here.
 *  - Producer throws `DiviopsError` → emit `{ok: false, error: {code, message, hint?}}`.
 *  - Producer throws anything else → emit `{ok: false, error: {code: 'wp_error', message: e.message}}`.
 *    The fallback covers thrown HTTP errors from `wp.request` whose body
 *    is the plugin's envelope JSON; we attempt to parse the message and
 *    promote the embedded code/hint when present.
 */
export async function wrapResponse<T>(
  producer: () => Promise<T | DiviopsResponse<T>>,
): Promise<DiviopsResponse<T>> {
  try {
    const result = await producer();
    if (isEnveloped(result)) {
      return result as DiviopsResponse<T>;
    }
    return { ok: true, data: result as T };
  } catch (e) {
    if (e instanceof DiviopsError) {
      const error: DiviopsErrorBody = { code: e.code, message: e.message };
      if (e.hint) error.hint = e.hint;
      if (e.data !== undefined) error.data = e.data;
      return { ok: false, error };
    }
    return { ok: false, error: parseThrownError(e) };
  }
}

/**
 * Extract envelope error info from a thrown value.
 *
 * `wp.request` throws Errors of the form
 *   `WordPress API error (NNN): <body>`
 * where `<body>` may be the plugin's envelope JSON. We try to recover the
 * embedded code/hint in that case so re-thrown plugin errors don't lose
 * their structured shape on round-trip through `wrapResponse`.
 */
function parseThrownError(e: unknown): DiviopsErrorBody {
  const message = e instanceof Error ? e.message : String(e);
  const bodyMatch = message.match(/^WordPress API error \(\d+\):\s*(.*)$/s);
  if (bodyMatch) {
    try {
      const parsed = JSON.parse(bodyMatch[1]);
      if (
        parsed &&
        typeof parsed === "object" &&
        parsed.ok === false &&
        parsed.error &&
        typeof parsed.error === "object" &&
        typeof parsed.error.code === "string" &&
        typeof parsed.error.message === "string"
      ) {
        const out: DiviopsErrorBody = {
          code: parsed.error.code,
          message: parsed.error.message,
        };
        if (typeof parsed.error.hint === "string") out.hint = parsed.error.hint;
        if (parsed.error.data !== undefined) out.data = parsed.error.data;
        return out;
      }
    } catch {
      // Body wasn't JSON, fall through to generic wp_error.
    }
  }
  return { code: ErrorCodes.WP_ERROR, message };
}

/**
 * Map the data of a `DiviopsResponse` through `fn` (success branch only).
 * Errors pass through unchanged. Use to post-process a wrapped result
 * (e.g. shrink schema, project fields) without unwrapping by hand.
 */
export function envelopeMap<T, U>(
  response: DiviopsResponse<T>,
  fn: (data: T) => U,
): DiviopsResponse<U> {
  if (response.ok) return { ok: true, data: fn(response.data) };
  return response;
}

/**
 * Idempotency vocabulary surfaced to clients via `_meta.idempotent` on
 * every tool response (#597). Mirrors the registration-level `_meta.idempotent`
 * declared at each `registerTool()` callsite so per-call consumers see the
 * field where they naturally look (response envelope), not just at
 * `tools/list` discovery time.
 */
export type IdempotentVerdict = "true" | "false" | "conditional";

/**
 * Tool-name → idempotent-verdict registry, populated at server startup by
 * `recordIdempotent()` from each tool's registration `_meta`. The audit
 * doc (`docs/idempotency-audit.md`) remains the single source of truth;
 * this table is just the runtime mirror used to enrich per-call responses.
 */
const IDEMPOTENT_TABLE = new Map<string, IdempotentVerdict>();

/**
 * Record a tool's idempotency verdict at registration time so per-call
 * `serializeEnvelope(result, toolName)` can emit it on every response.
 * Throws if the registration is missing `_meta.idempotent` — fail-loud
 * matches `feedback_explicit_reject_over_silent_sanitize`: a tool that
 * skipped the audit would silently lack the field, defeating #597.
 */
export function recordIdempotent(
  name: string,
  meta: { idempotent?: string } | undefined,
): void {
  const verdict = meta?.idempotent;
  if (verdict !== "true" && verdict !== "false" && verdict !== "conditional") {
    throw new Error(
      `Tool '${name}' is missing or has invalid _meta.idempotent declaration ` +
        `(got: ${verdict === undefined ? "undefined" : JSON.stringify(verdict)}). ` +
        `Every tool must declare idempotent: "true" | "false" | "conditional" ` +
        `per docs/idempotency-audit.md.`,
    );
  }
  IDEMPOTENT_TABLE.set(name, verdict);
}

/**
 * Look up a tool's idempotency verdict. Returns undefined if the tool
 * hasn't been recorded — caller decides whether to fail or skip emission.
 */
export function getRecordedIdempotent(
  name: string,
): IdempotentVerdict | undefined {
  return IDEMPOTENT_TABLE.get(name);
}

/**
 * Snapshot of the recorded table — used by tests + the CI gate. Returns
 * a fresh object so callers can't mutate the live registry.
 */
export function snapshotIdempotentTable(): Record<string, IdempotentVerdict> {
  return Object.fromEntries(IDEMPOTENT_TABLE.entries());
}

/**
 * Serialize a `DiviopsResponse` as the JSON string an MCP tool emits in its
 * `content[0].text` slot. Single emit point keeps the wire shape consistent.
 *
 * When `toolName` is provided AND the tool has been recorded via
 * `recordIdempotent()`, injects `_meta.idempotent` onto the response
 * envelope before serialization (#597). Per-call emission is a strict
 * superset of the `tools/list` registration-level `_meta` surface — both
 * keep working, per-call consumers get the field where they look. Applied
 * to ok:true AND ok:false envelopes since idempotency is a property of
 * the tool, not the call outcome.
 */
export function serializeEnvelope<T>(
  response: DiviopsResponse<T>,
  toolName?: string,
): string {
  if (toolName === undefined) {
    return JSON.stringify(response);
  }
  const verdict = IDEMPOTENT_TABLE.get(toolName);
  if (verdict === undefined) {
    return JSON.stringify(response);
  }
  const existingMetaRaw = (response as { _meta?: unknown })._meta;
  const existingMeta =
    typeof existingMetaRaw === "object" && existingMetaRaw !== null
      ? (existingMetaRaw as Record<string, unknown>)
      : {};
  const enriched = {
    ...response,
    _meta: { ...existingMeta, idempotent: verdict },
  };
  return JSON.stringify(enriched);
}
