# PHP 运行时安全核查（2026-11-03）

核查对象为本分支单容器 NGINX / PHP-FPM 部署和其 Alpine 3.24 依赖。检查实际运行容器、镜像的软件包清单与有效配置，不以项目写在 README 里的版本作为结论。生产数据不用于新增/删除测试。

## 公告与适用性

| 公告 | 检查结果与处理 |
| --- | --- |
| CVE-2026-109618、CVE-2026-109619 | PHP 官方于 10 月 21 日发布修复，8.3 分支修复版本为 8.3.36。本机检查前已是 8.3.36，继续保留；不能以关闭某项功能代替版本修复。 |
| CVE-2026-115560 | 官方 10 月 23 日说明涉及特殊的会话序列化处理器切换及上传进度组合，完整公告随下一次常规修复发布。现有 `session.upload_progress.enabled=Off` 已符合官方缓解建议，继续保留。关闭的是 PHP 内置会话上传进度，不是文件上传。 |
| CVE-2026-111497 | Brotli 解压越界写；本机 Alpine 的 brotli-libs 已为 1.2.1，包含修复。PHP Windows 的 `8.3.36-1` 是依赖更新后的 Windows 重打包，不是本机 Linux 必须升级的另一个 PHP 源码版本。 |
| CVE-2026-113351、CVE-2026-115506、CVE-2026-117610、CVE-2026-118370 | 检查前 `libexpat 2.9.0-r0` 受影响，主镜像升级并要求至少 `2.10.0-r0`，修复 XML 解析拒绝服务、崩溃与整数溢出问题。它由 fontconfig 依赖引入，不能将此简单等同于 PHP 的 libxml 解析器漏洞。 |
| 9 月 PHP 安全公告 | HTTP 流重定向、FPM IPv6 ACL、OpenSSL、Phar 等修复已包含在当前 8.3.36 中；SOAP 扩展未启用。未发现需要通过回退版本或移植未发布补丁处理的官方 PHP 修复。 |

官方来源：

- [PHP 2026 公告与临时缓解说明](https://www.php.net/archive/2026.php)
- [PHP 8.3 当前版本元数据](https://www.php.net/releases/index.php?json&version=8.3)
- [PHP 源码安全公告](https://github.com/php/php-src/security/advisories)
- [Expat 2.10.0 变更与 4 项 CVE](https://github.com/libexpat/libexpat/blob/R_2_10_0/expat/Changes)
- [Alpine 3.24 main 安全库](https://secdb.alpinelinux.org/v3.24/main.json)、[community 安全库](https://secdb.alpinelinux.org/v3.24/community.json)

## 本次修复

1. 主 Dockerfile 的软件包升级层增加 `SECURITY_REFRESH` 构建参数。`apk --no-cache` 只控制 APK 索引缓存，不能绕过 Docker 的构建层缓存；更新该参数可刷新安全软件包而无需重新编译 PHP 扩展。
2. 对 `libexpat` 加最低版本要求，仓库镜像源尚未提供安全包时构建失败，避免静默保留已知受影响版本。
3. 新增最后加载的 `zzz-wallos-security.conf`，FPM 由所有网卡的 9000 端口收紧为 `127.0.0.1:9000`，显式设置回环客户端白名单，只执行 `.php`。NGINX 原本使用这个回环地址，现有 Web/MCP 路径继续沿用。
4. 增加 `tests/runtime_security_test.py`，读取有效 FPM 配置，并探测实际监听：回环可连接、容器非回环地址不可连接，同时检查 PHP/Expat/Brotli 安全基线与健康接口。

## 重建与验证

首次构建先按 README 构建 PHP 基础镜像，再在仓库根目录执行：

```bash
docker build --build-arg SECURITY_REFRESH="$(date -u +%F)" -t wallos-remastered:local .
python3 tests/runtime_security_test.py --container 你的候选容器名
```

检查脚本只读，不写账号或订阅，但需要本机 Docker 访问权限和运行中的桥接网络容器。基线版本有日期限制，后续仍须检查新的官方公告。拆分 NGINX/FPM 的自定义部署不能直接套用本机回环配置，应单独限制 FastCGI 私网来源。

本次使用 Alpine 官方安全库，将 114 个已安装包按 origin 映射后执行 817 次 APK 版本比较，发现一个受影响包、4 项 CVE；另使用校验过发行包 SHA-256 的 Trivy 0.76.1 和当日漏洞库交叉扫描。更新镜像的 Alpine 包扫描为 0 项已知漏洞；PHP 源码编译产物另行按官方公告人工核验。软件包扫描不涵盖所有手工复制的 PHP 库、应用逻辑、未知漏洞或上游未公开的问题。[Expat 上游跟踪](https://github.com/libexpat/libexpat/issues/1160) 仍列有未公开及待验证问题，因此不作“没有任何安全风险”的保证。

隔离候选容器通过运行时 11 项、Agent 业务 57 项、HTTP/MCP 28 项、浏览器 Agent 46 项、静态 25 项和既有 PHP 回归 42 项检查。测试账号、密钥和新增订阅均留在一次性数据库中。

## 部署与回滚

部署前保留在线 SQLite 备份、原 Compose 和旧镜像。仅替换镜像，沿用持久化目录；本次运行时修复没有数据库迁移。回滚只需恢复原镜像并重新创建容器，通常无需恢复数据库。不要用旧数据库快照覆盖修复后产生的新业务数据。

本记录不等同于入侵取证结论：修补版本和扫描结果不能证明历史上从未遭受攻击。

## 随附 PHP 库核查与 OTPHP 安全回移

本项目不通过 Composer 清单加载这些库，容器扫描显示“0 个语言依赖文件”不能证明随附 PHP 库没有漏洞。单独检查了 PHPMailer 6.8.1、Parsedown 1.8.0（实际启用 Safe Mode）、OTPHP 旧接口与常量时间编码库的上游公告。未以不存在公开公告推断无风险，也没有为追求版本号而整体更换接口。

OTPHP 的 [GHSA-g7m4-839x-ch6v](https://github.com/Spomky-Labs/otphp/security/advisories/GHSA-g7m4-839x-ch6v) 和 [GHSA-2jx3-65f3-xr8r](https://github.com/Spomky-Labs/otphp/security/advisories/GHSA-2jx3-65f3-xr8r) 于 2026-05-31 公布，影响 11.4.3 之前版本，公告未分配 CVE。前者可经异常 `digits` 导致除零错误，后者允许导入 URI 向内部对象属性赋值。本地随附代码具有这两项缺陷。实际页面只用 `TOTP::createFromSecret()` 和固定默认位数，没有调用 `Factory::loadFromProvisioningUri()` 导入外部 URI，因此不能把它描述为已经证实的远程登录攻击入口。

保留当前库文件组织与现有异常接口，回移 11.4.3 的安全含义：

- OTP 位数只接受整数或整数字符串，范围 1–10，读取端同样限制上界。
- 动态属性赋值仅允许验证过的 label/issuer；普通参数不能替换内部参数表或只读时钟。
- URI 只允许公开参数 issuer、algorithm、digits、period、counter，拒绝嵌套参数；对象创建和填充时的错误归一为当前接口的 `InvalidArgumentException`。
- 没有将本地库冒称为完整的 11.4.3；具体回移记录在 `libs/OTPHP/SECURITY-PATCHES.md`。

新增 `tests/otphp_security_test.php` 的 53 项检查覆盖 RFC 4226 / 6238 标准向量、1/6/8/10 位边界、异常位数、恶意 URI、内部属性保护与正常 URI 往返。`tests/otphp_http_test.py` 在隔离账号上验证 14 项实际流程：错误验证码不启用、正常六位验证码启用、密码登录进入第二步、启用验证码不能重放、恢复码单次使用、关闭后恢复正常登录。测试不读取生产 OTP 密钥，也不改变真实账号的双因素配置。

公开来源：[PHPMailer 公告](https://github.com/PHPMailer/PHPMailer/security/advisories)、[Parsedown 公告](https://github.com/erusev/parsedown/security/advisories)、[OTPHP 公告](https://github.com/Spomky-Labs/otphp/security/advisories)、[常量时间编码库公告](https://github.com/paragonie/constant_time_encoding/security/advisories)。
