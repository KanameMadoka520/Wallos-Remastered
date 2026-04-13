<?php

$subscriptionColumns = [];
$subscriptionColumnsResult = $db->query("PRAGMA table_info(subscriptions)");
while ($subscriptionColumnsResult && ($row = $subscriptionColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $subscriptionColumns[] = $row['name'];
}

if (!in_array('manual_cycle_used_value_main', $subscriptionColumns, true)) {
    $db->exec('ALTER TABLE subscriptions ADD COLUMN manual_cycle_used_value_main REAL DEFAULT 0');
}

if (!in_array('manual_cycle_used_value_cycle_start', $subscriptionColumns, true)) {
    $db->exec("ALTER TABLE subscriptions ADD COLUMN manual_cycle_used_value_cycle_start TEXT DEFAULT ''");
}

$db->exec('UPDATE subscriptions SET manual_cycle_used_value_main = 0 WHERE manual_cycle_used_value_main IS NULL');
$db->exec("UPDATE subscriptions SET manual_cycle_used_value_cycle_start = '' WHERE manual_cycle_used_value_cycle_start IS NULL");
