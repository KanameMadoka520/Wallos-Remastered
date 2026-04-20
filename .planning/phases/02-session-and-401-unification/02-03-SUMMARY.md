---
phase: 02-session-and-401-unification
plan: 03
subsystem: frontend
tags: [javascript, api, 401, regression, settings, calendar]
requires:
  - phase: 02
    provides: standardized async session failure contract
provides:
  - Shared frontend session-failure recognition
  - Fewer raw per-module 401 checks
  - Regression suite upgraded to assert standardized 401 JSON contracts
affects: [phase-03-observability-and-feedback, subscriptions, settings, calendar, testing]
tech-stack:
  added: [shared session-expired error metadata on request errors]
  patterns: [request-layer session detection, contract-based unauth smoke]
key-files:
  created: []
  modified:
    - scripts/common.js
    - scripts/api.js
    - scripts/settings.js
    - scripts/calendar.js
    - scripts/subscriptions.js
    - tests/lib/regression_bootstrap.php
    - tests/lib/regression_checks.php
key-decisions:
  - "Session-failure recognition moved into the shared request layer instead of leaving raw 401 parsing inside business modules."
  - "Expanded regression checks to enforce standardized 401 JSON contracts for multiple protected endpoints."
patterns-established:
  - "Frontend request pattern: request layer throws rich errors with sessionExpired/accountTrashed/rateLimit metadata."
  - "Regression auth suite now verifies contract shape, not just a freeform message."
requirements-completed: [SESS-02, SESS-03]
duration: 50min
completed: 2026-04-20
---

# Phase 2: 会话与 401 统一层 Summary

**前端请求层现在能统一识别 session-expired 错误，回归套件也开始强制校验标准化 401 契约。**

## Performance

- **Duration:** 50 min
- **Started:** 2026-04-20T18:35:00+08:00
- **Completed:** 2026-04-20T19:25:00+08:00
- **Tasks:** 3
- **Files modified:** 7

## Accomplishments
- `common.js` 与 `api.js` 现在会生成带 `status/data/code/sessionExpired/accountTrashed/rateLimit` 元数据的 request errors
- `settings.js`、`calendar.js`、`subscriptions.js` 这些高风险前端消费点开始使用共享 session failure 识别路径
- Phase 1 regression runner 的 auth suite 已升级为校验 `subscriptions/get.php`、`subscriptionpages.php`、`payments/get.php` 的统一 JSON 401 契约

## Task Commits

Each task was committed atomically:

1. **Task 1: Add shared frontend session-failure recognition to the request layer** - `2ddee36` (前端)
2. **Task 2: Remove hand-written 401 parsing from high-risk frontend modules** - `2ddee36` (前端)
3. **Task 3: Expand regression checks for the unified Phase 2 contract** - `2ddee36` (前端)

**Plan metadata:** `9422c69` (文档)

## Files Created/Modified
- `scripts/common.js` - 丰富 request error 元数据并统一 session-expired 分发
- `scripts/api.js` - 为 `WallosApi` 暴露共享 session failure 识别 helper
- `scripts/settings.js` - `reloadPaymentMethods()` 迁移到共享请求层
- `scripts/calendar.js` - 日历弹窗与导出请求迁移到共享请求层
- `scripts/subscriptions.js` - 使用共享 session failure helper 代替原始 401 判断
- `tests/lib/regression_bootstrap.php` - auth suite catalog 扩展
- `tests/lib/regression_checks.php` - 标准化 401 JSON 合约 smoke checks

## Decisions Made
- 统一识别逻辑放在请求层，业务模块只保留场景性 reload / modal 行为
- 401 回归检查升级为“检查结构化契约”，而不再依赖旧的纯文本消息格式

## Deviations from Plan

### Auto-fixed scope extension

**1. Also migrated `settings.js` and `calendar.js` raw fetch consumers**
- **Reason:** `payments/get.php` 与 `getcalendar.php` 后端契约已经统一，如果前端仍用原始 fetch，session 失效时容易把 JSON 文本插进界面或只打控制台日志
- **Impact:** 这是对高风险消费点的必要补齐，仍然属于 Phase 2 的前端统一处理目标

## Issues Encountered

- authenticated positive-path smoke 仍然依赖外部凭据，因此当前 runner 在无凭据环境下会按设计给出 `SKIP`，而不是伪造登录流程

## User Setup Required

如果要让 auth suite 跑到正向登录态检查，而不是 `SKIP`，提供其一：
- `WALLOS_TEST_COOKIE`
- `WALLOS_TEST_USERNAME` + `WALLOS_TEST_PASSWORD`

## Next Phase Readiness

- Phase 3 可以直接在现有 request-layer 元数据与 regression baseline 基础上增加异常可观测性、Service Worker 状态可见性与更明确的失败反馈

---
*Phase: 02-session-and-401-unification*
*Completed: 2026-04-20*
