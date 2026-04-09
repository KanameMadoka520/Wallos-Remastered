<?php

$adminColumns = [];
$adminColumnsResult = $db->query("PRAGMA table_info(admin)");
while ($adminColumnsResult && ($row = $adminColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $adminColumns[] = $row['name'];
}

if (!in_array('subscription_image_external_url_limit', $adminColumns, true)) {
    $db->exec('ALTER TABLE admin ADD COLUMN subscription_image_external_url_limit INTEGER DEFAULT 10');
}

if (!in_array('trusted_subscription_upload_limit', $adminColumns, true)) {
    $db->exec('ALTER TABLE admin ADD COLUMN trusted_subscription_upload_limit INTEGER DEFAULT 1');
}

if (!in_array('subscription_image_max_size_mb', $adminColumns, true)) {
    $db->exec('ALTER TABLE admin ADD COLUMN subscription_image_max_size_mb INTEGER DEFAULT 10');
}

if (!in_array('invite_only_registration', $adminColumns, true)) {
    $db->exec('ALTER TABLE admin ADD COLUMN invite_only_registration INTEGER DEFAULT 0');
}

$db->exec('UPDATE admin SET subscription_image_external_url_limit = 10 WHERE subscription_image_external_url_limit IS NULL OR subscription_image_external_url_limit < 1');
$db->exec('UPDATE admin SET trusted_subscription_upload_limit = 1 WHERE trusted_subscription_upload_limit IS NULL OR trusted_subscription_upload_limit < 0');
$db->exec('UPDATE admin SET subscription_image_max_size_mb = 10 WHERE subscription_image_max_size_mb IS NULL OR subscription_image_max_size_mb < 1');
$db->exec('UPDATE admin SET invite_only_registration = 0 WHERE invite_only_registration IS NULL');

$userColumns = [];
$userColumnsResult = $db->query("PRAGMA table_info(user)");
while ($userColumnsResult && ($row = $userColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $userColumns[] = $row['name'];
}

if (!in_array('account_status', $userColumns, true)) {
    $db->exec('ALTER TABLE user ADD COLUMN account_status TEXT DEFAULT "active"');
}

if (!in_array('trash_reason', $userColumns, true)) {
    $db->exec('ALTER TABLE user ADD COLUMN trash_reason TEXT DEFAULT ""');
}

if (!in_array('trashed_at', $userColumns, true)) {
    $db->exec('ALTER TABLE user ADD COLUMN trashed_at TEXT DEFAULT ""');
}

if (!in_array('scheduled_delete_at', $userColumns, true)) {
    $db->exec('ALTER TABLE user ADD COLUMN scheduled_delete_at TEXT DEFAULT ""');
}

$db->exec("UPDATE user SET account_status = 'active' WHERE account_status IS NULL OR TRIM(account_status) = '' OR account_status NOT IN ('active', 'trashed')");
$db->exec("UPDATE user SET trash_reason = '' WHERE trash_reason IS NULL");
$db->exec("UPDATE user SET trashed_at = '' WHERE trashed_at IS NULL");
$db->exec("UPDATE user SET scheduled_delete_at = '' WHERE scheduled_delete_at IS NULL");

$tableCheck = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='subscription_uploaded_images'");
if (!$tableCheck) {
    $db->exec("
        CREATE TABLE subscription_uploaded_images (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            subscription_id INTEGER NOT NULL,
            path TEXT NOT NULL,
            file_name TEXT NOT NULL,
            original_name TEXT DEFAULT '',
            mime_type TEXT DEFAULT '',
            file_size INTEGER DEFAULT 0,
            width INTEGER DEFAULT 0,
            height INTEGER DEFAULT 0,
            compressed INTEGER DEFAULT 1,
            upload_sequence INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ");
}

$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_subscription_uploaded_images_path ON subscription_uploaded_images(path)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_subscription_uploaded_images_subscription ON subscription_uploaded_images(subscription_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_subscription_uploaded_images_user ON subscription_uploaded_images(user_id)');

$tableCheck = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='invite_codes'");
if (!$tableCheck) {
    $db->exec("
        CREATE TABLE invite_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL,
            max_uses INTEGER DEFAULT 1,
            uses_count INTEGER DEFAULT 0,
            created_by INTEGER DEFAULT 1,
            deleted INTEGER DEFAULT 0,
            deleted_at TEXT DEFAULT '',
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ");
}

$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_invite_codes_code ON invite_codes(code)');

$tableCheck = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='invite_code_usages'");
if (!$tableCheck) {
    $db->exec("
        CREATE TABLE invite_code_usages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invite_code_id INTEGER NOT NULL,
            used_by_user_id INTEGER DEFAULT 0,
            used_by_username TEXT DEFAULT '',
            used_by_email TEXT DEFAULT '',
            used_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ");
}

$db->exec('CREATE INDEX IF NOT EXISTS idx_invite_code_usages_invite ON invite_code_usages(invite_code_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_invite_code_usages_user ON invite_code_usages(used_by_user_id)');

$tableCheck = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='request_logs'");
if (!$tableCheck) {
    $db->exec("
        CREATE TABLE request_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER DEFAULT 0,
            username TEXT DEFAULT '',
            path TEXT NOT NULL,
            method TEXT NOT NULL,
            ip_address TEXT DEFAULT '',
            forwarded_for TEXT DEFAULT '',
            user_agent TEXT DEFAULT '',
            headers_json TEXT DEFAULT '',
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ");
}

$db->exec('CREATE INDEX IF NOT EXISTS idx_request_logs_created_at ON request_logs(created_at)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_request_logs_user_id ON request_logs(user_id)');

$subscriptionColumns = [];
$subscriptionColumnsResult = $db->query("PRAGMA table_info(subscriptions)");
while ($subscriptionColumnsResult && ($row = $subscriptionColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $subscriptionColumns[] = $row['name'];
}

if (in_array('detail_image', $subscriptionColumns, true)) {
    $legacyImages = $db->query("SELECT id, user_id, detail_image FROM subscriptions WHERE detail_image IS NOT NULL AND TRIM(detail_image) != ''");
    $sequenceByUser = [];

    while ($legacyImages && ($legacyImage = $legacyImages->fetchArray(SQLITE3_ASSOC))) {
        $path = $legacyImage['detail_image'];
        $existsStmt = $db->prepare('SELECT COUNT(*) AS count FROM subscription_uploaded_images WHERE path = :path');
        $existsStmt->bindValue(':path', $path, SQLITE3_TEXT);
        $existsResult = $existsStmt->execute();
        $existsRow = $existsResult ? $existsResult->fetchArray(SQLITE3_ASSOC) : false;
        if ((int) ($existsRow['count'] ?? 0) > 0) {
            continue;
        }

        $userId = (int) $legacyImage['user_id'];
        if (!isset($sequenceByUser[$userId])) {
            $countStmt = $db->prepare('SELECT COUNT(*) AS count FROM subscription_uploaded_images WHERE user_id = :user_id');
            $countStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $countResult = $countStmt->execute();
            $countRow = $countResult ? $countResult->fetchArray(SQLITE3_ASSOC) : false;
            $sequenceByUser[$userId] = (int) ($countRow['count'] ?? 0);
        }

        $sequenceByUser[$userId]++;
        $fileName = basename($path);

        $insertStmt = $db->prepare('
            INSERT INTO subscription_uploaded_images (
                user_id, subscription_id, path, file_name, original_name, upload_sequence, compressed
            ) VALUES (
                :user_id, :subscription_id, :path, :file_name, :original_name, :upload_sequence, 1
            )
        ');
        $insertStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $insertStmt->bindValue(':subscription_id', (int) $legacyImage['id'], SQLITE3_INTEGER);
        $insertStmt->bindValue(':path', $path, SQLITE3_TEXT);
        $insertStmt->bindValue(':file_name', $fileName, SQLITE3_TEXT);
        $insertStmt->bindValue(':original_name', $fileName, SQLITE3_TEXT);
        $insertStmt->bindValue(':upload_sequence', $sequenceByUser[$userId], SQLITE3_INTEGER);
        $insertStmt->execute();
    }
}
