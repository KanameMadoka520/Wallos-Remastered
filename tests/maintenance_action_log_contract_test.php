<?php

function wallos_maintenance_action_log_contract_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require_once __DIR__ . '/../includes/system_maintenance.php';

$db = new SQLite3(':memory:');

try {
    require __DIR__ . '/../migrations/000074.php';

    $successPayload = [
        'success' => true,
        'message' => 'Storage usage refreshed.',
        'storage' => [
            'database' => ['size_label' => '12 KB'],
            'directories' => [
                'subscription_media' => ['size_label' => '34 KB'],
            ],
        ],
    ];
    $summary = wallos_summarize_maintenance_action_result('get_storage_usage', $successPayload);
    wallos_maintenance_action_log_contract_assert(
        strpos($summary, 'db=12 KB') !== false && strpos($summary, 'subscription_media=34 KB') !== false,
        'Storage action summary should include compact storage sizes.'
    );

    wallos_maintenance_action_log_contract_assert(
        wallos_record_maintenance_action($db, 'get_storage_usage', true, 123, $summary, 1),
        'Successful maintenance action should be recorded.'
    );
    wallos_maintenance_action_log_contract_assert(
        wallos_record_maintenance_action($db, 'run_sqlite_maintenance', false, 456, 'failed summary', 1),
        'Failed maintenance action should be recorded.'
    );

    $items = wallos_get_recent_maintenance_actions($db, 10);
    wallos_maintenance_action_log_contract_assert(count($items) === 2, 'Expected two recent maintenance action logs.');
    wallos_maintenance_action_log_contract_assert($items[0]['action'] === 'run_sqlite_maintenance', 'Newest log should be returned first.');
    wallos_maintenance_action_log_contract_assert($items[0]['success'] === false, 'Failure flag should round-trip as boolean false.');
    wallos_maintenance_action_log_contract_assert(
        wallos_count_recent_failed_maintenance_actions($db, 24) === 1,
        'Recent failed maintenance action count should be one.'
    );
    $actionSummary = wallos_get_maintenance_action_log_summary($db, 24);
    wallos_maintenance_action_log_contract_assert((int) ($actionSummary['recent_rows'] ?? 0) === 2, 'Maintenance action summary should count recent rows.');
    wallos_maintenance_action_log_contract_assert((int) ($actionSummary['recent_failed_rows'] ?? 0) === 1, 'Maintenance action summary should count recent failures.');
    wallos_maintenance_action_log_contract_assert((string) ($actionSummary['slowest_action'] ?? '') === 'run_sqlite_maintenance', 'Maintenance action summary should expose the slowest recent action.');

    $oldTimestamp = date('Y-m-d H:i:s', strtotime('-120 days'));
    $db->exec("UPDATE maintenance_action_logs SET created_at = '" . SQLite3::escapeString($oldTimestamp) . "' WHERE action = 'run_sqlite_maintenance'");
    wallos_prune_maintenance_action_logs($db);
    $afterPrune = wallos_get_recent_maintenance_actions($db, 10);
    wallos_maintenance_action_log_contract_assert(count($afterPrune) === 1, 'Old maintenance action logs should be pruned.');
    wallos_maintenance_action_log_contract_assert($afterPrune[0]['action'] === 'get_storage_usage', 'Only the fresh log should remain after pruning.');

    echo 'Maintenance action log contract checks passed.' . PHP_EOL;
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $db->close();
}
