<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/subscription_trash.php';
require_once '../../includes/subscription_payment_records.php';
require_once '../../includes/subscription_price_rules.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);
$subscriptionId = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$expectedDueDate = $data['expected_due_date'] ?? null;
if (!$subscriptionId || !is_string($expectedDueDate) || !wallos_payment_record_is_valid_date($expectedDueDate)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => translate('subscription_renewal_schedule_changed', $i18n)]);
    exit;
}

$currentDateString = date('Y-m-d');
try {
    $renewed = wallos_payment_write_transaction($db, function () use ($db, $userId, $subscriptionId, $expectedDueDate, $currentDateString) {
        $stmt = $db->prepare('SELECT * FROM subscriptions WHERE id = :id AND user_id = :user_id
            AND auto_renew = 0 AND inactive = 0 AND cycle != 5 AND lifecycle_status = :lifecycle_status');
        $stmt->bindValue(':id', $subscriptionId, SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
        $subscriptionToRenew = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($subscriptionToRenew === false || $subscriptionToRenew['next_payment'] !== $expectedDueDate) {
            return false;
        }

        $nextPaymentDate = wallos_calendar_advance_subscription_next_payment($subscriptionToRenew, $currentDateString, 1);
        if ($nextPaymentDate === false) {
            throw new RuntimeException('Could not calculate the next payment date.');
        }

        if (!wallos_get_subscription_payment_record_by_due_date($db, $subscriptionId, $userId, $expectedDueDate)) {
            $rules = wallos_get_subscription_price_rules($db, $subscriptionId, $userId, true);
            $price = wallos_get_effective_subscription_price_for_due_date($subscriptionToRenew, $rules, $expectedDueDate, $db, $userId);
            if ($price['amount_original'] > 0) {
                wallos_record_subscription_payment(
                    $db, $userId, $subscriptionId, $expectedDueDate, $currentDateString,
                    $price['amount_original'], $price['currency_id'], (int) $subscriptionToRenew['payment_method_id']
                );
            }
        }

        $updateStmt = $db->prepare('UPDATE subscriptions SET next_payment = :next_payment WHERE id = :id AND user_id = :user_id');
        $updateStmt->bindValue(':next_payment', $nextPaymentDate, SQLITE3_TEXT);
        $updateStmt->bindValue(':id', $subscriptionId, SQLITE3_INTEGER);
        $updateStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $updateStmt->execute();
        return true;
    });
    if (!$renewed) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => translate('subscription_renewal_schedule_changed', $i18n)]);
        exit;
    }
    echo json_encode(['success' => true, 'message' => translate('success', $i18n), 'id' => $subscriptionId]);
} catch (Throwable $throwable) {
    wallos_database_emit_busy_response_if_needed($i18n, $throwable, $db);
    echo json_encode(['success' => false, 'message' => translate('error', $i18n)]);
}
