import { createHash } from "node:crypto";
import type { WPClient } from "../wp-client.js";

export interface MenuTerm { id: number; name: string; slug: string }
export interface MenuIdentity extends MenuTerm { digest: string }
export interface MenuMapping {
  module: string;
  source: MenuIdentity;
  target: MenuIdentity;
  match: "unique_name" | "operator";
}
export interface MenuSource {
  menus?: MenuIdentity[];
  menu_targets?: Record<string, number>;
}
export interface MenuTarget { menus?: MenuIdentity[] }

export const PRIMARY_MENU_NOTE = "Uses the destination's primary menu location.";

function menuSelection(attrs: unknown): number | "primary_location" | null {
  let node = attrs;
  for (const key of ["menu", "advanced", "menuId"]) {
    if (!node || typeof node !== "object" || Array.isArray(node)) return null;
    if (!Object.hasOwn(node, key)) return "primary_location";
    node = (node as Record<string, unknown>)[key];
  }
  if (!node || typeof node !== "object" || Object.keys(node).join() !== "desktop") return null;
  const desktop = (node as { desktop: unknown }).desktop;
  if (!desktop || typeof desktop !== "object" || Object.keys(desktop).join() !== "value") return null;
  const value = (desktop as { value: unknown }).value;
  if (value === "") return "primary_location";
  if ((typeof value === "number" || typeof value === "string") &&
      /^[1-9]\d*$/.test(String(value)) && Number.isSafeInteger(Number(value))) return Number(value);
  return null;
}

export function menuReferences(markup: string) {
  const refs: Array<{ module: string; selection: ReturnType<typeof menuSelection> }> = [];
  for (const match of markup.matchAll(/<!--\s+wp:(divi\/(?:fullwidth-)?menu)(?=[\s/])([\s\S]*?)-->/g)) {
    let selection: ReturnType<typeof menuSelection> = null;
    try {
      selection = menuSelection(JSON.parse(match[2].replace(/\/\s*$/, "").trim() || "{}"));
    } catch { /* Malformed attributes remain a blocking reference. */ }
    refs.push({ module: match[1], selection });
  }
  return refs;
}

export function planMenus(source: MenuSource & { markup: string }, target: MenuTarget) {
  const refs = menuReferences(source.markup);
  const mappings: MenuMapping[] = [];
  const blockers: Array<{ code: string; message: string }> = [];
  for (const ref of refs) {
    if (ref.selection === "primary_location") continue;
    const origins = source.menus?.filter(menu => menu.id === ref.selection) ?? [];
    const origin = origins[0];
    const chosen = ref.selection === null ? undefined : source.menu_targets?.[ref.selection];
    const matches = target.menus?.filter(menu => chosen === undefined ? menu.name === origin?.name : menu.id === chosen) ?? [];
    if (origins.length !== 1 || !origin?.name || matches.length !== 1 ||
        ![origin, matches[0]].every(menu => Number.isSafeInteger(menu.id) && menu.id > 0 &&
          typeof menu.name === "string" && typeof menu.slug === "string" && /^[a-f0-9]{64}$/.test(menu.digest))) {
      blockers.push({ code: "menu_mapping_unverified", message:
        `Cannot resolve ${ref.module} menu ${ref.selection ?? "(unsupported selection)"}. Inspect current menu names on both sites or provide an explicit destination for this source ID.` });
      continue;
    }
    mappings.push({ module: ref.module, source: origin, target: matches[0], match: chosen === undefined ? "unique_name" : "operator" });
  }
  return { refs, mappings, blockers };
}

// Hash only the menu endpoint's published member fields, in their stored order.
// Only the digest and public term identity enter the exported plan.
async function readMenu(client: WPClient, term: MenuTerm): Promise<MenuIdentity> {
  const result = await client.requestEnveloped<{ menu: MenuTerm; items: Record<string, unknown>[] }>(`/menu/get/${term.id}`);
  if (!result.ok) throw new Error("Cannot read the reviewed navigation menu.");
  const { menu, items } = result.data;
  if (menu.id !== term.id || menu.name !== term.name || menu.slug !== term.slug || !Array.isArray(items))
    throw new Error("Navigation menu changed during inventory.");
  const fields = ["id", "title", "url", "type", "object", "object_id", "parent", "order"];
  const digest = createHash("sha256").update(JSON.stringify(items.map(item => fields.map(field => item[field])))).digest("hex");
  return { id: menu.id, name: menu.name, slug: menu.slug, digest };
}

export async function readMenus(client: WPClient, select: (term: MenuTerm) => boolean) {
  const result = await client.requestEnveloped<{ menus: MenuTerm[] }>("/menu/list");
  if (!result.ok) throw new Error("Cannot inventory navigation menus. Check edit_theme_options on the connected site.");
  const menus: MenuIdentity[] = [];
  for (const term of result.data.menus.filter(select)) menus.push(await readMenu(client, term));
  return menus;
}

export async function targetMenus(client: WPClient, names: string[] = [], ids: number[] = []): Promise<MenuTarget> {
  if (!names.length && !ids.length) return {};
  return { menus: await readMenus(client, term => names.includes(term.name) || ids.includes(term.id)) };
}

export function targetMenuHints(source: MenuSource) {
  return { names: source.menus?.map(menu => menu.name), ids: Object.values(source.menu_targets ?? {}) };
}
