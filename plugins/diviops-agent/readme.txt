=== DiviOps Agent ===
Contributors: davidricardo
Tags: divi, mcp, ai, rest-api, site-builder
Requires at least: 6.5
Tested up to: 7.0
Stable tag: 1.5.8
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

REST bridge for the DiviOps MCP server, so AI clients can work with Divi-powered WordPress sites.

== Description ==

DiviOps Agent is the WordPress-side companion plugin for DiviOps, an AI harness for WordPress site authoring. It exposes authenticated `/diviops/v1/*` REST endpoints that the `@diviops/mcp-server` package can use from Claude Code, Codex, Claude Desktop, and other MCP clients.

The Free plugin is useful on its own as the core REST bridge for Divi 5 page authoring, schema inspection, block validation, design-token management, preset audits, and safe read-only diagnostics. Pro adds paid workflow leverage, including advanced cross-environment apply flows, deeper paid coverage slices, Pro plugin handlers, license/update handling, and higher-support agency or studio workflows. Not every current or future MCP tool is guaranteed to be backed by the Free plugin; tools are advertised through the DiviOps capability handshake for the plugins installed on the connected site.

This plugin is not intended to be used as a standalone admin UI. Install and activate the WordPress plugin, create a WordPress Application Password, then configure the DiviOps MCP server for your AI client.

Divi is a registered trademark of Elegant Themes, Inc. DiviOps Agent is not affiliated with or endorsed by Elegant Themes.

= External services and authentication =

DiviOps Agent is a WordPress REST bridge. Normal Free plugin runtime does not require the plugin to contact DiviOps servers.

To use the plugin, you run the separately distributed `@diviops/mcp-server` package, which is published through npm. Depending on your installation method, `npx` or npm may download that package from the npm registry. The MCP server then connects to your WordPress site with WordPress Application Password authentication.

Relevant external service:

* Service: npm registry, used to distribute `@diviops/mcp-server`
* Package: https://www.npmjs.com/package/@diviops/mcp-server
* Terms: https://www.npmjs.com/policies/terms

Do not paste Application Passwords, license keys, access tokens, cookies, or other secrets into issue comments, documentation examples, screenshots, or repository files. Keep credentials in your AI client's local MCP configuration or environment variables.

= Privacy =

The plugin does not add analytics or tracking. It exposes authenticated REST endpoints on your WordPress site. What data is read or written depends on the MCP tools you choose to run, your WordPress user's permissions, and the installed DiviOps plugin capabilities.

== Installation ==

1. Upload `diviops-agent.zip` through **Plugins > Add New > Upload Plugin**.
2. Activate **DiviOps Agent**.
3. Confirm Divi 5 is active on the site.
4. Create a WordPress Application Password from **Users > Profile > Application Passwords**.
5. Configure the DiviOps MCP server for your AI client with your site URL, WordPress username, and Application Password.

For full setup instructions, see the DiviOps setup guide in the distribution package.

== Frequently Asked Questions ==

= Does this plugin work without the MCP server? =

No. DiviOps Agent is the WordPress REST bridge. The MCP server is the client-facing layer that exposes tools to Claude Code, Codex, Claude Desktop, and other MCP clients.

= Does this plugin require Divi? =

Yes. DiviOps Agent targets Divi 5 today. Authenticated requests return a `divi_unavailable` error when Divi is not active.

= How are permissions handled? =

All endpoints require WordPress Application Password authentication. Read endpoints generally require `edit_posts`, write endpoints generally require `edit_pages`, and administrative surfaces such as preset and variable management require `manage_options`.

= Is every DiviOps MCP tool included in this Free plugin? =

No. The Free plugin backs the core useful surface. Some higher-leverage workflows and paid coverage slices require Pro plugin handlers. The MCP server checks the plugin capability handshake and only exposes or runs tools supported by the connected site.

== Changelog ==

= 1.5.8 =

* Adds a guarded preset-registry doctor for diagnosing and repairing duplicate or stale preset registry entries.
* Improves nested module moves with a parser-backed fallback when direct block parsing cannot preserve the requested placement.
* Rejects foreign CSS variable references recursively across supported Divi content and design writers.

= 1.5.7 =

* Adds guarded rollback snapshots for Divi content writes, including snapshot list/get/delete surfaces and restore support with checksum drift checks.
* Adds dashboard-ready rollback snapshot inspection data for operator review before restore.
* Keeps restore operations protected by readback verification and cache invalidation evidence.

= 1.5.6 =

* Adds typed WordPress menu tools for creating menus, adding page/custom-link items, reading normalized menu trees, and assigning registered theme locations through the DiviOps capability handshake.
* Adds safer FluentCart 1.5 Advanced Variations read support for attribute metadata inspection while continuing to refuse unsupported write shapes.
* Adds read-only post taxonomy term inspection through the sanctioned WP-CLI fallback path.

= 1.5.5 =

* Adds richer DiviOps preflight metadata for the MCP server, including plugin version records used by `diviops_meta_info`.
* Keeps authenticated DiviOps REST endpoints and capability handshake support aligned with the current MCP server release.
* Keeps `Stable tag` aligned with the plugin header version.

== Upgrade Notice ==

= 1.5.8 =

Recommended for beta users who want guarded preset-registry repair, more reliable nested module moves, and stronger CSS variable validation.
