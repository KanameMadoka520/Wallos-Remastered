<?php
// All requests should be POST requests
// CSRF Token must be included and match the token stored on the session
// User must be logged in

require_once __DIR__ . '/../libs/csrf.php';
require_once __DIR__ . '/auth_session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wallos_auth_emit_async_error($i18n, 'invalid_request_method', 405, [], null, 'Invalid request method');
}

$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!verify_csrf_token($csrf)) {
    wallos_auth_emit_async_error($i18n, 'invalid_csrf', 400, [], null, 'Invalid CSRF token');
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || (int) ($userId ?? 0) <= 0) {
    wallos_auth_emit_async_error($i18n, 'session_expired', 401, [], 'session_expired');
}
