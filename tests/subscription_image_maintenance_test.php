<?php

require_once __DIR__ . '/../includes/system_maintenance.php';

function wallos_subscription_image_maintenance_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_subscription_image_maintenance_write_file($basePath, $relativePath, $content)
{
    $absolutePath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $directory = dirname($absolutePath);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Failed to create test directory: ' . $directory);
    }

    if (file_put_contents($absolutePath, $content) === false) {
        throw new RuntimeException('Failed to create test file: ' . $absolutePath);
    }

    return $absolutePath;
}

function wallos_subscription_image_maintenance_remove_tree($path)
{
    if (!is_dir($path)) {
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

$basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wallos-image-maintenance-' . bin2hex(random_bytes(6));
$db = null;

try {
    if (!mkdir($basePath, 0777, true) && !is_dir($basePath)) {
        throw new RuntimeException('Failed to create temporary base path.');
    }

    $db = new SQLite3(':memory:');
    $db->exec('
        CREATE TABLE subscription_uploaded_images (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            subscription_id INTEGER NOT NULL,
            path TEXT NOT NULL,
            preview_path TEXT,
            thumbnail_path TEXT
        )
    ');

    $referencedOriginal = 'images/uploads/logos/subscription-media/user-7/referenced.png';
    $referencedPreview = 'images/uploads/logos/subscription-media/user-7/derived/referenced--preview.png';
    $referencedThumbnail = 'images/uploads/logos/subscription-media/user-7/derived/referenced--thumbnail.png';
    $orphanOriginal = 'images/uploads/logos/subscription-media/user-7/orphan.png';
    $orphanPreview = 'images/uploads/logos/subscription-media/user-7/derived/orphan--preview.png';
    $outsideFile = 'images/uploads/logos/not-subscription-media.png';

    wallos_subscription_image_maintenance_write_file($basePath, $referencedOriginal, 'referenced-original');
    wallos_subscription_image_maintenance_write_file($basePath, $referencedPreview, 'referenced-preview');
    wallos_subscription_image_maintenance_write_file($basePath, $referencedThumbnail, 'referenced-thumbnail');
    wallos_subscription_image_maintenance_write_file($basePath, $orphanOriginal, 'orphan-original');
    wallos_subscription_image_maintenance_write_file($basePath, $orphanPreview, 'orphan-preview');
    wallos_subscription_image_maintenance_write_file($basePath, $outsideFile, 'outside');

    $stmt = $db->prepare('
        INSERT INTO subscription_uploaded_images (user_id, subscription_id, path, preview_path, thumbnail_path)
        VALUES (:user_id, :subscription_id, :path, :preview_path, :thumbnail_path)
    ');
    $stmt->bindValue(':user_id', 7, SQLITE3_INTEGER);
    $stmt->bindValue(':subscription_id', 77, SQLITE3_INTEGER);
    $stmt->bindValue(':path', $referencedOriginal, SQLITE3_TEXT);
    $stmt->bindValue(':preview_path', $referencedPreview, SQLITE3_TEXT);
    $stmt->bindValue(':thumbnail_path', $referencedThumbnail, SQLITE3_TEXT);
    $stmt->execute();

    $auditBefore = wallos_audit_subscription_image_storage($db, $basePath);
    wallos_subscription_image_maintenance_assert($auditBefore['indexed_rows'] === 1, 'Expected one indexed image row before cleanup.');
    wallos_subscription_image_maintenance_assert($auditBefore['indexed_files'] === 3, 'Expected three indexed file paths before cleanup.');
    wallos_subscription_image_maintenance_assert($auditBefore['disk_files'] === 5, 'Expected five subscription-media disk files before cleanup.');
    wallos_subscription_image_maintenance_assert($auditBefore['orphan_files'] === 2, 'Expected two orphan subscription-media files before cleanup.');

    $cleanup = wallos_cleanup_subscription_image_orphans($db, $basePath);
    wallos_subscription_image_maintenance_assert($cleanup['deleted_files'] === 2, 'Expected cleanup to delete exactly two orphan files.');
    wallos_subscription_image_maintenance_assert($cleanup['failed_files'] === 0, 'Expected cleanup to have no failed orphan deletions.');
    wallos_subscription_image_maintenance_assert($cleanup['after']['orphan_files'] === 0, 'Expected no orphan files after cleanup.');
    wallos_subscription_image_maintenance_assert($cleanup['after']['disk_files'] === 3, 'Expected only referenced subscription-media files after cleanup.');

    foreach ([$referencedOriginal, $referencedPreview, $referencedThumbnail] as $relativePath) {
        wallos_subscription_image_maintenance_assert(
            wallos_resolve_subscription_image_absolute_path($basePath, $relativePath) !== '',
            'Referenced file was deleted unexpectedly: ' . $relativePath
        );
    }

    foreach ([$orphanOriginal, $orphanPreview] as $relativePath) {
        wallos_subscription_image_maintenance_assert(
            wallos_resolve_subscription_image_absolute_path($basePath, $relativePath) === '',
            'Orphan file still exists after cleanup: ' . $relativePath
        );
    }

    wallos_subscription_image_maintenance_assert(
        is_file(rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $outsideFile)),
        'Cleanup should not touch files outside the subscription media directory.'
    );

    echo 'Subscription image maintenance regression checks passed.' . PHP_EOL;
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($db instanceof SQLite3) {
        $db->close();
    }
    wallos_subscription_image_maintenance_remove_tree($basePath);
}
