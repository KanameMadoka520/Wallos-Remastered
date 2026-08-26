<?php

/**
 * Return the calendar increment for a recurring subscription.
 *
 * Cycle 5 is intentionally handled by wallos_calendar_get_payment_dates()
 * because a one-time purchase has no recurring interval. Unknown cycle IDs
 * are rejected instead of silently being treated as monthly subscriptions.
 */
function wallos_calendar_get_increment_string($cycle, $frequency)
{
    $cycle = (int) $cycle;
    $frequency = (int) $frequency;
    if ($frequency < 1) {
        return null;
    }

    switch ($cycle) {
        case 1:
            return '+' . $frequency . ' days';
        case 2:
            return '+' . $frequency . ' weeks';
        case 3:
            return '+' . $frequency . ' months';
        case 4:
            return '+' . $frequency . ' years';
        default:
            return null;
    }
}

/**
 * Parse a Wallos date value without turning malformed input into Unix epoch.
 */
function wallos_calendar_parse_date($value)
{
    $value = trim((string) $value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T].*)?$/', $value)) {
        return false;
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? false : $timestamp;
}

/**
 * Move a date by whole calendar months without allowing month-end anchors to
 * drift. For example, January 31 becomes February 28 and then March 31.
 */
function wallos_calendar_add_months($timestamp, $months, $anchorDay = null)
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', date('Y-m-d', (int) $timestamp));
    if ($date === false) {
        return false;
    }

    $anchorDay = $anchorDay === null ? (int) $date->format('j') : (int) $anchorDay;
    if ($anchorDay < 1 || $anchorDay > 31) {
        return false;
    }

    $months = (int) $months;
    $target = $date->modify('first day of ' . ($months >= 0 ? '+' : '') . $months . ' months');
    if ($target === false) {
        return false;
    }

    $day = min($anchorDay, (int) $target->format('t'));
    return $target->setDate(
        (int) $target->format('Y'),
        (int) $target->format('n'),
        $day
    )->getTimestamp();
}

/**
 * Project a subscription's payment dates into one calendar month.
 *
 * The returned values are midnight timestamps. This function deliberately
 * contains no database or presentation concerns, making the high-risk date
 * rules testable without booting the authenticated page.
 */
function wallos_calendar_get_payment_dates(array $subscription, $calendarYear, $calendarMonth, $yearsToLoad = 1)
{
    $nextPaymentDate = wallos_calendar_parse_date($subscription['next_payment'] ?? '');
    if ($nextPaymentDate === false) {
        return [];
    }

    $subscriptionStartDate = wallos_calendar_parse_date($subscription['start_date'] ?? '');
    if ($subscriptionStartDate === false) {
        $subscriptionStartDate = $nextPaymentDate;
    }

    $calendarYear = (int) $calendarYear;
    $calendarMonth = (int) $calendarMonth;
    if ($calendarYear < 1 || $calendarMonth < 1 || $calendarMonth > 12) {
        return [];
    }

    $monthKey = sprintf('%04d-%02d', $calendarYear, $calendarMonth);
    $startOfMonth = strtotime($monthKey . '-01');
    if ($startOfMonth === false) {
        return [];
    }

    // A one-time purchase is shown only on its exact due date and still
    // respects start_date when old/inconsistent records are encountered.
    if ((int) ($subscription['cycle'] ?? 0) === 5) {
        if ($nextPaymentDate < $subscriptionStartDate || date('Y-m', $nextPaymentDate) !== $monthKey) {
            return [];
        }
        return [$nextPaymentDate];
    }

    $cycle = (int) ($subscription['cycle'] ?? 0);
    $frequency = (int) ($subscription['frequency'] ?? 0);
    $incrementString = wallos_calendar_get_increment_string($cycle, $frequency);
    if ($incrementString === null) {
        return [];
    }

    $yearsToLoad = max(1, (int) $yearsToLoad);
    $endDate = strtotime('+' . $yearsToLoad . ' years', $nextPaymentDate);
    if ($endDate === false) {
        return [];
    }

    // Move back to the first candidate around the requested month. Guard
    // against malformed intervals so an invalid record cannot loop forever.
    $monthAnchorDay = $cycle === 3 ? (int) date('j', $nextPaymentDate) : null;
    $startDate = $nextPaymentDate;
    while ($startDate > $startOfMonth) {
        $previousDate = $cycle === 3
            ? wallos_calendar_add_months($startDate, -$frequency, $monthAnchorDay)
            : strtotime('-' . ltrim($incrementString, '+'), $startDate);
        if ($previousDate === false || $previousDate >= $startDate) {
            return [];
        }
        $startDate = $previousDate;
    }

    $dates = [];
    for ($date = $startDate; $date <= $endDate;) {
        if ($date >= $subscriptionStartDate && date('Y-m', $date) === $monthKey) {
            $dates[] = $date;
        }

        $nextDate = $cycle === 3
            ? wallos_calendar_add_months($date, $frequency, $monthAnchorDay)
            : strtotime($incrementString, $date);
        if ($nextDate === false || $nextDate <= $date) {
            break;
        }
        $date = $nextDate;
    }

    return $dates;
}

/**
 * Return the seven calendar header keys in the user's preferred order.
 */
function wallos_calendar_get_week_days($weekStartsSunday = false)
{
    $weekDays = [
        ['key' => 'mon', 'offset' => 0],
        ['key' => 'tue', 'offset' => 1],
        ['key' => 'wed', 'offset' => 2],
        ['key' => 'thu', 'offset' => 3],
        ['key' => 'fri', 'offset' => 4],
        ['key' => 'sat', 'offset' => 5],
        ['key' => 'sun', 'offset' => 6],
    ];

    if ($weekStartsSunday) {
        $weekDays = [
            ['key' => 'sun', 'offset' => 6],
            ['key' => 'mon', 'offset' => 0],
            ['key' => 'tue', 'offset' => 1],
            ['key' => 'wed', 'offset' => 2],
            ['key' => 'thu', 'offset' => 3],
            ['key' => 'fri', 'offset' => 4],
            ['key' => 'sat', 'offset' => 5],
        ];
    }

    return $weekDays;
}

function wallos_calendar_get_first_day_offset($timestamp, $weekStartsSunday = false)
{
    $offset = (int) date('N', (int) $timestamp) - 1;
    return $weekStartsSunday ? (($offset + 1) % 7) : $offset;
}

function wallos_calendar_is_due($paymentTimestamp, $todayTimestamp)
{
    return (int) $paymentTimestamp >= (int) $todayTimestamp;
}
