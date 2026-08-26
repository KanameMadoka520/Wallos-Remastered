<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/subscription_trash.php';
require_once '../../includes/subscription_payment_records.php';
require_once '../../includes/calendar_calculations.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$currentDate = new DateTime();
$currentDateString = $currentDate->format('Y-m-d');

$subscriptionId = $data["id"];
$query = "SELECT * FROM subscriptions WHERE id = :id AND user_id = :user_id AND auto_renew = 0 AND cycle != 5 AND lifecycle_status = :lifecycle_status";
$stmt = $db->prepare($query);
$stmt->bindValue(':id', $subscriptionId, SQLITE3_INTEGER);
$stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
$result = $stmt->execute();
$subscriptionToRenew = $result->fetchArray(SQLITE3_ASSOC);
if ($subscriptionToRenew === false) {
    die(json_encode([
        "success" => false,
        "message" => translate("error", $i18n)
    ]));
}

$renewedDueDate = $subscriptionToRenew['next_payment'];
$nextPaymentDate = wallos_calendar_advance_subscription_next_payment(
    $subscriptionToRenew,
    $currentDate->format('Y-m-d H:i:s'),
    1
);
if ($nextPaymentDate === false) {
    die(json_encode([
        "success" => false,
        "message" => translate("error", $i18n)
    ]));
}

try {
    $db->exec('BEGIN IMMEDIATE');

    wallos_record_subscription_payment(
        $db,
        $userId,
        (int) $subscriptionId,
        $renewedDueDate,
        $currentDateString,
        (float) $subscriptionToRenew['price'],
        (int) $subscriptionToRenew['currency_id'],
        (int) $subscriptionToRenew['payment_method_id'],
        ''
    );

    $updateQuery = "UPDATE subscriptions SET next_payment = :nextPaymentDate WHERE id = :subscriptionId";
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->bindValue(':nextPaymentDate', $nextPaymentDate, SQLITE3_TEXT);
    $updateStmt->bindValue(':subscriptionId', $subscriptionId);

    if (!$updateStmt->execute()) {
        throw new RuntimeException('Failed to update next payment date.');
    }

    $db->exec('COMMIT');
    $response = [
        "success" => true,
        "message" => translate('success', $i18n),
        "id" => $subscriptionId
    ];
    echo json_encode($response);
} catch (Throwable $throwable) {
    wallos_database_emit_busy_response_if_needed($i18n, $throwable, $db ?? null);
    $db->exec('ROLLBACK');
    die(json_encode([
        "success" => false,
        "message" => translate("error", $i18n)
    ]));
}
