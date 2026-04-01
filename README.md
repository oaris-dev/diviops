# DiviOps — Setup Guide

Get from zero to generating Divi 5 pages with Claude Code in ~15 minutes.

> **Beta software.** DiviOps is under active development. Use on production sites at your own discretion. Always back up your WordPress site before running write operations.

## Prerequisites

- **WordPress** 6.0+ with **Divi 5** theme (5.1.0+)
- **Node.js** 18+ (for the MCP server)
- **Claude Code** CLI installed
- A local or remote WordPress site (Local by Flywheel recommended for local dev)

## Step 1: Install the WordPress Plugin

1. In this repo, zip the plugin directory:
   ```bash
   cd wp-content/plugins
   zip -r diviops-agent.zip diviops-agent/
   ```
2. Go to **WP Admin → Plugins → Add New → Upload Plugin**
3. Upload `diviops-agent.zip` and activate it
4. Verify: visit `http://your-site.local/wp-json/diviops/v1/settings` — you should get a 401 (auth required)

> **If Divi is not active**, authenticated requests return `503 divi_unavailable`. Unauthenticated requests return 401 first.

## Step 2: Create an Application Password

1. Go to **WP Admin → Users → Your Profile**
2. Scroll to **Application Passwords**
3. Enter a name (e.g., "Claude MCP") and click **Add New Application Password**
4. Copy the generated password (spaces are fine)

> Save this — you won't see it again.

## Step 3: Build the MCP Server

```bash
cd diviops-server
npm install
npm run build
```

Verify: `ls dist/index.js` should exist.

## Step 4: Register with Claude Code

First, find the absolute path to the MCP server:

```bash
cd diviops-server && echo "$(pwd)/dist/index.js"
```

Copy that path — you'll need it below.

> **Important**: Choose a unique MCP name that won't conflict with other MCP servers you have registered. Use your site name (e.g., `diviops-mysite`).

### Minimal (REST API only — works with any WordPress host)

```bash
claude mcp add diviops-mysite -- env \
  WP_URL=http://your-site.local \
  WP_USER=your-username \
  WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  node /Users/you/projects/diviops-server/dist/index.js
```

### With WP-CLI (Local by Flywheel — enables the `diviops_wp_cli` tool)

```bash
claude mcp add diviops-mysite -- env \
  WP_URL=http://your-site.local \
  WP_USER=your-username \
  WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  WP_PATH="/Users/you/Local Sites/your-site/app/public" \
  node /Users/you/projects/diviops-server/dist/index.js
```

> `LOCAL_SITE_ID` is auto-detected from `WP_PATH` — no need to find it manually.

### Common Pitfalls

- **Strip spaces from the app password** — WordPress generates passwords like `758r WQ1X URcg GW3s wCwQ QI0V` with spaces. **Remove the spaces** when using `claude mcp add`: `WP_APP_PASSWORD=758rWQ1XURcgGW3swCwQQI0V`. WordPress accepts both formats, but `claude mcp add` can misparse the spaces as separate arguments.
- **Use absolute paths** for both `WP_PATH` and the `node` script — relative paths break when Claude Code runs from a different directory
- **Unique MCP name** — don't reuse a name from another project (e.g., if you have `directus-mcp`, don't name this one the same)
- **Paths with spaces** — directories like `Local Sites/my site` work as long as they're quoted
- **MCP not appearing after registration** — run `claude mcp list` to verify. If it's not there, `claude mcp remove` and re-add. Fully restart Claude Code (not just the window) after adding.

## Step 5: Verify Registration

Before restarting Claude Code, confirm the registration:

```bash
claude mcp list
```

You should see your MCP server listed with the correct env vars. If anything looks wrong, remove and re-add:

```bash
claude mcp remove diviops-mysite
claude mcp add diviops-mysite -- env ...
```

## Step 6: Test Connection

Restart Claude Code (or open a new window), then run:

```
Use diviops_test_connection to verify the MCP is working.
```

You should see your site URL, WordPress version, and Divi version.

Then try:

```
Use diviops_list_pages to show all pages.
```

> **If tools don't appear**: Check `claude mcp list` output. The `node` path must be absolute and the `dist/index.js` file must exist (run `npm run build` first).

## Step 7: Optional — Install the Design Library Plugin

For CSS entrance animations (`ddl-fade-up`, `ddl-scale-in`), gradient text effects, and Three.js WebGL shaders:

```bash
cd wp-content/plugins
zip -r diviops-design-library.zip diviops-design-library/
```

Upload and activate in WP Admin. This is optional — the MCP agent works without it.

## Step 8: Load the Divi 5 Builder Skill

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
cd /path/to/diviops     # the cloned repo
claude --plugin-dir .    # load plugin structure from repo root
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

## Step 9: Optional — Bootstrap the Design System

The skill uses a per-project design system manifest (`.claude/design-system.json`) to resolve preset role keys to site-specific UUIDs. Without it, the agent falls back to inline styling or runtime discovery via `diviops_preset_audit`.

> **This is optional.** Pages can be generated without a design system — the agent uses inline values. The design system adds consistency and reduces token count.

Start with the audit prompt — it detects your project's state and tells you which phases to run.

### Start Here: Audit Your Site

Always start here regardless of project state:

```
Audit my site's design system state. Check for existing oa-* tokens by
running diviops_list_variables twice: once with prefix gcid-oa- (type: colors)
and once with prefix gvid-oa- (type: numbers), and check oa presets with
diviops_preset_audit. Also check diviops_get_global_colors for any existing brand
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
My site already has brand colors set up (check diviops_get_global_colors).
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

See [SKILL.md — Design System Lifecycle](skills/divi-5-builder/SKILL.md#design-system-lifecycle) for the full technical reference.

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
│ (knowledge)  │  design patterns, 16 VB-verified modules
└──────────────┘
```

**How it works:** When you ask Claude to build a Divi page, it uses the **MCP Server** to talk to your WordPress site via REST API. The **Skill** teaches Claude the correct Divi 5 block format — without it, Claude would guess attr paths and produce broken content. The **WP Plugin** exposes Divi-specific endpoints that WordPress doesn't have natively.

| Component | Location | Purpose |
|-----------|----------|---------|
| **MCP Server** | `diviops-server/` | Bridges Claude to WordPress via 43 tools (read pages, edit modules, validate blocks) |
| **WP Plugin** | `plugins/diviops-agent/` | REST API endpoints for Divi page data, section targeting, block validation |
| **Skill** | `skills/divi-5-builder/` | Verified attr paths for 16 modules, design patterns, format rules |
| **Design Library** | `plugins/diviops-design-library/` | CSS animations, glass effects, Three.js WebGL (optional) |

## Available Tools (33)

### Read (20)
Pages, modules, settings, icons, presets, library, Theme Builder, block validation, schema optimization

### Write (13)
Create/edit pages, sections, modules, presets, library items, Theme Builder templates, WP-CLI

See `skills/divi-5-builder/SKILL.md` for the complete tool reference with attribute formats and design patterns.

## Targeting Modules

Four ways to target modules for editing:

| Mode | Example | Use when |
|------|---------|----------|
| **Admin label** | `label: "Hero Heading"` | MCP-generated content |
| **Text match** | `match_text: "Hello"` | Find by visible text |
| **Auto-index** | `auto_index: "text:5"` | Any module (from layout response) |
| **Occurrence** | `occurrence: 2` | Duplicate labels |

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
claude mcp add diviops-main -- env \
  WP_URL=http://main-site.local \
  WP_USER=admin WP_APP_PASSWORD="xxxx" \
  node /path/to/diviops-server/dist/index.js

# Test site (same MCP server build, different credentials)
claude mcp add diviops-test -- env \
  WP_URL=http://test-site.local \
  WP_USER=admin WP_APP_PASSWORD="yyyy" \
  node /path/to/diviops-server/dist/index.js
```

Each registration is independent — different site, different credentials, different MCP name. The same `diviops-server/dist/index.js` build works for all.

**Teammate setup**: They only need:
1. The `diviops-agent` plugin zip (installed in their WP site)
2. A copy of `diviops-server/` (clone repo or copy the directory, then `npm install && npm run build`)
3. Their own `WP_URL`, `WP_USER`, `WP_APP_PASSWORD`

## Troubleshooting

| Issue | Solution |
|-------|----------|
| 401 Unauthorized | Check WP_USER and WP_APP_PASSWORD |
| 503 Divi unavailable | Activate Divi theme |
| WP-CLI "not configured" | Set WP_PATH (Local by Flywheel only) |
| Styles not rendering | Hard-refresh browser (Cmd+Shift+R) — CSS cache |
| VB shows raw `$variable()$` | Dynamic content binding — click the chip to edit |

## For Maintainers: Updating the Distribution Repo

This guide is also the README for the distribution repo (`oaris-dev/diviops-internal`). The dist repo is **not auto-synced** — it must be updated manually after merging changes to the dev repo.

```bash
# From the dev repo root:
./scripts/package-dist.sh

# Push to dist repo:
cd dist-package
git add -A
git commit -m "Sync: [describe what changed]"
git push
```

The packaging script copies only distribution files (MCP server, plugins, skill, tests) — no dev history, research docs, or test pages. See `scripts/package-dist.sh` for details.
