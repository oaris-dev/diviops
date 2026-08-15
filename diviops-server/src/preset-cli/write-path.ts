/**
 * Apply-mode write path for the preset-emitter CLI.
 *
 * Reuses the existing server WP-client conventions:
 *  - `WP_URL` / `WP_USER` / `WP_APP_PASSWORD` env vars.
 *  - `WPClient` from `wp-client.ts` for the HTTP + Basic-Auth machinery.
 *  - `WPClient.handshake()` for the plugin capability map.
 *
 * Before any network call the CLI rejects foreign CSS aliases in attrs, then
 * verifies the plugin handshake reports `storage_multipath_probe_v1` (the
 * storage-path capability contract).
 *
 * `--dry-run` never reaches this module: it requires no credentials, no
 * handshake, and no network.
 */

import { WPClient } from "../wp-client.js";
import {
  capabilityMissingMessage,
  capabilityUpgradeHint,
  observedVersion,
  type HandshakeResult,
} from "../compatibility.js";
import type { DiviopsResponse } from "../envelope.js";
import {
  type ForeignVarRef,
  formatIsolationError,
  scanAttrsForForeignVarRefs,
} from "../validate-attrs.js";
import type { ButtonPresetEntry } from "./button-emitter.js";
import { buildPresetCreateBody } from "./button-emitter.js";
import type { HeadingFontPresetEntry } from "./heading-font-emitter.js";
import { buildHeadingFontPresetCreateBody } from "./heading-font-emitter.js";
import type { TextBodyFontPresetEntry } from "./text-body-font-emitter.js";
import { buildTextBodyFontPresetCreateBody } from "./text-body-font-emitter.js";
import type { SpacingPresetEntry } from "./spacing-emitter.js";
import { buildSpacingPresetCreateBody } from "./spacing-emitter.js";

/** The plugin capability the storage-path capability contract ships. */
export const STORAGE_CAPABILITY = "storage_multipath_probe_v1";

/** The REST route the CLI posts to — same route `diviops_preset_create` uses. */
export const PRESET_CREATE_ROUTE = "/preset/create";

/** Minimal client surface the write path needs — eased for mocking in tests. */
export interface PresetWriteClient {
  handshake(serverVersion: string): Promise<HandshakeResult>;
  requestEnveloped<T = unknown>(
    endpoint: string,
    options?: {
      method?: string;
      body?: Record<string, unknown>;
      params?: Record<string, string>;
    },
  ): Promise<DiviopsResponse<T>>;
}

export class CredentialsMissingError extends Error {
  constructor(missing: string[]) {
    super(
      `Apply mode requires WordPress credentials. Missing: ${missing.join(", ")}. ` +
        `Set WP_URL / WP_USER / WP_APP_PASSWORD (the same env vars the MCP server uses). ` +
        `Use --dry-run to compose preset JSON without credentials.`,
    );
    this.name = "CredentialsMissingError";
  }
}

export class CapabilityMissingError extends Error {
  constructor(
    public readonly capability: string,
    public readonly pluginVersion: string | null | undefined,
    public readonly serverVersion: string | null | undefined,
    public readonly tool: string = "diviops-preset --apply",
  ) {
    const cleanPluginVersion = observedVersion(pluginVersion);
    const cleanServerVersion = observedVersion(serverVersion);
    const pluginEvidence = cleanPluginVersion
      ? ` observed_free_plugin_version=${cleanPluginVersion};`
      : "";
    const serverEvidence = cleanServerVersion
      ? ` mcp_server_version=${cleanServerVersion};`
      : "";
    super(
      capabilityMissingMessage(capability, pluginVersion) +
        " " +
        capabilityUpgradeHint(capability) +
        ` Diagnostics: tool=${tool}; capability=${capability};` +
        pluginEvidence +
        serverEvidence,
    );
    this.name = "CapabilityMissingError";
  }
}

export class PresetIsolationError extends Error {
  constructor(public readonly refs: ForeignVarRef[]) {
    super(formatIsolationError("diviops-preset --apply", refs));
    this.name = "PresetIsolationError";
  }
}

/** Refuse foreign CSS aliases in a preset body before any network call. */
export function assertPresetBodyIsolation(
  body: Record<string, unknown>,
): void {
  const attrs = body.attrs;
  if (typeof attrs !== "object" || attrs === null || Array.isArray(attrs)) {
    return;
  }
  const hits = scanAttrsForForeignVarRefs(attrs as Record<string, unknown>);
  if (hits.length > 0) throw new PresetIsolationError(hits);
}

/** Build a `WPClient` from the standard env vars, or throw if any are absent. */
export function buildClientFromEnv(
  env: NodeJS.ProcessEnv = process.env,
): WPClient {
  const url = env.WP_URL ?? "";
  const user = env.WP_USER ?? "";
  const pass = env.WP_APP_PASSWORD ?? "";
  const missing: string[] = [];
  if (!url) missing.push("WP_URL");
  if (!user) missing.push("WP_USER");
  if (!pass) missing.push("WP_APP_PASSWORD");
  if (missing.length > 0) throw new CredentialsMissingError(missing);
  return new WPClient({
    siteUrl: url,
    username: user,
    applicationPassword: pass,
  });
}

/**
 * Verify the plugin handshake reports `storage_multipath_probe_v1`.
 * Throws `CapabilityMissingError` if absent. Returns the handshake result
 * so callers can surface the plugin version.
 */
export async function assertStorageCapability(
  client: PresetWriteClient,
  serverVersion: string,
): Promise<HandshakeResult> {
  const hs = await client.handshake(serverVersion);
  if (!hs.capabilities || hs.capabilities[STORAGE_CAPABILITY] !== true) {
    throw new CapabilityMissingError(
      STORAGE_CAPABILITY,
      hs.plugin_version,
      serverVersion,
    );
  }
  return hs;
}

async function applyPresetBody(
  client: PresetWriteClient,
  body: Record<string, unknown>,
  serverVersion: string,
): Promise<DiviopsResponse<unknown>> {
  assertPresetBodyIsolation(body);
  await assertStorageCapability(client, serverVersion);
  return client.requestEnveloped(PRESET_CREATE_ROUTE, {
    method: "POST",
    body,
  });
}

/**
 * Apply a button preset: isolation gate, capability gate, then POST.
 *
 * Both gates run BEFORE the write. The write goes through the existing
 * storage-routed route — no plugin route is added here.
 */
export async function applyButtonPreset(
  client: PresetWriteClient,
  entry: ButtonPresetEntry,
  opts: { serverVersion: string; dry_run?: boolean },
): Promise<DiviopsResponse<unknown>> {
  const body = buildPresetCreateBody(entry, { dry_run: opts.dry_run });
  return applyPresetBody(client, body, opts.serverVersion);
}

/**
 * Apply a `divi/font` heading group preset: isolation gate, capability gate,
 * then POST to `/preset/create`. Mirrors `applyButtonPreset`'s sequence and
 * reuses the existing storage-routed route (no plugin route is added).
 *
 * The `pattern_variant` metadata is intentionally NOT in the wire body —
 * variant selection is a client-side registry-gate decision and the
 * server route accepts only the standard preset-create fields.
 */
export async function applyHeadingFontPreset(
  client: PresetWriteClient,
  entry: HeadingFontPresetEntry,
  opts: { serverVersion: string; dry_run?: boolean },
): Promise<DiviopsResponse<unknown>> {
  const body = buildHeadingFontPresetCreateBody(entry, {
    dry_run: opts.dry_run,
  });
  return applyPresetBody(client, body, opts.serverVersion);
}

/**
 * Apply a `divi/font-body` body-text group preset for `divi/text`:
 * isolation gate, capability gate, then POST to `/preset/create`. Mirrors
 * `applyHeadingFontPreset`'s sequence and reuses the existing storage-routed
 * route (no plugin route is added).
 *
 * The `pattern_variant` metadata is intentionally NOT in the wire body —
 * variant selection is a client-side registry-gate decision and the
 * server route accepts only the standard preset-create fields.
 */
export async function applyTextBodyFontPreset(
  client: PresetWriteClient,
  entry: TextBodyFontPresetEntry,
  opts: { serverVersion: string; dry_run?: boolean },
): Promise<DiviopsResponse<unknown>> {
  const body = buildTextBodyFontPresetCreateBody(entry, {
    dry_run: opts.dry_run,
  });
  return applyPresetBody(client, body, opts.serverVersion);
}

/**
 * Apply a `divi/spacing` section group preset: isolation gate, capability
 * gate, then POST to `/preset/create`. Mirrors `applyTextBodyFontPreset`'s
 * sequence and reuses the existing storage-routed route (no plugin route is
 * added).
 *
 * Unlike the font emitters, the spacing entry carries `primary_attr_name`
 * (`"module"` for the section cell per the canonical capture), which IS
 * sent on the wire — the `/preset/create` route accepts it as an optional
 * snake_case param and stores it as `primaryAttrName` in the preset.
 */
export async function applySpacingPreset(
  client: PresetWriteClient,
  entry: SpacingPresetEntry,
  opts: { serverVersion: string; dry_run?: boolean },
): Promise<DiviopsResponse<unknown>> {
  const body = buildSpacingPresetCreateBody(entry, {
    dry_run: opts.dry_run,
  });
  return applyPresetBody(client, body, opts.serverVersion);
}
