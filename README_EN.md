# Wallos-Remastered

[简体中文 README](README.md)

## Overview

`Wallos-Remastered` is a heavily customized branch based on upstream `Wallos`, designed for stronger admin operations, multi-user governance, controlled media delivery, and backup lifecycle management.

This repository is no longer identical to official Wallos defaults.

## Major Differences From Official Wallos

- recommended deployment is a source build from this repository
- default compose example uses port `18282`
- persistent runtime directories include:
  - `db`
  - `logos`
  - `backups`
- stronger admin tooling
- protected subscription media access
- automated backups, verification, and restore-from-list

## First Admin Account

When the database is empty:

- the app redirects to registration
- the first successfully registered account becomes the initial admin
- the current system treats user `id = 1` as the administrator

For a fresh deployment, create your own admin account first.

## Public Page Language Behavior

- login page defaults to Simplified Chinese
- registration page defaults to Simplified Chinese
- both pages include a language switcher
- the current registration page language becomes the new user's initial language

## Recommended Docker Build Flow

Clone the repository and run from the repository root:

```bash
git clone https://github.com/KanameMadoka520/Wallos-Remastered.git
cd Walllos_Remastered
docker compose up -d --build
```

## Default docker-compose Behavior

The repository root ships with a source-build `docker-compose.yaml`:

```yaml
services:
  wallos:
    build:
      context: .
      dockerfile: Dockerfile.local
    image: wallos-remastered:latest
    container_name: wallos-remastered
    restart: unless-stopped
    ports:
      - "18282:80"
    environment:
      TZ: "Asia/Shanghai"
    volumes:
      - "./db:/var/www/html/db"
      - "./logos:/var/www/html/images/uploads/logos"
      - "./backups:/var/www/html/backups"
```

## Health Check

```bash
curl http://127.0.0.1:18282/health.php
```

Expected response:

```text
OK
```

## Regression Checks

After changing high-risk pages or shared request logic, run:

```bash
docker exec wallos-local php /var/www/html/tests/regression_runner.php --base-url=http://127.0.0.1
```

The runner checks public pages, default purple theme behavior, Service Worker cache contracts, unauthenticated endpoint `401` contracts, subscription page JSON/HTML contracts, subscription frontend static contracts, API key transport rules, subscription image size slots, and the existing PHP logic regressions.

Authenticated smoke checks can log in with a dedicated test account:

```bash
docker exec wallos-local php /var/www/html/tests/regression_runner.php \
  --base-url=http://127.0.0.1 \
  --username=YOUR_TEST_USER \
  --password=YOUR_TEST_PASSWORD
```

The optional mutating flow creates, edits, records a payment for, moves to trash, and permanently deletes a temporary subscription:

```bash
docker exec wallos-local php /var/www/html/tests/regression_runner.php \
  --base-url=http://127.0.0.1 \
  --username=YOUR_TEST_USER \
  --password=YOUR_TEST_PASSWORD \
  --mutating-auth-checks
```

For browser-level subscription-page checks, install Playwright locally and run:

```bash
npm install
WALLOS_BASE_URL=http://127.0.0.1:18282 \
WALLOS_TEST_USERNAME=YOUR_TEST_USER \
WALLOS_TEST_PASSWORD=YOUR_TEST_PASSWORD \
npm run e2e:subscriptions
```

The browser smoke opens the real page and clicks pagination, the three-dot action menu, edit modal, add-subscription save flow, payment history, image viewer hooks, display-column toggles, and the dynamic-wallpaper immersive button. It removes the temporary subscription it creates during the check.

## Highlights

### Admin

- user cards
- user ID display and copy
- admin-triggered temporary password reset
- invite lifecycle management
- banned user list
- configurable login rate limit threshold

### Subscription Media

- multiple server-hosted images per subscription
- original / preview / thumbnail layering
- when upload compression is disabled, the original file is stored byte-for-byte; when compression is enabled, the stored original is processed once
- the form shows local file size before upload
- the image viewer shows thumbnail / preview / original server-side sizes
- if a generated preview or thumbnail would be larger than the original, that derived layer can reuse the original instead of storing a wasteful larger file
- protected media endpoint
- drag-and-drop ordering
- upload and processing progress
- original image loading progress
- one-click generation for missing legacy derived images

### Subscription Organization

- tab-like subscription pages for splitting large subscription lists
- `All / Unassigned / Custom Pages` page filters on the subscriptions screen
- page assignment can be changed when creating or editing a subscription

### Backup Lifecycle

- automated `db + logos` backups
- backup list in admin
- manual backup and download
- backup verification
- cleanup of old backups
- direct restore from the admin backup list

### Security Tokens

- CSRF tokens now have a server-enforced 30-minute TTL.
- The footer shows only a short fingerprint of the current page token, not the full token.
- Footer token times are displayed in the current account timezone.
- Login sessions can still last up to 30 days when "stay logged in" is used; this is separate from the shorter CSRF form token lifetime.
- If a page stays open for longer than 30 minutes, refresh the page before submitting forms or other sensitive actions.

### Service Worker Cache Refresh

- Static assets use stricter filemtime-based versions in the page shell.
- `service-worker.js` exposes a client-cache clear message.
- The admin page can clear the current browser cache and publish a refresh marker so other clients clear cached static assets on their next page load.

### Maintenance Tools

The admin page includes a maintenance area for long-running deployments:

- retention-policy visibility for request logs, security anomalies, and rate-limit usage
- subscription image storage audit for missing derived-image rows and orphan files
- one-click reuse of originals for preview/thumbnail variants that are larger than the original, with cleanup of unreferenced oversized derived files
- manual SQLite `PRAGMA optimize`, `ANALYZE`, and `VACUUM`

`VACUUM` can briefly lock writes, so run it during a quiet maintenance window.

## Repository Publication Notes

This public repository has been cleaned up to avoid publishing private deployment details such as:

- private infrastructure descriptions
- private directory layouts
- self-hosted banner text
- environment-specific local path references
- deployment-specific internal handoff notes

Runtime-only files are also ignored through `.gitignore` and `.dockerignore`.

## Related Documents

- `README.md`
- `CONTRIBUTING.md`
- `CHANGELOG.md`
- `SECURITY.md`
- `FRP+Nginx+Fail2ban防刷站部署指南.md`

## 2026-04 Security Behavior Changes

The following behavior changes were added to reduce risk on public deployments, FRP tunnels, reverse proxies, and VPS gateway setups:

- `Disable login` is no longer equivalent to “auto-login any visitor as admin”. The bypass now works only for direct local requests.
- If the instance is exposed through a public domain, FRP, or a reverse proxy, keep normal login enabled and use the regular admin login flow.
- Initial database restore on an empty instance is now limited to direct local access to prevent hostile first-import takeover on fresh deployments.
- API keys should no longer be passed through URL query strings. Prefer `X-API-Key` or `Authorization: Bearer ...` headers so keys do not leak into browser history or proxy logs.
- Payment icon remote fetching now validates redirects more strictly. Some unusual redirect-heavy URLs may be rejected by design.

