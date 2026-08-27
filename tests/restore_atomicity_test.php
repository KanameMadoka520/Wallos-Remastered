<?php

require_once __DIR__ . '/../includes/backup_manager.php';

function wallos_restore_atomicity_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_restore_atomicity_create_database($path, $identity, $validCycle = true)
{
    $db = new SQLite3($path);
    $db->enableExceptions(true);
    $db->exec('CREATE TABLE migrations (
        id INTEGER PRIMARY KEY,
        migration TEXT NOT NULL,
        migrated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
    $db->exec('CREATE TABLE admin (id INTEGER PRIMARY KEY)');
    $db->exec('CREATE TABLE user (
        id INTEGER PRIMARY KEY,
        period_budget REAL,
        budget_period_type TEXT,
        budget_period_anchor_date TEXT
    )');
    $db->exec('CREATE TABLE subscriptions (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        inactive INTEGER,
        next_payment TEXT,
        notify INTEGER,
        logo_text_color TEXT,
        logo_variant TEXT
    )');
    $db->exec('CREATE TABLE currencies (id INTEGER PRIMARY KEY)');
    $db->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY, week_starts_sunday INTEGER)');
    $db->exec('CREATE TABLE notification_settings (
        id INTEGER PRIMARY KEY,
        period_summary_at_period_start INTEGER DEFAULT 0
    )');
    $db->exec('CREATE TABLE cycles (id INTEGER PRIMARY KEY, days INTEGER NOT NULL, name TEXT NOT NULL)');
    $db->exec('CREATE TABLE restore_identity (value TEXT NOT NULL)');
    $db->exec('CREATE INDEX idx_subscriptions_user_inactive_next_payment
               ON subscriptions (user_id, inactive, next_payment)');
    $db->exec('CREATE INDEX idx_subscriptions_user_notify_inactive
               ON subscriptions (user_id, notify, inactive)');

    $marker = $db->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
    $marker->bindValue(':migration', 'migrations/000001.php', SQLITE3_TEXT);
    $marker->execute();
    $cycle = $db->prepare('INSERT INTO cycles (id, days, name) VALUES (5, :days, :name)');
    $cycle->bindValue(':days', $validCycle ? 0 : 30, SQLITE3_INTEGER);
    $cycle->bindValue(':name', $validCycle ? 'One-time' : 'Custom monthly', SQLITE3_TEXT);
    $cycle->execute();
    $identityStmt = $db->prepare('INSERT INTO restore_identity (value) VALUES (:value)');
    $identityStmt->bindValue(':value', (string) $identity, SQLITE3_TEXT);
    $identityStmt->execute();
    $db->close();
}

function wallos_restore_atomicity_create_archive($archivePath, $databasePath, $logoContents)
{
    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create restore archive fixture.');
    }
    $zip->addFile($databasePath, 'wallos.db');
    $zip->addFromString('logos/restored.txt', (string) $logoContents);
    $zip->close();
}

function wallos_restore_atomicity_database_identity($databasePath)
{
    $db = new SQLite3($databasePath, SQLITE3_OPEN_READONLY);
    $db->enableExceptions(true);
    try {
        return (string) $db->querySingle('SELECT value FROM restore_identity LIMIT 1');
    } finally {
        $db->close();
    }
}

$testRoot = sys_get_temp_dir() . '/wallos-restore-atomicity-' . bin2hex(random_bytes(8));

try {
    foreach (['db', 'migrations', 'images/uploads/logos', 'backups', '.tmp'] as $directory) {
        $path = $testRoot . '/' . $directory;
        if (!mkdir($path, 0770, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create restore fixture directory.');
        }
    }
    file_put_contents($testRoot . '/migrations/000001.php', "<?php\n\$db->exec('SELECT 1');\n");
    file_put_contents($testRoot . '/images/uploads/logos/current.txt', 'current-logo');
    wallos_restore_atomicity_create_database($testRoot . '/db/wallos.db', 'current', true);

    $mountedSource = $testRoot . '/mounted-source';
    $mountedTarget = $testRoot . '/mounted-target';
    mkdir($mountedSource . '/subscription-media/user-7', 0770, true);
    mkdir($mountedTarget . '/avatars', 0770, true);
    file_put_contents($mountedSource . '/restored.txt', 'restored-mounted-logo');
    file_put_contents($mountedSource . '/subscription-media/user-7/image.txt', 'restored-private-media');
    file_put_contents($mountedTarget . '/current.txt', 'current-mounted-logo');
    file_put_contents($mountedTarget . '/avatars/current.txt', 'current-avatar');
    chmod($mountedTarget, 0755);
    $mountedTargetIdentity = lstat($mountedTarget);
    $mountedTransaction = [];
    wallos_restore_prepare_logos_transaction(
        $mountedTransaction,
        $mountedSource,
        $mountedTarget,
        bin2hex(random_bytes(8)),
        ['logos_strategy' => 'contents']
    );
    wallos_restore_commit_logos_transaction($mountedTransaction);
    wallos_restore_finalize_logos_transaction($mountedTransaction);
    wallos_restore_atomicity_assert(
        file_get_contents($mountedTarget . '/restored.txt') === 'restored-mounted-logo'
            && file_get_contents($mountedTarget . '/subscription-media/user-7/image.txt') === 'restored-private-media'
            && !file_exists($mountedTarget . '/current.txt')
            && !file_exists($mountedTarget . '/avatars/current.txt'),
        'Mounted logos fallback did not replace directory contents.'
    );
    wallos_restore_atomicity_assert(
        ($mountedTransaction['strategy'] ?? '') === 'contents'
            && (lstat($mountedTarget)['ino'] ?? -1) === ($mountedTargetIdentity['ino'] ?? -2)
            && (fileperms($mountedTarget) & 0777) === 0755
            && (fileperms($mountedTarget . '/subscription-media/user-7') & 0777) === 0755
            && (fileperms($mountedTarget . '/restored.txt') & 0777) === 0644
            && (fileperms($mountedTarget . '/subscription-media/user-7/image.txt') & 0777) === 0644,
        'Restored Logo tree is not traversable and readable by the Nginx worker.'
    );
    wallos_restore_atomicity_assert(
        glob($mountedTarget . '/.wallos.restore.*') === [],
        'Mounted logos fallback left a recovery workspace after success.'
    );

    $invalidDatabase = $testRoot . '/invalid-restored.db';
    $invalidArchive = $testRoot . '/invalid.zip';
    wallos_restore_atomicity_create_database($invalidDatabase, 'invalid-restored', false);
    wallos_restore_atomicity_create_archive($invalidArchive, $invalidDatabase, 'invalid-logo');

    $invalidRejected = false;
    try {
        wallos_restore_backup_archive($invalidArchive, $testRoot);
    } catch (RuntimeException $runtimeException) {
        $invalidRejected = strpos($runtimeException->getMessage(), 'one-time billing cycle') !== false;
    }
    wallos_restore_atomicity_assert($invalidRejected, 'Invalid restored database was not rejected.');
    wallos_restore_atomicity_assert(
        wallos_restore_atomicity_database_identity($testRoot . '/db/wallos.db') === 'current',
        'Failed restore did not put the previous database back.'
    );
    wallos_restore_atomicity_assert(
        file_get_contents($testRoot . '/images/uploads/logos/current.txt') === 'current-logo',
        'Failed restore changed the current logos.'
    );

    $validDatabase = $testRoot . '/valid-restored.db';
    $validArchive = $testRoot . '/valid.zip';
    wallos_restore_atomicity_create_database($validDatabase, 'restored', true);
    wallos_restore_atomicity_create_archive($validArchive, $validDatabase, 'restored-logo');
    wallos_restore_backup_archive($validArchive, $testRoot);

    wallos_restore_atomicity_assert(
        wallos_restore_atomicity_database_identity($testRoot . '/db/wallos.db') === 'restored',
        'Successful restore did not install the restored database.'
    );
    wallos_restore_atomicity_assert(
        file_get_contents($testRoot . '/images/uploads/logos/restored.txt') === 'restored-logo'
            && !file_exists($testRoot . '/images/uploads/logos/current.txt'),
        'Successful restore did not atomically replace the logos.'
    );
    wallos_restore_atomicity_assert(
        glob($testRoot . '/db/.wallos.restore.previous-*.db') === []
            && !file_exists($testRoot . '/.tmp/database-maintenance.lock'),
        'Successful restore left rollback or maintenance state behind.'
    );

    echo "Restore database and logo atomicity test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    wallos_delete_directory_tree($testRoot);
}

?>
