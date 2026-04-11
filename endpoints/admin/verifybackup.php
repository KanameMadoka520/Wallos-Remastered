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
        'verification' => [
            'statusLabel' => translate('backup_verification_status_failed', $i18n),
            'statusTone' => 'error',
        ],
    ]);
    exit;
}

$backup = wallos_find_backup_by_name($backupName, __DIR__ . '/../../');
if ($backup === null) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
        'verification' => [
            'statusLabel' => translate('backup_verification_status_failed', $i18n),
            'statusTone' => 'error',
        ],
    ]);
    exit;
}

$verification = wallos_verify_backup_archive($backup['path']);

if ($verification['is_valid']) {
    $message = $verification['level'] === 'full'
        ? sprintf(translate('backup_verification_full_success', $i18n), (int) $verification['files_checked'])
        : translate('backup_verification_basic_success', $i18n);

    $statusLabel = $verification['level'] === 'full'
        ? translate('backup_verification_status_full', $i18n)
        : translate('backup_verification_status_basic', $i18n);

    echo json_encode([
        'success' => true,
        'message' => $message,
        'verification' => [
            'level' => $verification['level'],
            'filesChecked' => (int) $verification['files_checked'],
            'expectedFiles' => (int) $verification['expected_files'],
            'statusLabel' => $statusLabel,
            'statusTone' => $verification['level'] === 'full' ? 'success' : 'warning',
        ],
    ]);
    exit;
}

echo json_encode([
    'success' => false,
    'message' => translate('backup_verification_failed', $i18n),
    'verification' => [
        'level' => 'invalid',
        'filesChecked' => (int) $verification['files_checked'],
        'expectedFiles' => (int) $verification['expected_files'],
        'statusLabel' => translate('backup_verification_status_failed', $i18n),
        'statusTone' => 'error',
    ],
]);
