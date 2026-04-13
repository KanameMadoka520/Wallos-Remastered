<?php

require_once __DIR__ . '/subscription_payment_records.php';
require_once __DIR__ . '/subscription_price_rules.php';

function wallos_payment_history_is_valid_date($value)
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $value)) === 1;
}

function wallos_get_subscription_paid_due_dates_from_records(array $records)
{
    $map = [];

    foreach ($records as $record) {
        $dueDate = trim((string) ($record['due_date'] ?? ''));
        if ($dueDate === '' || !wallos_payment_history_is_valid_date($dueDate)) {
            continue;
        }

        $map[$dueDate] = true;
    }

    return $map;
}

function wallos_enrich_subscription_payment_records_with_rule_replay($db, array $subscription, $userId, array $records, array $priceRules, $currencies, $i18n)
{
    $enriched = [];

    foreach ($records as $record) {
        $referenceDueDate = trim((string) ($record['due_date'] ?? ''));
        if ($referenceDueDate === '') {
            $referenceDueDate = trim((string) ($record['paid_at'] ?? ''));
        }

        if (wallos_payment_history_is_valid_date($referenceDueDate)) {
            $effectivePrice = wallos_get_effective_subscription_price_for_due_date($subscription, $priceRules, $referenceDueDate, $db, $userId);
            $record['rule_summary_current'] = $effectivePrice['matched_rule']
                ? wallos_format_subscription_price_rule_summary($effectivePrice['matched_rule'], $currencies, $i18n)
                : translate('metric_explanation_regular_price_source', $i18n);
            $record['expected_amount_main'] = round((float) ($effectivePrice['amount_main'] ?? 0), 2);
            $record['ledger_difference_main'] = round((float) ($record['amount_main_snapshot'] ?? 0) - (float) ($record['expected_amount_main'] ?? 0), 2);
        } else {
            $record['rule_summary_current'] = translate('metric_explanation_regular_price_source', $i18n);
            $record['expected_amount_main'] = round((float) ($record['amount_main_snapshot'] ?? 0), 2);
            $record['ledger_difference_main'] = 0.0;
        }

        $enriched[] = $record;
    }

    return $enriched;
}

function wallos_build_subscription_remaining_value_snapshot($db, array $subscription, $userId, array $priceRules, array $records, $currencies, $i18n, DateTime $today = null)
{
    $nextPaymentValue = trim((string) ($subscription['next_payment'] ?? ''));
    $mainCurrencyCode = wallos_get_main_currency_snapshot($db, $userId);
    if (!wallos_payment_history_is_valid_date($nextPaymentValue)) {
        return [
            'available' => false,
            'remaining_value_main' => 0.0,
            'current_cycle_value_main' => 0.0,
            'remaining_ratio' => 0.0,
            'remaining_days' => 0,
            'total_days' => 0,
            'current_cycle_start' => '',
            'current_cycle_end' => '',
            'current_cycle_value_label' => '',
            'value_source_summary' => '',
            'main_currency_code' => $mainCurrencyCode,
        ];
    }

    $today = $today ?: new DateTime('today');
    $cycleEnd = new DateTime($nextPaymentValue);
    $interval = new DateInterval(wallos_get_subscription_interval_spec((int) ($subscription['cycle'] ?? 3), (int) ($subscription['frequency'] ?? 1)));
    $cycleStart = (clone $cycleEnd)->sub($interval);

    $startDateValue = trim((string) ($subscription['start_date'] ?? ''));
    if (wallos_payment_history_is_valid_date($startDateValue)) {
        $configuredStartDate = new DateTime($startDateValue);
        if ($cycleStart < $configuredStartDate) {
            $cycleStart = $configuredStartDate;
        }
    }

    $totalDays = max(1, (int) $cycleStart->diff($cycleEnd)->days);
    if ($today >= $cycleEnd) {
        $remainingDays = 0;
    } elseif ($today <= $cycleStart) {
        $remainingDays = $totalDays;
    } else {
        $remainingDays = (int) $today->diff($cycleEnd)->days;
    }

    $currentCycleAnchor = $cycleStart->format('Y-m-d');
    $currentCycleRecord = null;
    foreach ($records as $record) {
        if (trim((string) ($record['due_date'] ?? '')) === $currentCycleAnchor && trim((string) ($record['status'] ?? 'paid')) === 'paid') {
            $currentCycleRecord = $record;
            break;
        }
    }

    if ($currentCycleRecord === null) {
        $currentCycleRecord = wallos_get_subscription_payment_record_by_due_date($db, (int) ($subscription['id'] ?? 0), $userId, $currentCycleAnchor);
    }

    if ($currentCycleRecord !== false && $currentCycleRecord !== null) {
        $currentCycleValueMain = round((float) ($currentCycleRecord['amount_main_snapshot'] ?? 0), 2);
        $currentCycleValueLabel = !empty($currentCycleRecord['currency_code_snapshot'])
            ? CurrencyFormatter::format((float) ($currentCycleRecord['amount_original'] ?? 0), (string) $currentCycleRecord['currency_code_snapshot'])
            : number_format((float) ($currentCycleRecord['amount_original'] ?? 0), 2);
        $valueSourceSummary = translate('subscription_remaining_value_source_record', $i18n);
    } else {
        $effectivePrice = wallos_get_effective_subscription_price_for_due_date($subscription, $priceRules, $currentCycleAnchor, $db, $userId);
        $currentCycleValueMain = round((float) ($effectivePrice['amount_main'] ?? 0), 2);
        $currencyCode = (string) ($effectivePrice['currency_code'] ?? '');
        $currentCycleValueLabel = $currencyCode !== ''
            ? CurrencyFormatter::format((float) ($effectivePrice['amount_original'] ?? 0), $currencyCode)
            : number_format((float) ($effectivePrice['amount_original'] ?? 0), 2);
        $valueSourceSummary = $effectivePrice['matched_rule']
            ? translate('subscription_remaining_value_source_rule', $i18n) . ' - ' . wallos_format_subscription_price_rule_summary($effectivePrice['matched_rule'], $currencies, $i18n)
            : translate('subscription_remaining_value_source_rule', $i18n);
    }

    $remainingRatio = $totalDays > 0 ? round($remainingDays / $totalDays, 6) : 0.0;
    $remainingValueMain = round($currentCycleValueMain * $remainingRatio, 2);

    return [
        'available' => true,
        'remaining_value_main' => $remainingValueMain,
        'current_cycle_value_main' => $currentCycleValueMain,
        'remaining_ratio' => round($remainingRatio * 100, 2),
        'remaining_days' => $remainingDays,
        'total_days' => $totalDays,
        'current_cycle_start' => $currentCycleAnchor,
        'current_cycle_end' => $cycleEnd->format('Y-m-d'),
        'current_cycle_value_label' => $currentCycleValueLabel,
        'value_source_summary' => $valueSourceSummary,
        'main_currency_code' => $mainCurrencyCode,
    ];
}

function wallos_build_subscription_future_payment_forecast($db, array $subscription, $userId, array $priceRules, array $paidDueDates, $currencies, $i18n, $limit = 18, DateTime $fromDate = null, DateTime $endDate = null)
{
    $forecast = [];
    $nextPaymentValue = trim((string) ($subscription['next_payment'] ?? ''));
    $mainCurrencyCode = wallos_get_main_currency_snapshot($db, $userId);

    if (!wallos_payment_history_is_valid_date($nextPaymentValue)) {
        return $forecast;
    }

    $fromDate = $fromDate ?: new DateTime('today');
    $endDate = $endDate ?: new DateTime(($fromDate->format('Y') + 1) . '-12-31');
    $interval = new DateInterval(wallos_get_subscription_interval_spec((int) ($subscription['cycle'] ?? 3), (int) ($subscription['frequency'] ?? 1)));
    $cursor = new DateTime($nextPaymentValue);
    $iterations = 0;

    while ($cursor <= $endDate && count($forecast) < max(1, (int) $limit) && $iterations < 2400) {
        $dueDate = $cursor->format('Y-m-d');
        if ($cursor >= $fromDate && empty($paidDueDates[$dueDate])) {
            $effectivePrice = wallos_get_effective_subscription_price_for_due_date($subscription, $priceRules, $dueDate, $db, $userId);
            $forecast[] = [
                'due_date' => $dueDate,
                'amount_original' => round((float) ($effectivePrice['amount_original'] ?? 0), 2),
                'amount_main' => round((float) ($effectivePrice['amount_main'] ?? 0), 2),
                'currency_code' => (string) ($effectivePrice['currency_code'] ?? ''),
                'main_currency_code' => $mainCurrencyCode,
                'rule_summary' => $effectivePrice['matched_rule']
                    ? wallos_format_subscription_price_rule_summary($effectivePrice['matched_rule'], $currencies, $i18n)
                    : translate('metric_explanation_regular_price_source', $i18n),
            ];
        }

        $cursor->add($interval);
        $iterations++;
    }

    return $forecast;
}

function wallos_build_subscription_yearly_cashflow(array $records, array $forecast, $year)
{
    $year = (int) $year;
    $rows = [];

    for ($month = 1; $month <= 12; $month++) {
        $rows[$month] = [
            'month_number' => $month,
            'actual_total' => 0.0,
            'predicted_total' => 0.0,
            'total' => 0.0,
        ];
    }

    foreach ($records as $record) {
        $paidAt = trim((string) ($record['paid_at'] ?? ''));
        if (!wallos_payment_history_is_valid_date($paidAt)) {
            continue;
        }

        $paidDate = new DateTime($paidAt);
        if ((int) $paidDate->format('Y') !== $year) {
            continue;
        }

        $month = (int) $paidDate->format('n');
        $rows[$month]['actual_total'] += round((float) ($record['amount_main_snapshot'] ?? 0), 2);
    }

    foreach ($forecast as $item) {
        $dueDate = trim((string) ($item['due_date'] ?? ''));
        if (!wallos_payment_history_is_valid_date($dueDate)) {
            continue;
        }

        $forecastDate = new DateTime($dueDate);
        if ((int) $forecastDate->format('Y') !== $year) {
            continue;
        }

        $month = (int) $forecastDate->format('n');
        $rows[$month]['predicted_total'] += round((float) ($item['amount_main'] ?? 0), 2);
    }

    foreach ($rows as &$row) {
        $row['actual_total'] = round((float) $row['actual_total'], 2);
        $row['predicted_total'] = round((float) $row['predicted_total'], 2);
        $row['total'] = round((float) ($row['actual_total'] + $row['predicted_total']), 2);
    }
    unset($row);

    return array_values($rows);
}

function wallos_build_subscription_payment_history_available_years(array $subscription, array $records, DateTime $today = null)
{
    $today = $today ?: new DateTime('today');
    $years = [];

    $startDateValue = trim((string) ($subscription['start_date'] ?? ''));
    $nextPaymentValue = trim((string) ($subscription['next_payment'] ?? ''));

    if (wallos_payment_history_is_valid_date($startDateValue)) {
        $years[(int) substr($startDateValue, 0, 4)] = true;
    }

    if (wallos_payment_history_is_valid_date($nextPaymentValue)) {
        $years[(int) substr($nextPaymentValue, 0, 4)] = true;
    }

    foreach ($records as $record) {
        foreach (['paid_at', 'due_date'] as $field) {
            $value = trim((string) ($record[$field] ?? ''));
            if (wallos_payment_history_is_valid_date($value)) {
                $years[(int) substr($value, 0, 4)] = true;
            }
        }
    }

    $currentYear = (int) $today->format('Y');
    $years[$currentYear] = true;
    $years[$currentYear + 1] = true;

    if (empty($years)) {
        return [$currentYear];
    }

    $minYear = min(array_keys($years));
    $maxYear = max(array_keys($years));

    $expandedYears = [];
    for ($year = $minYear; $year <= $maxYear; $year++) {
        $expandedYears[] = $year;
    }

    rsort($expandedYears, SORT_NUMERIC);
    return $expandedYears;
}
