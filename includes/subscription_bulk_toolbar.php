<?php
// Screenshot mode must not expose real reference options or allow a preview to
// reveal the real names behind masked subscription cards.
if (function_exists('wallos_screenshot_privacy_enabled') && wallos_screenshot_privacy_enabled($settings ?? [])) {
    echo '<p class="subscription-bulk-hint">' . htmlspecialchars(translate('screenshot_privacy_mode_blocked', $i18n), ENT_QUOTES, 'UTF-8') . '</p>';
    return;
}
// English fallback for every supported locale, Chinese wording for zh locales.
$bulkZh = str_starts_with(strtolower((string) ($lang ?? 'en')), 'zh');
$bulkStrings = $bulkZh ? [
    'title' => '批量管理', 'selectPage' => '选择当前页', 'clear' => '清空选择',
    'hint' => '只操作明确选中的订阅，最多 50 条；翻页或筛选后清空选择。',
    'selected' => '已选 {count} 条', 'select' => '选择此订阅', 'choose' => '选择操作',
    'notifyOn' => '开启提醒', 'notifyOff' => '关闭提醒', 'days' => '修改提前提醒天数',
    'categories' => '替换全部分类', 'payment' => '修改付款方式', 'trash' => '移入回收站',
    'preview' => '预览修改', 'apply' => '确认执行', 'cancel' => '关闭预览',
    'previewTitle' => '以下订阅将一起修改', 'previewHint' => '预览未保存。执行时会再次检查版本；任意一条发生冲突，整批不提交。',
    'done' => '批量操作已完成', 'empty' => '请先选择订阅和操作。',
    'limit' => '一次最多选择 50 条订阅。', 'valueRequired' => '请选择有效的操作值。',
    'failed' => '操作失败，请重新预览。网络异常时可按原请求重试。',
    'retry' => '重试原请求', 'retryHint' => '响应未确认，请重试原请求以核对结果；不要重新创建操作。',
] : [
    'title' => 'Bulk management', 'selectPage' => 'Select current page', 'clear' => 'Clear selection',
    'hint' => 'Only explicitly selected subscriptions are changed, up to 50. Changing page or filters clears the selection.',
    'selected' => '{count} selected', 'select' => 'Select this subscription', 'choose' => 'Choose an action',
    'notifyOn' => 'Enable notifications', 'notifyOff' => 'Disable notifications', 'days' => 'Change reminder days',
    'categories' => 'Replace all categories', 'payment' => 'Change payment method', 'trash' => 'Move to recycle bin',
    'preview' => 'Preview changes', 'apply' => 'Apply changes', 'cancel' => 'Close preview',
    'previewTitle' => 'These subscriptions will be changed together', 'previewHint' => 'Preview is not saved. Versions are checked again when applying; any conflict rolls back the entire batch.',
    'done' => 'Bulk operation completed', 'empty' => 'Select subscriptions and an action first.',
    'limit' => 'Select at most 50 subscriptions per batch.', 'valueRequired' => 'Choose a valid action value.',
    'failed' => 'Operation failed. Preview again. For a network failure retry the original request.',
    'retry' => 'Retry original request', 'retryHint' => 'The response is unconfirmed. Retry the original request to verify its result; do not create a new operation.',
];
$bulkCategories = wallos_agent_rows_for_bulk_toolbar($db, (int) $userId, 'categories');
$bulkMethods = wallos_agent_rows_for_bulk_toolbar($db, (int) $userId, 'payment_methods');
function wallos_agent_rows_for_bulk_toolbar($db, $uid, $table)
{
    // Table is a fixed internal choice, never input from a request.
    $stmt = $db->prepare("SELECT id,name FROM $table WHERE user_id=:uid ORDER BY name,id");
    $stmt->bindValue(':uid', $uid, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}
$bulkEscape = static fn($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
?>
<style>
.subscription-bulk-toolbar{margin:0 0 16px;padding:12px 16px;border:1px solid var(--border-color,#8885);border-radius:12px}
.subscription-bulk-controls{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-top:10px}
.subscription-bulk-toolbar select{max-width:100%;min-width:0}
.subscription-bulk-toolbar select[multiple]{min-height:80px}
.subscription-bulk-hint{font-size:.85rem;opacity:.8;margin:8px 0}
.subscription-bulk-selection{display:none;align-items:center;justify-content:center;gap:8px;padding:6px 10px;cursor:pointer}
.subscription-bulk-mode .subscription-bulk-selection{display:flex}
.subscription-bulk-selection input{appearance:auto!important;position:static!important;opacity:1!important;width:20px!important;height:20px!important;accent-color:var(--accent-color,#7452ce)}
.subscription-bulk-selected{outline:2px solid var(--accent-color,#7452ce);outline-offset:2px}
.subscription-bulk-preview{border-top:1px solid #8885;margin-top:12px;padding-top:12px}
.subscription-bulk-preview ul{max-height:220px;overflow:auto;padding-inline-start:24px}
.subscription-bulk-toolbar [hidden]{display:none!important}
.subscription-bulk-error{color:var(--danger-color,#bf3636);overflow-wrap:anywhere}
</style>
<details id="subscription-bulk-toolbar" class="subscription-bulk-toolbar" data-page-ui-hide-target>
  <summary><?= $bulkEscape($bulkStrings['title']) ?></summary>
  <p class="subscription-bulk-hint"><?= $bulkEscape($bulkStrings['hint']) ?></p>
  <div class="subscription-bulk-controls">
    <button type="button" id="subscription-bulk-select-page" class="secondary-button thin"><?= $bulkEscape($bulkStrings['selectPage']) ?></button>
    <button type="button" id="subscription-bulk-clear" class="secondary-button thin"><?= $bulkEscape($bulkStrings['clear']) ?></button>
    <span id="subscription-bulk-count" aria-live="polite"></span>
    <select id="subscription-bulk-action" aria-label="<?= $bulkEscape($bulkStrings['choose']) ?>">
      <option value=""><?= $bulkEscape($bulkStrings['choose']) ?></option>
      <?php foreach (['notifyOn','notifyOff','days','categories','payment','trash'] as $bulkAction): ?>
        <option value="<?= $bulkAction ?>"><?= $bulkEscape($bulkStrings[$bulkAction]) ?></option>
      <?php endforeach; ?>
    </select>
    <input id="subscription-bulk-days" type="number" min="0" max="365" step="1" value="3" hidden aria-label="<?= $bulkEscape($bulkStrings['days']) ?>">
    <select id="subscription-bulk-categories" multiple hidden aria-label="<?= $bulkEscape($bulkStrings['categories']) ?>">
      <?php foreach ($bulkCategories as $bulkReference): ?>
        <option value="<?= (int) $bulkReference['id'] ?>"><?= $bulkEscape(html_entity_decode($bulkReference['name'], ENT_QUOTES, 'UTF-8')) ?></option>
      <?php endforeach; ?>
    </select>
    <select id="subscription-bulk-payment" hidden aria-label="<?= $bulkEscape($bulkStrings['payment']) ?>">
      <?php foreach ($bulkMethods as $bulkReference): ?>
        <option value="<?= (int) $bulkReference['id'] ?>"><?= $bulkEscape(html_entity_decode($bulkReference['name'], ENT_QUOTES, 'UTF-8')) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="button" id="subscription-bulk-preview-button" class="thin"><?= $bulkEscape($bulkStrings['preview']) ?></button>
  </div>
  <p id="subscription-bulk-error" class="subscription-bulk-error" role="alert" hidden></p>
  <div id="subscription-bulk-preview" class="subscription-bulk-preview" hidden>
    <strong><?= $bulkEscape($bulkStrings['previewTitle']) ?></strong>
    <p id="subscription-bulk-preview-action"></p>
    <ul id="subscription-bulk-targets"></ul>
    <p class="subscription-bulk-hint"><?= $bulkEscape($bulkStrings['previewHint']) ?></p>
    <p id="subscription-bulk-retry-hint" class="subscription-bulk-hint" hidden><?= $bulkEscape($bulkStrings['retryHint']) ?></p>
    <button type="button" id="subscription-bulk-apply" class="thin"><?= $bulkEscape($bulkStrings['apply']) ?></button>
    <button type="button" id="subscription-bulk-cancel" class="secondary-button thin"><?= $bulkEscape($bulkStrings['cancel']) ?></button>
  </div>
</details>
<script>window.subscriptionBulkStrings = <?= json_encode($bulkStrings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;</script>
