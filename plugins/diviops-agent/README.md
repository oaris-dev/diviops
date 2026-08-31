# DiviOps Agent

**REST API bridge inside the DiviOps AI harness for WordPress — Divi-native today, WordPress-wide by design.**

The WordPress companion plugin for `@diviops/mcp-server`. Pairs with the MCP server to expose Divi 5 page authoring, SCF management, CPT/post population, data model introspection, and site auditing as `/diviops/v1/*` REST endpoints behind Application Password auth.

Divi is a registered trademark of Elegant Themes, Inc. DiviOps Agent is not affiliated with or endorsed by Elegant Themes.

> **Don't use this plugin standalone** — it's the WordPress side of a two-piece suite; install + configure the [DiviOps MCP Server](../../../diviops-server/) next.

## Requirements

- WordPress 6.5+
- Divi 5 theme (5.1.0+)
- PHP 7.4+
- Application Passwords enabled (default since WP 5.6)

## Installation

1. Zip this directory: `cd wp-content/plugins && zip -r diviops-agent.zip diviops-agent/`
2. **WP Admin → Plugins → Add New → Upload Plugin** — upload `diviops-agent.zip` and activate.
3. Create an Application Password under **WP Admin → Users → Profile → Application Passwords**.

If Divi is not active, all endpoints return `503 divi_unavailable`. See [setup-guide.md](../../../docs/setup-guide.md) for the full onboarding walkthrough including MCP server registration.

## Updates

The MCP server updates through npm. Once the Free WordPress plugin is published on WordPress.org, WordPress delivers plugin updates through the normal **Dashboard → Updates** and **Plugins** screens.

For pre-listing test packages or a manual fallback install, replace the plugin ZIP through WordPress admin:

1. Download `diviops-agent.zip` from the public dist repo root.
2. Go to **WP Admin → Plugins → Add New → Upload Plugin**.
3. Upload the new `diviops-agent.zip`.
4. Choose **Replace current with uploaded** when WordPress asks.

Your Application Password and MCP client config stay unchanged across Free plugin updates. Purchased Pro users activate Pro update access separately in **DiviOps → Pro License**.

## WordPress.org readiness metadata

The plugin includes a WordPress.org-format `readme.txt` and a plugin-local `changelog.txt`. The readme keeps the current public release entry; longer plugin-local history belongs in `changelog.txt` as the WordPress.org channel matures.

Current metadata policy:

- `Stable tag` matches the plugin header `Version` (`1.5.14`, prepared source candidate).
- `Requires at least` and `Requires PHP` mirror the main plugin header.
- `Tested up to` is evidence-based for this repo/substrate and should not be raised until the Free plugin is actually tested on that WordPress version.
- External-service/authentication disclosure must mention the separately distributed npm MCP server, WordPress Application Passwords, and the rule that secrets do not belong in issues, examples, screenshots, or repo files.
- Free/Pro copy must keep the Free plugin useful while making clear that Pro is the paid workflow-leverage layer and that not every MCP tool is Free-backed.

The 1.5.14 candidate is not published. Its changed PHP bytes require new
candidate-package compatibility evidence before publication; the retained
`Tested up to: 7.1` field does not inherit that proof from the 1.5.13 release.

Before a WordPress.org submission, validate the readme and plugin package:

```bash
git diff --check
php -l wp-content/plugins/diviops-agent/diviops-agent.php
```

Then run the official WordPress.org readme validator against `wp-content/plugins/diviops-agent/readme.txt` and run Plugin Check on the packaged plugin. For WordPress.org submission, use `diviops-agent` as the directory slug/text domain target. If WP-CLI is available in the target environment, the Plugin Check command shape is:

```bash
wp plugin install plugin-check --activate
wp plugin check diviops-agent --categories=plugin_repo
```

For WordPress.org-distributed installs, the Free plugin update channel is the standard WordPress.org plugin update flow. Manual ZIP replacement remains a fallback for pre-listing test packages and environments that intentionally install from the public dist repo.

## Pairing with the MCP server

Communication is via the `/diviops/v1/*` REST namespace, authenticated with Application Passwords. The MCP server reads the plugin's per-tool capability map at startup (the `/handshake` endpoint) and only exposes tools the plugin advertises support for — so you can update the plugin and server independently and unsupported tools fail with a clear `capability_missing` error rather than silent runtime breakage.

After installing the plugin, register the MCP server with Claude Code:

```bash
claude mcp add diviops-mcp \
  --env WP_URL=http://your-site.local \
  --env WP_USER=your-wp-username \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  -- npx -y --package @diviops/mcp-server diviops-mcp
```

For Codex, add the same server to `~/.codex/config.toml`:

```toml
[mcp_servers.diviops-mcp]
command = "npx"
args = ["-y", "--package", "@diviops/mcp-server", "diviops-mcp"]

[mcp_servers.diviops-mcp.env]
WP_URL = "http://your-site.local"
WP_USER = "your-wp-username"
WP_APP_PASSWORD = "xxxxXXXXxxxxXXXXxxxxXXXX"
```

See the [DiviOps MCP Server README](../../../diviops-server/) for full setup and the response contract.

## Capabilities

The plugin advertises 98 capability keys through the handshake (full MCP endpoint reference, 91 always-on tools: [docs/server-reference.md](../../../docs/server-reference.md)):

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
