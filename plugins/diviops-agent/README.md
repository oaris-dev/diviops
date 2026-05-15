# DiviOps Agent

**REST API bridge inside the DiviOps AI harness for WordPress — Divi-native today, WordPress-wide by design.**

The WordPress companion plugin for `@diviops/mcp-server`. Pairs with the MCP server to expose Divi 5 page authoring, SCF management, CPT/post population, data model introspection, and site auditing as `/diviops/v1/*` REST endpoints behind Application Password auth.

> **Don't use this plugin standalone** — it's the WordPress side of a two-piece suite; install + configure the [DiviOps MCP Server](../../../diviops-server/) next.

## Requirements

- WordPress 6.0+
- Divi 5 theme (5.1.0+)
- PHP 7.4+
- Application Passwords enabled (default since WP 5.6)

## Installation

1. Zip this directory: `cd wp-content/plugins && zip -r diviops-agent.zip diviops-agent/`
2. **WP Admin → Plugins → Add New → Upload Plugin** — upload `diviops-agent.zip` and activate.
3. Create an Application Password under **WP Admin → Users → Profile → Application Passwords**.

If Divi is not active, all endpoints return `503 divi_unavailable`. See [setup-guide.md](../../../docs/setup-guide.md) for the full onboarding walkthrough including MCP server registration.

## Pairing with the MCP server

Communication is via the `/diviops/v1/*` REST namespace, authenticated with Application Passwords. The MCP server reads the plugin's per-tool capability map at startup (the `/handshake` endpoint) and only exposes tools the plugin advertises support for — so you can update the plugin and server independently and unsupported tools fail with a clear `capability_missing` error rather than silent runtime breakage.

After installing the plugin, register the MCP server with Claude:

```bash
claude mcp add diviops-mcp \
  --env WP_URL=http://your-site.local \
  --env WP_USER=your-wp-username \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  -- npx @diviops/mcp-server
```

See the [DiviOps MCP Server README](../../../diviops-server/) for full setup and the response contract.

## Capabilities

The plugin exposes these capability surfaces (full per-endpoint reference, 67 tools: [docs/server-reference.md](../../../docs/server-reference.md)):

- **Page building** — Divi page/section/module/canvas CRUD; Theme Builder layouts + templates
- **SCF setup + management** — field group provisioning, sync, export/import
- **CPT + post population** — wp-cli-routed post type registration + bulk post operations
- **Data model reasoning** — module schema introspection, SCF field group inspection, post meta surveys
- **Site auditing** — preset audits, design-token usage scans, orphan detection (presets, variables, dangling references)
- **Hybrid site harmonization** — design token APIs (`variable_*`, `global_color_*`, `global_font_*`) for cross-surface design system management between Divi pages and custom PHP templates

## Authentication & permissions

All endpoints require Application Password authentication (Basic Auth). Three permission tiers:

| Tier | WP Capability | Endpoints |
|------|--------------|-----------|
| **Read** | `edit_posts` | Most GET endpoints, `/render`, `/validate/blocks` |
| **Write** | `edit_pages` | Page creation and content modification |
| **Admin** | `manage_options` | Theme options, preset audit/cleanup/update/delete, library save, variable management, scan-orphans |

If Divi is not active, all endpoints return `503 divi_unavailable`. All write operations automatically clear Divi's `et-cache` to ensure CSS regeneration.

## Upgrade from the previous plugin name

1. Deactivate the old `Divi MCP Agent` plugin.
2. Install or copy `diviops-agent/`.
3. Activate `DiviOps Agent`.
4. Keep your MCP server config pointed at `/wp-json/diviops/v1/`; the REST namespace is unchanged.

## Learn more

- [DiviOps MCP Server README](../../../diviops-server/) — server quick start + response contract
- [setup-guide.md](../../../docs/setup-guide.md) — full onboarding walkthrough
- [server-reference.md](../../../docs/server-reference.md) — full per-tool reference
- [troubleshooting.md](../../../docs/troubleshooting.md) — common errors and resolutions
