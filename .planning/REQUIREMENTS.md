# Requirements: Wallos-Remastered

**Defined:** 2026-04-26  
**Core Value:** 在不破坏现有视觉效果和复杂功能链路的前提下，持续提供稳定、可控、适合长期自托管运营的订阅管理体验。

## Previously Validated

- [x] **SAFE-01..03**: 已建立公开页、关键 endpoint、主题色与基础登录链路的回归基线。
- [x] **SESS-01..03**: 受保护请求已统一到共享会话恢复与标准化 `401/session_expired` 契约。
- [x] **OBS-01..03**: 管理后台已具备基础运行可观测性、异常聚合与缓存状态观察能力。
- [x] **FRON-01..02**: 高风险前端请求路径已明显收敛到共享请求层。
- [x] **SUBM-01..02 / SUB2-01..02**: 订阅页已完成两轮模块化减耦，关键交互由浏览器 E2E 覆盖。
- [x] **DOCS-01..02 / SPCL-01..02**: 契约文档化与剩余特殊请求流收口已完成。

## Milestone v1.3 Requirements

### Admin Browser Regression

- [ ] **ADME2E-01**: 维护者可以运行管理后台浏览器 E2E，覆盖运行可观测性、异常浏览器、访问日志、存储维护与备份关键流程。
- [ ] **ADME2E-02**: 管理后台浏览器 E2E 在失败时会输出截图、HTML 与诊断 JSON，并返回非零退出码。

### Cache Governance

- [ ] **CACHE-01**: 管理员可以触发客户端缓存刷新广播，并能在后台看到最新刷新标记变化。
- [ ] **CACHE-02**: 静态回归契约会校验 Service Worker 缓存治理、后台触发入口与静态资源版本连接关系。

### Session And CSRF Recovery

- [ ] **CSRFX-01**: 高风险受保护交互继续共享长时间挂页 / 失效 CSRF 的统一恢复提示与处理约定。
- [ ] **CSRFX-02**: 剩余管理侧受保护交互继续向共享请求/会话 helper 收敛，避免新的 raw `fetch` 漂移。

### Verification And Docs

- [ ] **VER-01**: 本里程碑改动需要同步到自建源码，重建运行容器，并通过 `health.php`、回归脚本和浏览器 smoke 验证。
- [ ] **VER-02**: 回归入口与规划文档需要明确反映新增的后台 E2E 覆盖和当前里程碑范围。

## Future Requirements

- 后台破坏性流程（恢复备份、清空日志、清理旧备份）的隔离式 E2E。
- 更细的 Service Worker / 客户端缓存治理 UI。
- 若体验可接受，再评估严格服务端 CSRF TTL。

## Out of Scope

| Feature | Reason |
|---------|--------|
| 数据库迁移 | 当前阶段优先级仍是稳定性与回归风险控制 |
| 新的大型业务功能 | 当前里程碑继续聚焦“稳定性闭环” |
| 大规模视觉重做 | 保持现有视觉与主题系统稳定 |
| 网关 / nginx / FRP 改造 | 属于独立运维主题，不并入当前代码里程碑 |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| ADME2E-01 | Phase 9 | In Progress |
| ADME2E-02 | Phase 9 | In Progress |
| CACHE-01 | Phase 10 | Planned |
| CACHE-02 | Phase 10 | Planned |
| CSRFX-01 | Phase 11 | Planned |
| CSRFX-02 | Phase 11 | Planned |
| VER-01 | Phase 12 | Planned |
| VER-02 | Phase 12 | Planned |

**Coverage:**
- current milestone requirements: 8 total
- mapped to phases: 8
- unmapped: 0

---
*Requirements defined: 2026-04-26*
*Last updated: 2026-04-26 after milestone v1.3 initialization*
