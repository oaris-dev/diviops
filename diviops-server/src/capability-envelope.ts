import { MissingCapabilityError } from "./compatibility.js";
import {
  type DiviopsResponse,
  ErrorCodes,
  serializeEnvelope,
} from "./envelope.js";

export type MissingCapabilityMcpResult = {
  content: Array<{ type: "text"; text: string }>;
};

const DEFAULT_HINT =
  "Update the diviops-agent WP plugin to the version shipped with this MCP server release.";

/**
 * Convert a server-side capability gate failure into the canonical DiviOps
 * response envelope carried in MCP text content.
 */
export function missingCapabilityEnvelope(
  error: MissingCapabilityError,
  toolName: string,
  hint: string = DEFAULT_HINT,
): MissingCapabilityMcpResult {
  const failure: DiviopsResponse<never> = {
    ok: false,
    error: {
      code: ErrorCodes.CAPABILITY_MISSING,
      message: error.message,
      hint,
      data: {
        capability: error.capability,
        plugin_version: error.pluginVersion,
        tool: toolName,
      },
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
