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
  "diviops_list_pages",
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
    const result = await wp.request("/pages", {
      params: {
        post_type: post_type ?? "page",
        per_page: String(per_page ?? 20),
        page: String(page ?? 1),
      },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_get_page",
  {
    description:
      "Get detailed info about a specific page including its raw Divi block content.",
    inputSchema: {
      page_id: z.number().describe("WordPress post/page ID"),
    },
  },
  async ({ page_id }) => {
    const result = await wp.request(`/page/${page_id}`);
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_get_page_layout",
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
    const result = await wp.request(`/page/${page_id}/layout`, {
      params: full ? { full: "true" } : {},
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_list_modules",
  {
    description:
      "List all available Divi modules (block types) with their names, titles, and categories. Use this to discover what modules can be used in layouts.",
  },
  async () => {
    const result = await wp.request("/modules");
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_get_module_schema",
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
      `/module/${encodeURIComponent(module_name)}`,
    );
    const output = raw ? result : optimizeSchema(result as Record<string, any>);
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(output, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_get_settings",
  {
    description:
      "Get Divi site settings including theme options, site info, and builder version. Useful for understanding the site context before generating content.",
  },
  async () => {
    const result = await wp.request("/settings");
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_get_global_colors",
  {
    description:
      "Get the global color palette defined in Divi. Returns all global colors that can be referenced by modules.",
  },
  async () => {
    const result = await wp.request("/global-colors");
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_get_global_fonts",
  {
    description: "Get the global font definitions from Divi settings.",
  },
  async () => {
    const result = await wp.request("/global-fonts");
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_find_icon",
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
      `/icons/search?q=${encodeURIComponent(query)}&type=${type ?? "all"}&limit=${limit ?? 10}`,
    );
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

// ── Write Tools ──────────────────────────────────────────────────────

server.registerTool(
  "diviops_update_page_content",
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
    const result = await wp.request(`/page/${page_id}/content`, {
      method: "POST",
      body: { content },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
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
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
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
    const result = await wp.request("/validate", {
      method: "POST",
      body: { content },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_append_section",
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
    const result = await wp.request(`/page/${page_id}/append`, {
      method: "POST",
      body: { content, position: position ?? "end" },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_replace_section",
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
    const body: Record<string, any> = { content, occurrence };
    if (label) body.label = label;
    if (match_text) body.match_text = match_text;
    const result = await wp.request(`/page/${page_id}/replace-section`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_remove_section",
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
    const result = await wp.request(`/page/${page_id}/remove-section`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_get_section",
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
    const result = await wp.request(`/page/${page_id}/get-section?${qs}`);
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_update_module",
  {
    description:
      'Update specific attributes of a module. Target by auto_index (e.g. "text:5"), admin label, or text content. Uses dot notation for attribute paths. Example: {"content.decoration.headingFont.h2.font.desktop.value.color": "#ff0000"}. Priority: auto_index > label > match_text. Use occurrence with label when duplicates exist.',
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
          'Auto-index target in "type:N" format (e.g. "text:5", "icon:3"). Get from diviops_get_page_layout. Takes priority over label/match_text.',
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
    const body: Record<string, any> = { attrs };
    if (auto_index) body.auto_index = auto_index;
    if (label) body.label = label;
    if (match_text) body.match_text = match_text;
    if (occurrence > 1) body.occurrence = occurrence;
    const result = await wp.request(`/page/${page_id}/update-module`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_move_module",
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
    const result = await wp.request(`/page/${page_id}/move-module`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_create_page",
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
    const result = await wp.request("/page/create", {
      method: "POST",
      body: { title, content: content ?? "", status: status ?? "draft" },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

// ── Preset Tools ────────────────────────────────────────────────────

server.registerTool(
  "diviops_preset_audit",
  {
    description:
      "Audit all Divi module presets. Returns counts and details of spam vs descriptive presets, which are referenced by pages, and which are orphaned.",
  },
  async () => {
    const result = await wp.request("/preset-audit");
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
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
    const result = await wp.request("/preset-cleanup", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_preset_update",
  {
    description:
      "Update a specific preset by ID. Can rename and/or replace its style attributes.",
    inputSchema: {
      preset_id: z.string().describe("Preset ID (UUID or short ID)"),
      name: z.string().optional().describe("New display name for the preset"),
      attrs: z
        .record(z.string(), z.any())
        .optional()
        .describe("New style attributes (replaces both attrs and styleAttrs)"),
    },
  },
  async ({ preset_id, name, attrs }) => {
    const body: Record<string, any> = { preset_id };
    if (name) body.name = name;
    if (attrs) body.attrs = attrs;
    const result = await wp.request("/preset-update", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_preset_delete",
  {
    description:
      "Delete a specific preset by ID. Use diviops_preset_audit first to verify the preset is unreferenced before deleting.",
    inputSchema: {
      preset_id: z.string().describe("Preset ID to delete"),
    },
  },
  async ({ preset_id }) => {
    const result = await wp.request("/preset-delete", {
      method: "POST",
      body: { preset_id },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

// ── Library Tools ───────────────────────────────────────────────────

server.registerTool(
  "diviops_list_library",
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
    const result = await wp.request("/library", { params });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_get_library_item",
  {
    description:
      "Get a Divi Library item's content by ID. Returns the raw block markup that can be used with diviops_append_section or diviops_update_page_content.",
    inputSchema: {
      item_id: z.number().describe("Library item ID"),
    },
  },
  async ({ item_id }) => {
    const result = await wp.request(`/library/${item_id}`);
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_save_to_library",
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
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

// ── Theme Builder Tools ─────────────────────────────────────────────

server.registerTool(
  "diviops_list_tb_templates",
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
    const result = await wp.request("/theme-builder/templates", { params });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_get_tb_layout",
  {
    description:
      "Get a Theme Builder layout's block markup content (header, body, or footer). Use the layout IDs from diviops_list_tb_templates.",
    inputSchema: {
      layout_id: z
        .number()
        .describe(
          "Layout post ID (from template header_layout_id, body_layout_id, or footer_layout_id)",
        ),
    },
  },
  async ({ layout_id }) => {
    const result = await wp.request(`/theme-builder/layout/${layout_id}`);
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_update_tb_layout",
  {
    description:
      "Update a Theme Builder layout's block markup (header, body, or footer). Replaces the full content.",
    inputSchema: {
      layout_id: z.number().describe("Layout post ID to update"),
      content: z.string().describe("New block markup content"),
    },
  },
  async ({ layout_id, content }) => {
    const result = await wp.request(`/theme-builder/layout/${layout_id}`, {
      method: "PUT",
      body: { content },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_create_tb_template",
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
    const result = await wp.request("/theme-builder/template", {
      method: "POST",
      body: { title, condition, header_content, footer_content },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

// ── Canvas Tools ────────────────────────────────────────────────────

server.registerTool(
  "diviops_create_canvas",
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
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_list_canvases",
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
    const result = await wp.request("/canvases", { params });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_get_canvas",
  {
    description: "Get a canvas's block content and metadata.",
    inputSchema: {
      canvas_post_id: z
        .number()
        .describe("Canvas post ID (from diviops_list_canvases)"),
    },
  },
  async ({ canvas_post_id }) => {
    const result = await wp.request(`/canvas/${canvas_post_id}`);
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_update_canvas",
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
    const result = await wp.request(`/canvas/${canvas_post_id}`, {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_delete_canvas",
  {
    description: "Delete a canvas. This permanently removes the canvas post.",
    inputSchema: {
      canvas_post_id: z.number().describe("Canvas post ID to delete"),
    },
  },
  async ({ canvas_post_id }) => {
    const result = await wp.request(`/canvas/${canvas_post_id}`, {
      method: "DELETE",
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

// ── WP-CLI ──────────────────────────────────────────────────────────

server.registerTool(
  "diviops_wp_cli",
  {
    description:
      "Run a WP-CLI command on the WordPress site. Requires WP_PATH env var (LOCAL_SITE_ID auto-detected from Local by Flywheel). Commands validated against a safety allowlist. Default tier: read commands, post create/update, post meta read/write, cache/rewrite flush, term create. Extended tier (requires DIVIOPS_WP_CLI_ALLOW env var): option update, post delete, post meta delete, plugin activate/deactivate, eval-file. Use --format=json for structured output.",
    inputSchema: {
      command: z
        .string()
        .describe(
          'WP-CLI command without the "wp" prefix. E.g. "option get blogname", "post list --format=json", "db query \\"SELECT COUNT(*) FROM wp_posts\\""',
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

// ── Connection ──────────────────────────────────────────────────────

server.registerTool(
  "diviops_test_connection",
  {
    description:
      "Test the connection to the WordPress site and verify the Divi MCP plugin is active.",
  },
  async () => {
    const result = await wp.testConnection();
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_server_info",
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
        { type: "text" as const, text: JSON.stringify(info, null, 2) },
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
1. Always use \`diviops_get_module_schema\` to check exact attribute names before building markup.
2. Use \`diviops_get_page_layout\` on existing pages to learn the format from real examples.
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
  "diviops_list_templates",
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
      content: [{ type: "text" as const, text: JSON.stringify(list, null, 2) }],
    };
  },
);

server.registerTool(
  "diviops_get_template",
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
        { type: "text" as const, text: JSON.stringify(template, null, 2) },
      ],
    };
  },
);

// ── Variable Manager CRUD ─────────────────────────────────────────────

server.registerTool(
  "diviops_list_variables",
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
    const result = await wp.request("/variables", { params });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_create_variable",
  {
    description:
      'Create a design token variable in the Divi Variable Manager. Colors (type "colors") use gcid-* IDs and hex values. Numbers/strings/etc use gvid-* IDs.',
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
        .describe(
          'Variable value: hex color for colors (e.g. "#3a7a6a"), CSS value for numbers (e.g. "clamp(30px, 8vw, 100px)")',
        ),
    },
  },
  async ({ type, id, label, value }) => {
    const body: Record<string, string> = { type, label, value };
    if (id) body.id = id;
    const result = await wp.request("/variable/create", {
      method: "POST",
      body,
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  },
);

server.registerTool(
  "diviops_delete_variable",
  {
    description:
      "Delete a design token variable by ID. Auto-detects storage from ID prefix (gcid-* = colors, gvid-* = numbers/strings/etc).",
    inputSchema: {
      id: z
        .string()
        .describe(
          'Variable ID to delete (e.g. "gcid-oa-accent" or "gvid-oa-size-xl")',
        ),
    },
  },
  async ({ id }) => {
    const result = await wp.request("/variable/delete", {
      method: "POST",
      body: { id },
    });
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
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
