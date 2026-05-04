# Divi 5 MCP Server

MCP server that exposes Divi 5 Visual Builder operations as tools for Claude Code and Claude Desktop.

```
Claude Code <-> MCP Server (stdio) <-> WordPress REST API <-> Divi MCP Plugin
```

## Requirements

- **Node.js** >= 18.0.0
- **PHP** >= 7.4
- **WordPress** >= 6.5
- **Divi 5** theme active
- **DiviOps Agent** WordPress plugin installed and active

## Setup

### 1. Install the WordPress Plugin

Download and activate the **DiviOps Agent** plugin — [direct zip](https://github.com/oaris-dev/diviops/raw/main/diviops-agent.zip) or browse the [public distribution repo](https://github.com/oaris-dev/diviops).

### 2. Create an Application Password

Go to **WP Admin -> Users -> Your Profile -> Application Passwords**:
- Enter a name (e.g., "MCP Server")
- Click "Add New Application Password"
- Copy the generated password

> **Tip:** Strip spaces from Application Passwords before use. WordPress generates them with spaces for readability but accepts them without. Spaces in shell commands can cause parsing issues.

### 3. Configure Claude Code

```bash
claude mcp add diviops-mcp \
  --env WP_URL=http://your-site.local \
  --env WP_USER=your-wp-username \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  -- npx @diviops/mcp-server
```

> **Use `--env` flags, not the `env` command.** Claude Code's native `--env KEY=VALUE` flags survive copy-paste; the older `-- env KEY=VALUE` form (piping through unix `env`) breaks silently when any value contains a space. Quote any value with spaces (e.g. `--env "WP_PATH=/Users/you/Local Sites/site/app/public"`) — no backslash escaping needed inside quotes.

**With WP-CLI** (optional — enables `diviops_wp_cli` tool):
```bash
claude mcp add diviops-mcp \
  --env WP_URL=http://your-site.local \
  --env WP_USER=your-wp-username \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  --env "WP_PATH=/path/to/wordpress" \
  -- npx @diviops/mcp-server
```

**With Docker-based WP-CLI** (optional — uses a custom command prefix):
```bash
claude mcp add diviops-mcp \
  --env WP_URL=https://site-name.ddev.site \
  --env WP_USER=your-wp-username \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  --env "WP_CLI_CMD=ddev wp" \
  -- npx @diviops/mcp-server
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
| `DIVIOPS_WP_CLI_SAFE_FS_ROOT` | No | Absolute path to constrain filesystem-touching commands (`wp export`, `acf export/import`). Defaults to `<WP_PATH>/.diviops-tmp/` in host mode. **Required** in `WP_CLI_CMD` wrapper mode (must be the container-namespace path, since the host path can't be inferred) |
| `DIVIOPS_WP_CLI_UNSAFE_FS` | No | Set to `1` to disable filesystem flag validation entirely. For trusted single-user local-dev setups where the `--dir` / positional-path safety checks get in the way |

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

## Available Tools (63)

### Read (30)
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
| `diviops_preset_audit` | Audit presets with referenced/unreferenced analysis. Walks both page content and in-registry `groupPresets` chains; exposes `block_ref_count`, `group_ref_count`, `referenced_by_presets`. Also reports `orphan_default_pointers` — per-bucket `default` pointers referencing UUIDs missing from `items[]` (legacy damage from past unsafe deletes; clear via `diviops_preset_set_default` in bucket-addressed mode: `type` + `module` + `unset=true`) |
| `diviops_preset_scan_orphans` | List page-referenced preset UUIDs missing from the D5 registry (separates dangling orphans from D4-legacy refs) |
| `diviops_list_library` | List saved Divi Library items |
| `diviops_get_library_item` | Get a library item's block markup |
| `diviops_render_preview` | Render block markup to HTML for preview |
| `diviops_validate_blocks` | Validate block markup (structure, required attrs, known pitfalls) |
| `diviops_list_tb_templates` | List Theme Builder templates with conditions and layout IDs |
| `diviops_get_tb_layout` | Get a Theme Builder layout's block markup (header/body/footer) |
| `diviops_list_variables` | List design token variables (filter by type or prefix) |
| `diviops_variables_scan_orphans` | Find `gvid-`/`gcid-` refs with no backing Variable Manager entry (orphans render as invalid CSS) + unused variables (defined, never referenced). Scans pages, Theme Builder layouts (header/body/footer), Divi Library items, canvas pages, and the preset registry |
| `diviops_variables_used_on_page` | Detect which `gvid-` (numeric/font) IDs a single page emits — the exact set Divi 5.4.0+ uses to scope selective `:root{--gvid-*}` CSS variable emission. Walks the same content stack the frontend assembles (post_content + active TB header/body/footer + appended canvases + presets). `gcid-` colors are out of scope (separate emission path). Use for per-page orphan validation, preflight before bulk variable rename, or to debug why a numeric/font variable doesn't render on a specific page. Read-only |
| `diviops_list_canvases` | List all canvas pages |
| `diviops_get_canvas` | Get canvas content |
| `diviops_scf_status` | Show SCF (Secure Custom Fields) sync status — pending JSON-vs-DB drift across field groups, post types, taxonomies, options pages. Wraps `wp scf json status` |
| `diviops_scf_list_field_groups` | List all SCF/ACF field groups (post_name = ACF key, post_title, post_status, post_modified). Queries the `acf-field-group` post type via `wp post list` (works on SCF 6.8.4+ and older ACF) |
| `diviops_scf_get_field_group` | Fetch a single SCF/ACF field-group post by ACF key (`group_abc123` → post_name) or numeric WP post ID. For the parsed/structured field tree, use `diviops_scf_export --field-groups=<key> --stdout` |

### Write (31)
| Tool | Description |
|------|-------------|
| `diviops_create_page` | Create a new page with optional Divi content |
| `diviops_update_page_content` | Full page content rewrite |
| `diviops_append_section` | Append a section to existing page (start or end) |
| `diviops_replace_section` | Replace a section by admin label |
| `diviops_remove_section` | Remove a section by admin label |
| `diviops_update_module` | Update specific module attributes by label or text match |
| `diviops_move_module` | Move a block before/after another block (reorder modules, sections) |
| `diviops_lock_module` | Lock a module so VB users cannot edit it (frontend renders normally) |
| `diviops_unlock_module` | Unlock a module by removing `attrs.locked` (matches VB's absence convention) |
| `diviops_clone_module` | Deep-copy a module + insert next to source within the same parent |
| `diviops_add_global_color` | Add a new global color to Divi's palette (writes canonical shape; closes ET's bundle Zod gap that drops `label`) |
| `diviops_update_global_color` | Update an existing global color by gcid (only provided fields change) |
| `diviops_delete_global_color` | Delete a global color (refuses if `usedInPosts` non-empty unless `force=true`; customizer-bound defaults always protected) |
| `diviops_preset_cleanup` | Remove spam/duplicate presets, bulk rename |
| `diviops_preset_create` | Write a new preset to the D5 registry (module or group type, supports `divi/column` etc.). Optional `make_default: true` sets it as the bucket's default; optional `priority` controls stack-merge order |
| `diviops_preset_reassign` | Rewrite `modulePreset` references across pages (dry-run by default; optional `strip_inline` removes redundant inline attrs) |
| `diviops_preset_update` | Update a specific preset (name, attrs, priority) |
| `diviops_preset_delete` | Delete a preset by ID. Refuses with HTTP 409 `preset_is_default` when the target is the registered default for its bucket — clear the pointer first via `diviops_preset_set_default` with `unset=true`, or pass `force=true` to delete and clear the pointer in one write |
| `diviops_preset_set_default` | Set or clear the per-module/group default preset. Two modes: by `preset_id` (UUID-addressed; auto-resolves bucket) or by `type` + `module` + `unset=true` (bucket-addressed clear, used to repair orphan default pointers when the UUID is gone from `items[]`). Defaults apply to NEW instances only — use `diviops_preset_reassign` for retroactive swaps |
| `diviops_save_to_library` | Save block markup to Divi Library |
| `diviops_update_tb_layout` | Update a Theme Builder layout's block markup |
| `diviops_create_tb_template` | Create Theme Builder template with header/footer and conditions |
| `diviops_create_variable` | Create a design token variable. For `type=numbers` fluid tokens, pass `min`+`max` shorthand (anchors default to 320px/1920px) or explicit `targets` like `{"320px":"20px","1920px":"60px"}` — server generates arithmetically-correct `clamp()` instead of hand-written math that silently under-reaches the stated max. All-px inputs emit px (root-agnostic). Rem inputs OR rem output require explicit opt-in: pass `output_unit="rem"` (accepts the 1rem=16px default) or `root_font_size_px:N` (declares your site's actual root font-size, e.g. `10` for `html { font-size: 62.5% }`, `20` for `html { font-size: 20px }`) |
| `diviops_create_fluid_system` | Batch-emit a fluid typography + spacing + radius variable set in one call. Mirrors Divi 5.4.0's Variable Generator Modal at the algorithm level (clamp() math is identical to `diviops_create_variable`'s fluid mode) but layers profile-selectable anchors over it: `divi-default` (360→1350) matches ET's defaults, `wide` (320→1920) matches the diviops convention, `custom` takes explicit anchors. Each category is independent and optional. Typography uses modular-scale chains (named ratios `major-third`/`perfect-fifth`/`golden`/etc., or raw numbers) — h1 = largest, hN = base. Spacing/radius support `linear` or `geometric` step distributions. `dry_run: true` returns the full plan without persisting; `overwrite: false` (default) skips existing IDs. Single atomic write to the registry — mid-batch failures roll back cleanly |
| `diviops_delete_variable` | Delete a variable by ID. Returns HTTP 409 when live references exist unless `force=true` (use `diviops_variables_scan_orphans` to find reference locations). Returns HTTP 403 for Divi's customizer-bound defaults (`gcid-primary-color`, `gcid-secondary-color`, `gcid-heading-color`, `gcid-body-color`, `gcid-link-color` — managed via WP Customizer) |
| `diviops_create_canvas` | Create a canvas page |
| `diviops_update_canvas` | Update canvas content |
| `diviops_delete_canvas` | Delete a canvas page |
| `diviops_scf_export` | Export SCF schema (field groups, post types, taxonomies, options pages) as JSON to a directory under the safe-root, or to stdout. Wraps `wp scf json export` |
| `diviops_scf_import` | Import SCF schema from a JSON file (mutates DB; idempotent — existing items are updated). Wraps `wp scf json import <file>` |
| `diviops_scf_sync` | Apply pending JSON-on-disk SCF changes to the DB. Defaults to `dry_run: true` for safety. Wraps `wp scf json sync` |

### Utility (2)
| Tool | Description |
|------|-------------|
| `diviops_wp_cli` | Run WP-CLI commands (allowlisted, requires `WP_PATH` or `WP_CLI_CMD`) |
| `diviops_flush_static_cache` | Flush Divi's compiled CSS cache under `wp-content/et-cache/`. `wp cache flush` does NOT touch these files — the frontend can keep serving stale CSS after mutations. Delegates to Divi's native clearer (`ET_Core_PageResource::remove_static_resources`) when available — also clears Theme Builder / archive / taxonomy / home / notfound CSS, object cache, module features cache, post features cache, dynamic assets cache, Google Fonts cache, post meta caches. Falls back to a filesystem walk of numeric-named subdirs when Divi is inactive. Response includes `backend: "divi_native"` or `"fs_fallback"`. Exactly one selector required: `post_id`, `all`, or `after` (unix ts) — no default to `all` |

## WP-CLI Security

The `diviops_wp_cli` tool validates every command against a safety allowlist before execution. Commands not on the list are rejected.

### Default allowlist (always available)

Read-only commands plus non-destructive writes needed for core MCP functionality and local development workflows:

| Category | Commands |
|----------|----------|
| Options | `option get`, `option list` |
| Posts | `post list`, `post get`, `post create`, `post update` |
| Post meta | `post meta get`, `post meta list`, `post meta set`, `post meta update` |
| Post types | `post-type list`, `post-type get` |
| Taxonomies | `taxonomy list`, `term list`, `term create`, `term update` |
| ACF / SCF | `acf export`, `acf import`, `acf field-group list`, `acf field-group get`, `scf json {status,sync,import,export}` (also aliased as `acf json …` per SCF 6.8.4+) |
| Users | `user list` |
| Cache | `cache flush`, `transient delete`, `rewrite flush` |
| Export | `export` (WXR data export to file) |
| Info | `cron event list`, `plugin list`, `theme list`, `menu list`, `site url` |
| Core (read-only) | `core version`, `core check-update`, `core is-installed`, `core verify-checksums`, `core language list` |
| DB (introspection) | `db columns`, `db size`, `db tables`, `db check`, `db search` |

### Extended commands (opt-in)

These commands carry higher risk and require explicit opt-in via the `DIVIOPS_WP_CLI_ALLOW` environment variable:

| Command | Risk | Why opt-in |
|---------|------|------------|
| `option update` | High | Can change site URL, admin email, or security settings |
| `option delete` | High | Permanently removes a WP option (no undo) |
| `post delete` | Medium | Permanently removes content |
| `post meta delete` | Medium | Removes metadata |
| `term delete` | Medium | Permanently removes taxonomy terms |
| `search-replace` | High | Bulk database modification — can corrupt content if misused |
| `import` | Medium | Bulk content ingestion from WXR files |
| `plugin activate` | Medium | Can enable untrusted plugins |
| `plugin deactivate` | Medium | Can disable security plugins |
| `eval-file` | Critical | Executes arbitrary PHP from a file path |

To enable extended commands, add `DIVIOPS_WP_CLI_ALLOW` to your MCP registration:

```bash
claude mcp add diviops-mcp \
  --env WP_URL=http://your-site.local \
  --env WP_USER=admin \
  --env WP_APP_PASSWORD=xxxx \
  --env "WP_PATH=/path/to/wordpress" \
  --env "DIVIOPS_WP_CLI_ALLOW=option update,post delete,search-replace" \
  -- npx @diviops/mcp-server
```

Only list the specific commands you need. Unknown entries are ignored with a warning.

#### Wildcard / "god-mode" (local dev only)

For trusted local-dev environments where you don't want to re-list every extended command per site, the values `*` and `all` grant the full extended set:

```bash
--env "DIVIOPS_WP_CLI_ALLOW=*"
```

The sentinel grants exactly the extended set above — it does NOT unlock anything beyond it (notably: `db query` stays out by design). The server emits a startup warning to stderr whenever the wildcard is active, so the broad grant is never silent. Auto-adopts new extended commands on future versions.

> **Don't use this in shared or production environments.** Pin the specific commands you need with the comma-separated form instead.

> **Note on `acf import`**: included in the default allowlist because it's an idempotent dev-time schema operation (re-creates field groups from JSON). Bulk content imports use `wp import` instead, which is opt-in.

### Filesystem flag validation

The DEFAULT-tier filesystem commands (`wp export`, `acf export <path>`, `acf import <path>`, `scf json export --dir=<path>`, `scf json import <file>`, plus the `acf json …` aliases) are second-pass validated against a safe root so wrong-path arguments can't write WXR / schema JSON to the web root or read configs from arbitrary locations.

- **Safe root**: `<WP_PATH>/.diviops-tmp/` by default (auto-created on first use in host mode). Override with `DIVIOPS_WP_CLI_SAFE_FS_ROOT=/absolute/path`. All path arguments must canonicalize under this directory; symlinks are resolved via `realpath` so a planted symlink inside the safe root pointing outside it is caught.
- **`wp export` must pass `--dir=<path-under-safe-root>`** (or `--stdout`). Without `--dir`, wp-cli writes to the current working directory; on prod that's typically the web root.
- **`--filename_format=` must be a filename template**, not a path — separators (`/`, `\`) are rejected so a crafted template can't escape `--dir`'s scope.
- **`acf export/import`'s positional path** must resolve under the safe root.
- **`scf json export`'s `--dir=` flag** must resolve under the safe root (or pass `--stdout` for in-memory transfer). **`scf json import`'s positional `<file>` path** must resolve under the safe root.
- **Wrapper mode (`WP_CLI_CMD`)**: the host-derived safe root doesn't correspond to the wrapper's filesystem (e.g., container paths like `/www/app`), so `DIVIOPS_WP_CLI_SAFE_FS_ROOT` is **required** and must be set to the container-namespace path. FS-sensitive commands are rejected with a clear error if it's missing.
- **Escape hatch**: `DIVIOPS_WP_CLI_UNSAFE_FS=1` disables validation entirely. Appropriate for trusted single-user local-dev setups that don't want the guard.

**EXTENDED-tier filesystem commands** (`import`, `eval-file`) are not flag-validated here — opt-in via `DIVIOPS_WP_CLI_ALLOW` signals the caller has accepted the path-scope risk. That constraint is a candidate future enhancement if the MCP server ships in multi-tenant contexts.

## Safety Patterns

High-risk or bulk destructive tools follow one of two conventions to guard against unintended mutation. Both are stateless (no session tokens between calls), but they guard differently: Pattern A is a **stateless gate** — the first call mutates when the safety check passes, refuses with an explanatory error when it fires. Pattern B is **preview-before-commit** — the first call never mutates; an explicit apply step is required. Tools without a gate (e.g., `diviops_update_page_content`) execute their mutation directly — whether to adopt a pattern is a per-tool design decision, not a retrofit requirement.

### Pattern A — `force: false/true` (refuse-with-override)

Tool refuses the operation with an explanatory error when a safety check fails; caller reviews the reason and retries with `force=true` to commit. Single round-trip on the happy path, single retry on the override path.

**Fit criteria:**
- Operation targets a **single item** (one variable, one preset, one page)
- Safety check is **binary** (safe / not safe)
- The reason for blocking is compact enough to fit in the error body (e.g., "3 live references")
- The caller can decide from the error alone — no full diff needed

**Current tools:**
| Tool | Guard | Override |
|------|-------|----------|
| `diviops_delete_variable` | HTTP 409 when live references exist | `force=true` |
| `diviops_preset_delete` | HTTP 409 `preset_is_default` when target is the registered default for its bucket | `force=true` (deletes + clears the `default` pointer in the same write) |

### Pattern B — `mode: "dry-run"/"apply"` (preview-then-commit)

Tool returns a preview of the changes it would make; caller reviews the diff, then re-invokes with the apply flag to commit. Two round-trips by design — the preview itself is the value.

**Fit criteria:**
- Operation touches **many items** (bulk reassign, bulk cleanup)
- The *preview is the value* — caller wants the full list of changes before committing
- Side effects don't fit in a one-line reason (e.g., "142 preset refs across 18 pages rewritten from UUID X to UUID Y")
- Two round-trips are acceptable because the caller is already in review mode

**Current tools:**
| Tool | Preview flag | Commit flag |
|------|--------------|-------------|
| `diviops_preset_reassign` | `mode: "dry-run"` (default) | `mode: "apply"` |
| `diviops_preset_cleanup` | `dry_run: true` (default) | `dry_run: false` |

> Both preview-then-commit tools share the same semantic pattern but use different parameter shapes (`mode` enum vs `dry_run` bool). Both predate this convention and stay as-is for caller compatibility. **New bulk tools should use the enum form** (`mode: "dry-run" | "apply"`) — it's more extensible if future modes are needed (`"interactive"`, `"selective"`, etc.) and keeps the interface consistent as the tool set grows.

### Picking a pattern for a new tool

Ask: **single item or many?** If single, Pattern A. If many, Pattern B.

Don't introduce a third pattern (`confirmation_token`, session-based preview, etc.) unless a tool has a genuine need that neither A nor B covers — both patterns above are stateless and flexible enough for most cases.

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

### Preset edits not visible on the frontend

After `preset_update` / `preset_create`, the preset option is updated immediately but Divi serves frontend CSS from a **per-post static cache** that neither `wp cache flush` nor `wp transient delete --all` invalidates.

Cache location: `wp-content/et-cache/{post_id}/` — contains files like `et-divi-dynamic-tb-*-{post_id}-critical.css` with preset CSS baked in.

To force regeneration for a specific page:
```bash
rm -rf wp-content/et-cache/{post_id}/
```

Next visit re-renders and writes fresh CSS. Applies to: any change that affects preset-derived CSS output (preset_update, preset_create when used by an existing page, preset_reassign in apply mode).

The preset option (`et_divi_builder_global_presets_d5`) always reflects the current MCP-written state — if `wp option get et_divi_builder_global_presets_d5` shows your change but the frontend doesn't, it's this cache.

## License

MIT
