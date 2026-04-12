<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/subscription_trash.php';

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);

$subscriptionId = (int) ($data['id'] ?? 0);

if ($subscriptionId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
    exit;
}

try {
    $checkStmt = $db->prepare('SELECT id FROM subscriptions WHERE id = :id AND user_id = :user_id AND lifecycle_status = :lifecycle_status');
    $checkStmt->bindValue(':id', $subscriptionId, SQLITE3_INTEGER);
    $checkStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $checkStmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_TRASHED, SQLITE3_TEXT);
    $checkResult = $checkStmt->execute();
    if (!$checkResult || $checkResult->fetchArray(SQLITE3_ASSOC) === false) {
        throw new RuntimeException('Subscription not found.');
    }

    wallos_delete_subscription_data($db, $subscriptionId, $userId, __DIR__ . '/../../');
    echo json_encode([
        'success' => true,
        'message' => translate('subscription_permanently_deleted', $i18n),
    ]);
} catch (Throwable $throwable) {
    echo json_encode([
        'success' => false,
        'message' => translate('error_deleting_subscription', $i18n),
    ]);
}

$db->close();
