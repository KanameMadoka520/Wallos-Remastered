<?php

function wallos_get_next_subscription_sort_order($db, $userId)
{
    $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort_order FROM subscriptions WHERE user_id = :user_id');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    return max(1, (int) ($row['next_sort_order'] ?? 1));
}

function wallos_update_subscription_sort_order($db, $userId, array $orderedSubscriptionIds)
{
    $normalizedIds = array_values(array_unique(array_filter(array_map(function ($subscriptionId) {
        return (int) $subscriptionId;
    }, $orderedSubscriptionIds), function ($subscriptionId) {
        return $subscriptionId > 0;
    })));

    if (empty($normalizedIds)) {
        throw new RuntimeException('No valid subscription ids were provided.');
    }

    $placeholders = [];
    $selectStmt = $db->prepare(
        'SELECT id FROM subscriptions WHERE user_id = :user_id AND id IN (' .
        implode(', ', array_map(function ($index) use (&$placeholders) {
            $placeholder = ':subscription_id_' . $index;
            $placeholders[] = $placeholder;
            return $placeholder;
        }, array_keys($normalizedIds))) .
        ')'
    );
    $selectStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);

    foreach ($normalizedIds as $index => $subscriptionId) {
        $selectStmt->bindValue($placeholders[$index], $subscriptionId, SQLITE3_INTEGER);
    }

    $selectResult = $selectStmt->execute();
    $existingIds = [];
    while ($selectResult && ($row = $selectResult->fetchArray(SQLITE3_ASSOC))) {
        $existingIds[] = (int) $row['id'];
    }

    sort($existingIds);
    $expectedIds = $normalizedIds;
    sort($expectedIds);

    if ($existingIds !== $expectedIds) {
        throw new RuntimeException('One or more subscriptions do not belong to the current user.');
    }

    $db->exec('BEGIN IMMEDIATE');

    try {
        $updateStmt = $db->prepare('UPDATE subscriptions SET sort_order = :sort_order WHERE id = :id AND user_id = :user_id');
        $sortOrder = 1;

        foreach ($normalizedIds as $subscriptionId) {
            $updateStmt->bindValue(':sort_order', $sortOrder++, SQLITE3_INTEGER);
            $updateStmt->bindValue(':id', $subscriptionId, SQLITE3_INTEGER);
            $updateStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $updateStmt->execute();
        }

        $db->exec('COMMIT');
    } catch (Throwable $throwable) {
        $db->exec('ROLLBACK');
        throw $throwable;
    }
}
