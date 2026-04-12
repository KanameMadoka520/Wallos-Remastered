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
