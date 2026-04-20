# Phase 6: 契约文档化与残余收敛 - Context

**Gathered:** 2026-04-21
**Status:** Ready for planning

<domain>
## Phase Boundary

把共享请求层、错误契约、主题/反馈层的使用规则写进仓库文档，并补充必要的开发约定，降低后续再次退化为 raw fetch 和重复错误处理的风险。

</domain>

<decisions>
## Implementation Decisions

### 文档落点
- **D-01:** README / CONTRIBUTING 是面向仓库使用者和维护者的主入口，必须明确写出共享请求层和回归基线的使用规则。
- **D-02:** 额外的细节契约放在独立中文文档里，避免 README 变成一整本手册。

### 收敛重点
- **D-03:** 重点记录：何时必须走 `WallosApi` / `WallosHttp`、何时允许特殊流保留原始实现、如何使用 regression runner。
- **D-04:** 主题和反馈层只补“不要退化”的关键规则，不做大而全设计系统。

### the agent's Discretion
- 独立契约文档的命名
- README / CONTRIBUTING 中新段落的结构

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

- `.planning/PROJECT.md`
- `.planning/REQUIREMENTS.md`
- `.planning/ROADMAP.md`
- `.planning/STATE.md`
- `README.md`
- `CONTRIBUTING.md`
- `scripts/api.js`
- `scripts/common.js`
- `tests/regression_runner.php`

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `WallosApi` / `WallosHttp` 已经是事实上的共享请求层
- `regression_runner.php` 已经是事实上的回归基线入口

### Established Patterns
- 高风险页面的请求层已经大幅收敛
- 剩余特殊流主要是低频文本/下载/特殊场景，不应在文档里和高频 JSON 请求混为一谈

### Integration Points
- README 需要写给新维护者
- CONTRIBUTING 需要写给后续开发者
- 独立契约文档需要给未来 GSD 阶段直接引用

</code_context>

<specifics>
## Specific Ideas

- 以“防止退化”为目标写文档，而不是追求百科全书式说明
- 让下一次新开开发时，维护者能直接知道哪些层必须复用

</specifics>

<deferred>
## Deferred Ideas

- 完整设计系统文档
- 完整 API 参考手册
- 浏览器级 E2E 文档套件

</deferred>

---

*Phase: 06-contracts-and-docs*
*Context gathered: 2026-04-21*
