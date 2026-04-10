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

// Update the admin table (assuming id 1 is the primary settings row, as in your reference)
$sql = "UPDATE admin SET local_webhook_notifications_allowlist = :allowlist, login_rate_limit_max_attempts = :login_rate_limit_max_attempts WHERE id = 1";
$stmt = $db->prepare($sql);
$stmt->bindParam(':allowlist', $allowlist, SQLITE3_TEXT);
$stmt->bindParam(':login_rate_limit_max_attempts', $loginRateLimitMaxAttempts, SQLITE3_INTEGER);
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
