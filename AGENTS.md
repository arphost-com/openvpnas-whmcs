# Repository Guidelines

## Project Structure & Module Organization

- `openvpnas_whmcs.php` contains the WHMCS server module entry points (`*_CreateAccount`, `*_SuspendAccount`, etc.).
- `lib/OpenVpnAsWhmcsDockerClient.php` encapsulates SSH + `sacli` calls against the OpenVPN-AS Docker container.
- `clientarea.tpl` renders the client area panel for profile download and password changes.
- `README.md` documents installation, configuration, and usage.

## Build, Test, and Development Commands

This repository has no build tooling or automated test runner.

- Manual smoke test in WHMCS: run module commands from a service (`Create`, `Suspend`, `Unsuspend`, `Terminate`).
- Connectivity check (from WHMCS host):
  - `curl -vk https://vpn.example.com:943/` to validate API/UI reachability.

## Coding Style & Naming Conventions

- PHP uses 4-space indentation and PSR-style braces.
- Module functions are prefixed `openvpnas_whmcs_` to match WHMCS module expectations.
- Class names use `PascalCase`; methods use `camelCase`.
- Keep new strings and comments ASCII unless required by existing content.

## Testing Guidelines

- No formal test suite is present.
- Verify changes by running the WHMCS module commands and reviewing logs:
  - **Utilities → Logs → Module Log** (enable debug, then disable after use).
- If adding new behavior, include a manual test note in the PR description.
- Smoke tests:
  - Local PHP: `php tests/smoke.php`
  - Docker: `bash tests/docker/run-smoke.sh`

## Commit & Pull Request Guidelines

- Commit history uses short, plain-language messages (e.g., “Revise README…” or version tags).
- Prefer concise, imperative summaries (50–72 chars) with scope when helpful.
- PRs should include:
  - What changed and why.
  - Steps to test in WHMCS (or note “manual test pending”).
  - Any config or environment assumptions (OpenVPN-AS version, Docker host).

## Security & Configuration Tips

- Never commit credentials or private keys; use WHMCS server config fields.
- Use HTTPS and restrict API access to the WHMCS host IP.
- When debugging, redact secrets from logs or screenshots.
