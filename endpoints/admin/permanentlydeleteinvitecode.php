<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);
$inviteCodeId = (int) ($data['inviteCodeId'] ?? 0);

if ($inviteCodeId <= 0) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n)
    ]));
}

$lookupStmt = $db->prepare('SELECT id, deleted FROM invite_codes WHERE id = :id');
$lookupStmt->bindValue(':id', $inviteCodeId, SQLITE3_INTEGER);
$lookupResult = $lookupStmt->execute();
$inviteCode = $lookupResult ? $lookupResult->fetchArray(SQLITE3_ASSOC) : false;

if ($inviteCode === false || (int) ($inviteCode['deleted'] ?? 0) !== 1) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n)
    ]));
}

$db->exec('BEGIN IMMEDIATE');

try {
    $deleteUsageStmt = $db->prepare('DELETE FROM invite_code_usages WHERE invite_code_id = :id');
    $deleteUsageStmt->bindValue(':id', $inviteCodeId, SQLITE3_INTEGER);
    $deleteUsageStmt->execute();

    $deleteInviteStmt = $db->prepare('DELETE FROM invite_codes WHERE id = :id');
    $deleteInviteStmt->bindValue(':id', $inviteCodeId, SQLITE3_INTEGER);
    $deleteInviteStmt->execute();

    $db->exec('COMMIT');
} catch (Throwable $throwable) {
    $db->exec('ROLLBACK');
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n)
    ]));
}

die(json_encode([
    'success' => true,
    'message' => translate('invite_code_permanently_deleted', $i18n)
]));
