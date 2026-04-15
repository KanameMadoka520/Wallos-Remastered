<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/security_rate_limit_presets.php';

$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);
if (!is_array($data)) {
    $data = [];
}

$action = trim((string) ($data['action'] ?? ''));
$presetId = (int) ($data['preset_id'] ?? 0);
$presetName = trim((string) ($data['name'] ?? ''));
$presetConfig = is_array($data['config'] ?? null) ? $data['config'] : [];

function rate_limit_preset_response($success, $message, array $extra = [])
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
    exit;
}

if ($action === 'create') {
    if ($presetName === '') {
        rate_limit_preset_response(false, translate('error', $i18n));
    }

    $normalizedConfig = wallos_normalize_rate_limit_preset_config($presetConfig);
    $stmt = $db->prepare('INSERT INTO rate_limit_presets (name, config_json, updated_at) VALUES (:name, :config_json, CURRENT_TIMESTAMP)');
    $stmt->bindValue(':name', $presetName, SQLITE3_TEXT);
    $stmt->bindValue(':config_json', wallos_encode_rate_limit_preset_config($normalizedConfig), SQLITE3_TEXT);
    $result = @$stmt->execute();

    if ($result) {
        rate_limit_preset_response(true, translate('rate_limit_preset_created', $i18n));
    }

    rate_limit_preset_response(false, translate('error', $i18n));
}

if ($action === 'update') {
    if ($presetId <= 0) {
        rate_limit_preset_response(false, translate('error', $i18n));
    }

    $normalizedConfig = wallos_normalize_rate_limit_preset_config($presetConfig);
    $stmt = $db->prepare('UPDATE rate_limit_presets SET config_json = :config_json, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $stmt->bindValue(':config_json', wallos_encode_rate_limit_preset_config($normalizedConfig), SQLITE3_TEXT);
    $stmt->bindValue(':id', $presetId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($result) {
        rate_limit_preset_response(true, translate('rate_limit_preset_saved', $i18n));
    }

    rate_limit_preset_response(false, translate('error', $i18n));
}

if ($action === 'delete') {
    if ($presetId <= 0) {
        rate_limit_preset_response(false, translate('error', $i18n));
    }

    $stmt = $db->prepare('DELETE FROM rate_limit_presets WHERE id = :id');
    $stmt->bindValue(':id', $presetId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($result) {
        rate_limit_preset_response(true, translate('rate_limit_preset_deleted', $i18n));
    }

    rate_limit_preset_response(false, translate('error', $i18n));
}

rate_limit_preset_response(false, translate('error', $i18n));
