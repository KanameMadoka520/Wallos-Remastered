---
phase: 01-regression-safety-baseline
status: passed
verified: 2026-04-20T17:00:00+08:00
requirements_verified: [SAFE-01, SAFE-02, SAFE-03]
---

# Phase 1: 自动化回归检查基线 Verification

## Verdict

Phase 1 passed. The phase goal was to establish a minimum local regression entrypoint for the highest-risk contracts, and that goal is now met.

## Goal Check

- **Single command regression entrypoint:** passed via `tests/regression_runner.php`
- **Structured summary + non-zero failure signaling:** passed via unified PASS / FAIL / SKIP summary and runner exit semantics
- **Configurable base URL / cookie / credentials:** passed via CLI flags and env fallbacks
- **Public + pagination regression coverage:** passed with public suite green and auth suite validating the unauthenticated contract plus explicit credential-aware SKIP behavior

## Evidence

### Syntax checks
- `docker exec wallos-local php -l /var/www/html/tests/regression_runner.php`
- `docker exec wallos-local php -l /var/www/html/tests/lib/regression_bootstrap.php`
- `docker exec wallos-local php -l /var/www/html/tests/lib/regression_http.php`
- `docker exec wallos-local php -l /var/www/html/tests/lib/regression_output.php`
- `docker exec wallos-local php -l /var/www/html/tests/lib/regression_checks.php`
- `docker exec wallos-local php -l /var/www/html/tests/lib/regression_legacy.php`

### Regression runner checks
- `docker exec wallos-local php /var/www/html/tests/regression_runner.php --base-url=http://127.0.0.1 --public-only` -> `5 pass | 0 fail | 0 skip`
- `docker exec wallos-local php /var/www/html/tests/regression_runner.php --existing-only` -> `3 pass | 0 fail | 0 skip`
- `docker exec wallos-local php /var/www/html/tests/regression_runner.php --base-url=http://127.0.0.1 --auth-only` -> `1 pass | 0 fail | 3 skip`

### Deployment health
- `http://127.0.0.1:18282/health.php` -> `OK`
- `wallos-local` container status -> `healthy`

## Requirement Coverage

- **SAFE-01:** satisfied by public smoke coverage, auth endpoint coverage, and legacy regression integration
- **SAFE-02:** satisfied by structured PASS / FAIL / SKIP output and non-zero-on-failure runner design
- **SAFE-03:** satisfied by `--base-url`, `--cookie`, `--username`, `--password` and matching env fallbacks

## Must-have Review

### Plan 01-01
- CLI entrypoint exists and accepts config from flags/env
- Shared HTTP helper persists cookies and exposes status/header/body data
- Structured summary output exists

### Plan 01-02
- Public smoke coverage exists for `health.php`, `login.php`, `registration.php`, `theme-color`, and service-worker contract signals
- Auth suite validates the unauthenticated pagination contract and cleanly skips auth-positive checks without secrets
- Existing PHP regression scripts are wrapped into the same runner

## Remaining Notes

- The authenticated positive-path checks are intentionally not forced when credentials are absent; the runner now surfaces this as explicit `SKIP`, which is acceptable for this baseline phase.
- Future phases can strengthen auth coverage by supplying `WALLOS_TEST_COOKIE` or username/password in CI or operator shells.

---
*Phase: 01-regression-safety-baseline*
*Verified: 2026-04-20*
