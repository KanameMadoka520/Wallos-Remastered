<?php

define('WALLOS_BACKUP_DEFAULT_RETENTION_DAYS', 14);
define('WALLOS_BACKUP_MAX_RETENTION_DAYS', 365);

function wallos_get_backup_storage_dir($basePath = null)
{
    $rootPath = $basePath !== null ? rtrim((string) $basePath, '/\\') : dirname(__DIR__);
    return $rootPath . DIRECTORY_SEPARATOR . 'backups';
}

function wallos_ensure_backup_storage_dir($basePath = null)
{
    $directory = wallos_get_backup_storage_dir($basePath);
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    return $directory;
}

function wallos_get_backup_retention_days($db)
{
    $row = $db->querySingle('SELECT backup_retention_days FROM admin WHERE id = 1', true);
    $retentionDays = (int) ($row['backup_retention_days'] ?? WALLOS_BACKUP_DEFAULT_RETENTION_DAYS);

    if ($retentionDays < 1) {
        $retentionDays = WALLOS_BACKUP_DEFAULT_RETENTION_DAYS;
    }
    if ($retentionDays > WALLOS_BACKUP_MAX_RETENTION_DAYS) {
        $retentionDays = WALLOS_BACKUP_MAX_RETENTION_DAYS;
    }

    return $retentionDays;
}

function wallos_normalize_backup_mode($mode)
{
    return strtolower(trim((string) $mode)) === 'auto' ? 'auto' : 'manual';
}

function wallos_get_backup_download_url($fileName)
{
    return 'endpoints/admin/downloadbackup.php?name=' . rawurlencode((string) $fileName);
}

function wallos_format_backup_size($bytes)
{
    $bytes = max(0, (int) $bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float) $bytes;
    $unitIndex = 0;

    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }

    return number_format($size, $unitIndex === 0 ? 0 : 1) . ' ' . $units[$unitIndex];
}

function wallos_build_backup_file_name($mode)
{
    return sprintf(
        'wallos-backup-%s-%s-%s.zip',
        wallos_normalize_backup_mode($mode),
        date('Ymd-His'),
        bin2hex(random_bytes(3))
    );
}

function wallos_add_directory_to_zip($sourceDir, ZipArchive $zipArchive, $archiveRoot = '')
{
    if (!is_dir($sourceDir)) {
        return;
    }

    $normalizedArchiveRoot = trim(str_replace('\\', '/', (string) $archiveRoot), '/');
    if ($normalizedArchiveRoot !== '') {
        $zipArchive->addEmptyDir($normalizedArchiveRoot);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $itemPath = $item->getPathname();
        $relativePath = trim(str_replace('\\', '/', substr($itemPath, strlen(rtrim($sourceDir, '/\\')) + 1)), '/');
        if ($relativePath === '') {
            continue;
        }

        $archivePath = $normalizedArchiveRoot !== '' ? $normalizedArchiveRoot . '/' . $relativePath : $relativePath;
        if ($item->isDir()) {
            $zipArchive->addEmptyDir($archivePath);
        } else {
            $zipArchive->addFile($itemPath, $archivePath);
        }
    }
}

function wallos_create_backup_database_snapshot($databaseFile, $snapshotPath)
{
    if (file_exists($snapshotPath)) {
        unlink($snapshotPath);
    }

    $database = new SQLite3($databaseFile);
    $database->busyTimeout(5000);

    $escapedSnapshotPath = str_replace("'", "''", $snapshotPath);
    $result = $database->exec("VACUUM INTO '" . $escapedSnapshotPath . "'");
    $database->close();

    if ($result === false || !is_file($snapshotPath)) {
        throw new RuntimeException('Failed to create database snapshot');
    }
}

function wallos_describe_backup_file($filePath)
{
    $fileName = basename((string) $filePath);
    if (!is_file($filePath) || !preg_match('/\.zip$/i', $fileName)) {
        return null;
    }

    $mode = 'manual';
    if (preg_match('/^wallos-backup-(auto|manual)-\d{8}-\d{6}-[a-f0-9]+\.zip$/i', $fileName, $matches)) {
        $mode = strtolower($matches[1]);
    }

    $modifiedAt = @filemtime($filePath);
    if ($modifiedAt === false) {
        $modifiedAt = time();
    }

    $sizeBytes = (int) @filesize($filePath);

    return [
        'name' => $fileName,
        'mode' => $mode,
        'path' => $filePath,
        'size_bytes' => $sizeBytes,
        'size_label' => wallos_format_backup_size($sizeBytes),
        'modified_at' => $modifiedAt,
        'created_at' => date('Y-m-d H:i:s', $modifiedAt),
        'download_url' => wallos_get_backup_download_url($fileName),
    ];
}

function wallos_list_backups($db = null, $limit = 20, $basePath = null)
{
    $backupDirectory = wallos_ensure_backup_storage_dir($basePath);
    $backups = [];

    $entries = @scandir($backupDirectory);
    if ($entries === false) {
        return [];
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $description = wallos_describe_backup_file($backupDirectory . DIRECTORY_SEPARATOR . $entry);
        if ($description !== null) {
            $backups[] = $description;
        }
    }

    usort($backups, function ($left, $right) {
        return $right['modified_at'] <=> $left['modified_at'];
    });

    return array_slice($backups, 0, max(1, (int) $limit));
}

function wallos_find_backup_by_name($fileName, $basePath = null)
{
    $fileName = basename(trim((string) $fileName));
    if ($fileName === '' || !preg_match('/^[A-Za-z0-9._-]+\.zip$/', $fileName)) {
        return null;
    }

    $backupDirectory = wallos_ensure_backup_storage_dir($basePath);
    $filePath = $backupDirectory . DIRECTORY_SEPARATOR . $fileName;
    $realFilePath = realpath($filePath);
    $realBackupDirectory = realpath($backupDirectory);

    if ($realFilePath === false || $realBackupDirectory === false || !is_file($realFilePath)) {
        return null;
    }

    $normalizedDirectory = rtrim(str_replace('\\', '/', $realBackupDirectory), '/');
    $normalizedPath = str_replace('\\', '/', $realFilePath);
    if (strpos($normalizedPath, $normalizedDirectory . '/') !== 0) {
        return null;
    }

    return wallos_describe_backup_file($realFilePath);
}

function wallos_delete_backup_by_name($fileName, $basePath = null)
{
    $backup = wallos_find_backup_by_name($fileName, $basePath);
    if ($backup === null) {
        return false;
    }

    return @unlink($backup['path']);
}

function wallos_cleanup_old_backups($db, $basePath = null)
{
    $retentionDays = wallos_get_backup_retention_days($db);
    $thresholdTimestamp = strtotime('-' . $retentionDays . ' days');
    $backupDirectory = wallos_ensure_backup_storage_dir($basePath);
    $deletedBackups = [];

    $entries = @scandir($backupDirectory);
    if ($entries === false) {
        return [
            'deleted_count' => 0,
            'deleted_backups' => [],
            'retention_days' => $retentionDays,
        ];
    }

    foreach ($entries as $entry) {
        $description = wallos_describe_backup_file($backupDirectory . DIRECTORY_SEPARATOR . $entry);
        if ($description === null) {
            continue;
        }

        if ($description['modified_at'] >= $thresholdTimestamp) {
            continue;
        }

        if (@unlink($description['path'])) {
            $deletedBackups[] = $description['name'];
        }
    }

    return [
        'deleted_count' => count($deletedBackups),
        'deleted_backups' => $deletedBackups,
        'retention_days' => $retentionDays,
    ];
}

function wallos_create_backup_archive($db, $mode = 'manual', $basePath = null)
{
    $projectRoot = $basePath !== null ? rtrim((string) $basePath, '/\\') : dirname(__DIR__);
    $backupDirectory = wallos_ensure_backup_storage_dir($projectRoot);
    $databaseFile = $projectRoot . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'wallos.db';
    $logosDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'logos';

    if (!is_file($databaseFile)) {
        throw new RuntimeException('Database file does not exist');
    }

    $mode = wallos_normalize_backup_mode($mode);
    $fileName = wallos_build_backup_file_name($mode);
    $archivePath = $backupDirectory . DIRECTORY_SEPARATOR . $fileName;
    $snapshotPath = $backupDirectory . DIRECTORY_SEPARATOR . '.snapshot-' . bin2hex(random_bytes(4)) . '.db';

    wallos_create_backup_database_snapshot($databaseFile, $snapshotPath);

    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($snapshotPath);
        throw new RuntimeException('Cannot open backup archive');
    }

    $metadata = [
        'mode' => $mode,
        'created_at' => date('c'),
        'includes' => ['wallos.db', 'logos/'],
    ];

    $zip->addFile($snapshotPath, 'wallos.db');
    wallos_add_directory_to_zip($logosDirectory, $zip, 'logos');
    $zip->addFromString('metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    if ($zip->close() === false) {
        @unlink($snapshotPath);
        @unlink($archivePath);
        throw new RuntimeException('Cannot finalize backup archive');
    }

    @unlink($snapshotPath);

    $backup = wallos_find_backup_by_name($fileName, $projectRoot);
    if ($backup === null) {
        throw new RuntimeException('Backup archive was not created');
    }

    return $backup;
}
