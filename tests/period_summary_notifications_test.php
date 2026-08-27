<?php

require_once __DIR__ . '/../includes/i18n/getlang.php';
require_once __DIR__ . '/../includes/i18n/en.php';
require_once __DIR__ . '/../includes/period_summary_notifications.php';

function wallos_period_summary_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $summaryOnly = wallos_build_notification_message('Alice', [], 'Amount needed this period: $25.00', true);
    wallos_period_summary_assert(
        strpos($summaryOnly, 'Amount needed this period: $25.00') !== false,
        'A summary-only notification was dropped.'
    );
    $combined = wallos_build_notification_message('Alice', [[
        'name' => 'Example',
        'formatted_price' => '$5.00',
        'days' => 1,
    ]], 'Amount needed this period: $25.00', true);
    wallos_period_summary_assert(
        substr_count($combined, 'Amount needed this period: $25.00') === 1
            && strpos($combined, 'Example for $5.00') !== false,
        'Renewals and a period summary were not combined exactly once.'
    );
    wallos_period_summary_assert(
        wallos_build_notification_message('', [], 'ignored', false) === '',
        'A disabled summary generated a message.'
    );

    $db = new SQLite3(':memory:');
    $db->enableExceptions(true);
    $db->exec('CREATE TABLE user (
        id INTEGER PRIMARY KEY,
        main_currency INTEGER,
        period_budget REAL,
        budget_period_type TEXT,
        budget_period_anchor_date TEXT
    )');
    $db->exec('CREATE TABLE currencies (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        name TEXT,
        symbol TEXT,
        code TEXT,
        rate REAL
    )');
    $db->exec('CREATE TABLE subscriptions (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        price REAL,
        currency_id INTEGER,
        next_payment TEXT,
        start_date TEXT,
        cycle INTEGER,
        frequency INTEGER,
        inactive INTEGER,
        auto_renew INTEGER,
        lifecycle_status TEXT,
        exclude_from_stats INTEGER
    )');
    $db->exec('CREATE TABLE subscription_price_rules (
        id INTEGER PRIMARY KEY,
        subscription_id INTEGER,
        user_id INTEGER,
        rule_type TEXT,
        price REAL,
        currency_id INTEGER,
        start_date TEXT,
        end_date TEXT,
        max_cycles INTEGER,
        priority INTEGER,
        note TEXT,
        enabled INTEGER,
        created_at TEXT
    )');
    $db->exec("INSERT INTO user VALUES (1, 1, 40, 'weekly', '2026-08-27')");
    $db->exec("INSERT INTO currencies VALUES (1, 1, 'US Dollar', '$', 'USD', 1.0)");
    $db->exec("INSERT INTO subscriptions VALUES
        (1, 1, 10, 1, '2026-08-28', '2026-08-28', 3, 1, 0, 1, 'active', 0),
        (2, 1, 20, 1, '2026-08-29', '2026-08-29', 5, 1, 0, 0, 'active', 0),
        (3, 1, 999, 1, '2026-08-30', '2026-08-30', 5, 1, 0, 0, 'active', 1),
        (4, 1, 888, 1, '2026-08-30', '2026-08-30', 5, 1, 0, 0, 'trashed', 0)");
    $db->exec("INSERT INTO subscription_price_rules VALUES
        (1, 1, 1, 'one_time', 5, 1, '2026-08-28', '', 0, 1, '', 1, CURRENT_TIMESTAMP)");

    $snapshot = wallos_get_period_summary_snapshot($db, 1, new DateTime('2026-08-27'), $i18n);
    wallos_period_summary_assert(is_array($snapshot) && $snapshot['is_period_start'], 'Period start was not detected.');
    wallos_period_summary_assert(
        abs((float) $snapshot['amount_needed'] - 25.0) < 0.0001,
        'Summary amount must include the special price and one-time purchase, while excluding hidden costs.'
    );
    wallos_period_summary_assert(
        strpos($snapshot['line'], translate('period_amount_needed', $i18n)) !== false
            && strpos($snapshot['line'], translate('period_budget_remaining', $i18n)) !== false,
        'Summary line is missing the amount or remaining-budget label.'
    );

    $cronSource = file_get_contents(__DIR__ . '/../endpoints/cronjobs/sendnotifications.php');
    wallos_period_summary_assert(
        substr_count($cronSource, 'wallos_build_notification_message(') >= 9,
        'Every text notification channel must use the shared summary-aware message builder.'
    );
    wallos_period_summary_assert(
        strpos($cronSource, 'foreach ($notify as $userId => $perUser)') === false,
        'A payer loop still overwrites the owning account user ID.'
    );
    wallos_period_summary_assert(
        strpos($cronSource, '{{period_summary}}') !== false,
        'Custom webhooks do not expose the opt-in period summary placeholder.'
    );

    echo "Payment-period summary notification test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

?>
