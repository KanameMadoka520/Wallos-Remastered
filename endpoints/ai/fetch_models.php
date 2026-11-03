<?php
require_once __DIR__ . '/../../includes/connect_endpoint.php';
require_once __DIR__ . '/../../includes/validate_endpoint.php';
require_once __DIR__ . '/../../includes/ai_client.php';
header('Content-Type: application/json; charset=UTF-8');
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) { echo json_encode(ai_failure($i18n, 'invalid_settings')); exit; }
$settings = ['type' => $data['type'] ?? '', 'api_key' => $data['api_key'] ?? '', 'url' => $data['ollama_host'] ?? '', 'model' => ''];
$valid = ai_validate_settings($settings, $db, $i18n, $userId, false);
if (!$valid['success']) { echo json_encode($valid); exit; }
$type = $settings['type'];
$key = $settings['api_key'];
$headers = ['Accept: application/json'];
$url = match ($type) {
    'chatgpt' => 'https://api.openai.com/v1/models',
    'gemini' => 'https://generativelanguage.googleapis.com/v1beta/models',
    'openrouter' => 'https://openrouter.ai/api/v1/models',
    'ollama' => rtrim($settings['url'], '/') . '/api/tags',
    default => rtrim($settings['url'], '/') . '/models',
};
if ($type === 'gemini') $headers[] = 'x-goog-api-key: ' . $key;
elseif ($type !== 'ollama' && $key !== '') $headers[] = 'Authorization: Bearer ' . $key;
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$response = ai_http_request($url, $headers, null, $db, $i18n, $userId, $type, 60);
if (!$response['success']) { echo json_encode($response); exit; }
$body = json_decode($response['body'], true, 128);
$rows = $type === 'gemini' ? ($body['models'] ?? null) : ($body['data'] ?? $body['models'] ?? null);
if (!is_array($rows) || !array_is_list($rows) || count($rows) > 10000 || isset($body['error'])) { echo json_encode(ai_failure($i18n, 'invalid_response')); exit; }
$models = [];
foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $id = $row[$type === 'gemini' ? 'name' : 'id'] ?? $row['name'] ?? '';
    if (!is_string($id)) continue;
    if ($type === 'gemini') $id = preg_replace('~^models/~', '', $id);
    $name = $row['displayName'] ?? $row['name'] ?? $id;
    if (!is_string($id) || $id === '' || strlen($id) > 512 || preg_match('/[\x00-\x1f\x7f]/', $id) || !mb_check_encoding($id, 'UTF-8') || !is_string($name) || strlen($name) > 1024 || !mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $name)) continue;
    $models[$id] = ['id' => $id, 'name' => $name];
}
echo json_encode(['success' => true, 'models' => array_values($models)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
