<?php

require_once __DIR__ . '/currency_rates.php';
require_once __DIR__ . '/subscription_price_rules.php';
require_once __DIR__ . '/calendar_calculations.php';

function wallos_budget_period_type($value)
{
    $value = strtolower(trim((string) $value));
    return in_array($value, ['weekly', 'fortnightly', 'monthly'], true) ? $value : 'monthly';
}

function wallos_budget_default_anchor_date()
{
    return (new DateTime('today'))->format('Y-m-d');
}

function wallos_budget_anchor_date($value)
{
    $value = trim((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return wallos_budget_default_anchor_date();
    }

    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : wallos_budget_default_anchor_date();
}

function wallos_budget_midnight(DateTime $date)
{
    return new DateTime($date->format('Y-m-d'));
}

function wallos_budget_parse_subscription_date($value)
{
    $value = trim((string) $value);
    if (wallos_calendar_parse_date($value) === false) {
        return null;
    }

    $date = DateTime::createFromFormat('!Y-m-d', substr($value, 0, 10));
    return $date && $date->format('Y-m-d') === substr($value, 0, 10) ? $date : null;
}

function wallos_budget_month_date($year, $month, $day)
{
    $first = DateTime::createFromFormat('!Y-n-j', $year . '-' . $month . '-1');
    if (!$first) {
        return new DateTime('1970-01-01');
    }

    $day = min(max(1, (int) $day), (int) $first->format('t'));
    return DateTime::createFromFormat('!Y-n-j', $year . '-' . $month . '-' . $day);
}

/**
 * Return the period containing $today. Monthly periods preserve the anchor
 * day and clamp it to the end of short months (31st -> 28th/30th).
 */
function wallos_get_active_budget_period(DateTime $today, $periodType, $anchorDate)
{
    $periodType = wallos_budget_period_type($periodType);
    $anchorDate = wallos_budget_anchor_date($anchorDate);
    $today = wallos_budget_midnight($today);
    $anchor = DateTime::createFromFormat('!Y-m-d', $anchorDate);

    if ($periodType !== 'monthly') {
        $days = $periodType === 'weekly' ? 7 : 14;
        $diff = (int) $anchor->diff($today)->format('%r%a');
        $offset = (int) floor($diff / $days);
        $start = clone $anchor;
        $start->modify(($offset * $days) . ' days');
        while ($start > $today) {
            $start->modify('-' . $days . ' days');
        }
        $end = clone $start;
        $end->modify('+' . ($days - 1) . ' days');
    } else {
        $anchorDay = (int) $anchor->format('j');
        $start = wallos_budget_month_date((int) $today->format('Y'), (int) $today->format('n'), $anchorDay);
        if ($today < $start) {
            $previous = clone $start;
            $previous->modify('first day of previous month');
            $start = wallos_budget_month_date((int) $previous->format('Y'), (int) $previous->format('n'), $anchorDay);
        }
        $nextMonth = clone $start;
        $nextMonth->modify('first day of next month');
        $nextStart = wallos_budget_month_date((int) $nextMonth->format('Y'), (int) $nextMonth->format('n'), $anchorDay);
        $end = clone $nextStart;
        $end->modify('-1 day');
    }

    return [
        'start' => $start,
        'end' => $end,
        'type' => $periodType,
        'anchor_date' => $anchorDate,
        'label' => $start->format('Y-m-d') . ' - ' . $end->format('Y-m-d'),
    ];
}

function wallos_budget_shift_occurrence(DateTime $date, array $subscription, DateTime $anchor, $direction)
{
    $frequency = (int) ($subscription['frequency'] ?? 0);
    $cycle = (int) ($subscription['cycle'] ?? 0);
    $nextTimestamp = wallos_calendar_shift_recurring_date(
        $date->getTimestamp(),
        $cycle,
        $frequency,
        $direction,
        $anchor->getTimestamp()
    );
    if ($nextTimestamp === false) {
        return null;
    }

    return DateTime::createFromFormat('!Y-m-d', date('Y-m-d', $nextTimestamp)) ?: null;
}

function wallos_budget_subscription_occurrences(array $subscription, DateTime $rangeStart, DateTime $rangeEnd)
{
    $cycle = (int) ($subscription['cycle'] ?? 0);
    $frequency = (int) ($subscription['frequency'] ?? 0);
    if (!in_array($cycle, [1, 2, 3, 4, 5], true) || ($cycle !== 5 && $frequency < 1)) {
        return [];
    }

    $nextPayment = wallos_budget_parse_subscription_date($subscription['next_payment'] ?? '');
    if (!$nextPayment) {
        return [];
    }
    $rangeStart = wallos_budget_midnight($rangeStart);
    $rangeEnd = wallos_budget_midnight($rangeEnd);
    if ($rangeStart > $rangeEnd) {
        return [];
    }

    $startDate = wallos_budget_parse_subscription_date($subscription['start_date'] ?? '');
    if ($startDate && $nextPayment < $startDate && ($cycle === 5 || (int) ($subscription['auto_renew'] ?? 0) !== 1)) {
        return [];
    }

    // One-time and manual-renewal subscriptions have at most their explicitly
    // stored next payment. Missing auto_renew is treated conservatively so a
    // partial SELECT cannot turn one charge into a recurring forecast.
    $autoRenew = (int) ($subscription['auto_renew'] ?? 0) === 1;
    if ($cycle === 5 || !$autoRenew) {
        return ($nextPayment >= $rangeStart && $nextPayment <= $rangeEnd) ? [$nextPayment] : [];
    }

    // Recover a true month-end or leap-day anchor from start_date only when
    // next_payment is actually on that recurrence. A manually shifted next
    // payment remains authoritative.
    $anchor = clone $nextPayment;
    if ($startDate && $startDate <= $nextPayment) {
        $occurrenceIndex = wallos_calendar_get_occurrence_index(
            $startDate->getTimestamp(),
            $nextPayment->getTimestamp(),
            $cycle,
            $frequency,
            10000
        );
        if ($occurrenceIndex !== null) {
            $anchor = clone $startDate;
        }
    }

    $minimumDate = clone $rangeStart;
    if ($startDate && $startDate > $minimumDate) {
        $minimumDate = clone $startDate;
    }

    $current = clone $nextPayment;
    $guard = 0;
    // next_payment is the earliest outstanding occurrence. Forecasting
    // backwards from it would recreate already-paid or manually skipped dues.
    while ($current < $minimumDate && $guard++ < 10000) {
        $next = wallos_budget_shift_occurrence($current, $subscription, $anchor, 1);
        if (!$next || $next <= $current) {
            return [];
        }
        $current = $next;
    }

    $occurrences = [];
    while ($current <= $rangeEnd && $guard++ < 10000) {
        if ($current >= $minimumDate) {
            $occurrences[] = clone $current;
        }
        $next = wallos_budget_shift_occurrence($current, $subscription, $anchor, 1);
        if (!$next || $next <= $current) {
            break;
        }
        $current = $next;
    }

    return $occurrences;
}

function wallos_budget_period_amount(array $subscriptions, DateTime $rangeStart, DateTime $rangeEnd, $db, $userId, array $rulesMap = [])
{
    $amount = 0.0;
    foreach ($subscriptions as $subscription) {
        if ((int) ($subscription['inactive'] ?? 0) !== 0
            || ((string) ($subscription['lifecycle_status'] ?? 'active') !== 'active')
            || (int) ($subscription['exclude_from_stats'] ?? 0) === 1) {
            continue;
        }

        $occurrences = wallos_budget_subscription_occurrences($subscription, $rangeStart, $rangeEnd);
        if (!$occurrences) {
            continue;
        }

        $rules = $rulesMap[(int) ($subscription['id'] ?? 0)] ?? [];
        foreach ($occurrences as $occurrence) {
            $dueDate = $occurrence->format('Y-m-d');
            if ($rules && function_exists('wallos_get_effective_subscription_price_for_due_date')) {
                $effective = wallos_get_effective_subscription_price_for_due_date($subscription, $rules, $dueDate, $db, $userId);
                $amount += (float) ($effective['amount_main'] ?? 0);
            } else {
                $amount += wallos_convert_price($subscription['price'] ?? 0, $subscription['currency_id'] ?? 0, $db, $userId);
            }
        }
    }

    return round($amount, 2);
}

?>
