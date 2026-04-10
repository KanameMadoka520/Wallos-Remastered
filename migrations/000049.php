<?php

$adminColumns = [];
$adminColumnsResult = $db->query("PRAGMA table_info(admin)");
while ($adminColumnsResult && ($row = $adminColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $adminColumns[] = $row['name'];
}

if (!in_array('login_rate_limit_max_attempts', $adminColumns, true)) {
    $db->exec('ALTER TABLE admin ADD COLUMN login_rate_limit_max_attempts INTEGER DEFAULT 8');
}

$db->exec('UPDATE admin SET login_rate_limit_max_attempts = 8 WHERE login_rate_limit_max_attempts IS NULL OR login_rate_limit_max_attempts < 1');
