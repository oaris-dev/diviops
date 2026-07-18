import {
  capabilityUpgradeHint,
  MissingCapabilityError,
  observedVersion,
} from "./compatibility.js";
import {
  type DiviopsResponse,
  ErrorCodes,
  serializeEnvelope,
} from "./envelope.js";

export type MissingCapabilityMcpResult = {
  content: Array<{ type: "text"; text: string }>;
};

export type MissingCapabilityEnvelopeOptions = {
  hint?: string;
  serverVersion?: string | null;
  releaseEvidence?: unknown;
};

/**
 * Convert a server-side capability gate failure into the canonical DiviOps
 * response envelope carried in MCP text content.
 */
export function missingCapabilityEnvelope(
  error: MissingCapabilityError,
  toolName: string,
  options: MissingCapabilityEnvelopeOptions = {},
): MissingCapabilityMcpResult {
  const pluginVersion = observedVersion(error.pluginVersion);
  const serverVersion = observedVersion(options.serverVersion);
  const diagnosticData: Record<string, unknown> = {
    capability: error.capability,
    plugin_component:
      error.pluginComponent === "pro" ? "diviops-agent-pro" : "diviops-agent",
    tool: toolName,
  };
  if (pluginVersion) diagnosticData.plugin_version = pluginVersion;
  if (serverVersion) diagnosticData.server_version = serverVersion;
  if (options.releaseEvidence !== undefined) {
    diagnosticData.release_evidence = options.releaseEvidence;
  }

  const failure: DiviopsResponse<never> = {
    ok: false,
    error: {
      code: ErrorCodes.CAPABILITY_MISSING,
      message: error.message,
      hint:
        options.hint ??
        capabilityUpgradeHint(error.capability, error.pluginComponent),
      data: diagnosticData,
    },
  };

  return {
    content: [
      {
        type: "text",
        text: serializeEnvelope(failure, toolName),
      },
    ],
  };
}
