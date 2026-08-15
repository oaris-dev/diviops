/**
 * `$variable()` color-token helpers for the preset-emitter CLI.
 *
 * Divi 5.5.x color bindings use the token grammar:
 *
 *   $variable({"type":"color","value":{"name":"gcid-heading-color","settings":{}}})$
 *
 * Two load-bearing rules (both from VB-verified memory):
 *  - The token MUST end with `)$` — a missing trailing `$` causes silent
 *    render failure (`feedback_variable_trailing_dollar`).
 *  - The `value` payload MUST be an object `{name, settings}`, not a flat
 *    `gcid-*` string — a flat string crashes PHP 8 in Divi's DynamicData
 *    resolver (`feedback_variable_color_value_object_shape`).
 *
 * The CLI accepts a color param that is EITHER a literal (a hex string,
 * or an already-formed `$variable(...)$` token) OR a bare `gcid-*` /
 * `gvid-*` token name, which this module wraps into the canonical shape.
 */

/** A bare Divi variable token name (gcid-* or gvid-*). */
const BARE_TOKEN_RE = /^(gcid|gvid)-[A-Za-z0-9_-]+$/;

/** An already-formed `$variable(...)$` token. */
export function isVariableToken(value: string): boolean {
  return value.startsWith("$variable(") && value.endsWith(")$");
}

/** A bare token name like `gcid-primary-color`. */
export function isBareTokenName(value: string): boolean {
  return BARE_TOKEN_RE.test(value);
}

/**
 * Build a canonical `$variable()` color token from a bare token name.
 *
 * The inner JSON uses single-escape `\"` when the whole preset is later
 * `JSON.stringify`-d — that is handled by JSON.stringify itself; here we
 * return the raw string value (un-stringified), which is what belongs in
 * the in-memory preset object.
 */
export function buildColorVariableToken(tokenName: string): string {
  if (!isBareTokenName(tokenName)) {
    throw new Error(
      `Invalid variable token name "${tokenName}". Expected a bare gcid-*/gvid-* name ` +
        `(e.g. "gcid-primary-color"), a hex literal, or an already-formed $variable(...)$ token.`,
    );
  }
  const payload = JSON.stringify({
    type: "color",
    value: { name: tokenName, settings: {} },
  });
  return `$variable(${payload})$`;
}

/**
 * Normalize a CLI color param to the value that belongs in the preset.
 *
 * - An already-formed `$variable(...)$` token → returned verbatim (the
 *   caller is responsible for its correctness; the trailing `)$` is
 *   asserted).
 * - A bare `gcid-*`/`gvid-*` name → wrapped into the canonical token.
 * - Anything else (a hex string, `rgba(...)`, etc.) → returned verbatim
 *   as a literal color value.
 */
export function normalizeColorValue(value: string): string {
  if (isVariableToken(value)) {
    return value;
  }
  if (value.startsWith("$variable(") && !value.endsWith(")$")) {
    throw new Error(
      `Malformed $variable() token "${value}" — must end with ")$" ` +
        `(missing trailing "$" causes silent render failure).`,
    );
  }
  if (isBareTokenName(value)) {
    return buildColorVariableToken(value);
  }
  return value;
}
