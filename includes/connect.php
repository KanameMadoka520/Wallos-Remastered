<?php
require_once __DIR__ . '/timezone_settings.php';

$databaseFile = __DIR__ . '/../db/wallos.db';

$db = new SQLite3($databaseFile);
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
