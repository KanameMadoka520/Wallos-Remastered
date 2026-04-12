<?php

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/subscription_trash.php';

$query = '
    SELECT id, user_id
    FROM subscriptions
    WHERE lifecycle_status = :lifecycle_status
      AND TRIM(scheduled_delete_at) != ""
      AND scheduled_delete_at <= datetime("now")
    ORDER BY scheduled_delete_at ASC
';

$stmt = $db->prepare($query);
$stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_TRASHED, SQLITE3_TEXT);
$result = $stmt->execute();

$deletedCount = 0;
$basePath = __DIR__ . '/../../';

while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
    try {
        wallos_delete_subscription_data($db, (int) $row['id'], (int) $row['user_id'], $basePath);
        $deletedCount++;
    } catch (Throwable $throwable) {
        echo "Failed to permanently delete trashed subscription id " . (int) $row['id'] . ": " . $throwable->getMessage() . "\n";
    }
}

echo "Expired trashed subscriptions permanently deleted: {$deletedCount}\n";

$db->close();
