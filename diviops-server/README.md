# DiviOps MCP Server

DiviOps connects an MCP client on your computer to the DiviOps Agent plugin on a WordPress site you manage. You can list pages, inspect presets and edit native Divi modules.

Choose a connection guide for [Claude Code](https://github.com/oaris-dev/diviops/blob/main/SETUP.md#claude-code), [Codex CLI (terminal)](https://github.com/oaris-dev/diviops/blob/main/SETUP.md#codex) or [Claude Desktop](https://github.com/oaris-dev/diviops/blob/main/SETUP.md#claude-desktop-json).

## Quick start

1. On a WordPress 6.5+ site running Divi 5.1+, activate the free [DiviOps Agent plugin](https://wordpress.org/plugins/diviops-agent/). A [manual plugin ZIP](https://github.com/oaris-dev/diviops/raw/main/diviops-agent.zip) is also available.
2. In **Users → Profile → Application Passwords**, create a password for your WordPress editor account. Use HTTPS for a remote site.
3. On the computer running your client, use Node.js 22+ and configure the stdio command below. Set `WP_URL`, `WP_USER` and `WP_APP_PASSWORD` in the client’s private configuration as shown in its connection guide.

   ```sh
   npx -y --package @diviops/mcp-server diviops-mcp
   ```

4. Confirm `WP_URL` points to your intended site and reconnect the client. Ask **“Use `diviops_page_list` to list up to five pages with their IDs and titles.”** A successful read returns the pages your account can inspect. The list can be empty.

Before your first write, follow the [connection check](https://github.com/oaris-dev/diviops/blob/main/SETUP.md#step-5-test-connection) and confirm the target URL matches the site you intend to edit. If you use a known page to check the site, compare its full permalink.

For native page authoring, load the separate `diviops-divi-5` skill in your client, then verify your first draft on a staging or disposable site. [First-run native Divi verification](https://github.com/oaris-dev/diviops/blob/main/SETUP.md#first-run-native-divi-verification)

The published end-to-end authoring check covers one run with Codex CLI 0.160.1, MCP server 1.5.74, a manually installed skill and explicit tool permissions. [Client verification details](https://github.com/oaris-dev/diviops/releases/tag/v1.5.91)

## Use cases

DiviOps fits multiple WordPress workflows where AI-driven authoring + management is the value:

- **Page building (Divi authoring)** — create + edit Divi pages, sections, modules, canvases via prompt; preset-driven design system reuse; Theme Builder layouts and templates.
- **SCF setup + management** — provision Secure Custom Fields field groups, sync schemas, export/import field group definitions; SCF data model becomes a tool surface, not an admin-UI flow.
- **CPT + post population** — register custom post types via wp-cli passthrough; bulk-populate posts and pages across any post type, not just Divi-built ones.
- **Data model reasoning** — schema introspection across Divi modules + SCF field groups + post meta; ask Claude what fields a post type carries, what attributes a module accepts, what tokens are defined.
- **WordPress site auditing** — preset audits, design-token usage scans, orphan detection (presets, variables, dangling references); broader site surveys via wp-cli (`wp option list`, `wp post list --format=json`, `wp post term list <id> <taxonomy> --format=json`, `wp user list`).
- **Hybrid sites (Divi + custom PHP)** — Divi authors the marketing pages; custom PHP templates handle dynamic ones (CPT listings, single-post views, member portals); design tokens harmonized across both surfaces via CSS custom properties driven from the Divi variable system.

## Authoring policy

Use native Divi modules first. Intentional custom HTML is supported when native
Divi cannot meet the agreed requirement; HTML shape is not a semantic refusal gate.
Permissions, schemas, sanitization, bounded parsing, dry-run, required backups and
readback still apply. Ordinary server startup and the seven full-content writes
do not require a browser, trust registration or receipt. Visual QA is separate.

With an unreleased browser-gate plugin snapshot, its refusals remain authoritative:
the migrated server removes private browser handoff data and does not retry or
bypass the refusal. An unreleased browser-gate server snapshot tolerates the
retired registration route on the migrated plugin, but retains its own
startup-attestation overhead. Existing capability checks and
authentication errors are unchanged. Published server 1.5.40 and Free 1.5.13
predate that experiment and did not require browser attestation or receipts.
Source behavior is not a claim that current npm, WordPress.org or manually
installed packages include the migration.

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

The server exposes **102 always-on tools** across the categories below. Each category links to representative tools; the full table lives in [server-reference.md](../docs/server-reference.md).

| Category | Use case | Tool prefixes |
|----------|----------|---------------|
| Page authoring | Create, edit, restructure pages | `page_*`, `section_*`, `module_*` |
| Design system | Manage colors, fonts, variables, presets | `variable_*`, `global_color_*`, `global_font_*`, `preset_*` |
| Library + templates | Reusable layouts + Theme Builder | `library_*`, `template_*`, `tb_*` |
| Native Custom CSS | Read, preview, update and restore named site-wide CSS blocks | `custom_css_*` |
| WordPress menus | Author reusable nav menus and theme-location assignments | `menu_*` |
| Semantic SEO metadata | Inspect provider support; TSF and Rank Math title/description and canonical/noindex with checksum/readback guards | `seo_*` |
| Schema introspection | Module attribute discovery | `schema_*` |
| Canvas / off-canvas | Popups, modals, menus | `canvas_*` |
| SCF integration | Secure Custom Fields sync | `scf_*` |
| Render + validate | Preview HTML, validate block markup | `render_preview`, `validate_blocks` |
| WP-CLI passthrough | Escape hatch for site ops | `meta_wp_cli` |
| Cache + meta | Connection probe, identity, icons, cache flush | `meta_*` |

`diviops_scf_text_value_update` is a Free REST writer, separate
from the existing SCF CLI schema tools. It previews by default and updates one
existing applicable top-level text field on an editable post, with exact expected
value, field validation and persisted value/reference readback. It supports exactly
SCF 6.9.4 and 6.9.5. The 6.9.5 extension shipped in Free 1.5.44 (GitHub v1.5.86).
Separate bounded verification passed the 6.9.4 → 6.9.5 upgrade, one text-field
write, native save/reopen and restoration. It requires
the precise Free plugin capability, not Pro or WP-CLI. Empty/literal-"0", multiline,
markup, nested/conditional fields and schema changes are outside its initial
scope. See the separately supplied DiviOps SCF skill's text-value contract
for exact inputs and failure semantics. No full native-form equivalence, atomic
CAS, SCF snapshot backup or automatic rollback/retry. These provider fixtures do not qualify another runtime. The exact Free 1.5.44
ZIP separately passed its Free-alone package gate; see the
[verification matrix](../docs/provider-support-evidence-29.md).

Use `diviops_meta_info` as the S0 preflight before dogfooding or product work. It returns `server_version`, a numeric `tool_count`, a `tools` catalog summary (`registered_total`, always-on count, Pro possible/registered counts by target), `plugins` version records for `diviops-agent`, `diviops-agent-pro`, FluentCart, and FluentCart Pro when available, plus the existing handshake and slice state. In regular npm/stdio sessions, successfully observed plugin, module, capability, and registered-tool fields are the immutable handshake snapshot that built the session catalog. The additive `startup_capture` record supplies its non-secret timestamp, exact covered evidence, independent-component compatibility rule, and deterministic reconnect guidance. A failed handshake instead reports `startup_unavailable`, a bounded `startup_handshake_failed` reason class, and no covered target evidence. After a supported plugin, theme, or target change, reconnect or restart MCP before treating `meta_info`, the registered tools, or capability gates as current. Live read tools such as `diviops_fc_status` remain separate current observations; they do not rewrite startup registry truth.

Launcher health mode keeps its stricter existing contract: `diviops_meta_ping` and `diviops_meta_info` re-observe model-visible target evidence on every call. The regular stdio `startup_capture` record does not alter launcher behavior, direct npm/stdio compatibility, or tool registration after initialization.

Additional **conditionally-registered Pro tools** appear only on sites that have the Pro plugin (`diviops-agent-pro`) active alongside the target coverage plugin:

| Category | Conditional gate | Tool names |
|----------|------------------|------------|
| FluentCart reads (V1) | Pro plugin + FluentCart installed + module enabled | `diviops_fc_product_list`, `diviops_fc_product_get` |
| FluentCart simple product writes (V2) | Pro plugin + FluentCart installed + module enabled | `diviops_fc_product_create`, `diviops_fc_product_update`, `diviops_fc_product_delete` |
| Optional product-create slug | Existing create tool; supplying `slug` additionally requires Pro capability `fluentcart_product_create_slug` from a fresh startup handshake | Native WordPress normalization/collision handling; dry-run reports intent without reservation, apply reads stored `product.slug`. Omission stays compatible; changed product titles still regenerate slugs. No slug-update/redirect support. |
| FluentCart variation read/write (V3) | Pro plugin + FluentCart installed + module enabled | `diviops_fc_variation_list`, `diviops_fc_variation_update` |
| FluentCart license-settings read/write, incl. update-file pointer + readiness (V3/V3.3) | Pro plugin + FluentCart Pro installed + module enabled | `diviops_fc_license_settings_get`, `diviops_fc_license_settings_update` |
| FluentCart managed downloads + license changelog (V3.4) | Pro plugin + FluentCart installed + module enabled | `diviops_fc_download_list`, `diviops_fc_download_attach`, `diviops_fc_license_changelog_get`, `diviops_fc_license_changelog_update` |
| FluentCart order readback + guarded mark-paid (V3.1) | Pro plugin + FluentCart installed + module enabled | `diviops_fc_order_list`, `diviops_fc_order_get`, `diviops_fc_order_mark_paid` |
| FluentCart license readback (V3.1) | Pro plugin + FluentCart Pro installed + module enabled | `diviops_fc_license_list`, `diviops_fc_license_get`, `diviops_fc_license_activations_list` |
| FluentCart checkout readiness / gateway inspection (V3.2) | Pro plugin + FluentCart installed + module enabled | `diviops_fc_status`, `diviops_fc_gateway_list`, `diviops_fc_gateway_get` |
| Cross-env reviewed layout rollout | Pro plugin + `cross_env` module enabled | `diviops_cross_env_header_apply`, `diviops_cross_env_layout_apply` |
| Managed recovery Phase 1A | Pro plugin + opt-in `managed_recovery` module enabled | `diviops_managed_recovery_policy_get`, `diviops_managed_recovery_policy_preview`, `diviops_managed_recovery_policy_update`, `diviops_managed_recovery_retention_preview`, `diviops_managed_recovery_retention_apply`, `diviops_managed_recovery_audit_list` |
| Managed recovery Phase 1B | Same opt-in module + Free protected restore service + `managed_recovery_plan_v1` | `diviops_managed_recovery_plan_check`, `diviops_managed_recovery_restore_apply` |

When the gates are not satisfied, the tools simply don't appear on the MCP surface — no error envelope, no missing-capability hint. See the `diviops-fluentcart` skill bundle for the operator-side guide.

See [server-reference.md](../docs/server-reference.md) for per-tool descriptions.

## Native preset composition

`diviops_preset_create` and `diviops_preset_update` accept optional `group_presets`
for **module presets only**, stored as native root `groupPresets`, never in attrs:

```json
{"group_presets":{"title.decoration.font":{"presetId":["existing-font-id"],"groupName":"divi/font"}}}
```

Omit it to preserve existing links; `{}` replaces them with an empty map. Each
native slot binding requires a nonempty string-array `presetId` and `groupName`,
with optional nonnegative integer `segmentBoundary`. Slot/group compatibility
and target existence in that group bucket are checked before writing, also in
dry-run. No defaults or IDs are substituted. `attrs.groupPresets` rejects with
a corrective hint. New composition calls require `preset_composition_v1`;
older/unverified plugins refuse before dispatch, while omitted-link calls keep
their existing behavior. Reconnect after updating a supported plugin.
`diviops_preset_inspect` exposes the outgoing root map without resolving inherited
styles or broadening its partial consumer coverage. Preview writes nothing and
allocates no UUID; native Builder/rendering qualification remains separate.

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
The CLI supports `tb_header_layout`, `tb_footer_layout`, and the bounded
staff-detail `tb_body_layout` recipe described below, preflighted against existing
same-kind targets. Header-only inputs retain the
shipped header-v1 report/fingerprint; footer and supported body inputs use the generic layout-v1
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
Body evidence requires `cross_env_staff_body_evidence`; body apply additionally
requires `cross_env_staff_body_apply` alongside the existing Pro/module gates.
The existing footer/generic-apply flags alone do not authorize body promotion.

The body exception is limited to the existing `diviops_staff` recipe: native
current-post title, featured image, Post Content and the unchanged
`custom_meta_diviops_staff_role` binding with raw HTML off. The target must have
the registered CPT, one active applicable top-level text field named
`diviops_staff_role` with key `field_diviops_staff_role`, and one enabled,
non-default existing body assignment for all singular staff posts, with no
exclusions or conflicting same-condition assignment. Source/proposed content
must match this recipe; the existing target body can be replaced without already
containing it. Field definitions, target staff records and assignments are not
changed. Other CPTs/selectors, fixed-post/loop contexts, missing or ambiguous
fields, and prerequisite drift refuse. There is no general body migration or
field mapping. Source and target exports include a `staff_body` proof with
versioned evidence and a SHA-256 digest; the generic body confirmation binds both.
The field group must contain a standalone `post_type == diviops_staff` location
branch; other OR branches are allowed and remain part of the reviewed evidence.
Current prerequisite evidence is bound into confirmation and
independently recomputed by the target apply route. Source tests do not certify
a live cross-site rollout or authorize installation/release.

For this body recipe, `diviops_cross_env_layout_apply` accepts `dry_run: true`
with the reviewed fingerprint and confirmation to validate without mutation.
That option refuses non-body requests rather than silently applying them.
A real body write retains a snapshot through the existing Free recovery store;
dry-run and already-converged requests create no snapshot.

To collect the source JSON from the source WordPress site, call the Free/core
read-only MCP tool `diviops_cross_env_source_export_get` and save the returned
`data` object as `source.json`. The export includes the source origin, same-kind
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
For generic layout preflight it also includes exact target post type and
a canonical template-linkage digest over the active Theme Builder master ID,
its exact `_et_template` order, and linked-template slot, enabled, condition,
and exclusion evidence. Header/footer empty linkage uses `master_template_ids: []`
and `links: []`; bounded staff bodies require the isolated assignment above.
Assignment state is evidence only and is never mutated by rollout.

Workflow:

1. Call `diviops_cross_env_source_export_get` on the source site and save the
   returned `data` object as `source.json`.
2. Call `diviops_cross_env_target_context_get` on the target site and save the
   returned `data` object as `target.json`. An absent menu ID or empty desktop value in a header
   or footer uses the destination's primary menu location. For explicit menu IDs,
   pass the names from `source.json`'s `menus` as `source_menu_names`. Review the
   source and destination term IDs and match method. If a name has zero or
   multiple matches, inspect the menus, repeat the source export with explicit
   `menu_targets` (for example `{"2":3}`), and pass those destination IDs as
   `target_menu_ids` when collecting fresh target context.
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

Preset deletion requires `preset_delete_exact_v1` for real dry-run or guarded `preset_ids` (1-200 exact IDs). Preview returns a registry checksum; exact-set apply requires it and rechecks defaults/references across all post content, postmeta and canonical preset definitions. Incomplete evidence refuses, and force cannot bypass exact-set checks. Legacy `preset_id` remains default-guard-only, with its existing force override. MCP never forwards new safety parameters to old or capability-unverified handlers, which could otherwise ignore `dry_run` and delete. See [coverage, limits and nontransactional semantics](../docs/safety-patterns.md#exact-set-preset-deletion).

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

`diviops_seo_metadata_update` accepts fixed semantic fields and requires the
checksum from `diviops_seo_metadata_get`. TSF retains its title/description
set/clear contract and request-local rollback. The Rank Math
adapter supports exactly 1.0.279 and 1.0.280 and adds `canonical_url` set/clear and `noindex` set with the string
value `noindex`, on pages only. It requires per-page edit permission and Rank
Math general/advanced metadata capabilities. Other robots writes are refused.

Preview with `dry_run:true`. Rank Math noindex preserves other directives;
when those directives were inherited, the preview explains that the local
robots array will pin them. A computed canonical is not proof of an emitted
tag: Rank Math suppresses canonical output under noindex. Frontend filters and
query context require separate verification.

Every non-noop Rank Math apply saves a private typed recovery record and returns
`snapshot_id`. To preview or apply restoration, call the same update tool with
`restore_snapshot_id`, a fresh `expected_checksum`, and no `changes`. Restore
checks the provider/version/page and current permissions, refuses third-party
changes to affected fields, and preserves later changes to untouched fields.
Records use non-autoloaded options, have no automatic expiry and remain until
operator cleanup; they are not listed or restored by the page-content snapshot
tools. A failed or uncertain response can include applied changes: retain its
snapshot ID and inspect state rather than retrying blindly. Provider sitemap
invalidation does not establish external cache eviction.

TSF 5.1.4 additionally supports page `canonical_url` set/clear and `noindex`
set with `noindex` or `reset_default` without a value. Use the additive
`indexing.checksum` from a fresh read for these operations and their typed
`restore_snapshot_id`; retain the top-level checksum for title/description.
Do not mix text and indexing changes. Reset resumes provider inheritance;
it does not force indexing. Other robots fields keep their inherited state.
TSF retains a custom canonical under noindex and suppresses generated fallback.
Homepage canonical settings can override a page value. Typed recovery records
have no automatic expiry and require operator cleanup.

Historical indexing qualification used MCP 1.5.71 with Free Agent 1.5.42. Native Classic
editor save/reopen, frontend canonical/robots, primed sitemap exclusion and
restoration, defaults drift and permission refusal passed on WordPress 7.1,
PHP 8.3 and Divi 5.13.1 for Rank Math 1.0.279 and TSF 5.1.4. TSF additionally
passed exact empty/zero/force-index restore and homepage/scheme checks. Block
editor, other provider versions, external cache eviction and MCP stdio runtime
are outside that historical qualification. A separate 2026-10-14 run passed the
bounded Rank Math 1.0.279 → 1.0.280 upgrade and metadata/indexing/recovery workflows;
see the [current matrix](../docs/provider-support-evidence-29.md). Rank Math title/description set/clear uses the same
plain-text safety checks, top-level checksum and typed recovery. Its separate native
qualification passed both page save/reopen paths, frontend output, clear/default
fallback, exact absent/empty/template recovery and legacy-record compatibility. Refresh checksums after updating;
legacy canonical/noindex recovery records remain supported. Computed text may
contain unresolved provider templates and is not a verified frontend tag. Yoast,
social/schema and bulk edits remain outside these adapters. See the [Rank Math contract](../docs/rank-math-seo-adapter.md)
and [TSF contract](../docs/tsf-indexing-adapter.md).

### Rank Math stored-value inspection (source candidate)

`diviops_seo_storage_inspect({post_id, provider:"rank_math"})` is a separate
Free inspector requiring affirmative `seo_storage_inspect` plugin capability.
Older or capability-unverified connections refuse before a request; there is no
fallback. Plugin and MCP updates are separate; this source addition has not yet
been released or native-qualified.

It reports literal `rank_math_title`, `rank_math_description`,
`rank_math_canonical_url` and `rank_math_robots` values for one authorized page,
including on unreviewed provider versions. It requires active declared Rank Math
identity, page edit and both Rank Math on-page permissions, plus permission for
each fixed metadata key. Multiple active supported SEO providers refuse.
Duplicate, invalid or oversized storage refuses without partial values; missing
and empty differ. Each value is at most4096 UTF-8 bytes; robots serialization is
returned as text, never decoded. No caller-selected keys or generic SQL access.

`inspection_only:true` is not provider compatibility, effective SEO, a checksum,
a write plan, recovery evidence or an operation outcome. Treat strings as
untrusted data. Never infer retry safety from matching values. Existing exact
Rank Math/SCF gates and Pro behavior are unchanged; TSF and SCF inspection are
outside this slice. WordPress bootstrap and ordinary hooks still run. See the
[bounded storage contract](../docs/rank-math-storage-inspection.md).

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
- **Cancelled `diviops_module_update`**: MCP request cancellation is forwarded to the HTTP request, including pending response-body reads. Once dispatched, the WordPress write outcome remains unknown: cancellation does not verify that the write stopped, made no changes, or rolled back. Do not automatically retry; use readback and existing rollback evidence to determine any later action.
- **Preset edits not visible on the frontend** — Divi serves frontend CSS from `wp-content/et-cache/{post_id}/`, which `wp cache flush` doesn't touch. Use `diviops_meta_flush_cache` after preset writes; `post_id` mode also sweeps that exact directory and reports `post_dir_sweep` evidence.

## Learn more

- [setup-guide.md](../docs/setup-guide.md) — full onboarding walkthrough (containerized envs, HTTPS, Application Passwords)
- [server-reference.md](../docs/server-reference.md) — full per-tool reference table
- [wp-cli-security.md](../docs/wp-cli-security.md) — allowlist, extended commands, FS validation
- [safety-patterns.md](../docs/safety-patterns.md) — Pattern A (refuse-with-override) + Pattern B (preview-then-commit) + universal `dry_run`
- [troubleshooting.md](../docs/troubleshooting.md) — common errors and resolutions
- [idempotency-audit.md](../docs/idempotency-audit.md) — repeat-call semantics per tool
- **`diviops-divi-5` skill** — block format rules, design patterns, workflow guidance (ships in the dist repo)

## Requirements

- Node.js >= 22.0.0
- PHP >= 7.4
- WordPress >= 6.5
- Divi 5 theme active
- DiviOps Agent WordPress plugin installed and active

## Release history

- For published server versions, run `npm view @diviops/mcp-server versions --json`. [npm registry metadata](https://registry.npmjs.org/@diviops%2fmcp-server)
- For plugin and skill updates shipped alongside the server, see the [public distribution releases](https://github.com/oaris-dev/diviops/releases).
- The [archive through MCP 1.5.77](https://github.com/oaris-dev/diviops/blob/v1.5.95/diviops-server/README.md#mcp-1577-release-note) preserves the original version notes and their qualification limits. The 1.5.67 note refers to the [WP-CLI command tiers](https://github.com/oaris-dev/diviops/blob/main/SETUP.md#wp-cli-security).

### MCP 1.5.80 release note

Pair with Agent Pro 1.0.20-beta to review navigation menus when transferring an existing Theme Builder header or footer between sites. The plan matches explicit menus by a unique name or a destination you select. It identifies menus that follow the destination’s primary theme location. Apply refuses an explicit selection when either side cannot verify the reviewed mapping.

### MCP 1.5.79 release note

The package page now opens with client setup guides and a first connection check that lists up to five page IDs and titles. Existing installations need no configuration change.

### MCP 1.5.78 release note

Pair with DiviOps Agent 1.5.52 for guarded canvas content replacement. Read the canvas post ID with `diviops_page_get` and reuse its `content_checksum` as `expected_checksum` on dry run and apply. The guard requires Agent support. A concurrent write can still occur after comparison.

Icon lookup searches bundled Divi and Font Awesome icons. For a Theme Builder edit, inspect the published templates that reference the layout ID and reuse the reviewed layout checksum on apply.

## License

MIT
