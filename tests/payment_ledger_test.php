<?php

require_once __DIR__ . '/../includes/subscription_payment_records.php';
require_once __DIR__ . '/../includes/subscription_payment_history.php';
require_once __DIR__ . '/../includes/subscription_price_rules.php';

if (!function_exists('translate')) {
    function translate($text, $translations)
    {
        return $translations[$text] ?? $text;
    }
}

function wallos_ledger_assert_equal($actual, $expected, $message)
{
    if ($actual !== $expected) {
        throw new RuntimeException($message . ' | expected: ' . var_export($expected, true) . ' got: ' . var_export($actual, true));
    }
}

function wallos_ledger_assert_float($actual, $expected, $message, $precision = 0.001)
{
    if (abs((float) $actual - (float) $expected) > $precision) {
        throw new RuntimeException($message . ' | expected: ' . $expected . ' got: ' . $actual);
    }
}

function wallos_ledger_print_ok($message)
{
    echo '[OK] ' . $message . PHP_EOL;
}

try {
    $db = new SQLite3(':memory:');
    $db->exec('CREATE TABLE user (id INTEGER PRIMARY KEY, main_currency INTEGER)');
    $db->exec('CREATE TABLE currencies (id INTEGER PRIMARY KEY, user_id INTEGER, code TEXT, rate REAL)');
    $db->exec('CREATE TABLE subscriptions (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        name TEXT,
        price REAL,
        currency_id INTEGER,
        cycle INTEGER,
        frequency INTEGER,
        start_date TEXT,
        next_payment TEXT,
        payment_method_id INTEGER
    )');
    $db->exec('CREATE TABLE subscription_payment_records (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        subscription_id INTEGER,
        due_date TEXT,
        paid_at TEXT,
        amount_original REAL,
        currency_id INTEGER,
        currency_code_snapshot TEXT,
        main_currency_code_snapshot TEXT,
        fx_rate_to_main_snapshot REAL,
        amount_main_snapshot REAL,
        payment_method_id INTEGER,
        status TEXT,
        note TEXT,
        created_at TEXT
    )');

    $db->exec("INSERT INTO user (id, main_currency) VALUES (1, 1)");
    $db->exec("INSERT INTO currencies (id, user_id, code, rate) VALUES (1, 1, 'USD', 1.0)");
    $db->exec("INSERT INTO currencies (id, user_id, code, rate) VALUES (2, 1, 'EUR', 0.5)");
    $db->exec("INSERT INTO subscriptions (id, user_id, name, price, currency_id, cycle, frequency, start_date, next_payment, payment_method_id)
        VALUES (9, 1, 'Test Subscription', 100, 1, 3, 1, '2026-01-01', '2026-03-01', 1)");
    $db->exec("INSERT INTO subscription_payment_records (id, user_id, subscription_id, due_date, paid_at, amount_original, currency_id, currency_code_snapshot, main_currency_code_snapshot, fx_rate_to_main_snapshot, amount_main_snapshot, payment_method_id, status, note, created_at)
        VALUES
        (1, 1, 9, '2026-01-01', '2026-01-01', 100, 1, 'USD', 'USD', 1, 100, 1, 'paid', '', '2026-01-01 00:00:00'),
        (2, 1, 9, '2026-02-01', '2026-02-01', 100, 1, 'USD', 'USD', 1, 100, 1, 'paid', '', '2026-02-01 00:00:00')");

    wallos_recalculate_subscription_next_payment_from_history($db, 9, 1);
    $nextPayment = $db->querySingle('SELECT next_payment FROM subscriptions WHERE id = 9');
    wallos_ledger_assert_equal($nextPayment, '2026-03-01', '根据已支付账期应回算出最早未支付账期');
    wallos_ledger_print_ok('账期回算到最早未支付日期');

    $db->exec("INSERT INTO subscription_payment_records (id, user_id, subscription_id, due_date, paid_at, amount_original, currency_id, currency_code_snapshot, main_currency_code_snapshot, fx_rate_to_main_snapshot, amount_main_snapshot, payment_method_id, status, note, created_at)
        VALUES (3, 1, 9, '2026-03-01', '2026-03-01', 100, 1, 'USD', 'USD', 1, 100, 1, 'paid', '', '2026-03-01 00:00:00')");
    wallos_recalculate_subscription_next_payment_from_history($db, 9, 1);
    $nextPayment = $db->querySingle('SELECT next_payment FROM subscriptions WHERE id = 9');
    wallos_ledger_assert_equal($nextPayment, '2026-04-01', '新增历史实付后应推进到下一个未支付账期');
    wallos_ledger_print_ok('新增历史实付后自动推进下次扣费');

    $db->exec("DELETE FROM subscription_payment_records WHERE id = 3");
    wallos_recalculate_subscription_next_payment_from_history($db, 9, 1);
    $nextPayment = $db->querySingle('SELECT next_payment FROM subscriptions WHERE id = 9');
    wallos_ledger_assert_equal($nextPayment, '2026-03-01', '删除历史实付后应回退到对应未支付账期');
    wallos_ledger_print_ok('删除历史实付后自动回退下次扣费');

    $subscription = [
        'id' => 9,
        'price' => 100,
        'currency_id' => 1,
        'cycle' => 3,
        'frequency' => 1,
        'start_date' => '2026-01-01',
        'next_payment' => '2026-03-01',
    ];
    $priceRules = [
        [
            'rule_type' => 'one_time',
            'price' => 10,
            'currency_id' => 2,
            'start_date' => '2026-04-01',
            'enabled' => 1,
        ],
    ];
    $paidDueDates = wallos_get_subscription_paid_due_dates_from_records([
        ['due_date' => '2026-01-01'],
        ['due_date' => '2026-02-01'],
    ]);
    $forecast = wallos_build_subscription_future_payment_forecast(
        $db,
        $subscription,
        1,
        $priceRules,
        $paidDueDates,
        [1 => ['code' => 'USD'], 2 => ['code' => 'EUR']],
        ['metric_explanation_regular_price_source' => 'Regular subscription price', 'subscription_price_rule_one_time_summary' => '%s on due date %s'],
        6,
        new DateTime('2026-03-15'),
        new DateTime('2026-05-31')
    );

    wallos_ledger_assert_equal(count($forecast), 2, '预测列表应只包含窗口内的未支付账期');
    wallos_ledger_assert_equal($forecast[0]['due_date'], '2026-04-01', '预测列表应从当前窗口开始');
    wallos_ledger_assert_float($forecast[0]['amount_main'], 20, '特殊价格规则与汇率换算应生效到未来预测');
    wallos_ledger_assert_equal($forecast[1]['due_date'], '2026-05-01', '预测列表应继续生成后续账期');
    wallos_ledger_assert_float($forecast[1]['amount_main'], 100, '未命中规则时未来预测应回退到常规定价');
    wallos_ledger_print_ok('未来预测会跳过已支付账期并命中特殊价格规则');

    $records = wallos_get_subscription_payment_records($db, 9, 1, 0);
    $cashflow = wallos_build_subscription_yearly_cashflow($records, $forecast, 2026);
    wallos_ledger_assert_float($cashflow[0]['actual_total'], 100, '一月现金流应计入历史实付');
    wallos_ledger_assert_float($cashflow[3]['predicted_total'], 20, '四月现金流应计入预测付款');
    wallos_ledger_assert_float($cashflow[4]['predicted_total'], 100, '五月现金流应计入常规定价预测');
    wallos_ledger_print_ok('年度现金流按月份汇总正确');

    echo PHP_EOL . '支付账本回归测试全部通过。' . PHP_EOL;
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
