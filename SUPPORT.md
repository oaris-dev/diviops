# Support

## Where to ask

- **Bug or unexpected behavior?** Open a GitHub issue using the bug-report template.
- **Question about how something works?** Open an issue using the feature-request template, or check [SETUP.md](SETUP.md) and the [troubleshooting section in README.md](README.md#troubleshooting) first.
- **Security vulnerability?** See [SECURITY.md](SECURITY.md) — do not open a public issue.

## Response expectations

DiviOps is **beta software** under active development. We triage free-distribution issues opportunistically — responses are best-effort, not guaranteed.

For guaranteed support windows, dedicated channels, and SLAs, see the Pro distribution (planned for the v2.0 commercial launch).

## Before opening an issue

- Check [SETUP.md](SETUP.md) for environment-specific setup (DDEV, wp-env, WordPress Studio, DevKinsta, Local by Flywheel).
- Check the [troubleshooting section in README.md](README.md#troubleshooting) for common quick fixes.
- Confirm you're on the latest released version of `@diviops/mcp-server` and the `diviops-agent` WordPress plugin.

## What to include

When opening an issue, the bug-report template prompts for:

- WordPress version
- Divi theme version
- MCP server version (`npx @diviops/mcp-server --version`, or check `package.json`)
- Plugin version (WP Admin → Plugins → DiviOps Agent)
- Hosting environment (Local by Flywheel, DDEV, wp-env, WordPress Studio, DevKinsta, etc.)
- Reproduction steps
- Expected vs. actual behavior

The more of these you provide up front, the faster triage moves.
