<?php

require_once __DIR__ . '/currency_formatter.php';

function wallos_get_subscription_cycle_interval_spec($cycleId, $frequency)
{
    $frequency = max(1, (int) $frequency);
    $cycleId = (int) $cycleId;

    if ($cycleId === 1) {
        return 'P' . $frequency . 'D';
    }
    if ($cycleId === 2) {
        return 'P' . $frequency . 'W';
    }
    if ($cycleId === 3) {
        return 'P' . $frequency . 'M';
    }

    return 'P' . $frequency . 'Y';
}

function wallos_is_valid_local_date($value)
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $value)) === 1;
}

function wallos_get_allowed_subscription_price_rule_types()
{
    return ['first_n_cycles', 'date_range', 'one_time'];
}

function wallos_normalize_subscription_price_rule($rule, $defaultCurrencyId, $priority = 1)
{
    if (!is_array($rule)) {
        return null;
    }

    $ruleType = trim((string) ($rule['rule_type'] ?? ''));
    if (!in_array($ruleType, wallos_get_allowed_subscription_price_rule_types(), true)) {
        return null;
    }

    $price = (float) ($rule['price'] ?? 0);
    if ($price <= 0) {
        return null;
    }

    $currencyId = (int) ($rule['currency_id'] ?? 0);
    if ($currencyId <= 0) {
        $currencyId = (int) $defaultCurrencyId;
    }

    $startDate = trim((string) ($rule['start_date'] ?? ''));
    $endDate = trim((string) ($rule['end_date'] ?? ''));
    $maxCycles = max(0, (int) ($rule['max_cycles'] ?? 0));

    if ($ruleType === 'one_time') {
        if (!wallos_is_valid_local_date($startDate)) {
            return null;
        }
        $endDate = '';
        $maxCycles = 0;
    } elseif ($ruleType === 'date_range') {
        if ($startDate !== '' && !wallos_is_valid_local_date($startDate)) {
            return null;
        }
        if ($endDate !== '' && !wallos_is_valid_local_date($endDate)) {
            return null;
        }
        if ($startDate === '' && $endDate === '') {
            return null;
        }
        $maxCycles = 0;
    } elseif ($ruleType === 'first_n_cycles') {
        if ($maxCycles < 1) {
            return null;
        }
        $startDate = '';
        $endDate = '';
    }

    return [
        'id' => (int) ($rule['id'] ?? 0),
        'rule_type' => $ruleType,
        'price' => round($price, 2),
        'currency_id' => $currencyId,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'max_cycles' => $maxCycles,
        'priority' => max(1, (int) ($rule['priority'] ?? $priority)),
        'note' => trim((string) ($rule['note'] ?? '')),
        'enabled' => !isset($rule['enabled']) || (int) $rule['enabled'] === 1 || $rule['enabled'] === true,
    ];
}

function wallos_decode_subscription_price_rules_input($rawJson, $defaultCurrencyId)
{
    $decoded = json_decode((string) $rawJson, true);
    if (!is_array($decoded)) {
        return [];
    }

    $rules = [];
    $priority = 1;
    foreach ($decoded as $rule) {
        $normalized = wallos_normalize_subscription_price_rule($rule, $defaultCurrencyId, $priority);
        if ($normalized === null) {
            continue;
        }

        $normalized['priority'] = $priority++;
        $rules[] = $normalized;
    }

    return $rules;
}

function wallos_get_subscription_price_rules($db, $subscriptionId, $userId, $enabledOnly = false)
{
    $sql = '
        SELECT id, subscription_id, user_id, rule_type, price, currency_id, start_date, end_date,
               max_cycles, priority, note, enabled, created_at
        FROM subscription_price_rules
        WHERE subscription_id = :subscription_id AND user_id = :user_id
    ';

    if ($enabledOnly) {
        $sql .= ' AND enabled = 1';
    }

    $sql .= ' ORDER BY priority ASC, id ASC';

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $rules = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $rules[] = $row;
    }

    return $rules;
}

function wallos_get_subscription_price_rules_map($db, $userId, $enabledOnly = false)
{
    $sql = '
        SELECT id, subscription_id, user_id, rule_type, price, currency_id, start_date, end_date,
               max_cycles, priority, note, enabled, created_at
        FROM subscription_price_rules
        WHERE user_id = :user_id
    ';

    if ($enabledOnly) {
        $sql .= ' AND enabled = 1';
    }

    $sql .= ' ORDER BY subscription_id ASC, priority ASC, id ASC';

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $rulesMap = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $subscriptionId = (int) ($row['subscription_id'] ?? 0);
        if ($subscriptionId <= 0) {
            continue;
        }

        if (!isset($rulesMap[$subscriptionId])) {
            $rulesMap[$subscriptionId] = [];
        }

        $rulesMap[$subscriptionId][] = $row;
    }

    return $rulesMap;
}

function wallos_replace_subscription_price_rules($db, $subscriptionId, $userId, array $rules)
{
    $deleteStmt = $db->prepare('DELETE FROM subscription_price_rules WHERE subscription_id = :subscription_id AND user_id = :user_id');
    $deleteStmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $deleteStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $deleteStmt->execute();

    if (empty($rules)) {
        return;
    }

    $insertStmt = $db->prepare('
        INSERT INTO subscription_price_rules (
            subscription_id, user_id, rule_type, price, currency_id, start_date, end_date,
            max_cycles, priority, note, enabled
        ) VALUES (
            :subscription_id, :user_id, :rule_type, :price, :currency_id, :start_date, :end_date,
            :max_cycles, :priority, :note, :enabled
        )
    ');

    foreach ($rules as $rule) {
        $insertStmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
        $insertStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $insertStmt->bindValue(':rule_type', $rule['rule_type'], SQLITE3_TEXT);
        $insertStmt->bindValue(':price', (float) $rule['price'], SQLITE3_FLOAT);
        $insertStmt->bindValue(':currency_id', (int) $rule['currency_id'], SQLITE3_INTEGER);
        $insertStmt->bindValue(':start_date', (string) $rule['start_date'], SQLITE3_TEXT);
        $insertStmt->bindValue(':end_date', (string) $rule['end_date'], SQLITE3_TEXT);
        $insertStmt->bindValue(':max_cycles', (int) $rule['max_cycles'], SQLITE3_INTEGER);
        $insertStmt->bindValue(':priority', (int) $rule['priority'], SQLITE3_INTEGER);
        $insertStmt->bindValue(':note', (string) $rule['note'], SQLITE3_TEXT);
        $insertStmt->bindValue(':enabled', !empty($rule['enabled']) ? 1 : 0, SQLITE3_INTEGER);
        $insertStmt->execute();
    }
}

function wallos_clone_subscription_price_rules($db, $sourceSubscriptionId, $targetSubscriptionId, $userId)
{
    $rules = wallos_get_subscription_price_rules($db, $sourceSubscriptionId, $userId, false);
    if (empty($rules)) {
        return;
    }

    $normalizedRules = [];
    foreach ($rules as $index => $rule) {
        $normalizedRules[] = [
            'rule_type' => $rule['rule_type'],
            'price' => $rule['price'],
            'currency_id' => $rule['currency_id'],
            'start_date' => $rule['start_date'],
            'end_date' => $rule['end_date'],
            'max_cycles' => $rule['max_cycles'],
            'priority' => $index + 1,
            'note' => $rule['note'],
            'enabled' => (int) ($rule['enabled'] ?? 1) === 1,
        ];
    }

    wallos_replace_subscription_price_rules($db, $targetSubscriptionId, $userId, $normalizedRules);
}

function wallos_delete_subscription_price_rules($db, $subscriptionId, $userId)
{
    $stmt = $db->prepare('DELETE FROM subscription_price_rules WHERE subscription_id = :subscription_id AND user_id = :user_id');
    $stmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}

function wallos_get_subscription_occurrence_index($subscription, $dueDate)
{
    $dueDateValue = trim((string) $dueDate);
    if (!wallos_is_valid_local_date($dueDateValue)) {
        return null;
    }

    $startDateValue = trim((string) ($subscription['start_date'] ?? ''));
    if (!wallos_is_valid_local_date($startDateValue)) {
        $nextPaymentValue = trim((string) ($subscription['next_payment'] ?? ''));
        if (wallos_is_valid_local_date($nextPaymentValue) && $nextPaymentValue === $dueDateValue) {
            return 1;
        }
        return null;
    }

    $startDate = new DateTime($startDateValue);
    $targetDate = new DateTime($dueDateValue);
    if ($targetDate < $startDate) {
        return null;
    }

    $interval = new DateInterval(wallos_get_subscription_cycle_interval_spec((int) ($subscription['cycle'] ?? 3), (int) ($subscription['frequency'] ?? 1)));
    $occurrenceDate = clone $startDate;
    $occurrenceIndex = 1;

    while ($occurrenceDate <= $targetDate && $occurrenceIndex <= 2400) {
        if ($occurrenceDate->format('Y-m-d') === $dueDateValue) {
            return $occurrenceIndex;
        }

        $occurrenceDate->add($interval);
        $occurrenceIndex++;
    }

    return null;
}

function wallos_subscription_price_rule_matches($rule, $subscription, $dueDate)
{
    $ruleType = trim((string) ($rule['rule_type'] ?? ''));
    if ((int) ($rule['enabled'] ?? 1) !== 1) {
        return false;
    }

    if ($ruleType === 'one_time') {
        return trim((string) ($rule['start_date'] ?? '')) === trim((string) $dueDate);
    }

    if ($ruleType === 'date_range') {
        $startDate = trim((string) ($rule['start_date'] ?? ''));
        $endDate = trim((string) ($rule['end_date'] ?? ''));
        $dueDateValue = trim((string) $dueDate);

        if ($startDate !== '' && $dueDateValue < $startDate) {
            return false;
        }
        if ($endDate !== '' && $dueDateValue > $endDate) {
            return false;
        }

        return true;
    }

    if ($ruleType === 'first_n_cycles') {
        $occurrenceIndex = wallos_get_subscription_occurrence_index($subscription, $dueDate);
        return $occurrenceIndex !== null && $occurrenceIndex <= max(0, (int) ($rule['max_cycles'] ?? 0));
    }

    return false;
}

function wallos_get_effective_subscription_price_for_due_date($subscription, array $rules, $dueDate, $db, $userId)
{
    $amountOriginal = (float) ($subscription['price'] ?? 0);
    $currencyId = (int) ($subscription['currency_id'] ?? 0);
    $matchedRule = null;

    foreach ($rules as $rule) {
        if (wallos_subscription_price_rule_matches($rule, $subscription, $dueDate)) {
            $amountOriginal = (float) ($rule['price'] ?? $amountOriginal);
            $currencyId = (int) ($rule['currency_id'] ?? $currencyId);
            $matchedRule = $rule;
            break;
        }
    }

    $currencySnapshot = null;
    if ($currencyId > 0) {
        try {
            $currencySnapshot = wallos_get_currency_snapshot($db, $userId, $currencyId);
        } catch (Throwable $throwable) {
            $currencySnapshot = null;
        }
    }

    $amountMain = $currencySnapshot ? wallos_convert_amount_to_main_snapshot($amountOriginal, $currencySnapshot['rate']) : $amountOriginal;

    return [
        'amount_original' => round($amountOriginal, 2),
        'currency_id' => $currencyId,
        'currency_code' => $currencySnapshot['code'] ?? '',
        'amount_main' => round($amountMain, 2),
        'matched_rule' => $matchedRule,
    ];
}

function wallos_format_subscription_price_rule_summary($rule, $currencies, $i18n)
{
    $currencyId = (int) ($rule['currency_id'] ?? 0);
    $currencyCode = $currencies[$currencyId]['code'] ?? '';
    $priceLabel = $currencyCode !== ''
        ? CurrencyFormatter::format((float) ($rule['price'] ?? 0), $currencyCode)
        : number_format((float) ($rule['price'] ?? 0), 2);

    $ruleType = trim((string) ($rule['rule_type'] ?? ''));
    if ($ruleType === 'one_time') {
        return sprintf(translate('subscription_price_rule_one_time_summary', $i18n), $priceLabel, (string) ($rule['start_date'] ?? ''));
    }
    if ($ruleType === 'date_range') {
        return sprintf(translate('subscription_price_rule_date_range_summary', $i18n), $priceLabel, (string) ($rule['start_date'] ?? '-'), (string) ($rule['end_date'] ?? '-'));
    }

    return sprintf(translate('subscription_price_rule_first_cycles_summary', $i18n), $priceLabel, (int) ($rule['max_cycles'] ?? 0));
}
