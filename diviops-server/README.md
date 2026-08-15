# DiviOps MCP Server

**An AI harness for WordPress site authoring — Divi-native today, WordPress-wide by design.**

The Node.js MCP server inside the DiviOps harness. It gives Claude Code, Codex, Claude Desktop, and other MCP clients a typed control layer over WordPress site state, dispatching to the DiviOps Agent plugin for Divi 5 page authoring, SCF and CPT data models, design tokens, presets, library and Theme Builder templates, site audits, and safe WP-CLI passthrough. Pairs with the `divi-5-builder` skill so the agent applies Divi's block format and design rules correctly.

```
Claude Code <-> MCP Server (stdio) <-> WordPress REST API <-> DiviOps Agent plugin
```

## Use cases

DiviOps fits multiple WordPress workflows where AI-driven authoring + management is the value:

- **Page building (Divi authoring)** — create + edit Divi pages, sections, modules, canvases via prompt; preset-driven design system reuse; Theme Builder layouts and templates.
- **SCF setup + management** — provision Secure Custom Fields field groups, sync schemas, export/import field group definitions; SCF data model becomes a tool surface, not an admin-UI flow.
- **CPT + post population** — register custom post types via wp-cli passthrough; bulk-populate posts and pages across any post type, not just Divi-built ones.
- **Data model reasoning** — schema introspection across Divi modules + SCF field groups + post meta; ask Claude what fields a post type carries, what attributes a module accepts, what tokens are defined.
- **WordPress site auditing** — preset audits, design-token usage scans, orphan detection (presets, variables, dangling references); broader site surveys via wp-cli (`wp option list`, `wp post list --format=json`, `wp post term list <id> <taxonomy> --format=json`, `wp user list`).
- **Hybrid sites (Divi + custom PHP)** — Divi authors the marketing pages; custom PHP templates handle dynamic ones (CPT listings, single-post views, member portals); design tokens harmonized across both surfaces via CSS custom properties driven from the Divi variable system.

## Quick start

The first three steps prove connectivity. Native Divi authoring additionally
requires the client-side skill and first-run verification in steps 4 and 5.

### 1. Install the WordPress plugin

Download and activate the **DiviOps Agent** plugin from WordPress.org once it is listed there. For pre-listing test packages or a manual fallback install, use the [direct zip](https://github.com/oaris-dev/diviops/raw/main/diviops-agent.zip) or browse the [public distribution repo](https://github.com/oaris-dev/diviops). Requires Divi 5.1+ on WordPress 6.5+.

The npm MCP server updates through npm. WordPress.org installs of the Free plugin update through the normal WordPress plugin update flow. For pre-listing test packages or a manual fallback install, replace `diviops-agent.zip` through **WP Admin → Plugins → Add New → Upload Plugin** and choose **Replace current with uploaded**.

### 2. Create an Application Password

In **WP Admin → Users → Your Profile → Application Passwords**:
- Enter a name (e.g. "MCP Server")
- Click "Add New Application Password"
- Copy the generated password and strip the spaces

### 3. Register the MCP server

The successor server line requires Node.js 22 or newer. Upgrade Node before
selecting `@diviops/mcp-server@1.5.40` or a later release. Operators who must
remain on Node 18 can pin the previously shipped compatible server explicitly:

```bash
npx -y --package=@diviops/mcp-server@1.5.39 diviops-mcp
```

That pin affects only the MCP server. Free and Pro WordPress plugin versions
use independent version series and remain compatible through the advertised
per-capability handshake. Direct npm/stdio and MCP v1 remain supported on the
Node 22 successor; MCP v2 fixtures and the launcher sidecar are not published.

Claude Code:

```bash
claude mcp add diviops-mcp \
  --env WP_URL=http://your-site.local \
  --env WP_USER=your-wp-username \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  -- npx -y --package @diviops/mcp-server diviops-mcp
```

Codex `~/.codex/config.toml`:

```toml
[mcp_servers.diviops-mcp]
command = "npx"
args = ["-y", "--package", "@diviops/mcp-server", "diviops-mcp"]

[mcp_servers.diviops-mcp.env]
WP_URL = "http://your-site.local"
WP_USER = "your-wp-username"
WP_APP_PASSWORD = "xxxxXXXXxxxxXXXXxxxxXXXX"
```

Then ask your AI client: **"List the pages on my site."** It calls
`diviops_page_list` and renders the result. This proves the plugin and MCP transport;
it does not prove that the client has loaded Divi authoring knowledge.

For Claude Desktop JSON, use `"command": "npx"` with args `["-y", "--package", "@diviops/mcp-server", "diviops-mcp"]`. The package also ships `diviops-preset`, so the explicit package/bin form is required; shorthand package invocation cannot reliably infer which bin to run.

The public npm package exposes exactly three supported CLIs:
`diviops-mcp`, `diviops-preset`, and `diviops-cross-env-preflight`. The
launcher-managed MCP sidecar is a separate future artifact identity; it is not
a public npm bin or part of the npm tarball. Repository launcher development
builds and supplies that private entrypoint explicitly. This boundary does not
change direct npm/stdio support.

For a deeper walkthrough (containerized environments, WP-CLI configuration, troubleshooting installation), see [setup-guide.md](../docs/setup-guide.md).

### 4. Load the `divi-5-builder` skill

Install it for Claude Code by running
`claude plugin marketplace add oaris-dev/diviops` and then
`claude plugin install divi-5-builder@diviops`, or copy the distribution's
`skills/*` into the supported skill directory for Codex/manual setups. WordPress
and npm updates do not update manual skill copies. Avoid simultaneous
plugin-managed and stale manual copies, then restart the client and confirm
`divi-5-builder` is visible.

### 5. Prove native module authoring

Run the disposable, fail-closed
[native Divi verification](../docs/setup-guide.md#first-run-native-divi-verification).
Stop if the skill or required tools are unavailable. Do not accept page-sized HTML,
Code modules, iframe layouts, or structural HTML in text/container fields as a
fallback for editable native Divi modules.

## Example workflow

> **You:** Create a hero section on a new page called "Spring Launch" with a heading, subheading, and a CTA button. Use my brand colors.

Claude orchestrates a few tool calls in sequence:

1. `diviops_global_color_list` — discovers your brand palette.
2. `diviops_template_list` / `diviops_template_get` — pulls a verified hero template that matches the request.
3. `diviops_validate_blocks` with inline `content` — confirms the constructed hero markup is well-formed before any write.
4. `diviops_page_create` — creates `Spring Launch` as a draft using those exact validated bytes.
5. `diviops_validate_blocks` with the saved `page_id` — verifies persisted readback.
6. `diviops_render_preview` — returns the rendered HTML so you can verify before publishing. Accepts inline `content` or a `page_id` to preview an existing page.

The skill enforces the Divi block format, the design system, and the response contract throughout — you stay at the prompt level.

## Tools at a glance

The server exposes **94 always-on tools** across the categories below. Each category links to representative tools; the full table lives in [server-reference.md](../docs/server-reference.md).

| Category | Use case | Tool prefixes |
|----------|----------|---------------|
| Page authoring | Create, edit, restructure pages | `page_*`, `section_*`, `module_*` |
| Design system | Manage colors, fonts, variables, presets | `variable_*`, `global_color_*`, `global_font_*`, `preset_*` |
| Library + templates | Reusable layouts + Theme Builder | `library_*`, `template_*`, `tb_*` |
| WordPress menus | Author reusable nav menus and theme-location assignments | `menu_*` |
| Semantic SEO metadata | Inspect provider support and author two explicit TSF text fields with checksum/readback guards | `seo_*` |
| Schema introspection | Module attribute discovery | `schema_*` |
| Canvas / off-canvas | Popups, modals, menus | `canvas_*` |
| SCF integration | Secure Custom Fields sync | `scf_*` |
| Render + validate | Preview HTML, validate block markup | `render_preview`, `validate_blocks` |
| WP-CLI passthrough | Escape hatch for site ops | `meta_wp_cli` |
| Cache + meta | Connection probe, identity, icons, cache flush | `meta_*` |

Use `diviops_meta_info` as the S0 preflight before dogfooding or product work. It returns `server_version`, a numeric `tool_count`, a `tools` catalog summary (`registered_total`, always-on count, Pro possible/registered counts by target), `plugins` version records for `diviops-agent`, `diviops-agent-pro`, FluentCart, and FluentCart Pro when available, plus the existing handshake and slice state.

Additional **conditionally-registered Pro tools** appear only on sites that have the Pro plugin (`diviops-agent-pro`) active alongside the target coverage plugin:

| Category | Conditional gate | Tool names |
|----------|------------------|------------|
| FluentCart reads (V1) | Pro plugin + FluentCart installed + module enabled | `diviops_fc_product_list`, `diviops_fc_product_get` |
| FluentCart simple product writes (V2) | Pro plugin + FluentCart installed + module enabled | `diviops_fc_product_create`, `diviops_fc_product_update`, `diviops_fc_product_delete` |
| FluentCart variation read/write (V3) | Pro plugin + FluentCart installed + module enabled | `diviops_fc_variation_list`, `diviops_fc_variation_update` |
| FluentCart license-settings read/write, incl. update-file pointer + readiness (V3/V3.3) | Pro plugin + FluentCart Pro installed + module enabled | `diviops_fc_license_settings_get`, `diviops_fc_license_settings_update` |
| FluentCart managed downloads + license changelog (V3.4) | Pro plugin + FluentCart installed + module enabled | `diviops_fc_download_list`, `diviops_fc_download_attach`, `diviops_fc_license_changelog_get`, `diviops_fc_license_changelog_update` |
| FluentCart order readback + guarded mark-paid (V3.1) | Pro plugin + FluentCart installed + module enabled | `diviops_fc_order_list`, `diviops_fc_order_get`, `diviops_fc_order_mark_paid` |
| FluentCart license readback (V3.1) | Pro plugin + FluentCart Pro installed + module enabled | `diviops_fc_license_list`, `diviops_fc_license_get`, `diviops_fc_license_activations_list` |
| FluentCart checkout readiness / gateway inspection (V3.2) | Pro plugin + FluentCart installed + module enabled | `diviops_fc_status`, `diviops_fc_gateway_list`, `diviops_fc_gateway_get` |
| Cross-env reviewed layout rollout | Pro plugin + `cross_env` module enabled | `diviops_cross_env_header_apply`, `diviops_cross_env_layout_apply` |
| Managed recovery Phase 1A | Pro plugin + opt-in `managed_recovery` module enabled | `diviops_managed_recovery_policy_get`, `diviops_managed_recovery_policy_preview`, `diviops_managed_recovery_policy_update`, `diviops_managed_recovery_retention_preview`, `diviops_managed_recovery_retention_apply`, `diviops_managed_recovery_audit_list` |

When the gates are not satisfied, the tools simply don't appear on the MCP surface — no error envelope, no missing-capability hint. See the `diviops-fluentcart` skill bundle for the operator-side guide.

See [server-reference.md](../docs/server-reference.md) for per-tool descriptions.

## Bundled CLI — `diviops-preset`

The package also ships a standalone command-line preset emitter, `diviops-preset`,
that produces byte-canonical Divi 5.5.x preset JSON gated by the verified-attrs
registry (`data/verified-attrs.json`). It is independent of the MCP stdio server —
run it directly. Current commands:

| Command | Emits |
|---|---|
| `diviops-preset button [options]` | `divi/button` group preset |
| `diviops-preset heading-font [options]` | `divi/font` group preset for `divi/heading` (Pattern A — Google Fonts — or Pattern B — local-hosted) |
| `diviops-preset text-body-font [options]` | `divi/font-body` group preset for `divi/text` — **Pattern A (Google Fonts) only**; Pattern B for body-text has no registered canonical shape and is refused |
| `diviops-preset spacing [options]` | `divi/spacing` group preset (currently `divi/section` only; padding + margin, desktop state). Other module cells are `SCHEMA_OBSERVED` and refused at the gate |

```bash
diviops-preset button --name "Primary" --bg-color gcid-primary-color \
  --bg-color-hover gcid-secondary-color --radius 8px \
  --font-family Inter --font-weight 600 --font-color gcid-body-color

diviops-preset heading-font --name "Heading H1" --pattern google \
  --font-family Inter --font-weight 700 \
  --font-color gcid-heading-color --font-size 48px

diviops-preset text-body-font --name "Body Text" --pattern google \
  --font-family Inter --font-weight 400 \
  --font-color gcid-body-color --font-size 16px

diviops-preset spacing --name "Section Rhythm" --module divi/section \
  --padding-top 80px --padding-bottom 80px --margin-bottom 40px
```

`--dry-run` (the default) composes and prints the canonical JSON with no
credentials and no network. `--apply` posts to the existing `/preset/create`
REST route, reusing the same `WP_URL` / `WP_USER` / `WP_APP_PASSWORD` env vars.

The CLI's coverage is intentionally narrow: only the (module, group, variant)
combinations whose canonical shape is VB-verified in the registry are
emittable. It is **not** an all-module or all-font-family emitter — each
additional vertical slice lands with its own verified evidence. See the
[preset-cli reference](https://github.com/oaris-dev/diviops/blob/main/diviops-server/src/preset-cli/README.md)
for the full command reference (the `src/` tree is not part of the published
npm package — this link resolves on the repository).

## Bundled CLI — `diviops-cross-env-preflight`

The package also ships a dry-run-only cross-environment Theme Builder header
sync preflight:

```bash
diviops-cross-env-preflight --source source.json --target target.json --dry-run
```

This command reads two secret-free JSON files and prints a report. It does not
connect to WordPress, does not accept credentials, and has no write/apply path.
The CLI supports `tb_header_layout` and `tb_footer_layout` source payloads
preflighted against existing same-kind targets. Header-only inputs retain the
shipped header-v1 report/fingerprint; footer inputs use the generic layout-v1
binding by default. Pass `--contract layout-v1` to emit the generic binding for
a header rollout through `diviops_cross_env_layout_apply`; `--contract
header-v1` explicitly selects the compatibility contract and accepts header
inputs only. It reports source-domain upload URL
rewrites, attachment remap status, `gcid-*` target resolution including Divi
built-in customizer colors, resolved target global-color value evidence,
referenced `modulePreset` target-presence status, off-canvas/canvas refusal, and
the required cache cleanup plan. Each report also emits a deterministic
`confirmation_binding` fingerprint over the reviewed source identity/checksum,
target identity/current target checksum, rewrite plan, cache plan,
blocker/operator-action codes, reference-resolution summaries, target module
preset resolution, and the per-`gcid` value evidence for target colors
referenced by the source layout.
The fingerprint is reviewed-plan evidence for the Pro-gated compatibility
`diviops_cross_env_header_apply` or generic `diviops_cross_env_layout_apply`
tool. The CLI itself still has no apply path.

The Free plugin advertises `cross_env_footer_layout_evidence` when its source
export and target-context routes support footer kinds. The server selects those
two tools' public enums only after handshake: older or unproven plugins retain
the shipped header-only schemas instead of advertising unsupported footer input.

To collect the source JSON from the source WordPress site, call the Free/core
read-only MCP tool `diviops_cross_env_source_export_get` and save the returned
`data` object as `source.json`. The export includes the source origin, header
layout metadata, sanitized markup, a bare SHA-256 checksum of the exported
markup, export metadata, and best-effort attachment inventory from upload URLs
and attachment IDs. It also inventories referenced `attrs.modulePreset` IDs
without exporting preset definitions. It strips query strings, fragments,
credentials, nonces, cookies, signed URL material, admin URLs, and local
filesystem paths.

The MCP server also writes the same source payload to a bounded local artifact
under `.diviops-tmp/cross-env-source-payloads/` and returns
`data.source_payload_ref`. Use that reference for large real layouts when
calling a Pro layout apply tool; it avoids asking an LLM
to re-emit large markup byte-for-byte. The reference is a server-created handle
plus checksum, not an arbitrary filesystem path.

To collect the target JSON from the target WordPress site, call the Free/core
read-only MCP tool `diviops_cross_env_target_context_get` against the target
server and save the returned `data` object as `target.json`. Optional
`source_asset_hints`, `source_upload_paths`, and `source_attachment_ids` let the
target site search for media-library candidates. `source_upload_paths` preserves
exact path provenance, including for root-level filenames; basename-only hints
remain ambiguous when multiple target paths share a filename. No media is
uploaded and no global colors or layouts are created. The export includes a
SHA-256 checksum of the current target layout
`post_content` as `destination_checksum` so preflight can bind the reviewed plan
to the target state without exposing the raw target content. It also includes
`global_color_value_evidence`, a deterministic SHA-256 digest map for resolved
user global colors and WP Customizer-backed built-ins; preflight binds only the
entries that are referenced by source markup.
It also includes target D5 module preset IDs, without preset definitions, so the
preflight can fail closed when source markup references a module preset the
target site does not have.
For header/footer generic preflight it also includes exact target post type and
a canonical template-linkage digest over the active Theme Builder master ID,
its exact `_et_template` order, and linked-template slot, enabled, condition,
and exclusion evidence. Empty linkage uses `master_template_ids: []` and
`links: []`; assignment state is evidence only and is never mutated by rollout.

Workflow:

1. Call `diviops_cross_env_source_export_get` on the source site and save the
   returned `data` object as `source.json`.
2. Call `diviops_cross_env_target_context_get` on the target site and save the
   returned `data` object as `target.json`.
3. Run `diviops-cross-env-preflight --source source.json --target target.json --dry-run --contract layout-v1`
   for `diviops_cross_env_layout_apply`. Omit the contract flag (or select
   `header-v1`) only for the compatibility header apply tool.

`--apply` is intentionally refused. To mutate a target, use the separate
Pro-gated `diviops_cross_env_layout_apply` tool with the generic reviewed
fingerprint and either inline `source_payload` for small/disposable tests or
`source_payload_ref` for large layouts. The existing header tool remains the
header-v1 compatibility path. Both refuse media
upload/import, global color creation/import, off-canvas reconcile, and new
target layout creation.

## Response contract

Tools return a standardized envelope. The shape lets clients branch on `ok` and machine-readable `error.code` without parsing freeform messages.

```jsonc
// Success
{ "ok": true, "data": <payload> }
// Failure
{ "ok": false, "error": { "code": "<code>", "message": "<human>", "hint": "<optional>" } }
```

### Standard error codes

| code | HTTP | meaning |
|---|---|---|
| `not_found` | 404 | Target ID does not resolve |
| `invalid_input` | 400 | Schema violation, malformed args |
| `validation_failed` | 400 | `validate_blocks`-detected shape error |
| `conflict` | 409 | Uniqueness collision |
| `forbidden` | 403 | Row-level WordPress auth signal |
| `capability_missing` | 412 | Connected plugin does not advertise the capability required by this tool; component versions are independent |
| `wp_error` | 500 | Underlying WordPress error |
| `divi_error` | 500 | Divi-specific error (block parser, validator, etc.) |

### Namespace-specific codes

Namespaces extend the vocabulary using the `<namespace>.<reason>` convention — e.g. `meta_wp_cli.command_failed`, `scf.not_configured`, `preset.bucket_mismatch`, `variable.customizer_default_immutable`, `seo.provider_absent`, and `seo.metadata_drift`. Namespace-prefixed codes carry structured `error.data` documenting the failure (exit codes, conflicting fields, reference counts, checksums, rollback evidence, etc.). Per-tool descriptions name the codes each tool emits and the `error.data` shape that accompanies them.

### Per-tool `error.data` extensions

Some tools attach a structured `error.data` payload alongside the `code`/`message`/`hint` envelope — e.g. `meta_wp_cli` carries `{ exit_code, stdout, stderr }` on `meta_wp_cli.command_failed`, `global_color_delete` carries `{ id, ref_count, locations[], scan_truncated, scanned_posts[] }` on `conflict`, and conflict-class adopters across `canvas_*`/`library_*`/`variable_*` echo the conflicting fields. The shape is per-tool and documented in each tool's description prose, not in this canonical envelope summary. The summary stays terse because (a) most tools never emit `error.data` and advertising it universally would be misleading, and (b) the per-tool shape diverges and `data?: unknown` would be information-free. The runtime mechanism is `withCode`'s 4th `data` argument (server-local) / `envelope_error()`'s `$data` parameter (plugin-routed); both flow through `wrapResponse` to land on `error.data`.

### `dry_run` plan shape

Every write tool accepts `dry_run: boolean` (default `false`). When `true`, the response carries a uniform plan shape and no state is mutated:

```json
{
  "ok": true,
  "data": {
    "dry_run": true,
    "plan": {
      "summary": "Would update 1 attr path(s) on module 'Hero CTA' (page #42, type divi/button).",
      "changes": [
        { "kind": "module.update", "target": "page#42/divi/button/Hero CTA#button.decoration.font.font.desktop.value.color", "before": "#000", "after": "#ff0066" }
      ]
    }
  }
}
```

`meta_wp_cli` and `scf_import` do not accept `dry_run` (raw passthrough / upstream gap respectively). `scf_sync` passes `dry_run` through to upstream `wp scf json sync --dry-run`, so its preview is the upstream plain-text summary rather than a plugin-built `data.plan`. For bulk preview-then-commit flows (preset reassign, preset cleanup), see [safety-patterns.md](../docs/safety-patterns.md).

Selected guarded post-content write tools also accept `backup: true`. In apply
mode the Free plugin stores an option-backed rollback snapshot before writing
and returns `data.backup` evidence with the snapshot id and status. With
`dry_run: true`, `backup: true` only reports the planned snapshot; it never
creates an option record.

`diviops_rollback_snapshot_restore` restores those snapshots only to their
captured post/page or Theme Builder layout target. It requires row-level edit
permission, refuses content or supported Divi post-meta drift before mutation,
uses the same full-content integrity/readback guard, and has no force override
or second pre-restore snapshot in this MVP.

`diviops_seo_metadata_update` is a separate explicit-metadata-only path. It
accepts no raw provider keys, requires the checksum from
`diviops_seo_metadata_get`, validates plain text before TSF sanitization,
verifies exact stored readback, and performs request-local restoration on a
mismatch. It does not create a persistent rollback snapshot; effective
provider output is verified through a follow-up get request.

### `_meta.idempotent` markers

Every tool's `_meta.idempotent` field documents how it behaves under repeat calls with identical inputs. Some tools are silent-success idempotent (e.g. `page_trash` on an already-trashed post returns `ok: true` with `data.already_trashed = true`); others are side-effect-equivalent (re-running produces the same final state via different intermediate effects). See [idempotency-audit.md](../docs/idempotency-audit.md) for the per-tool record.

## Configuration

### Environment variables

| Variable | Required | Description |
|----------|----------|-------------|
| `WP_URL` | Yes | WordPress site URL (e.g. `http://mysite.local`) |
| `WP_USER` | Yes | WordPress username with Editor or Admin role |
| `WP_APP_PASSWORD` | Yes | Application Password (spaces stripped) |
| `WP_PATH` | No | WordPress filesystem path for Local by Flywheel, or wrapper working directory when `WP_CLI_CMD` needs project context |
| `WP_CLI_CMD` | No | Custom WP-CLI command prefix for containerized environments (e.g. `ddev wp`, `npx wp-env run cli wp`) |
| `LOCAL_SITE_ID` | No | Override auto-detection of Local by Flywheel site ID |
| `DIVIOPS_WP_CLI_ALLOW` | No | Opt-in extended WP-CLI commands — see [wp-cli-security.md](../docs/wp-cli-security.md) |
| `DIVIOPS_WP_CLI_SAFE_FS_ROOT` | No | Path to constrain filesystem-touching wp-cli commands. **Required** in `WP_CLI_CMD` wrapper mode |
| `DIVIOPS_WP_CLI_UNSAFE_FS` | No | Set to `1` to disable filesystem flag validation entirely |

### Containerized environments

The server connects via standard WordPress REST API and works with any environment that exposes WordPress over HTTP with Application Password support — Local by Flywheel, DDEV, wp-env, WordPress Studio, DevKinsta, custom hosts. See [setup-guide.md](../docs/setup-guide.md) for environment-specific `WP_CLI_CMD` examples and HTTPS / `WP_ENVIRONMENT_TYPE` notes.

## Troubleshooting

Common quick fixes — full reference in [troubleshooting.md](../docs/troubleshooting.md).

- **"Missing required environment variable(s)"** — ensure `WP_URL`, `WP_USER`, `WP_APP_PASSWORD` are all set on `claude mcp add`.
- **`npx` fails with "could not determine executable to run"** — use `npx -y --package @diviops/mcp-server diviops-mcp`; this explicitly selects the MCP server bin.
- **"Connection failed"** — verify the plugin is active by visiting `{WP_URL}/wp-json/diviops/v1/schema/settings`; test the credentials with `curl -u "user:pass" …`.
- **"This tool requires plugin capability"** — the connected plugin does not advertise the capability this tool needs. Server and plugin versions are independent; install a compatible plugin from the same DiviOps suite release or a newer supported component, then reconnect or restart the MCP session to refresh the handshake.
- **Preset edits not visible on the frontend** — Divi serves frontend CSS from `wp-content/et-cache/{post_id}/`, which `wp cache flush` doesn't touch. Use `diviops_meta_flush_cache` after preset writes; `post_id` mode also sweeps that exact directory and reports `post_dir_sweep` evidence.

## Learn more

- [setup-guide.md](../docs/setup-guide.md) — full onboarding walkthrough (containerized envs, HTTPS, Application Passwords)
- [server-reference.md](../docs/server-reference.md) — full per-tool reference table
- [wp-cli-security.md](../docs/wp-cli-security.md) — allowlist, extended commands, FS validation
- [safety-patterns.md](../docs/safety-patterns.md) — Pattern A (refuse-with-override) + Pattern B (preview-then-commit) + universal `dry_run`
- [troubleshooting.md](../docs/troubleshooting.md) — common errors and resolutions
- [idempotency-audit.md](../docs/idempotency-audit.md) — repeat-call semantics per tool
- **`divi-5-builder` skill** — block format rules, design patterns, workflow guidance (ships in the dist repo)

## Requirements

- Node.js >= 22.0.0
- PHP >= 7.4
- WordPress >= 6.5
- Divi 5 theme active
- DiviOps Agent WordPress plugin installed and active

## License

MIT
