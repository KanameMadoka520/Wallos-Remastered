# Wallos-Remastered

## What This Is

Wallos-Remastered 是一个基于 Wallos 深度改造的多用户订阅与预算管理系统，重点面向长期自托管、细粒度资源治理、强后台运维和更丰富视觉主题的使用场景。它已经不再是官方版 Wallos 的轻量变体，而是一个同时承载订阅管理、支付账本、图片媒体、主题系统、管理员运维与多用户策略控制的 brownfield 项目。

## Core Value

在不破坏现有视觉效果和复杂功能链路的前提下，持续提供稳定、可控、适合长期自托管运营的订阅管理体验。

## Current Milestone: v1.1 结构收敛与低耦合重构

**Goal:** 在现有稳定性工程成果之上，继续降低核心复杂区的耦合度，重点统一剩余请求层、模块化订阅页前端，并把主题/API 共享契约明确文档化。

**Target features:**
- 管理员/设置/剩余高频页面请求层统一
- 订阅页前端模块化与边界收敛
- 主题/API 契约文档化与残余低风险收口

## Requirements

### Validated

- [x] 用户可以管理订阅、预算、支付记录、图片媒体与订阅分页。
- [x] 管理员可以执行邀请码管理、用户封禁/回收、备份与恢复、访问日志与速率限制配置。
- [x] 系统已经支持多主题、动态壁纸、页面切换动画、公开页多语言与主题色同步。
- [x] 项目已经存在若干本地 PHP 回归测试，覆盖预算、支付账本与订阅偏好的一部分核心逻辑。
- [x] 维护者可以运行一个本地回归入口，统一检查 `health.php`、公开页契约、订阅分页关键 endpoint 与旧 PHP 回归测试。（Phase 1）
- [x] 回归入口会输出结构化 PASS / FAIL / SKIP 摘要，并在失败时返回非零退出码。（Phase 1）
- [x] 回归入口支持通过参数或环境变量注入基础地址、cookie 和登录凭据，而不需要修改源码。（Phase 1）
- [x] 受保护的页面请求、异步 endpoint 与媒体访问已经共享认证恢复核心，而不再各自维护一套近似逻辑。（Phase 2）
- [x] 高风险受保护 endpoint 已经统一到机器可读的 `session_expired` 失败契约，减少了纯文本与 JSON 混用。（Phase 2）
- [x] 前端请求层已经能统一识别会话失效，并把高风险模块的原始 401 解析收敛到公共入口。（Phase 2）
- [x] 维护者已经可以在管理员页查看最近前端运行时异常、请求失败和 Service Worker 缓存/注册状态。（Phase 3）
- [x] 前端共享层已经能上报低噪声的运行时异常和请求失败，并在关键失败场景给出更明确反馈。（Phase 3）

### Active

- [ ] 管理员/设置/日历等高频页面统一使用共享请求层，而不是继续保留零散 `fetch` + 本地错误处理
- [ ] 订阅页脚本按数据/状态/渲染/交互重新切边界，在不改视觉效果的前提下降低耦合
- [ ] 主题/API/请求失败共享契约形成开发文档，防止后续改动再次退化

### Out of Scope

- 数据库迁移到 MySQL/PostgreSQL：当前优先级仍然是降低结构复杂度与回归风险，不做迁库工程。
- 大规模视觉重做：继续保持当前 UI/主题资产稳定，仅在必要范围内做结构收敛。
- 外部网关与 VPS 侧大改：仍然属于独立运维专题，不纳入本次代码里程碑。
- 新的大型业务功能扩张：本里程碑聚焦“收敛和减耦”，不是继续堆新能力。

## Context

- `v1.0 稳定性工程` 已经完成，当前拥有统一 regression runner、共享会话恢复核心和基础可观测性。
- 代码库当前最复杂、最容易再退化的区域，主要是订阅页前端和仍未完全统一到共享请求层的管理/设置/日历脚本。
- 现有请求层 (`WallosApi` / `WallosHttp`) 已经具备一定统一能力，适合作为下一轮“请求层统一”里程碑的底座。
- 现有 `.planning`、阶段总结和 regression runner 已经能为本里程碑继续兜底。

## Constraints

- **技术栈**: 保持现有 PHP + SQLite + Docker 体系，不做迁库或大规模框架替换。
- **兼容性**: 不能破坏当前公开页、订阅页、管理员页和主题系统的既有视觉效果与交互。
- **运维方式**: 继续支持本地中文 commit、容器重建、`health.php` 验证等现有协作流程。
- **迭代策略**: 优先通过高内聚、低耦合的增量重构降低风险，避免一次性大改。

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| 首个可追踪里程碑定为 `v1.0 稳定性工程` | 当前最紧迫的问题是回归风险，不是功能缺口 | Good |
| `v1.0` 先完成回归基线、会话统一和基础可观测性 | 先把“容易翻车”的底座稳住，再继续演进 | Good |
| `v1.1` 聚焦结构收敛与低耦合重构 | 当前最值得继续投入的是复杂区减耦，不是新功能扩张 | Good |
| 继续保留 SQLite 与现有部署结构 | 迁库收益暂时低于成本与风险 | Good |
| 采用 brownfield 规划初始化 `.planning` | 仓库此前没有 GSD 规划目录，需要先补项目治理骨架 | Good |
| Phase 1 采用 CLI-first 回归基线，而不是直接引入浏览器 E2E | 以最低维护成本先覆盖最容易翻车的公开页和分页契约 | Good |
| Phase 2 先统一高风险链路和请求层，再考虑全量 endpoint 契约收敛 | 兼容优先，避免一次性大改造成大面积回归 | Good |
| Phase 3 复用现有 security anomalies 和管理员页，而不是新建监控后台 | 低风险、低耦合，符合稳定性工程边界 | Good |

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
*Last updated: 2026-04-20 after milestone v1.1 initialization*
