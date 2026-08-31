import type { DiviopsResponse } from "./envelope.js";
import { normalizeBody } from "./wp-client.js";

type Client = {
  requestEnveloped(endpoint: string, options: { method: string; body: Record<string, unknown> }): Promise<DiviopsResponse<unknown>>;
};
export type AuthoringWriteSpec = { endpoint: string; method: string; body: Record<string, unknown>; operation: string; dryRun: boolean };

export async function requestAuthoringWrite(client: Client, spec: AuthoringWriteSpec): Promise<DiviopsResponse<unknown>> {
  const result = await client.requestEnveloped(spec.endpoint, {
    method: spec.method,
    body: normalizeBody(spec.body) as Record<string, unknown>,
  });
  if (result.ok || result.error.code !== "authoring_shape.render_required") return result;

  // Older plugins still own their refusal. Never expose their private candidate
  // handoff, manufacture a receipt, or replay a write to bypass that policy.
  return {
    ok: false,
    error: {
      code: result.error.code,
      message: result.error.message,
      ...(result.error.hint ? { hint: result.error.hint } : {}),
    },
  };
}
