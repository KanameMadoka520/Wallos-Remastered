<?php

require_once __DIR__ . '/subscription_media.php';
require_once __DIR__ . '/security_maintenance.php';
require_once __DIR__ . '/backup_manager.php';

if (!defined('WALLOS_SLOW_REQUEST_THRESHOLD_MS')) {
    define('WALLOS_SLOW_REQUEST_THRESHOLD_MS', 1500);
}

function wallos_format_maintenance_size($bytes)
{
    return wallos_format_backup_size(max(0, (int) $bytes));
}

function wallos_maintenance_table_exists($db, $tableName)
{
    $tableName = trim((string) $tableName);
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
        return false;
    }

    $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table_name LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bindValue(':table_name', $tableName, SQLITE3_TEXT);
    $result = $stmt->execute();
    return $result && $result->fetchArray(SQLITE3_ASSOC) !== false;
}

function wallos_maintenance_count_table_rows($db, $tableName)
{
    $tableName = trim((string) $tableName);
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName) || !wallos_maintenance_table_exists($db, $tableName)) {
        return 0;
    }

    return (int) $db->querySingle('SELECT COUNT(*) AS total FROM ' . $tableName);
}

function wallos_maintenance_action_logs_table_exists($db)
{
    return wallos_maintenance_table_has_columns($db, 'maintenance_action_logs', [
        'id',
        'admin_user_id',
        'action',
        'success',
        'duration_ms',
        'summary',
        'created_at',
    ]);
}

function wallos_prune_maintenance_action_logs($db)
{
    if (!wallos_maintenance_action_logs_table_exists($db)) {
        return;
    }

    $stmt = $db->prepare('DELETE FROM maintenance_action_logs WHERE created_at <= :cutoff');
    if (!$stmt) {
        return;
    }

    $stmt->bindValue(':cutoff', date('Y-m-d H:i:s', strtotime('-' . WALLOS_MAINTENANCE_ACTION_LOG_RETENTION_DAYS . ' days')), SQLITE3_TEXT);
    @$stmt->execute();
}

function wallos_summarize_maintenance_action_result($action, array $payload)
{
    $action = (string) $action;
    if (isset($payload['message']) && trim((string) $payload['message']) !== '') {
        $baseMessage = trim((string) $payload['message']);
    } else {
        $baseMessage = $action;
    }

    if ($action === 'scan_subscription_images' && isset($payload['audit']) && is_array($payload['audit'])) {
        $audit = $payload['audit'];
        return $baseMessage
            . ' | orphan=' . number_format((int) ($audit['orphan_files'] ?? 0))
            . ' | missing_variants=' . number_format((int) ($audit['missing_variant_rows'] ?? 0))
            . ' | reclaimable=' . (string) ($audit['reclaimable_size_estimate_label'] ?? '-');
    }

    if ($action === 'cleanup_subscription_image_orphans' && isset($payload['orphan_cleanup_result']) && is_array($payload['orphan_cleanup_result'])) {
        $result = $payload['orphan_cleanup_result'];
        return $baseMessage
            . ' | deleted=' . number_format((int) ($result['deleted_files'] ?? 0))
            . ' | failed=' . number_format((int) ($result['failed_files'] ?? 0))
            . ' | size=' . (string) ($result['deleted_size_label'] ?? '-');
    }

    if ($action === 'reuse_oversized_subscription_image_variants' && isset($payload['oversized_variant_result']) && is_array($payload['oversized_variant_result'])) {
        $result = $payload['oversized_variant_result'];
        return $baseMessage
            . ' | checked=' . number_format((int) ($result['checked_rows'] ?? 0))
            . ' | updated=' . number_format((int) ($result['updated_rows'] ?? 0))
            . ' | reused=' . number_format((int) ($result['reused_variants'] ?? 0));
    }

    if ($action === 'run_sqlite_maintenance' && isset($payload['result']) && is_array($payload['result'])) {
        $result = $payload['result'];
        return $baseMessage
            . ' | before=' . (string) ($result['before']['size_label'] ?? '-')
            . ' | after=' . (string) ($result['after']['size_label'] ?? '-')
            . ' | duration=' . number_format((int) ($result['duration_ms'] ?? 0)) . ' ms';
    }

    if ($action === 'check_sqlite_indexes' && isset($payload['index_health']) && is_array($payload['index_health'])) {
        $result = $payload['index_health'];
        return $baseMessage
            . ' | missing=' . number_format((int) ($result['missing_indexes'] ?? 0))
            . ' | invalid=' . number_format((int) ($result['invalid_indexes'] ?? 0));
    }

    if ($action === 'get_storage_usage' && isset($payload['storage']) && is_array($payload['storage'])) {
        $storage = $payload['storage'];
        return $baseMessage
            . ' | db=' . (string) ($storage['database']['size_label'] ?? '-')
            . ' | subscription_media=' . (string) ($storage['directories']['subscription_media']['size_label'] ?? '-');
    }

    return $baseMessage;
}

function wallos_record_maintenance_action($db, $action, $success, $durationMs, $summary = '', $adminUserId = 0)
{
    if (!wallos_maintenance_action_logs_table_exists($db)) {
        return false;
    }

    wallos_prune_maintenance_action_logs($db);

    $stmt = $db->prepare('
        INSERT INTO maintenance_action_logs (admin_user_id, action, success, duration_ms, summary, created_at)
        VALUES (:admin_user_id, :action, :success, :duration_ms, :summary, :created_at)
    ');
    if (!$stmt) {
        return false;
    }

    $stmt->bindValue(':admin_user_id', max(0, (int) $adminUserId), SQLITE3_INTEGER);
    $stmt->bindValue(':action', substr((string) $action, 0, 96), SQLITE3_TEXT);
    $stmt->bindValue(':success', !empty($success) ? 1 : 0, SQLITE3_INTEGER);
    $stmt->bindValue(':duration_ms', max(0, (int) $durationMs), SQLITE3_INTEGER);
    $stmt->bindValue(':summary', substr((string) $summary, 0, 1000), SQLITE3_TEXT);
    $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);

    return @$stmt->execute() !== false;
}

function wallos_get_recent_maintenance_actions($db, $limit = 12)
{
    if (!wallos_maintenance_action_logs_table_exists($db)) {
        return [];
    }

    $limit = min(50, max(1, (int) $limit));
    $stmt = $db->prepare('
        SELECT id, admin_user_id, action, success, duration_ms, summary, created_at
        FROM maintenance_action_logs
        ORDER BY created_at DESC, id DESC
        LIMIT :limit
    ');
    if (!$stmt) {
        return [];
    }

    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $items = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $items[] = [
            'id' => (int) ($row['id'] ?? 0),
            'admin_user_id' => (int) ($row['admin_user_id'] ?? 0),
            'action' => (string) ($row['action'] ?? ''),
            'success' => (int) ($row['success'] ?? 0) === 1,
            'duration_ms' => (int) ($row['duration_ms'] ?? 0),
            'summary' => (string) ($row['summary'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    return $items;
}

function wallos_count_recent_failed_maintenance_actions($db, $hours = 24)
{
    if (!wallos_maintenance_action_logs_table_exists($db)) {
        return 0;
    }

    $stmt = $db->prepare('
        SELECT COUNT(*) AS total
        FROM maintenance_action_logs
        WHERE success = 0
          AND created_at >= :cutoff
    ');
    if (!$stmt) {
        return 0;
    }

    $stmt->bindValue(':cutoff', date('Y-m-d H:i:s', strtotime('-' . max(1, (int) $hours) . ' hours')), SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    return (int) ($row['total'] ?? 0);
}

function wallos_get_maintenance_log_activity($db, $tableName, $retentionDays)
{
    $activity = [
        'oldest_at' => '',
        'latest_at' => '',
        'last_24h_rows' => 0,
        'last_24h_rows_label' => '0',
        'retention_window_rows' => 0,
        'retention_window_rows_label' => '0',
        'daily_average_rows' => 0.0,
        'daily_average_rows_label' => '0',
    ];
    $tableName = trim((string) $tableName);
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName) || !wallos_maintenance_table_has_columns($db, $tableName, ['created_at'])) {
        return $activity;
    }

    $quotedTable = wallos_quote_sqlite_identifier($tableName);
    $range = $db->querySingle('SELECT MIN(created_at) AS oldest_at, MAX(created_at) AS latest_at FROM ' . $quotedTable, true);
    if (is_array($range)) {
        $activity['oldest_at'] = (string) ($range['oldest_at'] ?? '');
        $activity['latest_at'] = (string) ($range['latest_at'] ?? '');
    }

    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM ' . $quotedTable . ' WHERE created_at >= datetime(\'now\', \'-24 hours\')');
    if ($stmt) {
        $result = $stmt->execute();
        $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
        $activity['last_24h_rows'] = (int) ($row['total'] ?? 0);
        $activity['last_24h_rows_label'] = number_format($activity['last_24h_rows']);
    }

    $retentionDays = max(1, (int) $retentionDays);
    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM ' . $quotedTable . ' WHERE created_at >= datetime(\'now\', :window)');
    if ($stmt) {
        $stmt->bindValue(':window', '-' . $retentionDays . ' days', SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
        $activity['retention_window_rows'] = (int) ($row['total'] ?? 0);
        $activity['retention_window_rows_label'] = number_format($activity['retention_window_rows']);
    }

    $dailyAverage = $activity['retention_window_rows'] / $retentionDays;
    $activity['daily_average_rows'] = round($dailyAverage, 1);
    $activity['daily_average_rows_label'] = number_format($activity['daily_average_rows'], $dailyAverage >= 10 ? 0 : 1);

    return $activity;
}

function wallos_maintenance_table_has_columns($db, $tableName, array $requiredColumns)
{
    $tableName = trim((string) $tableName);
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName) || !wallos_maintenance_table_exists($db, $tableName)) {
        return false;
    }

    $columns = [];
    $result = $db->query('PRAGMA table_info(' . wallos_quote_sqlite_identifier($tableName) . ')');
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $columns[] = (string) ($row['name'] ?? '');
    }

    foreach ($requiredColumns as $requiredColumn) {
        if (!in_array((string) $requiredColumn, $columns, true)) {
            return false;
        }
    }

    return true;
}

function wallos_count_maintenance_slow_requests($db, $hours = 24, $thresholdMs = WALLOS_SLOW_REQUEST_THRESHOLD_MS)
{
    if (!wallos_maintenance_table_has_columns($db, 'request_logs', ['duration_ms', 'created_at'])) {
        return 0;
    }

    $stmt = $db->prepare('
        SELECT COUNT(*) AS total
        FROM request_logs
        WHERE duration_ms >= :threshold
          AND created_at >= datetime(\'now\', :window)
    ');
    if (!$stmt) {
        return 0;
    }

    $stmt->bindValue(':threshold', max(1, (int) $thresholdMs), SQLITE3_INTEGER);
    $stmt->bindValue(':window', '-' . max(1, (int) $hours) . ' hours', SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    return (int) ($row['total'] ?? 0);
}

function wallos_collect_directory_usage($directory)
{
    $directory = rtrim((string) $directory, '/\\');
    $summary = [
        'path' => $directory,
        'exists' => is_dir($directory),
        'file_count' => 0,
        'directory_count' => 0,
        'size_bytes' => 0,
        'size_label' => wallos_format_maintenance_size(0),
        'scan_errors' => 0,
    ];

    if (!$summary['exists']) {
        return $summary;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $summary['directory_count']++;
                continue;
            }

            if (!$item->isFile()) {
                continue;
            }

            $summary['file_count']++;
            $fileSize = @filesize($item->getPathname());
            if ($fileSize === false) {
                $summary['scan_errors']++;
                continue;
            }

            $summary['size_bytes'] += (int) $fileSize;
        }
    } catch (Throwable $throwable) {
        $summary['scan_errors']++;
    }

    $summary['size_label'] = wallos_format_maintenance_size($summary['size_bytes']);
    return $summary;
}

function wallos_get_sqlite_database_file_path($db)
{
    $result = $db->query('PRAGMA database_list');
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        if (($row['name'] ?? '') === 'main' && trim((string) ($row['file'] ?? '')) !== '') {
            return (string) $row['file'];
        }
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'wallos.db';
}

function wallos_get_sqlite_database_metrics($db)
{
    $databasePath = wallos_get_sqlite_database_file_path($db);
    $pageSize = (int) $db->querySingle('PRAGMA page_size');
    $pageCount = (int) $db->querySingle('PRAGMA page_count');
    $freePageCount = (int) $db->querySingle('PRAGMA freelist_count');
    $sizeBytes = is_file($databasePath) ? (int) @filesize($databasePath) : 0;

    if ($sizeBytes <= 0 && $pageSize > 0 && $pageCount > 0) {
        $sizeBytes = $pageSize * $pageCount;
    }

    return [
        'path' => $databasePath,
        'size_bytes' => $sizeBytes,
        'size_label' => wallos_format_maintenance_size($sizeBytes),
        'page_size' => $pageSize,
        'page_count' => $pageCount,
        'freelist_count' => $freePageCount,
        'free_bytes_estimate' => $pageSize * $freePageCount,
        'free_size_label' => wallos_format_maintenance_size($pageSize * $freePageCount),
    ];
}

function wallos_quote_sqlite_identifier($identifier)
{
    return '"' . str_replace('"', '""', (string) $identifier) . '"';
}

function wallos_get_expected_sqlite_indexes()
{
    return [
        ['subscriptions', 'idx_subscriptions_user_status_sort', ['user_id', 'lifecycle_status', 'sort_order', 'id']],
        ['subscriptions', 'idx_subscriptions_user_page_status_sort', ['user_id', 'subscription_page_id', 'lifecycle_status', 'sort_order', 'id']],
        ['subscriptions', 'idx_subscriptions_user_status_next_payment', ['user_id', 'lifecycle_status', 'inactive', 'next_payment', 'id']],
        ['subscriptions', 'idx_subscriptions_user_status_stats', ['user_id', 'lifecycle_status', 'inactive', 'exclude_from_stats', 'next_payment', 'id']],
        ['subscriptions', 'idx_subscriptions_user_status_category', ['user_id', 'lifecycle_status', 'category_id']],
        ['subscriptions', 'idx_subscriptions_user_status_payment', ['user_id', 'lifecycle_status', 'payment_method_id']],
        ['subscriptions', 'idx_subscriptions_user_status_payer', ['user_id', 'lifecycle_status', 'payer_user_id']],
        ['subscriptions', 'idx_subscriptions_user_status_trash', ['user_id', 'lifecycle_status', 'trashed_at', 'id']],
        ['subscriptions', 'idx_subscriptions_status_scheduled_delete', ['lifecycle_status', 'scheduled_delete_at', 'id']],
        ['subscription_uploaded_images', 'idx_subscription_uploaded_images_user_subscription_sort', ['user_id', 'subscription_id', 'sort_order', 'id']],
        ['subscription_uploaded_images', 'idx_subscription_uploaded_images_subscription_user_sort', ['subscription_id', 'user_id', 'sort_order', 'id']],
        ['subscription_uploaded_images', 'idx_subscription_uploaded_images_user_sequence', ['user_id', 'upload_sequence']],
        ['subscription_uploaded_images', 'idx_subscription_uploaded_images_path', ['path']],
        ['subscription_uploaded_images', 'idx_subscription_uploaded_images_preview_path', ['preview_path']],
        ['subscription_uploaded_images', 'idx_subscription_uploaded_images_thumbnail_path', ['thumbnail_path']],
        ['subscription_payment_records', 'idx_subscription_payment_records_subscription_user_paid', ['subscription_id', 'user_id', 'paid_at', 'id']],
        ['subscription_payment_records', 'idx_subscription_payment_records_user_subscription_paid', ['user_id', 'subscription_id', 'paid_at', 'id']],
        ['subscription_payment_records', 'idx_subscription_payment_records_user_status_paid', ['user_id', 'status', 'paid_at', 'id']],
        ['subscription_payment_records', 'idx_subscription_payment_records_subscription_due_status', ['subscription_id', 'user_id', 'due_date', 'status', 'paid_at', 'id']],
        ['subscription_price_rules', 'idx_subscription_price_rules_user_subscription_priority', ['user_id', 'subscription_id', 'enabled', 'priority', 'id']],
        ['request_logs', 'idx_request_logs_created_id', ['created_at', 'id']],
        ['request_logs', 'idx_request_logs_method_created_id', ['method', 'created_at', 'id']],
        ['request_logs', 'idx_request_logs_user_created_id', ['user_id', 'created_at', 'id']],
        ['request_logs', 'idx_request_logs_duration_created_id', ['duration_ms', 'created_at', 'id']],
        ['request_logs', 'idx_request_logs_status_created_id', ['status_code', 'created_at', 'id']],
        ['security_anomalies', 'idx_security_anomalies_created_id', ['created_at', 'id']],
        ['security_anomalies', 'idx_security_anomalies_type_created_id', ['anomaly_type', 'created_at', 'id']],
        ['security_anomalies', 'idx_security_anomalies_user_created_id', ['user_id', 'created_at', 'id']],
        ['rate_limit_usage', 'idx_rate_limit_usage_user_category_created', ['user_id', 'category', 'created_at']],
        ['rate_limit_usage', 'idx_rate_limit_usage_created_id', ['created_at', 'id']],
        ['maintenance_action_logs', 'idx_maintenance_action_logs_created_id', ['created_at', 'id']],
        ['maintenance_action_logs', 'idx_maintenance_action_logs_action_created', ['action', 'created_at']],
        ['user', 'idx_user_status_scheduled_delete', ['account_status', 'scheduled_delete_at', 'id']],
    ];
}

function wallos_get_sqlite_index_columns($db, $indexName)
{
    $columns = [];
    $result = $db->query('PRAGMA index_info(' . wallos_quote_sqlite_identifier($indexName) . ')');
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $columns[] = (string) ($row['name'] ?? '');
    }

    return $columns;
}

function wallos_get_sqlite_table_indexes($db, $tableName)
{
    $indexes = [];
    if (!wallos_maintenance_table_exists($db, $tableName)) {
        return $indexes;
    }

    $result = $db->query('PRAGMA index_list(' . wallos_quote_sqlite_identifier($tableName) . ')');
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $indexName = (string) ($row['name'] ?? '');
        if ($indexName === '') {
            continue;
        }

        $indexes[$indexName] = wallos_get_sqlite_index_columns($db, $indexName);
    }

    return $indexes;
}

function wallos_check_sqlite_index_health($db)
{
    $expectedIndexes = wallos_get_expected_sqlite_indexes();
    $tableIndexCache = [];
    $items = [];
    $missing = [];
    $invalid = [];

    foreach ($expectedIndexes as $definition) {
        [$tableName, $indexName, $expectedColumns] = $definition;
        if (!isset($tableIndexCache[$tableName])) {
            $tableIndexCache[$tableName] = wallos_get_sqlite_table_indexes($db, $tableName);
        }

        $actualColumns = $tableIndexCache[$tableName][$indexName] ?? [];
        $exists = array_key_exists($indexName, $tableIndexCache[$tableName]);
        $valid = $exists && $actualColumns === $expectedColumns;
        $item = [
            'table' => $tableName,
            'index' => $indexName,
            'exists' => $exists,
            'valid' => $valid,
            'expected_columns' => $expectedColumns,
            'actual_columns' => $actualColumns,
        ];

        if (!$exists) {
            $missing[] = $item;
        } elseif (!$valid) {
            $invalid[] = $item;
        }

        $items[] = $item;
    }

    return [
        'success' => empty($missing) && empty($invalid),
        'checked_at' => date('Y-m-d H:i:s'),
        'total_indexes' => count($expectedIndexes),
        'existing_indexes' => count($expectedIndexes) - count($missing),
        'missing_indexes' => count($missing),
        'invalid_indexes' => count($invalid),
        'items' => $items,
        'missing_samples' => array_slice($missing, 0, 8),
        'invalid_samples' => array_slice($invalid, 0, 8),
    ];
}

function wallos_describe_maintenance_log_table($db, $tableName, $retentionDays, $warningRows, $criticalRows)
{
    $rows = wallos_maintenance_count_table_rows($db, $tableName);
    $activity = wallos_get_maintenance_log_activity($db, $tableName, $retentionDays);
    $risk = 'normal';

    if ($rows >= $criticalRows) {
        $risk = 'high';
    } elseif ($rows >= $warningRows) {
        $risk = 'watch';
    }

    return [
        'table' => $tableName,
        'rows' => $rows,
        'rows_label' => number_format($rows),
        'retention_days' => (int) $retentionDays,
        'risk' => $risk,
    ] + $activity;
}

function wallos_get_storage_usage_summary($db, $basePath)
{
    $basePath = rtrim((string) $basePath, '/\\');
    $logosDirectory = $basePath . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'logos';
    $subscriptionMediaDirectory = wallos_get_subscription_media_disk_dir($basePath);
    $backupDirectory = wallos_get_backup_storage_dir($basePath);

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'database' => wallos_get_sqlite_database_metrics($db),
        'directories' => [
            'logos' => wallos_collect_directory_usage($logosDirectory),
            'subscription_media' => wallos_collect_directory_usage($subscriptionMediaDirectory),
            'backups' => wallos_collect_directory_usage($backupDirectory),
        ],
        'logs' => [
            'request_logs' => wallos_describe_maintenance_log_table($db, 'request_logs', WALLOS_REQUEST_LOG_RETENTION_DAYS, 5000, 50000),
            'security_anomalies' => wallos_describe_maintenance_log_table($db, 'security_anomalies', WALLOS_SECURITY_ANOMALY_RETENTION_DAYS, 500, 5000),
            'rate_limit_usage' => wallos_describe_maintenance_log_table($db, 'rate_limit_usage', WALLOS_RATE_LIMIT_USAGE_RETENTION_DAYS, 10000, 100000),
            'maintenance_action_logs' => wallos_describe_maintenance_log_table($db, 'maintenance_action_logs', WALLOS_MAINTENANCE_ACTION_LOG_RETENTION_DAYS, 500, 5000),
        ],
    ];
}

function wallos_maintenance_translate($i18n, $key, $fallback)
{
    if (function_exists('translate') && is_array($i18n)) {
        $translated = translate($key, $i18n);
        if (is_string($translated) && $translated !== '' && $translated !== '[i18n String Missing]') {
            return $translated;
        }
    }

    return $fallback;
}

function wallos_build_maintenance_recommendation($severity, $title, $message, array $details = [], $action = '', $actionLabel = '')
{
    return [
        'severity' => in_array($severity, ['ok', 'watch', 'action'], true) ? $severity : 'watch',
        'title' => (string) $title,
        'message' => (string) $message,
        'details' => array_values(array_filter(array_map('strval', $details))),
        'action' => (string) $action,
        'action_label' => (string) $actionLabel,
    ];
}

function wallos_get_maintenance_recommendation_summary($db, $basePath, $i18n = null)
{
    $storage = wallos_get_storage_usage_summary($db, $basePath);
    $imageAudit = wallos_audit_subscription_image_storage($db, $basePath);
    $indexHealth = wallos_check_sqlite_index_health($db);
    $slowRequests24h = wallos_count_maintenance_slow_requests($db, 24);

    $recommendations = [];
    $database = $storage['database'] ?? [];
    $freeBytes = (int) ($database['free_bytes_estimate'] ?? 0);
    $sizeBytes = max(1, (int) ($database['size_bytes'] ?? 0));
    $freeRatio = $freeBytes / $sizeBytes;

    if (empty($indexHealth['success'])) {
        $recommendations[] = wallos_build_maintenance_recommendation(
            'action',
            wallos_maintenance_translate($i18n, 'maintenance_recommendation_index_title', 'SQLite index health needs attention'),
            wallos_maintenance_translate($i18n, 'maintenance_recommendation_index_message', 'Some expected indexes are missing or have unexpected columns. Re-run migrations or inspect the index health details.'),
            [
                wallos_maintenance_translate($i18n, 'sqlite_index_missing', 'Missing Indexes') . ': ' . (int) ($indexHealth['missing_indexes'] ?? 0),
                wallos_maintenance_translate($i18n, 'sqlite_index_invalid', 'Invalid Indexes') . ': ' . (int) ($indexHealth['invalid_indexes'] ?? 0),
            ],
            'check_sqlite_indexes',
            wallos_maintenance_translate($i18n, 'check_sqlite_indexes', 'Check SQLite Indexes')
        );
    }

    if ($freeBytes >= 64 * 1024 * 1024 && $freeRatio >= 0.20) {
        $recommendations[] = wallos_build_maintenance_recommendation(
            $freeBytes >= 256 * 1024 * 1024 ? 'action' : 'watch',
            wallos_maintenance_translate($i18n, 'maintenance_recommendation_sqlite_free_title', 'SQLite free pages are accumulating'),
            wallos_maintenance_translate($i18n, 'maintenance_recommendation_sqlite_free_message', 'The database has a meaningful amount of reusable free space. Consider running SQLite maintenance during a quiet window.'),
            [
                wallos_maintenance_translate($i18n, 'sqlite_free_size', 'Free Size') . ': ' . ($database['free_size_label'] ?? wallos_format_maintenance_size($freeBytes)),
                wallos_maintenance_translate($i18n, 'sqlite_free_pages', 'Free Pages') . ': ' . number_format((int) ($database['freelist_count'] ?? 0)),
            ],
            'run_sqlite_maintenance',
            wallos_maintenance_translate($i18n, 'run_sqlite_maintenance', 'Run SQLite Maintenance')
        );
    }

    $orphanFiles = (int) ($imageAudit['orphan_files'] ?? 0);
    $orphanBytes = (int) ($imageAudit['orphan_bytes'] ?? 0);
    if ($orphanFiles > 0) {
        $recommendations[] = wallos_build_maintenance_recommendation(
            $orphanBytes >= 100 * 1024 * 1024 ? 'action' : 'watch',
            wallos_maintenance_translate($i18n, 'maintenance_recommendation_orphan_images_title', 'Subscription image orphan files found'),
            wallos_maintenance_translate($i18n, 'maintenance_recommendation_orphan_images_message', 'Some files on disk are no longer referenced by the subscription image index. They can be cleaned from the media directory.'),
            [
                wallos_maintenance_translate($i18n, 'orphan_files', 'Orphan Files') . ': ' . number_format($orphanFiles),
                wallos_maintenance_translate($i18n, 'orphan_file_size', 'Orphan File Size') . ': ' . ($imageAudit['orphan_size_label'] ?? wallos_format_maintenance_size($orphanBytes)),
            ],
            'cleanup_subscription_image_orphans',
            wallos_maintenance_translate($i18n, 'cleanup_subscription_image_orphans', 'Clean Orphan Images')
        );
    }

    $missingVariantRows = (int) ($imageAudit['missing_variant_rows'] ?? 0);
    if ($missingVariantRows > 0) {
        $recommendations[] = wallos_build_maintenance_recommendation(
            'watch',
            wallos_maintenance_translate($i18n, 'maintenance_recommendation_missing_variants_title', 'Some subscription images are missing derived variants'),
            wallos_maintenance_translate($i18n, 'maintenance_recommendation_missing_variants_message', 'Some image records do not have complete preview/thumbnail paths. Regenerate variants from the subscription page when convenient.'),
            [
                wallos_maintenance_translate($i18n, 'missing_derived_image_rows', 'Missing Derived Image Rows') . ': ' . number_format($missingVariantRows),
            ]
        );
    }

    foreach (($storage['logs'] ?? []) as $logInfo) {
        $risk = (string) ($logInfo['risk'] ?? 'normal');
        if (!in_array($risk, ['watch', 'high'], true)) {
            continue;
        }

        $recommendations[] = wallos_build_maintenance_recommendation(
            $risk === 'high' ? 'action' : 'watch',
            wallos_maintenance_translate($i18n, 'maintenance_recommendation_log_growth_title', 'Log table growth needs review'),
            wallos_maintenance_translate($i18n, 'maintenance_recommendation_log_growth_message', 'A log table is growing beyond its normal operating range. Review retention and clear logs if they are no longer needed.'),
            [
                wallos_maintenance_translate($i18n, 'table', 'Table') . ': ' . (string) ($logInfo['table'] ?? '-'),
                wallos_maintenance_translate($i18n, 'rows', 'Rows') . ': ' . (string) ($logInfo['rows_label'] ?? number_format((int) ($logInfo['rows'] ?? 0))),
                wallos_maintenance_translate($i18n, 'log_growth_risk', 'Log Growth Risk') . ': ' . $risk,
            ]
        );
    }

    if ($slowRequests24h > 0) {
        $recommendations[] = wallos_build_maintenance_recommendation(
            $slowRequests24h >= 20 ? 'action' : 'watch',
            wallos_maintenance_translate($i18n, 'maintenance_recommendation_slow_requests_title', 'Slow requests detected'),
            wallos_maintenance_translate($i18n, 'maintenance_recommendation_slow_requests_message', 'Recent requests exceeded the slow-request threshold. Open the filtered access-log view to inspect which endpoints are slow.'),
            [
                wallos_maintenance_translate($i18n, 'slow_requests_24h', 'Slow Requests (24h)') . ': ' . number_format($slowRequests24h),
                wallos_maintenance_translate($i18n, 'min_duration_ms', 'Minimum Duration (ms)') . ': ' . number_format(WALLOS_SLOW_REQUEST_THRESHOLD_MS),
            ],
            'open_slow_requests',
            wallos_maintenance_translate($i18n, 'open_slow_requests', 'Open Slow Requests')
        );
    }

    foreach (($storage['directories'] ?? []) as $key => $directory) {
        $scanErrors = (int) ($directory['scan_errors'] ?? 0);
        if ($scanErrors <= 0) {
            continue;
        }

        $recommendations[] = wallos_build_maintenance_recommendation(
            'watch',
            wallos_maintenance_translate($i18n, 'maintenance_recommendation_scan_errors_title', 'Storage scan reported errors'),
            wallos_maintenance_translate($i18n, 'maintenance_recommendation_scan_errors_message', 'Some storage paths could not be scanned completely. Check local file permissions and directory health.'),
            [
                wallos_maintenance_translate($i18n, 'directory', 'Directory') . ': ' . (string) $key,
                wallos_maintenance_translate($i18n, 'scan_errors', 'Scan Errors') . ': ' . number_format($scanErrors),
            ]
        );
    }

    $backupBytes = (int) ($storage['directories']['backups']['size_bytes'] ?? 0);
    if ($backupBytes >= 2 * 1024 * 1024 * 1024) {
        $recommendations[] = wallos_build_maintenance_recommendation(
            'watch',
            wallos_maintenance_translate($i18n, 'maintenance_recommendation_backup_size_title', 'Backup directory is getting large'),
            wallos_maintenance_translate($i18n, 'maintenance_recommendation_backup_size_message', 'Backups are intentionally not deleted from the web UI. Review the Docker-mounted backup directory manually if disk space becomes tight.'),
            [
                wallos_maintenance_translate($i18n, 'backup_storage_size', 'Backup Storage') . ': ' . ($storage['directories']['backups']['size_label'] ?? wallos_format_maintenance_size($backupBytes)),
            ]
        );
    }

    if (empty($recommendations)) {
        $recommendations[] = wallos_build_maintenance_recommendation(
            'ok',
            wallos_maintenance_translate($i18n, 'maintenance_recommendation_ok_title', 'No maintenance action needed'),
            wallos_maintenance_translate($i18n, 'maintenance_recommendation_ok_message', 'Storage, logs, image index, SQLite indexes, and recent slow requests are within the current operating range.')
        );
    }

    $severityCounts = ['ok' => 0, 'watch' => 0, 'action' => 0];
    foreach ($recommendations as $recommendation) {
        $severity = (string) ($recommendation['severity'] ?? 'watch');
        if (!isset($severityCounts[$severity])) {
            $severity = 'watch';
        }
        $severityCounts[$severity]++;
    }

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'status' => $severityCounts['action'] > 0 ? 'action' : ($severityCounts['watch'] > 0 ? 'watch' : 'ok'),
        'severity_counts' => $severityCounts,
        'slow_request_threshold_ms' => WALLOS_SLOW_REQUEST_THRESHOLD_MS,
        'recommendations' => $recommendations,
    ];
}

function wallos_build_system_overview_card($tone, $icon, $label, $value, $detail = '')
{
    return [
        'tone' => in_array($tone, ['ok', 'watch', 'action'], true) ? $tone : 'watch',
        'icon' => (string) $icon,
        'label' => (string) $label,
        'value' => (string) $value,
        'detail' => (string) $detail,
    ];
}

function wallos_system_overview_status_label($i18n, $status)
{
    $status = in_array($status, ['ok', 'watch', 'action'], true) ? $status : 'watch';
    return wallos_maintenance_translate(
        $i18n,
        'maintenance_recommendation_status_' . $status,
        $status === 'ok' ? 'OK' : ($status === 'action' ? 'Action' : 'Watch')
    );
}

function wallos_get_admin_system_overview_summary($db, $basePath, $i18n = null, $storage = null, $recommendationSummary = null)
{
    if (!is_array($storage)) {
        $storage = wallos_get_storage_usage_summary($db, $basePath);
    }
    if (!is_array($recommendationSummary)) {
        $recommendationSummary = wallos_get_maintenance_recommendation_summary($db, $basePath, $i18n);
    }

    $indexHealth = wallos_check_sqlite_index_health($db);
    $imageAudit = wallos_audit_subscription_image_storage($db, $basePath);
    $slowRequests24h = wallos_count_maintenance_slow_requests($db, 24);
    $maintenanceActionActivity = wallos_get_maintenance_log_activity(
        $db,
        'maintenance_action_logs',
        WALLOS_MAINTENANCE_ACTION_LOG_RETENTION_DAYS
    );
    $failedMaintenanceActions24h = wallos_count_recent_failed_maintenance_actions($db, 24);
    $securityAnomalies24h = function_exists('wallos_count_security_anomalies')
        ? wallos_count_security_anomalies($db, 24)
        : 0;
    $securityTypeSummary = function_exists('wallos_get_security_anomaly_type_counts') && function_exists('wallos_summarize_security_anomaly_type_counts')
        ? wallos_summarize_security_anomaly_type_counts(wallos_get_security_anomaly_type_counts($db, 24))
        : '-';

    $maintenanceStatus = (string) ($recommendationSummary['status'] ?? 'ok');
    $severityCounts = $recommendationSummary['severity_counts'] ?? ['ok' => 0, 'watch' => 0, 'action' => 0];
    $indexProblemCount = (int) ($indexHealth['missing_indexes'] ?? 0) + (int) ($indexHealth['invalid_indexes'] ?? 0);
    $orphanFiles = (int) ($imageAudit['orphan_files'] ?? 0);
    $orphanBytes = (int) ($imageAudit['orphan_bytes'] ?? 0);
    $missingVariantRows = (int) ($imageAudit['missing_variant_rows'] ?? 0);

    $logRisks = [];
    $logTone = 'ok';
    foreach (($storage['logs'] ?? []) as $logInfo) {
        $risk = (string) ($logInfo['risk'] ?? 'normal');
        if ($risk === 'high') {
            $logTone = 'action';
        } elseif ($risk === 'watch' && $logTone !== 'action') {
            $logTone = 'watch';
        }

        $logRisks[] = (string) ($logInfo['table'] ?? '-') . '=' . (string) ($logInfo['rows_label'] ?? number_format((int) ($logInfo['rows'] ?? 0)));
    }

    $cards = [
        wallos_build_system_overview_card(
            'ok',
            'fa-solid fa-heart-pulse',
            wallos_maintenance_translate($i18n, 'system_overview_service_health', 'Service Health'),
            wallos_maintenance_translate($i18n, 'system_overview_ok', 'OK'),
            wallos_maintenance_translate($i18n, 'system_overview_service_health_detail', 'Admin page, PHP runtime, and SQLite connection are responding.')
        ),
        wallos_build_system_overview_card(
            $maintenanceStatus,
            'fa-solid fa-clipboard-check',
            wallos_maintenance_translate($i18n, 'system_overview_maintenance_status', 'Maintenance Status'),
            wallos_system_overview_status_label($i18n, $maintenanceStatus),
            wallos_maintenance_translate($i18n, 'system_overview_maintenance_detail', 'Action / Watch / OK') . ': '
                . (int) ($severityCounts['action'] ?? 0) . ' / '
                . (int) ($severityCounts['watch'] ?? 0) . ' / '
                . (int) ($severityCounts['ok'] ?? 0)
        ),
        wallos_build_system_overview_card(
            empty($indexHealth['success']) ? 'action' : 'ok',
            'fa-solid fa-database',
            wallos_maintenance_translate($i18n, 'system_overview_sqlite_indexes', 'SQLite Indexes'),
            empty($indexHealth['success'])
                ? number_format($indexProblemCount)
                : wallos_maintenance_translate($i18n, 'system_overview_ok', 'OK'),
            wallos_maintenance_translate($i18n, 'sqlite_index_total', 'Expected Indexes') . ': ' . number_format((int) ($indexHealth['total_indexes'] ?? 0))
        ),
        wallos_build_system_overview_card(
            $slowRequests24h >= 20 ? 'action' : ($slowRequests24h > 0 ? 'watch' : 'ok'),
            'fa-solid fa-gauge-high',
            wallos_maintenance_translate($i18n, 'slow_requests_24h', 'Slow Requests (24h)'),
            number_format($slowRequests24h),
            wallos_maintenance_translate($i18n, 'slow_request_threshold', 'Slow Request Threshold') . ': ' . number_format(WALLOS_SLOW_REQUEST_THRESHOLD_MS) . ' ms'
        ),
        wallos_build_system_overview_card(
            $securityAnomalies24h >= 20 ? 'action' : ($securityAnomalies24h > 0 ? 'watch' : 'ok'),
            'fa-solid fa-shield-halved',
            wallos_maintenance_translate($i18n, 'recent_security_anomalies', 'Recent Security Anomalies'),
            number_format($securityAnomalies24h),
            wallos_maintenance_translate($i18n, 'recent_anomaly_type_breakdown', 'Recent Anomaly Type Breakdown') . ': ' . ($securityTypeSummary !== '' ? $securityTypeSummary : '-')
        ),
        wallos_build_system_overview_card(
            $orphanBytes >= 100 * 1024 * 1024 ? 'action' : (($orphanFiles > 0 || $missingVariantRows > 0) ? 'watch' : 'ok'),
            'fa-solid fa-images',
            wallos_maintenance_translate($i18n, 'system_overview_subscription_images', 'Subscription Images'),
            wallos_maintenance_translate($i18n, 'orphan_files', 'Orphan Files') . ': ' . number_format($orphanFiles),
            wallos_maintenance_translate($i18n, 'missing_derived_image_rows', 'Missing Derived Image Rows') . ': ' . number_format($missingVariantRows)
        ),
        wallos_build_system_overview_card(
            $logTone,
            'fa-solid fa-table-list',
            wallos_maintenance_translate($i18n, 'system_overview_log_growth', 'Log Growth'),
            wallos_maintenance_translate($i18n, 'log_growth_risk_' . ($logTone === 'action' ? 'high' : ($logTone === 'watch' ? 'watch' : 'normal')), ucfirst($logTone)),
            implode(' | ', $logRisks)
        ),
        wallos_build_system_overview_card(
            $failedMaintenanceActions24h > 0 ? 'watch' : 'ok',
            'fa-solid fa-screwdriver-wrench',
            wallos_maintenance_translate($i18n, 'system_overview_maintenance_actions', 'Maintenance Actions'),
            (string) ($maintenanceActionActivity['last_24h_rows_label'] ?? '0') . ' / ' . number_format($failedMaintenanceActions24h),
            wallos_maintenance_translate($i18n, 'system_overview_maintenance_actions_detail', 'Last 24h actions / retention days') . ': '
                . (string) ($maintenanceActionActivity['last_24h_rows_label'] ?? '0')
                . ' / '
                . number_format(WALLOS_MAINTENANCE_ACTION_LOG_RETENTION_DAYS)
                . ' | '
                . wallos_maintenance_translate($i18n, 'system_overview_maintenance_action_failures', 'Recent failures') . ': '
                . number_format($failedMaintenanceActions24h)
        ),
    ];

    $overallStatus = 'ok';
    foreach ($cards as $card) {
        if (($card['tone'] ?? '') === 'action') {
            $overallStatus = 'action';
            break;
        }
        if (($card['tone'] ?? '') === 'watch') {
            $overallStatus = 'watch';
        }
    }

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'status' => $overallStatus,
        'cards' => $cards,
    ];
}

function wallos_collect_subscription_image_index($db)
{
    $indexedPaths = [];
    $missingVariantRows = 0;
    $rowCount = 0;
    $stmt = $db->prepare('SELECT id, path, preview_path, thumbnail_path FROM subscription_uploaded_images');
    $result = $stmt ? $stmt->execute() : false;

    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $rowCount++;
        $hasMissingVariant = false;
        foreach (['path', 'preview_path', 'thumbnail_path'] as $column) {
            $path = trim((string) ($row[$column] ?? ''));
            if ($path !== '') {
                $indexedPaths[str_replace('\\', '/', $path)] = true;
                continue;
            }

            if ($column !== 'path') {
                $hasMissingVariant = true;
            }
        }

        if ($hasMissingVariant) {
            $missingVariantRows++;
        }
    }

    return [
        'paths' => $indexedPaths,
        'row_count' => $rowCount,
        'missing_variant_rows' => $missingVariantRows,
    ];
}

function wallos_extract_subscription_image_user_id_from_path($path)
{
    $path = str_replace('\\', '/', trim((string) $path));
    if (preg_match('#/subscription-media/user-(\d+)/#', '/' . ltrim($path, '/'), $matches)) {
        return (int) $matches[1];
    }

    return 0;
}

function wallos_increment_subscription_image_user_issue(array &$summary, $userId, $field, $amount = 1)
{
    $userId = max(0, (int) $userId);
    $key = (string) $userId;
    if (!isset($summary[$key])) {
        $summary[$key] = [
            'user_id' => $userId,
            'orphan_files' => 0,
            'orphan_bytes' => 0,
            'orphan_size_label' => wallos_format_maintenance_size(0),
            'missing_original_rows' => 0,
            'missing_variant_files' => 0,
            'oversized_variants' => 0,
            'total_issues' => 0,
        ];
    }

    if (!array_key_exists($field, $summary[$key])) {
        $summary[$key][$field] = 0;
    }

    $summary[$key][$field] += (int) $amount;
    if ($field !== 'orphan_bytes') {
        $summary[$key]['total_issues'] += (int) $amount;
    }
    $summary[$key]['orphan_size_label'] = wallos_format_maintenance_size((int) $summary[$key]['orphan_bytes']);
}

function wallos_collect_subscription_image_variant_health($db, $basePath)
{
    $summary = [
        'checked_variant_rows' => 0,
        'missing_original_rows' => 0,
        'missing_original_samples' => [],
        'missing_variant_files' => 0,
        'missing_variant_file_samples' => [],
        'oversized_variant_rows' => 0,
        'oversized_variants' => 0,
        'oversized_variant_bytes' => 0,
        'oversized_variant_size_label' => wallos_format_maintenance_size(0),
        'oversized_variant_samples' => [],
        'user_issue_summary' => [],
    ];

    if (!wallos_maintenance_table_has_columns($db, 'subscription_uploaded_images', ['id', 'user_id', 'subscription_id', 'path', 'preview_path', 'thumbnail_path'])) {
        return $summary;
    }

    $stmt = $db->prepare('
        SELECT id, user_id, subscription_id, path, preview_path, thumbnail_path
        FROM subscription_uploaded_images
        ORDER BY user_id ASC, subscription_id ASC, id ASC
    ');
    $result = $stmt ? $stmt->execute() : false;

    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $summary['checked_variant_rows']++;
        $imageId = (int) ($row['id'] ?? 0);
        $subscriptionId = (int) ($row['subscription_id'] ?? 0);
        $originalPath = trim((string) ($row['path'] ?? ''));
        $originalAbsolutePath = $originalPath === '' ? '' : wallos_resolve_subscription_image_absolute_path($basePath, $originalPath);

        if ($originalAbsolutePath === '') {
            $summary['missing_original_rows']++;
            wallos_increment_subscription_image_user_issue($summary['user_issue_summary'], (int) ($row['user_id'] ?? 0), 'missing_original_rows');
            if (count($summary['missing_original_samples']) < 10) {
                $summary['missing_original_samples'][] = [
                    'image_id' => $imageId,
                    'subscription_id' => $subscriptionId,
                    'path' => $originalPath,
                ];
            }
            continue;
        }

        $originalSize = (int) @filesize($originalAbsolutePath);
        if ($originalSize <= 0) {
            continue;
        }

        $rowHasOversizedVariant = false;
        foreach (['preview_path', 'thumbnail_path'] as $column) {
            $variantPath = trim((string) ($row[$column] ?? ''));
            if ($variantPath === '' || $variantPath === $originalPath) {
                continue;
            }

            $variantAbsolutePath = wallos_resolve_subscription_image_absolute_path($basePath, $variantPath);
            if ($variantAbsolutePath === '') {
                $summary['missing_variant_files']++;
                wallos_increment_subscription_image_user_issue($summary['user_issue_summary'], (int) ($row['user_id'] ?? 0), 'missing_variant_files');
                if (count($summary['missing_variant_file_samples']) < 10) {
                    $summary['missing_variant_file_samples'][] = [
                        'image_id' => $imageId,
                        'subscription_id' => $subscriptionId,
                        'variant' => $column,
                        'path' => $variantPath,
                    ];
                }
                continue;
            }

            $variantSize = (int) @filesize($variantAbsolutePath);
            if ($variantSize <= 0 || $variantSize < $originalSize) {
                continue;
            }

            $rowHasOversizedVariant = true;
            $summary['oversized_variants']++;
            $summary['oversized_variant_bytes'] += $variantSize;
            wallos_increment_subscription_image_user_issue($summary['user_issue_summary'], (int) ($row['user_id'] ?? 0), 'oversized_variants');
            if (count($summary['oversized_variant_samples']) < 10) {
                $summary['oversized_variant_samples'][] = [
                    'image_id' => $imageId,
                    'subscription_id' => $subscriptionId,
                    'variant' => $column,
                    'path' => $variantPath,
                    'variant_size_bytes' => $variantSize,
                    'variant_size_label' => wallos_format_maintenance_size($variantSize),
                    'original_path' => $originalPath,
                    'original_size_bytes' => $originalSize,
                    'original_size_label' => wallos_format_maintenance_size($originalSize),
                ];
            }
        }

        if ($rowHasOversizedVariant) {
            $summary['oversized_variant_rows']++;
        }
    }

    $summary['oversized_variant_size_label'] = wallos_format_maintenance_size($summary['oversized_variant_bytes']);
    return $summary;
}

function wallos_scan_subscription_image_files($basePath)
{
    return array_map(
        function ($file) {
            return $file['path'];
        },
        wallos_scan_subscription_image_file_details($basePath)
    );
}

function wallos_scan_subscription_image_file_details($basePath)
{
    $mediaRoot = wallos_get_subscription_media_disk_dir($basePath);
    if (!is_dir($mediaRoot)) {
        return [];
    }

    $files = [];
    $normalizedBasePath = str_replace('\\', '/', rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($mediaRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $absolutePath = str_replace('\\', '/', $fileInfo->getPathname());
        $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($normalizedBasePath)));
        $sizeBytes = (int) @filesize($fileInfo->getPathname());
        $files[] = [
            'path' => $relativePath,
            'size_bytes' => $sizeBytes,
            'size_label' => wallos_format_maintenance_size($sizeBytes),
        ];
    }

    return $files;
}

function wallos_audit_subscription_image_storage($db, $basePath)
{
    $index = wallos_collect_subscription_image_index($db);
    $variantHealth = wallos_collect_subscription_image_variant_health($db, $basePath);
    $userIssueSummary = $variantHealth['user_issue_summary'] ?? [];
    $files = wallos_scan_subscription_image_file_details($basePath);
    $orphanFiles = [];
    $orphanDetails = [];
    $orphanBytes = 0;

    foreach ($files as $file) {
        $path = (string) ($file['path'] ?? '');
        if (!isset($index['paths'][$path])) {
            $orphanFiles[] = $path;
            $orphanDetails[] = $file;
            $orphanBytes += (int) ($file['size_bytes'] ?? 0);
            $pathUserId = wallos_extract_subscription_image_user_id_from_path($path);
            wallos_increment_subscription_image_user_issue($userIssueSummary, $pathUserId, 'orphan_files');
            wallos_increment_subscription_image_user_issue($userIssueSummary, $pathUserId, 'orphan_bytes', (int) ($file['size_bytes'] ?? 0));
        }
    }

    $userIssueSummary = array_values($userIssueSummary);
    usort($userIssueSummary, static function ($left, $right) {
        $leftScore = (int) ($left['total_issues'] ?? 0);
        $rightScore = (int) ($right['total_issues'] ?? 0);
        if ($leftScore === $rightScore) {
            return (int) ($left['user_id'] ?? 0) <=> (int) ($right['user_id'] ?? 0);
        }

        return $rightScore <=> $leftScore;
    });
    $largestOrphanDetails = $orphanDetails;
    usort($largestOrphanDetails, static function ($left, $right) {
        return (int) ($right['size_bytes'] ?? 0) <=> (int) ($left['size_bytes'] ?? 0);
    });

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'indexed_rows' => (int) $index['row_count'],
        'indexed_files' => count($index['paths']),
        'disk_files' => count($files),
        'orphan_files' => count($orphanFiles),
        'orphan_bytes' => $orphanBytes,
        'orphan_size_label' => wallos_format_maintenance_size($orphanBytes),
        'reclaimable_bytes_estimate' => $orphanBytes + (int) ($variantHealth['oversized_variant_bytes'] ?? 0),
        'reclaimable_size_estimate_label' => wallos_format_maintenance_size($orphanBytes + (int) ($variantHealth['oversized_variant_bytes'] ?? 0)),
        'missing_variant_rows' => (int) $index['missing_variant_rows'],
        'orphan_samples' => array_slice($orphanFiles, 0, 10),
        'orphan_details' => $orphanDetails,
        'orphan_largest_samples' => array_slice($largestOrphanDetails, 0, 10),
        'user_issue_summary' => $userIssueSummary,
    ] + $variantHealth;
}

function wallos_cleanup_subscription_image_orphans($db, $basePath)
{
    $audit = wallos_audit_subscription_image_storage($db, $basePath);
    $deletedFiles = 0;
    $deletedBytes = 0;
    $failedFiles = [];

    foreach (($audit['orphan_details'] ?? []) as $file) {
        $relativePath = trim((string) ($file['path'] ?? ''));
        if ($relativePath === '' || !wallos_subscription_image_path_is_within_media_dir($relativePath)) {
            $failedFiles[] = $relativePath;
            continue;
        }

        $absolutePath = wallos_resolve_subscription_image_absolute_path($basePath, $relativePath);
        if ($absolutePath === '') {
            continue;
        }

        $fileSize = isset($file['size_bytes']) ? (int) $file['size_bytes'] : 0;
        if ($fileSize <= 0) {
            $detectedFileSize = @filesize($absolutePath);
            $fileSize = $detectedFileSize === false ? 0 : (int) $detectedFileSize;
        }
        wallos_delete_subscription_image_file($basePath, $relativePath);

        if (wallos_resolve_subscription_image_absolute_path($basePath, $relativePath) === '') {
            $deletedFiles++;
            $deletedBytes += $fileSize;
            continue;
        }

        $failedFiles[] = $relativePath;
    }

    return [
        'success' => empty($failedFiles),
        'deleted_files' => $deletedFiles,
        'deleted_bytes' => $deletedBytes,
        'deleted_size_label' => wallos_format_maintenance_size($deletedBytes),
        'failed_files' => count($failedFiles),
        'failed_samples' => array_slice($failedFiles, 0, 10),
        'before' => $audit,
        'after' => wallos_audit_subscription_image_storage($db, $basePath),
    ];
}

function wallos_run_sqlite_maintenance($db)
{
    $startedAt = microtime(true);
    $before = wallos_get_sqlite_database_metrics($db);
    $db->exec('PRAGMA optimize');
    $db->exec('ANALYZE');
    $db->exec('VACUUM');
    $after = wallos_get_sqlite_database_metrics($db);

    return [
        'success' => true,
        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        'before' => $before,
        'after' => $after,
    ];
}

function wallos_get_maintenance_retention_summary()
{
    return [
        'request_log_retention_days' => WALLOS_REQUEST_LOG_RETENTION_DAYS,
        'security_anomaly_retention_days' => WALLOS_SECURITY_ANOMALY_RETENTION_DAYS,
        'rate_limit_usage_retention_days' => WALLOS_RATE_LIMIT_USAGE_RETENTION_DAYS,
        'maintenance_action_log_retention_days' => WALLOS_MAINTENANCE_ACTION_LOG_RETENTION_DAYS,
    ];
}
