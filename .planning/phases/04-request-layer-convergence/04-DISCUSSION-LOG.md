# Phase 4: 请求层统一收敛 - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md - this log preserves the selected direction.

**Date:** 2026-04-20
**Phase:** 04-请求层统一收敛
**Areas discussed:** 收敛边界, 共享模式, 验证方式

---

## 收敛边界

| Option | Description | Selected |
|--------|-------------|----------|
| 先迁高频 JSON/form 请求 | 风险低、收益高 | ✓ |
| 一次性迁所有 fetch | 风险高、容易引发回归 | |

## 共享模式

| Option | Description | Selected |
|--------|-------------|----------|
| 以 WallosApi/WallosHttp 为底座，局部再包 helper | 最稳妥 | ✓ |
| 每页自写一套 wrapper | 会继续增加分叉 | |

## 验证方式

| Option | Description | Selected |
|--------|-------------|----------|
| 继续用 regression runner + 语法检查 + 健康检查 | 与现有流程一致 | ✓ |
| 直接上浏览器 E2E | 当前阶段过重 | |
