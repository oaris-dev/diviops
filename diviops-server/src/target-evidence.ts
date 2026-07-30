import { createHash } from "node:crypto";
import type { HandshakeResult } from "./compatibility.js";

export const TARGET_EVIDENCE_SCHEMA_VERSION = 1;

export type LauncherProfileIdentity = {
  profile_id: string;
  profile_revision: number;
  profile_label: string;
  endpoint: string;
  credential_ref: string;
};

export type TargetEvidence = {
  schema_version: 1;
  profile_id: string;
  profile_revision: number;
  profile_label: string;
  endpoint: string;
  credential_ref: string;
  authenticated_user: { id: number; login: string };
  observed_site_url: string;
  plugin_version: string | null;
  divi: { active: boolean; version: string | null };
  capabilities: Record<string, boolean>;
  capability_digest: string;
  target_evidence_hash: string;
  observed_at: string;
  drift_state: "healthy";
};

export type TargetIdentityForHash = Omit<
  TargetEvidence,
  "profile_label" | "target_evidence_hash" | "observed_at" | "drift_state"
>;

function sha256(value: string): string {
  return `sha256:${createHash("sha256").update(value, "utf8").digest("hex")}`;
}

function assertPlainRecord(value: unknown, name: string): asserts value is Record<string, unknown> {
  if (value === null || typeof value !== "object" || Array.isArray(value)) {
    throw new Error(`launcher.target_evidence_invalid: ${name} must be an object`);
  }
  if (Object.getPrototypeOf(value) !== Object.prototype) {
    throw new Error(`launcher.target_evidence_invalid: ${name} must be a plain object`);
  }
}

export function canonicalJson(value: unknown): string {
  const visit = (input: unknown): unknown => {
    if (input === null || typeof input === "string" || typeof input === "boolean") return input;
    if (typeof input === "number") {
      if (!Number.isFinite(input) || !Number.isSafeInteger(input)) {
        throw new Error("launcher.target_evidence_invalid: canonical numbers must be safe integers");
      }
      return input;
    }
    if (Array.isArray(input)) return input.map(visit);
    assertPlainRecord(input, "canonical value");
    const out: Record<string, unknown> = {};
    for (const key of Object.keys(input).sort()) {
      if (input[key] === undefined) {
        throw new Error("launcher.target_evidence_invalid: undefined values are forbidden");
      }
      out[key] = visit(input[key]);
    }
    return out;
  };
  return JSON.stringify(visit(value));
}

export function normalizeWordPressEndpoint(raw: string): string {
  if (raw.trim() !== raw || raw.length === 0) {
    throw new Error("launcher.endpoint_invalid: endpoint must be non-empty without surrounding whitespace");
  }
  let url: URL;
  try {
    url = new URL(raw);
  } catch {
    throw new Error("launcher.endpoint_invalid: endpoint must be an absolute URL");
  }
  if (url.protocol !== "http:" && url.protocol !== "https:") {
    throw new Error("launcher.endpoint_invalid: endpoint scheme must be http or https");
  }
  if (url.username || url.password || url.search || url.hash) {
    throw new Error("launcher.endpoint_invalid: userinfo, query, and fragment are forbidden");
  }
  if (!url.hostname || url.hostname.includes("%")) {
    throw new Error("launcher.endpoint_invalid: endpoint host is invalid");
  }
  url.hostname = url.hostname.toLowerCase();
  url.pathname = url.pathname.replace(/\/+$/, "") || "/";
  return url.toString().replace(/\/$/, "");
}

export function canonicalCapabilities(value: unknown): Record<string, boolean> {
  assertPlainRecord(value, "capabilities");
  const sorted: Record<string, boolean> = {};
  for (const key of Object.keys(value).sort()) {
    if (!/^[a-z0-9_]+$/.test(key) || typeof value[key] !== "boolean") {
      throw new Error("launcher.target_evidence_invalid: capabilities must be boolean slug entries");
    }
    sorted[key] = value[key] as boolean;
  }
  return sorted;
}

export function targetIdentityForHash(evidence: TargetEvidence): TargetIdentityForHash {
  return {
    schema_version: evidence.schema_version,
    profile_id: evidence.profile_id,
    profile_revision: evidence.profile_revision,
    endpoint: evidence.endpoint,
    credential_ref: evidence.credential_ref,
    authenticated_user: evidence.authenticated_user,
    observed_site_url: evidence.observed_site_url,
    plugin_version: evidence.plugin_version,
    divi: evidence.divi,
    capabilities: evidence.capabilities,
    capability_digest: evidence.capability_digest,
  };
}

export function targetEvidenceHash(evidence: TargetEvidence): string {
  return sha256(canonicalJson(targetIdentityForHash(evidence)));
}

export function createTargetEvidence(
  profile: LauncherProfileIdentity,
  handshake: HandshakeResult,
  observedAt = new Date(),
): TargetEvidence {
  const endpoint = normalizeWordPressEndpoint(profile.endpoint);
  const observedSiteUrl = normalizeWordPressEndpoint(handshake.site_url ?? "");
  const user = handshake.authenticated_user;
  if (
    !user ||
    !Number.isSafeInteger(user.id) ||
    user.id < 1 ||
    typeof user.login !== "string" ||
    user.login.length === 0
  ) {
    throw new Error("launcher.target_evidence_invalid: handshake owner evidence is missing or malformed");
  }
  const capabilities = canonicalCapabilities(handshake.capabilities);
  const capabilityDigest = sha256(canonicalJson(capabilities));
  const evidence: TargetEvidence = {
    schema_version: TARGET_EVIDENCE_SCHEMA_VERSION,
    profile_id: profile.profile_id,
    profile_revision: profile.profile_revision,
    profile_label: profile.profile_label,
    endpoint,
    credential_ref: profile.credential_ref,
    authenticated_user: { id: user.id, login: user.login },
    observed_site_url: observedSiteUrl,
    plugin_version:
      typeof handshake.plugin_version === "string" && handshake.plugin_version.length > 0
        ? handshake.plugin_version
        : null,
    divi: {
      active: handshake.divi.active === true,
      version:
        typeof handshake.divi.version === "string" && handshake.divi.version.length > 0
          ? handshake.divi.version
          : null,
    },
    capabilities,
    capability_digest: capabilityDigest,
    target_evidence_hash: "",
    observed_at: observedAt.toISOString(),
    drift_state: "healthy",
  };
  evidence.target_evidence_hash = targetEvidenceHash(evidence);
  return evidence;
}

export function targetDriftAxes(
  approved: TargetEvidence,
  current: TargetEvidence,
): string[] {
  const axes: string[] = [];
  const pairs: Array<[string, unknown, unknown]> = [
    ["profile_id", approved.profile_id, current.profile_id],
    ["profile_revision", approved.profile_revision, current.profile_revision],
    ["endpoint", approved.endpoint, current.endpoint],
    ["credential_ref", approved.credential_ref, current.credential_ref],
    ["authenticated_user.id", approved.authenticated_user.id, current.authenticated_user.id],
    ["authenticated_user.login", approved.authenticated_user.login, current.authenticated_user.login],
    ["observed_site_url", approved.observed_site_url, current.observed_site_url],
    ["plugin_version", approved.plugin_version, current.plugin_version],
    ["divi.active", approved.divi.active, current.divi.active],
    ["divi.version", approved.divi.version, current.divi.version],
    ["capability_digest", approved.capability_digest, current.capability_digest],
    ["target_evidence_hash", approved.target_evidence_hash, current.target_evidence_hash],
  ];
  for (const [axis, before, after] of pairs) {
    if (before !== after) axes.push(axis);
  }
  return axes;
}
