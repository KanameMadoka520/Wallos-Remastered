<?php

function wallos_regression_make_result($status, $suite, $name, $detail = '', array $context = array())
{
    return array(
        'status' => strtoupper((string) $status),
        'suite' => (string) $suite,
        'name' => (string) $name,
        'detail' => trim((string) $detail),
        'context' => $context,
    );
}

function wallos_regression_render_results(array $results, array $config = array())
{
    $summary = array(
        'PASS' => 0,
        'FAIL' => 0,
        'SKIP' => 0,
        'TOTAL' => 0,
        'exit_code' => 0,
    );

    echo 'Wallos regression run' . PHP_EOL;
    echo '=====================' . PHP_EOL;
    if (!empty($config['base_url'])) {
        echo 'Base URL: ' . $config['base_url'] . PHP_EOL;
    }
    echo 'Suites: ' . implode(', ', $config['selected_suites']) . PHP_EOL . PHP_EOL;

    foreach ($results as $result) {
        $status = isset($result['status']) ? strtoupper((string) $result['status']) : 'FAIL';
        if (!isset($summary[$status])) {
            $status = 'FAIL';
        }

        $summary[$status]++;
        $summary['TOTAL']++;

        $line = sprintf(
            '[%s] %s :: %s',
            $status,
            isset($result['suite']) ? $result['suite'] : 'unknown',
            isset($result['name']) ? $result['name'] : 'Unnamed check'
        );

        if (!empty($result['detail'])) {
            $line .= ' - ' . $result['detail'];
        }

        echo $line . PHP_EOL;
    }

    if ($summary['TOTAL'] === 0) {
        echo '[SKIP] runner :: No checks were executed.' . PHP_EOL;
        $summary['SKIP']++;
        $summary['TOTAL']++;
    }

    echo PHP_EOL;
    echo sprintf(
        'Summary: %d pass | %d fail | %d skip | %d total',
        $summary['PASS'],
        $summary['FAIL'],
        $summary['SKIP'],
        $summary['TOTAL']
    ) . PHP_EOL;

    $summary['exit_code'] = $summary['FAIL'] > 0 ? 1 : 0;
    return $summary;
}
