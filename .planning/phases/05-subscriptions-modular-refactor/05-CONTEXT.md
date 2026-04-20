# Phase 5: 订阅页模块化重构 - Context

**Gathered:** 2026-04-21
**Status:** Ready for planning

<domain>
## Phase Boundary

继续降低 `subscriptions.js` 的复杂度，优先去掉已经有独立模块却仍在主文件重复实现的逻辑，再把布局/排序等边界拆出更明确的模块；必须保持现有视觉效果和用户行为不变。

</domain>

<decisions>
## Implementation Decisions

### 重构优先级
- **D-01:** 先删重复、改委托，再考虑新增模块文件；优先处理已经有 `subscription-payments.js`、`subscription-pages.js`、`subscription-media.js` 等模块却仍在主文件重复实现的部分。
- **D-02:** 布局/排序/拖拽这类与 DOM 强绑定但业务语义较清晰的部分，适合作为第二步拆分边界。

### 风险控制
- **D-03:** 不做大规模命名迁移或整文件翻新，避免在最复杂页面一次性引入过多变动。
- **D-04:** 每一步都要继续跑现有 regression runner，并保持订阅页相关行为与现有视觉效果不变。

### the agent's Discretion
- 先委托支付历史/表单，还是先拆布局/排序 helper
- 新模块文件的命名与切分颗粒度
- 哪些暂时保留在 `subscriptions.js` 里更稳妥

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

- `.planning/PROJECT.md`
- `.planning/REQUIREMENTS.md`
- `.planning/ROADMAP.md`
- `.planning/STATE.md`
- `subscriptions.php`
- `scripts/subscriptions.js`
- `scripts/subscription-pages.js`
- `scripts/subscription-payments.js`
- `scripts/subscription-media.js`
- `scripts/subscription-image-viewer.js`
- `scripts/subscription-preferences.js`
- `.planning/phases/04-request-layer-convergence/04-VERIFICATION.md`

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `subscription-pages.js` 已经承接分页管理逻辑
- `subscription-payments.js` 已经承接支付历史 / 记录支付逻辑
- `subscription-media.js` 已经承接图片选择/拖拽/排序逻辑

### Established Patterns
- `subscriptions.js` 里存在大量 wrapper 和部分仍未清理的重复实现
- 支付相关是一块明显的“已模块化但主文件仍保留大块逻辑”的高收益区域

### Integration Points
- 主入口仍然是 `subscriptions.php` + `scripts/subscriptions.js`
- 任何模块拆分都要保持 `window.WallosSubscription*` 对外表面兼容

</code_context>

<specifics>
## Specific Ideas

- 先把重复支付逻辑彻底委托给 `subscription-payments.js`
- 再考虑把 masonry / 排序 / 过滤之类的非支付交互拆成更独立的 helper

</specifics>

<deferred>
## Deferred Ideas

- 使用 ES module / bundler 重写前端结构
- 大规模重命名订阅页的全局函数
- 订阅页视觉层改版

</deferred>

---

*Phase: 05-subscriptions-modular-refactor*
*Context gathered: 2026-04-21*
