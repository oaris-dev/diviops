import type { SourceLayoutPayload } from "./cross-env-preflight/header-preflight.js";

export interface CrossEnvSourceHints {
  source_asset_hints?: string[];
  source_upload_paths?: string[];
  source_attachment_ids?: number[];
}

export function sourceHintsFromPayload(source: SourceLayoutPayload): CrossEnvSourceHints {
  const hints = new Set<string>();
  const uploadPaths = new Set<string>();
  const ids = new Set<number>();
  for (const attachment of source.attachments ?? []) {
    if (typeof attachment.id === "number" && Number.isInteger(attachment.id) && attachment.id > 0) {
      ids.add(attachment.id);
    }
    for (const value of [attachment.path, attachment.url, attachment.filename]) {
      if (typeof value === "string" && value.trim()) hints.add(value.trim());
    }
    if (typeof attachment.path === "string" && attachment.path.trim()) {
      uploadPaths.add(attachment.path.trim());
    }
  }
  return {
    ...(hints.size > 0 ? { source_asset_hints: [...hints].sort((a, b) => a.localeCompare(b)) } : {}),
    ...(uploadPaths.size > 0 ? { source_upload_paths: [...uploadPaths].sort((a, b) => a.localeCompare(b)) } : {}),
    ...(ids.size > 0 ? { source_attachment_ids: [...ids].sort((a, b) => a - b) } : {}),
  };
}
