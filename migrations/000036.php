<?php

// This migration adds a new column to the webhook_notifications table to store the cancelation payload.
// The legacy iterator column is intentionally retained so custom data is not discarded.
// The cancelation payload will be used to send cancelation notifications to the webhook

$columnQuery = $db->query("SELECT * FROM pragma_table_info('webhook_notifications') where name='cancelation_payload'");
$columnRequired = $columnQuery->fetchArray(SQLITE3_ASSOC) === false;

if ($columnRequired) {
    $db->exec("ALTER TABLE webhook_notifications ADD COLUMN cancelation_payload TEXT DEFAULT ''");
}
