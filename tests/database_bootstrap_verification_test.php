<?php

function wallos_database_safety_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_database_safety_remove_tree($path)
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}

function wallos_database_safety_run_php($script, array $arguments = [])
{
    $command = array_merge([PHP_BINARY, $script], $arguments);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start isolated PHP process.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'stdout' => (string) $stdout,
        'stderr' => (string) $stderr,
    ];
}

function wallos_database_safety_write_migration_inventory($projectRoot)
{
    for ($number = 1; $number <= 80; $number++) {
        $path = $projectRoot . '/migrations/' . sprintf('%06d.php', $number);
        if (file_put_contents($path, "<?php\n") === false) {
            throw new RuntimeException('Unable to create test migration inventory.');
        }
    }
}

function wallos_database_safety_create_current_database($databaseFile)
{
    $db = new SQLite3($databaseFile, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
    $db->enableExceptions(true);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('BEGIN IMMEDIATE');

    try {
        $db->exec('CREATE TABLE admin (id INTEGER PRIMARY KEY)');
        $db->exec('CREATE TABLE user (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            email TEXT NOT NULL,
            password TEXT NOT NULL,
            main_currency INTEGER NOT NULL,
            api_key TEXT,
            period_budget REAL DEFAULT 0,
            budget_period_type TEXT DEFAULT "monthly",
            budget_period_anchor_date TEXT DEFAULT ""
        )');
        $db->exec('CREATE TABLE subscriptions (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            cycle INTEGER,
            frequency INTEGER,
            next_payment TEXT,
            inactive INTEGER DEFAULT 0,
            notify INTEGER DEFAULT 0,
            auto_renew INTEGER DEFAULT 1,
            lifecycle_status TEXT DEFAULT "active",
            sort_order INTEGER DEFAULT 0,
            logo_text_color TEXT,
            logo_variant TEXT
        )');
        $db->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY)');
        $db->exec('CREATE TABLE currencies (id INTEGER PRIMARY KEY)');
        $db->exec('CREATE TABLE payment_methods (id INTEGER PRIMARY KEY)');
        $db->exec('CREATE TABLE cycles (id INTEGER PRIMARY KEY, days INTEGER NOT NULL, name TEXT NOT NULL)');
        $db->exec('CREATE TABLE settings (user_id INTEGER, week_starts_sunday INTEGER DEFAULT 0)');
        $db->exec('CREATE TABLE notification_settings (
            user_id INTEGER,
            days INTEGER DEFAULT 1,
            period_summary_at_period_start INTEGER DEFAULT 0
        )');
        $db->exec('CREATE TABLE migrations (migration TEXT NOT NULL)');
        $db->exec('CREATE INDEX idx_subscriptions_user_inactive_next_payment
                   ON subscriptions(user_id, inactive, next_payment)');
        $db->exec('CREATE INDEX idx_subscriptions_user_notify_inactive
                   ON subscriptions(user_id, notify, inactive)');
        $db->exec("INSERT INTO cycles (id, days, name) VALUES (5, 0, 'One-time')");

        $marker = $db->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
        for ($number = 1; $number <= 80; $number++) {
            $migration = sprintf('migrations/%06d.php', $number);
            if ($number === 80) {
                $migration = '../../' . $migration;
            }
            $marker->bindValue(':migration', $migration, SQLITE3_TEXT);
            $marker->execute();
            $marker->reset();
        }
        $db->exec("INSERT INTO migrations (migration) VALUES ('custom/remastered-extension.php')");

        $db->exec('COMMIT');
    } catch (Throwable $throwable) {
        $db->exec('ROLLBACK');
        $db->close();
        throw $throwable;
    }

    $db->close();
}

$temporaryRoot = sys_get_temp_dir() . '/wallos-database-safety-' . bin2hex(random_bytes(6));

try {
    foreach (['endpoints/cronjobs', 'endpoints/db', 'db', 'migrations'] as $directory) {
        $path = $temporaryRoot . '/' . $directory;
        if (!mkdir($path, 0770, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create isolated test directory.');
        }
    }

    $bootstrapSource = __DIR__ . '/../endpoints/cronjobs/createdatabase.php';
    $verifySource = __DIR__ . '/../endpoints/db/verify.php';
    $bootstrapScript = $temporaryRoot . '/endpoints/cronjobs/createdatabase.php';
    $verifyScript = $temporaryRoot . '/endpoints/db/verify.php';
    wallos_database_safety_assert(copy($bootstrapSource, $bootstrapScript), 'Unable to copy bootstrap script.');
    wallos_database_safety_assert(copy($verifySource, $verifyScript), 'Unable to copy verification script.');
    wallos_database_safety_write_migration_inventory($temporaryRoot);

    $verifySourceText = file_get_contents($verifySource);
    wallos_database_safety_assert(
        is_string($verifySourceText)
            && strpos($verifySourceText, 'WALLOS_REQUIRED_MIGRATION_PREFIX = 80') !== false,
        'Database verification minimum migration prefix must track migration 000080.'
    );

    $bootstrapSourceText = file_get_contents($bootstrapSource);
    wallos_database_safety_assert(
        is_string($bootstrapSourceText)
            && strpos($bootstrapSourceText, "PHP_SAPI !== 'cli'") !== false,
        'Database bootstrap must reject HTTP execution.'
    );

    $databaseFile = $temporaryRoot . '/db/wallos.db';
    $freshResult = wallos_database_safety_run_php($bootstrapScript);
    wallos_database_safety_assert(
        $freshResult['exit_code'] === 0 && is_file($databaseFile) && filesize($databaseFile) > 0,
        'Fresh bootstrap failed: ' . $freshResult['stderr']
    );
    wallos_database_safety_assert(
        strpos($freshResult['stdout'], 'created and verified successfully') !== false,
        'Fresh bootstrap did not report verified completion.'
    );
    wallos_database_safety_assert(
        glob($temporaryRoot . '/db/.wallos-create-*') === [],
        'Fresh bootstrap left a temporary database behind.'
    );

    $bootstrapDb = new SQLite3($databaseFile, SQLITE3_OPEN_READONLY);
    $bootstrapDb->enableExceptions(true);
    $foreignKeyTargets = [];
    $foreignKeys = $bootstrapDb->query('PRAGMA foreign_key_list(subscriptions)');
    while ($foreignKey = $foreignKeys->fetchArray(SQLITE3_ASSOC)) {
        $foreignKeyTargets[(string) ($foreignKey['from'] ?? '')] = (string) ($foreignKey['table'] ?? '');
    }
    wallos_database_safety_assert(
        ($foreignKeyTargets['payer_user_id'] ?? '') === 'household'
            && ($foreignKeyTargets['category_id'] ?? '') === 'categories',
        'Fresh subscription schema does not contain both corrected foreign keys.'
    );
    $bootstrapDb->close();

    $preMigrationHash = hash_file('sha256', $databaseFile);
    $preMigrationResult = wallos_database_safety_run_php($verifyScript, ['--pre-migration']);
    wallos_database_safety_assert(
        $preMigrationResult['exit_code'] === 0
            && strpos($preMigrationResult['stdout'], 'pre-migration verification passed') !== false,
        'Read-only pre-migration verification failed: ' . $preMigrationResult['stderr']
    );
    wallos_database_safety_assert(
        hash_file('sha256', $databaseFile) === $preMigrationHash,
        'Pre-migration verification modified the database file.'
    );

    $existingHash = hash_file('sha256', $databaseFile);
    $existingSize = filesize($databaseFile);
    $existingMtime = filemtime($databaseFile);
    $existingResult = wallos_database_safety_run_php($bootstrapScript);
    clearstatcache(true, $databaseFile);
    wallos_database_safety_assert(
        $existingResult['exit_code'] === 0
            && hash_file('sha256', $databaseFile) === $existingHash
            && filesize($databaseFile) === $existingSize
            && filemtime($databaseFile) === $existingMtime,
        'Bootstrap modified an existing non-empty database.'
    );

    $bootstrapDatabase = $temporaryRoot . '/db/bootstrap-base.db';
    wallos_database_safety_assert(rename($databaseFile, $bootstrapDatabase), 'Unable to preserve bootstrap fixture.');
    wallos_database_safety_create_current_database($databaseFile);

    $formalResult = wallos_database_safety_run_php($verifyScript);
    wallos_database_safety_assert(
        $formalResult['exit_code'] === 0
            && strpos($formalResult['stdout'], '80 contiguous migrations') !== false,
        'Formal verification rejected a valid current schema or legacy marker: ' . $formalResult['stderr']
    );

    $formalDb = new SQLite3($databaseFile, SQLITE3_OPEN_READWRITE);
    $formalDb->enableExceptions(true);
    $formalDb->exec("UPDATE cycles SET days = 30, name = 'Custom monthly' WHERE id = 5");
    $formalDb->close();
    $cycleConflictResult = wallos_database_safety_run_php($verifyScript);
    wallos_database_safety_assert(
        $cycleConflictResult['exit_code'] !== 0
            && strpos($cycleConflictResult['stderr'], 'Cycle id 5') !== false,
        'Formal verification accepted conflicting cycle id 5 semantics.'
    );

    $formalDb = new SQLite3($databaseFile, SQLITE3_OPEN_READWRITE);
    $formalDb->enableExceptions(true);
    $formalDb->exec("UPDATE cycles SET days = 0, name = 'One-time' WHERE id = 5");
    $formalDb->exec('DROP INDEX idx_subscriptions_user_notify_inactive');
    $formalDb->close();
    $missingIndexResult = wallos_database_safety_run_php($verifyScript);
    wallos_database_safety_assert(
        $missingIndexResult['exit_code'] !== 0
            && strpos($missingIndexResult['stderr'], 'idx_subscriptions_user_notify_inactive') !== false,
        'Formal verification accepted a missing critical subscription index.'
    );

    $formalDb = new SQLite3($databaseFile, SQLITE3_OPEN_READWRITE);
    $formalDb->enableExceptions(true);
    $formalDb->exec('CREATE INDEX idx_subscriptions_user_notify_inactive
                     ON subscriptions(user_id, notify, inactive)');
    $formalDb->exec("DELETE FROM migrations WHERE migration = 'migrations/000078.php'");
    $formalDb->close();
    $missingMarkerResult = wallos_database_safety_run_php($verifyScript);
    wallos_database_safety_assert(
        $missingMarkerResult['exit_code'] !== 0
            && strpos($missingMarkerResult['stderr'], 'migrations/000078.php') !== false,
        'Formal verification accepted a missing required migration marker.'
    );

    $formalDb = new SQLite3($databaseFile, SQLITE3_OPEN_READWRITE);
    $formalDb->enableExceptions(true);
    $formalDb->exec("INSERT INTO migrations (migration) VALUES ('migrations/000078.php')");
    $formalDb->exec("INSERT INTO migrations (migration) VALUES ('migrations/000080.php.bak')");
    $formalDb->close();
    $malformedMarkerResult = wallos_database_safety_run_php($verifyScript);
    wallos_database_safety_assert(
        $malformedMarkerResult['exit_code'] !== 0
            && strpos($malformedMarkerResult['stderr'], 'Malformed numbered migration marker') !== false,
        'Formal verification accepted a malformed numbered migration marker.'
    );

    $formalDb = new SQLite3($databaseFile, SQLITE3_OPEN_READWRITE);
    $formalDb->enableExceptions(true);
    $formalDb->exec("DELETE FROM migrations WHERE migration = 'migrations/000080.php.bak'");
    $formalDb->exec("DELETE FROM migrations WHERE migration = 'migrations/000040.php'");
    $formalDb->close();
    $ledgerGapHash = hash_file('sha256', $databaseFile);
    $ledgerGapResult = wallos_database_safety_run_php($verifyScript, ['--pre-migration']);
    wallos_database_safety_assert(
        $ledgerGapResult['exit_code'] !== 0
            && strpos($ledgerGapResult['stderr'], 'gap at migrations/000040.php') !== false,
        'Pre-migration verification accepted a migration ledger gap.'
    );
    wallos_database_safety_assert(
        hash_file('sha256', $databaseFile) === $ledgerGapHash,
        'Failed migration-ledger verification modified the database file.'
    );

    $formalDb = new SQLite3($databaseFile, SQLITE3_OPEN_READWRITE);
    $formalDb->enableExceptions(true);
    $formalDb->exec("INSERT INTO migrations (migration) VALUES ('migrations/000040.php')");
    $formalDb->exec("DELETE FROM migrations WHERE migration LIKE '%migrations/%.php'");
    $customMarkerCount = (int) $formalDb->querySingle(
        "SELECT COUNT(*) FROM migrations WHERE migration = 'custom/remastered-extension.php'"
    );
    $formalDb->close();
    wallos_database_safety_assert(
        $customMarkerCount === 1,
        'Modern-schema fixture did not preserve its custom extension marker.'
    );
    $missingLedgerResult = wallos_database_safety_run_php($verifyScript, ['--pre-migration']);
    wallos_database_safety_assert(
        $missingLedgerResult['exit_code'] !== 0
            && strpos($missingLedgerResult['stderr'], 'ledger is absent or empty') !== false,
        'Pre-migration verification accepted a migrated schema without an official migration ledger.'
    );

    $missingMigration = $temporaryRoot . '/migrations/000040.php';
    wallos_database_safety_assert(unlink($missingMigration), 'Unable to remove migration gap fixture.');
    $migrationGapResult = wallos_database_safety_run_php($verifyScript, ['--pre-migration']);
    wallos_database_safety_assert(
        $migrationGapResult['exit_code'] !== 0
            && strpos($migrationGapResult['stderr'], 'gap at 000040.php') !== false,
        'Pre-migration verification accepted a migration inventory gap.'
    );

    $formalDatabase = $temporaryRoot . '/db/formal.db';
    wallos_database_safety_assert(rename($databaseFile, $formalDatabase), 'Unable to preserve formal fixture.');
    wallos_database_safety_assert(touch($databaseFile), 'Unable to create empty database fixture.');
    $emptyResult = wallos_database_safety_run_php($bootstrapScript);
    clearstatcache(true, $databaseFile);
    wallos_database_safety_assert(
        $emptyResult['exit_code'] !== 0
            && filesize($databaseFile) === 0
            && strpos($emptyResult['stderr'], 'exists but is empty') !== false,
        'Bootstrap did not reject and preserve an existing empty database file.'
    );

    echo "Database bootstrap and verification safety test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    wallos_database_safety_remove_tree($temporaryRoot);
}

?>
