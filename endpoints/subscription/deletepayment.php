<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/subscription_payment_records.php';
require_once '../../includes/subscription_trash.php';

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);

$recordId = (int) ($data['record_id'] ?? 0);
$subscriptionId = (int) ($data['id'] ?? 0);

if ($recordId <= 0 || $subscriptionId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
    exit;
}

try {
    $existingRecord = wallos_get_subscription_payment_record_by_id($db, $recordId, $subscriptionId, $userId);
    if ($existingRecord === false) {
        throw new RuntimeException('Payment record not found.');
    }

    wallos_delete_subscription_payment_record($db, $recordId, $subscriptionId, $userId);

    echo json_encode([
        'success' => true,
        'message' => translate('subscription_payment_deleted', $i18n),
    ]);
} catch (Throwable $throwable) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
}

$db->close();
