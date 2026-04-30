<?php

$db->exec('
    CREATE TABLE IF NOT EXISTS maintenance_action_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_user_id INTEGER DEFAULT 0,
        action TEXT NOT NULL,
        success INTEGER DEFAULT 1,
        duration_ms INTEGER DEFAULT 0,
        summary TEXT DEFAULT "",
        created_at TEXT NOT NULL
    )
');

$db->exec('CREATE INDEX IF NOT EXISTS idx_maintenance_action_logs_created_id ON maintenance_action_logs(created_at, id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_maintenance_action_logs_action_created ON maintenance_action_logs(action, created_at)');

?>
