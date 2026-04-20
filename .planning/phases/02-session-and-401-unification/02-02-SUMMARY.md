---
phase: 02-session-and-401-unification
plan: 02
subsystem: auth
tags: [php, endpoint, media, session, 401]
requires:
  - phase: 02
    provides: shared backend auth/session helper
provides:
  - Page bootstrap moved onto shared auth/session core
  - High-risk endpoints converged on standardized session-expired payloads
  - Media access path shares auth restoration without losing binary HTTP semantics
affects: [phase-02-plan-03, subscriptions, settings, calendar, media]
tech-stack:
  added: []
  patterns: [shared auth reuse across pages/endpoints/media, contract-consistent async failures]
key-files:
  created: []
  modified:
    - includes/checksession.php
    - endpoints/media/subscriptionimage.php
    - endpoints/subscriptions/get.php
    - endpoints/payments/get.php
    - endpoints/subscription/paymenthistory.php
    - endpoints/subscription/getcalendar.php
    - endpoints/subscriptionpages.php
    - endpoints/subscription/get.php
key-decisions:
  - "Kept page failures as redirects while standardizing async endpoint failures as JSON."
  - "Preserved media 403/404/429 plain HTTP behavior while still reusing the shared auth core."
patterns-established:
  - "High-risk endpoint pattern: include connect_endpoint then call shared auth gate instead of ad hoc session checks."
  - "Media pattern: reuse shared auth restoration but keep non-JSON binary-friendly denial responses."
requirements-completed: [SESS-01, SESS-02]
duration: 45min
completed: 2026-04-20
---

# Phase 2: 会话与 401 统一层 Summary

**高风险页面、endpoint 与媒体链路已经切到共享会话恢复逻辑，并且关键异步失败契约收拢到统一结构。**

## Performance

- **Duration:** 45 min
- **Started:** 2026-04-20T17:50:00+08:00
- **Completed:** 2026-04-20T18:35:00+08:00
- **Tasks:** 3
- **Files modified:** 8

## Accomplishments
- `checksession.php` 已改为走共享 auth/session helper，而不是再维护一套独立 cookie 恢复逻辑
- `subscriptions/get.php`、`subscriptionpages.php`、`payments/get.php`、`paymenthistory.php`、`getcalendar.php` 等高风险 endpoint 收敛到统一 session-expired 失败契约
- `subscriptionimage.php` 改为复用共享 helper，同时保留 403 / 404 / 429 的媒体友好输出

## Task Commits

Each task was committed atomically:

1. **Task 1: Migrate page bootstrap to the shared auth/session helper** - `dd8b7c9` (重构)
2. **Task 2: Standardize high-risk async endpoint session failures** - `dd8b7c9` (重构)
3. **Task 3: Migrate media auth/session recovery without breaking binary HTTP behavior** - `dd8b7c9` (重构)

**Plan metadata:** `9422c69` (文档)

## Files Created/Modified
- `includes/checksession.php` - 页面请求改用共享 helper
- `endpoints/subscriptions/get.php` - unauthenticated failure 改为统一 JSON 401 契约
- `endpoints/payments/get.php` - 401 改为统一 JSON 401 契约
- `endpoints/subscription/paymenthistory.php` - 统一 async auth guard
- `endpoints/subscription/getcalendar.php` - 统一 async auth guard
- `endpoints/subscriptionpages.php` - 统一 async auth guard
- `endpoints/subscription/get.php` - GET JSON 接口补上统一 auth guard
- `endpoints/media/subscriptionimage.php` - 媒体访问复用共享 auth helper

## Decisions Made
- 页面与媒体链路保留各自输出风格，只统一认证核心与失败语义
- 高风险 endpoint 优先收口，避免一次性迁所有后端接口导致大面积回归

## Deviations from Plan

### Auto-fixed scope extension

**1. Added `endpoints/subscription/get.php` and `endpoints/subscriptionpages.php` to the migration batch**
- **Reason:** 这两个接口正好处在订阅页高频链路上，不统一会让新旧契约同时存在，影响后续前端收口
- **Impact:** 仍然属于 Phase 2 边界内的高风险链路收敛，没有引入新功能

## Issues Encountered

- `payments/get.php` 的成功态仍然返回 HTML，因此前端侧必须同步补强 request error 识别，否则 session 失效时容易把 JSON 字符串误插进 DOM

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- `02-03` 可以直接在统一后的 401 契约上做前端请求层收口和 regression 扩充

---
*Phase: 02-session-and-401-unification*
*Completed: 2026-04-20*
