/**
 * Server↔plugin compatibility surface.
 *
 * As of #486, the global `MIN_PLUGIN_VERSION` floor is gone. Compatibility
 * is now decided per-tool against a capability map returned by the plugin's
 * `/handshake`. See `wp-client.ts` (handshake parse) and `index.ts`
 * (`requireCapability` gate at each plugin-touching tool entry).
 *
 * Legacy plugins may emit `capabilities` as a string[] of coarse namespace
 * tags, not the per-tool map this server expects. `wp-client.ts` normalizes
 * that legacy shape to an empty map, which makes every gated tool fail fast
 * with capability-based upgrade guidance. No numeric plugin-version floor is
 * inferred from that legacy response.
 *
 * `compareVersions` is kept exported because handshake helper code and
 * future per-tool soft-deprecation messages may want it.
 */

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

/**
 * Per-target presence record (ADR-003 § handshake shape). `present`
 * reports whether the target plugin is installed + bootable on this
 * site; `version` is advisory only and may be null when version
 * detection is unavailable (front-end requests can't always reach
 * `get_plugin_data()`).
 */
export interface HandshakeTarget {
  present: boolean;
  version?: string | null;
}

/**
 * Advisory plugin version/presence record contributed by extension plugins.
 * This is a diagnostics/preflight surface only; capability gates continue to
 * use the explicit capability map and target/module flags.
 */
export interface HandshakePluginInfo {
  active: boolean;
  version?: string | null;
}

/**
 * Shape returned by `POST /diviops/v1/handshake`.
 *
 * `capabilities` is a per-tool map keyed by post-rename tool slug
 * (without the `diviops_` prefix). Older plugins (pre-1.2.0) emit
 * a string[] of coarse namespace keys; the server normalizes that
 * legacy shape to an empty map (every gated tool then fails fast
 * with an upgrade hint, which is the intended behavior).
 *
 * ADR-003 / ADR-007 Pro-extension fields (`pro_active`,
 * `pro_version`, `available_targets`, `active_modules`) are
 * optional — Free-only sites omit them entirely. The server treats
 * absence as `pro_active: false` + empty target/module maps so
 * Pro-gated tools cleanly decline registration on Free sites.
 */
export interface HandshakeResult {
  compatible: boolean;
  plugin_version?: string | null;
  min_server: string;
  divi: {
    active: boolean;
    version: string | null;
  };
  capabilities: Record<string, boolean>;
  /** ADR-007 § 7.1 — Pro plugin presence flag. Undefined on Free sites. */
  pro_active?: boolean;
  /** ADR-003 — Pro plugin version (when `pro_active === true`). */
  pro_version?: string;
  /** ADR-003 — per-target presence map (FluentCart, future slices). */
  available_targets?: Record<string, HandshakeTarget>;
  /** ADR-003 — per-target admin-controlled activation toggle. */
  active_modules?: Record<string, boolean>;
  /** Advisory installed-plugin versions for S0 preflight reports. */
  plugins?: Record<string, HandshakePluginInfo>;
  /** Permission-gated non-secret identity of the authenticated App Password owner. */
  authenticated_user?: { id: number; login: string };
  /** Observed WordPress site URL; normalized and pinned by launcher mode. */
  site_url?: string;
}

export function proToolGatesSatisfied(
  state: {
    proActive: boolean;
    availableTargets: Record<string, HandshakeTarget>;
    activeModules: Record<string, boolean>;
    capabilities: Record<string, boolean>;
  },
  gates: { target: string; capabilityKey: string },
): boolean {
  if (state.proActive !== true) return false;
  const target = state.availableTargets[gates.target];
  if (!target || target.present !== true) return false;
  if (state.activeModules[gates.target] !== true) return false;
  if (state.capabilities[gates.capabilityKey] !== true) return false;
  return true;
}

/**
 * Thrown when a tool handler calls `requireCapability(key)` and the
 * plugin's handshake response did not include `key`. The server's
 * tool dispatch wraps this into the MCP error response, surfacing
 * the upgrade hint to the agent.
 */
export class MissingCapabilityError extends Error {
  constructor(
    public readonly capability: string,
    public readonly pluginVersion?: string | null,
    public readonly pluginComponent: "free" | "pro" = "free",
  ) {
    super(capabilityMissingMessage(capability, pluginVersion, pluginComponent));
    this.name = 'MissingCapabilityError';
  }
}

export function observedVersion(version?: string | null): string | null {
  if (typeof version !== "string") return null;
  const normalized = version.trim();
  return normalized && normalized.toLowerCase() !== "unknown"
    ? normalized
    : null;
}

export function capabilityMissingMessage(
  capability: string,
  pluginVersion?: string | null,
  pluginComponent: "free" | "pro" = "free",
): string {
  const component =
    pluginComponent === "pro"
      ? "Pro WordPress plugin"
      : "Free WordPress plugin";
  const version = observedVersion(pluginVersion);
  const versionEvidence = version ? ` (observed version ${version})` : "";
  return (
    `The connected ${component}${versionEvidence} does not advertise ` +
    `the "${capability}" capability required by this operation. ` +
    "MCP server and WordPress plugin versions are independent."
  );
}

export function capabilityUpgradeHint(
  capability: string,
  pluginComponent: "free" | "pro" = "free",
  alternative?: string,
): string {
  const component =
    pluginComponent === "pro"
      ? "Pro WordPress plugin"
      : "Free WordPress plugin";
  const fallback = alternative ? ` ${alternative}` : "";
  return (
    "MCP server and WordPress plugin versions are independent. " +
    `Install a compatible ${component} from the same DiviOps suite release ` +
    `or a newer supported component that advertises "${capability}", then ` +
    "reconnect or restart the MCP session to refresh the capability handshake." +
    fallback
  );
}
