<?php

require_once __DIR__ . '/../includes/timezone_settings.php';

$settingsColumnsResult = $db->query("PRAGMA table_info('settings')");
$settingsColumns = [];
while ($settingsColumnsResult && ($column = $settingsColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $settingsColumns[] = $column['name'];
}

if (!in_array('user_timezone', $settingsColumns, true)) {
    $db->exec("ALTER TABLE settings ADD COLUMN user_timezone TEXT DEFAULT '" . wallos_get_default_user_timezone() . "'");
}

$adminColumnsResult = $db->query("PRAGMA table_info('admin')");
$adminColumns = [];
while ($adminColumnsResult && ($column = $adminColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $adminColumns[] = $column['name'];
}

if (!in_array('backup_timezone', $adminColumns, true)) {
    $db->exec("ALTER TABLE admin ADD COLUMN backup_timezone TEXT DEFAULT '" . wallos_get_default_backup_timezone() . "'");
}

$db->exec("UPDATE settings SET user_timezone = '" . wallos_get_default_user_timezone() . "' WHERE user_timezone IS NULL OR TRIM(user_timezone) = ''");
$db->exec("UPDATE admin SET backup_timezone = '" . wallos_get_default_backup_timezone() . "' WHERE backup_timezone IS NULL OR TRIM(backup_timezone) = ''");
