# Requirements: Wallos-Remastered

**Defined:** 2026-04-20
**Core Value:** 在不破坏现有视觉效果和复杂功能链路的前提下，持续提供稳定、可控、适合长期自托管运营的订阅管理体验。

## v1.0 Validated Requirements

### Regression Safety

- [x] **SAFE-01**: 维护者可以运行一个本地回归检查入口，至少覆盖 `health.php`、登录页、注册页、关键 `theme-color` 输出、订阅分页切换链路与关键 endpoint 返回格式。
- [x] **SAFE-02**: 回归检查在任一项失败时会返回非零退出码，并输出明确的通过/失败摘要，便于在本地或容器内快速判断是否回归。
- [x] **SAFE-03**: 回归检查支持使用可配置的基础地址、认证信息或 cookie，不需要每次手改脚本源码。

### Session Consistency

- [x] **SESS-01**: 受保护的页面请求、异步 endpoint 与媒体访问共用统一的会话初始化/恢复 helper，而不是继续散落多套相似逻辑。
- [x] **SESS-02**: 受保护的异步 endpoint 在会话失效时返回一致、可机读的 401 契约，避免 HTML、纯文本与 JSON 混用。
- [x] **SESS-03**: 前端对会话失效使用统一处理入口，能够根据场景稳定执行刷新、跳转或提示，不再每个模块各自处理。

### Observability

- [x] **OBS-01**: 维护者可以快速看到近期稳定性异常或关键失败事件，便于定位回归和线上异常。
- [x] **OBS-02**: 用户在遇到慢请求、会话失效或关键接口失败时，能看到明确且可关闭的反馈，而不是笼统的“未知错误”。
- [x] **OBS-03**: 维护者可以快速确认当前静态资源/Service Worker 版本状态，以便判断缓存是否影响问题复现。

## v1.1 Requirements

### Frontend Request Convergence

- [ ] **FRON-01**: 管理员、设置、日历等高频页面的关键请求路径使用共享 `WallosApi` / `WallosHttp` 请求层，而不是继续保留零散 `fetch` 与本地错误处理。
- [ ] **FRON-02**: 剩余高频前端模块在请求失败、会话失效和通用错误反馈上遵循同一处理入口，避免重复实现。

### Subscription Modularity

- [ ] **SUBM-01**: 订阅页前端逻辑按数据加载、状态管理、渲染和交互边界进行模块拆分，降低当前脚本复杂度。
- [ ] **SUBM-02**: 订阅页的关键流程（筛选、排序、分页、支付记录、图片相关）在模块化后保持现有行为与视觉效果。

### Contracts And Docs

- [ ] **DOCS-01**: 主题、请求失败契约和共享请求层的使用规则形成明确文档，便于未来改动复用。
- [ ] **DOCS-02**: README / CONTRIBUTING / 规划文档能明确指出哪些层必须复用、哪些做法属于退化风险。

## Out of Scope

| Feature | Reason |
|---------|--------|
| 数据库迁移 | 当前优先级仍是结构收敛与回归风险控制，不是重做数据层 |
| 大型新业务功能 | 本里程碑先处理复杂区减耦，不继续扩张业务面 |
| 主题风格大改版 | 保持当前视觉资产稳定，仅做结构与契约收敛 |
| 外部网关与 VPS 侧大改 | 属于独立运维专题，不纳入本次代码里程碑 |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| SAFE-01 | Phase 1 | Complete |
| SAFE-02 | Phase 1 | Complete |
| SAFE-03 | Phase 1 | Complete |
| SESS-01 | Phase 2 | Complete |
| SESS-02 | Phase 2 | Complete |
| SESS-03 | Phase 2 | Complete |
| OBS-01 | Phase 3 | Complete |
| OBS-02 | Phase 3 | Complete |
| OBS-03 | Phase 3 | Complete |
| FRON-01 | Phase 4 | Pending |
| FRON-02 | Phase 4 | Pending |
| SUBM-01 | Phase 5 | Pending |
| SUBM-02 | Phase 5 | Pending |
| DOCS-01 | Phase 6 | Pending |
| DOCS-02 | Phase 6 | Pending |

**Coverage:**
- v1.0 validated requirements: 9 total
- v1.1 active requirements: 6 total
- Mapped to phases: 15
- Unmapped: 0

---
*Requirements defined: 2026-04-20*
*Last updated: 2026-04-20 after milestone v1.1 initialization*
