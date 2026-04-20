# Phase 3: 可观测性与调试反馈 - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md - this log preserves the selected direction.

**Date:** 2026-04-20
**Phase:** 03-可观测性与调试反馈
**Areas discussed:** 异常数据归集, 管理员可见性, 前端反馈策略

---

## 异常数据归集

| Option | Description | Selected |
|--------|-------------|----------|
| 复用 `security_anomalies` | 低风险、已有浏览器入口 | ✓ |
| 新建独立异常系统 | 更完整，但超出本轮范围 | |

## 管理员可见性

| Option | Description | Selected |
|--------|-------------|----------|
| 在现有管理员页补统计与状态卡片 | 最贴合当前项目形态 | ✓ |
| 新建单独可观测性页面 | 结构更大，但开发成本更高 | |

## 前端反馈策略

| Option | Description | Selected |
|--------|-------------|----------|
| 沿用现有 toast/request 层增强 | 风格连续、风险低 | ✓ |
| 新建重型通知与调试 UI | 成本高、侵入性大 | |

## the agent's Discretion

- anomaly code 细分
- 去重/节流细节
- 管理员卡片具体布局
