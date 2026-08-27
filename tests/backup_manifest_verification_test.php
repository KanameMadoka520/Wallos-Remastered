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

    echo "Backup manifest verification test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    wallos_delete_directory_tree($testRoot);
}

?>
