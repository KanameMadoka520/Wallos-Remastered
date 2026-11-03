# Wallos v5.10.4 选择性兼容记录（2026-11-03）

> 本文保留 remastered.4 安全适配的历史范围。随后 `v5.10.4-remastered.1` 已继续补齐多付款人、多分类、批量操作和 Web Push；`v5.10.4-remastered.2` 另补 AI、分类翻译与 Logo 目录；当前功能状态与迁移见 [功能整合说明](upstream-feature-integration.md)。下文“本次不引入”只指此前安全发布。

本次发布为 `v5.7.1-remastered.4`。完整历史兼容基线仍为 v5.7.1；额外审阅并选择性吸收官方 v5.7.1 到 v5.10.4 的变化，不宣称完整合并上游分支或支持其所有新功能。审阅目标是 2026-11-01 的稳定标签 `v5.10.4`（`b6bb019`），不追随未发布的 main。原来的账本、优惠规则、回收站、图片、分组、后台维护、截图隐私和 Agent API 契约继续生效。

官方参考：[发布记录](https://github.com/ellite/Wallos/releases/tag/v5.10.4)、[完整差异](https://github.com/ellite/Wallos/compare/v5.7.1...v5.10.4)。运行时 CVE 核查另见 [PHP 安全记录](php-runtime-security-2026-11.md)。

## 已吸收与适配

| 官方提交 / 主题 | 本分支实现与兼容处理 |
| --- | --- |
| `a81772e` SSO-only 后端强制 | 直接 POST 正确本地密码也会被拒绝，不能通过补回浏览器隐藏表单绕过 SSO-only。原有 OIDC 声明式配置、登录限速及账号状态检查保留。 |
| `ba9973d` 订阅外键归属 | 浏览器新增/编辑校验币种、分类、付款方式、付款人、替代订阅及优惠规则币种的账号归属。在事务内再次校验，保留图片、价格规则与账本原子写入；拒绝返回 400 和原有 JSON 错误格式。MCP 原有归属检查继续独立生效。 |
| `693d867`、`121801f` XSS 防护 | 通知和取消通知的远程错误输出只在 Web 上转义；CLI 不改变原始日志。扩展保护重置/验证邮件和汇率任务的动态输出。筛选器先按存量转义存储约定解码一次，再转义输出；统计图数据用 `JSON_HEX_*` 嵌入，保留 Chart.js，不引入 HTML 图例。 |
| `4d24ccc` 通知账号混淆 | 所有付款人查询同时限制所属账号，取消通知的循环不再覆盖当前账号 ID，SSRF 授权始终使用实际账号。 |
| `864234f` Webhook JSON 与 header | 占位符去除 JSON 外层引号时使用定长截取，保留结尾转义引号；共享 header 解析支持 JSON 字典、列表与旧逐行格式，剔除 CR/LF，并只在需要时补充 JSON Content-Type。 |
| `c36e2f4` OIDC 会话与退出 | 回调消费 state 后立即写回 session，保留 state TTL 和单次使用限制；退出支持 `id_token_hint`、`client_id` 与编码后的返回地址，保持 HTTP(S) URL 校验和本地会话/remember token 撤销。 |
| `dd7cf87`、`e603f72` 无密钥汇率 | 增加 Frankfurter v2（provider=2）。手动、定时及切换主币种三条路径统一使用适配器。不自动替换现有付费供应商；临时切到 Frankfurter 不删除已存付费密钥。固定 HTTPS 主机、10 秒超时、1 MiB 响应上限，不跟随重定向；422 可剔除不支持币种并重试一次。 |
| 汇率存储的本分支加固 | 验证基准和所有返回汇率为有限正数；币种更新与刷新时间处于同一事务，失败全部回滚，未覆盖的币种保留原汇率。所有读写限定当前账号；Fixer 的四条请求路径改为 HTTPS，避免明文发送 API key。 |
| `1ea12e6`、`cbd4ec1` 部署管理 SMTP/URL | 增加 `WALLOS_SMTP_*`、密码文件与 `WALLOS_SERVER_URL`，未配置时沿用数据库；托管字段在管理 UI 禁用，保存时后端也不接受覆盖，环境密钥不回写数据库或插入 HTML。SMTP 测试只有管理员显式请求实例配置时才可使用托管凭据；普通用户的个人通知测试不回退到实例密钥。 |
| `59bc32b` 日历价格与续费方式 | 增加价格、币种与自动/手动续费显示。按本分支的具体付款日期解析优惠规则，并统一合计金额；保留自然日倒计时、真实 next_payment 标志及历史已完成/后续受阻状态。先脱敏再格式化价格，避免截图泄露。一次性项目不显示循环续费类型。 |
| `9dd66f4`、`5b3f445`、`ff78a22` 默认名称与阿拉伯语 | 新账号的分类/付款方式支持对应语言；保留中文定制默认值、稳定 ID、`jp` 语言码及 OIDC locale 归一化。扩展显式重置名称映射，不在语言切换时静默改写用户已有名称。补齐阿拉伯语默认名称和错误提示、2–7 天提醒的复数标签。 |
| `a41bae7` NGINX IPv4 回退 | 内核无 IPv6 时将既有双栈监听改为 IPv4；保留数据库离线迁移、进程监督和 FPM 回环限制。 |
| `a540a9e`、`9e560ed` PHP 安全镜像 | 继续使用已修补的 PHP 8.3.36；额外升级 Expat，并收紧 FPM。不是切回官方旧构建镜像。 |

## 保留本分支已有的等价实现

- `1d37f89` / `d2623b4` 的 PWA 静态版本刷新：本分支已有文件指纹、版本化资源 URL 和管理员刷新标志，并额外禁止缓存私有页面、接口及私有媒体。
- `e0f2d1d` 的个人资料保存路径：本分支已指向 `endpoints/user/save_user.php`，浏览器回归继续验证。
- `4fdeeb0` 的有效 locale：保留本分支 CurrencyFormatter 和语言归一化路径。
- `a924db3` 的拒绝响应状态：保留统一 401/400/403/503 JSON 契约，不替换为上游另一套返回格式。
- `e5326ec` / `a224dd2` 的列表布局与操作按钮：保留本分支多列、分页、拖动、详情弹窗和已有事件绑定，实际浏览器验证布局、保存与跳转。
- `1f27e7a` 无分类占位名称：保留本分支默认名称映射与本地化流程。
- `3a1da1c` 图表视觉调整、`6e9c271` 更新横幅与 release 链接：保留本分支 Chart.js 图表和 Remastered 更新展示，不把官方发布标签误当作本分支可直接升级的镜像。

## 本次不引入的结构变化

| 官方能力 | 原因与后续约束 |
| --- | --- |
| 多付款人 `faa0226`、多分类 `d750eac` | 会把订阅的标量 `payer_user_id` / `category_id` 改为多对多关系，涉及归属、账本、预算、筛选、备份和 MCP 输入 schema。不能在同一次安全更新里静默更改字段含义。若引入，应发布独立 API 兼容设计和数据迁移。 |
| 批量操作 `3874f6e`、`b3a4d98`、`29b5b5d` | 依赖上述关联表和官方 DOM；本分支的回收站、版本冲突、图片和账本需要独立定义批量原子性/失败语义。现有 MCP 可按单条有版本及防重保障地操作。 |
| 浏览器 Web Push `ece43eb`、`b308da0`、`9e2ad14` | 需新增设备/订阅密钥和推送库，并改写定制 Service Worker；需先明确设备注销、账号切换、私有数据及密钥生命周期，不能用上游 worker 覆盖本分支缓存和隐私边界。现有通知渠道继续可用。 |
| 切换语言自动改名 `ebb63b0`、`b3acdc0` | 仅凭与默认名称相同无法可靠区分用户自命名。本次支持新账号本地化与显式重置，避免语言偏好保存造成既有分类改名。 |
| 官方 AJAX JSON/图表与 UI 重构 `121801f` | 已移植安全含义；保留现有分页响应、支付 JSON、Chart.js 及定制 DOM，否则会破坏共享请求层、截图隐私和 Agent/浏览器契约。 |

发布、版本号、说明文件删除及纯换行提交不机械复制。本记录列出安全和功能取舍，不能将“选择性兼容”解读为已支持上述未引入功能。

## 部署配置

可选变量如下，无变量的既有部署行为不变：

| 变量 | 含义 |
| --- | --- |
| `WALLOS_SMTP_HOST` / `WALLOS_SMTP_PORT` | 实例邮件服务器与 1–65535 端口，仍须通过 SMTP SSRF 校验。 |
| `WALLOS_SMTP_ENCRYPTION` | `none`、`tls` 或 `ssl`。 |
| `WALLOS_SMTP_USERNAME` / `WALLOS_SMTP_FROM` | 实例 SMTP 用户及发信地址。 |
| `WALLOS_SMTP_PASSWORD` / `WALLOS_SMTP_PASSWORD_FILE` | 文件优先；建议只读挂载 PHP 用户可读的 secret 文件。字段在管理页面留空，避免暴露文件密钥。 |
| `WALLOS_SERVER_URL` | 用于验证邮件、密码重置等的外部 HTTP(S) URL。 |

配置不完整或无效的字段回退到数据库并展示管理说明，不将原始密钥写入说明。环境配置只影响实例邮件；每用户的订阅提醒 SMTP 仍为各自配置。Frankfurter 由用户在设置中主动选择，无需 API key；不支持的币种不获得伪造汇率，仍可能需要人工维护。

本次没有数据库迁移，结构仍为 83。部署前保存在线 SQLite 备份和当前镜像；只替换应用镜像并保留数据挂载。回退应用镜像通常不需要恢复旧数据库，避免覆盖部署后的新数据。

## 验证

已完成本机部署；本地与公网健康/登录接口正常，MCP 无凭据返回 401。部署前后 11 张业务表的记录数与内容哈希一致，SQLite quick_check 通过。生产未执行新增、编辑、删除或双因素配置测试。

全部写入测试在一次性容器及独立数据库进行，测试入口先验证专用账号并拒绝既有业务账号。不得对生产运行 fixture 脚本。

- 25 项静态契约、44 组既有 PHP 回归（含新增 88 项上游适配逻辑检查）。
- 新增 HTTP 测试 24 项：跨账号外键、优惠币种、合法新增/编辑、SMTP 权限、无密钥 provider 与日历。
- 新增受管配置 HTTP 测试 20 项：SSO-only 正确密码直发仍拒绝、环境 SMTP/URL 防覆盖、密钥不出现在 HTML、普通用户不能取得实例 SMTP。
- 新增浏览器专项 18 项：真实恶意分类/付款人/付款方式和 `</script>` 标签保持文本，图表无执行；日历使用优惠价格和正确币种，截图模式遮蔽新增金额，保留倒计时。
- OTPHP 安全回移另有 53 项 RFC/恶意参数检查及 14 项双因素认证 HTTP 生命周期/重放检查，见 [运行时及依赖安全记录](php-runtime-security-2026-11.md)。
- MCP 57 项业务、28 项 HTTP、46 项权限 UI 浏览器检查；实际验证新建、修改、回收及只读/读写/删除 scope。
- 订阅、图片、客户端缓存、管理员维护/备份校验、多语言（29 种）、截图隐私浏览器套件，以及隐私 DOM 检查。
- 运行时 11 项安全检查；软件包漏洞扫描的口径和已知限制见 PHP 安全记录。

复现主检查：

```bash
# 在候选容器中从挂载的源码执行；不要对生产创建 fixture。
php tests/regression_runner.php --base-url http://127.0.0.1 --static-only
php tests/regression_runner.php --base-url http://127.0.0.1 --existing-only
php tests/upstream_5_10_compatibility_test.php
```

HTTP 和浏览器专项分别是 `tests/upstream_http_test.py`、`tests/upstream_managed_http_test.py`、`tests/e2e/upstream_compatibility_smoke.mjs`，需要先等待候选容器 `/health.php` 就绪，再使用 `tests/agent_integration_fixture.php` 和 `tests/upstream_integration_fixture.php` 生成的隔离账号、私有凭据文件。变量为 `WALLOS_UPSTREAM_TEST_URL`、`WALLOS_UPSTREAM_FIXTURE_FILE`、`WALLOS_AGENT_TEST_KEY_FILE`；受管配置测试改用 `WALLOS_MANAGED_TEST_URL` 和 `WALLOS_MANAGED_FIXTURE_FILE`，容器还须设置表中示例测试 SMTP/URL 及完整的 `OIDC_*` SSO-only 测试配置。不得提交这些临时文件或真实密钥。所有检查在本机运行，不新增 GitHub Actions。
