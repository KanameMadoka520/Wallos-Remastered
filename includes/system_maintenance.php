<?php

require_once __DIR__ . '/subscription_media.php';
require_once __DIR__ . '/security_maintenance.php';

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
    $mediaRoot = wallos_get_subscription_media_disk_dir($basePath);
    if (!is_dir($mediaRoot)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($mediaRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $absolutePath = str_replace('\\', '/', $fileInfo->getPathname());
        $relativePath = str_replace('\\', '/', substr($absolutePath, strlen(str_replace('\\', '/', rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR))));
        $files[] = $relativePath;
    }

    return $files;
}

function wallos_audit_subscription_image_storage($db, $basePath)
{
    $index = wallos_collect_subscription_image_index($db);
    $files = wallos_scan_subscription_image_files($basePath);
    $orphanFiles = [];

    foreach ($files as $file) {
        if (!isset($index['paths'][$file])) {
            $orphanFiles[] = $file;
        }
    }

    return [
        'indexed_rows' => (int) $index['row_count'],
        'indexed_files' => count($index['paths']),
        'disk_files' => count($files),
        'orphan_files' => count($orphanFiles),
        'missing_variant_rows' => (int) $index['missing_variant_rows'],
        'orphan_samples' => array_slice($orphanFiles, 0, 10),
    ];
}

function wallos_run_sqlite_maintenance($db)
{
    $startedAt = microtime(true);
    $db->exec('PRAGMA optimize');
    $db->exec('ANALYZE');
    $db->exec('VACUUM');

    return [
        'success' => true,
        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
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

