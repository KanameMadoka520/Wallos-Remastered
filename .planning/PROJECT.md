# Wallos-Remastered

## What This Is

Wallos-Remastered 是一个基于 Wallos 深度改造的多用户订阅与预算管理系统，重点面向长期自托管、细粒度资源治理、强后台运维和更丰富视觉主题的使用场景。它已经不再是官方版 Wallos 的轻量变体，而是一个同时承载订阅管理、支付账本、图片媒体、主题系统、管理员运维与多用户策略控制的 brownfield 项目。

## Core Value

在不破坏现有视觉效果和复杂功能链路的前提下，持续提供稳定、可控、适合长期自托管运营的订阅管理体验。

## Current Milestone: v1.0 稳定性工程

**Goal:** 为现有复杂功能建立可复用的回归检查、统一的会话失效处理约定和更清晰的故障观测入口，降低后续迭代回归风险。

**Target features:**
- 自动化回归检查基线
- 会话与 401 统一处理链路
- 后台可观测性与调试反馈补强

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

### Active

- [ ] 为稳定性排查补齐最近异常与缓存/请求状态的可观测信息

### Out of Scope

- 数据库迁移到 MySQL/PostgreSQL：当前里程碑优先降低回归风险，不引入高成本迁库工程。
- 大规模视觉重做：现有 UI/主题效果是既有资产，本轮只做稳定性补强，不做风格重构。
- Nginx/FRP/Fail2ban 外部网关治理：作为后续运维专项处理，不纳入本次代码里程碑。
- 订阅新业务功能扩张：本轮不新增复杂终端功能，优先把现有复杂区稳住。

## Context

- 这是一个长期持续演进的 brownfield 代码库，已经积累了订阅管理、支付账本、图片媒体、管理员后台、备份闭环、动态主题和页面过场等大量功能。
- 当前运行栈以 PHP + SQLite + Docker 为主，项目要求保持现有部署方式可用，默认端口为 18282。
- 近期已经暴露出“复杂度增长导致局部改动带坏其他链路”的风险，包括订阅页久置后分页异常、endpoint 返回格式不一致、不同主题下局部 UI 适配和缓存状态差异等问题。
- 代码库已有一些零散的逻辑测试，但在 Phase 1 完成前，还没有覆盖 health、公开页、关键 endpoint、service worker 与订阅分页切换链路的统一回归脚本。

## Constraints

- **技术栈**: 保持现有 PHP + SQLite + Docker 体系，不做迁库或大规模框架替换。
- **兼容性**: 不能破坏当前公开页、订阅页、管理员页和主题系统的既有视觉效果与交互。
- **运维方式**: 继续支持本地中文 commit、容器重建、`health.php` 验证等现有协作流程。
- **迭代策略**: 优先通过高内聚、低耦合的增量重构降低风险，避免一次性大改。

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| 首个可追踪里程碑定为 `v1.0 稳定性工程` | 当前最紧迫的问题是回归风险，不是功能缺口 | Good |
| 本轮先做稳定性基线，再继续大规模功能开发 | 先降低复杂度与排查成本，后续迭代速度会更稳 | Good |
| 继续保留 SQLite 与现有部署结构 | 迁库收益暂时低于成本与风险 | Good |
| 采用 brownfield 规划初始化 `.planning` | 仓库此前没有 GSD 规划目录，需要先补项目治理骨架 | Good |
| Phase 1 采用 CLI-first 回归基线，而不是直接引入浏览器 E2E | 以最低维护成本先覆盖最容易翻车的公开页和分页契约 | Good |
| Phase 2 先统一高风险链路和请求层，再考虑全量 endpoint 契约收敛 | 兼容优先，避免一次性大改造成大面积回归 | Good |

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
*Last updated: 2026-04-20 after Phase 2 execution*
