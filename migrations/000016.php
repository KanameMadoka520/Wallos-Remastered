<?php

/* 
* This migration adds tables to store the date about the new notification methods (telegram, webhooks and gotify)
* Existing values on the notifications table will be split and migrated to the new tables.
*/

$db->exec('CREATE TABLE IF NOT EXISTS telegram_notifications (
    enabled BOOLEAN DEFAULT 0,
    bot_token TEXT DEFAULT "",
    chat_id TEXT DEFAULT ""
)');

$db->exec('CREATE TABLE IF NOT EXISTS webhook_notifications (
    enabled BOOLEAN DEFAULT 0,
    headers TEXT DEFAULT "",
    url TEXT DEFAULT "",
    request_method TEXT DEFAULT "POST",
    payload TEXT DEFAULT "",
    iterator TEXT DEFAULT ""
)');

$db->exec('CREATE TABLE IF NOT EXISTS gotify_notifications (
    enabled BOOLEAN DEFAULT 0,
    url TEXT DEFAULT "",
    token TEXT DEFAULT ""
)');

$db->exec('CREATE TABLE IF NOT EXISTS email_notifications (
    enabled BOOLEAN DEFAULT 0,
    smtp_address TEXT DEFAULT "",
    smtp_port INTEGER DEFAULT 587,
    smtp_username TEXT DEFAULT "",
    smtp_password TEXT DEFAULT "",
    from_email TEXT DEFAULT "",
    encryption TEXT DEFAULT "tls"
)');

$db->exec('CREATE TABLE IF NOT EXISTS notification_settings (
    days INTEGER DEFAULT 0
)');

// Preserve the legacy table as an archive. Existing target rows make the
// state ambiguous, so stop instead of guessing which settings should win.
$legacyTableExists = $db->querySingle(
    "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'notifications' LIMIT 1"
) !== null;
$archiveTableExists = $db->querySingle(
    "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'notifications_legacy_000016' LIMIT 1"
) !== null;

if ($legacyTableExists) {
    if ($archiveTableExists) {
        throw new RuntimeException('Both notifications and notifications_legacy_000016 exist; refusing an ambiguous migration.');
    }

    $legacyCount = (int) $db->querySingle('SELECT COUNT(*) FROM notifications');
    $emailCount = (int) $db->querySingle('SELECT COUNT(*) FROM email_notifications');
    $settingsCount = (int) $db->querySingle('SELECT COUNT(*) FROM notification_settings');

    if ($legacyCount > 0 && ($emailCount > 0 || $settingsCount > 0)) {
        throw new RuntimeException('Notification targets already contain data; refusing to overwrite or duplicate settings.');
    }

    if ($legacyCount > 0) {
        $db->exec('INSERT INTO email_notifications (enabled, smtp_address, smtp_port, smtp_username, smtp_password, from_email, encryption)
                   SELECT enabled, smtp_address, smtp_port, smtp_username, smtp_password, from_email, encryption FROM notifications');
        $db->exec('INSERT INTO notification_settings (days) SELECT days FROM notifications');

        if (
            (int) $db->querySingle('SELECT COUNT(*) FROM email_notifications') !== $legacyCount
            || (int) $db->querySingle('SELECT COUNT(*) FROM notification_settings') !== $legacyCount
        ) {
            throw new RuntimeException('Notification settings copy verification failed.');
        }
    }

    $db->exec('ALTER TABLE notifications RENAME TO notifications_legacy_000016');
}
?>
