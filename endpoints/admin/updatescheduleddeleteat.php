<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/user_status.php';

function wallos_parse_datetime_local_to_storage($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return false;
    }

    $dateTime = DateTime::createFromFormat('Y-m-d\TH:i', $value);
    if (!$dateTime) {
        $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $value);
    }

    if (!$dateTime) {
        return false;
    }

    return $dateTime;
}

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);
$targetUserId = (int) ($data['userId'] ?? 0);
$scheduledDeleteAtInput = trim((string) ($data['scheduledDeleteAt'] ?? ''));

if ($targetUserId <= 0) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n)
    ]));
}

if ($scheduledDeleteAtInput === '') {
    die(json_encode([
        'success' => false,
        'message' => translate('scheduled_delete_time_required', $i18n)
    ]));
}

$dateTime = wallos_parse_datetime_local_to_storage($scheduledDeleteAtInput);
if ($dateTime === false) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n)
    ]));
}

$lookupStmt = $db->prepare('SELECT id, account_status FROM user WHERE id = :id');
$lookupStmt->bindValue(':id', $targetUserId, SQLITE3_INTEGER);
$lookupResult = $lookupStmt->execute();
$targetUser = $lookupResult ? $lookupResult->fetchArray(SQLITE3_ASSOC) : false;

if ($targetUser === false || !wallos_is_user_trashed($targetUser['account_status'] ?? WALLOS_USER_STATUS_ACTIVE)) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n)
    ]));
}

$scheduledDeleteAt = $dateTime->format('Y-m-d H:i:s');

$updateStmt = $db->prepare('UPDATE user SET scheduled_delete_at = :scheduled_delete_at WHERE id = :id');
$updateStmt->bindValue(':scheduled_delete_at', $scheduledDeleteAt, SQLITE3_TEXT);
$updateStmt->bindValue(':id', $targetUserId, SQLITE3_INTEGER);
$updateStmt->execute();

die(json_encode([
    'success' => true,
    'message' => translate('scheduled_delete_time_updated', $i18n),
    'scheduledDeleteAt' => $scheduledDeleteAt,
    'datetimeLocal' => $dateTime->format('Y-m-d\TH:i')
]));
