<?php

require_once __DIR__ . '/../includes/subscription_preferences.php';

function wallos_subscription_preferences_assert_same($actual, $expected, $message)
{
    if ($actual !== $expected) {
        throw new RuntimeException(
            $message . ' | expected: ' . var_export($expected, true) . ' got: ' . var_export($actual, true)
        );
    }
}

function wallos_subscription_preferences_print_ok($message)
{
    echo '[OK] ' . $message . PHP_EOL;
}

try {
    wallos_subscription_preferences_assert_same(
        wallos_normalize_subscription_display_columns_setting(3),
        3,
        'display columns keep supported values'
    );
    wallos_subscription_preferences_assert_same(
        wallos_normalize_subscription_display_columns_setting(9),
        1,
        'display columns fallback to 1'
    );
    wallos_subscription_preferences_print_ok('display columns normalization');

    wallos_subscription_preferences_assert_same(
        wallos_normalize_subscription_image_layout_setting('grid'),
        'grid',
        'image layout keeps grid'
    );
    wallos_subscription_preferences_assert_same(
        wallos_normalize_subscription_image_layout_setting('unknown'),
        'focus',
        'image layout fallback to focus'
    );
    wallos_subscription_preferences_print_ok('image layout normalization');

    $legacyVisibility = wallos_normalize_subscription_value_visibility_setting('{"invested":false,"remaining":false,"used":false}');
    wallos_subscription_preferences_assert_same(
        $legacyVisibility['metrics'],
        false,
        'legacy hidden metrics map to metrics false'
    );
    wallos_subscription_preferences_assert_same(
        $legacyVisibility['payment_records'],
        true,
        'payment records default to true when missing'
    );

    $normalizedVisibility = wallos_normalize_subscription_value_visibility_setting([
        'metrics' => false,
        'payment_records' => false,
    ]);
    wallos_subscription_preferences_assert_same(
        $normalizedVisibility['metrics'],
        false,
        'metrics visibility keeps explicit false'
    );
    wallos_subscription_preferences_assert_same(
        $normalizedVisibility['payment_records'],
        false,
        'payment record visibility keeps explicit false'
    );
    wallos_subscription_preferences_print_ok('visibility normalization');

    $payload = wallos_get_subscription_page_preferences_payload([
        'subscription_display_columns' => 2,
        'subscription_value_visibility' => '{"metrics":true,"payment_records":false}',
        'subscription_image_layout_form' => 'grid',
        'subscription_image_layout_detail' => 'focus',
    ]);
    wallos_subscription_preferences_assert_same(
        $payload['displayColumns'],
        2,
        'payload exposes normalized display columns'
    );
    wallos_subscription_preferences_assert_same(
        $payload['valueVisibility']['payment_records'],
        false,
        'payload exposes normalized payment record visibility'
    );
    wallos_subscription_preferences_assert_same(
        $payload['imageLayout']['form'],
        'grid',
        'payload exposes normalized form image layout'
    );
    wallos_subscription_preferences_print_ok('payload generation');

    echo PHP_EOL . 'Subscription preference regression checks passed.' . PHP_EOL;
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
