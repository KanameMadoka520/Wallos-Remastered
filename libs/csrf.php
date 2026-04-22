<?php
require_once __DIR__ . '/../includes/request_security.php';

$secondsInMonth = 30 * 24 * 60 * 60;
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(wallos_build_session_cookie_params($secondsInMonth));
    session_start();
}

function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) return false;
    // Use hash_equals to avoid timing attacks
    return hash_equals($_SESSION['csrf_token'], $token);
}
