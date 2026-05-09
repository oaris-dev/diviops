# DiviOps — AI-powered Divi 5 page builder

[![npm](https://img.shields.io/npm/v/@diviops/mcp-server.svg?label=%40diviops%2Fmcp-server)](https://www.npmjs.com/package/@diviops/mcp-server)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Divi 5](https://img.shields.io/badge/Divi-5.1.0%2B-7E3DD3.svg)](https://www.elegantthemes.com/gallery/divi/)

WordPress plugin + MCP server + Claude skill, working in concert. Build Divi 5 pages from natural language with Claude Code — typed module APIs, preset-driven design, and a uniform error envelope across the whole tool surface.

## Setup Guide

Get from zero to generating Divi 5 pages with Claude Code in ~15 minutes.

> **Beta software.** DiviOps is under active development. Use on production sites at your own discretion. Always back up your WordPress site before running write operations.

## Prerequisites

- **WordPress** 6.5+ with **Divi 5** theme (5.1.0+)
- **PHP** 7.4+
- **Node.js** 18+ (for the MCP server)
- **Claude Code** CLI installed
- A local or remote WordPress site (Local by Flywheel recommended for local dev)

## Step 1: Install the WordPress Plugin

1. Download `diviops-agent.zip` from the dist repo root (`oaris-dev/diviops` or `oaris-dev/diviops-internal`) — it ships at the top level of each dist repo
2. Go to **WP Admin → Plugins → Add New → Upload Plugin**
3. Upload `diviops-agent.zip` and activate it
4. Verify: visit `http://your-site.local/wp-json/diviops/v1/schema/settings` — you should get a 401 (auth required)

> **If Divi is not active**, authenticated requests return `503 divi_unavailable`. Unauthenticated requests return 401 first.

## Step 2: Create an Application Password

1. Go to **WP Admin → Users → Your Profile**
2. Scroll to **Application Passwords**
3. Enter a name (e.g., "Claude MCP") and click **Add New Application Password**
4. Copy the generated password

> **Strip the spaces.** WordPress generates passwords like `758r WQ1X URcg GW3s wCwQ QI0V` for readability but accepts them without spaces. Use `758rWQ1XURcgGW3swCwQQI0V` in `claude mcp add` — spaces can be misparsed as separate arguments.

> Save this — you won't see it again.

## Step 3: Register with Claude Code

The MCP server runs via `npx @diviops/mcp-server` — no clone, no build step.

> **Important**: Choose a unique MCP name that won't conflict with other MCP servers you have registered. Use your site name (e.g., `diviops-mysite`).

### Minimal (REST API only — works with any WordPress host)

```bash
claude mcp add diviops-mysite \
  --env WP_URL=http://your-site.local \
  --env WP_USER=your-username \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  -- npx @diviops/mcp-server
```

### With WP-CLI (Local by Flywheel — enables the `diviops_meta_wp_cli` tool)

```bash
claude mcp add diviops-mysite \
  --env WP_URL=http://your-site.local \
  --env WP_USER=your-username \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  --env "WP_PATH=/Users/you/Local Sites/your-site/app/public" \
  -- npx @diviops/mcp-server
```

> **Use `--env` flags, not the `env` command.** Claude Code's native `--env KEY=VALUE` flags survive copy-paste; the older `-- env KEY=VALUE` form (piping through unix `env`) breaks silently when any value contains a space. Quote any value with spaces using regular double quotes — no backslash escaping needed inside quotes.

> `LOCAL_SITE_ID` is auto-detected from `WP_PATH` — no need to find it manually.

### Local Development Environments

DiviOps connects via standard WordPress REST API and works with any host that exposes WordPress over HTTP with Application Password support.

| Environment | `WP_URL` | WP-CLI setup | Notes |
|-------------|----------|--------------|-------|
| **Local by Flywheel** | `http://site-name.local` | `WP_PATH=/path/to/site/app/public` | Site ID auto-detected |
| **WordPress Studio** | `http://localhost:{port}` | `WP_CLI_CMD="studio wp --path=/path/to/site"` | Port auto-assigned (8881, 8882, …); SQLite |
| **DDEV** | `https://site-name.ddev.site` | `WP_CLI_CMD="ddev wp"` plus `WP_PATH=/path/to/project` | Wrapper runs from `WP_PATH` |
| **wp-env** | `http://localhost:8888` | `WP_CLI_CMD="npx wp-env run cli wp"` plus `WP_PATH=/path/to/project` | Requires `WP_ENVIRONMENT_TYPE=local` (see below) |
| **DevKinsta** | `https://site-name.local` | `WP_CLI_CMD="docker exec -u www-data devkinsta_fpm wp --path=/www/kinsta/public/sitename"` | HTTPS with self-signed certs |
| **Custom / Remote** | Your site URL | `WP_PATH=/path/to/site` or `WP_CLI_CMD="..."` | Works with any WP host |

> **Application Passwords on HTTP:** WordPress requires HTTPS for Application Passwords unless `WP_ENVIRONMENT_TYPE` is set to `'local'`. HTTPS environments (DDEV, DevKinsta) work out of the box. HTTP environments (wp-env, WordPress Studio) need this in `wp-config.php`:
> ```php
> define('WP_ENVIRONMENT_TYPE', 'local');
> ```
> Local by Flywheel sets this automatically.

### Environment Variables

| Variable | Required | Description |
|----------|----------|-------------|
| `WP_URL` | Yes | WordPress site URL (e.g. `http://mysite.local`) |
| `WP_USER` | Yes | WordPress username with Editor or Admin role |
| `WP_APP_PASSWORD` | Yes | Application Password (spaces stripped) |
| `WP_PATH` | No | WordPress filesystem path for Local by Flywheel, or wrapper working directory when `WP_CLI_CMD` needs project context |
| `WP_CLI_CMD` | No | Custom WP-CLI command prefix for containerized environments |
| `LOCAL_SITE_ID` | No | Override auto-detection of Local by Flywheel site ID |
| `DIVIOPS_WP_CLI_ALLOW` | No | Comma-separated list of extended WP-CLI commands to enable ([see below](#wp-cli-security)) |

### Common Pitfalls

- **Strip spaces from the app password** — covered above; this is the #1 setup snag
- **Use absolute paths** for `WP_PATH` — relative paths break when Claude Code runs from a different directory
- **Unique MCP name** — don't reuse a name from another project
- **Paths with spaces** — wrap the entire `KEY=VALUE` argument in double quotes (e.g. `--env "WP_PATH=/path with spaces/"`). Same goes for any custom server script path passed after `--`
- **MCP not appearing after registration** — run `claude mcp list` to verify. If it's not there, `claude mcp remove` and re-add. Fully restart Claude Code (not just the window) after adding.

## Step 4: Verify Registration

```bash
claude mcp list
```

You should see your MCP server listed with the correct env vars. If anything looks wrong, remove and re-add:

```bash
claude mcp remove diviops-mysite
claude mcp add diviops-mysite --env KEY=VALUE ... -- npx @diviops/mcp-server
```

## Step 5: Test Connection

Restart Claude Code (or open a new window), then run:

```
Use diviops_meta_ping to verify the MCP is working.
```

You should see your site URL, WordPress version, and Divi version.

Then try:

```
Use diviops_page_list to show all pages.
```

> **If tools don't appear**: Check `claude mcp list` output. The `npx` command must be reachable on your `PATH` (it ships with Node.js, which provides `npm`/`npx`). `npx` then fetches and runs the `@diviops/mcp-server` package on demand.

## Step 6: Optional — Install the Design Library Plugin

For CSS entrance animations (`ddl-fade-up`, `ddl-scale-in`), gradient text effects, and Three.js WebGL shaders:

1. Download `diviops-design-library.zip` from the dist repo root (also at the top level of `oaris-dev/diviops` and `oaris-dev/diviops-internal`)
2. Upload and activate in **WP Admin → Plugins → Add New → Upload Plugin**

This is optional — the MCP agent works without it.

## Step 7: Load the Divi 5 Builder Skill

The skill teaches Claude the correct Divi 5 block format — module attribute paths, design patterns, and format rules. **Without it, Claude will guess attribute formats and produce broken pages** (e.g., empty buttons, wrong innerContent format).

**Option A — Install as a Claude Code plugin** (recommended):
```bash
claude plugin install oaris-dev/diviops
```

This installs the `divi-5-builder` skill from this repo. Works from any directory — no need to clone or copy files. To update later:
```bash
claude plugin update divi-5-builder
```

**Option B — Load from cloned repo**:
```bash
git clone https://github.com/oaris-dev/diviops.git
cd diviops
claude --plugin-dir .
```

**Option C — Copy skill to your project** (auto-loads without flags):
```bash
mkdir -p /path/to/your-project/.claude/skills
cp -r /path/to/diviops/skills/divi-5-builder /path/to/your-project/.claude/skills/
cd /path/to/your-project
claude
```

Verify the skill loaded:
```
What skills do you have?
```
You should see `divi-5-builder` in the list.

## Step 8: Optional — Bootstrap the Design System

The skill uses a per-project design system manifest (`.claude/design-system.json`) to resolve preset role keys to site-specific UUIDs. Without it, the agent falls back to inline styling or runtime discovery via `diviops_preset_audit`.

> **This is optional.** Pages can be generated without a design system — the agent uses inline values. The design system adds consistency and reduces token count.

Start with the audit prompt — it detects your project's state and tells you which phases to run.

### Start Here: Audit Your Site

Always start here regardless of project state:

```
Audit my site's design system state. Check for existing oa-* tokens by
running diviops_variable_list twice: once with prefix gcid-oa- (type: colors)
and once with prefix gvid-oa- (type: numbers), and check oa presets with
diviops_preset_audit. Also check diviops_global_color_list for any existing brand
colors. Tell me what exists, what's missing, and which bootstrap phase I
should start from.
```

Then follow the path that matches your result:

---

### Path A: Fresh Site (no tokens, no presets)

Full bootstrap — provide your brand colors:

```
Bootstrap the oa design system tokens for my project.
My brand colors are:
- Primary: Navy #1a2744
- Secondary: Orange #f97316
- Neutral: Slate #64748b
Create the full gcid-oa-* color palette (3 families x 11 shades + white/black)
and all gvid-oa-* number tokens (font sizes, spacings, radii, line heights).
```

Then continue to **Create Presets** below.

### Path B: Branded Site (has global colors, no oa-* tokens)

Your site already has brand colors but they're not in the oa namespace. Adopt them:

```
My site already has brand colors set up (check diviops_global_color_list).
Adopt these into the oa design system:
- Map the primary brand color → gcid-oa-primary family (generate 50-950 shades)
- Map the secondary brand color → gcid-oa-secondary family
- Map the neutral/gray → gcid-oa-neutral family
- Create gcid-oa-white and gcid-oa-black
- Create all gvid-oa-* number tokens (font sizes, spacings, radii, line heights)
Keep the original global colors — the oa tokens are additions, not replacements.
```

Then continue to **Create Presets** below.

### Path C: Partially Bootstrapped (has oa-* tokens, no presets)

Tokens exist but presets are missing. Skip to **Create Presets** below.

### Path D: Existing Project with Non-oa Presets

Your site has presets with project-local names (not `oa *`). You can either:

1. **Keep existing presets** and just generate a manifest mapping them:
```
My site has existing presets that are not oa-named. Run diviops_preset_audit
and list all presets with their names, IDs, and groupNames. Help me map them
to the standard role keys (heading-h1, text-standard, button-primary, etc.)
and generate .claude/design-system.json using my existing preset UUIDs.
```

2. **Or create oa presets alongside** existing ones for consistency with the canonical system. Use the **Create Presets** checklist below.

---

### Create Presets (all paths)

Presets must be created manually in the Visual Builder. Use the following prompt to get a checklist from Claude Code to guide your manual creation:

```
Give me the preset creation checklist. I need to create oa attribute-level
presets in the Visual Builder. List each preset name, which module to create
it on, the groupId, groupName, and which tokens to reference.
```

After creating each batch in the VB, have Claude inspect them:

```
Run diviops_preset_audit and verify the presets I just created.
Capture the UUIDs for the manifest.
```

### Generate Manifest (all paths)

Once tokens and presets are in place:

```
Generate .claude/design-system.json for my project.
Map all oa preset names to role keys and capture UUIDs from diviops_preset_audit.
Also create .claude/instructions/design-system.md with my project's brand
personality and design preferences.
```

See [SKILL.md — Design System Lifecycle](https://github.com/oaris-dev/diviops/blob/main/skills/divi-5-builder/SKILL.md#design-system-lifecycle) for the full technical reference.

## Quick Test: Generate Your First Page

Ask Claude Code:

```
Create a landing page called "Test Page" with a hero section (dark background,
white heading "Hello World", subtitle, and a CTA button).
```

Claude will use the `divi-5-builder` skill to generate the page. Check the result at your site URL.

## Architecture

```
┌─────────────┐    stdio     ┌─────────────┐   HTTP/REST   ┌──────────────────┐
│ Claude Code │◄────────────►│ MCP Server  │◄─────────────►│ WordPress + Divi │
│  (your AI)  │              │ (TypeScript)│               │  (PHP plugin)    │
└──────┬──────┘              └─────────────┘               └──────────────────┘
       │
       │ reads automatically
       ▼
┌──────────────┐
│    Skill     │  Block format rules, verified attr paths,
│ (knowledge)  │  design patterns, VB-verified module attr paths
└──────────────┘
```

**How it works:** When you ask Claude to build a Divi page, it uses the **MCP Server** (npm package) to talk to your WordPress site via REST API. The **Skill** teaches Claude the correct Divi 5 block format — without it, Claude would guess attr paths and produce broken content. The **WP Plugin** exposes Divi-specific endpoints that WordPress doesn't have natively.

| Component | Distribution | Purpose |
|-----------|--------------|---------|
| **MCP Server** | npm: `@diviops/mcp-server` | Bridges Claude to WordPress via 48 tools (read pages, edit modules, validate blocks) |
| **WP Plugin** | `diviops-agent.zip` (in this repo) | REST API endpoints for Divi page data, section targeting, block validation |
| **Skill** | `claude plugin install oaris-dev/diviops` | VB-verified attr paths, design patterns, block-format rules |
| **Design Library** | `diviops-design-library.zip` (in this repo) | CSS animations, glass effects, Three.js WebGL (optional) |

## Free vs Pro

The Free distribution (this repo) and the Pro distribution share the same plugins, MCP server, and most of the skill. The only difference is the depth of per-module attribute reference in the skill.

| | Free | Pro |
|---|---|---|
| `diviops-agent` WordPress plugin | ✓ | ✓ (same binary) |
| `diviops-design-library` plugin | ✓ | ✓ (same binary) |
| `@diviops/mcp-server` on npm — all 48 tools | ✓ | ✓ (same package) |
| Skill: SKILL.md, design patterns, tools reference, preset system, design-effects, mega-menu, minimal snippets, SaaS landing | ✓ | ✓ |
| Skill: **Tier 1** attribute reference — universal decoration, innerContent variants, attribute tree layout, design tokens, exceptions quick reference | ✓ | ✓ |
| Skill: **Tier 2** — shared pattern families (font, icon, container cascade, module link) | — | ✓ |
| Skill: **Tier 3** — per-module element maps for 20+ verified modules | — | ✓ |
| Skill: Advanced attributes (boxShadow, filters, transform, sticky, transition, scroll, animation) | — | ✓ |
| Skill: `$variable()$` per-module binding examples (loop content, global color tokens) and Interactions reference | — | ✓ |

**Practical difference.** The Free skill is enough to generate pages using universal decoration patterns plus runtime lookups via `diviops_schema_get_module`. Pro adds verified per-module maps, which cuts schema-lookup round-trips and reduces silent-fail risk on quirks only documented in the full maps — e.g., Toggle's `closedTitle.decoration.font.*` (closed-state title styling; without it you'd target the open state only) or Video's `overlay.decoration.background` (the correct background target — not `module.decoration.background`).

No feature gating in the MCP server or the WordPress plugin — all 48 tools are available in both distributions.

Upgrade: <https://diviops.com>

## Available Tools (48)

### Read (26)
Pages, modules, sections, settings, icons, presets, library, Theme Builder, canvas, variables, templates, block validation, schema optimization

### Write (20)
Create/edit pages, sections, modules, presets (create/update/delete/reassign/cleanup), library items, Theme Builder templates, canvas, variables

### Utility (2)
WP-CLI (allowlisted, requires `WP_PATH` or `WP_CLI_CMD`); flush Divi's per-post static CSS cache (`wp-content/et-cache/{post_id}/`) after mutations — `wp cache flush` doesn't touch these files

See [`skills/divi-5-builder/SKILL.md`](https://github.com/oaris-dev/diviops/blob/main/skills/divi-5-builder/SKILL.md) for the complete tool reference with attribute formats and design patterns.

## Targeting Modules

Four ways to target modules for editing:

| Mode | Example | Use when |
|------|---------|----------|
| **Admin label** | `label: "Hero Heading"` | MCP-generated content |
| **Text match** | `match_text: "Hello"` | Find by visible text |
| **Auto-index** | `auto_index: "text:5"` | Any module (from layout response) |
| **Occurrence** | `occurrence: 2` | Duplicate labels |

## WP-CLI Security

The `diviops_meta_wp_cli` tool validates every command against a safety allowlist. Default allowlist covers read-only commands (options, posts, taxonomies, users, ACF field groups, cron/plugin/theme/menu info) plus non-destructive writes (post/term/post-meta create and update, ACF schema export/import, cache flush, transient delete, rewrite flush, WXR export).

**Extended commands** (opt-in via `DIVIOPS_WP_CLI_ALLOW`):

| Command | Risk |
|---------|------|
| `option update` | High — can change site URL, admin email, security settings |
| `post delete` / `post meta delete` / `term delete` | Medium — permanent removal |
| `search-replace` | High — bulk DB modification |
| `import` | Medium — bulk content ingestion |
| `plugin activate` / `plugin deactivate` | Medium |
| `eval-file` | Critical — executes arbitrary PHP |

To enable extended commands:

```bash
claude mcp add diviops-mysite \
  --env WP_URL=http://your-site.local \
  --env WP_USER=admin \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  --env "WP_PATH=/Users/you/Local Sites/your-site/app/public" \
  --env "DIVIOPS_WP_CLI_ALLOW=option update,post delete,search-replace" \
  -- npx @diviops/mcp-server
```

Only list the specific commands you need. Unknown entries are ignored with a warning.

## Security

Three permission tiers:
- **Read**: `edit_posts` — list/get pages, modules, settings
- **Write**: `edit_pages` — create/modify pages and content
- **Admin**: `manage_options` — presets, library, theme builder, WP-CLI

All endpoints require Application Password authentication.

## Multi-Site / Parallel Testing

The MCP server is a Node.js process that connects to any WordPress site via HTTP. It doesn't need to live inside the WordPress directory — only the `diviops-agent` plugin does.

**Register multiple sites** with different names:

```bash
# Production site
claude mcp add diviops-main \
  --env WP_URL=http://main-site.local \
  --env WP_USER=admin \
  --env WP_APP_PASSWORD=xxxx \
  -- npx @diviops/mcp-server

# Test site (same MCP package, different credentials)
claude mcp add diviops-test \
  --env WP_URL=http://test-site.local \
  --env WP_USER=admin \
  --env WP_APP_PASSWORD=yyyy \
  -- npx @diviops/mcp-server
```

Each registration is independent — different site, different credentials, different MCP name.

**Teammate setup**: They only need:
1. The `diviops-agent.zip` (installed in their WP site — download from this repo)
2. `claude mcp add ... npx @diviops/mcp-server` with their own `WP_URL`, `WP_USER`, `WP_APP_PASSWORD`
3. The skill via `claude plugin install oaris-dev/diviops`

No clone, no build.

## Troubleshooting

| Issue | Solution |
|-------|----------|
| 401 Unauthorized | Check `WP_USER` and `WP_APP_PASSWORD` (strip spaces) |
| 503 Divi unavailable | Activate Divi 5 theme |
| WP-CLI "not configured" | Set `WP_PATH` (Local by Flywheel) or `WP_CLI_CMD` (containerized) |
| Styles not rendering | Hard-refresh browser (Cmd+Shift+R) — CSS cache |
| VB shows raw `$variable()$` | Dynamic content binding — click the chip to edit |
| `npx` can't find package | Update Node.js to 18+; verify `npx --version` works |
