<?php

$subscriptionColumnsResult = $db->query("PRAGMA table_info('subscriptions')");
$subscriptionColumns = [];
while ($subscriptionColumnsResult && ($column = $subscriptionColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $subscriptionColumns[] = $column['name'];
}

if (!in_array('subscription_page_id', $subscriptionColumns, true)) {
    $db->exec('ALTER TABLE subscriptions ADD COLUMN subscription_page_id INTEGER');
}

$tableExists = (bool) $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='subscription_pages'");
if (!$tableExists) {
    $db->exec('
        CREATE TABLE subscription_pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(user_id) REFERENCES user(id) ON DELETE CASCADE
        )
    ');
}

$db->exec('CREATE INDEX IF NOT EXISTS idx_subscription_pages_user_sort ON subscription_pages(user_id, sort_order, id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_subscriptions_user_page_status ON subscriptions(user_id, subscription_page_id, lifecycle_status)');
