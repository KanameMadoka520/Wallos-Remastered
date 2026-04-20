---
phase: 03-observability-and-feedback
plan: 01
subsystem: observability
tags: [php, anomaly, logging, service-worker]
requires: []
provides:
  - Lightweight client anomaly logging endpoint
  - Service Worker cache-version parsing helper
  - Logging/rate-limit exclusions for the self-reporting endpoint
affects: [admin, frontend, observability]
tech-stack:
  added: [runtime observability helper, client anomaly endpoint]
  patterns: [reuse security anomalies as observability sink]
key-files:
  created:
    - includes/runtime_observability.php
    - endpoints/client/loganomaly.php
  modified:
    - includes/request_logs.php
    - includes/security_rate_limits.php
key-decisions:
  - "Reused security_anomalies instead of building a new error store."
  - "Excluded the anomaly endpoint from self-generated request log noise."
patterns-established:
  - "Client anomaly pattern: POST lightweight runtime/request failures into the existing anomaly pool."
requirements-completed: [OBS-01]
duration: 25min
completed: 2026-04-20
---

# Phase 3: 可观测性与调试反馈 Summary

**轻量前端异常上报基础已经建立，现有安全异常池开始承载运行时与请求失败事件。**

## Performance

- **Duration:** 25 min
- **Started:** 2026-04-20T19:30:00+08:00
- **Completed:** 2026-04-20T19:55:00+08:00
- **Tasks:** 2
- **Files modified:** 4

## Accomplishments
- 新增 `endpoints/client/loganomaly.php`，允许已登录页面把 runtime/request failure 轻量上报到现有异常池
- 新增 `includes/runtime_observability.php`，可解析 `service-worker.js` 缓存版本
- 让 request log / rate-limit 规则忽略这个自上报 endpoint，避免自我污染

## Task Commits

Each task was committed atomically:

1. **Task 1: Add runtime observability helper and anomaly endpoint** - `bf42a54` (观测)
2. **Task 2: Prevent self-generated logging noise** - `bf42a54` (观测)

**Plan metadata:** `4a2e1b2` (文档)

## Files Created/Modified
- `includes/runtime_observability.php` - Service Worker 版本解析与异常统计 helper
- `endpoints/client/loganomaly.php` - 前端异常上报 endpoint
- `includes/request_logs.php` - 请求日志跳过自上报 endpoint
- `includes/security_rate_limits.php` - 速率限制跳过自上报 endpoint

## Decisions Made
- 观测数据优先复用现有 anomaly 池，避免新建第二套后台
- 上报 endpoint 需要先去噪，否则观测系统会被自己污染

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

- 为了避免请求层递归记录，必须同时更新 request log 与 rate-limit 的忽略规则，而不是只加 endpoint

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- 管理员页已经可以直接接上 anomaly 计数和 Service Worker 状态显示
- 前端公共层已经可以继续接上异常上报和更明确的反馈

---
*Phase: 03-observability-and-feedback*
*Completed: 2026-04-20*
