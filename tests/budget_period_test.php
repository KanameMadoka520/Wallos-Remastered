<?php

require_once __DIR__ . '/../includes/budget_period_calculations.php';

function wallos_period_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_period_date(DateTime $date)
{
    return $date->format('Y-m-d');
}

try {
    $statsSource = file_get_contents(__DIR__ . '/../includes/stats_calculations.php');
    wallos_period_assert(
        is_string($statsSource)
        && preg_match('/SELECT[^"\r\n]*\bauto_renew\b[^"\r\n]*FROM subscriptions/', $statsSource) === 1,
        'the statistics subscription query must load auto_renew for rolling forecasts'
    );
    wallos_period_assert(
        substr_count($statsSource, 'wallos_budget_subscription_occurrences(') >= 3
        && strpos($statsSource, 'new DateInterval(') === false,
        'monthly, yearly, and rolling statistics must share the anchored occurrence projection'
    );

    $budgetEndpointSource = file_get_contents(__DIR__ . '/../endpoints/user/budget.php');
    wallos_period_assert(is_string($budgetEndpointSource), 'the budget endpoint source must be readable');
    wallos_period_assert(
        preg_match(
            '/if\s*\(\s*\$budget\s*<\s*0\s*\|\|\s*\$yearlyBudget\s*<\s*0\s*\|\|\s*\$periodBudget\s*<\s*0\s*\)/',
            $budgetEndpointSource
        ) === 1,
        'monthly, yearly, and period budgets must all reject negative values'
    );
    wallos_period_assert(
        preg_match('/["\']success["\']\s*=>\s*false/', $budgetEndpointSource) === 1
        && strpos($budgetEndpointSource, "translate('fill_mandatory_fields', \$i18n)") !== false,
        'negative budgets must return the existing validation error response'
    );
    wallos_period_assert(
        preg_match('/\$periodBudget\s*<=\s*0/', $budgetEndpointSource) !== 1,
        'a zero period budget must remain valid so the feature can be disabled'
    );
    wallos_period_assert(
        strpos($budgetEndpointSource, 'max(0, $periodBudget)') === false
        && preg_match(
            '/bindValue\(\s*["\']:period_budget["\']\s*,\s*\$periodBudget\s*,\s*SQLITE3_FLOAT\s*\)/',
            $budgetEndpointSource
        ) === 1,
        'validated period budgets must be saved without silently clamping negatives to zero'
    );
    wallos_period_assert(
        strpos($budgetEndpointSource, "\$hasPeriodPayload = array_key_exists('period_budget', \$data)") !== false
        && preg_match('/if\s*\(\s*\$hasPeriodPayload\s*\)\s*\{[^}]*period_budget\s*=\s*:period_budget/s', $budgetEndpointSource) === 1,
        'legacy requests without period fields must leave existing period settings unchanged'
    );

    $weekly = wallos_get_active_budget_period(new DateTime('2026-08-26'), 'weekly', '2026-08-24');
    wallos_period_assert(wallos_period_date($weekly['start']) === '2026-08-24', 'weekly period starts on its anchor weekday');
    wallos_period_assert(wallos_period_date($weekly['end']) === '2026-08-30', 'weekly period has seven days');

    $fortnightly = wallos_get_active_budget_period(new DateTime('2026-09-10'), 'fortnightly', '2026-09-01');
    wallos_period_assert(wallos_period_date($fortnightly['start']) === '2026-09-01', 'fortnightly period keeps its anchor');
    wallos_period_assert(wallos_period_date($fortnightly['end']) === '2026-09-14', 'fortnightly period has fourteen days');

    $monthEnd = wallos_get_active_budget_period(new DateTime('2026-02-15'), 'monthly', '2026-01-31');
    wallos_period_assert(wallos_period_date($monthEnd['start']) === '2026-01-31', 'anchored month can start in the previous month');
    wallos_period_assert(wallos_period_date($monthEnd['end']) === '2026-02-27', 'short month ends the day before the next clamped anchor');

    $db = new SQLite3(':memory:');
    $db->exec('CREATE TABLE currencies (id INTEGER PRIMARY KEY, user_id INTEGER, rate REAL)');
    $db->exec('CREATE TABLE subscription_price_rules (id INTEGER PRIMARY KEY, subscription_id INTEGER, user_id INTEGER, rule_type TEXT, price REAL, currency_id INTEGER, start_date TEXT, end_date TEXT, max_cycles INTEGER, priority INTEGER, note TEXT, enabled INTEGER, created_at TEXT)');
    $db->exec("INSERT INTO currencies (id, user_id, rate) VALUES (1, 7, 1.0), (2, 7, 2.0)");

    $subscription = [
        'id' => 10,
        'user_id' => 7,
        'inactive' => 0,
        'lifecycle_status' => 'active',
        'exclude_from_stats' => 0,
        'price' => 20,
        'currency_id' => 2,
        'cycle' => 3,
        'frequency' => 1,
        'start_date' => '2026-01-31',
        'next_payment' => '2026-01-31',
        'auto_renew' => 1,
    ];
    $occurrences = wallos_budget_subscription_occurrences($subscription, new DateTime('2026-02-01'), new DateTime('2026-04-01'));
    $dates = array_map('wallos_period_date', $occurrences);
    wallos_period_assert($dates === ['2026-02-28', '2026-03-31'], 'monthly occurrences preserve an end-of-month anchor');

    $amount = wallos_budget_period_amount([$subscription], new DateTime('2026-02-01'), new DateTime('2026-04-01'), $db, 7);
    wallos_period_assert(abs($amount - 20.0) < 0.001, 'period amount converts each occurrence into main currency');

    $advancedMonthEnd = $subscription;
    $advancedMonthEnd['next_payment'] = '2026-02-28';
    $advancedOccurrences = wallos_budget_subscription_occurrences(
        $advancedMonthEnd,
        new DateTime('2026-03-01'),
        new DateTime('2026-04-30')
    );
    wallos_period_assert(
        array_map('wallos_period_date', $advancedOccurrences) === ['2026-03-31', '2026-04-30'],
        'an advanced February payment must recover its original month-end anchor'
    );

    $firstCyclesRules = [
        10 => [
            [
                'rule_type' => 'first_n_cycles',
                'price' => 8,
                'currency_id' => 1,
                'max_cycles' => 2,
                'enabled' => 1,
            ],
        ],
    ];
    $discountedFebruary = wallos_budget_period_amount(
        [$subscription],
        new DateTime('2026-02-01'),
        new DateTime('2026-02-28'),
        $db,
        7,
        $firstCyclesRules
    );
    wallos_period_assert(
        abs($discountedFebruary - 8.0) < 0.001,
        'month-end occurrences must retain their first-N-cycle special price'
    );

    $manualRenewal = $subscription;
    $manualRenewal['auto_renew'] = 0;
    $manualOccurrences = wallos_budget_subscription_occurrences(
        $manualRenewal,
        new DateTime('2026-01-01'),
        new DateTime('2026-04-30')
    );
    wallos_period_assert(
        array_map('wallos_period_date', $manualOccurrences) === ['2026-01-31'],
        'manual-renewal subscriptions must not be repeated by a forecast'
    );

    $partialSelect = $manualRenewal;
    unset($partialSelect['auto_renew']);
    wallos_period_assert(
        count(wallos_budget_subscription_occurrences($partialSelect, new DateTime('2026-01-01'), new DateTime('2026-04-30'))) === 1,
        'a missing auto_renew field must fail closed instead of creating recurring charges'
    );

    $oneTime = $subscription;
    $oneTime['cycle'] = 5;
    $oneTime['frequency'] = 0;
    $oneTime['next_payment'] = '2026-03-15';
    $oneTime['start_date'] = '2026-03-15';
    $oneTime['auto_renew'] = 0;
    wallos_period_assert(
        array_map('wallos_period_date', wallos_budget_subscription_occurrences($oneTime, new DateTime('2026-03-01'), new DateTime('2026-03-31'))) === ['2026-03-15'],
        'one-time purchases must appear exactly once on their due date'
    );
    $oneTimeAmount = wallos_budget_period_amount(
        [$oneTime],
        new DateTime('2026-03-01'),
        new DateTime('2026-03-31'),
        $db,
        7,
        [
            10 => [
                [
                    'rule_type' => 'one_time',
                    'price' => 6,
                    'currency_id' => 1,
                    'start_date' => '2026-03-15',
                    'enabled' => 1,
                ],
            ],
        ]
    );
    wallos_period_assert(
        abs($oneTimeAmount - 6.0) < 0.001,
        'one-time purchases must retain their due-date price rule in period totals'
    );

    $futureStart = $subscription;
    $futureStart['start_date'] = '2026-12-15';
    $futureStart['next_payment'] = '2026-12-15';
    wallos_period_assert(
        wallos_budget_subscription_occurrences($futureStart, new DateTime('2026-09-01'), new DateTime('2026-11-30')) === [],
        'recurring charges must never be backfilled before start_date'
    );

    $futureNextPayment = $subscription;
    $futureNextPayment['start_date'] = '2026-01-15';
    $futureNextPayment['next_payment'] = '2026-11-15';
    wallos_period_assert(
        array_map(
            'wallos_period_date',
            wallos_budget_subscription_occurrences(
                $futureNextPayment,
                new DateTime('2026-09-01'),
                new DateTime('2026-12-31')
            )
        ) === ['2026-11-15', '2026-12-15'],
        'future forecasts must not invent unpaid occurrences before next_payment'
    );

    $leapYear = $subscription;
    $leapYear['cycle'] = 4;
    $leapYear['frequency'] = 1;
    $leapYear['start_date'] = '2024-02-29';
    $leapYear['next_payment'] = '2025-02-28';
    wallos_period_assert(
        array_map(
            'wallos_period_date',
            wallos_budget_subscription_occurrences(
                $leapYear,
                new DateTime('2026-01-01'),
                new DateTime('2028-12-31')
            )
        ) === ['2026-02-28', '2027-02-28', '2028-02-29'],
        'yearly forecasts must clamp and recover a leap-day anchor'
    );

    $invalidDate = $subscription;
    $invalidDate['next_payment'] = '2026-02-30';
    wallos_period_assert(
        wallos_budget_subscription_occurrences($invalidDate, new DateTime('2026-02-01'), new DateTime('2026-04-30')) === [],
        'impossible next-payment dates must be rejected instead of normalized'
    );
    $db->close();

    echo "Budget period calculations test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

?>
