<?php
require_once __DIR__ . '/user_status.php';
require_once __DIR__ . '/request_security.php';

function wallos_auth_get_session_lifetime_seconds()
{
    return 30 * 24 * 60 * 60;
}

function wallos_auth_start_session()
{
    if (session_status() === PHP_SESSION_NONE) {
        $secondsInMonth = wallos_auth_get_session_lifetime_seconds();
        ini_set('session.gc_maxlifetime', (string) $secondsInMonth);
        session_set_cookie_params(wallos_build_session_cookie_params($secondsInMonth));
        session_start();
    }
}

function wallos_auth_clear_login_cookie()
{
    setcookie('wallos_login', '', wallos_build_cookie_options(time() - 3600, ['httponly' => true]));
}

function wallos_auth_reset_login_state()
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    wallos_auth_clear_login_cookie();
}

function wallos_auth_fetch_user_by_id($db, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return false;
    }

    $stmt = $db->prepare('SELECT * FROM user WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    return $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
}

function wallos_auth_fetch_user_by_username($db, $username)
{
    $username = trim((string) $username);
    if ($username === '') {
        return false;
    }

    $stmt = $db->prepare('SELECT * FROM user WHERE username = :username LIMIT 1');
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    return $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
}

function wallos_auth_is_login_disabled($db)
{
    $result = $db->query('SELECT login_disabled FROM admin');
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    return !empty($row['login_disabled']);
}

function wallos_auth_is_token_valid_for_user($db, $userId, $token)
{
    $userId = (int) $userId;
    $token = trim((string) $token);
    if ($userId <= 0 || $token === '') {
        return false;
    }

    $stmt = $db->prepare('SELECT 1 FROM login_tokens WHERE user_id = :user_id AND token = :token LIMIT 1');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);

    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_NUM) : false;
    return $row !== false;
}

function wallos_auth_apply_user_session(array $user, $token = '', $mainCurrencyFallback = null)
{
    $_SESSION['username'] = $user['username'] ?? '';
    $_SESSION['token'] = (string) $token;
    $_SESSION['loggedin'] = true;
    $_SESSION['main_currency'] = $user['main_currency'] ?? $mainCurrencyFallback;
    $_SESSION['userId'] = $user['id'] ?? 0;
}

function wallos_auth_parse_login_cookie($cookieValue)
{
    $cookieValue = trim((string) $cookieValue);
    if ($cookieValue === '') {
        return null;
    }

    $parts = explode('|', $cookieValue, 3);
    if (count($parts) < 2) {
        return null;
    }

    $username = trim((string) ($parts[0] ?? ''));
    $token = trim((string) ($parts[1] ?? ''));
    $mainCurrency = isset($parts[2]) ? trim((string) $parts[2]) : '';
    if ($username === '' || $token === '') {
        return null;
    }

    return [
        'username' => $username,
        'token' => $token,
        'main_currency' => $mainCurrency,
    ];
}

function wallos_auth_build_state($authenticated, $code, $user = null, $source = 'none', $staleCredentials = false)
{
    return [
        'authenticated' => (bool) $authenticated,
        'code' => (string) $code,
        'user' => $user ?: null,
        'user_id' => !empty($user['id']) ? (int) $user['id'] : 0,
        'source' => (string) $source,
        'stale_credentials' => (bool) $staleCredentials,
    ];
}

function wallos_auth_resolve_authenticated_user($db, array $options = [])
{
    wallos_auth_start_session();

    $sessionOnly = !empty($options['session_only']);
    $cookieValue = array_key_exists('cookie_value', $options)
        ? (string) $options['cookie_value']
        : (string) ($_COOKIE['wallos_login'] ?? '');

    if (!empty($_SESSION['loggedin']) && !empty($_SESSION['userId'])) {
        $sessionUser = wallos_auth_fetch_user_by_id($db, $_SESSION['userId']);
        if ($sessionUser === false) {
            wallos_auth_reset_login_state();
            return wallos_auth_build_state(false, 'session_expired', null, 'session', true);
        }

        if (wallos_is_user_trashed($sessionUser['account_status'] ?? WALLOS_USER_STATUS_ACTIVE)) {
            wallos_auth_reset_login_state();
            return wallos_auth_build_state(false, 'account_trashed', $sessionUser, 'session', true);
        }

        wallos_auth_apply_user_session($sessionUser, $_SESSION['token'] ?? '', $_SESSION['main_currency'] ?? null);
        return wallos_auth_build_state(true, 'authenticated', $sessionUser, 'session');
    }

    if ($sessionOnly) {
        return wallos_auth_build_state(false, 'not_authenticated');
    }

    $cookieData = wallos_auth_parse_login_cookie($cookieValue);
    if ($cookieData === null) {
        if (trim($cookieValue) !== '') {
            wallos_auth_clear_login_cookie();
            return wallos_auth_build_state(false, 'session_expired', null, 'cookie', true);
        }

        return wallos_auth_build_state(false, 'not_authenticated');
    }

    $cookieUser = wallos_auth_fetch_user_by_username($db, $cookieData['username']);
    if ($cookieUser === false) {
        wallos_auth_reset_login_state();
        return wallos_auth_build_state(false, 'session_expired', null, 'cookie', true);
    }

    if (wallos_is_user_trashed($cookieUser['account_status'] ?? WALLOS_USER_STATUS_ACTIVE)) {
        wallos_auth_reset_login_state();
        return wallos_auth_build_state(false, 'account_trashed', $cookieUser, 'cookie', true);
    }

    if (!wallos_auth_is_token_valid_for_user($db, $cookieUser['id'], $cookieData['token'])) {
        wallos_auth_reset_login_state();
        return wallos_auth_build_state(false, 'session_expired', null, 'cookie', true);
    }

    wallos_auth_apply_user_session($cookieUser, $cookieData['token'], $cookieData['main_currency']);
    return wallos_auth_build_state(true, 'authenticated', $cookieUser, 'cookie');
}

function wallos_auth_build_async_error_payload($i18n, $code, array $extra = [], $messageKey = null, $messageOverride = null)
{
    $resolvedCode = trim((string) $code) !== '' ? trim((string) $code) : 'error';
    if ($messageOverride !== null) {
        $message = (string) $messageOverride;
    } else {
        $resolvedKey = $messageKey ?: ($resolvedCode === 'session_expired' ? 'session_expired' : 'error');
        $message = translate($resolvedKey, $i18n);
    }

    $payload = [
        'success' => false,
        'code' => $resolvedCode,
        'error' => $resolvedCode,
        'message' => $message,
    ];

    if ($resolvedCode === 'session_expired') {
        $payload['session_expired'] = true;
        $payload['requires_relogin'] = true;
    }

    if ($resolvedCode === 'account_trashed') {
        $payload['account_trashed'] = true;
    }

    return array_merge($payload, $extra);
}

function wallos_auth_emit_async_error($i18n, $code, $statusCode = 400, array $extra = [], $messageKey = null, $messageOverride = null)
{
    http_response_code((int) $statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        wallos_auth_build_async_error_payload($i18n, $code, $extra, $messageKey, $messageOverride),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}
