<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/backup_manager.php';
require_once '../../includes/backup_progress_messages.php';

set_time_limit(0);

function wallos_build_backup_progress_payload($lang, $stage, $progress, $tone = 'pending', array $context = [])
{
    $payload = [
        'state' => $stage === 'completed' ? 'completed' : ($stage === 'failed' ? 'failed' : 'running'),
        'stage' => $stage,
        'progress' => max(0, min(100, (int) $progress)),
        'tone' => $tone,
        'message' => wallos_get_backup_progress_message($lang, $stage, $context),
    ];

    if (!empty($context['backup']['download_url'])) {
        $payload['downloadUrl'] = $context['backup']['download_url'];
    }

    if (!empty($context['backup']['name'])) {
        $payload['backupName'] = $context['backup']['name'];
    }

    return $payload;
}

$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);
$operationId = wallos_normalize_backup_operation_id($data['operationId'] ?? '');

if ($operationId === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    wallos_write_backup_progress_status(
        $operationId,
        wallos_build_backup_progress_payload($lang, 'preparing', 2, 'pending'),
        __DIR__ . '/../../'
    );

    $progressCallback = function (array $progressUpdate) use ($operationId, $lang) {
        $stage = (string) ($progressUpdate['stage'] ?? 'running');
        $progress = (int) ($progressUpdate['progress'] ?? 0);
        $context = is_array($progressUpdate['context'] ?? null) ? $progressUpdate['context'] : [];
        $tone = $stage === 'completed' ? 'success' : 'pending';

        wallos_write_backup_progress_status(
            $operationId,
            wallos_build_backup_progress_payload($lang, $stage, $progress, $tone, $context),
            __DIR__ . '/../../'
        );
    };

    $backup = wallos_create_backup_archive($db, 'manual', __DIR__ . '/../../', $progressCallback);

    wallos_write_backup_progress_status(
        $operationId,
        wallos_build_backup_progress_payload($lang, 'completed', 100, 'success', ['backup' => $backup]),
        __DIR__ . '/../../'
    );

    echo json_encode([
        'success' => true,
        'message' => translate('backup_created_successfully', $i18n),
        'backup' => $backup,
        'downloadUrl' => $backup['download_url'],
        'operationId' => $operationId,
    ]);
} catch (Throwable $throwable) {
    wallos_write_backup_progress_status(
        $operationId,
        wallos_build_backup_progress_payload($lang, 'failed', 100, 'error'),
        __DIR__ . '/../../'
    );
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('backup_failed', $i18n),
    ]);
}
