<?php
require_once __DIR__ . '/timezone_settings.php';
require_once __DIR__ . '/database_runtime_lock.php';

define('WALLOS_BACKUP_DEFAULT_RETENTION_DAYS', 14);
define('WALLOS_BACKUP_MAX_RETENTION_DAYS', 365);
define('WALLOS_BACKUP_MANIFEST_VERSION', 1);

function wallos_normalize_backup_operation_id($value)
{
    $operationId = trim((string) $value);
    return preg_match('/^[A-Za-z0-9_-]{8,80}$/', $operationId) ? $operationId : '';
}

function wallos_get_backup_progress_file_path($operationId, $basePath = null)
{
    $normalizedOperationId = wallos_normalize_backup_operation_id($operationId);
    if ($normalizedOperationId === '') {
        return null;
    }

    $backupDirectory = wallos_ensure_backup_storage_dir($basePath);
    return $backupDirectory . DIRECTORY_SEPARATOR . '.backup-progress-' . $normalizedOperationId . '.json';
}

function wallos_write_backup_progress_status($operationId, array $status, $basePath = null)
{
    $progressFilePath = wallos_get_backup_progress_file_path($operationId, $basePath);
    if ($progressFilePath === null) {
        return false;
    }

    $payload = [
        'operationId' => wallos_normalize_backup_operation_id($operationId),
        'state' => (string) ($status['state'] ?? 'running'),
        'stage' => (string) ($status['stage'] ?? 'waiting'),
        'progress' => max(0, min(100, (int) ($status['progress'] ?? 0))),
        'message' => trim((string) ($status['message'] ?? '')),
        'tone' => (string) ($status['tone'] ?? 'pending'),
        'updatedAt' => date('c'),
    ];

    foreach (['downloadUrl', 'backupName'] as $optionalKey) {
        if (isset($status[$optionalKey])) {
            $payload[$optionalKey] = $status[$optionalKey];
        }
    }

    $temporaryPath = $progressFilePath . '.' . bin2hex(random_bytes(2)) . '.tmp';
    $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encodedPayload === false) {
        return false;
    }

    if (@file_put_contents($temporaryPath, $encodedPayload, LOCK_EX) === false) {
        return false;
    }

    return @rename($temporaryPath, $progressFilePath);
}

function wallos_read_backup_progress_status($operationId, $basePath = null)
{
    $progressFilePath = wallos_get_backup_progress_file_path($operationId, $basePath);
    if ($progressFilePath === null || !is_file($progressFilePath)) {
        return null;
    }

    $rawPayload = @file_get_contents($progressFilePath);
    if ($rawPayload === false || trim($rawPayload) === '') {
        return null;
    }

    $decodedPayload = json_decode($rawPayload, true);
    return is_array($decodedPayload) ? $decodedPayload : null;
}

function wallos_emit_backup_progress($callback, $stage, $progress, array $context = [])
{
    if (!is_callable($callback)) {
        return;
    }

    $callback([
        'stage' => (string) $stage,
        'progress' => max(0, min(100, (int) $progress)),
        'context' => $context,
    ]);
}

function wallos_count_directory_files($sourceDirectory)
{
    if (!is_dir($sourceDirectory)) {
        return 0;
    }

    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $count++;
        }
    }

    return $count;
}

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

function wallos_build_backup_file_name($mode, $timezone = null)
{
    $normalizedTimezone = wallos_normalize_timezone_identifier($timezone, wallos_get_default_backup_timezone());
    $dateTime = new DateTimeImmutable('now', new DateTimeZone($normalizedTimezone));

    return sprintf(
        'wallos-backup-%s-%s-%s.zip',
        wallos_normalize_backup_mode($mode),
        $dateTime->format('Ymd-His'),
        bin2hex(random_bytes(3))
    );
}

function wallos_normalize_backup_entry_name($entryName)
{
    $entryName = trim((string) $entryName);
    if ($entryName === '') {
        return '';
    }

    $entryName = str_replace('\\', '/', $entryName);
    $entryName = preg_replace('#/+#', '/', $entryName);
    $entryName = ltrim($entryName, '/');
    $entryName = rtrim($entryName, '/');

    if ($entryName === '') {
        return '';
    }

    $segments = [];
    foreach (explode('/', $entryName) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }

        if ($segment === '..' || strpos($segment, "\0") !== false || strpos($segment, ':') !== false) {
            return null;
        }

        $segments[] = $segment;
    }

    return implode('/', $segments);
}

function wallos_build_backup_manifest_entry($archivePath, $sourcePath)
{
    return [
        'path' => $archivePath,
        'size_bytes' => (int) filesize($sourcePath),
        'sha256' => hash_file('sha256', $sourcePath),
    ];
}

function wallos_collect_backup_manifest_files($databaseSnapshotPath, $logosDirectory)
{
    $files = [
        'wallos.db' => wallos_build_backup_manifest_entry('wallos.db', $databaseSnapshotPath),
    ];

    if (is_dir($logosDirectory)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($logosDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $logosDirectoryLength = strlen(rtrim($logosDirectory, '/\\')) + 1;
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $relativePath = substr($item->getPathname(), $logosDirectoryLength);
            $relativePath = str_replace('\\', '/', $relativePath);
            $archivePath = wallos_normalize_backup_entry_name('logos/' . $relativePath);
            if ($archivePath === null || $archivePath === '') {
                continue;
            }

            $files[$archivePath] = wallos_build_backup_manifest_entry($archivePath, $item->getPathname());
        }
    }

    ksort($files, SORT_STRING);

    return $files;
}

function wallos_build_backup_manifest($databaseSnapshotPath, $logosDirectory, $timezone = null)
{
    $files = wallos_collect_backup_manifest_files($databaseSnapshotPath, $logosDirectory);
    $normalizedTimezone = wallos_normalize_timezone_identifier($timezone, wallos_get_default_backup_timezone());
    $dateTime = new DateTimeImmutable('now', new DateTimeZone($normalizedTimezone));

    return [
        'version' => WALLOS_BACKUP_MANIFEST_VERSION,
        'created_at' => $dateTime->format('c'),
        'file_count' => count($files),
        'files' => $files,
    ];
}

function wallos_add_directory_to_zip($sourceDir, ZipArchive $zipArchive, $archiveRoot = '', $progressCallback = null, $progressStart = 0, $progressEnd = 100)
{
    if (!is_dir($sourceDir)) {
        wallos_emit_backup_progress($progressCallback, 'zip_archive', $progressEnd, ['current' => 0, 'total' => 0]);
        return;
    }

    $normalizedArchiveRoot = trim(str_replace('\\', '/', (string) $archiveRoot), '/');
    if ($normalizedArchiveRoot !== '') {
        $zipArchive->addEmptyDir($normalizedArchiveRoot);
    }

    $totalFiles = wallos_count_directory_files($sourceDir);
    $processedFiles = 0;

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
            $processedFiles++;
            $progress = $totalFiles > 0
                ? $progressStart + (($progressEnd - $progressStart) * ($processedFiles / $totalFiles))
                : $progressEnd;
            wallos_emit_backup_progress($progressCallback, 'zip_archive', (int) round($progress), [
                'current' => $processedFiles,
                'total' => $totalFiles,
            ]);
        }
    }

    wallos_emit_backup_progress($progressCallback, 'zip_archive', $progressEnd, [
        'current' => $processedFiles,
        'total' => $totalFiles,
    ]);
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

function wallos_describe_backup_file($filePath, $timezone = null)
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
    $normalizedTimezone = wallos_normalize_timezone_identifier($timezone, wallos_get_default_backup_timezone());
    $dateTime = new DateTimeImmutable('@' . $modifiedAt);
    $dateTime = $dateTime->setTimezone(new DateTimeZone($normalizedTimezone));

    return [
        'name' => $fileName,
        'mode' => $mode,
        'path' => $filePath,
        'size_bytes' => $sizeBytes,
        'size_label' => wallos_format_backup_size($sizeBytes),
        'modified_at' => $modifiedAt,
        'created_at' => $dateTime->format('Y-m-d H:i:s'),
        'download_url' => wallos_get_backup_download_url($fileName),
    ];
}

function wallos_list_backups($db = null, $limit = 20, $basePath = null)
{
    $backupDirectory = wallos_ensure_backup_storage_dir($basePath);
    $backups = [];
    $backupTimezone = $db ? wallos_fetch_backup_timezone($db) : wallos_get_default_backup_timezone();

    $entries = @scandir($backupDirectory);
    if ($entries === false) {
        return [];
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $description = wallos_describe_backup_file($backupDirectory . DIRECTORY_SEPARATOR . $entry, $backupTimezone);
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

    return wallos_describe_backup_file($realFilePath, wallos_get_default_backup_timezone());
}

function wallos_delete_backup_by_name($fileName, $basePath = null)
{
    $backup = wallos_find_backup_by_name($fileName, $basePath);
    if ($backup === null) {
        return false;
    }

    return @unlink($backup['path']);
}

function wallos_cleanup_backup_temp_files($basePath = null, $maxAgeSeconds = 86400)
{
    $backupDirectory = wallos_ensure_backup_storage_dir($basePath);
    $entries = @scandir($backupDirectory);
    if ($entries === false) {
        return 0;
    }

    $threshold = time() - max(60, (int) $maxAgeSeconds);
    $deletedCount = 0;

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        if (
            strpos($entry, '.snapshot-') !== 0
            && !preg_match('/\.zip\.[A-Za-z0-9]+\.part$/', $entry)
            && !preg_match('/^\.backup-progress-[A-Za-z0-9_-]+\.json$/', $entry)
        ) {
            continue;
        }

        $path = $backupDirectory . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($path)) {
            continue;
        }

        $modifiedAt = @filemtime($path);
        if ($modifiedAt !== false && $modifiedAt > $threshold) {
            continue;
        }

        if (@unlink($path)) {
            $deletedCount++;
        }
    }

    return $deletedCount;
}

function wallos_cleanup_old_backups($db, $basePath = null)
{
    $retentionDays = wallos_get_backup_retention_days($db);
    $thresholdTimestamp = strtotime('-' . $retentionDays . ' days');
    $backupDirectory = wallos_ensure_backup_storage_dir($basePath);
    $deletedBackups = [];

    wallos_cleanup_backup_temp_files($basePath);

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

function wallos_open_backup_archive($archivePath)
{
    $zip = new ZipArchive();
    if ($zip->open($archivePath) !== true) {
        throw new RuntimeException('Cannot open backup archive');
    }

    return $zip;
}

function wallos_hash_zip_entry(ZipArchive $zip, $entryName)
{
    $stream = $zip->getStream($entryName);
    if ($stream === false) {
        return null;
    }

    $hashContext = hash_init('sha256');
    $sizeBytes = 0;

    while (!feof($stream)) {
        $chunk = fread($stream, 1024 * 1024);
        if ($chunk === false) {
            fclose($stream);
            return null;
        }

        $sizeBytes += strlen($chunk);
        if ($chunk !== '') {
            hash_update($hashContext, $chunk);
        }
    }

    fclose($stream);

    return [
        'size_bytes' => $sizeBytes,
        'sha256' => hash_final($hashContext),
    ];
}

function wallos_get_backup_manifest(ZipArchive $zip)
{
    $manifestRaw = $zip->getFromName('manifest.json');
    if ($manifestRaw === false) {
        return null;
    }

    $manifest = json_decode($manifestRaw, true);
    if (!is_array($manifest) || !isset($manifest['files']) || !is_array($manifest['files'])) {
        throw new RuntimeException('Invalid backup manifest');
    }

    return $manifest;
}

function wallos_verify_backup_archive($archivePath)
{
    $result = [
        'is_valid' => false,
        'level' => 'invalid',
        'files_checked' => 0,
        'expected_files' => 0,
        'errors' => [],
    ];

    try {
        $zip = wallos_open_backup_archive($archivePath);
    } catch (Throwable $throwable) {
        $result['errors'][] = $throwable->getMessage();
        return $result;
    }

    try {
        $wallosDbIndex = $zip->locateName('wallos.db');
        if ($wallosDbIndex === false) {
            $result['errors'][] = 'Missing wallos.db';
            return $result;
        }

        try {
            $manifest = wallos_get_backup_manifest($zip);
        } catch (Throwable $throwable) {
            $result['errors'][] = $throwable->getMessage();
            return $result;
        }

        if ($manifest === null) {
            $databaseHash = wallos_hash_zip_entry($zip, 'wallos.db');
            if ($databaseHash === null || $databaseHash['size_bytes'] < 1) {
                $result['errors'][] = 'Cannot read wallos.db';
                return $result;
            }

            $result['is_valid'] = true;
            $result['level'] = 'basic';
            $result['files_checked'] = 1;
            $result['expected_files'] = 1;
            return $result;
        }

        $files = $manifest['files'];
        $result['expected_files'] = count($files);

        foreach ($files as $entryName => $expectedFile) {
            $normalizedEntryName = wallos_normalize_backup_entry_name($entryName);
            if ($normalizedEntryName === null || $normalizedEntryName === '') {
                $result['errors'][] = 'Invalid manifest entry: ' . $entryName;
                continue;
            }

            if ($zip->locateName($normalizedEntryName) === false) {
                $result['errors'][] = 'Missing file in archive: ' . $normalizedEntryName;
                continue;
            }

            $actualFile = wallos_hash_zip_entry($zip, $normalizedEntryName);
            if ($actualFile === null) {
                $result['errors'][] = 'Cannot read file in archive: ' . $normalizedEntryName;
                continue;
            }

            $expectedSize = (int) ($expectedFile['size_bytes'] ?? -1);
            $expectedHash = strtolower(trim((string) ($expectedFile['sha256'] ?? '')));

            if ($expectedSize < 0 || $expectedHash === '') {
                $result['errors'][] = 'Incomplete manifest entry: ' . $normalizedEntryName;
                continue;
            }

            if ($actualFile['size_bytes'] !== $expectedSize) {
                $result['errors'][] = 'Size mismatch: ' . $normalizedEntryName;
                continue;
            }

            if ($actualFile['sha256'] !== $expectedHash) {
                $result['errors'][] = 'Checksum mismatch: ' . $normalizedEntryName;
                continue;
            }

            $result['files_checked']++;
        }

        if (!isset($files['wallos.db'])) {
            $result['errors'][] = 'Manifest does not include wallos.db';
        }

        if (empty($result['errors'])) {
            $result['is_valid'] = true;
            $result['level'] = 'full';
        }

        return $result;
    } finally {
        $zip->close();
    }
}

function wallos_create_backup_workspace($projectRoot, $prefix)
{
    $tmpRoot = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . '.tmp';
    if (!is_dir($tmpRoot)) {
        mkdir($tmpRoot, 0755, true);
    }

    $workspace = $tmpRoot . DIRECTORY_SEPARATOR . $prefix . '-' . bin2hex(random_bytes(6));
    mkdir($workspace, 0755, true);

    return $workspace;
}

function wallos_delete_directory_tree($path)
{
    if (!file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($path);
}

function wallos_copy_stream_to_file($stream, $destination)
{
    $destinationDirectory = dirname($destination);
    if (!is_dir($destinationDirectory)) {
        mkdir($destinationDirectory, 0755, true);
    }

    $output = fopen($destination, 'wb');
    if ($output === false) {
        throw new RuntimeException('Cannot write extracted backup file');
    }

    try {
        while (!feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('Cannot read extracted backup file');
            }

            if ($chunk !== '' && fwrite($output, $chunk) === false) {
                throw new RuntimeException('Cannot write extracted backup file');
            }
        }
    } finally {
        fclose($output);
    }
}

function wallos_copy_directory_tree($sourceDirectory, $destinationDirectory, $progressCallback = null, $progressStart = 0, $progressEnd = 100)
{
    if (!is_dir($sourceDirectory)) {
        wallos_emit_backup_progress($progressCallback, 'copy_logos', $progressEnd, ['current' => 0, 'total' => 0]);
        return;
    }

    if (!is_dir($destinationDirectory)) {
        mkdir($destinationDirectory, 0755, true);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $sourceDirectoryLength = strlen(rtrim($sourceDirectory, '/\\')) + 1;
    $totalFiles = wallos_count_directory_files($sourceDirectory);
    $processedFiles = 0;

    foreach ($iterator as $item) {
        $relativePath = substr($item->getPathname(), $sourceDirectoryLength);
        $destinationPath = $destinationDirectory . DIRECTORY_SEPARATOR . $relativePath;

        if ($item->isDir()) {
            if (!is_dir($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }
            continue;
        }

        $destinationPathDirectory = dirname($destinationPath);
        if (!is_dir($destinationPathDirectory)) {
            mkdir($destinationPathDirectory, 0755, true);
        }

        if (!copy($item->getPathname(), $destinationPath)) {
            throw new RuntimeException('Cannot copy restored logos file');
        }
        $processedFiles++;
        $progress = $totalFiles > 0
            ? $progressStart + (($progressEnd - $progressStart) * ($processedFiles / $totalFiles))
            : $progressEnd;
        wallos_emit_backup_progress($progressCallback, 'copy_logos', (int) round($progress), [
            'current' => $processedFiles,
            'total' => $totalFiles,
        ]);
    }

    wallos_emit_backup_progress($progressCallback, 'copy_logos', $progressEnd, [
        'current' => $processedFiles,
        'total' => $totalFiles,
    ]);
}

function wallos_clear_directory_contents($directory)
{
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
        return;
    }

    $entries = scandir($directory);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        wallos_delete_directory_tree($directory . DIRECTORY_SEPARATOR . $entry);
    }
}

function wallos_restore_table_exists(SQLite3 $db, $tableName)
{
    $stmt = $db->prepare('SELECT 1 FROM sqlite_master WHERE type = :type AND name = :name LIMIT 1');
    $stmt->bindValue(':type', 'table', SQLITE3_TEXT);
    $stmt->bindValue(':name', (string) $tableName, SQLITE3_TEXT);
    $result = $stmt->execute();

    return $result->fetchArray(SQLITE3_NUM) !== false;
}

function wallos_restore_table_columns(SQLite3 $db, $tableName)
{
    $quotedTableName = str_replace("'", "''", (string) $tableName);
    $result = $db->query("PRAGMA table_info('{$quotedTableName}')");
    $columns = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columnName = (string) ($row['name'] ?? '');
        if ($columnName !== '') {
            $columns[] = $columnName;
        }
    }

    return $columns;
}

function wallos_restore_index_columns(SQLite3 $db, $indexName)
{
    $quotedIndexName = str_replace("'", "''", (string) $indexName);
    $result = $db->query("PRAGMA index_info('{$quotedIndexName}')");
    $columns = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columnName = (string) ($row['name'] ?? '');
        if ($columnName !== '') {
            $columns[] = $columnName;
        }
    }

    return $columns;
}

function wallos_restore_index_table(SQLite3 $db, $indexName)
{
    $stmt = $db->prepare('SELECT tbl_name FROM sqlite_master WHERE type = :type AND name = :name LIMIT 1');
    $stmt->bindValue(':type', 'index', SQLITE3_TEXT);
    $stmt->bindValue(':name', (string) $indexName, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    return $row === false ? null : (string) ($row['tbl_name'] ?? '');
}

function wallos_restore_normalize_migration_marker($migrationMarker)
{
    $migrationMarker = str_replace('\\', '/', trim((string) $migrationMarker));
    if (!preg_match('#^(?:\.\./\.\./)?migrations/([0-9]{6}\.php)$#D', $migrationMarker, $matches)) {
        return null;
    }

    return 'migrations/' . $matches[1];
}

function wallos_restore_migration_catalog($projectRoot)
{
    $migrationPaths = glob(rtrim((string) $projectRoot, '/\\') . '/migrations/*.php') ?: [];
    sort($migrationPaths, SORT_STRING);
    if (!$migrationPaths) {
        throw new RuntimeException('No migration files are available for restored database validation');
    }

    $catalog = [];
    $expectedNumber = 1;
    foreach ($migrationPaths as $migrationPath) {
        $fileName = basename($migrationPath);
        if (!preg_match('/^([0-9]{6})\.php$/D', $fileName, $matches)) {
            throw new RuntimeException('Unexpected migration file name: ' . $fileName);
        }

        $migrationNumber = (int) $matches[1];
        if ($migrationNumber !== $expectedNumber) {
            throw new RuntimeException(sprintf(
                'Migration source is not continuous: expected %06d.php, found %s',
                $expectedNumber,
                $fileName
            ));
        }

        $catalog['migrations/' . $fileName] = $migrationPath;
        $expectedNumber++;
    }

    return $catalog;
}

function wallos_restore_read_completed_migrations(SQLite3 $db, array $catalog)
{
    if (!wallos_restore_table_exists($db, 'migrations')) {
        return [];
    }

    $completed = [];
    $result = $db->query('SELECT migration FROM migrations ORDER BY id ASC');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rawMarker = (string) ($row['migration'] ?? '');
        $migrationName = wallos_restore_normalize_migration_marker($rawMarker);
        if ($migrationName === null) {
            throw new RuntimeException('Restored database contains an invalid migration marker: ' . $rawMarker);
        }
        if (!isset($catalog[$migrationName])) {
            throw new RuntimeException('Restored database was created by an unknown migration: ' . $migrationName);
        }

        $completed[$migrationName] = true;
    }

    return $completed;
}

function wallos_restore_assert_migration_prefix(array $catalog, array $completed)
{
    $firstMissingMigration = null;
    foreach ($catalog as $migrationName => $migrationPath) {
        if (!isset($completed[$migrationName])) {
            if ($firstMissingMigration === null) {
                $firstMissingMigration = $migrationName;
            }
            continue;
        }

        if ($firstMissingMigration !== null) {
            throw new RuntimeException(
                'Restored database migration history has a gap at ' . $firstMissingMigration
            );
        }
    }
}

function wallos_restore_assert_database_integrity(SQLite3 $db)
{
    $integrityResult = $db->query('PRAGMA integrity_check');
    $integrityRows = 0;
    while ($row = $integrityResult->fetchArray(SQLITE3_NUM)) {
        $integrityRows++;
        if (strtolower(trim((string) ($row[0] ?? ''))) !== 'ok') {
            throw new RuntimeException('Restored database integrity check failed');
        }
    }
    if ($integrityRows === 0) {
        throw new RuntimeException('Restored database integrity check returned no result');
    }

    $foreignKeyResult = $db->query('PRAGMA foreign_key_check');
    if ($foreignKeyResult->fetchArray(SQLITE3_NUM) !== false) {
        throw new RuntimeException('Restored database foreign key check failed');
    }
}

function wallos_restore_assert_latest_schema(SQLite3 $db, array $catalog)
{
    $requiredColumns = [
        'migrations' => ['id', 'migration', 'migrated_at'],
        'admin' => [],
        'user' => ['period_budget', 'budget_period_type', 'budget_period_anchor_date'],
        'subscriptions' => ['user_id', 'inactive', 'next_payment', 'notify', 'logo_text_color', 'logo_variant'],
        'currencies' => [],
        'settings' => ['week_starts_sunday'],
        'notification_settings' => ['period_summary_at_period_start'],
        'cycles' => ['id', 'days', 'name'],
    ];

    foreach ($requiredColumns as $tableName => $columns) {
        if (!wallos_restore_table_exists($db, $tableName)) {
            throw new RuntimeException('Restored database is missing required table: ' . $tableName);
        }

        $actualColumns = wallos_restore_table_columns($db, $tableName);
        foreach ($columns as $columnName) {
            if (!in_array($columnName, $actualColumns, true)) {
                throw new RuntimeException(
                    'Restored database is missing required column: ' . $tableName . '.' . $columnName
                );
            }
        }
    }

    $requiredIndexes = [
        'idx_subscriptions_user_inactive_next_payment' => [
            'table' => 'subscriptions',
            'columns' => ['user_id', 'inactive', 'next_payment'],
        ],
        'idx_subscriptions_user_notify_inactive' => [
            'table' => 'subscriptions',
            'columns' => ['user_id', 'notify', 'inactive'],
        ],
    ];
    foreach ($requiredIndexes as $indexName => $expectedIndex) {
        if (wallos_restore_index_table($db, $indexName) !== $expectedIndex['table']
            || wallos_restore_index_columns($db, $indexName) !== $expectedIndex['columns']) {
            throw new RuntimeException('Restored database is missing or has an invalid index: ' . $indexName);
        }
    }

    $oneTimeCycle = $db->querySingle('SELECT days, name FROM cycles WHERE id = 5', true);
    if (!is_array($oneTimeCycle)
        || !array_key_exists('days', $oneTimeCycle)
        || (int) $oneTimeCycle['days'] !== 0
        || strcasecmp(trim((string) ($oneTimeCycle['name'] ?? '')), 'One-time') !== 0) {
        throw new RuntimeException('Restored database has an invalid one-time billing cycle');
    }

    $completed = wallos_restore_read_completed_migrations($db, $catalog);
    foreach ($catalog as $migrationName => $migrationPath) {
        if (!isset($completed[$migrationName])) {
            throw new RuntimeException('Restored database is missing migration marker: ' . $migrationName);
        }
    }
}

function wallos_run_migrations_after_restore($projectRoot, $databasePath)
{
    $projectRoot = rtrim((string) $projectRoot, '/\\');
    $databasePath = (string) $databasePath;
    if (!is_file($databasePath) || filesize($databasePath) <= 0) {
        throw new RuntimeException('Restored database file is missing or empty');
    }

    $catalog = wallos_restore_migration_catalog($projectRoot);
    $db = null;

    try {
        $db = new SQLite3($databasePath, SQLITE3_OPEN_READWRITE);
        $db->enableExceptions(true);
        $db->busyTimeout(10000);
        $db->exec('PRAGMA foreign_keys = ON');
        if ((int) $db->querySingle('PRAGMA foreign_keys') !== 1) {
            throw new RuntimeException('Cannot enable foreign key enforcement for restored database');
        }

        wallos_restore_assert_database_integrity($db);

        $hasMigrationTable = wallos_restore_table_exists($db, 'migrations');
        if (!$hasMigrationTable) {
            foreach (['admin', 'user', 'subscriptions', 'settings'] as $existingSchemaTable) {
                if (wallos_restore_table_exists($db, $existingSchemaTable)) {
                    throw new RuntimeException(
                        'Restored database has application data but no migration history'
                    );
                }
            }
        }

        $completed = wallos_restore_read_completed_migrations($db, $catalog);
        wallos_restore_assert_migration_prefix($catalog, $completed);

        foreach ($catalog as $migrationName => $migrationPath) {
            if (isset($completed[$migrationName])) {
                continue;
            }

            $transactionStarted = false;
            try {
                $db->exec('BEGIN IMMEDIATE');
                $transactionStarted = true;

                (static function ($path, SQLite3 $migrationDatabase) {
                    $db = $migrationDatabase;
                    require $path;
                })($migrationPath, $db);

                $stmt = $db->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
                $stmt->bindValue(':migration', $migrationName, SQLITE3_TEXT);
                $insertResult = $stmt->execute();
                if ($insertResult instanceof SQLite3Result) {
                    $insertResult->finalize();
                }

                $db->exec('COMMIT');
                $transactionStarted = false;
                $completed[$migrationName] = true;
            } catch (Throwable $throwable) {
                if ($transactionStarted) {
                    try {
                        $db->exec('ROLLBACK');
                    } catch (Throwable $rollbackError) {
                        // Preserve the original migration failure.
                    }
                }

                throw new RuntimeException(
                    'Restored database migration ' . $migrationName . ' failed: ' . $throwable->getMessage(),
                    0,
                    $throwable
                );
            }
        }

        wallos_restore_assert_database_integrity($db);
        wallos_restore_assert_latest_schema($db, $catalog);
    } finally {
        if ($db instanceof SQLite3) {
            $db->close();
        }
    }
}

function wallos_extract_backup_archive_to_workspace($archivePath, $workspace)
{
    $zip = wallos_open_backup_archive($archivePath);

    try {
        $databasePath = '';
        $logosPath = $workspace . DIRECTORY_SEPARATOR . 'logos';

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                continue;
            }

            $entryName = (string) ($stat['name'] ?? '');
            $normalizedEntryName = wallos_normalize_backup_entry_name($entryName);
            if ($normalizedEntryName === null) {
                throw new RuntimeException('Backup archive contains an unsafe path');
            }
            if ($normalizedEntryName === '') {
                continue;
            }

            $isDirectory = substr($entryName, -1) === '/';
            if ($normalizedEntryName === 'wallos.db') {
                if ($isDirectory) {
                    throw new RuntimeException('Backup archive is invalid');
                }

                $stream = $zip->getStream($entryName);
                if ($stream === false) {
                    throw new RuntimeException('Cannot extract wallos.db');
                }

                $databasePath = $workspace . DIRECTORY_SEPARATOR . 'wallos.db';
                try {
                    wallos_copy_stream_to_file($stream, $databasePath);
                } finally {
                    fclose($stream);
                }
                continue;
            }

            if ($normalizedEntryName === 'logos' || strpos($normalizedEntryName, 'logos/') === 0) {
                $destinationPath = $workspace . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedEntryName);
                if ($isDirectory) {
                    if (!is_dir($destinationPath)) {
                        mkdir($destinationPath, 0755, true);
                    }
                    continue;
                }

                $stream = $zip->getStream($entryName);
                if ($stream === false) {
                    throw new RuntimeException('Cannot extract logos file');
                }

                try {
                    wallos_copy_stream_to_file($stream, $destinationPath);
                } finally {
                    fclose($stream);
                }
            }
        }

        if ($databasePath === '' || !is_file($databasePath)) {
            throw new RuntimeException('wallos.db does not exist in the backup file');
        }

        return [
            'database_path' => $databasePath,
            'logos_path' => $logosPath,
        ];
    } finally {
        $zip->close();
    }
}

function wallos_restore_remove_database_bundle($databasePath)
{
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $databasePath . '-journal'] as $path) {
        if ((is_file($path) || is_link($path)) && !@unlink($path)) {
            throw new RuntimeException('Cannot remove database restore file: ' . basename($path));
        }
    }
}

function wallos_restore_checkpoint_database($databasePath)
{
    if (!is_file($databasePath)) {
        return;
    }

    $db = new SQLite3($databasePath, SQLITE3_OPEN_READWRITE);
    $db->enableExceptions(true);
    $db->busyTimeout(10000);
    try {
        $result = $db->query('PRAGMA wal_checkpoint(TRUNCATE)');
        $row = $result->fetchArray(SQLITE3_NUM);
        if ($row === false || (int) ($row[0] ?? 1) !== 0) {
            throw new RuntimeException('Cannot checkpoint the current database before restore');
        }
    } finally {
        $db->close();
    }

    clearstatcache();
    foreach ([$databasePath . '-wal', $databasePath . '-shm', $databasePath . '-journal'] as $sidecarPath) {
        if (file_exists($sidecarPath) || is_link($sidecarPath)) {
            throw new RuntimeException(
                'Database restore refused because an active SQLite sidecar remains: ' . basename($sidecarPath)
            );
        }
    }
}

function wallos_restore_backup_archive($archivePath, $projectRoot)
{
    $projectRoot = rtrim((string) $projectRoot, '/\\');
    $workspace = wallos_create_backup_workspace($projectRoot, 'restore');
    $keepWorkspace = false;
    $exclusiveRuntimeLock = null;

    try {
        $extractedBackup = wallos_extract_backup_archive_to_workspace($archivePath, $workspace);

        $databaseDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'db';
        $databasePath = $databaseDirectory . DIRECTORY_SEPARATOR . 'wallos.db';
        $databaseBackupPath = $databaseDirectory . DIRECTORY_SEPARATOR
            . '.wallos.restore.previous-' . bin2hex(random_bytes(6)) . '.db';
        $logosDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'logos';
        $logosBackupDirectory = $workspace . DIRECTORY_SEPARATOR . 'logos.previous';
        $databaseInstalled = false;
        $logosPreserved = false;

        if (!is_dir($databaseDirectory)) {
            mkdir($databaseDirectory, 0755, true);
        }

        $exclusiveRuntimeLock = wallos_database_acquire_exclusive_runtime_lock($databasePath);
        wallos_restore_checkpoint_database($databasePath);

        if (is_file($databasePath) && !@rename($databasePath, $databaseBackupPath)) {
            throw new RuntimeException('Cannot replace current database');
        }

        if (!@rename($extractedBackup['database_path'], $databasePath)) {
            if (is_file($databaseBackupPath)) {
                @rename($databaseBackupPath, $databasePath);
            }
            throw new RuntimeException('Cannot install restored database');
        }
        $databaseInstalled = true;

        try {
            wallos_run_migrations_after_restore($projectRoot, $databasePath);
            wallos_restore_checkpoint_database($databasePath);

            if (is_dir($logosDirectory)) {
                if (!@rename($logosDirectory, $logosBackupDirectory)) {
                    throw new RuntimeException('Cannot preserve current logos before restore');
                }
                $logosPreserved = true;
            }

            if (is_dir($extractedBackup['logos_path'])) {
                wallos_copy_directory_tree($extractedBackup['logos_path'], $logosDirectory);
            } else {
                if (!mkdir($logosDirectory, 0755, true) && !is_dir($logosDirectory)) {
                    throw new RuntimeException('Cannot create the restored logos directory');
                }
            }
            if (is_file($databaseBackupPath)) {
                @unlink($databaseBackupPath);
            }
            wallos_delete_directory_tree($logosBackupDirectory);
        } catch (Throwable $throwable) {
            $rollbackErrors = [];

            if ($logosPreserved) {
                wallos_delete_directory_tree($logosDirectory);
                if (!@rename($logosBackupDirectory, $logosDirectory)) {
                    $rollbackErrors[] = 'current logos could not be restored';
                }
            }

            if ($databaseInstalled) {
                try {
                    wallos_restore_remove_database_bundle($databasePath);
                } catch (Throwable $databaseCleanupError) {
                    $rollbackErrors[] = $databaseCleanupError->getMessage();
                }
            }
            if (is_file($databaseBackupPath) && !@rename($databaseBackupPath, $databasePath)) {
                $rollbackErrors[] = 'previous database could not be restored';
            }

            if ($rollbackErrors) {
                $keepWorkspace = true;
                if (is_array($exclusiveRuntimeLock)) {
                    $exclusiveRuntimeLock['retain_maintenance'] = true;
                }
                throw new RuntimeException(
                    $throwable->getMessage()
                    . '; rollback incomplete: ' . implode('; ', $rollbackErrors)
                    . '; recovery workspace retained at ' . $workspace,
                    0,
                    $throwable
                );
            }

            throw $throwable;
        }
    } finally {
        wallos_database_release_exclusive_runtime_lock($exclusiveRuntimeLock);
        if (!$keepWorkspace) {
            wallos_delete_directory_tree($workspace);
        }
    }
}

function wallos_create_backup_archive($db, $mode = 'manual', $basePath = null, $progressCallback = null)
{
    $projectRoot = $basePath !== null ? rtrim((string) $basePath, '/\\') : dirname(__DIR__);
    $backupDirectory = wallos_ensure_backup_storage_dir($projectRoot);
    $databaseFile = $projectRoot . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'wallos.db';
    $logosDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'logos';
    $backupTimezone = $db ? wallos_fetch_backup_timezone($db) : wallos_get_default_backup_timezone();

    if (!is_file($databaseFile)) {
        throw new RuntimeException('Database file does not exist');
    }

    $mode = wallos_normalize_backup_mode($mode);
    $fileName = wallos_build_backup_file_name($mode, $backupTimezone);
    $archivePath = $backupDirectory . DIRECTORY_SEPARATOR . $fileName;
    $temporaryArchivePath = $archivePath . '.' . bin2hex(random_bytes(3)) . '.part';
    $workspace = wallos_create_backup_workspace($projectRoot, 'backup');
    $snapshotPath = $workspace . DIRECTORY_SEPARATOR . 'wallos.db';
    $stagedLogosDirectory = $workspace . DIRECTORY_SEPARATOR . 'logos';

    wallos_cleanup_backup_temp_files($projectRoot);
    try {
        wallos_emit_backup_progress($progressCallback, 'preparing', 5);
        wallos_create_backup_database_snapshot($databaseFile, $snapshotPath);
        wallos_emit_backup_progress($progressCallback, 'snapshot', 18);
        if (!is_dir($stagedLogosDirectory)) {
            mkdir($stagedLogosDirectory, 0755, true);
        }
        wallos_copy_directory_tree($logosDirectory, $stagedLogosDirectory, $progressCallback, 22, 60);

        wallos_emit_backup_progress($progressCallback, 'manifest', 66);
        $manifest = wallos_build_backup_manifest($snapshotPath, $stagedLogosDirectory, $backupTimezone);

        $zip = new ZipArchive();
        if ($zip->open($temporaryArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot open backup archive');
        }

        try {
            $metadata = [
                'mode' => $mode,
                'created_at' => (new DateTimeImmutable('now', new DateTimeZone($backupTimezone)))->format('c'),
                'includes' => ['wallos.db', 'logos/'],
                'manifest_version' => WALLOS_BACKUP_MANIFEST_VERSION,
                'manifest_file_count' => (int) $manifest['file_count'],
            ];

            $zip->addFile($snapshotPath, 'wallos.db');
            wallos_emit_backup_progress($progressCallback, 'zip_archive', 74, ['current' => 0, 'total' => 0]);
            wallos_add_directory_to_zip($stagedLogosDirectory, $zip, 'logos', $progressCallback, 76, 94);
            $zip->addFromString('metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } finally {
            if ($zip->close() === false) {
                throw new RuntimeException('Cannot finalize backup archive');
            }
        }

        wallos_emit_backup_progress($progressCallback, 'finalizing', 97);
        if (!@rename($temporaryArchivePath, $archivePath)) {
            throw new RuntimeException('Cannot finalize backup archive');
        }

        $backup = wallos_find_backup_by_name($fileName, $projectRoot);
        if ($backup === null) {
            throw new RuntimeException('Backup archive was not created');
        }

        wallos_emit_backup_progress($progressCallback, 'completed', 100, [
            'backup' => $backup,
        ]);
        return $backup;
    } finally {
        wallos_delete_directory_tree($workspace);
        if (file_exists($temporaryArchivePath)) {
            @unlink($temporaryArchivePath);
        }
    }
}
