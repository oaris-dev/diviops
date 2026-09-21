import { createHash } from "node:crypto";
import { z } from "zod";
import { serializeEnvelope } from "./envelope.js";

export const BOUNDED_PAGE_CAPABILITY = "page_get_bounded_utf8_v1";
export const BOUNDED_PAGE_TEXT_LIMIT = 32 * 1024;
const TOOL = "diviops_page_get";
const checksum = z.string().regex(/^sha256:[a-f0-9]{64}$/);
const byteCount = z.number().int().min(0).max(Number.MAX_SAFE_INTEGER);
const chunkEnvelope = z.object({
  ok: z.literal(true),
  data: z.object({
    id: z.number().int().positive().max(Number.MAX_SAFE_INTEGER),
    encoding: z.literal("utf-8"),
    content_raw: z.string().max(4096),
    content_checksum: checksum,
    total_bytes: byteCount,
    offset: byteCount,
    chunk_bytes: byteCount.max(4096),
    next_offset: byteCount.nullable(),
    complete: z.boolean(),
  }).strict(),
}).strict();

export function boundedPageError(code: string, message: string): string {
  return serializeEnvelope({ ok: false, error: { code, message } }, TOOL);
}

const errors: Record<string, string> = {
  not_found: "Page not found.",
  forbidden: "Page read permission denied.",
  invalid_input: "Invalid bounded read parameters; use the returned byte offset and checksum.",
  "page.content_drift": "Page content changed; discard prior chunks and restart at offset zero.",
  "page.invalid_encoding": "Page content is not valid UTF-8; no bytes were returned.",
};

/** Validate only this optional wire contract; never forward upstream diagnostics. */
export function serializeBoundedPageRead(
  result: unknown,
  request: { page_id: number; offset: number; expected_checksum?: string },
): string {
  const invalid = () => boundedPageError("page.invalid_bounded_response", "Plugin returned an invalid or oversized bounded page response; no content was forwarded.");
  if (typeof result === "object" && result !== null && "ok" in result && result.ok === false) {
    const error = "error" in result ? result.error : null;
    const code = typeof error === "object" && error !== null && "code" in error ? error.code : null;
    if (typeof code === "string" && Object.hasOwn(errors, code)) return boundedPageError(code, errors[code]);
    return boundedPageError("page.bounded_read_failed", "Bounded page read failed; no upstream payload was forwarded.");
  }
  const parsed = chunkEnvelope.safeParse(result);
  if (!parsed.success) return invalid();
  const data = parsed.data.data;
  const bytes = Buffer.from(data.content_raw, "utf8");
  const end = data.offset + data.chunk_bytes;
  if (data.id !== request.page_id || data.offset !== request.offset
    || bytes.toString("utf8") !== data.content_raw
    || bytes.length !== data.chunk_bytes || !Number.isSafeInteger(end) || end > data.total_bytes
    || data.complete !== (end === data.total_bytes)
    || data.next_offset !== (data.complete ? null : end)
    || (!data.complete && data.chunk_bytes < 4093)
    || (request.offset > 0 && request.expected_checksum === undefined)
    || (request.expected_checksum !== undefined && data.content_checksum !== request.expected_checksum)) return invalid();
  if (data.offset === 0 && data.complete
    && data.content_checksum !== `sha256:${createHash("sha256").update(bytes).digest("hex")}`) return invalid();
  const text = serializeEnvelope(parsed.data, TOOL);
  // Also measure the surrounding text block, including its second JSON escaping layer.
  if (Buffer.byteLength(text, "utf8") > BOUNDED_PAGE_TEXT_LIMIT
    || Buffer.byteLength(JSON.stringify({ content: [{ type: "text", text }] }), "utf8") > BOUNDED_PAGE_TEXT_LIMIT) return invalid();
  return text;
}
