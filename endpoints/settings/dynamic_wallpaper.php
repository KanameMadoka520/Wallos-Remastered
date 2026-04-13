<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);

$dynamicWallpaper = $data['value'] ?? null;

if (!is_bool($dynamicWallpaper)) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]));
}

$stmt = $db->prepare('UPDATE settings SET dynamic_wallpaper = :dynamic_wallpaper WHERE user_id = :userId');
$stmt->bindValue(':dynamic_wallpaper', $dynamicWallpaper, SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

if ($stmt->execute()) {
    die(json_encode([
        'success' => true,
        'message' => translate('success', $i18n),
    ]));
}

die(json_encode([
    'success' => false,
    'message' => translate('error', $i18n),
]));
