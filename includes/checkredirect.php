<?php

require_once __DIR__ . '/subscription_trash.php';

$currentPage = basename($_SERVER['PHP_SELF']);
if ($currentPage == 'index.php') {
    // Redirect to subscriptions page if no subscriptions exist
    $stmt = $db->prepare("SELECT COUNT(*) FROM subscriptions WHERE user_id = :userId AND lifecycle_status = :lifecycle_status");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_NUM);
    $subscriptionCount = $row[0];

    if ($subscriptionCount === 0) {
        header('Location: subscriptions.php');
        exit;
    }
}
