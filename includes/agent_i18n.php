<?php
function wallos_agent_labels($lang)
{
    $en = [
        'title' => 'Agent access / MCP', 'intro' => 'Give each agent its own expiring key and only the permissions it needs. The full key is shown once. Existing browser login and legacy API keys do not grant agent write access.',
        'endpoint' => 'MCP endpoint', 'http' => 'HTTP tool API', 'name' => 'Key name', 'days' => 'Valid for (days)',
        'create' => 'Create key', 'scope' => 'Permissions', 'keys' => 'Your agent keys', 'revoke' => 'Revoke',
        'presets' => 'Permission presets', 'preset_read' => 'Read only', 'preset_write' => 'Read and edit', 'preset_all' => 'All permissions (including delete)',
        'scope_help' => 'Read-only is the default. Select create / edit to allow changes, and delete separately to allow deletion. Presets apply only to the new key; you can adjust each checkbox below.',
        'scope_read' => 'Selected: read-only permissions; this key cannot create, edit or delete records.',
        'scope_write' => 'Selected: read / edit permissions; deletion is not enabled.',
        'scope_delete' => 'Selected permissions include deletion. Payment and reference deletion is permanent; subscription deletion uses the recycle bin.',
        'scope_none' => 'Select at least one permission before creating a key.',
        'replace_help' => 'Existing key permissions cannot be edited here. To change them, create a new key with the desired permissions, replace the key in your agent connection and refresh its tool list, then revoke the old key.',
        'key_readonly' => 'This key is read-only. Allowing writes in the client does not grant write access on Wallos.',
        'confirm' => 'Revoke this key now? New requests will be refused; already committed operations remain.',
        'active' => 'Active', 'revoked' => 'Revoked', 'expired' => 'Expired', 'expires' => 'Expires', 'used' => 'Last used', 'never' => 'Never',
        'once' => 'Save this key now. It cannot be viewed again. Keep it out of chat messages, URLs and version control.',
        'copy' => 'Copy key', 'hide' => 'Hide key', 'error' => 'Request failed. Please retry.', 'empty' => 'No agent keys yet.',
        'audit' => 'Recent agent operations', 'audit_empty' => 'No agent operations recorded.',
        'read' => 'Read', 'write' => 'Create / edit', 'delete' => 'Delete',
        'subscriptions' => 'Subscriptions', 'payments' => 'Payment records', 'references' => 'Categories / members / payment methods / currencies / pages', 'budgets' => 'Budgets',
        'audit_read' => 'Read operation audit', 'loading' => 'Loading…', 'saved' => 'Key created.', 'copied' => 'Key copied.',
        'hint' => 'Subscription deletion moves records to the 180-day recycle bin. Payment/reference deletion is permanent and requires its separate permission. Writes require a version and request ID. Dry-run is available. Recording a payment does not charge money or cancel a provider subscription.',
        'client' => 'Connect using Streamable HTTP and Authorization: Bearer YOUR_KEY. A client must support external MCP servers. Creating this endpoint does not add MCP client support to another application.',
        'readme' => 'Connection examples and safety contract: docs/agent-api.md in the source repository.',
    ];
    if (!str_starts_with($lang, 'zh')) {
        return $en;
    }
    return [
        'title' => 'Agent 接入 / MCP', 'intro' => '给每个 agent 单独创建有有效期的密钥，只授予需要的权限。完整密钥仅显示一次。浏览器登录态和旧 API 密钥不会获得 agent 写入权限。',
        'endpoint' => 'MCP 接口地址', 'http' => 'HTTP 工具接口', 'name' => '密钥名称', 'days' => '有效天数',
        'create' => '创建密钥', 'scope' => '权限范围', 'keys' => '我的 agent 密钥', 'revoke' => '撤销',
        'presets' => '快捷选择权限', 'preset_read' => '仅读取', 'preset_write' => '读取和修改', 'preset_all' => '全部权限（含删除）',
        'scope_help' => '默认只授予读取。需要更改数据时勾选“新建 / 修改”，需要删除时单独勾选“删除”。快捷选择只影响即将创建的新密钥，仍可逐项调整。',
        'scope_read' => '当前选择：只读权限，不能新建、修改或删除数据。',
        'scope_write' => '当前选择：包含读取 / 修改权限，未开启删除。',
        'scope_delete' => '当前选择包含删除权限：付款记录和引用数据会永久删除，订阅会移入回收站。',
        'scope_none' => '请至少选择一项权限后再创建密钥。',
        'replace_help' => '已有密钥的权限不能在这里修改。需要调整时，请按所需权限创建新密钥，在 agent 连接中替换并刷新工具列表，确认后撤销旧密钥。',
        'key_readonly' => '这枚密钥只有读取权限；客户端允许写入，不会自动获得 Wallos 写入权限。',
        'confirm' => '立即撤销这个密钥？后续请求会被拒绝，已经提交的操作仍会保留。',
        'active' => '有效', 'revoked' => '已撤销', 'expired' => '已过期', 'expires' => '到期时间', 'used' => '最近使用', 'never' => '尚未使用',
        'once' => '请立即保存密钥，关闭后无法再次查看。不要放进聊天正文、URL 或代码仓库。',
        'copy' => '复制密钥', 'hide' => '隐藏密钥', 'error' => '请求失败，请重试。', 'empty' => '尚未创建 agent 密钥。',
        'audit' => '最近的 agent 操作', 'audit_empty' => '暂无 agent 操作记录。',
        'read' => '读取', 'write' => '新建 / 修改', 'delete' => '删除',
        'subscriptions' => '订阅', 'payments' => '付款记录', 'references' => '分类 / 成员 / 支付方式 / 币种 / 订阅页面', 'budgets' => '预算',
        'audit_read' => '读取操作审计', 'loading' => '加载中…', 'saved' => '密钥已创建。', 'copied' => '已复制密钥。',
        'hint' => '删除订阅会进入保留 180 天的回收站。删除付款记录或未使用的引用数据是永久操作，需要单独授权。写入要求版本号和请求编号，支持预演。记录付款不会真实扣款，也不会替你取消服务商的订阅。',
        'client' => '以 Streamable HTTP 连接，并发送 Authorization: Bearer 你的密钥。调用方需要具备连接外部 MCP 服务的能力；开放此接口不会自动为其他应用添加 MCP 客户端。',
        'readme' => '接入示例和安全约定见源码仓库 docs/agent-api.md。',
    ] + $en;
}
