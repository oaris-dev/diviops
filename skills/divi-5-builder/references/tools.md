# MCP Tool Reference

48 tools for reading and writing Divi 5 pages, presets, variables, library, canvas, Theme Builder, and WP-CLI.

## Read Tools (26)

- `diviops_meta_ping` — verify WordPress + plugin connection
- `diviops_meta_info` — DiviOps server identity, version, license type, capabilities
- `diviops_page_list` / `diviops_page_get` / `diviops_page_get_layout` — read pages (layout returns slim targeting metadata by default; use `full: true` for complete attrs)
- `diviops_schema_list_modules` / `diviops_schema_get_module` — discover modules and attributes (optimized schema by default)
- `diviops_schema_get_settings` / `diviops_global_color_list` / `diviops_global_font_list` — site config
- `diviops_meta_find_icon` — search 1,989 icons by keyword (returns unicode, type, weight)
- `diviops_section_get` — get a section's markup by admin label or text content
- `diviops_template_list` / `diviops_template_get` — load verified block markup templates
- `diviops_preset_audit` — audit presets with referenced/unreferenced analysis (exposes `block_ref_count`, `group_ref_count`, `referenced_by_presets` chain)
- `diviops_preset_scan_orphans` — list page-referenced preset UUIDs missing from the D5 registry; separates dangling orphans from D4-legacy refs
- `diviops_library_list` / `diviops_library_get` — browse and load Divi Library items
- `diviops_render_preview` — render block markup to HTML for preview
- `diviops_validate_blocks` — validate block markup (structure, required attrs, known pitfalls)
- `diviops_tb_template_list` / `diviops_tb_layout_get` — browse Theme Builder templates and layouts
- `diviops_canvas_list` / `diviops_canvas_get` — browse and read off-canvas workspaces (popups, modals, menus)
- `diviops_variable_list` — list design token variables, filter by type (`colors`, `numbers`, etc.) or ID prefix (e.g. `gcid-oa-` for oa design system colors, `gvid-oa-` for numbers)
- `diviops_variable_scan_orphans` — find `gvid-`/`gcid-` refs with no backing Variable Manager entry (orphans render as invalid CSS when the `$variable()$` resolver falls through) plus variables defined but referenced nowhere (unused — deletion candidates). Scans pages, Theme Builder layouts (`et_header_layout` / `et_body_layout` / `et_footer_layout`), Divi Library items (`et_pb_layout`), canvas pages (`et_pb_canvas`), and the preset registry. Symmetric to `diviops_preset_scan_orphans`

## Write Tools (20)

- `diviops_page_create` — create new page with Divi content
- `diviops_page_update_content` — full page rewrite
- `diviops_section_append` — add section to existing page (start or end)
- `diviops_section_replace` — replace section by admin label or text content
- `diviops_section_remove` — remove section by admin label or text content
- `diviops_module_update` — surgically update module attributes (dot notation, 3 targeting modes + occurrence)
- `diviops_module_move` — move any block to a new position (before/after a target block). Separate source + target targeting (auto_index, label, or text). Works across sections.
- `diviops_preset_cleanup` — manage presets: default (spam removal), `action=remove_orphans` with `scope=spam|all`, `action=rename_strip_prefix`, `dedup=true`
- `diviops_preset_create` — create a new preset in the D5 registry. Required: `module_name`, `name`, `attrs`. For `type: "group"` (attribute-level preset), also requires `group_name` (e.g. `divi/font`, `divi/button`) + `group_id` (e.g. `designTitleText`, `designButton`). Returns the created UUID as `preset.id` (nested under a `preset` object in the response). See [presets.md](presets.md) for the attrs-shape difference between `module` and `group` types
- `diviops_preset_reassign` — rewrite preset refs across pages from `old_uuid` → `new_uuid`. Covers both module-level (`attrs.modulePreset[...]` arrays) and attribute-level (`attrs.groupPreset.<slot>.presetId`, scalar or array) refs, plus registry chain refs (`attrs.groupPresets.<slot>.presetId` in other presets that pull in `old_uuid`) for group-bucket swaps. `scope: "both"` (default) auto-selects based on `new_uuid`'s bucket; pass `"module"` or `"group"` to gate explicitly. Cross-bucket swaps (module ↔ group) are rejected. Legacy single-string form `"modulePreset": "uuid"` (D4-migrated content) is **not** rewritten — normalize to array form `["uuid"]` first if needed. `mode: "dry-run"` (default) or `"apply"`. `strip_inline: true` (default) — module-scope only — recursively removes per-attribute inline values that deep-equal the new preset's value at the same path; only fires when post-swap stack is singular `[new_uuid]`. Group-scope inline strip is not yet implemented (emits `summary.strip_advisory`). See [presets.md](presets.md) for full semantics
- `diviops_preset_update` / `diviops_preset_delete` — update or delete individual presets
- `diviops_library_save` — save block markup to Divi Library for reuse
- `diviops_tb_layout_update` — update Theme Builder header/footer/body content
- `diviops_tb_template_create` — create Theme Builder template with header/footer and conditions
- `diviops_canvas_create` — create off-canvas workspace (popups, modals, menus) linked to a page
- `diviops_canvas_update` — update canvas content and metadata
- `diviops_canvas_delete` — remove a canvas
- `diviops_variable_create` — create a design token variable (colors: `gcid-*` + hex, numbers: `gvid-*` + CSS value). For `type=numbers` fluid tokens, pass `min`+`max` shorthand (anchors default to 320px/1920px) or explicit `targets` like `{"320px":"20px","1920px":"60px"}` — server generates arithmetically-correct `clamp()` instead of hand-written math that silently under-reaches the stated max. All-px inputs emit px (root-agnostic). Rem inputs OR rem output require explicit opt-in: pass `output_unit="rem"` (accepts the 1rem=16px default) or `root_font_size_px:N` (declares the site's actual root, e.g. `10` for `html { font-size: 62.5% }`, `20` for `html { font-size: 20px }`). Mutually exclusive with `value`
- `diviops_variable_delete` — delete a variable by ID (auto-detects storage from prefix). Refuses with HTTP 409 when live references exist unless `force=true`; run `diviops_variable_scan_orphans` to find where they live. Returns HTTP 403 for Divi's customizer-bound defaults (`gcid-primary-color`, `gcid-secondary-color`, `gcid-heading-color`, `gcid-body-color`, `gcid-link-color`) — those are managed via WP Customizer theme options, not this tool

## Utility Tools (2)

- `diviops_meta_wp_cli` — run WP-CLI commands (allowlisted; default safelist + opt-in extended commands via `DIVIOPS_WP_CLI_ALLOW`; requires `WP_PATH` for Local by Flywheel or `WP_CLI_CMD` for containerized envs). Filesystem-touching commands (`wp export`, `acf export/import`) additionally require path arguments under a safe root (default `<WP_PATH>/.diviops-tmp/`; override via `DIVIOPS_WP_CLI_SAFE_FS_ROOT`; disable via `DIVIOPS_WP_CLI_UNSAFE_FS=1`). `wp export` requires explicit `--dir=` or `--stdout`. In `WP_CLI_CMD` wrapper mode, `DIVIOPS_WP_CLI_SAFE_FS_ROOT` is mandatory for FS-sensitive commands (host-derived paths don't correspond to the container namespace)
- `diviops_meta_flush_cache` — flush Divi's compiled CSS cache at `wp-content/et-cache/`. Needed after preset / variable / module mutations because `wp cache flush` does NOT invalidate this on-disk cache — stale CSS keeps serving until the cache is cleared. Delegates to Divi's native `ET_Core_PageResource::remove_static_resources` when available (response field `backend: "divi_native"`); native mode additionally clears Theme Builder CSS scattered across other post dirs, archive / taxonomy / home / notfound CSS, object cache, module features cache, post features cache, dynamic assets cache, Google Fonts cache, and post meta caches — significantly broader than the fs fallback. Falls back to a targeted filesystem walk of numeric-named subdirs when the Divi class is absent (`backend: "fs_fallback"`). Exactly one selector required: `post_id` (single post), `all` (every cached file), or `after` (unix ts — delete Divi CSS files with mtime strictly greater than ts; native backend does a single-pass sweep of the full et-cache tree preserving Visual Builder `-vb-*` runtime CSS, fallback iterates numeric post dirs whose latest file mtime exceeds the cutoff). No default to `all` — omitting a selector returns HTTP 400 to prevent accidental site-wide flushes. Safety in fallback mode: only numeric-named subdirs are touched; siblings like `.cache-cleared-at`, `global/`, `en_US/`, `notfound/`, `*.data` are never removed. Response includes `mode`, `backend`, `flushed`, `files_freed`, `bytes_freed`, and a `scope_note` in native mode describing the exact scope flushed per selector: `post_id` counts reflect the per-post dir only (lower bound — native clearer also removed matching TB CSS across other dirs); `all` covers numeric post dirs + archive/taxonomy/home/notfound/global subtrees; `after` sweeps the whole et-cache tree by file mtime and counts include non-post subtree deletions, so `files_freed` is not limited to per-post dirs. Idempotent when cache root missing (returns 200 with empty list)

## Targeting Reference

### Module targeting (`diviops_module_update`)

Three targeting modes, in priority order:

| Mode | Parameter | Example | Use when |
|---|---|---|---|
| **Auto-index** | `auto_index: "text:5"` | `diviops_module_update(page_id: 312, auto_index: "text:5", attrs: {...})` | Any module — get the index from `diviops_page_get_layout` |
| **Admin label** | `label: "Hero Heading"` | `diviops_module_update(page_id: 312, label: "Hero Heading", attrs: {...})` | MCP-generated content with labels |
| **Text match** | `match_text: "Kitas"` | `diviops_module_update(page_id: 312, match_text: "Kitas", attrs: {...})` | Quick targeting by visible text content |

**Duplicate handling**: Add `occurrence: N` (1-based) when multiple modules share the same label. Response includes `total_matches` when duplicates exist.

### Section targeting (`diviops_section_get`, `diviops_section_replace`, `diviops_section_remove`)

| Parameter | Example | Use when |
|---|---|---|
| `label` | `label: "Hero"` | Section has an admin label |
| `match_text` | `match_text: "Lernen, das sich"` | Find section by text content (case-insensitive substring) |
| `occurrence` | `occurrence: 3` | Multiple sections match (1-based) |

Either `label` or `match_text` is required. When duplicates exist, `diviops_section_get` includes `total_matches` and a warning; `diviops_section_replace` / `diviops_section_remove` include `total_matches`.

### Auto-index (from `diviops_page_get_layout`)

The layout response includes targeting metadata per module:
- `admin_label` — manually set label
- `text_preview` — first ~50 chars of innerContent
- `auto_index` — type + sequential counter (e.g., `text:5`, `icon:3`) — works for ALL modules, including those without labels or text
