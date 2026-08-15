import { readFileSync } from "node:fs";
import {
  preflightCrossEnvHeaderSync,
  type CrossEnvHeaderPreflightInput,
} from "./header-preflight.js";
import { preflightCrossEnvThemeBuilderLayoutSync } from "./layout-preflight.js";

export const EXIT = {
  OK: 0,
  INVALID_INPUT: 1,
} as const;

export interface CliIO {
  out(text: string): void;
  err(text: string): void;
}

const HELP = `diviops-cross-env-preflight — dry-run cross-environment layout safety reports

USAGE
  diviops-cross-env-preflight --source source.json --target target.json --dry-run [--contract <auto|header-v1|layout-v1>]

OPTIONS
  --source <path>       Secret-free source layout payload JSON.
  --target <path>       Secret-free target context JSON.
  --contract <version>  auto (default), header-v1, or layout-v1. Use layout-v1
                        for diviops_cross_env_layout_apply, including headers.
  --dry-run             Required/default. Produce a report only; no WordPress writes.
  --apply               Refused. No write/apply path exists in this MVP.
  --help                Show this help.

Supported kinds are tb_header_layout and tb_footer_layout. Existing header-only
inputs retain the shipped header-v1 report and fingerprint contract unless
--contract layout-v1 is explicitly selected.`;

type PreflightContract = "auto" | "header-v1" | "layout-v1";

interface ParsedArgs {
  help: boolean;
  source?: string;
  target?: string;
  contract?: PreflightContract;
}

function parseArgs(argv: string[]): ParsedArgs {
  const parsed: ParsedArgs = { help: false };

  for (let i = 0; i < argv.length; i++) {
    const token = argv[i];
    if (token === "--help" || token === "-h") {
      parsed.help = true;
      continue;
    }
    if (token === "--dry-run") continue;
    if (token === "--apply") {
      throw new Error("--apply is not supported; this MVP is preflight-only.");
    }
    if (token === "--source" || token === "--target" || token === "--contract") {
      const value = argv[i + 1];
      if (value === undefined || value.startsWith("--")) {
        throw new Error(`${token} requires a value.`);
      }
      if (token === "--source") parsed.source = value;
      if (token === "--target") parsed.target = value;
      if (token === "--contract") {
        if (!(["auto", "header-v1", "layout-v1"] as string[]).includes(value)) {
          throw new Error("--contract must be auto, header-v1, or layout-v1.");
        }
        parsed.contract = value as PreflightContract;
      }
      i++;
      continue;
    }
    throw new Error(`Unknown flag or argument: ${token}`);
  }

  return parsed;
}

function readJsonFile(path: string): unknown {
  try {
    return JSON.parse(readFileSync(path, "utf8"));
  } catch (err) {
    throw new Error(
      `Failed to read JSON from ${path}: ${err instanceof Error ? err.message : String(err)}`,
    );
  }
}

export async function runCli(argv: string[], io: CliIO): Promise<number> {
  try {
    const parsed = parseArgs(argv);
    if (parsed.help || argv.length === 0) {
      io.out(HELP);
      return EXIT.OK;
    }
    if (!parsed.source) throw new Error("--source <path> is required.");
    if (!parsed.target) throw new Error("--target <path> is required.");

    const input: CrossEnvHeaderPreflightInput = {
      source: readJsonFile(parsed.source) as CrossEnvHeaderPreflightInput["source"],
      target: readJsonFile(parsed.target) as CrossEnvHeaderPreflightInput["target"],
    };
    const contract = parsed.contract ?? "auto";
    const headerPair =
      input.source.object_kind === "tb_header_layout" &&
      input.target.destination_kind === "tb_header_layout";
    if (contract === "header-v1" && !headerPair) {
      throw new Error("--contract header-v1 requires tb_header_layout source and target inputs.");
    }
    const report = contract === "layout-v1" || (contract === "auto" && !headerPair)
      ? preflightCrossEnvThemeBuilderLayoutSync(input)
      : preflightCrossEnvHeaderSync(input);
    io.out(JSON.stringify(report, null, 2));
    return EXIT.OK;
  } catch (err) {
    io.err(err instanceof Error ? err.message : String(err));
    return EXIT.INVALID_INPUT;
  }
}
