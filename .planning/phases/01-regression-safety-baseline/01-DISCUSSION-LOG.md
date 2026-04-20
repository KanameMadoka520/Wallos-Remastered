# Phase 1: 自动化回归检查基线 - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md - this log preserves the alternatives considered.

**Date:** 2026-04-20
**Phase:** 01-自动化回归检查基线

**Areas discussed:** 回归检查入口, 认证接入方式, 订阅分页覆盖深度, Service Worker 与 theme-color 验收策略

---

## 回归检查入口

| Option | Description | Selected |
|--------|-------------|----------|
| 单一 CLI 入口 + 复用现有 PHP 测试风格 | 与现有 `tests/*.php` 保持一致，最稳妥，维护成本低 | ✓ |
| 直接上浏览器 E2E | 覆盖更广，但 Phase 1 成本更高、波动更大 | |
| 只保留零散脚本 | 上手快，但难形成统一入口与可靠摘要 | |

**User's choice:** 直接按默认推荐走，按最稳定的方法做。  
**Notes:** 解释为采用单一 CLI 入口，并优先复用当前 PHP 测试风格。

---

## 认证接入方式

| Option | Description | Selected |
|--------|-------------|----------|
| 支持登录拿 cookie，也允许直接传现成 cookie | 兼顾自动化与复用，适合当前 `wallos_login` 体系 | ✓ |
| 只允许脚本自己登录 | 更简单，但不利于复用和调试 | |
| 只允许手动提供 cookie | 实现轻，但自动化体验差 | |

**User's choice:** 直接按默认推荐走，按最稳定的方法做。  
**Notes:** 解释为同时支持登录态自动获取与手动注入现成 cookie。

---

## 订阅分页覆盖深度

| Option | Description | Selected |
|--------|-------------|----------|
| 轻量契约回归 | 验证 JSON 结构、分页响应和基本 smoke，不做完整浏览器交互 | ✓ |
| 完整浏览器自动化 | 更全面，但 Phase 1 不够稳妥 | |
| 只测匿名页面 | 成本低，但覆盖不到真正容易回归的登录后链路 | |

**User's choice:** 直接按默认推荐走，按最稳定的方法做。  
**Notes:** 解释为只做关键 endpoint 与分页响应 smoke，不在 Phase 1 扩成浏览器交互自动化。

---

## Service Worker 与 theme-color 验收策略

| Option | Description | Selected |
|--------|-------------|----------|
| 先验存在与输出正确，不做强门禁 | 先稳住回归检查，再逐步收紧缓存策略 | ✓ |
| 强制每次改静态资源都 bump 版本 | 约束更严，但容易先打断开发流 | |
| 暂时不检查 | 成本低，但遗漏真实高风险点 | |

**User's choice:** 直接按默认推荐走，按最稳定的方法做。  
**Notes:** 解释为检查 `theme-color` 存在和 `service-worker.js` 关键常量可见，但不把缓存版本递增设为 Phase 1 硬门禁。

---

## the agent's Discretion

- 回归入口最终使用纯 PHP 还是轻量命令包装，由后续 planning 决定
- 结构化摘要和失败输出的具体形式由后续 planning 决定

## Deferred Ideas

- 完整浏览器 E2E / Playwright 级别分页交互回放
- 强制缓存版本 bump 门禁
- 更大范围主题与视觉矩阵回归
