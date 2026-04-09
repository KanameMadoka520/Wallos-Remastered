<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/user_groups.php';

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);

$targetUserId = (int) ($data['userId'] ?? 0);
$targetUserGroup = wallos_normalize_user_group($data['userGroup'] ?? '');

if ($targetUserId <= 0 || $targetUserId === 1) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n)
    ]));
}

$userLookup = $db->prepare('SELECT id FROM user WHERE id = :userId');
$userLookup->bindValue(':userId', $targetUserId, SQLITE3_INTEGER);
$userLookupResult = $userLookup->execute();
$userExists = $userLookupResult ? $userLookupResult->fetchArray(SQLITE3_ASSOC) : false;

if ($userExists === false) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n)
    ]));
}

$stmt = $db->prepare('UPDATE user SET user_group = :userGroup WHERE id = :userId');
$stmt->bindValue(':userGroup', $targetUserGroup, SQLITE3_TEXT);
$stmt->bindValue(':userId', $targetUserId, SQLITE3_INTEGER);

if ($stmt->execute()) {
    die(json_encode([
        'success' => true,
        'message' => translate('user_group_updated', $i18n)
    ]));
}

die(json_encode([
    'success' => false,
    'message' => translate('error', $i18n)
]));
