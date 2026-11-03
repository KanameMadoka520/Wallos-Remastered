<?php
require_once __DIR__ . '/../../includes/connect_endpoint.php';
require_once __DIR__ . '/../../includes/validate_endpoint.php';
require_once __DIR__ . '/../../includes/ai_client.php';
header('Content-Type: application/json; charset=UTF-8');
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) { echo json_encode(ai_failure($i18n, 'invalid_settings')); exit; }
foreach (['ai_type', 'api_key', 'ollama_host', 'model', 'ai_run_schedule'] as $field) {
    if (isset($data[$field]) && !is_string($data[$field])) { echo json_encode(ai_failure($i18n, 'invalid_settings')); exit; }
}
if (isset($data['ai_enabled']) && !is_bool($data['ai_enabled']) && !in_array($data['ai_enabled'], [0, 1, '0', '1'], true)) { echo json_encode(ai_failure($i18n, 'invalid_settings')); exit; }
$settings = [
    'user_id' => $userId, 'type' => trim($data['ai_type'] ?? ''), 'api_key' => trim($data['api_key'] ?? ''),
    'url' => trim($data['ollama_host'] ?? ''), 'model' => trim($data['model'] ?? ''),
    'enabled' => !empty($data['ai_enabled']), 'run_schedule' => $data['ai_run_schedule'] ?? 'manual',
];
if (!in_array($settings['run_schedule'], ['manual', 'weekly', 'monthly'], true)) { echo json_encode(ai_failure($i18n, 'invalid_settings')); exit; }
$valid = ai_validate_settings($settings, $db, $i18n, $userId);
if (!$valid['success']) { echo json_encode($valid); exit; }
if ($settings['type'] === 'ollama') $settings['api_key'] = '';
if (!in_array($settings['type'], ['ollama', 'openai-compatible'], true)) $settings['url'] = '';
$db->enableExceptions(true);
$began = false;
try {
    $db->exec('BEGIN IMMEDIATE');
    $began = true;
    // Historical installations may contain duplicates: replace this account's rows atomically.
    $stmt = $db->prepare('DELETE FROM ai_settings WHERE user_id=:uid');
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    $stmt = $db->prepare('INSERT INTO ai_settings(user_id,type,enabled,api_key,model,url,run_schedule) VALUES(:user_id,:type,:enabled,:api_key,:model,:url,:run_schedule)');
    foreach ($settings as $key => $value) $stmt->bindValue(':' . $key, $value, in_array($key, ['user_id', 'enabled'], true) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    $stmt->execute();
    $db->exec('COMMIT');
    echo json_encode(['success' => true, 'message' => translate('success', $i18n), 'enabled' => $settings['enabled']]);
} catch (Throwable $error) {
    if ($began) $db->exec('ROLLBACK');
    ai_log_failure($settings['type'], $userId, 'settings_save_failed');
    echo json_encode(ai_failure($i18n, 'invalid_settings'));
}
