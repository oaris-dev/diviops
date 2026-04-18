# MCP Tool Reference

48 tools for reading and writing Divi 5 pages, presets, variables, library, canvas, Theme Builder, and WP-CLI.

## Read Tools (26)

- `diviops_test_connection` — verify WordPress + plugin connection
- `diviops_server_info` — DiviOps server identity, version, license type, capabilities
- `diviops_list_pages` / `diviops_get_page` / `diviops_get_page_layout` — read pages (layout returns slim targeting metadata by default; use `full: true` for complete attrs)
- `diviops_list_modules` / `diviops_get_module_schema` — discover modules and attributes (optimized schema by default)
- `diviops_get_settings` / `diviops_get_global_colors` / `diviops_get_global_fonts` — site config
- `diviops_find_icon` — search 1,989 icons by keyword (returns unicode, type, weight)
- `diviops_get_section` — get a section's markup by admin label or text content
- `diviops_list_templates` / `diviops_get_template` — load verified block markup templates
- `diviops_preset_audit` — audit presets with referenced/unreferenced analysis (exposes `block_ref_count`, `group_ref_count`, `referenced_by_presets` chain)
- `diviops_preset_scan_orphans` — list page-referenced preset UUIDs missing from the D5 registry; separates dangling orphans from D4-legacy refs
- `diviops_list_library` / `diviops_get_library_item` — browse and load Divi Library items
- `diviops_render_preview` — render block markup to HTML for preview
- `diviops_validate_blocks` — validate block markup (structure, required attrs, known pitfalls)
- `diviops_list_tb_templates` / `diviops_get_tb_layout` — browse Theme Builder templates and layouts
- `diviops_list_canvases` / `diviops_get_canvas` — browse and read off-canvas workspaces (popups, modals, menus)
- `diviops_list_variables` — list design token variables, filter by type (`colors`, `numbers`, etc.) or ID prefix (e.g. `gcid-oa-` for oa design system colors, `gvid-oa-` for numbers)
- `diviops_variables_scan_orphans` — find `gvid-`/`gcid-` refs with no backing Variable Manager entry (orphans render as invalid CSS when the `$variable()$` resolver falls through) plus variables defined but referenced nowhere (unused — deletion candidates). Scans pages, Theme Builder layouts (`et_header_layout` / `et_body_layout` / `et_footer_layout`), Divi Library items (`et_pb_layout`), canvas pages (`et_pb_canvas`), and the preset registry. Symmetric to `diviops_preset_scan_orphans`

## Write Tools (20)

- `diviops_create_page` — create new page with Divi content
- `diviops_update_page_content` — full page rewrite
- `diviops_append_section` — add section to existing page (start or end)
- `diviops_replace_section` — replace section by admin label or text content
- `diviops_remove_section` — remove section by admin label or text content
- `diviops_update_module` — surgically update module attributes (dot notation, 3 targeting modes + occurrence)
- `diviops_move_module` — move any block to a new position (before/after a target block). Separate source + target targeting (auto_index, label, or text). Works across sections.
- `diviops_preset_cleanup` — manage presets: default (spam removal), `action=remove_orphans` with `scope=spam|all`, `action=rename_strip_prefix`, `dedup=true`
- `diviops_preset_create` — create a new preset in the D5 registry. Required: `module_name`, `name`, `attrs`. For `type: "group"` (attribute-level preset), also requires `group_name` (e.g. `divi/font`, `divi/button`) + `group_id` (e.g. `designTitleText`, `designButton`). Returns the created UUID as `preset.id` (nested under a `preset` object in the response). See [presets.md](presets.md) for the attrs-shape difference between `module` and `group` types
- `diviops_preset_reassign` — rewrite `modulePreset` refs across pages from `old_uuid` → `new_uuid`. Walks `attrs.modulePreset` **arrays only** (does NOT cover `groupPreset.<slot>.presetId`). Legacy single-string form `"modulePreset": "uuid"` (D4-migrated content) is **not** rewritten — normalize to array form `["uuid"]` first if needed. `mode: "dry-run"` (default) or `"apply"`. `strip_inline: true` (default) recursively removes per-attribute inline values that deep-equal the new preset's value at the same path; only fires when post-swap stack is singular `[new_uuid]`
- `diviops_preset_update` / `diviops_preset_delete` — update or delete individual presets
- `diviops_save_to_library` — save block markup to Divi Library for reuse
- `diviops_update_tb_layout` — update Theme Builder header/footer/body content
- `diviops_create_tb_template` — create Theme Builder template with header/footer and conditions
- `diviops_create_canvas` — create off-canvas workspace (popups, modals, menus) linked to a page
- `diviops_update_canvas` — update canvas content and metadata
- `diviops_delete_canvas` — remove a canvas
- `diviops_create_variable` — create a design token variable (colors: `gcid-*` + hex, numbers: `gvid-*` + CSS value). For `type=numbers` fluid tokens, pass `min`+`max` shorthand (anchors default to 320px/1920px) or explicit `targets` like `{"320px":"20px","1920px":"60px"}` — server generates arithmetically-correct `clamp()` instead of hand-written math that silently under-reaches the stated max. Mutually exclusive with `value`. Px inputs only in this MVP; rem inputs should be converted to px (1rem=16px) before calling
- `diviops_delete_variable` — delete a variable by ID (auto-detects storage from prefix). Refuses with HTTP 409 when live references exist unless `force=true`; run `diviops_variables_scan_orphans` to find where they live. Returns HTTP 403 for Divi's customizer-bound defaults (`gcid-primary-color`, `gcid-secondary-color`, `gcid-heading-color`, `gcid-body-color`, `gcid-link-color`) — those are managed via WP Customizer theme options, not this tool

## Utility Tools (2)

- `diviops_wp_cli` — run WP-CLI commands (allowlisted; default safelist + opt-in extended commands via `DIVIOPS_WP_CLI_ALLOW`; requires `WP_PATH` for Local by Flywheel or `WP_CLI_CMD` for containerized envs)
- `diviops_flush_static_cache` — flush Divi's compiled CSS cache at `wp-content/et-cache/`. Needed after preset / variable / module mutations because `wp cache flush` does NOT invalidate this on-disk cache — stale CSS keeps serving until the cache is cleared. Delegates to Divi's native `ET_Core_PageResource::remove_static_resources` when available (response field `backend: "divi_native"`); native mode additionally clears Theme Builder CSS scattered across other post dirs, archive / taxonomy / home / notfound CSS, object cache, module features cache, post features cache, dynamic assets cache, Google Fonts cache, and post meta caches — significantly broader than the fs fallback. Falls back to a targeted filesystem walk of numeric-named subdirs when the Divi class is absent (`backend: "fs_fallback"`). Exactly one selector required: `post_id` (single post), `all` (every cached file), or `after` (unix ts — iterate dirs with mtime greater than ts, flushing each). No default to `all` — omitting a selector returns HTTP 400 to prevent accidental site-wide flushes. Safety in fallback mode: only numeric-named subdirs are touched; siblings like `.cache-cleared-at`, `global/`, `en_US/`, `notfound/`, `*.data` are never removed. Response includes `mode`, `backend`, `flushed`, `files_freed`, `bytes_freed`, and a `scope_note` in native mode reminding that counts reflect per-post dirs only (lower bound — broader WP caches are purged but not counted). Idempotent when cache root missing (returns 200 with empty list)

## Targeting Reference

### Module targeting (`diviops_update_module`)

Three targeting modes, in priority order:

| Mode | Parameter | Example | Use when |
|---|---|---|---|
| **Auto-index** | `auto_index: "text:5"` | `diviops_update_module(page_id: 312, auto_index: "text:5", attrs: {...})` | Any module — get the index from `diviops_get_page_layout` |
| **Admin label** | `label: "Hero Heading"` | `diviops_update_module(page_id: 312, label: "Hero Heading", attrs: {...})` | MCP-generated content with labels |
| **Text match** | `match_text: "Kitas"` | `diviops_update_module(page_id: 312, match_text: "Kitas", attrs: {...})` | Quick targeting by visible text content |

**Duplicate handling**: Add `occurrence: N` (1-based) when multiple modules share the same label. Response includes `total_matches` when duplicates exist.

### Section targeting (`diviops_get_section`, `diviops_replace_section`, `diviops_remove_section`)

| Parameter | Example | Use when |
|---|---|---|
| `label` | `label: "Hero"` | Section has an admin label |
| `match_text` | `match_text: "Lernen, das sich"` | Find section by text content (case-insensitive substring) |
| `occurrence` | `occurrence: 3` | Multiple sections match (1-based) |

Either `label` or `match_text` is required. When duplicates exist, `diviops_get_section` includes `total_matches` and a warning; `diviops_replace_section` / `diviops_remove_section` include `total_matches`.

### Auto-index (from `diviops_get_page_layout`)

The layout response includes targeting metadata per module:
- `admin_label` — manually set label
- `text_preview` — first ~50 chars of innerContent
- `auto_index` — type + sequential counter (e.g., `text:5`, `icon:3`) — works for ALL modules, including those without labels or text
