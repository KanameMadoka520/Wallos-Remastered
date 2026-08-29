<?php

require_once __DIR__ . '/../includes/timezone_settings.php';

function wallos_timezone_settings_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
try {
    $allTimezones = timezone_identifiers_list();
    $supportedTimezoneSet = wallos_get_supported_timezone_set();

    wallos_timezone_settings_assert(
        count($supportedTimezoneSet) === count($allTimezones)
            && array_keys($supportedTimezoneSet) === $allTimezones,
        'The cached timezone lookup set must preserve PHP timezone identifiers exactly.'
    );
    wallos_timezone_settings_assert(
        wallos_is_supported_timezone($allTimezones[0])
            && wallos_is_supported_timezone($allTimezones[count($allTimezones) - 1])
            && !wallos_is_supported_timezone('Not/A_Timezone'),
        'Timezone membership checks must accept the complete PHP list and reject unknown values.'
    );
    wallos_timezone_settings_assert(
        wallos_normalize_timezone_identifier('  Europe/London  ') === 'Europe/London'
            && wallos_normalize_timezone_identifier('Not/A_Timezone', 'UTC') === 'UTC'
            && wallos_normalize_timezone_identifier('', 'Custom/Fallback') === 'Custom/Fallback',
        'Timezone normalization must preserve trimming and fallback behavior.'
    );

    $reference = new DateTimeImmutable('2026-06-15 12:00:00', new DateTimeZone('UTC'));
    wallos_timezone_settings_assert(
        wallos_get_timezone_offset_label('Asia/Shanghai', $reference) === 'UTC+08:00'
            && wallos_build_timezone_label('America/New_York', $reference) === '(UTC-04:00) America/New_York',
        'Timezone offset and display labels must retain their existing format.'
    );

    $preferredTimezones = [
        wallos_get_default_user_timezone(),
        'UTC',
        'Asia/Tokyo',
        'America/Los_Angeles',
        'America/New_York',
        'Europe/London',
        'Europe/Berlin',
    ];
    $expectedOrder = [];
    foreach (array_merge($preferredTimezones, $allTimezones) as $timezone) {
        $normalizedTimezone = in_array($timezone, $allTimezones, true)
            ? $timezone
            : wallos_get_default_user_timezone();
        $expectedOrder[$normalizedTimezone] = $normalizedTimezone;
    }

    $options = wallos_get_timezone_options('America/New_York');
    $actualOrder = array_column($options, 'value');
    wallos_timezone_settings_assert(
        $actualOrder === array_values($expectedOrder),
        'Optimized timezone options must keep the preferred-first order and de-duplication behavior.'
    );
    wallos_timezone_settings_assert(
        count(array_filter($options, function ($option) {
            return !empty($option['selected']);
        })) === 1,
        'Timezone options must select exactly the requested supported timezone.'
    );
    foreach ($options as $option) {
        wallos_timezone_settings_assert(
            isset($option['value'], $option['label'], $option['selected'])
                && substr($option['label'], -strlen($option['value'])) === $option['value'],
            'Every timezone option must retain its value, label, and selected fields.'
        );
    }

    $fallbackOptions = wallos_get_timezone_options('Not/A_Timezone');
    $fallbackSelected = array_values(array_filter($fallbackOptions, function ($option) {
        return !empty($option['selected']);
    }));
    wallos_timezone_settings_assert(
        count($fallbackSelected) === 1
            && $fallbackSelected[0]['value'] === wallos_get_default_user_timezone(),
        'Unknown selected timezones must continue to fall back to the default timezone.'
    );

    $source = file_get_contents(__DIR__ . '/../includes/timezone_settings.php');
    wallos_timezone_settings_assert(
        is_string($source)
            && strpos($source, 'static $supportedTimezoneSet = null;') !== false
            && strpos($source, 'array_fill_keys(timezone_identifiers_list(), true)') !== false
            && strpos($source, 'isset($supportedTimezoneSet[(string) $timezone])') !== false,
        'Timezone validation must retain the cached O(1) membership lookup contract.'
    );

    echo "Timezone settings compatibility and performance checks passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
