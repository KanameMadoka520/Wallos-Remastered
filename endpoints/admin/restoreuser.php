<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/user_data_cleanup.php';
require_once '../../includes/user_status.php';

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);
$targetUserId = (int) ($data['userId'] ?? 0);

if ($targetUserId <= 0 || $targetUserId === 1) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n)
    ]));
}

$userLookup = $db->prepare('SELECT account_status FROM user WHERE id = :id');
$userLookup->bindValue(':id', $targetUserId, SQLITE3_INTEGER);
$userLookupResult = $userLookup->execute();
$targetUser = $userLookupResult ? $userLookupResult->fetchArray(SQLITE3_ASSOC) : false;

if ($targetUser === false || !wallos_is_user_trashed($targetUser['account_status'] ?? WALLOS_USER_STATUS_ACTIVE)) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n)
    ]));
}

wallos_restore_user($db, $targetUserId);

die(json_encode([
    'success' => true,
    'message' => translate('user_restored_from_recycle_bin', $i18n)
]));
