# Roadmap: Wallos-Remastered v1.1 结构收敛与低耦合重构

**Milestone:** v1.1 结构收敛与低耦合重构  
**Goal:** 继续降低核心复杂区耦合度，重点统一剩余请求层、模块化订阅页前端，并把共享契约明确文档化。  
**Phases:** 6  
**Requirement coverage:** 15 / 15

## Phase Overview

| Phase | Status | Name | Goal | Requirements |
|-------|--------|------|------|--------------|
| 1 | Complete | 自动化回归检查基线 | 建立可本地运行的最小回归脚本与执行约定 | SAFE-01, SAFE-02, SAFE-03 |
| 2 | Complete | 会话与 401 统一层 | 统一受保护请求的登录恢复、401 契约与前端处理入口 | SESS-01, SESS-02, SESS-03 |
| 3 | Complete | 可观测性与调试反馈 | 提升异常可见性、用户反馈清晰度与缓存状态可观测性 | OBS-01, OBS-02, OBS-03 |
| 4 | Pending | 请求层统一收敛 | 收敛管理员/设置/日历等高频页面的剩余请求实现到共享请求层 | FRON-01, FRON-02 |
| 5 | Pending | 订阅页模块化重构 | 按边界拆分订阅页前端逻辑，降低复杂度并保持现有行为 | SUBM-01, SUBM-02 |
| 6 | Pending | 契约文档化与残余收敛 | 文档化主题/API/请求层契约，补齐残余低风险收口 | DOCS-01, DOCS-02 |

## Phase Details

### Phase 1: 自动化回归检查基线

**Goal:** 把当前最容易回归的健康检查、公开页、主题输出、关键 endpoint 和订阅分页链路收拢进一个统一的本地检查入口。  
**Requirements:** SAFE-01, SAFE-02, SAFE-03  
**Status:** Complete (2026-04-20)

### Phase 2: 会话与 401 统一层

**Goal:** 清理当前分散的会话恢复逻辑，统一 cookie 恢复、401 契约和前端处理方式。  
**Requirements:** SESS-01, SESS-02, SESS-03  
**Status:** Complete (2026-04-20)

### Phase 3: 可观测性与调试反馈

**Goal:** 让维护者更快定位异常，让用户在失败场景下获得明确反馈，并降低缓存问题的排障成本。  
**Requirements:** OBS-01, OBS-02, OBS-03  
**Status:** Complete (2026-04-20)

### Phase 4: 请求层统一收敛

**Goal:** 把管理员、设置、日历等高频页面仍然零散的原始请求实现收敛到共享请求层，并统一错误/会话处理模式。  
**Requirements:** FRON-01, FRON-02

**Success criteria:**
1. 主要高频页面的关键请求路径改用共享 `WallosApi` / `WallosHttp`。
2. 原始 `fetch` + 本地错误处理重复逻辑明显减少。
3. 现有行为与视觉不发生回归。

### Phase 5: 订阅页模块化重构

**Goal:** 继续降低订阅页复杂度，把前端逻辑按边界拆分成更清晰的模块。  
**Requirements:** SUBM-01, SUBM-02

**Success criteria:**
1. 订阅页逻辑按数据/状态/渲染/交互边界拆分。
2. 关键订阅流程保持现有行为和视觉。
3. 回归基线在重构后依旧保持绿色。

### Phase 6: 契约文档化与残余收敛

**Goal:** 把主题/API/请求层共享规则文档化，并补齐剩余低风险收口，减少未来退化。  
**Requirements:** DOCS-01, DOCS-02

**Success criteria:**
1. 共享请求层、错误契约和主题 token 规则有明确文档。
2. 开发文档能指导后续改动复用现有层，而不是重新散落实现。
3. 残余低风险收口不会破坏既有回归基线。

## Dependency Notes

- Phase 4 优先，因为剩余原始请求层是继续减耦的最短路径。
- Phase 5 依赖前两轮稳定性工程和请求层收敛成果，否则订阅页模块化容易再次复制旧模式。
- Phase 6 在前两步完成后落文档最有效，因为此时共享模式已经比较稳定。

## Execution Order

1. Phase 4 - 请求层统一收敛
2. Phase 5 - 订阅页模块化重构
3. Phase 6 - 契约文档化与残余收敛

## Next Command

`$gsd-discuss-phase 4`

Also available:
- `$gsd-plan-phase 4`

---
*Roadmap last updated: 2026-04-20 after milestone v1.1 initialization*
