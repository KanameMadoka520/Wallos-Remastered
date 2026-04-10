<?php

$tableCheck = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='login_attempts'");
if (!$tableCheck) {
    $db->exec("
        CREATE TABLE login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT DEFAULT '',
            username TEXT DEFAULT '',
            success INTEGER DEFAULT 0,
            attempted_at TEXT DEFAULT CURRENT_TIMESTAMP,
            blocked_until TEXT DEFAULT ''
        )
    ");
}

$db->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_attempted ON login_attempts(ip_address, attempted_at)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_username_attempted ON login_attempts(username, attempted_at)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_blocked_until ON login_attempts(blocked_until)');
