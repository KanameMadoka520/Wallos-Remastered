<?php

// Compatibility migration for the Remastered branch.  It only adds columns,
// indexes, and missing per-user API keys; existing user and subscription data
// is never replaced or deleted.

$wallos75_columns = static function ($db, $table) {
    $columns = [];
    $result = $db->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")');
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $columns[] = (string) ($row['name'] ?? '');
    }
    return $columns;
};

$wallos75_table_exists = static function ($db, $table) {
    $stmt = $db->prepare('SELECT 1 FROM sqlite_master WHERE type = :type AND name = :name LIMIT 1');
    $stmt->bindValue(':type', 'table', SQLITE3_TEXT);
    $stmt->bindValue(':name', $table, SQLITE3_TEXT);
    $result = $stmt->execute();
    return $result && $result->fetchArray(SQLITE3_NUM) !== false;
};

if ($wallos75_table_exists($db, 'user')) {
    $userColumns = $wallos75_columns($db, 'user');
    if (in_array('api_key', $userColumns, true)) {
        $result = $db->query('SELECT id FROM user WHERE api_key IS NULL OR TRIM(api_key) = ""');
        $update = $db->prepare('UPDATE user SET api_key = :api_key WHERE id = :id');
        while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $update->bindValue(':api_key', bin2hex(random_bytes(32)), SQLITE3_TEXT);
            $update->bindValue(':id', (int) $row['id'], SQLITE3_INTEGER);
            $update->execute();
            $update->reset();
        }
    }
}

if ($wallos75_table_exists($db, 'totp')) {
    $totpColumns = $wallos75_columns($db, 'totp');
    if (!in_array('failed_attempts', $totpColumns, true)) {
        $db->exec('ALTER TABLE totp ADD COLUMN failed_attempts INTEGER DEFAULT 0');
    }
    if (!in_array('lockout_until', $totpColumns, true)) {
        $db->exec('ALTER TABLE totp ADD COLUMN lockout_until INTEGER DEFAULT 0');
    }
    $db->exec('UPDATE totp SET failed_attempts = 0 WHERE failed_attempts IS NULL');
    $db->exec('UPDATE totp SET lockout_until = 0 WHERE lockout_until IS NULL');
}

if ($wallos75_table_exists($db, 'oauth_settings')) {
    $oidcColumns = $wallos75_columns($db, 'oauth_settings');
    if (!in_array('require_email_verified', $oidcColumns, true)) {
        $db->exec('ALTER TABLE oauth_settings ADD COLUMN require_email_verified INTEGER DEFAULT 1');
    }
    $db->exec('UPDATE oauth_settings SET require_email_verified = 1 WHERE require_email_verified IS NULL');
}

if ($wallos75_table_exists($db, 'subscriptions')) {
    $subscriptionColumns = $wallos75_columns($db, 'subscriptions');
    if (in_array('user_id', $subscriptionColumns, true) && in_array('inactive', $subscriptionColumns, true) && in_array('next_payment', $subscriptionColumns, true)) {
        $db->exec('CREATE INDEX IF NOT EXISTS idx_subscriptions_user_inactive_next_payment ON subscriptions(user_id, inactive, next_payment)');
    }
    if (in_array('user_id', $subscriptionColumns, true) && in_array('notify', $subscriptionColumns, true) && in_array('inactive', $subscriptionColumns, true)) {
        $db->exec('CREATE INDEX IF NOT EXISTS idx_subscriptions_user_notify_inactive ON subscriptions(user_id, notify, inactive)');
    }
}

