<?php

require_once __DIR__ . '/../includes/calendar_calculations.php';

function wallos_calendar_test_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_calendar_test_assert_dates(array $timestamps, array $expected, $message)
{
    $actual = array_map(function ($timestamp) {
        return date('Y-m-d', $timestamp);
    }, $timestamps);
    wallos_calendar_test_assert($actual === $expected, $message . ' | got: ' . json_encode($actual));
}

try {
    $monthlyCostApiSource = file_get_contents(__DIR__ . '/../api/subscriptions/get_monthly_cost.php');
    wallos_calendar_test_assert(
        is_string($monthlyCostApiSource)
        && strpos($monthlyCostApiSource, 'wallos_calendar_get_payment_dates(') !== false,
        'The monthly-cost API must use the shared recurrence projection'
    );

    $recurring = [
        'next_payment' => '2026-08-10',
        'start_date' => '2026-08-15',
        'cycle' => 1,
        'frequency' => 5,
    ];
    wallos_calendar_test_assert_dates(
        wallos_calendar_get_payment_dates($recurring, 2026, 8, 1),
        ['2026-08-15', '2026-08-20', '2026-08-25', '2026-08-30'],
        'Recurring payments must not appear before start_date'
    );

    $monthEnd = [
        'next_payment' => '2026-01-31',
        'start_date' => '2026-01-01',
        'cycle' => 3,
        'frequency' => 1,
    ];
    wallos_calendar_test_assert_dates(
        wallos_calendar_get_payment_dates($monthEnd, 2026, 2, 1),
        ['2026-02-28'],
        'Monthly payments anchored on month-end must clamp to February month-end'
    );
    wallos_calendar_test_assert_dates(
        wallos_calendar_get_payment_dates($monthEnd, 2026, 3, 1),
        ['2026-03-31'],
        'Monthly month-end anchors must recover the original day in longer months'
    );
    wallos_calendar_test_assert_dates(
        wallos_calendar_get_payment_dates($monthEnd, 2026, 4, 1),
        ['2026-04-30'],
        'Monthly month-end anchors must remain at the target month end'
    );

    $advancedMonthEnd = $monthEnd;
    $advancedMonthEnd['start_date'] = '2026-01-31';
    $advancedMonthEnd['next_payment'] = '2026-02-28';
    wallos_calendar_test_assert_dates(
        wallos_calendar_get_payment_dates($advancedMonthEnd, 2026, 3, 1),
        ['2026-03-31'],
        'An advanced short-month payment must recover the original start-date anchor'
    );

    $oneTime = [
        'next_payment' => '2026-08-20',
        'start_date' => '2026-08-01',
        'cycle' => 5,
        'frequency' => 1,
    ];
    wallos_calendar_test_assert_dates(
        wallos_calendar_get_payment_dates($oneTime, 2026, 8, 1),
        ['2026-08-20'],
        'One-time purchases must be shown on their exact due date'
    );
    wallos_calendar_test_assert(
        wallos_calendar_get_payment_dates($oneTime, 2026, 9, 1) === [],
        'One-time purchases must not recur in later months'
    );

    $oneTimeBeforeStart = $oneTime;
    $oneTimeBeforeStart['start_date'] = '2026-08-21';
    wallos_calendar_test_assert(
        wallos_calendar_get_payment_dates($oneTimeBeforeStart, 2026, 8, 1) === [],
        'One-time purchases before start_date must be hidden'
    );

    $unknownCycle = $recurring;
    $unknownCycle['cycle'] = 99;
    wallos_calendar_test_assert(
        wallos_calendar_get_payment_dates($unknownCycle, 2026, 8, 1) === [],
        'Unknown cycle IDs must not be treated as monthly'
    );

    $today = strtotime('2026-08-20');
    wallos_calendar_test_assert(
        wallos_calendar_is_due(strtotime('2026-08-20'), $today),
        'A payment due today must count as due'
    );
    wallos_calendar_test_assert(
        !wallos_calendar_is_due(strtotime('2026-08-19'), $today),
        'A payment before today must not count as due'
    );

    wallos_calendar_test_assert(
        wallos_calendar_get_week_days(false)[0]['key'] === 'mon'
        && wallos_calendar_get_week_days(true)[0]['key'] === 'sun',
        'Weekday headers must honor the Sunday-start preference'
    );
    wallos_calendar_test_assert(
        wallos_calendar_get_first_day_offset(strtotime('2026-08-01'), false) === 5
        && wallos_calendar_get_first_day_offset(strtotime('2026-08-01'), true) === 6,
        'First-day offset must honor the Sunday-start preference'
    );

    wallos_calendar_test_assert(
        wallos_calendar_parse_date('2026-02-30') === false,
        'Impossible calendar dates must not be normalized into a later month'
    );

    $leapAnchor = strtotime('2024-02-29');
    $shortYear = wallos_calendar_shift_recurring_date($leapAnchor, 4, 1, 1, $leapAnchor);
    $nextLeapYear = $shortYear;
    for ($leapStep = 0; $leapStep < 3; $leapStep++) {
        $nextLeapYear = wallos_calendar_shift_recurring_date($nextLeapYear, 4, 1, 1, $leapAnchor);
    }
    wallos_calendar_test_assert(
        date('Y-m-d', $shortYear) === '2025-02-28'
        && date('Y-m-d', $nextLeapYear) === '2028-02-29',
        'Yearly leap-day anchors must clamp and then recover'
    );

    $writeMonthEnd = [
        'start_date' => '2026-01-31',
        'next_payment' => '2026-01-31',
        'cycle' => 3,
        'frequency' => 1,
    ];
    wallos_calendar_test_assert(
        wallos_calendar_advance_subscription_next_payment(
            $writeMonthEnd,
            '2026-02-01 12:00:00'
        ) === '2026-02-28',
        'Write paths must clamp a January 31 schedule to February 28'
    );
    $writeMonthEnd['next_payment'] = '2026-02-28';
    wallos_calendar_test_assert(
        wallos_calendar_advance_subscription_next_payment(
            $writeMonthEnd,
            '2026-03-01 12:00:00'
        ) === '2026-03-31',
        'Write paths must recover the January 31 anchor after February'
    );

    $writeLeapDay = [
        'start_date' => '2024-02-29',
        'next_payment' => '2024-02-29',
        'cycle' => 4,
        'frequency' => 1,
    ];
    wallos_calendar_test_assert(
        wallos_calendar_advance_subscription_next_payment(
            $writeLeapDay,
            '2025-01-01 12:00:00'
        ) === '2025-02-28',
        'Write paths must clamp a leap-day schedule in a short year'
    );
    $writeLeapDay['next_payment'] = '2025-02-28';
    wallos_calendar_test_assert(
        wallos_calendar_advance_subscription_next_payment(
            $writeLeapDay,
            '2028-01-01 12:00:00'
        ) === '2028-02-29',
        'Write paths must recover February 29 in the next leap year'
    );

    $offScheduleWrite = [
        'start_date' => '2026-01-31',
        'next_payment' => '2026-02-27',
        'cycle' => 3,
        'frequency' => 1,
    ];
    wallos_calendar_test_assert(
        wallos_calendar_advance_subscription_next_payment(
            $offScheduleWrite,
            '2026-03-01 12:00:00'
        ) === '2026-03-27',
        'An off-schedule manual due date must retain its own anchor'
    );

    $invalidWrite = $writeMonthEnd;
    $invalidWrite['next_payment'] = '2026-02-30';
    wallos_calendar_test_assert(
        wallos_calendar_advance_subscription_next_payment($invalidWrite, '2026-03-01') === false,
        'Write paths must reject impossible next-payment dates'
    );
    wallos_calendar_test_assert(
        wallos_calendar_advance_subscription_next_payment($writeMonthEnd, 'not-a-date') === false,
        'Write paths must reject invalid thresholds'
    );
    $invalidWrite = $writeMonthEnd;
    $invalidWrite['cycle'] = 5;
    wallos_calendar_test_assert(
        wallos_calendar_advance_subscription_next_payment($invalidWrite, '2026-03-01') === false,
        'Write paths must reject non-recurring cycles'
    );
    $invalidWrite = $writeMonthEnd;
    $invalidWrite['frequency'] = 0;
    wallos_calendar_test_assert(
        wallos_calendar_advance_subscription_next_payment($invalidWrite, '2026-03-01') === false,
        'Write paths must reject invalid frequencies'
    );
    wallos_calendar_test_assert(
        wallos_calendar_advance_subscription_next_payment(
            $writeMonthEnd,
            '2027-01-01',
            0,
            1
        ) === false,
        'Write-path recurrence searches must honor their iteration bound'
    );

    $manualAdvance = $writeMonthEnd;
    $manualAdvance['next_payment'] = '2026-03-31';
    wallos_calendar_test_assert(
        wallos_calendar_advance_subscription_next_payment(
            $manualAdvance,
            '2026-03-01 12:00:00',
            1
        ) === '2026-04-30',
        'Manual renewal must be able to require at least one occurrence'
    );

    $cronWriteSource = file_get_contents(__DIR__ . '/../endpoints/cronjobs/updatenextpayment.php');
    $manualWriteSource = file_get_contents(__DIR__ . '/../endpoints/subscription/renew.php');
    wallos_calendar_test_assert(
        is_string($cronWriteSource)
        && strpos($cronWriteSource, 'wallos_calendar_advance_subscription_next_payment(') !== false
        && strpos($cronWriteSource, 'start_date') !== false
        && strpos($cronWriteSource, 'new DateInterval(') === false,
        'The automatic renewal writer must use the shared anchored helper'
    );
    wallos_calendar_test_assert(
        strpos($cronWriteSource, 'enableExceptions(true)') !== false
        && strpos($cronWriteSource, 'BEGIN IMMEDIATE') !== false
        && strpos($cronWriteSource, 'COMMIT') !== false
        && strpos($cronWriteSource, 'ROLLBACK') !== false
        && strpos($cronWriteSource, '$pendingUpdates[]') !== false
        && strpos($cronWriteSource, 'exit(1)') !== false,
        'The automatic renewal writer must update dates and its completion marker atomically'
    );
    wallos_calendar_test_assert(
        is_string($manualWriteSource)
        && preg_match(
            '/wallos_calendar_advance_subscription_next_payment\\(\\s*\\$subscriptionToRenew,\\s*\\$currentDate->format\\([^)]*\\),\\s*1\\s*\\)/s',
            $manualWriteSource
        ) === 1
        && strpos($manualWriteSource, 'new DateInterval(') === false,
        'The manual renewal writer must use the shared helper and advance at least once'
    );

    echo "[OK] Calendar date compatibility checks passed.\n";
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
