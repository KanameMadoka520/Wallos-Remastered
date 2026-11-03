# Web Push

Web Push is an optional notification channel. Open **Settings → Notifications → Web Push**, give the device a name, then choose **Enable this device**. The browser asks for permission only after that click. The subscription also enables this account's Web Push channel. Save the account toggle to pause reminders on all devices; revoke an individual device to remove its endpoint. Devices can be renamed and tested independently (maximum 20 per account).

HTTPS and a browser with Push API, Notifications API and Service Workers are required. iOS/iPadOS 16.4+ requires the web app to be added to the Home Screen. Browser/system policies, Focus modes, network access to the browser vendor and OS delivery scheduling affect notification arrival. A successful test means the vendor accepted the encrypted message; check the target device to verify delivery. Existing email/ntfy/other channels remain available.

Default notification text is generic. Opting into details adds the subscription name and date, which can be visible on the lock screen. No notes, credentials, prices or private images are sent. Notification clicks open this installation's authenticated index page; payloads cannot choose a destination URL. Notifications already accepted by a push service cannot be recalled, including after revocation.

## Account and device boundaries

Only a logged-in browser session with a valid CSRF token can register/list/rename/revoke/test its own devices. This is separate from MCP and does not add any Agent scopes. Device lists never expose endpoints, auth secrets or VAPID private keys. Device identity uses a random HttpOnly SameSite cookie stored as a SHA-256 hash. Logout (including OIDC redirects) revokes this browser on the server. On account change, authenticated page/API entrypoints revoke any binding belonging to the previous account; enabling again creates a fresh browser subscription. Other devices remain bound. Account trashing revokes devices permanently; restoring an account requires re-enrollment. Permanent deletion removes settings, devices and delivery claims.

## Operations and security

Migration 85 adds `push_vapid`, `push_notifications`, `push_subscriptions` and `push_delivery_claims`. It is additive and does not rewrite subscriptions, ledger records, existing notification settings or the Service Worker's private-cache policy. Upstream v5.10.4's OpenSSL implementation of RFC 8291/8292 is reused and tested against the published RFC encryption vector. No additional Composer dependency is required.

The VAPID keypair is generated once using atomic insert-and-reread and kept in its own singleton table. Database backups contain private VAPID material, device endpoint capabilities and auth secrets: continue to store them privately. Restore the whole database to preserve device compatibility; rotating or losing the keypair requires devices to enroll again. The key is deliberately absent from broad `admin` settings serialization. PHP OpenSSL, cURL and mbstring are needed (already present in the supported container).

Subscriptions accept HTTPS port 443 endpoints only on browser-vendor domains: `fcm.googleapis.com`, `*.push.services.mozilla.com`, `*.push.apple.com`, `*.notify.windows.com` (and their exact base names). Before every send, DNS is resolved again and pinned to a public IPv4 address for TLS/cURL, with redirects disabled. Local webhook allowlists cannot permit LAN endpoints here. IPv6-only endpoints and custom/self-hosted browser push providers currently fail closed; adding one requires an explicit vetted allowlist change. Outbound firewall/proxy rules must permit supported services.

Renewal and cancellation cron jobs use the existing per-account/per-subscription reminder timing and active/non-trash selection. Push events are claimed per device, event type, subscription and due date, preventing duplicate cron execution. Failed sends can be retried by a later cron run after five minutes, up to three attempts; delivery is not an exactly-once guarantee if the vendor accepted a message before the connection failed. 404/410 subscriptions are pruned, other failures retain the device. Claims older than 90 days are cleaned during sends. Test sends are throttled to one per device per minute. A 24-hour TTL bounds stale queued messages.

The existing `service-worker.js` gains only `push` and `notificationclick` handlers. PHP/API/private media and documents continue to bypass caches. Permission grants alone do not enroll a device: both browser subscription creation and the authenticated server registration must succeed.

## Verification

`tests/webpush_test.php` uses an in-memory database only, exercises migration idempotency, RFC encryption/signatures, concurrent initialization semantics, ownership, device limits, deduplication and revocation. `tests/e2e/webpush_smoke.mjs` verifies settings UI, CSRF/session boundary and worker notification event behavior using an isolated test instance. Actual end-to-end vendor delivery still requires a supported real browser/device and a user permission gesture; a mock subscription is not evidence of push-service delivery.

Restoring an older database runs migrations 84–86 before promotion. Final restore validation checks the feature tables, required columns and relation/device indexes according to the image migration inventory; a backup with a completed ledger but missing feature schema is rejected. `tests/feature_restore_test.php` covers an 83-to-86 synthetic restore and corrupt completed ledgers.
