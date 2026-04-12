<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/subscription_trash.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$currentDate = new DateTime();
$currentDateString = $currentDate->format('Y-m-d');

$cycles = array();
$query = "SELECT * FROM cycles";
$result = $db->query($query);
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $cycleId = $row['id'];
    $cycles[$cycleId] = $row;
}

$subscriptionId = $data["id"];
$query = "SELECT * FROM subscriptions WHERE id = :id AND user_id = :user_id AND auto_renew = 0 AND lifecycle_status = :lifecycle_status";
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

$nextPaymentDate = new DateTime($subscriptionToRenew['next_payment']);
$frequency = $subscriptionToRenew['frequency'];
$cycle = $cycles[$subscriptionToRenew['cycle']]['name'];

// Calculate the interval to add based on the cycle
$intervalSpec = "P";
if ($cycle == 'Daily') {
    $intervalSpec .= "{$frequency}D";
} elseif ($cycle === 'Weekly') {
    $intervalSpec .= "{$frequency}W";
} elseif ($cycle === 'Monthly') {
    $intervalSpec .= "{$frequency}M";
} elseif ($cycle === 'Yearly') {
    $intervalSpec .= "{$frequency}Y";
}

$interval = new DateInterval($intervalSpec);

// Add intervals until the next payment date is in the future and after current next payment date
while ($nextPaymentDate < $currentDate || $nextPaymentDate == new DateTime($subscriptionToRenew['next_payment'])) {
    $nextPaymentDate->add($interval);
}

// Update the subscription's next_payment date
$updateQuery = "UPDATE subscriptions SET next_payment = :nextPaymentDate WHERE id = :subscriptionId";
$updateStmt = $db->prepare($updateQuery);
$updateStmt->bindValue(':nextPaymentDate', $nextPaymentDate->format('Y-m-d'));
$updateStmt->bindValue(':subscriptionId', $subscriptionId);
$updateStmt->execute();

if ($updateStmt->execute()) {
    $response = [
        "success" => true,
        "message" => translate('success', $i18n),
        "id" => $subscriptionId
    ];
    echo json_encode($response);
} else {
    die(json_encode([
        "success" => false,
        "message" => translate("error", $i18n)
    ]));
}
