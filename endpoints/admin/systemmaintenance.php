<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/system_maintenance.php';

$payload = json_decode(file_get_contents('php://input'), true);
$action = is_array($payload) ? trim((string) ($payload['action'] ?? '')) : '';

try {
    if ($action === 'scan_subscription_images') {
        echo json_encode([
            'success' => true,
            'message' => translate('success', $i18n),
            'audit' => wallos_audit_subscription_image_storage($db, __DIR__ . '/../..'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'run_sqlite_maintenance') {
        $result = wallos_run_sqlite_maintenance($db);
        echo json_encode([
            'success' => true,
            'message' => translate('sqlite_maintenance_completed', $i18n),
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n) . ': ' . $throwable->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$db->close();

