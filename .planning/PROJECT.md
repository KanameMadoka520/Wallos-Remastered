# Wallos-Remastered

## What This Is

Wallos-Remastered 是一个基于 Wallos 深度改造的多用户订阅与预算管理系统，重点面向长期自托管、细粒度资源治理、强后台运维和更丰富视觉主题的使用场景。它已经不再是官方版 Wallos 的轻量变体，而是一个同时承载订阅管理、支付账本、图片媒体、主题系统、管理员运维与多用户策略控制的 brownfield 项目。

## Core Value

在不破坏现有视觉效果和复杂功能链路的前提下，持续提供稳定、可控、适合长期自托管运营的订阅管理体验。

## Current Milestone: v1.2 残余特殊流收口与订阅页第二轮减耦

**Goal:** 继续降低残余前端复杂度，优先收掉仍未统一的特殊请求流，并对订阅页剩余交互逻辑做第二轮低风险模块化。

**Target features:**
- 残余特殊请求流收口
- 订阅页第二轮模块化
- 继续保持 regression 基线绿色

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
- [x] 管理员、设置、日历等高频页面的请求层已明显收敛到共享请求基础设施。（Phase 4）
- [x] 订阅页的支付逻辑与布局逻辑已完成第一轮模块化拆分，并保持 regression 绿色。（Phase 5）
- [x] 共享请求层、错误契约与回归基线规则已经文档化，供后续维护者复用。（Phase 6）

### Active

- [ ] 剩余低频但仍常维护的特殊请求流在不破坏语义的前提下继续收口，减少 raw `fetch`
- [ ] 订阅页第二轮模块化继续按“删重复、改委托、抽 helper”的方式推进
- [ ] 在后续减耦过程中继续保持现有 regression 基线绿色

### Out of Scope

- 数据库迁移到 MySQL/PostgreSQL：当前优先级仍是结构收敛与回归风险控制，不做迁库工程。
- 大规模视觉重做：继续保持当前 UI/主题资产稳定，仅在必要范围内做结构收敛。
- 外部网关与 VPS 侧大改：仍然属于独立运维专题，不纳入本次代码里程碑。
- 新的大型业务功能扩张：本里程碑继续聚焦“收敛和减耦”，不是继续堆新能力。

## Context

- `v1.0 稳定性工程` 已经完成，当前拥有统一 regression runner、共享会话恢复核心和基础可观测性。
- `v1.1 结构收敛与低耦合重构` 已经完成第一轮请求层收敛、订阅页模块化与契约文档化。
- 当前代码里最适合继续推进的，不是新功能，而是剩余特殊流和订阅页第二轮减耦。
- 当前残余 raw `fetch` 已经明显减少，说明下一轮可以用更小、更稳的 phase 继续收尾。

## Constraints

- **技术栈**: 保持现有 PHP + SQLite + Docker 体系，不做迁库或大规模框架替换。
- **兼容性**: 不能破坏当前公开页、订阅页、管理员页和主题系统的既有视觉效果与交互。
- **运维方式**: 继续支持本地中文 commit、容器重建、`health.php` 验证等现有协作流程。
- **迭代策略**: 优先通过高内聚、低耦合的增量重构降低风险，避免一次性大改。

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| `v1.0` 先完成回归基线、会话统一和基础可观测性 | 先把“容易翻车”的底座稳住，再继续演进 | Good |
| `v1.1` 聚焦结构收敛与低耦合重构 | 当前最值得继续投入的是复杂区减耦，不是新功能扩张 | Good |
| `v1.2` 优先处理残余特殊流与订阅页第二轮减耦 | 当前剩余改动点已经适合用更小、更稳的 phase 继续收尾 | Good |
| 继续保留 SQLite 与现有部署结构 | 迁库收益暂时低于成本与风险 | Good |
| 采用 brownfield 规划初始化 `.planning` | 仓库此前没有 GSD 规划目录，需要先补项目治理骨架 | Good |
| 高风险链路先统一，再做更深层模块化 | 风险最低，也最符合长期维护收益 | Good |

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
*Last updated: 2026-04-21 after milestone v1.2 initialization*
