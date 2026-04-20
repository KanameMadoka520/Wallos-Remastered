# Requirements: Wallos-Remastered

**Defined:** 2026-04-20
**Core Value:** 在不破坏现有视觉效果和复杂功能链路的前提下，持续提供稳定、可控、适合长期自托管运营的订阅管理体验。

## v1 Requirements

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

## v2 Requirements

### Architecture

- **ARCH-01**: 订阅页前端进一步模块化，拆分数据层、状态层、渲染层与交互层。
- **ARCH-02**: 主题系统建立明确 token 规范与开发文档，持续消除硬编码颜色。

### Performance

- **PERF-01**: 对高频筛选、支付记录与日志查询进行索引与分页专项优化。
- **PERF-02**: 对媒体处理与动态背景进行更系统的性能压测和降载策略补强。

### Operations

- **OPS-01**: 外层 Nginx/FRP/Fail2ban 防刷配置形成与应用内限制相互配合的正式运维方案。

## Out of Scope

| Feature | Reason |
|---------|--------|
| 数据库迁移 | 当前优先级是降低回归风险，不是重做数据层 |
| 新的大型订阅业务能力 | 本里程碑先稳住现有复杂区，避免继续堆功能 |
| 主题风格大改版 | 保持当前视觉资产稳定，仅做稳定性相关修正 |
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

**Coverage:**
- v1 requirements: 9 total
- Mapped to phases: 9
- Unmapped: 0

---
*Requirements defined: 2026-04-20*
*Last updated: 2026-04-20 after Phase 3 execution*

