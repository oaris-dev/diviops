---
name: diviops
description: DiviOps harness primer — error envelope, capability handshake, dry-run plan shape, idempotency contract. Used as a shared primer by DiviOps target-coverage skills.
metadata:
  author: oaris-dev
  version: "1.0"
---

# DiviOps harness primer

Cross-cutting conventions every DiviOps target-coverage skill (`divi-5-builder`, `diviops-scf`, `diviops-fluentcart`, future slices) relies on. Read this when you're about to issue a `diviops_*` MCP call and want to know what the success / failure envelope looks like, how to preview a write, or which capability gate decides whether a tool is even registered for this session.

This skill carries no tool-surface documentation — tools live with the coverage slice that owns them.

## Response envelope

Every `diviops_*` tool returns the same envelope:

```jsonc
// Success
{ "ok": true, "data": <payload> }
// Failure
{ "ok": false, "error": { "code": "<code>", "message": "<human>", "hint": "<optional>", "data": <optional structured detail> } }
```

The envelope is uniform across every namespace (`page_*`, `module_*`, `preset_*`, `variable_*`, `global_color_*`, `global_font_*`, `tb_*`, `template_*`, `library_*`, `canvas_*`, `meta_*`, `schema_*`, `validate_*`, `render_*`, `scf_*`, `section_*`). Callers branch on `error.code` for failure paths; the `error.data` field carries structured detail when the failure mode needs more than a code + message.

## Standard error codes

```
not_found            404  Target ID does not resolve
invalid_input        400  Schema violation, malformed args
validation_failed    400  validate_blocks-detected shape error
conflict             409  Uniqueness collision (delete-with-references, default-preset delete, name collision, …)
capability_missing   412  Connected plugin does not advertise the required capability
forbidden            403  Row-level WordPress permission denied
wp_error             500  Underlying WordPress error
divi_error           500  Divi-specific error (block parser, validator, …)
```

`capability_missing` is the handshake-layer signal — the plugin on this site doesn't carry the capability flag the tool requires. MCP server and WordPress plugin versions are independent; install a compatible plugin component from the same DiviOps suite release or a newer supported component, then reconnect or restart the MCP session to refresh the handshake. Distinct from `forbidden` (the WP user lacks the capability on this row). Do not infer an exact required plugin version unless authoritative release-manifest evidence is actually supplied.

## Namespace-prefixed error codes

The convention is `<namespace>.<reason>` for codes that are scoped to a specific namespace and carry namespace-specific structured detail. Two flavors:

- **Gate codes** — `<ns>.not_configured`, `<ns>.capability_missing`. Pre-execution rejection by env-var configuration or handshake state.
- **Runtime codes** — `<ns>.command_failed`, `<ns>.bucket_mismatch`, `<ns>.fluid_generation_failed`, `<ns>.customizer_default_immutable`, etc. Failures that happen once the tool has begun executing.

The split matters because gate failures are environment problems (config missing, capability not advertised) — the user fixes them site-side. Runtime failures are operation problems (wp-cli exited non-zero, input shape was syntactically valid but algorithmically impossible, target row exists but is protected) — the user fixes them per-call.

Codifying `<ns>.not_configured` (gate) + `<ns>.command_failed` (runtime) as distinct codes — rather than collapsing onto `wp_error` or `capability_missing` — is the discipline. Callers branch on whether they need to surface a setup hint or a per-call recovery hint.

## Capability handshake

On MCP session start the server pings the plugin and receives a handshake payload that names which target plugins are present, which modules the user has activated, and which per-tool capabilities the plugin advertises. The server uses this to (a) gate tool registration so unsupported tools don't appear at all, and (b) gate coverage-slice skill activation so the right slice (Divi page authoring vs SCF vs FluentCart vs …) routes.

Three layers must align for a coverage slice's tools to be live in the session:

1. **Target presence** — the target plugin (Divi 5, SCF, FluentCart, …) is installed on the WP site. Detected via `class_exists()` or similar; reflected in the handshake's `available_targets`.
2. **Module activation** — the user enabled the target's module in WP admin's Modules settings page. Reflected in the handshake's `active_modules`.
3. **Project preference** — the user has not opted out of this slice for the current project on their machine. Persisted client-side; storage shape resolves during Phase B implementation.

If any layer is false, the slice declines activation even when the user's prompt matches its description. The harness primer skill (this one) does NOT have layer 1 or 2 gating — the primer's contract content is cross-cutting and load-bearing for every coverage slice's correctness, so it activates whenever any DiviOps task is detected.

The handshake does NOT carry license state. License is implicit: if the skill files are on disk, the user got them through the licensed distribution channel; if the Pro plugin is installed and active, the user got it through the licensed download.

## `dry_run` plan shape

Every mutating tool accepts an optional `dry_run: boolean` (default `false`). Read tools don't — they're already side-effect-free.

When `dry_run: true`, the tool does not mutate state. The success envelope carries a uniform plan:

```jsonc
{
  "ok": true,
  "data": {
    "dry_run": true,
    "plan": {
      "summary": "<one-line human description>",
      "changes": [
        { "kind": "<namespace>.<verb>", "target": "<resource identifier>", "before": <value|null>, "after": <value|null> }
      ],
      "warnings": [ "<optional caveat>" ]
    }
  }
}
```

Apply mode (`dry_run` omitted or `false`) keeps each tool's namespace-specific success-payload shape unchanged.

**Exceptions** — two passthrough surfaces do not accept `dry_run`:

- `diviops_meta_wp_cli` — raw passthrough; use explicit read-only commands instead.
- `diviops_scf_import` — SCF's upstream `wp scf json import` lacks a `--dry-run` flag; use `diviops_scf_sync --dry_run` for SCF-on-disk previews.

`diviops_scf_sync` flows `dry_run` through to wp-cli's `--dry-run` flag — the resulting preview is wp-cli's plain-text summary, NOT the standardized `data.plan = { summary, changes[] }` shape above. This divergence is by design because it reports the upstream SCF sync preview.

Plugin-routed mutating tools should use the standard plan slot. Some tools also preserve route-specific diagnostics as sibling keys beside `dry_run` and `plan`, but callers should branch first on `data.plan.summary`, `data.plan.changes`, and optional `data.plan.warnings`.

## Idempotency conventions

A tool is idempotent when running it twice produces the same observable state as running it once. DiviOps tools document idempotency per-tool; the conventions:

- **`_meta.idempotent: true`** on the success payload signals a write whose repeat call would be a no-op (or a side-effect-equivalent re-apply). Documented per-tool in the tool's MCP description.
- **`annotations.idempotentHint: true`** on the tool registration is the lighter signal — declares the tool is intended idempotent without machine-checked guarantees. Useful for callers that want to retry safely without re-confirming.
- **Destructive ops on already-destructed targets** return `ok: true` with `data.already_<state>: true`, NOT `409 conflict`. `page_trash` on an already-trashed page returns `{ ok: true, data: { already_trashed: true } }`; `tb_template_trash` default mode is similarly silent-success on already-clean. The signal is preserved in the `already_<state>` flag for callers that need it. Repeat-safe semantics matter for AI agent retries.
- **Same-status no-op updates** return `ok: true` with `data.noop: true`. `page_update_status` against a post already in the target status, for example.
- **`force=true` override** — for `*_delete` tools whose default mode refuses on live-reference conflicts (`variable_delete`, `global_color_delete`, `global_font_delete`, `preset_delete`), passing `force=true` clears the conflict and proceeds. Orphan refs remain; run the corresponding `*_scan_orphans` tool to clean up afterward.

These conventions are documentation discipline, not runtime enforcement. The plugin doesn't reject a non-idempotent retry; it just guarantees the documented shape when retry is safe.

## When you'd reach for this primer

- A `diviops_*` tool returned `{ ok: false, error: { code: "scf.not_configured", … } }` and you want to know whether to surface a setup hint or a per-call recovery hint → gate code, setup-side fix.
- You're about to call a write tool and want to confirm the change before committing → pass `dry_run: true`, inspect the plan, then re-call without it.
- Two layers above MCP, an agent retries a `page_trash` call after the network blip and you want to know whether the retry will throw → idempotent on already-trashed targets, returns `already_trashed: true`.
- A coverage-slice skill mentions "the standardized envelope" and you want the canonical shape → this file is the source.

When you need the actual tool list for a specific target, route to the coverage-slice skill (`divi-5-builder` for Divi page authoring, `diviops-scf` for SCF data ops, future slices for future targets). The primer is the contract; the slice is the surface.
