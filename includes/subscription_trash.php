<?php

define('WALLOS_SUBSCRIPTION_STATUS_ACTIVE', 'active');
define('WALLOS_SUBSCRIPTION_STATUS_TRASHED', 'trashed');

function wallos_is_subscription_trashed($status)
{
    return trim((string) $status) === WALLOS_SUBSCRIPTION_STATUS_TRASHED;
}

function wallos_calculate_subscription_scheduled_delete_at($trashedAt = null)
{
    $timestamp = $trashedAt ? strtotime((string) $trashedAt) : time();
    if ($timestamp === false) {
        $timestamp = time();
    }

    return date('Y-m-d H:i:s', strtotime('+180 days', $timestamp));
}

function wallos_trash_subscription($db, $subscriptionId, $userId)
{
    $trashedAt = date('Y-m-d H:i:s');
    $scheduledDeleteAt = wallos_calculate_subscription_scheduled_delete_at($trashedAt);

    $db->exec('BEGIN IMMEDIATE');

    try {
        $stmt = $db->prepare('
            UPDATE subscriptions
            SET lifecycle_status = :lifecycle_status,
                trashed_at = :trashed_at,
                scheduled_delete_at = :scheduled_delete_at
            WHERE id = :id AND user_id = :user_id
        ');
        $stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_TRASHED, SQLITE3_TEXT);
        $stmt->bindValue(':trashed_at', $trashedAt, SQLITE3_TEXT);
        $stmt->bindValue(':scheduled_delete_at', $scheduledDeleteAt, SQLITE3_TEXT);
        $stmt->bindValue(':id', (int) $subscriptionId, SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
        $stmt->execute();

        $clearReplacementStmt = $db->prepare('
            UPDATE subscriptions
            SET replacement_subscription_id = NULL
            WHERE replacement_subscription_id = :subscription_id AND user_id = :user_id
        ');
        $clearReplacementStmt->bindValue(':subscription_id', (int) $subscriptionId, SQLITE3_INTEGER);
        $clearReplacementStmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
        $clearReplacementStmt->execute();

        $db->exec('COMMIT');
    } catch (Throwable $throwable) {
        $db->exec('ROLLBACK');
        throw $throwable;
    }
}

function wallos_restore_subscription($db, $subscriptionId, $userId)
{
    $stmt = $db->prepare('
        UPDATE subscriptions
        SET lifecycle_status = :lifecycle_status,
            trashed_at = "",
            scheduled_delete_at = ""
        WHERE id = :id AND user_id = :user_id
    ');
    $stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
    $stmt->bindValue(':id', (int) $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    $stmt->execute();
}

function wallos_delete_subscription_data($db, $subscriptionId, $userId, $basePath)
{
    $subscriptionId = (int) $subscriptionId;
    $userId = (int) $userId;

    $clearReplacementStmt = $db->prepare('
        UPDATE subscriptions
        SET replacement_subscription_id = NULL
        WHERE replacement_subscription_id = :subscription_id AND user_id = :user_id
    ');
    $clearReplacementStmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $clearReplacementStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $clearReplacementStmt->execute();

    $deletePaymentsStmt = $db->prepare('DELETE FROM subscription_payment_records WHERE subscription_id = :subscription_id AND user_id = :user_id');
    $deletePaymentsStmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $deletePaymentsStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $deletePaymentsStmt->execute();

    $deleteRulesStmt = $db->prepare('DELETE FROM subscription_price_rules WHERE subscription_id = :subscription_id AND user_id = :user_id');
    $deleteRulesStmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $deleteRulesStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $deleteRulesStmt->execute();

    wallos_delete_subscription_uploaded_images_for_subscription($db, $basePath, $subscriptionId, $userId);

    $deleteStmt = $db->prepare('DELETE FROM subscriptions WHERE id = :subscription_id AND user_id = :user_id');
    $deleteStmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $deleteStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $deleteStmt->execute();
}
