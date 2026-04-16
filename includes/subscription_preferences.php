<?php

function wallos_normalize_subscription_display_columns_setting($value)
{
    $columns = (int) $value;
    return in_array($columns, [1, 2, 3], true) ? $columns : 1;
}

function wallos_normalize_subscription_image_layout_setting($value)
{
    $mode = trim((string) $value);
    return in_array($mode, ['focus', 'grid'], true) ? $mode : 'focus';
}

function wallos_normalize_subscription_value_visibility_setting($value)
{
    $decoded = is_array($value) ? $value : json_decode((string) $value, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    $legacyMetricsVisible = !(
        array_key_exists('invested', $decoded)
        && $decoded['invested'] === false
        && array_key_exists('remaining', $decoded)
        && $decoded['remaining'] === false
        && array_key_exists('used', $decoded)
        && $decoded['used'] === false
    );

    return [
        'metrics' => array_key_exists('metrics', $decoded) ? (bool) $decoded['metrics'] : $legacyMetricsVisible,
        'payment_records' => array_key_exists('payment_records', $decoded) ? (bool) $decoded['payment_records'] : true,
    ];
}

function wallos_get_subscription_page_preferences_payload(array $settings)
{
    return [
        'displayColumns' => wallos_normalize_subscription_display_columns_setting(
            $settings['subscriptionDisplayColumns'] ?? $settings['subscription_display_columns'] ?? 1
        ),
        'valueVisibility' => wallos_normalize_subscription_value_visibility_setting(
            $settings['subscriptionValueVisibility'] ?? $settings['subscription_value_visibility'] ?? ''
        ),
        'imageLayout' => [
            'form' => wallos_normalize_subscription_image_layout_setting(
                $settings['subscriptionImageLayoutForm'] ?? $settings['subscription_image_layout_form'] ?? 'focus'
            ),
            'detail' => wallos_normalize_subscription_image_layout_setting(
                $settings['subscriptionImageLayoutDetail'] ?? $settings['subscription_image_layout_detail'] ?? 'focus'
            ),
        ],
    ];
}
