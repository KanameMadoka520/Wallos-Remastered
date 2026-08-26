<?php

function wallos_one_time_migration_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_run_one_time_migration(SQLite3 $db)
{
    require __DIR__ . '/../migrations/000076.php';
}

try {
    $db = new SQLite3(':memory:');
    $db->enableExceptions(true);
    $db->exec('CREATE TABLE cycles (id INTEGER PRIMARY KEY, days INTEGER NOT NULL, name TEXT NOT NULL)');

    wallos_run_one_time_migration($db);
    wallos_run_one_time_migration($db);
    $cycle = $db->querySingle('SELECT days, name FROM cycles WHERE id = 5', true);
    wallos_one_time_migration_assert(
        is_array($cycle) && (int) $cycle['days'] === 0 && $cycle['name'] === 'One-time',
        'The one-time cycle must be created repeatably with the expected semantics.'
    );
    $db->close();

    $conflictDb = new SQLite3(':memory:');
    $conflictDb->enableExceptions(true);
    $conflictDb->exec('CREATE TABLE cycles (id INTEGER PRIMARY KEY, days INTEGER NOT NULL, name TEXT NOT NULL)');
    $conflictDb->exec("INSERT INTO cycles (id, days, name) VALUES (5, 30, 'Custom monthly')");
    $conflictRejected = false;
    try {
        wallos_run_one_time_migration($conflictDb);
    } catch (RuntimeException $runtimeException) {
        $conflictRejected = strpos($runtimeException->getMessage(), 'Cycle id 5') !== false;
    }
    wallos_one_time_migration_assert(
        $conflictRejected,
        'A pre-existing cycle id 5 must stop the migration instead of being reinterpreted as one-time.'
    );
    $conflictingCycle = $conflictDb->querySingle('SELECT days, name FROM cycles WHERE id = 5', true);
    wallos_one_time_migration_assert(
        (int) $conflictingCycle['days'] === 30 && $conflictingCycle['name'] === 'Custom monthly',
        'Conflict handling must not overwrite the existing cycle row.'
    );
    $conflictDb->close();

    echo "One-time cycle migration test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

?>
