# Wallos-Remastered

[English README](README_EN.md)

## 项目简介

`Wallos-Remastered` 是基于上游 `Wallos` 深度改造的重制分支，定位为更适合长期自托管、多用户受控使用、管理员集中运维与资源治理的版本。

这个仓库已经不再等同于官方 `Wallos` 默认行为。与官方版本相比，当前分支重点强化了以下方向：

- 更强的管理员后台
- 用户分组、邀请码与封禁名单管理
- 登录限速与访问日志治理
- 订阅图片多图上传、鉴权访问、缩略图/预览图/原图分层
- 自动备份、备份校验、后台直接恢复
- 更明确的源码构建式 Docker 使用方式

## 与官方 Wallos 的区别

官方 `Wallos` 常见用法是直接运行官方镜像。

`Wallos-Remastered` 的推荐方式不同：

- 推荐直接基于本仓库源码构建镜像
- 默认 compose 示例端口使用 `18282`
- 默认持久化目录包含：
  - `db`
  - `logos`
  - `backups`

也就是说，本仓库的默认 Docker 用法已经和官方仓库不再相同。

## 首次部署与初始管理员

首次部署时，如果数据库中还没有任何用户：

- 系统会自动进入注册流程
- 第一个注册成功的用户会成为初始管理员
- 当前系统默认将 `id = 1` 视为管理员账户

建议你在首次部署完成后，第一时间进入管理员后台确认以下设置：

- 注册是否开放
- 是否启用邀请码注册
- 登录限速阈值
- 订阅图片上传策略
- 备份保留天数

## 登录页与注册页语言

- 登录页默认英文
- 注册页默认英文
- 登录页和注册页都提供页面级语言切换器
- 注册时会自动把当前页面语言作为新用户的初始语言

## 推荐 Docker 用法

### 1. 克隆仓库

```bash
git clone https://github.com/KanameMadoka520/Wallos-Remastered.git
cd Walllos_Remastered
```

说明：

- 仓库目录名你可以自行调整
- 文档不再依赖任何私有本地文件夹结构
- 下文均默认你在仓库根目录执行命令

### 2. 直接源码构建并启动

仓库根目录已经提供源码构建版 `docker-compose.yaml`，直接执行：

```bash
docker compose up -d --build
```

### 3. 默认运行参数

当前仓库内置 compose 的默认行为如下：

```yaml
services:
  wallos:
    build:
      context: .
      dockerfile: Dockerfile.local
    image: wallos-remastered:latest
    container_name: wallos-remastered
    restart: unless-stopped
    ports:
      - "18282:80"
    environment:
      TZ: "Asia/Shanghai"
    volumes:
      - "./db:/var/www/html/db"
      - "./logos:/var/www/html/images/uploads/logos"
      - "./backups:/var/www/html/backups"
```

默认端口：

- `18282`

默认持久化目录：

- `./db`
- `./logos`
- `./backups`

## 健康检查

启动后可使用：

```bash
curl http://127.0.0.1:18282/health.php
```

正常情况下应返回：

```text
OK
```

## 当前已落地的重要增强

### 管理员后台

- 用户卡片式管理
- 用户 ID 展示与复制
- 管理员一键重置并生成用户临时密码
- 邀请码生命周期管理
- 封禁用户名单
- 访问日志可折叠查看
- 登录限速阈值后台可配

### 订阅图片

- 每个订阅支持多张服务器图片
- 图片分层为：
  - 主文件
  - 预览图
  - 缩略图
- 订阅列表默认加载缩略图
- 图片预览弹窗默认加载预览图
- 只有点击“打开原图”时才加载高质量主文件
- 支持上传进度、处理进度、原图加载进度
- 支持拖拽排序
- 支持历史图片一键补生成缩略图与预览图
- 删除图片时连带清理派生图

### 安全与运维

- 登录失败限速
- 图片直链受保护
- 备份直链受保护
- 自动备份 `db + logos`
- 后台显示最近备份列表
- 手动备份、下载、校验、清理旧备份
- 直接从备份列表恢复

## 备份与恢复

### 自动备份

- 自动备份内容包含：
  - 数据库
  - 图片与 logos 目录
- 默认备份目录：
  - `./backups`

### 后台支持

管理员后台支持：

- 创建备份
- 下载备份
- 校验备份
- 清理旧备份
- 直接恢复所选备份

### 安全边界

- `/backups/` 静态目录已由 Nginx 拦截
- 备份必须通过后台鉴权端点访问

## 仓库发布注意事项

本开源仓库版本已经尽量移除或隔离以下内容：

- 私有部署目录结构
- 自建节点说明
- 自建版横幅文案
- 私有域名和环境特有描述

另外，本仓库已通过 `.gitignore` 和 `.dockerignore` 尽量避免纳入以下运行期内容：

- 本地 `.env`
- 临时目录
- 日志
- 运行期数据库
- 运行期图片
- 运行期备份文件

## 文档索引

- 中文说明：`README.md`
- 英文说明：`README_EN.md`
- 贡献指南：`CONTRIBUTING.md`
- 变更记录：`CHANGELOG.md`
- 安全策略：`SECURITY.md`

## 许可证

本分支继续遵循上游项目的 GPLv3 许可证。
