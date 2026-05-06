/**
 * WordPress REST API client with Application Password authentication.
 *
 * Uses WP Application Passwords (built into WP 5.6+) for auth.
 * Generate one at: WP Admin → Users → Your Profile → Application Passwords.
 */

import { type HandshakeResult } from './compatibility.js';

/**
 * Normalize quote-escape pathologies inside `$variable(...)$` token regions only.
 *
 * Divi block-attrs JSON uses `\"` (2-byte: backslash + quote) for inner quotes
 * inside variable token payloads. Three pathological forms can leak in through
 * callers and silently break the WP block parser at write time.
 *
 * Over-escape (existing #395/#396 fix). Two forms produced when callers
 * round-trip pre-existing broken bytes:
 *   - `\u005cu0022` (11 bytes literal) — the
 *     mass-corruption form (backslash itself unicode-escaped, observed in the
 *     wild on Divi 5.3.x sites)
 *   - `\\u0022`     (7 bytes literal: 2 backslashes + `u0022`) — produced when
 *     a caller passes the 6-byte unicode-escape form through an extra
 *     JSON-encoding layer
 *   These cause render-only failure: the resolver can't decode the token, and
 *   attr paths like `background.color`, `spacing.margin`, `border.color`,
 *   `layout.columnGap` silently fall through to defaults (or leak literal
 *   `0022` into emitted CSS).
 *
 * Under-escape (#409 fix). One form produced when an agent transcribes
 * `section_get` markup (which emits inner quotes as `&quot;` HTML entities) and
 * a layer in the agent → MCP → WP pipeline strips one level of escaping:
 *   - bare `"` (1 byte) — the inner quote loses its `\` prefix and prematurely
 *     terminates the OUTER block-attrs string at parse time. The WP block
 *     parser then silently drops ALL attrs from the affected module. Section
 *     appears to save (`success: true`) but renders empty / broken.
 *
 * We normalize defensively so any write — clean, pre-broken, or
 * agent-transcribed — settles on canonical 2-byte `\"`.
 *
 * Order matters: collapse over-escapes first, then escape under-escapes. The
 * negative lookbehind on the under-escape rule skips `\"` produced by the
 * over-escape pass (and any already-canonical input). Idempotent.
 *
 * Scope is intentionally narrow: rewrites only happen inside `$variable(...)$`
 * regions (Divi's actual resolver boundary). Bytes outside those regions —
 * arbitrary user text, code samples, string-variable values that happen to
 * contain `\u005cu0022`, `\\u0022`, or bare `"` — are left untouched.
 */
function normalizeQuoteEscapes(s: string): string {
  return s.replace(/\$variable\([^$]*?\)\$/g, (token) => {
    // Pass 1: collapse over-escaped forms (#395/#396) to canonical \"
    let normalized = token.replace(/(?:\\u005cu0022|\\\\u0022)/g, '\\"');
    // Pass 2: escape any bare " (#409) to canonical \" — negative lookbehind
    // skips properly-escaped quotes produced by Pass 1 or already canonical.
    normalized = normalized.replace(/(?<!\\)"/g, '\\"');
    return normalized;
  });
}

/**
 * Body keys whose values (and descendants) carry Divi block markup or block
 * attribute trees, where `$variable(...)$` token-region normalization is the
 * intended behavior. Strings reachable only through other top-level keys
 * — variable values, labels, match-text predicates, descriptions, etc.
 * — are passed through verbatim so a literal `$variable({"x":"y"})$`
 * docs example in a `string_variable_value` is preserved (#409 review:
 * Codex-flagged regression — without this scoping, the bare-quote pass would
 * silently rewrite literal token-shaped substrings in user-prose fields).
 */
const BLOCK_CONTENT_KEYS = new Set([
  'content',         // update_page_content, render_preview, validate_blocks,
                     // section_append, section_replace, update_tb_layout,
                     // library_save, create_page
  'attrs',           // update_module — attr values embedded in block JSON
  'header_content',  // create_tb_template
  'footer_content',  // create_tb_template
]);

function normalizeBody(value: unknown, withinBlockTree = false): unknown {
  if (typeof value === 'string') {
    return withinBlockTree ? normalizeQuoteEscapes(value) : value;
  }
  if (Array.isArray(value)) return value.map((v) => normalizeBody(v, withinBlockTree));
  // Restrict recursion to plain objects so Date / RegExp / class instances
  // with custom `toJSON` keep their canonical serialization.
  if (
    value &&
    typeof value === 'object' &&
    Object.getPrototypeOf(value) === Object.prototype
  ) {
    const out: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(value)) {
      out[k] = normalizeBody(v, withinBlockTree || BLOCK_CONTENT_KEYS.has(k));
    }
    return out;
  }
  return value;
}

export interface WPClientConfig {
  siteUrl: string;
  username: string;
  applicationPassword: string;
}

export class WPClient {
  private baseUrl: string;
  private authHeader: string;

  constructor(config: WPClientConfig) {
    // Strip trailing slash.
    this.baseUrl = config.siteUrl.replace(/\/+$/, '');

    // WP Application Passwords use Basic Auth.
    const credentials = Buffer.from(
      `${config.username}:${config.applicationPassword}`
    ).toString('base64');
    this.authHeader = `Basic ${credentials}`;
  }

  /**
   * Make a request to the diviops/v1 REST namespace.
   */
  async request<T = unknown>(
    endpoint: string,
    options: {
      method?: string;
      body?: Record<string, unknown>;
      params?: Record<string, string>;
    } = {}
  ): Promise<T> {
    const { method = 'GET', body, params } = options;

    let url = `${this.baseUrl}/wp-json/diviops/v1${endpoint}`;

    if (params) {
      const searchParams = new URLSearchParams(params);
      url += `?${searchParams.toString()}`;
    }

    const fetchOptions: RequestInit = {
      method,
      headers: {
        Authorization: this.authHeader,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
    };

    if (body && method !== 'GET') {
      fetchOptions.body = JSON.stringify(normalizeBody(body));
    }

    const response = await fetch(url, fetchOptions);

    if (!response.ok) {
      const errorBody = await response.text();
      let errorMessage: string;
      try {
        const errorJson = JSON.parse(errorBody);
        errorMessage = errorJson.message || errorBody;
      } catch {
        errorMessage = errorBody;
      }

      if (response.status === 429) {
        const retryAfter = response.headers.get('Retry-After') || '60';
        throw new Error(
          `Rate limited: ${errorMessage} (retry after ${retryAfter}s)`
        );
      }

      throw new Error(
        `WordPress API error (${response.status}): ${errorMessage}`
      );
    }

    return response.json() as Promise<T>;
  }

  /**
   * Test the connection to WordPress.
   */
  async testConnection(): Promise<{ ok: boolean; message: string }> {
    try {
      const result = await this.request<{ builder: { version: string } }>(
        '/schema/settings'
      );
      return {
        ok: true,
        message: `Connected to Divi ${result.builder?.version ?? 'unknown'}`,
      };
    } catch (error) {
      return {
        ok: false,
        message: `Connection failed: ${error instanceof Error ? error.message : String(error)}`,
      };
    }
  }

  /**
   * Perform a capability handshake with the WP plugin.
   *
   * As of #486 there is no global plugin-version floor — compatibility is
   * decided per-tool against `result.capabilities`. This method only:
   *  - issues the request (network/auth errors propagate)
   *  - normalizes the legacy pre-1.2.0 shape (`capabilities: string[]`)
   *    into the post-1.2.0 shape (`capabilities: Record<string,boolean>`)
   *    so the rest of the server can assume a uniform map.
   *
   * The plugin still rejects servers below its own MIN_SERVER_VERSION
   * with HTTP 426 — that error surfaces here as a regular request error.
   */
  async handshake(
    serverVersion: string,
  ): Promise<HandshakeResult> {
    const result = await this.request<HandshakeResult>('/handshake', {
      method: 'POST',
      body: { mcp_server_version: serverVersion },
    });

    // Pre-1.2.0 plugins emit `capabilities` as a string[] of coarse
    // namespace tags. Coerce to an empty map so per-tool gates fail fast
    // with the upgrade hint instead of silently passing because of a
    // shape mismatch.
    if (Array.isArray(result.capabilities)) {
      result.capabilities = {};
    } else if (
      result.capabilities === null ||
      typeof result.capabilities !== 'object'
    ) {
      result.capabilities = {};
    }

    return result;
  }
}
