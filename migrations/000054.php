<?php

$subscriptionColumns = [];
$subscriptionColumnsResult = $db->query("PRAGMA table_info(subscriptions)");
while ($subscriptionColumnsResult && ($row = $subscriptionColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $subscriptionColumns[] = $row['name'];
}

if (!in_array('lifecycle_status', $subscriptionColumns, true)) {
    $db->exec("ALTER TABLE subscriptions ADD COLUMN lifecycle_status TEXT DEFAULT 'active'");
}

if (!in_array('trashed_at', $subscriptionColumns, true)) {
    $db->exec("ALTER TABLE subscriptions ADD COLUMN trashed_at TEXT DEFAULT ''");
}

if (!in_array('scheduled_delete_at', $subscriptionColumns, true)) {
    $db->exec("ALTER TABLE subscriptions ADD COLUMN scheduled_delete_at TEXT DEFAULT ''");
}

if (!in_array('exclude_from_stats', $subscriptionColumns, true)) {
    $db->exec('ALTER TABLE subscriptions ADD COLUMN exclude_from_stats BOOLEAN DEFAULT 0');
}

$db->exec("UPDATE subscriptions SET lifecycle_status = 'active' WHERE lifecycle_status IS NULL OR TRIM(lifecycle_status) = ''");
$db->exec("UPDATE subscriptions SET trashed_at = '' WHERE trashed_at IS NULL");
$db->exec("UPDATE subscriptions SET scheduled_delete_at = '' WHERE scheduled_delete_at IS NULL");
$db->exec("UPDATE subscriptions SET exclude_from_stats = 0 WHERE exclude_from_stats IS NULL");
