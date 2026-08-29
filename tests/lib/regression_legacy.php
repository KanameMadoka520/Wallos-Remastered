<?php

function wallos_regression_run_legacy_suite(array $config, array $suiteDefinition)
{
    $tests = array(
        'budget-regression' => 'tests/budget_regression_test.php',
        'budget-period' => 'tests/budget_period_test.php',
        'currency-rates' => 'tests/currency_rates_test.php',
        'currency-update-schedule' => 'tests/currency_update_schedule_test.php',
        'ical-helper' => 'tests/ical_helper_test.php',
        'migration-079' => 'tests/migration_079_test.php',
        'migration-080' => 'tests/migration_080_test.php',
        'migration-081' => 'tests/migration_081_test.php',
        'screenshot-privacy' => 'tests/screenshot_privacy_test.php',
        'declarative-oidc' => 'tests/oidc_declarative_test.php',
        'period-summary-notifications' => 'tests/period_summary_notifications_test.php',
        'themed-logo-variants' => 'tests/logo_theme_variant_test.php',
        'one-time-migration' => 'tests/one_time_migration_test.php',
        'subscription-index' => 'tests/subscription_index_test.php',
        'smtp-ssrf' => 'tests/ssrf_smtp_test.php',
        'database-bootstrap-verification' => 'tests/database_bootstrap_verification_test.php',
        'database-runtime-lock' => 'tests/database_runtime_lock_test.php',
        'request-log-completion-safety' => 'tests/request_log_completion_safety_test.php',
        'legacy-migration-safety' => 'tests/legacy_migration_safety_test.php',
        'restore-migration' => 'tests/restore_migration_test.php',
        'restore-atomicity' => 'tests/restore_atomicity_test.php',
        'restore-transaction' => 'tests/restore_transaction_test.php',
        'backup-manifest-verification' => 'tests/backup_manifest_verification_test.php',
        'startup-safety' => 'tests/startup_safety_contract_test.php',
        'startup-process-supervision' => 'tests/startup_process_supervision_test.php',
        'upstream-security' => 'tests/upstream_security_contract_test.php',
        'calendar-calculations' => 'tests/calendar_calculations_test.php',
        'payment-ledger' => 'tests/payment_ledger_test.php',
        'subscription-preferences' => 'tests/subscription_preferences_test.php',
        'subscription-image-maintenance' => 'tests/subscription_image_maintenance_test.php',
        'csrf-ttl' => 'tests/csrf_ttl_test.php',
        'sqlite-index-contract' => 'tests/sqlite_index_contract_test.php',
        'maintenance-action-log-contract' => 'tests/maintenance_action_log_contract_test.php',
        'database-busy-contract' => 'tests/database_busy_contract_test.php',
        'subscription-empty-sort' => 'tests/subscription_empty_sort_test.php',
        'i18n-contract' => 'tests/i18n_contract_test.php',
        'timezone-settings' => 'tests/timezone_settings_test.php',
        'backend-rate-limit-exemption' => 'tests/backend_rate_limit_exemption_test.php',
        'navigation-performance-contract' => 'tests/navigation_performance_contract_test.php',
        'subscription-ajax-pagination-contract' => 'tests/subscription_ajax_pagination_contract_test.php',
    );

    $results = array();
    foreach ($tests as $checkName => $relativePath) {
        $absolutePath = $config['repo_root'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!file_exists($absolutePath)) {
            $results[] = wallos_regression_make_result('FAIL', 'legacy', $checkName, $relativePath . ' not found');
            continue;
        }

        $execution = wallos_regression_run_php_subprocess($absolutePath);
        $detail = $execution['detail'];
        $results[] = wallos_regression_make_result(
            $execution['exit_code'] === 0 ? 'PASS' : 'FAIL',
            'legacy',
            $checkName,
            $detail
        );
    }

    return $results;
}

function wallos_regression_run_php_subprocess($filePath)
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($filePath);
    $descriptorSpec = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );

    $process = proc_open($command, $descriptorSpec, $pipes, dirname($filePath));
    if (!is_resource($process)) {
        return array(
            'exit_code' => 1,
            'detail' => 'Failed to start PHP subprocess.',
        );
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $combinedOutput = trim($stdout . PHP_EOL . $stderr);
    $detail = $combinedOutput !== ''
        ? wallos_regression_pick_last_non_empty_line($combinedOutput)
        : 'Process exited with code ' . $exitCode;

    return array(
        'exit_code' => (int) $exitCode,
        'detail' => $detail,
    );
}

function wallos_regression_pick_last_non_empty_line($text)
{
    $lines = preg_split('/\r\n|\n|\r/', (string) $text);
    for ($index = count($lines) - 1; $index >= 0; $index--) {
        $line = trim((string) $lines[$index]);
        if ($line !== '') {
            return $line;
        }
    }

    return '';
}
