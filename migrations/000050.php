<?php

$adminColumns = [];
$adminColumnsResult = $db->query("PRAGMA table_info(admin)");
while ($adminColumnsResult && ($row = $adminColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $adminColumns[] = $row['name'];
}

if (!in_array('backup_retention_days', $adminColumns, true)) {
    $db->exec('ALTER TABLE admin ADD COLUMN backup_retention_days INTEGER DEFAULT 14');
}

$db->exec('UPDATE admin SET backup_retention_days = 14 WHERE backup_retention_days IS NULL OR backup_retention_days < 1');
