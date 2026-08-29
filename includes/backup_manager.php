<?php
require_once __DIR__ . '/timezone_settings.php';
require_once __DIR__ . '/database_runtime_lock.php';

define('WALLOS_BACKUP_DEFAULT_RETENTION_DAYS', 14);
define('WALLOS_BACKUP_MAX_RETENTION_DAYS', 365);
define('WALLOS_BACKUP_MANIFEST_VERSION', 1);
define('WALLOS_BACKUP_MAX_ARCHIVE_FILES', 100000);
define('WALLOS_BACKUP_MAX_UNCOMPRESSED_BYTES', 50 * 1024 * 1024 * 1024);

final class WallosRestoreRollbackIncompleteException extends RuntimeException
{
    private $rollbackErrors;
    private $recoveryPaths;

    public function __construct($message, array $rollbackErrors, array $recoveryPaths, Throwable $previous = null)
    {
        parent::__construct((string) $message, 0, $previous);
        $this->rollbackErrors = array_values($rollbackErrors);
        $this->recoveryPaths = array_values($recoveryPaths);
    }

    public function getRollbackErrors()
    {
        return $this->rollbackErrors;
    }

    public function getRecoveryPaths()
    {
        return $this->recoveryPaths;
    }
}

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

        if ($segment === '..'
            || strpos($segment, "\0") !== false
            || strpos($segment, ':') !== false
            || strpos($segment, '.wallos.restore.') === 0) {
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
            $relativePath = substr($item->getPathname(), $logosDirectoryLength);
            $relativePath = str_replace('\\', '/', $relativePath);
            if (wallos_restore_is_reserved_relative_path($relativePath)) {
                continue;
            }
            if ($item->isLink() || (!$item->isFile() && !$item->isDir())) {
                throw new RuntimeException('Backup media tree contains a symbolic link or special file');
            }
            if (!$item->isFile()) {
                continue;
            }

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
        if (!$zipArchive->addEmptyDir($normalizedArchiveRoot)) {
            throw new RuntimeException('Cannot add backup media root to archive');
        }
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
        if (wallos_restore_is_reserved_relative_path($relativePath)) {
            continue;
        }
        if ($item->isLink() || (!$item->isFile() && !$item->isDir())) {
            throw new RuntimeException('Backup media tree contains a symbolic link or special file');
        }

        $archivePath = $normalizedArchiveRoot !== '' ? $normalizedArchiveRoot . '/' . $relativePath : $relativePath;
        if ($item->isDir()) {
            if (!$zipArchive->addEmptyDir($archivePath)) {
                throw new RuntimeException('Cannot add backup media directory to archive');
            }
        } else {
            if (!$zipArchive->addFile($itemPath, $archivePath)) {
                throw new RuntimeException('Cannot add backup media file to archive');
            }
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

    $database = new SQLite3($databaseFile, SQLITE3_OPEN_READWRITE);
    $database->busyTimeout(5000);

    $escapedSnapshotPath = str_replace("'", "''", $snapshotPath);
    $result = $database->exec("VACUUM INTO '" . $escapedSnapshotPath . "'");
    $database->close();

    if ($result === false || !is_file($snapshotPath)) {
        throw new RuntimeException('Failed to create database snapshot');
    }
}

function wallos_backup_close_caller_database($db)
{
    if (!($db instanceof SQLite3)) {
        return false;
    }
    if (!$db->close()) {
        throw new RuntimeException('Cannot close the caller database before consistent backup staging');
    }

    return true;
}

function wallos_backup_reopen_caller_database($db, $databaseFile)
{
    if (!($db instanceof SQLite3)) {
        return;
    }

    try {
        $db->open($databaseFile, SQLITE3_OPEN_READWRITE);
        $db->busyTimeout(5000);
        $journalMode = strtolower((string) $db->querySingle('PRAGMA journal_mode = WAL'));
        if ($journalMode !== 'wal'
            || !$db->exec('PRAGMA synchronous = NORMAL')
            || !$db->exec('PRAGMA foreign_keys = ON')
            || (int) $db->querySingle('PRAGMA foreign_keys') !== 1) {
            throw new RuntimeException('Cannot restore caller database runtime settings');
        }
    } catch (Throwable $throwable) {
        try {
            $db->close();
        } catch (Throwable $closeException) {
            // Preserve the reopen failure.
        }
        throw new RuntimeException(
            'Cannot reopen the caller database after consistent backup staging',
            0,
            $throwable
        );
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

function wallos_backup_archive_catalog(ZipArchive $zip)
{
    if ($zip->numFiles > WALLOS_BACKUP_MAX_ARCHIVE_FILES) {
        throw new RuntimeException('Backup archive contains too many entries');
    }

    $catalog = [];
    $totalUncompressedBytes = 0;
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $stat = $zip->statIndex($index);
        if ($stat === false) {
            throw new RuntimeException('Cannot inspect backup archive entry');
        }

        $entryName = (string) ($stat['name'] ?? '');
        $isDirectory = substr($entryName, -1) === '/';
        $normalizedEntryName = wallos_normalize_backup_entry_name($entryName);
        $canonicalSpelling = rtrim(str_replace('\\', '/', $entryName), '/');
        if ($normalizedEntryName === null || $normalizedEntryName === ''
            || $normalizedEntryName !== $canonicalSpelling) {
            throw new RuntimeException('Backup archive contains a non-canonical or unsafe path');
        }
        if (isset($catalog[$normalizedEntryName])) {
            throw new RuntimeException('Backup archive contains a duplicate path: ' . $normalizedEntryName);
        }

        $allowed = in_array($normalizedEntryName, ['wallos.db', 'manifest.json', 'metadata.json', 'logos'], true)
            || strpos($normalizedEntryName, 'logos/') === 0;
        if (!$allowed
            || ($normalizedEntryName === 'logos' && !$isDirectory)
            || (in_array($normalizedEntryName, ['wallos.db', 'manifest.json', 'metadata.json'], true)
                && $isDirectory)) {
            throw new RuntimeException('Backup archive contains an unsupported path: ' . $normalizedEntryName);
        }

        $operatingSystem = 0;
        $attributes = 0;
        if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
            $unixType = ((int) $attributes >> 16) & 0170000;
            if ($unixType !== 0
                && $unixType !== 0100000
                && $unixType !== 0040000) {
                throw new RuntimeException('Backup archive contains a symbolic link or special file');
            }
            if (($isDirectory && $unixType === 0100000)
                || (!$isDirectory && $unixType === 0040000)) {
                throw new RuntimeException('Backup archive entry type does not match its path');
            }
        }

        $sizeBytes = max(0, (int) ($stat['size'] ?? 0));
        $totalUncompressedBytes += $sizeBytes;
        if ($totalUncompressedBytes > WALLOS_BACKUP_MAX_UNCOMPRESSED_BYTES) {
            throw new RuntimeException('Backup archive expands beyond the supported size limit');
        }

        $catalog[$normalizedEntryName] = [
            'index' => $index,
            'name' => $entryName,
            'type' => $isDirectory ? 'directory' : 'file',
            'size_bytes' => $sizeBytes,
        ];
    }

    foreach ($catalog as $entryName => $entry) {
        $segments = explode('/', $entryName);
        array_pop($segments);
        while ($segments) {
            $ancestor = implode('/', $segments);
            if (isset($catalog[$ancestor]) && $catalog[$ancestor]['type'] === 'file') {
                throw new RuntimeException('Backup archive contains a file/directory path conflict');
            }
            array_pop($segments);
        }
    }

    return $catalog;
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

function wallos_verify_open_backup_archive(ZipArchive $zip)
{
    $result = [
        'is_valid' => false,
        'level' => 'invalid',
        'files_checked' => 0,
        'expected_files' => 0,
        'errors' => [],
    ];

    try {
        $archiveCatalog = wallos_backup_archive_catalog($zip);
    } catch (Throwable $throwable) {
        $result['errors'][] = $throwable->getMessage();
        return $result;
    }

        if (!isset($archiveCatalog['wallos.db'])
            || $archiveCatalog['wallos.db']['type'] !== 'file') {
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
        if (isset($manifest['file_count']) && (int) $manifest['file_count'] !== count($files)) {
            $result['errors'][] = 'Backup manifest file count does not match its file map';
        }

        $manifestFileNames = [];

        foreach ($files as $entryName => $expectedFile) {
            $normalizedEntryName = wallos_normalize_backup_entry_name($entryName);
            if ($normalizedEntryName === null || $normalizedEntryName === ''
                || $normalizedEntryName !== $entryName
                || !is_array($expectedFile)
                || (string) ($expectedFile['path'] ?? '') !== $normalizedEntryName
                || ($normalizedEntryName !== 'wallos.db'
                    && strpos($normalizedEntryName, 'logos/') !== 0)) {
                $result['errors'][] = 'Invalid manifest entry: ' . $entryName;
                continue;
            }
            $manifestFileNames[$normalizedEntryName] = true;

            if (!isset($archiveCatalog[$normalizedEntryName])
                || $archiveCatalog[$normalizedEntryName]['type'] !== 'file') {
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

        foreach ($archiveCatalog as $entryName => $archiveEntry) {
            if ($archiveEntry['type'] !== 'file'
                || in_array($entryName, ['manifest.json', 'metadata.json'], true)) {
                continue;
            }
            if (!isset($manifestFileNames[$entryName])) {
                $result['errors'][] = 'Archive file is not declared by manifest: ' . $entryName;
            }
        }

        if (!isset($files['wallos.db'])) {
            $result['errors'][] = 'Manifest does not include wallos.db';
        }

        if (empty($result['errors'])) {
            $result['is_valid'] = true;
            $result['level'] = 'full';
        }

    return $result;
}

function wallos_verify_backup_archive($archivePath)
{
    try {
        $zip = wallos_open_backup_archive($archivePath);
    } catch (Throwable $throwable) {
        return [
            'is_valid' => false,
            'level' => 'invalid',
            'files_checked' => 0,
            'expected_files' => 0,
            'errors' => [$throwable->getMessage()],
        ];
    }

    try {
        return wallos_verify_open_backup_archive($zip);
    } finally {
        $zip->close();
    }
}

function wallos_create_backup_workspace($projectRoot, $prefix)
{
    $projectRoot = rtrim((string) $projectRoot, '/\\');
    if ($projectRoot === '' || is_link($projectRoot) || !is_dir($projectRoot)) {
        throw new RuntimeException('Backup workspace root must be a real directory');
    }

    $tmpRoot = $projectRoot . DIRECTORY_SEPARATOR . '.tmp';
    wallos_restore_assert_path_components($projectRoot, $tmpRoot, true);
    if (!is_dir($tmpRoot)) {
        if (!@mkdir($tmpRoot, 0755, true) && !is_dir($tmpRoot)) {
            throw new RuntimeException('Cannot create backup workspace root');
        }
    }
    wallos_restore_assert_path_components($projectRoot, $tmpRoot, false);

    $workspace = $tmpRoot . DIRECTORY_SEPARATOR . $prefix . '-' . bin2hex(random_bytes(6));
    if (!@mkdir($workspace, 0700)) {
        throw new RuntimeException('Cannot create backup workspace');
    }

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

function wallos_copy_stream_to_file($stream, $destination, $mode = 0600)
{
    $destinationDirectory = dirname($destination);
    if (!is_dir($destinationDirectory)
        && !@mkdir($destinationDirectory, 0755, true)
        && !is_dir($destinationDirectory)) {
        throw new RuntimeException('Cannot create extracted backup directory');
    }
    if (is_link($destinationDirectory) || wallos_restore_path_exists($destination)) {
        throw new RuntimeException('Extracted backup destination is unsafe or already exists');
    }

    $output = @fopen($destination, 'xb');
    if ($output === false) {
        throw new RuntimeException('Cannot write extracted backup file');
    }

    try {
        while (!feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('Cannot read extracted backup file');
            }

            $offset = 0;
            $chunkLength = strlen($chunk);
            while ($offset < $chunkLength) {
                $written = fwrite($output, substr($chunk, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Cannot write extracted backup file');
                }
                $offset += $written;
            }
        }
        if (!@chmod($destination, (int) $mode)) {
            throw new RuntimeException('Cannot set extracted backup file permissions');
        }
        wallos_restore_sync_stream($output);
    } catch (Throwable $throwable) {
        fclose($output);
        @unlink($destination);
        throw $throwable;
    }
    fclose($output);
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
        $normalizedRelativePath = str_replace('\\', '/', $relativePath);
        if (wallos_restore_is_reserved_relative_path($normalizedRelativePath)) {
            continue;
        }
        if ($item->isLink() || (!$item->isFile() && !$item->isDir())) {
            throw new RuntimeException('Backup media tree contains a symbolic link or special file');
        }
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

function wallos_restore_path_exists($path)
{
    return file_exists($path) || is_link($path);
}

function wallos_restore_assert_path_components($trustedRoot, $path, $allowMissingLeaf = false)
{
    $trustedRoot = rtrim((string) $trustedRoot, '/\\');
    $path = rtrim((string) $path, '/\\');
    if ($trustedRoot === '' || ($path !== $trustedRoot
        && strpos($path, $trustedRoot . DIRECTORY_SEPARATOR) !== 0)) {
        throw new RuntimeException('Restore path escapes the trusted project root');
    }
    if (is_link($trustedRoot) || !is_dir($trustedRoot)) {
        throw new RuntimeException('Restore project root must be a real directory');
    }

    $relativePath = ltrim(substr($path, strlen($trustedRoot)), '/\\');
    $segments = $relativePath === '' ? [] : preg_split('#[/\\\\]+#', $relativePath);
    $currentPath = $trustedRoot;
    foreach ($segments as $index => $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new RuntimeException('Restore path contains an unsafe component');
        }
        $currentPath .= DIRECTORY_SEPARATOR . $segment;
        $identity = @lstat($currentPath);
        if ($identity === false) {
            $isLeaf = $index === count($segments) - 1;
            if ($allowMissingLeaf && $isLeaf) {
                return;
            }
            throw new RuntimeException('Restore path component is missing: ' . $segment);
        }
        if (is_link($currentPath)) {
            throw new RuntimeException('Restore path contains a symbolic-link component: ' . $segment);
        }
        $isLeaf = $index === count($segments) - 1;
        if (!$isLeaf && !is_dir($currentPath)) {
            throw new RuntimeException('Restore path parent is not a directory: ' . $segment);
        }
    }
}

function wallos_restore_is_reserved_relative_path($relativePath)
{
    $normalized = trim(str_replace('\\', '/', (string) $relativePath), '/');
    if ($normalized === '') {
        return false;
    }

    foreach (explode('/', $normalized) as $segment) {
        if (strpos($segment, '.wallos.restore.') === 0) {
            return true;
        }
    }

    return false;
}

function wallos_restore_invoke_fault(array $options, $phase, array $state = [])
{
    $faultHook = $options['fault_hook'] ?? null;
    if (is_callable($faultHook)) {
        $faultHook((string) $phase, $state);
    }
}

function wallos_restore_directory_entries($directory)
{
    $entries = @scandir($directory);
    if ($entries === false) {
        throw new RuntimeException('Cannot read logos directory during restore');
    }

    $entries = array_values(array_filter($entries, static function ($entry) {
        return $entry !== '.' && $entry !== '..';
    }));
    sort($entries, SORT_STRING);

    return $entries;
}

function wallos_restore_path_identity($path)
{
    $stat = @lstat($path);
    if ($stat === false) {
        throw new RuntimeException('Cannot inspect restore path: ' . basename($path));
    }

    if (is_link($path)) {
        $type = 'link';
    } elseif (is_dir($path)) {
        $type = 'directory';
    } elseif (is_file($path)) {
        $type = 'file';
    } else {
        $type = 'special';
    }

    return [
        'dev' => (int) ($stat['dev'] ?? -1),
        'ino' => (int) ($stat['ino'] ?? -1),
        'mode' => (int) ($stat['mode'] ?? 0),
        'type' => $type,
    ];
}

function wallos_restore_assert_identity($path, array $expectedIdentity)
{
    $actualIdentity = wallos_restore_path_identity($path);
    foreach (['dev', 'ino', 'mode', 'type'] as $key) {
        if (($actualIdentity[$key] ?? null) !== ($expectedIdentity[$key] ?? null)) {
            throw new RuntimeException('Restore path changed after validation: ' . basename($path));
        }
    }
}

function wallos_restore_directory_manifest($directory, $excludeRecoveryWorkspaces = false)
{
    if (is_link($directory) || !is_dir($directory)) {
        throw new RuntimeException('Restore media root must be a real directory');
    }

    $manifest = [];
    $rootLength = strlen(rtrim($directory, '/\\')) + 1;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $path = $item->getPathname();
        $relativePath = str_replace('\\', '/', substr($path, $rootLength));
        if (wallos_restore_is_reserved_relative_path($relativePath)) {
            if ($excludeRecoveryWorkspaces) {
                continue;
            }
            throw new RuntimeException('Restore media tree contains a reserved recovery path');
        }

        if ($item->isLink() || is_link($path)) {
            throw new RuntimeException('Restore media tree contains a symbolic link: ' . $relativePath);
        }

        if ($item->isDir()) {
            $manifest[$relativePath] = ['type' => 'directory'];
            continue;
        }

        if (!$item->isFile()) {
            throw new RuntimeException('Restore media tree contains a special file: ' . $relativePath);
        }

        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('Cannot hash restore media file: ' . $relativePath);
        }
        $manifest[$relativePath] = [
            'type' => 'file',
            'size_bytes' => (int) filesize($path),
            'sha256' => $hash,
        ];
    }

    ksort($manifest, SORT_STRING);
    return $manifest;
}

function wallos_restore_sync_stream($stream)
{
    if (!fflush($stream)) {
        throw new RuntimeException('Cannot flush restored file');
    }
    if (function_exists('fsync') && !fsync($stream)) {
        throw new RuntimeException('Cannot sync restored file');
    }
}

function wallos_restore_sync_directory($directory)
{
    if (is_link($directory) || !is_dir($directory)) {
        throw new RuntimeException('Cannot sync a non-directory restore path');
    }
    if (!function_exists('fsync')) {
        return;
    }

    $stream = @fopen($directory, 'r');
    if ($stream === false) {
        throw new RuntimeException('Cannot open restore directory for sync');
    }
    try {
        if (!fsync($stream)) {
            throw new RuntimeException('Cannot sync restore directory');
        }
    } finally {
        fclose($stream);
    }
}

function wallos_restore_sync_directories(array $directories)
{
    foreach (array_values(array_unique($directories)) as $directory) {
        wallos_restore_sync_directory($directory);
    }
}

function wallos_restore_sync_directory_tree($rootDirectory)
{
    if (is_link($rootDirectory) || !is_dir($rootDirectory)) {
        throw new RuntimeException('Cannot sync a non-directory restore tree');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if ($item->isLink() || is_link($path)) {
            throw new RuntimeException('Cannot sync a restore tree containing a symbolic link');
        }
        if ($item->isDir()) {
            wallos_restore_sync_directory($path);
        } elseif (!$item->isFile()) {
            throw new RuntimeException('Cannot sync a restore tree containing a special file');
        }
    }

    wallos_restore_sync_directory($rootDirectory);
}

function wallos_restore_copy_regular_file($sourcePath, $destinationPath, $mode = 0644)
{
    if (is_link($sourcePath) || !is_file($sourcePath)) {
        throw new RuntimeException('Restore source is not a regular file: ' . basename($sourcePath));
    }
    if (wallos_restore_path_exists($destinationPath)) {
        throw new RuntimeException('Restore destination already exists: ' . basename($destinationPath));
    }

    $sourceHash = hash_file('sha256', $sourcePath);
    $sourceSize = filesize($sourcePath);
    if ($sourceHash === false || $sourceSize === false) {
        throw new RuntimeException('Cannot inspect restore source file');
    }

    $input = @fopen($sourcePath, 'rb');
    $output = @fopen($destinationPath, 'xb');
    if ($input === false || $output === false) {
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_resource($output)) {
            fclose($output);
        }
        @unlink($destinationPath);
        throw new RuntimeException('Cannot open restored file copy streams');
    }

    try {
        while (!feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('Cannot read restore source file');
            }

            $offset = 0;
            $chunkLength = strlen($chunk);
            while ($offset < $chunkLength) {
                $written = fwrite($output, substr($chunk, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Cannot write restored file');
                }
                $offset += $written;
            }
        }
        if (!@chmod($destinationPath, (int) $mode)) {
            throw new RuntimeException('Cannot set restored file permissions');
        }
        wallos_restore_sync_stream($output);
    } catch (Throwable $throwable) {
        fclose($input);
        fclose($output);
        @unlink($destinationPath);
        throw $throwable;
    }

    fclose($input);
    fclose($output);

    $destinationHash = hash_file('sha256', $destinationPath);
    $destinationSize = filesize($destinationPath);
    if ($destinationHash !== $sourceHash || $destinationSize !== $sourceSize) {
        @unlink($destinationPath);
        throw new RuntimeException('Restored file copy failed size or checksum verification');
    }
}

function wallos_restore_copy_directory_tree_strict($sourceDirectory, $destinationDirectory)
{
    if (wallos_restore_path_exists($destinationDirectory)) {
        throw new RuntimeException('Restore staging directory already exists');
    }
    if (!@mkdir($destinationDirectory, 0700, true)) {
        throw new RuntimeException('Cannot create restore staging directory');
    }

    $sourceManifest = [];
    if (is_dir($sourceDirectory)) {
        $sourceManifest = wallos_restore_directory_manifest($sourceDirectory, false);
        $rootLength = strlen(rtrim($sourceDirectory, '/\\')) + 1;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), $rootLength);
            $destinationPath = $destinationDirectory . DIRECTORY_SEPARATOR . $relativePath;
            if ($item->isDir()) {
                if (!@mkdir($destinationPath, 0755, true) && !is_dir($destinationPath)) {
                    throw new RuntimeException('Cannot create restored media directory');
                }
                if (!@chmod($destinationPath, 0755)) {
                    throw new RuntimeException('Cannot set restored media directory permissions');
                }
                continue;
            }

            $parent = dirname($destinationPath);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                throw new RuntimeException('Cannot create restored media parent directory');
            }
            wallos_restore_copy_regular_file($item->getPathname(), $destinationPath, 0644);
        }
    }

    if (wallos_restore_directory_manifest($destinationDirectory, false) !== $sourceManifest) {
        throw new RuntimeException('Staged restore media tree failed manifest verification');
    }

    // Files are synced as they are copied. Persist directory entries from the
    // leaves upward before this tree can be renamed into service.
    wallos_restore_sync_directory_tree($destinationDirectory);
    wallos_restore_sync_directory(dirname($destinationDirectory));

    return $sourceManifest;
}

function wallos_restore_delete_tree_strict($path)
{
    if (!wallos_restore_path_exists($path)) {
        return;
    }
    if (is_link($path)) {
        throw new RuntimeException('Refusing to delete a restore symlink');
    }
    if (is_file($path)) {
        if (!@unlink($path)) {
            throw new RuntimeException('Cannot delete restore file: ' . basename($path));
        }
        wallos_restore_sync_directory(dirname($path));
        return;
    }
    if (!is_dir($path)) {
        throw new RuntimeException('Refusing to delete a special restore path');
    }

    wallos_restore_directory_manifest($path, false);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $itemPath = $item->getPathname();
        if ($item->isDir()) {
            wallos_restore_sync_directory($itemPath);
            if (!@rmdir($itemPath)) {
                throw new RuntimeException('Cannot delete restore directory: ' . basename($itemPath));
            }
            wallos_restore_sync_directory(dirname($itemPath));
        } elseif (!@unlink($itemPath)) {
            throw new RuntimeException('Cannot delete restore file: ' . basename($itemPath));
        } else {
            wallos_restore_sync_directory(dirname($itemPath));
        }
    }
    wallos_restore_sync_directory($path);
    if (!@rmdir($path)) {
        throw new RuntimeException('Cannot delete restore directory: ' . basename($path));
    }
    wallos_restore_sync_directory(dirname($path));
}

function wallos_restore_decode_mountinfo_path($path)
{
    return strtr((string) $path, [
        '\\040' => ' ',
        '\\011' => "\t",
        '\\012' => "\n",
        '\\134' => '\\',
    ]);
}

function wallos_restore_is_mount_point($path)
{
    $resolvedPath = realpath($path);
    if ($resolvedPath === false || !is_file('/proc/self/mountinfo')) {
        return false;
    }

    $lines = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return false;
    }
    foreach ($lines as $line) {
        $fields = explode(' ', $line);
        if (isset($fields[4])
            && wallos_restore_decode_mountinfo_path($fields[4]) === $resolvedPath) {
            return true;
        }
    }

    return false;
}

function wallos_restore_prepare_logos_transaction(
    &$transaction,
    $sourceDirectory,
    $targetDirectory,
    $transactionId,
    array $options = []
)
{
    $targetExists = wallos_restore_path_exists($targetDirectory);
    if ($targetExists && (is_link($targetDirectory) || !is_dir($targetDirectory))) {
        throw new RuntimeException('Restore media root must be a real directory');
    }

    $requestedStrategy = strtolower(trim((string) ($options['logos_strategy'] ?? 'auto')));
    if (!in_array($requestedStrategy, ['auto', 'contents', 'directory'], true)) {
        throw new RuntimeException('Unsupported restore Logo transaction strategy');
    }
    $strategy = $requestedStrategy;
    if ($strategy === 'auto') {
        $strategy = $targetExists && wallos_restore_is_mount_point($targetDirectory)
            ? 'contents'
            : 'directory';
    }

    $parentDirectory = dirname($targetDirectory);
    if (is_link($parentDirectory) || !is_dir($parentDirectory)) {
        throw new RuntimeException('Restore media parent must be a real directory');
    }

    $originalManifest = $targetExists
        ? wallos_restore_directory_manifest($targetDirectory, false)
        : [];
    $targetCreated = false;
    if ($strategy === 'contents' && !$targetExists) {
        if (!@mkdir($targetDirectory, 0755, true)) {
            throw new RuntimeException('Cannot create restored logos root');
        }
        wallos_restore_sync_directory($parentDirectory);
        $targetCreated = true;
        $targetExists = true;
    }

    if ($strategy === 'contents') {
        $originalEntries = wallos_restore_directory_entries($targetDirectory);
        $originalIdentities = [];
        foreach ($originalEntries as $entry) {
            $originalIdentities[$entry] = wallos_restore_path_identity(
                $targetDirectory . DIRECTORY_SEPARATOR . $entry
            );
        }
        $incomingDirectory = $targetDirectory . DIRECTORY_SEPARATOR
            . '.wallos.restore.next-' . $transactionId;
        $previousDirectory = $targetDirectory . DIRECTORY_SEPARATOR
            . '.wallos.restore.previous-' . $transactionId;
    } else {
        $originalEntries = $targetExists ? ['__root__'] : [];
        $originalIdentities = $targetExists
            ? ['__root__' => wallos_restore_path_identity($targetDirectory)]
            : [];
        $incomingDirectory = $parentDirectory . DIRECTORY_SEPARATOR
            . '.wallos.restore.next-' . $transactionId;
        $previousDirectory = $parentDirectory . DIRECTORY_SEPARATOR
            . '.wallos.restore.previous-' . $transactionId;
    }

    $transaction = [
        'id' => $transactionId,
        'strategy' => $strategy,
        'phase' => 'preparing',
        'target' => $targetDirectory,
        'parent' => $parentDirectory,
        'incoming' => $incomingDirectory,
        'previous' => $previousDirectory,
        'target_existed' => !empty($originalEntries),
        'target_created' => $targetCreated,
        'original_manifest' => $originalManifest,
        'new_manifest' => [],
        'original_entries' => $originalEntries,
        'original_identities' => $originalIdentities,
        'new_entries' => [],
        'new_identities' => [],
        'moved_original' => [],
        'installed_new' => [],
        'committed' => false,
    ];

    $newManifest = wallos_restore_copy_directory_tree_strict($sourceDirectory, $incomingDirectory);
    if ($strategy === 'directory') {
        // The staging root is private while it is built, but after a root-level
        // rename it becomes the public Logo directory and must be traversable by
        // the Nginx worker.
        if (!@chmod($incomingDirectory, 0755)) {
            throw new RuntimeException('Cannot set restored Logo directory permissions');
        }
        wallos_restore_sync_directory($incomingDirectory);
        wallos_restore_sync_directory($parentDirectory);
    }
    if ($strategy === 'contents' && !@mkdir($previousDirectory, 0700)) {
        throw new RuntimeException('Cannot create previous logos recovery directory');
    }

    if ($strategy === 'contents') {
        $newEntries = wallos_restore_directory_entries($incomingDirectory);
        $newIdentities = [];
        foreach ($newEntries as $entry) {
            $newIdentities[$entry] = wallos_restore_path_identity(
                $incomingDirectory . DIRECTORY_SEPARATOR . $entry
            );
        }
    } else {
        $newEntries = ['__root__'];
        $newIdentities = ['__root__' => wallos_restore_path_identity($incomingDirectory)];
    }

    $transaction['new_manifest'] = $newManifest;
    $transaction['new_entries'] = $newEntries;
    $transaction['new_identities'] = $newIdentities;
    $transaction['phase'] = 'prepared';
}

function wallos_restore_commit_logos_transaction(array &$transaction, array $options = [])
{
    $target = $transaction['target'];
    $previous = $transaction['previous'];
    $incoming = $transaction['incoming'];

    if (($transaction['strategy'] ?? 'contents') === 'directory') {
        $parent = $transaction['parent'];
        if (!empty($transaction['original_entries'])) {
            wallos_restore_assert_identity($target, $transaction['original_identities']['__root__']);
            if (!@rename($target, $previous)) {
                throw new RuntimeException('Cannot preserve current Logo directory');
            }
            $transaction['moved_original'][] = '__root__';
            wallos_restore_assert_identity($previous, $transaction['original_identities']['__root__']);
            wallos_restore_sync_directory($parent);
            $transaction['phase'] = 'moving_original';
            wallos_restore_invoke_fault($options, 'logos.original_moved', $transaction);
        }

        wallos_restore_assert_identity($incoming, $transaction['new_identities']['__root__']);
        if (!@rename($incoming, $target)) {
            throw new RuntimeException('Cannot install prepared Logo directory');
        }
        $transaction['installed_new'][] = '__root__';
        wallos_restore_assert_identity($target, $transaction['new_identities']['__root__']);
        wallos_restore_sync_directory($parent);
        $transaction['phase'] = 'installing_new';
        wallos_restore_invoke_fault($options, 'logos.new_installed', $transaction);

        if (wallos_restore_directory_manifest($target, false) !== $transaction['new_manifest']) {
            throw new RuntimeException('Installed Logo directory failed manifest verification');
        }
        $transaction['phase'] = 'verified';
        wallos_restore_invoke_fault($options, 'logos.verified', $transaction);
        return;
    }

    foreach ($transaction['original_entries'] as $entry) {
        $sourcePath = $target . DIRECTORY_SEPARATOR . $entry;
        wallos_restore_assert_identity($sourcePath, $transaction['original_identities'][$entry]);
        if (!@rename($sourcePath, $previous . DIRECTORY_SEPARATOR . $entry)) {
            throw new RuntimeException('Cannot preserve logos entry: ' . $entry);
        }
        $transaction['moved_original'][] = $entry;
        wallos_restore_assert_identity(
            $previous . DIRECTORY_SEPARATOR . $entry,
            $transaction['original_identities'][$entry]
        );
        wallos_restore_sync_directories([$target, $previous]);
        $transaction['phase'] = 'moving_original';
        wallos_restore_invoke_fault($options, 'logos.original_moved', $transaction);
    }

    foreach ($transaction['new_entries'] as $entry) {
        $sourcePath = $incoming . DIRECTORY_SEPARATOR . $entry;
        wallos_restore_assert_identity($sourcePath, $transaction['new_identities'][$entry]);
        if (!@rename($sourcePath, $target . DIRECTORY_SEPARATOR . $entry)) {
            throw new RuntimeException('Cannot install restored logos entry: ' . $entry);
        }
        $transaction['installed_new'][] = $entry;
        wallos_restore_assert_identity(
            $target . DIRECTORY_SEPARATOR . $entry,
            $transaction['new_identities'][$entry]
        );
        wallos_restore_sync_directories([$incoming, $target]);
        $transaction['phase'] = 'installing_new';
        wallos_restore_invoke_fault($options, 'logos.new_installed', $transaction);
    }

    $activeManifest = wallos_restore_directory_manifest($target, true);
    if ($activeManifest !== $transaction['new_manifest']) {
        throw new RuntimeException('Installed logos failed manifest verification');
    }

    $transaction['phase'] = 'verified';
    wallos_restore_invoke_fault($options, 'logos.verified', $transaction);
}

function wallos_restore_rollback_logos_transaction(array &$transaction, array $options = [])
{
    if (!$transaction) {
        return;
    }
    if (($transaction['phase'] ?? '') === 'rolled_back') {
        return;
    }

    $errors = [];
    $target = $transaction['target'];
    $incoming = $transaction['incoming'];
    $previous = $transaction['previous'];
    $strategy = $transaction['strategy'] ?? 'contents';

    if ($strategy === 'directory') {
        if ($transaction['installed_new']) {
            try {
                wallos_restore_invoke_fault($options, 'rollback.logo_entry', [
                    'direction' => 'new_to_incoming',
                    'entry' => '__root__',
                    'transaction' => $transaction,
                ]);
                if (!@rename($target, $incoming)) {
                    throw new RuntimeException('cannot move new Logo directory out of live path');
                }
                array_pop($transaction['installed_new']);
                wallos_restore_sync_directory($transaction['parent']);
            } catch (Throwable $throwable) {
                $errors[] = 'new:__root__: ' . $throwable->getMessage();
            }
        }

        if (!$errors && $transaction['moved_original']) {
            try {
                wallos_restore_invoke_fault($options, 'rollback.logo_entry', [
                    'direction' => 'previous_to_live',
                    'entry' => '__root__',
                    'transaction' => $transaction,
                ]);
                if (!@rename($previous, $target)) {
                    throw new RuntimeException('cannot restore previous Logo directory');
                }
                array_pop($transaction['moved_original']);
                wallos_restore_sync_directory($transaction['parent']);
            } catch (Throwable $throwable) {
                $errors[] = 'previous:__root__: ' . $throwable->getMessage();
            }
        }

        if (!$errors) {
            try {
                if (!empty($transaction['target_existed'])) {
                    if (wallos_restore_directory_manifest($target, false) !== $transaction['original_manifest']) {
                        throw new RuntimeException('rolled-back Logo directory does not match the original manifest');
                    }
                } elseif (wallos_restore_path_exists($target)) {
                    throw new RuntimeException('rolled-back empty instance still has a live Logo directory');
                }
                wallos_restore_delete_tree_strict($incoming);
                wallos_restore_delete_tree_strict($previous);
            } catch (Throwable $throwable) {
                $errors[] = $throwable->getMessage();
            }
        }

        if ($errors) {
            throw new WallosRestoreRollbackIncompleteException(
                'Logo directory restore rollback is incomplete',
                $errors,
                [$previous, $incoming, $target]
            );
        }

        $transaction['phase'] = 'rolled_back';
        return;
    }

    while ($transaction['installed_new']) {
        $entry = end($transaction['installed_new']);
        try {
            wallos_restore_invoke_fault($options, 'rollback.logo_entry', [
                'direction' => 'new_to_incoming',
                'entry' => $entry,
                'transaction' => $transaction,
            ]);
            if (!@rename(
                $target . DIRECTORY_SEPARATOR . $entry,
                $incoming . DIRECTORY_SEPARATOR . $entry
            )) {
                throw new RuntimeException('cannot move new entry out of live tree');
            }
            array_pop($transaction['installed_new']);
            wallos_restore_sync_directories([$target, $incoming]);
        } catch (Throwable $throwable) {
            $errors[] = 'new:' . $entry . ': ' . $throwable->getMessage();
            break;
        }
    }

    while (!$errors && $transaction['moved_original']) {
        $entry = end($transaction['moved_original']);
        try {
            wallos_restore_invoke_fault($options, 'rollback.logo_entry', [
                'direction' => 'previous_to_live',
                'entry' => $entry,
                'transaction' => $transaction,
            ]);
            if (!@rename(
                $previous . DIRECTORY_SEPARATOR . $entry,
                $target . DIRECTORY_SEPARATOR . $entry
            )) {
                throw new RuntimeException('cannot restore previous entry');
            }
            array_pop($transaction['moved_original']);
            wallos_restore_sync_directories([$previous, $target]);
        } catch (Throwable $throwable) {
            $errors[] = 'previous:' . $entry . ': ' . $throwable->getMessage();
            break;
        }
    }

    if (!$errors) {
        try {
            if (wallos_restore_directory_manifest($target, true) !== $transaction['original_manifest']) {
                throw new RuntimeException('rolled-back logos do not match the original manifest');
            }
            wallos_restore_delete_tree_strict($incoming);
            wallos_restore_delete_tree_strict($previous);
            if (!empty($transaction['target_created'])
                && wallos_restore_directory_entries($target) === []) {
                wallos_restore_delete_tree_strict($target);
            }
        } catch (Throwable $throwable) {
            $errors[] = $throwable->getMessage();
        }
    }

    if ($errors) {
        throw new WallosRestoreRollbackIncompleteException(
            'Logos restore rollback is incomplete',
            $errors,
            [$previous, $incoming]
        );
    }

    $transaction['phase'] = 'rolled_back';
}

function wallos_restore_finalize_logos_transaction(array &$transaction)
{
    $excludeRecovery = ($transaction['strategy'] ?? 'contents') === 'contents';
    if (wallos_restore_directory_manifest($transaction['target'], $excludeRecovery) !== $transaction['new_manifest']) {
        throw new RuntimeException('Cannot finalize an unverified logos restore');
    }

    $warnings = [];
    foreach ([$transaction['previous'], $transaction['incoming']] as $recoveryPath) {
        try {
            wallos_restore_delete_tree_strict($recoveryPath);
        } catch (Throwable $throwable) {
            $warnings[] = $throwable->getMessage() . ' at ' . $recoveryPath;
        }
    }
    foreach ($warnings as $warning) {
        error_log('Wallos restore committed; protected recovery cleanup warning: ' . $warning);
    }

    $transaction['committed'] = true;
    $transaction['phase'] = 'finalized';
    return $warnings;
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
        'settings' => ['week_starts_sunday', 'screenshot_privacy_mode'],
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

function wallos_run_migrations_after_restore($projectRoot, $databasePath, $restoreLogosDirectory = null)
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

                (static function ($path, SQLite3 $migrationDatabase, $restoreLogosRoot) {
                    $db = $migrationDatabase;
                    $wallosRestoreLogosDirectory = $restoreLogosRoot;
                    require $path;
                })($migrationPath, $db, $restoreLogosDirectory);

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
        $verification = wallos_verify_open_backup_archive($zip);
        if (empty($verification['is_valid'])) {
            throw new RuntimeException('Backup archive failed verification during extraction');
        }
        $verifiedManifest = ($verification['level'] ?? '') === 'full'
            ? wallos_get_backup_manifest($zip)
            : null;
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
                        if (!@mkdir($destinationPath, 0755, true) && !is_dir($destinationPath)) {
                            throw new RuntimeException('Cannot create extracted Logo directory');
                        }
                    }
                    if (is_link($destinationPath) || !@chmod($destinationPath, 0755)) {
                        throw new RuntimeException('Cannot protect extracted Logo directory');
                    }
                    continue;
                }

                $stream = $zip->getStream($entryName);
                if ($stream === false) {
                    throw new RuntimeException('Cannot extract logos file');
                }

                try {
                    wallos_copy_stream_to_file($stream, $destinationPath, 0644);
                } finally {
                    fclose($stream);
                }
            }
        }

        if ($databasePath === '' || !is_file($databasePath)) {
            throw new RuntimeException('wallos.db does not exist in the backup file');
        }

        if (is_array($verifiedManifest)) {
            $extractedFiles = wallos_collect_backup_manifest_files($databasePath, $logosPath);
            $verifiedFiles = $verifiedManifest['files'];
            ksort($verifiedFiles, SORT_STRING);
            if (array_keys($extractedFiles) !== array_keys($verifiedFiles)) {
                throw new RuntimeException('Extracted backup file set differs from its verified manifest');
            }
            foreach ($extractedFiles as $entryName => $actualFile) {
                $expectedFile = $verifiedFiles[$entryName];
                if ((string) ($expectedFile['path'] ?? '') !== $actualFile['path']
                    || (int) ($expectedFile['size_bytes'] ?? -1) !== $actualFile['size_bytes']
                    || strtolower((string) ($expectedFile['sha256'] ?? '')) !== $actualFile['sha256']) {
                    throw new RuntimeException(
                        'Extracted backup file differs from its verified manifest: ' . $entryName
                    );
                }
            }
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
    $removed = false;
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $databasePath . '-journal'] as $path) {
        if (!wallos_restore_path_exists($path)) {
            continue;
        }
        if (is_link($path) || !is_file($path) || !@unlink($path)) {
            throw new RuntimeException('Cannot remove database restore file: ' . basename($path));
        }
        $removed = true;
    }
    if ($removed) {
        wallos_restore_sync_directory(dirname($databasePath));
    }
}

function wallos_restore_checkpoint_database($databasePath)
{
    if (!wallos_restore_path_exists($databasePath)) {
        return;
    }
    if (is_link($databasePath) || !is_file($databasePath)) {
        throw new RuntimeException('Database restore path must be a regular file');
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

function wallos_restore_sync_file($path)
{
    if (is_link($path) || !is_file($path)) {
        throw new RuntimeException('Cannot sync a non-regular restore file');
    }

    $stream = @fopen($path, 'c+b');
    if ($stream === false) {
        throw new RuntimeException('Cannot open restored database for sync');
    }
    try {
        wallos_restore_sync_stream($stream);
    } finally {
        fclose($stream);
    }
}

function wallos_restore_write_transaction_marker($markerPath, $transactionId, $phase)
{
    $temporaryPath = $markerPath . '.tmp-' . bin2hex(random_bytes(4));
    $payload = json_encode([
        'version' => 1,
        'transaction_id' => (string) $transactionId,
        'phase' => (string) $phase,
        'updated_at' => date('c'),
    ], JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Cannot encode restore transaction marker');
    }

    $stream = @fopen($temporaryPath, 'xb');
    if ($stream === false) {
        throw new RuntimeException('Cannot create restore transaction marker');
    }
    try {
        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $written = fwrite($stream, substr($payload, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Cannot write restore transaction marker');
            }
            $offset += $written;
        }
        if (!@chmod($temporaryPath, 0660)) {
            throw new RuntimeException('Cannot protect restore transaction marker');
        }
        wallos_restore_sync_stream($stream);
    } catch (Throwable $throwable) {
        fclose($stream);
        @unlink($temporaryPath);
        throw $throwable;
    }
    fclose($stream);

    if (!@rename($temporaryPath, $markerPath)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Cannot publish restore transaction marker');
    }
    wallos_restore_sync_directory(dirname($markerPath));
}

function wallos_restore_remove_transaction_marker($markerPath)
{
    if (!wallos_restore_path_exists($markerPath)) {
        return;
    }
    if (is_link($markerPath) || !is_file($markerPath) || !@unlink($markerPath)) {
        throw new RuntimeException('Cannot remove restore transaction marker');
    }
    wallos_restore_sync_directory(dirname($markerPath));
}

function wallos_restore_prepare_database_transaction(
    &$transaction,
    $sourceDatabasePath,
    $databasePath,
    $projectRoot,
    $restoreLogosDirectory,
    $transactionId,
    array $options = []
) {
    $databaseDirectory = dirname($databasePath);
    if (is_link($databaseDirectory) || !is_dir($databaseDirectory)) {
        throw new RuntimeException('Restore database directory must be a real directory');
    }
    if (is_link($sourceDatabasePath) || !is_file($sourceDatabasePath)) {
        throw new RuntimeException('Extracted restore database is not a regular file');
    }

    $currentExists = wallos_restore_path_exists($databasePath);
    if ($currentExists && (is_link($databasePath) || !is_file($databasePath))) {
        throw new RuntimeException('Current database is not a regular file');
    }
    if ($currentExists) {
        wallos_restore_checkpoint_database($databasePath);
    }

    $incomingPath = $databaseDirectory . DIRECTORY_SEPARATOR
        . '.wallos.restore.incoming-' . $transactionId . '.db';
    $previousPath = $databaseDirectory . DIRECTORY_SEPARATOR
        . '.wallos.restore.previous-' . $transactionId . '.db';
    foreach ([$incomingPath, $previousPath] as $recoveryPath) {
        if (wallos_restore_path_exists($recoveryPath)) {
            throw new RuntimeException('A database restore recovery path already exists');
        }
    }

    $transaction = [
        'id' => $transactionId,
        'phase' => 'preparing',
        'current' => $databasePath,
        'incoming' => $incomingPath,
        'previous' => $previousPath,
        'current_existed' => $currentExists,
        'current_identity' => $currentExists ? wallos_restore_path_identity($databasePath) : null,
        'current_hash' => $currentExists ? hash_file('sha256', $databasePath) : null,
        'incoming_identity' => null,
        'incoming_hash' => null,
        'preserved_current' => false,
        'installed_incoming' => false,
        'new_main_isolated' => false,
        'isolated_sidecars' => [],
        'committed' => false,
    ];
    if ($currentExists && $transaction['current_hash'] === false) {
        throw new RuntimeException('Cannot hash current database before restore');
    }

    wallos_restore_copy_regular_file($sourceDatabasePath, $incomingPath, 0660);
    wallos_restore_invoke_fault($options, 'db.incoming_copied', $transaction);

    wallos_run_migrations_after_restore(
        $projectRoot,
        $incomingPath,
        $restoreLogosDirectory
    );
    wallos_restore_checkpoint_database($incomingPath);
    if (!@chmod($incomingPath, 0660)) {
        throw new RuntimeException('Cannot protect prepared restore database');
    }
    wallos_restore_sync_file($incomingPath);
    wallos_restore_sync_directory($databaseDirectory);

    $incomingIdentity = wallos_restore_path_identity($incomingPath);
    $databaseDirectoryIdentity = wallos_restore_path_identity($databaseDirectory);
    if ($incomingIdentity['type'] !== 'file'
        || $incomingIdentity['dev'] !== $databaseDirectoryIdentity['dev']) {
        throw new RuntimeException('Prepared database is not on the live database filesystem');
    }
    if ($currentExists && $incomingIdentity['dev'] !== $transaction['current_identity']['dev']) {
        throw new RuntimeException('Prepared and current databases are not on the same filesystem');
    }

    $transaction['incoming_identity'] = $incomingIdentity;
    $transaction['incoming_hash'] = hash_file('sha256', $incomingPath);
    if ($transaction['incoming_hash'] === false) {
        throw new RuntimeException('Cannot hash prepared restore database');
    }
    $transaction['phase'] = 'prepared';
    wallos_restore_invoke_fault($options, 'db.incoming_validated', $transaction);
}

function wallos_restore_commit_database_transaction(array &$transaction, array $options = [])
{
    $current = $transaction['current'];
    $incoming = $transaction['incoming'];
    $previous = $transaction['previous'];

    if (!empty($transaction['current_existed'])) {
        wallos_restore_checkpoint_database($current);
        wallos_restore_assert_identity($current, $transaction['current_identity']);
        if (!@rename($current, $previous)) {
            throw new RuntimeException('Cannot preserve current database');
        }
        $transaction['preserved_current'] = true;
        wallos_restore_assert_identity($previous, $transaction['current_identity']);
        wallos_restore_sync_directory(dirname($current));
        $transaction['phase'] = 'current_moved';
        wallos_restore_invoke_fault($options, 'db.current_moved', $transaction);
    }

    wallos_restore_assert_identity($incoming, $transaction['incoming_identity']);
    if (!@rename($incoming, $current)) {
        throw new RuntimeException('Cannot install prepared restore database');
    }
    $transaction['installed_incoming'] = true;
    wallos_restore_sync_directory(dirname($current));
    $transaction['phase'] = 'incoming_installed';

    $installedIdentity = wallos_restore_path_identity($current);
    if ($installedIdentity['dev'] !== $transaction['incoming_identity']['dev']
        || $installedIdentity['ino'] !== $transaction['incoming_identity']['ino']
        || hash_file('sha256', $current) !== $transaction['incoming_hash']) {
        throw new RuntimeException('Installed database identity or checksum changed during cutover');
    }

    wallos_restore_invoke_fault($options, 'db.incoming_installed', $transaction);
}

function wallos_restore_assert_database_file_integrity($databasePath)
{
    if (is_link($databasePath) || !is_file($databasePath)) {
        throw new RuntimeException('Restored database is not a regular file');
    }

    $db = new SQLite3($databasePath, SQLITE3_OPEN_READONLY);
    $db->enableExceptions(true);
    $db->busyTimeout(10000);
    try {
        wallos_restore_assert_database_integrity($db);
    } finally {
        $db->close();
    }
}

function wallos_restore_rollback_database_transaction(array &$transaction, array $options = [])
{
    if (!$transaction) {
        return;
    }
    if (($transaction['phase'] ?? '') === 'rolled_back') {
        return;
    }

    $errors = [];
    $current = $transaction['current'];
    $incoming = $transaction['incoming'];
    $previous = $transaction['previous'];

    if (!empty($transaction['installed_incoming'])) {
        try {
            wallos_restore_invoke_fault($options, 'rollback.db', [
                'action' => 'isolate_installed',
                'transaction' => $transaction,
            ]);
            if (empty($transaction['new_main_isolated'])) {
                if (!@rename($current, $incoming)) {
                    throw new RuntimeException('cannot isolate installed database');
                }
                $transaction['new_main_isolated'] = true;
                wallos_restore_sync_directory(dirname($current));
            }

            foreach (['-wal', '-shm', '-journal'] as $suffix) {
                $liveSidecar = $current . $suffix;
                $isolatedSidecar = $incoming . $suffix;
                if (!wallos_restore_path_exists($liveSidecar)) {
                    continue;
                }
                if (is_link($liveSidecar) || !is_file($liveSidecar)
                    || wallos_restore_path_exists($isolatedSidecar)
                    || !@rename($liveSidecar, $isolatedSidecar)) {
                    throw new RuntimeException('cannot isolate installed database sidecar: ' . basename($liveSidecar));
                }
                $transaction['isolated_sidecars'][] = $suffix;
                wallos_restore_sync_directory(dirname($current));
            }

            foreach (['-wal', '-shm', '-journal'] as $suffix) {
                if (wallos_restore_path_exists($current . $suffix)) {
                    throw new RuntimeException('installed database sidecar remains on the live basename');
                }
            }
            $transaction['installed_incoming'] = false;
        } catch (Throwable $throwable) {
            $errors[] = $throwable->getMessage();
        }
    }

    if (!$errors && !empty($transaction['preserved_current'])) {
        try {
            wallos_restore_invoke_fault($options, 'rollback.db', [
                'action' => 'restore_previous',
                'transaction' => $transaction,
            ]);
            if (!@rename($previous, $current)) {
                throw new RuntimeException('cannot restore previous database');
            }
            $transaction['preserved_current'] = false;
            wallos_restore_sync_directory(dirname($current));
        } catch (Throwable $throwable) {
            $errors[] = $throwable->getMessage();
        }
    }

    if (!$errors) {
        try {
            if (!empty($transaction['current_existed'])) {
                wallos_restore_checkpoint_database($current);
                wallos_restore_assert_database_file_integrity($current);
                if (hash_file('sha256', $current) !== $transaction['current_hash']) {
                    throw new RuntimeException('rolled-back database does not match the original checksum');
                }
            } elseif (wallos_restore_path_exists($current)) {
                throw new RuntimeException('rolled-back empty instance still has a live database');
            }

            wallos_restore_remove_database_bundle($incoming);
            wallos_restore_remove_database_bundle($previous);
        } catch (Throwable $throwable) {
            $errors[] = $throwable->getMessage();
        }
    }

    if ($errors) {
        throw new WallosRestoreRollbackIncompleteException(
            'Database restore rollback is incomplete',
            $errors,
            [$previous, $incoming, $current]
        );
    }

    $transaction['phase'] = 'rolled_back';
}

function wallos_restore_finalize_database_transaction(array &$transaction)
{
    if (!is_file($transaction['current'])
        || hash_file('sha256', $transaction['current']) !== $transaction['incoming_hash']) {
        throw new RuntimeException('Cannot finalize an unverified database restore');
    }

    $warnings = [];
    foreach ([$transaction['previous'], $transaction['incoming']] as $recoveryPath) {
        try {
            wallos_restore_remove_database_bundle($recoveryPath);
        } catch (Throwable $throwable) {
            $warnings[] = $throwable->getMessage();
        }
    }
    foreach ($warnings as $warning) {
        error_log('Wallos restore committed; protected database recovery cleanup warning: ' . $warning);
    }

    $transaction['committed'] = true;
    $transaction['phase'] = 'finalized';
    return $warnings;
}

function wallos_restore_backup_archive($archivePath, $projectRoot, array $options = [])
{
    if (is_link($archivePath) || !is_file($archivePath)) {
        throw new RuntimeException('Backup restore input must be a regular file');
    }

    $projectRootInput = rtrim((string) $projectRoot, '/\\');
    if ($projectRootInput === '' || is_link($projectRootInput)) {
        throw new RuntimeException('Restore project root must be a real directory');
    }
    $projectRoot = realpath($projectRootInput);
    if ($projectRoot === false || is_link($projectRoot) || !is_dir($projectRoot)) {
        throw new RuntimeException('Restore project root must be a real directory');
    }
    $projectRoot = rtrim($projectRoot, '/\\');
    $databaseDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'db';
    $databasePath = $databaseDirectory . DIRECTORY_SEPARATOR . 'wallos.db';
    $logosDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'images'
        . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'logos';
    wallos_restore_assert_path_components($projectRoot, $databaseDirectory, true);
    wallos_restore_assert_path_components($projectRoot, $logosDirectory, true);

    $workspace = wallos_create_backup_workspace($projectRoot, 'restore');
    $keepWorkspace = false;
    $exclusiveRuntimeLock = null;
    $transactionMarker = '';
    $transactionId = bin2hex(random_bytes(16));
    $databaseTransaction = [];
    $logosTransaction = [];
    $committed = false;

    try {
        $extractedBackup = wallos_extract_backup_archive_to_workspace($archivePath, $workspace);

        if (!wallos_restore_path_exists($databaseDirectory)) {
            if (!@mkdir($databaseDirectory, 0770, true)) {
                throw new RuntimeException('Cannot create restore database directory');
            }
        }
        if (is_link($databaseDirectory) || !is_dir($databaseDirectory)) {
            throw new RuntimeException('Restore database directory must be a real directory');
        }

        $exclusiveRuntimeLock = wallos_database_acquire_exclusive_runtime_lock($databasePath);
        $lockPaths = wallos_database_runtime_lock_paths($databasePath);
        $transactionMarker = (string) ($lockPaths['restore_transaction'] ?? '');
        if ($transactionMarker === '') {
            throw new RuntimeException('Restore transaction marker path is unavailable');
        }
        wallos_restore_write_transaction_marker($transactionMarker, $transactionId, 'PREPARING');

        wallos_restore_prepare_logos_transaction(
            $logosTransaction,
            $extractedBackup['logos_path'],
            $logosDirectory,
            $transactionId,
            $options
        );
        wallos_restore_invoke_fault($options, 'logos.prepared', $logosTransaction);

        wallos_restore_prepare_database_transaction(
            $databaseTransaction,
            $extractedBackup['database_path'],
            $databasePath,
            $projectRoot,
            $extractedBackup['logos_path'],
            $transactionId,
            $options
        );
        wallos_restore_write_transaction_marker($transactionMarker, $transactionId, 'PREPARED');

        wallos_restore_commit_database_transaction($databaseTransaction, $options);
        wallos_restore_write_transaction_marker($transactionMarker, $transactionId, 'DATABASE_INSTALLED');

        wallos_restore_commit_logos_transaction($logosTransaction, $options);
        wallos_restore_write_transaction_marker($transactionMarker, $transactionId, 'VERIFYING');

        wallos_run_migrations_after_restore($projectRoot, $databasePath, $logosDirectory);
        wallos_restore_checkpoint_database($databasePath);
        if (hash_file('sha256', $databasePath) !== $databaseTransaction['incoming_hash']) {
            throw new RuntimeException('Installed database changed during joint restore verification');
        }
        if (wallos_restore_directory_manifest($logosDirectory, true) !== $logosTransaction['new_manifest']) {
            throw new RuntimeException('Installed media changed during joint restore verification');
        }

        wallos_restore_invoke_fault($options, 'before_commit', [
            'database' => $databaseTransaction,
            'logos' => $logosTransaction,
        ]);
        wallos_restore_write_transaction_marker($transactionMarker, $transactionId, 'COMMITTED');
        $committed = true;
        wallos_restore_invoke_fault($options, 'after_commit', [
            'database' => $databaseTransaction,
            'logos' => $logosTransaction,
        ]);

        $cleanupWarnings = array_merge(
            wallos_restore_finalize_logos_transaction($logosTransaction),
            wallos_restore_finalize_database_transaction($databaseTransaction)
        );
        if ($cleanupWarnings) {
            throw new RuntimeException(
                'Committed restore cleanup incomplete: ' . implode('; ', $cleanupWarnings)
            );
        }
        wallos_restore_delete_tree_strict($workspace);
        $workspace = '';
        wallos_restore_remove_transaction_marker($transactionMarker);
        wallos_database_clear_exclusive_maintenance_marker($exclusiveRuntimeLock);
        $transactionMarker = '';
    } catch (Throwable $throwable) {
        if ($committed) {
            $cleanupErrors = [$throwable->getMessage()];
            if ($transactionMarker !== '') {
                try {
                    wallos_restore_write_transaction_marker(
                        $transactionMarker,
                        $transactionId,
                        'COMMITTED_CLEANUP_INCOMPLETE'
                    );
                } catch (Throwable $markerException) {
                    $cleanupErrors[] = $markerException->getMessage();
                }
            }
            $keepWorkspace = true;
            if (is_array($exclusiveRuntimeLock)) {
                $exclusiveRuntimeLock['retain_maintenance'] = true;
            }
            throw new WallosRestoreRollbackIncompleteException(
                'Restore reached COMMITTED state but final cleanup is incomplete',
                $cleanupErrors,
                array_values(array_filter([
                    $workspace,
                    $transactionMarker,
                    $logosTransaction['previous'] ?? null,
                    $logosTransaction['incoming'] ?? null,
                    $databaseTransaction['previous'] ?? null,
                    $databaseTransaction['incoming'] ?? null,
                ])),
                $throwable
            );
        }

        $rollbackErrors = [];
        $recoveryPaths = [];
        if ($logosTransaction) {
            try {
                wallos_restore_rollback_logos_transaction($logosTransaction, $options);
            } catch (WallosRestoreRollbackIncompleteException $rollbackException) {
                $rollbackErrors = array_merge($rollbackErrors, $rollbackException->getRollbackErrors());
                $recoveryPaths = array_merge($recoveryPaths, $rollbackException->getRecoveryPaths());
            } catch (Throwable $rollbackException) {
                $rollbackErrors[] = $rollbackException->getMessage();
            }
        }

        if ($databaseTransaction) {
            try {
                wallos_restore_rollback_database_transaction($databaseTransaction, $options);
            } catch (WallosRestoreRollbackIncompleteException $rollbackException) {
                $rollbackErrors = array_merge($rollbackErrors, $rollbackException->getRollbackErrors());
                $recoveryPaths = array_merge($recoveryPaths, $rollbackException->getRecoveryPaths());
            } catch (Throwable $rollbackException) {
                $rollbackErrors[] = $rollbackException->getMessage();
            }
        }

        if (!$rollbackErrors) {
            try {
                wallos_restore_delete_tree_strict($workspace);
                $workspace = '';
                if ($transactionMarker !== '') {
                    wallos_restore_remove_transaction_marker($transactionMarker);
                }
                if (is_array($exclusiveRuntimeLock)) {
                    wallos_database_clear_exclusive_maintenance_marker($exclusiveRuntimeLock);
                }
                if ($transactionMarker !== '') {
                    $transactionMarker = '';
                }
            } catch (Throwable $cleanupException) {
                $rollbackErrors[] = $cleanupException->getMessage();
                $recoveryPaths = array_merge($recoveryPaths, array_filter([
                    $workspace,
                    $transactionMarker,
                    $exclusiveRuntimeLock['maintenance'] ?? null,
                ]));
            }
        }

        if ($rollbackErrors) {
            if ($transactionMarker !== '') {
                try {
                    wallos_restore_write_transaction_marker(
                        $transactionMarker,
                        $transactionId,
                        'ROLLBACK_INCOMPLETE'
                    );
                } catch (Throwable $markerException) {
                    $rollbackErrors[] = $markerException->getMessage();
                    $recoveryPaths[] = $transactionMarker;
                }
            }
            $keepWorkspace = true;
            if ($workspace !== '') {
                $recoveryPaths[] = $workspace;
            }
            if (is_array($exclusiveRuntimeLock)) {
                $exclusiveRuntimeLock['retain_maintenance'] = true;
            }
            throw new WallosRestoreRollbackIncompleteException(
                $throwable->getMessage() . '; rollback incomplete',
                $rollbackErrors,
                array_values(array_filter(array_unique($recoveryPaths))),
                $throwable
            );
        }

        throw $throwable;
    } finally {
        wallos_database_release_exclusive_runtime_lock($exclusiveRuntimeLock);
        if (!$keepWorkspace) {
            wallos_delete_directory_tree($workspace);
        }
    }
}

function wallos_create_backup_archive($db, $mode = 'manual', $basePath = null, $progressCallback = null)
{
    $projectRootInput = $basePath !== null ? rtrim((string) $basePath, '/\\') : dirname(__DIR__);
    if ($projectRootInput === '' || is_link($projectRootInput)) {
        throw new RuntimeException('Backup project root must be a real directory');
    }
    $projectRoot = realpath($projectRootInput);
    if ($projectRoot === false || !is_dir($projectRoot)) {
        throw new RuntimeException('Backup project root must be a real directory');
    }
    $projectRoot = rtrim($projectRoot, '/\\');
    $databaseFile = $projectRoot . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'wallos.db';
    $logosDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'logos';
    wallos_restore_assert_path_components($projectRoot, $databaseFile, false);
    wallos_restore_assert_path_components($projectRoot, $logosDirectory, true);
    if (wallos_restore_path_exists($logosDirectory)
        && (is_link($logosDirectory) || !is_dir($logosDirectory))) {
        throw new RuntimeException('Backup media root must be a real directory');
    }

    $backupDirectory = wallos_get_backup_storage_dir($projectRoot);
    wallos_restore_assert_path_components($projectRoot, $backupDirectory, true);
    $backupDirectory = wallos_ensure_backup_storage_dir($projectRoot);
    wallos_restore_assert_path_components($projectRoot, $backupDirectory, false);
    if (is_link($backupDirectory) || !is_dir($backupDirectory)) {
        throw new RuntimeException('Backup storage root must be a real directory');
    }
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
    $backupRuntimeLock = null;
    $sharedRuntimeLockNeedsRestore = false;
    $callerDatabaseNeedsReopen = false;

    wallos_cleanup_backup_temp_files($projectRoot);
    try {
        $callerDatabaseNeedsReopen = wallos_backup_close_caller_database($db);
        $sharedRuntimeLockNeedsRestore = true;
        try {
            $backupRuntimeLock = wallos_database_acquire_exclusive_runtime_lock($databaseFile);
        } catch (Throwable $lockException) {
            // If publication of the exclusive marker failed before the helper
            // released our old shared lock, drop it now. A fresh acquire in the
            // finally block must re-check every maintenance marker.
            wallos_database_release_shared_runtime_lock();
            throw $lockException;
        }

        wallos_emit_backup_progress($progressCallback, 'preparing', 5);
        wallos_create_backup_database_snapshot($databaseFile, $snapshotPath);
        wallos_emit_backup_progress($progressCallback, 'snapshot', 18);
        if (!is_dir($stagedLogosDirectory)) {
            mkdir($stagedLogosDirectory, 0755, true);
        }
        $liveMediaManifest = is_dir($logosDirectory)
            ? wallos_restore_directory_manifest($logosDirectory, true)
            : [];
        wallos_copy_directory_tree($logosDirectory, $stagedLogosDirectory, $progressCallback, 22, 60);
        if (wallos_restore_directory_manifest($stagedLogosDirectory, false) !== $liveMediaManifest) {
            throw new RuntimeException('Staged backup media differs from the locked live media tree');
        }

        wallos_database_downgrade_exclusive_runtime_lock($backupRuntimeLock);
        $backupRuntimeLock = null;
        if ($callerDatabaseNeedsReopen) {
            wallos_backup_reopen_caller_database($db, $databaseFile);
            $callerDatabaseNeedsReopen = false;
        }
        $sharedRuntimeLockNeedsRestore = false;

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

            $metadataJson = json_encode(
                $metadata,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            $manifestJson = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            if ($metadataJson === false || $manifestJson === false
                || !$zip->addFile($snapshotPath, 'wallos.db')) {
                throw new RuntimeException('Cannot add database or metadata to backup archive');
            }
            wallos_emit_backup_progress($progressCallback, 'zip_archive', 74, ['current' => 0, 'total' => 0]);
            wallos_add_directory_to_zip($stagedLogosDirectory, $zip, 'logos', $progressCallback, 76, 94);
            if (!$zip->addFromString('metadata.json', $metadataJson)
                || !$zip->addFromString('manifest.json', $manifestJson)) {
                throw new RuntimeException('Cannot add metadata or manifest to backup archive');
            }
        } finally {
            if ($zip->close() === false) {
                throw new RuntimeException('Cannot finalize backup archive');
            }
        }

        wallos_emit_backup_progress($progressCallback, 'finalizing', 97);
        $verification = wallos_verify_backup_archive($temporaryArchivePath);
        if (empty($verification['is_valid']) || ($verification['level'] ?? '') !== 'full') {
            throw new RuntimeException('Created backup failed full manifest verification');
        }
        $backupStorageIdentity = @lstat($backupDirectory);
        $temporaryArchiveIdentity = @lstat($temporaryArchivePath);
        if ($backupStorageIdentity === false || $temporaryArchiveIdentity === false) {
            throw new RuntimeException('Cannot inspect finalized backup ownership');
        }
        $targetUid = (int) ($backupStorageIdentity['uid'] ?? -1);
        $targetGid = (int) ($backupStorageIdentity['gid'] ?? -1);
        if (((int) ($temporaryArchiveIdentity['uid'] ?? -2) !== $targetUid
                && !@chown($temporaryArchivePath, $targetUid))
            || ((int) ($temporaryArchiveIdentity['gid'] ?? -2) !== $targetGid
                && !@chgrp($temporaryArchivePath, $targetGid))) {
            throw new RuntimeException('Cannot assign finalized backup to its storage owner');
        }
        if (!@chmod($temporaryArchivePath, 0660)) {
            throw new RuntimeException('Cannot protect finalized backup archive');
        }
        wallos_restore_sync_file($temporaryArchivePath);
        if (!@rename($temporaryArchivePath, $archivePath)) {
            throw new RuntimeException('Cannot finalize backup archive');
        }
        wallos_restore_sync_directory($backupDirectory);

        $backup = wallos_find_backup_by_name($fileName, $projectRoot);
        if ($backup === null) {
            throw new RuntimeException('Backup archive was not created');
        }

        wallos_emit_backup_progress($progressCallback, 'completed', 100, [
            'backup' => $backup,
        ]);
        return $backup;
    } finally {
        if (is_array($backupRuntimeLock)) {
            wallos_database_release_exclusive_runtime_lock($backupRuntimeLock);
            $backupRuntimeLock = null;
        }
        if ($sharedRuntimeLockNeedsRestore) {
            try {
                wallos_database_acquire_shared_runtime_lock($databaseFile);
                if ($callerDatabaseNeedsReopen) {
                    wallos_backup_reopen_caller_database($db, $databaseFile);
                    $callerDatabaseNeedsReopen = false;
                }
                $sharedRuntimeLockNeedsRestore = false;
            } catch (Throwable $lockException) {
                error_log('Wallos backup could not restore caller database access: ' . $lockException->getMessage());
            }
        }
        wallos_delete_directory_tree($workspace);
        if (file_exists($temporaryArchivePath)) {
            @unlink($temporaryArchivePath);
        }
    }
}
