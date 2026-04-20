# Phase 8: 订阅页第二轮减耦 - Context

**Gathered:** 2026-04-21
**Status:** Ready for planning

<domain>
## Phase Boundary

继续减少 `subscriptions.js` 的剩余复杂区，这一轮优先处理搜索、过滤、动作菜单、滑动提示等交互逻辑，并保持现有订阅页视觉与行为稳定。

</domain>

<decisions>
## Implementation Decisions

### 第二轮减耦策略
- **D-01:** 优先抽离“纯交互 orchestration”逻辑，例如搜索、过滤、动作菜单、滑动提示。
- **D-02:** 保持 `subscriptions.js` 作为入口和编排层，不进行激进的全量重写。

### 风险控制
- **D-03:** 继续用 regression runner 验证整站稳定性，不以视觉重做换结构清爽。
- **D-04:** 新 helper 必须保持现有全局函数表面兼容。

### the agent's Discretion
- 交互 helper 的命名与切分边界
- 哪些低价值残余逻辑可以留在主文件

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

- `.planning/PROJECT.md`
- `.planning/REQUIREMENTS.md`
- `.planning/ROADMAP.md`
- `.planning/STATE.md`
- `scripts/subscriptions.js`
- `scripts/subscription-payments.js`
- `scripts/subscription-layout.js`
- `subscriptions.php`

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `subscription-payments.js` 已接手支付历史/表单
- `subscription-layout.js` 已接手布局/排序

### Established Patterns
- 第二轮减耦应继续采用“提取 helper + 保留 wrapper”的低风险方式

### Integration Points
- `scripts/subscriptions.js`
- `scripts/subscription-interactions.js`
- `subscriptions.php`

</code_context>

<specifics>
## Specific Ideas

- 把搜索、过滤、动作菜单、滑动提示抽到独立交互 helper，是当前最稳的第二轮减耦切口

</specifics>

<deferred>
## Deferred Ideas

- 完整 ES module 化
- 订阅页全局命名体系重做

</deferred>

---

*Phase: 08-subscriptions-second-pass*
*Context gathered: 2026-04-21*
