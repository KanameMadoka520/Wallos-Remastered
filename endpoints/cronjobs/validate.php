<?php

// CLI cron jobs are authenticated by local process access. Browser-triggered
// maintenance jobs must use the same POST + CSRF contract as other mutations.
$userId = 0;
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../../libs/csrf.php';

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        die('Invalid request method');
    }

    $csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!verify_csrf_token($csrf)) {
        http_response_code(400);
        die('Invalid CSRF token');
    }

    $userId = isset($_SESSION['loggedin'], $_SESSION['userId']) && $_SESSION['loggedin'] === true
        ? (int) $_SESSION['userId']
        : 0;
    if ($userId !== 1) {
        http_response_code(403);
        die('Unauthorized');
    }
}

?>
