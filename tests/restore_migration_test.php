<?php

require_once __DIR__ . '/../includes/backup_manager.php';

function wallos_restore_migration_test_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_restore_migration_test_write_migration($projectRoot, $number, $body)
{
    $migrationDirectory = $projectRoot . '/migrations';
    if (!is_dir($migrationDirectory)) {
        mkdir($migrationDirectory, 0700, true);
    }

    $migrationPath = sprintf('%s/%06d.php', $migrationDirectory, (int) $number);
    if (file_put_contents($migrationPath, "<?php\n" . trim((string) $body) . "\n") === false) {
        throw new RuntimeException('Cannot write migration fixture: ' . $migrationPath);
    }
}

function wallos_restore_migration_test_create_database($databasePath, array $markers = [])
{
    $db = new SQLite3($databasePath);
    $db->enableExceptions(true);

    try {
        wallos_restore_migration_test_create_latest_schema($db);
        $stmt = $db->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
        foreach ($markers as $marker) {
            $stmt->bindValue(':migration', (string) $marker, SQLITE3_TEXT);
            $result = $stmt->execute();
            if ($result instanceof SQLite3Result) {
                $result->finalize();
            }
            $stmt->reset();
        }
    } finally {
        $db->close();
    }
}

function wallos_restore_migration_test_create_latest_schema(SQLite3 $db)
{
    $db->exec('CREATE TABLE migrations (
        id INTEGER PRIMARY KEY,
        migration TEXT NOT NULL,
        migrated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
    $db->exec('CREATE TABLE admin (id INTEGER PRIMARY KEY)');
    $db->exec('CREATE TABLE user (
        id INTEGER PRIMARY KEY,
        period_budget REAL DEFAULT 0,
        budget_period_type TEXT DEFAULT "monthly",
        budget_period_anchor_date TEXT DEFAULT ""
    )');
    $db->exec('CREATE TABLE subscriptions (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        inactive INTEGER,
        next_payment TEXT,
        notify INTEGER
    )');
    $db->exec('CREATE TABLE currencies (id INTEGER PRIMARY KEY)');
    $db->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY, week_starts_sunday INTEGER DEFAULT 0)');
    $db->exec('CREATE TABLE cycles (id INTEGER PRIMARY KEY, days INTEGER NOT NULL, name TEXT NOT NULL)');
    $db->exec("INSERT INTO cycles (id, days, name) VALUES (5, 0, 'One-time')");
    $db->exec('CREATE INDEX idx_subscriptions_user_inactive_next_payment
               ON subscriptions (user_id, inactive, next_payment)');
    $db->exec('CREATE INDEX idx_subscriptions_user_notify_inactive
               ON subscriptions (user_id, notify, inactive)');
}

function wallos_restore_migration_test_database_value($databasePath, $sql)
{
    $db = new SQLite3($databasePath, SQLITE3_OPEN_READONLY);
    $db->enableExceptions(true);
    try {
        return $db->querySingle($sql);
    } finally {
        $db->close();
    }
}

function wallos_restore_migration_test_transaction_rollback($testRoot)
{
    $projectRoot = $testRoot . '/rollback';
    mkdir($projectRoot, 0700, true);
    wallos_restore_migration_test_write_migration(
        $projectRoot,
        1,
        <<<'PHP'
throw new RuntimeException('A recognized legacy marker must not be rerun.');
PHP
    );
    wallos_restore_migration_test_write_migration(
        $projectRoot,
        2,
        <<<'PHP'
$db->exec('CREATE TABLE restore_failure_probe (id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO restore_failure_probe (id) VALUES (1)');
throw new RuntimeException('Injected migration failure.');
PHP
    );

    $databasePath = $projectRoot . '/wallos.db';
    wallos_restore_migration_test_create_database($databasePath, ['../../migrations/000001.php']);

    $failedAsExpected = false;
    try {
        wallos_run_migrations_after_restore($projectRoot, $databasePath);
    } catch (RuntimeException $runtimeException) {
        $failedAsExpected = strpos($runtimeException->getMessage(), '000002.php') !== false;
    }

    wallos_restore_migration_test_assert($failedAsExpected, 'The injected migration failure was not reported.');
    wallos_restore_migration_test_assert(
        (int) wallos_restore_migration_test_database_value(
            $databasePath,
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'restore_failure_probe'"
        ) === 0,
        'A failed migration left its business table behind.'
    );
    wallos_restore_migration_test_assert(
        (int) wallos_restore_migration_test_database_value(
            $databasePath,
            "SELECT COUNT(*) FROM migrations WHERE migration = 'migrations/000002.php'"
        ) === 0,
        'A failed migration left its completion marker behind.'
    );
}

function wallos_restore_migration_test_legacy_marker($testRoot)
{
    $projectRoot = $testRoot . '/legacy-marker';
    mkdir($projectRoot, 0700, true);
    wallos_restore_migration_test_write_migration(
        $projectRoot,
        1,
        <<<'PHP'
throw new RuntimeException('The legacy migration marker was not recognized.');
PHP
    );
    wallos_restore_migration_test_write_migration(
        $projectRoot,
        2,
        <<<'PHP'
$db->exec('CREATE TABLE restore_success_probe (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
$db->exec("INSERT INTO restore_success_probe (id, value) VALUES (1, 'complete')");
PHP
    );

    $databasePath = $projectRoot . '/wallos.db';
    wallos_restore_migration_test_create_database($databasePath, ['../../migrations/000001.php']);
    wallos_run_migrations_after_restore($projectRoot, $databasePath);

    wallos_restore_migration_test_assert(
        (string) wallos_restore_migration_test_database_value(
            $databasePath,
            'SELECT value FROM restore_success_probe WHERE id = 1'
        ) === 'complete',
        'The pending migration did not run after the legacy marker.'
    );
    wallos_restore_migration_test_assert(
        (int) wallos_restore_migration_test_database_value(
            $databasePath,
            "SELECT COUNT(*) FROM migrations WHERE migration = '../../migrations/000001.php'"
        ) === 1,
        'The legacy migration marker was unexpectedly rewritten.'
    );
    wallos_restore_migration_test_assert(
        (int) wallos_restore_migration_test_database_value(
            $databasePath,
            "SELECT COUNT(*) FROM migrations WHERE migration = 'migrations/000002.php'"
        ) === 1,
        'The successful migration marker was not recorded.'
    );
}

function wallos_restore_migration_test_history_gap($testRoot)
{
    $projectRoot = $testRoot . '/history-gap';
    mkdir($projectRoot, 0700, true);
    wallos_restore_migration_test_write_migration($projectRoot, 1, '$db->exec("SELECT 1");');
    wallos_restore_migration_test_write_migration(
        $projectRoot,
        2,
        '$db->exec("CREATE TABLE restore_gap_probe (id INTEGER PRIMARY KEY)");'
    );
    wallos_restore_migration_test_write_migration($projectRoot, 3, '$db->exec("SELECT 1");');

    $databasePath = $projectRoot . '/wallos.db';
    wallos_restore_migration_test_create_database(
        $databasePath,
        ['migrations/000001.php', 'migrations/000003.php']
    );

    $gapRejected = false;
    try {
        wallos_run_migrations_after_restore($projectRoot, $databasePath);
    } catch (RuntimeException $runtimeException) {
        $gapRejected = strpos($runtimeException->getMessage(), 'gap at migrations/000002.php') !== false;
    }

    wallos_restore_migration_test_assert($gapRejected, 'A migration history gap was not rejected.');
    wallos_restore_migration_test_assert(
        (int) wallos_restore_migration_test_database_value(
            $databasePath,
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'restore_gap_probe'"
        ) === 0,
        'Migration execution started before the history gap was rejected.'
    );
}

function wallos_restore_migration_test_complete_schema($testRoot)
{
    $projectRoot = $testRoot . '/complete-schema';
    mkdir($projectRoot, 0700, true);
    wallos_restore_migration_test_write_migration(
        $projectRoot,
        1,
        'wallos_restore_migration_test_create_latest_schema($db);'
    );

    $databasePath = $projectRoot . '/wallos.db';
    $emptyDatabase = new SQLite3($databasePath);
    $emptyDatabase->exec('PRAGMA user_version = 1');
    $emptyDatabase->close();

    wallos_run_migrations_after_restore($projectRoot, $databasePath);
    wallos_run_migrations_after_restore($projectRoot, $databasePath);

    wallos_restore_migration_test_assert(
        (int) wallos_restore_migration_test_database_value(
            $databasePath,
            "SELECT COUNT(*) FROM migrations WHERE migration = 'migrations/000001.php'"
        ) === 1,
        'A valid restored database did not finish with exactly one migration marker.'
    );
    wallos_restore_migration_test_assert(
        strtolower((string) wallos_restore_migration_test_database_value($databasePath, 'PRAGMA integrity_check')) === 'ok',
        'A successfully migrated database did not pass integrity_check.'
    );
}

function wallos_restore_migration_test_schema_rejection($testRoot)
{
    $projectRoot = $testRoot . '/schema-rejection';
    mkdir($projectRoot, 0700, true);
    wallos_restore_migration_test_write_migration($projectRoot, 1, '$db->exec("SELECT 1");');

    $databasePath = $projectRoot . '/wallos.db';
    wallos_restore_migration_test_create_database($databasePath, ['migrations/000001.php']);
    $db = new SQLite3($databasePath);
    $db->exec('DROP INDEX idx_subscriptions_user_notify_inactive');
    $db->close();

    $schemaRejected = false;
    try {
        wallos_run_migrations_after_restore($projectRoot, $databasePath);
    } catch (RuntimeException $runtimeException) {
        $schemaRejected = strpos(
            $runtimeException->getMessage(),
            'idx_subscriptions_user_notify_inactive'
        ) !== false;
    }

    wallos_restore_migration_test_assert(
        $schemaRejected,
        'A completed migration history with an incomplete latest schema was accepted.'
    );
}

$testRoot = sys_get_temp_dir() . '/wallos-restore-migration-' . bin2hex(random_bytes(8));
mkdir($testRoot, 0700, true);
$testExitCode = 0;

try {
    wallos_restore_migration_test_transaction_rollback($testRoot);
    echo "[PASS] failed migration rollback\n";

    wallos_restore_migration_test_legacy_marker($testRoot);
    echo "[PASS] legacy migration marker compatibility\n";

    wallos_restore_migration_test_history_gap($testRoot);
    echo "[PASS] migration history continuity\n";

    wallos_restore_migration_test_complete_schema($testRoot);
    echo "[PASS] successful migration and complete validation\n";

    wallos_restore_migration_test_schema_rejection($testRoot);
    echo "[PASS] incomplete latest schema rejection\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    $testExitCode = 1;
} finally {
    wallos_delete_directory_tree($testRoot);
}

if ($testExitCode !== 0) {
    exit($testExitCode);
}

echo "Restore migration safety test passed.\n";

?>
