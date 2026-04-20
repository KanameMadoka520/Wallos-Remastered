---
phase: 02-session-and-401-unification
status: passed
verified: 2026-04-20T19:25:00+08:00
requirements_verified: [SESS-01, SESS-02, SESS-03]
---

# Phase 2: 会话与 401 统一层 Verification

## Verdict

Phase 2 passed. Shared backend auth/session restoration now exists, high-risk async endpoints emit a standardized session-expired contract, and the frontend request layer can recognize that contract in one place.

## Goal Check

- **Shared backend helper:** passed via `includes/auth_session.php`
- **Consistent async 401 contract for high-risk endpoints:** passed via unified JSON payloads with `code=session_expired`
- **Shared frontend handling entrypoint:** passed via `common.js` / `api.js` request-layer recognition and reduced raw module parsing

## Evidence

### Syntax checks
- `docker exec wallos-local php -l /var/www/html/includes/auth_session.php`
- `docker exec wallos-local php -l /var/www/html/includes/connect_endpoint.php`
- `docker exec wallos-local php -l /var/www/html/includes/checksession.php`
- `docker exec wallos-local php -l /var/www/html/includes/validate_endpoint.php`
- `docker exec wallos-local php -l /var/www/html/endpoints/media/subscriptionimage.php`
- `docker exec wallos-local php -l /var/www/html/endpoints/subscriptions/get.php`
- `docker exec wallos-local php -l /var/www/html/endpoints/payments/get.php`
- `docker exec wallos-local php -l /var/www/html/endpoints/subscription/paymenthistory.php`
- `docker exec wallos-local php -l /var/www/html/endpoints/subscription/getcalendar.php`
- `docker exec wallos-local php -l /var/www/html/endpoints/subscriptionpages.php`
- `docker exec wallos-local php -l /var/www/html/endpoints/subscription/get.php`

### JavaScript checks
- `node --check scripts/common.js`
- `node --check scripts/api.js`
- `node --check scripts/settings.js`
- `node --check scripts/calendar.js`
- `node --check scripts/subscriptions.js`

### Regression runner checks
- `docker exec wallos-local php /var/www/html/tests/regression_runner.php --base-url=http://127.0.0.1 --auth-only` -> `3 pass | 0 fail | 3 skip`
- `docker exec wallos-local php /var/www/html/tests/regression_runner.php --base-url=http://127.0.0.1` -> `11 pass | 0 fail | 3 skip`

### Deployment health
- `http://127.0.0.1:18282/health.php` -> `OK`
- `wallos-local` container status -> `healthy`

## Requirement Coverage

- **SESS-01:** satisfied by shared backend auth/session helper and removal of duplicated recovery cores from key paths
- **SESS-02:** satisfied by standardized `session_expired` JSON failure contract for high-risk protected endpoints
- **SESS-03:** satisfied by shared frontend request-layer session failure recognition and reduced raw module parsing

## Must-have Review

### Plan 02-01
- Shared backend auth/session helper exists
- Async session failure payload helper exists
- Endpoint auth guard helper exists

### Plan 02-02
- Page bootstrap uses the shared helper
- High-risk async endpoints no longer mix raw text and inconsistent JSON for session-expired failures
- Media access reuses shared auth/session restoration while keeping 403/404/429 semantics

### Plan 02-03
- Frontend request errors expose session-failure metadata
- High-risk frontend modules consume shared detection helpers
- Regression suite enforces the standardized unauthenticated JSON 401 contract

## Remaining Notes

- Some lower-priority endpoints still have not been migrated to the new contract; this is acceptable because Phase 2 intentionally targeted the highest-risk chains first.
- Positive-path authenticated smoke remains optional and credential-driven; in no-secret environments it degrades to explicit `SKIP`.

---
*Phase: 02-session-and-401-unification*
*Verified: 2026-04-20*
