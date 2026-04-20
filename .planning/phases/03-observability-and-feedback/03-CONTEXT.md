# Phase 3: 可观测性与调试反馈 - Context

**Gathered:** 2026-04-20
**Status:** Ready for planning

<domain>
## Phase Boundary

提升维护者对近期前端异常、请求失败和 Service Worker 状态的可见性，并让用户在关键失败场景下获得更明确的反馈；重点是复用现有后台和日志体系，而不是另起一套庞大的可观测性平台。

</domain>

<decisions>
## Implementation Decisions

### 异常数据归集
- **D-01:** Phase 3 复用现有 `security_anomalies` 作为轻量异常归集池，而不是新建一套完全独立的日志浏览系统。
- **D-02:** 新增的前端运行时异常和请求失败，统一写入 `security_anomalies`，并通过现有管理员异常浏览器查看。

### 管理员可见性
- **D-03:** 管理员页面直接展示“最近前端运行时异常”“最近请求失败”“Service Worker 缓存版本”“客户端注册/控制状态”等信息，方便快速判断异常和缓存状态。
- **D-04:** 安全异常浏览器继续作为主要查看入口，只扩展 anomaly type 过滤项，不重做一整套新 modal。

### 前端反馈策略
- **D-05:** 在不影响当前视觉风格的前提下，关键失败场景优先通过已有 toast/request-layer 反馈增强，而不是增加新的重型通知系统。
- **D-06:** session 失效时先给出明确反馈，再统一触发页面刷新/回到登录流程。

### the agent's Discretion
- 具体 anomaly code 命名与细节字段组织
- Service Worker 状态卡片的展示结构
- 前端异常上报去重与节流策略

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### 阶段边界
- `.planning/PROJECT.md`
- `.planning/REQUIREMENTS.md`
- `.planning/ROADMAP.md`
- `.planning/STATE.md`
- `.planning/phases/02-session-and-401-unification/02-VERIFICATION.md`

### 现有后台与日志能力
- `admin.php` - 管理员页面现有统计与入口结构
- `scripts/admin.js` - 管理员页面脚本入口
- `scripts/admin-access-logs.js` - 访问日志/安全异常 modal 现有实现
- `includes/request_logs.php` - 请求日志过滤规则
- `includes/security_rate_limits.php` - 安全异常写入与速率限制结构
- `endpoints/admin/securityanomalies.php` - 安全异常浏览 endpoint

### 前端反馈与请求层
- `scripts/common.js` - 现有 toast、request queue notice、统一请求层
- `scripts/api.js` - `WallosApi` 包装层
- `service-worker.js` - Service Worker 缓存版本常量

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `security_anomalies` 已有后台浏览器与清理入口，适合承载轻量前端异常/请求失败记录
- `common.js` 已经掌握 toast 和请求层，是增强用户反馈的自然位置
- `service-worker.js` 已经存在清晰的缓存版本常量，适合直接解析后展示

### Established Patterns
- 维护者可观测性优先放进现有管理员页与现有 modal，而不是新建独立页面
- 用户反馈沿用现有 toast / request-queue notice 视觉系统，避免破坏现有主题样式

### Integration Points
- 后端需要新增一个轻量异常上报 endpoint
- 管理员页需要展示服务端缓存版本和客户端注册状态
- 前端请求层需要把关键失败上报到后端异常池，并补足更清晰的 toast 提示

</code_context>

<specifics>
## Specific Ideas

- 延续前两阶段“低耦合、低风险、复用现有资产”的思路。
- 本阶段优先追求“出了问题能快速看见、能快速判断”，而不是重型监控平台。

</specifics>

<deferred>
## Deferred Ideas

- 真正的远程错误聚合平台（如 Sentry 类能力）
- 更复杂的前端性能遥测、Web Vitals、长时历史趋势图
- Service Worker 深度调试面板与缓存逐项清理器

</deferred>

---

*Phase: 03-observability-and-feedback*
*Context gathered: 2026-04-20*
