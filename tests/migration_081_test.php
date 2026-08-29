<?php

function wallos_migration_081_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function wallos_run_migration_081(SQLite3 $database)
{
    $db = $database;
    require __DIR__ . '/../migrations/000081.php';
}

try {
    $db = new SQLite3(':memory:');
    $db->enableExceptions(true);
    $db->exec('CREATE TABLE settings (user_id INTEGER, dark_theme INTEGER DEFAULT 0)');
    $db->exec('CREATE TABLE subscriptions (id INTEGER PRIMARY KEY, name TEXT, price REAL, logo TEXT, notes TEXT)');
    $db->exec("INSERT INTO settings (user_id, dark_theme) VALUES (1, 1), (2, 0)");
    $db->exec("INSERT INTO subscriptions (id, name, price, logo, notes) VALUES (9, 'Canary Service', 123.45, 'canary.png', 'Canary notes')");

    wallos_run_migration_081($db);
    wallos_run_migration_081($db);

    $columns = [];
    $result = $db->query("PRAGMA table_info('settings')");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = (string) ($row['name'] ?? '');
    }
    wallos_migration_081_assert(
        count(array_filter($columns, static function ($column) {
            return $column === 'screenshot_privacy_mode';
        })) === 1,
        'Migration must add screenshot_privacy_mode exactly once.'
    );

    $settingsRows = [];
    $result = $db->query('SELECT user_id, dark_theme, screenshot_privacy_mode FROM settings ORDER BY user_id');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $settingsRows[(int) $row['user_id']] = $row;
    }
    wallos_migration_081_assert((int) $settingsRows[1]['screenshot_privacy_mode'] === 0, 'Existing users must default to privacy mode off.');
    wallos_migration_081_assert((int) $settingsRows[2]['screenshot_privacy_mode'] === 0, 'Every existing user must default to privacy mode off.');
    wallos_migration_081_assert((int) $settingsRows[1]['dark_theme'] === 1, 'Migration changed an unrelated setting.');

    $db->exec('UPDATE settings SET screenshot_privacy_mode = 1 WHERE user_id = 1');
    $db->exec('UPDATE settings SET screenshot_privacy_mode = NULL WHERE user_id = 2');
    wallos_run_migration_081($db);
    wallos_migration_081_assert(
        (int) $db->querySingle('SELECT screenshot_privacy_mode FROM settings WHERE user_id = 1') === 1,
        'Idempotent migration must preserve an enabled privacy preference.'
    );
    wallos_migration_081_assert(
        (int) $db->querySingle('SELECT screenshot_privacy_mode FROM settings WHERE user_id = 2') === 0,
        'Idempotent migration must normalize only NULL privacy preferences.'
    );

    $db->exec('INSERT INTO settings (user_id, dark_theme) VALUES (3, 1)');
    wallos_migration_081_assert(
        (int) $db->querySingle('SELECT screenshot_privacy_mode FROM settings WHERE user_id = 3') === 0,
        'New rows must inherit the database default of privacy mode off.'
    );

    $subscription = $db->querySingle('SELECT name, price, logo, notes FROM subscriptions WHERE id = 9', true);
    wallos_migration_081_assert(
        $subscription === [
            'name' => 'Canary Service',
            'price' => 123.45,
            'logo' => 'canary.png',
            'notes' => 'Canary notes',
        ],
        'Migration changed real subscription data.'
    );

    echo "Migration 000081 screenshot privacy test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
