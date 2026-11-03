# Logo 搜索来源与兼容性

订阅编辑表单的 Logo 搜索和设置页的自定义付款方式图标搜索现在共用来源选择器。默认仍是现有的网页搜索：DuckDuckGo 优先，失败后使用 Brave。可以切换至 selfh.st 或 Dashboard Icons，编辑搜索词并分页查看图标；每个结果显示名称和来源。选择图片后仍通过原有订阅或付款方式表单保存。

selfh.st 与 Dashboard Icons 无需配置额外密钥。目录搜索只在 Wallos 服务器取得公共索引后本地筛选；搜索词随带有 CSRF 保护的 POST 发送到本站，不会作为目录请求参数发送到上游。浏览器仅加载当前页的 PNG 预览，使用懒加载且不发送 Referer；上游仍会收到访问该 PNG 的网络请求。目录故障时保留已显示结果，可以继续选择已有结果，或切回网页搜索。切换来源或修改搜索词后，分页按钮只针对当前成功显示的查询启用，避免把旧结果的页码用于另一来源。

截图脱敏模式开启时，两处搜索入口都会阻断新查询；已经打开的搜索窗口会关闭并清空复制的查询和结果。不会通过新的搜索界面绕过订阅表单原有的脱敏保护。

## 上游对照

本实现吸收了 Wallos 官方 v5.10.4 的公共图标目录功能，参考 [官方 icon_search.php](https://github.com/ellite/Wallos/blob/v5.10.4/endpoints/logos/icon_search.php)，同时保留本分支的 DDG/Brave 搜索。上游目录格式已通过实际请求核对：

| 来源 | 公共索引 | 使用字段 | 图标路径 |
| --- | --- | --- | --- |
| [selfh.st/icons](https://github.com/selfhst/icons) | `https://raw.githubusercontent.com/selfhst/icons/main/index.json` | 数组中的 `Name`、`Reference`、`PNG: Yes` | 同仓库 `main/png/{Reference}.png` |
| [Dashboard Icons](https://github.com/homarr-labs/dashboard-icons) | `https://raw.githubusercontent.com/homarr-labs/dashboard-icons/main/tree.json` | `png` 数组中的 `.png` 文件名 | 同仓库 `main/png/{filename}` |

Dashboard Icons 的 `-light`、`-dark` 变体不重复展示，优先提供基础图标。两套目录均按固定主仓库路径请求，只返回 PNG；没有下载整个图标集合，也没有为生成搜索结果逐个请求图片。项目和单个品牌图标的权利归属以各目录仓库说明为准。

本次没有加入 Google Programmable Search。它需要独立的 API key、搜索引擎 ID、配额和设置迁移设计；现有无额外密钥的三种来源已经覆盖网页搜索及两套官方新增目录。后续引入 Google 时，应单独处理凭据保存、脱密、超时、配额错误和已有设置兼容，不能直接复制上游抓取逻辑。

## 请求与网络限制

新增 `POST endpoints/logos/icon_search.php` 使用现有登录会话、账号状态检查、后端请求频率限制及 CSRF 验证。无登录返回 401，非 POST 返回 405，CSRF 或输入错误返回 400，上游不可用且没有有效缓存返回 502。响应使用 JSON、`Cache-Control: private, no-store` 和 `X-Content-Type-Options: nosniff`，遵循共享请求层错误处理。

| 参数 | 约束 |
| --- | --- |
| `source` | 仅 `selfhst`、`dashboardicons` |
| `search` | 1–128 个 UTF-8 字符、最多 256 字节，拒绝控制字符 |
| `page` | 1–250，默认 1 |
| `limit` | 1–48，默认 24；界面固定 24 |

成功响应保留与网页图片搜索兼容的 `results[].thumbnail` / `image` / `width` / `height`，并提供 `name`、`source`、`provider`、`page`、`limit`、`total`、`has_more`、`stale`。名称仅通过 DOM `textContent` 展示，不能插入 HTML。来源标识由本站定义。分页结果严格受数量限制，超出最大页数不会继续提供下一页。

服务器目录获取只允许上述两个完整 HTTPS 索引 URL，主机固定为 `raw.githubusercontent.com`，端口为 443；不接收客户端 URL。解析 DNS 后拒绝任何私网、保留、回环、链路本地或混合公私地址结果，再通过 `CURLOPT_RESOLVE` 将本次连接锁定到校验过的地址。请求验证 TLS，禁用代理和自动重定向；任何 3xx 或非 200 响应均视为失败。连接最多 3 秒，总请求最多 10 秒，单个响应最多 2 MiB、最多 12,000 条原始记录，JSON 深度最多 16。目录中的文件名还要通过字符集、长度和路径穿越检查，才可用于构造固定仓库 PNG 地址。

公共索引缓存位于 PHP 临时目录中按安装路径隔离的 `wallos-public-icon-catalog-*` 私有目录。目录权限 0700、缓存文件 0600，使用文件锁防止并发刷新及临时文件原子替换。正常缓存 1 小时，更新失败时最多回用 24 小时内的旧公共索引，并向界面提示缓存状态；失败后至少等待 60 秒才再次请求同一上游。缓存不保存搜索词、搜索结果、用户 ID、会话或账号资料，前端也不将查询写入 localStorage。

## 保存与现有功能兼容

新目录不直接写入图片文件或订阅数据库。订阅仍设置原有 `logo-url` 字段，经 `endpoints/subscription/add.php` 的 URL、IP、安全下载、GD 解码、PNG 重编码及主题变体处理保存；付款方式仍使用 `icon-url`，由原有 `endpoints/payments/add.php` 验证和重编码。目录来源不能绕过订阅图片、SVG、私有媒体鉴权或服务端 SSRF 防护。现有上传、手工图片、详情图片、主题 Logo 和 MCP 字段约定继续使用原实现。

不需要数据库迁移，也不改变现有数据及 MCP 权限。新增的独立 `scripts/logo-catalog-search.js` 在原页面脚本后增强两个入口；原来的 DDG/Brave endpoint 保持独立可用。静态资源由文件修改时间参与版本号，未扩大 Service Worker 对 PHP 或私有媒体的缓存范围。

## 本机专项验证

使用隔离数据库和专用测试账号运行。PHP 逻辑测试覆盖两套目录格式、恶意文件名、大小和条目上限、DNS 限制、分页、缓存刷新、失效重试和过期行为：

```sh
docker run --rm --entrypoint php -v "$PWD:/work:ro" \
  local-security/wallos-agent:20261103-features-final /work/tests/logo_catalog_test.php
```

浏览器测试会创建并清理临时订阅和付款方式，核对两个真实上游目录、PNG 加载及原有服务端保存链路；同时验证认证、CSRF、非法参数、默认网页来源、恶意名称文本渲染、分页、故障恢复和截图脱敏阻断：

```sh
WALLOS_BASE_URL=http://127.0.0.1:18284 \
WALLOS_TEST_USERNAME=专用测试用户 \
WALLOS_TEST_PASSWORD=专用测试密码 \
npm run e2e:logo-catalog
```

测试还支持从权限受限的 JSON 凭据文件读取 `username` / `password`，通过 `WALLOS_TEST_CREDENTIALS_FILE` 指定路径；测试不会输出其内容。
