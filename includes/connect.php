<?php
require_once __DIR__ . '/timezone_settings.php';

$databaseFile = __DIR__ . '/../db/wallos.db';

$db = new SQLite3($databaseFile);
$db->busyTimeout(5000);
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('PRAGMA synchronous = NORMAL');
$db->exec('PRAGMA foreign_keys = ON');

if (!$db) {
    die('Connection to the database failed.');
}

wallos_apply_php_timezone(wallos_get_default_user_timezone());

?>
