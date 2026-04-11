<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/backup_manager.php';

try {
    $result = wallos_cleanup_old_backups($db, __DIR__ . '/../../');
    $messageKey = $result['deleted_count'] > 0 ? 'backup_cleanup_success' : 'backup_cleanup_no_old_backups';

    echo json_encode([
        'success' => true,
        'message' => sprintf(translate($messageKey, $i18n), (int) $result['retention_days'], (int) $result['deleted_count']),
        'deletedCount' => (int) $result['deleted_count'],
    ]);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
}
