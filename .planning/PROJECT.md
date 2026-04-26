# Wallos-Remastered

## What This Is

Wallos-Remastered 是基于 Wallos 深度改造的多用户订阅与预算管理系统，重点面向长期自托管、细粒度资源治理、强后台运维以及更丰富的主题与交互体验。

## Core Value

在不破坏现有视觉效果和复杂功能链路的前提下，持续提供稳定、可控、适合长期自托管运营的订阅管理体验。

## Current Milestone: v1.3 后台稳定性闭环与缓存治理

**Goal:** 补齐后台高风险运维链路的浏览器回归、缓存治理与会话恢复闭环，在不改变现有视觉效果的前提下继续降低回归风险。

**Target features:**
- 管理后台浏览器级 E2E 回归
- Service Worker / 客户端缓存治理与可观测性
- CSRF / 会话恢复统一处理
- 回归入口与维护文档同步

## Requirements

### Validated

- [x] 已建立本地可执行的公开页、鉴权链路、静态契约与旧逻辑回归基线。
- [x] 受保护页面、异步 endpoint 与媒体访问已共享统一的会话恢复与 `401/session_expired` 契约。
- [x] 管理后台已具备运行可观测性、访问日志/异常日志浏览、备份与存储维护能力。
- [x] 订阅页已完成两轮前端模块化减耦，并由浏览器 E2E 覆盖关键交互。

### Active

- [ ] 管理后台高风险运维链路需要浏览器级 smoke 覆盖，避免“看起来能点、实际上漂移”的回归。
- [ ] Service Worker 缓存刷新、客户端缓存清理和刷新标记需要继续纳入治理闭环。
- [ ] 长时间挂页、失效 CSRF 与剩余受保护交互仍需继续统一到共享恢复路径。
- [ ] 所有新增改动都要继续保持 `health.php`、静态/鉴权回归和浏览器 E2E 绿色。

### Out of Scope

- 数据库迁移到 MySQL/PostgreSQL。
- 新一轮大型业务功能扩张。
- 大规模视觉重做或推翻既有主题系统。
- VPS / nginx / FRP 外部网关改造。

## Context

- `v1.0` 已完成回归基线、会话统一与基础可观测性。
- `v1.1` 已完成请求层收敛、订阅页第一轮模块化与契约文档化。
- `v1.2` 已完成剩余特殊请求流收口与订阅页第二轮减耦。
- 当前最大风险已经不是“不会做功能”，而是“复杂前端和缓存/会话链路继续演进时的回归漂移”。

## Constraints

- **技术栈**: 保持现有 PHP + SQLite + Docker 体系，不做迁库。
- **兼容性**: 不破坏既有主题、动态壁纸、公开页和订阅页交互。
- **运维方式**: 继续支持本地中文 commit、同步到 TCY 自建源码、重建容器并验证 `health.php`。
- **迭代策略**: 优先通过高内聚、低耦合的增量改造来继续降风险。

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| `v1.3` 先补后台浏览器级回归 | 后台已承载备份、日志、缓存、速率限制等高风险运维操作，最值得先防回归 | In Progress |
| 缓存治理继续围绕 Service Worker 与管理员可观测性推进 | 这是“某些设备异常、另一些设备正常”的主要风险源 | Planned |
| 会话 / CSRF 恢复放在后台回归之后继续收尾 | 先有可重复验证手段，再继续统一恢复链路，风险更低 | Planned |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition**:
1. Requirements invalidated? -> Move to Out of Scope with reason
2. Requirements validated? -> Move to Validated with phase reference
3. New requirements emerged? -> Add to Active
4. Decisions to log? -> Add to Key Decisions
5. "What This Is" still accurate? -> Update if drifted

**After each milestone**:
1. Full review of all sections
2. Core Value check -> still the right priority?
3. Audit Out of Scope -> reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-04-26 after milestone v1.3 initialization*
