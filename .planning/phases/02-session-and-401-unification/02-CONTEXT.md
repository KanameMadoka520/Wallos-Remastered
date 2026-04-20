# Phase 2: 会话与 401 统一层 - Context

**Gathered:** 2026-04-20
**Status:** Ready for planning

<domain>
## Phase Boundary

统一页面请求、异步 endpoint 与媒体访问的登录恢复逻辑，并为受保护链路建立清晰一致的会话失效与 401 返回约定；重点是减少重复实现、避免返回格式混乱，以及把前端会话失效处理收拢到统一入口。

</domain>

<decisions>
## Implementation Decisions

### 后端会话恢复边界
- **D-01:** Phase 2 应抽出一个共享的后端会话/登录恢复 helper，供页面请求、异步 endpoint 与媒体访问共同复用，而不是继续在 `checksession.php`、`connect_endpoint.php`、`subscriptionimage.php` 各写一套近似逻辑。
- **D-02:** 共享 helper 需要统一负责会话启动、`wallos_login` cookie 解析、token 校验、用户状态检查、登录 cookie 清理，以及用户基础上下文恢复；不同入口只保留“如何输出失败结果”的差异。

### 失败输出契约
- **D-03:** 页面请求保持“未登录时重定向到登录页”的行为，不改成 JSON。
- **D-04:** 受保护的异步 endpoint 统一采用 JSON 失败契约，不再混用纯文本、HTML 片段和结构不一致的 JSON；最小统一字段至少包含 `success: false`、稳定错误代码、可展示消息，以及前端可识别的会话失效标记。
- **D-05:** 媒体访问链路继续保持非 JSON、二进制友好的 HTTP 契约，但需要与统一 helper 对齐登录恢复逻辑，并明确区分 403 / 404 / 429 的输出边界。

### 前端 401 处理入口
- **D-06:** 会话失效识别应尽量收敛到 `scripts/api.js` / 公共请求层，而不是由每个业务模块各自解析 401 和 `session_expired`。
- **D-07:** 业务模块只保留少量场景性钩子，例如“当前页面需要刷新”“当前弹窗需要关闭”“当前请求可以静默忽略”；不得继续手写各自的原始 401 解析逻辑。

### 兼容与迁移策略
- **D-08:** 本阶段采用兼容优先的收敛方式：先抽共享 helper，再迁移最容易出问题的高风险链路，避免一次性大改全部 endpoint。
- **D-09:** 既有翻译键 `session_expired`、`account_trashed` 和速率限制相关结构要尽量保留，优先用统一包装层兼容现有前端，而不是强行推翻旧消息字段。

### the agent's Discretion
- 共享 helper 的具体文件名、函数名和拆分颗粒度由后续 planning 决定。
- 高风险链路的迁移顺序由后续 planning 根据代码耦合度决定。
- 前端公共 401 hook 的落点放在 `WallosApi`、`WallosHttp` 或 `common.js` 的哪一层，由后续 planning 选择最稳妥方案。

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### 里程碑与阶段边界
- `.planning/PROJECT.md` - 当前里程碑目标、约束和已验证的回归基础
- `.planning/REQUIREMENTS.md` - Phase 2 对应的 `SESS-01` / `SESS-02` / `SESS-03`
- `.planning/ROADMAP.md` - Phase 2 目标、成功标准与阶段顺序
- `.planning/STATE.md` - 当前进展、风险与下一步焦点
- `.planning/phases/01-regression-safety-baseline/01-VERIFICATION.md` - 已建立的回归基线与后续 Phase 2 可复用的 smoke 安全网

### 页面请求登录恢复链路
- `includes/checksession.php` - 当前页面请求登录恢复逻辑，包含 session/cookie 恢复、重定向与用户状态检查
- `login.php` - 登录后 session / `wallos_login` cookie 的写入来源
- `logout.php` - 现有 cookie/session 清理行为

### endpoint 登录恢复链路
- `includes/connect_endpoint.php` - 当前异步 endpoint 登录恢复核心与后台速率限制入口
- `includes/validate_endpoint.php` - 当前 POST/CSRF/session 验证入口，返回契约与 `connect_endpoint.php` 不一致
- `endpoints/subscriptions/get.php` - 目前会返回纯文本 `session_expired` 的代表性 endpoint
- `endpoints/payments/get.php` - 目前 401 返回的是另一套 JSON 结构的代表性 endpoint
- `endpoints/subscription/paymenthistory.php` - 现有 JSON 失败结构示例
- `endpoints/subscription/getcalendar.php` - 现有 JSON 失败结构示例

### 媒体访问链路
- `endpoints/media/subscriptionimage.php` - 当前媒体访问使用独立的登录恢复逻辑与 403/404/429 输出
- `includes/security_rate_limits.php` - 媒体和后端请求速率限制结构

### 前端请求层与 401 处理
- `scripts/api.js` - 当前 `WallosApi` / `WallosHttp` 请求封装层
- `scripts/subscriptions.js` - 当前有手写 401 捕获与 reload 行为的代表性业务模块
- `scripts/common.js` - 通用请求反馈、toast 与页面级状态处理层

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `includes/connect_endpoint.php` - 已具备 endpoint 会话恢复、账号状态检查和后台速率限制入口，是最接近共享 helper 的现有基础
- `scripts/api.js` - 已经有通用请求封装，可以作为统一前端 401 处理的收口点
- `tests/regression_runner.php` 与 `tests/lib/regression_checks.php` - 可直接作为 Phase 2 回归安全网，验证 401 与 endpoint 契约改动是否翻车

### Established Patterns
- 页面请求当前以“失败后重定向”为主，异步 endpoint 则混用纯文本与 JSON；这正是 Phase 2 需要统一的核心问题
- `subscriptionimage.php` 对媒体场景已经做了二进制友好处理，说明媒体链路不适合直接套用普通 JSON endpoint 返回
- `session_expired` 已有完整 i18n 覆盖，说明统一输出时应复用该翻译键，而不是引入新的人类可读文本常量
- 速率限制、账号回收站/封禁等扩展状态已经接入 endpoint 链路，统一 helper 不能只考虑“登录成功/失败”两态

### Integration Points
- 后端统一层至少会影响 `checksession.php`、`connect_endpoint.php`、`validate_endpoint.php`、`subscriptionimage.php` 和一批依赖这些入口的 endpoint
- 前端统一层至少会影响 `scripts/api.js`、`scripts/common.js` 以及当前自行处理 401 的业务脚本
- 现有 Phase 1 回归 runner 可以扩充 auth suite，用于检查统一 401 契约是否真的收敛

</code_context>

<specifics>
## Specific Ideas

- 用户在 Phase 1 完成后明确要求“继续”，因此本阶段按默认稳定路线直接锁定上下文，不再做冗长交互。
- 本阶段的重点不是功能扩张，而是收敛重复实现、稳定错误契约、减少前端各模块自己兜底。
- 迁移过程应优先保护现有行为和视觉效果，避免因为统一会话层而引入新的全站 UI 回归。

</specifics>

<deferred>
## Deferred Ideas

- 把所有后端 endpoint 全量改造成严格 REST/typed API 形式 - 超出本阶段目标
- 媒体访问链路改造成 JSON 授权票据或签名 URL 模式 - 属于后续更大安全架构话题
- 全站错误码体系与前端状态机全面重构 - 可放入后续 observability / API consistency 专题

</deferred>

---

*Phase: 02-session-and-401-unification*
*Context gathered: 2026-04-20*
