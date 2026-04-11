<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/backup_manager.php';

set_time_limit(0);

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);
$backupName = trim((string) ($data['name'] ?? ''));

if ($backupName === '') {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
    exit;
}

$backup = wallos_find_backup_by_name($backupName, __DIR__ . '/../../');
if ($backup === null) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
    exit;
}

$verification = wallos_verify_backup_archive($backup['path']);
if (!$verification['is_valid']) {
    echo json_encode([
        'success' => false,
        'message' => translate('backup_restore_verification_failed', $i18n),
    ]);
    exit;
}

try {
    $db->close();
    wallos_restore_backup_archive($backup['path'], __DIR__ . '/../../');

    echo json_encode([
        'success' => true,
        'message' => translate('backup_restored_successfully', $i18n),
    ]);
} catch (Throwable $throwable) {
    echo json_encode([
        'success' => false,
        'message' => translate('restore_failed', $i18n),
    ]);
}
