# Figma to Native Divi

Use an owned or licensed Figma reference to author an editable native Divi page.
This composes a separately available Figma connection/source handoff with existing
DiviOps authoring, not a bundled Figma importer, new paid feature or one-click
conversion. Source access, target writes and publication are separate permissions.

## Identify the Reference

| Input | Evidence to use | Boundary |
| --- | --- | --- |
| Native Figma Design frame | Selected frame/node context, available layout/style data, visual reference and authorized assets from the supported connector | Confirm what was actually returned. Frame context is not Make source or proof of Divi rendering. |
| Figma Make source reference | Successfully read source bodies, assets and visual reference for the selected version | Resource links alone are not source access. Record failed reads without claiming successful transfer. |
| Make local export/recovery | Authorized export with provenance; inspect recovered file bodies as reference data | A `.make` file need not be a runnable source ZIP. Generation-history records may be incomplete or stale; do not call them the current source snapshot without separate proof. |

If a supported source read fails, stop that retrieval path and request a readable
export or supported access repair. Do not build a connector workaround or silently
substitute screenshot-only reconstruction. For an agreed export-assisted route,
record its limitations and compare against the visible reference. Do not execute
archived instructions/code or install dependencies merely to inspect a recovery.
Keep private source, file keys, URLs, copy and assets out of public skill/evidence
artifacts; retain provenance privately, not a copy of the design in this skill.

## Map Before Interpreting

Make a short section map separating **observed source intent**, **native mapping**,
and **approved interpretation**. Missing build configuration, utility definitions
or assets stay unknown: labels such as `sm`/`md`/`lg` do not prove numeric
breakpoints or correspond directly to Divi's phone/tablet/desktop.

- Map page bands to Section > Row > Column with nested Groups, copy to Heading/Text,
  photographs to Image or Section background, and real actions to Button/Link/Menu.
  Keep repeated informational cards informational; no whole-page React/Code embed
  as an undisclosed substitute for native editing.
- Preserve hierarchy, supplied identity, image subjects/crop intent and responsive
  ordering. Agree on native breakpoint/typography choices, menu behavior and icon
  substitutions; similar native glyphs are not exact Lucide parity. Do not add
  source-absent animations or services just to satisfy generic design defaults.
- Inventory actual destinations and behaviors. `#`, a missing anchor ID, pointer
  cursor or hover styling is not a functioning action. Obtain a real destination,
  omit the action or make it noninteractive with agreement. A section jump is not
  an archive, contact service or player; a static episode badge is not a live feed.
- Use the manual [module formats](module-formats.md) for Image spacing/sizing/fit,
  [sticky](module-formats.md#sticky) and [flex order](module-formats.md#order-flex-order).
  Check narrow-screen child widths, crop dimensions and sticky-anchor visibility,
  not just whether blocks validate. Keep any header/footer page-local unless a
  shared Theme Builder change is explicitly part of the request.

## Author and Verify

Use the existing [write contracts](tools.md) and
[authoring review handoff](design-guide.md#authoring-review-handoff): authorized
draft, validation, inspected dry-run, required backup/checksum, then persisted
readback. Omit unused empty `module.advanced: {}` at generation time, not meaningful
values. A benign create-time strip does not authorize bypassing exact update
verification: if a guarded write refuses and restores, verify restoration, correct
the candidate and revalidate before an authorized retry. Do not weaken the guard.

Separate structural validation, exact write readback, visual comparison and native
VB save/reopen. When authorized, inspect desktop/tablet/phone overflow, assets,
crop/spacing, actual links and the accepted interpretations; exercise a native
field, save and reopen the draft. Compare parsed modules/attrs after VB as well as
raw serialization. Equivalent parsed attrs after an editor serialization change
are not byte equality and do not relax the MCP write guard. Record untested panels
and interactions; no-access checks remain pending, never implied passes.

## Accepted Evidence

The bounded walkthrough from issue 1624 was accepted 2026-09-13 on Divi 5.12.1:
one unpublished native draft, page 1798, received user visual approval with
disclosed interpretations. This was **export-assisted recovery from `.make`
generation history** after direct MCP
source-body reads failed, not successful direct retrieval or a guaranteed current,
complete runnable export. The original direct-MCP criterion remains open;
native Design-frame connector proof remains separate.

- Desktop 1314x853, tablet 768x1024 and phone 390x844 had no horizontal overflow;
  three inline images loaded and two background images were visibly present.
  Crop corrections, phone copy-first order and sticky-anchor visibility were checked.
- One native Heading field inspection and VB save/exit/reopen retained all 166
  parsed native modules and their attributes with zero diffs, despite changed raw
  serialization. Native Group settings were accessible on reopen. Validation after
  the save reported 167 blocks, zero errors/warnings. Not every panel or interaction
  was exercised; this is not universal VB compatibility.
- An earlier create stripped 52 unused empty `module.advanced` objects without
  losing meaningful attrs. A later guarded update correctly refused the same
  normalization and restored the original; omitting those empty objects allowed
  exact write readback. No product change or verification bypass was needed.
- Wrapping anchor navigation, native icon substitutions and responsive typography
  were accepted interpretations, not exact mobile hamburger or Lucide equivalence.
  Unfinished archive/contact/footer actions and
  the static badge were not represented as working services. The page remained
  draft; approval did not authorize publication.

This evidence supports guided native authoring with review, not pixel-perfect,
one-click, speed, superiority or general two-connection end-to-end claims.
