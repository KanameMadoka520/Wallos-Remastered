# State

## Current Position

Phase: 2 - 会话与 401 统一层
Plan: Context captured
Status: Ready for planning
Last activity: 2026-04-20 - Phase 2 context gathered

## Project Reference

See: .planning/PROJECT.md (updated 2026-04-20)

**Core value:** 在不破坏现有视觉效果和复杂功能链路的前提下，持续提供稳定、可控、适合长期自托管运营的订阅管理体验。
**Current focus:** Phase 2 - 会话与 401 统一层

## Accumulated Context

- 仓库此前没有 `.planning`，本次以 brownfield 方式补建规划骨架。
- 现有复杂度主要集中在订阅页、主题系统、公开页、管理员运维链路与媒体访问鉴权。
- Phase 1 已经把公开页契约、订阅分页关键 endpoint、service worker 关键常量与旧 PHP 回归脚本统一进同一个 regression runner。
- 后续每次改动仍应保持本地中文 commit，并优先用小步提交固定阶段成果。

## Open Risks

- 页面请求、异步 endpoint 与媒体访问的登录恢复逻辑还存在重复实现。
- 某些稳定性问题只会在长时间停留、缓存陈旧或跨设备/跨主题切换时暴露。
- authenticated positive-path smoke 仍然依赖外部提供 cookie 或账号凭据，后续可以在 CI/运维环境继续增强。

## Next Up

- 基于 `02-CONTEXT.md` 为 Phase 2 拆出共享会话 helper 与统一 401 契约的执行计划
- 明确优先迁移的高风险链路：页面检查、endpoint 检查、媒体访问与前端请求层
- 继续复用 Phase 1 的 regression runner 作为本阶段回归安全网

---
*Last updated: 2026-04-20 after Phase 2 context gathering*
