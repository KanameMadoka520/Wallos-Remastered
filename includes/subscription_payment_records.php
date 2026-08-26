<?php

require_once __DIR__ . '/calendar_calculations.php';

function wallos_payment_record_is_valid_date($value)
{
    $value = trim((string) $value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1
        && wallos_calendar_parse_date($value) !== false;
}

function wallos_get_currency_snapshot($db, $userId, $currencyId)
{
    $stmt = $db->prepare('SELECT code, rate FROM currencies WHERE id = :currency_id AND user_id = :user_id');
    $stmt->bindValue(':currency_id', $currencyId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    if ($row === false) {
        throw new RuntimeException('Currency not found.');
    }

    return [
        'code' => (string) ($row['code'] ?? ''),
        'rate' => (float) ($row['rate'] ?? 1),
    ];
}

function wallos_get_main_currency_snapshot($db, $userId)
{
    $stmt = $db->prepare('
        SELECT currencies.code
        FROM user
        INNER JOIN currencies ON currencies.id = user.main_currency
        WHERE user.id = :user_id
    ');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    if ($row === false) {
        throw new RuntimeException('Main currency not found.');
    }

    return (string) ($row['code'] ?? '');
}

function wallos_convert_amount_to_main_snapshot($amountOriginal, $rateToMain)
{
    $amountOriginal = (float) $amountOriginal;
    $rateToMain = (float) $rateToMain;

    if ($rateToMain <= 0) {
        $rateToMain = 1.0;
    }

    return $amountOriginal / $rateToMain;
}

function wallos_payment_main_conversion_is_available($currencyCode, $mainCurrencyCode, $rateToMain)
{
    $currencyCode = strtoupper(trim((string) $currencyCode));
    $mainCurrencyCode = strtoupper(trim((string) $mainCurrencyCode));

    if ($currencyCode === '' || $mainCurrencyCode === '' || $currencyCode === $mainCurrencyCode) {
        return true;
    }

    $rateToMain = (float) $rateToMain;
    if ($rateToMain <= 0) {
        return false;
    }

    return abs($rateToMain - 1.0) > 0.000001;
}

function wallos_build_single_currency_original_total(array $items, $amountKey, $currencyKey)
{
    $total = 0.0;
    $currencyCode = '';
    $hasItems = false;
    $mixedCurrency = false;

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $itemCurrencyCode = strtoupper(trim((string) ($item[$currencyKey] ?? '')));
        if ($itemCurrencyCode === '') {
            continue;
        }

        if ($currencyCode === '') {
            $currencyCode = $itemCurrencyCode;
        } else if ($currencyCode !== $itemCurrencyCode) {
            $mixedCurrency = true;
        }

        $total += (float) ($item[$amountKey] ?? 0);
        $hasItems = true;
    }

    return [
        'available' => $hasItems && !$mixedCurrency && $currencyCode !== '',
        'amount' => round($total, 2),
        'currency_code' => $currencyCode,
        'mixed_currency' => $mixedCurrency,
    ];
}

function wallos_all_payment_main_conversions_are_available(array $items)
{
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        if (array_key_exists('main_currency_conversion_available', $item)
            && empty($item['main_currency_conversion_available'])
        ) {
            return false;
        }
    }

    return true;
}

function wallos_enrich_payment_record_conversion_status(array $record)
{
    $record['main_currency_conversion_available'] = wallos_payment_main_conversion_is_available(
        $record['currency_code_snapshot'] ?? '',
        $record['main_currency_code_snapshot'] ?? '',
        $record['fx_rate_to_main_snapshot'] ?? 0
    );

    return $record;
}

function wallos_get_subscription_payment_records($db, $subscriptionId, $userId, $limit = 10)
{
    $sql = '
        SELECT id, subscription_id, due_date, paid_at, amount_original, currency_id, currency_code_snapshot,
               main_currency_code_snapshot, fx_rate_to_main_snapshot, amount_main_snapshot, payment_method_id, status, note, created_at
        FROM subscription_payment_records
        WHERE subscription_id = :subscription_id AND user_id = :user_id
        ORDER BY paid_at DESC, id DESC
    ';
    if ((int) $limit > 0) {
        $sql .= ' LIMIT :limit_value';
    }

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    if ((int) $limit > 0) {
        $stmt->bindValue(':limit_value', max(1, (int) $limit), SQLITE3_INTEGER);
    }
    $result = $stmt->execute();

    $records = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $records[] = wallos_enrich_payment_record_conversion_status($row);
    }

    return $records;
}

function wallos_get_subscription_payment_record_by_id($db, $recordId, $subscriptionId, $userId)
{
    $stmt = $db->prepare('
        SELECT id, subscription_id, due_date, paid_at, amount_original, currency_id, currency_code_snapshot,
               main_currency_code_snapshot, fx_rate_to_main_snapshot, amount_main_snapshot, payment_method_id, status, note, created_at
        FROM subscription_payment_records
        WHERE id = :id AND subscription_id = :subscription_id AND user_id = :user_id
        LIMIT 1
    ');
    $stmt->bindValue(':id', $recordId, SQLITE3_INTEGER);
    $stmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $record = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    return $record === false ? false : wallos_enrich_payment_record_conversion_status($record);
}

function wallos_get_subscription_payment_record_by_due_date($db, $subscriptionId, $userId, $dueDate)
{
    $stmt = $db->prepare('
        SELECT id, subscription_id, due_date, paid_at, amount_original, currency_id, currency_code_snapshot,
               main_currency_code_snapshot, fx_rate_to_main_snapshot, amount_main_snapshot, payment_method_id, status, note, created_at
        FROM subscription_payment_records
        WHERE subscription_id = :subscription_id AND user_id = :user_id AND due_date = :due_date AND status = :status
        ORDER BY paid_at DESC, id DESC
        LIMIT 1
    ');
    $stmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':due_date', $dueDate, SQLITE3_TEXT);
    $stmt->bindValue(':status', 'paid', SQLITE3_TEXT);
    $result = $stmt->execute();

    $record = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    return $record === false ? false : wallos_enrich_payment_record_conversion_status($record);
}

function wallos_get_subscription_payment_records_map($db, $userId, $limitPerSubscription = 6)
{
    $stmt = $db->prepare('
        SELECT id, subscription_id, due_date, paid_at, amount_original, currency_code_snapshot,
               main_currency_code_snapshot, fx_rate_to_main_snapshot, amount_main_snapshot, payment_method_id, status, note, created_at
        FROM subscription_payment_records
        WHERE user_id = :user_id
        ORDER BY subscription_id ASC, paid_at DESC, id DESC
    ');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $recordsMap = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $subscriptionId = (int) ($row['subscription_id'] ?? 0);
        if ($subscriptionId <= 0) {
            continue;
        }

        if (!isset($recordsMap[$subscriptionId])) {
            $recordsMap[$subscriptionId] = [];
        }

        if (count($recordsMap[$subscriptionId]) >= $limitPerSubscription) {
            continue;
        }

        $recordsMap[$subscriptionId][] = wallos_enrich_payment_record_conversion_status($row);
    }

    return $recordsMap;
}

function wallos_get_subscription_payment_record_count_map($db, $userId)
{
    $stmt = $db->prepare('
        SELECT subscription_id, COUNT(*) AS record_count
        FROM subscription_payment_records
        WHERE user_id = :user_id
        GROUP BY subscription_id
    ');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $counts = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $counts[(int) ($row['subscription_id'] ?? 0)] = (int) ($row['record_count'] ?? 0);
    }

    return $counts;
}

function wallos_get_subscription_payment_total_map($db, $userId)
{
    $stmt = $db->prepare('
        SELECT subscription_id, COALESCE(SUM(amount_main_snapshot), 0) AS total_amount
        FROM subscription_payment_records
        WHERE user_id = :user_id AND status = :status
        GROUP BY subscription_id
    ');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':status', 'paid', SQLITE3_TEXT);
    $result = $stmt->execute();

    $totals = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $totals[(int) ($row['subscription_id'] ?? 0)] = round((float) ($row['total_amount'] ?? 0), 2);
    }

    return $totals;
}

function wallos_get_subscription_payment_total_summary_map($db, $userId)
{
    $stmt = $db->prepare('
        SELECT subscription_id, amount_original, currency_code_snapshot,
               main_currency_code_snapshot, fx_rate_to_main_snapshot, amount_main_snapshot
        FROM subscription_payment_records
        WHERE user_id = :user_id AND status = :status
        ORDER BY subscription_id ASC, paid_at ASC, id ASC
    ');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':status', 'paid', SQLITE3_TEXT);
    $result = $stmt->execute();

    $totals = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $subscriptionId = (int) ($row['subscription_id'] ?? 0);
        if ($subscriptionId <= 0) {
            continue;
        }

        if (!isset($totals[$subscriptionId])) {
            $totals[$subscriptionId] = [
                'main_amount' => 0.0,
                'main_currency_conversion_available' => true,
                'original_items' => [],
            ];
        }

        $totals[$subscriptionId]['main_amount'] += (float) ($row['amount_main_snapshot'] ?? 0);
        $totals[$subscriptionId]['original_items'][] = [
            'amount' => (float) ($row['amount_original'] ?? 0),
            'currency_code' => (string) ($row['currency_code_snapshot'] ?? ''),
        ];

        if (!wallos_payment_main_conversion_is_available(
            $row['currency_code_snapshot'] ?? '',
            $row['main_currency_code_snapshot'] ?? '',
            $row['fx_rate_to_main_snapshot'] ?? 0
        )) {
            $totals[$subscriptionId]['main_currency_conversion_available'] = false;
        }
    }

    foreach ($totals as &$total) {
        $total['main_amount'] = round((float) ($total['main_amount'] ?? 0), 2);
        $total['original_total'] = wallos_build_single_currency_original_total($total['original_items'] ?? [], 'amount', 'currency_code');
        unset($total['original_items']);
    }
    unset($total);

    return $totals;
}

function wallos_subscription_payment_scope_clause($subscriptionIds, &$bindings)
{
    if ($subscriptionIds === null) {
        return '';
    }

    $normalizedIds = [];
    foreach ((array) $subscriptionIds as $subscriptionId) {
        $subscriptionId = (int) $subscriptionId;
        if ($subscriptionId > 0) {
            $normalizedIds[$subscriptionId] = $subscriptionId;
        }
    }

    if (empty($normalizedIds)) {
        return ' AND 1 = 0';
    }

    $placeholders = [];
    foreach (array_values($normalizedIds) as $index => $subscriptionId) {
        $placeholder = ':subscription_scope_' . $index;
        $placeholders[] = $placeholder;
        $bindings[$placeholder] = $subscriptionId;
    }

    return ' AND subscription_payment_records.subscription_id IN (' . implode(', ', $placeholders) . ')';
}

function wallos_get_subscription_payment_records_for_period($db, $userId, $dateFrom, $dateTo, $respectExcludeFromStats = true, $subscriptionIds = null)
{
    $sql = '
        SELECT subscription_payment_records.id, subscription_payment_records.subscription_id,
               subscription_payment_records.due_date, subscription_payment_records.paid_at,
               subscription_payment_records.amount_original, subscription_payment_records.currency_code_snapshot,
               subscription_payment_records.main_currency_code_snapshot, subscription_payment_records.fx_rate_to_main_snapshot,
               subscription_payment_records.amount_main_snapshot,
               subscription_payment_records.payment_method_id, subscription_payment_records.status,
               subscription_payment_records.note, subscription_payment_records.created_at,
               subscriptions.name AS subscription_name
        FROM subscription_payment_records
        INNER JOIN subscriptions ON subscriptions.id = subscription_payment_records.subscription_id
        WHERE subscription_payment_records.user_id = :user_id
          AND subscription_payment_records.status = :status
          AND subscription_payment_records.paid_at >= :date_from
          AND subscription_payment_records.paid_at <= :date_to
    ';

    $bindings = [];
    $sql .= wallos_subscription_payment_scope_clause($subscriptionIds, $bindings);

    if ($respectExcludeFromStats) {
        $sql .= ' AND subscriptions.exclude_from_stats = 0';
    }

    $sql .= ' ORDER BY subscription_payment_records.paid_at DESC, subscription_payment_records.id DESC';

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':status', 'paid', SQLITE3_TEXT);
    $stmt->bindValue(':date_from', $dateFrom, SQLITE3_TEXT);
    $stmt->bindValue(':date_to', $dateTo, SQLITE3_TEXT);
    foreach ($bindings as $placeholder => $subscriptionId) {
        $stmt->bindValue($placeholder, $subscriptionId, SQLITE3_INTEGER);
    }
    $result = $stmt->execute();

    $records = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $records[] = wallos_enrich_payment_record_conversion_status($row);
    }

    return $records;
}

function wallos_record_subscription_payment(
    $db,
    $userId,
    $subscriptionId,
    $dueDate,
    $paidAt,
    $amountOriginal,
    $currencyId,
    $paymentMethodId,
    $note = '',
    $status = 'paid'
) {
    $subscriptionStmt = $db->prepare('
        SELECT id, currency_id, payment_method_id
        FROM subscriptions
        WHERE id = :subscription_id AND user_id = :user_id
    ');
    $subscriptionStmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $subscriptionStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $subscriptionResult = $subscriptionStmt->execute();
    $subscription = $subscriptionResult ? $subscriptionResult->fetchArray(SQLITE3_ASSOC) : false;

    if ($subscription === false) {
        throw new RuntimeException('Subscription not found.');
    }

    $normalizedPaidAt = trim((string) $paidAt);
    if ($normalizedPaidAt === '') {
        throw new RuntimeException('Payment date is required.');
    }

    $normalizedDueDate = trim((string) $dueDate);
    if ($normalizedDueDate === '') {
        $normalizedDueDate = $normalizedPaidAt;
    }

    if (!wallos_payment_record_is_valid_date($normalizedPaidAt)) {
        throw new RuntimeException('Invalid payment date.');
    }

    if (!wallos_payment_record_is_valid_date($normalizedDueDate)) {
        throw new RuntimeException('Invalid due date.');
    }

    $amountOriginal = (float) $amountOriginal;
    if ($amountOriginal <= 0) {
        throw new RuntimeException('Invalid payment amount.');
    }

    $currencyId = (int) $currencyId > 0 ? (int) $currencyId : (int) ($subscription['currency_id'] ?? 0);
    $paymentMethodId = (int) $paymentMethodId > 0 ? (int) $paymentMethodId : (int) ($subscription['payment_method_id'] ?? 0);

    $currencySnapshot = wallos_get_currency_snapshot($db, $userId, $currencyId);
    $mainCurrencyCode = wallos_get_main_currency_snapshot($db, $userId);
    $amountMainSnapshot = wallos_convert_amount_to_main_snapshot($amountOriginal, $currencySnapshot['rate']);

    $stmt = $db->prepare('
        INSERT INTO subscription_payment_records (
            user_id, subscription_id, due_date, paid_at, amount_original, currency_id,
            currency_code_snapshot, main_currency_code_snapshot, fx_rate_to_main_snapshot,
            amount_main_snapshot, payment_method_id, status, note
        ) VALUES (
            :user_id, :subscription_id, :due_date, :paid_at, :amount_original, :currency_id,
            :currency_code_snapshot, :main_currency_code_snapshot, :fx_rate_to_main_snapshot,
            :amount_main_snapshot, :payment_method_id, :status, :note
        )
    ');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':due_date', $normalizedDueDate, SQLITE3_TEXT);
    $stmt->bindValue(':paid_at', $normalizedPaidAt, SQLITE3_TEXT);
    $stmt->bindValue(':amount_original', $amountOriginal, SQLITE3_FLOAT);
    $stmt->bindValue(':currency_id', $currencyId, SQLITE3_INTEGER);
    $stmt->bindValue(':currency_code_snapshot', $currencySnapshot['code'], SQLITE3_TEXT);
    $stmt->bindValue(':main_currency_code_snapshot', $mainCurrencyCode, SQLITE3_TEXT);
    $stmt->bindValue(':fx_rate_to_main_snapshot', (float) $currencySnapshot['rate'], SQLITE3_FLOAT);
    $stmt->bindValue(':amount_main_snapshot', $amountMainSnapshot, SQLITE3_FLOAT);
    $stmt->bindValue(':payment_method_id', $paymentMethodId, SQLITE3_INTEGER);
    $stmt->bindValue(':status', $status, SQLITE3_TEXT);
    $stmt->bindValue(':note', trim((string) $note), SQLITE3_TEXT);

    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to record payment.');
    }

    return (int) $db->lastInsertRowID();
}

function wallos_update_subscription_payment_record(
    $db,
    $userId,
    $recordId,
    $subscriptionId,
    $dueDate,
    $paidAt,
    $amountOriginal,
    $currencyId,
    $paymentMethodId,
    $note = '',
    $status = 'paid'
) {
    $existingRecord = wallos_get_subscription_payment_record_by_id($db, $recordId, $subscriptionId, $userId);
    if ($existingRecord === false) {
        throw new RuntimeException('Payment record not found.');
    }

    $subscriptionStmt = $db->prepare('
        SELECT id, currency_id, payment_method_id
        FROM subscriptions
        WHERE id = :subscription_id AND user_id = :user_id
    ');
    $subscriptionStmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $subscriptionStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $subscriptionResult = $subscriptionStmt->execute();
    $subscription = $subscriptionResult ? $subscriptionResult->fetchArray(SQLITE3_ASSOC) : false;

    if ($subscription === false) {
        throw new RuntimeException('Subscription not found.');
    }

    $normalizedPaidAt = trim((string) $paidAt);
    if (!wallos_payment_record_is_valid_date($normalizedPaidAt)) {
        throw new RuntimeException('Invalid payment date.');
    }

    $normalizedDueDate = trim((string) $dueDate);
    if ($normalizedDueDate === '') {
        $normalizedDueDate = $normalizedPaidAt;
    }
    if (!wallos_payment_record_is_valid_date($normalizedDueDate)) {
        throw new RuntimeException('Invalid due date.');
    }

    $amountOriginal = (float) $amountOriginal;
    if ($amountOriginal <= 0) {
        throw new RuntimeException('Invalid payment amount.');
    }

    $currencyId = (int) $currencyId > 0 ? (int) $currencyId : (int) ($subscription['currency_id'] ?? 0);
    $paymentMethodId = (int) $paymentMethodId > 0 ? (int) $paymentMethodId : (int) ($subscription['payment_method_id'] ?? 0);

    $currencySnapshot = wallos_get_currency_snapshot($db, $userId, $currencyId);
    $mainCurrencyCode = wallos_get_main_currency_snapshot($db, $userId);
    $amountMainSnapshot = wallos_convert_amount_to_main_snapshot($amountOriginal, $currencySnapshot['rate']);

    $stmt = $db->prepare('
        UPDATE subscription_payment_records
        SET due_date = :due_date,
            paid_at = :paid_at,
            amount_original = :amount_original,
            currency_id = :currency_id,
            currency_code_snapshot = :currency_code_snapshot,
            main_currency_code_snapshot = :main_currency_code_snapshot,
            fx_rate_to_main_snapshot = :fx_rate_to_main_snapshot,
            amount_main_snapshot = :amount_main_snapshot,
            payment_method_id = :payment_method_id,
            status = :status,
            note = :note
        WHERE id = :id AND subscription_id = :subscription_id AND user_id = :user_id
    ');
    $stmt->bindValue(':due_date', $normalizedDueDate, SQLITE3_TEXT);
    $stmt->bindValue(':paid_at', $normalizedPaidAt, SQLITE3_TEXT);
    $stmt->bindValue(':amount_original', $amountOriginal, SQLITE3_FLOAT);
    $stmt->bindValue(':currency_id', $currencyId, SQLITE3_INTEGER);
    $stmt->bindValue(':currency_code_snapshot', $currencySnapshot['code'], SQLITE3_TEXT);
    $stmt->bindValue(':main_currency_code_snapshot', $mainCurrencyCode, SQLITE3_TEXT);
    $stmt->bindValue(':fx_rate_to_main_snapshot', (float) $currencySnapshot['rate'], SQLITE3_FLOAT);
    $stmt->bindValue(':amount_main_snapshot', $amountMainSnapshot, SQLITE3_FLOAT);
    $stmt->bindValue(':payment_method_id', $paymentMethodId, SQLITE3_INTEGER);
    $stmt->bindValue(':status', $status, SQLITE3_TEXT);
    $stmt->bindValue(':note', trim((string) $note), SQLITE3_TEXT);
    $stmt->bindValue(':id', $recordId, SQLITE3_INTEGER);
    $stmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);

    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to update payment record.');
    }
}

function wallos_delete_subscription_payment_record($db, $recordId, $subscriptionId, $userId)
{
    $stmt = $db->prepare('DELETE FROM subscription_payment_records WHERE id = :id AND subscription_id = :subscription_id AND user_id = :user_id');
    $stmt->bindValue(':id', $recordId, SQLITE3_INTEGER);
    $stmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}

function wallos_get_subscription_payment_total($db, $userId, $dateFrom, $dateTo, $respectExcludeFromStats = true, $subscriptionIds = null)
{
    $sql = '
        SELECT COALESCE(SUM(subscription_payment_records.amount_main_snapshot), 0) AS total_amount
        FROM subscription_payment_records
        INNER JOIN subscriptions ON subscriptions.id = subscription_payment_records.subscription_id
        WHERE subscription_payment_records.user_id = :user_id
          AND subscription_payment_records.status = :status
          AND subscription_payment_records.paid_at >= :date_from
          AND subscription_payment_records.paid_at <= :date_to
    ';

    $bindings = [];
    $sql .= wallos_subscription_payment_scope_clause($subscriptionIds, $bindings);

    if ($respectExcludeFromStats) {
        $sql .= ' AND subscriptions.exclude_from_stats = 0';
    }

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':status', 'paid', SQLITE3_TEXT);
    $stmt->bindValue(':date_from', $dateFrom, SQLITE3_TEXT);
    $stmt->bindValue(':date_to', $dateTo, SQLITE3_TEXT);
    foreach ($bindings as $placeholder => $subscriptionId) {
        $stmt->bindValue($placeholder, $subscriptionId, SQLITE3_INTEGER);
    }
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    return (float) ($row['total_amount'] ?? 0);
}

function wallos_get_paid_due_dates_map($db, $userId, $dateFrom, $dateTo, $respectExcludeFromStats = true, $subscriptionIds = null)
{
    $sql = '
        SELECT DISTINCT subscription_payment_records.subscription_id, subscription_payment_records.due_date
        FROM subscription_payment_records
        INNER JOIN subscriptions ON subscriptions.id = subscription_payment_records.subscription_id
        WHERE subscription_payment_records.user_id = :user_id
          AND subscription_payment_records.status = :status
          AND subscription_payment_records.due_date >= :date_from
          AND subscription_payment_records.due_date <= :date_to
    ';

    $bindings = [];
    $sql .= wallos_subscription_payment_scope_clause($subscriptionIds, $bindings);

    if ($respectExcludeFromStats) {
        $sql .= ' AND subscriptions.exclude_from_stats = 0';
    }

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':status', 'paid', SQLITE3_TEXT);
    $stmt->bindValue(':date_from', $dateFrom, SQLITE3_TEXT);
    $stmt->bindValue(':date_to', $dateTo, SQLITE3_TEXT);
    foreach ($bindings as $placeholder => $subscriptionId) {
        $stmt->bindValue($placeholder, $subscriptionId, SQLITE3_INTEGER);
    }
    $result = $stmt->execute();

    $map = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $subscriptionId = (int) ($row['subscription_id'] ?? 0);
        $dueDate = trim((string) ($row['due_date'] ?? ''));
        if ($subscriptionId <= 0 || $dueDate === '') {
            continue;
        }

        if (!isset($map[$subscriptionId])) {
            $map[$subscriptionId] = [];
        }

        $map[$subscriptionId][$dueDate] = true;
    }

    return $map;
}

function wallos_get_subscription_interval_spec($cycleId, $frequency)
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
    if ($cycleId === 4) {
        return 'P' . $frequency . 'Y';
    }

    return null;
}

function wallos_get_cycle_start_value_from_due_date($startDateValue, $dueDateValue, $cycleId, $frequency)
{
    $startDateValue = trim((string) $startDateValue);
    $dueDateValue = trim((string) $dueDateValue);
    $cycleId = (int) $cycleId;
    $frequency = (int) $frequency;
    if (!wallos_payment_record_is_valid_date($dueDateValue)
        || !in_array($cycleId, [1, 2, 3, 4], true)
        || $frequency < 1) {
        return '';
    }

    $dueTimestamp = wallos_calendar_parse_date($dueDateValue);
    $anchorTimestamp = $dueTimestamp;
    $configuredStartTimestamp = wallos_payment_record_is_valid_date($startDateValue)
        ? wallos_calendar_parse_date($startDateValue)
        : false;
    if ($configuredStartTimestamp !== false
        && $configuredStartTimestamp <= $dueTimestamp
        && wallos_calendar_get_occurrence_index(
            $configuredStartTimestamp,
            $dueTimestamp,
            $cycleId,
            $frequency,
            10000
        ) !== null) {
        $anchorTimestamp = $configuredStartTimestamp;
    }

    $cycleStartTimestamp = wallos_calendar_shift_recurring_date(
        $dueTimestamp,
        $cycleId,
        $frequency,
        -1,
        $anchorTimestamp
    );
    if ($cycleStartTimestamp === false) {
        return '';
    }

    if ($configuredStartTimestamp !== false && $cycleStartTimestamp < $configuredStartTimestamp) {
        $cycleStartTimestamp = $configuredStartTimestamp;
    }

    return date('Y-m-d', $cycleStartTimestamp);
}

function wallos_advance_subscription_next_payment_after_record($db, $subscriptionId, $userId, $recordedDueDate)
{
    $stmt = $db->prepare('
        SELECT id, start_date, next_payment, cycle, frequency
        FROM subscriptions
        WHERE id = :subscription_id AND user_id = :user_id
    ');
    $stmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $subscription = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    if ($subscription === false) {
        return;
    }

    $cycleId = (int) ($subscription['cycle'] ?? 0);
    $frequency = (int) ($subscription['frequency'] ?? 0);
    if (!in_array($cycleId, [1, 2, 3, 4], true) || $frequency < 1) {
        return;
    }

    $currentNextPayment = trim((string) ($subscription['next_payment'] ?? ''));
    $dueDate = trim((string) $recordedDueDate);
    if (!wallos_payment_record_is_valid_date($currentNextPayment)
        || !wallos_payment_record_is_valid_date($dueDate)) {
        return;
    }

    $nextPaymentTimestamp = wallos_calendar_parse_date($currentNextPayment);
    $recordedDueTimestamp = wallos_calendar_parse_date($dueDate);
    $anchorTimestamp = $nextPaymentTimestamp;
    $startDateValue = trim((string) ($subscription['start_date'] ?? ''));
    $startTimestamp = wallos_payment_record_is_valid_date($startDateValue)
        ? wallos_calendar_parse_date($startDateValue)
        : false;
    if ($startTimestamp !== false
        && $startTimestamp <= $nextPaymentTimestamp
        && wallos_calendar_get_occurrence_index(
            $startTimestamp,
            $nextPaymentTimestamp,
            $cycleId,
            $frequency,
            10000
        ) !== null) {
        $anchorTimestamp = $startTimestamp;
    }

    if ($nextPaymentTimestamp > $recordedDueTimestamp) {
        return;
    }

    while ($nextPaymentTimestamp <= $recordedDueTimestamp) {
        $nextTimestamp = wallos_calendar_shift_recurring_date(
            $nextPaymentTimestamp,
            $cycleId,
            $frequency,
            1,
            $anchorTimestamp
        );
        if ($nextTimestamp === false || $nextTimestamp <= $nextPaymentTimestamp) {
            return;
        }
        $nextPaymentTimestamp = $nextTimestamp;
    }

    $updateStmt = $db->prepare('UPDATE subscriptions SET next_payment = :next_payment WHERE id = :subscription_id AND user_id = :user_id');
    $updateStmt->bindValue(':next_payment', date('Y-m-d', $nextPaymentTimestamp), SQLITE3_TEXT);
    $updateStmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $updateStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $updateStmt->execute();
}

function wallos_recalculate_subscription_next_payment_from_history($db, $subscriptionId, $userId)
{
    $stmt = $db->prepare('
        SELECT id, start_date, next_payment, cycle, frequency, manual_cycle_used_value_cycle_start
        FROM subscriptions
        WHERE id = :subscription_id AND user_id = :user_id
        LIMIT 1
    ');
    $stmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $subscription = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    if ($subscription === false) {
        return;
    }

    $cycleId = (int) ($subscription['cycle'] ?? 0);
    $frequency = (int) ($subscription['frequency'] ?? 0);
    // A lifetime purchase has no next recurrence. Recording or deleting its
    // ledger row must not manufacture an annual renewal date.
    if ($cycleId === 5) {
        return;
    }
    if (!in_array($cycleId, [1, 2, 3, 4], true) || $frequency < 1) {
        return;
    }

    $startDateValue = trim((string) ($subscription['start_date'] ?? ''));
    $nextPaymentValue = trim((string) ($subscription['next_payment'] ?? ''));
    $anchorValue = '';

    if (wallos_payment_record_is_valid_date($startDateValue)) {
        $anchorValue = $startDateValue;
    } elseif (wallos_payment_record_is_valid_date($nextPaymentValue)) {
        $anchorValue = $nextPaymentValue;
    } else {
        return;
    }

    $paidDueDates = [];
    $paidDueDatesStmt = $db->prepare('
        SELECT due_date
        FROM subscription_payment_records
        WHERE subscription_id = :subscription_id AND user_id = :user_id AND status = :status
        ORDER BY due_date ASC, id ASC
    ');
    $paidDueDatesStmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $paidDueDatesStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $paidDueDatesStmt->bindValue(':status', 'paid', SQLITE3_TEXT);
    $paidDueDatesResult = $paidDueDatesStmt->execute();
    while ($paidDueDatesResult && ($row = $paidDueDatesResult->fetchArray(SQLITE3_ASSOC))) {
        $dueDate = trim((string) ($row['due_date'] ?? ''));
        if (wallos_payment_record_is_valid_date($dueDate)) {
            $paidDueDates[$dueDate] = true;
        }
    }

    $anchorTimestamp = wallos_calendar_parse_date($anchorValue);
    $cursorTimestamp = $anchorTimestamp;
    $nextUnpaidDueDate = null;

    for ($iteration = 0; $iteration < 2400; $iteration++) {
        $candidate = date('Y-m-d', $cursorTimestamp);
        if (empty($paidDueDates[$candidate])) {
            $nextUnpaidDueDate = $candidate;
            break;
        }

        $nextTimestamp = wallos_calendar_shift_recurring_date(
            $cursorTimestamp,
            $cycleId,
            $frequency,
            1,
            $anchorTimestamp
        );
        if ($nextTimestamp === false || $nextTimestamp <= $cursorTimestamp) {
            return;
        }
        $cursorTimestamp = $nextTimestamp;
    }

    if ($nextUnpaidDueDate === null) {
        return;
    }

    $newCycleStart = wallos_get_cycle_start_value_from_due_date(
        $subscription['start_date'] ?? '',
        $nextUnpaidDueDate,
        $subscription['cycle'] ?? 3,
        $subscription['frequency'] ?? 1
    );

    $clearManualCycleValue = trim((string) ($subscription['manual_cycle_used_value_cycle_start'] ?? '')) !== $newCycleStart;

    $sql = 'UPDATE subscriptions SET next_payment = :next_payment';
    if ($clearManualCycleValue) {
        $sql .= ", manual_cycle_used_value_main = 0, manual_cycle_used_value_cycle_start = ''";
    }
    $sql .= ' WHERE id = :subscription_id AND user_id = :user_id';

    $updateStmt = $db->prepare($sql);
    $updateStmt->bindValue(':next_payment', $nextUnpaidDueDate, SQLITE3_TEXT);
    $updateStmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
    $updateStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $updateStmt->execute();
}
