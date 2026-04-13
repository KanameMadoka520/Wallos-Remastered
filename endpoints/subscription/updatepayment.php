<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/subscription_payment_records.php';
require_once '../../includes/subscription_trash.php';

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);

$recordId = (int) ($data['record_id'] ?? 0);
$subscriptionId = (int) ($data['id'] ?? 0);
$dueDate = trim((string) ($data['due_date'] ?? ''));
$paidAt = trim((string) ($data['paid_at'] ?? ''));
$amountOriginal = (float) ($data['amount_original'] ?? 0);
$currencyId = (int) ($data['currency_id'] ?? 0);
$paymentMethodId = (int) ($data['payment_method_id'] ?? 0);
$note = trim((string) ($data['note'] ?? ''));

if ($recordId <= 0 || $subscriptionId <= 0 || $paidAt === '' || $amountOriginal <= 0) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
    exit;
}

try {
    wallos_update_subscription_payment_record(
        $db,
        $userId,
        $recordId,
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
        'message' => translate('subscription_payment_updated', $i18n),
    ]);
} catch (Throwable $throwable) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
}

$db->close();
