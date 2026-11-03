<?php
/** Additive, account-owned subscription relations. Scalars remain primary references. */
function wallos_subscription_relation_config($kind)
{
    if ($kind === 'payer') return ['subscription_payers', 'payer_id', 'household', 'payer_user_id', 'payer_ids'];
    if ($kind === 'category') return ['subscription_categories', 'category_id', 'categories', 'category_id', 'category_ids'];
    throw new InvalidArgumentException('Unknown subscription relation');
}

function wallos_subscription_relation_ids($db, array $subscription, $kind)
{
    [$table, $column, $references, $scalar] = wallos_subscription_relation_config($kind);
    $ids = [];
    if ((int) ($subscription[$scalar] ?? 0) > 0) $ids[] = (int) $subscription[$scalar];
    // Old databases are readable while migrations/bootstrap inspect their data.
    if (!$db || !$db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='$table'")) return $ids;
    $stmt = $db->prepare("SELECT r.$column AS id FROM $table r JOIN subscriptions s ON s.id=r.subscription_id JOIN $references ref ON ref.id=r.$column AND ref.user_id=s.user_id WHERE r.subscription_id=:id ORDER BY r.position, r.$column");
    $stmt->bindValue(':id', (int) ($subscription['id'] ?? 0), SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $id = (int) $row['id'];
        if (!in_array($id, $ids, true)) $ids[] = $id;
    }
    return $ids;
}

function wallos_subscription_relation_validate($db, $userId, $kind, $input)
{
    [, , $references] = wallos_subscription_relation_config($kind);
    if (!is_array($input) || !$input || count($input) > 100) throw new InvalidArgumentException('Select between 1 and 100 references');
    $ids = [];
    $stmt = $db->prepare("SELECT 1 FROM $references WHERE id=:id AND user_id=:user");
    foreach ($input as $value) {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)) throw new InvalidArgumentException('Invalid reference ID');
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new InvalidArgumentException('Invalid reference ID');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->bindValue(':user', (int) $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        if (!$result || !$result->fetchArray(SQLITE3_NUM)) throw new InvalidArgumentException('Reference does not belong to this account');
        if (!in_array($id, $ids, true)) $ids[] = $id;
    }
    return $ids;
}

/** Explicit array replaces the list; a legacy scalar promotes a primary, preserving other members. */
function wallos_subscription_relation_input($db, $userId, $kind, array $input, $existing = null)
{
    [, , , $scalar, $array] = wallos_subscription_relation_config($kind);
    if (array_key_exists($array, $input)) {
        $ids = wallos_subscription_relation_validate($db, $userId, $kind, $input[$array]);
        if (array_key_exists($scalar, $input) && (int) $input[$scalar] !== $ids[0]) throw new InvalidArgumentException('Primary reference must match the first list entry');
        return $ids;
    }
    $ids = $existing ? wallos_subscription_relation_ids($db, $existing, $kind) : [];
    if (array_key_exists($scalar, $input)) {
        $primary = wallos_subscription_relation_validate($db, $userId, $kind, [$input[$scalar]])[0];
        $ids = array_values(array_unique(array_merge([$primary], $ids)));
    }
    return wallos_subscription_relation_validate($db, $userId, $kind, $ids);
}

/** Caller owns transaction. Validate all relations before touching any list. */
function wallos_save_subscription_relations($db, $subscriptionId, array $payerIds, array $categoryIds)
{
    $stmt = $db->prepare('SELECT * FROM subscriptions WHERE id=:id');
    $stmt->bindValue(':id', (int) $subscriptionId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) throw new InvalidArgumentException('Subscription not found');
    $lists = [
        'payer' => wallos_subscription_relation_validate($db, $row['user_id'], 'payer', $payerIds),
        'category' => wallos_subscription_relation_validate($db, $row['user_id'], 'category', $categoryIds),
    ];
    foreach ($lists as $kind => $ids) {
        [$table, $column, , $scalar] = wallos_subscription_relation_config($kind);
        $stmt = $db->prepare("DELETE FROM $table WHERE subscription_id=:id");
        $stmt->bindValue(':id', (int) $subscriptionId, SQLITE3_INTEGER);
        if (!$stmt->execute()) throw new RuntimeException('Failed to save subscription relations');
        $stmt = $db->prepare("INSERT INTO $table(subscription_id,$column,position) VALUES(:id,:ref,:position)");
        foreach ($ids as $position => $id) {
            $stmt->bindValue(':id', (int) $subscriptionId, SQLITE3_INTEGER);
            $stmt->bindValue(':ref', $id, SQLITE3_INTEGER);
            $stmt->bindValue(':position', $position, SQLITE3_INTEGER);
            if (!$stmt->execute()) throw new RuntimeException('Failed to save subscription relations');
        }
        $stmt = $db->prepare("UPDATE subscriptions SET $scalar=:ref WHERE id=:id");
        $stmt->bindValue(':id', (int) $subscriptionId, SQLITE3_INTEGER);
        $stmt->bindValue(':ref', $ids[0], SQLITE3_INTEGER);
        if (!$stmt->execute()) throw new RuntimeException('Failed to save subscription primary');
    }
}

function wallos_subscription_with_relations($db, array $subscription)
{
    $subscription['payer_ids'] = wallos_subscription_relation_ids($db, $subscription, 'payer');
    $subscription['category_ids'] = wallos_subscription_relation_ids($db, $subscription, 'category');
    return $subscription;
}

function wallos_subscription_relation_names($db, array $subscription, $kind, array $lookup)
{
    return implode(', ', array_map(static function ($id) use ($lookup) { return (string) ($lookup[$id]['name'] ?? ''); }, wallos_subscription_relation_ids($db, $subscription, $kind)));
}

/** Current assignment share in a filtered statistics view; never changes a ledger row. */
function wallos_subscription_relation_filter_share($db, array $subscription, array $filters)
{
    $share = 1.0;
    foreach (['payer' => 'member', 'category' => 'category'] as $kind => $filter) {
        if (!isset($filters[$filter])) continue;
        $ids = wallos_subscription_relation_ids($db, $subscription, $kind);
        $share *= in_array((int) $filters[$filter], $ids, true) ? 1 / max(1, count($ids)) : 0;
    }
    return $share;
}
