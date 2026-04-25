<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/cache_refresh.php';

try {
    $marker = wallos_write_cache_refresh_marker(__DIR__ . '/../..');
    echo json_encode([
        'success' => true,
        'message' => translate('service_worker_cache_refresh_requested', $i18n),
        'token' => $marker['token'],
        'requested_at' => $marker['requested_at'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('service_worker_cache_refresh_failed', $i18n),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$db->close();

