<?php
require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/request_security.php';
require_once __DIR__ . '/auth_session.php';
require_once 'i18n/languages.php';
require_once 'i18n/getlang.php';
require_once 'i18n/' . $lang . '.php';
require_once 'request_logs.php';
require_once 'security_rate_limits.php';

wallos_prepare_api_request_credentials();

wallos_auth_start_session();

$wallosAuthState = wallos_auth_resolve_authenticated_user($db);
$currentAuthenticatedUser = $wallosAuthState['user'] ?? null;
$userId = !empty($wallosAuthState['authenticated']) ? (int) ($wallosAuthState['user_id'] ?? 0) : 0;

if ($wallosAuthState['code'] === 'account_trashed') {
    wallos_auth_emit_async_error(
        $i18n,
        'account_trashed',
        403,
        [
            'reason' => $currentAuthenticatedUser['trash_reason'] ?? '',
            'scheduled_delete_at' => $currentAuthenticatedUser['scheduled_delete_at'] ?? '',
        ],
        'account_in_recycle_bin'
    );
}

if ($userId > 0 && $currentAuthenticatedUser !== null) {
    wallos_apply_php_timezone(wallos_fetch_user_timezone($db, $userId));
    wallos_log_request($db, $currentAuthenticatedUser['id'], $currentAuthenticatedUser['username'] ?? '');

    $rateLimitViolation = wallos_enforce_backend_request_rate_limit(
        $db,
        $currentAuthenticatedUser['id'],
        $currentAuthenticatedUser['username'] ?? '',
        $i18n
    );
    if ($rateLimitViolation !== null) {
        http_response_code((int) ($rateLimitViolation['status'] ?? 429));
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'rate_limit' => true,
            'message' => $rateLimitViolation['message'],
            'retry_at' => $rateLimitViolation['retry_at'],
            'code' => $rateLimitViolation['code'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function wallos_endpoint_require_authenticated($i18n, array $options = [])
{
    global $wallosAuthState, $userId;

    if ($userId > 0 && !empty($wallosAuthState['authenticated'])) {
        return;
    }

    $statusCode = isset($options['status']) ? (int) $options['status'] : 401;
    wallos_auth_emit_async_error($i18n, 'session_expired', $statusCode, [], 'session_expired');
}
?>
