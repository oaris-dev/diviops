# DiviOps

**An AI harness and MCP server for WordPress. Divi-native today, WordPress-wide by design.**

[![npm](https://img.shields.io/npm/v/@diviops/mcp-server.svg?label=%40diviops%2Fmcp-server)](https://www.npmjs.com/package/@diviops/mcp-server)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Divi 5](https://img.shields.io/badge/Divi-5.1.0%2B-7E3DD3.svg)](https://www.elegantthemes.com/gallery/divi/)

Plan, build and improve your WordPress site with **OpenAI Codex or Claude Code**. DiviOps connects your AI client to supported WordPress and Divi operations through an MCP server, WordPress plugins and practical authoring skills. Claude Desktop and other MCP clients can also connect; skill loading depends on the client.

Bring your audience, page goals and design references into your AI client's project context. Use DiviOps to inspect the site, build a focused draft and validate the result. Native Divi pages remain editable in the Visual Builder.

**[Get started](#quick-start)** · [Explore features](https://diviops.com/features/) · [See real use cases](https://diviops.com/use-cases/)

> **Public beta.** DiviOps is under active development. Start on a development or staging site, keep backups and review changes before publishing.

```text
Project context + authoring skills
                |
                v
   Codex / Claude Code / MCP client
                ↕
   DiviOps MCP server (stdio)
                ↕
       WordPress REST API
                ↕
   DiviOps Agent WordPress plugin
```

Tool requests and results travel between the client and WordPress. Project files and skills belong to the client workflow; updating the WordPress plugin does not install them.

Divi is a registered trademark of Elegant Themes, Inc. DiviOps Agent is not affiliated with or endorsed by Elegant Themes.

## What you can build

| Workflow | See it in practice |
|---|---|
| Build and refine editable Divi pages from a brief or design reference | [Figma design to native Divi](https://diviops.com/figma-design-to-editable-divi-page/) |
| Reuse presets and design context when improving an existing page | [Focused page refinement](https://diviops.com/improve-existing-divi-page-with-ai/) |
| Create navigation with native Divi modules and review its responsive behavior | [Our Divi 5 mega menu](https://diviops.com/divi-5-mega-menu-ai/) |
| Connect structured content to layouts with supported SCF tools, loops and dynamic bindings | [Staff-directory workflow](https://diviops.com/build-a-wordpress-staff-directory-with-ai-and-divi/) |
| Bring external research into the same AI workflow as WordPress content updates | [SEO insights to page improvements](https://diviops.com/seo-insights-wordpress-ai/) |

Each example explains the implementation and review steps. External research services and target plugins are separate requirements where used.

[![Published DiviOps desktop navigation with the Use Cases mega menu open](https://diviops.com/wp-content/uploads/2026/09/megamenu-desktop.jpg)](https://diviops.com/divi-5-mega-menu-ai/)

*Real result: the DiviOps navigation, built with native Divi modules plus scoped CSS and a small behavior helper. [Read the build and review process](https://diviops.com/divi-5-mega-menu-ai/).*

## What's in this Free distribution

| Component | Role | Where to find it |
|---|---|---|
| **DiviOps Agent** WordPress plugin | WordPress-side tools for supported Divi page operations, validation, presets and other site operations | `diviops-agent.zip` at the repo root |
| **`@diviops/mcp-server`** | Connects MCP clients to the WordPress plugin | `npx -y --package @diviops/mcp-server diviops-mcp` |
| **`divi-5-builder`** Free skill | Native block formats, Tier 1 attribute guidance, design patterns and tool references | `skills/divi-5-builder/`; see [skill installation](#4-load-the-divi-5-builder-skill) |
| **`diviops`** harness primer | Shared conventions for the connected agent workflow | `skills/diviops/` |
| **`diviops-design-library`** plugin | Optional visual effects, including CSS animations, gradients, glass effects and Three.js shaders | `diviops-design-library.zip` at the repo root |

Core SCF MCP tools are Free. Pro adds extended Divi guidance, the deeper SCF skill guide and supported paid workflows through the separate Pro plugin. **Pro packages are not included in this public Free repository.** See [Free vs Pro](#free-vs-pro) or [compare plans](https://diviops.com/pricing/).

The WordPress plugin, npm MCP server, and client-side skill are three independent
components. WordPress and npm updates do not install or refresh a manually copied
skill. A working MCP tool call proves connectivity only; native Divi authoring also
requires a current `divi-5-builder` skill in the active client session.

## Quick start

The first three steps prove connectivity; steps 4 and 5 establish native Divi
authoring readiness. For containerized environments, HTTPS configuration, and
troubleshooting, see [SETUP.md](SETUP.md).

### 1. Install the WordPress plugin

Upload **`diviops-agent.zip`** (at the root of this repo) via **WP Admin → Plugins → Add New → Upload Plugin**, then activate it. Requires Divi 5.1+ on WordPress 6.5+.

Verify: visit `http://your-site.local/wp-json/diviops/v1/schema/settings` — you should get a 401 (auth required).

**Free plugin updates:** the npm MCP server updates through npm. [DiviOps Agent is available on WordPress.org](https://wordpress.org/plugins/diviops-agent/), with plugin updates delivered through the normal **Dashboard → Updates** and **Plugins** screens. For a manual fallback install, replace `diviops-agent.zip` through **Plugins → Add New → Upload Plugin** and choose **Replace current with uploaded**. Your Application Password and MCP config stay unchanged.

**Purchased Pro:** upload and activate **`diviops-agent-pro.zip`** after the Free plugin, then open **DiviOps → Pro License** and activate your license key. Pro runtime coverage requires the Pro plugin; license activation gates updates and support.

**WordPress.org distribution:** `diviops-agent.zip` includes the plugin-local `readme.txt` and `changelog.txt`. WordPress.org installs use the standard plugin update flow; upload-based replacement remains a fallback for environments that intentionally install from the public distribution repository. Directory banners, icons and screenshots are maintained separately from installed runtime assets.

### 2. Create an Application Password

In **WP Admin → Users → Your Profile → Application Passwords**:

- Enter a name (e.g. "Claude MCP")
- Click "Add New Application Password"
- **Strip the spaces** from the generated password — WordPress shows `758r WQ1X URcg ...` for readability but accepts the spaceless form, which avoids argument-parsing surprises in `claude mcp add`.

### 3. Register the MCP server

The current successor server requires Node.js 22 or newer. Upgrade Node before
using `@diviops/mcp-server@1.5.40` or later. If an environment must remain on
Node 18, pin the prior compatible server with
`npx -y --package=@diviops/mcp-server@1.5.39 diviops-mcp`. This server pin does
not select a WordPress plugin version: Free and Pro use independent version
series and compatibility is determined by the capability handshake. Direct
npm/stdio and MCP v1 remain supported; the launcher and MCP v2 fixtures are not
part of the public package.

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

Restart your client, then ask: **"List the pages on my site."** The assistant calls
`diviops_page_list` and renders the result. This proves connectivity; complete the
skill install and native-module smoke below before authoring content.

### 4. Load the `divi-5-builder` skill

The skill teaches the assistant the correct Divi 5 block format. Without it, the agent guesses attr formats and produces broken pages.

```bash
claude plugin marketplace add oaris-dev/diviops
claude plugin install divi-5-builder@diviops
```

Verify with `What skills do you have?` — you should see `divi-5-builder` listed.

This distribution includes a [`.claude-plugin/marketplace.json`](.claude-plugin/marketplace.json) manifest. For a local clone, add its absolute path as the marketplace source, then install the same qualified plugin ID:

```bash
claude plugin marketplace add /absolute/path/to/diviops
claude plugin install divi-5-builder@diviops
```

For alternative skill installation paths (cloned repo, project-local copy), see [SETUP.md](SETUP.md#step-7-load-the-divi-5-builder-skill).

For Codex, run this from the extracted DiviOps distribution or a local repo clone, then restart Codex:

```bash
mkdir -p "$HOME/.codex/skills"
cp -R skills/* "$HOME/.codex/skills/"
```

Manual Claude or Codex copies do not update with WordPress or npm. Replace them
from each newer distribution and restart the client. Do not leave a stale manual
Claude copy active beside the plugin-managed copy. Claude Desktop and other MCP
clients do not necessarily load Claude Code's `.claude/skills` paths.

### 5. Verify native Divi authoring

Use the fail-closed disposable draft prompt in
[SETUP.md](SETUP.md#first-run-native-divi-verification). It must produce and
report native section, row, column, heading, text, and button modules. Code modules,
page-sized HTML, iframe layouts, and structural HTML stuffed into text/container
fields are not acceptable fallbacks.

## Example workflow

> **You:** Create a hero section on a new page called "Spring Launch" with a heading, subheading, and a CTA button. Use my brand colors.

Your AI client can orchestrate a sequence such as:

1. `diviops_global_color_list` — discovers your brand palette.
2. `diviops_template_list` / `diviops_template_get` — pulls a verified hero template that matches the request.
3. `diviops_validate_blocks` with inline `content` — confirms the constructed hero markup is well-formed before any write.
4. `diviops_page_create` — creates `Spring Launch` as a draft using those exact validated bytes.
5. `diviops_validate_blocks` with the saved `page_id` — verifies persisted readback.
6. `diviops_render_preview` — returns the rendered HTML so you can verify before publishing.

The skill guides native Divi authoring. Validate the saved content and review the rendered result before publication.

## Tools at a glance

The suite exposes tools across the categories below. Available tools depend on installed components and the capability handshake. Per-tool descriptions, request shapes, and response payloads live in the server [README](diviops-server/README.md).

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

DiviOps is a harness. The Free distribution carries the core Divi authoring surface; the Pro distribution adds deeper skill knowledge, the Pro plugin, license/update gating, and paid coverage slices for target plugins.

### What ships in Free (v1.x today)

The Free distribution (`oaris-dev/diviops`) carries the core DiviOps execution surface:

- `diviops-agent` WordPress plugin (REST bridge, Divi 5 + SCF + CPT + WP-CLI handlers)
- `diviops-design-library` plugin (CSS effects, gradients, glass, Three.js shaders)
- `@diviops/mcp-server` on npm — the shared MCP server package
- `divi-5-builder` skill, free slice: `SKILL.md`, design patterns, tools reference, preset system, design-effects, mega-menu, minimal snippets, SaaS landing, and the **Tier 1** attribute reference (universal decoration, `innerContent[]` variants, attribute tree layout, design tokens, exceptions quick reference)

### What ships in Pro (v1.x today)

The Pro distribution adds the Pro plugin, license/update gating, target coverage slices, and the deeper skill knowledge layer — `divi-5-builder` **Tier 2** + **Tier 3**:

| | Free | Pro |
|---|:---:|:---:|
| `diviops-agent` WordPress plugin | ✓ | ✓ (same binary) |
| `diviops-agent-pro` WordPress plugin | — | ✓ |
| `diviops-design-library` plugin | ✓ | ✓ (same binary) |
| `@diviops/mcp-server` on npm | ✓ | ✓ (same package) |
| Skill: SKILL.md, design patterns, tools reference, preset system, design-effects, mega-menu, minimal snippets, SaaS landing | ✓ | ✓ |
| Skill: **Tier 1** attribute reference — universal decoration, innerContent variants, attribute tree layout, design tokens, exceptions quick reference | ✓ | ✓ |
| Skill: **Tier 2** — shared pattern families (font, icon, container cascade, module link) | — | ✓ |
| Skill: **Tier 3** — per-module element maps for 20+ verified modules | — | ✓ |
| Skill: Advanced attributes (boxShadow, filters, transform, sticky, transition, scroll, animation) | — | ✓ |
| Skill: `$variable()$` per-module binding examples and Interactions reference | — | ✓ |
| Skill: `diviops-fluentcart` coverage guide | — | ✓ |
| Skill: `diviops-scf` deeper SCF guide | — | ✓ |
| Pro license activation + update gating | — | ✓ |
| FluentCart Pro coverage handlers | — | ✓ |

**Practical difference today.** The Free skill is enough to generate pages using universal decoration patterns plus runtime lookups via `diviops_schema_get_module`. Pro adds verified per-module maps, which cuts schema-lookup round-trips and reduces silent-fail risk on quirks only documented in the full maps — e.g., Toggle's `closedTitle.decoration.font.*` (closed-state title styling; without it you'd target the open state only) or Video's `overlay.decoration.background` (the correct background target — not `module.decoration.background`).

Pro also includes `diviops-agent-pro`. When the Pro plugin and a supported target plugin are active, the MCP handshake exposes conditional Pro tools. For example, a site with FluentCart + FluentCart Pro + DiviOps Agent Pro can expose `diviops_fc_*` product, gateway, order, license, and activation tools. If those gates are not satisfied, those tools are intentionally omitted from the MCP tool list.

### Purchased Pro install path

1. Download the Pro package from your customer account
2. Install and activate `diviops-agent.zip`
3. Install and activate `diviops-agent-pro.zip`
4. Open **DiviOps → Pro License**
5. Paste your license key and confirm the license is active
6. Register or restart the MCP server
7. Verify Pro capabilities with `diviops_meta_info`; with FluentCart + FluentCart Pro active, confirm `diviops_fc_*` tools appear

A license activation represents one active WordPress environment where DiviOps Pro is installed and used, including local development sites such as `localhost`, `.local`, `.test`, and `.lab`. Deactivate old environments from your customer account when they are no longer in active use.

### Current and future Pro coverage

The harness is designed to grow through **per-target execution coverage slices** — skill knowledge + MCP tools + plugin handlers bundled per target plugin. A per-tool capability handshake at MCP server startup queries the WP plugin for installed capabilities and applies two distinct gating modes: tools whose backing Pro plugin is **not installed** on the site are omitted from the MCP server's exposed tool list entirely (the AI client never sees them); tools whose backing Pro plugin is installed but does **not advertise the required capability** fail with a clear `capability_missing` error rather than silent breakage. Server and plugin component versions remain independent. Current and planned slices:

- **Current — FluentCart Pro pilot.** Product, variation, license-settings, gateway readiness, order, transaction, license, and activation readback tools (`diviops_fc_*`) backed by the `diviops-fluentcart/` skill slice and Pro-plugin handlers in `diviops-agent-pro`. Sequencing reflects the project's own commerce dogfooding on `diviops.com`.
- **Future coverage.** See the [public roadmap](https://diviops.com/roadmap/) for areas being explored. Planned integrations are not included in the current package, and their scope and order may change.

**MCP tools always ship in the free MCP package.** What separates Free from Pro on a coverage slice is the *curated skill knowledge* and the *Pro-plugin handlers that back the tools*; the dispatch surface itself is universal. A Free-tier user on a site without the Pro plugin installed simply doesn't see Pro-only tools — they're gated by the per-tool capability handshake, not feature-flagged in the MCP server.

Explore [DiviOps Pro](https://diviops.com/diviops-pro/) and [compare plans](https://diviops.com/pricing/). For a concrete Pro example, see the [toy-shop product campaign](https://diviops.com/ai-product-campaign-divi-fluentcart/), which requires the relevant commerce plugins.

## Requirements

- Node.js 22+
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
