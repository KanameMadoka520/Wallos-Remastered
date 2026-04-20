---
phase: 04-request-layer-convergence
plan: 02
subsystem: frontend
tags: [settings, request-layer, fetch, api]
requires:
  - phase: 04
    provides: admin request convergence baseline
provides:
  - Settings high-frequency CRUD and save flows moved onto shared request helpers
affects: [settings, request-layer]
tech-stack:
  added: [settings request helpers built on WallosApi]
  patterns: [settings request convergence]
key-files:
  created: []
  modified:
    - scripts/settings.js
key-decisions:
  - "Prioritized household/category/currency/payment/settings/AI high-frequency flows and left low-value special cases for later phases."
patterns-established:
  - "settings.js now uses shared helpers for repeated form/json request flows."
requirements-completed: [FRON-01, FRON-02]
duration: 35min
completed: 2026-04-21
---

# Phase 4: 请求层统一收敛 Summary

**设置页最密集的一批 CRUD 和保存请求已经收敛到共享请求层。**

## Performance

- **Duration:** 35 min
- **Started:** 2026-04-21T09:20:00+08:00
- **Completed:** 2026-04-21T09:55:00+08:00
- **Tasks:** 1
- **Files modified:** 1

## Accomplishments
- `settings.js` 增加基于 `WallosApi` 的局部 helper
- household/category/currency/payment/settings/AI 高密度请求链条不再依赖原始 `fetch`
- `settings.js` 与 `calendar.js` 的高频路径中已经没有剩余 raw `fetch`

## Task Commits

1. **Task 1: Converge repeated settings CRUD fetch chains** - `732647d` (前端)

## Files Created/Modified
- `scripts/settings.js` - 高密度 CRUD 与保存请求收敛

## Decisions Made
- 优先收敛重复最多、最常改动的路径，而不是为了“看起来干净”去动下载/低频特殊流

## Deviations from Plan

### Auto-fixed Scope Interpretation

**1. Calendar high-frequency requests were already converged**
- **Reason:** 先前阶段里 `calendar.js` 的高频请求实际上已经切到共享请求层，所以本阶段不需要为它再制造无意义改动
- **Impact:** `04-03` 主要变成回归确认与收尾，而不是强行改代码

## Issues Encountered

- `settings.js` 中多种请求类型混杂（JSON、URLSearchParams、FormData、GET JSON），需要通过局部 helper 统一，而不是单一函数硬套所有流

## User Setup Required

None.

## Next Phase Readiness

- 可以直接用现有 regression runner 对请求层收敛结果做回归确认，并将 Phase 4 结案

---
*Phase: 04-request-layer-convergence*
*Completed: 2026-04-21*
