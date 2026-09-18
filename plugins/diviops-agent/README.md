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

- `Stable tag` matches the plugin header `Version` (`1.5.25`).
- `Requires at least` and `Requires PHP` mirror the main plugin header.
- `Tested up to` is evidence-based for this repo/substrate and should not be raised until the Free plugin is actually tested on that WordPress version.
- External-service/authentication disclosure must mention the separately distributed npm MCP server, WordPress Application Passwords, and the rule that secrets do not belong in issues, examples, screenshots, or repo files.
- Free/Pro copy must keep the Free plugin useful while making clear that Pro is the paid workflow-leverage layer and that not every MCP tool is Free-backed.

Free 1.5.25 prepares a read-only Design System dashboard for inspecting existing
presets, variables and sampled consumers. Preset inspection exposes direct
variable-reference IDs and explicit partial usage coverage; zero references do
not establish that deletion is safe, and stored settings are not computed styles.
Layout warnings now account for supported preset-provided container settings
while preserving inline precedence and unresolved-reference warnings. No write
behavior or global MCP/Pro compatibility floor changes. Candidate package
validation and publication remain separate from this source preparation.

Free 1.5.24 prepares a small correction to the Setup Guide book icon's vertical
alignment in the DiviOps dashboard on WordPress 7.1. This is a presentation-only
fix; existing behavior and compatibility requirements remain unchanged. No MCP
or Pro update is required for the Free dashboard fix. Candidate package
validation and publication remain separate from this source preparation.

Free 1.5.23 prepares basic authoring for one existing applicable top-level SCF
6.9.4 text field. MCP 1.5.52 exposes its capability-gated REST writer, with
preview enabled by default, exact expected-state checks, field validation and
persisted value/reference readback. Pro and WP-CLI are not required. The writer
does not author Divi bindings/design or field definitions; it supplies no atomic
CAS, SCF snapshot rollback or full native-form equivalence. The prior bounded
local proof used temporary Free 1.5.22/MCP 1.5.51, not these candidate versions.
Candidate package validation and publication remain separate.

Free 1.5.22 prepares clearer Not active labels for unloaded optional add-ons and
read-only snapshot history after the component overview, collapsed by default
with its count visible. It also supplies the internal Free-owned protection
service for reviewed recovery: capture the current layout, verify the recovery
point against the actual after-state and permit at most one bounded recovery
attempt on failure. Basic snapshot inspection and guarded restore remain Free;
direct Free restore behavior is unchanged. No public tool, restore UI, full-site
backup or global MCP/Pro version floor is added. Candidate package validation
and publication remain separate from this source preparation.

Free 1.5.21 refreshed the native WordPress admin dashboard presentation with a
clearer installed-component overview and expandable read-only snapshot metadata.
Existing access and compatibility requirements remain unchanged. Displayed status
does not verify MCP connectivity; snapshots are not full-site backups.

Free 1.5.20 added standard WordPress Posts support alongside Pages through
the existing status endpoint. Mapped edit and applicable publish permissions
remain enforced before dry-run plans and no-op responses. Custom post types
and attachments remain unsupported by this endpoint; scheduling is not expanded.
MCP 1.5.50 clarifies the existing tool description; request shapes and the
capability contract are unchanged. No new MCP or Pro version floor is introduced.

Free 1.5.19 prepares a canonical serialization correction for native module
updates containing HTML-bearing attributes. Strict integrity, backups and
rollback remain unchanged; this fix adds no capability or MCP/Pro version floor.
Source preparation is not publication or qualification of a newly built package.

Free 1.5.18 introduced bounded native staff-detail body evidence,
prerequisite checks and the existing recovery-store bridge. Inspection/preflight
remain Free; applying to one existing isolated target body requires Pro's
`cross_env_staff_body_apply` in addition to Free's `cross_env_staff_body_evidence`
and the existing workflow gates. These are feature-specific checks, not a raised
global minimum MCP or Pro/Free version floor. Restart MCP after supported plugin
updates to refresh the startup capability snapshot. Target records, field
definitions and template assignments are not created or changed. The prior
technical proof is not user visual acceptance or qualification of new packages;
it did not test a Visual Builder save or execute rollback.

Free 1.5.16 adds an optional exact-checksum guard to full-content page updates.
It refuses reviewed-content drift before mutation while preserving the legacy
write contract when the guard is omitted. The precise
`page_update_content_expected_checksum` capability distinguishes enforcement
from older Free versions that only advertise the base writer. The packaged plugin is syntax- and
startup-tested across PHP 7.4, 8.0, 8.1, 8.2, and 8.3 before publication.

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
