# DiviOps Agent

WordPress plugin that exposes Divi 5 Visual Builder data and operations via authenticated REST API endpoints. Companion to the [Divi 5 MCP Server](../../../diviops-server/).

## Requirements

- WordPress 6.0+
- Divi 5 theme (5.1.0+)
- PHP 7.4+
- Application Passwords enabled (default since WP 5.6)

## Installation

1. Zip this directory: `cd wp-content/plugins && zip -r diviops-agent.zip diviops-agent/`
2. Go to **WP Admin → Plugins → Add New → Upload Plugin**
3. Upload `diviops-agent.zip` and activate
4. If Divi is not active, all endpoints return `503 divi_unavailable`

## Upgrade From The Previous Plugin Name

1. Deactivate the old `Divi MCP Agent` plugin.
2. Install or copy `diviops-agent/`.
3. Activate `DiviOps Agent`.
4. Keep your MCP server config pointed at `/wp-json/diviops/v1/`; the REST namespace is unchanged.

See [setup guide](../../../docs/setup-guide.md) for full onboarding with MCP server registration.

## REST Endpoints

Base: `/wp-json/diviops/v1/`

### Read
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/handshake` | POST | Version + capabilities handshake for MCP server pairing (plugin version, Divi version, registered capabilities). POST because it takes a required `mcp_server_version` body param |
| `/page/list` | GET | List pages with Divi status |
| `/page/get/{id}` | GET | Get page details + raw content |
| `/page/get-layout/{id}` | GET | Parsed block tree with auto-index, text preview, admin labels |
| `/section/get/{id}?label=` | GET | Section markup by admin label |
| `/schema/modules` | GET | List all Divi modules |
| `/schema/module/{name}` | GET | Module attribute schema |
| `/schema/settings` | GET | Divi theme settings |
| `/global-color/list` | GET | Global color palette |
| `/global-font/list` | GET | Global font definitions |
| `/meta/find-icon?q=&type=&limit=` | GET | Search 1,989 icons by keyword |
| `/preset/list` | GET | All presets (D5 + legacy) |
| `/preset/audit` | GET | Preset analysis with referenced/unreferenced breakdown + `orphan_default_pointers` (per-bucket `default` pointers referencing UUIDs missing from `items[]`) |
| `/preset/scan-orphans` | GET | List preset UUIDs referenced in pages but missing from the D5 registry (dangling vs D4-legacy) |
| `/variable/list` | GET | List design token variables (filter by type, prefix) |
| `/variable/scan-orphans` | GET | Scan variable refs across pages, Theme Builder layouts, Divi Library items, canvas pages, and the preset registry. Reports both orphans (`gvid-`/`gcid-` refs missing from the registry) and unused variables (defined but never referenced — deletion candidates) |
| `/variable/used-on-page/{id}` | GET | Detect which gvid- variable IDs a single page emits (post_content + active TB layouts + canvases + presets) |
| `/canvas/list` | GET | List canvas items (reusable block containers) |
| `/canvas/get/{id}` | GET | Get canvas content |
| `/library/items` | GET | List Divi Library items (filter by type, scope) |
| `/library/item/{id}` | GET | Get library item content |
| `/render` | POST | Render block markup to HTML (read-only, no state change) |
| `/validate/blocks` | POST | Validate block markup structure + known pitfalls (read-only) |
| `/theme-builder/template/list` | GET | List Theme Builder templates with conditions |
| `/theme-builder/layout/get/{id}` | GET | Get Theme Builder layout content |

### Write
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/page/create` | POST | Create new page |
| `/page/update-content/{id}` | POST | Full content rewrite |
| `/section/append/{id}` | POST | Append section to page |
| `/section/replace/{id}` | POST | Replace section by label |
| `/section/remove/{id}` | POST | Remove section by label |
| `/module/update/{id}` | POST | Update module attrs by label or text match |
| `/module/move/{id}` | POST | Move a block before/after another block |
| `/module/lock/{id}` | POST | Lock a module so VB users cannot edit it |
| `/module/unlock/{id}` | POST | Unlock a module by removing `attrs.locked` |
| `/module/clone/{id}` | POST | Deep-copy a module + insert next to source within the same parent |
| `/page/set-meta/{id}` | POST | Set page template/meta |
| `/global-color/upsert` | POST | Upsert global color palette (create + update via single bulk write) |
| `/global-color/delete` | POST | Delete a global color by gcid (auto-protects customizer defaults) |
| `/theme-options` | POST | Update theme customizer options |
| `/preset/create` | POST | Create a module or group preset in the D5 registry |
| `/preset/reassign` | POST | Rewrite preset UUID refs across page content + (for group-bucket swaps) registry chains. Supports `scope: "module" \| "group" \| "both"` (default `"both"`); dry-run default with explicit `mode: "apply"` to commit |
| `/preset/cleanup` | POST | Remove spam/duplicate presets, bulk rename |
| `/preset/update` | POST | Update a single preset (name, attrs) |
| `/preset/delete` | POST | Delete a preset by ID. Refuses with `409 preset_is_default` if the target is the registered default for its bucket; pass `force=true` to delete and clear the `default` pointer in the same write |
| `/preset/set-default` | POST | Set or clear the per-module/group default preset pointer (preset_id mode walks both buckets; bucket-addressed mode requires `type` + `module` + `unset=true` to clear orphan pointers) |
| `/variable/create` | POST | Create a design token variable (colors or numbers/strings/etc) |
| `/variable/create-fluid-system` | POST | Batch-emit a fluid typography + spacing + radius variable set (single atomic write) |
| `/variable/delete` | POST | Delete a variable by ID |
| `/canvas/create` | POST | Create a new canvas item |
| `/canvas/update/{id}` | POST | Update canvas content |
| `/canvas/delete/{id}` | POST | Delete a canvas |
| `/library/save` | POST | Save block markup to Divi Library |
| `/theme-builder/layout/update/{id}` | PUT | Update Theme Builder layout content |
| `/theme-builder/template/create` | POST | Create Theme Builder template with conditions |
| `/meta/flush-cache` | POST | Flush Divi's compiled CSS cache at `wp-content/et-cache/` after preset / variable / module mutations (required because `wp cache flush` doesn't invalidate this on-disk cache) |

### Authentication & Permissions

All endpoints require Application Password authentication (Basic Auth). Three permission tiers:

| Tier | WP Capability | Endpoints |
|------|--------------|-----------|
| **Read** | `edit_posts` | Most GET endpoints, `/render`, `/validate/blocks` |
| **Write** | `edit_pages` | Page creation and content modification |
| **Admin** | `manage_options` | Theme options, preset audit/cleanup/update/delete, library save, variable management, scan-orphans (variable + preset) |

If Divi is not active, all endpoints return `503 divi_unavailable`.

### Module Targeting (module/update)
Three ways to target a module for editing:

| Method | Parameter | Works for |
|--------|-----------|-----------|
| Admin label | `label: "Hero Heading"` | Manually labeled modules |
| Text content | `match_text: "Kitas"` | Modules with text (case-insensitive substring) |
| Auto-index | Call `GET /page/get-layout/{id}` to find `auto_index` like `icon:5` | All modules including icons, dividers, images |

### Page Layout Response
`/page/get-layout/{id}` returns per module:
- `admin_label` — manual label if set
- `text_preview` — first 50 chars of content text
- `auto_index` — `type:count` (e.g. `text:3`, `icon:5`, `group:9`)

### Cache Invalidation
All write operations automatically clear Divi's et-cache to ensure CSS regeneration.

## Setup

1. Copy plugin folder to `wp-content/plugins/diviops-agent/`
2. Activate in WP Admin → Plugins
3. Create Application Password: WP Admin → Users → Profile → Application Passwords
4. Use credentials with the MCP server or direct REST API calls
