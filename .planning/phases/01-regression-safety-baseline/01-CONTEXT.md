# Phase 1: 自动化回归检查基线 - Context

**Gathered:** 2026-04-20
**Status:** Ready for planning

<domain>
## Phase Boundary

为现有项目建立一个可本地运行的最小自动化回归检查入口，覆盖 `health.php`、登录页、注册页、`theme-color`、关键 endpoint，以及订阅分页切换这条最容易回归的链路；重点是快速发现回归，而不是一次性做成完整浏览器 E2E 体系。

</domain>

<decisions>
## Implementation Decisions

### 回归检查入口
- **D-01:** Phase 1 采用“单一 CLI 入口”作为主回归入口，而不是先做浏览器优先的 E2E 方案。
- **D-02:** 新入口应尽量复用现有 `tests/*.php` 的风格和执行习惯，把已有 PHP 逻辑测试作为基础模块保留，而不是推倒重来。

### 认证接入方式
- **D-03:** 受保护检查同时支持两种认证方式：脚本主动登录拿到会话/cookie，以及直接传入现成 cookie 进行复用。
- **D-04:** 匿名可访问检查与登录后 smoke 检查需要明确分层，避免混在一起导致失败原因不清楚。

### 订阅分页与关键 endpoint 覆盖深度
- **D-05:** Phase 1 对订阅分页只做轻量契约回归，不做完整浏览器交互自动化回放。
- **D-06:** 核心断言至少覆盖 `endpoints/subscriptionpages.php` 的 JSON 返回、`endpoints/subscriptions/get.php` 的分页响应，以及登录态/失效态下的基础行为。

### 主题色与 Service Worker 验收策略
- **D-07:** `theme-color` 只校验“存在且输出合理”，优先覆盖登录页、注册页和公开入口，不在 Phase 1 做庞大的主题排列组合矩阵。
- **D-08:** `service-worker.js` 只校验静态资源存在、缓存常量可见、基础入口正常，不把“缓存版本必须递增”做成 Phase 1 的硬门禁。

### the agent's Discretion
- 回归入口最终落地为纯 PHP、PHP + Shell 包装，还是其他最稳妥的轻量组合，由后续 planning 决定。
- 结构化输出的具体格式、颜色、摘要布局和失败汇总样式由后续 planning 决定。
- 登录凭据的注入方式（环境变量、参数文件、cookie 文件）由后续 planning 在不暴露敏感信息的前提下决定。

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### 里程碑与需求边界
- `.planning/PROJECT.md` - 当前里程碑目标、约束与稳定性优先级
- `.planning/REQUIREMENTS.md` - Phase 1 的 SAFE-01 / SAFE-02 / SAFE-03 需求定义
- `.planning/ROADMAP.md` - Phase 1 目标、成功标准与阶段边界
- `.planning/STATE.md` - 当前项目上下文与近期风险摘要

### 现有测试基线
- `tests/budget_regression_test.php` - 现有 PHP 回归测试风格示例之一
- `tests/payment_ledger_test.php` - 现有 PHP 回归测试风格示例之一
- `tests/subscription_preferences_test.php` - 现有 PHP 回归测试风格示例之一

### 公开页与主题色链路
- `login.php` - 登录页 `theme-color` 输出与登录 cookie 设置
- `registration.php` - 注册页 `theme-color` 输出
- `includes/header.php` - 登录后页面 `theme-color` 与 service worker 预取触发点
- `scripts/common.js` - `theme-color` 更新逻辑与通用请求反馈层
- `scripts/login.js` - 登录页主题色同步逻辑
- `scripts/registration.js` - 注册页主题色同步逻辑

### 鉴权与会话恢复链路
- `includes/checksession.php` - 页面请求的登录恢复逻辑
- `includes/connect_endpoint.php` - endpoint 会话恢复、`wallos_login` cookie 恢复与限速入口
- `endpoints/media/subscriptionimage.php` - 媒体访问链路中的会话恢复参考

### 订阅分页与 endpoint 契约
- `scripts/subscription-pages.js` - 分页切换前端逻辑与 URL 参数处理
- `scripts/subscriptions.js` - 订阅页分页切换与 401 处理入口
- `includes/subscription_pages.php` - 订阅分页过滤、赋值与 payload 构建逻辑
- `endpoints/subscriptionpages.php` - 分页管理 JSON endpoint
- `endpoints/subscriptions/get.php` - 订阅列表与 `subscription_page` 过滤响应

### 健康检查与缓存
- `health.php` - 最基础健康检查返回契约
- `scripts/all.js` - service worker 注册入口
- `service-worker.js` - 静态缓存版本常量与缓存资源清单

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `tests/*.php` - 已有可直接复用的 PHP 回归测试风格，适合作为统一 smoke 入口的子检查模块
- `health.php` - 极简、稳定的健康检查端点，适合放在回归清单最前面
- `scripts/common.js` - 已存在统一反馈与请求包装基础，可作为未来失败提示对齐参考
- `includes/connect_endpoint.php` - 已经具备 endpoint 登录恢复与限速入口，是后续 Phase 2 收敛会话逻辑的重要基线

### Established Patterns
- 项目当前既有“纯文本响应”也有“JSON 响应”，回归检查应先忠实验证当前契约，而不是在 Phase 1 顺手改协议
- 登录态核心围绕 `wallos_login` cookie + session 恢复展开，受保护 smoke 不能只做匿名访问
- `theme-color` 已经分散在公开页与登录后页面多处输出/同步，回归需要至少覆盖首屏输出而不是只看 JS
- `service-worker.js` 使用固定缓存版本常量（当前为 `static-cache-v14` 等），说明缓存状态适合先做观测，不适合立刻做强制门禁

### Integration Points
- 回归入口需要连通公开页 (`login.php`, `registration.php`) 与受保护链路 (`endpoints/subscriptionpages.php`, `endpoints/subscriptions/get.php`)
- 认证 smoke 需要连接 `login.php` 的登录流程与后续 endpoint 请求
- 订阅分页 smoke 需要覆盖前端使用的 `subscription_page` 参数与后端 payload 契约

</code_context>

<specifics>
## Specific Ideas

- 用户明确要求“按最稳定的方法做”。
- 本阶段优先做一条可靠的本地回归主入口，而不是追求一次性把浏览器自动化、视觉回归、缓存版本门禁全部做满。
- Phase 1 的目标是降低回归风险，不是引入新的高维护成本测试体系。

</specifics>

<deferred>
## Deferred Ideas

- 完整浏览器 E2E / Playwright 级别的订阅分页交互回放 - 可在后续稳定性阶段再评估
- “静态资源一改就必须强制 bump Service Worker 缓存版本”的硬门禁 - 先观察，后续再决定是否收紧
- 更大范围的主题矩阵、动态壁纸矩阵、移动端视觉回归 - 不属于 Phase 1 最小回归范围

</deferred>

---

*Phase: 01-regression-safety-baseline*
*Context gathered: 2026-04-20*
