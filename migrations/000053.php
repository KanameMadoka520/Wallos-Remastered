<?php

$subscriptionColumns = [];
$subscriptionColumnsResult = $db->query("PRAGMA table_info(subscriptions)");
while ($subscriptionColumnsResult && ($row = $subscriptionColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $subscriptionColumns[] = $row['name'];
}

if (!in_array('sort_order', $subscriptionColumns, true)) {
    $db->exec('ALTER TABLE subscriptions ADD COLUMN sort_order INTEGER DEFAULT 0');
}

$db->exec("
    UPDATE subscriptions
    SET sort_order = CASE
        WHEN sort_order IS NULL OR sort_order < 1 THEN id
        ELSE sort_order
    END
");
