<?php

header('Content-Type: text/plain; charset=UTF-8');
require_once __DIR__ . '/includes/database_runtime_lock.php';

$readyFile = getenv('WALLOS_READY_FILE') ?: '/run/wallos/ready';
$databaseFile = __DIR__ . '/db/wallos.db';
$db = null;

try {
    if (!is_file($readyFile) || !is_file($databaseFile) || filesize($databaseFile) <= 0) {
        throw new RuntimeException('not ready');
    }

    wallos_database_acquire_shared_runtime_lock($databaseFile, 500);
    $db = new SQLite3($databaseFile, SQLITE3_OPEN_READONLY);
    $db->enableExceptions(true);
    $db->busyTimeout(2000);

    if ($db->querySingle('PRAGMA quick_check(1)') !== 'ok') {
        throw new RuntimeException('database check failed');
    }

    $requiredTables = [
        'admin',
        'user',
        'subscriptions',
        'currencies',
        'settings',
        'notification_settings',
        'cycles',
        'migrations',
    ];
    foreach ($requiredTables as $table) {
        $stmt = $db->prepare('SELECT 1 FROM sqlite_master WHERE type = :type AND name = :name LIMIT 1');
        $stmt->bindValue(':type', 'table', SQLITE3_TEXT);
        $stmt->bindValue(':name', $table, SQLITE3_TEXT);
        $result = $stmt->execute();
        if ($result->fetchArray(SQLITE3_NUM) === false) {
            throw new RuntimeException('required table missing');
        }
    }

    $completedLatestMigration = false;
    $migrationResult = $db->query('SELECT migration FROM migrations');
    while ($row = $migrationResult->fetchArray(SQLITE3_ASSOC)) {
        $migration = str_replace('\\', '/', trim((string) ($row['migration'] ?? '')));
        if (preg_match('#(?:^|/)' . preg_quote('migrations/000081.php', '#') . '$#', $migration) === 1) {
            $completedLatestMigration = true;
            break;
        }
    }
    if (!$completedLatestMigration) {
        throw new RuntimeException('latest migration missing');
    }

    $requiredUserColumns = [
        'period_budget',
        'budget_period_type',
        'budget_period_anchor_date',
    ];
    $userColumns = [];
    $columnResult = $db->query("PRAGMA table_info('user')");
    while ($row = $columnResult->fetchArray(SQLITE3_ASSOC)) {
        $userColumns[(string) ($row['name'] ?? '')] = true;
    }
    foreach ($requiredUserColumns as $column) {
        if (!isset($userColumns[$column])) {
            throw new RuntimeException('required column missing');
        }
    }

    $settingsColumns = [];
    $columnResult = $db->query("PRAGMA table_info('settings')");
    while ($row = $columnResult->fetchArray(SQLITE3_ASSOC)) {
        $settingsColumns[(string) ($row['name'] ?? '')] = true;
    }
    if (!isset($settingsColumns['screenshot_privacy_mode'])) {
        throw new RuntimeException('required screenshot privacy column missing');
    }

    $notificationColumns = [];
    $columnResult = $db->query("PRAGMA table_info('notification_settings')");
    while ($row = $columnResult->fetchArray(SQLITE3_ASSOC)) {
        $notificationColumns[(string) ($row['name'] ?? '')] = true;
    }
    if (!isset($notificationColumns['period_summary_at_period_start'])) {
        throw new RuntimeException('required notification column missing');
    }

    $subscriptionColumns = [];
    $columnResult = $db->query("PRAGMA table_info('subscriptions')");
    while ($row = $columnResult->fetchArray(SQLITE3_ASSOC)) {
        $subscriptionColumns[(string) ($row['name'] ?? '')] = true;
    }
    foreach (['logo_text_color', 'logo_variant'] as $column) {
        if (!isset($subscriptionColumns[$column])) {
            throw new RuntimeException('required subscription logo column missing');
        }
    }

    $requiredIndexes = [
        'idx_subscriptions_user_inactive_next_payment' => ['user_id', 'inactive', 'next_payment'],
        'idx_subscriptions_user_notify_inactive' => ['user_id', 'notify', 'inactive'],
    ];
    foreach ($requiredIndexes as $index => $expectedColumns) {
        $stmt = $db->prepare(
            'SELECT 1 FROM sqlite_master WHERE type = :type AND tbl_name = :table AND name = :name LIMIT 1'
        );
        $stmt->bindValue(':type', 'index', SQLITE3_TEXT);
        $stmt->bindValue(':table', 'subscriptions', SQLITE3_TEXT);
        $stmt->bindValue(':name', $index, SQLITE3_TEXT);
        $result = $stmt->execute();
        if ($result->fetchArray(SQLITE3_NUM) === false) {
            throw new RuntimeException('required index missing');
        }

        $actualColumns = [];
        $indexColumnResult = $db->query(
            'PRAGMA index_info("' . str_replace('"', '""', $index) . '")'
        );
        while ($row = $indexColumnResult->fetchArray(SQLITE3_ASSOC)) {
            $actualColumns[] = (string) ($row['name'] ?? '');
        }
        if ($actualColumns !== $expectedColumns) {
            throw new RuntimeException('required index columns are invalid');
        }
    }

    $cycle = $db->querySingle('SELECT id, days, name FROM cycles WHERE id = 5', true);
    if (!is_array($cycle)
        || (int) ($cycle['id'] ?? 0) !== 5
        || (int) ($cycle['days'] ?? -1) !== 0
        || strcasecmp(trim((string) ($cycle['name'] ?? '')), 'One-time') !== 0) {
        throw new RuntimeException('one-time cycle is invalid');
    }

    $db->close();
    $db = null;
    http_response_code(200);
    echo 'OK';
} catch (Throwable $throwable) {
    if ($db instanceof SQLite3) {
        $db->close();
    }
    http_response_code(503);
    echo 'NOT_READY';
}

exit;
