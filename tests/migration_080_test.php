<?php

function wallos_migration_080_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_run_migration_080(SQLite3 $database)
{
    $db = $database;
    require __DIR__ . '/../migrations/000080.php';
}

try {
    // Historical database: add the column, preserve rows, and remain repeatable.
    $legacy = new SQLite3(':memory:');
    $legacy->enableExceptions(true);
    $legacy->exec('CREATE TABLE notification_settings (id INTEGER PRIMARY KEY, user_id INTEGER, days INTEGER)');
    $legacy->exec('CREATE TABLE subscriptions (id INTEGER PRIMARY KEY, logo TEXT)');
    $legacy->exec('INSERT INTO notification_settings VALUES (1, 7, 5)');
    wallos_run_migration_080($legacy);
    wallos_run_migration_080($legacy);
    $legacyRow = $legacy->querySingle('SELECT * FROM notification_settings WHERE id = 1', true);
    wallos_migration_080_assert((int) $legacyRow['days'] === 5, 'Migration changed the existing reminder lead time.');
    wallos_migration_080_assert(
        (int) $legacyRow['period_summary_at_period_start'] === 0,
        'Existing users must start with period summaries disabled.'
    );
    $legacySubscriptionColumns = [];
    $legacyColumnResult = $legacy->query("PRAGMA table_info('subscriptions')");
    while ($legacyColumnResult && ($column = $legacyColumnResult->fetchArray(SQLITE3_ASSOC))) {
        $legacySubscriptionColumns[] = (string) $column['name'];
    }
    wallos_migration_080_assert(
        in_array('logo_text_color', $legacySubscriptionColumns, true)
            && in_array('logo_variant', $legacySubscriptionColumns, true),
        'Migration did not add both themed-logo metadata columns.'
    );

    // New/empty database: later rows inherit the safe default.
    $fresh = new SQLite3(':memory:');
    $fresh->enableExceptions(true);
    $fresh->exec('CREATE TABLE notification_settings (id INTEGER PRIMARY KEY, user_id INTEGER, days INTEGER)');
    $fresh->exec('CREATE TABLE subscriptions (id INTEGER PRIMARY KEY, logo TEXT)');
    wallos_run_migration_080($fresh);
    $fresh->exec('INSERT INTO notification_settings (id, user_id, days) VALUES (1, 1, 2)');
    wallos_migration_080_assert(
        (int) $fresh->querySingle('SELECT period_summary_at_period_start FROM notification_settings WHERE id = 1') === 0,
        'New notification rows must inherit the disabled default.'
    );

    // Customized/current database: preserve explicit values and only backfill NULL.
    $current = new SQLite3(':memory:');
    $current->enableExceptions(true);
    $current->exec('CREATE TABLE notification_settings (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        days INTEGER,
        period_summary_at_period_start INTEGER
    )');
    $current->exec('CREATE TABLE subscriptions (
        id INTEGER PRIMARY KEY,
        logo TEXT,
        logo_text_color TEXT,
        logo_variant TEXT
    )');
    $current->exec("INSERT INTO subscriptions VALUES (1, 'original.png', 'dark', 'custom-variant.png')");
    $current->exec('INSERT INTO notification_settings VALUES (1, 1, 3, 1), (2, 2, 4, NULL)');
    wallos_run_migration_080($current);
    wallos_run_migration_080($current);
    wallos_migration_080_assert(
        (int) $current->querySingle('SELECT period_summary_at_period_start FROM notification_settings WHERE id = 1') === 1,
        'Migration overwrote an enabled summary preference.'
    );
    wallos_migration_080_assert(
        (int) $current->querySingle('SELECT period_summary_at_period_start FROM notification_settings WHERE id = 2') === 0,
        'Migration did not backfill a NULL summary preference.'
    );
    $currentLogo = $current->querySingle('SELECT * FROM subscriptions WHERE id = 1', true);
    wallos_migration_080_assert(
        $currentLogo['logo_text_color'] === 'dark'
            && $currentLogo['logo_variant'] === 'custom-variant.png',
        'Migration overwrote existing themed-logo metadata.'
    );

    // Third-party partial schema: add only the genuinely missing counterpart.
    $partial = new SQLite3(':memory:');
    $partial->enableExceptions(true);
    $partial->exec('CREATE TABLE notification_settings (user_id INTEGER, days INTEGER)');
    $partial->exec('CREATE TABLE subscriptions (id INTEGER PRIMARY KEY, logo_text_color TEXT)');
    $partial->exec("INSERT INTO subscriptions VALUES (1, 'light')");
    wallos_run_migration_080($partial);
    wallos_migration_080_assert(
        $partial->querySingle("SELECT logo_text_color FROM subscriptions WHERE id = 1") === 'light'
            && (int) $partial->querySingle("SELECT COUNT(*) FROM pragma_table_info('subscriptions') WHERE name='logo_variant'") === 1,
        'Migration did not preserve a partial custom schema while adding its missing column.'
    );

    echo "Migration 000080 compatibility test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

?>
