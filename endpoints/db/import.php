<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/request_security.php';
require_once '../../includes/backup_manager.php';

header('Content-Type: application/json; charset=UTF-8');

function wallos_emit_bootstrap_import_response($success, $message, $statusCode = 200)
{
    http_response_code((int) $statusCode);
    echo json_encode([
        'success' => (bool) $success,
        'message' => (string) $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wallos_emit_bootstrap_import_response(false, translate('invalid_request_method', $i18n), 405);
}

$result = $db->query('SELECT COUNT(*) as count FROM user');
$row = $result ? $result->fetchArray(SQLITE3_NUM) : [1];
if ((int) ($row[0] ?? 1) > 0) {
    wallos_emit_bootstrap_import_response(false, 'Denied', 403);
}

if (!wallos_request_allows_local_login_bypass()) {
    wallos_emit_bootstrap_import_response(false, 'Bootstrap restore is only available from a direct local request.', 403);
}

if (!isset($_FILES['file'])) {
    wallos_emit_bootstrap_import_response(false, 'No file uploaded', 400);
}

$file = $_FILES['file'];
$fileError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($fileError !== UPLOAD_ERR_OK) {
    wallos_emit_bootstrap_import_response(false, 'Failed to upload file', 400);
}

$restoreTempDirectory = __DIR__ . '/../../.tmp';
if (!is_dir($restoreTempDirectory)) {
    mkdir($restoreTempDirectory, 0755, true);
}

$fileDestination = $restoreTempDirectory . '/bootstrap-restore-' . bin2hex(random_bytes(6)) . '.zip';
if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $fileDestination)) {
    wallos_emit_bootstrap_import_response(false, translate('restore_failed', $i18n), 500);
}

try {
    $verification = wallos_verify_backup_archive($fileDestination);
    if (!$verification['is_valid']) {
        wallos_emit_bootstrap_import_response(false, translate('backup_verification_failed', $i18n), 400);
    }

    $db->close();
    wallos_restore_backup_archive($fileDestination, __DIR__ . '/../../');

    wallos_emit_bootstrap_import_response(true, translate('success', $i18n));
} catch (Throwable $throwable) {
    wallos_emit_bootstrap_import_response(false, translate('restore_failed', $i18n), 500);
} finally {
    if (file_exists($fileDestination)) {
        @unlink($fileDestination);
    }
}
