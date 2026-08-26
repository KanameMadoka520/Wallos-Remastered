<?php

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/subscription_trash.php';
require_once __DIR__ . '/../../includes/calendar_calculations.php';

require 'settimezone.php';

$date = new DateTime('now');
echo "\n" . $date->format('Y-m-d') . " " . $date->format('H:i:s') . "<br />\n";
echo $timezone . "<br />\n";

$currentDate = new DateTime();
$currentDateString = $currentDate->format('Y-m-d');

$query = "SELECT id, start_date, next_payment, frequency, cycle FROM subscriptions WHERE next_payment < :currentDate AND auto_renew = 1 AND inactive = 0 AND cycle != 5 AND lifecycle_status = :lifecycle_status";
$stmt = $db->prepare($query);
$stmt->bindValue(':currentDate', $currentDate->format('Y-m-d'));
$stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
$result = $stmt->execute();

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $subscriptionId = $row['id'];
    $nextPaymentDate = wallos_calendar_advance_subscription_next_payment(
        $row,
        $currentDate->format('Y-m-d H:i:s')
    );
    if ($nextPaymentDate === false) {
        continue;
    }

    // Update the subscription's next_payment date
    $updateQuery = "UPDATE subscriptions SET next_payment = :nextPaymentDate WHERE id = :subscriptionId";
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->bindValue(':nextPaymentDate', $nextPaymentDate, SQLITE3_TEXT);
    $updateStmt->bindValue(':subscriptionId', $subscriptionId);
    $updateStmt->execute();
}

$formattedDate = $currentDate->format('Y-m-d');

$deleteQuery = "DELETE FROM last_update_next_payment_date";
$deleteStmt = $db->prepare($deleteQuery);
$deleteResult = $deleteStmt->execute();

$query = "INSERT INTO last_update_next_payment_date (date) VALUES (:formattedDate)";
$stmt = $db->prepare($query);
$stmt->bindParam(':formattedDate', $currentDateString, SQLITE3_TEXT);
$result = $stmt->execute();

echo "Updated next payment dates";
?>
