# DiviOps

**An AI harness for WordPress site authoring — Divi-native today, WordPress-wide by design.**

[![npm](https://img.shields.io/npm/v/@diviops/mcp-server.svg?label=%40diviops%2Fmcp-server)](https://www.npmjs.com/package/@diviops/mcp-server)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Divi 5](https://img.shields.io/badge/Divi-5.1.0%2B-7E3DD3.svg)](https://www.elegantthemes.com/gallery/divi/)

DiviOps gives Claude Code, Codex, Claude Desktop, and other MCP clients a typed control layer over WordPress site state. It pairs an MCP server, a WordPress agent plugin, and skill knowledge so AI agents can author Divi pages, inspect schemas, manage design tokens, work with SCF/CPT data models, run safe WP-CLI operations, and extend into target plugin coverage slices.

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
| **`@diviops/mcp-server`** | Node.js MCP server that bridges MCP clients to WordPress. Distributed via npm — no clone, no build. | `npx -y --package @diviops/mcp-server diviops-mcp` |
| **`divi-5-builder`** skill | Block format rules, verified attribute paths, design patterns. Without it, agents guess attr formats and produce broken pages. | `skills/divi-5-builder/` (Claude: `claude plugin install oaris-dev/diviops`; Codex: copy `skills/*` into `~/.codex/skills`) |
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

### 3. Register the MCP server

Claude Code:

```bash
claude mcp add diviops-mysite \
  --env WP_URL=http://your-site.local \
  --env WP_USER=your-wp-username \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  -- npx -y --package @diviops/mcp-server diviops-mcp
```

For Local by Flywheel (enables the `diviops_meta_wp_cli` tool), add `--env "WP_PATH=/Users/you/Local Sites/your-site/app/public"`.

For Claude Desktop, use `"command": "npx"` with args `["-y", "--package", "@diviops/mcp-server", "diviops-mcp"]`. If Claude cannot find `npx`, run `npm install -g @diviops/mcp-server@latest` and use `diviops-mcp`, or use `node "$(npm root -g)/@diviops/mcp-server/dist/index.js"`.

Codex `~/.codex/config.toml`:

```toml
[mcp_servers.diviops-mysite]
command = "npx"
args = ["-y", "--package", "@diviops/mcp-server", "diviops-mcp"]

[mcp_servers.diviops-mysite.env]
WP_URL = "http://your-site.local"
WP_USER = "your-wp-username"
WP_APP_PASSWORD = "xxxxXXXXxxxxXXXXxxxxXXXX"
```

Restart your client, then ask: **"List the pages on my site."** The assistant calls `diviops_page_list` and renders the result. You're authoring with the suite.

### 4. Load the `divi-5-builder` skill

The skill teaches the assistant the correct Divi 5 block format. Without it, the agent guesses attr formats and produces broken pages.

```bash
claude plugin install oaris-dev/diviops
```

Verify with `What skills do you have?` — you should see `divi-5-builder` listed.

This distribution includes a [`.claude-plugin/marketplace.json`](.claude-plugin/marketplace.json) manifest, so the same install command also works from a local clone of this repo (`claude plugin install <path-to-clone>`). The repository is published as a Claude Code plugin marketplace entry.

For alternative skill installation paths (cloned repo, project-local copy), see [SETUP.md](SETUP.md#step-7-load-the-divi-5-builder-skill).

For Codex, run this from the extracted DiviOps distribution or a local repo clone, then restart Codex:

```bash
mkdir -p "$HOME/.codex/skills"
cp -R skills/* "$HOME/.codex/skills/"
```

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

The suite exposes **74 tools** across the categories below. Per-tool descriptions, request shapes, and response payloads live in the server [README](diviops-server/README.md).

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

DiviOps is a harness; the Free and Pro distributions split along skill knowledge depth today, with execution coverage slices (target plugin handlers + skill knowledge bundled per target) layering on through Phase B and Phase C+.

### What ships in Free (v1.x today)

The Free distribution (`oaris-dev/diviops`) carries the full execution surface:

- `diviops-agent` WordPress plugin (REST bridge, Divi 5 + SCF + CPT + WP-CLI handlers)
- `diviops-design-library` plugin (CSS effects, gradients, glass, Three.js shaders)
- `@diviops/mcp-server` on npm — all 74 tools
- `divi-5-builder` skill, free slice: `SKILL.md`, design patterns, tools reference, preset system, design-effects, mega-menu, minimal snippets, SaaS landing, and the **Tier 1** attribute reference (universal decoration, `innerContent[]` variants, attribute tree layout, design tokens, exceptions quick reference)

### What ships in Pro (v1.x today)

The Pro distribution adds the deeper skill knowledge layer for the same Free execution surface — `divi-5-builder` **Tier 2** + **Tier 3**:

| | Free | Pro |
|---|:---:|:---:|
| `diviops-agent` WordPress plugin | ✓ | ✓ (same binary) |
| `diviops-design-library` plugin | ✓ | ✓ (same binary) |
| `@diviops/mcp-server` on npm — all 74 tools | ✓ | ✓ (same package) |
| Skill: SKILL.md, design patterns, tools reference, preset system, design-effects, mega-menu, minimal snippets, SaaS landing | ✓ | ✓ |
| Skill: **Tier 1** attribute reference — universal decoration, innerContent variants, attribute tree layout, design tokens, exceptions quick reference | ✓ | ✓ |
| Skill: **Tier 2** — shared pattern families (font, icon, container cascade, module link) | — | ✓ |
| Skill: **Tier 3** — per-module element maps for 20+ verified modules | — | ✓ |
| Skill: Advanced attributes (boxShadow, filters, transform, sticky, transition, scroll, animation) | — | ✓ |
| Skill: `$variable()$` per-module binding examples and Interactions reference | — | ✓ |

**Practical difference today.** The Free skill is enough to generate pages using universal decoration patterns plus runtime lookups via `diviops_schema_get_module`. Pro adds verified per-module maps, which cuts schema-lookup round-trips and reduces silent-fail risk on quirks only documented in the full maps — e.g., Toggle's `closedTitle.decoration.font.*` (closed-state title styling; without it you'd target the open state only) or Video's `overlay.decoration.background` (the correct background target — not `module.decoration.background`).

No feature gating in the MCP server or the WordPress plugin in v1.x — all 74 tools are available in both distributions.

### What's coming in Pro (Phase B onwards)

The harness is designed to grow through **per-target execution coverage slices** — skill knowledge + MCP tools + plugin handlers bundled per target plugin. A per-tool capability handshake at MCP server startup queries the WP plugin for installed capabilities and applies two distinct gating modes: tools whose backing Pro plugin is **not installed** on the site are omitted from the MCP server's exposed tool list entirely (Claude never sees them); tools whose backing Pro plugin is installed but at an **older version** than the tool requires fail with a clear `capability_missing` error rather than silent breakage. The next slices land in this order:

- **Phase B — FluentCart Pro pilot.** 7 product + variation authoring tools (`diviops_fc_product_*`, `diviops_fc_variation_*`) backed by a `diviops-fluentcart/` skill slice and Pro-plugin handlers in `diviops-agent-pro`. Sequencing reflects the project's own commerce dogfooding on `diviops.com`.
- **Phase C+ — additional target plugin slices.** SCF deeper knowledge slice (curated patterns + recipes; the existing 6 free SCF MCP tools stay free), Bit Forms Pro, Bit Flows Pro, Gutenberg interop. Each slice ships as its own `diviops-<target>/` skill plus dedicated handlers, following the same per-target-slice packaging shape.

**MCP tools always ship in the free MCP package.** What separates Free from Pro on a coverage slice is the *curated skill knowledge* and the *Pro-plugin handlers that back the tools*; the dispatch surface itself is universal. A Free-tier user on a site without the Pro plugin installed simply doesn't see Pro-only tools — they're gated by the per-tool capability handshake, not feature-flagged in the MCP server.

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
- **[Releases](https://github.com/oaris-dev/diviops/releases)** — release history

## License

MIT — see [LICENSE](LICENSE).
