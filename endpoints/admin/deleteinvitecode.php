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

$stmt = $db->prepare('UPDATE invite_codes SET deleted = 1, deleted_at = CURRENT_TIMESTAMP WHERE id = :id');
$stmt->bindValue(':id', $inviteCodeId, SQLITE3_INTEGER);

if ($stmt->execute()) {
    die(json_encode([
        'success' => true,
        'message' => translate('invite_code_deleted', $i18n)
    ]));
}

die(json_encode([
    'success' => false,
    'message' => translate('error', $i18n)
]));
