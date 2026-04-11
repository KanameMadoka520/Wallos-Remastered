<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/backup_manager.php';

try {
    $backup = wallos_create_backup_archive($db, 'manual', __DIR__ . '/../../');

    echo json_encode([
        'success' => true,
        'message' => translate('backup_created_successfully', $i18n),
        'backup' => $backup,
        'downloadUrl' => $backup['download_url'],
    ]);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('backup_failed', $i18n),
    ]);
}
