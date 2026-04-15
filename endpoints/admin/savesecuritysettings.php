<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

if (!isset($data['local_webhook_notifications_allowlist']) || !isset($data['login_rate_limit_max_attempts'])) {
    echo json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]);
    die();
}

// Basic cleanup: trim whitespace and strip any accidental HTML tags
$allowlist = trim(strip_tags($data['local_webhook_notifications_allowlist']));
$loginRateLimitMaxAttempts = max(1, (int) $data['login_rate_limit_max_attempts']);
$loginRateLimitBlockMinutes = max(1, (int) ($data['login_rate_limit_block_minutes'] ?? 15));
$advancedRateLimitEnabled = !empty($data['advanced_rate_limit_enabled']) ? 1 : 0;
$backendRequestLimitPerMinute = max(1, (int) ($data['backend_request_limit_per_minute'] ?? 240));
$backendRequestLimitPerHour = max(1, (int) ($data['backend_request_limit_per_hour'] ?? 3600));
$imageUploadLimitPerMinute = max(1, (int) ($data['image_upload_limit_per_minute'] ?? 20));
$imageUploadLimitPerHour = max(1, (int) ($data['image_upload_limit_per_hour'] ?? 240));
$imageUploadMbPerMinute = max(1, (int) ($data['image_upload_mb_per_minute'] ?? 120));
$imageUploadMbPerHour = max(1, (int) ($data['image_upload_mb_per_hour'] ?? 1200));
$imageDownloadLimitPerMinute = max(1, (int) ($data['image_download_limit_per_minute'] ?? 180));
$imageDownloadLimitPerHour = max(1, (int) ($data['image_download_limit_per_hour'] ?? 2400));
$imageDownloadMbPerMinute = max(1, (int) ($data['image_download_mb_per_minute'] ?? 300));
$imageDownloadMbPerHour = max(1, (int) ($data['image_download_mb_per_hour'] ?? 3000));

// Update the admin table (assuming id 1 is the primary settings row, as in your reference)
$sql = "UPDATE admin SET
    local_webhook_notifications_allowlist = :allowlist,
    login_rate_limit_max_attempts = :login_rate_limit_max_attempts,
    login_rate_limit_block_minutes = :login_rate_limit_block_minutes,
    advanced_rate_limit_enabled = :advanced_rate_limit_enabled,
    backend_request_limit_per_minute = :backend_request_limit_per_minute,
    backend_request_limit_per_hour = :backend_request_limit_per_hour,
    image_upload_limit_per_minute = :image_upload_limit_per_minute,
    image_upload_limit_per_hour = :image_upload_limit_per_hour,
    image_upload_mb_per_minute = :image_upload_mb_per_minute,
    image_upload_mb_per_hour = :image_upload_mb_per_hour,
    image_download_limit_per_minute = :image_download_limit_per_minute,
    image_download_limit_per_hour = :image_download_limit_per_hour,
    image_download_mb_per_minute = :image_download_mb_per_minute,
    image_download_mb_per_hour = :image_download_mb_per_hour
    WHERE id = 1";
$stmt = $db->prepare($sql);
$stmt->bindParam(':allowlist', $allowlist, SQLITE3_TEXT);
$stmt->bindParam(':login_rate_limit_max_attempts', $loginRateLimitMaxAttempts, SQLITE3_INTEGER);
$stmt->bindParam(':login_rate_limit_block_minutes', $loginRateLimitBlockMinutes, SQLITE3_INTEGER);
$stmt->bindParam(':advanced_rate_limit_enabled', $advancedRateLimitEnabled, SQLITE3_INTEGER);
$stmt->bindParam(':backend_request_limit_per_minute', $backendRequestLimitPerMinute, SQLITE3_INTEGER);
$stmt->bindParam(':backend_request_limit_per_hour', $backendRequestLimitPerHour, SQLITE3_INTEGER);
$stmt->bindParam(':image_upload_limit_per_minute', $imageUploadLimitPerMinute, SQLITE3_INTEGER);
$stmt->bindParam(':image_upload_limit_per_hour', $imageUploadLimitPerHour, SQLITE3_INTEGER);
$stmt->bindParam(':image_upload_mb_per_minute', $imageUploadMbPerMinute, SQLITE3_INTEGER);
$stmt->bindParam(':image_upload_mb_per_hour', $imageUploadMbPerHour, SQLITE3_INTEGER);
$stmt->bindParam(':image_download_limit_per_minute', $imageDownloadLimitPerMinute, SQLITE3_INTEGER);
$stmt->bindParam(':image_download_limit_per_hour', $imageDownloadLimitPerHour, SQLITE3_INTEGER);
$stmt->bindParam(':image_download_mb_per_minute', $imageDownloadMbPerMinute, SQLITE3_INTEGER);
$stmt->bindParam(':image_download_mb_per_hour', $imageDownloadMbPerHour, SQLITE3_INTEGER);
$result = $stmt->execute();

if ($result) {
    echo json_encode([
        "success" => true,
        "message" => translate('success', $i18n)
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]);
}
