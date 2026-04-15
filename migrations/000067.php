<?php

require_once __DIR__ . '/../includes/security_rate_limit_presets.php';

$db->exec('
    CREATE TABLE IF NOT EXISTS rate_limit_presets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        config_json TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )
');

$adminRow = $db->querySingle('SELECT * FROM admin WHERE id = 1', true);
if (!is_array($adminRow)) {
    $adminRow = [];
}

$seedPresets = [
    WALLOS_RATE_LIMIT_TEST_PRESET_NAME => wallos_build_rate_limit_test_preset_config(),
    WALLOS_RATE_LIMIT_NORMAL_PRESET_NAME => wallos_build_rate_limit_preset_config_from_admin_row($adminRow),
    WALLOS_RATE_LIMIT_RECOMMENDED_PRESET_NAME => wallos_build_rate_limit_recommended_preset_config(),
];

foreach ($seedPresets as $presetName => $presetConfig) {
    $stmt = $db->prepare('INSERT OR IGNORE INTO rate_limit_presets (name, config_json) VALUES (:name, :config_json)');
    $stmt->bindValue(':name', $presetName, SQLITE3_TEXT);
    $stmt->bindValue(':config_json', wallos_encode_rate_limit_preset_config($presetConfig), SQLITE3_TEXT);
    $stmt->execute();
}
