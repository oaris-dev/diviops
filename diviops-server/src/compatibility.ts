/**
 * Version compatibility between MCP server and WP plugin.
 */

/** Minimum WP plugin version this server requires. */
export const MIN_PLUGIN_VERSION = '1.0.0-beta.22';

/**
 * Compare two semver-like version strings (supports pre-release tags).
 *
 * Returns:
 *  -1 if a < b
 *   0 if a === b
 *   1 if a > b
 *
 * Pre-release versions (e.g. 1.0.0-beta.22) sort before their release (1.0.0).
 */
export function compareVersions(a: string, b: string): -1 | 0 | 1 {
  const parseVersion = (v: string) => {
    const [core, pre] = v.split('-', 2);
    const parts = core.split('.').map(Number);
    return { parts, pre: pre ?? null };
  };

  const va = parseVersion(a);
  const vb = parseVersion(b);

  // Compare numeric parts.
  const maxLen = Math.max(va.parts.length, vb.parts.length);
  for (let i = 0; i < maxLen; i++) {
    const na = va.parts[i] ?? 0;
    const nb = vb.parts[i] ?? 0;
    if (na < nb) return -1;
    if (na > nb) return 1;
  }

  // Equal numeric parts — pre-release sorts before release.
  if (va.pre === null && vb.pre === null) return 0;
  if (va.pre !== null && vb.pre === null) return -1;
  if (va.pre === null && vb.pre !== null) return 1;

  // Both have pre-release — compare lexicographically with numeric awareness.
  const aParts = va.pre!.split('.');
  const bParts = vb.pre!.split('.');
  const preLen = Math.max(aParts.length, bParts.length);
  for (let i = 0; i < preLen; i++) {
    const ap = aParts[i];
    const bp = bParts[i];
    if (ap === undefined) return -1;
    if (bp === undefined) return 1;
    const an = Number(ap);
    const bn = Number(bp);
    if (!isNaN(an) && !isNaN(bn)) {
      if (an < bn) return -1;
      if (an > bn) return 1;
    } else {
      if (ap < bp) return -1;
      if (ap > bp) return 1;
    }
  }

  return 0;
}

export interface HandshakeResult {
  compatible: boolean;
  plugin_version: string;
  min_server: string;
  divi: {
    active: boolean;
    version: string | null;
  };
  capabilities: string[];
}
