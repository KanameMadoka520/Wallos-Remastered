<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/subscription_media.php';

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);

$subscriptionId = (int) ($data['subscriptionId'] ?? 0);
$orderedImageIds = $data['imageIds'] ?? [];

if ($subscriptionId <= 0 || !is_array($orderedImageIds) || empty($orderedImageIds)) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
    exit;
}

try {
    wallos_update_subscription_uploaded_image_order($db, $subscriptionId, $userId, $orderedImageIds);

    echo json_encode([
        'success' => true,
        'message' => translate('success', $i18n),
    ]);
} catch (Throwable $throwable) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
}
