<?php

require_once __DIR__ . '/lib/regression_bootstrap.php';
require_once __DIR__ . '/lib/regression_http.php';
require_once __DIR__ . '/lib/regression_output.php';

$regressionChecksPath = __DIR__ . '/lib/regression_checks.php';
if (file_exists($regressionChecksPath)) {
    require_once $regressionChecksPath;
}

$regressionLegacyPath = __DIR__ . '/lib/regression_legacy.php';
if (file_exists($regressionLegacyPath)) {
    require_once $regressionLegacyPath;
}

$catalog = wallos_regression_build_suite_catalog();
$config = wallos_regression_parse_cli_config($argv, $catalog);

if ($config['show_help']) {
    wallos_regression_render_help($catalog, $config['command_name']);
    exit(0);
}

if ($config['show_list']) {
    wallos_regression_render_catalog($catalog, $config['selected_suites']);
    exit(0);
}

$validationError = wallos_regression_validate_config($config);
if ($validationError !== '') {
    fwrite(STDERR, '[ERROR] ' . $validationError . PHP_EOL);
    exit(1);
}

$results = array();
$selectedSuites = $config['selected_suites'];
$suiteRunners = array(
    'public' => 'wallos_regression_run_public_suite',
    'auth' => 'wallos_regression_run_auth_suite',
    'static' => 'wallos_regression_run_static_suite',
    'legacy' => 'wallos_regression_run_legacy_suite',
);

foreach ($selectedSuites as $suiteKey) {
    if (!isset($suiteRunners[$suiteKey])) {
        $results[] = wallos_regression_make_result('FAIL', $suiteKey, 'Suite dispatch', 'No runner is registered for this suite.');
        continue;
    }

    $runner = $suiteRunners[$suiteKey];
    if (!function_exists($runner)) {
        $results[] = wallos_regression_make_result('FAIL', $suiteKey, 'Suite dispatch', 'This suite is not implemented in the current checkout.');
        continue;
    }

    try {
        $suiteResults = $runner($config, $catalog[$suiteKey]);
        foreach ($suiteResults as $suiteResult) {
            $results[] = $suiteResult;
        }
    } catch (Throwable $throwable) {
        $results[] = wallos_regression_make_result(
            'FAIL',
            $suiteKey,
            'Unhandled suite exception',
            trim((string) $throwable->getMessage()) !== '' ? trim((string) $throwable->getMessage()) : 'Unknown exception'
        );
    }
}

$summary = wallos_regression_render_results($results, $config);
exit($summary['exit_code']);
