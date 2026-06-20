#!/usr/bin/env node

/**
 * Divi 5 MCP Server
 *
 * Exposes Divi Visual Builder operations as MCP tools for Claude.
 * Requires the companion WordPress plugin "diviops-agent" to be active.
 *
 * Auth: WordPress Application Passwords (Basic Auth).
 * Config: Environment variables WP_URL, WP_USER, WP_APP_PASSWORD.
 */

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { WPClient } from "./wp-client.js";
import { MissingCapabilityError, proToolGatesSatisfied } from "./compatibility.js";
import {
  type DiviopsResponse,
  ErrorCodes,
  envelopeMap,
  recordIdempotent,
  serializeEnvelope,
  withCode,
  wrapResponse,
} from "./envelope.js";
import {
  preflightCrossEnvHeaderSync,
  type SourceLayoutPayload,
  type TargetLayoutContext,
} from "./cross-env-preflight/header-preflight.js";
import { optimizeSchema } from "./schema-optimizer.js";
import { schemaModuleRoute } from "./schema-route.js";
import { createWpCli } from "./wp-cli.js";
import {
  findForeignVarRefs,
  scanAttrsForForeignVarRefs,
  isolationErrorResult,
} from "./validate-attrs.js";
import { readFileSync, readdirSync } from "fs";
import { join, dirname } from "path";
import { fileURLToPath } from "url";

const __dirname = dirname(fileURLToPath(import.meta.url));

// ── Config ───────────────────────────────────────────────────────────

const WP_URL = process.env.WP_URL ?? "";
const WP_USER = process.env.WP_USER ?? "";
const WP_APP_PASSWORD = process.env.WP_APP_PASSWORD ?? "";

if (!WP_URL || !WP_USER || !WP_APP_PASSWORD) {
  const missing = [
    !WP_URL && "WP_URL",
    !WP_USER && "WP_USER",
    !WP_APP_PASSWORD && "WP_APP_PASSWORD",
  ].filter(Boolean);
  console.error(
    `Error: Missing required environment variable(s): ${missing.join(", ")}.\n` +
      "Set WP_URL to your WordPress site URL (e.g. http://mysite.local).\n" +
      "Generate an Application Password at: WP Admin → Users → Profile → Application Passwords",
  );
  process.exit(1);
}

const wp = new WPClient({
  siteUrl: WP_URL,
  username: WP_USER,
  applicationPassword: WP_APP_PASSWORD,
});

// WP-CLI (optional — Local by Flywheel via WP_PATH, or custom wrapper via WP_CLI_CMD)
const WP_PATH = process.env.WP_PATH ?? "";
const WP_CLI_CMD = process.env.WP_CLI_CMD?.trim() ?? "";
const LOCAL_SITE_ID = process.env.LOCAL_SITE_ID ?? "";
let wpCli: ReturnType<typeof createWpCli> | null = null;
if (WP_CLI_CMD) {
  try {
    wpCli = createWpCli({
      wpCliCmd: WP_CLI_CMD,
      wpPath: WP_PATH || process.cwd(),
    });
  } catch (e) {
    console.error(`WP-CLI setup failed (non-fatal): ${e}`);
  }
} else if (WP_PATH) {
  try {
    wpCli = createWpCli({
      wpPath: WP_PATH,
      localSiteId: LOCAL_SITE_ID || undefined,
    });
  } catch (e) {
    console.error(`WP-CLI setup failed (non-fatal): ${e}`);
  }
}

// ── Version ─────────────────────────────────────────────────────────

// Read version from package.json at startup — single source of truth.
const SERVER_VERSION: string = (() => {
  try {
    const pkg = JSON.parse(
      readFileSync(join(__dirname, "..", "package.json"), "utf-8"),
    );
    return pkg.version ?? "0.0.0";
  } catch {
    return "0.0.0";
  }
})();

// ── MCP Server ───────────────────────────────────────────────────────

const server = new McpServer({
  name: "diviops-mcp",
  version: SERVER_VERSION,
});

// ── Capability map (#486) ────────────────────────────────────────────

// Per-tool capability gate. Populated by main()'s handshake call against
// the plugin's /handshake response. Plugin-touching tools register via
// `registerPluginTool` (below), which calls `requireCapability(slug)` at
// entry and converts the typed `MissingCapabilityError` into an MCP error
// response with an upgrade hint.
//
// Server-local tools (wp-cli wrappers, in-memory templates, meta_ping /
// meta_info) register directly via `server.registerTool` — they have no
// plugin dependency.
//
// Three distinct startup states the gate must honor (Codex review):
//   - "ok"      — handshake succeeded, capabilities is the real map.
//                 Missing key ⇒ MissingCapabilityError (upgrade hint).
//   - "failed"  — handshake threw (network, auth, 5xx, etc.). The gate
//                 must not synthesize an upgrade hint here; instead it
//                 falls through and lets the underlying tool's
//                 `wp.request()` surface the real error (pre-PR
//                 behavior, e.g. "WordPress API error (401): …").
//   - "pending" — handshake hasn't run yet (defensive; main() awaits it
//                 before connecting transport, so this should not be
//                 reachable in normal flow).
type HandshakeState =
  | {
      kind: "ok";
      capabilities: Record<string, boolean>;
      pluginVersion: string;
      proVersion?: string;
      // ADR-003 / ADR-007 Pro-extension fields — present on `ok` only.
      // Free-only sites populate these as `false` / `{}` via wp-client
      // normalization so gates can read them without per-call checks.
      proActive: boolean;
      availableTargets: Record<string, { present: boolean; version?: string | null }>;
      activeModules: Record<string, boolean>;
    }
  | { kind: "failed" }
  | { kind: "pending" };

let handshakeState: HandshakeState = { kind: "pending" };

const BASE_META_CAPABILITIES = [
  "pages",
  "modules",
  "presets",
  "library",
  "theme_builder",
  "canvas",
  "variables",
  "templates",
  "icons",
  "validation",
  "preview",
];

function enabledCapabilityKeys(prefix?: string): string[] {
  if (handshakeState.kind !== "ok") return [];
  const state = handshakeState;
  return Object.keys(state.capabilities)
    .filter((key) => state.capabilities[key])
    .filter((key) => (prefix ? key.startsWith(prefix) : true))
    .sort();
}

function buildMetaInfo() {
  const fluentCartCapabilityKeys = enabledCapabilityKeys("fluentcart_");
  const crossEnvCapabilityKeys = enabledCapabilityKeys("cross_env_");
  const fluentCartTarget =
    handshakeState.kind === "ok"
      ? handshakeState.availableTargets.fluentcart ?? null
      : null;
  const crossEnvTarget =
    handshakeState.kind === "ok"
      ? handshakeState.availableTargets.cross_env ?? null
      : null;
  const fluentCartActive =
    handshakeState.kind === "ok" &&
    handshakeState.proActive === true &&
    fluentCartTarget?.present === true &&
    handshakeState.activeModules.fluentcart === true &&
    fluentCartCapabilityKeys.length > 0;
  const crossEnvActive =
    handshakeState.kind === "ok" &&
    handshakeState.proActive === true &&
    crossEnvTarget?.present === true &&
    handshakeState.activeModules.cross_env === true &&
    crossEnvCapabilityKeys.length > 0;
  const capabilities = fluentCartActive
    ? [...BASE_META_CAPABILITIES, "fluentcart"]
    : [...BASE_META_CAPABILITIES];
  if (crossEnvActive) capabilities.push("cross_env");

  return {
    brand: "DiviOps",
    server: "diviops-mcp",
    version: SERVER_VERSION,
    license: "MIT",
    capabilities,
    handshake:
      handshakeState.kind === "ok"
        ? {
            state: "ok",
            plugin_version: handshakeState.pluginVersion,
            capability_count: enabledCapabilityKeys().length,
          }
        : { state: handshakeState.kind },
    pro:
      handshakeState.kind === "ok"
        ? {
            active: handshakeState.proActive,
            version: handshakeState.proVersion ?? null,
          }
        : {
            active: false,
            version: null,
          },
    slices: {
      fluentcart: {
        target: fluentCartTarget,
        active: fluentCartActive,
        module_active:
          handshakeState.kind === "ok"
            ? handshakeState.activeModules.fluentcart === true
            : false,
        tool_capabilities: fluentCartCapabilityKeys,
      },
      cross_env: {
        target: crossEnvTarget,
        active: crossEnvActive,
        module_active:
          handshakeState.kind === "ok"
            ? handshakeState.activeModules.cross_env === true
            : false,
        tool_capabilities: crossEnvCapabilityKeys,
      },
    },
    wp_cli: wpCli ? wpCli.getAllowedCommands() : false,
  };
}

function requireCapability(key: string): void {
  // Only gate when we have a real capability map. On handshake failure,
  // bypass the gate so the underlying request surfaces the actual cause
  // (auth, network, 5xx) rather than misattributing it to the plugin
  // version.
  if (handshakeState.kind !== "ok") return;
  if (!handshakeState.capabilities[key]) {
    throw new MissingCapabilityError(key, handshakeState.pluginVersion);
  }
}

// `any` here is deliberate, not laziness. McpServer.registerTool is a
// multi-overload generic whose `cb`/`InputArgs` machinery doesn't compose
// with `Parameters<typeof server.registerTool>` (overload collapse to
// `never`). Restating its Zod-driven generics in this thin wrapper buys
// no real safety — the per-callsite `inputSchema` Zod object at every
// usage site below is what enforces actual argument shape; this helper
// only adds a capability-check + an error envelope on top, both shape-
// independent. Scope: 4 narrow suppressions, all in this 25-line block.
/* eslint-disable @typescript-eslint/no-explicit-any */
function registerPluginTool<H extends (args: any) => Promise<any>>(
  name: string,
  config: any,
  handler: H,
): void {
  const key = name.replace(/^diviops_/, "");
  const wrapped = (async (args: any) => {
    try {
      requireCapability(key);
    } catch (e) {
      if (e instanceof MissingCapabilityError) {
        return {
          content: [{ type: "text" as const, text: e.message }],
          isError: true,
        };
      }
      throw e;
    }
    return handler(args);
  }) as any;
  recordIdempotent(name, config?._meta);
  server.registerTool(name, config, wrapped);
}

/**
 * Server-local tools (no plugin dependency) register via this thin shim
 * instead of `server.registerTool` directly. Same recording obligation
 * as `registerPluginTool` — every tool surface needs `_meta.idempotent`
 * captured into the runtime table so `serializeEnvelope(result, name)`
 * can emit it on per-call responses (#597).
 */
function registerLocalTool<H extends (args: any) => Promise<any>>(
  name: string,
  config: any,
  handler: H,
): void {
  recordIdempotent(name, config?._meta);
  server.registerTool(name, config, handler);
}

/**
 * Register a Pro-coverage-slice tool (ADR-003 / ADR-007).
 *
 * Differs from `registerPluginTool` in three ways:
 *
 * 1. **Capability-key override.** The MCP tool name follows the
 *    `diviops_<namespace>_<verb>` convention (e.g. `diviops_fc_product_list`),
 *    while the plugin-side capability key follows ADR-007's
 *    `<target>_<noun>_<verb>` shape (e.g. `fluentcart_product_list`).
 *    The two don't share a stripping rule, so the capability key must
 *    be passed explicitly.
 *
 * 2. **Conditional registration.** The tool is registered with the
 *    MCP server ONLY when all four gates align at handshake time:
 *      - handshakeState.kind === "ok"
 *      - proActive === true
 *      - availableTargets[target].present === true
 *      - activeModules[target] === true
 *      - capabilities[capabilityKey] === true
 *
 *    When any gate is false the call is a no-op — the tool simply
 *    doesn't exist on the MCP surface. Per ADR-007 "no error surface,
 *    just absence."
 *
 * 3. **No runtime requireCapability().** Because registration is
 *    already gated at startup, the wrapped handler doesn't need to
 *    recheck capabilities on every call. The wp.request() call is
 *    still naturally guarded by the plugin's permission_callback +
 *    route presence at the WP side.
 *
 * **Call-site ordering.** This helper MUST be invoked from
 * `registerProTools()` (run after the handshake settles in `main()`),
 * not at module load time. Calling it at module load would always
 * short-circuit on `handshakeState.kind === "pending"`. The Pro tools
 * are defined inside `registerProTools()` precisely so they can read
 * the resolved handshakeState.
 */
function registerProTool<H extends (args: any) => Promise<any>>(
  name: string,
  config: any,
  handler: H,
  gates: { target: string; capabilityKey: string },
): void {
  if (handshakeState.kind !== "ok") return;
  if (!proToolGatesSatisfied(handshakeState, gates)) return;

  recordIdempotent(name, config?._meta);
  server.registerTool(name, config, handler);
}
/* eslint-enable @typescript-eslint/no-explicit-any */

// ── dry_run convention ──────────────────────────────────────────────
//
// Standard description suffix appended to every write tool that accepts
// dry_run, and a shared Zod field reused across the registrations. The
// suffix lets the model see one consistent line per tool ("Pass dry_run:
// true to preview the change plan without mutating state."), and the
// shared field guarantees the same default + description across the
// surface.
//
// Shape returned when dry_run is true (built by the plugin's
// dry_run_response helper):
//   { ok: true, data: { dry_run: true, plan: { summary, changes[, warnings] }, ...extra } }
// Apply mode keeps each tool's pre-existing response shape unchanged.
const DRY_RUN_DESC_SUFFIX =
  " Pass dry_run: true to preview the change plan without mutating state.";
const DRY_RUN_FIELD = z
  .boolean()
  .optional()
  .default(false)
  .describe(
    "When true, return the change plan { summary, changes[, warnings] } without mutating state.",
  );

// ── Read Tools ───────────────────────────────────────────────────────

registerPluginTool(
  "diviops_page_list",
  {
    description:
      "List pages/posts in the WordPress site. Returns title, ID, URL, status, and whether each page uses Divi builder. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
    inputSchema: {
      post_type: z
        .string()
        .optional()
        .default("page")
        .describe('Post type to query: "page", "post", or custom type'),
      per_page: z
        .number()
        .optional()
        .default(20)
        .describe("Number of results per page (max 100)"),
      page: z.number().optional().default(1).describe("Page number"),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ post_type, per_page, page }) => {
    const result = await wp.requestEnveloped("/page/list", {
      params: {
        post_type: post_type ?? "page",
        per_page: String(per_page ?? 20),
        page: String(page ?? 1),
      },
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_page_list") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_page_get",
  {
    description:
      "Get detailed info about a specific page including its raw Divi block content. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing page_id returns ok:false with code 'not_found' and a hint pointing to diviops_page_list.",
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ page_id }) => {
    const result = await wp.requestEnveloped(`/page/get/${page_id}`);
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_page_get") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_page_get_layout",
  {
    description:
      "Get the parsed block tree for a page. Returns slim targeting metadata by default (block names, admin labels, text previews, auto_index). Use full: true for complete attrs (warning: can be very large on complex pages). Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing page_id returns ok:false with code 'not_found'.",
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      full: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "Include full block attrs and raw content (default: false for slim mode)",
        ),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ page_id, full }) => {
    const result = await wp.requestEnveloped(`/page/get-layout/${page_id}`, {
      params: full ? { full: "true" } : {},
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_page_get_layout") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_module_get",
  {
    description:
      'Get one targeted Divi module/block from a page, post, or Theme Builder layout by auto_index (e.g. "text:5"), admin label, or text content. Uses the same selector validation as diviops_module_update: pass exactly one of auto_index, label, or match_text; use occurrence with duplicate labels. Default mode returns identity, block name, admin label, auto_index, text preview, source bounds, and a compact attr summary. Use full: true to include decoded attrs and raw serialized block markup for the matched module only. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing module returns code "not_found" with error.data = { target_kind: "module", target_mode, target_value, page_id }.',
    inputSchema: {
      page_id: z
        .number()
        .int()
        .describe("WordPress post/layout ID whose Divi block content should be searched"),
      label: z
        .string()
        .optional()
        .describe("Admin label of the module (exact match)"),
      match_text: z
        .string()
        .optional()
        .describe(
          "Text to find in module attrs/innerContent (case-insensitive substring, first match)",
        ),
      auto_index: z
        .string()
        .optional()
        .describe(
          'Auto-index target in "type:N" format (e.g. "text:5", "icon:3"). Get from diviops_page_get_layout.',
        ),
      occurrence: z
        .number()
        .int()
        .min(1)
        .optional()
        .default(1)
        .describe(
          "Which occurrence to target when multiple modules share the same label (1-based)",
        ),
      full: z
        .boolean()
        .optional()
        .default(false)
        .describe("Include decoded attrs and raw serialized block markup for the matched module only"),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ page_id, label, match_text, auto_index, occurrence, full }) => {
    const params: Record<string, string> = {};
    if (auto_index) params.auto_index = auto_index;
    if (label) params.label = label;
    if (match_text) params.match_text = match_text;
    if (occurrence > 1) params.occurrence = String(occurrence);
    if (full) params.full = "true";
    const qs = new URLSearchParams(params).toString();
    const suffix = qs ? `?${qs}` : "";
    const result = await wp.requestEnveloped(`/module/get/${page_id}${suffix}`);
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_module_get") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_schema_list_modules",
  {
    description:
      "List all available Divi modules (block types) with their names, titles, and categories. Use this to discover what modules can be used in layouts. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async () => {
    const result = await wp.requestEnveloped("/schema/modules");
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_schema_list_modules") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_schema_get_module",
  {
    description:
      "Get the attribute schema for a Divi module. Default mode 'single' returns one module's schema (optimized, ~70% smaller; pass raw: true for full). Mode 'dump_all' snapshots every Divi module in one call and includes a `schema_version` hash over the canonical *PresetAttrsMap.php files — build-time entry point for the skill regen pipeline; ignores `module_name` and `raw`. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
    inputSchema: {
      mode: z
        .enum(["single", "dump_all"])
        .optional()
        .default("single")
        .describe("'single' (default): return one module's schema. 'dump_all': return every module keyed by name plus schema_version + divi_version."),
      module_name: z
        .string()
        .optional()
        .describe(
          'Module name, e.g. "text", "image", "accordion", or full "divi/text". Required when mode="single"; ignored when mode="dump_all".',
        ),
      raw: z
        .boolean()
        .optional()
        .default(false)
        .describe("Return full schema including CSS selectors and VB metadata. Applies to mode='single' only."),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ mode, module_name, raw }) => {
    if (mode === "dump_all") {
      // Capability gate for the dump-all surface: handled here (rather
      // than the wrapper's auto-derived `schema_get_module` key) so older
      // plugins without /schema/module/dump-all return a typed envelope
      // error instead of a 404 from the underlying request.
      if (
        handshakeState.kind === "ok" &&
        !handshakeState.capabilities["schema_get_module_dump_all"]
      ) {
        const err = new MissingCapabilityError(
          "schema_get_module_dump_all",
          handshakeState.pluginVersion,
        );
        const failure: DiviopsResponse<never> = {
          ok: false,
          error: {
            code: ErrorCodes.CAPABILITY_MISSING,
            message: err.message,
            hint: "Update the diviops-agent WP plugin to a version that exposes the dump-all surface.",
          },
        };
        return {
          content: [{ type: "text" as const, text: serializeEnvelope(failure, "diviops_schema_get_module") }],
        };
      }
      const result = await wp.requestEnveloped("/schema/module/dump-all");
      return {
        content: [{ type: "text" as const, text: serializeEnvelope(result, "diviops_schema_get_module") }],
      };
    }

    if (!module_name) {
      const failure: DiviopsResponse<never> = {
        ok: false,
        error: {
          code: ErrorCodes.INVALID_INPUT,
          message: "module_name is required when mode='single'",
        },
      };
      return {
        content: [{ type: "text" as const, text: serializeEnvelope(failure, "diviops_schema_get_module") }],
      };
    }

    const result = await wp.requestEnveloped<Record<string, unknown>>(
      schemaModuleRoute(module_name),
    );
    const projected = envelopeMap(result, (data) =>
      raw ? data : optimizeSchema(data as Record<string, any>),
    );
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(projected, "diviops_schema_get_module") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_schema_get_settings",
  {
    description:
      "Get Divi site settings including theme options, site info, and builder version. Useful for understanding the site context before generating content. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async () => {
    const result = await wp.requestEnveloped("/schema/settings");
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_schema_get_settings") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_global_color_list",
  {
    description:
      "Get the global color palette defined in Divi. Returns `{ colors, customizer }` — `colors` is the user-defined palette stored under `et_divi.et_global_data.global_colors` (read via the #719 priority-ordered probe); `customizer` surfaces the five WP-customizer-bound defaults (gcid-primary-color / gcid-secondary-color / gcid-heading-color / gcid-body-color / gcid-link-color) sourced from `\\ET\\Builder\\Packages\\GlobalData\\GlobalData::$customizer_colors`. Top-level `_meta.source_path` + `_meta.probed_paths` document which storage path yielded the user palette; `_meta.customizer_source` describes the customizer-bound default surface. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async () => {
    const result = await wp.requestEnveloped("/global-color/list");
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_global_color_list") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_global_color_audit_storage",
  {
    description:
      "Audit the global_colors STORAGE LOCATION landscape (#719 contract). Aggregates entries across all candidate paths for the global_colors surface with per-entry provenance via `_meta.entry_sources = { <id>: { path, provenance } }`. Provenance vocabulary: `et_divi_nested` (canonical 5.x — `et_divi.et_global_data.global_colors`), `top_level` (hypothetical standalone option, not observed on tested 5.5.x substrates), `wp_customizer` (the five WP-customizer-bound defaults — gcid-primary-color / gcid-secondary-color / gcid-heading-color / gcid-body-color / gcid-link-color, sourced from GlobalData::$customizer_colors). Warnings: `id_collision` (same id across two paths). The user palette overrides customizer defaults when both present (matches Divi's render-side behavior at GlobalData::get_global_colors). Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async () => {
    const result = await wp.requestEnveloped("/global-color/audit-storage");
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_global_color_audit_storage") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_global_color_create",
  {
    description:
      "Add a new global color to Divi's palette. The plugin mints a fresh `gcid-<uuid>` ID (the server forwards the color entry without an id and the WP-side handler generates one) and writes to the et_global_data option in the canonical Divi shape `{color, folder, label, lastUpdated, status, usedInPosts}`. The color appears in the VB color picker after save and can be referenced via `$variable({type:color,value:{name:gcid-...}})$` tokens. Note: Divi's AI Agent bundle has a Zod schema gap that drops `label` on its own writes — our PHP path goes around that bug by writing directly to the option. CONCURRENCY: this is a read-modify-write on a single WP option with no conflict detection. If a Visual Builder session holds stale global data, its next save can clobber colors written here in the interim. Coordinate writes when VB sessions are active, or have the user reload VB after MCP color writes. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; input-shape rejections (non-CSS color value, missing required `color` for a new entry) return code 'invalid_input' with `error.data` documenting the failed field." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      color: z
        .string()
        .describe('CSS color value — hex (e.g. "#ff0000", "#ff0000aa") or functional rgba/hsla notation. Bare keywords like "red" are not accepted.'),
      label: z
        .string()
        .optional()
        .describe('Human-readable label shown in the VB color picker (e.g. "Brand Red"). Optional — defaults to empty (matches Divi\'s stock palette which leaves labels blank).'),
      folder: z
        .string()
        .optional()
        .describe("Folder name for grouping colors in the picker UI. Optional — defaults to empty (no folder)."),
      status: z
        .enum(["active", "archived"])
        .optional()
        .default("active")
        .describe('Color status — "active" (default, visible in picker) or "archived" (hidden but preserved).'),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "false" },
  },
  async ({ color, label, folder, status, dry_run }) => {
    const colorEntry: Record<string, any> = { color };
    if (label !== undefined) colorEntry.label = label;
    if (folder !== undefined) colorEntry.folder = folder;
    if (status) colorEntry.status = status;
    const body: Record<string, unknown> = { colors: [colorEntry], mode: "merge" };
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped("/global-color/upsert", {
      method: "POST",
      body,
    });
    return { content: [{ type: "text" as const, text: serializeEnvelope(result, "diviops_global_color_create") }] };
  },
);

registerPluginTool(
  "diviops_global_color_update",
  {
    description:
      "Update an existing global color by gcid. Only provided fields are updated; omitted fields are preserved. The lastUpdated timestamp is bumped on every write. Use diviops_global_color_list first to find the gcid for a color. NOTE: the underlying upsert is merge-mode — supplying a gcid that doesn't yet exist creates a new color with that gcid (provided it satisfies the gcid charset/length rules) rather than failing as 'not found'. Pre-check via diviops_global_color_list if you need strict-update semantics. CONCURRENCY: same VB-session race caveat as diviops_global_color_create — the write is read-modify-write on a single WP option, so an active VB session's next save can clobber this update. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; malformed gcid charset/length returns code 'invalid_input' with `error.data` documenting the failed field; non-CSS color value returns code 'invalid_input'; attempts to write to a customizer-bound default (gcid-primary-color / gcid-secondary-color / gcid-heading-color / gcid-body-color / gcid-link-color) return code 'variable.customizer_default_immutable' (HTTP 403) with `error.data = { id, managed_by: 'wp_customizer' }` — same code as diviops_variable_delete because the identity is identical (5.4+ unified gcid-* into the variable manager while preserving customizer-binding for the five legacy defaults)." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      gcid: z
        .string()
        .describe('Global color ID, e.g. "gcid-abc123..." (must start with "gcid-"). Get from diviops_global_color_list.'),
      color: z
        .string()
        .optional()
        .describe('New CSS color value — hex or rgba/hsla notation. Omit to keep existing.'),
      label: z
        .string()
        .optional()
        .describe('New human-readable label. Pass empty string to clear.'),
      folder: z
        .string()
        .optional()
        .describe('New folder. Pass empty string to clear.'),
      status: z
        .enum(["active", "archived"])
        .optional()
        .describe('Change status — "active" or "archived". Omit to keep existing.'),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "conditional" },
  },
  async ({ gcid, color, label, folder, status, dry_run }) => {
    const colorEntry: Record<string, any> = { id: gcid };
    if (color !== undefined) colorEntry.color = color;
    if (label !== undefined) colorEntry.label = label;
    if (folder !== undefined) colorEntry.folder = folder;
    if (status) colorEntry.status = status;
    const body: Record<string, unknown> = { colors: [colorEntry], mode: "merge" };
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped("/global-color/upsert", {
      method: "POST",
      body,
    });
    return { content: [{ type: "text" as const, text: serializeEnvelope(result, "diviops_global_color_update") }] };
  },
);

registerPluginTool(
  "diviops_global_color_delete",
  {
    description:
      "Delete a global color from the registry by gcid. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }. Live-reference detection uses parse_blocks over post_content across pages / TB layouts / library / canvas + the preset registry (mirrors diviops_variable_delete) — MCP-authored content is detected reliably, not just VB-saved content. Returns code 'conflict' (HTTP 409) when references exist with `error.data = { id, ref_count, locations[], scan_truncated, scanned_posts }`. Pass `force: true` to override; orphan refs will render as invalid CSS until pages are re-authored. Always refuses to delete the 5 customizer-bound defaults (gcid-primary-color, gcid-secondary-color, gcid-heading-color, gcid-body-color, gcid-link-color) regardless of force — returns code 'variable.customizer_default_immutable' (HTTP 403) with `error.data = { id, managed_by: 'wp_customizer' }`. Missing gcids return 'not_found' (HTTP 404). Malformed gcid (empty or missing `gcid-` prefix) returns 'invalid_input'. CONCURRENCY: same VB-session race caveat as diviops_global_color_create — an active VB session's next save can re-introduce a color we just deleted if the session held stale data." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      gcid: z
        .string()
        .describe('Global color ID to delete (must start with "gcid-").'),
      force: z
        .boolean()
        .optional()
        .default(false)
        .describe("If true, delete even when live references exist. Customizer-bound defaults remain protected regardless."),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ gcid, force, dry_run }) => {
    const body: Record<string, any> = { gcid };
    if (force) body.force = true;
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped("/global-color/delete", {
      method: "POST",
      body,
    });
    return { content: [{ type: "text" as const, text: serializeEnvelope(result, "diviops_global_color_delete") }] };
  },
);

registerPluginTool(
  "diviops_global_font_list",
  {
    description:
      "List the DiviOps-managed global fonts registered under `et_divi.et_global_data.global_fonts` (gfid-* Google catalog) AND the local-hosted Pattern B fonts registered under `et_uploaded_fonts` (per #719 AC #9). Returns `{ count, fonts, uploaded_count, uploaded_fonts }` — both maps always emitted as JSON objects (consistent shape across empty/populated substrates). Top-level `_meta.sources` discriminates the two surfaces with `provenance: \"gfid_catalog\"` vs `provenance: \"uploaded_local\"`. Distinct from the variable-manager font tokens (`gvid-*` under `et_global_data.global_variables.fonts`, surfaced via `diviops_variable_list({type:\"fonts\"})`) — `global_font_*` is the DiviOps-controlled font catalog presets bind to via canonical `gfid-` slugs. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async () => {
    const result = await wp.requestEnveloped("/global-font/list");
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_global_font_list") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_global_font_audit_storage",
  {
    description:
      "Audit the global_fonts STORAGE LOCATION landscape (#719 contract). Aggregates entries across the gfid-* catalog (`et_divi.et_global_data.global_fonts`) AND the local-hosted `et_uploaded_fonts` Pattern B surface with per-entry provenance via `_meta.entry_sources = { <id>: { path, provenance } }`. Provenance vocabulary: `gfid_catalog` (Google CDN canonical), `uploaded_local` (file-uploaded local-hosted fonts per `reference_local_hosted_fonts_eu_pattern`). Warnings: `id_collision` (same id in both — upstream contract violation since the two surfaces are key-namespace-disjoint by convention). Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async () => {
    const result = await wp.requestEnveloped("/global-font/audit-storage");
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_global_font_audit_storage") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_global_font_create",
  {
    description:
      "Create a new global font in DiviOps's registry under `et_global_data.global_fonts`. Mints a fresh `gfid-<uuid>` if `id` is omitted; otherwise uses the supplied id (must match `gfid-[0-9a-z-]{1,80}`; auto-prefixes `gfid-` if missing). Strict create — collision on existing id returns `conflict` (HTTP 409) with `error.data = { id, existing }`; use diviops_global_font_update to modify an existing record. Stored shape: `{ family, source, weights[], subsets[], label, fallback, status, lastUpdated }`. Required: `family` (CSS family name, e.g. \"Sora\") + `source` (one of `google`/`system`/`custom`). Distinct from `diviops_variable_create({type:\"fonts\"})` which writes `gvid-*` font tokens to the variable manager — `global_font_*` is the DiviOps catalog presets bind via `gfid-` slugs. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; input-shape rejections (malformed id, invalid source enum, non-array weights/subsets, missing required `family`/`source` for a new entry) collapse onto `invalid_input` with structured `error.data`." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      family: z
        .string()
        .describe('CSS font family name (e.g. "Sora", "Inter", "JetBrains Mono"). Stored as the bare name; consumers wrap in single quotes when emitting CSS.'),
      source: z
        .enum(["google", "system", "custom"])
        .describe('Font source: "google" (Google Fonts), "system" (system/web-safe families), or "custom" (self-hosted/CDN).'),
      id: z
        .string()
        .optional()
        .describe('Optional explicit gfid (e.g. "gfid-oa-sora"). Auto-prefixes `gfid-` if missing; must match `[0-9a-z-]{1,80}` after the prefix. Omit to mint a fresh `gfid-<uuid>`.'),
      weights: z
        .array(z.union([z.number(), z.string()]))
        .optional()
        .describe('Font weights to load. Accepts integers (100,200,...,900) or keyword strings ("normal","bold","lighter","bolder"). Defaults to []. Drives Google Fonts URL composition + loader hints.'),
      subsets: z
        .array(z.string())
        .optional()
        .describe('Character subsets (e.g. ["latin","latin-ext"]). Defaults to []. Permissive — not allowlisted server-side (Google adds new subsets regularly).'),
      label: z
        .string()
        .optional()
        .describe('Human-readable display label. Defaults to the family name.'),
      fallback: z
        .string()
        .optional()
        .describe('CSS fallback chain appended after the family name (e.g. "sans-serif", "Georgia, serif"). Defaults to empty.'),
      status: z
        .enum(["active", "archived"])
        .optional()
        .default("active")
        .describe('Font status — "active" (visible) or "archived" (hidden but preserved).'),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "false" },
  },
  async ({ family, source, id, weights, subsets, label, fallback, status, dry_run }) => {
    const body: Record<string, unknown> = { family, source };
    if (id !== undefined) body.id = id;
    if (weights !== undefined) body.weights = weights;
    if (subsets !== undefined) body.subsets = subsets;
    if (label !== undefined) body.label = label;
    if (fallback !== undefined) body.fallback = fallback;
    if (status) body.status = status;
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped("/global-font/create", {
      method: "POST",
      body,
    });
    return { content: [{ type: "text" as const, text: serializeEnvelope(result, "diviops_global_font_create") }] };
  },
);

registerPluginTool(
  "diviops_global_font_update",
  {
    description:
      "Update an existing global font by gfid. Strict update — `id` must reference an existing record; unknown gfid returns `not_found` (HTTP 404) with `error.data = { id }` (unlike `diviops_global_color_update`'s merge-mode semantics). Partial: only supplied fields are written, omitted fields preserved; `lastUpdated` bumped on every write. To rename a font's family slug, use diviops_global_font_delete + diviops_global_font_create — `family` itself can be updated in place but the `gfid` identity is immutable via this tool. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; malformed id charset/length returns 'invalid_input'; missing `id` returns 'invalid_input' with `error.data.missing = \"id\"`; invalid source enum / non-array weights / non-array subsets return 'invalid_input' with structured `error.data`." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      id: z
        .string()
        .describe('Global font ID (e.g. "gfid-oa-sora"). Required. Get from diviops_global_font_list.'),
      family: z
        .string()
        .optional()
        .describe('New CSS family name. Omit to keep existing.'),
      source: z
        .enum(["google", "system", "custom"])
        .optional()
        .describe('New source. Omit to keep existing.'),
      weights: z
        .array(z.union([z.number(), z.string()]))
        .optional()
        .describe('New weights array. Omit to keep existing; pass [] to clear.'),
      subsets: z
        .array(z.string())
        .optional()
        .describe('New subsets array. Omit to keep existing; pass [] to clear.'),
      label: z
        .string()
        .optional()
        .describe('New label. Pass empty string to clear.'),
      fallback: z
        .string()
        .optional()
        .describe('New CSS fallback chain. Pass empty string to clear.'),
      status: z
        .enum(["active", "archived"])
        .optional()
        .describe('New status — "active" or "archived". Omit to keep existing.'),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "conditional" },
  },
  async ({ id, family, source, weights, subsets, label, fallback, status, dry_run }) => {
    const body: Record<string, unknown> = { id };
    if (family !== undefined) body.family = family;
    if (source !== undefined) body.source = source;
    if (weights !== undefined) body.weights = weights;
    if (subsets !== undefined) body.subsets = subsets;
    if (label !== undefined) body.label = label;
    if (fallback !== undefined) body.fallback = fallback;
    if (status) body.status = status;
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped("/global-font/update", {
      method: "POST",
      body,
    });
    return { content: [{ type: "text" as const, text: serializeEnvelope(result, "diviops_global_font_update") }] };
  },
);

registerPluginTool(
  "diviops_global_font_delete",
  {
    description:
      "Delete a global font from the registry by gfid. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }. Live-reference detection uses parse_blocks over post_content across pages / TB layouts / library / canvas + the preset registry (parallel to diviops_variable_delete / diviops_global_color_delete) — MCP-authored content is detected reliably. Returns code 'conflict' (HTTP 409) when references exist with `error.data = { id, ref_count, locations[], scan_truncated, scanned_posts }`. Pass `force: true` to override; orphan refs will fall back to the browser default until pages are re-authored. Missing gfid returns 'not_found' (HTTP 404) with `error.data = { id }`. Malformed gfid (empty or missing `gfid-` prefix) returns 'invalid_input'. Unlike global_color_delete, no customizer-bound `gfid-*` defaults exist to protect — the Divi customizer-bound font defaults live in `heading_font` / `body_font` plain WP options, not this registry." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      id: z
        .string()
        .describe('Global font ID to delete (must start with "gfid-").'),
      force: z
        .boolean()
        .optional()
        .default(false)
        .describe("If true, delete even when live references exist."),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ id, force, dry_run }) => {
    const body: Record<string, any> = { id };
    if (force) body.force = true;
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped("/global-font/delete", {
      method: "POST",
      body,
    });
    return { content: [{ type: "text" as const, text: serializeEnvelope(result, "diviops_global_font_delete") }] };
  },
);

registerPluginTool(
  "diviops_meta_find_icon",
  {
    description:
      "Search for icons by keyword. Returns matching icons with unicode, type (fa/divi), and weight. Use the returned unicode/type/weight in Blurb icon or Icon module attributes. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
    inputSchema: {
      query: z
        .string()
        .describe('Search keyword (e.g. "rocket", "heart", "chart", "user")'),
      type: z
        .enum(["all", "fa", "divi"])
        .optional()
        .default("all")
        .describe(
          'Filter by icon type: "all", "fa" (Font Awesome), or "divi" (ETmodules)',
        ),
      limit: z
        .number()
        .optional()
        .default(10)
        .describe("Max results (default 10, max 50)"),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ query, type, limit }) => {
    const result = await wp.requestEnveloped(
      `/meta/find-icon?q=${encodeURIComponent(query)}&type=${type ?? "all"}&limit=${limit ?? 10}`,
    );
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_meta_find_icon") },
      ],
    };
  },
);

// ── Write Tools ──────────────────────────────────────────────────────

registerPluginTool(
  "diviops_page_update_content",
  {
    description:
      "Update the content of a page with Divi block markup. The content should be valid WordPress block markup using divi/* blocks. IMPORTANT: This overwrites the entire page content. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing page_id returns 'not_found', edit-permission failures return 'forbidden' (HTTP 403), non-string content returns 'invalid_input' with `error.data = { field, received_type }`." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID to update"),
      content: z
        .string()
        .describe(
          "Full page content in WordPress block markup format (<!-- wp:divi/section -->...<!-- /wp:divi/section -->)",
        ),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "conditional" },
  },
  async ({ page_id, content, dry_run }) => {
    const hits = findForeignVarRefs(content, "content");
    if (hits.length > 0) return isolationErrorResult("diviops_page_update_content", hits);
    const body: Record<string, unknown> = { content };
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped(`/page/update-content/${page_id}`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_page_update_content") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_render_preview",
  {
    description:
      "Render Divi block markup to HTML. Accepts EITHER inline `content` (string of block markup) OR `page_id` (loads `post_content` from the DB, requires edit_post capability on the page — useful for previewing shipped pages without round-tripping the markup blob). Provide exactly one. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload is { rendered_html: string }. Errors map to `invalid_input` (neither/both supplied, or invalid page_id), `forbidden` (caller lacks edit_post on the page), `not_found` (page_id does not exist), or `divi_error` (parser/render exception, with truncated message and full detail in `error.data.detail`).",
    inputSchema: {
      content: z
        .string()
        .optional()
        .describe(
          "Divi block markup to render to HTML. Provide exactly one of {content, page_id}.",
        ),
      page_id: z
        .number()
        .int()
        .optional()
        .describe(
          "WordPress post/page ID to read post_content from the DB. Requires edit_post capability on the page. Provide exactly one of {content, page_id}.",
        ),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ content, page_id }) => {
    if (page_id !== undefined) {
      try {
        requireCapability("validate_render_by_page_id");
      } catch (e) {
        if (e instanceof MissingCapabilityError) {
          return {
            content: [{ type: "text" as const, text: e.message }],
            isError: true,
          };
        }
        throw e;
      }
    }
    const result = await wp.requestEnveloped("/render", {
      method: "POST",
      body: { content, page_id },
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_render_preview") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_validate_blocks",
  {
    description:
      "Validate Divi block markup before saving. Accepts EITHER inline `content` (string of block markup) OR `page_id` (loads `post_content` from the DB, requires edit_post capability on the page — useful for regression checks on shipped pages without round-tripping the markup blob). Provide exactly one. Checks structure (malformed comments, unknown blocks, missing builderVersion), required attributes (layout display on containers), and known pitfalls (button padding path, icon.enable, gradient enabled/positions). Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload is { valid: bool, total_blocks: number, errors: Finding[], warnings: Finding[] } where each Finding is { block, index, code, message, path? }. Note: shape errors detected in the markup surface as success-branch `data.errors[]` entries (NOT `validation_failed` envelopes) — the findings array is the payload, not an error. The envelope's error branch fires only for tool-level failures (`invalid_input` for neither/both supplied or invalid page_id; `forbidden` for missing edit_post; `not_found` for unknown page_id; `divi_error` for an exception in the walker).",
    inputSchema: {
      content: z
        .string()
        .optional()
        .describe(
          "Divi block markup to validate. Provide exactly one of {content, page_id}.",
        ),
      page_id: z
        .number()
        .int()
        .optional()
        .describe(
          "WordPress post/page ID to read post_content from the DB. Requires edit_post capability on the page. Provide exactly one of {content, page_id}.",
        ),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ content, page_id }) => {
    if (page_id !== undefined) {
      try {
        requireCapability("validate_render_by_page_id");
      } catch (e) {
        if (e instanceof MissingCapabilityError) {
          return {
            content: [{ type: "text" as const, text: e.message }],
            isError: true,
          };
        }
        throw e;
      }
    }
    const result = await wp.requestEnveloped("/validate/blocks", {
      method: "POST",
      body: { content, page_id },
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_validate_blocks") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_section_append",
  {
    description:
      "Append a Divi section to an existing page without overwriting other content. Use this to incrementally build pages. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing page_id returns 'not_found' with `error.data.target_kind = \"page\"`, edit-permission failures return 'forbidden' (HTTP 403), non-string content or invalid position returns 'invalid_input' with `error.data = { field, ... }`." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      content: z
        .string()
        .describe(
          "Section block markup to append (<!-- wp:divi/section ...-->...<!-- /wp:divi/section -->)",
        ),
      position: z
        .enum(["start", "end"])
        .optional()
        .default("end")
        .describe('Where to insert: "start" or "end" (default)'),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "false" },
  },
  async ({ page_id, content, position, dry_run }) => {
    const hits = findForeignVarRefs(content, "content");
    if (hits.length > 0) return isolationErrorResult("diviops_section_append", hits);
    const body: Record<string, unknown> = { content, position: position ?? "end" };
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped(`/section/append/${page_id}`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_section_append") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_section_replace",
  {
    description:
      "Replace a section on a page. Target by admin label OR text content. Use occurrence when multiple sections match. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing section returns 'not_found' with `error.data = { target_kind: \"section\", ... }`, missing/ambiguous selectors return 'invalid_input' with `error.data.reason`." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      label: z
        .string()
        .optional()
        .describe("Admin label of the section to replace"),
      match_text: z
        .string()
        .optional()
        .describe(
          "Text to search for in section content (case-insensitive substring)",
        ),
      content: z
        .string()
        .describe("New section block markup to replace the matched section"),
      occurrence: z
        .number()
        .int()
        .min(1)
        .optional()
        .default(1)
        .describe("Which match to target (1-based, default: 1)"),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "conditional" },
  },
  async ({ page_id, label, match_text, content, occurrence, dry_run }) => {
    const hits = findForeignVarRefs(content, "content");
    if (hits.length > 0) return isolationErrorResult("diviops_section_replace", hits);
    const body: Record<string, any> = { content, occurrence };
    if (label) body.label = label;
    if (match_text) body.match_text = match_text;
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped(`/section/replace/${page_id}`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_section_replace") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_section_remove",
  {
    description:
      "Remove a section from a page. Target by admin label OR text content. Use occurrence when multiple sections match. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; section selectors lack identity-preserving repeat-call detection so a removal of an already-removed section returns 'not_found' (HTTP 404) — the side-effect (section is gone) holds regardless of how many times you call." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      label: z
        .string()
        .optional()
        .describe("Admin label of the section to remove"),
      match_text: z
        .string()
        .optional()
        .describe(
          "Text to search for in section content (case-insensitive substring)",
        ),
      occurrence: z
        .number()
        .int()
        .min(1)
        .optional()
        .default(1)
        .describe("Which match to target (1-based, default: 1)"),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ page_id, label, match_text, occurrence, dry_run }) => {
    const body: Record<string, any> = { occurrence };
    if (label) body.label = label;
    if (match_text) body.match_text = match_text;
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped(`/section/remove/${page_id}`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_section_remove") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_section_get",
  {
    description:
      "Get the raw block markup of a section. Target by admin label OR text content. Use occurrence when multiple sections match. Returns total_matches warning when duplicates exist. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing section returns 'not_found' with `error.data.target_kind = \"section\"`.",
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      label: z
        .string()
        .optional()
        .describe("Admin label of the section to retrieve"),
      match_text: z
        .string()
        .optional()
        .describe(
          "Text to search for in section content (case-insensitive substring)",
        ),
      occurrence: z
        .number()
        .int()
        .min(1)
        .optional()
        .default(1)
        .describe("Which match to target (1-based, default: 1)"),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ page_id, label, match_text, occurrence }) => {
    const params: Record<string, string> = { occurrence: String(occurrence) };
    if (label) params.label = label;
    if (match_text) params.match_text = match_text;
    const qs = new URLSearchParams(params).toString();
    const result = await wp.requestEnveloped(`/section/get/${page_id}?${qs}`);
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_section_get") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_module_update",
  {
    description:
      'Update specific attributes of a module. Target by auto_index (e.g. "text:5"), admin label, or text content. Uses dot notation for attribute paths. Example: {"content.decoration.headingFont.h2.font.desktop.value.color": "#ff0000"}. For paths whose key segments contain literal dots — notably Composable Settings preset slots like groupPreset["title.decoration.spacing"] — escape the inner dots with `\\.` to keep the segment intact: {"groupPreset.title\\\\.decoration\\\\.spacing.presetId": ["uuid"]}. Priority: auto_index > label > match_text. Use occurrence with label when duplicates exist. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing module returns code "not_found" with error.data = { target_kind: "module", target_mode, target_value, page_id }, non-array attrs returns code "invalid_input" with error.data.field = "attrs", malformed Divi block markup surfaces code "divi_error" (HTTP 500).' +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      label: z
        .string()
        .optional()
        .describe("Admin label of the module (exact match)"),
      match_text: z
        .string()
        .optional()
        .describe(
          "Text to find in module innerContent (case-insensitive substring, first match)",
        ),
      auto_index: z
        .string()
        .optional()
        .describe(
          'Auto-index target in "type:N" format (e.g. "text:5", "icon:3"). Get from diviops_page_get_layout. Takes priority over label/match_text.',
        ),
      occurrence: z
        .number()
        .int()
        .min(1)
        .optional()
        .default(1)
        .describe(
          "Which occurrence to target when multiple modules share the same label (1-based)",
        ),
      attrs: z
        .record(z.string(), z.any())
        .describe("Attribute paths (dot notation) and their new values"),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "conditional" },
  },
  async ({ page_id, label, match_text, auto_index, occurrence, attrs, dry_run }) => {
    const hits = scanAttrsForForeignVarRefs(attrs);
    if (hits.length > 0) return isolationErrorResult("diviops_module_update", hits);
    const body: Record<string, any> = { attrs };
    if (auto_index) body.auto_index = auto_index;
    if (label) body.label = label;
    if (match_text) body.match_text = match_text;
    if (occurrence > 1) body.occurrence = occurrence;
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped(`/module/update/${page_id}`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_module_update") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_module_move",
  {
    description:
      'Move a module to a new position on the page. Specify source and target blocks using auto_index (e.g. "text:3"), admin label, or text content. Position "before" or "after" the target. Works with any block type including sections, rows, and modules. Both blocks are found in the original content, so auto_index values refer to positions before the move. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing source/target blocks return code "not_found" with error.data = { target_kind: "block", context: "source"|"target", ... }, moving a block into itself returns code "module.overlap" (HTTP 400).' +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      source_label: z
        .string()
        .optional()
        .describe("Admin label of the module to move"),
      source_match_text: z
        .string()
        .optional()
        .describe("Text to search for in source module (case-insensitive)"),
      source_auto_index: z
        .string()
        .optional()
        .describe(
          'Auto-index of the module to move in "type:N" format (e.g. "text:3")',
        ),
      source_occurrence: z
        .number()
        .int()
        .min(1)
        .optional()
        .default(1)
        .describe(
          "Which occurrence when multiple sources match by label (1-based)",
        ),
      target_label: z
        .string()
        .optional()
        .describe("Admin label of the reference module"),
      target_match_text: z
        .string()
        .optional()
        .describe("Text to search for in target module (case-insensitive)"),
      target_auto_index: z
        .string()
        .optional()
        .describe(
          'Auto-index of the reference module in "type:N" format (e.g. "text:5")',
        ),
      target_occurrence: z
        .number()
        .int()
        .min(1)
        .optional()
        .default(1)
        .describe(
          "Which occurrence when multiple targets match by label (1-based)",
        ),
      position: z
        .enum(["before", "after"])
        .describe("Place the source before or after the target"),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "conditional" },
  },
  async ({
    page_id,
    source_label,
    source_match_text,
    source_auto_index,
    source_occurrence,
    target_label,
    target_match_text,
    target_auto_index,
    target_occurrence,
    position,
    dry_run,
  }) => {
    const body: Record<string, any> = { position };
    if (source_label) body.source_label = source_label;
    if (source_match_text) body.source_match_text = source_match_text;
    if (source_auto_index) body.source_auto_index = source_auto_index;
    if (source_occurrence > 1) body.source_occurrence = source_occurrence;
    if (target_label) body.target_label = target_label;
    if (target_match_text) body.target_match_text = target_match_text;
    if (target_auto_index) body.target_auto_index = target_auto_index;
    if (target_occurrence > 1) body.target_occurrence = target_occurrence;
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped(`/module/move/${page_id}`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_module_move") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_module_lock",
  {
    description:
      'Lock a module so VB users cannot edit it. Sets attrs.locked = {desktop: {value: "on"}} per Divi\'s per-breakpoint convention (verified via VB-save probe). Locked modules render normally on frontend; only VB-side editing is gated. Same targeting pattern as diviops_module_update — pick one of label / match_text / auto_index. Use diviops_module_unlock to reverse. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing module returns code "not_found" with error.data.target_kind = "module".' +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      label: z.string().optional().describe("Admin label of the module to lock (exact match)"),
      match_text: z.string().optional().describe("Text to search for in module markup (case-insensitive)"),
      auto_index: z.string().optional().describe('Auto-index in "type:N" format (e.g. "text:3")'),
      occurrence: z.number().int().min(1).optional().default(1).describe("Which occurrence when multiple modules share the same label (1-based)"),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "false" },
  },
  async ({ page_id, label, match_text, auto_index, occurrence, dry_run }) => {
    const body: Record<string, any> = {};
    if (label) body.label = label;
    if (match_text) body.match_text = match_text;
    if (auto_index) body.auto_index = auto_index;
    if (occurrence && occurrence > 1) body.occurrence = occurrence;
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped(`/module/lock/${page_id}`, { method: "POST", body });
    return { content: [{ type: "text" as const, text: serializeEnvelope(result, "diviops_module_lock") }] };
  },
);

registerPluginTool(
  "diviops_module_unlock",
  {
    description:
      "Unlock a module by removing attrs.locked entirely. Matches Divi VB's convention: unlocked = attribute absent (NOT {value: 'off'}) — VB doesn't write a falsy value on unlock, it removes the field. Same targeting pattern as diviops_module_lock. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing module returns 'not_found' with `error.data.target_kind = \"module\"`." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      label: z.string().optional().describe("Admin label of the module to unlock (exact match)"),
      match_text: z.string().optional().describe("Text to search for in module markup (case-insensitive)"),
      auto_index: z.string().optional().describe('Auto-index in "type:N" format'),
      occurrence: z.number().int().min(1).optional().default(1).describe("Which occurrence when multiple modules share the same label (1-based)"),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "false" },
  },
  async ({ page_id, label, match_text, auto_index, occurrence, dry_run }) => {
    const body: Record<string, any> = {};
    if (label) body.label = label;
    if (match_text) body.match_text = match_text;
    if (auto_index) body.auto_index = auto_index;
    if (occurrence && occurrence > 1) body.occurrence = occurrence;
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped(`/module/unlock/${page_id}`, { method: "POST", body });
    return { content: [{ type: "text" as const, text: serializeEnvelope(result, "diviops_module_unlock") }] };
  },
);

registerPluginTool(
  "diviops_module_clone",
  {
    description:
      'Clone a module by deep-copying its block JSON and inserting it next to the source within the same parent container. Position controls before/after placement (default "after"). Module IDs are reassigned by Divi at render time from the block tree position, so the clone gets fresh IDs automatically. Same targeting pattern as diviops_module_lock. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing module returns code "not_found" with error.data.target_kind = "module", malformed parent containers surface code "divi_error" (HTTP 500).' +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      label: z.string().optional().describe("Admin label of the module to clone (exact match)"),
      match_text: z.string().optional().describe("Text to search for in module markup (case-insensitive)"),
      auto_index: z.string().optional().describe('Auto-index in "type:N" format'),
      occurrence: z.number().int().min(1).optional().default(1).describe("Which occurrence when multiple modules share the same label (1-based)"),
      position: z.enum(["before", "after"]).optional().default("after").describe('Place the clone "before" or "after" the source module within its parent.'),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "false" },
  },
  async ({ page_id, label, match_text, auto_index, occurrence, position, dry_run }) => {
    const body: Record<string, any> = {};
    if (label) body.label = label;
    if (match_text) body.match_text = match_text;
    if (auto_index) body.auto_index = auto_index;
    if (occurrence && occurrence > 1) body.occurrence = occurrence;
    if (position) body.position = position;
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped(`/module/clone/${page_id}`, { method: "POST", body });
    return { content: [{ type: "text" as const, text: serializeEnvelope(result, "diviops_module_clone") }] };
  },
);

registerPluginTool(
  "diviops_page_create",
  {
    description:
      "Create a new WordPress page, optionally with Divi block content. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; non-string content or invalid status return code 'invalid_input' with `error.data` documenting the failed field." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      title: z.string().describe("Page title"),
      content: z
        .string()
        .optional()
        .default("")
        .describe("Page content in Divi block markup format"),
      status: z
        .enum(["draft", "publish", "private"])
        .optional()
        .default("draft")
        .describe("Post status"),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "false" },
  },
  async ({ title, content, status, dry_run }) => {
    if (content) {
      const hits = findForeignVarRefs(content, "content");
      if (hits.length > 0) return isolationErrorResult("diviops_page_create", hits);
    }
    const body: Record<string, unknown> = { title, content: content ?? "", status: status ?? "draft" };
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped("/page/create", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_page_create") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_page_trash",
  {
    description:
      "Trash or permanently delete a page/post. Defaults to trash (reversible via WP Admin → Trash). Pass force=true to permanently delete (wp_delete_post — irreversible). Idempotent: trashing an already-trashed post returns ok:true with `data.already_trashed = true` (repeat-safe semantics for AI-agent retries). Pass dry_run=true to preview without mutating. Replaces wp-cli `post delete --force=0|1` routing for AI-agent callers (typed input, deterministic envelope). Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing post_id returns 'not_found', delete-permission failures return 'forbidden' (HTTP 403). Note: dry_run currently returns the route-specific shape rather than the standardized `data.plan = { summary, changes[] }` shape used by tools introduced after the dry_run convention was generalized; plan-shape standardization is tracked separately for the pre-existing dry_run wave.",
    inputSchema: {
      post_id: z.number().int().describe("WordPress post/page ID"),
      force: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "When true, permanently delete (skips trash). Default false moves to trash.",
        ),
      dry_run: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "When true, return the change plan without mutating state.",
        ),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ post_id, force, dry_run }) => {
    const result = await wp.requestEnveloped(`/page/trash/${post_id}`, {
      method: "POST",
      body: {
        force: force ?? false,
        dry_run: dry_run ?? false,
      },
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_page_trash") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_page_update_status",
  {
    description:
      "Update a page's post_status. Valid statuses: publish, draft, private, pending, future. status='future' requires date_gmt (ISO 8601 UTC, must be in the future) — server writes both post_date_gmt and the site-tz post_date so WP's scheduler picks it up. status='publish' on a previously-scheduled post clears the future date so it publishes immediately. Idempotent: same-status update returns ok:true with `data.noop = true`. Pass dry_run=true to preview. Replaces wp-cli `post update --post_status=...` routing. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing post_id returns 'not_found', edit-permission failures return 'forbidden' (HTTP 403); status enum violations and date_gmt validation failures return 'invalid_input' with `error.data` documenting the field. Note: dry_run currently returns the route-specific shape rather than the standardized `data.plan = { summary, changes[] }` shape used by tools introduced after the dry_run convention was generalized; plan-shape standardization is tracked separately for the pre-existing dry_run wave.",
    inputSchema: {
      post_id: z.number().int().describe("WordPress post/page ID"),
      status: z
        .enum(["publish", "draft", "private", "pending", "future"])
        .describe("Target post status"),
      date_gmt: z
        .string()
        .optional()
        .describe(
          "Required when status='future'. ISO 8601 UTC datetime (e.g. '2026-06-01T09:00:00Z'). Must be in the future.",
        ),
      dry_run: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "When true, return the change plan without mutating state.",
        ),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ post_id, status, date_gmt, dry_run }) => {
    const body: Record<string, any> = {
      status,
      dry_run: dry_run ?? false,
    };
    if (date_gmt) body.date_gmt = date_gmt;
    const result = await wp.requestEnveloped(`/page/update-status/${post_id}`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_page_update_status") },
      ],
    };
  },
);

// ── Preset Tools ────────────────────────────────────────────────────

registerPluginTool(
  "diviops_preset_audit",
  {
    description:
      "Audit all Divi presets (module + group). Each entry reports `block_ref_count` (page-content refs via modulePreset / groupPreset block markup), `group_ref_count` (in-registry chain refs from other presets — module presets via top-level `groupPresets.<slot>.presetId`, group presets via `attrs.groupPreset.<slot>.presetId`), and `referenced` (true if either > 0). Group presets that are chain-referenced also expose `referenced_by_presets` (UUIDs of the presets that wire them in — typically module presets, but type-agnostic). Use this before deleting — orphan-cleanup based only on page refs would silently wipe load-bearing chain-wired group presets (font, border, box-shadow, spacing, button). Also reports `orphan_default_pointers`: per-bucket `default` pointers that reference a UUID no longer present in `items[]` (caused by past unsafe deletes). Render-safe but blocks Divi's lazy recreate-on-VB-use path; clear via diviops_preset_set_default with unset=true on the affected module/group. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async () => {
    const result = await wp.requestEnveloped("/preset/audit");
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_preset_audit") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_preset_audit_storage",
  {
    description:
      "Audit the D5 preset STORAGE LOCATION landscape (#719 contract). Distinct from `diviops_preset_audit` (which audits preset CONTENT — usage refs, orphans, defaults). Aggregates entries across the canonical top-level `et_divi_builder_global_presets_d5` and the legacy nested `et_divi.builder_global_presets_d5` scratchpad on upgraded substrates, with per-entry provenance via `_meta.entry_sources = { <id>: { path, provenance } }`. Provenance vocabulary: `d5_top_level` (canonical), `d5_nested_scratchpad` (upgrade artifact), `legacy_d4_ng` (D4-era `et_divi_builder_global_presets_ng` store — OUT-OF-BAND per the banner, surfaced via entry_sources only, NEVER merged into the D5 aggregate). Warnings: `id_collision` (same id across D5 paths, same top-level shape), `shape_inconsistency` (same id, divergent top-level keys), `ng_non_empty` (legacy D4 store contains content; surface for inventory). Use this to diagnose substrate state before/after upgrades — agents do NOT auto-migrate; surfacing state is the contract. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; the routing-provenance fields sit on top-level `_meta`.",
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async () => {
    const result = await wp.requestEnveloped("/preset/audit-storage");
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_preset_audit_storage") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_preset_cleanup",
  {
    description:
      'Clean up presets. Default: remove spam presets. Optional: dedup=true to also remove duplicates, action="rename_strip_prefix" with prefix to strip a name prefix, or action="remove_orphans" with scope="spam"|"all" to remove unreferenced presets. Use dry_run: true (default) to preview. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }. Note: dry_run currently returns the route-specific summary shape rather than the standardized `data.plan = { summary, changes[] }` shape used by tools introduced after the dry_run convention was generalized; plan-shape standardization is tracked separately for the pre-existing dry_run wave.',
    inputSchema: {
      dry_run: z
        .boolean()
        .optional()
        .default(true)
        .describe(
          "If true, preview changes without applying. Set false to execute.",
        ),
      dedup: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "Remove duplicate presets with identical attrs within the same module.",
        ),
      action: z
        .string()
        .optional()
        .describe(
          'Action: "rename_strip_prefix" strips a prefix, "remove_orphans" removes unreferenced presets.',
        ),
      prefix: z
        .string()
        .optional()
        .describe(
          'Prefix to strip when action is "rename_strip_prefix" (e.g. "Online Courses ").',
        ),
      scope: z
        .enum(["spam", "all"])
        .default("spam")
        .describe(
          'Scope for remove_orphans: "spam" (only spam-named orphans) or "all" (all non-default orphans).',
        ),
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "false" },
  },
  async ({ dry_run, dedup, action, prefix, scope }) => {
    const body: Record<string, any> = { dry_run: dry_run ?? true };
    if (dedup) body.dedup = true;
    if (action) body.action = action;
    if (prefix) body.prefix = prefix;
    if (action === "remove_orphans" && scope) body.scope = scope;
    const result = await wp.requestEnveloped("/preset/cleanup", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_preset_cleanup") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_preset_update",
  {
    description:
      "Update a specific preset by ID. Can rename, replace its style attributes, and/or change its stack priority. Note: Divi serves frontend CSS from a per-post static cache at wp-content/et-cache/{post_id}/ that wp cache flush does NOT invalidate — if you're verifying a preset change on the rendered frontend, delete that dir for affected pages to force regeneration. Server-side preset state updates immediately; only the pre-rendered CSS file is stale. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing preset_id returns code 'not_found' with a hint to diviops_preset_audit." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      preset_id: z.string().describe("Preset ID (UUID or short ID)"),
      name: z.string().optional().describe("New display name for the preset"),
      attrs: z
        .record(z.string(), z.any())
        .optional()
        .describe(
          "New style attributes (replaces attrs, styleAttrs, and renderAttrs — matches VB save semantics so render cache stays in sync with edit state)",
        ),
      priority: z
        .number()
        .int()
        .optional()
        .describe(
          "Stack-merge priority. When this preset is part of a stacked-preset arrangement (e.g. base typography + brand override on the same module/group slot), Divi sorts presets ascending and merges in priority order, so a higher number wins the cascade. Default in Divi is 10 when omitted. Only meaningful for presets that participate in a stack — solo presets render the same regardless of priority.",
        ),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "conditional" },
  },
  async ({ preset_id, name, attrs, priority, dry_run }) => {
    const body: Record<string, any> = { preset_id };
    if (name) body.name = name;
    if (attrs) body.attrs = attrs;
    if (typeof priority === "number") body.priority = priority;
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped("/preset/update", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_preset_update") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_preset_delete",
  {
    description:
      "Delete a specific preset by ID. Use diviops_preset_audit first to verify the preset is unreferenced before deleting. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing preset_id returns code 'not_found' with a hint to diviops_preset_audit. Refuses with code 'conflict' (HTTP 409) and `error.data = { preset_id, type, module, name, reason: 'is_default' }` if the target is the registered default for its module/group bucket — clear the pointer first via diviops_preset_set_default with unset=true, or pass force=true to delete and clear the pointer in one write. The `reason` discriminator field leaves room for future conflict reasons (referenced_in_chain, etc.) without reshaping.",
    inputSchema: {
      preset_id: z.string().describe("Preset ID to delete"),
      force: z
        .boolean()
        .optional()
        .describe(
          "When true, deletes the preset even if it is the registered default and clears the default pointer in the same write. Default false (refuse-by-default).",
        ),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ preset_id, force }) => {
    const body: Record<string, unknown> = { preset_id };
    if (force !== undefined) body.force = force;
    const result = await wp.requestEnveloped("/preset/delete", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_preset_delete") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_preset_create",
  {
    description:
      'Create a new preset in the Divi 5 registry. For module presets, supply module_name (e.g. "divi/column", "divi/button", "divi/section"), name, and attrs. For group (attribute-level) presets, set type="group" and supply group_name ("divi/font", "divi/button", etc.), group_id ("designTitleText", "button", etc.), and optionally primary_attr_name.' +
      DRY_RUN_DESC_SUFFIX +
      " NOTE: dry_run plan does not pre-allocate the UUID — that's generated at apply time. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }. Per-bucket name uniqueness check: a name collision in the same `(bucket, bucket_key)` returns code 'conflict' (HTTP 409) with `error.data = { existing_preset_id, bucket, bucket_key, name }` so callers can branch on reuse / rename / preset_update. Bucket coordinates are the natural addressing scope: a 'Hero Title' font preset and a 'Hero Title' button preset coexist (different buckets), but two 'Hero Title' presets under `group/divi/font` collide. Input-shape rejections (missing module_name/name/attrs, type outside [module,group], group preset without group_name/group_id) return code 'invalid_input' with structured `error.data` documenting the failed field.",
    inputSchema: {
      module_name: z
        .string()
        .describe(
          'Divi module slug (e.g. "divi/column", "divi/button", "divi/section"). For group presets, this is still required and describes the module the preset originated from.',
        ),
      name: z.string().describe("Display name for the new preset"),
      attrs: z
        .record(z.string(), z.any())
        .describe(
          "Full module attribute bag (same shape as a module's top-level attrs in block markup). Saved to attrs, styleAttrs, and renderAttrs — matches VB save semantics so render cache stays in sync with edit state.",
        ),
      type: z
        .enum(["module", "group"])
        .optional()
        .default("module")
        .describe('"module" (default) or "group" for attribute-level presets.'),
      group_name: z
        .string()
        .optional()
        .describe(
          'Group name (e.g. "divi/font", "divi/button"). Required when type="group".',
        ),
      group_id: z
        .string()
        .optional()
        .describe(
          'Group id (e.g. "designTitleText", "designText", "button"). Required when type="group".',
        ),
      primary_attr_name: z
        .string()
        .optional()
        .describe(
          'Primary attr name for the group (e.g. "title" for designTitleText). Optional.',
        ),
      make_default: z
        .boolean()
        .optional()
        .describe(
          "If true, set this newly-created preset as the default for its module/group after creation. Defaults apply to NEW instances only — existing modules keep their current preset bindings (use diviops_preset_reassign for retroactive swaps). Saves a round-trip vs. calling diviops_preset_set_default after creation.",
        ),
      priority: z
        .number()
        .int()
        .optional()
        .describe(
          "Stack-merge priority. When this preset participates in a stacked-preset arrangement, Divi sorts ascending and merges in priority order — higher number wins the cascade. Default in Divi is 10 when omitted.",
        ),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "conditional" },
  },
  async ({ module_name, name, attrs, type, group_name, group_id, primary_attr_name, make_default, priority, dry_run }) => {
    if (type === "group" && (!group_name || !group_id)) {
      throw new Error(
        'type="group" requires both group_name and group_id. Example: group_name="divi/font", group_id="designTitleText".',
      );
    }
    const body: Record<string, any> = { module_name, name, attrs, type };
    if (group_name) body.group_name = group_name;
    if (group_id) body.group_id = group_id;
    if (primary_attr_name) body.primary_attr_name = primary_attr_name;
    if (make_default) body.make_default = true;
    if (typeof priority === "number") body.priority = priority;
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped("/preset/create", { method: "POST", body });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_preset_create") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_preset_reassign",
  {
    description:
      'Reassign a preset UUID across page content. Covers both module-level refs (`attrs.modulePreset[...]`) and attribute-level group-preset refs (`attrs.groupPreset.<slot>.presetId`), plus — for group presets — registry chain refs: module-bucket presets via top-level `groupPresets.<slot>.presetId`, group-bucket presets via `attrs.groupPreset.<slot>.presetId`. The `scope` param controls which ref types are walked (default "both", auto-selects based on new_uuid\'s bucket). Cross-bucket swaps (module ↔ group) are rejected with code \'preset.bucket_mismatch\' (HTTP 400) carrying `error.data = { old_bucket, new_bucket }`. Explicit scope mismatch with new_uuid\'s bucket returns code \'preset.scope_mismatch\' (HTTP 400) with `error.data = { scope, new_bucket }`. When `strip_inline=true` (default), strips inline attrs that duplicate the new preset\'s attrs (otherwise inline wins over preset): for module scope, strips from block root; for group scope, strips per-slot using Divi\'s own slot→target-path resolver (handles composite button groups, `-id-classes` suffix, FormField/checkbox/radio `attrName` mappings, cross-module translation). Both scopes enforce a singular-stack guard (skip strip when slot holds multiple presets). Unmappable group slots skip strip and emit a per-slot advisory at `summary.strip_advisory_per_slot[<module>::<slot>]`; neighbor slots are unaffected. Defaults to dry-run — set mode="apply" to actually rewrite. Use this to consolidate repeated inline styling into a reusable preset after creating one with diviops_preset_create. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing/invalid inputs return code \'invalid_input\' with structured `error.data` documenting the failed field; new_uuid not in registry returns code \'not_found\'; oversized page_ids batch returns code \'preset.too_many_pages\' with `error.data = { received, max_pages }`.',
    inputSchema: {
      old_uuid: z
        .string()
        .describe("Preset UUID to replace (can be a dangling/orphan UUID)"),
      new_uuid: z
        .string()
        .describe(
          "New preset UUID to insert. Must already exist in the registry.",
        ),
      page_ids: z
        .array(z.number().int().positive())
        .optional()
        .describe(
          "Restrict to specific post IDs. Omit to scan all pages and posts.",
        ),
      mode: z
        .enum(["dry-run", "apply"])
        .optional()
        .default("dry-run")
        .describe(
          '"dry-run" (default) returns the diff without writing. "apply" rewrites page content (and registry chains for group-scope swaps).',
        ),
      strip_inline: z
        .boolean()
        .optional()
        .default(true)
        .describe(
          "If true (default), strip inline attrs that deep-equal the new preset's attrs so the preset actually takes effect. Applies to both module-scope (block-root strip) and group-scope (per-slot strip via Divi's target-path resolver). Singular-stack guard enforced at both scopes — strip is skipped when the modulePreset stack or a groupPreset slot holds multiple presets. Unmappable group slots skip strip with a per-slot advisory. Set false to swap UUIDs only.",
        ),
      scope: z
        .enum(["module", "group", "both"])
        .optional()
        .default("both")
        .describe(
          '"module" walks `attrs.modulePreset[...]` only. "group" walks `attrs.groupPreset.<slot>.presetId` plus registry chain refs (top-level `groupPresets.<slot>.presetId` on module presets, `attrs.groupPreset.<slot>.presetId` on group presets). "both" (default) auto-selects based on new_uuid\'s bucket — module/group identity is disjoint, so there is one valid walk per swap. An explicit "module" or "group" rejects if new_uuid is in the wrong bucket.',
        ),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ old_uuid, new_uuid, page_ids, mode, strip_inline, scope }) => {
    const body: Record<string, any> = {
      old_uuid,
      new_uuid,
      mode,
      strip_inline,
      scope,
    };
    if (page_ids) body.page_ids = page_ids;
    const result = await wp.requestEnveloped("/preset/reassign", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_preset_reassign") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_preset_scan_orphans",
  {
    description:
      "Scan page content for modulePreset UUIDs that are not in the D5 registry. Categorizes as dangling orphans (preset was deleted, reference remains) or D4-legacy candidates (preset exists in the legacy builder_global_presets_ng option but not in D5). Use before diviops_preset_reassign to identify stale UUIDs for consolidation. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async () => {
    const result = await wp.requestEnveloped("/preset/scan-orphans");
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_preset_scan_orphans") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_preset_set_default",
  {
    description:
      "Set or clear the per-module/group default preset. Two addressing modes: (1) preset_id mode — walks both buckets to locate the preset by UUID, then points the containing module/group's `default` slot at it (or clears it with unset=true). (2) Bucket-addressed clear — pass type + module + unset=true to clear an orphan default pointer when the preset_id no longer exists in items[] (the preset_id walk path can't locate orphans — that's the very state being repaired; surfaced via diviops_preset_audit's `orphan_default_pointers`). Defaults apply to NEW module instances only — existing modules keep their current preset bindings (use diviops_preset_reassign for retroactive swaps). Use diviops_preset_audit's `is_default` and `orphan_default_pointers` fields to verify state before/after. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing preset_id (and not in bucket-addressed-clear mode) / bucket-addressed mode without unset=true return code 'invalid_input'; missing preset / unknown bucket returns 'not_found'." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      preset_id: z
        .string()
        .optional()
        .describe(
          "Preset UUID. Bucket (module vs. group) and target module/group are auto-resolved from the registry — no need to specify them. Required unless using bucket-addressed clear (type + module + unset=true) to repair an orphan default pointer.",
        ),
      type: z
        .enum(["module", "group"])
        .optional()
        .describe(
          "Bucket-addressed clear: bucket type. Required together with `module` and `unset=true` to clear an orphan default pointer (UUID gone from items[] but `default` still references it).",
        ),
      module: z
        .string()
        .optional()
        .describe(
          'Bucket-addressed clear: module slug or group key (e.g. "divi/blurb", "divi/font"). Required together with `type` and `unset=true`.',
        ),
      unset: z
        .boolean()
        .optional()
        .describe(
          "If true, clear the default pointer. With preset_id, clears the bucket containing that preset. With type+module, clears that bucket directly (use this form for orphan-pointer repair). Defaults to false (set the preset as the default — preset_id required).",
        ),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ preset_id, type, module, unset, dry_run }) => {
    const body: Record<string, any> = {};
    if (preset_id !== undefined) body.preset_id = preset_id;
    if (type !== undefined) body.type = type;
    if (module !== undefined) body.module = module;
    if (unset) body.unset = true;
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped("/preset/set-default", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_preset_set_default") },
      ],
    };
  },
);

// ── Library Tools ───────────────────────────────────────────────────

registerPluginTool(
  "diviops_library_list",
  {
    description:
      "List saved Divi Library items. Filter by layout_type (section, row, module) and scope (global, non_global). Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
    inputSchema: {
      layout_type: z
        .string()
        .optional()
        .describe(
          'Filter by type: "section", "row", "module", or empty for all',
        ),
      scope: z
        .string()
        .optional()
        .describe('Filter by scope: "global", "non_global", or empty for all'),
      per_page: z
        .number()
        .optional()
        .default(50)
        .describe("Max results (default 50)"),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ layout_type, scope, per_page }) => {
    const params: Record<string, string> = {};
    if (layout_type) params.layout_type = layout_type;
    if (scope) params.scope = scope;
    if (per_page) params.per_page = String(per_page);
    const result = await wp.requestEnveloped("/library/items", { params });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_library_list") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_library_get",
  {
    description:
      "Get a Divi Library item's content by ID. Returns the raw block markup that can be used with diviops_section_append or diviops_page_update_content. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing item_id returns ok:false with code 'not_found' and a hint pointing to diviops_library_list.",
    inputSchema: {
      item_id: z.number().describe("Library item ID"),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ item_id }) => {
    const result = await wp.requestEnveloped(`/library/item/${item_id}`);
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_library_get") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_library_save",
  {
    description:
      'Save Divi block markup to the Divi Library for reuse. Saved items appear in the VB\'s "Add From Library" panel. Title-uniqueness is enforced and scoped to (layout_type, scope) — a "Hero" section and a "Hero" row coexist (different design intent), but a second "Hero" section under the same scope returns ok:false with code \'conflict\' (HTTP 409) and `error.data = { existing_library_id, layout_type, scope }` so callers can retrieve the existing item and decide whether to reuse, rename, or delete-and-replace. Other rejections: missing title / non-string content / invalid layout_type or scope return \'invalid_input\'.' +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      title: z.string().describe("Display name for the library item"),
      content: z
        .string()
        .describe("Block markup to save (section, row, or module)"),
      layout_type: z
        .enum(["section", "row", "module"])
        .optional()
        .default("section")
        .describe('Type of layout: "section", "row", or "module"'),
      scope: z
        .enum(["global", "non_global"])
        .optional()
        .default("non_global")
        .describe(
          '"global" = synced across all uses, "non_global" = independent copies',
        ),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "conditional" },
  },
  async ({ title, content, layout_type, scope, dry_run }) => {
    const body: Record<string, unknown> = { title, content, layout_type, scope };
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped("/library/save", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_library_save") },
      ],
    };
  },
);

// ── Theme Builder Tools ─────────────────────────────────────────────

registerPluginTool(
  "diviops_tb_template_list",
  {
    description:
      "List all Theme Builder templates with their conditions, layout IDs, and enabled status. Shows which template applies to which pages/post types. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
    inputSchema: {
      per_page: z
        .number()
        .max(100)
        .optional()
        .default(50)
        .describe("Results per page (max 100)"),
      page: z.number().optional().default(1).describe("Page number"),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ per_page, page }) => {
    const params: Record<string, string> = {};
    if (per_page) params.per_page = String(per_page);
    if (page) params.page = String(page);
    const result = await wp.requestEnveloped("/theme-builder/template/list", { params });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_tb_template_list") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_tb_layout_get",
  {
    description:
      "Get a Theme Builder layout's block markup content (header, body, or footer). Use the layout IDs from diviops_tb_template_list. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing layout_id returns ok:false with code 'not_found' and a hint pointing to diviops_tb_template_list.",
    inputSchema: {
      layout_id: z
        .number()
        .describe(
          "Layout post ID (from template header_layout_id, body_layout_id, or footer_layout_id)",
        ),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ layout_id }) => {
    const result = await wp.requestEnveloped(`/theme-builder/layout/get/${layout_id}`);
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_tb_layout_get") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_cross_env_target_context_get",
  {
    description:
      "Export read-only, secret-free target-site context for the offline cross-environment Theme Builder header preflight. Produces JSON suitable for `diviops-cross-env-preflight --target`: target origin derived from WordPress, destination header layout metadata, a sha256 checksum of current target layout post_content without exposing raw content, target global colors, built-in customizer color IDs, sha256 value evidence for user/customizer global colors, defensible attachment candidates/remaps from optional source asset hints, and cache_scope. This Free/core tool does not write layouts, upload media, create global colors, or expose cookies, nonces, app passwords, signed URLs, query strings, fragments, or local filesystem paths. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; unsupported destination_kind returns invalid_input, and missing/non-header layouts return not_found.",
    inputSchema: {
      destination_id: z
        .number()
        .int()
        .positive()
        .describe("Existing target Theme Builder header layout post ID."),
      destination_kind: z
        .enum(["tb_header_layout"])
        .optional()
        .default("tb_header_layout")
        .describe("Destination kind. Only tb_header_layout is supported in this read-only slice."),
      source_asset_hints: z
        .array(z.string())
        .optional()
        .describe("Optional source upload paths, asset URLs, or basenames used to search target media candidates. Query strings, fragments, and credentials are stripped from output."),
      source_attachment_ids: z
        .array(z.number())
        .optional()
        .describe("Optional numeric source attachment IDs found in source markup. A remap is emitted only when exactly one source ID and one exact target candidate are present."),
      dry_run: z
        .boolean()
        .optional()
        .default(true)
        .describe("Accepted for workflow symmetry; the tool is always read-only and never mutates state."),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({
    destination_id,
    destination_kind,
    source_asset_hints,
    source_attachment_ids,
  }) => {
    const body: Record<string, unknown> = {
      destination_id,
      destination_kind: destination_kind ?? "tb_header_layout",
    };
    if (source_asset_hints && source_asset_hints.length > 0) {
      body.source_asset_hints = source_asset_hints;
    }
    if (source_attachment_ids && source_attachment_ids.length > 0) {
      body.source_attachment_ids = source_attachment_ids;
    }
    const result = await wp.requestEnveloped("/cross-env/target-context", {
      method: "POST",
      body,
    });
    return {
      content: [
        {
          type: "text" as const,
          text: serializeEnvelope(result, "diviops_cross_env_target_context_get"),
        },
      ],
    };
  },
);

registerPluginTool(
  "diviops_cross_env_source_export_get",
  {
    description:
      "Export read-only, secret-free source-site payload for the offline cross-environment Theme Builder header preflight. Produces JSON suitable for `diviops-cross-env-preflight --source`: source origin, `tb_header_layout` metadata, sanitized markup, bare sha256 checksum of that exported markup, export timestamp, DiviOps plugin version, and best-effort attachment inventory from markup media URLs and attachment IDs. This Free/core tool does not write layouts, upload media, create global colors, reconcile references, expose cookies, nonces, app passwords, signed URLs, query strings, fragments, admin URLs, or local filesystem paths. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; unsupported source_kind returns invalid_input, and missing/non-header source layouts return not_found.",
    inputSchema: {
      source_id: z
        .number()
        .int()
        .positive()
        .describe("Existing source Theme Builder header layout post ID."),
      source_kind: z
        .enum(["tb_header_layout"])
        .optional()
        .default("tb_header_layout")
        .describe("Source kind. Only tb_header_layout is supported in this read-only slice."),
      dry_run: z
        .boolean()
        .optional()
        .default(true)
        .describe("Accepted for workflow symmetry; the tool is always read-only and never mutates state."),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ source_id, source_kind }) => {
    const body: Record<string, unknown> = {
      source_id,
      source_kind: source_kind ?? "tb_header_layout",
    };
    const result = await wp.requestEnveloped("/cross-env/source-export", {
      method: "POST",
      body,
    });
    return {
      content: [
        {
          type: "text" as const,
          text: serializeEnvelope(result, "diviops_cross_env_source_export_get"),
        },
      ],
    };
  },
);

registerPluginTool(
  "diviops_tb_layout_update",
  {
    description:
      "Update a Theme Builder layout's block markup (header, body, or footer). Replaces the full content. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing layout_id returns ok:false with code 'not_found'." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      layout_id: z.number().describe("Layout post ID to update"),
      content: z.string().describe("New block markup content"),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "conditional" },
  },
  async ({ layout_id, content, dry_run }) => {
    const body: Record<string, unknown> = { content };
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped(`/theme-builder/layout/update/${layout_id}`, {
      method: "PUT",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_tb_layout_update") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_tb_layout_block_insert",
  {
    description:
      "Insert one or more serialized Divi blocks into an existing Theme Builder layout without replacing the whole layout. Target a unique parent with `parent_selector` (for example `divi/group[adminLabel=\"Legal Col\"]`, or `divi/group` only when it is unique) or an explicit zero-based `parent_path` from the parsed block tree such as `0.1.2`. `position=append|prepend` inserts as children of the target block; `position=before|after` inserts beside the target within its parent. Ambiguous selectors return ok:false with code 'invalid_input'; missing targets return 'not_found'. The route parses and validates the inserted blocks, rejects malformed pseudo-escapes such as bare `u003c`, validates the final serialized layout before saving, and returns a no-op when the exact requested block sequence already exists at the insertion point." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      layout_id: z.number().int().describe("Theme Builder layout post ID to mutate"),
      parent_selector: z
        .string()
        .optional()
        .describe('Unique selector such as `divi/group[adminLabel="Legal Col"]` or `divi/column`. Provide exactly one of parent_selector or parent_path.'),
      parent_path: z
        .string()
        .optional()
        .describe('Zero-based parsed-tree path such as `0`, `0.1`, or `0.1.2`. Provide exactly one of parent_selector or parent_path.'),
      position: z
        .enum(["append", "prepend", "before", "after"])
        .optional()
        .default("append")
        .describe("Where to insert relative to the target block."),
      content: z.string().describe("One or more serialized Divi blocks to insert"),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "conditional" },
  },
  async ({ layout_id, parent_selector, parent_path, position, content, dry_run }) => {
    const body: Record<string, unknown> = {
      content,
      position: position ?? "append",
    };
    if (parent_selector !== undefined) body.parent_selector = parent_selector;
    if (parent_path !== undefined) body.parent_path = parent_path;
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped(
      `/theme-builder/layout/block-insert/${layout_id}`,
      {
        method: "POST",
        body,
      },
    );
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_tb_layout_block_insert") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_tb_template_create",
  {
    description:
      "Create a Theme Builder template with custom header and/or footer. Automatically creates layout posts, sets conditions, and links to Theme Builder. Pass condition=\"default\" (case-insensitive) or an empty string to register the template as the catch-all Default Website Template — the route writes the `_et_default = '1'` flag with an empty `_et_use_on`, matching the meta shape Divi's TB router gates the default route on; any other condition string lands in `_et_use_on` unchanged. Default Website Template is a singleton scoped to the active Theme Builder master: if the active master's `_et_template` linked list already names an et_template carrying `_et_default = '1'` (regardless of `_et_enabled` status — the router resolves by linked-list position before checking the enable-gate, so a disabled existing default linked ahead of the new one would still shadow it), the route rejects with code `tb_template.default_already_exists` (HTTP 409) and `error.data.existing_default_id` + `error.data.master_post_id`. Templates outside the active master's linked list (orphan defaults, library-cloned-master defaults) cannot shadow the router's pick and DO NOT block creation. Caller resolves a real conflict by trashing the existing default (diviops_tb_template_trash) or pinning this template to a specific condition; the route never silently flips the existing default's flag or proceeds with non-deterministic router state. If the Theme Builder master post is missing (fresh substrate that never opened Divi → Theme Builder in WP Admin), the route auto-bootstraps one with the same shape Divi creates on first admin visit and returns `data.master_post_bootstrapped: true` so callers can audit the side-effect. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; failures during master-post bootstrap or template/layout insert surface the underlying WP_Error code (commonly `db_insert_error`, `db_update_error`, or other slugs from the WordPress vocabulary), not a generic `wp_error` — branch on `error.code` against the WP slug, not against a hard-coded string. The literal `wp_error` slug only surfaces when the upstream WP_Error has an empty code." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      title: z.string().describe('Template name (e.g. "Landing Pages")'),
      condition: z
        .string()
        .describe(
          'Condition string. Pass "default" (case-insensitive) or "" for the catch-all Default Website Template (sets `_et_default = 1`). Otherwise a Divi router-recognized location string such as "singular:post_type:page:all", "singular:post_type:project:all", "archive:taxonomy:category:all", "homepage", or "404" (lands in `_et_use_on`).',
        ),
      header_content: z
        .string()
        .optional()
        .default("")
        .describe(
          "Header block markup (empty = inherit from default template)",
        ),
      footer_content: z
        .string()
        .optional()
        .default("")
        .describe(
          "Footer block markup (empty = inherit from default template)",
        ),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "false" },
  },
  async ({ title, condition, header_content, footer_content, dry_run }) => {
    const body: Record<string, unknown> = { title, condition, header_content, footer_content };
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped("/theme-builder/template/create", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_tb_template_create") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_tb_template_trash",
  {
    description:
      "Trash (or permanently delete) a Theme Builder template AND its linked header/body/footer layouts AND scrub the `_et_template` meta refs on the Theme Builder master post. Closes the orphan-meta gap left by `diviops_page_trash` / wp-cli `post delete` on linked layouts: the typed wrapper does the cleanup atomically. Defaults to trash (reversible via WP Admin → Trash). Pass `force=true` to permanently delete (wp_delete_post — irreversible, one-shot: a repeat call after a successful force-delete returns 'not_found' because the template post is gone from the DB). Idempotency applies to the default trash mode only: a repeat call after a successful trash-mode cleanup returns ok:true with `data.already_trashed = true` (mirrors `diviops_page_trash`). If a prior trash-mode call partially succeeded (some layouts already trashed, master meta still carries refs), the next call detects already-trashed targets via pre-state checks, skips the no-op WP destructor calls (which would otherwise return false), and still runs the meta scrub — `data.linked_layouts[].skipped` and `data.template_skipped` flag the targets that were already at the end-state. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing template_id returns 'not_found' (HTTP 404), delete-permission failures return 'forbidden' (HTTP 403); per-step trash/delete/meta-scrub failures return the namespaced 'tb_template.command_failed' (HTTP 500) with `error.data.failed_step` ∈ { 'layout_destroy', 'template_destroy', 'meta_scrub' } plus `template_id` and `force`." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      template_id: z
        .number()
        .int()
        .describe(
          "Theme Builder template post ID (the `et_template` post). Discover via diviops_tb_template_list — NOT the linked layout IDs.",
        ),
      force: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "When true, permanently delete (skips trash). Default false moves to trash.",
        ),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "conditional" },
  },
  async ({ template_id, force, dry_run }) => {
    const result = await wp.requestEnveloped(
      `/theme-builder/template/trash/${template_id}`,
      {
        method: "POST",
        body: {
          force: force ?? false,
          dry_run: dry_run ?? false,
        },
      },
    );
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_tb_template_trash") },
      ],
    };
  },
);

// ── Canvas Tools ────────────────────────────────────────────────────

registerPluginTool(
  "diviops_canvas_create",
  {
    description:
      "Create a canvas (off-canvas workspace) linked to a page. Used for popups, off-canvas menus, modals. Content uses standard Divi block markup. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing parent_page_id returns ok:false with code 'not_found'; non-string content / malformed canvas_id / append_to_main outside {above, below} returns 'invalid_input'. Returns code 'conflict' (HTTP 409) when a canvas with the same title already exists under the same parent_page_id — error.data = { existing_canvas_id, parent_page_id, title }. Mirrors diviops_preset_create's uniqueness contract." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      title: z
        .string()
        .describe('Canvas name (e.g. "Popup Menu", "Modal Contact Form")'),
      parent_page_id: z.number().describe("Parent page post ID"),
      content: z
        .string()
        .optional()
        .default("")
        .describe("Divi block markup for canvas content"),
      canvas_id: z
        .string()
        .optional()
        .describe("Canvas UUID (auto-generated if omitted)"),
      append_to_main: z
        .enum(["above", "below"])
        .optional()
        .describe("Auto-append position relative to main content"),
      z_index: z
        .number()
        .optional()
        .describe("Layering order (higher = on top)"),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "conditional" },
  },
  async ({
    title,
    parent_page_id,
    content,
    canvas_id,
    append_to_main,
    z_index,
    dry_run,
  }) => {
    const body: Record<string, unknown> = {
      title,
      parent_page_id,
      content: content ?? "",
    };
    if (canvas_id) body.canvas_id = canvas_id;
    if (append_to_main) body.append_to_main = append_to_main;
    if (z_index !== undefined) body.z_index = z_index;
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped("/canvas/create", { method: "POST", body });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_canvas_create") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_canvas_list",
  {
    description:
      "List canvases (off-canvas workspaces). Filter by parent page or list all. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
    inputSchema: {
      parent_page_id: z
        .number()
        .optional()
        .describe("Filter by parent page ID (omit for all canvases)"),
      per_page: z
        .number()
        .int()
        .min(1)
        .max(100)
        .optional()
        .default(50)
        .describe("Max results (default 50, 1-100)"),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ parent_page_id, per_page }) => {
    const params: Record<string, string> = {};
    if (parent_page_id) params.parent_page_id = String(parent_page_id);
    if (per_page) params.per_page = String(per_page);
    const result = await wp.requestEnveloped("/canvas/list", { params });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_canvas_list") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_canvas_get",
  {
    description:
      "Get a canvas's block content and metadata. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing canvas_post_id returns ok:false with code 'not_found' and a hint pointing to diviops_canvas_list.",
    inputSchema: {
      canvas_post_id: z
        .number()
        .describe("Canvas post ID (from diviops_canvas_list)"),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ canvas_post_id }) => {
    const result = await wp.requestEnveloped(`/canvas/get/${canvas_post_id}`);
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_canvas_get") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_canvas_update",
  {
    description:
      "Update a canvas's content and/or metadata. Pass any subset of fields — e.g. `{canvas_post_id, title}` to rename without touching content. `content` replaces the entire canvas when present. At least one of content/title/append_to_main/z_index is required. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing canvas_post_id returns ok:false with code 'not_found'; empty / no-op payload (no content/title/append_to_main/z_index) returns 'invalid_input' with a hint pointing at the rename-only path." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      canvas_post_id: z.number().describe("Canvas post ID"),
      content: z
        .string()
        .optional()
        .describe("New block markup (replaces entire content)"),
      title: z.string().optional().describe("New canvas title"),
      append_to_main: z
        .enum(["above", "below", ""])
        .optional()
        .describe('Append position: "above", "below", or "" to clear'),
      z_index: z.number().optional().describe("Layering order"),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "conditional" },
  },
  async ({ canvas_post_id, content, title, append_to_main, z_index, dry_run }) => {
    const body: Record<string, unknown> = {};
    if (content !== undefined) body.content = content;
    if (title !== undefined) body.title = title;
    if (append_to_main !== undefined) body.append_to_main = append_to_main;
    if (z_index !== undefined) body.z_index = z_index;
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped(`/canvas/update/${canvas_post_id}`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_canvas_update") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_canvas_duplicate",
  {
    description:
      "Deep-copy a canvas (post_content + canvas-specific meta: parent page, append_to_main, z_index). Source canvas untouched. Default copy title is `<source title> (Copy)` with auto-suffix on collision (Copy 2, Copy 3, …) — use this for repeat-clone workflows. Pass an explicit `title` for a deliberate name; collisions return ok:false with code 'conflict' (HTTP 409) and `error.data = { existing_canvas_id, parent_page_id }` so callers can retrieve / rename the conflicting canvas. Pass `dry_run: true` to preview without mutating. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing canvas_post_id returns 'not_found'.",
    inputSchema: {
      canvas_post_id: z.number().describe("Source canvas post ID"),
      title: z
        .string()
        .optional()
        .describe(
          "Optional explicit title for the duplicate. Omit to auto-derive `<source> (Copy [N])`. Explicit collisions return 409.",
        ),
      dry_run: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "When true, return the change plan without creating the canvas.",
        ),
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "conditional" },
  },
  async ({ canvas_post_id, title, dry_run }) => {
    const body: Record<string, unknown> = { dry_run: dry_run ?? false };
    if (title !== undefined) body.title = title;
    const result = await wp.requestEnveloped(`/canvas/duplicate/${canvas_post_id}`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_canvas_duplicate") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_canvas_delete",
  {
    description:
      "Delete a canvas. This permanently removes the canvas post. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; missing canvas_post_id returns ok:false with code 'not_found'." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      canvas_post_id: z.number().describe("Canvas post ID to delete"),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ canvas_post_id, dry_run }) => {
    const body: Record<string, unknown> = {};
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped(`/canvas/delete/${canvas_post_id}`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_canvas_delete") },
      ],
    };
  },
);

// ── WP-CLI ──────────────────────────────────────────────────────────

registerLocalTool(
  "diviops_meta_wp_cli",
  {
    description:
      "Run a WP-CLI command on the WordPress site. Requires WP_PATH env var (LOCAL_SITE_ID auto-detected from Local by Flywheel), or WP_CLI_CMD for containerized wrappers. Commands validated against a safety allowlist. Default tier covers read ops across options/posts/post-types/taxonomies/users/info/core/db, non-destructive writes (post/term create+update, post meta read/write, cache/rewrite/transient flush, `plugin update` from authenticated sources), ACF/SCF schema ops (`acf export/import/field-group list/get` plus SCF 6.8.4+ `scf json {status,sync,import,export}` and the `acf json …` aliases), and WXR export. Extended tier (requires DIVIOPS_WP_CLI_ALLOW env var) adds destructive or bulk-modifying ops: option update, post/post meta/term delete, search-replace, import, plugin activate/deactivate, eval-file. Filesystem-touching commands (`wp export`, `acf export/import`, `scf|acf json export/import`) are additionally constrained: path arguments must resolve under a safe root (defaults to `<WP_PATH>/.diviops-tmp/`, overridable via DIVIOPS_WP_CLI_SAFE_FS_ROOT, disable via DIVIOPS_WP_CLI_UNSAFE_FS=1); `wp export` and `scf json export` require an explicit `--dir=<path>` (or `--stdout`). In WP_CLI_CMD wrapper mode, DIVIOPS_WP_CLI_SAFE_FS_ROOT is required for FS-sensitive commands. Prefer the typed `diviops_scf_*` wrappers for SCF round-trips — they're easier to invoke and accept the same safe-root scoping. Use --format=json for structured output. Full allowlist + tier rationale + filesystem semantics in the MCP server README. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }. Success payload: { stdout: string, stderr: string, exit_code: 0 }. Four failure modes converge on 'meta_wp_cli.command_failed' with error.data = { exit_code: number | null, stdout: string, stderr: string }: (a) numeric exit_code — wp-cli ran and exited non-zero; stdout/stderr are raw streams verbatim. (b) exit_code=null and message starts with 'wp-cli command terminated:' — execFile launched the child but it was killed (timeout or signal); stdout/stderr carry whatever streamed before the kill. (c) exit_code=null and message starts with 'wp-cli could not spawn:' — the OS refused to start the child (ENOENT/EACCES/EPERM); child never ran, stdout/stderr are empty. (d) exit_code=null and message is the rejection reason — pre-execution rejection by the allowlist / FS validator; rejection reason synthesized into error.data.stderr because the child never ran. A missing wp-cli configuration surfaces as 'meta_wp_cli.not_configured'. stdout is always passed through as a string (no server-side JSON parse) — pass --format=json and parse on the caller side when you want structured output.",
    inputSchema: {
      command: z
        .string()
        .describe(
          'WP-CLI command without the "wp" prefix. E.g. "option get blogname", "post list --format=json", "export --dir=$DIVIOPS_WP_CLI_SAFE_FS_ROOT --filename_format={site}.{date}.xml"',
        ),
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "conditional" },
  },
  async ({ command }) => {
    const response = await wrapResponse(async () => {
      if (!wpCli) {
        withCode(
          "meta_wp_cli.not_configured",
          "WP-CLI not configured.",
          'Set the WP_PATH environment variable to your WordPress installation path. Example: claude mcp add diviops-mcp --env WP_URL=http://site.local --env WP_USER=admin --env WP_APP_PASSWORD=xxxx --env "WP_PATH=/Users/you/Local Sites/your-site/app/public" -- npx -y --package @diviops/mcp-server diviops-mcp. Local site ID is auto-detected from WP_PATH; set LOCAL_SITE_ID explicitly if needed.',
        );
      }
      const result = await wpCli.run(command);
      if (!result.success) {
        // Four failure shapes converge on `meta_wp_cli.command_failed`,
        // discriminated by `result.failureKind` from the runner:
        //   - 'exited':       wp-cli ran and returned a numeric exit code.
        //                     stdout/stderr are raw streams verbatim
        //                     (empty string when wp-cli emitted nothing).
        //                     The exit-code summary lives on `error.message`
        //                     so callers branch on `error.data.exit_code`
        //                     rather than parsing the stream.
        //   - 'killed':       execFile spawned the child but it was killed
        //                     (timeout or signal). exit_code is null
        //                     because a numeric code is unavailable, but
        //                     stdout/stderr carry whatever streamed before
        //                     the kill — surface them verbatim. The kill
        //                     reason lives on `error.message` and `hint`
        //                     so callers can distinguish "timed out" from
        //                     "got partial output then bailed."
        //   - 'spawn_failed': execFile invoked but the OS refused to start
        //                     the child (ENOENT, EACCES, EPERM, etc.). The
        //                     child never ran; stdout/stderr are empty.
        //                     Distinct from 'killed' — fix path is
        //                     environmental (PATH, install, perms), not
        //                     "raise the timeout." The system errno lives
        //                     in `result.error` so callers can identify the
        //                     specific OS reason without parsing.
        //   - 'rejected':     pre-execution rejection (allowlist / FS
        //                     validator). Child never ran, `result.stderr`
        //                     always empty — synthesize from `result.error`
        //                     so callers see a uniform
        //                     `{ exit_code, stdout, stderr }` shape.
        //
        // Codex review history:
        //   pass 1 — collapsed 'killed' onto 'rejected' (both share
        //            exit_code: null), causing timeouts to mis-emit
        //            pre-execution rejection hints. Fixed in a33ed7c.
        //   pass 2 — collapsed 'spawn_failed' (ENOENT etc.) onto 'killed',
        //            telling callers the child was launched and killed
        //            even though it never spawned. This branch.
        const detail = result.error ?? "wp-cli command failed";
        const kind = result.failureKind ?? "exited";
        let message: string;
        let hint: string;
        let stderrForData: string;
        if (kind === "rejected") {
          message = detail;
          hint =
            "Command was rejected before execution. Common causes: not in the allowlist (see DIVIOPS_WP_CLI_ALLOW for opt-ins) or filesystem path outside DIVIOPS_WP_CLI_SAFE_FS_ROOT.";
          stderrForData = detail;
        } else if (kind === "spawn_failed") {
          message = `wp-cli could not spawn: ${detail}`;
          hint =
            "The OS refused to start the wp-cli executable — common causes: WP_CLI_CMD points at a missing binary (ENOENT), the binary is not executable (EACCES), or PATH does not include wp-cli. Verify `which wp` (or your WP_CLI_CMD prefix) resolves and is executable. error.data.stdout / error.data.stderr are empty because the child never ran.";
          stderrForData = detail;
        } else if (kind === "killed") {
          message = `wp-cli command terminated: ${detail}`;
          hint =
            "Command was launched but killed before it finished (timeout or signal). error.data.stdout / error.data.stderr carry whatever streamed before the kill. Consider raising the timeout or splitting the command into smaller batches.";
          stderrForData = result.stderr;
        } else {
          message = `wp-cli exited with code ${result.exitCode}`;
          hint =
            "Inspect error.data.stderr for the failure reason; re-run with WP_CLI_DEBUG=1 in the env to surface PHP traceback.";
          stderrForData = result.stderr;
        }
        withCode("meta_wp_cli.command_failed", message, hint, {
          exit_code: result.exitCode,
          stdout: result.stdout,
          stderr: stderrForData,
        });
      }
      return {
        stdout: result.stdout,
        stderr: result.stderr,
        exit_code: 0,
      };
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(response, "diviops_meta_wp_cli") },
      ],
    };
  },
);

// ── SCF (Secure Custom Fields / ACF) wrappers ───────────────────────
//
// Typed wrappers over SCF 6.8.4+'s `wp scf json {status,sync,import,export}`
// CLI family (also reachable as `wp acf json …`). The plugin file at
// wp-content/plugins/secure-custom-fields/src/CLI/JsonCommand.php is the
// upstream source of truth for flag shapes — keep these wrappers aligned.
//
// Envelope adoption: every tool wraps its handler in `wrapResponse` +
// `serializeEnvelope`. wp-cli failures route through `failScfCommand`
// which mirrors `meta_wp_cli.command_failed`'s four-failureKind shape but
// emits a namespace-prefixed `scf.command_failed` code so callers can
// branch on `error.code` without reading `error.data` to know whether the
// failed call was `wp scf json …` or `wp post …`.

/**
 * Short-circuit when wp-cli isn't configured. Throws via `withCode` so the
 * surrounding `wrapResponse` emits the standard envelope. Adopted from the
 * `meta_wp_cli` precedent (`meta_wp_cli.not_configured`); reuses the
 * namespace-prefixed pattern as `scf.not_configured` so callers can
 * branch on `error.code` without inspecting message strings.
 */
function ensureScfWpCli(): NonNullable<typeof wpCli> {
  if (!wpCli) {
    withCode(
      "scf.not_configured",
      "WP-CLI not configured.",
      "Set WP_PATH (Local by Flywheel auto-detect) or WP_CLI_CMD (containerized wrappers) to enable SCF round-trip tools.",
    );
  }
  return wpCli;
}

function pushScfFlag(args: string[], name: string, value: string | undefined): void {
  if (!value) return;
  // Each `--name=value` becomes a single argv entry — execFile handles spaces
  // and quotes inside the value transparently. No string concatenation, no
  // parseCommand round-trip, so values like "Bob's Group" or filenames with
  // spaces flow through verbatim.
  args.push(`--${name}=${value}`);
}

/**
 * Mirror of `meta_wp_cli.command_failed`'s four-failureKind branch logic,
 * scoped to the scf_* namespace. Inputs:
 *   - `result`: the raw `wpCli.runArgs(...)` payload (success === false here)
 *   - `args`: the wp-cli argv (sanitized of secrets at the wrapper level —
 *     SCF args carry no credentials) so callers can see exactly what was
 *     attempted
 *
 * Throws via `withCode` so the surrounding `wrapResponse` emits the
 * standard envelope with code `scf.command_failed`. `error.data` mirrors
 * meta_wp_cli's shape verbatim (`{ exit_code, stdout, stderr, failure_kind,
 * command }`) — see tools.md "Response shape" for the four failure_kind
 * branches and the matching hints.
 */
function failScfCommand(
  result: {
    error?: string;
    stdout: string;
    stderr: string;
    exitCode: number | null;
    failureKind?: "exited" | "killed" | "spawn_failed" | "rejected";
  },
  args: readonly string[],
): never {
  const detail = result.error ?? "wp-cli command failed";
  const kind = result.failureKind ?? "exited";
  let message: string;
  let hint: string;
  let stderrForData: string;
  if (kind === "rejected") {
    message = detail;
    hint =
      "Command was rejected before execution. Common causes: not in the allowlist (see DIVIOPS_WP_CLI_ALLOW for opt-ins) or filesystem path outside DIVIOPS_WP_CLI_SAFE_FS_ROOT.";
    stderrForData = detail;
  } else if (kind === "spawn_failed") {
    message = `wp-cli could not spawn: ${detail}`;
    hint =
      "The OS refused to start the wp-cli executable — common causes: WP_CLI_CMD points at a missing binary (ENOENT), the binary is not executable (EACCES), or PATH does not include wp-cli. Verify `which wp` (or your WP_CLI_CMD prefix) resolves and is executable. error.data.stdout / error.data.stderr are empty because the child never ran.";
    stderrForData = detail;
  } else if (kind === "killed") {
    message = `wp-cli command terminated: ${detail}`;
    hint =
      "Command was launched but killed before it finished (timeout or signal). error.data.stdout / error.data.stderr carry whatever streamed before the kill. Consider raising the timeout or splitting the command into smaller batches.";
    stderrForData = result.stderr;
  } else {
    message = `wp-cli exited with code ${result.exitCode}`;
    hint =
      "Inspect error.data.stderr for the failure reason; re-run with WP_CLI_DEBUG=1 in the env to surface PHP traceback.";
    stderrForData = result.stderr;
  }
  withCode("scf.command_failed", message, hint, {
    exit_code: result.exitCode,
    stdout: result.stdout,
    stderr: stderrForData,
    failure_kind: kind,
    command: [...args],
  });
}

registerLocalTool(
  "diviops_scf_status",
  {
    description:
      "Show SCF (Secure Custom Fields) sync status — how many field groups, post types, taxonomies, and options pages have JSON-on-disk newer than the database (or absent from DB). Read-only. Wraps `wp scf json status`. Requires SCF 6.8.4+ and WP_PATH or WP_CLI_CMD. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload is { stdout: string, stderr: string }. wp-cli failures map to 'scf.command_failed' with `error.data = { exit_code, stdout, stderr, failure_kind, command }` (four failure_kind branches: 'exited'/'killed'/'spawn_failed'/'rejected' — see tools.md). Missing wp-cli configuration surfaces as 'scf.not_configured'.",
    inputSchema: {
      type: z
        .enum(["field-group", "post-type", "taxonomy", "options-page"])
        .optional()
        .describe(
          "Limit to a single item type. Defaults to all types. options-page requires ACF PRO.",
        ),
      detailed: z
        .boolean()
        .optional()
        .describe(
          "List the individual pending items (key/title/type/action) instead of just counts.",
        ),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ type, detailed }) => {
    const response = await wrapResponse(async () => {
      const cli = ensureScfWpCli();
      const args = ["scf", "json", "status", "--format=json"];
      pushScfFlag(args, "type", type);
      if (detailed) args.push("--detailed");
      const result = await cli.runArgs(args);
      if (!result.success) failScfCommand(result, args);
      return { stdout: result.stdout, stderr: result.stderr };
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(response, "diviops_scf_status") },
      ],
    };
  },
);

registerLocalTool(
  "diviops_scf_export",
  {
    description:
      "Export SCF field groups, post types, taxonomies, and options pages as JSON — to a directory under the safe-root (`<WP_PATH>/.diviops-tmp/` by default, override via DIVIOPS_WP_CLI_SAFE_FS_ROOT) or to stdout. Wraps `wp scf json export`. Either `dir` or `stdout: true` is required. Filters can be combined; without filters, all items are exported. Note: SCF writes a fixed filename `acf-export-YYYY-MM-DD.json` inside `dir` — two exports on the same day silently overwrite. Copy/rename if you're archiving baselines. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload is { stdout: string, stderr: string }. Pre-wp-cli input rejections (neither/both of `dir`/`stdout`) return code 'invalid_input' with `error.data` documenting the failed fields. wp-cli failures map to 'scf.command_failed' (same shape as scf_status). Missing wp-cli configuration surfaces as 'scf.not_configured'.",
    inputSchema: {
      dir: z
        .string()
        .optional()
        .describe(
          "Absolute output directory under the WP-CLI safe-root. Mutually exclusive with `stdout`. SCF writes a single `acf-export-YYYY-MM-DD.json` file inside this dir.",
        ),
      stdout: z
        .boolean()
        .optional()
        .describe(
          "Print JSON to stdout instead of writing a file. Mutually exclusive with `dir`.",
        ),
      field_groups: z
        .string()
        .optional()
        .describe(
          "Comma-separated field-group ACF keys (`group_abc123`) or admin titles (`My Field Group`). NOT WP post slugs — SCF matches against the def's `key` field or its `title` (case-insensitive). Use `diviops_scf_field_group_list` to discover keys (post_name column).",
        ),
      post_types: z
        .string()
        .optional()
        .describe(
          "Comma-separated SCF post-type def keys (`post_type_xxx`) or admin titles (`Programm`). IMPORTANT: this is the SCF def's identifier, NOT the registered post-type slug (`event`, `book`). The registered slug is what `wp post list` and REST URLs use, but SCF's filter matches against the def's `key` field or its `title`. To discover def keys, run `diviops_scf_export --stdout` (no filter) and inspect the top-level entries with `parent='post-type'`.",
        ),
      taxonomies: z
        .string()
        .optional()
        .describe(
          "Comma-separated SCF taxonomy def keys (`taxonomy_xxx`) or admin titles. Same caveat as `post_types`: NOT the registered taxonomy slug — the SCF def's `key` or `title`. Discover via `diviops_scf_export --stdout`.",
        ),
      options_pages: z
        .string()
        .optional()
        .describe(
          "Comma-separated options-page def keys or admin titles. Requires ACF PRO.",
        ),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ dir, stdout, field_groups, post_types, taxonomies, options_pages }) => {
    const response = await wrapResponse(async () => {
      const cli = ensureScfWpCli();
      if (!dir && !stdout) {
        withCode(
          ErrorCodes.INVALID_INPUT,
          "Pass either `dir` or `stdout`, not neither.",
          "Set `stdout: true` to print JSON, or `dir: '<absolute path under DIVIOPS_WP_CLI_SAFE_FS_ROOT>'` to write a file.",
          { missing: ["dir", "stdout"] },
        );
      }
      if (dir && stdout) {
        withCode(
          ErrorCodes.INVALID_INPUT,
          "`dir` and `stdout` are mutually exclusive — pick one.",
          "Pass `dir` to write a file, OR `stdout: true` to print JSON. Not both.",
          { conflict: ["dir", "stdout"] },
        );
      }
      const args = ["scf", "json", "export"];
      if (stdout) args.push("--stdout");
      pushScfFlag(args, "dir", dir);
      pushScfFlag(args, "field-groups", field_groups);
      pushScfFlag(args, "post-types", post_types);
      pushScfFlag(args, "taxonomies", taxonomies);
      pushScfFlag(args, "options-pages", options_pages);
      const result = await cli.runArgs(args);
      if (!result.success) failScfCommand(result, args);
      return { stdout: result.stdout, stderr: result.stderr };
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(response, "diviops_scf_export") },
      ],
    };
  },
);

registerLocalTool(
  "diviops_scf_import",
  {
    description:
      "Import SCF field groups, post types, taxonomies, options pages from a JSON file. Mutates the database. File path must resolve under the safe-root (`<WP_PATH>/.diviops-tmp/` by default, override via DIVIOPS_WP_CLI_SAFE_FS_ROOT). Idempotent — existing items with matching keys are updated. Wraps `wp scf json import <file>`. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload is { stdout: string, stderr: string }. wp-cli failures (missing/unreadable file, malformed JSON, allowlist or FS-validator rejection) map to 'scf.command_failed' with `error.data = { exit_code, stdout, stderr, failure_kind, command }`. Missing wp-cli configuration surfaces as 'scf.not_configured'.",
    inputSchema: {
      file: z
        .string()
        .describe(
          "Absolute path to the .json file to import. Must resolve under DIVIOPS_WP_CLI_SAFE_FS_ROOT.",
        ),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ file }) => {
    const response = await wrapResponse(async () => {
      const cli = ensureScfWpCli();
      const args = ["scf", "json", "import", file];
      const result = await cli.runArgs(args);
      if (!result.success) failScfCommand(result, args);
      return { stdout: result.stdout, stderr: result.stderr };
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(response, "diviops_scf_import") },
      ],
    };
  },
);

registerLocalTool(
  "diviops_scf_sync",
  {
    description:
      "Apply pending JSON-on-disk SCF changes to the database. Reads JSON files from the theme/plugin acf-json directory and creates/updates DB entries. Defaults to `dry_run: true` for safety — caller must opt in to mutation. Wraps `wp scf json sync`. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload is { dry_run: boolean, stdout: string, stderr: string }. NOTE: `dry_run` is passed through as wp-cli's `--dry-run` flag — the upstream output shape is wp-cli's plain-text summary, NOT the standard `data.plan = { summary, changes[] }` shape used by plugin-routed `dry_run` tools. The `dry_run` boolean is reflected in the success payload so callers can branch without re-checking input args, but the SCF-on-disk preview is what wp-cli produced. wp-cli failures map to 'scf.command_failed'; missing wp-cli configuration surfaces as 'scf.not_configured'.",
    inputSchema: {
      type: z
        .enum(["field-group", "post-type", "taxonomy", "options-page"])
        .optional()
        .describe("Limit sync to a single item type."),
      key: z
        .string()
        .optional()
        .describe("Sync only the item with this ACF key (e.g. `group_abc123`)."),
      dry_run: z
        .boolean()
        .optional()
        .default(true)
        .describe(
          "Preview pending changes without mutating the database. Defaults to true. Pass `false` to commit.",
        ),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ type, key, dry_run }) => {
    const response = await wrapResponse(async () => {
      const cli = ensureScfWpCli();
      const args = ["scf", "json", "sync"];
      pushScfFlag(args, "type", type);
      pushScfFlag(args, "key", key);
      const isDryRun = dry_run !== false;
      if (isDryRun) args.push("--dry-run");
      const result = await cli.runArgs(args);
      if (!result.success) failScfCommand(result, args);
      return {
        dry_run: isDryRun,
        stdout: result.stdout,
        stderr: result.stderr,
      };
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(response, "diviops_scf_sync") },
      ],
    };
  },
);

registerLocalTool(
  "diviops_scf_field_group_list",
  {
    description:
      "List all SCF/ACF field groups in the database (post_name = ACF key, post_title, post_status, post_modified). Read-only. Queries the underlying `acf-field-group` post type via `wp post list` — works on both SCF 6.8.4+ (which dropped the legacy `wp acf field-group …` family in favor of the `wp scf json` namespace) and older ACF installs. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload is `Array<{ ID, post_name, post_title, post_status, post_modified }>` parsed from wp-cli's JSON output (or an empty array on no results). wp-cli failures map to 'scf.command_failed'; missing wp-cli configuration surfaces as 'scf.not_configured'.",
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async () => {
    const response = await wrapResponse(async () => {
      const cli = ensureScfWpCli();
      const args = [
        "post",
        "list",
        "--post_type=acf-field-group",
        "--post_status=any",
        "--fields=ID,post_name,post_title,post_status,post_modified",
        "--format=json",
      ];
      const result = await cli.runArgs(args);
      if (!result.success) failScfCommand(result, args);
      // wp-cli emits `[]` for no rows; parse so callers get structured data.
      // Malformed JSON (shouldn't happen with --format=json on a successful
      // run, but wp-cli has surprised us before) maps to wp_error so the
      // failure is at least visible rather than silently empty.
      try {
        return JSON.parse(result.stdout || "[]");
      } catch (e) {
        withCode(
          ErrorCodes.WP_ERROR,
          `wp-cli returned non-JSON output for --format=json: ${(e as Error).message}`,
          "Inspect wp-cli's stdout for malformed output. This usually indicates a wp-cli bootstrap warning bleeding into the JSON stream — re-run with WP_CLI_DEBUG=1 in the env.",
        );
      }
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(response, "diviops_scf_field_group_list") },
      ],
    };
  },
);

registerLocalTool(
  "diviops_scf_field_group_get",
  {
    description:
      "Fetch a single SCF/ACF field group from the `acf-field-group` post type — by ACF key (`group_abc123`, looked up via `post_name`) or by numeric WP post ID. Returns the WP post fields (post_name, post_title, post_content with serialized fields blob, post_status, post_modified). For the parsed/structured field tree including nested fields, use `diviops_scf_export --field-groups=<key> --stdout` instead. Read-only. SCF 6.8.4 dropped the legacy `wp acf field-group get` command, so this wrapper queries the post type directly via `wp post`. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload is the parsed `wp post get --format=json` object. Unresolvable key (no row in the `acf-field-group` post type and not a numeric ID that wp-cli accepts) returns code 'not_found' with hint pointing to diviops_scf_field_group_list. wp-cli failures map to 'scf.command_failed'; missing wp-cli configuration surfaces as 'scf.not_configured'.",
    inputSchema: {
      key: z
        .string()
        .describe(
          "ACF field-group key (`group_abc123`, matched against post_name) or numeric WP post ID.",
        ),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ key }) => {
    const response = await wrapResponse(async () => {
      const cli = ensureScfWpCli();
      // If the input looks like a numeric ID, hand it to `wp post get` directly.
      // Otherwise treat it as an ACF key and resolve via post_name first.
      const isNumericId = /^\d+$/.test(key);
      let postId: string;
      if (isNumericId) {
        postId = key;
      } else {
        const lookupArgs = [
          "post",
          "list",
          "--post_type=acf-field-group",
          "--post_status=any",
          `--name=${key}`,
          "--fields=ID",
          "--format=json",
        ];
        const lookup = await cli.runArgs(lookupArgs);
        if (!lookup.success) failScfCommand(lookup, lookupArgs);
        let resolved: string | null = null;
        try {
          const rows = JSON.parse(lookup.stdout || "[]") as Array<{ ID: number }>;
          if (Array.isArray(rows) && rows.length > 0) {
            resolved = String(rows[0].ID);
          }
        } catch {
          // Fall through — resolved stays null, treated as not_found below.
        }
        if (!resolved) {
          withCode(
            ErrorCodes.NOT_FOUND,
            `No field-group found for key "${key}".`,
            'Expected an ACF key (e.g. "group_5f8a1b2c3d4e5") or a numeric WP post ID. Run diviops_scf_field_group_list to see available field groups.',
            { key },
          );
        }
        postId = resolved;
      }
      const args = ["post", "get", postId, "--format=json"];
      const result = await cli.runArgs(args);
      // For numeric IDs that don't resolve, wp-cli exits non-zero with
      // "Could not find the post with ID <n>" on stderr — surface as
      // not_found rather than the generic command_failed so callers can
      // branch uniformly on `error.code`.
      if (!result.success) {
        const stderr = result.stderr ?? "";
        if (
          isNumericId &&
          result.failureKind === "exited" &&
          /Could not find the post with ID/i.test(stderr)
        ) {
          withCode(
            ErrorCodes.NOT_FOUND,
            `No field-group found for ID "${key}".`,
            "Run diviops_scf_field_group_list to see available field groups.",
            { key },
          );
        }
        failScfCommand(result, args);
      }
      try {
        return JSON.parse(result.stdout);
      } catch (e) {
        withCode(
          ErrorCodes.WP_ERROR,
          `wp-cli returned non-JSON output for --format=json: ${(e as Error).message}`,
          "Inspect wp-cli's stdout for malformed output. Re-run with WP_CLI_DEBUG=1 in the env to surface PHP traceback.",
        );
      }
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(response, "diviops_scf_field_group_get") },
      ],
    };
  },
);

// ── Connection ──────────────────────────────────────────────────────

registerLocalTool(
  "diviops_meta_ping",
  {
    description:
      "Test the connection to the WordPress site and verify the Divi MCP plugin is active. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload is { connected: true, message: \"Connected to Divi <version>\" } and connection failure surfaces as { ok: false, error: { code: 'wp_error', message } } with the underlying transport message preserved.",
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async () => {
    const response = await wrapResponse(async () => {
      const ping = await wp.testConnection();
      if (!ping.ok) {
        withCode(ErrorCodes.WP_ERROR, ping.message);
      }
      return { connected: true, message: ping.message };
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(response, "diviops_meta_ping") },
      ],
    };
  },
);

registerLocalTool(
  "diviops_meta_info",
  {
    description:
      "Returns DiviOps MCP server identity, version, license type, available capability groups, WP-CLI allowlist, and plugin handshake/slice state including Pro and FluentCart target readiness. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async () => {
    const response = await wrapResponse(async () => buildMetaInfo());
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(response, "diviops_meta_info") },
      ],
    };
  },
);

// ── Resources ────────────────────────────────────────────────────────

server.registerResource(
  "divi-block-format-guide",
  "divi://block-format-guide",
  {},
  async () => ({
    contents: [
      {
        uri: "divi://block-format-guide",
        mimeType: "text/markdown",
        text: BLOCK_FORMAT_GUIDE,
      },
    ],
  }),
);

const BLOCK_FORMAT_GUIDE = `# Divi 5 Block Markup Format

Divi 5 uses WordPress block markup (Gutenberg-style comments) to define layouts.

## Basic Structure

Every Divi layout follows this hierarchy:
\`\`\`
Section → Row → Column → Module
\`\`\`

## Example: Simple Text Section

\`\`\`html
<!-- wp:divi/section -->
<!-- wp:divi/row -->
<!-- wp:divi/column -->
<!-- wp:divi/text {"module":{"meta":{"adminLabel":{"desktop":{"value":"Heading"}}},"advanced":{"text":{"text":{"desktop":{"value":"<h1>Hello World</h1><p>This is a paragraph.</p>"}}}}}} -->
<!-- /wp:divi/text -->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
\`\`\`

## Key Patterns

### Module Attributes
Attributes are JSON in the block comment. Structure:
- \`module.meta\` — Admin label, visibility, etc.
- \`module.advanced\` — Content settings (text, links, etc.)
- \`module.decoration\` — Design/style settings (colors, fonts, spacing)

### Multi-Column Layout
\`\`\`html
<!-- wp:divi/section -->
<!-- wp:divi/row -->
<!-- wp:divi/column {"attrs":{"type":"1_2"}} -->
<!-- wp:divi/text ... --><!-- /wp:divi/text -->
<!-- /wp:divi/column -->
<!-- wp:divi/column {"attrs":{"type":"1_2"}} -->
<!-- wp:divi/image ... --><!-- /wp:divi/image -->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
\`\`\`

### Common Modules
- \`divi/text\` — Rich text content
- \`divi/image\` — Images
- \`divi/button\` — CTA buttons
- \`divi/heading\` — Headings
- \`divi/blurb\` — Icon + text cards
- \`divi/accordion\` — Collapsible sections
- \`divi/slider\` — Slide carousels
- \`divi/gallery\` — Image galleries
- \`divi/video\` — Video embeds
- \`divi/divider\` — Visual separators
- \`divi/cta\` — Call to action blocks

## Tips
1. Always use \`diviops_schema_get_module\` to check exact attribute names before building markup.
2. Use \`diviops_page_get_layout\` on existing pages to learn the format from real examples.
3. Use \`diviops_render_preview\` to validate markup before saving.
`;

// ── Template Resources ──────────────────────────────────────────────

const templatesDir = join(__dirname, "..", "templates");

function loadTemplates(): Map<string, any> {
  const templates = new Map<string, any>();
  try {
    const files = readdirSync(templatesDir).filter((f) => f.endsWith(".json"));
    for (const file of files) {
      const content = readFileSync(join(templatesDir, file), "utf-8");
      const template = JSON.parse(content);
      const name = file.replace(".json", "");
      templates.set(name, template);
    }
  } catch (e) {
    console.error("Warning: Could not load templates:", e);
  }
  return templates;
}

const templates = loadTemplates();

// Register a list tool so Claude can discover available templates
registerLocalTool(
  "diviops_template_list",
  {
    description:
      "List available Divi page section templates. Each template contains verified block markup patterns that can be used as a base for page generation. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload is an array of { name, description, customizable, requires_css }.",
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async () => {
    const response = await wrapResponse(async () =>
      Array.from(templates.entries()).map(([name, t]) => ({
        name,
        description: t.description,
        customizable: t.customizable,
        requires_css: t.requires_css ?? false,
      })),
    );
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(response, "diviops_template_list") },
      ],
    };
  },
);

registerLocalTool(
  "diviops_template_get",
  {
    description:
      "Get a specific Divi template with verified block markup, customizable variables, and usage notes. Use this to generate pages based on proven patterns. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }. Missing template names return ok:false with code 'not_found' and error.data.available: string[] listing the registered template names.",
    inputSchema: {
      template_name: z
        .string()
        .describe(
          'Template name (e.g. "hero-centered", "hero-split", "hero-marquee", "features-blurbs", "cta-gradient", "cards-flex")',
        ),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ template_name }) => {
    const response = await wrapResponse(async () => {
      const template = templates.get(template_name);
      if (!template) {
        withCode(
          ErrorCodes.NOT_FOUND,
          `Template "${template_name}" not found.`,
          "Run diviops_template_list to see available templates.",
          { available: Array.from(templates.keys()) },
        );
      }
      return template;
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(response, "diviops_template_get") },
      ],
    };
  },
);

// ── Variable Manager CRUD ─────────────────────────────────────────────

registerPluginTool(
  "diviops_variable_list",
  {
    description:
      "List all design token variables from the Divi Variable Manager. Colors (gcid-*) come from et_global_data, numbers/strings/etc (gvid-*) from et_divi_global_variables. Filter by type or ID prefix. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; invalid `type` returns ok:false with code 'invalid_input'.",
    inputSchema: {
      type: z
        .enum(["colors", "numbers", "strings", "images", "links", "fonts", "gradients"])
        .optional()
        .describe("Filter by variable type"),
      prefix: z
        .string()
        .optional()
        .describe(
          'Filter by ID prefix (e.g. "gcid-oa-" for oa design system colors)',
        ),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ type, prefix }) => {
    const params: Record<string, string> = {};
    if (type) params.type = type;
    if (prefix) params.prefix = prefix;
    const result = await wp.requestEnveloped("/variable/list", { params });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_variable_list") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_variable_create",
  {
    description:
      'Create a design token variable in the Divi Variable Manager. Colors (type "colors") use gcid-* IDs and hex values. Numbers/strings/etc use gvid-* IDs. For type="numbers" fluid tokens, pass min+max shorthand (anchors default to 320px/1920px) or explicit targets — server generates arithmetically-correct clamp() formulas. All-px inputs emit px (safe default, root-agnostic). Rem inputs OR rem output require explicit opt-in: pass output_unit="rem" (accepts the 1rem=16px default) or root_font_size_px:N (declares your site\'s actual root font-size for correct rem emission on non-16px-root sites). Mutually exclusive with value. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; input-shape rejections (invalid type, fluid+value conflict, rem-without-opt-in, malformed id, non-hex color, etc.) return ok:false with code \'invalid_input\' and `error.data` documenting the failed field. Algorithmic clamp() failures return code \'variable.fluid_generation_failed\' with `error.data = { min, max, targets, reason }`.' +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      type: z
        .enum(["colors", "numbers", "strings", "images", "links", "fonts", "gradients"])
        .describe("Variable type"),
      id: z
        .string()
        .optional()
        .describe(
          'Variable ID (e.g. "gcid-oa-accent" for colors, "gvid-oa-size-xl" for numbers). Auto-generated if omitted.',
        ),
      label: z
        .string()
        .describe("Human-readable label shown in the VB Variable Manager"),
      value: z
        .string()
        .optional()
        .describe(
          'Variable value (required unless using fluid min/max/targets for type=numbers, OR the structured `gradient` object for type=gradients): hex color for colors (e.g. "#3a7a6a"), CSS value for numbers (e.g. "clamp(30px, 8vw, 100px)" or "2rem"), arbitrary text/URL for strings/links/images. For type=gradients do NOT pass a CSS gradient string here — it stores an unrenderable variable; use the `gradient` object instead (or pass a full $variable({"type":"gradient",…})$ token verbatim).',
        ),
      gradient: z
        .object({
          stops: z
            .array(z.object({ position: z.union([z.string(), z.number()]), color: z.string() }))
            .min(2)
            .describe('Gradient color stops, min 2. position = unitless number 0–100 (string or number; PHP normalizes to string); color = hex or a $variable(gcid-…)$ token.'),
          type: z
            .enum(["linear", "circular", "elliptical", "conic"])
            .optional()
            .describe('Gradient type (default linear). circular/elliptical render as radial-gradient(circle|ellipse …); conic as conic-gradient. NOTE: the enum is circular/elliptical, NOT "radial".'),
          direction: z.string().optional().describe('CSS angle for linear/conic (default "180deg"), e.g. "90deg".'),
          directionRadial: z.string().optional().describe('Position keyword for circular/elliptical/conic (default "center"), e.g. "top left".'),
          length: z.string().optional().describe('Gradient length (default "100%").'),
          repeat: z.enum(["on", "off"]).optional().describe('Repeat the gradient (default "off").'),
          overlaysImage: z.enum(["on", "off"]).optional().describe('Place gradient above a background image (default "off").'),
        })
        .optional()
        .describe(
          'Structured gradient settings for type="gradients" (5.7.4). The server serializes the canonical $variable({"type":"gradient",value:{name:"gradient",settings:{…}}})$ token that Divi resolves to a defined --gvid-* custom property. REQUIRED for a renderable gradient variable — a raw CSS-string `value` is rejected. Ignored for non-gradient types.',
        ),
      min: z
        .string()
        .optional()
        .describe(
          'Fluid minimum value (e.g. "20px" or "1.25rem"). Paired with max. Anchors default to 320px/1920px. Rem inputs require explicit opt-in via output_unit or root_font_size_px. type="numbers" only.',
        ),
      max: z
        .string()
        .optional()
        .describe(
          'Fluid maximum value (e.g. "60px" or "3.75rem"). Paired with min.',
        ),
      targets: z
        .record(z.string(), z.string())
        .refine((m) => !m || Object.keys(m).length === 2, {
          message: "targets must contain exactly 2 viewport entries",
        })
        .optional()
        .describe(
          'Explicit two-anchor fluid spec, object keyed by viewport width (px only). Example: {"320px":"20px","1920px":"60px"} → clamp(20px, 12px + 2.5vw, 60px). Exactly 2 entries required. type="numbers" only. Mutually exclusive with min/max. Rem values require explicit opt-in via output_unit or root_font_size_px.',
        ),
      output_unit: z
        .enum(["rem", "px"])
        .optional()
        .describe(
          'Unit for generated clamp formula. Omit for all-px inputs (safe default — emits px, root-agnostic). Pass "rem" to emit rem (accepts the 1rem=16px assumption unless root_font_size_px is also passed); required when inputs include rem unless root_font_size_px is passed. Pass "px" to force px output regardless of input unit.',
        ),
      root_font_size_px: z
        .number()
        .positive()
        .optional()
        .describe(
          "Site's root font-size in px (positive number), used for correct rem↔px conversion in the generated clamp() formula. Defaults to 16 (standard browser default) when omitted. Pass explicitly for sites that customize `html { font-size }` (e.g. 10 for `html { font-size: 62.5% }`, 20 for `html { font-size: 20px }`). Also counts as an opt-in signal for rem emission — passing it alone (without output_unit) implies rem output. Only applies when min/max/targets is used.",
        ),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "false" },
  },
  async ({
    type,
    id,
    label,
    value,
    gradient,
    min,
    max,
    targets,
    output_unit,
    root_font_size_px,
    dry_run,
  }) => {
    // Structured gradient serialization is a plugin-side capability (#921).
    // Gate it so a new server + old plugin fails with a clear "update plugin"
    // error instead of the confusing "value must be scalar" the old callback
    // would emit for a gradient-without-value request.
    if (gradient !== undefined) requireCapability("variable_create_gradient");
    const body: Record<string, unknown> = { type, label };
    if (value !== undefined) body.value = value;
    if (gradient !== undefined) body.gradient = gradient;
    if (id) body.id = id;
    if (min !== undefined) body.min = min;
    if (max !== undefined) body.max = max;
    if (targets !== undefined) body.targets = targets;
    if (output_unit !== undefined) body.output_unit = output_unit;
    if (root_font_size_px !== undefined) body.root_font_size_px = root_font_size_px;
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped("/variable/create", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_variable_create") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_variable_create_fluid_system",
  {
    description:
      "Batch-emit a fluid typography + spacing + radius variable set in one call — mirrors Divi 5.4.0's Variable Generator Modal at the algorithm level (clamp() math is identical to diviops_variable_create's fluid mode) but layers profile-selectable anchors over it. Each category is independent and optional. Use for: (1) bootstrapping a design system in one call instead of 20+ individual diviops_variable_create invocations; (2) mirroring ET's variable layout so your tokens coexist with VB-generated ones in the Variable Manager; (3) deterministic preflight via dry_run before committing the registry change. By default, refuses to overwrite existing IDs (returns them in `skipped`) — pass overwrite=true to update in place. Persists in a single atomic write to the variable registry; mid-batch failures roll back cleanly. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; input-shape rejections (invalid namespace, no categories, invalid profile, plan ID collision, etc.) return code 'invalid_input' with `error.data` documenting the failed field. Algorithmic scale-generation failures (degenerate ratios/anchors caught inside compute_typography_scale or compute_size_scale) return code 'variable.fluid_system_generation_failed' with `error.data = { profile, categories, reason }`.",
    inputSchema: {
      profile: z
        .enum(["divi-default", "wide", "custom"])
        .optional()
        .default("divi-default")
        .describe(
          'Anchor preset for the underlying clamp() math. "divi-default" (360→1350) matches Divi 5.4.0\'s Variable Generator Modal defaults; "wide" (320→1920) covers a wider device span (the diviops convention); "custom" requires custom_anchors. Affects ALL three categories uniformly.',
        ),
      custom_anchors: z
        .object({
          min_viewport_px: z.number().positive(),
          max_viewport_px: z.number().positive(),
        })
        .refine((a) => a.max_viewport_px > a.min_viewport_px, {
          message: "custom_anchors.max_viewport_px must be > min_viewport_px",
        })
        .optional()
        .describe(
          'Required when profile="custom". Defines the (min_viewport_px, max_viewport_px) pair the clamp() formulas anchor to. max must be > min. (The profile/custom_anchors pairing is also enforced server-side, returning 400 invalid_profile if profile="custom" is sent without custom_anchors.)',
        ),
      typography: z
        .object({
          base_px: z
            .number()
            .positive()
            .describe(
              "Base body size in px. Step N's value = base_px × ratio^(steps-1). h1 = largest (top of chain), hN = base.",
            ),
          ratio: z
            .union([
              z.number().positive(),
              z.enum([
                "minor-second",
                "major-second",
                "minor-third",
                "major-third",
                "perfect-fourth",
                "augmented-fourth",
                "perfect-fifth",
                "golden",
              ]),
            ])
            .describe(
              "Modular-scale ratio. Pass a named scale ('major-third'=1.25, 'perfect-fifth'=1.5, 'golden'=1.618, etc.) or a raw number. Step N is base × ratio^(steps-N), so h1 (step 1) is the largest size when steps>1.",
            ),
          steps: z
            .number()
            .int()
            .min(1)
            .max(20)
            .describe(
              "Number of typography steps to emit (e.g. 6 = h1..h6). Cap is 20 to prevent runaway scale chains.",
            ),
          max_ratio: z
            .union([
              z.number().positive(),
              z.enum([
                "minor-second",
                "major-second",
                "minor-third",
                "major-third",
                "perfect-fourth",
                "augmented-fourth",
                "perfect-fifth",
                "golden",
              ]),
            ])
            .optional()
            .describe(
              "Optional ratio at max viewport. Defaults to ratio (same chain at both anchors). Pass a larger value (e.g. ratio=1.2 + max_ratio=1.333) for a more dramatic scale on large screens.",
            ),
          fluid_growth: z
            .number()
            .positive()
            .optional()
            .describe(
              "Multiplicative growth factor at max viewport. Default 1.0 = discrete (each step emits a fixed value, no clamp growth). Common values: 1.2-1.5 for moderate fluid scaling. Step N's clamp goes from `base × ratio^(steps-N)` at min_viewport to `base × max_ratio^(steps-N) × fluid_growth` at max_viewport.",
            ),
          name_prefix: z
            .string()
            .optional()
            .describe(
              "ID prefix per step. Default 'h' → IDs become gvid-{namespace}-size-h1..hN. Pass 'display' for hero sizes ('gvid-{namespace}-size-display1..').",
            ),
        })
        .optional(),
      spacing: z
        .object({
          min_px: z.number().min(0),
          max_px: z.number().positive(),
          steps: z.number().int().min(1).max(30),
          scale: z
            .enum(["linear", "geometric"])
            .optional()
            .default("linear")
            .describe(
              "Distribution between min_px and max_px. 'linear' = equal arithmetic spacing (best for spacing scales). 'geometric' = equal multiplicative spacing (best for typography-like scales). geometric requires min_px > 0.",
            ),
          fluid_growth: z
            .number()
            .positive()
            .optional()
            .describe(
              "Multiplicative growth factor at max viewport. Default 1.0 = discrete (each spacing token is constant across viewports — typical design-system behavior). > 1.0 = fluid (each token scales from `value` at min_viewport to `value × fluid_growth` at max_viewport).",
            ),
          name_prefix: z
            .string()
            .optional()
            .describe(
              "ID prefix. Default 'space' → gvid-{namespace}-space-1..N.",
            ),
        })
        .optional(),
      radius: z
        .object({
          min_px: z.number().min(0),
          max_px: z.number().positive(),
          steps: z.number().int().min(1).max(30),
          scale: z.enum(["linear", "geometric"]).optional().default("linear"),
          fluid_growth: z
            .number()
            .positive()
            .optional()
            .describe(
              "Multiplicative growth factor at max viewport. Default 1.0 = discrete. Most radius tokens stay discrete; pass > 1.0 only when you want corners to grow with viewport.",
            ),
          name_prefix: z
            .string()
            .optional()
            .describe(
              "ID prefix. Default 'rounded' → gvid-{namespace}-rounded-1..N.",
            ),
        })
        .optional(),
      namespace: z
        .string()
        .regex(/^[a-z0-9_-]+$/i, {
          message:
            "namespace must match [a-z0-9_-]+ (case-insensitive; lowercased server-side). Inputs outside this charset are rejected explicitly rather than silently rewritten — passing 'o a' or 'oa!' would alias onto the default 'oa' namespace and risk overwriting unrelated tokens.",
        })
        .optional()
        .default("oa")
        .describe(
          "Namespace inserted into every generated ID (gvid-{namespace}-*). Default 'oa' matches existing diviops convention. Validated against [a-z0-9_-]+ on both client and server (rejects rather than sanitizes — see message for rationale).",
        ),
      output_unit: z
        .enum(["rem", "px"])
        .optional()
        .describe(
          'Unit for emitted clamp() formulas. Defaults to "px" (root-agnostic, safe). Pass "rem" to opt into rem emission (bakes the 1rem=16px assumption unless root_font_size_px is also passed).',
        ),
      root_font_size_px: z
        .number()
        .positive()
        .optional()
        .describe(
          "Site's actual root font-size in px. Pass for non-16px-root sites (e.g. 10 for `html { font-size: 62.5% }`). Passing this alone implies output_unit='rem'.",
        ),
      dry_run: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "Preview the full plan without persisting. Returns identical `created`/`skipped` shape so callers can audit IDs and clamp() values before committing.",
        ),
      overwrite: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "When false (default), existing IDs land in `skipped` with the existing value. When true, each existing ID is updated in place (label + value rewritten, order preserved).",
        ),
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "false" },
  },
  async ({
    profile,
    custom_anchors,
    typography,
    spacing,
    radius,
    namespace,
    output_unit,
    root_font_size_px,
    dry_run,
    overwrite,
  }) => {
    const body: Record<string, unknown> = { profile };
    if (custom_anchors !== undefined) body.custom_anchors = custom_anchors;
    if (typography !== undefined) body.typography = typography;
    if (spacing !== undefined) body.spacing = spacing;
    if (radius !== undefined) body.radius = radius;
    if (namespace !== undefined) body.namespace = namespace;
    if (output_unit !== undefined) body.output_unit = output_unit;
    if (root_font_size_px !== undefined) body.root_font_size_px = root_font_size_px;
    if (dry_run !== undefined) body.dry_run = dry_run;
    if (overwrite !== undefined) body.overwrite = overwrite;
    const result = await wp.requestEnveloped("/variable/create-fluid-system", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_variable_create_fluid_system") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_variable_delete",
  {
    description:
      "Delete a design token variable by ID. Auto-detects storage from ID prefix (gcid-* = colors, gvid-* = numbers/strings/etc). Returns the standardized envelope { ok, data?, error: { code, message, hint? } }. Live-reference collision returns ok:false with code 'conflict' (HTTP 409) and `error.data = { id: string, ref_count: number, locations: object[] }` so callers can audit before re-issuing with force=true. The `locations` array is a discriminated union by `type` — content surfaces emit `{ type: 'page'|'post'|'et_header_layout'|'et_body_layout'|'et_footer_layout'|'et_pb_layout'|'et_pb_canvas', post_id: number, title: string }` (post_type as `type` so the Theme Builder + library + canvas flavors are distinguishable); preset-registry refs emit `{ type: 'preset', bucket: 'module'|'group', module: string, preset_uuid: string, preset_name: string }`. This shape is the precedent for any future conflict envelope carrying structured `error.data` collections. Run diviops_variable_scan_orphans first to see where the references live. Customizer-bound color defaults (gcid-primary-color, gcid-secondary-color, gcid-heading-color, gcid-body-color, gcid-link-color) are managed via WP Customizer theme options and reject with code 'variable.customizer_default_immutable' (HTTP 403). Missing IDs return 'not_found' (HTTP 404)." +
      DRY_RUN_DESC_SUFFIX,
    inputSchema: {
      id: z
        .string()
        .describe(
          'Variable ID to delete (e.g. "gcid-oa-accent" or "gvid-oa-size-xl")',
        ),
      force: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "Delete even if live references exist. Orphans will remain in page/preset content and render as invalid CSS on the frontend — run diviops_variable_scan_orphans afterwards to audit.",
        ),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ id, force, dry_run }) => {
    const body: Record<string, unknown> = { id, force };
    if (dry_run) body.dry_run = true;
    const result = await wp.requestEnveloped("/variable/delete", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_variable_delete") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_variable_scan_orphans",
  {
    description:
      "Scan pages, Theme Builder layouts (header/body/footer), Divi Library items, canvas pages, and the preset registry for gvid-/gcid- references that have no backing entry in the Variable Manager (orphans), plus variables defined but referenced nowhere (unused). Orphans render as invalid CSS on the frontend — the $variable()$ resolver falls through with no fallback. Use after a deletion with force=true, or periodically as a hygiene check. Symmetric to diviops_preset_scan_orphans. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async () => {
    const result = await wp.requestEnveloped("/variable/scan-orphans");
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_variable_scan_orphans") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_variable_used_on_page",
  {
    description:
      "Detect which numeric/font variable IDs a single page actually emits — the exact set Divi 5.4.0+ uses to scope selective `:root{--gvid-*}` CSS variable emission. Walks the same content stack the frontend assembles: post_content + active Theme Builder header/body/footer template content + appended canvas content (interaction targets etc.), plus presets referenced by that content. NOTE: this is `gvid-*` only — color variables (`gcid-*`) are emitted via a separate path (`GlobalData` color block) that is NOT scoped per-page in 5.4.0; this tool returns gvid IDs only. Use for per-page orphan validation (complements global diviops_variable_scan_orphans), preflight before bulk variable rename (know which pages are affected), or to debug why a numeric/font variable doesn't render on a specific page. Read-only. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload is { post_id, variable_ids (sorted, deduped), count, tb_template_ids }. Missing post_id returns 'not_found'; non-positive post_id returns 'invalid_input'; a Divi 5 environment without the `\\\\ET\\\\Builder\\\\FrontEnd\\\\Assets\\\\DetectFeature` class (e.g. Divi 4 active, or Divi disabled) returns 'wp_error' (HTTP 500) with a hint to activate Divi 5.",
    inputSchema: {
      post_id: z
        .number()
        .int()
        .positive()
        .describe(
          "WordPress post/page ID. The page does not need to be Divi-built — TB templates and canvases attached to non-Divi posts are still scanned.",
        ),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ post_id }) => {
    const result = await wp.requestEnveloped(`/variable/used-on-page/${post_id}`);
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_variable_used_on_page") },
      ],
    };
  },
);

registerPluginTool(
  "diviops_meta_flush_cache",
  {
    description:
      "Flush Divi's compiled static CSS cache under wp-content/et-cache/. wp cache flush does NOT touch these files — the frontend can keep serving stale CSS after a preset/variable/module mutation until the cache is cleared. Delegates to Divi's native ET_Core_PageResource::remove_static_resources when available (response backend: \"divi_native\"), which additionally clears Theme Builder CSS scattered across other post dirs, archive/taxonomy/home/notfound CSS, the object cache, module features cache, post features cache, Google Fonts cache, dynamic assets cache, and post meta caches. Falls back to a targeted filesystem walk of numeric-named et-cache subdirs when the Divi class is absent (backend: \"fs_fallback\"). Provide exactly one selector — no site-wide default to prevent accidental full flush. Optional cleanup_dynamic_assets=true explicitly deletes and reports _divi_dynamic_assets_cached_feature_used postmeta for the selected target IDs; cleanup_canvas_refs=true also deletes _divi_dynamic_assets_canvases_used and is only appropriate when canvas/off-canvas references are affected. Dynamic-assets postmeta cleanup is supported with post_id or all (bounded to posts that already carry the selected meta keys), not after. Idempotent: missing cache root returns 200 with empty list and repeat postmeta cleanup reports absent keys. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; namespace-specific error codes: meta_flush_cache.unwritable (filesystem refused), meta_flush_cache.fs_init_failed (WP_Filesystem could not authenticate)." +
      DRY_RUN_DESC_SUFFIX +
      " Note: in `after` mode the dry-run plan reports the cutoff only — accurate file count requires the live mtime walk.",
    inputSchema: {
      post_id: z
        .number()
        .int()
        .positive()
        .optional()
        .describe(
          "Flush cache for one post. Native backend also clears matching Theme Builder CSS in other post dirs; fs_fallback only clears wp-content/et-cache/{post_id}/.",
        ),
      all: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "Flush every cached file. Native backend clears archive/taxonomy/home/notfound CSS + multi-layer WP caches; fs_fallback only clears numeric-named subdirs (siblings like .cache-cleared-at, global/, en_US/, notfound/, *.data are preserved in either mode).",
        ),
      after: z
        .number()
        .int()
        .positive()
        .optional()
        .describe(
          "Unix timestamp — flush Divi CSS files (et-*.css) with mtime strictly greater than this value. Useful for flushing entries touched since a known deployment or mutation batch. Native backend does a single-pass filesystem sweep covering numeric post dirs AND archive/taxonomy/home/notfound/global subtrees in one walk (Visual Builder -vb-* runtime CSS preserved); fs_fallback iterates numeric post dirs whose latest file mtime > after. `flushed` lists numeric post_ids whose files were actually deleted; `skipped` lists numeric post_ids that exist but had no files pass the filter.",
        ),
      dry_run: DRY_RUN_FIELD,
      cleanup_dynamic_assets: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "Explicitly delete and report Divi's _divi_dynamic_assets_cached_feature_used postmeta for the selected post_id, or for all posts that already carry selected dynamic-assets postmeta when all=true. Supported with post_id or all only.",
        ),
      cleanup_canvas_refs: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "Opt-in canvas/off-canvas cleanup. Requires cleanup_dynamic_assets=true and also deletes _divi_dynamic_assets_canvases_used. Use only when canvas/off-canvas references are affected.",
        ),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async ({ post_id, all, after, dry_run, cleanup_dynamic_assets, cleanup_canvas_refs }) => {
    const body: Record<string, unknown> = {};
    if (post_id !== undefined) body.post_id = post_id;
    if (all) body.all = true;
    if (after !== undefined) body.after = after;
    if (dry_run) body.dry_run = true;
    if (cleanup_dynamic_assets) body.cleanup_dynamic_assets = true;
    if (cleanup_canvas_refs) body.cleanup_canvas_refs = true;
    const result = await wp.requestEnveloped("/meta/flush-cache", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: serializeEnvelope(result, "diviops_meta_flush_cache") },
      ],
    };
  },
);

// ── Pro coverage-slice tools (ADR-003 / ADR-007) ─────────────────────
//
// FCP V1 read tools — ADR-007 § 7.1. Registered through `registerProTool`
// which short-circuits when any of {pro_active, target presence, module
// activation, capability key} gates are false. On Free-only sites the
// FCP tools simply don't exist on the MCP surface — no error envelope,
// no missing-capability hint, just absence.
//
// Run inside `registerProTools()` rather than at module load because the
// gates read handshakeState which is `pending` until `main()` runs.

const CrossEnvSourceAttachmentSchema = z
  .object({
    id: z.number().int().positive().optional(),
    url: z.string().optional(),
    path: z.string().optional(),
    filename: z.string().optional(),
    mime: z.string().optional(),
  })
  .passthrough();

const CrossEnvSourcePayloadSchema = z
  .object({
    origin: z.string().describe("Source site origin, e.g. https://source.example."),
    object_kind: z
      .enum(["tb_header_layout"])
      .describe("Source object kind. MVP supports tb_header_layout only."),
    object_id: z.union([z.number(), z.string()]).optional(),
    object_title: z.string().optional(),
    markup: z.string().describe("Sanitized source header layout block markup."),
    checksum: z
      .string()
      .regex(/^(sha256:)?[a-fA-F0-9]{64}$/)
      .describe("sha256 checksum of source_payload.markup from source export."),
    attachments: z.array(CrossEnvSourceAttachmentSchema).optional(),
  })
  .passthrough();

function normalizeReviewedFingerprint(value: string): string {
  return value.trim().replace(/^sha256:/i, "").toLowerCase();
}

function sourceHintsFromPayload(source: SourceLayoutPayload): {
  source_asset_hints?: string[];
  source_attachment_ids?: number[];
} {
  const hints = new Set<string>();
  const ids = new Set<number>();
  for (const attachment of source.attachments ?? []) {
    if (typeof attachment.id === "number" && Number.isInteger(attachment.id) && attachment.id > 0) {
      ids.add(attachment.id);
    }
    for (const value of [attachment.path, attachment.url, attachment.filename]) {
      if (typeof value === "string" && value.trim()) hints.add(value.trim());
    }
  }
  return {
    ...(hints.size > 0 ? { source_asset_hints: [...hints].sort((a, b) => a.localeCompare(b)) } : {}),
    ...(ids.size > 0 ? { source_attachment_ids: [...ids].sort((a, b) => a - b) } : {}),
  };
}

function registerProTools(): void {
  // diviops_cross_env_header_apply — guarded Pro apply for reviewed header preflight
  registerProTool(
    "diviops_cross_env_header_apply",
    {
      description:
        "Apply a reviewed cross-environment Theme Builder header payload into an existing target header layout (Pro tier; requires the cross_env Pro module). This first MVP is intentionally narrow: it requires `confirm_apply: true`, a source export payload with checksum, an existing target `destination_id`, and the reviewed `confirmation_binding.fingerprint` from `diviops-cross-env-preflight`. The server re-exports current target context, reruns the TypeScript preflight engine, refuses fingerprint mismatch, unsafe verdicts, destination checksum drift, missing/ambiguous attachment remaps, missing global-color value evidence, and off-canvas/canvas references, then calls the Pro route with the verified plan. The Pro route re-loads the destination post, verifies the current checksum again, builds content only from source-origin upload URL rewrites and proven attachment-ID remaps, writes with readback/rollback evidence, and runs Divi static-resource cleanup plus explicit _divi_dynamic_assets_cached_feature_used cleanup. No media upload/import, global-color creation/import, new layout creation, off-canvas reconcile, public/store/license change, or live-site smoke is performed by this tool. Returns the standardized envelope { ok, data?, error: { code, message, hint?, data? } }. Error codes include cross_env.confirmation_required, cross_env.fingerprint_mismatch, cross_env.preflight_not_safe, cross_env.destination_checksum_drift, cross_env.source_checksum_mismatch, cross_env.content_write_corruption, invalid_input, not_found, forbidden, and wp_error.",
      inputSchema: {
        source_payload: CrossEnvSourcePayloadSchema.describe(
          "The `data` object returned by diviops_cross_env_source_export_get on the source site.",
        ),
        destination_id: z
          .number()
          .int()
          .positive()
          .describe("Existing target Theme Builder header layout post ID."),
        destination_kind: z
          .enum(["tb_header_layout"])
          .optional()
          .default("tb_header_layout")
          .describe("Destination kind. MVP supports tb_header_layout only."),
        reviewed_fingerprint: z
          .string()
          .regex(/^(sha256:)?[a-fA-F0-9]{64}$/)
          .describe("Reviewed confirmation_binding.fingerprint from the safe preflight report."),
        confirm_apply: z
          .boolean()
          .describe("Must be true. Missing/false refuses before any target mutation."),
      },
      annotations: { idempotentHint: false },
      _meta: { idempotent: "conditional" },
    },
    async ({
      source_payload,
      destination_id,
      destination_kind,
      reviewed_fingerprint,
      confirm_apply,
    }: {
      source_payload: SourceLayoutPayload;
      destination_id: number;
      destination_kind?: "tb_header_layout";
      reviewed_fingerprint: string;
      confirm_apply: boolean;
    }) => {
      const result = await wrapResponse(async () => {
        if (confirm_apply !== true) {
          withCode(
            "cross_env.confirmation_required",
            "confirm_apply: true is required before a cross-env header apply can mutate content.",
            "Review the preflight report, then retry with confirm_apply: true.",
          );
        }

        const targetBody: Record<string, unknown> = {
          destination_id,
          destination_kind: destination_kind ?? "tb_header_layout",
          ...sourceHintsFromPayload(source_payload),
        };
        const targetEnvelope = await wp.requestEnveloped<TargetLayoutContext>(
          "/cross-env/target-context",
          { method: "POST", body: targetBody },
        );
        if (!targetEnvelope.ok) return targetEnvelope;

        const report = preflightCrossEnvHeaderSync({
          source: source_payload,
          target: targetEnvelope.data,
        });
        const reviewed = normalizeReviewedFingerprint(reviewed_fingerprint);
        if (report.confirmation_binding.fingerprint !== reviewed) {
          withCode(
            "cross_env.fingerprint_mismatch",
            "Reviewed confirmation fingerprint does not match the recomputed current preflight report.",
            "Re-run diviops-cross-env-preflight against the latest target context and confirm the new fingerprint.",
            {
              reviewed_fingerprint: reviewed,
              recomputed_fingerprint: report.confirmation_binding.fingerprint,
              current_destination_checksum: report.target.destination_checksum ?? null,
            },
          );
        }
        if (report.verdict !== "safe_dry_run") {
          withCode(
            "cross_env.preflight_not_safe",
            "Recomputed preflight verdict is not safe for this MVP apply path.",
            "Resolve blockers/operator actions, then re-run and re-confirm preflight.",
            {
              verdict: report.verdict,
              blockers: report.blockers,
              operator_actions: report.operator_actions,
            },
          );
        }

        return wp.requestEnveloped("/pro/cross-env/header-layout/apply", {
          method: "POST",
          body: {
            confirm_apply: true,
            destination_id,
            destination_kind: destination_kind ?? "tb_header_layout",
            source_payload,
            source_checksum: source_payload.checksum,
            destination_checksum: report.target.destination_checksum,
            reviewed_fingerprint: reviewed,
            preflight_report: report,
          },
        });
      });
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(result, "diviops_cross_env_header_apply"),
          },
        ],
      };
    },
    { target: "cross_env", capabilityKey: "cross_env_header_apply" },
  );

  // diviops_fc_product_list — bridges /diviops/v1/pro/fluentcart/products
  registerProTool(
    "diviops_fc_product_list",
    {
      description:
        "List FluentCart Pro products (Pro tier; requires FluentCart Pro installed + activated). Returns a paginated summary list with product identity (id, title, slug, status), variation_type, variants_count, and min/max price. Filterable by `search` (LIKE post_title), `type` (one of physical/digital/subscription/onetime/simple/variations), and `status` (one of publish/draft/pending/private/trash; default returns publish+draft+pending+private). Read-only. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; the success payload is { products: ProductSummary[], pagination: { page, per_page, total, total_pages }, filters: { search, type, status } }. Error codes: invalid_input (HTTP 400) when type/status filter is out of range; fluentcart.module_inactive (HTTP 412) when FluentCart is uninstalled or the diviops-agent-pro module toggle is off; fluentcart.query_failed (HTTP 500) when the underlying FluentCart model query raises an exception (message field carries the upstream exception). Use this before authoring a Divi commerce page to identify which product IDs / types to render.",
      inputSchema: {
        page: z
          .number()
          .int()
          .positive()
          .optional()
          .default(1)
          .describe("Page number, 1-indexed. Default 1."),
        per_page: z
          .number()
          .int()
          .positive()
          .optional()
          .default(20)
          .describe(
            "Page size. Default 20, clamped to a max of 100 per call.",
          ),
        search: z
          .string()
          .optional()
          .describe(
            "Search term — matches against product post_title via SQL LIKE %term%. Case-insensitive on most MySQL collations.",
          ),
        type: z
          .enum([
            "physical",
            "digital",
            "subscription",
            "onetime",
            "simple",
            "variations",
          ])
          .optional()
          .describe(
            "Product type filter. physical/digital filter by fulfillment_type on variations; subscription/onetime filter by payment_type on variations; simple filters detail.variation_type='simple'; variations filters detail.variation_type in {simple_variations, advanced_variations}.",
          ),
        status: z
          .enum(["publish", "draft", "pending", "private", "trash"])
          .optional()
          .describe(
            "Post status filter. Defaults to all visible-to-admin statuses (publish+draft+pending+private). Pass 'trash' explicitly to inspect trashed products.",
          ),
      },
      annotations: { idempotentHint: true },
      _meta: { idempotent: "true" },
    },
    async ({
      page,
      per_page,
      search,
      type,
      status,
    }: {
      page?: number;
      per_page?: number;
      search?: string;
      type?: string;
      status?: string;
    }) => {
      const body: Record<string, unknown> = {};
      if (page !== undefined) body.page = page;
      if (per_page !== undefined) body.per_page = per_page;
      if (search !== undefined) body.search = search;
      if (type !== undefined) body.type = type;
      if (status !== undefined) body.status = status;
      const result = await wp.requestEnveloped(
        "/pro/fluentcart/products",
        { method: "POST", body },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(result, "diviops_fc_product_list"),
          },
        ],
      };
    },
    { target: "fluentcart", capabilityKey: "fluentcart_product_list" },
  );

  // diviops_fc_product_get — bridges /diviops/v1/pro/fluentcart/products/{id}
  registerProTool(
    "diviops_fc_product_get",
    {
      description:
        "Fetch a single FluentCart Pro product by ID, including the ProductDetail row, the default-variation read-back fields, and a list of variation IDs (Pro tier; requires FluentCart Pro installed + activated). Read-only. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; the success payload is { product: { id, title, slug, status, created_at, modified_at, variation_type, variants_count, min_price, max_price, stock_availability, excerpt, content, author_id, view_url, edit_url }, detail: { fulfillment_type, variation_type, min_price, max_price, manage_stock, manage_downloadable, stock_availability, default_variation_id, ... } | null, default_variation: { id, sku, item_price, compare_price } | null, variation_ids: number[], variations_count }. The default_variation block closes the read-after-write loop for the V2 simple-product write tools — sku and compare_price round-trip cleanly without a FluentCart admin fallback. Unit asymmetry: product_create / product_update accept price and compare_price in currency units (e.g. 29.99); product_get returns min_price, max_price, default_variation.item_price, and default_variation.compare_price in stored cents (e.g. 2999). default_variation.sku is null when the stored SKU is SQL NULL; clearing a SKU with sku: \"\" on update reads back as null. default_variation itself is null when the product has no default variation row. Use the variation_ids list to follow up with a (future) diviops_fc_variation_list call. Error codes: invalid_input (HTTP 400) when id is not a positive integer; not_found (HTTP 404) when no product matches the ID (or it's filtered out by the FluentCart auto-draft global scope); fluentcart.module_inactive (HTTP 412) when FluentCart is uninstalled or the module toggle is off; fluentcart.query_failed (HTTP 500) when the FluentCart model query raises an exception.",
      inputSchema: {
        id: z
          .number()
          .int()
          .positive()
          .describe(
            "FluentCart product ID (the post ID of the fluent_products CPT entry).",
          ),
      },
      annotations: { idempotentHint: true },
      _meta: { idempotent: "true" },
    },
    async ({ id }: { id: number }) => {
      const result = await wp.requestEnveloped(
        `/pro/fluentcart/products/${id}`,
        { method: "POST" },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(result, "diviops_fc_product_get"),
          },
        ],
      };
    },
    { target: "fluentcart", capabilityKey: "fluentcart_product_get" },
  );

  // ── V2 — simple product writes ─────────────────────────────────────
  //
  // Three Pro write tools backing the constrained simple-onetime-product
  // surface from ADR-007 § 7.1. All three accept `dry_run` (default
  // false), emit the standard envelope, and refuse non-simple shapes
  // with `fluentcart.unsupported_product_shape` so the V3 variation
  // surface can own multi-variant complexity cleanly.

  // diviops_fc_product_create — POST /diviops/v1/pro/fluentcart/products/create
  registerProTool(
    "diviops_fc_product_create",
    {
      description:
        "Create a simple FluentCart Pro product (Pro tier; requires FluentCart Pro installed + activated). V2 scope: simple onetime products only — one default variant, `detail.variation_type=\"simple\"`, `payment_type=\"onetime\"`, `fulfillment_type=\"digital\"|\"physical\"`. Multi-variation, subscriptions, downloadables, gallery, taxonomies, activation_limit, and license-flow fields ship in later verticals and are refused here. Required: `title` (1-200 chars). Optional: `status` (`draft`|`publish`|`pending`|`private`; default `draft`), `content`, `excerpt`, `fulfillment_type` (default `digital`), `price` (≥0; default 0), `compare_price` (≥0; must be ≥ `price` when provided), `sku` (optional; must be unique across FluentCart variations and at most 30 characters because FluentCart stores `fct_product_variations.sku` as `VARCHAR(30)` — overlong values are rejected before mutation rather than silently truncated). Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; apply-mode success payload is { product, detail, default_variation: { id, sku, item_price, compare_price } | null, variation_ids, variations_count, product_id, detail_id, default_variation_id } (HTTP 201). The default_variation block mirrors the diviops_fc_product_get read-back shape so callers don't need a follow-up read to confirm sku / compare_price / item_price after a write. Unit asymmetry: write inputs (price, compare_price) are in currency units (e.g. 29.99); the default_variation block returns item_price + compare_price in stored cents (e.g. 2999), matching detail.min_price / max_price. default_variation.compare_price is null when no compare price is stored (FCP persists \"no compare\" as 0, normalized to null on read); default_variation.sku is null when the SKU column is SQL NULL or an empty string (the create path stores omitted SKU as NULL). Error codes: invalid_input (400) when any input violates the constraints above (including SKU > 30 chars — error.data carries { field: \"sku\", max_length: 30, actual_length }); fluentcart.sku_conflict (409) when the provided SKU is already in use; fluentcart.module_inactive (412); fluentcart.command_failed (500) when wp_insert_post/ProductDetail/ProductVariation creation raises. Idempotency: NOT idempotent — repeat calls create distinct products." +
        DRY_RUN_DESC_SUFFIX,
      inputSchema: {
        title: z
          .string()
          .min(1)
          .max(200)
          .describe(
            "Product title (post_title). 1-200 chars. Used verbatim as the default variation's variation_title.",
          ),
        status: z
          .enum(["draft", "publish", "pending", "private"])
          .optional()
          .describe("Post status. Defaults to 'draft'."),
        content: z
          .string()
          .optional()
          .describe(
            "Long product description (post_content). Optional.",
          ),
        excerpt: z
          .string()
          .optional()
          .describe(
            "Short product summary (post_excerpt). Optional.",
          ),
        fulfillment_type: z
          .enum(["digital", "physical"])
          .optional()
          .describe(
            "Fulfillment shape — digital downloads vs physical shipping. Defaults to 'digital'.",
          ),
        price: z
          .number()
          .min(0)
          .optional()
          .describe(
            "Default variation's item_price (currency units, e.g. dollars — converted to cents server-side). Non-negative. Defaults to 0.",
          ),
        compare_price: z
          .number()
          .min(0)
          .optional()
          .describe(
            "Default variation's compare-at price (strike-through). Must be ≥ `price` when both provided. Non-negative.",
          ),
        sku: z
          .string()
          .optional()
          .describe(
            "Default variation's SKU. Optional. Must be unique across all FluentCart variations and at most 30 characters (FluentCart stores fct_product_variations.sku as VARCHAR(30)). Omit to skip SKU assignment.",
          ),
        dry_run: DRY_RUN_FIELD,
      },
      annotations: { idempotentHint: false },
      _meta: { idempotent: "false" },
    },
    async ({
      title,
      status,
      content,
      excerpt,
      fulfillment_type,
      price,
      compare_price,
      sku,
      dry_run,
    }: {
      title: string;
      status?: string;
      content?: string;
      excerpt?: string;
      fulfillment_type?: string;
      price?: number;
      compare_price?: number;
      sku?: string;
      dry_run?: boolean;
    }) => {
      const body: Record<string, unknown> = { title };
      if (status !== undefined) body.status = status;
      if (content !== undefined) body.content = content;
      if (excerpt !== undefined) body.excerpt = excerpt;
      if (fulfillment_type !== undefined) body.fulfillment_type = fulfillment_type;
      if (price !== undefined) body.price = price;
      if (compare_price !== undefined) body.compare_price = compare_price;
      if (sku !== undefined) body.sku = sku;
      if (dry_run !== undefined) body.dry_run = dry_run;
      const result = await wp.requestEnveloped(
        "/pro/fluentcart/products/create",
        { method: "POST", body },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(result, "diviops_fc_product_create"),
          },
        ],
      };
    },
    { target: "fluentcart", capabilityKey: "fluentcart_product_create" },
  );

  // diviops_fc_product_update — POST /diviops/v1/pro/fluentcart/products/{id}/update
  registerProTool(
    "diviops_fc_product_update",
    {
      description:
        "Update a simple FluentCart Pro product (Pro tier; requires FluentCart Pro installed + activated). V2 scope: simple onetime products only — accepts partial updates on title, status, content, excerpt, fulfillment_type, price, compare_price, sku. SKU constraint: must be unique across FluentCart variations and at most 30 characters (FluentCart stores `fct_product_variations.sku` as `VARCHAR(30)`); `sku: \"\"` clears the SKU and reads back as `null`. Overlong SKUs are rejected before mutation rather than silently truncated. Refuses non-simple products (variation_type other than 'simple', or default variant with payment_type other than 'onetime') with `fluentcart.unsupported_product_shape` (HTTP 422) — multi-variation + subscription writes ship in V3+. Required: `id` (positive integer; the post ID of the fluent_products CPT entry). All other fields optional; only changed fields are applied. When no field actually changes, returns `ok:true` with `data.noop: true` (apply mode) or an empty-plan dry-run summary. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; apply-mode success payload is { product, detail, default_variation: { id, sku, item_price, compare_price } | null, variation_ids, variations_count, changed_fields[] } (or { noop: true, product, detail, default_variation, ... } on a no-op). The default_variation block reflects the post-update state (or current state on noop) and mirrors the diviops_fc_product_get shape — sku, item_price, and compare_price round-trip without a follow-up read. Unit asymmetry: write inputs (price, compare_price) are in currency units (e.g. 29.99); default_variation returns item_price + compare_price in stored cents (e.g. 2999). default_variation.sku is null after `sku: \"\"` clears it (the empty string is stored as SQL NULL so the variations table's UNIQUE constraint allows multiple cleared SKUs); default_variation.compare_price is null when no compare price is stored (FCP persists \"no compare\" as 0, normalized to null on read). Error codes: invalid_input (400) when any field violates the constraints (including SKU > 30 chars — error.data carries { field: \"sku\", max_length: 30, actual_length }); not_found (404) when the product ID does not exist; fluentcart.unsupported_product_shape (422) when the product is not simple/onetime; fluentcart.sku_conflict (409) when a new SKU collides with another variation; fluentcart.module_inactive (412); fluentcart.command_failed (500). Idempotency: conditional — repeating an identical update is a no-op." +
        DRY_RUN_DESC_SUFFIX,
      inputSchema: {
        id: z
          .number()
          .int()
          .positive()
          .describe(
            "FluentCart product ID (the post ID of the fluent_products CPT entry).",
          ),
        title: z
          .string()
          .min(1)
          .max(200)
          .optional()
          .describe("New product title. 1-200 chars."),
        status: z
          .enum(["draft", "publish", "pending", "private"])
          .optional()
          .describe("New post status."),
        content: z.string().optional().describe("New long description."),
        excerpt: z.string().optional().describe("New short summary."),
        fulfillment_type: z
          .enum(["digital", "physical"])
          .optional()
          .describe("New fulfillment shape."),
        price: z
          .number()
          .min(0)
          .optional()
          .describe(
            "New default-variation item_price (currency units). Non-negative.",
          ),
        compare_price: z
          .number()
          .min(0)
          .optional()
          .describe(
            "New compare-at price. Must be ≥ `price` when both provided.",
          ),
        sku: z
          .string()
          .optional()
          .describe(
            "New SKU for the default variation. Optional. Must be unique across all FluentCart variations and at most 30 characters (FluentCart stores fct_product_variations.sku as VARCHAR(30)). Empty string clears the SKU (reads back as null).",
          ),
        dry_run: DRY_RUN_FIELD,
      },
      annotations: { idempotentHint: false },
      _meta: { idempotent: "conditional" },
    },
    async ({
      id,
      title,
      status,
      content,
      excerpt,
      fulfillment_type,
      price,
      compare_price,
      sku,
      dry_run,
    }: {
      id: number;
      title?: string;
      status?: string;
      content?: string;
      excerpt?: string;
      fulfillment_type?: string;
      price?: number;
      compare_price?: number;
      sku?: string;
      dry_run?: boolean;
    }) => {
      const body: Record<string, unknown> = {};
      if (title !== undefined) body.title = title;
      if (status !== undefined) body.status = status;
      if (content !== undefined) body.content = content;
      if (excerpt !== undefined) body.excerpt = excerpt;
      if (fulfillment_type !== undefined) body.fulfillment_type = fulfillment_type;
      if (price !== undefined) body.price = price;
      if (compare_price !== undefined) body.compare_price = compare_price;
      if (sku !== undefined) body.sku = sku;
      if (dry_run !== undefined) body.dry_run = dry_run;
      const result = await wp.requestEnveloped(
        `/pro/fluentcart/products/${id}/update`,
        { method: "POST", body },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(result, "diviops_fc_product_update"),
          },
        ],
      };
    },
    { target: "fluentcart", capabilityKey: "fluentcart_product_update" },
  );

  // diviops_fc_product_delete — POST /diviops/v1/pro/fluentcart/products/{id}/delete
  registerProTool(
    "diviops_fc_product_delete",
    {
      description:
        "Trash a FluentCart Pro product (Pro tier; requires FluentCart Pro installed + activated). V2 semantics: trash, NOT hard-delete. Uses `wp_trash_post` (not FluentCart's `ProductResource::delete`, which permanently destroys detail / variation rows) so the trash bin remains recoverable from the FluentCart admin UI. Repeat-safe: trashing an already-trashed product returns `ok:true` with `data.already_trashed: true` (no error). Permanent delete is intentionally NOT in V2 — surfaces in a later vertical with explicit policy. Pending-order protection: a product with at least one on-hold or processing order returns `fluentcart.pending_orders` (HTTP 409) and is not bypassable in V2. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; apply-mode success payload is { trashed: true, product_id } or { already_trashed: true, product_id }. Error codes: invalid_input (400) when id is not a positive integer; not_found (404) when no product matches; fluentcart.pending_orders (409) when the product has on-hold/processing orders; fluentcart.module_inactive (412); fluentcart.command_failed (500). Idempotency: conditional — repeat trash is a no-op." +
        DRY_RUN_DESC_SUFFIX,
      inputSchema: {
        id: z
          .number()
          .int()
          .positive()
          .describe(
            "FluentCart product ID (the post ID of the fluent_products CPT entry).",
          ),
        dry_run: DRY_RUN_FIELD,
      },
      annotations: { idempotentHint: false },
      _meta: { idempotent: "conditional" },
    },
    async ({ id, dry_run }: { id: number; dry_run?: boolean }) => {
      const body: Record<string, unknown> = {};
      if (dry_run !== undefined) body.dry_run = dry_run;
      const result = await wp.requestEnveloped(
        `/pro/fluentcart/products/${id}/delete`,
        { method: "POST", body },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(result, "diviops_fc_product_delete"),
          },
        ],
      };
    },
    { target: "fluentcart", capabilityKey: "fluentcart_product_delete" },
  );

  // ── V3 — variation read/write + license-settings read/write ────────
  //
  // V3 expands the FCP authoring surface so a draft simple-product catalog
  // can represent ADR-005 commercial shape: annual rows become subscription
  // products, lifetime rows stay onetime, and per-tier activation_limit
  // is writable. Activation limits live in `ProductMeta.license_settings`
  // per FluentCart Pro source (NOT variation `other_info`), so license
  // settings have their own dedicated read/write tools.
  //
  // V3 stays inside the simple-product default-variation contract:
  // no multi-variation create/delete, no signup-fee writes,
  // no license activation flow, no update-ZIP / readme / banner config.

  // diviops_fc_variation_list — POST /diviops/v1/pro/fluentcart/products/{id}/variations
  registerProTool(
    "diviops_fc_variation_list",
    {
      description:
        "List FluentCart Pro variations for a product (Pro tier; V3; requires FluentCart Pro installed + activated). Read-only. Returns every variation row attached to the product with its subscription shape (payment_type, other_info.repeat_interval/times/trial_days/manage_setup_fee) and a license-settings projection when ProductMeta.license_settings is configured. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload is { product_id, variation_type, default_variation_id, variations: VariationRow[], variations_count }. Each VariationRow carries { id, post_id, variation_title, sku, payment_type, item_price, compare_price, fulfillment_type, stock_status, manage_stock, available, other_info: { ...all stored keys... }, license: { activation_limit, validity: { unit, value } } | null }. Unit convention: item_price and compare_price are stored cents (e.g. 1900 = $19.00). compare_price is null when FCP stores the no-compare sentinel 0; sku is null when the column is SQL NULL OR an empty string. license is null when the product has no license_settings; otherwise activation_limit is null/'' (unset), 0 (unlimited per FluentCart Pro License::getActivationLimit), or a positive integer (max activations). validity.unit is one of: lifetime/day/week/month/year. Error codes: invalid_input (400) when id is not a positive integer; not_found (404) when the product does not exist; fluentcart.module_inactive (412); fluentcart.query_failed (500). Idempotency: read-only.",
      inputSchema: {
        product_id: z
          .number()
          .int()
          .positive()
          .describe(
            "FluentCart product ID (the post ID of the fluent_products CPT entry).",
          ),
      },
      annotations: { idempotentHint: true },
      _meta: { idempotent: "true" },
    },
    async ({ product_id }: { product_id: number }) => {
      const result = await wp.requestEnveloped(
        `/pro/fluentcart/products/${product_id}/variations`,
        { method: "POST" },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(result, "diviops_fc_variation_list"),
          },
        ],
      };
    },
    { target: "fluentcart", capabilityKey: "fluentcart_variation_list" },
  );

  // diviops_fc_variation_update — POST /diviops/v1/pro/fluentcart/products/{product_id}/variations/{variation_id}/update
  registerProTool(
    "diviops_fc_variation_update",
    {
      description:
        "Update the default variation of a simple FluentCart Pro product (Pro tier; V3; requires FluentCart Pro installed + activated). V3 scope: writes the product's default variation only; refuses non-simple products and non-default variations with `fluentcart.unsupported_product_shape` (HTTP 422). Multi-variation create/delete remains out of scope. Accepts partial updates on price, compare_price, sku, payment_type, and the subscription shape (repeat_interval, times, trial_days, manage_setup_fee). Switching `payment_type: \"subscription\"` requires `repeat_interval` (yearly/half_yearly/quarterly/monthly/weekly/daily) — either supplied in the same call or already stored. Switching to `onetime` strips the subscription-only keys from other_info, matching ProductVariationRequest::beforeValidation. `manage_setup_fee: \"yes\"` requires signup_fee + signup_fee_name which are out of scope for V3 (use FluentCart admin UI for setup fees); only `manage_setup_fee: \"no\"` is accepted. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; apply-mode success payload is { product_id, variation_id, changed_fields[], variation: VariationRow, product_price_range: { min_price, max_price } | null } (or { noop: true, product_id, variation_id, variation } when nothing changes). VariationRow mirrors the diviops_fc_variation_list shape — sku, item_price, compare_price, payment_type, other_info, license round-trip without a follow-up read. Unit asymmetry: write inputs (price, compare_price) are currency units (e.g. 19.00); VariationRow returns item_price + compare_price in stored cents. Error codes: invalid_input (400); not_found (404); fluentcart.unsupported_product_shape (422); fluentcart.sku_conflict (409); fluentcart.module_inactive (412); fluentcart.command_failed (500). Idempotency: conditional — identical repeat is a no-op." +
        DRY_RUN_DESC_SUFFIX,
      inputSchema: {
        product_id: z
          .number()
          .int()
          .positive()
          .describe(
            "FluentCart product ID (the post ID of the fluent_products CPT entry).",
          ),
        variation_id: z
          .number()
          .int()
          .positive()
          .describe(
            "FluentCart variation ID. Must be the product's default variation (V3 constraint).",
          ),
        price: z
          .number()
          .min(0)
          .optional()
          .describe(
            "Variation item_price in currency units (e.g. 19.00). Non-negative.",
          ),
        compare_price: z
          .number()
          .min(0)
          .optional()
          .describe(
            "Variation compare-at price in currency units. Must be ≥ price when both provided.",
          ),
        sku: z
          .string()
          .optional()
          .describe(
            "Variation SKU. Must be unique across all FluentCart variations and at most 30 characters. Empty string clears the SKU (reads back as null).",
          ),
        payment_type: z
          .enum(["onetime", "subscription"])
          .optional()
          .describe(
            "Variation payment_type. Switching to 'subscription' requires repeat_interval. Switching to 'onetime' strips subscription-only fields from other_info.",
          ),
        repeat_interval: z
          .enum([
            "yearly",
            "half_yearly",
            "quarterly",
            "monthly",
            "weekly",
            "daily",
          ])
          .optional()
          .describe(
            "Subscription billing interval. Required when switching to payment_type='subscription' unless already stored. For ADR-005 annual rows use 'yearly'.",
          ),
        times: z
          .number()
          .int()
          .min(0)
          .optional()
          .describe(
            "Number of subscription billing cycles. 0 = indefinite (default for V3 subscription rows).",
          ),
        trial_days: z
          .number()
          .int()
          .min(0)
          .max(365)
          .optional()
          .describe("Trial-period length in days (0-365)."),
        manage_setup_fee: z
          .enum(["no"])
          .optional()
          .describe(
            "Setup-fee mode. V3 only accepts 'no' — manage_setup_fee='yes' requires signup_fee + signup_fee_name which are out of scope for V3.",
          ),
        dry_run: DRY_RUN_FIELD,
      },
      annotations: { idempotentHint: false },
      _meta: { idempotent: "conditional" },
    },
    async ({
      product_id,
      variation_id,
      price,
      compare_price,
      sku,
      payment_type,
      repeat_interval,
      times,
      trial_days,
      manage_setup_fee,
      dry_run,
    }: {
      product_id: number;
      variation_id: number;
      price?: number;
      compare_price?: number;
      sku?: string;
      payment_type?: string;
      repeat_interval?: string;
      times?: number;
      trial_days?: number;
      manage_setup_fee?: string;
      dry_run?: boolean;
    }) => {
      const body: Record<string, unknown> = {};
      if (price !== undefined) body.price = price;
      if (compare_price !== undefined) body.compare_price = compare_price;
      if (sku !== undefined) body.sku = sku;
      if (payment_type !== undefined) body.payment_type = payment_type;
      if (repeat_interval !== undefined) body.repeat_interval = repeat_interval;
      if (times !== undefined) body.times = times;
      if (trial_days !== undefined) body.trial_days = trial_days;
      if (manage_setup_fee !== undefined) body.manage_setup_fee = manage_setup_fee;
      if (dry_run !== undefined) body.dry_run = dry_run;
      const result = await wp.requestEnveloped(
        `/pro/fluentcart/products/${product_id}/variations/${variation_id}/update`,
        { method: "POST", body },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(result, "diviops_fc_variation_update"),
          },
        ],
      };
    },
    { target: "fluentcart", capabilityKey: "fluentcart_variation_update" },
  );

  // diviops_fc_license_settings_get — POST /diviops/v1/pro/fluentcart/products/{id}/license-settings
  registerProTool(
    "diviops_fc_license_settings_get",
    {
      description:
        "Read the per-product FluentCart Pro license-settings projection (Pro tier; V3/V3.3; requires FluentCart Pro installed + activated). FluentCart Pro stores license settings in `ProductMeta` under meta_key='license_settings'; this tool reads that meta row and joins it against the product's variations so each variation surfaces with its current activation_limit + validity. Read-only. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload is { product_id, enabled: boolean, version, prefix, variations: [ { variation_id, title, activation_limit, validity: { unit, value } | null } ], update_file: { configured_id: string|null, resolved: DownloadRow|null }, downloads: DownloadRow[], update_file_readiness: { applicable: boolean, ready: boolean|null, problems: [ { code, message } ] } }. DownloadRow is { id, title, file_name, driver, file_size, type } — metadata only, never file paths or signed URLs. `update_file.configured_id` is the raw `global_update_file` pointer (the download-row ID FluentCart Pro's licensed-update path resolves); `resolved` is the matching download row or null. `update_file_readiness` is the updater-pointer verdict: applicable only when licensing is enabled (ready: null otherwise); problem codes are downloads_missing (no download rows at all), update_file_pointer_missing (downloads exist but no pointer persisted — fails even for single-download products because FCP Pro's signed-package-URL path resolves the pointer with no fallback), update_file_pointer_unresolved (pointer matches no download row on the product). The verdict does NOT judge whether the resolved file is the intended updater package — check resolved.file_name/title yourself (e.g. a plugin update ZIP vs a bundle/suite ZIP). The update_file/downloads/update_file_readiness fields require plugin capability `fluentcart_license_update_file_get`; older plugin builds omit them. Storage semantics: `enabled` is stored as 'yes'/'no' in FCP and projected to boolean here. `activation_limit` is null/'' (unconfigured), 0 (unlimited per FluentCart Pro License::getActivationLimit), or a positive integer. `validity.unit` is one of lifetime/day/week/month/year. Variations the product has but license_settings doesn't mention surface with `activation_limit: null` and `validity: null`. Variations license_settings mentions that no longer exist on the product are filtered out — only the live variation set is returned. Error codes: invalid_input (400) when id is not a positive integer; not_found (404) when the product does not exist; fluentcart.module_inactive (412); fluentcart.query_failed (500). Idempotency: read-only.",
      inputSchema: {
        product_id: z
          .number()
          .int()
          .positive()
          .describe(
            "FluentCart product ID (the post ID of the fluent_products CPT entry).",
          ),
      },
      annotations: { idempotentHint: true },
      _meta: { idempotent: "true" },
    },
    async ({ product_id }: { product_id: number }) => {
      const result = await wp.requestEnveloped(
        `/pro/fluentcart/products/${product_id}/license-settings`,
        { method: "POST" },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(
              result,
              "diviops_fc_license_settings_get",
            ),
          },
        ],
      };
    },
    { target: "fluentcart", capabilityKey: "fluentcart_license_settings_get" },
  );

  // diviops_fc_license_settings_update — POST /diviops/v1/pro/fluentcart/products/{id}/license-settings/update
  registerProTool(
    "diviops_fc_license_settings_update",
    {
      description:
        "Write the per-product FluentCart Pro license-settings ProductMeta row (Pro tier; V3/V3.3; requires FluentCart Pro installed + activated). Authors `enabled`, `version`, `prefix`, per-variation `activation_limit` + `validity`, and (V3.3) the `global_update_file` updater pointer — the storage shape FluentCart Pro reads via LicenseGenerationHandler when an order is placed and via the licensed-update path when an installed plugin checks for updates. Still out of scope: `wp` readme/banner/icon config, download-asset CRUD/upload, and the license-activation API; the `wp` field is preserved when present but not authored. Refuses bundle products with `fluentcart.unsupported_product_shape` (HTTP 422). Inputs (all optional except product_id; partial updates supported): `enabled` (boolean — projected to FCP's 'yes'/'no' on write), `version` (required when enabling; max 50 chars), `prefix` (max 20 chars), `variations` (array of { variation_id (required), activation_limit (integer ≥ 0 or null; 0 = unlimited per FluentCart Pro License::getActivationLimit), validity (optional { unit: lifetime/day/week/month/year, value: positive integer } or null to auto-derive) }), `global_update_file` (positive integer download-row ID to point licensed updates at, or null to clear the pointer; the ID must match one of the product's download rows — read diviops_fc_license_settings_get's `downloads` array for valid IDs — and is rejected with invalid_input otherwise, never silently coerced; stored as a string to match FluentCart Pro's UI-authored shape; requires plugin capability `fluentcart_license_update_file_set`, otherwise this tool returns capability_missing without calling the plugin). Omitting `global_update_file` preserves the stored pointer — unlike FluentCart Pro's own UI save path, partial updates here never wipe it. When `validity` is omitted on a variation, the validity is derived from the variation's payment_type: subscription+yearly → { unit: 'year', value: 1 }; onetime → { unit: 'lifetime', value: 1 }. When `enabled: true` and the product carries variations, every variation must end up with a non-empty validity.unit. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; apply-mode success payload is { product_id, changed_fields[], license_settings: <same shape as diviops_fc_license_settings_get> } (or { noop: true, product_id, license_settings } on no-op). Error codes: invalid_input (400) when any field violates the constraints (unknown variation_id, negative activation_limit, missing version when enabling, missing validity.unit when enabling, bad enum value, global_update_file not matching a download row); not_found (404) when the product does not exist; fluentcart.unsupported_product_shape (422) on bundle products; fluentcart.module_inactive (412); fluentcart.command_failed (500); capability_missing when global_update_file is passed against a plugin build without the capability. Idempotency: conditional — identical repeat is a no-op." +
        DRY_RUN_DESC_SUFFIX,
      inputSchema: {
        product_id: z
          .number()
          .int()
          .positive()
          .describe(
            "FluentCart product ID (the post ID of the fluent_products CPT entry).",
          ),
        enabled: z
          .boolean()
          .optional()
          .describe(
            "Toggle FCP license-settings enablement. Stored as 'yes'/'no' in FCP. When true, version is required (use '1.0.0-beta' for the dogfood catalog).",
          ),
        version: z
          .string()
          .max(50)
          .optional()
          .describe(
            "License-settings version string (max 50 chars). Required when enabling. Recommended: '1.0.0-beta' for a beta-cohort catalog.",
          ),
        prefix: z
          .string()
          .max(20)
          .optional()
          .describe(
            "License-key prefix (max 20 chars). Recommended: 'DOP' for DiviOps products.",
          ),
        variations: z
          .array(
            z.object({
              variation_id: z
                .number()
                .int()
                .positive()
                .describe("Target variation ID; must belong to this product."),
              activation_limit: z
                .number()
                .int()
                .min(0)
                .nullable()
                .optional()
                .describe(
                  "Activation limit. 0 = unlimited (per FluentCart Pro License::getActivationLimit). null clears the configured limit.",
                ),
              validity: z
                .object({
                  unit: z.enum(["lifetime", "day", "week", "month", "year"]),
                  value: z.number().int().positive(),
                })
                .nullable()
                .optional()
                .describe(
                  "Validity period. When omitted, derived from the variation's payment_type (subscription+yearly → year/1; onetime → lifetime/1). When null, force-rederived from current variation state.",
                ),
            }),
          )
          .optional()
          .describe(
            "Per-variation license configuration. Each entry's variation_id must belong to the product. Omitted variations preserve their existing license_settings.",
          ),
        global_update_file: z
          .number()
          .int()
          .positive()
          .nullable()
          .optional()
          .describe(
            "Download-row ID to point licensed updates at (must match one of the product's download rows — see diviops_fc_license_settings_get's downloads array), or null to clear the pointer. Omit to preserve the stored pointer.",
          ),
        dry_run: DRY_RUN_FIELD,
      },
      annotations: { idempotentHint: false },
      _meta: { idempotent: "conditional" },
    },
    async ({
      product_id,
      enabled,
      version,
      prefix,
      variations,
      global_update_file,
      dry_run,
    }: {
      product_id: number;
      enabled?: boolean;
      version?: string;
      prefix?: string;
      variations?: Array<{
        variation_id: number;
        activation_limit?: number | null;
        validity?: { unit: string; value: number } | null;
      }>;
      global_update_file?: number | null;
      dry_run?: boolean;
    }) => {
      // Param-level capability gate (mirrors schema_get_module's dump_all
      // gate): the tool itself registers against older plugins via the V3
      // fluentcart_license_settings_update key, but those builds silently
      // ignore an undeclared global_update_file param — surface a typed
      // envelope error instead of a silent no-op.
      if (
        global_update_file !== undefined &&
        handshakeState.kind === "ok" &&
        !handshakeState.capabilities["fluentcart_license_update_file_set"]
      ) {
        const err = new MissingCapabilityError(
          "fluentcart_license_update_file_set",
          handshakeState.pluginVersion,
        );
        const failure: DiviopsResponse<never> = {
          ok: false,
          error: {
            code: ErrorCodes.CAPABILITY_MISSING,
            message: err.message,
            hint: "Update the diviops-agent-pro WP plugin to a version that supports authoring the global_update_file pointer, or omit global_update_file from this call.",
          },
        };
        return {
          content: [
            {
              type: "text" as const,
              text: serializeEnvelope(
                failure,
                "diviops_fc_license_settings_update",
              ),
            },
          ],
        };
      }
      const body: Record<string, unknown> = {};
      if (enabled !== undefined) body.enabled = enabled;
      if (version !== undefined) body.version = version;
      if (prefix !== undefined) body.prefix = prefix;
      if (variations !== undefined) body.variations = variations;
      if (global_update_file !== undefined)
        body.global_update_file = global_update_file;
      if (dry_run !== undefined) body.dry_run = dry_run;
      const result = await wp.requestEnveloped(
        `/pro/fluentcart/products/${product_id}/license-settings/update`,
        { method: "POST", body },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(
              result,
              "diviops_fc_license_settings_update",
            ),
          },
        ],
      };
    },
    {
      target: "fluentcart",
      capabilityKey: "fluentcart_license_settings_update",
    },
  );

  // ── V3.4 — downloadable artifact metadata + license changelog ──────
  //
  // Store-deployment helper surface. This intentionally complements
  // FluentCart's native MCP by focusing on DiviOps-specific launch
  // readiness: metadata-only download rows, attaching an already-present
  // server-side ZIP, and the FCP Pro updater changelog field. No binary
  // upload and no signed buyer URLs.

  // diviops_fc_download_list — POST /diviops/v1/pro/fluentcart/products/{id}/downloads
  registerProTool(
    "diviops_fc_download_list",
    {
      description:
        "List FluentCart downloadable-file rows for a product (Pro tier; V3.4; requires FluentCart Pro installed + activated). Read-only metadata surface for store artifact deployment. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload is { product_id, downloads: DownloadRow[] }. DownloadRow is { id, title, file_name, driver, file_size, type, variation_ids, is_update_file } — metadata only, never file_path, file_url, download_identifier, signed URLs, or buyer URLs. `is_update_file` is derived from the product's license_settings.global_update_file pointer when present. Use this before `diviops_fc_license_settings_update` so the updater pointer can be set to the plugin ZIP row, not the suite ZIP row. Error codes: invalid_input (400), not_found (404), fluentcart.module_inactive (412), fluentcart.query_failed (500). Idempotency: read-only.",
      inputSchema: {
        product_id: z
          .number()
          .int()
          .positive()
          .describe(
            "FluentCart product ID (the post ID of the fluent_products CPT entry).",
          ),
      },
      annotations: { idempotentHint: true },
      _meta: { idempotent: "true" },
    },
    async ({ product_id }: { product_id: number }) => {
      const result = await wp.requestEnveloped(
        `/pro/fluentcart/products/${product_id}/downloads`,
        { method: "POST" },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(result, "diviops_fc_download_list"),
          },
        ],
      };
    },
    { target: "fluentcart", capabilityKey: "fluentcart_download_list" },
  );

  // diviops_fc_download_attach — POST /diviops/v1/pro/fluentcart/products/{id}/downloads/attach
  registerProTool(
    "diviops_fc_download_attach",
    {
      description:
        "Attach an already-present server-side ZIP as a FluentCart downloadable-file row (Pro tier; V3.4; requires FluentCart Pro installed + activated). This is NOT a binary upload tool: `file_path` must resolve to a readable .zip on the WordPress server under ABSPATH or the uploads directory. The response never exposes file paths, file URLs, download identifiers, signed URLs, or buyer URLs. Required: product_id, file_path. Optional: file_name (defaults to basename(file_path), must end in .zip), title (defaults to filename without .zip), variation_ids (must belong to this product; empty means all variants), expected_sha1, expected_size, allow_duplicate (default false), dry_run. Duplicate detection rejects an existing row with the same file_name or title unless allow_duplicate:true is passed. Expected hash/size let operators verify the server-side file matches the locally built artifact before creating the row. Apply-mode success payload is { product_id, created: DownloadRow, downloads: DownloadRow[] } with DownloadRow metadata only. This tool only creates the download row and enables manage_downloadable; it does NOT set license_settings.global_update_file — follow with diviops_fc_license_settings_update using the created row ID. Error codes: invalid_input (400), not_found (404), fluentcart.download_duplicate (409), fluentcart.module_inactive (412), fluentcart.command_failed (500). Idempotency: false; repeat calls are rejected by duplicate detection unless allow_duplicate:true." +
        DRY_RUN_DESC_SUFFIX,
      inputSchema: {
        product_id: z
          .number()
          .int()
          .positive()
          .describe(
            "FluentCart product ID (the post ID of the fluent_products CPT entry).",
          ),
        file_path: z
          .string()
          .min(1)
          .describe(
            "Server-side .zip path under ABSPATH or wp_upload_dir().basedir. Relative paths resolve under ABSPATH. No local-client upload occurs.",
          ),
        file_name: z
          .string()
          .min(1)
          .optional()
          .describe(
            "Download filename shown to buyers/admins. Defaults to basename(file_path). Must end in .zip.",
          ),
        title: z
          .string()
          .min(1)
          .optional()
          .describe(
            "FluentCart download title. Defaults to file_name without the .zip suffix.",
          ),
        variation_ids: z
          .array(z.number().int().positive())
          .optional()
          .describe(
            "Optional product variation IDs this download applies to. Omit or pass [] for all variants.",
          ),
        expected_sha1: z
          .string()
          .regex(/^[a-fA-F0-9]{40}$/)
          .optional()
          .describe(
            "Optional SHA1 of the expected server-side file. Mismatch aborts before mutation.",
          ),
        expected_size: z
          .number()
          .int()
          .min(0)
          .optional()
          .describe(
            "Optional byte size of the expected server-side file. Mismatch aborts before mutation.",
          ),
        allow_duplicate: z
          .boolean()
          .optional()
          .describe(
            "Default false. When false, an existing row with the same file_name or title returns fluentcart.download_duplicate.",
          ),
        dry_run: DRY_RUN_FIELD,
      },
      annotations: { idempotentHint: false },
      _meta: { idempotent: "false" },
    },
    async ({
      product_id,
      file_path,
      file_name,
      title,
      variation_ids,
      expected_sha1,
      expected_size,
      allow_duplicate,
      dry_run,
    }: {
      product_id: number;
      file_path: string;
      file_name?: string;
      title?: string;
      variation_ids?: number[];
      expected_sha1?: string;
      expected_size?: number;
      allow_duplicate?: boolean;
      dry_run?: boolean;
    }) => {
      const body: Record<string, unknown> = { file_path };
      if (file_name !== undefined) body.file_name = file_name;
      if (title !== undefined) body.title = title;
      if (variation_ids !== undefined) body.variation_ids = variation_ids;
      if (expected_sha1 !== undefined) body.expected_sha1 = expected_sha1;
      if (expected_size !== undefined) body.expected_size = expected_size;
      if (allow_duplicate !== undefined) body.allow_duplicate = allow_duplicate;
      if (dry_run !== undefined) body.dry_run = dry_run;
      const result = await wp.requestEnveloped(
        `/pro/fluentcart/products/${product_id}/downloads/attach`,
        { method: "POST", body },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(result, "diviops_fc_download_attach"),
          },
        ],
      };
    },
    { target: "fluentcart", capabilityKey: "fluentcart_download_attach" },
  );

  // diviops_fc_license_changelog_get — POST /diviops/v1/pro/fluentcart/products/{id}/license-changelog
  registerProTool(
    "diviops_fc_license_changelog_get",
    {
      description:
        "Read the FluentCart Pro software-license changelog HTML for a product (Pro tier; V3.4; requires FluentCart Pro installed + activated). This reads ProductMeta meta_key='_fluent_sl_changelog', the field FluentCart Pro shows in Product → License Settings → Changelog Description. Read-only. Returns the standardized envelope { ok, data?, error }; success payload is { product_id, changelog_html, is_empty, storage_meta_key }. Error codes: invalid_input (400), not_found (404), fluentcart.module_inactive (412), fluentcart.query_failed (500). Idempotency: read-only.",
      inputSchema: {
        product_id: z
          .number()
          .int()
          .positive()
          .describe(
            "FluentCart product ID (the post ID of the fluent_products CPT entry).",
          ),
      },
      annotations: { idempotentHint: true },
      _meta: { idempotent: "true" },
    },
    async ({ product_id }: { product_id: number }) => {
      const result = await wp.requestEnveloped(
        `/pro/fluentcart/products/${product_id}/license-changelog`,
        { method: "POST" },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(
              result,
              "diviops_fc_license_changelog_get",
            ),
          },
        ],
      };
    },
    {
      target: "fluentcart",
      capabilityKey: "fluentcart_license_changelog_get",
    },
  );

  // diviops_fc_license_changelog_update — POST /diviops/v1/pro/fluentcart/products/{id}/license-changelog/update
  registerProTool(
    "diviops_fc_license_changelog_update",
    {
      description:
        "Write the FluentCart Pro software-license changelog HTML for a product (Pro tier; V3.4; requires FluentCart Pro installed + activated). This writes ProductMeta meta_key='_fluent_sl_changelog', the field FluentCart Pro shows in Product → License Settings → Changelog Description. Input is sanitized by WordPress wp_kses_post on the plugin side. Supports dry_run and no-op detection. Success payload is { product_id, changed, changelog_html } or { noop:true, dry_run, product_id, changelog_html }. Error codes: invalid_input (400), not_found (404), fluentcart.module_inactive (412), fluentcart.command_failed (500). Idempotency: conditional — identical repeat is a no-op." +
        DRY_RUN_DESC_SUFFIX,
      inputSchema: {
        product_id: z
          .number()
          .int()
          .positive()
          .describe(
            "FluentCart product ID (the post ID of the fluent_products CPT entry).",
          ),
        changelog_html: z
          .string()
          .describe(
            "Buyer/update-facing changelog HTML. Sanitized with wp_kses_post before storage.",
          ),
        dry_run: DRY_RUN_FIELD,
      },
      annotations: { idempotentHint: false },
      _meta: { idempotent: "conditional" },
    },
    async ({
      product_id,
      changelog_html,
      dry_run,
    }: {
      product_id: number;
      changelog_html: string;
      dry_run?: boolean;
    }) => {
      const body: Record<string, unknown> = { changelog_html };
      if (dry_run !== undefined) body.dry_run = dry_run;
      const result = await wp.requestEnveloped(
        `/pro/fluentcart/products/${product_id}/license-changelog/update`,
        { method: "POST", body },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(
              result,
              "diviops_fc_license_changelog_update",
            ),
          },
        ],
      };
    },
    {
      target: "fluentcart",
      capabilityKey: "fluentcart_license_changelog_update",
    },
  );

  // ── V3.1 — order/license/activation read + guarded mark-paid ───────
  //
  // FluentCart commerce-artifact readback surface plus a single
  // mutating tool: a guarded offline mark-paid that mirrors FCP's
  // OrderController::markAsPaid. Lifts the local checkout/license
  // smoke off of eval-file PHP probes.

  // diviops_fc_order_list — POST /diviops/v1/pro/fluentcart/orders
  registerProTool(
    "diviops_fc_order_list",
    {
      description:
        "List FluentCart orders for commerce dogfooding / smoke baselines (Pro tier; V3.1; requires FluentCart installed + activated). Returns a paginated summary with order identity (id, status, payment_status), gateway info (payment_method + payment_method_title, mode), totals (currency, total_amount, total_paid), fulfillment_type, type (payment/subscription), customer (customer_id + customer_email), item_count, license_count, and timestamps (created_at, updated_at, completed_at). Filterable by status, payment_status, payment_method, product_id, customer_email, and mode (test/live). Read-only. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; the success payload is { orders: OrderSummary[], pagination: { page, per_page, total, total_pages }, filters: { ... } }. Error codes: invalid_input (HTTP 400) when status/payment_status/mode is out of range; fluentcart.module_inactive (HTTP 412); fluentcart.query_failed (HTTP 500). Use this alongside diviops_fc_order_get / diviops_fc_license_list to verify a smoke run without raw SQL probes.",
      inputSchema: {
        page: z
          .number()
          .int()
          .positive()
          .optional()
          .default(1)
          .describe("Page number, 1-indexed. Default 1."),
        per_page: z
          .number()
          .int()
          .positive()
          .optional()
          .default(20)
          .describe("Page size. Default 20, clamped to a max of 100."),
        status: z
          .enum([
            "on-hold",
            "pending",
            "processing",
            "completed",
            "canceled",
            "refunded",
            "failed",
            "draft",
          ])
          .optional()
          .describe(
            "Order status filter (fct_orders.status). Match exact values such as 'completed' or 'on-hold'.",
          ),
        payment_status: z
          .enum([
            "pending",
            "paid",
            "partially_paid",
            "partially_refunded",
            "refunded",
            "failed",
          ])
          .optional()
          .describe("Payment status filter (fct_orders.payment_status)."),
        payment_method: z
          .string()
          .optional()
          .describe(
            "Exact-match payment_method (e.g. 'offline_payment', 'stripe'). Case-sensitive — FluentCart stores gateway slugs verbatim.",
          ),
        product_id: z
          .number()
          .int()
          .positive()
          .optional()
          .describe(
            "FluentCart product ID (post_id of the fluent_products CPT entry). Filters to orders with at least one matching order item.",
          ),
        customer_email: z
          .string()
          .optional()
          .describe(
            "Filter to orders whose customer record has this email (exact match).",
          ),
        mode: z
          .enum(["test", "live"])
          .optional()
          .describe(
            "Filter to test-mode or live-mode orders. Useful for smoke runs to isolate the test gateway corpus.",
          ),
      },
      annotations: { idempotentHint: true },
      _meta: { idempotent: "true" },
    },
    async ({
      page,
      per_page,
      status,
      payment_status,
      payment_method,
      product_id,
      customer_email,
      mode,
    }: {
      page?: number;
      per_page?: number;
      status?: string;
      payment_status?: string;
      payment_method?: string;
      product_id?: number;
      customer_email?: string;
      mode?: string;
    }) => {
      const body: Record<string, unknown> = {};
      if (page !== undefined) body.page = page;
      if (per_page !== undefined) body.per_page = per_page;
      if (status !== undefined) body.status = status;
      if (payment_status !== undefined) body.payment_status = payment_status;
      if (payment_method !== undefined) body.payment_method = payment_method;
      if (product_id !== undefined) body.product_id = product_id;
      if (customer_email !== undefined) body.customer_email = customer_email;
      if (mode !== undefined) body.mode = mode;
      const result = await wp.requestEnveloped("/pro/fluentcart/orders", {
        method: "POST",
        body,
      });
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(result, "diviops_fc_order_list"),
          },
        ],
      };
    },
    { target: "fluentcart", capabilityKey: "fluentcart_order_list" },
  );

  // diviops_fc_order_get — POST /diviops/v1/pro/fluentcart/orders/{id}
  registerProTool(
    "diviops_fc_order_get",
    {
      description:
        "Fetch a single FluentCart order with line items, transactions, and related license IDs (Pro tier; V3.1; requires FluentCart installed + activated). Read-only. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; the success payload is { order: OrderSummary, items: OrderItem[], transactions: Transaction[], license_ids: number[] }. OrderItem includes id, post_id (product CPT post_id), object_id (variation_id), title, quantity, unit_price, line_total, payment_type, fulfillment_type. Transaction includes id, status, payment_method, payment_mode, transaction_type, total, currency, created_at. license_ids carries the IDs of any fct_licenses rows tied to this order (use diviops_fc_license_get to fetch each row's redacted shape). Does NOT expose payment credentials, gateway secrets, or full license keys. Error codes: invalid_input (HTTP 400) when id is not a positive integer; not_found (HTTP 404) when no order matches; fluentcart.module_inactive (HTTP 412); fluentcart.query_failed (HTTP 500).",
      inputSchema: {
        id: z
          .number()
          .int()
          .positive()
          .describe(
            "FluentCart order ID (fct_orders.id; for the local smoke runbook this is the order receipt anchor).",
          ),
      },
      annotations: { idempotentHint: true },
      _meta: { idempotent: "true" },
    },
    async ({ id }: { id: number }) => {
      const result = await wp.requestEnveloped(
        `/pro/fluentcart/orders/${id}`,
        { method: "POST" },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(result, "diviops_fc_order_get"),
          },
        ],
      };
    },
    { target: "fluentcart", capabilityKey: "fluentcart_order_get" },
  );

  // diviops_fc_order_mark_paid — POST /diviops/v1/pro/fluentcart/orders/{id}/mark-paid
  registerProTool(
    "diviops_fc_order_mark_paid",
    {
      description:
        "Guarded local/offline mark-paid for a FluentCart order (Pro tier; V3.1; requires FluentCart installed + activated). Mirrors the canonical `FluentCart\\App\\Http\\Controllers\\OrderController::markAsPaid` sequence — updates the pending offline transaction, flips payment_status to 'paid' (unless partially_refunded), flips order status to 'processing' (and 'completed' for digital fulfillment), then dispatches `OrderPaid` (which fires the `fluent_cart/order_paid` WordPress action, the listener `LicenseGenerationHandler::maybeGenerateLicensesOnPurchaseSuccess` hangs off of) plus `OrderStatusUpdated`. Does NOT directly insert license rows — license generation is a side effect of the dispatched event, exactly like the FCP admin path. Refuses non-offline gateways (anything other than `payment_method='offline_payment'`) with `fluentcart.unsupported_payment_method` (HTTP 422) — this slice is for local/test/COD smokes only. Refuses canceled orders with `fluentcart.order_canceled` (HTTP 422). Already-paid orders are repeat-safe: returns `ok:true` with `data.already_paid: true` (no second event fires). **dry_run defaults to TRUE** for safety; apply requires `dry_run:false` PLUS `confirm_order_id` + `confirm_payment_method` + `confirm_due_amount` matching current state. Dry-run payload: { dry_run:true, plan: { summary, changes[] (payment_status, status, total_paid, transaction), warnings[] }, events: ['fluent_cart/order_paid', 'fluent_cart/order_status_updated'], order_id, licenses_before }. Apply payload: { order: OrderSummary (post-mutation), transaction: TransactionSummary, events_fired: string[], licenses_before, licenses_after, licenses_created, license_ids: number[], licenses: LicenseRedactedSummary[] (no full keys). Error codes: invalid_input (400) when id/confirmation fields are wrong; not_found (404); fluentcart.order_canceled (422); fluentcart.unsupported_payment_method (422); fluentcart.module_inactive (412); fluentcart.command_failed (500). Idempotency: repeat-safe via already_paid sentinel." +
        " Pass dry_run: false plus the confirm_* fields to apply.",
      inputSchema: {
        id: z
          .number()
          .int()
          .positive()
          .describe(
            "FluentCart order ID (fct_orders.id) to mark paid.",
          ),
        dry_run: z
          .boolean()
          .optional()
          .default(true)
          .describe(
            "When true (default), return the change plan without mutating state. Apply requires explicit dry_run: false + the confirm_* fields.",
          ),
        confirm_order_id: z
          .number()
          .int()
          .positive()
          .optional()
          .describe(
            "Apply-mode confirmation: must equal `id`. Prevents accidentally marking the wrong order paid.",
          ),
        confirm_payment_method: z
          .string()
          .optional()
          .describe(
            "Apply-mode confirmation: must equal the order's current payment_method (e.g. 'offline_payment').",
          ),
        confirm_due_amount: z
          .number()
          .int()
          .min(0)
          .optional()
          .describe(
            "Apply-mode confirmation: must equal (total_amount - total_paid) in stored units (cents). Inspect via diviops_fc_order_get.",
          ),
        mark_paid_note: z
          .string()
          .optional()
          .describe(
            "Optional sanitize_text_field note to attach to the order's `note` column. Mirrors the admin mark-paid form field.",
          ),
      },
      annotations: { idempotentHint: false },
      _meta: { idempotent: "conditional" },
    },
    async ({
      id,
      dry_run,
      confirm_order_id,
      confirm_payment_method,
      confirm_due_amount,
      mark_paid_note,
    }: {
      id: number;
      dry_run?: boolean;
      confirm_order_id?: number;
      confirm_payment_method?: string;
      confirm_due_amount?: number;
      mark_paid_note?: string;
    }) => {
      const body: Record<string, unknown> = {};
      // dry_run defaults to true at the MCP level — pass through whatever
      // the caller sent so the plugin can apply the same default.
      if (dry_run !== undefined) body.dry_run = dry_run;
      else body.dry_run = true;
      if (confirm_order_id !== undefined) body.confirm_order_id = confirm_order_id;
      if (confirm_payment_method !== undefined)
        body.confirm_payment_method = confirm_payment_method;
      if (confirm_due_amount !== undefined)
        body.confirm_due_amount = confirm_due_amount;
      if (mark_paid_note !== undefined) body.mark_paid_note = mark_paid_note;
      const result = await wp.requestEnveloped(
        `/pro/fluentcart/orders/${id}/mark-paid`,
        { method: "POST", body },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(result, "diviops_fc_order_mark_paid"),
          },
        ],
      };
    },
    { target: "fluentcart", capabilityKey: "fluentcart_order_mark_paid" },
  );

  // diviops_fc_license_list — POST /diviops/v1/pro/fluentcart/licenses
  registerProTool(
    "diviops_fc_license_list",
    {
      description:
        "List FluentCart Pro licenses (Pro tier; V3.1; requires FluentCart Pro + Licensing module). Read-only. Filterable by product_id, variation_id, order_id, customer_id, status (active/inactive/disabled/expired/in_trial). License keys are NEVER returned in full — every row carries `redacted_key` only (first 4 + last 4 with ellipsis); use diviops_fc_license_get with explicit secret-handling opt-in if a full key is required for an authorized integration test. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; the success payload is { licenses: LicenseSummary[], pagination: { page, per_page, total, total_pages }, filters: { ... } }. LicenseSummary includes id, status, product_id, variation_id, order_id, customer_id, subscription_id, limit (0 = unlimited per License::getActivationLimit), activation_count, expiration_date, created_at, updated_at, redacted_key. Error codes: invalid_input (HTTP 400) when status is out of range; fluentcart.module_inactive (HTTP 412); fluentcart.licensing_unavailable (HTTP 412) when FluentCart Pro's Licensing module is absent; fluentcart.query_failed (HTTP 500).",
      inputSchema: {
        page: z
          .number()
          .int()
          .positive()
          .optional()
          .default(1)
          .describe("Page number, 1-indexed. Default 1."),
        per_page: z
          .number()
          .int()
          .positive()
          .optional()
          .default(20)
          .describe("Page size. Default 20, clamped to a max of 100."),
        product_id: z
          .number()
          .int()
          .positive()
          .optional()
          .describe("Filter to licenses for this FluentCart product ID."),
        variation_id: z
          .number()
          .int()
          .positive()
          .optional()
          .describe("Filter to licenses for this FluentCart variation ID."),
        order_id: z
          .number()
          .int()
          .positive()
          .optional()
          .describe("Filter to licenses issued by this order ID."),
        customer_id: z
          .number()
          .int()
          .positive()
          .optional()
          .describe("Filter to licenses owned by this customer ID."),
        status: z
          .enum(["active", "inactive", "disabled", "expired", "in_trial"])
          .optional()
          .describe(
            "License status (fct_licenses.status). 'active' is the healthy default.",
          ),
      },
      annotations: { idempotentHint: true },
      _meta: { idempotent: "true" },
    },
    async ({
      page,
      per_page,
      product_id,
      variation_id,
      order_id,
      customer_id,
      status,
    }: {
      page?: number;
      per_page?: number;
      product_id?: number;
      variation_id?: number;
      order_id?: number;
      customer_id?: number;
      status?: string;
    }) => {
      const body: Record<string, unknown> = {};
      if (page !== undefined) body.page = page;
      if (per_page !== undefined) body.per_page = per_page;
      if (product_id !== undefined) body.product_id = product_id;
      if (variation_id !== undefined) body.variation_id = variation_id;
      if (order_id !== undefined) body.order_id = order_id;
      if (customer_id !== undefined) body.customer_id = customer_id;
      if (status !== undefined) body.status = status;
      const result = await wp.requestEnveloped("/pro/fluentcart/licenses", {
        method: "POST",
        body,
      });
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(result, "diviops_fc_license_list"),
          },
        ],
      };
    },
    { target: "fluentcart", capabilityKey: "fluentcart_license_list" },
  );

  // diviops_fc_license_get — POST /diviops/v1/pro/fluentcart/licenses/{id}
  registerProTool(
    "diviops_fc_license_get",
    {
      description:
        "Fetch a single FluentCart Pro license by ID (Pro tier; V3.1; requires FluentCart Pro + Licensing module). Read-only by default. The default response redacts the license key to `redacted_key` only (first 4 + last 4 with ellipsis). To surface the full key, pass BOTH `include_license_key: true` AND `confirm_secret_handling: true` — the response then carries `license.license_key` plus `_meta.contains_secret: true` naming the secret field. **Full license keys must never be pasted into PRs, issues, Slack, or any external surface.** Returns the standardized envelope { ok, data?, error: { code, message, hint? } }. Success payload: { license: { id, status, product_id, variation_id, order_id, customer_id, subscription_id, limit, activation_count, expiration_date, created_at, updated_at, redacted_key, license_key? } }. limit semantics: 0 = unlimited per License::getActivationLimit; positive integers are the actual activation cap. Error codes: invalid_input (HTTP 400) when id is non-positive or include_license_key was passed without confirm_secret_handling; not_found (HTTP 404); fluentcart.module_inactive (HTTP 412); fluentcart.licensing_unavailable (HTTP 412); fluentcart.query_failed (HTTP 500).",
      inputSchema: {
        id: z
          .number()
          .int()
          .positive()
          .describe("FluentCart license ID (fct_licenses.id)."),
        include_license_key: z
          .boolean()
          .optional()
          .describe(
            "Opt-in: include the full unredacted license key in the response. Requires confirm_secret_handling: true. Default: redacted-only.",
          ),
        confirm_secret_handling: z
          .boolean()
          .optional()
          .describe(
            "Required alongside include_license_key: true. Acknowledges that full license keys must not be pasted into reports, PRs, issues, or external chat surfaces.",
          ),
      },
      annotations: { idempotentHint: true },
      _meta: { idempotent: "true" },
    },
    async ({
      id,
      include_license_key,
      confirm_secret_handling,
    }: {
      id: number;
      include_license_key?: boolean;
      confirm_secret_handling?: boolean;
    }) => {
      const body: Record<string, unknown> = {};
      if (include_license_key !== undefined)
        body.include_license_key = include_license_key;
      if (confirm_secret_handling !== undefined)
        body.confirm_secret_handling = confirm_secret_handling;
      const result = await wp.requestEnveloped(
        `/pro/fluentcart/licenses/${id}`,
        { method: "POST", body },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(result, "diviops_fc_license_get"),
          },
        ],
      };
    },
    { target: "fluentcart", capabilityKey: "fluentcart_license_get" },
  );

  // diviops_fc_license_activations_list — POST /diviops/v1/pro/fluentcart/licenses/{id}/activations
  registerProTool(
    "diviops_fc_license_activations_list",
    {
      description:
        "List a FluentCart license's activation rows (Pro tier; V3.1; requires FluentCart Pro + Licensing module). Read-only. Returns one row per `fct_license_activations` entry for the license, including the joined `fct_license_sites.site_url` (the NORMALIZED form — scheme + trailing slash + `www.` prefix stripped per LicenseHelper::sanitizeSiteUrl — not the raw URL the consumer submitted). Filterable by status (active/inactive/deactivated). License keys are NEVER returned by this endpoint. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload: { license_id, activations: Activation[], count, filters: { status } }. Activation row: id, license_id, site_id, site_url, status, is_local, product_id, variation_id, activation_method, last_update_date, last_update_version, created_at, updated_at. Error codes: invalid_input (HTTP 400); not_found (HTTP 404) when the license doesn't exist; fluentcart.module_inactive (HTTP 412); fluentcart.licensing_unavailable (HTTP 412); fluentcart.query_failed (HTTP 500). Useful for smoke verification (count + site_url presence) and for the activation-cap test described in the diviops-fluentcart skill.",
      inputSchema: {
        license_id: z
          .number()
          .int()
          .positive()
          .describe(
            "FluentCart license ID whose activation rows to list (fct_licenses.id).",
          ),
        status: z
          .enum(["active", "inactive", "deactivated"])
          .optional()
          .describe(
            "Activation row status filter (fct_license_activations.status).",
          ),
      },
      annotations: { idempotentHint: true },
      _meta: { idempotent: "true" },
    },
    async ({
      license_id,
      status,
    }: {
      license_id: number;
      status?: string;
    }) => {
      const body: Record<string, unknown> = {};
      if (status !== undefined) body.status = status;
      const result = await wp.requestEnveloped(
        `/pro/fluentcart/licenses/${license_id}/activations`,
        { method: "POST", body },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(
              result,
              "diviops_fc_license_activations_list",
            ),
          },
        ],
      };
    },
    {
      target: "fluentcart",
      capabilityKey: "fluentcart_license_activations_list",
    },
  );

  // ── V3.2 — checkout readiness / status ────────────────────────────
  //
  // Pre-check surface so smoke runners can answer "is this site ready
  // to checkout?" without admin UI, raw SQL, or eval-file probes. All
  // three tools are read-only.

  // diviops_fc_status — POST /diviops/v1/pro/fluentcart/status
  registerProTool(
    "diviops_fc_status",
    {
      description:
        "Inspect FluentCart checkout/license readiness (Pro tier; V3.2). Read-only. Returns a structured snapshot covering: site (wp_url, host, is_local_hint, wp_version), plugins (fluent_cart, fluent_cart_pro, diviops_agent_pro — { active, version } each), store (order_mode, currency, currency_symbol, currency_position, store_country, store_state, checkout/cart/receipt/shop page IDs), modules (license.active, stock_management.active+advanced_inventory), tables (core / licensing / advanced_inventory groups with count/expected/ok and gated_by hints — `ok: null` means the group is optional and module is off), gateways (registered_count, enabled_count, enabled_methods, registered_methods, local_safe_methods — slug-only summary; use diviops_fc_gateway_list/get for details), counts (orders, licenses, license_activations), and readiness (verdict: green|yellow|red, checkout_writable, license_writable, local_no_money_smoke_ready, public_launch_ready, warnings[], blockers[]). Distinguishes \"fluentcart inactive\" from \"core schema missing\" from \"license module on but tables missing\" — the same gaps that drove earlier local checkout/license smokes into WP-CLI and eval-file probes. No secrets are returned at any layer; gateway settings are summarized through diviops_fc_gateway_* tools. Pass include_table_lists=true to receive the full present[]/missing[] table arrays (default: count/expected/ok only, to keep the response compact). Returns the standardized envelope { ok, data?, error: { code, message, hint? } }. Error codes: fluentcart.module_inactive (412); fluentcart.command_failed (500).",
      inputSchema: {
        include_table_lists: z
          .boolean()
          .optional()
          .default(false)
          .describe(
            "When true, include the full present[] and missing[] arrays in each `tables.*` group. Default false to keep the response compact.",
          ),
      },
      annotations: { idempotentHint: true },
      _meta: { idempotent: "true" },
    },
    async ({
      include_table_lists,
    }: {
      include_table_lists?: boolean;
    }) => {
      const body: Record<string, unknown> = {};
      if (include_table_lists !== undefined)
        body.include_table_lists = include_table_lists;
      const result = await wp.requestEnveloped(
        "/pro/fluentcart/status",
        { method: "POST", body },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(result, "diviops_fc_status"),
          },
        ],
      };
    },
    { target: "fluentcart", capabilityKey: "fluentcart_status_get" },
  );

  // diviops_fc_gateway_list — POST /diviops/v1/pro/fluentcart/gateways
  registerProTool(
    "diviops_fc_gateway_list",
    {
      description:
        "List FluentCart registered payment gateways with secrets redacted (Pro tier; V3.2). Read-only. Returns one row per gateway registered with FluentCart's GatewayManager — each row carries `method`, `title`, `admin_title`, `label`, `route`, `enabled`, `upcoming`, `requires_pro`, `no_real_money` (true for offline/COD), `local_safe`, `webhook_required` (capability hint — reachability is NOT probed), `credentials_configured` (best-effort boolean inferred without reading credential values), `supports.subscriptions`, `supports.refund`, `settings_summary` (allowlisted non-secret fields only — `is_active`, `payment_mode`, `checkout_mode`, `checkout_label`, `checkout_logo`, `checkout_instructions`, `provider`, `define_*_keys` boolean flags, `*_is_encrypted` flags), `settings_source` (e.g. `fct_meta:fluent_cart_payment_settings_stripe`), `description`, `brand_color`, and a sanitized `meta` bag. NEVER returns: API keys, secret keys, publishable keys, webhook signing secrets, client IDs, customer IDs, vendor/seller IDs, tokens, or any value matching the credential-substring heuristic. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload: { gateways: GatewayRow[], count }. Error codes: fluentcart.module_inactive (412); fluentcart.command_failed (500). Use as the discovery call before diviops_fc_gateway_get to inspect a specific method.",
      inputSchema: {},
      annotations: { idempotentHint: true },
      _meta: { idempotent: "true" },
    },
    async () => {
      const result = await wp.requestEnveloped(
        "/pro/fluentcart/gateways",
        { method: "POST" },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(result, "diviops_fc_gateway_list"),
          },
        ],
      };
    },
    { target: "fluentcart", capabilityKey: "fluentcart_gateway_list" },
  );

  // diviops_fc_gateway_get — POST /diviops/v1/pro/fluentcart/gateways/{method}
  registerProTool(
    "diviops_fc_gateway_get",
    {
      description:
        "Fetch a single FluentCart payment gateway by method slug with secrets redacted (Pro tier; V3.2). Read-only. Same row shape as diviops_fc_gateway_list plus a `field_metadata` array describing what settings the gateway accepts (key + label + type + description from the gateway's `fields()` map) WITHOUT any stored values. `method` is one of the registered slugs from diviops_fc_gateway_list — common values: `offline_payment` (COD, local-safe, no webhook, no real money — the slug to use for local smoke runs), `stripe`, `paypal`, `paystack`, `airwallex`, `paddle` (Pro), `mollie` (Pro), `authorize_net` (Pro). NEVER returns credential values; field_metadata is metadata-only. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload: { gateway: GatewayRow }. Error codes: invalid_input (HTTP 400) when method is empty; not_found (HTTP 404) when the slug is not registered (hint: \"Call diviops_fc_gateway_list to see the registered method slugs.\"); fluentcart.module_inactive (412); fluentcart.command_failed (500). Useful for confirming the local-safe gateway is enabled before a smoke run, and for surfacing whether Paddle / Stripe / etc. is registered without exposing keys.",
      inputSchema: {
        method: z
          .string()
          .min(1)
          .describe(
            "Gateway method slug — e.g. `offline_payment`, `stripe`, `paypal`, `paddle`, `mollie`. Run diviops_fc_gateway_list first to enumerate registered slugs.",
          ),
      },
      annotations: { idempotentHint: true },
      _meta: { idempotent: "true" },
    },
    async ({ method }: { method: string }) => {
      const safe = encodeURIComponent(method);
      const result = await wp.requestEnveloped(
        `/pro/fluentcart/gateways/${safe}`,
        { method: "POST" },
      );
      return {
        content: [
          {
            type: "text" as const,
            text: serializeEnvelope(result, "diviops_fc_gateway_get"),
          },
        ],
      };
    },
    { target: "fluentcart", capabilityKey: "fluentcart_gateway_get" },
  );
}

// ── Start ────────────────────────────────────────────────────────────

async function main() {
  // Capability handshake — populate the per-tool gate map (#486)
  // and the ADR-003 / ADR-007 Pro-extension surface (target presence,
  // module activation). On Free-only sites the Pro fields are
  // normalized to `false` / `{}` by wp-client.
  try {
    const hs = await wp.handshake(SERVER_VERSION);
    handshakeState = {
      kind: "ok",
      capabilities: hs.capabilities,
      pluginVersion: hs.plugin_version,
      proVersion: hs.pro_version,
      proActive: hs.pro_active === true,
      availableTargets: hs.available_targets ?? {},
      activeModules: hs.active_modules ?? {},
    };
    const diviInfo = hs.divi.active
      ? `Divi ${hs.divi.version ?? "unknown"}`
      : "Divi not active";
    const capCount = Object.keys(hs.capabilities).filter(
      (k) => hs.capabilities[k],
    ).length;
    const proInfo = handshakeState.proActive
      ? `Pro active (${hs.pro_version ?? "version unknown"})`
      : "Pro inactive";
    console.error(
      `Handshake OK: plugin ${hs.plugin_version}, ${diviInfo}, ${proInfo}, ${capCount} capabilities`,
    );
    if (capCount === 0) {
      console.error(
        "Warning: plugin returned an empty capability map. Plugin-touching tools will fail with an upgrade hint. Update diviops-agent to ≥1.2.0.",
      );
    }
  } catch (error) {
    const msg = error instanceof Error ? error.message : String(error);
    // Plugin rejected this server as too old (HTTP 426) — fatal.
    if (msg.includes("WordPress API error (426)")) {
      console.error(`Server too old for plugin: ${msg}`);
      process.exit(1);
    }
    // Network / auth / other transient failure — mark the gate as
    // failed so plugin-touching tools fall through to their own
    // wp.request() calls and surface the real error (401, 5xx, etc.)
    // instead of being misreported as missing capabilities.
    // Prior review feedback: the pre-handshake-gate behavior surfaced the
    // actual cause; the gate must preserve that.
    handshakeState = { kind: "failed" };
    console.error(`Handshake warning (gate disabled): ${msg}`);
  }

  // Pro coverage-slice registration must run AFTER the handshake so the
  // gates (`pro_active`, `available_targets`, `active_modules`,
  // capability map) reflect the connected site's actual state. On Free
  // sites — or when the handshake failed — registerProTool's internal
  // gates short-circuit so no Pro tools register.
  registerProTools();

  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error("Divi MCP Server running on stdio");
}

main().catch((error) => {
  console.error("Fatal error:", error);
  process.exit(1);
});
