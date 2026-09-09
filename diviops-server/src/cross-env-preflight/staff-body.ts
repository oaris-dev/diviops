import { createHash } from "node:crypto";

export function staffCanonical(value: unknown): string {
  const sort = (v: any): any => Array.isArray(v) ? v.map(sort) : v && typeof v === "object"
    ? Object.fromEntries(Object.keys(v).sort().map(k => [k, sort(v[k])])) : v;
  return JSON.stringify(sort(value));
}
export function staffProof(evidence: Record<string, unknown>) {
  return { evidence, digest: { algorithm: "sha256", computed: createHash("sha256").update(staffCanonical(evidence)).digest("hex") } };
}
const expected = {
  "divi/image:image.innerContent.desktop.value.src": { type: "content", value: { name: "post_featured_image", settings: { thumbnail_size: "large" } } },
  "divi/heading:title.innerContent.desktop.value": { type: "content", value: { name: "post_title", settings: { before: "", after: "" } } },
  "divi/text:content.innerContent.desktop.value": { type: "content", value: { name: "post_meta_key", settings: { before: "", after: "", select_meta_key: "custom_meta_diviops_staff_role", meta_key: "", date_format: "default", custom_date_format: "", enable_html: "off" } } },
};

// Uses the same serialized Divi comment/JSON contract as the existing hazard scanner.
// PHP independently repeats this check with WordPress's real block parser.
export function staffSourceProof(markup: string) {
  const found: Record<string, unknown> = {};
  const counts: Record<string, number> = {};
  const stack: string[] = [];
  let end = 0;
  const walk = (v: unknown, path: string, name: string) => {
    if (v && typeof v === "object") {
      for (const [key, nested] of Object.entries(v)) {
        if (/loop|post.?id|context|dynamic|modulePreset|groupPreset|globalModule/i.test(key)) throw Error("unsupported staff context");
        walk(nested, path ? `${path}.${key}` : key, name);
      }
    } else if (typeof v === "string" && v.includes("$variable")) {
      const key = `${name}:${path}`;
      const token = v.startsWith("$variable(") && v.endsWith(")$") ? JSON.parse(v.slice(10, -2)) : null;
      if (!(key in expected) || key in found || staffCanonical(token) !== staffCanonical(expected[key as keyof typeof expected])) throw Error("unsupported staff binding");
      found[key] = token;
    }
  };
  for (const match of markup.matchAll(/<!--\s+(\/)?wp:([A-Za-z0-9_-]+\/[A-Za-z0-9_-]+)(.*?)(\/)?-->/gs)) {
    if (markup.slice(end, match.index).trim()) throw Error("unsupported staff content");
    end = match.index! + match[0].length;
    const [, close, name, tail, selfClose] = match;
    if (!["divi/placeholder", "divi/section", "divi/row", "divi/column", "divi/group", "divi/image", "divi/heading", "divi/text", "divi/post-content"].includes(name)) throw Error("unsupported staff module");
    if (close) { if (stack.pop() !== name) throw Error("unbalanced staff body"); continue; }
    if (!selfClose) stack.push(name);
    counts[name] = (counts[name] ?? 0) + 1;
    walk(tail.trim() ? JSON.parse(tail.trim()) : {}, "", name);
  }
  if (stack.length || markup.slice(end).trim() || ["divi/image", "divi/heading", "divi/text", "divi/post-content"].some(n => counts[n] !== 1) || staffCanonical(found) !== staffCanonical(expected)) throw Error("unsupported staff recipe");
  return staffProof({ schema: "diviops.cross_env.staff_body.source.v1", context: "current_post", bindings: expected, post_content_count: 1 });
}

export function validStaffTarget(proof: any, linkage: any, destinationId: unknown): boolean {
  if (!proof?.evidence || staffCanonical(proof) !== staffCanonical(staffProof(proof.evidence))) return false;
  const e = proof.evidence, f = e.field, link = e.linkage?.links?.[0];
  return e.schema === "diviops.cross_env.staff_body.target.v1" && e.post_type === "diviops_staff" && e.registered === true
    && f?.key === "field_diviops_staff_role" && f.name === "diviops_staff_role" && f.type === "text"
    && f.selector === "custom_meta_diviops_staff_role" && f.group_active === true && typeof f.group_key === "string" && f.group_key.length > 0
    && Array.isArray(f.location) && f.location.some((branch: unknown) => staffCanonical(branch) === staffCanonical([{ param: "post_type", operator: "==", value: "diviops_staff" }]))
    && staffCanonical(e.linkage) === staffCanonical(linkage) && e.linkage.destination_id === destinationId
    && e.linkage.destination_kind === "tb_body_layout" && e.linkage.destination_post_type === "et_body_layout"
    && e.linkage.active_master_id > 0 && e.linkage.links.length === 1
    && link?.slot === "body" && link.layout_id === destinationId && link.layout_enabled === true && link.template_enabled === true && link.template_default === false
    && staffCanonical(link.conditions) === '["singular:post_type:diviops_staff:all"]' && staffCanonical(link.exclusions) === "[]"
    && e.slots?.body?.id === destinationId && e.slots.body.enabled === "1" && e.slots.body.global !== "1"
    && !!e.slots.header && !!e.slots.footer;
}
