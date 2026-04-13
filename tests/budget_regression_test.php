<?php

require_once __DIR__ . '/../includes/budget_metrics.php';
require_once __DIR__ . '/../includes/subscription_payment_records.php';
require_once __DIR__ . '/../includes/subscription_price_rules.php';

function wallos_test_assert_equal($actual, $expected, $message)
{
    if ($actual !== $expected) {
        throw new RuntimeException($message . ' | expected: ' . var_export($expected, true) . ' got: ' . var_export($actual, true));
    }
}

function wallos_test_assert_float($actual, $expected, $message, $precision = 0.001)
{
    if (abs((float) $actual - (float) $expected) > $precision) {
        throw new RuntimeException($message . ' | expected: ' . $expected . ' got: ' . $actual);
    }
}

function wallos_test_print_ok($message)
{
    echo '[OK] ' . $message . PHP_EOL;
}

try {
    $monthlyUnder = wallos_calculate_budget_metrics(100, 35.5);
    wallos_test_assert_float($monthlyUnder['remaining'], 64.5, '月预算剩余金额应正确');
    wallos_test_assert_float($monthlyUnder['used_percent'], 35.5, '月预算使用率应正确');
    wallos_test_assert_float($monthlyUnder['over_amount'], 0, '月预算未超支时超支金额应为 0');
    wallos_test_print_ok('月预算未超支场景');

    $monthlyOver = wallos_calculate_budget_metrics(100, 140);
    wallos_test_assert_float($monthlyOver['remaining'], 0, '月预算超支时剩余金额应封顶为 0');
    wallos_test_assert_float($monthlyOver['used_percent'], 100, '月预算使用率应封顶为 100');
    wallos_test_assert_float($monthlyOver['over_amount'], 40, '月预算超支金额应正确');
    wallos_test_print_ok('月预算超支场景');

    $yearlyUnder = wallos_calculate_yearly_budget_metrics(1000, 420, 280, 1200);
    wallos_test_assert_float($yearlyUnder['projected_total'], 700, '年预算预计总额应等于历史实付加剩余预测');
    wallos_test_assert_float($yearlyUnder['remaining'], 300, '年预算剩余金额应正确');
    wallos_test_assert_float($yearlyUnder['used_percent'], 70, '年预算使用率应正确');
    wallos_test_assert_float($yearlyUnder['standardized_reference'], 1200, '年预算标准化参考值应保留');
    wallos_test_print_ok('年预算未超支场景');

    $yearlyOver = wallos_calculate_yearly_budget_metrics(1000, 760, 390, 960);
    wallos_test_assert_float($yearlyOver['projected_total'], 1150, '年预算预计总额应正确');
    wallos_test_assert_float($yearlyOver['remaining'], 0, '年预算超支时剩余金额应封顶为 0');
    wallos_test_assert_float($yearlyOver['used_percent'], 100, '年预算使用率应封顶为 100');
    wallos_test_assert_float($yearlyOver['over_amount'], 150, '年预算超支金额应正确');
    wallos_test_print_ok('年预算超支场景');

    $db = new SQLite3(':memory:');
    $db->exec('CREATE TABLE user (id INTEGER PRIMARY KEY, main_currency INTEGER)');
    $db->exec('CREATE TABLE currencies (id INTEGER PRIMARY KEY, user_id INTEGER, code TEXT, rate REAL)');
    $db->exec("INSERT INTO user (id, main_currency) VALUES (1, 1)");
    $db->exec("INSERT INTO currencies (id, user_id, code, rate) VALUES (1, 1, 'USD', 1.0)");
    $db->exec("INSERT INTO currencies (id, user_id, code, rate) VALUES (2, 1, 'EUR', 0.5)");

    $subscription = [
        'id' => 11,
        'price' => 100,
        'currency_id' => 1,
        'cycle' => 3,
        'frequency' => 1,
        'start_date' => '2026-01-01',
        'next_payment' => '2026-01-01',
    ];

    $firstCycleRules = [
        [
            'rule_type' => 'first_n_cycles',
            'price' => 50,
            'currency_id' => 1,
            'max_cycles' => 2,
            'enabled' => 1,
        ],
    ];
    $firstCycleEffective = wallos_get_effective_subscription_price_for_due_date($subscription, $firstCycleRules, '2026-02-01', $db, 1);
    wallos_test_assert_float($firstCycleEffective['amount_main'], 50, '首两期规则应命中第二个账期');
    wallos_test_assert_equal($firstCycleEffective['matched_rule']['rule_type'], 'first_n_cycles', '首两期规则类型应返回');
    wallos_test_print_ok('首两期特殊价格规则');

    $priorityRules = [
        [
            'rule_type' => 'one_time',
            'price' => 10,
            'currency_id' => 2,
            'start_date' => '2026-04-01',
            'enabled' => 1,
        ],
        [
            'rule_type' => 'date_range',
            'price' => 80,
            'currency_id' => 1,
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
            'enabled' => 1,
        ],
    ];
    $priorityEffective = wallos_get_effective_subscription_price_for_due_date($subscription, $priorityRules, '2026-04-01', $db, 1);
    wallos_test_assert_float($priorityEffective['amount_original'], 10, '优先级更高的一次性规则应命中');
    wallos_test_assert_float($priorityEffective['amount_main'], 20, '命中非主货币规则时应正确换算为主货币');
    wallos_test_assert_equal($priorityEffective['currency_code'], 'EUR', '命中规则后的货币快照应正确');
    wallos_test_assert_equal($priorityEffective['matched_rule']['rule_type'], 'one_time', '规则优先级应按顺序生效');
    wallos_test_print_ok('规则优先级与汇率换算');

    $regularEffective = wallos_get_effective_subscription_price_for_due_date($subscription, $priorityRules, '2026-08-01', $db, 1);
    wallos_test_assert_float($regularEffective['amount_main'], 100, '无规则命中时应回退到常规定价');
    wallos_test_assert_equal($regularEffective['matched_rule'], null, '无规则命中时不应返回规则对象');
    wallos_test_print_ok('常规定价回退场景');

    echo PHP_EOL . '预算与价格规则回归测试全部通过。' . PHP_EOL;
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
