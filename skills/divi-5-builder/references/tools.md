# MCP Tool Reference

43 tools for reading and writing Divi 5 pages, presets, variables, library, canvas, Theme Builder, and WP-CLI.

## Read Tools (22 + 2 canvas)

- `diviops_test_connection` — verify WordPress + plugin connection
- `diviops_server_info` — DiviOps server identity, version, license type, capabilities
- `diviops_list_pages` / `diviops_get_page` / `diviops_get_page_layout` — read pages (layout returns slim targeting metadata by default; use `full: true` for complete attrs)
- `diviops_list_modules` / `diviops_get_module_schema` — discover modules and attributes (optimized schema by default)
- `diviops_get_settings` / `diviops_get_global_colors` / `diviops_get_global_fonts` — site config
- `diviops_find_icon` — search 1,989 icons by keyword (returns unicode, type, weight)
- `diviops_get_section` — get a section's markup by admin label or text content
- `diviops_list_templates` / `diviops_get_template` — load verified block markup templates
- `diviops_preset_audit` — audit presets with referenced/unreferenced analysis
- `diviops_list_library` / `diviops_get_library_item` — browse and load Divi Library items
- `diviops_render_preview` — render block markup to HTML for preview
- `diviops_validate_blocks` — validate block markup (structure, required attrs, known pitfalls)
- `diviops_list_tb_templates` / `diviops_get_tb_layout` — browse Theme Builder templates and layouts
- `diviops_list_canvases` / `diviops_get_canvas` — browse and read off-canvas workspaces (popups, modals, menus)
- `diviops_list_variables` — list design token variables, filter by type (`colors`, `numbers`, etc.) or ID prefix (e.g. `gcid-oa-` for oa design system colors, `gvid-oa-` for numbers)

## Write Tools (18)

- `diviops_create_page` — create new page with Divi content
- `diviops_update_page_content` — full page rewrite
- `diviops_append_section` — add section to existing page (start or end)
- `diviops_replace_section` — replace section by admin label or text content
- `diviops_remove_section` — remove section by admin label or text content
- `diviops_update_module` — surgically update module attributes (dot notation, 3 targeting modes + occurrence)
- `diviops_move_module` — move any block to a new position (before/after a target block). Separate source + target targeting (auto_index, label, or text). Works across sections.
- `diviops_preset_cleanup` — manage presets: default (spam removal), `action=remove_orphans` with `scope=spam|all`, `action=rename_strip_prefix`, `dedup=true`
- `diviops_preset_update` / `diviops_preset_delete` — update or delete individual presets
- `diviops_save_to_library` — save block markup to Divi Library for reuse
- `diviops_update_tb_layout` — update Theme Builder header/footer/body content
- `diviops_create_tb_template` — create Theme Builder template with header/footer and conditions
- `diviops_create_canvas` — create off-canvas workspace (popups, modals, menus) linked to a page
- `diviops_update_canvas` — update canvas content and metadata
- `diviops_delete_canvas` — remove a canvas
- `diviops_create_variable` — create a design token variable (colors: `gcid-*` + hex, numbers: `gvid-*` + CSS value)
- `diviops_delete_variable` — delete a variable by ID (auto-detects storage from prefix)
- `diviops_wp_cli` — run WP-CLI commands (allowlisted, Local by Flywheel)

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
