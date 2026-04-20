---
phase: 02-session-and-401-unification
plan: 01
subsystem: auth
tags: [php, session, cookies, endpoint, 401]
requires: []
provides:
  - Shared backend auth/session helper
  - Unified async session-expired payload contract
  - Shared endpoint auth guard helper
affects: [phase-02-plan-02, phase-02-plan-03, api, media]
tech-stack:
  added: [includes/auth_session.php]
  patterns: [shared auth helper, standardized async auth failure payload]
key-files:
  created:
    - includes/auth_session.php
  modified:
    - includes/connect_endpoint.php
    - includes/validate_endpoint.php
key-decisions:
  - "Extracted auth/session restoration into a shared helper instead of keeping cookie parsing inside connect_endpoint.php."
  - "Standardized async session failure around machine-readable `code=session_expired` payloads."
patterns-established:
  - "Backend auth/session pattern: start session -> resolve user -> emit typed async failure when needed."
  - "Endpoint guard pattern: shared `wallos_endpoint_require_authenticated()` gate instead of per-file ad hoc checks."
requirements-completed: [SESS-01]
duration: 40min
completed: 2026-04-20
---

# Phase 2: 会话与 401 统一层 Summary

**共享后端会话恢复辅助层已经建立，异步接口现在有统一的 session-expired JSON 契约基础。**

## Performance

- **Duration:** 40 min
- **Started:** 2026-04-20T17:10:00+08:00
- **Completed:** 2026-04-20T17:50:00+08:00
- **Tasks:** 3
- **Files modified:** 3

## Accomplishments
- 新增 `includes/auth_session.php` 作为共享会话恢复与 cookie/token 校验入口
- 让 `connect_endpoint.php` 从“自己维护一整套登录恢复逻辑”变成“调用共享 helper”
- 让 `validate_endpoint.php` 输出统一的异步失败契约，而不是零散 JSON 结构

## Task Commits

Each task was committed atomically:

1. **Task 1: Extract shared backend auth/session helper** - `dff670b` (重构)
2. **Task 2: Define standardized async session failure contract** - `dff670b` (重构)
3. **Task 3: Keep Phase 1 regression runner aligned with backend foundation changes** - `dff670b` (重构)

**Plan metadata:** `9422c69` (文档)

## Files Created/Modified
- `includes/auth_session.php` - 共享会话恢复、cookie 清理、token 校验与异步错误 payload helper
- `includes/connect_endpoint.php` - 改为消费共享 helper，并保留 endpoint 特有的速率限制/请求日志行为
- `includes/validate_endpoint.php` - 统一 POST/CSRF/session 失败输出格式

## Decisions Made
- 共享 helper 负责认证核心，入口文件只保留输出语义差异
- 异步会话失效统一输出 `success/code/error/message/session_expired/requires_relogin`

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

- 为了保证最小破坏，页面重定向语义没有在这一步改成 JSON，而是保留到页面链路迁移时单独处理

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- `02-02` 可以直接把高风险页面、endpoint 和媒体链路迁移到共享 helper
- `02-03` 可以在这个统一契约上继续收口前端 401 处理逻辑

---
*Phase: 02-session-and-401-unification*
*Completed: 2026-04-20*
