# Phase 7: 特殊请求流收口 - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md - this log preserves the selected direction.

**Date:** 2026-04-21
**Phase:** 07-特殊请求流收口
**Areas discussed:** 优先级, 收敛方式

---

## 优先级

| Option | Description | Selected |
|--------|-------------|----------|
| 先收 admin cron 文本流、更新检查触发、订阅 logo 搜索 | 最高性价比 | ✓ |
| 一次性扫尽所有特殊流 | 风险和收益不成比例 | |

## 收敛方式

| Option | Description | Selected |
|--------|-------------|----------|
| 用共享请求层的 text/json 能力收口 | 最稳 | ✓ |
| 保持 raw fetch 只做零碎修补 | 容易继续退化 | |
