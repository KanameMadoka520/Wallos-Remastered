<?php

require_once __DIR__ . '/database_runtime_lock.php';

$databaseFile = __DIR__ . '/../db/wallos.db';
try {
    wallos_database_acquire_shared_runtime_lock($databaseFile);
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
    exit(1);
}
$db = new SQLite3($databaseFile);
$db->busyTimeout(5000);
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('PRAGMA synchronous = NORMAL');
$db->exec('PRAGMA foreign_keys = ON');

if (!$db) {
    die('Connection to the database failed.');
}

require_once __DIR__ . '/../includes/i18n/languages.php';
require_once __DIR__ . '/../includes/i18n/getlang.php';
require_once __DIR__ . '/../includes/i18n/' . $lang . '.php';

?>
