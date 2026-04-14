<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/backup_manager.php';
require_once '../../includes/backup_progress_messages.php';

$operationId = wallos_normalize_backup_operation_id($_GET['operationId'] ?? '');
if ($operationId === '') {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
    exit;
}

$status = wallos_read_backup_progress_status($operationId, __DIR__ . '/../../');
if ($status === null) {
    $labels = wallos_get_backup_progress_labels($lang);
    echo json_encode([
        'success' => true,
        'status' => [
            'operationId' => $operationId,
            'state' => 'waiting',
            'stage' => 'waiting',
            'progress' => 0,
            'tone' => 'pending',
            'message' => $labels['idle_message'],
        ],
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'status' => $status,
]);
