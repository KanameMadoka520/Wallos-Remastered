<?php

define('WALLOS_BACKUP_DEFAULT_RETENTION_DAYS', 14);
define('WALLOS_BACKUP_MAX_RETENTION_DAYS', 365);
define('WALLOS_BACKUP_MANIFEST_VERSION', 1);

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

function wallos_build_backup_manifest($databaseSnapshotPath, $logosDirectory)
{
    $files = wallos_collect_backup_manifest_files($databaseSnapshotPath, $logosDirectory);

    return [
        'version' => WALLOS_BACKUP_MANIFEST_VERSION,
        'created_at' => date('c'),
        'file_count' => count($files),
        'files' => $files,
    ];
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
    $manifestRaw = $zip->getFromName('manifest.json', 0, ZipArchive::FL_NODIR);
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
        $wallosDbIndex = $zip->locateName('wallos.db', ZipArchive::FL_NODIR);
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

            if ($zip->locateName($normalizedEntryName, ZipArchive::FL_NODIR) === false) {
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

function wallos_copy_directory_tree($sourceDirectory, $destinationDirectory)
{
    if (!is_dir($sourceDirectory)) {
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

        copy($item->getPathname(), $destinationPath);
    }
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

function wallos_restore_backup_archive($archivePath, $projectRoot)
{
    $projectRoot = rtrim((string) $projectRoot, '/\\');
    $workspace = wallos_create_backup_workspace($projectRoot, 'restore');

    try {
        $extractedBackup = wallos_extract_backup_archive_to_workspace($archivePath, $workspace);

        $databaseDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'db';
        $databasePath = $databaseDirectory . DIRECTORY_SEPARATOR . 'wallos.db';
        $databaseBackupPath = $databaseDirectory . DIRECTORY_SEPARATOR . 'wallos.restore.previous.db';
        $logosDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'logos';

        if (!is_dir($databaseDirectory)) {
            mkdir($databaseDirectory, 0755, true);
        }

        if (is_file($databaseBackupPath)) {
            @unlink($databaseBackupPath);
        }

        if (is_file($databasePath) && !@rename($databasePath, $databaseBackupPath)) {
            throw new RuntimeException('Cannot replace current database');
        }

        if (!@rename($extractedBackup['database_path'], $databasePath)) {
            if (is_file($databaseBackupPath)) {
                @rename($databaseBackupPath, $databasePath);
            }
            throw new RuntimeException('Cannot install restored database');
        }

        if (is_file($databaseBackupPath)) {
            @unlink($databaseBackupPath);
        }

        wallos_clear_directory_contents($logosDirectory);
        if (is_dir($extractedBackup['logos_path'])) {
            wallos_copy_directory_tree($extractedBackup['logos_path'], $logosDirectory);
        }
    } finally {
        wallos_delete_directory_tree($workspace);
    }
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
    $temporaryArchivePath = $archivePath . '.' . bin2hex(random_bytes(3)) . '.part';
    $workspace = wallos_create_backup_workspace($projectRoot, 'backup');
    $snapshotPath = $workspace . DIRECTORY_SEPARATOR . 'wallos.db';
    $stagedLogosDirectory = $workspace . DIRECTORY_SEPARATOR . 'logos';

    wallos_cleanup_backup_temp_files($projectRoot);
    try {
        wallos_create_backup_database_snapshot($databaseFile, $snapshotPath);
        if (!is_dir($stagedLogosDirectory)) {
            mkdir($stagedLogosDirectory, 0755, true);
        }
        wallos_copy_directory_tree($logosDirectory, $stagedLogosDirectory);

        $manifest = wallos_build_backup_manifest($snapshotPath, $stagedLogosDirectory);

        $zip = new ZipArchive();
        if ($zip->open($temporaryArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot open backup archive');
        }

        try {
            $metadata = [
                'mode' => $mode,
                'created_at' => date('c'),
                'includes' => ['wallos.db', 'logos/'],
                'manifest_version' => WALLOS_BACKUP_MANIFEST_VERSION,
                'manifest_file_count' => (int) $manifest['file_count'],
            ];

            $zip->addFile($snapshotPath, 'wallos.db');
            wallos_add_directory_to_zip($stagedLogosDirectory, $zip, 'logos');
            $zip->addFromString('metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } finally {
            if ($zip->close() === false) {
                throw new RuntimeException('Cannot finalize backup archive');
            }
        }

        if (!@rename($temporaryArchivePath, $archivePath)) {
            throw new RuntimeException('Cannot finalize backup archive');
        }

        $backup = wallos_find_backup_by_name($fileName, $projectRoot);
        if ($backup === null) {
            throw new RuntimeException('Backup archive was not created');
        }

        return $backup;
    } finally {
        wallos_delete_directory_tree($workspace);
        if (file_exists($temporaryArchivePath)) {
            @unlink($temporaryArchivePath);
        }
    }
}
