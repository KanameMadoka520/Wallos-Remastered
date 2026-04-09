<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/subscription_media.php';

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);

$externalUrlLimit = max(1, min(WALLOS_SUBSCRIPTION_IMAGE_MAX_EXTERNAL_URL_LIMIT, (int) ($data['subscription_image_external_url_limit'] ?? 10)));
$trustedUploadLimit = max(0, min(WALLOS_SUBSCRIPTION_IMAGE_MAX_TRUSTED_UPLOAD_LIMIT, (int) ($data['trusted_subscription_upload_limit'] ?? 1)));
$maxSizeMb = max(1, min(WALLOS_SUBSCRIPTION_IMAGE_MAX_MAX_MB, (int) ($data['subscription_image_max_size_mb'] ?? 10)));

$stmt = $db->prepare('
    UPDATE admin
    SET subscription_image_external_url_limit = :external_url_limit,
        trusted_subscription_upload_limit = :trusted_upload_limit,
        subscription_image_max_size_mb = :max_size_mb
    WHERE id = 1
');
$stmt->bindValue(':external_url_limit', $externalUrlLimit, SQLITE3_INTEGER);
$stmt->bindValue(':trusted_upload_limit', $trustedUploadLimit, SQLITE3_INTEGER);
$stmt->bindValue(':max_size_mb', $maxSizeMb, SQLITE3_INTEGER);

if ($stmt->execute()) {
    die(json_encode([
        'success' => true,
        'message' => translate('subscription_image_settings_saved', $i18n)
    ]));
}

die(json_encode([
    'success' => false,
    'message' => translate('error', $i18n)
]));
