# Wallos-Remastered 贡献指南

## 基本原则

请把本项目视为“长期维护的重制分支”，而不是简单的上游镜像仓库。

默认要求：

- 先理解现有行为，再修改
- 优先保证可运维、可验证、可回滚
- 不引入明文密码、未鉴权资源直链或其他明显危险实现
- 涉及数据结构时必须补 migration
- 功能改动必须同步更新文档

## 提交建议

推荐使用清晰的中文提交信息，例如：

- `补齐自动备份闭环并接入后台列表下载与清理能力`
- `收紧订阅图片访问权限并阻断原始静态直链`
- `重构订阅图片派生图管线并接入拖拽排序与历史补图`

## 最低验证要求

### PHP 语法检查

```bash
docker exec wallos-remastered php -l /var/www/html/<file>.php
```

### 前端脚本检查

```bash
node --check scripts/subscriptions.js
```

### 健康检查

```bash
curl http://127.0.0.1:18282/health.php
```

## 必须同步更新的文件

涉及部署、权限、图片、备份、安全或后台能力变化时，至少同步检查：

- `README.md`
- `README_EN.md`
- `CONTRIBUTING.md`
- `CHANGELOG.md`
- `SECURITY.md`

## 不接受的做法

- 只改前端，不改后端权限或数据层
- 暴露私有图片或备份为公开直链
- 提供明文密码查看能力
- 改完代码却不更新文档

## 共享请求层与回归约定

从 `v1.0` 和 `v1.1` 开始，本仓库已经逐步收敛出一套共享请求层与稳定性基线。继续贡献时，请默认遵守：

- 高频页面的 JSON / Form 请求优先使用 `WallosApi` 或 `WallosHttp`
- 不要为高频页面继续新增 raw `fetch().then(response => response.json())` 的重复链条
- 会话失效、通用错误、成功反馈优先复用共享请求层与 `common.js`
- 只有少数特殊流可以保留原始实现，例如：
  - 下载/导出
  - 纯文本 cronjob 输出
  - 纯二进制媒体流
- 如果你改动了高风险链路，请至少跑一次：

正式镜像通过 `.dockerignore` 排除了 `tests/`，不要假设运行中的生产容器含有测试源码。在 Linux 主机的仓库根目录，用只读源码挂载运行：

```bash
docker run --rm --network host --entrypoint php \
  -v "$PWD:/work:ro" \
  wallos-remastered:v5.7.1-remastered.3 \
  /work/tests/regression_runner.php --base-url=http://127.0.0.1:18282
```

`--network host` 是 Linux Docker 示例；Docker Desktop 需要改用平台提供的宿主机地址。

这套 runner 当前包含公共页面、登录态 endpoint、静态契约和既有 PHP 逻辑回归。静态契约会检查主题默认值、订阅页关键 DOM、订阅页脚本加载顺序、共享请求层、API key 传输约定、订阅图片大小显示槽位、管理后台系统总览、系统总览局部刷新、管理后台运行可观测性、慢请求观测、慢接口排行、慢接口日志筛选入口、维护建议面板、维护建议刷新 E2E、日志内容安全渲染、维护区存储占用统计、日志增长活动指标、图片审计状态卡、图片审计 CSV 导出、SQLite 维护指标、索引健康检查入口和 `database_busy` 错误契约；既有 PHP 逻辑回归会检查关键 SQLite 索引迁移可重复执行，并验证健康检查 helper 可以发现缺失/异常索引。这样可以提前发现“按钮事件漂移”“局部刷新返回格式漂移”“主题默认值被改回蓝色”“日志内容污染后台页面”“维护工具误删数据”“维护建议丢失”“系统总览卡片丢失”“索引迁移漏列或不可幂等”“数据库锁等待变成未知错误”“慢接口没有可观测字段”“慢接口排行聚合丢失”等问题。

如需覆盖登录态页面，请提供专用测试账号：

```bash
docker run --rm --network host --entrypoint php \
  -v "$PWD:/work:ro" \
  wallos-remastered:v5.7.1-remastered.3 \
  /work/tests/regression_runner.php \
  --base-url=http://127.0.0.1:18282 \
  --username=你的测试账号 \
  --password=你的测试密码
```

如需验证真实写入链路，可以追加 `--mutating-auth-checks`。该模式会创建临时订阅并自动清理，不应用真实重要账号运行不可控测试。

订阅页 UI 变动后，建议额外运行浏览器级 E2E：

```bash
npm ci
WALLOS_BASE_URL=http://127.0.0.1:18282 \
WALLOS_TEST_USERNAME=你的测试账号 \
WALLOS_TEST_PASSWORD=你的测试密码 \
npm run e2e:subscriptions
```

修改 PHP/JavaScript 翻译键、语言选择器或 RTL 样式时，先运行无需账号的语言浏览器检查：

```bash
WALLOS_BASE_URL=http://127.0.0.1:18282 npm run e2e:i18n
```

该脚本会真实点击订阅分页、三点菜单、编辑弹窗、新增保存、实际支付记录、记录支付弹窗、图片预览、单/双/三列切换、成本与价值显示开关、动态壁纸相关按钮和 CSRF 持久提醒。脚本会创建临时订阅并自动清理，同时恢复测试前的订阅页显示偏好。

修改 Service Worker、静态资源版本、管理员缓存刷新按钮或右下角提示样式后，请额外运行客户端缓存刷新 E2E：

```bash
WALLOS_BASE_URL=http://127.0.0.1:18282 \
WALLOS_TEST_USERNAME=你的测试账号 \
WALLOS_TEST_PASSWORD=你的测试密码 \
npm run e2e:cache
```

该脚本会检查普通用户页面中的 `WallosClientCache` 状态接口、缓存刷新持久提示、缺失翻译和页面宽度不被提示框撑开。

浏览器级 E2E 失败时会在 `screenshots/e2e/` 留下截图、当前 HTML 和诊断 JSON。修改订阅页交互、主题、Service Worker、共享请求层、CSRF 或弹窗层级后，请优先查看这些诊断文件，而不是只看终端最后一行错误。

详细说明请继续阅读：

- `docs/共享请求层与稳定性契约.md`

## 安全回归提醒

修改 PHP 基础镜像、Alpine 依赖或 FPM 配置时，先对照 PHP 官方公告和 Alpine 对应分支安全库检查实际版本；PHP 为源码构建，不能只依赖 APK 扫描。主镜像安全重建须更新 `SECURITY_REFRESH` 构建参数，部署候选容器运行 `python3 tests/runtime_security_test.py --container 容器名`，并验证 Web 和 MCP。NGINX 与 FPM 同容器部署时，FPM 必须仅监听 IPv4 回环；拆分容器的部署需另行设计受限的 FastCGI 网络，不能直接恢复全网卡监听。

上游 5.7.1 的集成取舍见 [兼容记录](docs/upstream-5.7.1-compatibility.md)。本分支迁移现已到 86；不要覆盖同号上游迁移，也不要批量解码现有备注。增加提醒卡片必须同时覆盖回收站过滤和截图脱密。

涉及以下链路时，提交前请额外确认不要回退本次安全边界：

- 不要重新把“禁用登录”做回公网可触发的管理员旁路
- 不要重新允许空实例通过公网域名执行首次数据库恢复
- 不要重新把永久 API key 设计成通过 URL 查询参数传递
- 不要在支付图标或其他外链抓取逻辑中恢复无约束的自动重定向
- 不要把页脚显示的 CSRF 短指纹改成完整 token
- 不要绕过 CSRF 30 分钟服务端 TTL，除非同步调整安全文档并明确用户体验影响
- 不要让受保护异步接口在未登录、CSRF 失效时返回页面 HTML 或普通 `200` 错误；应使用统一 JSON 契约
- 不要移除共享请求层对登录页 HTML 的 `session_expired` 归一化，否则页面挂久后的接口漂移会重新变成未知错误
- 不要让 Service Worker 模糊缓存 endpoint、带查询参数的接口响应或私有媒体资源
- 不要让订阅图片维护工具自动删除文件；孤儿文件扫描应先报告，由管理员确认后再处理
- 不要把存储占用统计、日志增长提示、图片审计 CSV 导出做成后台自动删除；维护区默认应保持只读报告，只有明确命名的人工维护动作才能修改数据


## Agent API 回归

Agent 接口和密钥管理改动必须阅读 [协议与权限约定](docs/agent-api.md)，运行 `tests/agent_api_test.php`，对隔离测试数据库运行 `tests/agent_http_test.py`。不得用生产数据执行新增/删除测试；MCP 和 HTTP 共享业务函数，禁止出现绕开 scope、归属、版本和幂等的旁路。迁移基线已推进到 86；旧读取 API 和浏览器 CSRF 必须保持兼容。账号清理也必须删除新增密钥、防重与审计记录。

密钥管理 UI 的浏览器回归还须验证权限控件的实际可见性、鼠标 / 键盘交互及所创建密钥的远端权限，不能只断言 DOM 中存在或 `checked` 值正确。已有密钥不自动提权；权限快捷选择仅用于新建。

## 维护者提交身份

本仓库维护者的 author 和 committer 统一使用 `KanameMadoka520 <90090108+KanameMadoka520@users.noreply.github.com>`。提交采用中文 Conventional Commits，保留上游作者署名；不写入私人邮箱、GitHub 令牌或生产凭据。历史邮箱隐私调整必须同时检查提交者、作者、标签和历史文件内容，远端历史更新使用预期旧提交校验，避免覆盖新提交。

## v5.10.4 选择性同步回归

取舍与复现入口见 [兼容记录](docs/upstream-5.10.4-compatibility.md)。多关系使用本分支迁移 84，并保留标量主引用；不得以同号上游迁移覆盖已有历史，不得用官方 Service Worker 覆盖私有缓存边界。浏览器订阅和 MCP 必须同时验证账号归属；新的汇率路径必须保持存储事务及刷新时间一致。实例 SMTP 与普通用户通知 SMTP 不可共享隐式密钥后备。新增的 HTTP/浏览器专项使用独立 fixture 数据库和私有凭据文件，禁止指向生产。

## 多关系、批量及 Web Push 回归

设计与恢复见 [功能整合](docs/upstream-feature-integration.md)。MCP 的关联数组省略时不清空，scalar-only 更新提升主引用并保留其他成员；版本包括关联。批量不绕过单条业务规则，不永久删除订阅，事务同时包含数据、审计与防重。新增 Web Push 必须验证设备归属、退出/切换解绑、外发 endpoint/密钥校验、加密向量、去重及原 Service Worker 缓存隐私边界。真实设备送达与本机模拟测试分开报告。写入测试始终使用隔离数据库。

## AI 与目录搜索回归

AI 调用复用 `includes/ai_client.php`，推荐和分类翻译的模型输出必须先验证再写入。远端调用不持数据库写锁；并发状态变化时拒绝用旧结果覆盖新数据。不得把提供商原始错误、URL 中的凭据或 API key 写入日志或响应。分类翻译保留原有 ID 与关联，用短期预览及显式应用保证用户能检查结果；截图模式阻断该流程。

Logo 目录仅使用审阅过的公共固定来源，维持 DNS 校验、响应大小/时限、有限返回和缓存约束；保存继续复用现有媒体处理，不能把目录 SVG 直接当作可信 HTML。变更时运行相应 AI/翻译/目录 PHP、HTTP 和浏览器专项，并保留订阅、多关系、批量、私有媒体、缓存、语言和截图隐私回归。所有故障注入和模拟 AI 服务只允许连接隔离 fixture；不得对生产实例运行。具体配置及验证入口见 [AI](docs/ai-recommendations.md) 和 [Logo](docs/logo-sources.md)。
