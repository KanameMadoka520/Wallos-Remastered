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
        if (preg_match('#(?:^|/)' . preg_quote('migrations/000079.php', '#') . '$#', $migration) === 1) {
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
