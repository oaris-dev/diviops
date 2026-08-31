import type { ToolRegistrar } from "./canonical-tool-registry.js";
import type { WPClient } from "./wp-client.js";
import {
  ErrorCodes,
  recordIdempotent,
  serializeEnvelope,
  withCode,
  wrapResponse,
} from "./envelope.js";
import type { TargetEvidence } from "./target-evidence.js";

export const LAUNCHER_HEALTH_TOOL_NAMES = [
  "diviops_meta_ping",
  "diviops_meta_info",
] as const;

export const META_PING_CONFIG = {
  description:
    "Test the connection to the WordPress site and verify the Divi MCP plugin is active. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload is { connected: true, message: \"Connected to Divi <version>\" } and connection failure surfaces as { ok: false, error: { code: 'wp_error', message } } with the underlying transport message preserved.",
  annotations: { readOnlyHint: true, idempotentHint: true },
  _meta: { idempotent: "true" },
} as const;

export const META_INFO_CONFIG = {
  description:
    "Returns DiviOps MCP server identity, server_version, license type, numeric tool_count, registered tool catalog summary, active plugin version summary, WP-CLI allowlist, and plugin handshake/slice state including Pro and FluentCart target readiness. In regular stdio mode, a successful plugin, module, capability, and registered-tool observation is the immutable startup snapshot; failed startup observation is explicitly unavailable and claims no target coverage. Reconnect or restart MCP after supported target changes. Launcher health mode continues to observe target evidence per call. Use as the S0 preflight before dogfooding or product work. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
  annotations: { readOnlyHint: true, idempotentHint: true },
  _meta: { idempotent: "true" },
} as const;

export function modelVisibleHealthResult(
  response: unknown,
  target: TargetEvidence,
  toolName: string,
) {
  const visible = {
    ...(response as Record<string, unknown>),
    diviops_target: target,
  };
  return {
    content: [
      {
        type: "text" as const,
        text: serializeEnvelope(visible as never, toolName),
      },
    ],
    structuredContent: {
      result: response,
      diviops_target: target,
    },
    _meta: { diviops_target: target },
  };
}

type ToolCancellationContext = {
  signal?: AbortSignal;
  mcpReq?: { signal?: AbortSignal };
};

export function requestAbortSignal(
  args: unknown,
  context?: ToolCancellationContext,
): AbortSignal | undefined {
  const fallback = args as ToolCancellationContext | undefined;
  return (
    context?.mcpReq?.signal ??
    context?.signal ??
    fallback?.mcpReq?.signal ??
    fallback?.signal
  );
}

export function registerLauncherHealthTools(options: {
  server: ToolRegistrar;
  wp: {
    testConnection(signal?: AbortSignal): ReturnType<WPClient["testConnection"]>;
  };
  observe: (signal?: AbortSignal) => Promise<TargetEvidence>;
  serverVersion: string;
}): void {
  const { server, wp, observe, serverVersion } = options;
  recordIdempotent("diviops_meta_ping", META_PING_CONFIG._meta);
  recordIdempotent("diviops_meta_info", META_INFO_CONFIG._meta);
  server.registerTool("diviops_meta_ping", META_PING_CONFIG, async (
    _args: unknown,
    context: ToolCancellationContext | undefined,
  ) => {
    const signal = requestAbortSignal(_args, context);
    const target = await observe(signal);
    const response = await wrapResponse(async () => {
      const ping = await wp.testConnection(signal);
      if (!ping.ok) withCode(ErrorCodes.WP_ERROR, ping.message);
      return { connected: true, message: ping.message };
    });
    return modelVisibleHealthResult(response, target, "diviops_meta_ping");
  });

  server.registerTool("diviops_meta_info", META_INFO_CONFIG, async (
    _args: unknown,
    context: ToolCancellationContext | undefined,
  ) => {
    const target = await observe(requestAbortSignal(_args, context));
    const response = await wrapResponse(async () => ({
      brand: "DiviOps",
      server: "diviops-mcp",
      server_version: serverVersion,
      version: serverVersion,
      license: "MIT",
      launcher_mode: true,
      tool_count: LAUNCHER_HEALTH_TOOL_NAMES.length,
      tools: {
        registered_total: LAUNCHER_HEALTH_TOOL_NAMES.length,
        registered_tool_names: [...LAUNCHER_HEALTH_TOOL_NAMES],
      },
      plugins: {
        diviops_agent: { active: true, version: target.plugin_version },
      },
      handshake: {
        state: "ok",
        plugin_version: target.plugin_version,
        capability_count: Object.values(target.capabilities).filter(Boolean).length,
      },
      wp_cli: false,
    }));
    return modelVisibleHealthResult(response, target, "diviops_meta_info");
  });
}
