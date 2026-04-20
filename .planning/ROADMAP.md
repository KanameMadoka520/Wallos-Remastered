# Roadmap: Wallos-Remastered v1.2 残余特殊流收口与订阅页第二轮减耦

**Milestone:** v1.2 残余特殊流收口与订阅页第二轮减耦  
**Goal:** 继续收掉残余特殊请求流，并对订阅页剩余复杂区做第二轮低风险减耦。  
**Phases:** 8  
**Requirement coverage:** 19 / 19

## Phase Overview

| Phase | Status | Name | Goal | Requirements |
|-------|--------|------|------|--------------|
| 1 | Complete | 自动化回归检查基线 | 建立可本地运行的最小回归脚本与执行约定 | SAFE-01, SAFE-02, SAFE-03 |
| 2 | Complete | 会话与 401 统一层 | 统一受保护请求的登录恢复、401 契约与前端处理入口 | SESS-01, SESS-02, SESS-03 |
| 3 | Complete | 可观测性与调试反馈 | 提升异常可见性、用户反馈清晰度与缓存状态可观测性 | OBS-01, OBS-02, OBS-03 |
| 4 | Complete | 请求层统一收敛 | 收敛管理员/设置/日历等高频页面的剩余请求实现到共享请求层 | FRON-01, FRON-02 |
| 5 | Complete | 订阅页模块化重构 | 按边界拆分订阅页前端逻辑，降低复杂度并保持现有行为 | SUBM-01, SUBM-02 |
| 6 | Complete | 契约文档化与残余收敛 | 文档化主题/API/请求层契约，补齐残余低风险收口 | DOCS-01, DOCS-02 |
| 7 | Complete | 特殊请求流收口 | 收掉残余低频但仍常维护的特殊请求流，并与共享层对齐 | SPCL-01, SPCL-02 |
| 8 | Complete | 订阅页第二轮减耦 | 继续降低订阅页剩余复杂区耦合度 | SUB2-01, SUB2-02 |

## Phase Details

### Phase 7: 特殊请求流收口

**Goal:** 在不破坏文本/下载/特殊返回语义的前提下，收掉仍然频繁维护的残余特殊请求流。  
**Requirements:** SPCL-01, SPCL-02  
**Status:** Complete (2026-04-21)

### Phase 8: 订阅页第二轮减耦

**Goal:** 继续清理订阅页主文件中剩余复杂区，优先处理搜索/过滤/动作分发等边界。  
**Requirements:** SUB2-01, SUB2-02  
**Status:** Complete (2026-04-21)

## Dependency Notes

- Phase 7 先做，因为剩余特殊请求流量级小、风险低、可快速继续压缩漂移面。
- Phase 8 在此基础上继续订阅页第二轮减耦，更稳妥。

## Execution Order

1. Phase 7 - 特殊请求流收口
2. Phase 8 - 订阅页第二轮减耦

## Next Command

`$gsd-new-milestone`

Also available:
- 开启下一个里程碑并继续演进

---
*Roadmap last updated: 2026-04-21 after Phase 8 execution*
