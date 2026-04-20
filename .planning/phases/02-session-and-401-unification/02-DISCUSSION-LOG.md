# Phase 2: 会话与 401 统一层 - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md - this log preserves the selected direction.

**Date:** 2026-04-20
**Phase:** 02-会话与 401 统一层
**Areas discussed:** 后端会话恢复边界, 失败输出契约, 前端 401 处理入口, 兼容与迁移策略

---

## 后端会话恢复边界

| Option | Description | Selected |
|--------|-------------|----------|
| 抽一个共享 helper，页面 / endpoint / 媒体共用认证核心 | 稳定、低耦合，最适合后续 Phase 2 收敛 | ✓ |
| 保持多套实现，只做局部修补 | 变更少，但会继续积累重复逻辑 | |
| 直接重构成全新认证系统 | 风险过高，超出本阶段 | |

**User's choice:** 继续。  
**Notes:** 根据用户“继续 / 按稳定方法做”的连续偏好，锁定共享 helper 路线。

---

## 失败输出契约

| Option | Description | Selected |
|--------|-------------|----------|
| 页面保留重定向，异步 endpoint 统一 JSON，媒体保留非 JSON HTTP 契约 | 兼顾稳定性与场景差异 | ✓ |
| 所有链路都统一成 JSON | 会破坏页面与媒体既有行为 | |
| 保持当前混合状态 | 无法解决本阶段核心问题 | |

**User's choice:** 继续。  
**Notes:** 采用“按链路类型统一，而不是全都同一种输出”的稳定方案。

---

## 前端 401 处理入口

| Option | Description | Selected |
|--------|-------------|----------|
| 收敛到公共请求层，业务模块只保留少量 hook | 最稳妥、最符合低耦合目标 | ✓ |
| 每个模块自己 catch 401 | 实现快，但继续复制逻辑 | |
| 完全不做统一前端处理 | 会让 Phase 2 效果大打折扣 | |

**User's choice:** 继续。  
**Notes:** 锁定 `scripts/api.js` / 通用请求层收口方向。

---

## 兼容与迁移策略

| Option | Description | Selected |
|--------|-------------|----------|
| 兼容优先，先抽 helper 再迁高风险链路 | 风险最低，符合当前项目复杂度 | ✓ |
| 一次性迁所有 endpoint | 回归风险大 | |
| 只补新代码，不动旧链路 | 无法真正统一 | |

**User's choice:** 继续。  
**Notes:** 锁定分阶段迁移，优先处理 `checksession/connect_endpoint/validate_endpoint/subscriptionimage` 及高风险 endpoint。

---

## the agent's Discretion

- 共享 helper 的具体文件组织和命名
- 前端统一 hook 的具体落点
- 高风险链路的落地迁移顺序

## Deferred Ideas

- 全站 REST/typed API 改造
- 媒体签名 URL / 授权票据体系
- 更大范围的错误码和状态机重构
