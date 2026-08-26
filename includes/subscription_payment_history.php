<?php

require_once __DIR__ . '/subscription_payment_records.php';
require_once __DIR__ . '/subscription_price_rules.php';

function wallos_payment_history_is_valid_date($value)
{
    return wallos_payment_record_is_valid_date($value);
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

function wallos_get_subscription_cycle_window(array $subscription, DateTime $today = null)
{
    $nextPaymentValue = trim((string) ($subscription['next_payment'] ?? ''));
    if (!wallos_payment_history_is_valid_date($nextPaymentValue)) {
        return [
            'available' => false,
            'cycle_start' => '',
            'cycle_end' => '',
            'remaining_days' => 0,
            'total_days' => 0,
        ];
    }

    $cycleId = (int) ($subscription['cycle'] ?? 0);
    $frequency = (int) ($subscription['frequency'] ?? 0);
    if (!in_array($cycleId, [1, 2, 3, 4], true) || $frequency < 1) {
        return [
            'available' => false,
            'cycle_start' => '',
            'cycle_end' => '',
            'remaining_days' => 0,
            'total_days' => 0,
        ];
    }

    $today = $today ?: new DateTime('today');
    $cycleEndTimestamp = wallos_calendar_parse_date($nextPaymentValue);
    $anchorTimestamp = $cycleEndTimestamp;

    $startDateValue = trim((string) ($subscription['start_date'] ?? ''));
    $configuredStartTimestamp = false;
    if (wallos_payment_history_is_valid_date($startDateValue)) {
        $configuredStartTimestamp = wallos_calendar_parse_date($startDateValue);
        if ($configuredStartTimestamp <= $cycleEndTimestamp
            && wallos_calendar_get_occurrence_index(
                $configuredStartTimestamp,
                $cycleEndTimestamp,
                $cycleId,
                $frequency,
                10000
            ) !== null) {
            $anchorTimestamp = $configuredStartTimestamp;
        }
    }

    $cycleStartTimestamp = wallos_calendar_shift_recurring_date(
        $cycleEndTimestamp,
        $cycleId,
        $frequency,
        -1,
        $anchorTimestamp
    );
    if ($cycleStartTimestamp === false) {
        return [
            'available' => false,
            'cycle_start' => '',
            'cycle_end' => '',
            'remaining_days' => 0,
            'total_days' => 0,
        ];
    }
    if ($configuredStartTimestamp !== false && $cycleStartTimestamp < $configuredStartTimestamp) {
        $cycleStartTimestamp = $configuredStartTimestamp;
    }

    $cycleEnd = new DateTime(date('Y-m-d', $cycleEndTimestamp));
    $cycleStart = new DateTime(date('Y-m-d', $cycleStartTimestamp));

    $totalDays = max(1, (int) $cycleStart->diff($cycleEnd)->days);
    if ($today >= $cycleEnd) {
        $remainingDays = 0;
    } elseif ($today <= $cycleStart) {
        $remainingDays = $totalDays;
    } else {
        $remainingDays = (int) $today->diff($cycleEnd)->days;
    }

    return [
        'available' => true,
        'cycle_start' => $cycleStart->format('Y-m-d'),
        'cycle_end' => $cycleEnd->format('Y-m-d'),
        'remaining_days' => $remainingDays,
        'total_days' => $totalDays,
    ];
}

function wallos_get_subscription_current_cycle_start_value(array $subscription)
{
    $window = wallos_get_subscription_cycle_window($subscription);
    return $window['available'] ? (string) $window['cycle_start'] : '';
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
    $mainCurrencyCode = wallos_get_main_currency_snapshot($db, $userId);
    $window = wallos_get_subscription_cycle_window($subscription, $today);
    if (empty($window['available'])) {
        return [
            'available' => false,
            'remaining_value_main' => 0.0,
            'remaining_value_original' => 0.0,
            'current_cycle_value_main' => 0.0,
            'current_cycle_value_original' => 0.0,
            'time_prorated_remaining_main' => 0.0,
            'time_prorated_remaining_original' => 0.0,
            'manual_used_value_main' => 0.0,
            'manual_unused_value_main' => 0.0,
            'manual_used_value_active' => false,
            'remaining_ratio' => 0.0,
            'remaining_days' => 0,
            'total_days' => 0,
            'current_cycle_start' => '',
            'current_cycle_end' => '',
            'current_cycle_value_label' => '',
            'value_source_summary' => '',
            'main_currency_code' => $mainCurrencyCode,
            'currency_code' => '',
            'main_currency_conversion_available' => true,
            'remaining_value_original_available' => false,
        ];
    }

    $currentCycleAnchor = (string) $window['cycle_start'];
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
        $currentCycleValueOriginal = round((float) ($currentCycleRecord['amount_original'] ?? 0), 2);
        $currentCycleCurrencyCode = strtoupper(trim((string) ($currentCycleRecord['currency_code_snapshot'] ?? '')));
        $mainCurrencyConversionAvailable = wallos_payment_main_conversion_is_available(
            $currentCycleCurrencyCode,
            $mainCurrencyCode,
            $currentCycleRecord['fx_rate_to_main_snapshot'] ?? 0
        );
        $currentCycleValueLabel = !empty($currentCycleRecord['currency_code_snapshot'])
            ? CurrencyFormatter::format((float) ($currentCycleRecord['amount_original'] ?? 0), (string) $currentCycleRecord['currency_code_snapshot'])
            : number_format((float) ($currentCycleRecord['amount_original'] ?? 0), 2);
        $valueSourceSummary = translate('subscription_remaining_value_source_record', $i18n);
    } else {
        $effectivePrice = wallos_get_effective_subscription_price_for_due_date($subscription, $priceRules, $currentCycleAnchor, $db, $userId);
        $currentCycleValueMain = round((float) ($effectivePrice['amount_main'] ?? 0), 2);
        $currencyCode = (string) ($effectivePrice['currency_code'] ?? '');
        $currentCycleValueOriginal = round((float) ($effectivePrice['amount_original'] ?? 0), 2);
        $currentCycleCurrencyCode = strtoupper(trim($currencyCode));
        $mainCurrencyConversionAvailable = wallos_payment_main_conversion_is_available(
            $currentCycleCurrencyCode,
            $mainCurrencyCode,
            $effectivePrice['fx_rate_to_main'] ?? 0
        );
        $currentCycleValueLabel = $currencyCode !== ''
            ? CurrencyFormatter::format((float) ($effectivePrice['amount_original'] ?? 0), $currencyCode)
            : number_format((float) ($effectivePrice['amount_original'] ?? 0), 2);
        $valueSourceSummary = $effectivePrice['matched_rule']
            ? translate('subscription_remaining_value_source_rule', $i18n) . ' - ' . wallos_format_subscription_price_rule_summary($effectivePrice['matched_rule'], $currencies, $i18n)
            : translate('subscription_remaining_value_source_rule', $i18n);
    }

    $remainingRatio = (int) ($window['total_days'] ?? 0) > 0 ? round(((int) ($window['remaining_days'] ?? 0)) / ((int) ($window['total_days'] ?? 1)), 6) : 0.0;
    $timeProratedRemainingMain = round($currentCycleValueMain * $remainingRatio, 2);
    $timeProratedRemainingOriginal = round($currentCycleValueOriginal * $remainingRatio, 2);

    $storedManualUsedValue = round((float) ($subscription['manual_cycle_used_value_main'] ?? 0), 2);
    $storedManualAnchor = trim((string) ($subscription['manual_cycle_used_value_cycle_start'] ?? ''));
    $manualUsedValueActive = $storedManualUsedValue > 0 && $storedManualAnchor === $currentCycleAnchor;
    $manualUsedValueMain = $manualUsedValueActive ? min($currentCycleValueMain, max(0, $storedManualUsedValue)) : 0.0;
    $manualUnusedValueMain = round(max(0, $currentCycleValueMain - $manualUsedValueMain), 2);

    $remainingValueMain = $timeProratedRemainingMain;
    $remainingValueOriginal = $timeProratedRemainingOriginal;
    $remainingValueOriginalAvailable = $currentCycleCurrencyCode !== '' && !$manualUsedValueActive;
    $remainingValueModeSummary = translate('subscription_remaining_value_mode_time', $i18n);
    if ($manualUsedValueActive) {
        $remainingValueMain = round(min($timeProratedRemainingMain, $manualUnusedValueMain), 2);
        $remainingValueModeSummary = translate('subscription_remaining_value_mode_hybrid', $i18n);

        if ($mainCurrencyConversionAvailable && $currentCycleValueMain > 0 && $currentCycleCurrencyCode !== '') {
            $mainToOriginalRatio = $currentCycleValueOriginal / $currentCycleValueMain;
            $manualUnusedValueOriginal = round(max(0, $manualUnusedValueMain * $mainToOriginalRatio), 2);
            $remainingValueOriginal = round(min($timeProratedRemainingOriginal, $manualUnusedValueOriginal), 2);
            $remainingValueOriginalAvailable = true;
        }
    }

    return [
        'available' => true,
        'remaining_value_main' => $remainingValueMain,
        'remaining_value_original' => $remainingValueOriginal,
        'current_cycle_value_main' => $currentCycleValueMain,
        'current_cycle_value_original' => $currentCycleValueOriginal,
        'time_prorated_remaining_main' => $timeProratedRemainingMain,
        'time_prorated_remaining_original' => $timeProratedRemainingOriginal,
        'manual_used_value_main' => $manualUsedValueMain,
        'manual_unused_value_main' => $manualUnusedValueMain,
        'manual_used_value_active' => $manualUsedValueActive,
        'remaining_ratio' => round($remainingRatio * 100, 2),
        'remaining_days' => (int) ($window['remaining_days'] ?? 0),
        'total_days' => (int) ($window['total_days'] ?? 0),
        'current_cycle_start' => $currentCycleAnchor,
        'current_cycle_end' => (string) ($window['cycle_end'] ?? ''),
        'current_cycle_value_label' => $currentCycleValueLabel,
        'value_source_summary' => $valueSourceSummary,
        'remaining_mode_summary' => $remainingValueModeSummary,
        'main_currency_code' => $mainCurrencyCode,
        'currency_code' => $currentCycleCurrencyCode,
        'main_currency_conversion_available' => $mainCurrencyConversionAvailable,
        'remaining_value_original_available' => $remainingValueOriginalAvailable,
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

    $cycleId = (int) ($subscription['cycle'] ?? 0);
    $frequency = (int) ($subscription['frequency'] ?? 0);
    $oneTime = $cycleId === 5;
    // Manual-renewal subscriptions expose only their explicitly stored next
    // payment. Missing auto_renew fails closed so a partial SELECT cannot turn
    // one known charge into an unlimited recurring forecast.
    $recursAutomatically = !$oneTime && (int) ($subscription['auto_renew'] ?? 0) === 1;
    if (!$oneTime && (!in_array($cycleId, [1, 2, 3, 4], true) || $frequency < 1)) {
        return $forecast;
    }

    $fromDate = $fromDate ?: new DateTime('today');
    $endDate = $endDate ?: new DateTime(($fromDate->format('Y') + 1) . '-12-31');
    $cursorTimestamp = wallos_calendar_parse_date($nextPaymentValue);
    $anchorTimestamp = $cursorTimestamp;
    $startDateValue = trim((string) ($subscription['start_date'] ?? ''));
    if (!$oneTime && wallos_payment_history_is_valid_date($startDateValue)) {
        $startTimestamp = wallos_calendar_parse_date($startDateValue);
        if ($startTimestamp <= $cursorTimestamp
            && wallos_calendar_get_occurrence_index(
                $startTimestamp,
                $cursorTimestamp,
                $cycleId,
                $frequency,
                10000
            ) !== null) {
            $anchorTimestamp = $startTimestamp;
        }
    }

    $fromTimestamp = wallos_calendar_parse_date($fromDate->format('Y-m-d'));
    $endTimestamp = wallos_calendar_parse_date($endDate->format('Y-m-d'));
    $iterations = 0;

    while ($cursorTimestamp <= $endTimestamp && count($forecast) < max(1, (int) $limit) && $iterations < 2400) {
        $dueDate = date('Y-m-d', $cursorTimestamp);
        if ($cursorTimestamp >= $fromTimestamp && empty($paidDueDates[$dueDate])) {
            $effectivePrice = wallos_get_effective_subscription_price_for_due_date($subscription, $priceRules, $dueDate, $db, $userId);
            $forecast[] = [
                'due_date' => $dueDate,
                'amount_original' => round((float) ($effectivePrice['amount_original'] ?? 0), 2),
                'amount_main' => round((float) ($effectivePrice['amount_main'] ?? 0), 2),
                'currency_code' => (string) ($effectivePrice['currency_code'] ?? ''),
                'main_currency_code' => $mainCurrencyCode,
                'main_currency_conversion_available' => wallos_payment_main_conversion_is_available(
                    $effectivePrice['currency_code'] ?? '',
                    $mainCurrencyCode,
                    $effectivePrice['fx_rate_to_main'] ?? 0
                ),
                'rule_summary' => $effectivePrice['matched_rule']
                    ? wallos_format_subscription_price_rule_summary($effectivePrice['matched_rule'], $currencies, $i18n)
                    : translate('metric_explanation_regular_price_source', $i18n),
            ];
        }

        if (!$recursAutomatically) {
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
            break;
        }
        $cursorTimestamp = $nextTimestamp;
        $iterations++;
    }

    return $forecast;
}

function wallos_build_subscription_yearly_cashflow(array $records, array $forecast, $year)
{
    $year = (int) $year;
    $rows = [];
    $originalRows = [];

    for ($month = 1; $month <= 12; $month++) {
        $rows[$month] = [
            'month_number' => $month,
            'actual_total' => 0.0,
            'predicted_total' => 0.0,
            'total' => 0.0,
            'actual_total_main_currency_available' => true,
            'predicted_total_main_currency_available' => true,
            'total_main_currency_available' => true,
        ];
        $originalRows[$month] = [
            'actual' => [],
            'predicted' => [],
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
        $originalRows[$month]['actual'][] = [
            'amount' => (float) ($record['amount_original'] ?? 0),
            'currency_code' => (string) ($record['currency_code_snapshot'] ?? ''),
        ];
        if (array_key_exists('main_currency_conversion_available', $record)
            && empty($record['main_currency_conversion_available'])
        ) {
            $rows[$month]['actual_total_main_currency_available'] = false;
        }
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
        $originalRows[$month]['predicted'][] = [
            'amount' => (float) ($item['amount_original'] ?? 0),
            'currency_code' => (string) ($item['currency_code'] ?? ''),
        ];
        if (array_key_exists('main_currency_conversion_available', $item)
            && empty($item['main_currency_conversion_available'])
        ) {
            $rows[$month]['predicted_total_main_currency_available'] = false;
        }
    }

    foreach ($rows as &$row) {
        $monthNumber = (int) $row['month_number'];
        $totalOriginalItems = array_merge($originalRows[$monthNumber]['actual'], $originalRows[$monthNumber]['predicted']);

        $row['actual_total'] = round((float) $row['actual_total'], 2);
        $row['predicted_total'] = round((float) $row['predicted_total'], 2);
        $row['total'] = round((float) ($row['actual_total'] + $row['predicted_total']), 2);
        $row['actual_total_original'] = wallos_build_single_currency_original_total($originalRows[$monthNumber]['actual'], 'amount', 'currency_code');
        $row['predicted_total_original'] = wallos_build_single_currency_original_total($originalRows[$monthNumber]['predicted'], 'amount', 'currency_code');
        $row['total_original'] = wallos_build_single_currency_original_total($totalOriginalItems, 'amount', 'currency_code');
        $row['total_main_currency_available'] = !empty($row['actual_total_main_currency_available'])
            && !empty($row['predicted_total_main_currency_available']);
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
