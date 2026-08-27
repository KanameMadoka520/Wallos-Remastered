<?php

require_once __DIR__ . '/../includes/backup_manager.php';

function wallos_backup_manifest_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_backup_manifest_file_entry($contents)
{
    return [
        'size_bytes' => strlen($contents),
        'sha256' => hash('sha256', $contents),
    ];
}

function wallos_backup_manifest_create_archive($path, array $contents, array $manifestFiles)
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create backup manifest fixture.');
    }

    try {
        foreach ($contents as $entryName => $entryContents) {
            $zip->addFromString($entryName, $entryContents);
        }

        $zip->addFromString('manifest.json', json_encode([
            'version' => 1,
            'file_count' => count($manifestFiles),
            'files' => $manifestFiles,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } finally {
        $zip->close();
    }
}

$testRoot = sys_get_temp_dir() . '/wallos-backup-manifest-' . bin2hex(random_bytes(8));

try {
    if (!mkdir($testRoot, 0770, true) && !is_dir($testRoot)) {
        throw new RuntimeException('Unable to create backup manifest fixture directory.');
    }
    ini_set('error_log', $testRoot . '/expected-errors.log');

    $databaseContents = "SQLite format 3\0wallos-test-database";
    $logoContents = 'nested-logo-contents';
    $mediaContents = 'nested-subscription-media';
    $contents = [
        'wallos.db' => $databaseContents,
        'logos/example.png' => $logoContents,
        'logos/subscription-media/user-7/example.jpg' => $mediaContents,
    ];
    $manifestFiles = [];
    foreach ($contents as $entryName => $entryContents) {
        $manifestFiles[$entryName] = array_merge(
            ['path' => $entryName],
            wallos_backup_manifest_file_entry($entryContents)
        );
    }

    $validArchive = $testRoot . '/valid.zip';
    wallos_backup_manifest_create_archive($validArchive, $contents, $manifestFiles);
    $validResult = wallos_verify_backup_archive($validArchive);
    wallos_backup_manifest_assert(!empty($validResult['is_valid']), 'Valid nested backup was rejected.');
    wallos_backup_manifest_assert(($validResult['level'] ?? '') === 'full', 'Valid manifest did not receive full verification.');
    wallos_backup_manifest_assert((int) ($validResult['files_checked'] ?? 0) === 3, 'Verifier did not check every nested file.');

    $corruptContents = $contents;
    $corruptContents['logos/example.png'] = 'corrupted-logo-contents';
    $corruptArchive = $testRoot . '/corrupt.zip';
    wallos_backup_manifest_create_archive($corruptArchive, $corruptContents, $manifestFiles);
    $corruptResult = wallos_verify_backup_archive($corruptArchive);
    wallos_backup_manifest_assert(empty($corruptResult['is_valid']), 'Corrupted nested backup was accepted.');
    wallos_backup_manifest_assert(
        in_array('Size mismatch: logos/example.png', $corruptResult['errors'] ?? [], true)
            || in_array('Checksum mismatch: logos/example.png', $corruptResult['errors'] ?? [], true),
        'Corrupted nested file did not produce a size or checksum error.'
    );

    $extraContents = $contents;
    $extraContents['logos/unlisted.txt'] = 'not-declared-by-manifest';
    $extraArchive = $testRoot . '/extra.zip';
    wallos_backup_manifest_create_archive($extraArchive, $extraContents, $manifestFiles);
    $extraResult = wallos_verify_backup_archive($extraArchive);
    wallos_backup_manifest_assert(
        empty($extraResult['is_valid'])
            && in_array(
                'Archive file is not declared by manifest: logos/unlisted.txt',
                $extraResult['errors'] ?? [],
                true
            ),
        'Archive extractor could install a Logo that was omitted from the manifest.'
    );

    $reservedContents = $contents;
    $reservedContents['logos/.wallos.restore.previous-fixture/private.txt'] = 'private';
    $reservedArchive = $testRoot . '/reserved.zip';
    wallos_backup_manifest_create_archive($reservedArchive, $reservedContents, $manifestFiles);
    $reservedResult = wallos_verify_backup_archive($reservedArchive);
    wallos_backup_manifest_assert(
        empty($reservedResult['is_valid']),
        'Archive containing the reserved restore workspace prefix was accepted.'
    );

    $symlinkArchive = $testRoot . '/symlink.zip';
    $symlinkContents = $contents;
    $symlinkContents['logos/link'] = '/outside/secret';
    $symlinkManifest = $manifestFiles;
    $symlinkManifest['logos/link'] = array_merge(
        ['path' => 'logos/link'],
        wallos_backup_manifest_file_entry($symlinkContents['logos/link'])
    );
    wallos_backup_manifest_create_archive($symlinkArchive, $symlinkContents, $symlinkManifest);
    $symlinkZip = new ZipArchive();
    wallos_backup_manifest_assert($symlinkZip->open($symlinkArchive) === true, 'Cannot reopen symlink fixture.');
    $symlinkZip->setExternalAttributesName('logos/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $symlinkZip->close();
    $symlinkResult = wallos_verify_backup_archive($symlinkArchive);
    wallos_backup_manifest_assert(
        empty($symlinkResult['is_valid'])
            && in_array(
                'Backup archive contains a symbolic link or special file',
                $symlinkResult['errors'] ?? [],
                true
            ),
        'Declared symbolic-link entry was not rejected by its archive type.'
    );

    $conflictContents = $contents;
    $conflictContents['logos/conflict'] = 'file-parent';
    $conflictContents['logos/conflict/nested.txt'] = 'nested-child';
    $conflictManifest = $manifestFiles;
    foreach (['logos/conflict', 'logos/conflict/nested.txt'] as $entryName) {
        $conflictManifest[$entryName] = array_merge(
            ['path' => $entryName],
            wallos_backup_manifest_file_entry($conflictContents[$entryName])
        );
    }
    $conflictArchive = $testRoot . '/path-conflict.zip';
    wallos_backup_manifest_create_archive($conflictArchive, $conflictContents, $conflictManifest);
    $conflictResult = wallos_verify_backup_archive($conflictArchive);
    wallos_backup_manifest_assert(
        empty($conflictResult['is_valid']),
        'Archive containing a file/directory path conflict was accepted.'
    );

    $sourceRoot = $testRoot . '/source-symlink';
    mkdir($sourceRoot . '/db', 0770, true);
    mkdir($sourceRoot . '/images/uploads/logos', 0770, true);
    mkdir($sourceRoot . '/backups', 0770, true);
    mkdir($sourceRoot . '/.tmp', 0770, true);
    $sourceDatabase = new SQLite3($sourceRoot . '/db/wallos.db');
    $sourceDatabase->enableExceptions(true);
    $sourceDatabase->exec('CREATE TABLE fixture (id INTEGER PRIMARY KEY)');
    $sourceDatabase->exec('CREATE TABLE admin (id INTEGER PRIMARY KEY, backup_timezone TEXT)');
    $sourceDatabase->exec("INSERT INTO admin (id, backup_timezone) VALUES (1, 'UTC')");
    $outsideFile = $testRoot . '/outside-secret.txt';
    file_put_contents($outsideFile, 'outside-secret');
    symlink($outsideFile, $sourceRoot . '/images/uploads/logos/linked-secret.txt');
    $sourceSymlinkRejected = false;
    try {
        wallos_create_backup_archive(null, 'manual', $sourceRoot);
    } catch (RuntimeException $runtimeException) {
        $sourceSymlinkRejected = strpos($runtimeException->getMessage(), 'symbolic link') !== false;
    }
    wallos_backup_manifest_assert(
        $sourceSymlinkRejected
            && file_get_contents($outsideFile) === 'outside-secret'
            && glob($sourceRoot . '/backups/*.zip') === [],
        'Backup creation followed a source Logo symlink or published a partial archive.'
    );
    unlink($sourceRoot . '/images/uploads/logos/linked-secret.txt');
    file_put_contents($sourceRoot . '/images/uploads/logos/consistent.txt', 'consistent-media');
    if (function_exists('posix_geteuid') && posix_geteuid() === 0
        && function_exists('posix_getpwnam') && function_exists('posix_getgrnam')) {
        $wwwUser = posix_getpwnam('www-data');
        $wwwGroup = posix_getgrnam('www-data');
        if (is_array($wwwUser) && is_array($wwwGroup)) {
            chown($sourceRoot . '/backups', (int) $wwwUser['uid']);
            chgrp($sourceRoot . '/backups', (int) $wwwGroup['gid']);
        }
    }
    $backupStorageIdentity = lstat($sourceRoot . '/backups');
    $backupLockObserved = false;
    $concurrentWriterRejected = false;
    $callerDatabaseClosedDuringSnapshot = false;
    $downgradedSharedLockObserved = false;
    $createdBackup = wallos_create_backup_archive(
        $sourceDatabase,
        'manual',
        $sourceRoot,
        static function ($progress) use (
            &$backupLockObserved,
            &$concurrentWriterRejected,
            &$callerDatabaseClosedDuringSnapshot,
            &$downgradedSharedLockObserved,
            $sourceDatabase,
            $sourceRoot
        ) {
            $stage = (string) ($progress['stage'] ?? '');
            if ($stage === 'manifest') {
                global $wallosDatabaseSharedRuntimeLock;
                $paths = wallos_database_runtime_lock_paths($sourceRoot . '/db/wallos.db');
                $downgradedSharedLockObserved = is_resource($wallosDatabaseSharedRuntimeLock)
                    && !wallos_database_maintenance_marker_exists($paths);
                return;
            }
            if ($stage !== 'snapshot') {
                return;
            }
            $databasePath = $sourceRoot . '/db/wallos.db';
            $paths = wallos_database_runtime_lock_paths($databasePath);
            $backupLockObserved = is_file($paths['maintenance']);
            try {
                $sourceDatabase->querySingle('SELECT COUNT(*) FROM fixture');
            } catch (Throwable $throwable) {
                $callerDatabaseClosedDuringSnapshot = true;
            }
            try {
                wallos_database_acquire_shared_runtime_lock($databasePath, 20);
                wallos_database_release_shared_runtime_lock();
            } catch (RuntimeException $runtimeException) {
                $concurrentWriterRejected = true;
            }
        }
    );
    $createdBackupVerification = wallos_verify_backup_archive($createdBackup['path']);
    $createdBackupZip = new ZipArchive();
    wallos_backup_manifest_assert(
        $createdBackupZip->open($createdBackup['path']) === true,
        'Cannot open the successfully created backup fixture.'
    );
    $createdMedia = $createdBackupZip->getFromName('logos/consistent.txt');
    $createdBackupZip->close();
    $createdBackupIdentity = lstat($createdBackup['path']);
    wallos_backup_manifest_assert(
        $backupLockObserved
            && $concurrentWriterRejected
            && $callerDatabaseClosedDuringSnapshot
            && $downgradedSharedLockObserved
            && (int) $sourceDatabase->querySingle('SELECT COUNT(*) FROM fixture') === 0
            && !empty($createdBackupVerification['is_valid'])
            && ($createdBackupVerification['level'] ?? '') === 'full'
            && $createdMedia === 'consistent-media'
            && (fileperms($createdBackup['path']) & 0777) === 0660
            && (int) ($createdBackupIdentity['uid'] ?? -1)
                === (int) ($backupStorageIdentity['uid'] ?? -2)
            && (int) ($createdBackupIdentity['gid'] ?? -1)
                === (int) ($backupStorageIdentity['gid'] ?? -2),
        'Backup snapshot and media staging were not protected by one exclusive consistency window.'
    );
    $sourceDatabase->close();

    $competitionDatabase = new SQLite3(
        $sourceRoot . '/db/wallos.db',
        SQLITE3_OPEN_READWRITE
    );
    $competitionDatabase->enableExceptions(true);
    $competitionJournal = $sourceRoot . '/db/.wallos-restore-transaction';
    file_put_contents($competitionJournal, '{"phase":"ROLLBACK_INCOMPLETE"}');
    $competitionRejected = false;
    try {
        wallos_create_backup_archive($competitionDatabase, 'manual', $sourceRoot);
    } catch (RuntimeException $runtimeException) {
        $competitionRejected = true;
    }
    $staleConnectionUnavailable = false;
    try {
        $competitionDatabase->querySingle('SELECT COUNT(*) FROM fixture');
    } catch (Throwable $throwable) {
        $staleConnectionUnavailable = true;
    }
    wallos_backup_manifest_assert(
        $competitionRejected && $staleConnectionUnavailable,
        'Failed backup lock upgrade left its old SQLite connection usable during restore maintenance.'
    );
    unlink($competitionJournal);
    $competitionDatabase->open($sourceRoot . '/db/wallos.db', SQLITE3_OPEN_READWRITE);
    $competitionDatabase->close();

    if (function_exists('posix_mkfifo')) {
        $specialRoot = $testRoot . '/source-special-root';
        foreach (['db', 'images/uploads', 'backups', '.tmp'] as $directory) {
            mkdir($specialRoot . '/' . $directory, 0770, true);
        }
        $specialDatabase = new SQLite3($specialRoot . '/db/wallos.db');
        $specialDatabase->exec('CREATE TABLE fixture (id INTEGER PRIMARY KEY)');
        $specialDatabase->close();
        wallos_backup_manifest_assert(
            posix_mkfifo($specialRoot . '/images/uploads/logos', 0600),
            'Cannot create special media-root fixture.'
        );
        $specialRootRejected = false;
        try {
            wallos_create_backup_archive(null, 'manual', $specialRoot);
        } catch (RuntimeException $runtimeException) {
            $specialRootRejected = strpos($runtimeException->getMessage(), 'media root') !== false;
        }
        wallos_backup_manifest_assert(
            $specialRootRejected && glob($specialRoot . '/backups/*.zip') === [],
            'Backup creation accepted a FIFO media root or published an archive.'
        );
        unlink($specialRoot . '/images/uploads/logos');
    }

    $outsideImages = $testRoot . '/outside-images';
    mkdir($outsideImages . '/uploads/logos', 0770, true);
    file_put_contents($outsideImages . '/uploads/logos/sentinel.txt', 'outside-tree');

    $backupAncestorRoot = $testRoot . '/backup-ancestor-symlink';
    foreach (['db', 'backups', '.tmp'] as $directory) {
        mkdir($backupAncestorRoot . '/' . $directory, 0770, true);
    }
    $ancestorDatabase = new SQLite3($backupAncestorRoot . '/db/wallos.db');
    $ancestorDatabase->exec('CREATE TABLE fixture (id INTEGER PRIMARY KEY)');
    $ancestorDatabase->close();
    symlink($outsideImages, $backupAncestorRoot . '/images');
    $backupAncestorRejected = false;
    try {
        wallos_create_backup_archive(null, 'manual', $backupAncestorRoot);
    } catch (RuntimeException $runtimeException) {
        $backupAncestorRejected = strpos($runtimeException->getMessage(), 'symbolic-link component') !== false;
    }
    wallos_backup_manifest_assert(
        $backupAncestorRejected
            && file_get_contents($outsideImages . '/uploads/logos/sentinel.txt') === 'outside-tree'
            && glob($backupAncestorRoot . '/backups/*.zip') === [],
        'Backup creation accepted a symbolic-link ancestor or touched its external target.'
    );
    unlink($backupAncestorRoot . '/images');

    $restoreAncestorRoot = $testRoot . '/restore-ancestor-symlink';
    foreach (['db', 'backups', '.tmp'] as $directory) {
        mkdir($restoreAncestorRoot . '/' . $directory, 0770, true);
    }
    symlink($outsideImages, $restoreAncestorRoot . '/images');
    $restoreAncestorRejected = false;
    try {
        wallos_restore_backup_archive($validArchive, $restoreAncestorRoot);
    } catch (RuntimeException $runtimeException) {
        $restoreAncestorRejected = strpos($runtimeException->getMessage(), 'symbolic-link component') !== false;
    }
    wallos_backup_manifest_assert(
        $restoreAncestorRejected
            && file_get_contents($outsideImages . '/uploads/logos/sentinel.txt') === 'outside-tree'
            && !file_exists($restoreAncestorRoot . '/db/.wallos-restore-transaction')
            && glob($restoreAncestorRoot . '/.tmp/restore-*') === [],
        'Restore accepted a symbolic-link ancestor or began mutating live state.'
    );
    unlink($restoreAncestorRoot . '/images');

    echo "Backup manifest verification test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    wallos_delete_directory_tree($testRoot);
}

?>
