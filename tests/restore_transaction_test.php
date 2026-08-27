<?php

require_once __DIR__ . '/../includes/backup_manager.php';

function wallos_restore_transaction_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_restore_transaction_create_database($path, $identity)
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
    $db->exec("INSERT INTO migrations (migration) VALUES ('migrations/000001.php')");
    $db->exec("INSERT INTO cycles (id, days, name) VALUES (5, 0, 'One-time')");

    $identityStmt = $db->prepare('INSERT INTO restore_identity (value) VALUES (:value)');
    $identityStmt->bindValue(':value', (string) $identity, SQLITE3_TEXT);
    $identityStmt->execute();
    $db->close();
}

function wallos_restore_transaction_database_identity($databasePath)
{
    $db = new SQLite3($databasePath, SQLITE3_OPEN_READONLY);
    try {
        return (string) $db->querySingle('SELECT value FROM restore_identity LIMIT 1');
    } finally {
        $db->close();
    }
}

function wallos_restore_transaction_open_wal_connection($databasePath, $probeIdentity)
{
    $db = new SQLite3($databasePath, SQLITE3_OPEN_READWRITE);
    $db->enableExceptions(true);
    $db->busyTimeout(10000);
    try {
        $journalMode = strtolower((string) $db->querySingle('PRAGMA journal_mode=WAL'));
        wallos_restore_transaction_assert(
            $journalMode === 'wal',
            'Cannot enable WAL mode for restore rollback regression fixture.'
        );
        $db->exec('PRAGMA wal_autocheckpoint=0');

        $statement = $db->prepare('INSERT INTO restore_identity (value) VALUES (:value)');
        $statement->bindValue(':value', (string) $probeIdentity, SQLITE3_TEXT);
        $statement->execute();

        clearstatcache(true, $databasePath . '-wal');
        clearstatcache(true, $databasePath . '-shm');
        wallos_restore_transaction_assert(
            is_file($databasePath . '-wal') && is_file($databasePath . '-shm'),
            'Open WAL fixture did not retain live -wal and -shm sidecars.'
        );

        return $db;
    } catch (Throwable $throwable) {
        $db->close();
        throw $throwable;
    }
}

function wallos_restore_transaction_assert_no_live_sidecars($databasePath, $label)
{
    foreach (['-wal', '-shm', '-journal'] as $suffix) {
        $sidecarPath = $databasePath . $suffix;
        clearstatcache(true, $sidecarPath);
        wallos_restore_transaction_assert(
            !file_exists($sidecarPath) && !is_link($sidecarPath),
            $label . ': live SQLite sidecar remains after rollback: ' . basename($sidecarPath)
        );
    }
}

function wallos_restore_transaction_create_archive($archivePath, $databasePath, array $logoFiles)
{
    $manifestFiles = [
        'wallos.db' => [
            'path' => 'wallos.db',
            'size_bytes' => (int) filesize($databasePath),
            'sha256' => hash_file('sha256', $databasePath),
        ],
    ];
    foreach ($logoFiles as $relativePath => $contents) {
        $entryName = 'logos/' . $relativePath;
        $manifestFiles[$entryName] = [
            'path' => $entryName,
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
    }

    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create restore transaction archive fixture.');
    }
    try {
        $zip->addFile($databasePath, 'wallos.db');
        $zip->addEmptyDir('logos');
        foreach ($logoFiles as $relativePath => $contents) {
            $zip->addFromString('logos/' . $relativePath, $contents);
        }
        $zip->addFromString('manifest.json', json_encode([
            'version' => 1,
            'file_count' => count($manifestFiles),
            'files' => $manifestFiles,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } finally {
        $zip->close();
    }
}

function wallos_restore_transaction_create_project($root, $identity)
{
    foreach (['db', 'migrations', 'images/uploads/logos/nested', 'backups', '.tmp'] as $directory) {
        $path = $root . '/' . $directory;
        if (!mkdir($path, 0770, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create restore transaction fixture directory.');
        }
    }
    file_put_contents($root . '/migrations/000001.php', "<?php\n\$db->exec('SELECT 1');\n");
    file_put_contents($root . '/images/uploads/logos/a-current.txt', 'old-logo-a');
    file_put_contents($root . '/images/uploads/logos/nested/current.txt', 'old-logo-nested');
    wallos_restore_transaction_create_database($root . '/db/wallos.db', $identity);
}

function wallos_restore_transaction_snapshot($root)
{
    return [
        'database_hash' => hash_file('sha256', $root . '/db/wallos.db'),
        'database_identity' => wallos_restore_transaction_database_identity($root . '/db/wallos.db'),
        'logos' => wallos_restore_directory_manifest($root . '/images/uploads/logos', true),
    ];
}

function wallos_restore_transaction_assert_clean($root, $label)
{
    wallos_restore_transaction_assert(
        !file_exists($root . '/db/.wallos-restore-transaction')
            && !file_exists($root . '/.tmp/database-maintenance.lock'),
        $label . ': restore transaction or maintenance marker was not removed.'
    );
    wallos_restore_transaction_assert(
        glob($root . '/db/.wallos.restore.*') === []
            && glob($root . '/images/uploads/logos/.wallos.restore.*') === [],
        $label . ': restore recovery paths were not removed.'
    );
}

function wallos_restore_transaction_run_clean_failure(
    $testRoot,
    $name,
    callable $faultHook,
    array $restoreOptions = []
)
{
    $root = $testRoot . '/' . $name;
    wallos_restore_transaction_create_project($root, 'old-' . $name);
    $incomingDatabase = $testRoot . '/' . $name . '-incoming.db';
    wallos_restore_transaction_create_database($incomingDatabase, 'new-' . $name);
    $archive = $testRoot . '/' . $name . '.zip';
    wallos_restore_transaction_create_archive($archive, $incomingDatabase, [
        'b-new.txt' => 'new-logo-b',
        'new/nested.txt' => 'new-logo-nested',
    ]);

    $before = wallos_restore_transaction_snapshot($root);
    $failed = false;
    try {
        $restoreOptions['fault_hook'] = $faultHook;
        wallos_restore_backup_archive($archive, $root, $restoreOptions);
    } catch (RuntimeException $runtimeException) {
        $failed = true;
    }

    wallos_restore_transaction_assert($failed, $name . ': injected failure did not abort restore.');
    wallos_restore_transaction_assert(
        wallos_restore_transaction_snapshot($root) === $before,
        $name . ': clean rollback did not restore the exact old database and media tree.'
    );
    wallos_restore_transaction_assert_clean($root, $name);
}

$testRoot = sys_get_temp_dir() . '/wallos-restore-transaction-' . bin2hex(random_bytes(8));
$externalSentinel = $testRoot . '-sentinel.txt';

try {
    if (!mkdir($testRoot, 0770, true) && !is_dir($testRoot)) {
        throw new RuntimeException('Cannot create restore transaction test root.');
    }
    ini_set('error_log', $testRoot . '/expected-errors.log');

    wallos_restore_transaction_run_clean_failure(
        $testRoot,
        'incoming-copy',
        static function ($phase) {
            if ($phase === 'db.incoming_copied') {
                throw new RuntimeException('Injected incoming copy failure.');
            }
        }
    );
    wallos_restore_transaction_run_clean_failure(
        $testRoot,
        'database-moved',
        static function ($phase) {
            if ($phase === 'db.current_moved') {
                throw new RuntimeException('Injected database move failure.');
            }
        }
    );
    wallos_restore_transaction_run_clean_failure(
        $testRoot,
        'database-installed',
        static function ($phase) {
            if ($phase === 'db.incoming_installed') {
                throw new RuntimeException('Injected database install failure.');
            }
        }
    );
    wallos_restore_transaction_run_clean_failure(
        $testRoot,
        'old-logo-moved',
        static function ($phase, $state) {
            if ($phase === 'logos.original_moved' && count($state['moved_original'] ?? []) === 1) {
                throw new RuntimeException('Injected old logo move failure.');
            }
        },
        ['logos_strategy' => 'contents']
    );
    wallos_restore_transaction_run_clean_failure(
        $testRoot,
        'new-logo-installed',
        static function ($phase, $state) {
            if ($phase === 'logos.new_installed' && count($state['installed_new'] ?? []) === 1) {
                throw new RuntimeException('Injected new logo install failure.');
            }
        }
    );
    wallos_restore_transaction_run_clean_failure(
        $testRoot,
        'before-commit',
        static function ($phase) {
            if ($phase === 'before_commit') {
                throw new RuntimeException('Injected joint verification failure.');
            }
        }
    );

    $walRoot = $testRoot . '/open-wal-rollback';
    wallos_restore_transaction_create_project($walRoot, 'old-open-wal');
    $walDatabasePath = $walRoot . '/db/wallos.db';
    $walBeforeStat = lstat($walDatabasePath);
    $walBeforeHash = hash_file('sha256', $walDatabasePath);
    $walBeforeIdentity = wallos_restore_transaction_database_identity($walDatabasePath);
    $walIncoming = $testRoot . '/open-wal-rollback.db';
    wallos_restore_transaction_create_database($walIncoming, 'new-open-wal');
    $walArchive = $testRoot . '/open-wal-rollback.zip';
    wallos_restore_transaction_create_archive($walArchive, $walIncoming, [
        'wal.txt' => 'wal-logo',
    ]);
    $walConnection = null;
    $walFailure = null;
    $walSidecarsObserved = false;
    try {
        wallos_restore_backup_archive($walArchive, $walRoot, [
            'fault_hook' => static function ($phase) use (
                &$walConnection,
                &$walSidecarsObserved,
                $walDatabasePath
            ) {
                if ($phase !== 'db.incoming_installed') {
                    return;
                }

                $walConnection = wallos_restore_transaction_open_wal_connection(
                    $walDatabasePath,
                    'new-open-wal-sidecar-probe'
                );
                $walSidecarsObserved = is_file($walDatabasePath . '-wal')
                    && is_file($walDatabasePath . '-shm');
                throw new RuntimeException('Injected rollback with an open WAL connection.');
            },
        ]);
    } catch (Throwable $throwable) {
        $walFailure = $throwable;
    }

    wallos_restore_transaction_assert(
        $walFailure instanceof RuntimeException
            && !($walFailure instanceof WallosRestoreRollbackIncompleteException),
        'Open WAL rollback did not complete cleanly after the injected failure.'
    );
    wallos_restore_transaction_assert(
        $walConnection instanceof SQLite3 && $walSidecarsObserved,
        'Open WAL rollback was not triggered with live -wal and -shm files.'
    );
    clearstatcache(true, $walDatabasePath);
    $walAfterStat = lstat($walDatabasePath);
    wallos_restore_transaction_assert(
        is_array($walBeforeStat)
            && is_array($walAfterStat)
            && (int) $walAfterStat['dev'] === (int) $walBeforeStat['dev']
            && (int) $walAfterStat['ino'] === (int) $walBeforeStat['ino']
            && hash_file('sha256', $walDatabasePath) === $walBeforeHash
            && wallos_restore_transaction_database_identity($walDatabasePath) === $walBeforeIdentity,
        'Open WAL rollback did not restore the old database inode, checksum, and content.'
    );
    wallos_restore_transaction_assert_no_live_sidecars(
        $walDatabasePath,
        'open-wal-rollback while connection remains open'
    );
    $walConnection->close();
    $walConnection = null;
    wallos_restore_transaction_assert_no_live_sidecars(
        $walDatabasePath,
        'open-wal-rollback after connection close'
    );
    wallos_restore_transaction_assert_clean($walRoot, 'open-wal-rollback');

    $retryRoot = $testRoot . '/sidecar-isolation-retry';
    wallos_restore_transaction_create_project($retryRoot, 'old-sidecar-retry');
    $retryDatabasePath = $retryRoot . '/db/wallos.db';
    $retryBeforeStat = lstat($retryDatabasePath);
    $retryBeforeHash = hash_file('sha256', $retryDatabasePath);
    $retryBeforeIdentity = wallos_restore_transaction_database_identity($retryDatabasePath);
    $retryIncoming = $testRoot . '/sidecar-isolation-retry.db';
    wallos_restore_transaction_create_database($retryIncoming, 'new-sidecar-retry');
    $retryTransaction = [];
    wallos_restore_prepare_database_transaction(
        $retryTransaction,
        $retryIncoming,
        $retryDatabasePath,
        $retryRoot,
        $retryRoot . '/images/uploads/logos',
        bin2hex(random_bytes(16))
    );
    wallos_restore_commit_database_transaction($retryTransaction);
    $retryConnection = wallos_restore_transaction_open_wal_connection(
        $retryDatabasePath,
        'new-sidecar-retry-probe'
    );

    $shmIsolationBlocker = $retryTransaction['incoming'] . '-shm';
    wallos_restore_transaction_assert(
        file_put_contents($shmIsolationBlocker, 'intentional-shm-isolation-conflict') !== false,
        'Cannot create the sidecar isolation retry fixture.'
    );
    $firstRollbackFailure = null;
    try {
        wallos_restore_rollback_database_transaction($retryTransaction);
    } catch (Throwable $throwable) {
        $firstRollbackFailure = $throwable;
    }
    wallos_restore_transaction_assert(
        $firstRollbackFailure instanceof WallosRestoreRollbackIncompleteException,
        'Mid-sidecar isolation failure did not retain an incomplete rollback state.'
    );
    wallos_restore_transaction_assert(
        !empty($retryTransaction['installed_incoming'])
            && !empty($retryTransaction['preserved_current'])
            && !empty($retryTransaction['new_main_isolated'])
            && in_array('-wal', $retryTransaction['isolated_sidecars'] ?? [], true)
            && !wallos_restore_path_exists($retryDatabasePath)
            && is_file($retryTransaction['incoming'])
            && is_file($retryTransaction['incoming'] . '-wal')
            && is_file($retryDatabasePath . '-shm')
            && is_file($retryTransaction['previous']),
        'Mid-sidecar isolation failure did not stop at the expected recoverable state.'
    );
    wallos_restore_transaction_assert(
        @unlink($shmIsolationBlocker),
        'Cannot remove the intentional sidecar isolation conflict.'
    );

    wallos_restore_rollback_database_transaction($retryTransaction);
    wallos_restore_rollback_database_transaction($retryTransaction);
    clearstatcache(true, $retryDatabasePath);
    $retryAfterStat = lstat($retryDatabasePath);
    wallos_restore_transaction_assert(
        is_array($retryBeforeStat)
            && is_array($retryAfterStat)
            && (int) $retryAfterStat['dev'] === (int) $retryBeforeStat['dev']
            && (int) $retryAfterStat['ino'] === (int) $retryBeforeStat['ino']
            && hash_file('sha256', $retryDatabasePath) === $retryBeforeHash
            && wallos_restore_transaction_database_identity($retryDatabasePath) === $retryBeforeIdentity
            && empty($retryTransaction['installed_incoming'])
            && empty($retryTransaction['preserved_current'])
            && ($retryTransaction['phase'] ?? '') === 'rolled_back',
        'Repeated rollback did not converge to the exact original database state.'
    );
    wallos_restore_transaction_assert_no_live_sidecars(
        $retryDatabasePath,
        'sidecar-isolation-retry while connection remains open'
    );
    $retryConnection->close();
    wallos_restore_transaction_assert_no_live_sidecars(
        $retryDatabasePath,
        'sidecar-isolation-retry after connection close'
    );
    wallos_restore_transaction_assert_clean($retryRoot, 'sidecar-isolation-retry');

    $symlinkRoot = $testRoot . '/symlink-rejection';
    wallos_restore_transaction_create_project($symlinkRoot, 'old-symlink');
    file_put_contents($externalSentinel, 'external-sentinel');
    symlink($externalSentinel, $symlinkRoot . '/images/uploads/logos/external-link');
    $symlinkIncoming = $testRoot . '/symlink-incoming.db';
    wallos_restore_transaction_create_database($symlinkIncoming, 'new-symlink');
    $symlinkArchive = $testRoot . '/symlink.zip';
    wallos_restore_transaction_create_archive($symlinkArchive, $symlinkIncoming, ['safe.txt' => 'safe']);
    $symlinkBeforeHash = hash_file('sha256', $symlinkRoot . '/db/wallos.db');
    $symlinkRejected = false;
    try {
        wallos_restore_backup_archive($symlinkArchive, $symlinkRoot);
    } catch (RuntimeException $runtimeException) {
        $symlinkRejected = strpos($runtimeException->getMessage(), 'symbolic link') !== false;
    }
    wallos_restore_transaction_assert($symlinkRejected, 'Live Logo symlink was not rejected.');
    wallos_restore_transaction_assert(
        file_get_contents($externalSentinel) === 'external-sentinel'
            && hash_file('sha256', $symlinkRoot . '/db/wallos.db') === $symlinkBeforeHash,
        'Symlink rejection touched the external sentinel or live database.'
    );
    wallos_restore_transaction_assert_clean($symlinkRoot, 'symlink-rejection');

    $incompleteRoot = $testRoot . '/rollback-incomplete';
    wallos_restore_transaction_create_project($incompleteRoot, 'old-incomplete');
    $incompleteIncoming = $testRoot . '/rollback-incomplete.db';
    wallos_restore_transaction_create_database($incompleteIncoming, 'new-incomplete');
    $incompleteArchive = $testRoot . '/rollback-incomplete.zip';
    wallos_restore_transaction_create_archive($incompleteArchive, $incompleteIncoming, [
        'new.txt' => 'new-logo',
    ]);
    $incompleteFailure = null;
    try {
        wallos_restore_backup_archive($incompleteArchive, $incompleteRoot, [
            'logos_strategy' => 'contents',
            'fault_hook' => static function ($phase, $state) {
                if ($phase === 'logos.new_installed'
                    && count($state['installed_new'] ?? []) === 1) {
                    throw new RuntimeException('Injected primary logo failure.');
                }
                if ($phase === 'rollback.logo_entry') {
                    throw new RuntimeException('Injected rollback failure.');
                }
            },
        ]);
    } catch (Throwable $throwable) {
        $incompleteFailure = $throwable;
    }
    wallos_restore_transaction_assert(
        $incompleteFailure instanceof WallosRestoreRollbackIncompleteException,
        'Incomplete rollback did not raise the dedicated exception.'
    );
    wallos_restore_transaction_assert(
        file_exists($incompleteRoot . '/db/.wallos-restore-transaction')
            && file_exists($incompleteRoot . '/.tmp/database-maintenance.lock')
            && glob($incompleteRoot . '/images/uploads/logos/.wallos.restore.*') !== []
            && (json_decode(
                file_get_contents($incompleteRoot . '/db/.wallos-restore-transaction'),
                true
            )['phase'] ?? '') === 'ROLLBACK_INCOMPLETE',
        'Incomplete rollback did not retain fail-closed markers and recovery paths.'
    );
    $sharedLockRejected = false;
    try {
        wallos_database_acquire_shared_runtime_lock($incompleteRoot . '/db/wallos.db', 10);
    } catch (RuntimeException $runtimeException) {
        $sharedLockRejected = true;
    }
    wallos_restore_transaction_assert($sharedLockRejected, 'Shared database access ignored an incomplete restore journal.');

    $committedRoot = $testRoot . '/after-commit';
    wallos_restore_transaction_create_project($committedRoot, 'old-committed');
    $committedIncoming = $testRoot . '/after-commit.db';
    wallos_restore_transaction_create_database($committedIncoming, 'new-committed');
    $committedArchive = $testRoot . '/after-commit.zip';
    wallos_restore_transaction_create_archive($committedArchive, $committedIncoming, [
        'committed.txt' => 'committed-logo',
    ]);
    $committedFailure = null;
    try {
        wallos_restore_backup_archive($committedArchive, $committedRoot, [
            'fault_hook' => static function ($phase) {
                if ($phase === 'after_commit') {
                    throw new RuntimeException('Injected post-commit cleanup failure.');
                }
            },
        ]);
    } catch (Throwable $throwable) {
        $committedFailure = $throwable;
    }
    wallos_restore_transaction_assert(
        $committedFailure instanceof WallosRestoreRollbackIncompleteException
            && wallos_restore_transaction_database_identity($committedRoot . '/db/wallos.db') === 'new-committed'
            && file_get_contents($committedRoot . '/images/uploads/logos/committed.txt') === 'committed-logo'
            && file_exists($committedRoot . '/db/.wallos-restore-transaction')
            && file_exists($committedRoot . '/.tmp/database-maintenance.lock')
            && (json_decode(
                file_get_contents($committedRoot . '/db/.wallos-restore-transaction'),
                true
            )['phase'] ?? '') === 'COMMITTED_CLEANUP_INCOMPLETE',
        'Post-COMMITTED failure rolled backward or failed to remain fail closed.'
    );

    $cleanupRoot = $testRoot . '/committed-cleanup-warning';
    wallos_restore_transaction_create_project($cleanupRoot, 'old-cleanup-warning');
    $cleanupIncoming = $testRoot . '/committed-cleanup-warning.db';
    wallos_restore_transaction_create_database($cleanupIncoming, 'new-cleanup-warning');
    $cleanupArchive = $testRoot . '/committed-cleanup-warning.zip';
    wallos_restore_transaction_create_archive($cleanupArchive, $cleanupIncoming, [
        'cleanup.txt' => 'cleanup-logo',
    ]);
    $cleanupFailure = null;
    try {
        wallos_restore_backup_archive($cleanupArchive, $cleanupRoot, [
            'fault_hook' => static function ($phase, $state) use ($externalSentinel) {
                if ($phase !== 'after_commit') {
                    return;
                }
                $previousLogos = (string) ($state['logos']['previous'] ?? '');
                if ($previousLogos === ''
                    || !symlink($externalSentinel, $previousLogos . '/cleanup-blocker-link')) {
                    throw new RuntimeException('Cannot create committed cleanup warning fixture.');
                }
            },
        ]);
    } catch (Throwable $throwable) {
        $cleanupFailure = $throwable;
    }
    wallos_restore_transaction_assert(
        $cleanupFailure instanceof WallosRestoreRollbackIncompleteException
            && wallos_restore_transaction_database_identity($cleanupRoot . '/db/wallos.db') === 'new-cleanup-warning'
            && file_get_contents($cleanupRoot . '/images/uploads/logos/cleanup.txt') === 'cleanup-logo'
            && file_exists($cleanupRoot . '/db/.wallos-restore-transaction')
            && file_exists($cleanupRoot . '/.tmp/database-maintenance.lock')
            && glob($cleanupRoot . '/images/uploads/.wallos.restore.previous-*') !== []
            && (json_decode(
                file_get_contents($cleanupRoot . '/db/.wallos-restore-transaction'),
                true
            )['phase'] ?? '') === 'COMMITTED_CLEANUP_INCOMPLETE',
        'A real committed cleanup warning removed fail-closed state or rolled back new data.'
    );

    $maintenanceCleanupRoot = $testRoot . '/maintenance-cleanup-failure';
    wallos_restore_transaction_create_project($maintenanceCleanupRoot, 'old-maintenance-cleanup');
    $maintenanceCleanupIncoming = $testRoot . '/maintenance-cleanup-failure.db';
    wallos_restore_transaction_create_database(
        $maintenanceCleanupIncoming,
        'new-maintenance-cleanup'
    );
    $maintenanceCleanupArchive = $testRoot . '/maintenance-cleanup-failure.zip';
    wallos_restore_transaction_create_archive(
        $maintenanceCleanupArchive,
        $maintenanceCleanupIncoming,
        ['maintenance.txt' => 'maintenance-logo']
    );
    $maintenanceCleanupFailure = null;
    try {
        wallos_restore_backup_archive($maintenanceCleanupArchive, $maintenanceCleanupRoot, [
            'fault_hook' => static function ($phase) use ($maintenanceCleanupRoot) {
                if ($phase !== 'after_commit') {
                    return;
                }
                $maintenancePath = $maintenanceCleanupRoot . '/.tmp/database-maintenance.lock';
                if (!@unlink($maintenancePath) || !mkdir($maintenancePath, 0700)) {
                    throw new RuntimeException('Cannot create maintenance cleanup failure fixture.');
                }
            },
        ]);
    } catch (Throwable $throwable) {
        $maintenanceCleanupFailure = $throwable;
    }
    wallos_restore_transaction_assert(
        $maintenanceCleanupFailure instanceof WallosRestoreRollbackIncompleteException
            && wallos_restore_transaction_database_identity(
                $maintenanceCleanupRoot . '/db/wallos.db'
            ) === 'new-maintenance-cleanup'
            && file_get_contents(
                $maintenanceCleanupRoot . '/images/uploads/logos/maintenance.txt'
            ) === 'maintenance-logo'
            && is_dir($maintenanceCleanupRoot . '/.tmp/database-maintenance.lock')
            && file_exists($maintenanceCleanupRoot . '/db/.wallos-restore-transaction')
            && (json_decode(
                file_get_contents($maintenanceCleanupRoot . '/db/.wallos-restore-transaction'),
                true
            )['phase'] ?? '') === 'COMMITTED_CLEANUP_INCOMPLETE',
        'Maintenance cleanup failure lost fail-closed state or rolled back committed data.'
    );

    echo "Restore transaction fault-injection tests passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    wallos_delete_directory_tree($testRoot);
    if (file_exists($externalSentinel) || is_link($externalSentinel)) {
        @unlink($externalSentinel);
    }
}

?>
