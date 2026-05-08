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
 * Serialize a `DiviopsResponse` as the JSON string an MCP tool emits in its
 * `content[0].text` slot. Single emit point keeps the wire shape consistent.
 */
export function serializeEnvelope<T>(response: DiviopsResponse<T>): string {
  return JSON.stringify(response);
}
