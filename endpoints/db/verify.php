<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const WALLOS_REQUIRED_MIGRATION_PREFIX = 81;

function wallos_verify_quote_identifier($identifier)
{
    return '"' . str_replace('"', '""', (string) $identifier) . '"';
}

function wallos_verify_normalize_migration_name($migration)
{
    $migration = str_replace('\\', '/', trim((string) $migration));
    if (preg_match('#(?:^|/)migrations/(\d{6}\.php)$#', $migration, $matches) === 1) {
        if ((int) substr($matches[1], 0, 6) < 1) {
            throw new RuntimeException('Malformed numbered migration marker: ' . $migration);
        }

        return 'migrations/' . $matches[1];
    }

    $basename = basename($migration);
    $hasMigrationDirectory = preg_match('#(?:^|/)migrations/#', $migration) === 1;
    $looksNumbered = preg_match('/^\d/', $basename) === 1
        || preg_match('#(?:^|/)\d+\.php(?:$|[./])#', $migration) === 1;
    if ($hasMigrationDirectory && $looksNumbered) {
        throw new RuntimeException('Malformed numbered migration marker: ' . $migration);
    }
    if (!$hasMigrationDirectory && preg_match('#(?:^|/)\d{6}\.php(?:$|[./])#', $migration) === 1) {
        throw new RuntimeException('Numbered migration marker is outside the migrations directory: ' . $migration);
    }

    // Non-numbered Remastered extension markers are intentionally preserved.
    return null;
}

function wallos_verify_migration_inventory($projectRoot)
{
    $migrationFiles = glob(rtrim($projectRoot, '/\\') . '/migrations/*.php') ?: [];
    $migrationsByNumber = [];

    foreach ($migrationFiles as $migrationFile) {
        $basename = basename($migrationFile);
        if (preg_match('/^(\d{6})\.php$/', $basename, $matches) !== 1) {
            throw new RuntimeException('Unexpected migration filename: ' . $basename);
        }

        $number = (int) $matches[1];
        if ($number < 1 || isset($migrationsByNumber[$number])) {
            throw new RuntimeException('Invalid or duplicate migration number: ' . $basename);
        }
        $migrationsByNumber[$number] = 'migrations/' . $basename;
    }

    if (!$migrationsByNumber) {
        throw new RuntimeException('No migration files are available in the application image.');
    }

    ksort($migrationsByNumber, SORT_NUMERIC);
    $latestNumber = (int) array_key_last($migrationsByNumber);
    if ($latestNumber < WALLOS_REQUIRED_MIGRATION_PREFIX) {
        throw new RuntimeException(sprintf(
            'Migration inventory stops at %06d; at least %06d is required.',
            $latestNumber,
            WALLOS_REQUIRED_MIGRATION_PREFIX
        ));
    }

    for ($number = 1; $number <= $latestNumber; $number++) {
        if (!isset($migrationsByNumber[$number])) {
            throw new RuntimeException(sprintf('Migration inventory has a gap at %06d.php.', $number));
        }
    }

    return array_values($migrationsByNumber);
}

function wallos_verify_assert_integrity(SQLite3 $db)
{
    $integrityResult = $db->query('PRAGMA integrity_check');
    $integrityRows = 0;
    while ($row = $integrityResult->fetchArray(SQLITE3_NUM)) {
        $integrityRows++;
        if (($row[0] ?? '') !== 'ok') {
            throw new RuntimeException('SQLite integrity_check failed.');
        }
    }
    if ($integrityRows === 0) {
        throw new RuntimeException('SQLite integrity_check returned no result.');
    }

    $foreignKeyResult = $db->query('PRAGMA foreign_key_check');
    if ($foreignKeyResult->fetchArray(SQLITE3_NUM) !== false) {
        throw new RuntimeException('SQLite foreign_key_check found violations.');
    }
}

function wallos_verify_table_exists(SQLite3 $db, $table)
{
    $stmt = $db->prepare('SELECT 1 FROM sqlite_master WHERE type = :type AND name = :name LIMIT 1');
    $stmt->bindValue(':type', 'table', SQLITE3_TEXT);
    $stmt->bindValue(':name', (string) $table, SQLITE3_TEXT);
    $result = $stmt->execute();

    return $result->fetchArray(SQLITE3_NUM) !== false;
}

function wallos_verify_assert_tables(SQLite3 $db, array $tables)
{
    foreach ($tables as $table) {
        if (!wallos_verify_table_exists($db, $table)) {
            throw new RuntimeException('Required table is missing: ' . $table);
        }
    }
}

function wallos_verify_table_columns(SQLite3 $db, $table)
{
    $columns = [];
    $result = $db->query('PRAGMA table_info(' . wallos_verify_quote_identifier($table) . ')');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $name = (string) ($row['name'] ?? '');
        if ($name !== '') {
            $columns[$name] = true;
        }
    }

    return $columns;
}

function wallos_verify_assert_columns(SQLite3 $db, $table, array $requiredColumns)
{
    $columns = wallos_verify_table_columns($db, $table);
    foreach ($requiredColumns as $column) {
        if (!isset($columns[$column])) {
            throw new RuntimeException('Required column is missing: ' . $table . '.' . $column);
        }
    }
}

function wallos_verify_assert_index(SQLite3 $db, $table, $index, array $expectedColumns)
{
    $stmt = $db->prepare('SELECT tbl_name FROM sqlite_master WHERE type = :type AND name = :name LIMIT 1');
    $stmt->bindValue(':type', 'index', SQLITE3_TEXT);
    $stmt->bindValue(':name', (string) $index, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if ($row === false || (string) ($row['tbl_name'] ?? '') !== $table) {
        throw new RuntimeException('Required index is missing or belongs to the wrong table: ' . $index);
    }

    $actualColumns = [];
    $columnResult = $db->query('PRAGMA index_info(' . wallos_verify_quote_identifier($index) . ')');
    while ($column = $columnResult->fetchArray(SQLITE3_ASSOC)) {
        $actualColumns[] = (string) ($column['name'] ?? '');
    }
    if ($actualColumns !== array_values($expectedColumns)) {
        throw new RuntimeException('Required index has unexpected columns: ' . $index);
    }
}

function wallos_verify_is_unmigrated_base_schema(SQLite3 $db)
{
    foreach (['payment_methods', 'categories', 'household', 'frequencies', 'notifications'] as $baseTable) {
        if (!wallos_verify_table_exists($db, $baseTable)) {
            return false;
        }
    }

    foreach (['settings', 'admin', 'email_notifications', 'oauth_settings', 'subscription_payment_records'] as $modernTable) {
        if (wallos_verify_table_exists($db, $modernTable)) {
            return false;
        }
    }

    $userColumns = wallos_verify_table_columns($db, 'user');
    foreach (['language', 'budget', 'api_key', 'period_budget'] as $modernColumn) {
        if (isset($userColumns[$modernColumn])) {
            return false;
        }
    }

    $subscriptionColumns = wallos_verify_table_columns($db, 'subscriptions');
    foreach (['url', 'inactive', 'user_id', 'auto_renew', 'lifecycle_status'] as $modernColumn) {
        if (isset($subscriptionColumns[$modernColumn])) {
            return false;
        }
    }

    return true;
}

function wallos_verify_read_migration_ledger(SQLite3 $db)
{
    if (!wallos_verify_table_exists($db, 'migrations')) {
        return [];
    }

    $columns = wallos_verify_table_columns($db, 'migrations');
    if (!isset($columns['migration'])) {
        throw new RuntimeException('Migration ledger is missing its migration column.');
    }

    $completed = [];
    $result = $db->query('SELECT migration FROM migrations');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $migration = wallos_verify_normalize_migration_name($row['migration'] ?? '');
        if ($migration !== null) {
            $completed[$migration] = true;
        }
    }

    return $completed;
}

function wallos_verify_assert_migration_prefix(SQLite3 $db, array $migrationInventory, $allowUnmigratedBase)
{
    $completed = wallos_verify_read_migration_ledger($db);
    if (!$completed) {
        if ($allowUnmigratedBase && wallos_verify_is_unmigrated_base_schema($db)) {
            return [];
        }
        throw new RuntimeException('Migration ledger is absent or empty on a database with migrated schema.');
    }

    $completedNumbers = [];
    foreach (array_keys($completed) as $migration) {
        $number = (int) substr(basename($migration), 0, 6);
        $completedNumbers[$number] = true;
    }
    ksort($completedNumbers, SORT_NUMERIC);

    $highestCompleted = (int) array_key_last($completedNumbers);
    $latestAvailable = count($migrationInventory);
    if ($highestCompleted > $latestAvailable) {
        throw new RuntimeException(sprintf(
            'Database migration %06d is newer than the application migration inventory.',
            $highestCompleted
        ));
    }

    for ($number = 1; $number <= $highestCompleted; $number++) {
        if (!isset($completedNumbers[$number])) {
            throw new RuntimeException(sprintf(
                'Migration ledger has a gap at migrations/%06d.php.',
                $number
            ));
        }
    }

    return $completed;
}

function wallos_verify_assert_completed_migrations(SQLite3 $db, array $migrationInventory)
{
    $completed = wallos_verify_assert_migration_prefix($db, $migrationInventory, false);
    foreach ($migrationInventory as $migration) {
        if (!isset($completed[$migration])) {
            throw new RuntimeException('Required migration is not recorded: ' . $migration);
        }
    }
}

function wallos_verify_assert_current_schema(SQLite3 $db)
{
    wallos_verify_assert_tables($db, [
        'admin',
        'user',
        'subscriptions',
        'categories',
        'currencies',
        'payment_methods',
        'cycles',
        'settings',
        'notification_settings',
        'migrations',
    ]);

    wallos_verify_assert_columns($db, 'user', [
        'id',
        'username',
        'email',
        'password',
        'main_currency',
        'api_key',
        'period_budget',
        'budget_period_type',
        'budget_period_anchor_date',
    ]);
    wallos_verify_assert_columns($db, 'subscriptions', [
        'id',
        'user_id',
        'cycle',
        'frequency',
        'next_payment',
        'inactive',
        'notify',
        'auto_renew',
        'lifecycle_status',
        'sort_order',
        'logo_text_color',
        'logo_variant',
    ]);
    wallos_verify_assert_columns($db, 'settings', ['user_id', 'week_starts_sunday', 'screenshot_privacy_mode']);
    wallos_verify_assert_columns($db, 'notification_settings', [
        'user_id',
        'days',
        'period_summary_at_period_start',
    ]);
    wallos_verify_assert_columns($db, 'migrations', ['migration']);
    wallos_verify_assert_columns($db, 'cycles', ['id', 'days', 'name']);

    wallos_verify_assert_index(
        $db,
        'subscriptions',
        'idx_subscriptions_user_inactive_next_payment',
        ['user_id', 'inactive', 'next_payment']
    );
    wallos_verify_assert_index(
        $db,
        'subscriptions',
        'idx_subscriptions_user_notify_inactive',
        ['user_id', 'notify', 'inactive']
    );

    $stmt = $db->prepare('SELECT days, name FROM cycles WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', 5, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $cycle = $result->fetchArray(SQLITE3_ASSOC);
    if ($cycle === false
        || (int) ($cycle['days'] ?? -1) !== 0
        || strcasecmp(trim((string) ($cycle['name'] ?? '')), 'One-time') !== 0) {
        throw new RuntimeException('Cycle id 5 does not have the required one-time purchase semantics.');
    }
}

$projectRoot = dirname(__DIR__, 2);
$databaseFile = $projectRoot . '/db/wallos.db';
$db = null;

try {
    $arguments = array_values(array_slice($argv ?? [], 1));
    if (count($arguments) > 1
        || (isset($arguments[0]) && $arguments[0] !== '--pre-migration')) {
        throw new InvalidArgumentException('Usage: verify.php [--pre-migration]');
    }
    $preMigration = isset($arguments[0]);

    $migrationInventory = wallos_verify_migration_inventory($projectRoot);

    clearstatcache(true, $databaseFile);
    if (is_link($databaseFile) || !is_file($databaseFile) || filesize($databaseFile) <= 0) {
        throw new RuntimeException('wallos.db is missing, empty, or not a regular file.');
    }

    $db = new SQLite3($databaseFile, SQLITE3_OPEN_READONLY);
    $db->enableExceptions(true);
    $db->busyTimeout(5000);

    wallos_verify_assert_integrity($db);

    if ($preMigration) {
        wallos_verify_assert_tables($db, ['user', 'subscriptions', 'currencies', 'cycles']);
        wallos_verify_assert_migration_prefix($db, $migrationInventory, true);
        echo 'Database pre-migration verification passed; '
            . count($migrationInventory)
            . " contiguous migration files are available.\n";
    } else {
        wallos_verify_assert_current_schema($db);
        wallos_verify_assert_completed_migrations($db, $migrationInventory);
        echo 'Database verification passed: '
            . count($migrationInventory)
            . " contiguous migrations and current schema contracts.\n";
    }
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Database verification failed: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($db instanceof SQLite3) {
        $db->close();
    }
}

?>
