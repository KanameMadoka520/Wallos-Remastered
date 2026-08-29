# Wallos-Remastered

> 面向长期自托管的 Wallos 重制分支。当前版本：`v5.4.5-remastered.6`；上游兼容基线：[`ellite/Wallos v5.4.5`](https://github.com/ellite/Wallos/tree/v5.4.5)。

[English README](README_EN.md) · [变更记录](CHANGELOG.md) · [安全策略](SECURITY.md) · [贡献指南](CONTRIBUTING.md)

## 这是什么

Wallos-Remastered 保留 Wallos 的订阅、统计、多币种、通知、OIDC/TOTP 等基础能力，并针对中文用户、多人受控使用和长期运维做了大量扩展。

它不是上游页面的原样复制，也不是官方 Docker 镜像的换皮版。上游 `v5.4.5` 的兼容与安全修复经过人工移植，再与本分支的账本、媒体、主题和管理功能整合。因此，部署方式、数据库结构和部分交互均与官方 Wallos 不同。

## 最值得关注的功能

- **金额更接近真实支出**：可记录每次实际付款、特殊价格、一次性购买、月/年/账期预算；统计、日历和未来预测会共同使用这些数据。
- **续费日期不易漂移**：月底、闰日、手动续订和定时推进使用同一套锚定日期算法，例如 1 月 31 日会先落到 2 月末，再回到 3 月 31 日。
- **订阅更容易整理**：支持自定义分页、拖动排序、单/双/三列布局、回收站、详情弹窗和账号级显示偏好。
- **图片不再只是一个 Logo**：每个订阅可保存多张图片，并分为缩略图、预览图和原图；私有媒体通过鉴权端点读取，黑白文字型 Logo 可随深浅主题切换变体。
- **适合受控的多人实例**：提供邀请码、用户分组、封禁与延期删除、管理员密码重置、登录限速、访问日志和异常记录。
- **备份与维护有闭环**：可自动或手动备份数据库与 Logo，执行校验、下载和恢复；后台还提供 SQLite、日志和图片一致性检查。
- **通知和单点登录更适合容器**：可在账期开始时发送支出摘要，也可用 `OIDC_*` 环境变量声明式配置身份提供商。
- **多语言有可靠兜底**：保留上游语言选择；某种语言尚未翻译的新词条会显示英文原文，而不是直接露出 `[Translation Missing]`。
- **截图可以隐藏真实订阅**：显示设置中的截图脱敏模式会临时用演示名称、价格、说明和内存图标替代真实订阅内容，不改数据库；关闭后恢复原显示。
- **保留重制视觉体验**：支持动态壁纸、毛玻璃、自定义主题与 CSS、按目标页面变化的转场场景、沉浸模式，并可让偏好跟随账号。

## 与上游 Wallos v5.4.5 的区别

| 方面 | 上游 Wallos | Wallos-Remastered |
| --- | --- | --- |
| 定位 | 通用的个人订阅管理器 | 中文优先、多人受控、便于长期维护的重制分支 |
| 部署 | 常用官方预构建镜像 | 推荐从本仓库源码构建，默认端口 `18282`，固定基础镜像与关键依赖 |
| 支出记录 | 以上游订阅与统计模型为主 | 增加实际付款账本、特殊价格、价值折算和多种预算，并统一预测口径 |
| 订阅组织 | 上游标准列表与筛选 | 增加分页、拖动排序、多列布局、回收站和详情弹窗 |
| 媒体 | 以上游 Logo 能力为主 | 增加主题 Logo 变体、订阅多图、三层派生图、鉴权读取、审计与受限清理 |
| 多人运维 | 上游用户与管理能力 | 增加邀请码、分组、封禁、限速、访问日志、维护建议和动作审计 |
| 备份恢复 | 保留上游能力 | 扩展为数据库 + Logo 备份、清单校验、后台恢复、运行锁和失败回滚 |
| 容器化认证 | 支持在页面管理 OIDC | 保留页面配置，并补齐 `OIDC_*` 环境变量、Issuer Discovery 和 Secret File |
| 界面 | 上游主题和页面 | 增加中文默认体验、动态壁纸、页面专属转场、截图脱敏及账号级主题与布局偏好 |

这里的“兼容基线”表示已吸收适合本分支的 `v5.4.5` 行为，不表示两个仓库逐文件相同。例如 Logo 搜索仍保留 Remastered 的 DuckDuckGo + Brave 路线，没有照搬上游 Google/selfh.st/Dashboard Icons 的整套界面。请不要把官方镜像和本仓库镜像混用，也不要跳过备份直接互换数据库。

## Docker 部署

### 要求

- Docker Engine 25 或更新版本
- Docker Compose V2
- 建议使用 Linux 主机，并预留数据库、图片和备份的持久化空间

仓库内的 Compose 使用严格的 bind mount：宿主机目录不存在时不会偷偷创建空目录。这样可以降低路径写错后启动出一个“全新空实例”的风险。

### 全新安装

```bash
git clone https://github.com/KanameMadoka520/Wallos-Remastered.git
cd Wallos-Remastered
mkdir -p logos backups
cp -n db/wallos.empty.db db/wallos.db
docker compose up -d --build
```

`cp -n` 命令**只用于全新安装且 `db/wallos.db` 不存在时**；`-n` 用于拒绝覆盖已有文件。模板数据库会在第一次启动时离线迁移到当前结构。已有实例应跳过整段初始化，只按后文升级流程操作。

默认持久化目录和端口如下：

| 宿主机位置 | 容器用途 |
| --- | --- |
| `./db` | SQLite 数据库 |
| `./logos` | Logo、头像和订阅图片 |
| `./backups` | 自动与手动备份 |
| `18282` | Web 访问端口 |

检查运行状态：

```bash
docker compose ps
curl http://127.0.0.1:18282/health.php
docker compose logs --tail=100 wallos
```

健康检查正常时返回 `OK`。打开 `http://主机地址:18282` 注册，第一个用户（`id = 1`）是初始管理员。完成后请立即确认注册策略、邀请码、登录限速和备份保留时间。About 页面应显示当前 Remastered 版本和上游基线。

### 升级已有 Remastered 实例

先在管理员后台创建并校验备份，再把 `db`、`logos`、`backups` 复制到另一块磁盘或另一台机器。确认备份可用后执行：

```bash
docker compose down
git fetch --tags
git checkout v5.4.5-remastered.6
docker compose up -d --build
curl http://127.0.0.1:18282/health.php
```

启动程序会在 Web 和定时任务运行前检查数据库、离线执行迁移并再次验证。数据库缺失、为空、迁移记录冲突或结构不符合要求时会拒绝启动，不会自动生成一个看似正常的空库。

升级时请特别注意：

- 不要复制 `wallos.empty.db`，不要删除或重新创建现有持久化目录。
- 如果改过 `docker-compose.yaml`，先人工合并端口、时区、用户 ID 和反向代理配置。
- 从官方 Wallos 或很早的自定义分支迁移时，先在数据库副本和测试环境演练；“有迁移脚本”不等于所有第三方魔改都可自动识别。
- 失败时先查看 `docker compose logs wallos`，保留现场和备份，不要用空数据库反复尝试。

### 可选：账期开始摘要

在“设置 → 通知”开启“在每个账期开始时发送摘要”，并至少配置一个支持文本摘要的通知渠道。定时任务会按账号时区，在所选周、双周或月账期的第一天发送本期预计所需金额；账期预算大于 `0` 时还会附上预计剩余预算。常规文本渠道会自动带上摘要；自定义 Webhook 只有在 payload 中加入 `{{period_summary}}` 才会收到它。该选项默认关闭，只发送计算摘要，不会自动创建付款记录或改变续费日期。

### 可选：声明式 OIDC

OIDC 既可在管理员页面设置，也可写进 Compose 的 `environment`。只要某个 `OIDC_*` 变量已设置，它就在运行时覆盖对应数据库值；页面不会把环境变量中的密钥回写进数据库。

- `OIDC_ENABLED`、`OIDC_PROVIDER_NAME`、`OIDC_CLIENT_ID`：启用状态、名称和客户端 ID。
- `OIDC_ISSUER`：自动读取 `/.well-known/openid-configuration`；`OIDC_AUTH_URL`、`OIDC_TOKEN_URL`、`OIDC_USERINFO_URL` 可分别覆盖发现结果。
- `OIDC_CLIENT_SECRET_FILE`：从容器内文件读取密钥，优先于 `OIDC_CLIENT_SECRET`，推荐配合 Docker Secret 或只读挂载使用。
- `OIDC_REDIRECT_URL`、`OIDC_LOGOUT_URL`、`OIDC_USER_IDENTIFIER`、`OIDC_SCOPES`：登录流程细节。
- `OIDC_AUTO_CREATE_USER`、`OIDC_DISABLE_PASSWORD_LOGIN`、`OIDC_REQUIRE_EMAIL_VERIFIED`：自动建号、密码登录和邮箱验证策略。

如果身份提供商位于内网，还需把准确的主机或 `主机:端口` 加入管理员安全设置的 SSRF allowlist（也可使用 `SSRF_ALLOWLIST` 环境变量）。先用管理员账号完整测试 OIDC，再关闭密码登录；不要把客户端密钥直接写进仓库里的 Compose 文件。

## 备份与恢复

管理员后台可创建、下载、校验和恢复备份，也可设置自动备份及保留天数（默认 14 天）。Remastered 备份包含 SQLite 数据库、Logo/头像/订阅图片和校验清单。

恢复时会先逐文件校验归档，把新数据库和媒体完整暂存在各自的 Docker 挂载卷内，迁移并联合复核后才标记提交。提交前失败会恢复旧数据；如果回滚无法完整完成，持久 journal 会让后续请求和容器启动保持关闭，避免拿半份数据继续服务。切换媒体的短暂窗口会返回维护状态，而不是暴露新旧混合文件。但任何应用内保护都不能替代异机备份，建议定期完成一次“下载备份 → 在测试实例恢复 → 核对订阅和图片”的演练。

`/db/`、`/backups/` 和订阅私有媒体不应作为静态目录暴露。若数据库损坏或容器无法启动，请先复制完整现场；不要手工解压覆盖正在运行的实例。空实例的首次恢复仅允许本机直连，以防公网访问者抢先接管。

## 安全提醒

- 公网、FRP 或反向代理部署必须保留正常登录，并在入口启用 HTTPS；“禁用登录”只允许真正的本地直连旁路。
- API key 使用 `X-API-Key` 或 `Authorization: Bearer ...` 请求头，不要放进 URL、日志或截图。
- 管理员密码、Cookie、GitHub Token、`.env`、数据库和备份都不能提交到仓库。
- CSRF 页面令牌服务端有效期为 30 分钟；页面放置很久后，提交表单前先刷新。
- 外部 Logo、SMTP、Webhook 等地址有 SSRF 和重定向限制；被拒绝访问内网或异常跳转通常是预期安全行为。
- 建议限制 `18282` 仅供反向代理或可信网络访问，并定期检查后台的异常、慢请求、图片审计和维护动作记录。

更完整的边界与漏洞报告方式见 [SECURITY.md](SECURITY.md)。公网防刷可参考 [FRP + Nginx + Fail2ban 指南](FRP+Nginx+Fail2ban防刷站部署指南.md)。

## 目录结构

```text
api/、endpoints/          API、表单动作、定时任务和数据库入口
includes/                账期、汇率、媒体、备份、安全等共享逻辑
migrations/              按编号执行的 SQLite 迁移
scripts/、styles/         前端交互、主题和样式
tests/                   PHP 回归检查与 Playwright 浏览器测试（不打进正式镜像）
db/                      数据库模板与运行期 wallos.db
logos/                   Compose 使用的运行期媒体目录（首次部署需创建）
backups/                 运行期备份目录
Dockerfile               正式源码构建镜像
docker-compose.yaml      默认单容器部署
startup.sh、nginx*.conf  启动检查、进程监管与 Web 安全边界
```

运行期数据不应进入版本库；忽略规则也不是安全边界。尤其要在发布前检查根目录 `logos/`、`db/wallos.db`、`backups/` 和 `git status`，绝不能暂存真实媒体、数据库、备份或凭据。

## 开发与测试

常规改动至少应完成 PHP/JavaScript 语法检查、健康检查和回归 runner。正式镜像由 `.dockerignore` 排除了 `tests/`，不能在生产容器里执行 `/var/www/html/tests`。在 Linux 主机的仓库根目录，可把源码只读挂载到临时容器，并复用已构建镜像的 PHP 环境：

```bash
docker run --rm --network host --entrypoint php \
  -v "$PWD:/work:ro" \
  wallos-remastered:v5.4.5-remastered.6 \
  /work/tests/regression_runner.php --base-url=http://127.0.0.1:18282
```

这里的 `--network host` 指 Linux Docker 的主机网络；Docker Desktop 需要按平台改用可访问宿主机的地址，不能原样假设 `127.0.0.1` 指向宿主机服务。

需要验证真实新增、编辑、付款和删除链路时，使用专用测试账号并追加 `--username`、`--password` 和 `--mutating-auth-checks`。该模式会写入临时数据，不要直接拿重要生产账号试跑。

浏览器级测试需要 Node.js：

```bash
npm ci
WALLOS_BASE_URL=http://127.0.0.1:18282 \
WALLOS_TEST_USERNAME=测试账号 \
WALLOS_TEST_PASSWORD=测试密码 \
npm run e2e:subscriptions
```

还可分别运行 `e2e:i18n`、`e2e:images`、`e2e:cache`、`e2e:admin`，或用 `npm run e2e` 顺序执行。管理员测试必须提供显式且未过期的测试凭据；详细约定见 [贡献指南](CONTRIBUTING.md) 和 [共享请求层与稳定性契约](docs/共享请求层与稳定性契约.md)。

提交功能时请同步检查迁移、权限、翻译、测试和文档。欢迎通过 [Issues](https://github.com/KanameMadoka520/Wallos-Remastered/issues) 报告可复现问题，或按 [CONTRIBUTING.md](CONTRIBUTING.md) 提交改动。

## 许可证与链接

本分支继续使用 [GNU GPLv3](LICENSE.md)。它是社区重制分支，问题请优先反馈到本仓库，不要让上游 Wallos 为本分支特有行为背锅。

- [Wallos-Remastered 仓库](https://github.com/KanameMadoka520/Wallos-Remastered)
- [上游 Wallos](https://github.com/ellite/Wallos)
- [完整变更记录](CHANGELOG.md)
- [英文说明](README_EN.md)
