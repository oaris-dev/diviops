#!/usr/bin/env node
/**
 * `diviops-preset` bin entrypoint.
 *
 * Thin wrapper around `run()` from `cli.ts`: forwards argv, prints output,
 * and translates the structured exit code into `process.exitCode`.
 */

import { readFileSync } from "fs";
import { dirname, join } from "path";
import { fileURLToPath } from "url";
import { run } from "./cli.js";

const __dirname = dirname(fileURLToPath(import.meta.url));

// Read version from package.json — same single-source-of-truth pattern as
// `src/index.ts`. Threaded into `run()` so apply-mode supplies it to the
// plugin handshake (the /handshake route requires `mcp_server_version`).
const SERVER_VERSION: string = (() => {
  try {
    const pkg = JSON.parse(
      readFileSync(join(__dirname, "..", "..", "package.json"), "utf-8"),
    );
    return pkg.version ?? "0.0.0";
  } catch {
    return "0.0.0";
  }
})();

run(process.argv.slice(2), undefined, SERVER_VERSION)
  .then((code) => {
    process.exitCode = code;
  })
  .catch((err) => {
    process.stderr.write(
      `diviops-preset: unexpected error: ${
        err instanceof Error ? err.stack ?? err.message : String(err)
      }\n`,
    );
    process.exitCode = 4;
  });
