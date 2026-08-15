import { createHash, randomUUID } from "node:crypto";
import { mkdirSync, readFileSync, statSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { type SourceLayoutPayload } from "./header-preflight.js";

export interface SourcePayloadRef {
  handle: string;
  checksum: string;
  algorithm: "sha256";
  storage: "server_local_artifact";
  expires_at?: string;
}

export interface SourcePayloadRefEnvelope {
  type: "diviops.cross_env.source_payload_ref.v1";
  created_at: string;
  expires_at: string | null;
  source_payload: SourceLayoutPayload;
  checksum: {
    algorithm: "sha256";
    computed: string;
  };
}

const DEFAULT_TTL_SECONDS = 24 * 60 * 60;
const MAX_TTL_SECONDS = 10 * 365 * 24 * 60 * 60;
const HANDLE_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/;

function sha256(value: string): string {
  return createHash("sha256").update(value).digest("hex");
}

function payloadRoot(): string {
  return (
    process.env.DIVIOPS_CROSS_ENV_PAYLOAD_REF_DIR ||
    join(process.cwd(), ".diviops-tmp", "cross-env-source-payloads")
  );
}

function ttlSeconds(): number {
  const raw = process.env.DIVIOPS_CROSS_ENV_PAYLOAD_REF_TTL_SECONDS;
  if (!raw) return DEFAULT_TTL_SECONDS;
  const parsed = Number(raw);
  if (!Number.isFinite(parsed) || parsed < 0 || parsed > MAX_TTL_SECONDS) {
    return DEFAULT_TTL_SECONDS;
  }
  return Math.floor(parsed);
}

function normalizeChecksum(value: unknown): string {
  if (typeof value !== "string") return "";
  return value.trim().replace(/^sha256:/i, "").toLowerCase();
}

function assertHandle(handle: string): void {
  if (!HANDLE_PATTERN.test(handle) || handle.includes("..")) {
    throw new Error("source_payload_ref.handle is invalid.");
  }
}

function payloadPath(handle: string): string {
  assertHandle(handle);
  return join(payloadRoot(), `${handle}.json`);
}

function assertSourcePayload(value: unknown): SourceLayoutPayload {
  if (!value || typeof value !== "object" || Array.isArray(value)) {
    throw new Error("source payload artifact does not contain an object.");
  }
  const payload = value as Partial<SourceLayoutPayload>;
  if (typeof payload.markup !== "string") {
    throw new Error("source payload artifact is missing markup.");
  }
  if (typeof payload.origin !== "string" || payload.origin.trim() === "") {
    throw new Error("source payload artifact is missing origin.");
  }
  if (typeof payload.object_kind !== "string" || payload.object_kind.trim() === "") {
    throw new Error("source payload artifact is missing object_kind.");
  }
  if (payload.attachments !== undefined) {
    if (!Array.isArray(payload.attachments)) {
      throw new Error("source payload artifact attachments must be an array.");
    }
    for (const attachment of payload.attachments) {
      if (!attachment || typeof attachment !== "object" || Array.isArray(attachment)) {
        throw new Error("source payload artifact attachments must contain objects.");
      }
    }
  }
  return payload as SourceLayoutPayload;
}

function checksumPayload(payload: SourceLayoutPayload): string {
  return sha256(payload.markup);
}

function handleForPayload(payload: SourceLayoutPayload): string {
  const id = payload.object_id === undefined ? "unknown" : String(payload.object_id);
  const slug =
    id
      .replace(/[^A-Za-z0-9._-]+/g, "-")
      .replace(/^-+|-+$/g, "")
      .slice(0, 64) || "source";
  return `src-${slug}-${checksumPayload(payload).slice(0, 16)}-${randomUUID().slice(0, 8)}`;
}

export function createSourcePayloadRef(payload: SourceLayoutPayload): SourcePayloadRef {
  const source = assertSourcePayload(payload);
  const computed = checksumPayload(source);
  const declared = normalizeChecksum(source.checksum);
  if (declared && declared !== computed) {
    throw new Error("source payload checksum does not match markup.");
  }

  const createdAt = new Date().toISOString();
  const ttl = ttlSeconds();
  const expiresAt = ttl > 0 ? new Date(Date.now() + ttl * 1000).toISOString() : null;
  const handle = handleForPayload(source);
  const envelope: SourcePayloadRefEnvelope = {
    type: "diviops.cross_env.source_payload_ref.v1",
    created_at: createdAt,
    expires_at: expiresAt,
    source_payload: { ...source, checksum: computed },
    checksum: {
      algorithm: "sha256",
      computed,
    },
  };

  mkdirSync(payloadRoot(), { recursive: true });
  writeFileSync(payloadPath(handle), `${JSON.stringify(envelope, null, 2)}\n`, {
    encoding: "utf8",
    mode: 0o600,
  });

  return {
    handle,
    checksum: computed,
    algorithm: "sha256",
    storage: "server_local_artifact",
    ...(expiresAt ? { expires_at: expiresAt } : {}),
  };
}

export function loadSourcePayloadRef(
  ref: unknown,
  expectedKind?: string,
): SourceLayoutPayload {
  if (!ref || typeof ref !== "object" || Array.isArray(ref)) {
    throw new Error("source_payload_ref must be an object.");
  }
  const sourceRef = ref as Partial<SourcePayloadRef>;
  if (typeof sourceRef.handle !== "string") {
    throw new Error("source_payload_ref.handle is required.");
  }
  assertHandle(sourceRef.handle);
  const expected = normalizeChecksum(sourceRef.checksum);
  if (!/^[a-f0-9]{64}$/.test(expected)) {
    throw new Error("source_payload_ref.checksum must be a sha256 hex value.");
  }

  const file = payloadPath(sourceRef.handle);
  let content: string;
  try {
    content = readFileSync(file, "utf8");
  } catch (err) {
    const code =
      typeof err === "object" && err !== null && "code" in err
        ? (err as { code?: unknown }).code
        : undefined;
    if (code === "ENOENT") {
      throw new Error("source_payload_ref artifact was not found.");
    }
    throw new Error("source_payload_ref artifact could not be read.");
  }

  let parsed: unknown;
  try {
    parsed = JSON.parse(content);
  } catch {
    throw new Error("source_payload_ref artifact is not valid JSON.");
  }

  const envelope = parsed as Partial<SourcePayloadRefEnvelope>;
  const isEnvelope = envelope?.type === "diviops.cross_env.source_payload_ref.v1";
  if (isEnvelope) {
    if (envelope.expires_at) {
      const expiresAt = Date.parse(envelope.expires_at);
      if (!Number.isFinite(expiresAt) || Date.now() > expiresAt) {
        throw new Error("source_payload_ref artifact is expired.");
      }
    }
  } else {
    const ttl = ttlSeconds();
    if (ttl > 0) {
      let stats;
      try {
        stats = statSync(file);
      } catch {
        throw new Error("source_payload_ref artifact could not be read.");
      }
      if (Date.now() - stats.mtimeMs > ttl * 1000) {
        throw new Error("source_payload_ref artifact is expired.");
      }
    }
  }

  const payload = assertSourcePayload(
    isEnvelope ? envelope.source_payload : parsed,
  );
  const computed = checksumPayload(payload);
  const declared = normalizeChecksum(payload.checksum);
  if (declared && declared !== computed) {
    throw new Error("source_payload_ref artifact checksum does not match markup.");
  }
  if (computed !== expected) {
    throw new Error("source_payload_ref checksum does not match artifact markup.");
  }
  if (expectedKind !== undefined && payload.object_kind !== expectedKind) {
    throw new Error(
      `source_payload_ref kind does not match destination kind (${String(payload.object_kind)} != ${expectedKind}).`,
    );
  }

  return { ...payload, checksum: computed };
}
