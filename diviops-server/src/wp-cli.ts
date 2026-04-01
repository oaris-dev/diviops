/**
 * WP-CLI Wrapper for Local by Flywheel
 *
 * Executes WP-CLI commands using Local's PHP/MySQL environment.
 * Uses execFile (no shell) to prevent command injection.
 * Auto-detects PHP/MySQL versions from Local's directory structure.
 */

import { execFile } from 'child_process';
import { readdirSync, readFileSync, existsSync } from 'fs';
import { homedir } from 'os';
import { join } from 'path';

interface WpCliConfig {
  /** Absolute path to the WordPress installation */
  wpPath: string;
  /** Local by Flywheel site ID (e.g. "BKr7xMxlH"). Auto-detected from wpPath if omitted. */
  localSiteId?: string;
  /** Custom WP-CLI command prefix for containerized environments (e.g. "ddev wp"). */
  wpCliCmd?: string;
}

/**
 * Default WP-CLI commands — safe for public distribution.
 * Read-only commands + non-destructive writes needed for core MCP functionality.
 */
const DEFAULT_COMMANDS: readonly string[] = [
  // Options (read-only)
  'option get',
  'option list',
  // Posts (read + create/update)
  'post list',
  'post get',
  'post create',
  'post update',
  'post meta get',
  'post meta list',
  'post meta set',
  'post meta update',
  // Users (read-only)
  'user list',
  // Cache (non-destructive maintenance)
  'cache flush',
  'transient delete',
  'rewrite flush',
  // Info (read-only)
  'cron event list',
  'plugin list',
  'theme list',
  'menu list',
  'term list',
  'term create',
  'site url',
];

/**
 * Extended commands that require explicit opt-in via DIVIOPS_WP_CLI_ALLOW env var.
 * These carry higher risk: destructive operations, arbitrary code execution,
 * or the ability to disable security features.
 *
 * To enable, set: DIVIOPS_WP_CLI_ALLOW="option update,post delete,eval-file"
 */
const EXTENDED_COMMANDS: readonly string[] = [
  'option update',       // Can change site URL, admin email, active plugins
  'post delete',         // Destructive — permanently removes content
  'post meta delete',    // Destructive — removes metadata
  'plugin activate',     // Can enable untrusted plugins
  'plugin deactivate',   // Can disable security plugins
  'eval-file',           // Executes arbitrary PHP from a file path
];

/** Build the effective allowlist from defaults + user opt-ins. */
function buildAllowlist(): readonly string[] {
  const extra = process.env.DIVIOPS_WP_CLI_ALLOW?.trim();
  if (!extra) return DEFAULT_COMMANDS;

  const requested = extra.split(',').map((s) => s.trim()).filter(Boolean);
  const granted = new Set<string>(DEFAULT_COMMANDS);

  for (const cmd of requested) {
    if (EXTENDED_COMMANDS.includes(cmd)) {
      granted.add(cmd);
    } else if (!granted.has(cmd)) {
      console.warn(`[diviops] Ignoring unknown WP-CLI allow entry: "${cmd}"`);
    }
  }

  return [...granted];
}

const ALLOWED_COMMANDS: readonly string[] = buildAllowlist();

/**
 * Validate a parsed command against the allowlist.
 * Checks the first 1-3 args against allowed command prefixes (supports
 * 2-word commands like "post list" and 3-word like "post meta get").
 */
function isCommandAllowed(args: string[]): { allowed: boolean; reason?: string } {
  if (args.length === 0) {
    return { allowed: false, reason: 'Empty command' };
  }

  // Build candidate prefixes: "post meta get", "post meta", "post"
  const threeWord = args.slice(0, 3).join(' ');
  const twoWord = args.slice(0, 2).join(' ');
  const oneWord = args[0];

  for (const allowed of ALLOWED_COMMANDS) {
    if (threeWord === allowed || twoWord === allowed || oneWord === allowed) {
      return { allowed: true };
    }
    // Allow commands with additional args/flags after the allowed prefix
    if (threeWord.startsWith(allowed + ' ') || twoWord.startsWith(allowed + ' ')) {
      return { allowed: true };
    }
  }

  const extendable = EXTENDED_COMMANDS.filter((c) => !ALLOWED_COMMANDS.includes(c));
  const hint = extendable.some((c) => twoWord === c || threeWord === c || oneWord === c.split(' ')[0])
    ? ` This command can be enabled via DIVIOPS_WP_CLI_ALLOW env var (see README).`
    : extendable.length > 0
      ? ` Opt-in commands available: ${extendable.join(', ')}.`
      : '';

  return {
    allowed: false,
    reason: `Command "${twoWord}" not in allowlist.${hint}`,
  };
}

/**
 * Parse a command string into an array of arguments.
 * Handles quoted strings (single and double quotes).
 */
function parseCommand(command: string): string[] {
  const args: string[] = [];
  let current = '';
  let inSingle = false;
  let inDouble = false;

  for (let i = 0; i < command.length; i++) {
    const ch = command[i];

    if (ch === "'" && !inDouble) {
      inSingle = !inSingle;
    } else if (ch === '"' && !inSingle) {
      inDouble = !inDouble;
    } else if (ch === ' ' && !inSingle && !inDouble) {
      if (current.length > 0) {
        args.push(current);
        current = '';
      }
    } else {
      current += ch;
    }
  }

  if (current.length > 0) {
    args.push(current);
  }

  return args;
}

/**
 * Find the latest installed version of a Local lightning-service.
 * Scans ~/Library/Application Support/Local/lightning-services/ for directories
 * matching the prefix (e.g. "php-", "mysql-") and returns the latest.
 */
function findServiceDir(localSupport: string, prefix: string, platform: string): string | null {
  const servicesDir = join(localSupport, 'lightning-services');
  try {
    const dirs = readdirSync(servicesDir)
      .filter((d) => d.startsWith(prefix))
      .sort()
      .reverse(); // Latest version first

    for (const dir of dirs) {
      const binDir = join(servicesDir, dir, 'bin', platform, 'bin');
      try {
        readdirSync(binDir); // Check it exists
        return binDir;
      } catch {
        // Try without nested bin
        const altDir = join(servicesDir, dir, 'bin', platform);
        try {
          readdirSync(altDir);
          return altDir;
        } catch {
          continue;
        }
      }
    }
  } catch {
    // lightning-services dir not found
  }
  return null;
}

/**
 * Detect the platform string for Local's binary directories.
 */
function detectPlatform(): string {
  const arch = process.arch === 'arm64' ? 'arm64' : 'x64';
  return `darwin-${arch}`;
}

/**
 * Auto-detect the Local by Flywheel site ID from a WordPress path.
 * Reads ~/Library/Application Support/Local/sites.json and matches by path.
 */
function detectLocalSiteId(wpPath: string): string | null {
  const home = homedir();
  const localSupport = join(home, 'Library', 'Application Support', 'Local');
  const sitesFile = join(localSupport, 'sites.json');

  if (!existsSync(sitesFile)) {
    return null;
  }

  try {
    const sites = JSON.parse(readFileSync(sitesFile, 'utf-8'));
    // Normalize the wpPath: strip trailing /app/public if present
    const normalizedWp = wpPath.replace(/\/app\/public\/?$/, '');

    for (const [siteId, site] of Object.entries(sites) as [string, any][]) {
      // site.path may use ~ for home dir
      const rawPath = site.path ?? '';
      if (!rawPath) continue; // Skip entries with missing path.
      const sitePath = rawPath.replace(/^~/, home);
      if (normalizedWp === sitePath || wpPath.startsWith(sitePath + '/')) {
        return siteId;
      }
    }
  } catch (e) {
    console.error(`Error reading Local sites.json: ${e}`);
  }

  return null;
}

/**
 * Build the environment variables needed for Local by Flywheel's WP-CLI.
 * Auto-detects PHP and MySQL versions from Local's directory structure.
 */
function buildLocalEnv(localSiteId: string): Record<string, string> {
  const localSupport = join(homedir(), 'Library', 'Application Support', 'Local');
  const runDir = `${localSupport}/run/${localSiteId}`;
  const platform = detectPlatform();

  const phpDir = findServiceDir(localSupport, 'php-', platform);
  const mysqlDir = findServiceDir(localSupport, 'mysql-', platform);
  const wpCliDir = '/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/posix';

  const pathParts = [
    mysqlDir,
    phpDir,
    wpCliDir,
    process.env.PATH ?? '',
  ].filter(Boolean);

  return {
    ...(process.env as Record<string, string>),
    MYSQL_HOME: `${runDir}/conf/mysql`,
    PHPRC: `${runDir}/conf/php`,
    PATH: pathParts.join(':'),
    WP_CLI_DISABLE_AUTO_CHECK_UPDATE: '1',
  };
}

export function createWpCli(config: WpCliConfig) {
  let executable = 'wp';
  let prefixArgs: string[] = [];
  let env: Record<string, string>;
  const customWpCliCmd = config.wpCliCmd?.trim();
  const execOptions = {
    env: process.env as Record<string, string>,
    timeout: 30_000,
    maxBuffer: 5 * 1024 * 1024,
  };

  if (customWpCliCmd) {
    [executable, ...prefixArgs] = parseCommand(customWpCliCmd);
    if (!executable) {
      throw new Error('WP_CLI_CMD must include an executable.');
    }
    env = { ...(process.env as Record<string, string>) };
  } else {
    // Auto-detect site ID if not provided.
    const localSiteId = config.localSiteId || detectLocalSiteId(config.wpPath);
    if (!localSiteId) {
      throw new Error(
        `Could not detect Local by Flywheel site ID for path "${config.wpPath}". ` +
        `Provide LOCAL_SITE_ID env var or ensure the site is registered in Local.`
      );
    }

    env = buildLocalEnv(localSiteId);
  }

  const runOptions = customWpCliCmd
    ? { ...execOptions, env, cwd: config.wpPath }
    : { ...execOptions, env };

  return {
    /**
     * Execute a WP-CLI command. Returns stdout on success.
     * Commands are parsed into args and validated against an allowlist.
     * Uses execFile (no shell) to prevent command injection.
     */
    async run(command: string): Promise<{ success: boolean; output: string; error?: string }> {
      const args = parseCommand(command);
      const check = isCommandAllowed(args);
      if (!check.allowed) {
        return { success: false, output: '', error: check.reason };
      }

      const fullArgs = customWpCliCmd
        ? [...prefixArgs, ...args, '--no-color']
        : [...args, `--path=${config.wpPath}`, '--no-color'];

      return new Promise((resolve) => {
        execFile(
          executable,
          fullArgs,
          runOptions,
          (error, stdout, stderr) => {
            // Filter PHP deprecation warnings from output
            const output = (stdout + '\n' + stderr)
              .split('\n')
              .filter((line) => !line.includes('Deprecated:') && !line.includes('PHP Deprecated'))
              .join('\n')
              .trim();

            if (error) {
              const detail = error.killed
                ? 'Command timed out'
                : error.signal
                  ? `Killed by signal ${error.signal}`
                  : `Exit code ${error.code ?? 'unknown'}`;
              resolve({ success: false, output, error: detail });
            } else {
              resolve({ success: true, output });
            }
          },
        );
      });
    },

    /** Return the list of allowed commands and available extensions. */
    getAllowedCommands(): { allowed: string[]; extendable: string[] } {
      return {
        allowed: [...ALLOWED_COMMANDS],
        extendable: EXTENDED_COMMANDS.filter((c) => !ALLOWED_COMMANDS.includes(c)),
      };
    },
  };
}
