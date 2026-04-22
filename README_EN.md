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

