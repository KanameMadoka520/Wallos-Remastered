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

    echo "[OK] Calendar date compatibility checks passed.\n";
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
