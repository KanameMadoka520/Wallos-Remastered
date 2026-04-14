<?php

require_once __DIR__ . '/../includes/settings_defaults.php';

$db->exec("UPDATE settings SET color_theme = 'purple' WHERE color_theme IS NULL OR TRIM(color_theme) = ''");

$missingSettingsResult = $db->query("
    SELECT user.id
    FROM user
    LEFT JOIN settings ON settings.user_id = user.id
    WHERE settings.user_id IS NULL
");

while ($missingSettingsResult && ($row = $missingSettingsResult->fetchArray(SQLITE3_ASSOC))) {
    $missingUserId = (int) ($row['id'] ?? 0);
    if ($missingUserId > 0) {
        wallos_insert_default_settings($db, $missingUserId);
    }
}
