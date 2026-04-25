<?php

function wallos_regression_run_legacy_suite(array $config, array $suiteDefinition)
{
    $tests = array(
        'budget-regression' => 'tests/budget_regression_test.php',
        'payment-ledger' => 'tests/payment_ledger_test.php',
        'subscription-preferences' => 'tests/subscription_preferences_test.php',
        'csrf-ttl' => 'tests/csrf_ttl_test.php',
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
