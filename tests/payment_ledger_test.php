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
        payment_method_id INTEGER,
        exclude_from_stats INTEGER DEFAULT 0,
        manual_cycle_used_value_main REAL DEFAULT 0,
        manual_cycle_used_value_cycle_start TEXT DEFAULT ""
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
    $db->exec("INSERT INTO subscriptions (id, user_id, name, price, currency_id, cycle, frequency, start_date, next_payment, payment_method_id)
        VALUES (11, 1, 'Scoped Subscription', 50, 1, 3, 1, '2026-01-01', '2026-03-01', 1)");
    $db->exec("INSERT INTO subscription_payment_records (id, user_id, subscription_id, due_date, paid_at, amount_original, currency_id, currency_code_snapshot, main_currency_code_snapshot, fx_rate_to_main_snapshot, amount_main_snapshot, payment_method_id, status, note, created_at)
        VALUES
        (1, 1, 9, '2026-01-01', '2026-01-01', 100, 1, 'USD', 'USD', 1, 100, 1, 'paid', '', '2026-01-01 00:00:00'),
        (2, 1, 9, '2026-02-01', '2026-02-01', 100, 1, 'USD', 'USD', 1, 100, 1, 'paid', '', '2026-02-01 00:00:00')");
    $db->exec("INSERT INTO subscription_payment_records (id, user_id, subscription_id, due_date, paid_at, amount_original, currency_id, currency_code_snapshot, main_currency_code_snapshot, fx_rate_to_main_snapshot, amount_main_snapshot, payment_method_id, status, note, created_at)
        VALUES (3, 1, 11, '2026-02-15', '2026-02-15', 50, 1, 'USD', 'USD', 1, 50, 1, 'paid', '', '2026-02-15 00:00:00')");

    wallos_ledger_assert_float(
        wallos_get_subscription_payment_total($db, 1, '2026-01-01', '2026-12-31', true, [11]),
        50,
        '筛选后的实付总额只能包含选中的订阅'
    );
    $scopedRecords = wallos_get_subscription_payment_records_for_period(
        $db,
        1,
        '2026-01-01',
        '2026-12-31',
        true,
        [11]
    );
    wallos_ledger_assert_equal(count($scopedRecords), 1, '筛选后的实付记录只能返回选中的订阅');
    wallos_ledger_assert_float(
        wallos_get_subscription_payment_total($db, 1, '2026-01-01', '2026-12-31', true, []),
        0,
        '空筛选结果不应回退为用户全部实付'
    );
    wallos_ledger_print_ok('统计筛选后的实际付款范围正确');

    $db->exec("INSERT INTO user (id, main_currency) VALUES (2, 22)");
    $db->exec("INSERT INTO currencies (id, user_id, code, rate) VALUES (21, 2, 'EUR', 1.0)");
    $db->exec("INSERT INTO currencies (id, user_id, code, rate) VALUES (22, 2, 'CNY', 1.0)");
    $db->exec("INSERT INTO subscriptions (id, user_id, name, price, currency_id, cycle, frequency, start_date, next_payment, payment_method_id)
        VALUES (10, 2, 'EUR Subscription', 21.61, 21, 3, 1, '2026-06-01', '2026-06-01', 1)");

    wallos_record_subscription_payment($db, 2, 10, '2026-06-01', '2026-06-01', 21.61, 21, 1);
    $missingRateRecords = wallos_get_subscription_payment_records($db, 10, 2, 0);
    wallos_ledger_assert_equal($missingRateRecords[0]['currency_code_snapshot'], 'EUR', '实付记录应保留原始扣款币种快照');
    wallos_ledger_assert_equal($missingRateRecords[0]['main_currency_conversion_available'], false, '非主货币且汇率仍为默认 1 时不应展示误导性的主货币折算');
    wallos_ledger_print_ok('缺少汇率时账本保留原币种并标记主货币折算不可用');

    $missingRateOriginalTotal = wallos_build_single_currency_original_total($missingRateRecords, 'amount_original', 'currency_code_snapshot');
    wallos_ledger_assert_equal($missingRateOriginalTotal['available'], true, 'Missing-rate summaries should be able to fall back to one original currency');
    wallos_ledger_assert_equal($missingRateOriginalTotal['currency_code'], 'EUR', 'Missing-rate summary fallback should keep EUR');
    wallos_ledger_assert_float($missingRateOriginalTotal['amount'], 21.61, 'Missing-rate summary fallback should sum original EUR amounts');
    wallos_ledger_assert_equal(wallos_all_payment_main_conversions_are_available($missingRateRecords), false, 'Missing-rate summary should mark main-currency totals unavailable');
    wallos_ledger_print_ok('缺少汇率时摘要汇总回退到原币种');

    $missingRateSubscription = [
        'id' => 10,
        'price' => 21.61,
        'currency_id' => 21,
        'cycle' => 3,
        'frequency' => 1,
        'start_date' => '2026-06-01',
        'next_payment' => '2026-07-01',
    ];
    $missingRateRemainingValue = wallos_build_subscription_remaining_value_snapshot(
        $db,
        $missingRateSubscription,
        2,
        [],
        $missingRateRecords,
        [21 => ['code' => 'EUR'], 22 => ['code' => 'CNY']],
        [
            'metric_explanation_regular_price_source' => 'Regular subscription price',
            'subscription_remaining_value_source_record' => 'Based on the actual payment recorded for the current cycle',
            'subscription_remaining_value_source_rule' => 'Estimated from the current pricing rules for the active cycle',
            'subscription_remaining_value_mode_time' => 'Uses the time-prorated remaining value of the active cycle',
            'subscription_remaining_value_mode_hybrid' => 'Uses the smaller of time-prorated value and manually remaining quota value',
        ],
        new DateTime('2026-06-16')
    );
    wallos_ledger_assert_equal($missingRateRemainingValue['main_currency_conversion_available'], false, 'Missing-rate remaining value should mark main conversion unavailable');
    wallos_ledger_assert_equal($missingRateRemainingValue['remaining_value_original_available'], true, 'Missing-rate remaining value should expose original-currency fallback');
    wallos_ledger_assert_equal($missingRateRemainingValue['currency_code'], 'EUR', 'Missing-rate remaining value fallback should keep EUR');
    wallos_ledger_assert_float($missingRateRemainingValue['remaining_value_original'], 10.81, 'Missing-rate remaining value should calculate original-currency remainder');
    wallos_ledger_print_ok('缺少汇率时剩余价值回退到原币种');

    $db->exec("UPDATE currencies SET rate = 0.125 WHERE id = 21 AND user_id = 2");
    wallos_record_subscription_payment($db, 2, 10, '2026-07-01', '2026-07-01', 21.61, 21, 1);
    $convertedRateRecords = wallos_get_subscription_payment_records($db, 10, 2, 0);
    wallos_ledger_assert_equal($convertedRateRecords[0]['main_currency_conversion_available'], true, '存在有效非默认汇率时可以展示主货币折算');
    wallos_ledger_assert_float($convertedRateRecords[0]['amount_main_snapshot'], 172.88, '有效汇率应写入主货币折算快照');
    wallos_ledger_print_ok('有效汇率下主货币折算快照可用');

    wallos_recalculate_subscription_next_payment_from_history($db, 9, 1);
    $nextPayment = $db->querySingle('SELECT next_payment FROM subscriptions WHERE id = 9');
    wallos_ledger_assert_equal($nextPayment, '2026-03-01', '根据已支付账期应回算出最早未支付账期');
    wallos_ledger_print_ok('账期回算到最早未支付日期');

    $db->exec("INSERT INTO subscription_payment_records (id, user_id, subscription_id, due_date, paid_at, amount_original, currency_id, currency_code_snapshot, main_currency_code_snapshot, fx_rate_to_main_snapshot, amount_main_snapshot, payment_method_id, status, note, created_at)
        VALUES (103, 1, 9, '2026-03-01', '2026-03-01', 100, 1, 'USD', 'USD', 1, 100, 1, 'paid', '', '2026-03-01 00:00:00')");
    wallos_recalculate_subscription_next_payment_from_history($db, 9, 1);
    $nextPayment = $db->querySingle('SELECT next_payment FROM subscriptions WHERE id = 9');
    wallos_ledger_assert_equal($nextPayment, '2026-04-01', '新增历史实付后应推进到下一个未支付账期');
    wallos_ledger_print_ok('新增历史实付后自动推进下次扣费');

    $db->exec("DELETE FROM subscription_payment_records WHERE id = 103");
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

    $shortRangeForecast = wallos_build_subscription_future_payment_forecast(
        $db,
        $subscription,
        1,
        $priceRules,
        $paidDueDates,
        [1 => ['code' => 'USD'], 2 => ['code' => 'EUR']],
        ['metric_explanation_regular_price_source' => 'Regular subscription price', 'subscription_price_rule_one_time_summary' => '%s on due date %s'],
        6,
        new DateTime('2026-03-15'),
        new DateTime('2026-04-15')
    );
    wallos_ledger_assert_equal(count($shortRangeForecast), 1, '较短预测范围应只保留窗口内账期');
    wallos_ledger_assert_equal($shortRangeForecast[0]['due_date'], '2026-04-01', '较短预测范围应命中窗口内的应付日期');
    wallos_ledger_print_ok('预测范围切换会影响未来账期列表');

    $records = wallos_get_subscription_payment_records($db, 9, 1, 0);
    $availableYears = wallos_build_subscription_payment_history_available_years($subscription, $records, new DateTime('2026-03-15'));
    wallos_ledger_assert_equal($availableYears, [2027, 2026], '账本年份列表应覆盖当前年与下一年，并按倒序返回');
    wallos_ledger_print_ok('账本年份切换列表生成正确');

    $paymentTotalMap = wallos_get_subscription_payment_total_map($db, 1);
    wallos_ledger_assert_float($paymentTotalMap[9], 200, '同一订阅的累计投入成本应按全部实付账本累加');
    wallos_ledger_print_ok('累计投入成本映射正确');

    $remainingValueSubscription = $subscription;
    $remainingValueSubscription['next_payment'] = '2026-05-01';
    $remainingValueRecords = [
        [
            'due_date' => '2026-04-01',
            'amount_main_snapshot' => 20,
            'amount_original' => 10,
            'currency_code_snapshot' => 'EUR',
            'status' => 'paid',
        ],
    ];
    $remainingValue = wallos_build_subscription_remaining_value_snapshot(
        $db,
        $remainingValueSubscription,
        1,
        $priceRules,
        $remainingValueRecords,
        [1 => ['code' => 'USD'], 2 => ['code' => 'EUR']],
        [
            'metric_explanation_regular_price_source' => 'Regular subscription price',
            'subscription_remaining_value_source_record' => 'Based on the actual payment recorded for the current cycle',
            'subscription_remaining_value_source_rule' => 'Estimated from the current pricing rules for the active cycle',
            'subscription_price_rule_one_time_summary' => '%s on due date %s',
        ],
        new DateTime('2026-04-16')
    );
    wallos_ledger_assert_float($remainingValue['current_cycle_value_main'], 20, '当前周期价值应优先采用本期实际支付记录');
    wallos_ledger_assert_float($remainingValue['remaining_value_main'], 10, '剩余价值应按当前周期剩余时间折算');
    wallos_ledger_assert_equal($remainingValue['remaining_days'], 15, '剩余天数应按到期时间折算');
    wallos_ledger_print_ok('剩余价值折算正确');

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
