<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/runtime_observability.php';
require_once '../../includes/system_maintenance.php';

$payload = json_decode(file_get_contents('php://input'), true);
$action = is_array($payload) ? trim((string) ($payload['action'] ?? '')) : '';
$startedAt = microtime(true);

function wallos_emit_system_maintenance_response($db, $i18n, $action, $startedAt, $adminUserId, array $response)
{
    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
    $summary = wallos_summarize_maintenance_action_result($action, $response);
    wallos_record_maintenance_action(
        $db,
        $action !== '' ? $action : 'unknown',
        !empty($response['success']),
        $durationMs,
        $summary,
        $adminUserId
    );
    $response['maintenance_action_logs'] = wallos_get_recent_maintenance_actions($db, 12);
    $response['maintenance_action_summary'] = wallos_get_maintenance_action_log_summary($db, 24);

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($action === 'get_system_overview') {
        wallos_emit_system_maintenance_response($db, $i18n, $action, $startedAt, $userId, [
            'success' => true,
            'message' => translate('system_overview_refreshed', $i18n),
            'system_overview' => wallos_get_admin_system_overview_summary($db, __DIR__ . '/../..', $i18n),
        ]);
    }

    if ($action === 'get_storage_usage') {
        wallos_emit_system_maintenance_response($db, $i18n, $action, $startedAt, $userId, [
            'success' => true,
            'message' => translate('storage_usage_refreshed', $i18n),
            'storage' => wallos_get_storage_usage_summary($db, __DIR__ . '/../..'),
            'recommendations' => wallos_get_maintenance_recommendation_summary($db, __DIR__ . '/../..', $i18n),
            'system_overview' => wallos_get_admin_system_overview_summary($db, __DIR__ . '/../..', $i18n),
        ]);
    }

    if ($action === 'get_maintenance_recommendations') {
        wallos_emit_system_maintenance_response($db, $i18n, $action, $startedAt, $userId, [
            'success' => true,
            'message' => translate('maintenance_recommendations_refreshed', $i18n),
            'recommendations' => wallos_get_maintenance_recommendation_summary($db, __DIR__ . '/../..', $i18n),
            'system_overview' => wallos_get_admin_system_overview_summary($db, __DIR__ . '/../..', $i18n),
        ]);
    }

    if ($action === 'get_maintenance_action_logs') {
        wallos_emit_system_maintenance_response($db, $i18n, $action, $startedAt, $userId, [
            'success' => true,
            'message' => translate('maintenance_action_logs_refreshed', $i18n),
        ]);
    }

    if ($action === 'scan_subscription_images') {
        wallos_emit_system_maintenance_response($db, $i18n, $action, $startedAt, $userId, [
            'success' => true,
            'message' => translate('success', $i18n),
            'audit' => wallos_audit_subscription_image_storage($db, __DIR__ . '/../..'),
            'recommendations' => wallos_get_maintenance_recommendation_summary($db, __DIR__ . '/../..', $i18n),
            'system_overview' => wallos_get_admin_system_overview_summary($db, __DIR__ . '/../..', $i18n),
        ]);
    }

    if ($action === 'reuse_oversized_subscription_image_variants') {
        $result = wallos_reuse_oversized_subscription_image_variants($db, __DIR__ . '/../..');
        wallos_emit_system_maintenance_response($db, $i18n, $action, $startedAt, $userId, [
            'success' => true,
            'message' => translate('subscription_image_oversized_variants_reused', $i18n),
            'oversized_variant_result' => $result,
            'audit' => wallos_audit_subscription_image_storage($db, __DIR__ . '/../..'),
            'recommendations' => wallos_get_maintenance_recommendation_summary($db, __DIR__ . '/../..', $i18n),
            'system_overview' => wallos_get_admin_system_overview_summary($db, __DIR__ . '/../..', $i18n),
        ]);
    }

    if ($action === 'cleanup_subscription_image_orphans') {
        $result = wallos_cleanup_subscription_image_orphans($db, __DIR__ . '/../..');
        wallos_emit_system_maintenance_response($db, $i18n, $action, $startedAt, $userId, [
            'success' => true,
            'message' => translate('subscription_image_orphans_cleaned', $i18n),
            'orphan_cleanup_result' => $result,
            'audit' => $result['after'] ?? null,
            'recommendations' => wallos_get_maintenance_recommendation_summary($db, __DIR__ . '/../..', $i18n),
            'system_overview' => wallos_get_admin_system_overview_summary($db, __DIR__ . '/../..', $i18n),
        ]);
    }

    if ($action === 'run_sqlite_maintenance') {
        $result = wallos_run_sqlite_maintenance($db);
        wallos_emit_system_maintenance_response($db, $i18n, $action, $startedAt, $userId, [
            'success' => true,
            'message' => translate('sqlite_maintenance_completed', $i18n),
            'result' => $result,
            'recommendations' => wallos_get_maintenance_recommendation_summary($db, __DIR__ . '/../..', $i18n),
            'system_overview' => wallos_get_admin_system_overview_summary($db, __DIR__ . '/../..', $i18n),
        ]);
    }

    if ($action === 'check_sqlite_indexes') {
        $result = wallos_check_sqlite_index_health($db);
        wallos_emit_system_maintenance_response($db, $i18n, $action, $startedAt, $userId, [
            'success' => true,
            'message' => $result['success']
                ? translate('sqlite_index_health_ok', $i18n)
                : translate('sqlite_index_health_problem', $i18n),
            'index_health' => $result,
            'recommendations' => wallos_get_maintenance_recommendation_summary($db, __DIR__ . '/../..', $i18n),
            'system_overview' => wallos_get_admin_system_overview_summary($db, __DIR__ . '/../..', $i18n),
        ]);
    }

    wallos_emit_system_maintenance_response($db, $i18n, $action, $startedAt, $userId, [
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
} catch (Throwable $throwable) {
    wallos_database_emit_busy_response_if_needed($i18n, $throwable, $db ?? null);
    http_response_code(500);
    $response = [
        'success' => false,
        'message' => translate('error', $i18n) . ': ' . $throwable->getMessage(),
    ];
    if (isset($db)) {
        wallos_record_maintenance_action(
            $db,
            $action !== '' ? $action : 'unknown',
            false,
            (int) round((microtime(true) - $startedAt) * 1000),
            wallos_summarize_maintenance_action_result($action, $response),
            isset($userId) ? (int) $userId : 0
        );
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$db->close();
