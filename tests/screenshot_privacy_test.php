<?php

require_once __DIR__ . '/../includes/screenshot_privacy.php';

function wallos_screenshot_privacy_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    wallos_screenshot_privacy_assert(
        wallos_screenshot_privacy_enabled(['screenshot_privacy_mode' => 1]),
        'Raw database setting 1 must enable screenshot privacy.'
    );
    wallos_screenshot_privacy_assert(
        wallos_screenshot_privacy_enabled(['screenshotPrivacyMode' => true]),
        'Normalized boolean setting must enable screenshot privacy.'
    );
    wallos_screenshot_privacy_assert(
        !wallos_screenshot_privacy_enabled([])
            && !wallos_screenshot_privacy_enabled(['screenshot_privacy_mode' => 0]),
        'Screenshot privacy must be disabled by default.'
    );

    $_SESSION = ['userId' => 77];
    $sessionSeedA = wallos_screenshot_privacy_seed();
    $sessionSeedB = wallos_screenshot_privacy_seed();
    wallos_screenshot_privacy_assert(
        $sessionSeedA === $sessionSeedB && preg_match('/^[a-f0-9]{64}$/', $sessionSeedA) === 1,
        'Session seed must be stable, random-looking and kept in the session.'
    );

    $seed = str_repeat('a1', 32);
    $fakeNameA = wallos_screenshot_privacy_fake_name(42, $seed, 'zh_cn');
    $fakeNameB = wallos_screenshot_privacy_fake_name(42, $seed, 'zh_cn');
    $fakeNameOther = wallos_screenshot_privacy_fake_name(43, $seed, 'zh_cn');
    wallos_screenshot_privacy_assert($fakeNameA === $fakeNameB, 'Fake name must be stable for a seed and identity.');
    wallos_screenshot_privacy_assert($fakeNameA !== $fakeNameOther, 'Different subscriptions should receive different fake names.');

    $fakeAmountA = wallos_screenshot_privacy_fake_amount('subscription:42', $seed);
    $fakeAmountB = wallos_screenshot_privacy_fake_amount('subscription:42', $seed);
    wallos_screenshot_privacy_assert(
        $fakeAmountA === $fakeAmountB && $fakeAmountA >= 4 && $fakeAmountA < 200,
        'Fake amount must be stable and remain in a plausible display range.'
    );

    $fakeIcon = wallos_screenshot_privacy_fake_icon('subscription:42', $seed);
    wallos_screenshot_privacy_assert(
        strpos($fakeIcon, 'data:image/svg+xml;charset=UTF-8,') === 0
            && strpos(rawurldecode($fakeIcon), '<svg') !== false,
        'Fake icon must be a self-contained SVG data URI.'
    );

    $realSubscription = [
        'id' => 42,
        'name' => 'TOP-SECRET-SERVICE',
        'subscription_name' => 'TOP-SECRET-SERVICE',
        'logo' => 'images/uploads/logos/top-secret.png',
        'logo_variant' => 'images/uploads/logos/top-secret-dark.png',
        'logo_text_color' => 'dark',
        'price' => 9876.54,
        'original_price' => 8765.43,
        'currency_code' => 'USD',
        'next_payment' => '2030-04-05',
        'cycle' => 3,
        'notes' => 'TOP-SECRET-NOTES',
        'notes_html' => '<p>TOP-SECRET-NOTES</p>',
        'url' => 'https://private.example.test/account',
        'detail_image' => 'https://private.example.test/receipt.png',
        'detail_image_urls' => ['https://private.example.test/one.png'],
        'uploaded_images' => [[
            'id' => 91,
            'original_name' => 'private-receipt.png',
            'thumbnail_url' => 'endpoints/media/subscriptionimage.php?id=91&variant=thumbnail',
            'preview_url' => 'endpoints/media/subscriptionimage.php?id=91&variant=preview',
            'original_url' => 'endpoints/media/subscriptionimage.php?id=91&variant=original',
        ]],
        'payment_records' => [[
            'id' => 8,
            'amount_original' => 9876.54,
            'amount_main_snapshot' => 9876.54,
            'note' => 'TOP-SECRET-PAYMENT-NOTE',
        ]],
        'price_rules' => [[
            'id' => 6,
            'price' => 4321.09,
            'note' => 'TOP-SECRET-RULE-NOTE',
        ]],
        'remaining_value' => [
            'remaining_value_main' => 3210.98,
            'value_source_summary' => 'TOP-SECRET-VALUE-SOURCE',
        ],
    ];
    $realSubscriptionSnapshot = $realSubscription;
    $maskedSubscription = wallos_screenshot_privacy_mask_subscription(
        $realSubscription,
        $seed,
        'zh_cn',
        'test-display'
    );

    wallos_screenshot_privacy_assert(
        $realSubscription === $realSubscriptionSnapshot,
        'Masking a subscription mutated the caller-owned real array.'
    );
    wallos_screenshot_privacy_assert(
        $maskedSubscription['id'] === 42
            && $maskedSubscription['next_payment'] === '2030-04-05'
            && $maskedSubscription['cycle'] === 3,
        'Masking changed non-sensitive identity or scheduling fields used by the UI.'
    );
    wallos_screenshot_privacy_assert(
        $maskedSubscription['name'] !== $realSubscription['name']
            && $maskedSubscription['price'] !== $realSubscription['price']
            && $maskedSubscription['notes'] !== $realSubscription['notes'],
        'Subscription name, amount and notes were not replaced.'
    );

    $aggregateSubscription = wallos_screenshot_privacy_mask_subscription([
        'id' => 99,
        'name' => 'AGGREGATE-SECRET',
        'price' => 123.45,
        'payment_total_main' => 7654.32,
        'payment_total_original' => [
            'amount' => 5432.10,
            'currency_code' => 'USD',
        ],
        'manual_cycle_used_value_main' => 3456.78,
    ], $seed, 'en', 'aggregate-test');
    $aggregateJson = json_encode($aggregateSubscription, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach (['7654.32', '5432.1', '3456.78'] as $aggregateCanary) {
        wallos_screenshot_privacy_assert(
            strpos($aggregateJson, $aggregateCanary) === false,
            'Expanded subscription metrics still contain a private amount: ' . $aggregateCanary
        );
    }
    wallos_screenshot_privacy_assert(
        strpos($maskedSubscription['logo'], 'data:image/svg+xml') === 0
            && $maskedSubscription['logo_variant'] === ''
            && $maskedSubscription['url'] === ''
            && $maskedSubscription['detail_image_urls'] === [],
        'Subscription media or external URL was not replaced safely.'
    );
    wallos_screenshot_privacy_assert(
        !empty($maskedSubscription['uploaded_images'][0]['screenshot_privacy_placeholder'])
            && strpos($maskedSubscription['uploaded_images'][0]['thumbnail_url'], 'data:image/svg+xml') === 0,
        'Uploaded subscription media did not become a local placeholder.'
    );

    $maskedJson = json_encode($maskedSubscription, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach ([
        'TOP-SECRET-SERVICE',
        'TOP-SECRET-NOTES',
        'TOP-SECRET-PAYMENT-NOTE',
        'TOP-SECRET-RULE-NOTE',
        'TOP-SECRET-VALUE-SOURCE',
        'top-secret.png',
        'private.example.test',
        'private-receipt.png',
        '9876.54',
        '8765.43',
        '4321.09',
        '3210.98',
    ] as $secretCanary) {
        wallos_screenshot_privacy_assert(
            strpos($maskedJson, $secretCanary) === false,
            'Masked subscription still contains secret canary: ' . $secretCanary
        );
    }

    $metricPayload = [
        'total' => 12345.67,
        'summary_cards' => [[
            'label' => 'Total',
            'value' => 12345.67,
            'format' => 'currency',
        ]],
        'item_groups' => [[
            'title' => 'Items',
            'items' => [[
                'subscription_id' => 42,
                'subscription_name' => 'METRIC-SECRET-SERVICE',
                'total_amount' => 2222.22,
                'amount_original' => 1111.11,
                'note' => 'METRIC-SECRET-NOTE',
                'rule_summary' => 'METRIC-SECRET-RULE at 1111.11',
                'due_date' => '2030-04-05',
            ]],
        ]],
    ];
    $metricSnapshot = $metricPayload;
    $maskedMetric = wallos_screenshot_privacy_mask_metric_payload($metricPayload, $seed, 'en');
    wallos_screenshot_privacy_assert($metricPayload === $metricSnapshot, 'Metric masking mutated its input payload.');
    wallos_screenshot_privacy_assert(
        $maskedMetric['total'] !== $metricPayload['total']
            && $maskedMetric['summary_cards'][0]['value'] !== $metricPayload['summary_cards'][0]['value']
            && $maskedMetric['item_groups'][0]['items'][0]['subscription_name'] !== 'METRIC-SECRET-SERVICE'
            && $maskedMetric['item_groups'][0]['items'][0]['due_date'] === '2030-04-05',
        'Metric payload did not mask amounts and names while preserving dates.'
    );
    $maskedMetricJson = json_encode($maskedMetric, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    wallos_screenshot_privacy_assert(
        strpos($maskedMetricJson, 'METRIC-SECRET') === false,
        'Masked metric payload still contains private names or notes.'
    );
    foreach (['12345.67', '2222.22', '1111.11'] as $privateAmount) {
        wallos_screenshot_privacy_assert(
            strpos($maskedMetricJson, $privateAmount) === false,
            'Masked metric payload still contains private amount: ' . $privateAmount
        );
    }

    $endpointSource = file_get_contents(__DIR__ . '/../endpoints/settings/screenshot_privacy_mode.php');
    wallos_screenshot_privacy_assert(
        strpos($endpointSource, "require_once '../../includes/validate_endpoint.php'") !== false
            && strpos($endpointSource, 'is_bool($value)') !== false
            && strpos($endpointSource, 'WHERE user_id = :userId') !== false,
        'Privacy setting endpoint must require CSRF/auth validation, a boolean, and user-scoped persistence.'
    );

    $defaultsSource = file_get_contents(__DIR__ . '/../includes/settings_defaults.php');
    $settingsSource = file_get_contents(__DIR__ . '/../includes/getsettings.php');
    wallos_screenshot_privacy_assert(
        strpos($defaultsSource, "'screenshot_privacy_mode' => 0") !== false
            && strpos($settingsSource, "['screenshotPrivacyMode']") !== false,
        'Screenshot privacy default or normalized settings mapping is missing.'
    );

    $headerSource = file_get_contents(__DIR__ . '/../includes/header.php');
    $settingsPageSource = file_get_contents(__DIR__ . '/../settings.php');
    $settingsScriptSource = file_get_contents(__DIR__ . '/../scripts/settings.js');
    $privacyScriptSource = file_get_contents(__DIR__ . '/../scripts/screenshot-privacy.js');
    $privacyStylesSource = file_get_contents(__DIR__ . '/../styles/screenshot-privacy.css');
    wallos_screenshot_privacy_assert(
        strpos($headerSource, "require_once 'screenshot_privacy.php'") !== false
            && strpos($headerSource, 'wallos-screenshot-privacy-enabled') !== false
            && strpos($headerSource, 'styles/screenshot-privacy.css') !== false
            && strpos($headerSource, 'scripts/screenshot-privacy.js') !== false
            && strpos($headerSource, 'window.WallosScreenshotPrivacyConfig') !== false
            && strpos($headerSource, "setcookie('wallosScreenshotPrivacy'") !== false
            && strpos($headerSource, 'seed: <?= json_encode($screenshotPrivacyClientSeed') !== false,
        'Authenticated pages must load privacy fail-closed styles and the derived browser display seed.'
    );
    wallos_screenshot_privacy_assert(
        strpos($settingsPageSource, 'id="screenshotprivacymode"') !== false
            && strpos($settingsPageSource, 'setScreenshotPrivacyMode()') !== false
            && strpos($settingsScriptSource, 'endpoints/settings/screenshot_privacy_mode.php') !== false
            && strpos($endpointSource, "setcookie('wallosScreenshotPrivacy'") !== false,
        'Display Settings must expose and persist the screenshot privacy toggle.'
    );
    foreach ([
        'open-edit-subscription',
        'open-add-subscription',
        'delete-subscription',
        'renew-subscription',
        'open-payment-history',
        'open-payment-modal',
        'open-subscription-image-viewer',
        'open-pages-manager',
        'export-payment-history',
        'export-subscription-calendar',
        '#export-json',
        '#export-csv',
        '#export-uploaded-images',
    ] as $blockedAction) {
        wallos_screenshot_privacy_assert(
            strpos($privacyScriptSource, $blockedAction) !== false,
            'Privacy runtime does not block sensitive action: ' . $blockedAction
        );
    }
    wallos_screenshot_privacy_assert(
        strpos($privacyScriptSource, 'MutationObserver') !== false
            && strpos($privacyScriptSource, 'BroadcastChannel') !== false
            && strpos($privacyScriptSource, 'wallos-screenshot-privacy-sync-v1') !== false
            && strpos($privacyScriptSource, 'event.persisted') !== false
            && strpos($privacyScriptSource, 'wallosScreenshotPrivacy=([01])') !== false
            && strpos($privacyScriptSource, 'sanitizeSubscription') !== false
            && strpos($privacyScriptSource, 'sanitizeChartData') !== false
            && strpos($privacyStylesSource, 'fail-closed') !== false
            && strpos($privacyStylesSource, 'visibility: hidden !important') !== false,
        'Privacy runtime must mask dynamic content and hide sensitive blocks before masking completes.'
    );

    $dashboardSource = file_get_contents(__DIR__ . '/../index.php');
    $subscriptionsSource = file_get_contents(__DIR__ . '/../subscriptions.php');
    $subscriptionFragmentSource = file_get_contents(__DIR__ . '/../endpoints/subscriptions/get.php');
    $calendarSource = file_get_contents(__DIR__ . '/../calendar.php');
    $calendarEndpointSource = file_get_contents(__DIR__ . '/../endpoints/subscription/getcalendar.php');
    $statsSource = file_get_contents(__DIR__ . '/../stats.php');
    $metricSource = file_get_contents(__DIR__ . '/../includes/metric_explanations.php');
    foreach ([$dashboardSource, $subscriptionsSource, $subscriptionFragmentSource, $calendarSource, $calendarEndpointSource, $statsSource, $metricSource] as $displaySource) {
        wallos_screenshot_privacy_assert(
            strpos($displaySource, 'wallos_screenshot_privacy_') !== false,
            'A subscription display path is missing server-side screenshot privacy handling.'
        );
    }
    wallos_screenshot_privacy_assert(
        strpos($calendarSource, "\$screenshotPrivacyEnabled ? ''") !== false,
        'Calendar must not retain the private API key in its DOM while screenshot privacy is enabled.'
    );
    wallos_screenshot_privacy_assert(
        strpos($statsSource, '$displayStatsSubtitleParts') !== false
            && strpos($statsSource, "wallos_screenshot_privacy_fake_group_label(") !== false,
        'Filtered statistics subtitles must not expose real member, category, or payment names.'
    );

    wallos_screenshot_privacy_assert(
        strpos($subscriptionFragmentSource, "\$pagePayload['pages'][\$pageIndex]['name']") !== false,
        'AJAX subscription refreshes must not restore real custom-page names.'
    );

    $profileExportSource = file_get_contents(__DIR__ . '/../endpoints/subscriptions/export.php');
    $imageExportSource = file_get_contents(__DIR__ . '/../endpoints/user/export_uploaded_images.php');
    $calendarExportSource = file_get_contents(__DIR__ . '/../endpoints/subscription/exportcalendar.php');
    foreach ([$profileExportSource, $imageExportSource, $calendarExportSource] as $exportSource) {
        wallos_screenshot_privacy_assert(
            strpos($exportSource, 'wallos_screenshot_privacy_enabled($settings)') !== false
                && strpos($exportSource, "http_response_code(409)") !== false,
            'A real-data export endpoint is not blocked while screenshot privacy is enabled.'
        );
    }

    wallos_screenshot_privacy_assert(
        strpos($headerSource, 'class="wallos-header-brand"') !== false
            && strpos($headerSource, 'wallos-header-edition') !== false
            && strpos($headerSource, '[Remastered]') !== false,
        'Authenticated Header must display the requested [Remastered] brand label.'
    );

    echo "Screenshot privacy helper tests passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
