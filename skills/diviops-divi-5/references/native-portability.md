# Native Portability Workflow

Use for an authorized native Divi page or Theme Builder package import and its
specified destination setup. Preserve the exact package identity/version and
standing authorization: target, files, replacement options, settings, publication
limits and stopping conditions. This note does not authorize another target,
reimport, cleanup, shared-preset repair or broader site changes.

## Import Readiness and Handoff

1. Confirm the intended destination and authenticated native importer are ready.
   Match JSON context to the importer: `et_builder` page JSON belongs in the
   intended page's Visual Builder portability dialog, not the Library collection
   importer; `et_theme_builder` belongs in Theme Builder portability. Confirm the
   destination page/template and scoped preset/replacement/assignment options.
2. Identify the exact approved file, using its package manifest/hash when available,
   and confirm a supported upload mechanism can access it. An `invalid_nonce`,
   denied upload path or no-file validation is a prerequisite failure, not proof
   of a corrupt export. Follow the [vendor authentication boundary](tools.md#divi-5121-vendor-boundary-source-proven);
   do not bypass authentication or file-access restrictions.
3. If the operator must select the file, name that exact file and ready dialog.
   Selection is a mechanical handoff, not a fresh permission request. Once the
   correct file is selected, continue import/save/readback within standing approval;
   do not expand scope or ask for the same approval again. If supported selection
   remains unavailable, stop on that prerequisite without submitting an empty import.
4. Record completion per file and destination before moving on, including native
   save and persisted-content readback. Do not replay a completed import or setup
   step merely because a handoff occurred. If a request's result is uncertain, read back destination state before
   considering a retry; if still uncertain, stop and report it. A known failure still
   follows the task's stopping conditions, not an automatic retry loop.

## Preset Inspection Is Three Separate Checks

- **Explicit references:** resolve actual imported `modulePreset` and `groupPreset`
  identities in the destination, including type/slot and styles. Do not assume a
  source UUID survives unchanged or proves the destination's effective style.
- **Effective styled defaults:** inspect what destination default tokens/omitted
  assignments resolve to, separately from explicit references. An imported styled
  preset can exist without becoming the destination default. Resolving every UUID
  does not prove fidelity; preserve intentional neutral defaults and local overrides.
- **Repeated display names:** names/prefixes alone do not establish equivalence or
  justify merging, deleting or renaming presets. Do not infer the cause of repeated
  names or silently run deduplication, normalization or a broader resolver.

Reuse [default references](presets.md#module-level-presets), the
[cascade](presets.md#cascade-order) and the
[identity/consumption convention](presets.md#when-to-use-presets-vs-inline-styles).
Preserve authored empty-array reset semantics: the recorded Divi 5.12.1 Text
`content.decoration.bodyFont.link.font.desktop.value.style: []` was not equivalent
to omission. Native normalization/merge on the reused destination did not restore
that reset from the candidate export. Do not strip it as noise or assume another
import repairs it; report the gap for a separately scoped semantic decision.

## Destination Setup and Saved-State Readback

1. Resolve actual destination page/menu IDs and links; source IDs are evidence,
   never values to force. Save/read back the header Menu module's selected menu,
   Theme Builder enablement/assignments and linked header/footer/body layouts.
2. Read WordPress Reading settings and apply only the required front-page/Posts-page
   choices within scope. Cybersecurity's Blog was an ordinary Divi page with a Blog
   module, Home the static front page and Posts page unassigned. That is a bounded
   package example, not a rule for every blog; do not publish pages without authority.
3. Apply explicitly required design settings through the native controls and read
   back committed values, not just text visible in an input. In the recorded
   Cybersecurity Divi 5.12.1 Customizer flow, color text needed Enter, then Publish,
   then saved-value verification; this is not a universal cross-version gesture.
   Hidden legacy header controls are not a reason to populate dormant settings.
4. Verify referenced global-color identities and values, not merely matching
   swatches. Preserve package/global-color identities and bindings; native creation
   of a lookalike swatch may mint a different ID. Record missing identities without
   silent substitution. Do not copy internal database options, migration/cache flags
   or bookkeeping to recreate the source site.

Report import completion, saved settings and visual checks separately. Perform only
authorized checks; unperformed checks remain pending. If a screenshot conflicts
with saved color bindings, keep the cause unresolved until rendered styles are
inspected within scope. Neither the saved binding nor a successful import alone
proves contrast, cross-version compatibility or publication readiness.

## Evidence Provenance

Derived from the Cybersecurity native portability pilot on WordPress 7.1 / Divi
5.12.1, September 12-15, 2026. Import/default-reset and recipient-setup observations
came from distinct pilot stages, including a reused target; they are not a general
fresh-target or cross-version guarantee. This guidance adds no runtime verification.
