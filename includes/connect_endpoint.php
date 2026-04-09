<?php

$databaseFile = '../../db/wallos.db';
$db = new SQLite3($databaseFile);
$db->busyTimeout(5000);
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('PRAGMA synchronous = NORMAL');
$db->exec('PRAGMA foreign_keys = ON');

if (!$db) {
    die('Connection to the database failed.');
}

require_once 'i18n/languages.php';
require_once 'i18n/getlang.php';
require_once 'i18n/' . $lang . '.php';
require_once 'user_status.php';
require_once 'request_logs.php';

$secondsInMonth = 30 * 24 * 60 * 60;
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $secondsInMonth,             
        'httponly' => true,          
        'samesite' => 'Lax'          
    ]);
    session_start();
}

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    $userId = $_SESSION['userId'];
} else {
    $userId = 0;
}

if ($userId > 0) {
    $userStmt = $db->prepare('SELECT id, username, account_status, trash_reason, scheduled_delete_at FROM user WHERE id = :userId');
    $userStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $userResult = $userStmt->execute();
    $userRow = $userResult ? $userResult->fetchArray(SQLITE3_ASSOC) : false;

    if ($userRow === false) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        setcookie('wallos_login', '', time() - 3600, '/');
        setcookie('wallos_login', '', time() - 3600);
        $userId = 0;
    } elseif (wallos_is_user_trashed($userRow['account_status'] ?? WALLOS_USER_STATUS_ACTIVE)) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        setcookie('wallos_login', '', time() - 3600, '/');
        setcookie('wallos_login', '', time() - 3600);

        echo json_encode([
            'success' => false,
            'account_trashed' => true,
            'message' => translate('account_in_recycle_bin', $i18n),
            'reason' => $userRow['trash_reason'] ?? '',
            'scheduled_delete_at' => $userRow['scheduled_delete_at'] ?? '',
        ]);
        exit;
    } else {
        wallos_log_request($db, $userRow['id'], $userRow['username'] ?? '');
    }
}

?>
