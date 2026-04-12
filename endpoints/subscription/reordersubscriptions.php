<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/subscription_sort.php';

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);

$orderedSubscriptionIds = $data['subscriptionIds'] ?? [];

if (!is_array($orderedSubscriptionIds) || count($orderedSubscriptionIds) < 2) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
    exit;
}

try {
    wallos_update_subscription_sort_order($db, $userId, $orderedSubscriptionIds);

    echo json_encode([
        'success' => true,
        'message' => translate('sort_order_saved', $i18n),
    ]);
} catch (Throwable $throwable) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
}
