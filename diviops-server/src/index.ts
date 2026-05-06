#!/usr/bin/env node

/**
 * Divi 5 MCP Server
 *
 * Exposes Divi Visual Builder operations as MCP tools for Claude.
 * Requires the companion WordPress plugin "diviops-agent" to be active.
 *
 * Auth: WordPress Application Passwords (Basic Auth).
 * Config: Environment variables WP_URL, WP_USER, WP_APP_PASSWORD.
 */

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { WPClient } from "./wp-client.js";
import { optimizeSchema } from "./schema-optimizer.js";
import { createWpCli } from "./wp-cli.js";
import {
  findForeignVarRefs,
  scanAttrsForForeignVarRefs,
  isolationErrorResult,
} from "./validate-attrs.js";
import { readFileSync, readdirSync } from "fs";
import { join, dirname } from "path";
import { fileURLToPath } from "url";

const __dirname = dirname(fileURLToPath(import.meta.url));

// ── Config ───────────────────────────────────────────────────────────

const WP_URL = process.env.WP_URL ?? "";
const WP_USER = process.env.WP_USER ?? "";
const WP_APP_PASSWORD = process.env.WP_APP_PASSWORD ?? "";

if (!WP_URL || !WP_USER || !WP_APP_PASSWORD) {
  const missing = [
    !WP_URL && "WP_URL",
    !WP_USER && "WP_USER",
    !WP_APP_PASSWORD && "WP_APP_PASSWORD",
  ].filter(Boolean);
  console.error(
    `Error: Missing required environment variable(s): ${missing.join(", ")}.\n` +
      "Set WP_URL to your WordPress site URL (e.g. http://mysite.local).\n" +
      "Generate an Application Password at: WP Admin → Users → Profile → Application Passwords",
  );
  process.exit(1);
}

const wp = new WPClient({
  siteUrl: WP_URL,
  username: WP_USER,
  applicationPassword: WP_APP_PASSWORD,
});

// WP-CLI (optional — Local by Flywheel via WP_PATH, or custom wrapper via WP_CLI_CMD)
const WP_PATH = process.env.WP_PATH ?? "";
const WP_CLI_CMD = process.env.WP_CLI_CMD?.trim() ?? "";
const LOCAL_SITE_ID = process.env.LOCAL_SITE_ID ?? "";
let wpCli: ReturnType<typeof createWpCli> | null = null;
if (WP_CLI_CMD) {
  try {
    wpCli = createWpCli({
      wpCliCmd: WP_CLI_CMD,
      wpPath: WP_PATH || process.cwd(),
    });
  } catch (e) {
    console.error(`WP-CLI setup failed (non-fatal): ${e}`);
  }
} else if (WP_PATH) {
  try {
    wpCli = createWpCli({
      wpPath: WP_PATH,
      localSiteId: LOCAL_SITE_ID || undefined,
    });
  } catch (e) {
    console.error(`WP-CLI setup failed (non-fatal): ${e}`);
  }
}

// ── Version ─────────────────────────────────────────────────────────

// Read version from package.json at startup — single source of truth.
const SERVER_VERSION: string = (() => {
  try {
    const pkg = JSON.parse(
      readFileSync(join(__dirname, "..", "package.json"), "utf-8"),
    );
    return pkg.version ?? "0.0.0";
  } catch {
    return "0.0.0";
  }
})();

// ── MCP Server ───────────────────────────────────────────────────────

const server = new McpServer({
  name: "diviops-mcp",
  version: SERVER_VERSION,
});

// ── Read Tools ───────────────────────────────────────────────────────

server.registerTool(
  "diviops_page_list",
  {
    description:
      "List pages/posts in the WordPress site. Returns title, ID, URL, status, and whether each page uses Divi builder.",
    inputSchema: {
      post_type: z
        .string()
        .optional()
        .default("page")
        .describe('Post type to query: "page", "post", or custom type'),
      per_page: z
        .number()
        .optional()
        .default(20)
        .describe("Number of results per page (max 100)"),
      page: z.number().optional().default(1).describe("Page number"),
    },
  },
  async ({ post_type, per_page, page }) => {
    const result = await wp.request("/page/list", {
      params: {
        post_type: post_type ?? "page",
        per_page: String(per_page ?? 20),
        page: String(page ?? 1),
      },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_page_get",
  {
    description:
      "Get detailed info about a specific page including its raw Divi block content.",
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
    },
  },
  async ({ page_id }) => {
    const result = await wp.request(`/page/get/${page_id}`);
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_page_get_layout",
  {
    description:
      "Get the parsed block tree for a page. Returns slim targeting metadata by default (block names, admin labels, text previews, auto_index). Use full: true for complete attrs (warning: can be very large on complex pages).",
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      full: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "Include full block attrs and raw content (default: false for slim mode)",
        ),
    },
  },
  async ({ page_id, full }) => {
    const result = await wp.request(`/page/get-layout/${page_id}`, {
      params: full ? { full: "true" } : {},
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_schema_list_modules",
  {
    description:
      "List all available Divi modules (block types) with their names, titles, and categories. Use this to discover what modules can be used in layouts.",
  },
  async () => {
    const result = await wp.request("/schema/modules");
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_schema_get_module",
  {
    description:
      "Get the attribute schema for a Divi module. Returns optimized schema by default (~70% smaller) with content-relevant fields only. Use raw: true for the full schema including CSS selectors and VB metadata.",
    inputSchema: {
      module_name: z
        .string()
        .describe(
          'Module name, e.g. "text", "image", "accordion", or full "divi/text"',
        ),
      raw: z
        .boolean()
        .optional()
        .default(false)
        .describe("Return full schema including CSS selectors and VB metadata"),
    },
  },
  async ({ module_name, raw }) => {
    const result = await wp.request(
      `/schema/module/${encodeURIComponent(module_name)}`,
    );
    const output = raw ? result : optimizeSchema(result as Record<string, any>);
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(output) },
      ],
    };
  },
);

server.registerTool(
  "diviops_schema_get_settings",
  {
    description:
      "Get Divi site settings including theme options, site info, and builder version. Useful for understanding the site context before generating content.",
  },
  async () => {
    const result = await wp.request("/schema/settings");
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_global_color_list",
  {
    description:
      "Get the global color palette defined in Divi. Returns all global colors that can be referenced by modules.",
  },
  async () => {
    const result = await wp.request("/global-color/list");
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_global_color_create",
  {
    description:
      "Add a new global color to Divi's palette. The plugin mints a fresh `gcid-<uuid>` ID (the server forwards the color entry without an id and the WP-side handler generates one) and writes to the et_global_data option in the canonical Divi shape `{color, folder, label, lastUpdated, status, usedInPosts}`. The color appears in the VB color picker after save and can be referenced via `$variable({type:color,value:{name:gcid-...}})$` tokens. Note: Divi's AI Agent bundle has a Zod schema gap that drops `label` on its own writes — our PHP path goes around that bug by writing directly to the option. CONCURRENCY: this is a read-modify-write on a single WP option with no conflict detection. If a Visual Builder session holds stale global data, its next save can clobber colors written here in the interim. Coordinate writes when VB sessions are active, or have the user reload VB after MCP color writes.",
    inputSchema: {
      color: z
        .string()
        .describe('CSS color value — hex (e.g. "#ff0000", "#ff0000aa") or functional rgba/hsla notation. Bare keywords like "red" are not accepted.'),
      label: z
        .string()
        .optional()
        .describe('Human-readable label shown in the VB color picker (e.g. "Brand Red"). Optional — defaults to empty (matches Divi\'s stock palette which leaves labels blank).'),
      folder: z
        .string()
        .optional()
        .describe("Folder name for grouping colors in the picker UI. Optional — defaults to empty (no folder)."),
      status: z
        .enum(["active", "archived"])
        .optional()
        .default("active")
        .describe('Color status — "active" (default, visible in picker) or "archived" (hidden but preserved).'),
    },
  },
  async ({ color, label, folder, status }) => {
    const colorEntry: Record<string, any> = { color };
    if (label !== undefined) colorEntry.label = label;
    if (folder !== undefined) colorEntry.folder = folder;
    if (status) colorEntry.status = status;
    const result = await wp.request("/global-color/upsert", {
      method: "POST",
      body: { colors: [colorEntry], mode: "merge" },
    });
    return { content: [{ type: "text" as const, text: JSON.stringify(result) }] };
  },
);

server.registerTool(
  "diviops_global_color_update",
  {
    description:
      "Update an existing global color by gcid. Only provided fields are updated; omitted fields are preserved. The lastUpdated timestamp is bumped on every write. Use diviops_global_color_list first to find the gcid for a color. CONCURRENCY: same VB-session race caveat as diviops_global_color_create — the write is read-modify-write on a single WP option, so an active VB session's next save can clobber this update.",
    inputSchema: {
      gcid: z
        .string()
        .describe('Global color ID, e.g. "gcid-abc123..." (must start with "gcid-"). Get from diviops_global_color_list.'),
      color: z
        .string()
        .optional()
        .describe('New CSS color value — hex or rgba/hsla notation. Omit to keep existing.'),
      label: z
        .string()
        .optional()
        .describe('New human-readable label. Pass empty string to clear.'),
      folder: z
        .string()
        .optional()
        .describe('New folder. Pass empty string to clear.'),
      status: z
        .enum(["active", "archived"])
        .optional()
        .describe('Change status — "active" or "archived". Omit to keep existing.'),
    },
  },
  async ({ gcid, color, label, folder, status }) => {
    const colorEntry: Record<string, any> = { id: gcid };
    if (color !== undefined) colorEntry.color = color;
    if (label !== undefined) colorEntry.label = label;
    if (folder !== undefined) colorEntry.folder = folder;
    if (status) colorEntry.status = status;
    const result = await wp.request("/global-color/upsert", {
      method: "POST",
      body: { colors: [colorEntry], mode: "merge" },
    });
    return { content: [{ type: "text" as const, text: JSON.stringify(result) }] };
  },
);

server.registerTool(
  "diviops_global_color_delete",
  {
    description:
      "Delete a global color from the registry by gcid. Refuses by default if the color is tracked as referenced by any post (per Divi's `usedInPosts` index — pass `force: true` to delete anyway; orphan refs will render as invalid CSS until pages are re-saved through VB). Always refuses to delete the 5 customizer-bound defaults (gcid-primary-color, gcid-secondary-color, gcid-heading-color, gcid-body-color, gcid-link-color) regardless of force — those must be edited via WP Customizer. CONCURRENCY: same VB-session race caveat as diviops_global_color_create — an active VB session's next save can re-introduce a color we just deleted if the session held stale data.",
    inputSchema: {
      gcid: z
        .string()
        .describe('Global color ID to delete (must start with "gcid-").'),
      force: z
        .boolean()
        .optional()
        .default(false)
        .describe("If true, delete even when usedInPosts shows live references. Customizer-bound defaults remain protected regardless."),
    },
  },
  async ({ gcid, force }) => {
    const body: Record<string, any> = { gcid };
    if (force) body.force = true;
    const result = await wp.request("/global-color/delete", {
      method: "POST",
      body,
    });
    return { content: [{ type: "text" as const, text: JSON.stringify(result) }] };
  },
);

server.registerTool(
  "diviops_global_font_list",
  {
    description: "Get the global font definitions from Divi settings.",
  },
  async () => {
    const result = await wp.request("/global-font/list");
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_meta_find_icon",
  {
    description:
      "Search for icons by keyword. Returns matching icons with unicode, type (fa/divi), and weight. Use the returned unicode/type/weight in Blurb icon or Icon module attributes.",
    inputSchema: {
      query: z
        .string()
        .describe('Search keyword (e.g. "rocket", "heart", "chart", "user")'),
      type: z
        .enum(["all", "fa", "divi"])
        .optional()
        .default("all")
        .describe(
          'Filter by icon type: "all", "fa" (Font Awesome), or "divi" (ETmodules)',
        ),
      limit: z
        .number()
        .optional()
        .default(10)
        .describe("Max results (default 10, max 50)"),
    },
  },
  async ({ query, type, limit }) => {
    const result = await wp.request(
      `/meta/find-icon?q=${encodeURIComponent(query)}&type=${type ?? "all"}&limit=${limit ?? 10}`,
    );
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

// ── Write Tools ──────────────────────────────────────────────────────

server.registerTool(
  "diviops_page_update_content",
  {
    description:
      "Update the content of a page with Divi block markup. The content should be valid WordPress block markup using divi/* blocks. IMPORTANT: This overwrites the entire page content.",
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID to update"),
      content: z
        .string()
        .describe(
          "Full page content in WordPress block markup format (<!-- wp:divi/section -->...<!-- /wp:divi/section -->)",
        ),
    },
  },
  async ({ page_id, content }) => {
    const hits = findForeignVarRefs(content, "content");
    if (hits.length > 0) return isolationErrorResult("diviops_page_update_content", hits);
    const result = await wp.request(`/page/update-content/${page_id}`, {
      method: "POST",
      body: { content },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_render_preview",
  {
    description:
      "Render Divi block markup to HTML. Use this to preview what the output will look like before saving. Useful for validation.",
    inputSchema: {
      content: z.string().describe("Divi block markup to render to HTML"),
    },
  },
  async ({ content }) => {
    const result = await wp.request("/render", {
      method: "POST",
      body: { content },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_validate_blocks",
  {
    description:
      "Validate Divi block markup before saving. Checks structure (malformed comments, unknown blocks, missing builderVersion), required attributes (layout display on containers), and known pitfalls (button padding path, icon.enable, gradient enabled/positions). Returns errors and warnings.",
    inputSchema: {
      content: z.string().describe("Divi block markup to validate"),
    },
  },
  async ({ content }) => {
    const result = await wp.request("/validate/blocks", {
      method: "POST",
      body: { content },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_section_append",
  {
    description:
      "Append a Divi section to an existing page without overwriting other content. Use this to incrementally build pages.",
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      content: z
        .string()
        .describe(
          "Section block markup to append (<!-- wp:divi/section ...-->...<!-- /wp:divi/section -->)",
        ),
      position: z
        .enum(["start", "end"])
        .optional()
        .default("end")
        .describe('Where to insert: "start" or "end" (default)'),
    },
  },
  async ({ page_id, content, position }) => {
    const hits = findForeignVarRefs(content, "content");
    if (hits.length > 0) return isolationErrorResult("diviops_section_append", hits);
    const result = await wp.request(`/section/append/${page_id}`, {
      method: "POST",
      body: { content, position: position ?? "end" },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_section_replace",
  {
    description:
      "Replace a section on a page. Target by admin label OR text content. Use occurrence when multiple sections match.",
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      label: z
        .string()
        .optional()
        .describe("Admin label of the section to replace"),
      match_text: z
        .string()
        .optional()
        .describe(
          "Text to search for in section content (case-insensitive substring)",
        ),
      content: z
        .string()
        .describe("New section block markup to replace the matched section"),
      occurrence: z
        .number()
        .int()
        .min(1)
        .optional()
        .default(1)
        .describe("Which match to target (1-based, default: 1)"),
    },
  },
  async ({ page_id, label, match_text, content, occurrence }) => {
    const hits = findForeignVarRefs(content, "content");
    if (hits.length > 0) return isolationErrorResult("diviops_section_replace", hits);
    const body: Record<string, any> = { content, occurrence };
    if (label) body.label = label;
    if (match_text) body.match_text = match_text;
    const result = await wp.request(`/section/replace/${page_id}`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_section_remove",
  {
    description:
      "Remove a section from a page. Target by admin label OR text content. Use occurrence when multiple sections match.",
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      label: z
        .string()
        .optional()
        .describe("Admin label of the section to remove"),
      match_text: z
        .string()
        .optional()
        .describe(
          "Text to search for in section content (case-insensitive substring)",
        ),
      occurrence: z
        .number()
        .int()
        .min(1)
        .optional()
        .default(1)
        .describe("Which match to target (1-based, default: 1)"),
    },
  },
  async ({ page_id, label, match_text, occurrence }) => {
    const body: Record<string, any> = { occurrence };
    if (label) body.label = label;
    if (match_text) body.match_text = match_text;
    const result = await wp.request(`/section/remove/${page_id}`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_section_get",
  {
    description:
      "Get the raw block markup of a section. Target by admin label OR text content. Use occurrence when multiple sections match. Returns total_matches warning when duplicates exist.",
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      label: z
        .string()
        .optional()
        .describe("Admin label of the section to retrieve"),
      match_text: z
        .string()
        .optional()
        .describe(
          "Text to search for in section content (case-insensitive substring)",
        ),
      occurrence: z
        .number()
        .int()
        .min(1)
        .optional()
        .default(1)
        .describe("Which match to target (1-based, default: 1)"),
    },
  },
  async ({ page_id, label, match_text, occurrence }) => {
    const params: Record<string, string> = { occurrence: String(occurrence) };
    if (label) params.label = label;
    if (match_text) params.match_text = match_text;
    const qs = new URLSearchParams(params).toString();
    const result = await wp.request(`/section/get/${page_id}?${qs}`);
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_module_update",
  {
    description:
      'Update specific attributes of a module. Target by auto_index (e.g. "text:5"), admin label, or text content. Uses dot notation for attribute paths. Example: {"content.decoration.headingFont.h2.font.desktop.value.color": "#ff0000"}. For paths whose key segments contain literal dots — notably Composable Settings preset slots like groupPreset["title.decoration.spacing"] — escape the inner dots with `\\.` to keep the segment intact: {"groupPreset.title\\\\.decoration\\\\.spacing.presetId": ["uuid"]}. Priority: auto_index > label > match_text. Use occurrence with label when duplicates exist.',
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      label: z
        .string()
        .optional()
        .describe("Admin label of the module (exact match)"),
      match_text: z
        .string()
        .optional()
        .describe(
          "Text to find in module innerContent (case-insensitive substring, first match)",
        ),
      auto_index: z
        .string()
        .optional()
        .describe(
          'Auto-index target in "type:N" format (e.g. "text:5", "icon:3"). Get from diviops_page_get_layout. Takes priority over label/match_text.',
        ),
      occurrence: z
        .number()
        .int()
        .min(1)
        .optional()
        .default(1)
        .describe(
          "Which occurrence to target when multiple modules share the same label (1-based)",
        ),
      attrs: z
        .record(z.string(), z.any())
        .describe("Attribute paths (dot notation) and their new values"),
    },
  },
  async ({ page_id, label, match_text, auto_index, occurrence, attrs }) => {
    const hits = scanAttrsForForeignVarRefs(attrs);
    if (hits.length > 0) return isolationErrorResult("diviops_module_update", hits);
    const body: Record<string, any> = { attrs };
    if (auto_index) body.auto_index = auto_index;
    if (label) body.label = label;
    if (match_text) body.match_text = match_text;
    if (occurrence > 1) body.occurrence = occurrence;
    const result = await wp.request(`/module/update/${page_id}`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_module_move",
  {
    description:
      'Move a module to a new position on the page. Specify source and target blocks using auto_index (e.g. "text:3"), admin label, or text content. Position "before" or "after" the target. Works with any block type including sections, rows, and modules. Both blocks are found in the original content, so auto_index values refer to positions before the move.',
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      source_label: z
        .string()
        .optional()
        .describe("Admin label of the module to move"),
      source_match_text: z
        .string()
        .optional()
        .describe("Text to search for in source module (case-insensitive)"),
      source_auto_index: z
        .string()
        .optional()
        .describe(
          'Auto-index of the module to move in "type:N" format (e.g. "text:3")',
        ),
      source_occurrence: z
        .number()
        .int()
        .min(1)
        .optional()
        .default(1)
        .describe(
          "Which occurrence when multiple sources match by label (1-based)",
        ),
      target_label: z
        .string()
        .optional()
        .describe("Admin label of the reference module"),
      target_match_text: z
        .string()
        .optional()
        .describe("Text to search for in target module (case-insensitive)"),
      target_auto_index: z
        .string()
        .optional()
        .describe(
          'Auto-index of the reference module in "type:N" format (e.g. "text:5")',
        ),
      target_occurrence: z
        .number()
        .int()
        .min(1)
        .optional()
        .default(1)
        .describe(
          "Which occurrence when multiple targets match by label (1-based)",
        ),
      position: z
        .enum(["before", "after"])
        .describe("Place the source before or after the target"),
    },
  },
  async ({
    page_id,
    source_label,
    source_match_text,
    source_auto_index,
    source_occurrence,
    target_label,
    target_match_text,
    target_auto_index,
    target_occurrence,
    position,
  }) => {
    const body: Record<string, any> = { position };
    if (source_label) body.source_label = source_label;
    if (source_match_text) body.source_match_text = source_match_text;
    if (source_auto_index) body.source_auto_index = source_auto_index;
    if (source_occurrence > 1) body.source_occurrence = source_occurrence;
    if (target_label) body.target_label = target_label;
    if (target_match_text) body.target_match_text = target_match_text;
    if (target_auto_index) body.target_auto_index = target_auto_index;
    if (target_occurrence > 1) body.target_occurrence = target_occurrence;
    const result = await wp.request(`/module/move/${page_id}`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_module_lock",
  {
    description:
      'Lock a module so VB users cannot edit it. Sets attrs.locked = {desktop: {value: "on"}} per Divi\'s per-breakpoint convention (verified via VB-save probe). Locked modules render normally on frontend; only VB-side editing is gated. Same targeting pattern as diviops_module_update — pick one of label / match_text / auto_index. Use diviops_module_unlock to reverse.',
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      label: z.string().optional().describe("Admin label of the module to lock (exact match)"),
      match_text: z.string().optional().describe("Text to search for in module markup (case-insensitive)"),
      auto_index: z.string().optional().describe('Auto-index in "type:N" format (e.g. "text:3")'),
      occurrence: z.number().int().min(1).optional().default(1).describe("Which occurrence when multiple modules share the same label (1-based)"),
    },
  },
  async ({ page_id, label, match_text, auto_index, occurrence }) => {
    const body: Record<string, any> = {};
    if (label) body.label = label;
    if (match_text) body.match_text = match_text;
    if (auto_index) body.auto_index = auto_index;
    if (occurrence && occurrence > 1) body.occurrence = occurrence;
    const result = await wp.request(`/module/lock/${page_id}`, { method: "POST", body });
    return { content: [{ type: "text" as const, text: JSON.stringify(result) }] };
  },
);

server.registerTool(
  "diviops_module_unlock",
  {
    description:
      "Unlock a module by removing attrs.locked entirely. Matches Divi VB's convention: unlocked = attribute absent (NOT {value: 'off'}) — VB doesn't write a falsy value on unlock, it removes the field. Same targeting pattern as diviops_module_lock.",
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      label: z.string().optional().describe("Admin label of the module to unlock (exact match)"),
      match_text: z.string().optional().describe("Text to search for in module markup (case-insensitive)"),
      auto_index: z.string().optional().describe('Auto-index in "type:N" format'),
      occurrence: z.number().int().min(1).optional().default(1).describe("Which occurrence when multiple modules share the same label (1-based)"),
    },
  },
  async ({ page_id, label, match_text, auto_index, occurrence }) => {
    const body: Record<string, any> = {};
    if (label) body.label = label;
    if (match_text) body.match_text = match_text;
    if (auto_index) body.auto_index = auto_index;
    if (occurrence && occurrence > 1) body.occurrence = occurrence;
    const result = await wp.request(`/module/unlock/${page_id}`, { method: "POST", body });
    return { content: [{ type: "text" as const, text: JSON.stringify(result) }] };
  },
);

server.registerTool(
  "diviops_module_clone",
  {
    description:
      'Clone a module by deep-copying its block JSON and inserting it next to the source within the same parent container. Position controls before/after placement (default "after"). Module IDs are reassigned by Divi at render time from the block tree position, so the clone gets fresh IDs automatically. Same targeting pattern as diviops_module_lock.',
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
      label: z.string().optional().describe("Admin label of the module to clone (exact match)"),
      match_text: z.string().optional().describe("Text to search for in module markup (case-insensitive)"),
      auto_index: z.string().optional().describe('Auto-index in "type:N" format'),
      occurrence: z.number().int().min(1).optional().default(1).describe("Which occurrence when multiple modules share the same label (1-based)"),
      position: z.enum(["before", "after"]).optional().default("after").describe('Place the clone "before" or "after" the source module within its parent.'),
    },
  },
  async ({ page_id, label, match_text, auto_index, occurrence, position }) => {
    const body: Record<string, any> = {};
    if (label) body.label = label;
    if (match_text) body.match_text = match_text;
    if (auto_index) body.auto_index = auto_index;
    if (occurrence && occurrence > 1) body.occurrence = occurrence;
    if (position) body.position = position;
    const result = await wp.request(`/module/clone/${page_id}`, { method: "POST", body });
    return { content: [{ type: "text" as const, text: JSON.stringify(result) }] };
  },
);

server.registerTool(
  "diviops_page_create",
  {
    description:
      "Create a new WordPress page, optionally with Divi block content.",
    inputSchema: {
      title: z.string().describe("Page title"),
      content: z
        .string()
        .optional()
        .default("")
        .describe("Page content in Divi block markup format"),
      status: z
        .enum(["draft", "publish", "private"])
        .optional()
        .default("draft")
        .describe("Post status"),
    },
  },
  async ({ title, content, status }) => {
    if (content) {
      const hits = findForeignVarRefs(content, "content");
      if (hits.length > 0) return isolationErrorResult("diviops_page_create", hits);
    }
    const result = await wp.request("/page/create", {
      method: "POST",
      body: { title, content: content ?? "", status: status ?? "draft" },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

// ── Preset Tools ────────────────────────────────────────────────────

server.registerTool(
  "diviops_preset_audit",
  {
    description:
      "Audit all Divi presets (module + group). Each entry reports `block_ref_count` (page-content refs via modulePreset / groupPreset block markup), `group_ref_count` (in-registry chain refs from other presets — module presets via top-level `groupPresets.<slot>.presetId`, group presets via `attrs.groupPreset.<slot>.presetId`), and `referenced` (true if either > 0). Group presets that are chain-referenced also expose `referenced_by_presets` (UUIDs of the presets that wire them in — typically module presets, but type-agnostic). Use this before deleting — orphan-cleanup based only on page refs would silently wipe load-bearing chain-wired group presets (font, border, box-shadow, spacing, button). Also reports `orphan_default_pointers`: per-bucket `default` pointers that reference a UUID no longer present in `items[]` (caused by past unsafe deletes). Render-safe but blocks Divi's lazy recreate-on-VB-use path; clear via diviops_preset_set_default with unset=true on the affected module/group.",
  },
  async () => {
    const result = await wp.request("/preset/audit");
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_preset_cleanup",
  {
    description:
      'Clean up presets. Default: remove spam presets. Optional: dedup=true to also remove duplicates, action="rename_strip_prefix" with prefix to strip a name prefix, or action="remove_orphans" with scope="spam"|"all" to remove unreferenced presets. Use dry_run: true (default) to preview.',
    inputSchema: {
      dry_run: z
        .boolean()
        .optional()
        .default(true)
        .describe(
          "If true, preview changes without applying. Set false to execute.",
        ),
      dedup: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "Remove duplicate presets with identical attrs within the same module.",
        ),
      action: z
        .string()
        .optional()
        .describe(
          'Action: "rename_strip_prefix" strips a prefix, "remove_orphans" removes unreferenced presets.',
        ),
      prefix: z
        .string()
        .optional()
        .describe(
          'Prefix to strip when action is "rename_strip_prefix" (e.g. "Online Courses ").',
        ),
      scope: z
        .enum(["spam", "all"])
        .default("spam")
        .describe(
          'Scope for remove_orphans: "spam" (only spam-named orphans) or "all" (all non-default orphans).',
        ),
    },
  },
  async ({ dry_run, dedup, action, prefix, scope }) => {
    const body: Record<string, any> = { dry_run: dry_run ?? true };
    if (dedup) body.dedup = true;
    if (action) body.action = action;
    if (prefix) body.prefix = prefix;
    if (action === "remove_orphans" && scope) body.scope = scope;
    const result = await wp.request("/preset/cleanup", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_preset_update",
  {
    description:
      "Update a specific preset by ID. Can rename, replace its style attributes, and/or change its stack priority. Note: Divi serves frontend CSS from a per-post static cache at wp-content/et-cache/{post_id}/ that wp cache flush does NOT invalidate — if you're verifying a preset change on the rendered frontend, delete that dir for affected pages to force regeneration. Server-side preset state updates immediately; only the pre-rendered CSS file is stale.",
    inputSchema: {
      preset_id: z.string().describe("Preset ID (UUID or short ID)"),
      name: z.string().optional().describe("New display name for the preset"),
      attrs: z
        .record(z.string(), z.any())
        .optional()
        .describe(
          "New style attributes (replaces attrs, styleAttrs, and renderAttrs — matches VB save semantics so render cache stays in sync with edit state)",
        ),
      priority: z
        .number()
        .int()
        .optional()
        .describe(
          "Stack-merge priority. When this preset is part of a stacked-preset arrangement (e.g. base typography + brand override on the same module/group slot), Divi sorts presets ascending and merges in priority order, so a higher number wins the cascade. Default in Divi is 10 when omitted. Only meaningful for presets that participate in a stack — solo presets render the same regardless of priority.",
        ),
    },
  },
  async ({ preset_id, name, attrs, priority }) => {
    const body: Record<string, any> = { preset_id };
    if (name) body.name = name;
    if (attrs) body.attrs = attrs;
    if (typeof priority === "number") body.priority = priority;
    const result = await wp.request("/preset/update", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_preset_delete",
  {
    description:
      "Delete a specific preset by ID. Use diviops_preset_audit first to verify the preset is unreferenced before deleting. Refuses with 409 preset_is_default if the target is the registered default for its module/group bucket — clear the pointer first via diviops_preset_set_default with unset=true, or pass force=true to delete and clear the pointer in one write.",
    inputSchema: {
      preset_id: z.string().describe("Preset ID to delete"),
      force: z
        .boolean()
        .optional()
        .describe(
          "When true, deletes the preset even if it is the registered default and clears the default pointer in the same write. Default false (refuse-by-default).",
        ),
    },
  },
  async ({ preset_id, force }) => {
    const body: Record<string, unknown> = { preset_id };
    if (force !== undefined) body.force = force;
    const result = await wp.request("/preset/delete", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_preset_create",
  {
    description:
      'Create a new preset in the Divi 5 registry. For module presets, supply module_name (e.g. "divi/column", "divi/button", "divi/section"), name, and attrs. For group (attribute-level) presets, set type="group" and supply group_name ("divi/font", "divi/button", etc.), group_id ("designTitleText", "button", etc.), and optionally primary_attr_name.',
    inputSchema: {
      module_name: z
        .string()
        .describe(
          'Divi module slug (e.g. "divi/column", "divi/button", "divi/section"). For group presets, this is still required and describes the module the preset originated from.',
        ),
      name: z.string().describe("Display name for the new preset"),
      attrs: z
        .record(z.string(), z.any())
        .describe(
          "Full module attribute bag (same shape as a module's top-level attrs in block markup). Saved to attrs, styleAttrs, and renderAttrs — matches VB save semantics so render cache stays in sync with edit state.",
        ),
      type: z
        .enum(["module", "group"])
        .optional()
        .default("module")
        .describe('"module" (default) or "group" for attribute-level presets.'),
      group_name: z
        .string()
        .optional()
        .describe(
          'Group name (e.g. "divi/font", "divi/button"). Required when type="group".',
        ),
      group_id: z
        .string()
        .optional()
        .describe(
          'Group id (e.g. "designTitleText", "designText", "button"). Required when type="group".',
        ),
      primary_attr_name: z
        .string()
        .optional()
        .describe(
          'Primary attr name for the group (e.g. "title" for designTitleText). Optional.',
        ),
      make_default: z
        .boolean()
        .optional()
        .describe(
          "If true, set this newly-created preset as the default for its module/group after creation. Defaults apply to NEW instances only — existing modules keep their current preset bindings (use diviops_preset_reassign for retroactive swaps). Saves a round-trip vs. calling diviops_preset_set_default after creation.",
        ),
      priority: z
        .number()
        .int()
        .optional()
        .describe(
          "Stack-merge priority. When this preset participates in a stacked-preset arrangement, Divi sorts ascending and merges in priority order — higher number wins the cascade. Default in Divi is 10 when omitted.",
        ),
    },
  },
  async ({ module_name, name, attrs, type, group_name, group_id, primary_attr_name, make_default, priority }) => {
    if (type === "group" && (!group_name || !group_id)) {
      throw new Error(
        'type="group" requires both group_name and group_id. Example: group_name="divi/font", group_id="designTitleText".',
      );
    }
    const body: Record<string, any> = { module_name, name, attrs, type };
    if (group_name) body.group_name = group_name;
    if (group_id) body.group_id = group_id;
    if (primary_attr_name) body.primary_attr_name = primary_attr_name;
    if (make_default) body.make_default = true;
    if (typeof priority === "number") body.priority = priority;
    const result = await wp.request("/preset/create", { method: "POST", body });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_preset_reassign",
  {
    description:
      'Reassign a preset UUID across page content. Covers both module-level refs (`attrs.modulePreset[...]`) and attribute-level group-preset refs (`attrs.groupPreset.<slot>.presetId`), plus — for group presets — registry chain refs: module-bucket presets via top-level `groupPresets.<slot>.presetId`, group-bucket presets via `attrs.groupPreset.<slot>.presetId`. The `scope` param controls which ref types are walked (default "both", auto-selects based on new_uuid\'s bucket). Cross-bucket swaps (module ↔ group) are rejected. When `strip_inline=true` (default), strips inline attrs that duplicate the new preset\'s attrs (otherwise inline wins over preset): for module scope, strips from block root; for group scope, strips per-slot using Divi\'s own slot→target-path resolver (handles composite button groups, `-id-classes` suffix, FormField/checkbox/radio `attrName` mappings, cross-module translation). Both scopes enforce a singular-stack guard (skip strip when slot holds multiple presets). Unmappable group slots skip strip and emit a per-slot advisory at `summary.strip_advisory_per_slot[<module>::<slot>]`; neighbor slots are unaffected. Defaults to dry-run — set mode="apply" to actually rewrite. Use this to consolidate repeated inline styling into a reusable preset after creating one with diviops_preset_create.',
    inputSchema: {
      old_uuid: z
        .string()
        .describe("Preset UUID to replace (can be a dangling/orphan UUID)"),
      new_uuid: z
        .string()
        .describe(
          "New preset UUID to insert. Must already exist in the registry.",
        ),
      page_ids: z
        .array(z.number().int().positive())
        .optional()
        .describe(
          "Restrict to specific post IDs. Omit to scan all pages and posts.",
        ),
      mode: z
        .enum(["dry-run", "apply"])
        .optional()
        .default("dry-run")
        .describe(
          '"dry-run" (default) returns the diff without writing. "apply" rewrites page content (and registry chains for group-scope swaps).',
        ),
      strip_inline: z
        .boolean()
        .optional()
        .default(true)
        .describe(
          "If true (default), strip inline attrs that deep-equal the new preset's attrs so the preset actually takes effect. Applies to both module-scope (block-root strip) and group-scope (per-slot strip via Divi's target-path resolver). Singular-stack guard enforced at both scopes — strip is skipped when the modulePreset stack or a groupPreset slot holds multiple presets. Unmappable group slots skip strip with a per-slot advisory. Set false to swap UUIDs only.",
        ),
      scope: z
        .enum(["module", "group", "both"])
        .optional()
        .default("both")
        .describe(
          '"module" walks `attrs.modulePreset[...]` only. "group" walks `attrs.groupPreset.<slot>.presetId` plus registry chain refs (top-level `groupPresets.<slot>.presetId` on module presets, `attrs.groupPreset.<slot>.presetId` on group presets). "both" (default) auto-selects based on new_uuid\'s bucket — module/group identity is disjoint, so there is one valid walk per swap. An explicit "module" or "group" rejects if new_uuid is in the wrong bucket.',
        ),
    },
  },
  async ({ old_uuid, new_uuid, page_ids, mode, strip_inline, scope }) => {
    const body: Record<string, any> = {
      old_uuid,
      new_uuid,
      mode,
      strip_inline,
      scope,
    };
    if (page_ids) body.page_ids = page_ids;
    const result = await wp.request("/preset/reassign", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_preset_scan_orphans",
  {
    description:
      "Scan page content for modulePreset UUIDs that are not in the D5 registry. Categorizes as dangling orphans (preset was deleted, reference remains) or D4-legacy candidates (preset exists in the legacy builder_global_presets_ng option but not in D5). Use before diviops_preset_reassign to identify stale UUIDs for consolidation.",
  },
  async () => {
    const result = await wp.request("/preset/scan-orphans");
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_preset_set_default",
  {
    description:
      "Set or clear the per-module/group default preset. Two addressing modes: (1) preset_id mode — walks both buckets to locate the preset by UUID, then points the containing module/group's `default` slot at it (or clears it with unset=true). (2) Bucket-addressed clear — pass type + module + unset=true to clear an orphan default pointer when the preset_id no longer exists in items[] (the preset_id walk path can't locate orphans — that's the very state being repaired; surfaced via diviops_preset_audit's `orphan_default_pointers`). Defaults apply to NEW module instances only — existing modules keep their current preset bindings (use diviops_preset_reassign for retroactive swaps). Use diviops_preset_audit's `is_default` and `orphan_default_pointers` fields to verify state before/after.",
    inputSchema: {
      preset_id: z
        .string()
        .optional()
        .describe(
          "Preset UUID. Bucket (module vs. group) and target module/group are auto-resolved from the registry — no need to specify them. Required unless using bucket-addressed clear (type + module + unset=true) to repair an orphan default pointer.",
        ),
      type: z
        .enum(["module", "group"])
        .optional()
        .describe(
          "Bucket-addressed clear: bucket type. Required together with `module` and `unset=true` to clear an orphan default pointer (UUID gone from items[] but `default` still references it).",
        ),
      module: z
        .string()
        .optional()
        .describe(
          'Bucket-addressed clear: module slug or group key (e.g. "divi/blurb", "divi/font"). Required together with `type` and `unset=true`.',
        ),
      unset: z
        .boolean()
        .optional()
        .describe(
          "If true, clear the default pointer. With preset_id, clears the bucket containing that preset. With type+module, clears that bucket directly (use this form for orphan-pointer repair). Defaults to false (set the preset as the default — preset_id required).",
        ),
    },
  },
  async ({ preset_id, type, module, unset }) => {
    const body: Record<string, any> = {};
    if (preset_id !== undefined) body.preset_id = preset_id;
    if (type !== undefined) body.type = type;
    if (module !== undefined) body.module = module;
    if (unset) body.unset = true;
    const result = await wp.request("/preset/set-default", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

// ── Library Tools ───────────────────────────────────────────────────

server.registerTool(
  "diviops_library_list",
  {
    description:
      "List saved Divi Library items. Filter by layout_type (section, row, module) and scope (global, non_global).",
    inputSchema: {
      layout_type: z
        .string()
        .optional()
        .describe(
          'Filter by type: "section", "row", "module", or empty for all',
        ),
      scope: z
        .string()
        .optional()
        .describe('Filter by scope: "global", "non_global", or empty for all'),
      per_page: z
        .number()
        .optional()
        .default(50)
        .describe("Max results (default 50)"),
    },
  },
  async ({ layout_type, scope, per_page }) => {
    const params: Record<string, string> = {};
    if (layout_type) params.layout_type = layout_type;
    if (scope) params.scope = scope;
    if (per_page) params.per_page = String(per_page);
    const result = await wp.request("/library/items", { params });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_library_get",
  {
    description:
      "Get a Divi Library item's content by ID. Returns the raw block markup that can be used with diviops_section_append or diviops_page_update_content.",
    inputSchema: {
      item_id: z.number().describe("Library item ID"),
    },
  },
  async ({ item_id }) => {
    const result = await wp.request(`/library/item/${item_id}`);
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_library_save",
  {
    description:
      'Save Divi block markup to the Divi Library for reuse. Saved items appear in the VB\'s "Add From Library" panel.',
    inputSchema: {
      title: z.string().describe("Display name for the library item"),
      content: z
        .string()
        .describe("Block markup to save (section, row, or module)"),
      layout_type: z
        .enum(["section", "row", "module"])
        .optional()
        .default("section")
        .describe('Type of layout: "section", "row", or "module"'),
      scope: z
        .enum(["global", "non_global"])
        .optional()
        .default("non_global")
        .describe(
          '"global" = synced across all uses, "non_global" = independent copies',
        ),
    },
  },
  async ({ title, content, layout_type, scope }) => {
    const result = await wp.request("/library/save", {
      method: "POST",
      body: {
        title,
        content,
        layout_type,
        scope,
      },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

// ── Theme Builder Tools ─────────────────────────────────────────────

server.registerTool(
  "diviops_tb_template_list",
  {
    description:
      "List all Theme Builder templates with their conditions, layout IDs, and enabled status. Shows which template applies to which pages/post types.",
    inputSchema: {
      per_page: z
        .number()
        .max(100)
        .optional()
        .default(50)
        .describe("Results per page (max 100)"),
      page: z.number().optional().default(1).describe("Page number"),
    },
  },
  async ({ per_page, page }) => {
    const params: Record<string, string> = {};
    if (per_page) params.per_page = String(per_page);
    if (page) params.page = String(page);
    const result = await wp.request("/theme-builder/template/list", { params });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_tb_layout_get",
  {
    description:
      "Get a Theme Builder layout's block markup content (header, body, or footer). Use the layout IDs from diviops_tb_template_list.",
    inputSchema: {
      layout_id: z
        .number()
        .describe(
          "Layout post ID (from template header_layout_id, body_layout_id, or footer_layout_id)",
        ),
    },
  },
  async ({ layout_id }) => {
    const result = await wp.request(`/theme-builder/layout/get/${layout_id}`);
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_tb_layout_update",
  {
    description:
      "Update a Theme Builder layout's block markup (header, body, or footer). Replaces the full content.",
    inputSchema: {
      layout_id: z.number().describe("Layout post ID to update"),
      content: z.string().describe("New block markup content"),
    },
  },
  async ({ layout_id, content }) => {
    const result = await wp.request(`/theme-builder/layout/update/${layout_id}`, {
      method: "PUT",
      body: { content },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_tb_template_create",
  {
    description:
      "Create a Theme Builder template with custom header and/or footer. Automatically creates layout posts, sets conditions, and links to Theme Builder.",
    inputSchema: {
      title: z.string().describe('Template name (e.g. "Landing Pages")'),
      condition: z
        .string()
        .describe(
          'Condition string (e.g. "singular:post_type:page:all", "singular:post_type:project:all", "archive:taxonomy:category:all")',
        ),
      header_content: z
        .string()
        .optional()
        .default("")
        .describe(
          "Header block markup (empty = inherit from default template)",
        ),
      footer_content: z
        .string()
        .optional()
        .default("")
        .describe(
          "Footer block markup (empty = inherit from default template)",
        ),
    },
  },
  async ({ title, condition, header_content, footer_content }) => {
    const result = await wp.request("/theme-builder/template/create", {
      method: "POST",
      body: { title, condition, header_content, footer_content },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

// ── Canvas Tools ────────────────────────────────────────────────────

server.registerTool(
  "diviops_canvas_create",
  {
    description:
      "Create a canvas (off-canvas workspace) linked to a page. Used for popups, off-canvas menus, modals. Content uses standard Divi block markup.",
    inputSchema: {
      title: z
        .string()
        .describe('Canvas name (e.g. "Popup Menu", "Modal Contact Form")'),
      parent_page_id: z.number().describe("Parent page post ID"),
      content: z
        .string()
        .optional()
        .default("")
        .describe("Divi block markup for canvas content"),
      canvas_id: z
        .string()
        .optional()
        .describe("Canvas UUID (auto-generated if omitted)"),
      append_to_main: z
        .enum(["above", "below"])
        .optional()
        .describe("Auto-append position relative to main content"),
      z_index: z
        .number()
        .optional()
        .describe("Layering order (higher = on top)"),
    },
  },
  async ({
    title,
    parent_page_id,
    content,
    canvas_id,
    append_to_main,
    z_index,
  }) => {
    const body: Record<string, unknown> = {
      title,
      parent_page_id,
      content: content ?? "",
    };
    if (canvas_id) body.canvas_id = canvas_id;
    if (append_to_main) body.append_to_main = append_to_main;
    if (z_index !== undefined) body.z_index = z_index;
    const result = await wp.request("/canvas/create", { method: "POST", body });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_canvas_list",
  {
    description:
      "List canvases (off-canvas workspaces). Filter by parent page or list all.",
    inputSchema: {
      parent_page_id: z
        .number()
        .optional()
        .describe("Filter by parent page ID (omit for all canvases)"),
      per_page: z
        .number()
        .int()
        .min(1)
        .max(100)
        .optional()
        .default(50)
        .describe("Max results (default 50, 1-100)"),
    },
  },
  async ({ parent_page_id, per_page }) => {
    const params: Record<string, string> = {};
    if (parent_page_id) params.parent_page_id = String(parent_page_id);
    if (per_page) params.per_page = String(per_page);
    const result = await wp.request("/canvas/list", { params });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_canvas_get",
  {
    description: "Get a canvas's block content and metadata.",
    inputSchema: {
      canvas_post_id: z
        .number()
        .describe("Canvas post ID (from diviops_canvas_list)"),
    },
  },
  async ({ canvas_post_id }) => {
    const result = await wp.request(`/canvas/get/${canvas_post_id}`);
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_canvas_update",
  {
    description:
      "Update a canvas's content and/or metadata. Content replaces the entire canvas.",
    inputSchema: {
      canvas_post_id: z.number().describe("Canvas post ID"),
      content: z
        .string()
        .optional()
        .describe("New block markup (replaces entire content)"),
      title: z.string().optional().describe("New canvas title"),
      append_to_main: z
        .enum(["above", "below", ""])
        .optional()
        .describe('Append position: "above", "below", or "" to clear'),
      z_index: z.number().optional().describe("Layering order"),
    },
  },
  async ({ canvas_post_id, content, title, append_to_main, z_index }) => {
    const body: Record<string, unknown> = {};
    if (content !== undefined) body.content = content;
    if (title !== undefined) body.title = title;
    if (append_to_main !== undefined) body.append_to_main = append_to_main;
    if (z_index !== undefined) body.z_index = z_index;
    const result = await wp.request(`/canvas/update/${canvas_post_id}`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_canvas_delete",
  {
    description: "Delete a canvas. This permanently removes the canvas post.",
    inputSchema: {
      canvas_post_id: z.number().describe("Canvas post ID to delete"),
    },
  },
  async ({ canvas_post_id }) => {
    const result = await wp.request(`/canvas/delete/${canvas_post_id}`, {
      method: "POST",
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

// ── WP-CLI ──────────────────────────────────────────────────────────

server.registerTool(
  "diviops_meta_wp_cli",
  {
    description:
      "Run a WP-CLI command on the WordPress site. Requires WP_PATH env var (LOCAL_SITE_ID auto-detected from Local by Flywheel), or WP_CLI_CMD for containerized wrappers. Commands validated against a safety allowlist. Default tier covers read ops across options/posts/post-types/taxonomies/users/info/core/db, non-destructive writes (post/term create+update, post meta read/write, cache/rewrite/transient flush), ACF/SCF schema ops (`acf export/import/field-group list/get` plus SCF 6.8.4+ `scf json {status,sync,import,export}` and the `acf json …` aliases), and WXR export. Extended tier (requires DIVIOPS_WP_CLI_ALLOW env var) adds destructive or bulk-modifying ops: option update, post/post meta/term delete, search-replace, import, plugin activate/deactivate, eval-file. Filesystem-touching commands (`wp export`, `acf export/import`, `scf|acf json export/import`) are additionally constrained: path arguments must resolve under a safe root (defaults to `<WP_PATH>/.diviops-tmp/`, overridable via DIVIOPS_WP_CLI_SAFE_FS_ROOT, disable via DIVIOPS_WP_CLI_UNSAFE_FS=1); `wp export` and `scf json export` require an explicit `--dir=<path>` (or `--stdout`). In WP_CLI_CMD wrapper mode, DIVIOPS_WP_CLI_SAFE_FS_ROOT is required for FS-sensitive commands. Prefer the typed `diviops_scf_*` wrappers for SCF round-trips — they're easier to invoke and accept the same safe-root scoping. Use --format=json for structured output. Full allowlist + tier rationale + filesystem semantics in the MCP server README.",
    inputSchema: {
      command: z
        .string()
        .describe(
          'WP-CLI command without the "wp" prefix. E.g. "option get blogname", "post list --format=json", "export --dir=$DIVIOPS_WP_CLI_SAFE_FS_ROOT --filename_format={site}.{date}.xml"',
        ),
    },
  },
  async ({ command }) => {
    if (!wpCli) {
      return {
        content: [
          {
            type: "text" as const,
            text: 'WP-CLI not configured. Set the WP_PATH environment variable to your WordPress installation path.\n\nExample:\n  claude mcp add diviops-mcp -- env WP_URL=http://site.local WP_USER=admin WP_APP_PASSWORD="xxxx" WP_PATH="/Users/you/Local Sites/your-site/app/public" npx @diviops/mcp-server\n\nThe Local by Flywheel site ID is auto-detected from WP_PATH. Set LOCAL_SITE_ID explicitly if auto-detection fails.',
          },
        ],
      };
    }

    const result = await wpCli.run(command);
    const output = result.success
      ? result.output
      : `Error: ${result.error}\n${result.output}`;
    return { content: [{ type: "text" as const, text: output }] };
  },
);

// ── SCF (Secure Custom Fields / ACF) wrappers ───────────────────────
//
// Typed wrappers over SCF 6.8.4+'s `wp scf json {status,sync,import,export}`
// CLI family (also reachable as `wp acf json …`). The plugin file at
// wp-content/plugins/secure-custom-fields/src/CLI/JsonCommand.php is the
// upstream source of truth for flag shapes — keep these wrappers aligned.

function ensureWpCli(): { ok: true } | { ok: false; text: string } {
  if (!wpCli) {
    return {
      ok: false,
      text:
        "WP-CLI not configured. Set WP_PATH (Local by Flywheel auto-detect) " +
        "or WP_CLI_CMD (containerized wrappers) to enable SCF round-trip tools.",
    };
  }
  return { ok: true };
}

function pushScfFlag(args: string[], name: string, value: string | undefined): void {
  if (!value) return;
  // Each `--name=value` becomes a single argv entry — execFile handles spaces
  // and quotes inside the value transparently. No string concatenation, no
  // parseCommand round-trip, so values like "Bob's Group" or filenames with
  // spaces flow through verbatim.
  args.push(`--${name}=${value}`);
}

server.registerTool(
  "diviops_scf_status",
  {
    description:
      "Show SCF (Secure Custom Fields) sync status — how many field groups, post types, taxonomies, and options pages have JSON-on-disk newer than the database (or absent from DB). Read-only. Wraps `wp scf json status`. Requires SCF 6.8.4+ and WP_PATH or WP_CLI_CMD.",
    inputSchema: {
      type: z
        .enum(["field-group", "post-type", "taxonomy", "options-page"])
        .optional()
        .describe(
          "Limit to a single item type. Defaults to all types. options-page requires ACF PRO.",
        ),
      detailed: z
        .boolean()
        .optional()
        .describe(
          "List the individual pending items (key/title/type/action) instead of just counts.",
        ),
    },
  },
  async ({ type, detailed }) => {
    const gate = ensureWpCli();
    if (!gate.ok) {
      return { content: [{ type: "text" as const, text: gate.text }] };
    }
    const args = ["scf", "json", "status", "--format=json"];
    pushScfFlag(args, "type", type);
    if (detailed) args.push("--detailed");
    const result = await wpCli!.runArgs(args);
    const output = result.success
      ? result.output
      : `Error: ${result.error}\n${result.output}`;
    return { content: [{ type: "text" as const, text: output }] };
  },
);

server.registerTool(
  "diviops_scf_export",
  {
    description:
      "Export SCF field groups, post types, taxonomies, and options pages as JSON — to a directory under the safe-root (`<WP_PATH>/.diviops-tmp/` by default, override via DIVIOPS_WP_CLI_SAFE_FS_ROOT) or to stdout. Wraps `wp scf json export`. Either `dir` or `stdout: true` is required. Filters can be combined; without filters, all items are exported. Note: SCF writes a fixed filename `acf-export-YYYY-MM-DD.json` inside `dir` — two exports on the same day silently overwrite. Copy/rename if you're archiving baselines.",
    inputSchema: {
      dir: z
        .string()
        .optional()
        .describe(
          "Absolute output directory under the WP-CLI safe-root. Mutually exclusive with `stdout`. SCF writes a single `acf-export-YYYY-MM-DD.json` file inside this dir.",
        ),
      stdout: z
        .boolean()
        .optional()
        .describe(
          "Print JSON to stdout instead of writing a file. Mutually exclusive with `dir`.",
        ),
      field_groups: z
        .string()
        .optional()
        .describe(
          "Comma-separated field-group ACF keys (`group_abc123`) or admin titles (`My Field Group`). NOT WP post slugs — SCF matches against the def's `key` field or its `title` (case-insensitive). Use `diviops_scf_field_group_list` to discover keys (post_name column).",
        ),
      post_types: z
        .string()
        .optional()
        .describe(
          "Comma-separated SCF post-type def keys (`post_type_xxx`) or admin titles (`Programm`). IMPORTANT: this is the SCF def's identifier, NOT the registered post-type slug (`event`, `book`). The registered slug is what `wp post list` and REST URLs use, but SCF's filter matches against the def's `key` field or its `title`. To discover def keys, run `diviops_scf_export --stdout` (no filter) and inspect the top-level entries with `parent='post-type'`.",
        ),
      taxonomies: z
        .string()
        .optional()
        .describe(
          "Comma-separated SCF taxonomy def keys (`taxonomy_xxx`) or admin titles. Same caveat as `post_types`: NOT the registered taxonomy slug — the SCF def's `key` or `title`. Discover via `diviops_scf_export --stdout`.",
        ),
      options_pages: z
        .string()
        .optional()
        .describe(
          "Comma-separated options-page def keys or admin titles. Requires ACF PRO.",
        ),
    },
  },
  async ({ dir, stdout, field_groups, post_types, taxonomies, options_pages }) => {
    const gate = ensureWpCli();
    if (!gate.ok) {
      return { content: [{ type: "text" as const, text: gate.text }] };
    }
    if (!dir && !stdout) {
      return {
        content: [
          {
            type: "text" as const,
            text: "Error: pass either `dir` (absolute path under DIVIOPS_WP_CLI_SAFE_FS_ROOT) or `stdout: true`.",
          },
        ],
      };
    }
    if (dir && stdout) {
      return {
        content: [
          {
            type: "text" as const,
            text: "Error: `dir` and `stdout` are mutually exclusive — pick one.",
          },
        ],
      };
    }
    const args = ["scf", "json", "export"];
    if (stdout) args.push("--stdout");
    pushScfFlag(args, "dir", dir);
    pushScfFlag(args, "field-groups", field_groups);
    pushScfFlag(args, "post-types", post_types);
    pushScfFlag(args, "taxonomies", taxonomies);
    pushScfFlag(args, "options-pages", options_pages);
    const result = await wpCli!.runArgs(args);
    const output = result.success
      ? result.output
      : `Error: ${result.error}\n${result.output}`;
    return { content: [{ type: "text" as const, text: output }] };
  },
);

server.registerTool(
  "diviops_scf_import",
  {
    description:
      "Import SCF field groups, post types, taxonomies, options pages from a JSON file. Mutates the database. File path must resolve under the safe-root (`<WP_PATH>/.diviops-tmp/` by default, override via DIVIOPS_WP_CLI_SAFE_FS_ROOT). Idempotent — existing items with matching keys are updated. Wraps `wp scf json import <file>`.",
    inputSchema: {
      file: z
        .string()
        .describe(
          "Absolute path to the .json file to import. Must resolve under DIVIOPS_WP_CLI_SAFE_FS_ROOT.",
        ),
    },
  },
  async ({ file }) => {
    const gate = ensureWpCli();
    if (!gate.ok) {
      return { content: [{ type: "text" as const, text: gate.text }] };
    }
    const result = await wpCli!.runArgs(["scf", "json", "import", file]);
    const output = result.success
      ? result.output
      : `Error: ${result.error}\n${result.output}`;
    return { content: [{ type: "text" as const, text: output }] };
  },
);

server.registerTool(
  "diviops_scf_sync",
  {
    description:
      "Apply pending JSON-on-disk SCF changes to the database. Reads JSON files from the theme/plugin acf-json directory and creates/updates DB entries. Defaults to `dry_run: true` for safety — caller must opt in to mutation. Wraps `wp scf json sync`.",
    inputSchema: {
      type: z
        .enum(["field-group", "post-type", "taxonomy", "options-page"])
        .optional()
        .describe("Limit sync to a single item type."),
      key: z
        .string()
        .optional()
        .describe("Sync only the item with this ACF key (e.g. `group_abc123`)."),
      dry_run: z
        .boolean()
        .optional()
        .default(true)
        .describe(
          "Preview pending changes without mutating the database. Defaults to true. Pass `false` to commit.",
        ),
    },
  },
  async ({ type, key, dry_run }) => {
    const gate = ensureWpCli();
    if (!gate.ok) {
      return { content: [{ type: "text" as const, text: gate.text }] };
    }
    const args = ["scf", "json", "sync"];
    pushScfFlag(args, "type", type);
    pushScfFlag(args, "key", key);
    if (dry_run !== false) args.push("--dry-run");
    const result = await wpCli!.runArgs(args);
    const output = result.success
      ? result.output
      : `Error: ${result.error}\n${result.output}`;
    return { content: [{ type: "text" as const, text: output }] };
  },
);

server.registerTool(
  "diviops_scf_field_group_list",
  {
    description:
      "List all SCF/ACF field groups in the database (post_name = ACF key, post_title, post_status, post_modified). Read-only. Queries the underlying `acf-field-group` post type via `wp post list` — works on both SCF 6.8.4+ (which dropped the legacy `wp acf field-group …` family in favor of the `wp scf json` namespace) and older ACF installs.",
  },
  async () => {
    const gate = ensureWpCli();
    if (!gate.ok) {
      return { content: [{ type: "text" as const, text: gate.text }] };
    }
    const result = await wpCli!.runArgs([
      "post",
      "list",
      "--post_type=acf-field-group",
      "--post_status=any",
      "--fields=ID,post_name,post_title,post_status,post_modified",
      "--format=json",
    ]);
    const output = result.success
      ? result.output
      : `Error: ${result.error}\n${result.output}`;
    return { content: [{ type: "text" as const, text: output }] };
  },
);

server.registerTool(
  "diviops_scf_field_group_get",
  {
    description:
      "Fetch a single SCF/ACF field group from the `acf-field-group` post type — by ACF key (`group_abc123`, looked up via `post_name`) or by numeric WP post ID. Returns the WP post fields (post_name, post_title, post_content with serialized fields blob, post_status, post_modified). For the parsed/structured field tree including nested fields, use `diviops_scf_export --field-groups=<key> --stdout` instead. Read-only. SCF 6.8.4 dropped the legacy `wp acf field-group get` command, so this wrapper queries the post type directly via `wp post`.",
    inputSchema: {
      key: z
        .string()
        .describe(
          "ACF field-group key (`group_abc123`, matched against post_name) or numeric WP post ID.",
        ),
    },
  },
  async ({ key }) => {
    const gate = ensureWpCli();
    if (!gate.ok) {
      return { content: [{ type: "text" as const, text: gate.text }] };
    }
    // If the input looks like a numeric ID, hand it to `wp post get` directly.
    // Otherwise treat it as an ACF key and resolve via post_name first.
    const isNumericId = /^\d+$/.test(key);
    if (isNumericId) {
      const result = await wpCli!.runArgs([
        "post",
        "get",
        key,
        "--format=json",
      ]);
      const output = result.success
        ? result.output
        : `Error: ${result.error}\n${result.output}`;
      return { content: [{ type: "text" as const, text: output }] };
    }
    // Resolve ACF key → post ID via `wp post list --name=<key>`. Single-row
    // lookup; returns [] if the key isn't found.
    const lookup = await wpCli!.runArgs([
      "post",
      "list",
      "--post_type=acf-field-group",
      "--post_status=any",
      `--name=${key}`,
      "--fields=ID",
      "--format=json",
    ]);
    if (!lookup.success) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error looking up field-group key "${key}": ${lookup.error}\n${lookup.output}`,
          },
        ],
      };
    }
    let postId: string | null = null;
    try {
      const rows = JSON.parse(lookup.output) as Array<{ ID: number }>;
      if (Array.isArray(rows) && rows.length > 0) {
        postId = String(rows[0].ID);
      }
    } catch {
      // Fall through — postId stays null, return a clear "not found" error.
    }
    if (!postId) {
      return {
        content: [
          {
            type: "text" as const,
            text: `No field-group found for key "${key}". Expected an ACF key (e.g. "group_5f8a1b2c3d4e5") or a numeric WP post ID (e.g. "287"). Use diviops_scf_field_group_list to see available keys (post_name field).`,
          },
        ],
      };
    }
    const result = await wpCli!.runArgs([
      "post",
      "get",
      postId,
      "--format=json",
    ]);
    const output = result.success
      ? result.output
      : `Error: ${result.error}\n${result.output}`;
    return { content: [{ type: "text" as const, text: output }] };
  },
);

// ── Connection ──────────────────────────────────────────────────────

server.registerTool(
  "diviops_meta_ping",
  {
    description:
      "Test the connection to the WordPress site and verify the Divi MCP plugin is active.",
  },
  async () => {
    const result = await wp.testConnection();
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_meta_info",
  {
    description:
      "Returns DiviOps MCP server identity, version, license type, and available capabilities.",
  },
  async () => {
    const info = {
      brand: "DiviOps",
      server: "diviops-mcp",
      version: SERVER_VERSION,
      license: "MIT",
      capabilities: [
        "pages",
        "modules",
        "presets",
        "library",
        "theme_builder",
        "canvas",
        "variables",
        "templates",
        "icons",
        "validation",
        "preview",
      ],
      wp_cli: wpCli ? wpCli.getAllowedCommands() : false,
    };
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(info) },
      ],
    };
  },
);

// ── Resources ────────────────────────────────────────────────────────

server.registerResource(
  "divi-block-format-guide",
  "divi://block-format-guide",
  {},
  async () => ({
    contents: [
      {
        uri: "divi://block-format-guide",
        mimeType: "text/markdown",
        text: BLOCK_FORMAT_GUIDE,
      },
    ],
  }),
);

const BLOCK_FORMAT_GUIDE = `# Divi 5 Block Markup Format

Divi 5 uses WordPress block markup (Gutenberg-style comments) to define layouts.

## Basic Structure

Every Divi layout follows this hierarchy:
\`\`\`
Section → Row → Column → Module
\`\`\`

## Example: Simple Text Section

\`\`\`html
<!-- wp:divi/section -->
<!-- wp:divi/row -->
<!-- wp:divi/column -->
<!-- wp:divi/text {"module":{"meta":{"adminLabel":{"desktop":{"value":"Heading"}}},"advanced":{"text":{"text":{"desktop":{"value":"<h1>Hello World</h1><p>This is a paragraph.</p>"}}}}}} -->
<!-- /wp:divi/text -->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
\`\`\`

## Key Patterns

### Module Attributes
Attributes are JSON in the block comment. Structure:
- \`module.meta\` — Admin label, visibility, etc.
- \`module.advanced\` — Content settings (text, links, etc.)
- \`module.decoration\` — Design/style settings (colors, fonts, spacing)

### Multi-Column Layout
\`\`\`html
<!-- wp:divi/section -->
<!-- wp:divi/row -->
<!-- wp:divi/column {"attrs":{"type":"1_2"}} -->
<!-- wp:divi/text ... --><!-- /wp:divi/text -->
<!-- /wp:divi/column -->
<!-- wp:divi/column {"attrs":{"type":"1_2"}} -->
<!-- wp:divi/image ... --><!-- /wp:divi/image -->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
\`\`\`

### Common Modules
- \`divi/text\` — Rich text content
- \`divi/image\` — Images
- \`divi/button\` — CTA buttons
- \`divi/heading\` — Headings
- \`divi/blurb\` — Icon + text cards
- \`divi/accordion\` — Collapsible sections
- \`divi/slider\` — Slide carousels
- \`divi/gallery\` — Image galleries
- \`divi/video\` — Video embeds
- \`divi/divider\` — Visual separators
- \`divi/cta\` — Call to action blocks

## Tips
1. Always use \`diviops_schema_get_module\` to check exact attribute names before building markup.
2. Use \`diviops_page_get_layout\` on existing pages to learn the format from real examples.
3. Use \`diviops_render_preview\` to validate markup before saving.
`;

// ── Template Resources ──────────────────────────────────────────────

const templatesDir = join(__dirname, "..", "templates");

function loadTemplates(): Map<string, any> {
  const templates = new Map<string, any>();
  try {
    const files = readdirSync(templatesDir).filter((f) => f.endsWith(".json"));
    for (const file of files) {
      const content = readFileSync(join(templatesDir, file), "utf-8");
      const template = JSON.parse(content);
      const name = file.replace(".json", "");
      templates.set(name, template);
    }
  } catch (e) {
    console.error("Warning: Could not load templates:", e);
  }
  return templates;
}

const templates = loadTemplates();

// Register a list tool so Claude can discover available templates
server.registerTool(
  "diviops_template_list",
  {
    description:
      "List available Divi page section templates. Each template contains verified block markup patterns that can be used as a base for page generation.",
  },
  async () => {
    const list = Array.from(templates.entries()).map(([name, t]) => ({
      name,
      description: t.description,
      customizable: t.customizable,
      requires_css: t.requires_css ?? false,
    }));
    return {
      content: [{ type: "text" as const, text: JSON.stringify(list) }],
    };
  },
);

server.registerTool(
  "diviops_template_get",
  {
    description:
      "Get a specific Divi template with verified block markup, customizable variables, and usage notes. Use this to generate pages based on proven patterns.",
    inputSchema: {
      template_name: z
        .string()
        .describe(
          'Template name (e.g. "hero-centered", "hero-split", "hero-marquee", "features-blurbs", "cta-gradient", "cards-flex")',
        ),
    },
  },
  async ({ template_name }) => {
    const template = templates.get(template_name);
    if (!template) {
      const available = Array.from(templates.keys()).join(", ");
      return {
        content: [
          {
            type: "text" as const,
            text: `Template "${template_name}" not found. Available: ${available}`,
          },
        ],
      };
    }
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(template) },
      ],
    };
  },
);

// ── Variable Manager CRUD ─────────────────────────────────────────────

server.registerTool(
  "diviops_variable_list",
  {
    description:
      "List all design token variables from the Divi Variable Manager. Colors (gcid-*) come from et_global_data, numbers/strings/etc (gvid-*) from et_divi_global_variables. Filter by type or ID prefix.",
    inputSchema: {
      type: z
        .enum(["colors", "numbers", "strings", "images", "links", "fonts"])
        .optional()
        .describe("Filter by variable type"),
      prefix: z
        .string()
        .optional()
        .describe(
          'Filter by ID prefix (e.g. "gcid-oa-" for oa design system colors)',
        ),
    },
  },
  async ({ type, prefix }) => {
    const params: Record<string, string> = {};
    if (type) params.type = type;
    if (prefix) params.prefix = prefix;
    const result = await wp.request("/variable/list", { params });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_variable_create",
  {
    description:
      'Create a design token variable in the Divi Variable Manager. Colors (type "colors") use gcid-* IDs and hex values. Numbers/strings/etc use gvid-* IDs. For type="numbers" fluid tokens, pass min+max shorthand (anchors default to 320px/1920px) or explicit targets — server generates arithmetically-correct clamp() formulas. All-px inputs emit px (safe default, root-agnostic). Rem inputs OR rem output require explicit opt-in: pass output_unit="rem" (accepts the 1rem=16px default) or root_font_size_px:N (declares your site\'s actual root font-size for correct rem emission on non-16px-root sites). Mutually exclusive with value.',
    inputSchema: {
      type: z
        .enum(["colors", "numbers", "strings", "images", "links", "fonts"])
        .describe("Variable type"),
      id: z
        .string()
        .optional()
        .describe(
          'Variable ID (e.g. "gcid-oa-accent" for colors, "gvid-oa-size-xl" for numbers). Auto-generated if omitted.',
        ),
      label: z
        .string()
        .describe("Human-readable label shown in the VB Variable Manager"),
      value: z
        .string()
        .optional()
        .describe(
          'Variable value (required unless using fluid min/max/targets for type=numbers): hex color for colors (e.g. "#3a7a6a"), CSS value for numbers (e.g. "clamp(30px, 8vw, 100px)" or "2rem")',
        ),
      min: z
        .string()
        .optional()
        .describe(
          'Fluid minimum value (e.g. "20px" or "1.25rem"). Paired with max. Anchors default to 320px/1920px. Rem inputs require explicit opt-in via output_unit or root_font_size_px. type="numbers" only.',
        ),
      max: z
        .string()
        .optional()
        .describe(
          'Fluid maximum value (e.g. "60px" or "3.75rem"). Paired with min.',
        ),
      targets: z
        .record(z.string(), z.string())
        .refine((m) => !m || Object.keys(m).length === 2, {
          message: "targets must contain exactly 2 viewport entries",
        })
        .optional()
        .describe(
          'Explicit two-anchor fluid spec, object keyed by viewport width (px only). Example: {"320px":"20px","1920px":"60px"} → clamp(20px, 12px + 2.5vw, 60px). Exactly 2 entries required. type="numbers" only. Mutually exclusive with min/max. Rem values require explicit opt-in via output_unit or root_font_size_px.',
        ),
      output_unit: z
        .enum(["rem", "px"])
        .optional()
        .describe(
          'Unit for generated clamp formula. Omit for all-px inputs (safe default — emits px, root-agnostic). Pass "rem" to emit rem (accepts the 1rem=16px assumption unless root_font_size_px is also passed); required when inputs include rem unless root_font_size_px is passed. Pass "px" to force px output regardless of input unit.',
        ),
      root_font_size_px: z
        .number()
        .positive()
        .optional()
        .describe(
          "Site's root font-size in px (positive number), used for correct rem↔px conversion in the generated clamp() formula. Defaults to 16 (standard browser default) when omitted. Pass explicitly for sites that customize `html { font-size }` (e.g. 10 for `html { font-size: 62.5% }`, 20 for `html { font-size: 20px }`). Also counts as an opt-in signal for rem emission — passing it alone (without output_unit) implies rem output. Only applies when min/max/targets is used.",
        ),
    },
  },
  async ({
    type,
    id,
    label,
    value,
    min,
    max,
    targets,
    output_unit,
    root_font_size_px,
  }) => {
    const body: Record<string, unknown> = { type, label };
    if (value !== undefined) body.value = value;
    if (id) body.id = id;
    if (min !== undefined) body.min = min;
    if (max !== undefined) body.max = max;
    if (targets !== undefined) body.targets = targets;
    if (output_unit !== undefined) body.output_unit = output_unit;
    if (root_font_size_px !== undefined) body.root_font_size_px = root_font_size_px;
    const result = await wp.request("/variable/create", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_variable_create_fluid_system",
  {
    description:
      "Batch-emit a fluid typography + spacing + radius variable set in one call — mirrors Divi 5.4.0's Variable Generator Modal at the algorithm level (clamp() math is identical to diviops_variable_create's fluid mode) but layers profile-selectable anchors over it. Each category is independent and optional. Use for: (1) bootstrapping a design system in one call instead of 20+ individual diviops_variable_create invocations; (2) mirroring ET's variable layout so your tokens coexist with VB-generated ones in the Variable Manager; (3) deterministic preflight via dry_run before committing the registry change. By default, refuses to overwrite existing IDs (returns them in `skipped`) — pass overwrite=true to update in place. Persists in a single atomic write to the variable registry; mid-batch failures roll back cleanly.",
    inputSchema: {
      profile: z
        .enum(["divi-default", "wide", "custom"])
        .optional()
        .default("divi-default")
        .describe(
          'Anchor preset for the underlying clamp() math. "divi-default" (360→1350) matches Divi 5.4.0\'s Variable Generator Modal defaults; "wide" (320→1920) covers a wider device span (the diviops convention); "custom" requires custom_anchors. Affects ALL three categories uniformly.',
        ),
      custom_anchors: z
        .object({
          min_viewport_px: z.number().positive(),
          max_viewport_px: z.number().positive(),
        })
        .refine((a) => a.max_viewport_px > a.min_viewport_px, {
          message: "custom_anchors.max_viewport_px must be > min_viewport_px",
        })
        .optional()
        .describe(
          'Required when profile="custom". Defines the (min_viewport_px, max_viewport_px) pair the clamp() formulas anchor to. max must be > min. (The profile/custom_anchors pairing is also enforced server-side, returning 400 invalid_profile if profile="custom" is sent without custom_anchors.)',
        ),
      typography: z
        .object({
          base_px: z
            .number()
            .positive()
            .describe(
              "Base body size in px. Step N's value = base_px × ratio^(steps-1). h1 = largest (top of chain), hN = base.",
            ),
          ratio: z
            .union([
              z.number().positive(),
              z.enum([
                "minor-second",
                "major-second",
                "minor-third",
                "major-third",
                "perfect-fourth",
                "augmented-fourth",
                "perfect-fifth",
                "golden",
              ]),
            ])
            .describe(
              "Modular-scale ratio. Pass a named scale ('major-third'=1.25, 'perfect-fifth'=1.5, 'golden'=1.618, etc.) or a raw number. Step N is base × ratio^(steps-N), so h1 (step 1) is the largest size when steps>1.",
            ),
          steps: z
            .number()
            .int()
            .min(1)
            .max(20)
            .describe(
              "Number of typography steps to emit (e.g. 6 = h1..h6). Cap is 20 to prevent runaway scale chains.",
            ),
          max_ratio: z
            .union([
              z.number().positive(),
              z.enum([
                "minor-second",
                "major-second",
                "minor-third",
                "major-third",
                "perfect-fourth",
                "augmented-fourth",
                "perfect-fifth",
                "golden",
              ]),
            ])
            .optional()
            .describe(
              "Optional ratio at max viewport. Defaults to ratio (same chain at both anchors). Pass a larger value (e.g. ratio=1.2 + max_ratio=1.333) for a more dramatic scale on large screens.",
            ),
          fluid_growth: z
            .number()
            .positive()
            .optional()
            .describe(
              "Multiplicative growth factor at max viewport. Default 1.0 = discrete (each step emits a fixed value, no clamp growth). Common values: 1.2-1.5 for moderate fluid scaling. Step N's clamp goes from `base × ratio^(steps-N)` at min_viewport to `base × max_ratio^(steps-N) × fluid_growth` at max_viewport.",
            ),
          name_prefix: z
            .string()
            .optional()
            .describe(
              "ID prefix per step. Default 'h' → IDs become gvid-{namespace}-size-h1..hN. Pass 'display' for hero sizes ('gvid-{namespace}-size-display1..').",
            ),
        })
        .optional(),
      spacing: z
        .object({
          min_px: z.number().min(0),
          max_px: z.number().positive(),
          steps: z.number().int().min(1).max(30),
          scale: z
            .enum(["linear", "geometric"])
            .optional()
            .default("linear")
            .describe(
              "Distribution between min_px and max_px. 'linear' = equal arithmetic spacing (best for spacing scales). 'geometric' = equal multiplicative spacing (best for typography-like scales). geometric requires min_px > 0.",
            ),
          fluid_growth: z
            .number()
            .positive()
            .optional()
            .describe(
              "Multiplicative growth factor at max viewport. Default 1.0 = discrete (each spacing token is constant across viewports — typical design-system behavior). > 1.0 = fluid (each token scales from `value` at min_viewport to `value × fluid_growth` at max_viewport).",
            ),
          name_prefix: z
            .string()
            .optional()
            .describe(
              "ID prefix. Default 'space' → gvid-{namespace}-space-1..N.",
            ),
        })
        .optional(),
      radius: z
        .object({
          min_px: z.number().min(0),
          max_px: z.number().positive(),
          steps: z.number().int().min(1).max(30),
          scale: z.enum(["linear", "geometric"]).optional().default("linear"),
          fluid_growth: z
            .number()
            .positive()
            .optional()
            .describe(
              "Multiplicative growth factor at max viewport. Default 1.0 = discrete. Most radius tokens stay discrete; pass > 1.0 only when you want corners to grow with viewport.",
            ),
          name_prefix: z
            .string()
            .optional()
            .describe(
              "ID prefix. Default 'rounded' → gvid-{namespace}-rounded-1..N.",
            ),
        })
        .optional(),
      namespace: z
        .string()
        .regex(/^[a-z0-9_-]+$/i, {
          message:
            "namespace must match [a-z0-9_-]+ (case-insensitive; lowercased server-side). Inputs outside this charset are rejected explicitly rather than silently rewritten — passing 'o a' or 'oa!' would alias onto the default 'oa' namespace and risk overwriting unrelated tokens.",
        })
        .optional()
        .default("oa")
        .describe(
          "Namespace inserted into every generated ID (gvid-{namespace}-*). Default 'oa' matches existing diviops convention. Validated against [a-z0-9_-]+ on both client and server (rejects rather than sanitizes — see message for rationale).",
        ),
      output_unit: z
        .enum(["rem", "px"])
        .optional()
        .describe(
          'Unit for emitted clamp() formulas. Defaults to "px" (root-agnostic, safe). Pass "rem" to opt into rem emission (bakes the 1rem=16px assumption unless root_font_size_px is also passed).',
        ),
      root_font_size_px: z
        .number()
        .positive()
        .optional()
        .describe(
          "Site's actual root font-size in px. Pass for non-16px-root sites (e.g. 10 for `html { font-size: 62.5% }`). Passing this alone implies output_unit='rem'.",
        ),
      dry_run: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "Preview the full plan without persisting. Returns identical `created`/`skipped` shape so callers can audit IDs and clamp() values before committing.",
        ),
      overwrite: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "When false (default), existing IDs land in `skipped` with the existing value. When true, each existing ID is updated in place (label + value rewritten, order preserved).",
        ),
    },
  },
  async ({
    profile,
    custom_anchors,
    typography,
    spacing,
    radius,
    namespace,
    output_unit,
    root_font_size_px,
    dry_run,
    overwrite,
  }) => {
    const body: Record<string, unknown> = { profile };
    if (custom_anchors !== undefined) body.custom_anchors = custom_anchors;
    if (typography !== undefined) body.typography = typography;
    if (spacing !== undefined) body.spacing = spacing;
    if (radius !== undefined) body.radius = radius;
    if (namespace !== undefined) body.namespace = namespace;
    if (output_unit !== undefined) body.output_unit = output_unit;
    if (root_font_size_px !== undefined) body.root_font_size_px = root_font_size_px;
    if (dry_run !== undefined) body.dry_run = dry_run;
    if (overwrite !== undefined) body.overwrite = overwrite;
    const result = await wp.request("/variable/create-fluid-system", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_variable_delete",
  {
    description:
      "Delete a design token variable by ID. Auto-detects storage from ID prefix (gcid-* = colors, gvid-* = numbers/strings/etc). Returns HTTP 409 when live references exist unless force=true — run diviops_variable_scan_orphans to see where the references live. Returns HTTP 403 for Divi's customizer-bound defaults (gcid-primary-color, gcid-secondary-color, gcid-heading-color, gcid-body-color, gcid-link-color); those are managed via WP Customizer theme options and can't be deleted via this tool.",
    inputSchema: {
      id: z
        .string()
        .describe(
          'Variable ID to delete (e.g. "gcid-oa-accent" or "gvid-oa-size-xl")',
        ),
      force: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "Delete even if live references exist. Orphans will remain in page/preset content and render as invalid CSS on the frontend — run diviops_variable_scan_orphans afterwards to audit.",
        ),
    },
  },
  async ({ id, force }) => {
    const result = await wp.request("/variable/delete", {
      method: "POST",
      body: { id, force },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_variable_scan_orphans",
  {
    description:
      "Scan pages, Theme Builder layouts (header/body/footer), Divi Library items, canvas pages, and the preset registry for gvid-/gcid- references that have no backing entry in the Variable Manager (orphans), plus variables defined but referenced nowhere (unused). Orphans render as invalid CSS on the frontend — the $variable()$ resolver falls through with no fallback. Use after a deletion with force=true, or periodically as a hygiene check. Symmetric to diviops_preset_scan_orphans.",
  },
  async () => {
    const result = await wp.request("/variable/scan-orphans");
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_variable_used_on_page",
  {
    description:
      "Detect which numeric/font variable IDs a single page actually emits — the exact set Divi 5.4.0+ uses to scope selective `:root{--gvid-*}` CSS variable emission. Walks the same content stack the frontend assembles: post_content + active Theme Builder header/body/footer template content + appended canvas content (interaction targets etc.), plus presets referenced by that content. NOTE: this is `gvid-*` only — color variables (`gcid-*`) are emitted via a separate path (`GlobalData` color block) that is NOT scoped per-page in 5.4.0; this tool returns gvid IDs only. Use for per-page orphan validation (complements global diviops_variable_scan_orphans), preflight before bulk variable rename (know which pages are affected), or to debug why a numeric/font variable doesn't render on a specific page. Read-only. Returns variable_ids (sorted, deduped), count, and the tb_template_ids resolved for that post.",
    inputSchema: {
      post_id: z
        .number()
        .int()
        .positive()
        .describe(
          "WordPress post/page ID. The page does not need to be Divi-built — TB templates and canvases attached to non-Divi posts are still scanned.",
        ),
    },
  },
  async ({ post_id }) => {
    const result = await wp.request(`/variable/used-on-page/${post_id}`);
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

server.registerTool(
  "diviops_meta_flush_cache",
  {
    description:
      "Flush Divi's compiled static CSS cache under wp-content/et-cache/. wp cache flush does NOT touch these files — the frontend can keep serving stale CSS after a preset/variable/module mutation until the cache is cleared. Delegates to Divi's native ET_Core_PageResource::remove_static_resources when available (response backend: \"divi_native\"), which additionally clears Theme Builder CSS scattered across other post dirs, archive/taxonomy/home/notfound CSS, the object cache, module features cache, post features cache, Google Fonts cache, dynamic assets cache, and post meta caches. Falls back to a targeted filesystem walk of numeric-named et-cache subdirs when the Divi class is absent (backend: \"fs_fallback\"). Provide exactly one selector — no site-wide default to prevent accidental full flush. Idempotent: missing cache root returns 200 with empty list.",
    inputSchema: {
      post_id: z
        .number()
        .int()
        .positive()
        .optional()
        .describe(
          "Flush cache for one post. Native backend also clears matching Theme Builder CSS in other post dirs; fs_fallback only clears wp-content/et-cache/{post_id}/.",
        ),
      all: z
        .boolean()
        .optional()
        .default(false)
        .describe(
          "Flush every cached file. Native backend clears archive/taxonomy/home/notfound CSS + multi-layer WP caches; fs_fallback only clears numeric-named subdirs (siblings like .cache-cleared-at, global/, en_US/, notfound/, *.data are preserved in either mode).",
        ),
      after: z
        .number()
        .int()
        .positive()
        .optional()
        .describe(
          "Unix timestamp — flush Divi CSS files (et-*.css) with mtime strictly greater than this value. Useful for flushing entries touched since a known deployment or mutation batch. Native backend does a single-pass filesystem sweep covering numeric post dirs AND archive/taxonomy/home/notfound/global subtrees in one walk (Visual Builder -vb-* runtime CSS preserved); fs_fallback iterates numeric post dirs whose latest file mtime > after. `flushed` lists numeric post_ids whose files were actually deleted; `skipped` lists numeric post_ids that exist but had no files pass the filter.",
        ),
    },
  },
  async ({ post_id, all, after }) => {
    const body: Record<string, unknown> = {};
    if (post_id !== undefined) body.post_id = post_id;
    if (all) body.all = true;
    if (after !== undefined) body.after = after;
    const result = await wp.request("/meta/flush-cache", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result) },
      ],
    };
  },
);

// ── Start ────────────────────────────────────────────────────────────

async function main() {
  // Version handshake — verify plugin compatibility before accepting tool calls.
  try {
    const hs = await wp.handshake(SERVER_VERSION);
    const diviInfo = hs.divi.active
      ? `Divi ${hs.divi.version ?? "unknown"}`
      : "Divi not active";
    console.error(
      `Handshake OK: plugin ${hs.plugin_version}, ${diviInfo}, ${hs.capabilities.length} capabilities`,
    );
  } catch (error) {
    const msg = error instanceof Error ? error.message : String(error);
    // Version mismatch — fatal (HTTP 426 from plugin, or client-side minimum check).
    if (
      msg.includes("WordPress API error (426)") ||
      msg.includes("below the minimum required")
    ) {
      console.error(`Version mismatch: ${msg}`);
      process.exit(1);
    }
    // Other errors (network, auth) — warn but continue, tools will fail individually.
    console.error(`Handshake warning: ${msg}`);
  }

  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error("Divi MCP Server running on stdio");
}

main().catch((error) => {
  console.error("Fatal error:", error);
  process.exit(1);
});
