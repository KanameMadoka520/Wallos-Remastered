<?php

function wallos_regression_build_suite_catalog()
{
    return array(
        'public' => array(
            'label' => 'Public smoke checks',
            'description' => 'Checks health, login, registration, theme-color, and service-worker contracts.',
            'checks' => array(
                'health-endpoint' => 'health.php returns HTTP 200 and body OK',
                'login-theme-color' => 'login.php responds and exposes meta[name="theme-color"]',
                'login-default-purple-theme' => 'login.php defaults to the Remastered purple theme for new visitors',
                'registration-theme-color' => 'registration.php responds and exposes meta[name="theme-color"]',
                'registration-default-purple-theme' => 'registration.php defaults to the Remastered purple theme for new visitors',
                'service-worker-registration' => 'scripts/all.js still registers service-worker.js',
                'service-worker-cache-contract' => 'service-worker.js still declares cache version constants',
                'service-worker-dynamic-cache-guard' => 'service-worker.js does not fuzzy-cache endpoints, private media, or query-string variants',
            ),
        ),
        'auth' => array(
            'label' => 'Authenticated smoke checks',
            'description' => 'Checks login reuse, subscription-page JSON payloads, and authenticated pagination HTML.',
            'checks' => array(
                'login-or-cookie' => 'Reuse existing cookies or login with supplied credentials',
                'subscriptions-unauth-401' => 'subscriptions/get.php returns the standardized unauthenticated JSON 401 contract',
                'subscription-pages-unauth-401' => 'subscriptionpages.php returns the standardized unauthenticated JSON 401 contract',
                'payments-unauth-401' => 'payments/get.php returns the standardized unauthenticated JSON 401 contract',
                'subscriptions-export-unauth-401' => 'subscriptions/export.php returns the standardized unauthenticated JSON 401 contract',
                'subscriptions-page-shell' => 'subscriptions.php opens as an authenticated browser shell without warnings',
                'subscription-pages-json' => 'subscriptionpages.php returns the expected JSON shape',
                'subscription-pages-invalid-csrf' => 'subscriptionpages.php returns the standardized invalid_csrf JSON contract',
                'subscriptions-html' => 'subscriptions/get.php returns HTML for subscription_page=all',
                'subscription-action-hooks' => 'subscription cards keep action-menu, edit, payment, and image-viewer hooks',
                'subscription-edit-json' => 'subscription/get.php returns editable JSON for a visible subscription',
                'subscription-payment-history-json' => 'subscription/paymenthistory.php returns payment-history JSON for a visible subscription',
                'subscription-media-access' => 'subscription uploaded image preview/original endpoints remain access-controlled and readable',
                'subscription-mutating-flow' => 'optional create/edit/payment/delete flow works when --mutating-auth-checks is enabled',
            ),
        ),
        'static' => array(
            'label' => 'Static contract checks',
            'description' => 'Checks high-risk frontend, theme, API, and media contracts without requiring a login.',
            'checks' => array(
                'default-theme-contract' => 'New users keep the purple theme and Blue Archive transition defaults',
                'remastered-version-contract' => 'About page identifies the v5.4.5 compatibility baseline and Remastered build',
                'declarative-oidc-contract' => 'OIDC environment, issuer discovery, secret redaction, and managed-field behavior stay wired',
                'period-summary-notification-contract' => 'Payment-period summaries keep migration, UI, calculation, and channel delivery wiring',
                'upstream-5-4-5-ical-contract' => 'iCalendar export applies user-scoped currency conversion and RFC 5545 escaping',
                'subscription-page-dom-contract' => 'subscriptions.php keeps the critical controls and modal anchors',
                'subscription-module-load-order' => 'subscriptions.php loads subscription modules in dependency order',
                'subscription-frontend-lifecycle-contract' => 'subscription page scripts keep shared request and rebind lifecycle hooks',
                'csrf-refresh-reminder-contract' => 'Long-idle pages and invalid CSRF responses show a persistent refresh reminder instead of a generic error',
                'csrf-footer-fingerprint-contract' => 'page footer shows a short CSRF token fingerprint and estimated expiry without exposing the raw token',
                'service-worker-refresh-contract' => 'admin can publish client cache refresh notices and static assets use stricter versioning',
                'api-key-transport-contract' => 'API credentials still prefer headers/POST and strip query-string api_key',
                'subscription-image-original-passthrough-contract' => 'Uncompressed subscription image uploads keep original bytes instead of re-encoding originals',
                'subscription-image-size-contract' => 'subscription image viewer keeps thumbnail/preview/original size slots',
                'maintenance-tools-contract' => 'admin maintenance tools expose retention strategy, image audit, and SQLite maintenance',
                'subscription-browser-e2e-contract' => 'browser E2E script keeps high-risk subscription interactions and diagnostic artifacts',
                'client-cache-browser-e2e-contract' => 'browser E2E script keeps client cache refresh prompt persistence and layout coverage',
                'admin-browser-e2e-contract' => 'browser E2E script keeps high-risk admin maintenance, logs, cache, and backup interactions',
                'admin-observability-contract' => 'admin runtime observability keeps anomaly summaries, filtered browsers, and safe log rendering',
            ),
        ),
        'legacy' => array(
            'label' => 'Existing PHP regression tests',
            'description' => 'Runs the existing logic regression scripts and folds them into the unified summary.',
            'checks' => array(
                'budget-regression' => 'Execute tests/budget_regression_test.php',
                'budget-period' => 'Execute tests/budget_period_test.php',
                'currency-rates' => 'Execute tests/currency_rates_test.php',
                'currency-update-schedule' => 'Execute tests/currency_update_schedule_test.php',
                'ical-helper' => 'Execute tests/ical_helper_test.php',
                'migration-079' => 'Execute tests/migration_079_test.php',
                'migration-080' => 'Execute tests/migration_080_test.php',
                'declarative-oidc' => 'Execute tests/oidc_declarative_test.php',
                'period-summary-notifications' => 'Execute tests/period_summary_notifications_test.php',
                'themed-logo-variants' => 'Execute tests/logo_theme_variant_test.php',
                'one-time-migration' => 'Execute tests/one_time_migration_test.php',
                'subscription-index' => 'Execute tests/subscription_index_test.php',
                'smtp-ssrf' => 'Execute tests/ssrf_smtp_test.php',
                'database-bootstrap-verification' => 'Execute tests/database_bootstrap_verification_test.php',
                'database-runtime-lock' => 'Execute tests/database_runtime_lock_test.php',
                'request-log-completion-safety' => 'Execute tests/request_log_completion_safety_test.php',
                'legacy-migration-safety' => 'Execute tests/legacy_migration_safety_test.php',
                'restore-migration' => 'Execute tests/restore_migration_test.php',
                'restore-atomicity' => 'Execute tests/restore_atomicity_test.php',
                'restore-transaction' => 'Execute tests/restore_transaction_test.php',
                'backup-manifest-verification' => 'Execute tests/backup_manifest_verification_test.php',
                'startup-safety' => 'Execute tests/startup_safety_contract_test.php',
                'startup-process-supervision' => 'Execute tests/startup_process_supervision_test.php',
                'upstream-security' => 'Execute tests/upstream_security_contract_test.php',
                'calendar-calculations' => 'Execute tests/calendar_calculations_test.php',
                'payment-ledger' => 'Execute tests/payment_ledger_test.php',
                'subscription-preferences' => 'Execute tests/subscription_preferences_test.php',
                'subscription-image-maintenance' => 'Execute tests/subscription_image_maintenance_test.php',
                'csrf-ttl' => 'Execute tests/csrf_ttl_test.php',
                'sqlite-index-contract' => 'Execute tests/sqlite_index_contract_test.php',
                'maintenance-action-log-contract' => 'Execute tests/maintenance_action_log_contract_test.php',
                'database-busy-contract' => 'Execute tests/database_busy_contract_test.php',
                'subscription-empty-sort' => 'Execute tests/subscription_empty_sort_test.php',
                'i18n-contract' => 'Execute tests/i18n_contract_test.php',
            ),
        ),
    );
}

function wallos_regression_parse_cli_config(array $argv, array $catalog)
{
    $config = array(
        'command_name' => basename((string) ($argv[0] ?? 'regression_runner.php')),
        'repo_root' => dirname(__DIR__, 2),
        'tests_root' => dirname(__DIR__),
        'base_url' => trim((string) getenv('WALLOS_BASE_URL')),
        'cookie' => trim((string) getenv('WALLOS_TEST_COOKIE')),
        'username' => trim((string) getenv('WALLOS_TEST_USERNAME')),
        'password' => trim((string) getenv('WALLOS_TEST_PASSWORD')),
        'timeout' => (int) (getenv('WALLOS_TEST_TIMEOUT') !== false ? getenv('WALLOS_TEST_TIMEOUT') : 20),
        'allow_mutations' => in_array(strtolower((string) getenv('WALLOS_TEST_ALLOW_MUTATIONS')), array('1', 'true', 'yes', 'on'), true),
        'show_help' => false,
        'show_list' => false,
        'suite_mode' => 'all',
        'selected_suites' => array_keys($catalog),
    );

    $argc = count($argv);
    for ($i = 1; $i < $argc; $i++) {
        $argument = (string) $argv[$i];

        if ($argument === '--help' || $argument === '-h') {
            $config['show_help'] = true;
            continue;
        }

        if ($argument === '--list') {
            $config['show_list'] = true;
            continue;
        }

        if ($argument === '--public-only') {
            $config['suite_mode'] = 'public';
            continue;
        }

        if ($argument === '--auth-only') {
            $config['suite_mode'] = 'auth';
            continue;
        }

        if ($argument === '--static-only') {
            $config['suite_mode'] = 'static';
            continue;
        }

        if ($argument === '--existing-only' || $argument === '--run-existing') {
            $config['suite_mode'] = 'legacy';
            continue;
        }

        if ($argument === '--mutating-auth-checks') {
            $config['allow_mutations'] = true;
            continue;
        }

        if (strpos($argument, '--base-url=') === 0) {
            $config['base_url'] = trim(substr($argument, strlen('--base-url=')));
            continue;
        }

        if ($argument === '--base-url' && isset($argv[$i + 1])) {
            $config['base_url'] = trim((string) $argv[++$i]);
            continue;
        }

        if (strpos($argument, '--cookie=') === 0) {
            $config['cookie'] = trim(substr($argument, strlen('--cookie=')));
            continue;
        }

        if ($argument === '--cookie' && isset($argv[$i + 1])) {
            $config['cookie'] = trim((string) $argv[++$i]);
            continue;
        }

        if (strpos($argument, '--username=') === 0) {
            $config['username'] = trim(substr($argument, strlen('--username=')));
            continue;
        }

        if ($argument === '--username' && isset($argv[$i + 1])) {
            $config['username'] = trim((string) $argv[++$i]);
            continue;
        }

        if (strpos($argument, '--password=') === 0) {
            $config['password'] = trim(substr($argument, strlen('--password=')));
            continue;
        }

        if ($argument === '--password' && isset($argv[$i + 1])) {
            $config['password'] = trim((string) $argv[++$i]);
            continue;
        }

        if (strpos($argument, '--timeout=') === 0) {
            $config['timeout'] = (int) trim(substr($argument, strlen('--timeout=')));
            continue;
        }

        if ($argument === '--timeout' && isset($argv[$i + 1])) {
            $config['timeout'] = (int) trim((string) $argv[++$i]);
            continue;
        }
    }

    $config['selected_suites'] = wallos_regression_resolve_selected_suites($config['suite_mode'], $catalog);
    $config['timeout'] = $config['timeout'] > 0 ? $config['timeout'] : 20;
    $config['base_url'] = wallos_regression_normalize_base_url($config['base_url']);

    return $config;
}

function wallos_regression_validate_config(array $config)
{
    if ($config['show_help'] || $config['show_list']) {
        return '';
    }

    $selectedSuites = $config['selected_suites'];
    $requiresBaseUrl = in_array('public', $selectedSuites, true) || in_array('auth', $selectedSuites, true);
    if ($requiresBaseUrl && $config['base_url'] === '') {
        return 'A valid base URL is required. Use --base-url or WALLOS_BASE_URL.';
    }

    return '';
}

function wallos_regression_resolve_selected_suites($suiteMode, array $catalog)
{
    if ($suiteMode === 'public') {
        return array('public');
    }

    if ($suiteMode === 'auth') {
        return array('auth');
    }

    if ($suiteMode === 'static') {
        return array('static');
    }

    if ($suiteMode === 'legacy') {
        return array('legacy');
    }

    return array_keys($catalog);
}

function wallos_regression_normalize_base_url($baseUrl)
{
    $baseUrl = trim((string) $baseUrl);
    if ($baseUrl === '') {
        return '';
    }

    $baseUrl = rtrim($baseUrl, '/');
    if (!preg_match('#^https?://#i', $baseUrl)) {
        return '';
    }

    return $baseUrl;
}

function wallos_regression_render_help(array $catalog, $commandName)
{
    $lines = array(
        'Wallos regression runner',
        '',
        'Usage:',
        '  php tests/' . $commandName . ' --base-url=http://127.0.0.1:18282',
        '',
        'Options:',
        '  --help                 Show this help text',
        '  --list                 List available suites and checks',
        '  --base-url URL         Base URL for public/auth smoke checks',
        '  --cookie VALUE         Existing cookie header to reuse (e.g. "wallos_login=...; PHPSESSID=...")',
        '  --username VALUE       Username for scripted login when cookie is not supplied',
        '  --password VALUE       Password for scripted login when cookie is not supplied',
        '  --timeout SECONDS      HTTP timeout in seconds (default: 20)',
        '  --mutating-auth-checks Create/edit/delete temporary subscription data during auth checks',
        '  --public-only          Run public smoke checks only',
        '  --auth-only            Run authenticated smoke checks only',
        '  --static-only          Run static contract checks only',
        '  --existing-only        Run existing PHP regression scripts only',
        '',
        'Environment fallbacks:',
        '  WALLOS_BASE_URL, WALLOS_TEST_COOKIE, WALLOS_TEST_USERNAME, WALLOS_TEST_PASSWORD, WALLOS_TEST_TIMEOUT, WALLOS_TEST_ALLOW_MUTATIONS',
        '',
        'Suites:',
    );

    foreach ($catalog as $suiteKey => $suiteDefinition) {
        $lines[] = '  - ' . $suiteKey . ': ' . $suiteDefinition['description'];
    }

    echo implode(PHP_EOL, $lines) . PHP_EOL;
}

function wallos_regression_render_catalog(array $catalog, array $selectedSuites)
{
    echo 'Available regression suites' . PHP_EOL;
    echo '===========================' . PHP_EOL;

    foreach ($selectedSuites as $suiteKey) {
        if (!isset($catalog[$suiteKey])) {
            continue;
        }

        $suiteDefinition = $catalog[$suiteKey];
        echo PHP_EOL . '[' . strtoupper($suiteKey) . '] ' . $suiteDefinition['label'] . PHP_EOL;
        echo $suiteDefinition['description'] . PHP_EOL;
        foreach ($suiteDefinition['checks'] as $checkKey => $description) {
            echo '  - ' . $checkKey . ': ' . $description . PHP_EOL;
        }
    }
}
