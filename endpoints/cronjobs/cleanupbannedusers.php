<?php

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/user_status.php';
require_once __DIR__ . '/../../includes/user_data_cleanup.php';

$query = "
    SELECT id
    FROM user
    WHERE account_status = :status
      AND TRIM(scheduled_delete_at) != ''
      AND scheduled_delete_at <= datetime('now')
    ORDER BY scheduled_delete_at ASC
";

$stmt = $db->prepare($query);
$stmt->bindValue(':status', WALLOS_USER_STATUS_TRASHED, SQLITE3_TEXT);
$result = $stmt->execute();

$deletedCount = 0;
$basePath = __DIR__ . '/../../';

while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
    try {
        wallos_delete_user_data($db, (int) $row['id'], $basePath);
        $deletedCount++;
    } catch (Throwable $throwable) {
        echo "Failed to permanently delete banned user id " . (int) $row['id'] . ": " . $throwable->getMessage() . "\n";
    }
}

echo "Expired banned users permanently deleted: {$deletedCount}\n";

$db->close();
