<?php
require_once __DIR__ . '/user_status.php';
require_once __DIR__ . '/request_logs.php';
require_once __DIR__ . '/timezone_settings.php';
require_once __DIR__ . '/auth_session.php';

function wallos_logout_trashed_session_and_redirect(array $userData)
{
    wallos_auth_reset_login_state();
    header('Location: login.php?' . wallos_prepare_trashed_login_redirect_query($userData));
    exit();
}

// Handle OIDC first
wallos_auth_start_session();

if (isset($_GET['code']) && isset($_GET['state'])) {
    // This request is coming from the OIDC login flow
    $code = $_GET['code'];
    $state = $_GET['state'];

    require_once 'includes/oidc/handle_oidc_callback.php';

} else {
    $wallosAuthState = wallos_auth_resolve_authenticated_user($db);
    if (!$wallosAuthState['authenticated']) {
        if ($wallosAuthState['code'] === 'account_trashed' && !empty($wallosAuthState['user'])) {
            wallos_logout_trashed_session_and_redirect($wallosAuthState['user']);
        }

        $db->close();
        header("Location: login.php");
        exit();
    }

    $userData = $wallosAuthState['user'];
    $userId = (int) ($userData['id'] ?? 0);
    $username = (string) ($userData['username'] ?? '');
    $main_currency = $userData['main_currency'] ?? ($_SESSION['main_currency'] ?? null);

    wallos_apply_php_timezone(wallos_fetch_user_timezone($db, $userId));

    if (($userData['avatar'] ?? '') == "") {
        $userData['avatar'] = "0";
    }
    wallos_log_request($db, $userData['id'], $userData['username'] ?? '');
}


?>
