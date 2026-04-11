<?php
require_once __DIR__ . '/../../includes/backup_manager.php';

$db = new SQLite3(__DIR__ . '/../../db/wallos.db');
$db->busyTimeout(5000);
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('PRAGMA synchronous = NORMAL');
$db->exec('PRAGMA foreign_keys = ON');

$mode = $argv[1] ?? 'auto';

try {
    if ($mode === 'cleanup') {
        $cleanupResult = wallos_cleanup_old_backups($db, __DIR__ . '/../../');
        echo 'Deleted old backups: ' . (int) $cleanupResult['deleted_count'] . PHP_EOL;
        exit(0);
    }

    $backup = wallos_create_backup_archive($db, $mode, __DIR__ . '/../../');
    $cleanupResult = wallos_cleanup_old_backups($db, __DIR__ . '/../../');

    echo 'Created backup: ' . $backup['name'] . PHP_EOL;
    echo 'Deleted old backups: ' . (int) $cleanupResult['deleted_count'] . PHP_EOL;
} catch (Throwable $throwable) {
    http_response_code(500);
    echo 'Backup failed: ' . $throwable->getMessage() . PHP_EOL;
}
