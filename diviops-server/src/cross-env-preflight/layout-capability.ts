export const CROSS_ENV_FOOTER_LAYOUT_EVIDENCE_CAPABILITY =
  "cross_env_footer_layout_evidence" as const;

export type CrossEnvEvidenceLayoutKind =
  | "tb_header_layout"
  | "tb_footer_layout"
  | "tb_body_layout";

export function crossEnvEvidenceLayoutKinds(
  capabilities: Record<string, boolean>,
): [CrossEnvEvidenceLayoutKind, ...CrossEnvEvidenceLayoutKind[]] {
  const kinds: [CrossEnvEvidenceLayoutKind, ...CrossEnvEvidenceLayoutKind[]] = ["tb_header_layout"];
  if (capabilities[CROSS_ENV_FOOTER_LAYOUT_EVIDENCE_CAPABILITY] === true) kinds.push("tb_footer_layout");
  if (capabilities.cross_env_staff_body_evidence === true) kinds.push("tb_body_layout");
  return kinds;
}
