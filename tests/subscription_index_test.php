<?php

// The migration is deliberately additive and safe to run repeatedly.
$db = new SQLite3(':memory:');
$db->exec('CREATE TABLE subscriptions (id INTEGER PRIMARY KEY, user_id INTEGER, inactive INTEGER, next_payment TEXT, notify INTEGER)');
$db->exec('CREATE TABLE migrations (migration TEXT)');
require __DIR__ . '/../migrations/000078.php';

$indexes = [];
$result = $db->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'subscriptions'");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $indexes[] = $row['name'];
}

foreach (['idx_subscriptions_user_inactive_next_payment', 'idx_subscriptions_user_notify_inactive'] as $index) {
    if (!in_array($index, $indexes, true)) {
        throw new RuntimeException('Missing subscription index: ' . $index);
    }
}

require __DIR__ . '/../migrations/000078.php';
$db->close();
echo "Subscription index migration test passed.\n";

?>
