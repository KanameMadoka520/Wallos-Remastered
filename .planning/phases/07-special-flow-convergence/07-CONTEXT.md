# Phase 7: 特殊请求流收口 - Context

**Gathered:** 2026-04-21
**Status:** Ready for planning

<domain>
## Phase Boundary

收掉当前仍然残留在高频维护脚本中的少量特殊请求流，重点是文本型 cronjob 调用、后台更新检查触发和订阅 logo 搜索；目标是减少残余 raw `fetch`，同时保留这些流原本的特殊返回语义。

</domain>

<decisions>
## Implementation Decisions

### 优先级
- **D-01:** 优先处理仍在高频脚本中的特殊请求：`admin.js` 的 cron 文本流和更新检查触发、`subscriptions.js` 的 logo 搜索。
- **D-02:** 不强行改造真正低频、价值不高的特殊流；本阶段只收掉“还会经常碰到、且已经足够明确”的残余点。

### 收敛方式
- **D-03:** 文本流优先通过共享请求层的 text 能力统一错误处理，而不是继续直接 `fetch(...).then(response.text())`。
- **D-04:** 搜索类 GET JSON 请求优先使用 `WallosApi.getJson`，避免继续保留原始 JSON parse 链条。

### the agent's Discretion
- 特殊流局部 helper 放在 admin.js 还是 common.js
- 更新检查触发是否需要保留静默 fire-and-forget 行为

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

- `.planning/PROJECT.md`
- `.planning/REQUIREMENTS.md`
- `.planning/ROADMAP.md`
- `.planning/STATE.md`
- `scripts/admin.js`
- `scripts/subscriptions.js`
- `scripts/api.js`
- `scripts/common.js`
- `.planning/phases/04-request-layer-convergence/04-VERIFICATION.md`

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `WallosApi.getText()` 已可承载文本流
- `WallosApi.getJson()` 已可承载 GET JSON 搜索流

### Established Patterns
- 高频 JSON/form 请求已经大多收敛，剩下的是少量特殊流
- 这些特殊流不需要大改协议，只需要把错误处理和请求入口统一起来

### Integration Points
- `scripts/admin.js`
- `scripts/subscriptions.js`

</code_context>

<specifics>
## Specific Ideas

- 这一步是 `v1.2` 最低风险、最高性价比的收口点
- 做完后，请求层残余漂移会进一步下降

</specifics>

<deferred>
## Deferred Ideas

- 真正低频、极少维护的特殊下载/文本流
- 需要浏览器自动化才能稳定验证的特殊交互

</deferred>

---

*Phase: 07-special-flow-convergence*
*Context gathered: 2026-04-21*
