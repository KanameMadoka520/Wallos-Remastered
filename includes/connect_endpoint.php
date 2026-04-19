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

require_once 'i18n/languages.php';
require_once 'i18n/getlang.php';
require_once 'i18n/' . $lang . '.php';
require_once 'user_status.php';
require_once 'request_logs.php';
require_once 'security_rate_limits.php';

$secondsInMonth = 30 * 24 * 60 * 60;
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', (string) $secondsInMonth);
    session_set_cookie_params([
        'lifetime' => $secondsInMonth,             
        'httponly' => true,          
        'samesite' => 'Lax'          
    ]);
    session_start();
}

function wallos_clear_endpoint_login_cookie()
{
    setcookie('wallos_login', '', time() - 3600, '/');
    setcookie('wallos_login', '', time() - 3600);
}

function wallos_try_restore_endpoint_session_from_cookie($db)
{
    if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true && !empty($_SESSION['userId'])) {
        return (int) $_SESSION['userId'];
    }

    $cookieValue = $_COOKIE['wallos_login'] ?? '';
    if ($cookieValue === '') {
        return 0;
    }

    $cookieParts = explode('|', $cookieValue, 3);
    if (count($cookieParts) !== 3) {
        wallos_clear_endpoint_login_cookie();
        return 0;
    }

    [$username, $token, $mainCurrency] = $cookieParts;
    $username = trim((string) $username);
    $token = trim((string) $token);

    if ($username === '' || $token === '') {
        wallos_clear_endpoint_login_cookie();
        return 0;
    }

    $userStmt = $db->prepare('SELECT * FROM user WHERE username = :username LIMIT 1');
    $userStmt->bindValue(':username', $username, SQLITE3_TEXT);
    $userResult = $userStmt->execute();
    $userRow = $userResult ? $userResult->fetchArray(SQLITE3_ASSOC) : false;
    if ($userRow === false || empty($userRow['id'])) {
        wallos_clear_endpoint_login_cookie();
        return 0;
    }

    $userId = (int) $userRow['id'];

    $adminResult = $db->query('SELECT login_disabled FROM admin');
    $adminRow = $adminResult ? $adminResult->fetchArray(SQLITE3_ASSOC) : false;
    $loginDisabled = !empty($adminRow['login_disabled']);

    if ($loginDisabled) {
        $tokenStmt = $db->prepare('SELECT 1 FROM login_tokens WHERE user_id = :userId LIMIT 1');
        $tokenStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    } else {
        $tokenStmt = $db->prepare('SELECT 1 FROM login_tokens WHERE user_id = :userId AND token = :token LIMIT 1');
        $tokenStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $tokenStmt->bindValue(':token', $token, SQLITE3_TEXT);
    }

    $tokenResult = $tokenStmt->execute();
    $tokenRow = $tokenResult ? $tokenResult->fetchArray(SQLITE3_ASSOC) : false;
    if ($tokenRow === false) {
        wallos_clear_endpoint_login_cookie();
        return 0;
    }

    $_SESSION['username'] = $username;
    $_SESSION['token'] = $token;
    $_SESSION['loggedin'] = true;
    $_SESSION['main_currency'] = $userRow['main_currency'] ?? $mainCurrency;
    $_SESSION['userId'] = $userId;

    return $userId;
}

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    $userId = $_SESSION['userId'];
} else {
    $userId = 0;
}

if ($userId <= 0) {
    $userId = wallos_try_restore_endpoint_session_from_cookie($db);
}

if ($userId > 0) {
    wallos_apply_php_timezone(wallos_fetch_user_timezone($db, $userId));
    $userStmt = $db->prepare('SELECT id, username, account_status, trash_reason, scheduled_delete_at FROM user WHERE id = :userId');
    $userStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $userResult = $userStmt->execute();
    $userRow = $userResult ? $userResult->fetchArray(SQLITE3_ASSOC) : false;

    if ($userRow === false) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        wallos_clear_endpoint_login_cookie();
        $userId = 0;
    } elseif (wallos_is_user_trashed($userRow['account_status'] ?? WALLOS_USER_STATUS_ACTIVE)) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        wallos_clear_endpoint_login_cookie();

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
        $rateLimitViolation = wallos_enforce_backend_request_rate_limit($db, $userRow['id'], $userRow['username'] ?? '', $i18n);
        if ($rateLimitViolation !== null) {
            http_response_code((int) ($rateLimitViolation['status'] ?? 429));
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'rate_limit' => true,
                'message' => $rateLimitViolation['message'],
                'retry_at' => $rateLimitViolation['retry_at'],
                'code' => $rateLimitViolation['code'],
            ]);
            exit;
        }
    }
}

?>
