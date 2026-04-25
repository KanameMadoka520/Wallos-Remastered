<?php
require_once __DIR__ . '/../includes/request_security.php';
require_once __DIR__ . '/../includes/auth_session.php';

const WALLOS_CSRF_TOKEN_CREATED_AT_SESSION_KEY = 'csrf_token_created_at';

if (session_status() === PHP_SESSION_NONE) {
    $secondsInMonth = wallos_auth_get_session_lifetime_seconds();
    ini_set('session.gc_maxlifetime', (string) $secondsInMonth);
    session_set_cookie_params(wallos_build_session_cookie_params($secondsInMonth));
    session_start();
}

function wallos_csrf_normalize_created_at(): int {
    $createdAt = (int) ($_SESSION[WALLOS_CSRF_TOKEN_CREATED_AT_SESSION_KEY] ?? 0);
    if ($createdAt <= 0) {
        $createdAt = time();
        $_SESSION[WALLOS_CSRF_TOKEN_CREATED_AT_SESSION_KEY] = $createdAt;
    }

    return $createdAt;
}

function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION[WALLOS_CSRF_TOKEN_CREATED_AT_SESSION_KEY] = time();
    }
    wallos_csrf_normalize_created_at();
    return $_SESSION['csrf_token'];
}

function get_csrf_token_created_at(): int {
    generate_csrf_token();
    return wallos_csrf_normalize_created_at();
}

function get_csrf_token_expires_at(): int {
    generate_csrf_token();
    return time() + wallos_auth_get_session_lifetime_seconds();
}

function get_csrf_token_fingerprint(): string {
    return substr(hash('sha256', generate_csrf_token()), 0, 12);
}

function verify_csrf_token(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) return false;
    // Use hash_equals to avoid timing attacks
    return hash_equals($_SESSION['csrf_token'], $token);
}
