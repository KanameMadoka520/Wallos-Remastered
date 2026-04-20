# Roadmap: Wallos-Remastered v1.0 稳定性工程

**Milestone:** v1.0 稳定性工程  
**Goal:** 建立稳定性基线，降低复杂功能持续演进时的回归与排障成本。  
**Phases:** 3  
**Requirement coverage:** 9 / 9

## Phase Overview

| Phase | Name | Goal | Requirements |
|-------|------|------|--------------|
| 1 | 自动化回归检查基线 | 建立可本地运行的最小回归脚本与执行约定 | SAFE-01, SAFE-02, SAFE-03 |
| 2 | 会话与 401 统一层 | 统一受保护请求的登录恢复、401 契约与前端处理入口 | SESS-01, SESS-02, SESS-03 |
| 3 | 可观测性与调试反馈 | 提升异常可见性、用户反馈清晰度与缓存状态可观测性 | OBS-01, OBS-02, OBS-03 |

## Phase Details

### Phase 1: 自动化回归检查基线

**Goal:** 把当前最容易回归的健康检查、公开页、主题输出、关键 endpoint 和订阅分页链路收拢进一个统一的本地检查入口。  
**Requirements:** SAFE-01, SAFE-02, SAFE-03

**Success criteria:**
1. 维护者可以用单个命令运行最小回归检查。
2. 检查结果会输出结构化的通过/失败摘要，并能以非零退出码表示失败。
3. 基础地址与认证输入可以通过参数或环境变量配置，而不是硬编码。
4. 检查清单覆盖 `health.php`、登录页、注册页、`theme-color`、关键 endpoint 与订阅分页切换。

### Phase 2: 会话与 401 统一层

**Goal:** 清理当前分散的会话恢复逻辑，统一 cookie 恢复、401 契约和前端处理方式。  
**Requirements:** SESS-01, SESS-02, SESS-03

**Success criteria:**
1. 页面请求、异步 endpoint 与媒体访问不再维护多套近似的会话恢复逻辑。
2. 受保护 endpoint 的 401 返回格式一致，并且前端可以稳定识别。
3. 会话失效后的刷新、跳转或提示逻辑被统一封装，而不是散落在多个模块里。
4. 新链路不会再把 warning/HTML 片段错误地渲染进业务区域。

### Phase 3: 可观测性与调试反馈

**Goal:** 让维护者更快定位异常，让用户在失败场景下获得明确反馈，并降低缓存问题的排障成本。  
**Requirements:** OBS-01, OBS-02, OBS-03

**Success criteria:**
1. 近期稳定性异常或关键失败事件有统一查看入口或统一数据来源。
2. 慢请求、会话失效和关键接口失败能够展示明确反馈，而不是泛化错误。
3. Service Worker/静态资源缓存状态可以被快速识别和确认。
4. 这些观测能力不会破坏现有主题和公开页视觉效果。

## Dependency Notes

- Phase 1 提供后续每次改动后的最小验收工具，是 Phase 2 和 Phase 3 的安全网。
- Phase 2 优先于 Phase 3，因为很多异常反馈与观测前提依赖统一的会话/401 契约。
- Phase 3 依赖 Phase 2 的统一错误约定，才能把观测和提示做得一致。

## Execution Order

1. Phase 1 - 自动化回归检查基线
2. Phase 2 - 会话与 401 统一层
3. Phase 3 - 可观测性与调试反馈

## Next Command

`$gsd-discuss-phase 1`

Also available:
- `$gsd-plan-phase 1`

---
*Roadmap created: 2026-04-20*
