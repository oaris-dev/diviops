# Contributing

Thanks for your interest in DiviOps. A note on how this repo works before you open a PR.

## This is a distribution repo

`oaris-dev/diviops` is a **sync target** — every commit here is produced by an automated release dance from a private upstream repo we manage. Direct PRs against this repo will not be merged, because the next sync would overwrite them.

## How to contribute

- **Found a bug?** Open an issue on this repo. Include WP version, Divi version, MCP server version (`@diviops/mcp-server`), plugin version, and reproduction steps. The bug-report template prompts for these.
- **Have a feature idea?** Open an issue using the feature-request template. Concrete use cases beat abstract requests.
- **Spotted a docs problem?** Open an issue. We'll fix it upstream and the next sync will land it here.

## Triage and response windows

DiviOps is **beta software** under active development. We triage free-distribution issues opportunistically — responses are best-effort, not guaranteed. For guaranteed support windows, see the Pro distribution (planned for the v2.0 commercial launch).

## Security issues

Don't open public issues for security vulnerabilities. See [SECURITY.md](SECURITY.md) for the disclosure flow.
