# Divi 5 MCP Server

MCP server that exposes Divi 5 Visual Builder operations as tools for Claude Code and Claude Desktop.

```
Claude Code <-> MCP Server (stdio) <-> WordPress REST API <-> Divi MCP Plugin
```

## Requirements

- **Node.js** >= 18.0.0
- **WordPress** >= 5.6 (Application Passwords support)
- **Divi 5** theme active
- **DiviOps Agent** WordPress plugin installed and active

## Setup

### 1. Install the WordPress Plugin

Download and activate the **DiviOps Agent** plugin from the [releases page](https://github.com/oaris-dev/diviops-internal/releases).

### 2. Create an Application Password

Go to **WP Admin -> Users -> Your Profile -> Application Passwords**:
- Enter a name (e.g., "MCP Server")
- Click "Add New Application Password"
- Copy the generated password

> **Tip:** Strip spaces from Application Passwords before use. WordPress generates them with spaces for readability but accepts them without. Spaces in shell commands can cause parsing issues.

### 3. Configure Claude Code

```bash
claude mcp add diviops-mcp -- env \
  WP_URL=http://your-site.local \
  WP_USER=your-wp-username \
  WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  npx @diviops/mcp-server
```

**With WP-CLI** (optional — enables `diviops_wp_cli` tool):
```bash
claude mcp add diviops-mcp -- env \
  WP_URL=http://your-site.local \
  WP_USER=your-wp-username \
  WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  WP_PATH="/path/to/wordpress" \
  npx @diviops/mcp-server
```

**With Docker-based WP-CLI** (optional — uses a custom command prefix):
```bash
claude mcp add diviops-mcp -- env \
  WP_URL=https://site-name.ddev.site \
  WP_USER=your-wp-username \
  WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  WP_CLI_CMD="ddev wp" \
  npx @diviops/mcp-server
```

### Environment Variables

| Variable | Required | Description |
|----------|----------|-------------|
| `WP_URL` | Yes | WordPress site URL (e.g. `http://mysite.local`) |
| `WP_USER` | Yes | WordPress username with Editor or Admin role |
| `WP_APP_PASSWORD` | Yes | Application Password (spaces stripped) |
| `WP_PATH` | No | WordPress filesystem path for Local by Flywheel, or wrapper working directory when `WP_CLI_CMD` needs project context |
| `WP_CLI_CMD` | No | Custom WP-CLI command prefix for containerized environments, e.g. `ddev wp`, `npx wp-env run cli wp`, `docker exec -u www-data devkinsta_fpm wp --path=/www/kinsta/public/sitename` |
| `LOCAL_SITE_ID` | No | Override auto-detection of Local by Flywheel site ID |
| `DIVIOPS_WP_CLI_ALLOW` | No | Comma-separated list of extended WP-CLI commands to enable (see [WP-CLI Security](#wp-cli-security)) |

### Local Development Environments

The server connects via standard WordPress REST API and works with any environment that exposes WordPress over HTTP with Application Password support.

| Environment | WP_URL | WP-CLI setup | Notes |
|-------------|--------|--------------|-------|
| **Local by Flywheel** | `http://site-name.local` | `WP_PATH=/path/to/site/app/public` | Site ID auto-detected, fully supported |
| **WordPress Studio** | `http://localhost:{port}` | `WP_CLI_CMD="studio wp --path=/path/to/site"` | Port auto-assigned (8881, 8882, ...). Uses SQLite, not MySQL |
| **DDEV** | `https://site-name.ddev.site` | `WP_CLI_CMD="ddev wp"` plus `WP_PATH=/path/to/project` | Wrapper runs from `WP_PATH` so DDEV can resolve the site |
| **wp-env** | `http://localhost:8888` | `WP_CLI_CMD="npx wp-env run cli wp"` plus `WP_PATH=/path/to/project` | Wrapper runs from `WP_PATH`; requires `WP_ENVIRONMENT_TYPE=local` (see below) |
| **DevKinsta** | `https://site-name.local` | `WP_CLI_CMD="docker exec -u www-data devkinsta_fpm wp --path=/www/kinsta/public/sitename"` | HTTPS with self-signed certs |
| **Custom / Remote** | Your site URL | `WP_PATH=/path/to/site` or `WP_CLI_CMD="..."` | Works with any WP host |

> **Application Passwords on HTTP:** WordPress requires HTTPS for Application Passwords unless `WP_ENVIRONMENT_TYPE` is set to `'local'`. HTTPS environments (DDEV, DevKinsta) work out of the box. HTTP environments (wp-env, WordPress Studio) need this in `wp-config.php`:
> ```php
> define('WP_ENVIRONMENT_TYPE', 'local');
> ```
> Local by Flywheel sets this automatically.

> **WP-CLI note:** `WP_PATH` keeps the existing Local by Flywheel behavior by running `wp` directly on the host filesystem. For Docker-based environments (DDEV, wp-env, DevKinsta, WordPress Studio), set `WP_CLI_CMD` to the wrapper command instead. When `WP_CLI_CMD` is set, the server executes the wrapper from `WP_PATH` if provided, otherwise from its current working directory. The MCP server still validates the requested WP-CLI subcommand against its allowlist before executing either path.

## Available Tools (43)

### Read (24)
| Tool | Description |
|------|-------------|
| `diviops_test_connection` | Test WordPress connection and Divi version |
| `diviops_server_info` | DiviOps server identity, version, license type, capabilities |
| `diviops_list_pages` | List pages/posts with Divi status |
| `diviops_get_page` | Get page details and raw content |
| `diviops_get_page_layout` | Get parsed block tree (layout structure) |
| `diviops_get_section` | Get a single section's markup by admin label |
| `diviops_list_modules` | List all available Divi modules |
| `diviops_get_module_schema` | Get attribute schema for a module (optimized by default, `raw: true` for full) |
| `diviops_get_settings` | Get Divi site settings and theme options |
| `diviops_get_global_colors` | Get global color palette |
| `diviops_get_global_fonts` | Get global font definitions |
| `diviops_find_icon` | Search 1,989 icons by keyword (FA + Divi) |
| `diviops_list_templates` | List available MCP prompt templates |
| `diviops_get_template` | Get a specific template's block markup |
| `diviops_preset_audit` | Audit presets with referenced/unreferenced analysis |
| `diviops_list_library` | List saved Divi Library items |
| `diviops_get_library_item` | Get a library item's block markup |
| `diviops_render_preview` | Render block markup to HTML for preview |
| `diviops_validate_blocks` | Validate block markup (structure, required attrs, known pitfalls) |
| `diviops_list_tb_templates` | List Theme Builder templates with conditions and layout IDs |
| `diviops_get_tb_layout` | Get a Theme Builder layout's block markup (header/body/footer) |
| `diviops_list_variables` | List design token variables (filter by type or prefix) |
| `diviops_list_canvases` | List all canvas pages |
| `diviops_get_canvas` | Get canvas content |

### Write (17)
| Tool | Description |
|------|-------------|
| `diviops_create_page` | Create a new page with optional Divi content |
| `diviops_update_page_content` | Full page content rewrite |
| `diviops_append_section` | Append a section to existing page (start or end) |
| `diviops_replace_section` | Replace a section by admin label |
| `diviops_remove_section` | Remove a section by admin label |
| `diviops_update_module` | Update specific module attributes by label or text match |
| `diviops_move_module` | Move a block before/after another block (reorder modules, sections) |
| `diviops_preset_cleanup` | Remove spam/duplicate presets, bulk rename |
| `diviops_preset_update` | Update a specific preset (name, attrs) |
| `diviops_preset_delete` | Delete a preset by ID |
| `diviops_save_to_library` | Save block markup to Divi Library |
| `diviops_update_tb_layout` | Update a Theme Builder layout's block markup |
| `diviops_create_tb_template` | Create Theme Builder template with header/footer and conditions |
| `diviops_create_variable` | Create a design token variable |
| `diviops_delete_variable` | Delete a variable by ID |
| `diviops_create_canvas` | Create a canvas page |
| `diviops_update_canvas` | Update canvas content |
| `diviops_delete_canvas` | Delete a canvas page |

### Utility (1)
| Tool | Description |
|------|-------------|
| `diviops_wp_cli` | Run WP-CLI commands (allowlisted, requires `WP_PATH` or `WP_CLI_CMD`) |

## WP-CLI Security

The `diviops_wp_cli` tool validates every command against a safety allowlist before execution. Commands not on the list are rejected.

### Default allowlist (always available)

Read-only commands plus non-destructive writes needed for core MCP functionality:

| Category | Commands |
|----------|----------|
| Options | `option get`, `option list` |
| Posts | `post list`, `post get`, `post create`, `post update` |
| Post meta | `post meta get`, `post meta list`, `post meta set`, `post meta update` |
| Users | `user list` |
| Cache | `cache flush`, `transient delete`, `rewrite flush` |
| Info | `cron event list`, `plugin list`, `theme list`, `menu list`, `term list`, `term create`, `site url` |

### Extended commands (opt-in)

These commands carry higher risk and require explicit opt-in via the `DIVIOPS_WP_CLI_ALLOW` environment variable:

| Command | Risk | Why opt-in |
|---------|------|------------|
| `option update` | High | Can change site URL, admin email, or security settings |
| `post delete` | Medium | Permanently removes content |
| `post meta delete` | Medium | Removes metadata |
| `plugin activate` | Medium | Can enable untrusted plugins |
| `plugin deactivate` | Medium | Can disable security plugins |
| `eval-file` | Critical | Executes arbitrary PHP from a file path |

To enable extended commands, add `DIVIOPS_WP_CLI_ALLOW` to your MCP registration:

```bash
claude mcp add diviops-mcp -- env \
  WP_URL=http://your-site.local \
  WP_USER=admin \
  WP_APP_PASSWORD=xxxx \
  WP_PATH="/path/to/wordpress" \
  DIVIOPS_WP_CLI_ALLOW="option update,post delete" \
  npx @diviops/mcp-server
```

Only list the specific commands you need. Unknown entries are ignored with a warning.

## Example Usage

After setup, Claude can:

- "List all my Divi pages"
- "Show me the layout structure of page 42"
- "Create a new landing page with a hero section, 3-column features, and a CTA"
- "Save the hero section from page 312 to the Divi Library"
- "Validate this block markup before saving"

## Troubleshooting

### "Missing required environment variable(s)"
Ensure `WP_URL`, `WP_USER`, and `WP_APP_PASSWORD` are all set. Check your `claude mcp add` command.

### "Connection failed" error
- Verify the WP plugin is active: visit `{WP_URL}/wp-json/diviops/v1/settings` in your browser
- Check Application Password is correct (try with curl first)

### "Version mismatch" error
The MCP server and WP plugin versions are incompatible. Update whichever side is older.

### "Permission denied" errors
- The WP user must have `edit_posts` capability (Editor or Admin role)
- Write operations (presets, library, theme builder) require `manage_options` (Admin role)

### Testing manually
```bash
curl -u "username:apppassword" http://site.local/wp-json/diviops/v1/settings
```

## License

MIT
