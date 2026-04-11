<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/subscription_media.php';
require_once '../../includes/user_groups.php';

$userStmt = $db->prepare('SELECT username, user_group FROM user WHERE id = :userId');
$userStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$userResult = $userStmt->execute();
$currentUser = $userResult ? $userResult->fetchArray(SQLITE3_ASSOC) : false;

$isAdminUser = $userId === 1;
if (!wallos_can_upload_subscription_images($isAdminUser, $currentUser['user_group'] ?? WALLOS_USER_GROUP_FREE)) {
    header('Content-Type: application/json');
    die(json_encode([
        'success' => false,
        'message' => translate('subscription_image_export_not_allowed', $i18n)
    ]));
}

$stmt = $db->prepare('SELECT subscription_id, path, file_name, original_name, created_at FROM subscription_uploaded_images WHERE user_id = :userId ORDER BY id ASC');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();

$images = [];
while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
    $images[] = $row;
}

if (empty($images)) {
    header('Content-Type: application/json');
    die(json_encode([
        'success' => false,
        'message' => translate('subscription_image_export_empty', $i18n)
    ]));
}

$tempFile = tempnam(sys_get_temp_dir(), 'wallos-images-');
$zipPath = $tempFile . '.zip';
rename($tempFile, $zipPath);

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    header('Content-Type: application/json');
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n)
    ]));
}

$metadata = [];
foreach ($images as $image) {
    $fullPath = __DIR__ . '/../../' . str_replace('/', DIRECTORY_SEPARATOR, $image['path']);
    if (!is_file($fullPath)) {
        continue;
    }

    $zip->addFile($fullPath, 'images/' . basename($image['file_name']));
    $metadata[] = [
        'subscription_id' => (int) ($image['subscription_id'] ?? 0),
        'file_name' => $image['file_name'],
        'original_name' => $image['original_name'],
        'created_at' => $image['created_at'],
        'path' => $image['path'],
    ];
}

$manifest = [
    'exported_at' => date('c'),
    'user' => [
        'id' => (int) $userId,
        'username' => $currentUser['username'] ?? ('user-' . $userId),
        'storage_directory' => 'user-' . (int) $userId,
    ],
    'image_count' => count($metadata),
    'images' => $metadata,
];

$zip->addFromString(
    'metadata.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
$zip->close();

$downloadName = wallos_sanitize_subscription_media_filename_part($currentUser['username'] ?? ('user-' . $userId)) . '-subscription-images.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($zipPath));
readfile($zipPath);
unlink($zipPath);
exit;
