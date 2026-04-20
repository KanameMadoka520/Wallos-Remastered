---
phase: 01-regression-safety-baseline
plan: 02
subsystem: testing
tags: [php, smoke, service-worker, theme-color, subscriptions]
requires:
  - phase: 01
    provides: regression harness foundation
provides:
  - Public smoke checks for health, login, registration, theme-color, and service-worker contracts
  - Auth smoke checks for subscription page endpoints with explicit SKIP behavior when secrets are absent
  - Legacy PHP regression tests folded into the same CLI summary
affects: [phase-02-session-consistency, phase-03-observability, testing]
tech-stack:
  added: [public/auth smoke suites, legacy test wrapper]
  patterns: [contract-based regression checks, skip-without-secrets policy]
key-files:
  created:
    - tests/lib/regression_checks.php
    - tests/lib/regression_legacy.php
  modified: []
key-decisions:
  - "Validated public-page contracts by HTTP/meta/file-contract checks instead of brittle full-page snapshots."
  - "When auth inputs are absent, authenticated smoke reports SKIP rather than forcing fake credentials or failing generically."
patterns-established:
  - "Public smoke pattern: contract assertions over response status/meta/file content."
  - "Legacy regression scripts stay source-of-truth and are wrapped instead of rewritten."
requirements-completed: [SAFE-01, SAFE-02]
duration: 45min
completed: 2026-04-20
---

# Phase 1: 自动化回归检查基线 Summary

**公共入口、订阅分页链路和旧 PHP 回归测试已经被统一进同一个回归命令。**

## Performance

- **Duration:** 45 min
- **Started:** 2026-04-20T16:15:00+08:00
- **Completed:** 2026-04-20T17:00:00+08:00
- **Tasks:** 3
- **Files modified:** 2

## Accomplishments
- 为 `health.php`、`login.php`、`registration.php`、`theme-color` 和 `service-worker.js` 建立了 public smoke checks
- 为订阅分页 JSON/HTML endpoint 建立了 auth smoke contract checks，并在缺少凭据时显式 `SKIP`
- 将 `budget_regression_test.php`、`payment_ledger_test.php`、`subscription_preferences_test.php` 统一接入同一 runner

## Task Commits

Each task was committed atomically:

1. **Task 1: Add public-page, theme-color, and service-worker smoke checks** - `0b0c7db` (测试)
2. **Task 2: Add authenticated subscription-page endpoint smoke checks** - `0b0c7db` (测试)
3. **Task 3: Wrap existing PHP regression tests into the unified runner** - `0b0c7db` (测试)

**Plan metadata:** `665a024` (文档)

## Files Created/Modified
- `tests/lib/regression_checks.php` - public/auth smoke suite 与登录/endpoint 合约验证
- `tests/lib/regression_legacy.php` - 旧 PHP 回归脚本的 subprocess wrapper

## Decisions Made
- public smoke 优先验证稳定契约信号，而不是做容易脆掉的整页快照断言
- 对 authenticated smoke 明确采用“无凭据即 SKIP”的策略，避免把测试基线绑死到本地私密账号

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

- 由于当前执行环境没有额外凭据，authenticated positive-path smoke 只能验证 unauthenticated 401 + explicit SKIP；这属于设计内行为，不是失败

## User Setup Required

If you want full authenticated smoke coverage instead of `SKIP`, provide one of:
- `WALLOS_TEST_COOKIE`
- `WALLOS_TEST_USERNAME` + `WALLOS_TEST_PASSWORD`

## Next Phase Readiness

- Phase 2 可以直接复用 `auth` suite 来收敛 session / 401 统一链路
- Phase 3 可以在现有 runner 基础上继续扩展缓存状态、异常反馈和观测入口回归

---
*Phase: 01-regression-safety-baseline*
*Completed: 2026-04-20*
