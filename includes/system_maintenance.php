<?php

require_once __DIR__ . '/subscription_media.php';
require_once __DIR__ . '/security_maintenance.php';
require_once __DIR__ . '/backup_manager.php';

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

function wallos_describe_maintenance_log_table($db, $tableName, $retentionDays, $warningRows, $criticalRows)
{
    $rows = wallos_maintenance_count_table_rows($db, $tableName);
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
    ];
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
        ],
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
        }
    }

    return [
        'indexed_rows' => (int) $index['row_count'],
        'indexed_files' => count($index['paths']),
        'disk_files' => count($files),
        'orphan_files' => count($orphanFiles),
        'orphan_bytes' => $orphanBytes,
        'orphan_size_label' => wallos_format_maintenance_size($orphanBytes),
        'missing_variant_rows' => (int) $index['missing_variant_rows'],
        'orphan_samples' => array_slice($orphanFiles, 0, 10),
        'orphan_details' => $orphanDetails,
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
    ];
}
