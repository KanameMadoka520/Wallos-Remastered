<?php

// Finish the additive upstream v5.4.5 compatibility fields that use migration
// numbers already occupied by older Remastered features. Existing values are
// never rewritten: notification summaries default off, and old logos continue
// using their original file until a new themed variant is generated.
$columns = [];
$result = $db->query("PRAGMA table_info('notification_settings')");
while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
    $columns[] = (string) ($row['name'] ?? '');
}

if (!in_array('period_summary_at_period_start', $columns, true)) {
    $db->exec('ALTER TABLE notification_settings ADD COLUMN period_summary_at_period_start INTEGER DEFAULT 0');
}

$db->exec('UPDATE notification_settings
           SET period_summary_at_period_start = 0
           WHERE period_summary_at_period_start IS NULL');

$subscriptionColumns = [];
$subscriptionResult = $db->query("PRAGMA table_info('subscriptions')");
while ($subscriptionResult && ($row = $subscriptionResult->fetchArray(SQLITE3_ASSOC))) {
    $subscriptionColumns[] = (string) ($row['name'] ?? '');
}

if (!in_array('logo_text_color', $subscriptionColumns, true)) {
    $db->exec('ALTER TABLE subscriptions ADD COLUMN logo_text_color TEXT DEFAULT NULL');
}
if (!in_array('logo_variant', $subscriptionColumns, true)) {
    $db->exec('ALTER TABLE subscriptions ADD COLUMN logo_variant TEXT DEFAULT NULL');
}

?>
