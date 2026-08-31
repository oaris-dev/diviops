export const STARTUP_CAPTURE_RECONNECT_GUIDANCE =
  "Plugin, theme, or target changes require reconnecting or restarting the MCP session before diviops_meta_info, the registered tool catalog, and capability gates can be treated as current.";

export const STARTUP_CAPTURE_MIXED_VERSION_GUIDANCE =
  "DiviOps MCP server and WordPress component versions are independent. Compatibility is determined by the per-capability startup handshake, not by matching version numbers.";

export const STARTUP_CAPTURE_LIVE_READ_GUIDANCE =
  "Live read tools such as diviops_fc_status are separate current target observations and do not refresh this startup snapshot or the registered tool catalog.";

const STARTUP_CAPTURE_COVERS = [
  "plugin_versions",
  "module_activation",
  "capabilities",
  "registered_tool_catalog",
] as const;

const STARTUP_CAPTURE_INVALIDATED_BY = [
  "plugin_change",
  "theme_change",
  "target_change",
] as const;

function captureTimestamp(
  value: string | Date | null | undefined,
): string | null {
  if (value instanceof Date) {
    return Number.isNaN(value.getTime()) ? null : value.toISOString();
  }
  if (typeof value !== "string" || value.trim() === "") return null;
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? null : parsed.toISOString();
}

export function createStartupCaptureEvidence(
  observation: { kind: "ok" | "failed" | "pending" },
  observedAt?: string | Date | null,
) {
  const observationState = observation.kind;
  const timestamp = captureTimestamp(observedAt);
  const captured = observationState === "ok";
  return {
    mode: captured
      ? ("startup_captured" as const)
      : ("startup_unavailable" as const),
    captured_at: captured ? timestamp : null,
    attempted_at: captured ? null : timestamp,
    timestamp_status: captured
      ? timestamp
        ? ("recorded" as const)
        : ("unavailable_legacy" as const)
      : timestamp
        ? ("observation_failed" as const)
        : ("unavailable_legacy" as const),
    observation: {
      state: observationState,
      reason_class:
        observationState === "failed"
          ? ("startup_handshake_failed" as const)
          : observationState === "pending"
            ? ("startup_handshake_pending" as const)
            : null,
    },
    immutable_for_session: true,
    covers: captured ? [...STARTUP_CAPTURE_COVERS] : [],
    freshness: {
      status: captured
        ? ("startup_snapshot" as const)
        : ("unavailable" as const),
      invalidated_by: [...STARTUP_CAPTURE_INVALIDATED_BY],
      action: "reconnect_or_restart_mcp" as const,
      guidance: STARTUP_CAPTURE_RECONNECT_GUIDANCE,
    },
    compatibility: {
      component_versions: "independent" as const,
      authority: "per_capability_startup_handshake" as const,
      guidance: STARTUP_CAPTURE_MIXED_VERSION_GUIDANCE,
    },
    live_observations: {
      separate: true,
      guidance: STARTUP_CAPTURE_LIVE_READ_GUIDANCE,
    },
  };
}

export type StartupCaptureEvidence = ReturnType<
  typeof createStartupCaptureEvidence
>;
