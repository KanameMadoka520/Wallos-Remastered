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
    if ($value === '' || !preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T].*)?$/', $value, $matches)) {
        return false;
    }

    // strtotime() normalizes impossible dates such as 2026-02-30. Validate
    // the calendar portion first so corrupt legacy values are not projected
    // into a different month.
    $calendarDate = DateTimeImmutable::createFromFormat('!Y-m-d', $matches[1]);
    if ($calendarDate === false || $calendarDate->format('Y-m-d') !== $matches[1]) {
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
 * Move a date by whole calendar years while preserving leap-day anchors.
 * February 29 therefore becomes February 28 in a short year and returns to
 * February 29 in the next leap year.
 */
function wallos_calendar_add_years($timestamp, $years, $anchorMonth = null, $anchorDay = null)
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', date('Y-m-d', (int) $timestamp));
    if ($date === false) {
        return false;
    }

    $anchorMonth = $anchorMonth === null ? (int) $date->format('n') : (int) $anchorMonth;
    $anchorDay = $anchorDay === null ? (int) $date->format('j') : (int) $anchorDay;
    $targetYear = (int) $date->format('Y') + (int) $years;
    if ($targetYear < 1 || $anchorMonth < 1 || $anchorMonth > 12 || $anchorDay < 1 || $anchorDay > 31) {
        return false;
    }

    $first = DateTimeImmutable::createFromFormat('!Y-n-j', $targetYear . '-' . $anchorMonth . '-1');
    if ($first === false) {
        return false;
    }

    return $first->setDate(
        $targetYear,
        $anchorMonth,
        min($anchorDay, (int) $first->format('t'))
    )->getTimestamp();
}

/**
 * Shift one subscription occurrence using the calendar rules shared by the
 * calendar, budget and price-rule projections.
 */
function wallos_calendar_shift_recurring_date($timestamp, $cycle, $frequency, $direction = 1, $anchorTimestamp = null)
{
    $cycle = (int) $cycle;
    $frequency = (int) $frequency;
    if ($frequency < 1 || !in_array($cycle, [1, 2, 3, 4], true)) {
        return false;
    }

    $direction = (int) $direction < 0 ? -1 : 1;
    $step = $frequency * $direction;
    $anchorTimestamp = $anchorTimestamp === null ? (int) $timestamp : (int) $anchorTimestamp;

    if ($cycle === 1 || $cycle === 2) {
        $days = $step * ($cycle === 2 ? 7 : 1);
        return strtotime(($days >= 0 ? '+' : '') . $days . ' days', (int) $timestamp);
    }

    if ($cycle === 3) {
        return wallos_calendar_add_months(
            $timestamp,
            $step,
            (int) date('j', $anchorTimestamp)
        );
    }

    return wallos_calendar_add_years(
        $timestamp,
        $step,
        (int) date('n', $anchorTimestamp),
        (int) date('j', $anchorTimestamp)
    );
}

/**
 * Return a one-based occurrence index when a target is on the recurrence
 * defined by its start date. Null means the target is not on that schedule.
 */
function wallos_calendar_get_occurrence_index($startTimestamp, $targetTimestamp, $cycle, $frequency, $maxIterations = 2400)
{
    $startTimestamp = (int) $startTimestamp;
    $targetTimestamp = (int) $targetTimestamp;
    if ($targetTimestamp < $startTimestamp) {
        return null;
    }

    $cursor = $startTimestamp;
    $anchor = $startTimestamp;
    $limit = max(1, (int) $maxIterations);
    for ($index = 1; $index <= $limit && $cursor <= $targetTimestamp; $index++) {
        if (date('Y-m-d', $cursor) === date('Y-m-d', $targetTimestamp)) {
            return $index;
        }

        $next = wallos_calendar_shift_recurring_date($cursor, $cycle, $frequency, 1, $anchor);
        if ($next === false || $next <= $cursor) {
            return null;
        }
        $cursor = $next;
    }

    return null;
}

/**
 * Advance a subscription due date to the first occurrence at or after a
 * threshold. A configured start date is used as the month/year anchor only
 * when the current due date is provably on that schedule; manual off-schedule
 * due dates therefore keep their own anchor.
 *
 * The return value is a Y-m-d string, or false when the inputs are invalid or
 * the bounded search cannot reach the threshold.
 */
function wallos_calendar_advance_subscription_next_payment(
    array $subscription,
    $thresholdValue,
    $minimumAdvances = 0,
    $maxIterations = 10000
) {
    $nextPaymentTimestamp = wallos_calendar_parse_date($subscription['next_payment'] ?? '');
    $thresholdTimestamp = wallos_calendar_parse_date($thresholdValue);
    $cycle = (int) ($subscription['cycle'] ?? 0);
    $frequency = (int) ($subscription['frequency'] ?? 0);
    $minimumAdvances = (int) $minimumAdvances;
    $maxIterations = (int) $maxIterations;

    if ($nextPaymentTimestamp === false
        || $thresholdTimestamp === false
        || !in_array($cycle, [1, 2, 3, 4], true)
        || $frequency < 1
        || $minimumAdvances < 0
        || $maxIterations < 1
    ) {
        return false;
    }

    $anchorTimestamp = $nextPaymentTimestamp;
    $startTimestamp = wallos_calendar_parse_date($subscription['start_date'] ?? '');
    if ($startTimestamp !== false
        && $startTimestamp <= $nextPaymentTimestamp
        && wallos_calendar_get_occurrence_index(
            $startTimestamp,
            $nextPaymentTimestamp,
            $cycle,
            $frequency,
            $maxIterations
        ) !== null
    ) {
        $anchorTimestamp = $startTimestamp;
    }

    $cursorTimestamp = $nextPaymentTimestamp;
    for ($advances = 0; $advances <= $maxIterations; $advances++) {
        if ($advances >= $minimumAdvances && $cursorTimestamp >= $thresholdTimestamp) {
            return date('Y-m-d', $cursorTimestamp);
        }

        if ($advances === $maxIterations) {
            break;
        }

        $nextTimestamp = wallos_calendar_shift_recurring_date(
            $cursorTimestamp,
            $cycle,
            $frequency,
            1,
            $anchorTimestamp
        );
        if ($nextTimestamp === false || $nextTimestamp <= $cursorTimestamp) {
            return false;
        }
        $cursorTimestamp = $nextTimestamp;
    }

    return false;
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
    if (wallos_calendar_get_increment_string($cycle, $frequency) === null) {
        return [];
    }

    $recurrenceAnchor = $nextPaymentDate;
    if ($subscriptionStartDate <= $nextPaymentDate
        && wallos_calendar_get_occurrence_index(
            $subscriptionStartDate,
            $nextPaymentDate,
            $cycle,
            $frequency,
            10000
        ) !== null
    ) {
        $recurrenceAnchor = $subscriptionStartDate;
    }

    $yearsToLoad = max(1, (int) $yearsToLoad);
    $endDate = strtotime('+' . $yearsToLoad . ' years', $nextPaymentDate);
    if ($endDate === false) {
        return [];
    }

    // Move back to the first candidate around the requested month. Guard
    // against malformed intervals so an invalid record cannot loop forever.
    $startDate = $nextPaymentDate;
    while ($startDate > $startOfMonth) {
        $previousDate = wallos_calendar_shift_recurring_date(
            $startDate,
            $cycle,
            $frequency,
            -1,
            $recurrenceAnchor
        );
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

        $nextDate = wallos_calendar_shift_recurring_date(
            $date,
            $cycle,
            $frequency,
            1,
            $recurrenceAnchor
        );
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
