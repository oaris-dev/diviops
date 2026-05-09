# DiviOps

**AI-driven WordPress site authoring — Divi-native, with the rest of WP in scope.**

[![npm](https://img.shields.io/npm/v/@diviops/mcp-server.svg?label=%40diviops%2Fmcp-server)](https://www.npmjs.com/package/@diviops/mcp-server)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Divi 5](https://img.shields.io/badge/Divi-5.1.0%2B-7E3DD3.svg)](https://www.elegantthemes.com/gallery/divi/)

Programmatic WordPress site authoring through Claude Code, Claude Desktop, and any other MCP client. Built on Divi 5 as the page authoring foundation, the suite also handles WordPress data models — custom post types, Secure Custom Fields, taxonomies, site audits, and hybrid sites where Divi authors the marketing pages and custom PHP templates handle the dynamic ones, with design tokens harmonized across both surfaces.

```
Claude Code ◄──► MCP Server (stdio) ◄──► WordPress REST API ◄──► DiviOps Agent plugin
                                                ▲
                                                │
                                       divi-5-builder skill
                                       (block format + design rules)
```

> **Beta software.** DiviOps is under active development. Use on production sites at your own discretion. Always back up your WordPress site before running write operations.

## What's in this distribution

| Component | What it is | Where it lives |
|---|---|---|
| **`diviops-agent`** WordPress plugin | REST API endpoints for Divi page data, section targeting, block validation, preset management. The contract layer between WordPress + Divi and the MCP server. | `diviops-agent.zip` at repo root |
| **`@diviops/mcp-server`** | Node.js MCP server that bridges Claude to WordPress. Distributed via npm — no clone, no build. | `npx @diviops/mcp-server` |
| **`divi-5-builder`** Claude skill | Block format rules, verified attribute paths, design patterns. Without it, Claude guesses attr formats and produces broken pages. | `skills/divi-5-builder/` (also installable via `claude plugin install oaris-dev/diviops`) |
| **`diviops-design-library`** plugin | Optional. CSS entrance animations, gradient text, glass effects, Three.js WebGL shaders. | `diviops-design-library.zip` at repo root |

## Use cases

DiviOps fits multiple WordPress workflows where AI-driven authoring + management is the value:

- **Page building (Divi authoring)** — create + edit Divi pages, sections, modules, canvases via prompt; preset-driven design system reuse; Theme Builder layouts and templates.
- **SCF setup + management** — provision Secure Custom Fields field groups, sync schemas, export/import field group definitions; SCF data model becomes a tool surface, not an admin-UI flow.
- **CPT + post population** — register custom post types via wp-cli passthrough; bulk-populate posts and pages across any post type, not just Divi-built ones.
- **Data model reasoning** — schema introspection across Divi modules + SCF field groups + post meta; ask Claude what fields a post type carries, what attributes a module accepts, what tokens are defined.
- **WordPress site auditing** — preset audits, design-token usage scans, orphan detection (presets, variables, dangling references); broader site surveys via wp-cli (`wp option list`, `wp post list --format=json`, `wp user list`).
- **Hybrid sites (Divi + custom PHP)** — Divi authors the marketing pages; custom PHP templates handle dynamic ones (CPT listings, single-post views, member portals); design tokens harmonized across both surfaces via CSS custom properties driven from the Divi variable system.

## Quick start

Three steps to your first tool call. For containerized environments, HTTPS configuration, and troubleshooting, see [SETUP.md](SETUP.md).

### 1. Install the WordPress plugin

Upload **`diviops-agent.zip`** (at the root of this repo) via **WP Admin → Plugins → Add New → Upload Plugin**, then activate it. Requires Divi 5.1+ on WordPress 6.5+.

Verify: visit `http://your-site.local/wp-json/diviops/v1/schema/settings` — you should get a 401 (auth required).

### 2. Create an Application Password

In **WP Admin → Users → Your Profile → Application Passwords**:

- Enter a name (e.g. "Claude MCP")
- Click "Add New Application Password"
- **Strip the spaces** from the generated password — WordPress shows `758r WQ1X URcg ...` for readability but accepts the spaceless form, which avoids argument-parsing surprises in `claude mcp add`.

### 3. Register the MCP server with Claude Code

```bash
claude mcp add diviops-mysite \
  --env WP_URL=http://your-site.local \
  --env WP_USER=your-wp-username \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  -- npx @diviops/mcp-server
```

For Local by Flywheel (enables the `diviops_meta_wp_cli` tool), add `--env "WP_PATH=/Users/you/Local Sites/your-site/app/public"`.

Restart Claude Code, then ask: **"List the pages on my site."** Claude calls `diviops_page_list` and renders the result. You're authoring with the suite.

### 4. Load the `divi-5-builder` skill

The skill teaches Claude the correct Divi 5 block format. Without it, Claude guesses attr formats and produces broken pages.

```bash
claude plugin install oaris-dev/diviops
```

Verify with `What skills do you have?` — you should see `divi-5-builder` listed.

For alternative skill installation paths (cloned repo, project-local copy), see [SETUP.md](SETUP.md#step-7-load-the-divi-5-builder-skill).

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

The suite exposes **66 tools** across the categories below. Per-tool descriptions, request shapes, and response payloads live in the server [README](diviops-server/README.md).

| Category | Use case | Tool prefixes |
|---|---|---|
| Page authoring | Create, edit, restructure pages | `page_*`, `section_*`, `module_*` |
| Design system | Manage colors, fonts, variables, presets | `variable_*`, `global_color_*`, `global_font_*`, `preset_*` |
| Library + templates | Reusable layouts + Theme Builder | `library_*`, `template_*`, `tb_*` |
| Schema introspection | Module attribute discovery | `schema_*` |
| Canvas / off-canvas | Popups, modals, menus | `canvas_*` |
| SCF integration | Secure Custom Fields sync | `scf_*` |
| Render + validate | Preview HTML, validate block markup | `render_preview`, `validate_blocks` |
| WP-CLI passthrough | Escape hatch for site ops | `meta_wp_cli` |
| Cache + meta | Connection probe, identity, icons, cache flush | `meta_*` |

## Response contract

Tools return a standardized envelope. The shape lets clients branch on `ok` and machine-readable `error.code` without parsing freeform messages.

```jsonc
// Success
{ "ok": true, "data": <payload> }
// Failure
{ "ok": false, "error": { "code": "<code>", "message": "<human>", "hint": "<optional>" } }
```

Standard error codes: `not_found` (404), `invalid_input` (400), `validation_failed` (400), `conflict` (409), `forbidden` (403), `capability_missing` (412), `wp_error` (500), `divi_error` (500). Namespaces extend the vocabulary using the `<namespace>.<reason>` convention — e.g. `meta_wp_cli.command_failed`, `scf.not_configured`, `preset.bucket_mismatch`. Namespace-prefixed codes carry structured `error.data` documenting the failure (exit codes, conflicting fields, reference counts, etc.).

Every write tool accepts `dry_run: boolean` (default `false`). When `true`, the response carries a uniform plan shape and no state is mutated. See the server [README](diviops-server/README.md#dry_run-plan-shape) for the plan envelope and per-tool `_meta.idempotent` markers.

## Free vs Pro

The Free distribution (`oaris-dev/diviops`) and the Pro distribution share the same plugins, the same MCP server, and the bulk of the skill. The only difference is the depth of per-module attribute reference in the skill.

| | Free | Pro |
|---|:---:|:---:|
| `diviops-agent` WordPress plugin | ✓ | ✓ (same binary) |
| `diviops-design-library` plugin | ✓ | ✓ (same binary) |
| `@diviops/mcp-server` on npm — all 66 tools | ✓ | ✓ (same package) |
| Skill: SKILL.md, design patterns, tools reference, preset system, design-effects, mega-menu, minimal snippets, SaaS landing | ✓ | ✓ |
| Skill: **Tier 1** attribute reference — universal decoration, innerContent variants, attribute tree layout, design tokens, exceptions quick reference | ✓ | ✓ |
| Skill: **Tier 2** — shared pattern families (font, icon, container cascade, module link) | — | ✓ |
| Skill: **Tier 3** — per-module element maps for 20+ verified modules | — | ✓ |
| Skill: Advanced attributes (boxShadow, filters, transform, sticky, transition, scroll, animation) | — | ✓ |
| Skill: `$variable()$` per-module binding examples and Interactions reference | — | ✓ |

**Practical difference.** The Free skill is enough to generate pages using universal decoration patterns plus runtime lookups via `diviops_schema_get_module`. Pro adds verified per-module maps, which cuts schema-lookup round-trips and reduces silent-fail risk on quirks only documented in the full maps — e.g., Toggle's `closedTitle.decoration.font.*` (closed-state title styling; without it you'd target the open state only) or Video's `overlay.decoration.background` (the correct background target — not `module.decoration.background`).

No feature gating in the MCP server or the WordPress plugin — all 66 tools are available in both distributions.

Pro upgrade: <https://diviops.com>

## Requirements

- Node.js 18+
- PHP 7.4+
- WordPress 6.5+
- Divi 5.1.0+ theme active
- DiviOps Agent WordPress plugin installed and active

## Troubleshooting

Common quick fixes:

- **401 Unauthorized** — strip spaces from the Application Password; verify `WP_USER` and `WP_APP_PASSWORD`.
- **503 `divi_unavailable`** — Divi 5 theme is not active.
- **MCP not appearing** — `claude mcp list`; if absent, `claude mcp remove` and re-add. Fully restart Claude Code (not just the window).
- **Preset edits not visible on the frontend** — Divi serves frontend CSS from `wp-content/et-cache/{post_id}/`, which `wp cache flush` doesn't touch. Use `diviops_meta_flush_cache` after preset writes.
- **VB shows raw `$variable()$`** — dynamic content binding rendered as text; click the chip to edit it inline.

Full troubleshooting matrix and environment-specific setup (DDEV, wp-env, WordPress Studio, DevKinsta) is in [SETUP.md](SETUP.md).

## Documentation

- **[SETUP.md](SETUP.md)** — full onboarding walkthrough (containerized envs, HTTPS, environment variables, WP-CLI security, design-system bootstrap)
- **[diviops-server/README.md](diviops-server/README.md)** — MCP server reference (response contract, error codes, `dry_run` plan shape, per-tool registration)
- **[skills/divi-5-builder/SKILL.md](skills/divi-5-builder/SKILL.md)** — block format rules, design patterns, workflow guidance
- **[CHANGELOG.md](CHANGELOG.md)** — release history

## License

MIT — see [LICENSE](LICENSE).
