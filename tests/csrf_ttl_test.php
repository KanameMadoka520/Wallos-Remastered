<?php

require_once __DIR__ . '/../libs/csrf.php';

function wallos_csrf_ttl_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
        exit(1);
    }
}

$_SESSION = [];

$token = generate_csrf_token();
wallos_csrf_ttl_assert($token !== '', 'CSRF token should be generated.');
wallos_csrf_ttl_assert(verify_csrf_token($token), 'Fresh CSRF token should verify.');

$_SESSION[WALLOS_CSRF_TOKEN_CREATED_AT_SESSION_KEY] = time() - WALLOS_CSRF_REFRESH_RECOMMENDED_SECONDS - 1;
wallos_csrf_ttl_assert(!verify_csrf_token($token), 'Expired CSRF token should be rejected.');
wallos_csrf_ttl_assert(empty($_SESSION['csrf_token']), 'Expired CSRF token should be cleared after rejection.');

$rotatedToken = generate_csrf_token();
wallos_csrf_ttl_assert($rotatedToken !== '', 'A new CSRF token should be generated after expiry.');
wallos_csrf_ttl_assert($rotatedToken !== $token, 'Expired CSRF token should rotate on the next page render.');
wallos_csrf_ttl_assert(verify_csrf_token($rotatedToken), 'Rotated CSRF token should verify.');

echo "CSRF TTL regression checks passed.\n";
