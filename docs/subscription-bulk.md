# 批量订阅管理

订阅页的“批量管理”可以选择当前页或逐条勾选，最多 50 条。翻页、搜索或筛选刷新列表时清空选择，不隐含选择其他页。操作包括提醒开关、提前提醒天数、完整分类列表替换、付款方式，以及移入现有 180 天回收站。分类操作名称明确为“替换全部分类”，避免误以为是追加。

预览列出全部目标的 ID、名称及操作值，完成服务端验证后回滚，不保存业务数据。点击确认才正式提交，并重新验证预览时的全部版本。任意记录被其他网页、Agent 或 cron 修改，整批拒绝，用户必须重新预览。不会自动刷新版本后覆盖并发改动。

每批最多 50 条，以一个 SQLite 事务提交。记录归属、引用归属和版本验证均在服务端执行；付款历史、优惠规则、图片及未修改字段保留。批量删除使用回收站，不提供永久删除。对超过上限的数据应明确分成多批，不承诺跨批次原子性。

## MCP / HTTP 工具

- `wallos_subscriptions_bulk_update` 需要 `subscriptions:write`。
- `wallos_subscriptions_bulk_trash` 需要 `subscriptions:delete`。
- `targets` 为 1–50 个 `{id, expected_version}`，版本来自最近一次订阅读取。
- 更新的 `changes` 仅允许 `notify`、`notify_days_before`、`category_id`、`category_ids`、`payment_method_id`。显式 `category_ids` 替换全部分类；旧 `category_id` 保留其他关联并提升主分类，遵循单条更新约定。
- 删除要求 `confirm_ids` 与目标 ID 集合完全相同，无重复项。
- 两个工具均要求独立 `request_id`，支持 `dry_run`，继承单条工具的权限、事务、防重与审计约定。

```json
{
  "tool": "wallos_subscriptions_bulk_update",
  "arguments": {
    "targets": [
      {"id": 123, "expected_version": "REPLACE_WITH_LATEST_VERSION"},
      {"id": 124, "expected_version": "REPLACE_WITH_LATEST_VERSION"}
    ],
    "changes": {"notify": true, "notify_days_before": 7},
    "request_id": "notify-batch-20261103-preview",
    "dry_run": true
  }
}
```

预览后正式执行使用新 request_id，网络重试则必须使用完全相同的原请求和 request_id。`replayed=true` 表示返回已提交的原结果，不是再次执行。不同参数复用同一 request_id 会拒绝。预览中的版本与 ID 不能用于后续写入，应使用提交前真实读取的版本。

## 浏览器与运维

浏览器入口 `endpoints/subscriptions/bulkedit.php` 仅接受登录会话、POST 和有效 CSRF，不能用 Bearer 密钥替代。它调用与 MCP 相同的业务函数。会话批量操作不会创建或借用 Agent key；审计中 `key_id=NULL`、工具名前缀为 `browser.`。

截图脱敏模式下，浏览器不输出真实分类/付款方式选项，也不开放批量预览与写入，避免预览泄露卡片背后的真实名称；MCP 仍遵循其独立的权限和数据访问约定。

浏览器的防重响应在迁移 86 新增的 `subscription_bulk_requests` 表中，与业务写入和逐项成功审计一起提交。该表可能含私人订阅结果，遵循数据库备份与隐私规则，不作为公开日志导出。清理账号会清除该账号的防重记录。防重记录不自动过期，避免旧请求在记录过期后再次执行。

如果正式执行响应丢失，页面冻结原操作并显示“重试原请求”，保留原 ID 和参数。明确的版本、权限或参数拒绝会退出预览，要求重新检查。查看预览本身不锁定对象。

验证入口：`tests/subscription_bulk_test.php` 使用内存数据库；`tests/e2e/subscription_bulk_smoke.mjs` 只针对一次性隔离 fixture，覆盖真实勾选、预演、冲突回滚、断网后原请求重试、分类替换、窄屏、搜索清空和回收站。
