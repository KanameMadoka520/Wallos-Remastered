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

function wallos_ledger_assert_throws(callable $callback, $expectedMessage, $message)
{
    try {
        $callback();
    } catch (Throwable $throwable) {
        if ($expectedMessage !== '' && strpos($throwable->getMessage(), $expectedMessage) === false) {
            throw new RuntimeException(
                $message . ' | expected exception containing: ' . $expectedMessage
                . ' got: ' . $throwable->getMessage()
            );
        }
        return;
    }

    throw new RuntimeException($message . ' | expected an exception but none was thrown');
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
    wallos_recalculate_subscription_next_payment_from_history($db, 9, 1, ['2026-03-01']);
    $nextPayment = $db->querySingle('SELECT next_payment FROM subscriptions WHERE id = 9');
    wallos_ledger_assert_equal($nextPayment, '2026-03-01', '删除历史实付后应回退到对应未支付账期');
    wallos_ledger_print_ok('删除历史实付后自动回退下次扣费');

    $db->exec("INSERT INTO subscriptions (id, user_id, name, price, currency_id, cycle, frequency, start_date, next_payment, payment_method_id)
        VALUES (15, 1, 'Partial Ledger Subscription', 60, 1, 3, 1, '2020-01-01', '2026-09-01', 1)");
    wallos_record_subscription_payment($db, 1, 15, '2025-01-01', '2025-01-01', 60, 1, 1);
    $partialLedgerCurrentRecordId = wallos_record_subscription_payment(
        $db,
        1,
        15,
        '2026-09-01',
        '2026-09-01',
        60,
        1,
        1
    );
    wallos_recalculate_subscription_next_payment_from_history($db, 15, 1, ['2026-09-01']);
    wallos_ledger_assert_equal(
        $db->querySingle('SELECT next_payment FROM subscriptions WHERE id = 15'),
        '2026-10-01',
        '部分账本只能从当前未付账期向前推进，不能退回订阅开始年份'
    );
    wallos_delete_subscription_payment_record($db, $partialLedgerCurrentRecordId, 15, 1);
    wallos_recalculate_subscription_next_payment_from_history($db, 15, 1, ['2026-09-01']);
    wallos_ledger_assert_equal(
        $db->querySingle('SELECT next_payment FROM subscriptions WHERE id = 15'),
        '2026-09-01',
        '删除当前付款时应只回退被重新打开的连续账期'
    );
    wallos_ledger_print_ok('多年老订阅的部分付款账本不会造成历史账期倒退');

    $db->exec("INSERT INTO subscriptions (id, user_id, name, price, currency_id, cycle, frequency, start_date, next_payment, payment_method_id)
        VALUES (16, 1, 'Strict Month-end Chain', 70, 1, 3, 1, '2026-01-31', '2026-04-30', 1)");
    wallos_record_subscription_payment($db, 1, 16, '2026-03-31', '2026-03-31', 70, 1, 1);
    $offScheduleRecordId = wallos_record_subscription_payment(
        $db,
        1,
        16,
        '2026-02-27',
        '2026-02-27',
        70,
        1,
        1
    );
    wallos_delete_subscription_payment_record($db, $offScheduleRecordId, 16, 1);
    wallos_recalculate_subscription_next_payment_from_history($db, 16, 1, ['2026-02-27']);
    wallos_ledger_assert_equal(
        $db->querySingle('SELECT next_payment FROM subscriptions WHERE id = 16'),
        '2026-04-30',
        '删除脱离月末日程的额外记录不得制造新的未付账期'
    );
    wallos_recalculate_subscription_next_payment_from_history($db, 16, 1, ['2026-02-28']);
    wallos_ledger_assert_equal(
        $db->querySingle('SELECT next_payment FROM subscriptions WHERE id = 16'),
        '2026-02-28',
        '真正的短月月末账期仍必须能够沿连续账本正确回退'
    );
    wallos_ledger_print_ok('账期回退只接受严格位于原日程上的日期');

    $recordsBeforeInvalidInsert = (int) $db->querySingle('SELECT COUNT(*) FROM subscription_payment_records');
    wallos_ledger_assert_throws(
        static function () use ($db) {
            wallos_record_subscription_payment($db, 1, 9, '2026-02-30', '2026-03-01', 100, 1, 1);
        },
        'Invalid due date.',
        '新增付款必须拒绝不存在的日期'
    );
    wallos_ledger_assert_equal(
        (int) $db->querySingle('SELECT COUNT(*) FROM subscription_payment_records'),
        $recordsBeforeInvalidInsert,
        '无效日期不得留下付款记录'
    );
    $recordBeforeInvalidUpdate = $db->querySingle("SELECT due_date || '|' || paid_at FROM subscription_payment_records WHERE id = 1");
    wallos_ledger_assert_throws(
        static function () use ($db) {
            wallos_update_subscription_payment_record($db, 1, 1, 9, '2026-01-01', '2026-02-30', 100, 1, 1);
        },
        'Invalid payment date.',
        '编辑付款必须拒绝不存在的日期'
    );
    wallos_ledger_assert_equal(
        $db->querySingle("SELECT due_date || '|' || paid_at FROM subscription_payment_records WHERE id = 1"),
        $recordBeforeInvalidUpdate,
        '无效编辑不得改写原付款记录'
    );
    wallos_ledger_print_ok('付款新增和编辑严格拒绝无效日历日期');

    $db->exec("INSERT INTO subscriptions (id, user_id, name, price, currency_id, cycle, frequency, start_date, next_payment, payment_method_id)
        VALUES (12, 1, 'One-time Purchase', 80, 1, 5, 1, '2026-09-15', '2026-09-15', 1)");
    $oneTimeSubscription = [
        'id' => 12,
        'price' => 80,
        'currency_id' => 1,
        'cycle' => 5,
        'frequency' => 1,
        'start_date' => '2026-09-15',
        'next_payment' => '2026-09-15',
    ];
    $oneTimeForecast = wallos_build_subscription_future_payment_forecast(
        $db,
        $oneTimeSubscription,
        1,
        [],
        [],
        [1 => ['code' => 'USD']],
        ['metric_explanation_regular_price_source' => 'Regular subscription price'],
        6,
        new DateTime('2026-09-01'),
        new DateTime('2027-12-31')
    );
    wallos_ledger_assert_equal(count($oneTimeForecast), 1, '未付款的一次性订阅在长预测窗口中也只能出现一次');
    wallos_ledger_assert_equal($oneTimeForecast[0]['due_date'], '2026-09-15', '一次性订阅必须保留其实际到期日');
    wallos_ledger_assert_equal(
        wallos_build_subscription_remaining_value_snapshot(
            $db,
            $oneTimeSubscription,
            1,
            [],
            [],
            [1 => ['code' => 'USD']],
            [],
            new DateTime('2026-09-01')
        )['available'],
        false,
        '一次性订阅不得伪造年度剩余价值周期'
    );

    $oneTimeRecordId = wallos_record_subscription_payment(
        $db,
        1,
        12,
        '2026-09-15',
        '2026-09-15',
        80,
        1,
        1
    );
    wallos_recalculate_subscription_next_payment_from_history($db, 12, 1);
    wallos_ledger_assert_equal(
        $db->querySingle('SELECT next_payment FROM subscriptions WHERE id = 12'),
        '2026-09-15',
        '记录一次性付款后不得把下次付款推进一年'
    );
    $oneTimeRecords = wallos_get_subscription_payment_records($db, 12, 1, 0);
    $paidOneTimeForecast = wallos_build_subscription_future_payment_forecast(
        $db,
        $oneTimeSubscription,
        1,
        [],
        wallos_get_subscription_paid_due_dates_from_records($oneTimeRecords),
        [1 => ['code' => 'USD']],
        ['metric_explanation_regular_price_source' => 'Regular subscription price'],
        6,
        new DateTime('2026-09-01'),
        new DateTime('2027-12-31')
    );
    wallos_ledger_assert_equal($paidOneTimeForecast, [], '已付款的一次性订阅不得继续出现在预测中');

    wallos_update_subscription_payment_record(
        $db,
        1,
        $oneTimeRecordId,
        12,
        '2026-09-15',
        '2026-09-16',
        80,
        1,
        1
    );
    wallos_recalculate_subscription_next_payment_from_history($db, 12, 1);
    wallos_ledger_assert_equal(
        $db->querySingle('SELECT next_payment FROM subscriptions WHERE id = 12'),
        '2026-09-15',
        '编辑一次性付款后不得制造续费日期'
    );
    wallos_delete_subscription_payment_record($db, $oneTimeRecordId, 12, 1);
    wallos_recalculate_subscription_next_payment_from_history($db, 12, 1);
    wallos_ledger_assert_equal(
        $db->querySingle('SELECT next_payment FROM subscriptions WHERE id = 12'),
        '2026-09-15',
        '删除一次性付款后仍应保留原始到期日'
    );
    wallos_ledger_print_ok('一次性付款的记录、编辑、删除和预测都不会伪造年度续费');

    $db->exec("INSERT INTO subscriptions (id, user_id, name, price, currency_id, cycle, frequency, start_date, next_payment, payment_method_id)
        VALUES (13, 1, 'Month-end Subscription', 100, 1, 3, 1, '2026-01-31', '2026-01-31', 1)");
    $januaryMonthEndRecordId = wallos_record_subscription_payment(
        $db,
        1,
        13,
        '2026-01-31',
        '2026-01-31',
        25,
        1,
        1
    );
    wallos_recalculate_subscription_next_payment_from_history($db, 13, 1);
    wallos_ledger_assert_equal(
        $db->querySingle('SELECT next_payment FROM subscriptions WHERE id = 13'),
        '2026-02-28',
        '1 月 31 日月付应夹紧到 2 月末'
    );
    $monthEndSubscription = [
        'id' => 13,
        'price' => 100,
        'currency_id' => 1,
        'cycle' => 3,
        'frequency' => 1,
        'auto_renew' => 1,
        'start_date' => '2026-01-31',
        'next_payment' => '2026-02-28',
    ];
    $monthEndRules = [
        [
            'rule_type' => 'first_n_cycles',
            'price' => 25,
            'currency_id' => 1,
            'max_cycles' => 2,
            'enabled' => 1,
        ],
    ];
    $monthEndForecast = wallos_build_subscription_future_payment_forecast(
        $db,
        $monthEndSubscription,
        1,
        $monthEndRules,
        [],
        [1 => ['code' => 'USD']],
        [
            'metric_explanation_regular_price_source' => 'Regular subscription price',
            'subscription_price_rule_first_cycles_summary' => '%s for first %d cycles',
        ],
        6,
        new DateTime('2026-02-01'),
        new DateTime('2026-04-30')
    );
    wallos_ledger_assert_equal(
        array_column($monthEndForecast, 'due_date'),
        ['2026-02-28', '2026-03-31', '2026-04-30'],
        '月末预测应在短月夹紧并在长月恢复原始日'
    );
    wallos_ledger_assert_float($monthEndForecast[0]['amount_main'], 25, '月末第 2 期仍应命中 first-N 特殊价格');
    wallos_ledger_assert_float($monthEndForecast[1]['amount_main'], 100, '月末第 3 期应恢复常规定价');

    $februaryMonthEndRecordId = wallos_record_subscription_payment(
        $db,
        1,
        13,
        '2026-02-28',
        '2026-02-28',
        25,
        1,
        1
    );
    wallos_recalculate_subscription_next_payment_from_history($db, 13, 1);
    wallos_ledger_assert_equal(
        $db->querySingle('SELECT next_payment FROM subscriptions WHERE id = 13'),
        '2026-03-31',
        '2 月末付款后必须恢复到 3 月 31 日'
    );
    wallos_delete_subscription_payment_record($db, $februaryMonthEndRecordId, 13, 1);
    wallos_recalculate_subscription_next_payment_from_history($db, 13, 1, ['2026-02-28']);
    wallos_ledger_assert_equal(
        $db->querySingle('SELECT next_payment FROM subscriptions WHERE id = 13'),
        '2026-02-28',
        '删除 2 月付款后必须回退到 2 月末而不是漂移到 3 月'
    );
    wallos_ledger_assert_equal($januaryMonthEndRecordId > 0, true, '月末首期付款必须成功写入账本');
    wallos_ledger_print_ok('月末付款推进、删除回退和特殊价格期次保持稳定');

    $db->exec("INSERT INTO subscriptions (id, user_id, name, price, currency_id, cycle, frequency, start_date, next_payment, payment_method_id)
        VALUES (14, 1, 'Leap-day Subscription', 120, 1, 4, 1, '2024-02-29', '2024-02-29', 1)");
    foreach (['2024-02-29', '2025-02-28', '2026-02-28', '2027-02-28'] as $leapDueDate) {
        wallos_record_subscription_payment($db, 1, 14, $leapDueDate, $leapDueDate, 120, 1, 1);
        wallos_recalculate_subscription_next_payment_from_history($db, 14, 1);
    }
    wallos_ledger_assert_equal(
        $db->querySingle('SELECT next_payment FROM subscriptions WHERE id = 14'),
        '2028-02-29',
        '闰日年付在平年夹紧后必须于闰年恢复到 2 月 29 日'
    );
    wallos_ledger_print_ok('闰日年付账期在短年夹紧并于闰年恢复');

    $subscription = [
        'id' => 9,
        'price' => 100,
        'currency_id' => 1,
        'cycle' => 3,
        'frequency' => 1,
        'auto_renew' => 1,
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

    $manualRenewalSubscription = $subscription;
    $manualRenewalSubscription['auto_renew'] = 0;
    $manualRenewalSubscription['next_payment'] = '2026-04-01';
    $manualRenewalForecast = wallos_build_subscription_future_payment_forecast(
        $db,
        $manualRenewalSubscription,
        1,
        [],
        [],
        [1 => ['code' => 'USD']],
        ['metric_explanation_regular_price_source' => 'Regular subscription price'],
        12,
        new DateTime('2026-03-15'),
        new DateTime('2027-03-31')
    );
    wallos_ledger_assert_equal(
        array_column($manualRenewalForecast, 'due_date'),
        ['2026-04-01'],
        '手动续费在长预测窗口中也只能显示明确保存的下一期'
    );
    $paidManualRenewalForecast = wallos_build_subscription_future_payment_forecast(
        $db,
        $manualRenewalSubscription,
        1,
        [],
        ['2026-04-01' => true],
        [1 => ['code' => 'USD']],
        ['metric_explanation_regular_price_source' => 'Regular subscription price'],
        12,
        new DateTime('2026-03-15'),
        new DateTime('2027-03-31')
    );
    wallos_ledger_assert_equal(
        $paidManualRenewalForecast,
        [],
        '手动续费的明确账期已付款后不得继续制造后续账期'
    );
    unset($manualRenewalSubscription['auto_renew']);
    $missingRenewalForecast = wallos_build_subscription_future_payment_forecast(
        $db,
        $manualRenewalSubscription,
        1,
        [],
        [],
        [1 => ['code' => 'USD']],
        ['metric_explanation_regular_price_source' => 'Regular subscription price'],
        12,
        new DateTime('2026-03-15'),
        new DateTime('2027-03-31')
    );
    wallos_ledger_assert_equal(
        array_column($missingRenewalForecast, 'due_date'),
        ['2026-04-01'],
        '缺少续费字段时必须保守地停在明确保存的账期'
    );
    $paymentHistoryEndpoint = file_get_contents(__DIR__ . '/../endpoints/subscription/paymenthistory.php');
    wallos_ledger_assert_equal(
        preg_match('/SELECT\\s+id,.*\\bauto_renew\\b.*FROM subscriptions/s', (string) $paymentHistoryEndpoint),
        1,
        '付款历史接口必须读取续费模式'
    );
    wallos_ledger_print_ok('手动续费和缺失续费字段不会被无限预测');

    $db->exec("INSERT INTO subscription_payment_records (id, user_id, subscription_id, due_date, paid_at, amount_original, currency_id, currency_code_snapshot, main_currency_code_snapshot, fx_rate_to_main_snapshot, amount_main_snapshot, payment_method_id, status, note, created_at)
        VALUES (104, 1, 11, '2027-01-15', '2026-08-01', 50, 1, 'USD', 'USD', 1, 50, 1, 'paid', '', '2026-08-01 00:00:00')");
    $prepaidFutureDueDates = wallos_get_paid_due_dates_map(
        $db,
        1,
        '2027-01-01',
        '2027-01-31',
        true,
        [11]
    );
    wallos_ledger_assert_equal(
        !empty($prepaidFutureDueDates[11]['2027-01-15']),
        true,
        '提前支付的未来账期必须按 due_date 从预测中排除'
    );
    wallos_ledger_assert_equal(
        wallos_get_paid_due_dates_map($db, 1, '2027-01-01', '2027-01-31', true, [9]),
        [],
        '未来已付账期映射仍必须尊重订阅筛选范围'
    );
    wallos_ledger_print_ok('提前支付记录按到期日排除且保持订阅隔离');

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
