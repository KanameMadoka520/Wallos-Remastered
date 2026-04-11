# Wallos-Remastered

## 项目定位

`Wallos-Remastered` 是基于上游 `Wallos` 深度重制的自托管订阅管理系统分支。

这个分支的目标不是只做少量界面修补，而是把原项目补到更适合长期私有化部署、管理员集中运维和多用户受控使用的状态。当前分支已经在以下方向完成了较大幅度增强：

- 管理员后台重构
- 用户分组与账号回收站
- 邀请码生命周期管理
- 登录限速与日志留存
- 订阅图片多图上传、鉴权访问、预览分层与资源治理
- 自动备份、备份校验、后台恢复闭环

项目正式名称统一为 `Wallos-Remastered`。

## 已完成的重制内容

### 1. 管理员后台能力增强

- 新增用户管理折叠区，用户以独立卡片形式展示。
- 管理员卡片中展示用户 ID，并支持一键复制用户 ID。
- 管理员可以直接为用户重置并生成临时密码，由管理员复制后转发给用户。
- 不提供明文密码查看能力，密码只以哈希形式保存在数据库中。
- 登录失败限速阈值已接入管理员后台配置。
- 访问日志区域改为可折叠展示。

### 2. 邀请码与用户封禁名单

- 邀请码管理区域支持折叠。
- 邀请码区分“有效 / 已删除”两个分页。
- 已删除的邀请码支持彻底删除，不再在页面显示。
- 原回收站已重构为“封禁用户名单”。
- 封禁名单区域支持折叠。
- 封禁用户卡片显示用户基础数据与关联统计，包括订阅数量、上传图片数量、头像数量等。
- 支持修改计划删除时间。

### 3. 用户分组与图片上传权限

- 用户分组支持 `free / trusted / admin` 的实际管理语义。
- `free` 用户不能向服务器上传订阅图片，只能使用外链。
- `trusted` 用户可按管理员设定的数量上限上传订阅图片。
- 管理员和受信用户在上传时可选择“是否压缩作为主文件保存”。

### 4. 登录安全与运行期维护

- 增加登录失败次数追踪与限速。
- 增加请求日志清理任务。
- 增加封禁用户到期清理任务。
- 本地 Webhook 允许名单已接入后台设置。
- 相关安全设置已统一进入管理员后台维护。

### 5. 订阅图片能力重做

#### 多图与展示

- 订阅支持多张服务器图片。
- 添加/编辑订阅时，服务器图片选择后立即显示缩略图，不再出现“当前没有选中服务器图片”的误导。
- 订阅详情区支持多图展示。
- 支持布局切换：聚焦视图 / 网格视图。
- 图片查看器支持整组浏览、上一张/下一张、键盘左右切换、移动端滑动。

#### 图片资源分层

服务器图片现在分成三层资源：

- 主文件：保存在 `subscription_uploaded_images.path`
  - 如果上传时勾选压缩，则这里保存“第一次上传压缩后的主文件”
  - 如果上传时不勾选压缩，则这里保存“第一次上传处理后的高清主文件”
- 预览图：保存在 `subscription_uploaded_images.preview_path`
  - 页面里的图片预览弹窗默认使用预览图
- 缩略图：保存在 `subscription_uploaded_images.thumbnail_path`
  - 订阅列表卡片与表单卡片默认使用缩略图

这意味着：

- 即使上传时没有勾选压缩，页面展示仍不会直接加载原始主文件。
- 只有点击“打开原图”或下载原图时，才会请求主文件。
- 如果上传时已经选择压缩主文件，预览图/缩略图仍会从主文件继续派生一次，但不会进入无限重复压缩链。

#### 图片访问控制

- 服务器图片的原始静态直链已经被 Nginx 拦截。
- 订阅图片必须通过受保护的媒体端点访问。
- 普通用户只能访问自己的图片。
- 管理员可以跨用户查看图片，用于审核违规内容。
- 预览图、缩略图、原图都遵循同一套权限校验规则。

#### 图片删除与派生清理

- 删除订阅图片时，会同时删除：
  - 主文件
  - 预览图
  - 缩略图
- 避免磁盘遗留脏文件。

#### 历史图片补图

- 订阅页面新增“一键生成图片缩略图”按钮。
- 会扫描当前用户的历史服务器图片。
- 如果某张图片已经存在缩略图和预览图，则自动跳过。
- 如果缺失，则补生成对应派生图。

#### 图片顺序

- 订阅添加/编辑页的服务器图片卡片支持拖拽排序。
- 订阅详情页中的服务器图片也支持拖拽排序。
- 排序结果会写回数据库的 `sort_order` 字段并在后续刷新后保持。

#### 上传与原图加载进度

- 订阅图片上传改为带进度的请求流程。
- 页面会区分显示：
  - 上传进度
  - 服务端生成预览图/缩略图的处理阶段
- 点击“打开原图”时，会以单独请求拉取主文件，并显示加载进度提示。

#### 资源与内存控制

- PHP `memory_limit` 已提高到 `512M`。
- 图片处理流程增加了内存预算估算，避免大图直接把 PHP 打爆。
- 对用户而言，建议继续使用缩略图/预览图浏览，减少带宽与服务器负担。

### 6. 自动备份与恢复闭环

#### 自动备份

- 已实现每日自动备份。
- 备份内容包含：
  - `db`
  - `logos`
- 备份目录独立持久化挂载到 `backups`。

#### 后台运维面板

- 后台显示最近备份列表。
- 支持手动创建备份并立即下载。
- 支持一键清理超过保留周期的旧备份。
- 支持直接从最近备份列表发起恢复，不再要求先手工上传 zip。

#### 备份安全

- `/backups/` 原始静态目录已被 Nginx 拦截，不能直接裸链下载。
- 备份文件必须通过受保护后台端点下载。

#### 备份校验

- 新备份会写入校验清单。
- 后台列表支持对单个备份执行校验。
- 恢复前会再次校验，避免用明显损坏的备份覆盖线上数据。
- 对旧格式备份会降级为基础校验，而不是直接拒绝。

### 7. 数据导出增强

- 导出已上传订阅图片时，压缩包中的 `metadata.json` 会补充归属信息：
  - 用户 ID
  - 用户名
  - 存储目录名（例如 `user-1`）
  - 每张图片所属订阅 ID
  - 图片总数

## 当前运行结构

核心持久化目录如下：

- `db/`
- `logos/`
- `backups/`

如果你使用 Docker Compose，建议至少挂载这三类目录：

```yaml
services:
  wallos:
    build:
      context: ${WALLOS_BUILD_CONTEXT}
      dockerfile: Dockerfile.local
    image: ${WALLOS_IMAGE}
    container_name: wallos-local
    restart: unless-stopped
    ports:
      - "${WALLOS_PORT}:80"
    environment:
      TZ: ${WALLOS_TZ}
    volumes:
      - type: bind
        source: ./db
        target: /var/www/html/db
      - type: bind
        source: ./logos
        target: /var/www/html/images/uploads/logos
      - type: bind
        source: ./backups
        target: /var/www/html/backups
```

## 推荐运维流程

### 启动 / 重建

```powershell
cd D:\_Plana_Docker\Wallos
docker compose up -d --build
```

### 健康检查

```powershell
curl.exe http://127.0.0.1:18282/health.php
```

返回 `OK` 即表示主服务存活。

### 语法检查

对改动过的 PHP 文件，建议在容器内执行：

```powershell
docker exec wallos-local php -l /var/www/html/<relative-path>.php
```

### 备份检查

- 进入后台查看最近备份列表
- 抽样执行备份校验
- 定期抽测“从列表恢复”链路

## 重要目录与文件

### 管理后台

- `admin.php`
- `scripts/admin.js`
- `styles/styles.css`

### 订阅图片

- `subscriptions.php`
- `includes/list_subscriptions.php`
- `scripts/subscriptions.js`
- `includes/subscription_media.php`
- `endpoints/media/subscriptionimage.php`
- `endpoints/subscription/add.php`
- `endpoints/subscription/reorderimages.php`
- `endpoints/subscription/generatevariants.php`

### 备份闭环

- `includes/backup_manager.php`
- `endpoints/admin/createbackup.php`
- `endpoints/admin/verifybackup.php`
- `endpoints/admin/restorebackup.php`
- `endpoints/admin/downloadbackup.php`
- `endpoints/admin/cleanupbackups.php`
- `endpoints/cronjobs/createbackup.php`

### 安全相关

- `includes/login_rate_limit.php`
- `includes/security_maintenance.php`
- `login.php`
- `nginx.conf`

## 注意事项

- 本分支是定制化分支，不再等同于上游原始 `Wallos` 行为。
- 文档、变更说明与安全策略均以 `Wallos-Remastered` 为准。
- 如果继续扩展功能，必须同步更新：
  - `README.md`
  - `CONTRIBUTING.md`
  - `CHANGELOG.md`
  - `SECURITY.md`
