/**
 * Filesystem flag validation for wp-cli commands that read/write outside
 * the standard WP data surface (options, posts, meta, etc.).
 *
 * The main allowlist (wp-cli.ts) uses prefix matching and accepts arbitrary
 * flags after the prefix. That's fine for commands like `option get` but
 * leaves path arguments on FS-touching commands unchecked — `wp export
 * --dir=/var/www/html/public/` would pass the prefix check and dump a WXR
 * containing user data + password hashes into a web-reachable folder.
 *
 * This module adds a second-pass validator specifically for DEFAULT-tier
 * FS commands. EXTENDED-tier FS commands (`import`, `eval-file`) are
 * already opt-in via DIVIOPS_WP_CLI_ALLOW; their validation is tracked
 * separately (see dev-repo #271 scope discussion).
 *
 * Guarantees:
 *   - All path arguments must resolve under SAFE_FS_ROOT after canonicalization
 *   - `wp export` requires an explicit --dir= so it can't write to the CWD
 *   - `--filename=` on `wp export` must be a filename, not a path
 *   - `.diviops-tmp/` (or the configured override) is auto-created on first use
 *
 * Escape hatch: `DIVIOPS_WP_CLI_UNSAFE_FS=1` disables all validation for
 * trusted single-user local-dev setups.
 */

import { isAbsolute, resolve, sep, dirname, basename, join } from 'path';
import { mkdirSync, realpathSync } from 'fs';

const SAFE_FS_ROOT_SUBDIR = '.diviops-tmp';
const UNSAFE_FS_ENV = 'DIVIOPS_WP_CLI_UNSAFE_FS';
const SAFE_FS_ROOT_OVERRIDE_ENV = 'DIVIOPS_WP_CLI_SAFE_FS_ROOT';

/**
 * DEFAULT-tier commands whose arguments can write/read to arbitrary paths.
 * EXTENDED-tier FS commands (`import`, `eval-file`) are opt-in and not
 * validated here — opting in implies accepting the path-scope risk.
 */
const FS_SENSITIVE_COMMANDS: readonly string[] = [
  'export',
  'acf export',
  'acf import',
];

export interface FsValidationResult {
  allowed: boolean;
  reason?: string;
}

export interface FsValidationOptions {
  /**
   * Whether wp-cli is invoked via WP_CLI_CMD wrapper (e.g. `docker exec ... wp`).
   * In wrapper mode, the host-derived safe root (from WP_PATH / process.cwd)
   * has no correspondence to the wrapper's filesystem namespace, so the
   * validator requires an explicit DIVIOPS_WP_CLI_SAFE_FS_ROOT before
   * accepting FS-sensitive commands.
   */
  isWrapper?: boolean;
}

/**
 * Resolve the effective safe filesystem root for this wp-cli instance.
 * Precedence: DIVIOPS_WP_CLI_SAFE_FS_ROOT env var > <wpPath>/.diviops-tmp/.
 * Returned path is canonical absolute.
 */
export function resolveSafeFsRoot(wpPath: string): string {
  const override = process.env[SAFE_FS_ROOT_OVERRIDE_ENV]?.trim();
  if (override) return resolve(override);
  return resolve(wpPath, SAFE_FS_ROOT_SUBDIR);
}

/** Whether FS validation is disabled via escape-hatch env var. */
export function fsValidationDisabled(): boolean {
  const v = process.env[UNSAFE_FS_ENV]?.trim();
  return v === '1' || v?.toLowerCase() === 'true';
}

/**
 * Idempotently create the safe root so first-invocation FS commands don't
 * fail with "no such directory" when the caller points at a not-yet-existing
 * safe path. Silently no-ops on failure — if mkdir fails the subsequent
 * wp-cli call will surface its own error.
 */
export function ensureSafeFsRoot(safeRoot: string): void {
  try {
    mkdirSync(safeRoot, { recursive: true });
  } catch {
    // Ignore — downstream wp-cli will report a clearer error if the dir
    // genuinely isn't usable (permissions, etc.).
  }
}

/**
 * Identify which FS-sensitive command a parsed arg vector represents.
 * Returns the canonical prefix ("export", "acf export", "acf import") or
 * null if the args don't match a FS-sensitive command.
 */
export function matchFsSensitiveCommand(args: string[]): string | null {
  if (args.length === 0) return null;
  const twoWord = args.slice(0, 2).join(' ');
  if (FS_SENSITIVE_COMMANDS.includes(twoWord)) return twoWord;
  const oneWord = args[0];
  if (FS_SENSITIVE_COMMANDS.includes(oneWord)) return oneWord;
  return null;
}

/**
 * Resolve a filesystem path to its canonical form including symlink
 * resolution. Handles non-existent paths (e.g. `wp export --dir=<new>`)
 * by walking up to the deepest existing ancestor, realpath'ing that, and
 * preserving the non-existing tail.
 *
 * Why the walk-up matters: a naive `realpathSync → catch → path.resolve()`
 * fallback produces asymmetric canonicalization. If the user path doesn't
 * exist but the safe root DOES exist and contains a symlink in its ancestry
 * (e.g. macOS `/tmp` → `/private/tmp`), the fallback keeps the un-resolved
 * `/tmp/...` form while the root canonicalizes to `/private/tmp/...`, so
 * the `startsWith` check fails for legitimate non-existing paths inside
 * the safe root. Walking up ensures the existing portion of both paths
 * goes through the same symlink resolution.
 *
 * Also closes the original security case: realpath on an existing symlink
 * inside SAFE_FS_ROOT returns the symlink's true target, so
 * `safe-root/escape-hatch → /var/www/evil` is caught by the `startsWith`
 * check because the canonical path is `/var/www/evil`, not under safe root.
 *
 * Terminates when `dirname` stops changing (root reached); falls back to
 * `path.resolve` in that edge case.
 */
function canonicalize(p: string): string {
  try {
    return realpathSync(p);
  } catch {
    const abs = resolve(p);
    const parent = dirname(abs);
    if (parent === abs) return abs;
    return join(canonicalize(parent), basename(abs));
  }
}

/**
 * Check whether a user-supplied absolute path resolves under safeRoot.
 *
 * Requires absolute input — relative paths return false. This matches the
 * validator's contract that callers must reject relative paths BEFORE this
 * function runs, because wp-cli resolves relative args against its own CWD
 * (process.cwd in host mode, config.wpPath in wrapper mode), NOT against
 * our safe root. Accepting relative paths here would create a validation-
 * vs-execution semantic gap: the validator would happily resolve the
 * relative input against safeRoot and pass, but wp-cli would execute it
 * against a different base and write outside the intended scope.
 *
 * Canonicalization uses `fs.realpathSync` with a walk-up fallback (see
 * `canonicalize`). Realpath follows symlinks, so an attacker-planted
 * symlink inside SAFE_FS_ROOT pointing outside it is caught.
 *
 * The trailing-separator check (`startsWith(rootCanonical + sep)`) prevents
 * prefix-match tricks like /safe-root-evil passing because /safe-root is
 * the allowed prefix.
 */
export function isPathUnderSafeRoot(userPath: string, safeRoot: string): boolean {
  if (!userPath) return false;
  if (!isAbsolute(userPath)) return false;
  const canonical = canonicalize(userPath);
  const rootCanonical = canonicalize(safeRoot);
  return canonical === rootCanonical || canonical.startsWith(rootCanonical + sep);
}

/**
 * Reject a user-supplied path argument if it's relative. wp-cli resolves
 * relative FS-command args against its own CWD (process.cwd in host mode,
 * config.wpPath in wrapper mode) — neither of which is our safe root.
 * Accepting relative input at the validator level would silently bypass
 * the safe-root guarantee on execution.
 *
 * Returns a FsValidationResult rejection when relative, or null when absolute.
 * Callers should short-circuit on a non-null return.
 */
function rejectIfRelative(
  userPath: string,
  label: string,
  safeRootCanonical: string,
): FsValidationResult | null {
  if (isAbsolute(userPath)) return null;
  return {
    allowed: false,
    reason:
      `${label} "${userPath}" is a relative path. FS-sensitive commands require ` +
      `absolute paths so the validator's safe-root check matches wp-cli's execution ` +
      `semantics (wp-cli resolves relative args against the process CWD, not ` +
      `against the validator's safe root). Use an absolute path under ` +
      `${safeRootCanonical}, or set ${UNSAFE_FS_ENV}=1 to disable validation ` +
      `entirely for trusted setups.`,
  };
}

/**
 * Extract `--dir=`, `--filename_format=`, and `--stdout` from a parsed
 * arg vector. Supports both `--foo=bar` and `--foo bar` forms.
 *
 * wp-cli's `wp export` uses `--filename_format=<template>` for the output
 * filename template (containing `{site}`, `{date}`, `{n}` placeholders) —
 * NOT `--filename` (which isn't a real flag). An earlier version of this
 * validator checked `--filename`, which wp-cli silently ignores, so an
 * `--filename_format=../../evil` attempt would have passed validation
 * while wp-cli actually used the malicious template. Fix: check the real
 * flag name.
 *
 * `--stdout` writes WXR to stdout instead of a file — no FS write, so
 * callers using it don't need `--dir`. Returned as a bool flag so the
 * validator can waive the `--dir` requirement.
 */
export function extractExportFlags(args: string[]): {
  dir: string | null;
  filenameFormat: string | null;
  stdout: boolean;
} {
  let dir: string | null = null;
  let filenameFormat: string | null = null;
  let stdout = false;
  for (let i = 0; i < args.length; i++) {
    const a = args[i];
    if (a.startsWith('--dir=')) {
      dir = a.slice('--dir='.length);
    } else if (a === '--dir' && i + 1 < args.length) {
      dir = args[i + 1];
      i++;
    } else if (a.startsWith('--filename_format=')) {
      filenameFormat = a.slice('--filename_format='.length);
    } else if (a === '--filename_format' && i + 1 < args.length) {
      filenameFormat = args[i + 1];
      i++;
    } else if (a === '--stdout') {
      stdout = true;
    }
  }
  return { dir, filenameFormat, stdout };
}

/**
 * Extract the first positional (non-flag) argument after a command prefix.
 * Used for `acf export <path>` / `acf import <path>` where the path is a
 * positional arg, not a flag value.
 */
export function extractPositionalAfterPrefix(
  args: string[],
  prefixLength: number,
): string | null {
  for (let i = prefixLength; i < args.length; i++) {
    const a = args[i];
    if (a.startsWith('--')) continue;
    return a;
  }
  return null;
}

/**
 * Validate a parsed wp-cli arg vector against the FS safe-root rules.
 * Returns `{ allowed: true }` for any command not in FS_SENSITIVE_COMMANDS
 * — non-FS commands are out of scope for this validator.
 *
 * Caller should invoke only after the main allowlist has accepted the
 * command; this validator assumes the command itself is already authorized
 * and only checks path arguments.
 */
export function validateFilesystemFlags(
  args: string[],
  safeRoot: string,
  opts: FsValidationOptions = {},
): FsValidationResult {
  const cmd = matchFsSensitiveCommand(args);
  if (!cmd) return { allowed: true };

  // Wrapper-mode gate: when wp-cli runs inside a container/wrapper (WP_CLI_CMD),
  // the host path we derived for safeRoot has no correspondence to the
  // wrapper's filesystem namespace. Evaluating user-supplied container paths
  // against a host path would either (a) reject legitimate wrapper paths or
  // (b) suggest host-only safe paths the wrapper can't reach. Require the
  // caller to set DIVIOPS_WP_CLI_SAFE_FS_ROOT explicitly to the correct
  // container-namespace path before we'll validate FS-sensitive commands.
  const hasExplicitSafeRoot = !!process.env[SAFE_FS_ROOT_OVERRIDE_ENV]?.trim();
  if (opts.isWrapper && !hasExplicitSafeRoot) {
    return {
      allowed: false,
      reason:
        `FS-sensitive command "${cmd}" runs through a WP_CLI_CMD wrapper, but ` +
        `${SAFE_FS_ROOT_OVERRIDE_ENV} is not set. Host-derived safe roots don't ` +
        `correspond to the wrapper's filesystem namespace (e.g. container paths ` +
        `like /www/app). Set ${SAFE_FS_ROOT_OVERRIDE_ENV}=<container-namespace ` +
        `path> to enable validation, or ${UNSAFE_FS_ENV}=1 to disable validation ` +
        `entirely for trusted setups.`,
    };
  }

  const safeRootCanonical = resolve(safeRoot);

  if (cmd === 'export') {
    const { dir, filenameFormat, stdout } = extractExportFlags(args);

    // --stdout writes WXR to the response stream instead of the filesystem.
    // No file is created, so --dir is irrelevant. Still validate
    // --filename_format shape in case it's set (defensive — wp-cli ignores
    // it under --stdout, but an attacker-supplied value shouldn't influence
    // any future path-using code path).
    if (!stdout) {
      // Require explicit --dir. Without it, wp-cli writes to the current
      // working directory, which on prod sites is typically the web root —
      // our whole reason for existing.
      if (!dir) {
        return {
          allowed: false,
          reason:
            `wp export requires an explicit --dir=<path> (or --stdout) when filesystem ` +
            `validation is enabled. Without --dir, wp-cli writes WXR files to the current ` +
            `working directory — on prod sites that's the web root, which would expose ` +
            `exported data publicly. Pass --dir=${safeRootCanonical} (or any path under ` +
            `it), use --stdout for in-memory transfer, or set ${UNSAFE_FS_ENV}=1 to ` +
            `disable validation for trusted setups.`,
        };
      }

      // Reject relative --dir — wp-cli would resolve it against process.cwd,
      // not our safe root, defeating the validation. See rejectIfRelative.
      const rel = rejectIfRelative(dir, 'wp export --dir', safeRootCanonical);
      if (rel) return rel;

      if (!isPathUnderSafeRoot(dir, safeRootCanonical)) {
        return {
          allowed: false,
          reason:
            `wp export --dir="${dir}" resolves outside the safe filesystem root ` +
            `"${safeRootCanonical}". Use a path under the safe root, or set ` +
            `${UNSAFE_FS_ENV}=1 to disable validation.`,
        };
      }
    }

    // --filename_format is a filename template, not a path. wp-cli uses it
    // to format files produced inside --dir (and contains placeholders like
    // {site}, {date}, {n}). Reject path separators so a crafted
    // --filename_format=../../etc/passwd can't escape --dir's scope.
    if (filenameFormat !== null && (filenameFormat.includes('/') || filenameFormat.includes('\\'))) {
      return {
        allowed: false,
        reason:
          `wp export --filename_format="${filenameFormat}" contains path separators. ` +
          `--filename_format must be a filename template (e.g. "{site}.{date}.{n}.xml"), ` +
          `not a path. Use --dir to set the output directory.`,
      };
    }

    return { allowed: true };
  }

  if (cmd === 'acf export' || cmd === 'acf import') {
    const userPath = extractPositionalAfterPrefix(args, 2);
    if (!userPath) {
      // Missing positional — let wp-cli surface its own usage error rather
      // than returning an allowlist rejection that obscures the real issue.
      return { allowed: true };
    }
    // Reject relative positional path — same reason as --dir above. Without
    // this gate, `acf export relative/file.json` would resolve against
    // process.cwd at execution time and escape the safe-root scope.
    const rel = rejectIfRelative(userPath, cmd, safeRootCanonical);
    if (rel) return rel;
    if (!isPathUnderSafeRoot(userPath, safeRootCanonical)) {
      return {
        allowed: false,
        reason:
          `${cmd} "${userPath}" resolves outside the safe filesystem root ` +
          `"${safeRootCanonical}". Use a path under the safe root, or set ` +
          `${UNSAFE_FS_ENV}=1 to disable validation.`,
      };
    }
    return { allowed: true };
  }

  return { allowed: true };
}
