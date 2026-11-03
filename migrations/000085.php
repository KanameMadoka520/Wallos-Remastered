<?php
// Additive Web Push support. Private VAPID material is never placed on the
// broadly serialized admin row; database backups retain it across restores.
$db->exec('CREATE TABLE IF NOT EXISTS push_vapid (
 id INTEGER PRIMARY KEY CHECK(id = 1), public_key TEXT NOT NULL, private_key TEXT NOT NULL
)');
$db->exec('CREATE TABLE IF NOT EXISTS push_notifications (
 user_id INTEGER PRIMARY KEY REFERENCES user(id) ON DELETE CASCADE,
 enabled INTEGER NOT NULL DEFAULT 0 CHECK(enabled IN (0,1)),
 show_details INTEGER NOT NULL DEFAULT 0 CHECK(show_details IN (0,1))
)');
$db->exec('CREATE TABLE IF NOT EXISTS push_subscriptions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL REFERENCES user(id) ON DELETE CASCADE,
 endpoint TEXT NOT NULL UNIQUE, p256dh TEXT NOT NULL, auth TEXT NOT NULL,
 device_hash TEXT NOT NULL UNIQUE, device_name TEXT NOT NULL DEFAULT \'Browser\',
 created_at INTEGER NOT NULL, last_used_at INTEGER NOT NULL DEFAULT 0,
 last_attempt_at INTEGER NOT NULL DEFAULT 0, last_status INTEGER,
 failure_count INTEGER NOT NULL DEFAULT 0
)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_push_subscriptions_user ON push_subscriptions(user_id)');
$db->exec('CREATE TABLE IF NOT EXISTS push_delivery_claims (
 device_id INTEGER NOT NULL REFERENCES push_subscriptions(id) ON DELETE CASCADE,
 event_key TEXT NOT NULL, claimed_at INTEGER NOT NULL,
 delivered_at INTEGER, attempts INTEGER NOT NULL DEFAULT 1,
 PRIMARY KEY(device_id,event_key)
)');
// Cleanup also works in legacy account removal paths that disable FK cascades.
$db->exec('CREATE TRIGGER IF NOT EXISTS push_device_cleanup AFTER DELETE ON push_subscriptions
 BEGIN DELETE FROM push_delivery_claims WHERE device_id = OLD.id; END');
