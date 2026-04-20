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
                'registration-theme-color' => 'registration.php responds and exposes meta[name="theme-color"]',
                'service-worker-registration' => 'scripts/all.js still registers service-worker.js',
                'service-worker-cache-contract' => 'service-worker.js still declares cache version constants',
            ),
        ),
        'auth' => array(
            'label' => 'Authenticated smoke checks',
            'description' => 'Checks login reuse, subscription-page JSON payloads, and authenticated pagination HTML.',
            'checks' => array(
                'login-or-cookie' => 'Reuse existing cookies or login with supplied credentials',
                'subscription-pages-json' => 'subscriptionpages.php returns the expected JSON shape',
                'subscriptions-html' => 'subscriptions/get.php returns HTML for subscription_page=all',
                'subscriptions-unauth-401' => 'subscriptions/get.php unauthenticated contract stays clean',
            ),
        ),
        'legacy' => array(
            'label' => 'Existing PHP regression tests',
            'description' => 'Runs the existing logic regression scripts and folds them into the unified summary.',
            'checks' => array(
                'budget-regression' => 'Execute tests/budget_regression_test.php',
                'payment-ledger' => 'Execute tests/payment_ledger_test.php',
                'subscription-preferences' => 'Execute tests/subscription_preferences_test.php',
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

        if ($argument === '--existing-only' || $argument === '--run-existing') {
            $config['suite_mode'] = 'legacy';
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
        '  --public-only          Run public smoke checks only',
        '  --auth-only            Run authenticated smoke checks only',
        '  --existing-only        Run existing PHP regression scripts only',
        '',
        'Environment fallbacks:',
        '  WALLOS_BASE_URL, WALLOS_TEST_COOKIE, WALLOS_TEST_USERNAME, WALLOS_TEST_PASSWORD, WALLOS_TEST_TIMEOUT',
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
