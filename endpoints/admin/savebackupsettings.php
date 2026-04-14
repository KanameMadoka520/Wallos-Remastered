<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/backup_manager.php';
require_once '../../includes/timezone_settings.php';

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);

if (!is_array($data) || !isset($data['backup_retention_days'])) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
    exit;
}

$retentionDays = max(1, min(WALLOS_BACKUP_MAX_RETENTION_DAYS, (int) $data['backup_retention_days']));
$backupTimezone = wallos_normalize_timezone_identifier($data['backup_timezone'] ?? '', wallos_get_default_backup_timezone());
$stmt = $db->prepare('UPDATE admin SET backup_retention_days = :backup_retention_days, backup_timezone = :backup_timezone WHERE id = 1');
$stmt->bindValue(':backup_retention_days', $retentionDays, SQLITE3_INTEGER);
$stmt->bindValue(':backup_timezone', $backupTimezone, SQLITE3_TEXT);
$result = $stmt->execute();

if ($result) {
    echo json_encode([
        'success' => true,
        'message' => translate('backup_settings_saved', $i18n),
        'backupRetentionDays' => $retentionDays,
        'backupTimezone' => $backupTimezone,
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
}
