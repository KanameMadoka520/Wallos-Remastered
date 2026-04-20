# Phase 8: 订阅页第二轮减耦 - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md - this log preserves the selected direction.

**Date:** 2026-04-21
**Phase:** 08-订阅页第二轮减耦
**Areas discussed:** 第二轮减耦策略, 风险控制

---

## 第二轮减耦策略

| Option | Description | Selected |
|--------|-------------|----------|
| 先抽搜索/过滤/动作菜单/滑动交互 | 最稳妥 | ✓ |
| 直接重写 subscriptions.js | 风险过高 | |

## 风险控制

| Option | Description | Selected |
|--------|-------------|----------|
| helper + wrapper + regression | 最稳妥 | ✓ |
| 一次性大范围重命名 | 风险高 | |
