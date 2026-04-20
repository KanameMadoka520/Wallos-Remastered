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

```bash
docker exec wallos-local php /var/www/html/tests/regression_runner.php --base-url=http://127.0.0.1
```

详细说明请继续阅读：

- `docs/共享请求层与稳定性契约.md`
