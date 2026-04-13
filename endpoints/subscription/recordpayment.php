<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/subscription_payment_records.php';
require_once '../../includes/subscription_trash.php';

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);

$subscriptionId = (int) ($data['id'] ?? 0);
$dueDate = trim((string) ($data['due_date'] ?? ''));
$paidAt = trim((string) ($data['paid_at'] ?? ''));
$amountOriginal = (float) ($data['amount_original'] ?? 0);
$currencyId = (int) ($data['currency_id'] ?? 0);
$paymentMethodId = (int) ($data['payment_method_id'] ?? 0);
$note = trim((string) ($data['note'] ?? ''));

if ($subscriptionId <= 0 || $paidAt === '' || $amountOriginal <= 0) {
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
    $checkStmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
    $checkResult = $checkStmt->execute();
    if (!$checkResult || $checkResult->fetchArray(SQLITE3_ASSOC) === false) {
        throw new RuntimeException('Subscription not found.');
    }

    $recordId = wallos_record_subscription_payment(
        $db,
        $userId,
        $subscriptionId,
        $dueDate,
        $paidAt,
        $amountOriginal,
        $currencyId,
        $paymentMethodId,
        $note
    );

    wallos_recalculate_subscription_next_payment_from_history($db, $subscriptionId, $userId);

    echo json_encode([
        'success' => true,
        'message' => translate('subscription_payment_recorded', $i18n),
        'record_id' => $recordId,
    ]);
} catch (Throwable $throwable) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
}

$db->close();
