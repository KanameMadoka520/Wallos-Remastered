# Wallos-Remastered

> A Wallos branch remastered for long-lived self-hosted installations. Current release: `v5.4.5-remastered.5`; upstream compatibility baseline: [`ellite/Wallos v5.4.5`](https://github.com/ellite/Wallos/tree/v5.4.5).

[简体中文 README](README.md) · [Changelog (Chinese)](CHANGELOG.md) · [Security policy](SECURITY.md) · [Contributing](CONTRIBUTING.md)

## What this project is

Wallos-Remastered keeps the core Wallos subscription, statistics, multi-currency, notification, OIDC, and TOTP capabilities. It adds a Chinese-first experience, controlled multi-user operation, and maintenance features intended for a server that stays online for years.

This is not an unmodified copy of the upstream UI or a repackaged official image. Relevant fixes from upstream `v5.4.5` were ported deliberately and integrated with this branch's ledger, media, theme, and admin systems. Its deployment model, database schema, and some interactions therefore differ from official Wallos.

## Key capabilities

- **Spending follows real payments:** record actual payments, special prices, one-time purchases, and monthly, yearly, or payment-period budgets; calendars and forecasts use the same data.
- **Renewal dates stay anchored:** month-end, leap-day, manual-renewal, and scheduled updates share one date model. A January 31 renewal temporarily lands at February month-end, then returns to March 31.
- **Large collections are manageable:** custom pages, drag sorting, one/two/three-column layouts, trash, detail popups, and account-synced display preferences.
- **Subscription media goes beyond one logo:** store multiple images with thumbnail, preview, and original layers; private media is authenticated, and monochrome text logos can swap variants with the light/dark theme.
- **Controlled multi-user hosting:** invitation codes, groups, bans and delayed deletion, admin password resets, login rate limits, request logs, and anomaly records.
- **Backup and maintenance lifecycle:** automatic/manual database and logo backups, verification, download, restore, SQLite maintenance, log review, and image consistency checks.
- **Container-friendly notifications and SSO:** send a spending summary at the beginning of a payment period and configure an identity provider declaratively with `OIDC_*` variables.
- **Predictable translation fallback:** upstream language choices remain available; a known term missing in one locale falls back to English instead of exposing `[Translation Missing]`.
- **Remastered presentation:** dynamic wallpaper, glass effects, custom themes/CSS, page transitions, immersive mode, and account-synced preferences.

## Differences from upstream Wallos v5.4.5

| Area | Upstream Wallos | Wallos-Remastered |
| --- | --- | --- |
| Focus | General personal subscription tracker | Chinese-first, controlled multi-user, long-running self-hosted branch |
| Deployment | Commonly uses the official prebuilt image | Builds this repository; defaults to port `18282` and pins the base image and important dependencies |
| Spending model | Upstream subscription and statistics model | Adds an actual-payment ledger, special prices, value estimates, and several budgets with a shared forecast model |
| Organization | Standard upstream list and filters | Adds pages, drag sorting, multi-column layout, trash, and detail popups |
| Media | Primarily the upstream logo workflow | Adds themed logo variants, multi-image storage, three derived layers, authenticated reads, auditing, and bounded cleanup |
| Administration | Upstream account/admin features | Adds invites, groups, bans, rate limits, access logs, maintenance advice, and action auditing |
| Backup/restore | Keeps upstream capabilities | Extends them to database + logos, manifest verification, admin restore, runtime locking, and failure rollback |
| Container SSO | OIDC can be managed in the UI | Keeps UI management and adds `OIDC_*` overrides, issuer discovery, and secret-file loading |
| UI | Upstream pages and themes | Adds Chinese defaults, dynamic wallpaper, transitions, and account-level theme/layout preferences |

“Compatibility baseline” means that applicable `v5.4.5` behavior has been incorporated; it does not mean both trees are file-for-file identical. For example, logo search keeps Remastered's DuckDuckGo + Brave path instead of copying upstream's complete Google/selfh.st/Dashboard Icons UI. Do not mix the official and Remastered images or exchange databases without a tested backup.

## Docker deployment

### Requirements

- Docker Engine 25 or newer
- Docker Compose V2
- A Linux host is recommended, with persistent space for the database, media, and backups

The included Compose file uses strict bind mounts. Missing host paths are not silently created, reducing the risk of a typo starting an apparently empty new instance.

### Fresh installation

```bash
git clone https://github.com/KanameMadoka520/Wallos-Remastered.git
cd Wallos-Remastered
mkdir -p logos backups
cp -n db/wallos.empty.db db/wallos.db
docker compose up -d --build
```

Run `cp -n` **only for a fresh installation where `db/wallos.db` does not exist**; `-n` refuses to overwrite an existing file. The template is migrated offline on first startup. Existing instances should skip the initialization block and follow the upgrade procedure below.

Default persistent paths and port:

| Host path | Container purpose |
| --- | --- |
| `./db` | SQLite database |
| `./logos` | Logos, avatars, and subscription images |
| `./backups` | Automatic and manual backups |
| `18282` | Web port |

Verify startup:

```bash
docker compose ps
curl http://127.0.0.1:18282/health.php
docker compose logs --tail=100 wallos
```

A healthy endpoint returns `OK`. Open `http://HOST:18282` and register; the first account (`id = 1`) is the initial administrator. Immediately review registration, invitation, login-rate-limit, and backup-retention settings. The About page should show both the Remastered release and the upstream baseline.

### Upgrading an existing Remastered instance

Create and verify a backup in the admin UI first, then copy `db`, `logos`, and `backups` to another disk or machine. After confirming that copy:

```bash
docker compose down
git fetch --tags
git checkout v5.4.5-remastered.5
docker compose up -d --build
curl http://127.0.0.1:18282/health.php
```

Before the web server and scheduler start, the entrypoint checks the database, runs migrations offline, and verifies the resulting schema. A missing/empty database, conflicting migration ledger, or unsupported schema stops startup instead of creating a deceptively valid empty database.

Upgrade rules:

- Never copy `wallos.empty.db`, delete existing persistent data, or replace its directories with empty mounts.
- If you customized `docker-compose.yaml`, merge port, timezone, user-ID, reverse-proxy, secret, and OIDC settings manually.
- Migrations from official Wallos or an old custom fork must be rehearsed against a database copy. Migration support cannot infer every third-party schema modification.
- On failure, inspect `docker compose logs wallos`, preserve the original files and backup, and do not retry with an empty database.

### Optional: payment-period start summary

Enable “Send summary at start of each payment period” under Settings → Notifications and configure at least one channel that supports text summaries. In the account timezone, the scheduled job sends the projected amount needed on the first day of each weekly, fortnightly, or monthly period; when the payment-period budget is greater than `0`, it also includes the projected remainder. Normal text channels include it automatically; a custom webhook receives it only when its payload contains `{{period_summary}}`. The option is off by default and only sends a calculated summary—it does not create payment records or change renewal dates.

### Optional: declarative OIDC

OIDC can be configured in the admin UI or in the Compose `environment` section. A supplied `OIDC_*` value overrides its database counterpart at runtime; environment secrets are not copied back into the database.

- `OIDC_ENABLED`, `OIDC_PROVIDER_NAME`, `OIDC_CLIENT_ID`: enablement, display name, and client ID.
- `OIDC_ISSUER`: loads `/.well-known/openid-configuration`; `OIDC_AUTH_URL`, `OIDC_TOKEN_URL`, and `OIDC_USERINFO_URL` can override individual discovered endpoints.
- `OIDC_CLIENT_SECRET_FILE`: reads the client secret from a file inside the container and takes priority over `OIDC_CLIENT_SECRET`; prefer a Docker Secret or read-only mount.
- `OIDC_REDIRECT_URL`, `OIDC_LOGOUT_URL`, `OIDC_USER_IDENTIFIER`, `OIDC_SCOPES`: login-flow details.
- `OIDC_AUTO_CREATE_USER`, `OIDC_DISABLE_PASSWORD_LOGIN`, `OIDC_REQUIRE_EMAIL_VERIFIED`: account creation, password-login, and verified-email policy.

For an identity provider on a private network, add the exact host or `host:port` to the admin SSRF allowlist, or set `SSRF_ALLOWLIST`. Test a complete administrator login before disabling password authentication. Never commit the client secret in a repository Compose file.

## Backup and restore

The admin UI creates, downloads, verifies, and restores backups and controls automatic retention (14 days by default). A Remastered archive contains the SQLite database, logos/avatars/subscription images, and a verification manifest.

Restore verifies every archived file, fully stages the new database and media inside their respective Docker volumes, migrates them, and marks the transaction committed only after joint verification. A pre-commit failure restores the old state; if rollback cannot finish, a durable journal keeps requests and container startup fail-closed instead of serving half-restored data. Public media returns maintenance status during the short cutover window rather than exposing a mixed tree. These protections do not replace off-host backups: periodically download an archive, restore it into a test instance, and inspect subscriptions and images.

`/db/`, `/backups/`, and private subscription media must not be served as static paths. If the database is damaged or the container cannot start, copy the complete state before doing anything else; do not unpack files over a running instance. First restore into an empty instance is restricted to a direct local request to prevent public takeover.

## Security notes

- Public, FRP, and reverse-proxy deployments must keep normal authentication and enable HTTPS at the edge. “Disable login” bypasses authentication only for a genuine direct-local request.
- Send API keys in `X-API-Key` or `Authorization: Bearer ...`, not in URLs, logs, or screenshots.
- Never commit administrator credentials, cookies, GitHub tokens, `.env`, databases, media, backups, or OIDC secrets.
- Page CSRF tokens have a server-enforced 30-minute lifetime; refresh a long-idle page before submitting a form.
- External logo, SMTP, webhook, and OIDC destinations are subject to SSRF and redirect controls. Rejection of private or unusual redirect targets can be expected behavior.
- Expose port `18282` only to a trusted network or reverse proxy and review anomalies, slow requests, image audits, and maintenance actions regularly.

See [SECURITY.md](SECURITY.md) for the full boundary and reporting process. The Chinese [FRP + Nginx + Fail2ban guide](FRP+Nginx+Fail2ban防刷站部署指南.md) covers a public gateway deployment.

## Repository layout

```text
api/, endpoints/          APIs, form actions, scheduled jobs, and database entrypoints
includes/                 Shared billing, currency, media, backup, and security logic
migrations/               Ordered SQLite migrations
scripts/, styles/         Frontend interactions, themes, and styling
tests/                    PHP/Playwright tests (excluded from the production image)
db/                       Database template and runtime wallos.db
logos/                    Runtime media bind mount (create it before first startup)
backups/                  Runtime backup directory
Dockerfile                Production source-build image
docker-compose.yaml       Default single-container deployment
startup.sh, nginx*.conf   Startup checks, process supervision, and web security boundaries
```

Runtime data does not belong in version control, and ignore rules are not a security boundary. Before every release, inspect root `logos/`, `db/wallos.db`, `backups/`, and `git status`; never stage real media, databases, archives, or credentials.

## Development and testing

Normal changes should receive PHP/JavaScript syntax checks, a health check, and the regression runner. The production image excludes `tests/` through `.dockerignore`, so `/var/www/html/tests` is not available inside the running app container. From the repository root on a Linux host, mount the source read-only into a temporary container and reuse the built image's PHP environment:

```bash
docker run --rm --network host --entrypoint php \
  -v "$PWD:/work:ro" \
  wallos-remastered:v5.4.5-remastered.5 \
  /work/tests/regression_runner.php --base-url=http://127.0.0.1:18282
```

The `--network host` example uses Linux Docker host networking. On Docker Desktop, choose the platform-specific hostname that reaches the host instead of assuming that container `127.0.0.1` is the application host.

For real create/edit/payment/delete paths, use a dedicated test account and append `--username`, `--password`, and `--mutating-auth-checks`. This mode writes temporary data; do not run it casually with an important production account.

Browser tests require Node.js:

```bash
npm ci
WALLOS_BASE_URL=http://127.0.0.1:18282 \
WALLOS_TEST_USERNAME=YOUR_TEST_USER \
WALLOS_TEST_PASSWORD=YOUR_TEST_PASSWORD \
npm run e2e:subscriptions
```

Run `e2e:i18n`, `e2e:images`, `e2e:cache`, and `e2e:admin` separately, or `npm run e2e` in sequence. Admin tests require explicit, unexpired credentials. See [CONTRIBUTING.md](CONTRIBUTING.md) and the Chinese [shared request/stability contract](docs/共享请求层与稳定性契约.md) for detailed rules.

Feature contributions should review migrations, authorization, translations, tests, and docs together. Report reproducible problems in [Issues](https://github.com/KanameMadoka520/Wallos-Remastered/issues), or follow [CONTRIBUTING.md](CONTRIBUTING.md) for code changes.

## License and links

This branch remains under [GNU GPLv3](LICENSE.md). It is a community remaster; report branch-specific behavior here rather than asking upstream Wallos to support these customizations.

- [Wallos-Remastered repository](https://github.com/KanameMadoka520/Wallos-Remastered)
- [Upstream Wallos](https://github.com/ellite/Wallos)
- [Full Remastered changelog (Chinese)](CHANGELOG.md)
- [Simplified Chinese README](README.md)
