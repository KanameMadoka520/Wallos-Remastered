<?php
require_once __DIR__ . '/timezone_settings.php';
require_once __DIR__ . '/database_runtime_lock.php';

$databaseFile = __DIR__ . '/../db/wallos.db';

try {
    wallos_database_acquire_shared_runtime_lock($databaseFile);
} catch (Throwable $throwable) {
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['SCRIPT_FILENAME'] ?? ''));
    $isEndpointRequest = strpos($scriptName, '/endpoints/') !== false;
    http_response_code(503);
    header('Retry-After: 5');

    if ($isEndpointRequest) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'code' => 'database_maintenance',
            'error' => 'database_maintenance',
            'message' => 'Database maintenance is in progress.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    die('Database maintenance is in progress.');
}

$db = new SQLite3($databaseFile, SQLITE3_OPEN_READWRITE);
$db->busyTimeout(5000);
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('PRAGMA synchronous = NORMAL');
$db->exec('PRAGMA foreign_keys = ON');

if (!$db) {
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['SCRIPT_FILENAME'] ?? ''));
    $isEndpointRequest = strpos($scriptName, '/endpoints/') !== false;

    if ($isEndpointRequest) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'code' => 'database_connection_failed',
            'error' => 'database_connection_failed',
            'message' => 'Connection to the database failed.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    die('Connection to the database failed.');
}

wallos_apply_php_timezone(wallos_get_default_user_timezone());

?>
