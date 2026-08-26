<?php

function wallos_migration_079_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = new SQLite3(':memory:');

try {
    $db->exec('CREATE TABLE user (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL,
        budget REAL DEFAULT 0,
        yearly_budget REAL DEFAULT 0
    )');
    $db->exec('CREATE TABLE notification_settings (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        days INTEGER DEFAULT 1
    )');
    $db->exec("INSERT INTO user (id, username, budget, yearly_budget) VALUES
        (1, 'existing-user', 321.45, 4321.54)");
    $db->exec('INSERT INTO notification_settings (id, user_id, days) VALUES (1, 1, 7)');

    require __DIR__ . '/../migrations/000079.php';
    require __DIR__ . '/../migrations/000079.php';

    $user = $db->querySingle('SELECT * FROM user WHERE id = 1', true);
    wallos_migration_079_assert(abs((float) $user['budget'] - 321.45) < 0.0001, 'Monthly budget changed during migration.');
    wallos_migration_079_assert(abs((float) $user['yearly_budget'] - 4321.54) < 0.0001, 'Yearly budget changed during migration.');
    wallos_migration_079_assert((float) $user['period_budget'] === 0.0, 'Existing users must start with period budget disabled.');
    wallos_migration_079_assert($user['budget_period_type'] === 'monthly', 'Existing users need a safe monthly period default.');
    wallos_migration_079_assert(
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $user['budget_period_anchor_date']) === 1,
        'Existing users need a valid anchor date.'
    );

    $notification = $db->querySingle('SELECT * FROM notification_settings WHERE id = 1', true);
    wallos_migration_079_assert((int) $notification['days'] === 7, 'Existing notification lead time changed during migration.');
    $notificationColumns = [];
    $notificationResult = $db->query("PRAGMA table_info('notification_settings')");
    while ($notificationResult && ($row = $notificationResult->fetchArray(SQLITE3_ASSOC))) {
        $notificationColumns[] = $row['name'];
    }
    wallos_migration_079_assert(
        !in_array('period_summary_at_period_start', $notificationColumns, true),
        'Migration must not add dormant notification settings.'
    );
    wallos_migration_079_assert((int) $db->querySingle('SELECT COUNT(*) FROM user') === 1, 'User row count changed during migration.');

    $db->close();
    $db = null;

    $customDb = new SQLite3(':memory:');
    $customDb->enableExceptions(true);
    $customDb->exec('CREATE TABLE user (
        id INTEGER PRIMARY KEY,
        period_budget REAL,
        budget_period_type TEXT,
        budget_period_anchor_date TEXT
    )');
    $customDb->exec("INSERT INTO user VALUES (1, -25, 'custom-cycle', '1970-01-01')");
    $db = $customDb;
    require __DIR__ . '/../migrations/000079.php';
    $customUser = $customDb->querySingle('SELECT * FROM user WHERE id = 1', true);
    wallos_migration_079_assert(
        (float) $customUser['period_budget'] === -25.0
            && $customUser['budget_period_type'] === 'custom-cycle'
            && $customUser['budget_period_anchor_date'] === '1970-01-01',
        'Migration must not overwrite non-empty fields supplied by a customized schema.'
    );
    echo "Migration 000079 compatibility test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($db instanceof SQLite3) {
        $db->close();
    }
}

?>
