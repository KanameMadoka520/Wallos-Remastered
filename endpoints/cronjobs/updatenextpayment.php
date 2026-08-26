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
$transactionStarted = false;
$db->enableExceptions(true);

try {
    // Reserve the writer before reading due rows. Date updates and the daily
    // completion marker must either all commit or all roll back.
    $db->exec('BEGIN IMMEDIATE');
    $transactionStarted = true;

    $query = "SELECT id, start_date, next_payment, frequency, cycle FROM subscriptions WHERE next_payment < :currentDate AND auto_renew = 1 AND inactive = 0 AND cycle != 5 AND lifecycle_status = :lifecycle_status";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':currentDate', $currentDateString, SQLITE3_TEXT);
    $stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
    $result = $stmt->execute();

    $pendingUpdates = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $nextPaymentDate = wallos_calendar_advance_subscription_next_payment(
            $row,
            $currentDate->format('Y-m-d H:i:s')
        );
        if ($nextPaymentDate === false) {
            throw new RuntimeException(
                'Invalid recurring schedule for subscription ' . (int) ($row['id'] ?? 0) . '.'
            );
        }

        $pendingUpdates[] = [
            'id' => (int) $row['id'],
            'next_payment' => $nextPaymentDate,
        ];
    }
    $result->finalize();

    $updateStmt = $db->prepare(
        'UPDATE subscriptions SET next_payment = :next_payment WHERE id = :subscription_id'
    );
    foreach ($pendingUpdates as $pendingUpdate) {
        $updateStmt->bindValue(':next_payment', $pendingUpdate['next_payment'], SQLITE3_TEXT);
        $updateStmt->bindValue(':subscription_id', $pendingUpdate['id'], SQLITE3_INTEGER);
        $updateStmt->execute();
    }

    $db->exec('DELETE FROM last_update_next_payment_date');
    $markerStmt = $db->prepare('INSERT INTO last_update_next_payment_date (date) VALUES (:date)');
    $markerStmt->bindValue(':date', $currentDateString, SQLITE3_TEXT);
    $markerStmt->execute();

    $db->exec('COMMIT');
    $transactionStarted = false;
    echo 'Updated next payment dates';
} catch (Throwable $throwable) {
    if ($transactionStarted) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $rollbackError) {
            // Preserve the original error; it identifies the failed operation.
        }
    }

    fwrite(STDERR, 'Failed to update next payment dates: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
?>
