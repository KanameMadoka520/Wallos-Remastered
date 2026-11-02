# Wallos-Remastered 开发约定

- 修改前阅读 `CONTRIBUTING.md`；功能、权限和部署变化同步维护文档与相应回归。
- 本仓库维护者的 Git author 和 committer 使用 `KanameMadoka520 <90090108+KanameMadoka520@users.noreply.github.com>`，提交信息使用中文 Conventional Commits。
- 维护者使用 GitHub 匿名邮箱；不要写入私人邮箱、访问令牌、运行密钥、真实账号密码或生产数据。保留上游贡献者的原始署名。
- 不新增 GitHub Actions；检查和构建在本机执行。
- Agent API 修改遵守 `docs/agent-api.md` 的账号归属、scope、版本、防重、事务及审计约定。写入测试使用隔离数据库。
- 不覆盖或重建已有生产数据库。提交代码和重写 Git 历史不要求重启运行服务。
