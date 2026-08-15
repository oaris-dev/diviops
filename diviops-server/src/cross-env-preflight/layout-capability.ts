export const CROSS_ENV_FOOTER_LAYOUT_EVIDENCE_CAPABILITY =
  "cross_env_footer_layout_evidence" as const;

export type CrossEnvEvidenceLayoutKind =
  | "tb_header_layout"
  | "tb_footer_layout";

export function crossEnvEvidenceLayoutKinds(
  capabilities: Record<string, boolean>,
): ["tb_header_layout"] | ["tb_header_layout", "tb_footer_layout"] {
  return capabilities[CROSS_ENV_FOOTER_LAYOUT_EVIDENCE_CAPABILITY] === true
    ? ["tb_header_layout", "tb_footer_layout"]
    : ["tb_header_layout"];
}
