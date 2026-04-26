# Roadmap: Wallos-Remastered v1.3 后台稳定性闭环与缓存治理

**Milestone:** v1.3 后台稳定性闭环与缓存治理  
**Goal:** 补齐后台高风险运维链路的浏览器回归、缓存治理与会话恢复闭环，在不改变现有视觉效果的前提下继续降低回归风险。  
**Phases:** 4  
**Requirement coverage:** 8 / 8

## Phase Overview

| Phase | Status | Name | Goal | Requirements |
|-------|--------|------|------|--------------|
| 9 | In Progress | 管理后台浏览器级回归 | 为后台高风险运维交互补齐真实浏览器 smoke 与失败诊断产物 | ADME2E-01, ADME2E-02 |
| 10 | Planned | 缓存治理与可观测性加固 | 继续收紧 Service Worker / 客户端缓存刷新闭环与回归保护 | CACHE-01, CACHE-02 |
| 11 | Planned | 会话与 CSRF 恢复收尾 | 把剩余长时间挂页与失效 CSRF 恢复路径继续统一到共享链路 | CSRFX-01, CSRFX-02 |
| 12 | Planned | 验证与文档收尾 | 做最终同步、容器验证、回归整理与里程碑文档收尾 | VER-01, VER-02 |

## Phase Details

### Phase 9: 管理后台浏览器级回归
**Goal:** 为后台运行可观测性、异常浏览器、访问日志、存储维护与备份流程补齐浏览器 E2E，并产出可定位故障的诊断文件。  
**Requirements:** ADME2E-01, ADME2E-02  
**Success criteria:**
1. 后台 smoke 可自动登录管理员账户并进入 `admin.php`
2. 可观测性刷新、异常浏览器、访问日志、存储刷新与备份校验流程可被真实点击验证
3. 失败时会保留截图、HTML 与 JSON 诊断产物

### Phase 10: 缓存治理与可观测性加固
**Goal:** 继续加固 Service Worker / 客户端缓存治理入口，并把关键连接关系纳入静态契约。  
**Requirements:** CACHE-01, CACHE-02  
**Success criteria:**
1. 后台触发客户端缓存刷新后可观察到刷新标记变化
2. 缓存治理相关入口、版本号与静态契约保持同步
3. 新增改动不会重新引入“某些设备缓存异常”的漂移点

### Phase 11: 会话与 CSRF 恢复收尾
**Goal:** 清理剩余长时间挂页、失效 CSRF 与受保护请求恢复逻辑的分散实现。  
**Requirements:** CSRFX-01, CSRFX-02  
**Success criteria:**
1. 剩余高风险交互优先走共享请求层与统一恢复提示
2. 无效 CSRF 与挂页恢复行为在用户视角上保持一致
3. 不引入新的 raw `fetch` 分叉处理

### Phase 12: 验证与文档收尾
**Goal:** 完成源码同步、容器验证、回归复跑与当前里程碑文档收口。  
**Requirements:** VER-01, VER-02  
**Success criteria:**
1. `Walllos_Remastered` 与 `Wallos_TCYSelf` 同步一致
2. 运行容器完成重建，`health.php` 与回归验证通过
3. 回归入口与规划文档准确记录本里程碑新增覆盖面

## Dependency Notes

- 先做 Phase 9，因为后台真实浏览器回归是后续缓存治理与会话收敛的最低风险护栏。
- Phase 10 与 Phase 11 都依赖 Phase 9 提供的“先能稳定复现再继续收敛”能力。
- Phase 12 在前面各阶段完成后统一做验证和收尾。

## Execution Order

1. Phase 9 - 管理后台浏览器级回归
2. Phase 10 - 缓存治理与可观测性加固
3. Phase 11 - 会话与 CSRF 恢复收尾
4. Phase 12 - 验证与文档收尾

## Next Command

`$gsd-discuss-phase 9`

Also available:
- 继续直接执行 Phase 9
- 完成 Phase 9 后进入 Phase 10

---
*Roadmap last updated: 2026-04-26 after milestone v1.3 initialization*
