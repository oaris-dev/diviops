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

See [setup guide](../../../.oaris/docs/setup-guide.md) for full onboarding with MCP server registration.

## REST Endpoints

Base: `/wp-json/diviops/v1/`

### Read
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/pages` | GET | List pages with Divi status |
| `/page/{id}` | GET | Get page details + raw content |
| `/page/{id}/layout` | GET | Parsed block tree with auto-index, text preview, admin labels |
| `/page/{id}/get-section?label=` | GET | Section markup by admin label |
| `/modules` | GET | List all Divi modules |
| `/module/{name}` | GET | Module attribute schema |
| `/settings` | GET | Divi theme settings |
| `/global-colors` | GET | Global color palette |
| `/global-fonts` | GET | Global font definitions |
| `/icons/search?q=&type=&limit=` | GET | Search 1,989 icons by keyword |
| `/presets` | GET | All presets (D5 + legacy) |
| `/preset-audit` | GET | Preset analysis with referenced/unreferenced breakdown |
| `/library` | GET | List Divi Library items (filter by type, scope) |
| `/library/{id}` | GET | Get library item content |
| `/render` | POST | Render block markup to HTML (read-only, no state change) |
| `/validate` | POST | Validate block markup structure + known pitfalls (read-only) |
| `/theme-builder/templates` | GET | List Theme Builder templates with conditions |
| `/theme-builder/layout/{id}` | GET | Get Theme Builder layout content |

### Write
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/page/create` | POST | Create new page |
| `/page/{id}/content` | POST | Full content rewrite |
| `/page/{id}/append` | POST | Append section |
| `/page/{id}/replace-section` | POST | Replace section by label |
| `/page/{id}/remove-section` | POST | Remove section by label |
| `/page/{id}/update-module` | POST | Update module attrs by label or text match |
| `/page/{id}/move-module` | POST | Move a block before/after another block |
| `/page/{id}/meta` | POST | Set page template/meta |
| `/global-colors` | POST | Update global color palette |
| `/theme-options` | POST | Update theme customizer options |
| `/preset-cleanup` | POST | Remove spam/duplicate presets, bulk rename |
| `/preset-update` | POST | Update a single preset (name, attrs) |
| `/preset-delete` | POST | Delete a preset by ID |
| `/variables` | GET | List design token variables (filter by type, prefix) |
| `/variable/create` | POST | Create a design token variable (colors or numbers/strings/etc) |
| `/variable/delete` | POST | Delete a variable by ID |
| `/library/save` | POST | Save block markup to Divi Library |
| `/theme-builder/layout/{id}` | PUT | Update Theme Builder layout content |
| `/theme-builder/template` | POST | Create Theme Builder template with conditions |

### Authentication & Permissions

All endpoints require Application Password authentication (Basic Auth). Three permission tiers:

| Tier | WP Capability | Endpoints |
|------|--------------|-----------|
| **Read** | `edit_posts` | All GET endpoints, `/render`, `/validate` |
| **Write** | `edit_pages` | Page creation and content modification |
| **Admin** | `manage_options` | Theme options, preset cleanup/update/delete, library save |

If Divi is not active, all endpoints return `503 divi_unavailable`.

### Module Targeting (update-module)
Three ways to target a module for editing:

| Method | Parameter | Works for |
|--------|-----------|-----------|
| Admin label | `label: "Hero Heading"` | Manually labeled modules |
| Text content | `match_text: "Kitas"` | Modules with text (case-insensitive substring) |
| Auto-index | Use `get_page_layout` to find `auto_index` like `icon:5` | All modules including icons, dividers, images |

### Page Layout Response
`/page/{id}/layout` returns per module:
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
