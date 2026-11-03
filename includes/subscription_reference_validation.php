<?php

/** Account ownership check shared by browser saves and their regression tests. */
function wallos_subscription_invalid_reference($db, $userId, array $references, array $priceRules = [])
{
    $tables = [
        'currency_id' => 'currencies',
        'category_id' => 'categories',
        'payer_user_id' => 'household',
        'payment_method_id' => 'payment_methods',
    ];
    foreach ($tables as $field => $table) {
        $value = $references[$field] ?? null;
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return $field;
        }
        $stmt = $db->prepare("SELECT 1 FROM $table WHERE id = :id AND user_id = :user_id");
        $stmt->bindValue(':id', (int) $value, SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        if (!$result || !$result->fetchArray(SQLITE3_NUM)) {
            return $field;
        }
    }
    if (!empty($references['replacement_subscription_id'])) {
        $replacementId = filter_var($references['replacement_subscription_id'], FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]);
        if ($replacementId === false || $replacementId === (int) ($references['id'] ?? 0)) {
            return 'replacement_subscription_id';
        }
        $stmt = $db->prepare("SELECT 1 FROM subscriptions WHERE id = :id AND user_id = :user_id AND lifecycle_status = 'active'");
        $stmt->bindValue(':id', $replacementId, SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        if (!$result || !$result->fetchArray(SQLITE3_NUM)) {
            return 'replacement_subscription_id';
        }
    }
    foreach ($priceRules as $rule) {
        $stmt = $db->prepare('SELECT 1 FROM currencies WHERE id = :id AND user_id = :user_id');
        $stmt->bindValue(':id', (int) ($rule['currency_id'] ?? 0), SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        if (!$result || !$result->fetchArray(SQLITE3_NUM)) {
            return 'subscription_price_rules.currency_id';
        }
    }
    return null;
}
