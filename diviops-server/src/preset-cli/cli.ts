/**
 * `diviops-preset` — standalone preset-emitter CLI.
 *
 * Emits byte-canonical Divi 5.5.x preset JSON, gated by the verified-attrs
 * registry and routed through the existing storage-path contract. The
 * initial slice shipped the `divi/button` group emitter; subsequent slices
 * added the heading/font, color, and spacing emitters.
 *
 * Usage:
 *   diviops-preset button [options]      Emit a divi/button group preset
 *   diviops-preset nav [options]         Emit Divi nav block skeletons
 *   diviops-preset --help                Show help
 *
 * Modes:
 *   --dry-run (default)   Compose and print canonical JSON. No credentials,
 *                         no handshake, no network.
 *   --apply               Capability-gate + POST to /preset/create. Reuses
 *                         WP_URL / WP_USER / WP_APP_PASSWORD env vars.
 *
 * Exit codes:
 *   0  success
 *   1  invalid input / usage error
 *   2  evidence-gate refusal (attr below VB_PRESET_STORAGE_VERIFIED)
 *   3  capability-missing (plugin lacks storage_multipath_probe_v1)
 *   4  write / network error
 */

import { readFileSync } from "fs";
import {
  emitButtonGroupPreset,
  buildPresetCreateBody,
  type ButtonEmitterInput,
  type ButtonRadiusInput,
  type ButtonBorderStylesInput,
  type ButtonFontInput,
} from "./button-emitter.js";
import {
  emitHeadingFontGroupPreset,
  buildHeadingFontPresetCreateBody,
  UnsupportedVariantCombinationError,
  type HeadingFontEmitterInput,
  type HeadingFontPattern,
} from "./heading-font-emitter.js";
import {
  emitTextBodyFontGroupPreset,
  buildTextBodyFontPresetCreateBody,
  type TextBodyFontEmitterInput,
  type TextBodyFontPattern,
} from "./text-body-font-emitter.js";
import {
  emitSpacingGroupPreset,
  buildSpacingPresetCreateBody,
  type SpacingEmitterInput,
  type SpacingCornerInput,
} from "./spacing-emitter.js";
import {
  emitNavBlockMarkup,
  buildNavMarkupBody,
  parseNavSpecJson,
  type NavEmitterInput,
} from "./nav-emitter.js";
import { EvidenceGateError } from "./registry.js";
import {
  applyButtonPreset,
  applyHeadingFontPreset,
  applyTextBodyFontPreset,
  applySpacingPreset,
  buildClientFromEnv,
  CredentialsMissingError,
  CapabilityMissingError,
  PresetIsolationError,
} from "./write-path.js";

export const EXIT = {
  OK: 0,
  INVALID_INPUT: 1,
  EVIDENCE_GATE: 2,
  CAPABILITY_MISSING: 3,
  WRITE_ERROR: 4,
} as const;

export type ExitCode = (typeof EXIT)[keyof typeof EXIT];

/** Sink for CLI output — injectable so tests capture without touching real stdio. */
export interface CliIO {
  out(text: string): void;
  err(text: string): void;
}

const realIO: CliIO = {
  out: (t) => process.stdout.write(t + "\n"),
  err: (t) => process.stderr.write(t + "\n"),
};

const HELP = `diviops-preset — Divi 5.5.x canonical preset-emitter CLI

USAGE
  diviops-preset button [options]           Emit a divi/button group preset
  diviops-preset heading-font [options]     Emit a divi/font group preset for
                                            divi/heading
  diviops-preset text-body-font [options]   Emit a divi/font-body group preset
                                            for divi/text (Pattern A only)
  diviops-preset spacing [options]          Emit a divi/spacing group preset
                                            (currently divi/section only)
  diviops-preset nav [options]              Emit Divi nav block-markup
                                            skeletons
  diviops-preset --help                     Show this help

MODE
  --dry-run            Compose + print canonical JSON only (DEFAULT).
                       No credentials, no handshake, no network.
  --apply              Capability-gate, then POST to /preset/create.
                       Requires WP_URL / WP_USER / WP_APP_PASSWORD.

button OPTIONS (all styling fields optional; emit-on-specification only)
  --name <string>          Preset display name (required).
  --bg-color <value>       Desktop background color. Hex literal, or a
                           bare gcid-*/gvid-* token, or a $variable(...)$ token.
  --bg-color-hover <value> Hover background color (same value forms).
  --radius-top-left <v>      Border radius corner. Any subset of the four
  --radius-top-right <v>     corners; the radius widget emits a sync flag
  --radius-bottom-left <v>   alongside any corner ("on" only when all four
  --radius-bottom-right <v>  corners are given and equal, else "off";
                             override with --radius-sync).
  --radius-sync <on|off>     Explicit radius sync flag.
  --radius <v>               Shorthand: sets all four corners to <v>.
  --border-width <v>       Outline-button border width.
  --border-style <v>       Outline-button border style (solid|dashed|...).
  --border-color <value>   Outline-button border color (same value forms).
  --font-family <string>   Button font family (literal string).
  --font-weight <string>   Button font weight (e.g. "600").
  --font-color <value>     Button font color (same value forms).
  --font-size <v>          Button font size (e.g. "16px").
  --bypass-hover-padding-gate
                           Opt-in: emit button.decoration.button.desktop
                           .value.padding.top="0px" (hover-padding-gate
                           workaround). Off by default.

heading-font OPTIONS (all styling fields optional; emit-on-specification only)
  --name <string>          Preset display name (required).
  --pattern <google|local> Required. Selects the verified variant:
                             google — Pattern A: plain family + explicit
                                      numeric weight (e.g. family "Inter",
                                      weight "700"). Verified against
                                      round-1a fixture.
                             local  — Pattern B: weight-encoded family
                                      string (e.g. family "Sora 700") with
                                      NO --font-weight. Used for local-
                                      hosted/EU-GDPR font flows. Verified
                                      against round-1b fixture.
                           Pattern A and Pattern B are distinct registry
                           variants — there is NO default; omitting
                           --pattern is invalid input.
  --font-family <string>   Font family. Pattern A: plain name ("Inter").
                           Pattern B: weight-encoded ("Sora 700").
  --font-weight <string>   Font weight (e.g. "700"). Pattern A only —
                           passing it with --pattern local is refused.
  --font-color <value>     Font color. Hex literal, bare gcid-*/gvid-*
                           token, or already-formed $variable(...)$ token.
  --font-size <v>          Font size (e.g. "48px").
  --font-line-height <v>   Font line-height (e.g. "1.1").

text-body-font OPTIONS (all styling fields optional; emit-on-specification only)
  --name <string>          Preset display name (required).
  --pattern <google|local> Required. Example: --pattern google.
                           Pattern A is the only supported variant for now:
                             google — Pattern A: plain family + optional
                                      numeric weight (e.g. family "Inter",
                                      weight "400"). Verified against
                                      round-2 body-text fixture.
                             local  — Pattern B for divi/font-body is NOT
                                      registered (no canonical-shape
                                      capture exists yet). Selecting it
                                      lands on a registry-absence refusal.
                           There is NO default; omitting --pattern is
                           invalid input.
  --font-family <string>   Font family (plain name, e.g. "Inter").
  --font-weight <string>   Font weight (e.g. "400").
  --font-color <value>     Font color. Hex literal, bare gcid-*/gvid-*
                           token, or already-formed $variable(...)$ token.
  --font-size <v>          Font size (e.g. "16px").
  --font-line-height <v>   Font line-height (e.g. "1.5").

spacing OPTIONS (sparse-emit per axis; paired sync flags per axis)
  --name <string>          Preset display name (required).
  --module <name>          Required. Currently only divi/section is wired
                           (the canonical capture verified only the
                           divi/section cell). Other modules (divi/heading,
                           divi/text, divi/button, etc.) resolve to the
                           registry gate and are refused with
                           EvidenceGateError — promoting them requires a
                           canonical-capture change plus a follow-up
                           implementation/docs change (NOT a free dispatch-
                           clear via the gate alone).
  --padding-top <v>        Desktop padding corners. Pass any subset; only
  --padding-right <v>      passed corners emit (sparse-emit per axis). v1
  --padding-bottom <v>     accepts literal CSS lengths only (px / rem /
  --padding-left <v>       em / % / vw / vh) — $variable(...) / gvid-*
                           tokens are refused (deferred until canonical
                           variable-token capture lands).
  --margin-top <v>         Desktop margin corners. Same shape rules as
  --margin-right <v>       padding; padding and margin are independent —
  --margin-bottom <v>      passing only padding flags omits the margin bag
  --margin-left <v>        from the output, and vice versa.
  --padding-sync-vertical <on|off>
                           Explicit padding sync flag (default "off").
                           Both syncVertical AND syncHorizontal always
  --padding-sync-horizontal <on|off>
                           emit as paired siblings when the padding axis
                           has any touched corner.
  --margin-sync-vertical <on|off>
                           Explicit margin sync flag (default "off").
  --margin-sync-horizontal <on|off>
                           Same paired-siblings rule as padding.

nav OPTIONS (dry-run block markup only; --apply is refused)
  --name <string>          Output/display name (required).
  --spec <json>            Compact nav spec JSON. Mutually exclusive with
                           --spec-file.
  --spec-file <path>       Read compact nav spec JSON from disk.
  --responsive-split       Emit sibling desktop/mobile nav units using native
                           Divi disabledOn visibility. Default is one drawer.

  Spec shape:
    {"label":"Menu","idPrefix":"main-nav","items":[
      {"label":"Home","url":"/"},
      {"label":"Products","links":[{"label":"Overview","url":"/products"}]}
    ]}

EXIT CODES
  0 success   1 invalid input   2 evidence-gate refusal
  3 capability missing   4 write error

EXAMPLES
  diviops-preset button --name "Primary" --bg-color gcid-primary-color \\
    --bg-color-hover gcid-secondary-color --radius 8px \\
    --font-family Inter --font-weight 600 --font-color gcid-body-color

  diviops-preset button --name "Primary" --bg-color "#2563eb" --apply

  diviops-preset heading-font --name "Heading H1" --pattern google \\
    --font-family Inter --font-weight 700 \\
    --font-color gcid-heading-color --font-size 48px

  diviops-preset heading-font --name "Heading H1 (local)" --pattern local \\
    --font-family "Sora 700" \\
    --font-color gcid-heading-color --font-size 48px

  diviops-preset text-body-font --name "Body Text" --pattern google \\
    --font-family Inter --font-weight 400 \\
    --font-color gcid-body-color --font-size 16px

  diviops-preset spacing --name "Section Rhythm" --module divi/section \\
    --padding-top 80px --padding-bottom 80px --margin-bottom 40px
`;

interface ParsedArgs {
  command: string | null;
  help: boolean;
  apply: boolean;
  dryRun: boolean;
  options: Map<string, string | true>;
}

const VALUE_FLAGS = new Set([
  "--name",
  "--bg-color",
  "--bg-color-hover",
  "--radius",
  "--radius-top-left",
  "--radius-top-right",
  "--radius-bottom-left",
  "--radius-bottom-right",
  "--radius-sync",
  "--border-width",
  "--border-style",
  "--border-color",
  "--font-family",
  "--font-weight",
  "--font-color",
  "--font-size",
  "--font-line-height",
  "--pattern",
  "--module",
  "--padding-top",
  "--padding-right",
  "--padding-bottom",
  "--padding-left",
  "--margin-top",
  "--margin-right",
  "--margin-bottom",
  "--margin-left",
  "--padding-sync-vertical",
  "--padding-sync-horizontal",
  "--margin-sync-vertical",
  "--margin-sync-horizontal",
  "--spec",
  "--spec-file",
]);

/** Parse argv (after `node script`) into a structured shape. Throws on unknown flags. */
export function parseArgs(argv: string[]): ParsedArgs {
  const parsed: ParsedArgs = {
    command: null,
    help: false,
    apply: false,
    dryRun: false,
    options: new Map(),
  };

  let i = 0;
  // First non-flag token is the command.
  if (argv.length > 0 && !argv[0].startsWith("-")) {
    parsed.command = argv[0];
    i = 1;
  }

  for (; i < argv.length; i++) {
    const tok = argv[i];
    if (tok === "--help" || tok === "-h") {
      parsed.help = true;
      continue;
    }
    if (tok === "--apply") {
      parsed.apply = true;
      continue;
    }
    if (tok === "--dry-run") {
      parsed.dryRun = true;
      continue;
    }
    if (tok === "--bypass-hover-padding-gate") {
      parsed.options.set(tok, true);
      continue;
    }
    if (tok === "--responsive-split") {
      parsed.options.set(tok, true);
      continue;
    }
    if (VALUE_FLAGS.has(tok)) {
      const val = argv[i + 1];
      if (val === undefined || val.startsWith("--")) {
        throw new UsageError(`Flag ${tok} requires a value.`);
      }
      parsed.options.set(tok, val);
      i++;
      continue;
    }
    if (!tok.startsWith("-") && parsed.command === null) {
      parsed.command = tok;
      continue;
    }
    throw new UsageError(`Unknown flag or argument: ${tok}`);
  }

  if (parsed.apply && parsed.dryRun) {
    throw new UsageError("--apply and --dry-run are mutually exclusive.");
  }
  // dry-run is the default-safe mode when neither is given.
  if (!parsed.apply) parsed.dryRun = true;

  return parsed;
}

export class UsageError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "UsageError";
  }
}

/** Known CLI commands. The CLI rejects anything else with EXIT.INVALID_INPUT. */
const KNOWN_COMMANDS = new Set([
  "button",
  "heading-font",
  "text-body-font",
  "spacing",
  "nav",
]);

/** Map parsed `button` options into the emitter input shape. */
export function buildButtonInput(parsed: ParsedArgs): ButtonEmitterInput {
  const opt = (k: string): string | undefined => {
    const v = parsed.options.get(k);
    return typeof v === "string" ? v : undefined;
  };

  const name = opt("--name");
  if (!name) {
    throw new UsageError("button command requires --name <string>.");
  }

  const input: ButtonEmitterInput = { name };

  const bg = opt("--bg-color");
  if (bg !== undefined) input.bg_color = bg;
  const bgh = opt("--bg-color-hover");
  if (bgh !== undefined) input.bg_color_hover = bgh;

  // Radius — shorthand --radius sets all four corners.
  const radius: ButtonRadiusInput = {};
  const radiusAll = opt("--radius");
  if (radiusAll !== undefined) {
    radius.topLeft = radiusAll;
    radius.topRight = radiusAll;
    radius.bottomLeft = radiusAll;
    radius.bottomRight = radiusAll;
  }
  const rtl = opt("--radius-top-left");
  if (rtl !== undefined) radius.topLeft = rtl;
  const rtr = opt("--radius-top-right");
  if (rtr !== undefined) radius.topRight = rtr;
  const rbl = opt("--radius-bottom-left");
  if (rbl !== undefined) radius.bottomLeft = rbl;
  const rbr = opt("--radius-bottom-right");
  if (rbr !== undefined) radius.bottomRight = rbr;
  const rsync = opt("--radius-sync");
  if (rsync !== undefined) {
    if (rsync !== "on" && rsync !== "off") {
      throw new UsageError('--radius-sync must be "on" or "off".');
    }
    radius.sync = rsync;
  }
  if (Object.keys(radius).length > 0) input.radius = radius;

  // Outline border styles.
  const border: ButtonBorderStylesInput = {};
  const bw = opt("--border-width");
  if (bw !== undefined) border.width = bw;
  const bs = opt("--border-style");
  if (bs !== undefined) border.style = bs;
  const bc = opt("--border-color");
  if (bc !== undefined) border.color = bc;
  if (Object.keys(border).length > 0) input.border = border;

  // Font.
  const font: ButtonFontInput = {};
  const ff = opt("--font-family");
  if (ff !== undefined) font.family = ff;
  const fw = opt("--font-weight");
  if (fw !== undefined) font.weight = fw;
  const fc = opt("--font-color");
  if (fc !== undefined) font.color = fc;
  const fs = opt("--font-size");
  if (fs !== undefined) font.size = fs;
  if (Object.keys(font).length > 0) input.font = font;

  if (parsed.options.get("--bypass-hover-padding-gate") === true) {
    input.bypass_hover_padding_gate = true;
  }

  return input;
}

/** Map parsed `heading-font` options into the heading-font emitter input shape. */
export function buildHeadingFontInput(
  parsed: ParsedArgs,
): HeadingFontEmitterInput {
  const opt = (k: string): string | undefined => {
    const v = parsed.options.get(k);
    return typeof v === "string" ? v : undefined;
  };

  const name = opt("--name");
  if (!name) {
    throw new UsageError("heading-font command requires --name <string>.");
  }

  // --pattern is REQUIRED — Pattern A vs Pattern B are distinct registry/
  // evidence variants and there is no safe default. An omitted --pattern
  // is invalid input; the CLI fails BEFORE emission (no guessing).
  const patternRaw = opt("--pattern");
  if (patternRaw === undefined) {
    throw new UsageError(
      "heading-font command requires --pattern <google|local>. " +
        "There is no default — Pattern A (google) and Pattern B (local) are distinct " +
        "registry variants and must be selected intentionally.",
    );
  }
  if (patternRaw !== "google" && patternRaw !== "local") {
    throw new UsageError(
      `--pattern must be "google" or "local"; got ${JSON.stringify(patternRaw)}.`,
    );
  }
  const pattern: HeadingFontPattern = patternRaw;

  const input: HeadingFontEmitterInput = { name, pattern };

  const family = opt("--font-family");
  if (family !== undefined) input.family = family;
  const weight = opt("--font-weight");
  if (weight !== undefined) input.weight = weight;
  const color = opt("--font-color");
  if (color !== undefined) input.color = color;
  const size = opt("--font-size");
  if (size !== undefined) input.size = size;
  const lineHeight = opt("--font-line-height");
  if (lineHeight !== undefined) input.lineHeight = lineHeight;

  return input;
}

/** Map parsed `text-body-font` options into the text-body-font emitter input shape. */
export function buildTextBodyFontInput(
  parsed: ParsedArgs,
): TextBodyFontEmitterInput {
  const opt = (k: string): string | undefined => {
    const v = parsed.options.get(k);
    return typeof v === "string" ? v : undefined;
  };

  const name = opt("--name");
  if (!name) {
    throw new UsageError("text-body-font command requires --name <string>.");
  }

  // --pattern is REQUIRED — there is no safe default. Pattern A only;
  // passing "local" lands on the registry-absence refusal downstream
  // (no Pattern B entry exists for `divi/font-body`).
  const patternRaw = opt("--pattern");
  if (patternRaw === undefined) {
    throw new UsageError(
      "text-body-font command requires --pattern <google|local>. " +
        "Example: --pattern google. Pattern A (google) is the only " +
        "supported variant for now; Pattern B (local) has no registry " +
        "entry for `divi/font-body` and will be refused.",
    );
  }
  if (patternRaw !== "google" && patternRaw !== "local") {
    throw new UsageError(
      `--pattern must be "google" or "local"; got ${JSON.stringify(patternRaw)}.`,
    );
  }
  const pattern: TextBodyFontPattern = patternRaw;

  const input: TextBodyFontEmitterInput = { name, pattern };

  const family = opt("--font-family");
  if (family !== undefined) input.family = family;
  const weight = opt("--font-weight");
  if (weight !== undefined) input.weight = weight;
  const color = opt("--font-color");
  if (color !== undefined) input.color = color;
  const size = opt("--font-size");
  if (size !== undefined) input.size = size;
  const lineHeight = opt("--font-line-height");
  if (lineHeight !== undefined) input.lineHeight = lineHeight;

  return input;
}

/** Parse an optional `on|off` flag value; throw a usage error otherwise. */
function parseOnOff(
  raw: string | undefined,
  flag: string,
): "on" | "off" | undefined {
  if (raw === undefined) return undefined;
  if (raw !== "on" && raw !== "off") {
    throw new UsageError(`${flag} must be "on" or "off"; got ${JSON.stringify(raw)}.`);
  }
  return raw;
}

/** Map parsed `spacing` options into the spacing emitter input shape. */
export function buildSpacingInput(parsed: ParsedArgs): SpacingEmitterInput {
  const opt = (k: string): string | undefined => {
    const v = parsed.options.get(k);
    return typeof v === "string" ? v : undefined;
  };

  const name = opt("--name");
  if (!name) {
    throw new UsageError("spacing command requires --name <string>.");
  }
  const module = opt("--module");
  if (!module) {
    throw new UsageError(
      "spacing command requires --module <name>. Currently only " +
        "divi/section is wired; other modules are refused by the registry " +
        "gate (heading/text/button cells are SCHEMA_OBSERVED).",
    );
  }

  const input: SpacingEmitterInput = { name, module };

  // Sparse-emit at parse time too: only attach an axis bag when at least
  // one corner OR a sync flag was passed. (Sync-flag-only input lands on
  // the emitter's per-axis sync-without-corner refusal.) Padding and
  // margin follow the identical shape rule, so the per-axis collection
  // is hoisted into a single helper.
  const buildAxis = (prefix: string): SpacingCornerInput | undefined => {
    const bag: SpacingCornerInput = {};
    const t = opt(`--${prefix}-top`);
    if (t !== undefined) bag.top = t;
    const r = opt(`--${prefix}-right`);
    if (r !== undefined) bag.right = r;
    const b = opt(`--${prefix}-bottom`);
    if (b !== undefined) bag.bottom = b;
    const l = opt(`--${prefix}-left`);
    if (l !== undefined) bag.left = l;
    const sv = parseOnOff(
      opt(`--${prefix}-sync-vertical`),
      `--${prefix}-sync-vertical`,
    );
    if (sv !== undefined) bag.syncVertical = sv;
    const sh = parseOnOff(
      opt(`--${prefix}-sync-horizontal`),
      `--${prefix}-sync-horizontal`,
    );
    if (sh !== undefined) bag.syncHorizontal = sh;
    return Object.keys(bag).length > 0 ? bag : undefined;
  };

  const padding = buildAxis("padding");
  if (padding) input.padding = padding;
  const margin = buildAxis("margin");
  if (margin) input.margin = margin;

  return input;
}

/** Map parsed `nav` options into the nav block-markup emitter input shape. */
export function buildNavInput(parsed: ParsedArgs): NavEmitterInput {
  const opt = (k: string): string | undefined => {
    const v = parsed.options.get(k);
    return typeof v === "string" ? v : undefined;
  };

  const name = opt("--name");
  if (!name) {
    throw new UsageError("nav command requires --name <string>.");
  }

  const inlineSpec = opt("--spec");
  const specFile = opt("--spec-file");
  if (inlineSpec && specFile) {
    throw new UsageError("nav command accepts either --spec or --spec-file, not both.");
  }
  if (!inlineSpec && !specFile) {
    throw new UsageError("nav command requires --spec <json> or --spec-file <path>.");
  }

  const specJson = inlineSpec ?? readFileSync(specFile as string, "utf-8");
  return {
    name,
    spec: parseNavSpecJson(specJson),
    mode: parsed.options.has("--responsive-split")
      ? "responsive_split"
      : "drawer",
  };
}

/**
 * Run the CLI. Returns the structured exit code (does NOT call
 * `process.exit` — the thin bin wrapper does). `io` is injectable so
 * tests capture output without touching real stdio.
 */
export async function run(
  argv: string[],
  io: CliIO = realIO,
  serverVersion?: string,
): Promise<ExitCode> {
  let parsed: ParsedArgs;
  try {
    parsed = parseArgs(argv);
  } catch (err) {
    io.err(err instanceof Error ? err.message : String(err));
    io.err("");
    io.err("Run `diviops-preset --help` for usage.");
    return EXIT.INVALID_INPUT;
  }

  if (parsed.help || (parsed.command === null && parsed.options.size === 0)) {
    io.out(HELP);
    return EXIT.OK;
  }

  if (parsed.command === null || !KNOWN_COMMANDS.has(parsed.command)) {
    io.err(
      `Unknown command: ${parsed.command ?? "(none)"}. ` +
        `Known commands: ${[...KNOWN_COMMANDS].sort().join(", ")}.`,
    );
    io.err("Run `diviops-preset --help` for usage.");
    return EXIT.INVALID_INPUT;
  }

  // --- compose + gate -------------------------------------------------
  // Per-command branch produces:
  //  - `dryRunBody`: the canonical JSON to print in --dry-run mode.
  //  - `applyFn`: a closure that issues the apply-mode write when the
  //    capability check passes. Both branches reuse the existing write-
  //    path plumbing (`assertStorageCapability` → `/preset/create`).
  let dryRunBody: Record<string, unknown>;
  let applyFn: (
    client: import("./write-path.js").PresetWriteClient,
    sv: string,
  ) => Promise<import("../envelope.js").DiviopsResponse<unknown>>;

  try {
    if (parsed.command === "button") {
      const input = buildButtonInput(parsed);
      const entry = emitButtonGroupPreset(input);
      dryRunBody = buildPresetCreateBody(entry, { dry_run: true });
      applyFn = (client, sv) =>
        applyButtonPreset(client, entry, { serverVersion: sv });
    } else if (parsed.command === "heading-font") {
      const input = buildHeadingFontInput(parsed);
      const entry = emitHeadingFontGroupPreset(input);
      dryRunBody = buildHeadingFontPresetCreateBody(entry, { dry_run: true });
      applyFn = (client, sv) =>
        applyHeadingFontPreset(client, entry, { serverVersion: sv });
    } else if (parsed.command === "text-body-font") {
      // text-body-font (Pattern A only; Pattern B lands on the
      // registry-absence refusal in emitTextBodyFontGroupPreset).
      const input = buildTextBodyFontInput(parsed);
      const entry = emitTextBodyFontGroupPreset(input);
      dryRunBody = buildTextBodyFontPresetCreateBody(entry, { dry_run: true });
      applyFn = (client, sv) =>
        applyTextBodyFontPreset(client, entry, { serverVersion: sv });
    } else if (parsed.command === "spacing") {
      // spacing (divi/section only — other modules land on the registry
      // gate in emitSpacingGroupPreset and surface as EvidenceGateError).
      const input = buildSpacingInput(parsed);
      const entry = emitSpacingGroupPreset(input);
      dryRunBody = buildSpacingPresetCreateBody(entry, { dry_run: true });
      applyFn = (client, sv) =>
        applySpacingPreset(client, entry, { serverVersion: sv });
    } else if (parsed.command === "nav") {
      if (parsed.apply) {
        throw new UsageError(
          "nav command is dry-run block-markup only in this MVP; --apply is not supported.",
        );
      }
      const input = buildNavInput(parsed);
      const entry = emitNavBlockMarkup(input);
      dryRunBody = buildNavMarkupBody(entry, { dry_run: true });
      applyFn = async () => {
        throw new Error("nav command has no apply mode.");
      };
    } else {
      // Defensive: a new entry in KNOWN_COMMANDS without a dispatch
      // branch here would silently break dry-run/apply. parseArgs
      // already gates on KNOWN_COMMANDS upstream, so reaching this is a
      // programmer error in the dispatch wiring.
      throw new UsageError(`Unhandled command: ${parsed.command}`);
    }
  } catch (err) {
    if (err instanceof EvidenceGateError) {
      io.err(err.message);
      return EXIT.EVIDENCE_GATE;
    }
    if (err instanceof UsageError) {
      io.err(err.message);
      io.err("Run `diviops-preset --help` for usage.");
      return EXIT.INVALID_INPUT;
    }
    if (err instanceof UnsupportedVariantCombinationError) {
      // Distinct from EvidenceGateError: the registry IS complete for the
      // verified variants, but the caller asked for a combination outside
      // any verified variant. Surfaces as invalid input (exit 1).
      io.err(err.message);
      return EXIT.INVALID_INPUT;
    }
    io.err(err instanceof Error ? err.message : String(err));
    return EXIT.INVALID_INPUT;
  }

  // --- dry-run --------------------------------------------------------
  if (parsed.dryRun) {
    io.out(JSON.stringify(dryRunBody, null, 2));
    return EXIT.OK;
  }

  // --- apply ----------------------------------------------------------
  try {
    const client = buildClientFromEnv();
    // Apply mode requires the server version for the plugin handshake; the
    // /handshake route gates on `mcp_server_version`. The bin entrypoint
    // always supplies it — guard here so apply mode can never reach the
    // handshake with an undefined/empty version.
    if (!serverVersion) {
      io.err(
        "Apply mode requires the server version for the plugin handshake. " +
          "This is an internal error — invoke via the `diviops-preset` bin.",
      );
      return EXIT.WRITE_ERROR;
    }
    const result = await applyFn(client, serverVersion);
    io.out(JSON.stringify(result, null, 2));
    return EXIT.OK;
  } catch (err) {
    if (err instanceof CapabilityMissingError) {
      io.err(err.message);
      return EXIT.CAPABILITY_MISSING;
    }
    if (err instanceof CredentialsMissingError) {
      io.err(err.message);
      return EXIT.INVALID_INPUT;
    }
    if (err instanceof PresetIsolationError) {
      io.err(err.message);
      return EXIT.INVALID_INPUT;
    }
    io.err(
      `Write failed: ${err instanceof Error ? err.message : String(err)}`,
    );
    return EXIT.WRITE_ERROR;
  }
}
