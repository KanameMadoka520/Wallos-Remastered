# Phase 4: 请求层统一收敛 - Context

**Gathered:** 2026-04-20
**Status:** Ready for planning

<domain>
## Phase Boundary

把管理员、设置、日历等高频页面里仍然零散存在的原始 `fetch` / 本地错误处理收敛到共享请求层，优先处理最常用、最容易再退化的交互路径；不要求一次性改完所有低频特殊接口。

</domain>

<decisions>
## Implementation Decisions

### 收敛边界
- **D-01:** 优先迁移管理员页、设置页、日历页中的高频 JSON / form 请求，尤其是保存设置、增删改列表项、弹窗详情加载等用户高频路径。
- **D-02:** 低频或特殊返回流（如 cronjob 文本输出、下载流、纯二进制流）可以暂时保留原始实现，不强行在本阶段统一。

### 共享模式
- **D-03:** 以 `WallosApi` / `WallosHttp` 为共享请求底座，必要时在局部脚本内部加小型 helper，但不再直接散落 `fetch().then(response => response.json())`。
- **D-04:** 会话失效、通用错误、成功提示的处理尽量复用公共层，只保留场景性 UI 行为。

### 验证方式
- **D-05:** 本阶段继续依赖现有 regression runner 做底线回归，同时用语法检查和容器健康检查保障部署安全。
- **D-06:** 不引入新的浏览器 E2E 工具，继续坚持低风险、低耦合的增量收敛策略。

### the agent's Discretion
- 哪些高频函数优先迁移
- 哪些特殊 `fetch` 可留到后续 phase
- admin/settings 各自适合的局部 helper 命名与组织方式

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

- `.planning/PROJECT.md`
- `.planning/REQUIREMENTS.md`
- `.planning/ROADMAP.md`
- `.planning/STATE.md`
- `.planning/phases/02-session-and-401-unification/02-VERIFICATION.md`
- `.planning/phases/03-observability-and-feedback/03-VERIFICATION.md`
- `scripts/api.js`
- `scripts/common.js`
- `scripts/admin.js`
- `scripts/settings.js`
- `scripts/calendar.js`
- `scripts/subscription-payments.js`

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `WallosApi` / `WallosHttp` 已具备统一请求、错误归一化和 session failure 识别能力。
- `common.js` 已掌握 toast、request queue 和 anomaly reporting，可复用为统一反馈层。

### Established Patterns
- `admin.js` 和 `settings.js` 仍有大量原始 `fetch`，是最明显的结构退化点。
- `calendar.js` 也还残留少量原始请求，属于高频使用页，值得一起收敛。

### Integration Points
- 高风险前端脚本：`scripts/admin.js`、`scripts/settings.js`、`scripts/calendar.js`
- 共享请求层：`scripts/api.js`、`scripts/common.js`
- 回归基线：`tests/regression_runner.php`

</code_context>

<specifics>
## Specific Ideas

- 本阶段继续保持“视觉不变、行为不变、结构更收敛”的原则。
- 重点不是减少代码行数，而是减少未来再出现新的 raw fetch 和重复错误处理的概率。

</specifics>

<deferred>
## Deferred Ideas

- cronjob 文本输出统一成请求层包装
- 下载/导出/文件流类特殊请求的统一适配
- 需要浏览器自动化才能稳定验证的复杂交互

</deferred>

---

*Phase: 04-request-layer-convergence*
*Context gathered: 2026-04-20*
