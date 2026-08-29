<?php

$settingsColumnsResult = $db->query("PRAGMA table_info('settings')");
$settingsColumns = [];
while ($settingsColumnsResult && ($column = $settingsColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $settingsColumns[] = (string) ($column['name'] ?? '');
}

if (!in_array('screenshot_privacy_mode', $settingsColumns, true)) {
    $db->exec('ALTER TABLE settings ADD COLUMN screenshot_privacy_mode BOOLEAN DEFAULT 0');
}

$db->exec('UPDATE settings SET screenshot_privacy_mode = 0 WHERE screenshot_privacy_mode IS NULL');
