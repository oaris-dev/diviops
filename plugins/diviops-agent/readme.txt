=== DiviOps Agent ===
Contributors: diviops
Tags: divi, mcp, ai, rest-api, site-builder
Requires at least: 6.5
Tested up to: 7.1
Stable tag: 1.5.26
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Codex or Claude to WordPress through MCP. Build and improve editable Divi 5 pages with the DiviOps AI harness.

== Description ==

Work on your WordPress website with Codex, Claude Code, Claude Desktop or another compatible MCP client. DiviOps Agent connects the DiviOps AI harness to your site, so your agent can inspect existing content and help build and improve native, editable Divi 5 pages.

DiviOps is in public beta. It targets Divi 5 today, with WordPress as the wider foundation. Your agent works in its own client; this plugin provides the authenticated WordPress REST bridge.

= What you can do with Free =

* Build and update native Divi page content that remains editable in the Visual Builder.
* Inspect module schemas and validate block structures before applying supported changes.
* Inspect and manage supported Divi presets and design variables, including preset audits.
* Work with supported Divi Library and Theme Builder operations.
* Inspect site capabilities and diagnose the connected setup.
* Inspect saved layout snapshots and use supported guarded restore operations. These are not full-site backups.

Available operations depend on your installed versions, WordPress permissions and the capabilities reported by the connected site. A successful structural check does not replace a visual review.

= Bring your project context =

Keep your audience, page goals, design references and review steps in your AI client's project documentation. Use the DiviOps authoring skills alongside that context to guide repeatable workflows. The WordPress plugin does not store or manage those client-side documents.

= How the pieces fit together =

Your AI client runs the separately installed DiviOps MCP server. That server connects to this plugin using a WordPress Application Password. The plugin's WordPress dashboard shows installed components and supported status information; it is not an AI chat interface or proof of an active MCP connection.

Start with a staging site, ask the agent to inspect before editing, and review the result before publishing.

[Setup documentation](https://diviops.com/docs/) | [Real workflows](https://diviops.com/use-cases/)

= Free and optional Pro =

This listing distributes the Free WordPress plugin. Selected advanced workflows, including supported cross-environment apply operations, require separate Pro components. Installing this plugin does not enable every DiviOps tool. The capability handshake identifies what the connected site supports.

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

1. Install **DiviOps Agent** from **Plugins > Add New**, or upload `diviops-agent.zip`.
2. Activate **DiviOps Agent** and confirm Divi 5 is active on the site.
3. Create a WordPress Application Password from **Users > Profile > Application Passwords**.
4. Configure the separately installed DiviOps MCP server in your compatible AI client with your site URL, WordPress username and Application Password.
5. Follow the setup guide to add the DiviOps authoring skills where supported, then ask your agent to inspect the site's capabilities before editing.

See the [DiviOps setup guide](https://diviops.com/docs/) for client configuration and connection checks.

== Frequently Asked Questions ==

= Can I use Codex or Claude? =

Yes. The DiviOps MCP server connects compatible clients including Codex, Claude Code and Claude Desktop to this plugin. Client configuration and client access are separate from the WordPress plugin.

= Does this plugin work without the MCP server? =

The plugin can be installed and its dashboard inspected independently, but the AI workflow requires the separately installed MCP server and a compatible AI client.

= Does this plugin require Divi? =

Yes. DiviOps Agent targets Divi 5 today. Authenticated requests return a `divi_unavailable` error when Divi is not active.

= Can I still edit the result in Divi? =

Native Divi modules remain editable in the Visual Builder. Review your agent's output on staging, including responsive layouts, before publishing.

= How are permissions handled? =

All endpoints require WordPress Application Password authentication. Read endpoints generally require `edit_posts`, write endpoints generally require `edit_pages`, and administrative surfaces such as preset and variable management require `manage_options`. Content creation and status changes additionally require the mapped create/publish capabilities for the affected post type.

= Is every DiviOps MCP tool included in this Free plugin? =

No. Free includes the core site-authoring bridge described above. Selected advanced workflows require separate Pro components. The MCP server checks the capability handshake for the connected site.

== Screenshots ==

1. DiviOps Agent Free dashboard showing installed components, rate limits and setup information. Optional add-ons are not active in this capture.
2. An agent-authored page open in the Divi 5 Visual Builder, with the native Heading module content controls available for continued editing. Divi is a separate required product.

== Changelog ==

= 1.5.26 =

* Adds optional bounded page-content reads with UTF-8-safe chunks and a full-content checksum required for continuation. Content drift refuses continuation rather than mixing versions.
* Updated MCP servers check the precise bounded-read capability before fetching a page from an older plugin. Default reads, authentication, permissions, writes and compatibility requirements are unchanged.
* Bounds returned chunks, not upstream memory use: each request still reads and hashes the full content. This is not a snapshot service or a guarantee for every client.

= 1.5.25 =

* Adds a read-only Design System dashboard for existing presets, variables and sampled consumers, with explicit partial coverage and unresolved references.
* Exposes direct variable-reference IDs in preset inspection. Zero references do not mean safe to delete; stored settings are not computed styles.
* Accounts for supported preset-provided container layout settings in validation warnings, preserving inline precedence and unresolved-reference warnings. Write behavior and compatibility requirements are unchanged.

= 1.5.24 =

* Corrects the Setup Guide book icon's vertical alignment in the DiviOps dashboard on WordPress 7.1.
* Presentation only; existing behavior and compatibility requirements remain unchanged.

= 1.5.23 =

* Adds basic Free updates for one existing SCF 6.9.4 top-level text field, with preview by default, expected-state checks and persisted readback.
* No atomic CAS, SCF snapshot rollback or full native-form equivalence; existing Divi bindings/design are not authored.

= 1.5.22 =

* Shows unloaded optional add-ons as Not active and places read-only snapshot history after the component overview, collapsed by default with its count visible.
* Adds internal protected-restore support: capture the current layout before restoration, verify the recovery point against the actual after-state, and allow at most one bounded recovery attempt on failure.
* Keeps basic snapshot inspection and guarded restore Free. Direct Free restore behavior is unchanged; this adds no public tool, restore UI or full-site backup.

= 1.5.21 =

* Refreshes the scoped native WordPress admin dashboard with a clearer installed-component overview and expandable read-only snapshot metadata.
* Preserves existing access and compatibility requirements. Displayed status does not verify MCP connectivity; snapshots are not full-site backups.

= 1.5.20 =

* Supports standard WordPress Posts alongside Pages through the existing status endpoint.
* Preserves mapped edit and applicable publish permission checks before dry-run plans and no-op responses. Custom post types and attachments remain unsupported; scheduling is not expanded.

= 1.5.19 =

* Fixes native module updates that were refused when WordPress canonically escaped HTML inside unchanged module attributes.
* Uses the existing canonical serializer for the selected module while preserving strict content integrity, backups and rollback behavior.

= 1.5.18 =

* Adds bounded source/target evidence and prerequisite checks for an existing native staff-detail Theme Builder body through the cross_env_staff_body_evidence capability.
* Keeps inspection and preflight Free; applying the reviewed body design requires Pro staff-body support. Target records, field definitions and template assignments are not created or changed.
* Bridges changed Pro body writes to the existing Free recovery store; dry-run and already-converged requests create no snapshots. This is not general body migration or arbitrary custom-field mapping.

= 1.5.17 =

* Adds optional body layout content to the existing Theme Builder template-creation operation, including the new body's ID and template link.
* Checks body create/publish permissions and combined layout-content limits before dry-run planning or writes, using the existing core sanitization path.
* Advertises body support through the precise tb_template_create_body capability. Updated MCP clients refuse nonempty body requests against older plugins; omitted or empty body content preserves existing behavior.
* Creates the requested layout without a global Theme Builder save or unrelated legacy-template cleanup.

= 1.5.16 =

* Adds an optional exact-checksum guard to full-content page updates, including a fresh pre-write read that refuses concurrent page drift before mutation.
* Advertises exact-checksum enforcement separately from the legacy unconditional writer so clients can gate checksum-dependent workflows through the capability handshake.
* Enables receipt-owned Pro workflows to bind reviewed page content to the guarded Free write path while preserving legacy behavior when the optional checksum is omitted.

= 1.5.15 =

* Restores the advertised PHP 7.4+ compatibility by moving constants out of traits, preventing fatal activation errors on PHP 7.4 through 8.1.
* Preserves the existing Divi Post Filter compatibility repair and authoring input limits without changing their behavior.

= 1.5.14 =

* Adds cumulative input, block, nesting and string limits before full-content dry-run plans or writes, including combined Theme Builder layouts.
* Keeps native-first authoring with intentional custom HTML and preserves existing permissions, sanitization and operation-specific backup/readback safeguards.

= 1.5.13 =

* Confirms compatibility with WordPress 7.1 and updates the WordPress.org compatibility metadata.
* Keeps the MCP server, capabilities, REST behavior, and Free/Pro boundary unchanged from 1.5.12.

= 1.5.12 =

* Repairs the exact affected Divi 5.10/5.11 Post Filter product-price permission callback while preserving its route-specific nonce and editor authority boundary.
* Preserves exact upload-path provenance for reviewed cross-environment media matching, including custom upload locations and root-level files.
* Rejects ignored legacy Link-module attribute paths and fails closed when the canonical SEO provider plugin directory is unavailable.

= 1.5.11 =

* Adds stronger handshake and target-identity evidence for connected MCP health diagnostics while preserving the existing direct MCP and WordPress REST workflows.
* Enforces request-aware create and publish permissions before page status plans or mutations, including fixed-publish Canvas, Divi Library, and Theme Builder creation paths.
* Hardens access to Divi-owned global variable and preset registries without changing their storage keys or behavior.

= 1.5.10 =

* Adds provider discovery plus guarded get, set, and clear operations for explicit The SEO Framework title and description metadata on one editable post.
* Adds dry-run, checksum drift refusal, exact no-op, provider readback, lifecycle/cache evidence, and request-local rollback verification for supported metadata changes.
* Keeps the SEO surface semantic and explicit-metadata-only: generic postmeta and automatic Divi, dynamic-content, or Theme Builder description extraction are not included.

= 1.5.9 =

* Extends read-only cross-environment source and target evidence to existing Theme Builder headers and footers when the connected capability supports footer evidence.
* Adds metadata-only local storage sequence evidence so Pro retention workflows can order same-second rollback snapshots safely without exposing stored payloads.
* Keeps basic one-site snapshot capture, list, get, delete, dashboard inspection, and guarded restore in Free.

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

= 1.5.26 =

Adds optional checksum-bound page-content chunks with MCP 1.5.54. Restart the MCP session after updating to refresh capabilities. Default reads and permissions are unchanged; this is not a saved snapshot.

= 1.5.25 =

Adds read-only design-system inspection and preset-aware layout warnings. Usage coverage is partial, not deletion approval or computed styles. Existing write behavior and compatibility requirements remain unchanged.

= 1.5.24 =

Corrects the Setup Guide icon alignment on WordPress 7.1. Existing behavior and compatibility requirements remain unchanged; no MCP or Pro update is required for this Free dashboard fix.

= 1.5.23 =

Adds the basic Free SCF text-value writer for MCP 1.5.52. Requires SCF 6.9.4 and an existing applicable text field. Preview first; restart MCP to refresh capabilities. Expected-state/readback checks are not atomic CAS, rollback or full native-form save.

= 1.5.22 =

Clarifies add-on status and collapses snapshot history by default. Supplies the Free-owned protection service for reviewed recovery workflows. Basic Free restore remains available with its existing behavior; no global MCP or Pro version floor is raised.

= 1.5.21 =

Refreshes the native WordPress admin dashboard with a clearer installed-component overview and expandable read-only snapshot metadata. Existing access and compatibility requirements remain. Status does not verify MCP connectivity; snapshots are not full-site backups.

= 1.5.20 =

Fixes status updates for standard Posts alongside Pages. Existing edit/publish permissions, dry-run and no-op checks remain. Custom post types and attachments are unsupported. Scheduling is unchanged; no new MCP or Pro version floor is introduced.

= 1.5.19 =

Fixes false content-integrity refusals when editing native modules containing HTML attributes. Existing permissions, backups and rollback checks remain. MCP and Pro versions do not need to change for this fix.

= 1.5.18 =

Adds bounded staff-body evidence and recovery support. Apply requires a body-aware MCP client and Pro staff-body capability. Restart MCP after updates to refresh the capability handshake. Unrelated tools retain their compatibility gates.

= 1.5.17 =

For guided shared-detail workflows needing a new Theme Builder body layout. Use an updated MCP client and restart its session to refresh the capability handshake before requesting body content. This is not a general Visual Builder save guarantee.

= 1.5.16 =

Recommended for receipt-owned Pro page workflows that require exact page-checksum drift protection. MCP server and WordPress plugin versions remain independent; capability advertisement is the compatibility gate.

= 1.5.15 =

Required for sites running PHP 7.4 through 8.1. Update normally from WordPress, or manually replace the plugin with the 1.5.15 ZIP if the prior version triggered Recovery Mode.

= 1.5.14 =

Adds cumulative authoring input limits while keeping native-first guidance and the existing capability gate.

= 1.5.13 =

Confirms compatibility with WordPress 7.1. MCP server and WordPress plugin versions remain independent; capability advertisement is the compatibility gate.

= 1.5.12 =

Recommended for Divi 5.10/5.11 sites and reviewed cross-environment media workflows. MCP server and WordPress plugin versions remain independent; capability advertisement is the compatibility gate.

= 1.5.11 =

Recommended for beta users who want stronger target evidence and request-aware create/publish permission enforcement.
