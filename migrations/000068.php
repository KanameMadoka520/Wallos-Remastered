<?php

require_once __DIR__ . '/../includes/security_rate_limit_presets.php';

$adminColumnsResult = $db->query("PRAGMA table_info('admin')");
$adminColumns = [];
while ($adminColumnsResult && ($column = $adminColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $adminColumns[] = $column['name'];
}

if (!in_array('login_rate_limit_block_minutes', $adminColumns, true)) {
    $db->exec('ALTER TABLE admin ADD COLUMN login_rate_limit_block_minutes INTEGER DEFAULT 15');
}

$db->exec('UPDATE admin SET login_rate_limit_block_minutes = 15 WHERE login_rate_limit_block_minutes IS NULL OR login_rate_limit_block_minutes < 1');

$tableExists = (bool) $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='rate_limit_presets'");
if ($tableExists) {
    $seedPresets = [
        WALLOS_RATE_LIMIT_TEST_PRESET_NAME => wallos_build_rate_limit_test_preset_config(),
        WALLOS_RATE_LIMIT_RECOMMENDED_PRESET_NAME => wallos_build_rate_limit_recommended_preset_config(),
    ];

    foreach ($seedPresets as $presetName => $presetConfig) {
        $stmt = $db->prepare('INSERT OR IGNORE INTO rate_limit_presets (name, config_json) VALUES (:name, :config_json)');
        $stmt->bindValue(':name', $presetName, SQLITE3_TEXT);
        $stmt->bindValue(':config_json', wallos_encode_rate_limit_preset_config($presetConfig), SQLITE3_TEXT);
        $stmt->execute();
    }

    $presetsResult = $db->query('SELECT id, config_json FROM rate_limit_presets');
    while ($presetsResult && ($row = $presetsResult->fetchArray(SQLITE3_ASSOC))) {
        $presetId = (int) ($row['id'] ?? 0);
        if ($presetId <= 0) {
            continue;
        }

        $normalizedConfig = wallos_decode_rate_limit_preset_config($row['config_json'] ?? '{}');
        $updateStmt = $db->prepare('UPDATE rate_limit_presets SET config_json = :config_json, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $updateStmt->bindValue(':config_json', wallos_encode_rate_limit_preset_config($normalizedConfig), SQLITE3_TEXT);
        $updateStmt->bindValue(':id', $presetId, SQLITE3_INTEGER);
        $updateStmt->execute();
    }
}
