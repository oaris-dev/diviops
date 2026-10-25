# DiviOps — Setup Guide

Connect an AI client, load the Divi authoring skill, and create one reviewed native Divi draft on a staging or disposable local site. This first-page path is available with DiviOps Free. This guide ships as `SETUP.md` in the Free and Pro distributions; see the [README](README.md) for suite components and Free/Pro scope.

> **Beta software.** DiviOps is under active development. Use on production sites at your own discretion. Always back up your WordPress site before running write operations.

## Prerequisites

- **WordPress** 6.5+ with **Divi 5** theme (5.1.0+)
- **PHP** 7.4+
- **Node.js** 22+ (for the MCP server)
- **Claude Code**, **Codex**, or **Claude Desktop** installed, with access to its MCP and skill features
- A staging or disposable local WordPress site you are authorized to edit
- For a remote site: valid HTTPS, a reachable WordPress REST API and Application Password authentication permitted for your account by the site/host policy

## Three independent components

Native Divi authoring requires all three components below. They are installed and
updated independently:

1. **DiviOps Agent** runs inside WordPress and owns the REST capability surface.
2. **`@diviops/mcp-server`** connects the AI client to that WordPress site.
3. **`diviops-divi-5` skill** gives the client the verified Divi block formats and
   native-module authoring rules.

A successful MCP connection proves transport, not native Divi authoring knowledge.
Updating WordPress or npm does not update a manually copied skill. Verify all three
components before the first write and after changing clients, workspaces, or skill
installation methods.

## Choose your client

Install the Free plugin and create an Application Password below, then follow one row:

| Client | Connect WordPress | Load the authoring skill | Continue |
|---|---|---|---|
| Claude Code | [Terminal command](#claude-code) | [Marketplace or one owned directory](#claude-code--existing-plugin-or-directory-installation) | Restart/open a fresh session, then [verify](#step-4-verify-registration) |
| Codex | [Local TOML configuration](#codex) | [Copy bundled skills](#codex-skills) | Restart/open a fresh session, then [verify](#step-4-verify-registration) |
| Claude Desktop chat | [Local JSON configuration](#claude-desktop-json) | [Upload the complete Free builder ZIP](#claude-desktop-chat--upload-the-free-skill-zip) | Restart Desktop/open a fresh chat, then [verify](#step-4-verify-registration) |

These terminal examples use macOS/Linux shell syntax. Windows/PowerShell and WSL
paths and client discovery need their own verification; do not paste multiline
shell examples into PowerShell unchanged. Client/organization policies can limit
MCP or skill availability. A missing feature is a reason to stop and resolve setup,
not to let the assistant guess native Divi markup.

## Step 1: Install the WordPress Plugin

1. Go to **WP Admin → Plugins → Add New**, search for **DiviOps Agent**, install it, and activate it.
2. For a manual alternative, download [diviops-agent.zip](diviops-agent.zip) from this distribution and upload it via **WP Admin → Plugins → Add New → Upload Plugin** and activate it.
3. Confirm **DiviOps Agent** is active in Plugins. The [connection check](#step-5-test-connection) below verifies the selected target after client setup.

> **If Divi is not active**, authenticated requests return `503 divi_unavailable`. Unauthenticated requests return 401 first.

### Free plugin updates

WordPress delivers Free plugin updates through **Dashboard → Updates** and **Plugins**. The npm MCP server and skills update separately; updating one does not update the others. Restart/reconnect MCP after a plugin or server update and verify the reported versions and capabilities before authoring.

For a manual fallback, replace the plugin ZIP through WordPress admin:

1. Download [diviops-agent.zip](diviops-agent.zip) from the selected distribution
2. Go to **WP Admin → Plugins → Add New → Upload Plugin**
3. Upload the new `diviops-agent.zip`
4. When WordPress asks, choose **Replace current with uploaded**

Your Application Password and MCP client config stay the same across Free plugin updates.

## Step 2: Create an Application Password

1. Go to **WP Admin → Users → Your Profile**
2. Scroll to **Application Passwords**
3. Enter a name (e.g., "Claude MCP") and click **Add New Application Password**
4. Copy the generated password

Keep the generated password in private storage; WordPress displays it only once.
Do not paste it into AI chat, issue reports, screenshots or shared logs. Substitute
it only in your chosen client's local command/configuration below.

If Application Passwords is absent, ask the administrator to check your account,
HTTPS and site/security policy. For a deliberately local HTTP site, see
[local environment guidance](#local-development-environments); do not weaken
production TLS or mark a production site as local.

[WordPress accepts grouping spaces](https://developer.wordpress.org/reference/functions/wp_authenticate_application_password/)
with or without removal. The client command/configuration must preserve the value
as one argument or string; removing spaces is optional, not an authentication fix.

## Step 3: Register with Your AI Client

The MCP server runs from the published npm package — no clone, no build step.

The successor server line requires Node.js 22 or newer. Upgrade Node before
using `@diviops/mcp-server@1.5.40` or later. If the client host must remain on
Node 18, pin the previously shipped compatible server explicitly:

```bash
npx -y --package=@diviops/mcp-server@1.5.39 diviops-mcp
```

The server pin does not select or downgrade either WordPress plugin. Server,
Free, and Pro versions are independent, and the connected site's advertised
capabilities remain the compatibility gate. The Node 22 successor continues to
use direct npm/stdio and MCP v1; MCP v2 fixtures and the launcher sidecar remain
unreleased.

> **Important**: Choose a unique MCP name that won't conflict with other MCP servers you have registered. Use your site name (e.g., `diviops-mysite`).

### Claude Code

Run the following in your computer's terminal shell, from the project where you
will use Claude Code, **not in an AI chat**. Replace `WP_URL`, `WP_USER` and
`WP_APP_PASSWORD` placeholders locally; use HTTPS for a remote target and keep
each complete `KEY=VALUE` argument quoted. The examples use double quotes; escape
shell-special characters in your own values if needed. The generated Application
Password is alphanumeric with optional grouping spaces. Keep credentials out of
shared shell history/transcripts.

`diviops-mysite` is your connection name: choose a unique name per site. The final
`diviops-mcp` is the package executable; keep that spelling. These are the native
[Claude Code stdio/--env options](https://code.claude.com/docs/en/mcp).

<a id="minimal-rest-api-only--works-with-any-wordpress-host"></a>

#### Minimal (REST API only)

```bash
claude mcp add diviops-mysite \
  --env "WP_URL=http://your-site.local" \
  --env "WP_USER=your-username" \
  --env "WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX" \
  -- npx -y --package @diviops/mcp-server diviops-mcp
```

### Codex

On the computer/host running Codex, open `~/.codex/config.toml` in a local text
editor. Add one entry for the intended site, preserving other entries. This is a
local configuration edit, **not text to send to Codex chat**. Replace the three
placeholder values inside the strings; use HTTPS for a remote site. If you change
`diviops-mysite`, change it in both table headings. Keep the file private and out
of version control. See the [official MCP configuration reference](https://learn.chatgpt.com/docs/extend/mcp?surface=cli).

```toml
[mcp_servers.diviops-mysite]
command = "npx"
args = ["-y", "--package", "@diviops/mcp-server", "diviops-mcp"]

[mcp_servers.diviops-mysite.env]
WP_URL = "http://your-site.local"
WP_USER = "your-username"
WP_APP_PASSWORD = "xxxxXXXXxxxxXXXXxxxxXXXX"
```

For a local WordPress site where you want WP-CLI passthrough tools, add this line under `[mcp_servers.diviops-mysite.env]`:

```toml
WP_PATH = "/absolute/path/to/wordpress"
```

Restart Codex after changing MCP config.

### Claude Desktop JSON

In Claude Desktop's application settings, choose **Developer → Edit Config**
to open `claude_desktop_config.json` in a local editor. Add the named entry under
`mcpServers`, preserving existing entries; do not replace the whole file or paste
credentials into chat. Substitute the URL, username and password strings locally
(use HTTPS remotely), choose a unique connection name, then save and restart
Desktop. See the [MCP Desktop configuration guide](https://modelcontextprotocol.io/docs/develop/connect-local-servers).
The example shows a file with just one connection:

```json
{
  "mcpServers": {
    "diviops-mysite": {
      "command": "npx",
      "args": ["-y", "--package", "@diviops/mcp-server", "diviops-mcp"],
      "env": {
        "WP_URL": "http://your-site.local",
        "WP_USER": "your-username",
        "WP_APP_PASSWORD": "xxxxXXXXxxxxXXXXxxxxXXXX"
      }
    }
  }
}
```

## Step 4: Verify Registration

After configuring the connection, [load the skill for your client](#step-7-load-the-divi-5-builder-skill),
then restart/reconnect and open a fresh session. In Claude Code, `claude mcp list`
can help inspect registration; in Codex/Desktop, check the selected entry in the
client's MCP settings. Do not post raw configuration or credential-bearing output.
If the server is absent or disconnected, resolve that before the next step.

## Step 5: Test Connection

Use this one read-only check in your chosen client's fresh session:

```text
Use diviops_meta_ping and diviops_meta_info to confirm the connection.
Report the target URL, WordPress/Divi and Free/MCP versions and available
capabilities, without credentials. Do not create or change content. If the
connection fails or the target differs from the staging/local site I intend,
stop and explain what needs correcting.
```

Confirm the reported target yourself. A connection and capability report do not
prove the authoring skill loaded; continue only after the [skill/reference check](#step-7-load-the-divi-5-builder-skill)
and [native draft verification](#first-run-native-divi-verification).
After a target, plugin, theme or server change, reconnect and repeat this check;
an existing session's startup tool catalog can be stale.

> **If tools don't appear:** verify the selected registration and `npx` availability
> on the client host, then restart/reconnect. The explicit
> `-y --package @diviops/mcp-server diviops-mcp` form selects the package binary.
> See [Common Pitfalls](#common-pitfalls) for recovery; do not replace a different
> site's connection or let the assistant write without the required tools.

<a id="step-7-load-the-divi-5-builder-skill"></a>

## Load the Divi 5 Builder Skill

The skill teaches the assistant the correct Divi 5 block format — module attribute paths, design patterns, and format rules. **Without it, the agent will guess attribute formats and produce broken pages** (e.g., empty buttons, wrong innerContent format).

### Skill names and folder layout

The `diviops-<target>` convention is a naming namespace, not a parent directory:

```text
skills/
├── diviops/             # shared harness primer
├── diviops-divi-5/      # Divi coverage, with its own references/
├── diviops-scf/         # separately distributed SCF coverage
└── diviops-fluentcart/  # separately distributed FluentCart coverage
```

Each coverage slice is a sibling skill. `diviops/` contains the shared primer;
it is not a folder into which other skills should be moved. A recipe belongs in
the relevant skill's references, rather than becoming a skill for each visual.
Install only the slices in your selected distribution. The Free Desktop uploads preserve
`diviops-divi-5` and `diviops` in separate ZIPs; their self-contained reference
packaging does not change the convention. The legacy marketplace package identifier described
below is a third identity, separate from the skill name and folder layout.

### Claude Desktop chat — upload the Free skill ZIP

Download [diviops-divi-5-desktop.zip](https://github.com/oaris-dev/diviops/raw/refs/heads/main/diviops-divi-5-desktop.zip) from the public distribution. Keep the ZIP intact: it contains one `diviops-divi-5/` folder, `SKILL.md`, all Free supporting references, and a generated copy of the shared primer. No repository download, manual repackaging, or separate primer upload is needed for the builder.

1. Enable **Code execution and file creation** in Claude's capabilities settings. Organization policy may also need to permit skills.
2. Open **Customize → Skills → + (Add skill) → Upload skill** and select the ZIP. Some versions place **Upload a skill** under **Create skill**.
3. Enable `diviops-divi-5`, then open a fresh chat. Ask `What skills do you have?`, then ask Claude to read its bundled primer and `references/module-formats.md` before any Divi authoring. Verify that it can open these references; an uploaded file alone is not proof that the chat loaded it.

For independent use of the shared harness conventions, [diviops-desktop.zip](https://github.com/oaris-dev/diviops/raw/refs/heads/main/diviops-desktop.zip) is an optional separate upload named `diviops`. It is not the builder skill. The canonical names remain `diviops` and `diviops-divi-5`; do not rename either archive's skill folder.

Skills provide knowledge. They do not install MCP tools, connect WordPress, or grant Pro features. Configure the MCP connection separately using the Desktop instructions above. These ZIPs include Free guidance only; SCF and FluentCart skills remain separate Pro slices.

WordPress and npm updates do not replace an uploaded skill. Retain the previous ZIP for rollback, download the new package, replace the selected custom skill through Claude's supported management UI, and verify it in a fresh chat. Keep one enabled builder copy. Cloud-enabled skills can also sync into recent Claude Code versions using the same Claude account; check ownership before enabling a duplicate local/plugin copy.

See Anthropic's [skill upload and management instructions](https://support.claude.com/en/articles/12512180-use-skills-in-claude) and [ZIP structure requirements](https://support.claude.com/en/articles/12512198-how-to-create-custom-skills). If the download is unavailable in your selected distribution, do not upload `SKILL.md` alone; it needs its reference files.

### Claude Code — existing plugin or directory installation

**Option A — Install as a Claude Code plugin** (recommended):
```bash
claude plugin marketplace add oaris-dev/diviops
claude plugin install divi-5-builder@diviops
```

The plugin identifier remains `divi-5-builder@diviops` for installed-plugin continuity; the skill inside it is named `diviops-divi-5`. Use a distribution containing the naming migration, not an older package with the old skill name. No need to clone or copy files. To update later:
```bash
claude plugin update divi-5-builder@diviops
```

**Option B — Load from cloned repo**:
```bash
git clone https://github.com/oaris-dev/diviops.git
cd diviops
claude --plugin-dir .
```

**Option C — Copy skill to your project** (auto-loads without flags):

For an existing installation, follow the replacement procedure below before copying. These copy commands assume both destination skill directories are absent. They copy the builder and its required sibling primer.
```bash
mkdir -p /path/to/your-project/.claude/skills
cp -R /path/to/diviops/skills/diviops /path/to/diviops/skills/diviops-divi-5 /path/to/your-project/.claude/skills/
cd /path/to/your-project
claude
```

This project-local path is
`<workspace>/.claude/skills/diviops-divi-5/SKILL.md`. Start Claude Code from that
workspace so the project skill is discoverable.

**Option D — Copy skill for Claude Code user-wide use** (the same ownership/replacement checks apply to both directories):
```bash
mkdir -p "$HOME/.claude/skills"
cp -R /path/to/diviops/skills/diviops /path/to/diviops/skills/diviops-divi-5 "$HOME/.claude/skills/"
```

The resulting path is `~/.claude/skills/diviops-divi-5/SKILL.md`.

Verify the skill loaded:
```
What skills do you have?
```
You should see `diviops-divi-5` and the sibling `diviops` primer in the list. Ask it to read `references/module-formats.md` and the bundled `diviops` primer; the displayed name alone does not prove reference access. If either cannot be read, stop before authoring.

Use one ownership path for a skill. Do not keep a Claude plugin-managed copy and a
stale manual copy active at the same time. Plugin-managed skills update with
`claude plugin update divi-5-builder@diviops`; manual project or user copies
update only when you replace those copied files from a newer distribution.

Pro packages include additional slice skills such as `diviops-fluentcart` and `diviops-scf`. Install or copy all bundled `skills/*` entries when you want those Pro workflows available to the agent.

<a id="codex-skills"></a>

**Codex — copy bundled skills into one owned skill directory** (requires a local repo clone from Option B, or an extracted DiviOps distribution containing `skills/`). For a new user-wide installation, current [Codex documentation](https://developers.openai.com/codex/skills) specifies `~/.agents/skills`. The following commands assume the bundled destination directories are absent:

```bash
mkdir -p "$HOME/.agents/skills"
cp -R /path/to/diviops/skills/* "$HOME/.agents/skills/"
```

For existing installations (including earlier `~/.codex/skills` copies), inspect
which copy your client actually loads before replacing or relocating anything.
Apply the replacement procedure below to each selected bundled skill; do not
merge-copy over local changes or create duplicates across discovery roots.

Restart Codex after copying skills. Verify with:
```
What skills do you have?
```
You should see `diviops-divi-5` and any bundled DiviOps slice skills you installed. Ask the client to read the builder's `references/module-formats.md` and the bundled `diviops` primer before writing. If either is unavailable, stop and resolve the installation.

Codex manual skills are not updated by npm, WordPress, or Claude plugin updates;
replace the copied directory from the new distribution and restart Codex. For
Claude Desktop chat, use the ZIP upload instructions above. Other MCP clients
need their own supported skill mechanism; otherwise treat them as read-only
for Divi authoring.

### Replacing an existing builder skill

The renamed skill preserves the existing Free/Pro guidance split; it does not require a new entitlement. Do not install a second discoverable alias for `divi-5-builder`.

1. Select the one installation you own: plugin-managed, project-local or user-local. Inspect the explicitly selected roots for both `divi-5-builder` and `diviops-divi-5`; different names can still be duplicate builder skills. Do not scan or change unrelated workspaces.
2. Record the selected distribution/version and compare the installed contents. Preserve the previous directory and any local modifications in a rollback location **outside all skill discovery roots**. If ownership or local changes are uncertain, stop and reconcile them before replacement. Renaming a directory changes path-sensitive receipts; keep the original evidence intact.
3. For plugin-managed installations, use the existing plugin update command above; do not manually edit plugin caches. Verify the resulting package contains only the new builder skill. For manual installations, retire only the confirmed old builder directory and replace the confirmed destination with the selected distribution's complete builder directory. Do not merge-copy over an existing directory or discard local edits. Keep other skills untouched.
4. Restart/re-discover using the client's supported mechanism and open a fresh session. Verify `diviops-divi-5` is available once and the old skill is no longer selected. Files on disk do not prove that an existing session refreshed its context. Keep rollback until the new session is accepted.

Apply the same ownership and rollback checks when replacing the required sibling `diviops` primer. Keep it beside the builder; do not merge-copy over an existing primer or overwrite unrelated skills.

The skill's frontmatter version and the enclosing plugin package version are separate identities. Updating npm or the WordPress plugin does not update manual skill copies. Source migration alone is not evidence that a published package or installed client has migrated.

## First-run native Divi verification

After the read-only connection and skill checks pass, explicitly choose a staging
or disposable local target and authorize one disposable draft there. Replace
`[confirmed site URL]` below; stop if the reported target does not match.

```text
On [confirmed site URL], confirm diviops-divi-5 and its required references
are loaded, then call diviops_meta_info. If the target, skill or required
tools are unavailable or uncertain, stop without changing content.
Construct inline markup containing native Divi section, row, column, heading,
text and button modules. Validate it with diviops_validate_blocks before any
write. Do not use a Code module, page-sized HTML, an iframe layout or structural
HTML inside text/container fields. Only with zero inline validation errors,
create one disposable unpublished draft from those validated bytes. Validate
the saved page by its exact ID and report both results plus the native module
names: divi/section, divi/row, divi/column, divi/heading, divi/text, divi/button.
Return its ID and preview for my review. Do not edit existing pages, shared
presets or publish anything. If a step fails, stop and report the draft ID if
one exists; do not create another draft automatically.
```

Pass only with zero inline and saved-page validation errors, the required native
module tree and human review of the draft. An HTML-blob substitute is not a
successful native setup. After review, trash **only that exact disposable draft**;
if verification failed, inspect the reported draft before deciding its cleanup.
Then continue to the useful-page example below, which is kept for further editing.

<a id="quick-test-generate-your-first-page"></a>

## Create a useful draft to keep

After verification, try a small service introduction on the same confirmed safe
site. Fill in the brief and button destination; this is a separate draft to keep
for editing, not the disposable test above.

```text
On [confirmed site URL], plan a small service introduction for [service/audience]
with a native heading, short text and one button to [URL]. Inspect existing
variables and presets first and show me the plan before creating one draft.
Use the loaded diviops-divi-5 skill and native Divi modules. Validate inline
markup before writing and the saved draft afterwards, with zero errors.
Stop if the target, skill, references or required tools are uncertain/unavailable.
Do not edit existing pages, change shared presets, use a Code/HTML fallback or
publish. Return the unpublished draft ID/preview and what I should review.
Keep this useful draft for further editing; do not trash it with the test draft.
```

Review the text, button destination, native editability and desktop/mobile layout
in WordPress/Divi. Publishing is a later explicit decision.

## Optional features and advanced setup

The Free path above does not require a Pro purchase, Visuals, WP-CLI passthrough
or a design-system bootstrap. These sections retain their separate setup and
permission requirements. Free/Pro scope lives in the [distribution README](README.md).

### Purchased Pro: install the Pro plugin too

If you downloaded the Pro package, install the Pro add-on after the Free plugin:

1. Upload and activate `diviops-agent-pro.zip`
2. Confirm both plugins are active: **DiviOps Agent** and **DiviOps Agent Pro**
3. Open **DiviOps → Pro License**
4. Paste your license key and activate it
5. Confirm the status shows an active matched plan and update eligibility

The Pro plugin requires the Free plugin. Pro runtime features continue to work after install, while the license gates updates and support.

> **Activation policy.** A license activation represents one active WordPress environment where DiviOps Pro is installed and used, including local development sites such as `localhost`, `.local`, `.test`, and `.lab`. Deactivate old environments from your customer account when they are no longer in active use.

### Purchased Pro: verify Pro capabilities

After activating `diviops-agent-pro.zip` and the Pro license, restart the MCP client and run:

```
Use diviops_meta_info to show the plugin handshake and capability summary. In a regular npm/stdio session, inspect `startup_capture`: successfully observed plugin, module, capability, and registered-tool evidence is the immutable startup snapshot. A failed handshake reports `startup_unavailable` with no covered target evidence. Reconnect or restart MCP after a plugin, theme, or target change before treating successful startup evidence as current. A live read such as `diviops_fc_status` is a separate current observation and does not refresh the startup tool catalog.
```

If FluentCart and FluentCart Pro are active on the target site, `diviops_meta_info` should report `slices.fluentcart.active: true` and list `fluentcart_*` entries under `slices.fluentcart.tool_capabilities`. Pro coverage tools such as `diviops_fc_status`, `diviops_fc_product_list`, `diviops_fc_gateway_list`, and license/order readback tools should also appear in the MCP tool list. If the target plugin is not installed or the Pro plugin is inactive, those tools are intentionally omitted.

<a id="step-6-optional--install-the-design-library-plugin"></a>

<a id="step-6-optional--install-diviops-visuals"></a>

### Install DiviOps Visuals

For CSS entrance animations (`ddl-fade-up`, `ddl-scale-in`), gradient text effects, and Three.js WebGL shaders:

1. Download [diviops-design-library.zip](diviops-design-library.zip) from this distribution
2. Upload and activate in **WP Admin → Plugins → Add New → Upload Plugin**

This is optional — the MCP agent works without it. DiviOps Visuals is the Free
companion formerly displayed as DiviOps Design Library. Its installed directory
and ZIP name remain `diviops-design-library`; older releases may still display
the former name. The display change does not require reinstallation.

### With WP-CLI (Local by Flywheel — enables the `diviops_meta_wp_cli` tool)

```bash
claude mcp add diviops-mysite \
  --env "WP_URL=http://your-site.local" \
  --env "WP_USER=your-username" \
  --env "WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX" \
  --env "WP_PATH=/Users/you/Local Sites/your-site/app/public" \
  -- npx -y --package @diviops/mcp-server diviops-mcp
```

> Use Claude Code's `--env` options before `--`; the server command comes after it. Quoting the full `KEY=VALUE` argument preserves grouping spaces in a password and spaces in a path.

> `LOCAL_SITE_ID` is auto-detected from `WP_PATH` — no need to find it manually.

### Fallback: Global Install

If Claude cannot find `npx`, install the package globally and register the installed bin:

```bash
npm install -g @diviops/mcp-server@latest

claude mcp add diviops-mysite \
  --env "WP_URL=http://your-site.local" \
  --env "WP_USER=your-username" \
  --env "WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX" \
  -- diviops-mcp
```

If the global bin directory is also missing from Claude's `PATH`, register the absolute entrypoint:

```bash
claude mcp add diviops-mysite \
  --env "WP_URL=http://your-site.local" \
  --env "WP_USER=your-username" \
  --env "WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX" \
  -- node "$(npm root -g)/@diviops/mcp-server/dist/index.js"
```

### Local Development Environments

DiviOps needs a reachable WordPress REST API and permitted Application Password authentication. Remote targets need valid HTTPS; host restrictions, proxies and security plugins can block requests. The following local-environment examples are configuration references, not a tested compatibility guarantee for every host or OS.

| Environment | `WP_URL` | WP-CLI setup | Notes |
|-------------|----------|--------------|-------|
| **Local by Flywheel** | `http://site-name.local` | `WP_PATH=/path/to/site/app/public` | Site ID auto-detected |
| **WordPress Studio** | `http://localhost:{port}` | `WP_CLI_CMD="studio wp --path=/path/to/site"` | Port auto-assigned (8881, 8882, …); SQLite |
| **DDEV** | `https://site-name.ddev.site` | `WP_CLI_CMD="ddev wp"` plus `WP_PATH=/path/to/project` | Wrapper runs from `WP_PATH` |
| **wp-env** | `http://localhost:8888` | `WP_CLI_CMD="npx wp-env run cli wp"` plus `WP_PATH=/path/to/project` | Requires `WP_ENVIRONMENT_TYPE=local` (see below) |
| **DevKinsta** | `https://site-name.local` | `WP_CLI_CMD="docker exec -u www-data devkinsta_fpm wp --path=/www/kinsta/public/sitename"` | HTTPS with self-signed certs |
| **Custom / Remote** | Your HTTPS site URL | Optional; requires separately configured execution access | REST reachability and account/host policy must allow it |

> **Application Passwords on local HTTP:** On a deliberately local development site, WordPress can expose Application Passwords when `WP_ENVIRONMENT_TYPE` is `'local'`, subject to account/site policy. If that local environment does not already set it, its administrator can configure:
> ```php
> define('WP_ENVIRONMENT_TYPE', 'local');
> ```
> Check the effective local environment configuration. Never use this exception on production or disable certificate verification; HTTPS targets need a certificate trusted by the client host.

### Environment Variables

| Variable | Required | Description |
|----------|----------|-------------|
| `WP_URL` | Yes | WordPress site URL (e.g. `http://mysite.local`) |
| `WP_USER` | Yes | WordPress username with Editor or Admin role |
| `WP_APP_PASSWORD` | Yes | Application Password (grouping spaces optional; preserve as one string) |
| `WP_PATH` | No | WordPress filesystem path for Local by Flywheel, or wrapper working directory when `WP_CLI_CMD` needs project context |
| `WP_CLI_CMD` | No | Custom WP-CLI command prefix for containerized environments |
| `LOCAL_SITE_ID` | No | Override auto-detection of Local by Flywheel site ID |
| `DIVIOPS_WP_CLI_ALLOW` | No | Comma-separated list of extended WP-CLI commands to enable ([see below](#wp-cli-security)) |

### Common Pitfalls

- **Password grouping spaces are optional** — quote the complete shell argument or JSON/TOML string. Do not paste credentials into chat.
- **Use absolute paths** for `WP_PATH` — relative paths break when Claude Code runs from a different directory
- **Unique MCP name** — don't reuse a name from another project
- **Paths with spaces** — wrap the entire `KEY=VALUE` argument in double quotes (e.g. `--env "WP_PATH=/path with spaces/"`). Same goes for any custom server script path passed after `--`
- **MCP not appearing after registration** — in Claude Code, run `claude mcp list` to verify. If it's not there, `claude mcp remove` and re-add. In Codex, verify the selected `~/.codex/config.toml` entry and restart Codex. In Desktop, verify the selected MCP JSON entry and restart the app. Preserve unrelated connections when correcting an entry.

<a id="step-8-optional--bootstrap-the-design-system"></a>

## Optional — Bootstrap the Design System

The skill uses a per-project design system manifest (`.claude/design-system.json`) to resolve preset role keys to site-specific UUIDs. Without it, the agent falls back to inline styling or runtime discovery via `diviops_preset_audit`.

> **This is optional.** Pages can be generated without a design system — the agent uses inline values. The design system adds consistency and reduces token count.

Inspect existing brand values and presets before proposing changes. The `oa` names below are an optional convention, not required setup; keep established project names and reuse their IDs where appropriate. Token/preset creation is a separate intentional write, not part of the first-page verification. Start with the audit prompt and review the proposed scope before any bootstrap.

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

1. **Prefer keeping existing presets** and generate a manifest mapping them:
```
My site has existing presets that are not oa-named. Run diviops_preset_audit
and list all presets with their names, IDs, and groupNames. Help me map them
to the standard role keys (heading-h1, text-standard, button-primary, etc.)
and generate .claude/design-system.json using my existing preset UUIDs.
```

2. **Or create oa presets alongside** existing ones for consistency with the canonical system. Use the **Create Presets** checklist below.

---

### Create Presets (all paths)

First inspect the target's advertised tools/capabilities, installed versions and
existing preset names/IDs. Released DiviOps supports `diviops_preset_create` for
module presets and group (attribute-level) presets with the required group
coordinates. This is not a promise that every group, attribute, composition or
migration works on every installed version. Use only a supported shape described
by the loaded skill and exposed by the current target.

```text
Inspect existing presets and the current tool/capability contract. Prefer reuse
and preserve my established brand names. For any requested new preset, propose
its exact module/group shape and a dry-run preview where supported. Do not apply,
set defaults, reassign or delete presets without my approval. If the required
shape is unsupported or uncertain, give me a Visual Builder creation checklist
instead of guessing attributes or bypassing a version/capability gate.
```

After an approved tool write or manual Visual Builder creation, inspect the stored
preset and validate its use in a native draft. Capture its actual UUID for the
manifest. A dry-run preview alone is not proof of saved/rendered correctness.

### Generate Manifest (all paths)

Once tokens and presets are in place:

```
Generate .claude/design-system.json for my project.
Map my chosen existing or newly approved preset names to role keys and capture
actual UUIDs from diviops_preset_audit; do not rename or replace existing presets.
Also create .claude/instructions/design-system.md with my project's brand
personality and design preferences.
```

See [SKILL.md — Design System Lifecycle](https://github.com/oaris-dev/diviops/blob/main/skills/diviops-divi-5/SKILL.md#design-system-lifecycle) for the full technical reference.

## Targeting Modules

Four ways to target modules for editing:

| Mode | Example | Use when |
|------|---------|----------|
| **Admin label** | `label: "Hero Heading"` | MCP-generated content |
| **Text match** | `match_text: "Hello"` | Find by visible text |
| **Auto-index** | `auto_index: "text:5"` | Any module (from layout response) |
| **Occurrence** | `occurrence: 2` | Duplicate labels |

## WP-CLI Security

The `diviops_meta_wp_cli` tool validates every command against a safety allowlist. Default allowlist covers read-only commands (options, posts, post-term assignment reads, taxonomies, users, ACF field groups, cron/plugin/theme/menu info) plus non-destructive writes (post create/update, term create/update, post-meta set/update, ACF schema export/import, cache flush, transient delete, rewrite flush, WXR export).

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
  --env "WP_URL=http://your-site.local" \
  --env "WP_USER=admin" \
  --env "WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX" \
  --env "WP_PATH=/Users/you/Local Sites/your-site/app/public" \
  --env "DIVIOPS_WP_CLI_ALLOW=option update,post delete,search-replace" \
  -- npx -y --package @diviops/mcp-server diviops-mcp
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
# Remote staging site
claude mcp add diviops-main \
  --env "WP_URL=https://staging.example.com" \
  --env "WP_USER=admin" \
  --env "WP_APP_PASSWORD=xxxx" \
  -- npx -y --package @diviops/mcp-server diviops-mcp

# Test site (same MCP package, different credentials)
claude mcp add diviops-test \
  --env "WP_URL=http://test-site.local" \
  --env "WP_USER=admin" \
  --env "WP_APP_PASSWORD=yyyy" \
  -- npx -y --package @diviops/mcp-server diviops-mcp
```

Each registration is independent — different site, different credentials, different MCP name.

**Teammate setup**: They only need:
1. `diviops-agent.zip` installed in their WordPress site
2. For Pro seats, `diviops-agent-pro.zip` installed and activated with their license key
3. `claude mcp add ... npx -y --package @diviops/mcp-server diviops-mcp` with their own `WP_URL`, `WP_USER`, `WP_APP_PASSWORD`
4. The bundled skills via `claude plugin marketplace add oaris-dev/diviops` followed by `claude plugin install divi-5-builder@diviops`, or by copying `skills/*` into their Codex skill directory

No source build is needed. Manual skill copies need a downloaded/extracted distribution or clone.

## Troubleshooting

| Issue | Solution |
|-------|----------|
| 401 Unauthorized | Check the intended target, username and Application Password, quoting/string handling and account policy; grouping-space removal is optional |
| 503 Divi unavailable | Activate Divi 5 theme |
| WP-CLI "not configured" | Set `WP_PATH` (Local by Flywheel) or `WP_CLI_CMD` (containerized) |
| Styles not rendering | Hard-refresh browser (Cmd+Shift+R) — CSS cache |
| VB shows raw `$variable()$` | Dynamic content binding — click the chip to edit |
| `npx` can't find package | Update Node.js to 22+; verify `npx --version` works; use `npx -y --package @diviops/mcp-server diviops-mcp`; the explicit package/bin form is required |

## Verification scope

Configuration syntax and generated distribution references are checked separately
from real client sessions. These instructions do not certify every client/OS
combination. Record your client/OS, Free/MCP versions, selected skill distribution
and target when you perform the checks; do not include credentials. Missing skill,
disconnected client or wrong target must stop authoring. A dashboard screenshot,
plugin activation or successful ping is not a completed first-page test.
