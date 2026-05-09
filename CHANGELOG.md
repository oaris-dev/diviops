# Changelog

## v1.5.6 — variable_delete envelope parity + flexType skill correction + flextype_wrong_path validator

Three defensive fixes ship together as a bundled release.

`diviops_variable_delete`'s conflict envelope (returned when you try to delete a variable that has live references) now carries the same `scan_truncated` + `scanned_posts` diagnostic fields that `diviops_global_color_delete` already emitted. Both tools use the same underlying scanner; the fields were always computed, just dropped before the envelope.

The skill documentation for column `flexType` was wrong — the cited attribute path (`module.decoration.layout.desktop.value.flexType`) doesn't exist in Divi's schema. The correct path is `module.decoration.sizing.desktop.value.flexType`. The skill also didn't tell you that column `flexType` is a no-op unless the parent row has `display: "flex"` — Divi has two column-rendering pipelines, and only the flex one honors the 24-unit grid. Both gaps are now closed in the skill.

A new `flextype_wrong_path` validator surfaces the wrong-path write at validation time so you don't have to discover it from a broken front-end. Plugin bumped to 1.4.5.

## v1.5.5 — `global_color_delete` ref-count fix + `canvas_create` uniqueness

`diviops_global_color_delete` now uses a `parse_blocks` scan over `post_content` (pages, TB layouts, library, canvas, preset registry) to detect references — the same path `diviops_variable_delete` has always used. Previous releases consulted Divi's internal `usedInPosts` index, which is only updated on Visual Builder save; MCP-authored content writing valid `$variable(gcid-...)$` tokens silently reported `0 refs`, allowing deletes that broke consuming pages.

Conflict envelope on a referenced color now matches `variable_delete`: `error.data = { id, ref_count, locations[], scan_truncated, scanned_posts }`.

`diviops_canvas_create` now returns `conflict` (HTTP 409) when a canvas with the same title already exists on the same parent page, with `error.data = { existing_canvas_id, parent_page_id, title }`. Previously created silent duplicates on retry. Matches `diviops_preset_create`'s contract.

Plugin bumped to 1.4.3.

## v1.5.4 — `_meta.idempotent` on every response

Every DiviOps tool response now carries `_meta.idempotent: "true" | "false" | "conditional"`. Lets consumers writing retry logic read the contract directly from the response instead of a separate `tools/list` lookup. The audit table in `docs/idempotency-audit.md` is unchanged; this release wires the runtime mirror.

No breaking changes. Existing scripts that don't read `_meta` see nothing different.

## v1.5.3 — critical packaging fix

`diviops-agent.zip` now contains the `includes/` directory it always should have. Earlier 1.x releases shipped a broken zip that fatal-errored on plugin activation because the packaging script wasn't copying the trait files into the distribution tree. Plugin source is unchanged; this is a distribution fix.

If you've been hitting "Plugin could not be activated because it triggered a fatal error" trying to install diviops-agent.zip, v1.5.3 fixes it. Re-download the zip and re-upload.

Thanks to the reporters in [oaris-dev/diviops#2](https://github.com/oaris-dev/diviops/issues/2) and [oaris-dev/diviops#3](https://github.com/oaris-dev/diviops/issues/3).

## v1.5.2 — v1.5 adoption-readiness ship

The v1.5 series (1.5.0 + 1.5.1 + 1.5.2) bundles into a single release: discovery-first README refactor, envelope-contract section, broadened hero positioning ("AI-driven WordPress site authoring — Divi-native, with the rest of WP in scope"), a six-category Use cases section in the server README, and two small functional improvements landing in 1.5.2:

- `wp plugin update` is now in the default `diviops_meta_wp_cli` allowlist. Plugin maintenance updates from authenticated sources no longer require `DIVIOPS_WP_CLI_ALLOW`. `plugin activate` / `plugin deactivate` stay extended-tier (opt-in).
- `scripts/regen-module-formats.mjs` reports a friendly error message when `scripts/tiers.json` is malformed (names the file, surfaces the JSON syntax problem, lists common errors) instead of Node's bare `SyntaxError`.

No breaking changes. Tool count and response contract unchanged from v1.4.1.

## v1.5.1 — broadened positioning + suite use cases

Server and plugin READMEs broaden the suite's positioning from "AI-driven page building for Divi 5 sites" to "AI-driven WordPress site authoring — Divi-native, with the rest of WP in scope." A new "Use cases" section in the server README surfaces six workflow categories already supported by the existing tools: page building (Divi authoring), SCF setup + management, CPT + post population, data model reasoning, WordPress site auditing, and hybrid Divi-plus-PHP sites. The plugin README's Capabilities section mirrors the same six categories.

Doc-only release. Tool count and response contract unchanged. Plugin v1.4.1 ships alongside; no plugin behavior change.

## v1.5.0 — adoption-readiness: discovery-first README

Server and plugin READMEs refactored for new-user onboarding. The README now leads with positioning + a 3-step quick start, groups the 66 tools by use case rather than a flat read/write dump, and includes a customer-facing summary of the response contract (error envelope, `dry_run` plan shape, idempotency markers) that v1.4 introduced.

Deep-dive content (full tool reference, WP-CLI security, safety patterns, troubleshooting) now lives in `/docs/` with cross-links throughout. No behavioral changes — this release re-presents the existing contract.

Plugin v1.4.0 ships alongside; no plugin behavior change.

## v1.4.1 — uniform error envelope

All tools now return a consistent envelope shape — `{ ok, data?, error: { code,
message, hint, data? } }` — across success and failure paths. Standardized error
codes (`not_found`, `invalid_input`, `wp_error`, `divi_error`, `capability_missing`,
`conflict`) make it possible to write generic error handlers across the entire
tool surface.

Plugin v1.3.5+ required for full envelope coverage.

