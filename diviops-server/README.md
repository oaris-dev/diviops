# DiviOps MCP Server

**AI-driven WordPress site authoring — Divi-native, with the rest of WP in scope.**

Programmatic site authoring through Claude Code, Claude Desktop, and any other MCP client. Built on Divi 5 as the page authoring foundation, the suite also handles WordPress data models — custom post types, Secure Custom Fields, taxonomies, site audits, and hybrid sites where Divi authors the marketing pages and custom PHP templates handle the dynamic ones, with design tokens harmonized across both surfaces. Pairs with a WordPress companion plugin and ships alongside a Claude skill that knows the Divi 5 block format.

```
Claude Code <-> MCP Server (stdio) <-> WordPress REST API <-> DiviOps Agent plugin
```

## Use cases

DiviOps fits multiple WordPress workflows where AI-driven authoring + management is the value:

- **Page building (Divi authoring)** — create + edit Divi pages, sections, modules, canvases via prompt; preset-driven design system reuse; Theme Builder layouts and templates.
- **SCF setup + management** — provision Secure Custom Fields field groups, sync schemas, export/import field group definitions; SCF data model becomes a tool surface, not an admin-UI flow.
- **CPT + post population** — register custom post types via wp-cli passthrough; bulk-populate posts and pages across any post type, not just Divi-built ones.
- **Data model reasoning** — schema introspection across Divi modules + SCF field groups + post meta; ask Claude what fields a post type carries, what attributes a module accepts, what tokens are defined.
- **WordPress site auditing** — preset audits, design-token usage scans, orphan detection (presets, variables, dangling references); broader site surveys via wp-cli (`wp option list`, `wp post list --format=json`, `wp user list`).
- **Hybrid sites (Divi + custom PHP)** — Divi authors the marketing pages; custom PHP templates handle dynamic ones (CPT listings, single-post views, member portals); design tokens harmonized across both surfaces via CSS custom properties driven from the Divi variable system.

## Quick start

Three steps to your first tool call.

### 1. Install the WordPress plugin

Download and activate the **DiviOps Agent** plugin — [direct zip](https://github.com/oaris-dev/diviops/raw/main/diviops-agent.zip) or browse the [public distribution repo](https://github.com/oaris-dev/diviops). Requires Divi 5.1+ on WordPress 6.5+.

### 2. Create an Application Password

In **WP Admin → Users → Your Profile → Application Passwords**:
- Enter a name (e.g. "MCP Server")
- Click "Add New Application Password"
- Copy the generated password and strip the spaces

### 3. Register the MCP server with Claude

```bash
claude mcp add diviops-mcp \
  --env WP_URL=http://your-site.local \
  --env WP_USER=your-wp-username \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  -- npx @diviops/mcp-server
```

Then ask Claude: **"List the pages on my site."** Claude calls `diviops_page_list` and renders the result. You're authoring with the suite.

For a deeper walkthrough (containerized environments, WP-CLI configuration, troubleshooting installation), see [setup-guide.md](../docs/setup-guide.md).

## Example workflow

> **You:** Create a hero section on a new page called "Spring Launch" with a heading, subheading, and a CTA button. Use my brand colors.

Claude orchestrates a few tool calls in sequence:

1. `diviops_global_color_list` — discovers your brand palette.
2. `diviops_template_list` / `diviops_template_get` — pulls a verified hero template that matches the request.
3. `diviops_page_create` — creates `Spring Launch` as a draft with the hero block markup.
4. `diviops_validate_blocks` — confirms the markup is well-formed before save.
5. `diviops_render_preview` — returns the rendered HTML so you can verify before publishing.

The skill enforces the Divi block format, the design system, and the response contract throughout — you stay at the prompt level.

## Tools at a glance

The server exposes **67 tools** across the categories below. Each category links to representative tools; the full table lives in [server-reference.md](../docs/server-reference.md).

| Category | Use case | Tool prefixes |
|----------|----------|---------------|
| Page authoring | Create, edit, restructure pages | `page_*`, `section_*`, `module_*` |
| Design system | Manage colors, fonts, variables, presets | `variable_*`, `global_color_*`, `global_font_*`, `preset_*` |
| Library + templates | Reusable layouts + Theme Builder | `library_*`, `template_*`, `tb_*` |
| Schema introspection | Module attribute discovery | `schema_*` |
| Canvas / off-canvas | Popups, modals, menus | `canvas_*` |
| SCF integration | Secure Custom Fields sync | `scf_*` |
| Render + validate | Preview HTML, validate block markup | `render_preview`, `validate_blocks` |
| WP-CLI passthrough | Escape hatch for site ops | `meta_wp_cli` |
| Cache + meta | Connection probe, identity, icons, cache flush | `meta_*` |

See [server-reference.md](../docs/server-reference.md) for per-tool descriptions.

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
| `capability_missing` | 412 | Plugin version below required for this tool |
| `wp_error` | 500 | Underlying WordPress error |
| `divi_error` | 500 | Divi-specific error (block parser, validator, etc.) |

### Namespace-specific codes

Namespaces extend the vocabulary using the `<namespace>.<reason>` convention — e.g. `meta_wp_cli.command_failed`, `scf.not_configured`, `preset.bucket_mismatch`, `variable.customizer_default_immutable`. Namespace-prefixed codes carry structured `error.data` documenting the failure (exit codes, conflicting fields, reference counts, etc.). Per-tool descriptions name the codes each tool emits and the `error.data` shape that accompanies them.

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

`meta_wp_cli` and `scf_import` do not accept `dry_run` (raw passthrough / upstream gap respectively). For bulk preview-then-commit flows (preset reassign, preset cleanup), see [safety-patterns.md](../docs/safety-patterns.md).

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
- **"Connection failed"** — verify the plugin is active by visiting `{WP_URL}/wp-json/diviops/v1/schema/settings`; test the credentials with `curl -u "user:pass" …`.
- **"This tool requires plugin capability"** — the plugin doesn't advertise the capability this tool needs. Update the plugin to the latest release.
- **Preset edits not visible on the frontend** — Divi serves frontend CSS from `wp-content/et-cache/{post_id}/`, which `wp cache flush` doesn't touch. Use `diviops_meta_flush_cache` after preset writes.

## Learn more

- [setup-guide.md](../docs/setup-guide.md) — full onboarding walkthrough (containerized envs, HTTPS, Application Passwords)
- [server-reference.md](../docs/server-reference.md) — full per-tool reference table
- [wp-cli-security.md](../docs/wp-cli-security.md) — allowlist, extended commands, FS validation
- [safety-patterns.md](../docs/safety-patterns.md) — Pattern A (refuse-with-override) + Pattern B (preview-then-commit) + universal `dry_run`
- [troubleshooting.md](../docs/troubleshooting.md) — common errors and resolutions
- [idempotency-audit.md](../docs/idempotency-audit.md) — repeat-call semantics per tool
- **`divi-5-builder` Claude skill** — block format rules, design patterns, workflow guidance (ships in the dist repo)

## Requirements

- Node.js >= 18.0.0
- PHP >= 7.4
- WordPress >= 6.5
- Divi 5 theme active
- DiviOps Agent WordPress plugin installed and active

## License

MIT
