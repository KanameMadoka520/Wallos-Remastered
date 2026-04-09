<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/invite_codes.php';

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);
$maxUses = max(1, (int) ($data['maxUses'] ?? 1));

do {
    $code = wallos_generate_invite_code(12);
    $checkStmt = $db->prepare('SELECT COUNT(*) AS count FROM invite_codes WHERE code = :code');
    $checkStmt->bindValue(':code', $code, SQLITE3_TEXT);
    $checkResult = $checkStmt->execute();
    $checkRow = $checkResult ? $checkResult->fetchArray(SQLITE3_ASSOC) : false;
    $existing = (int) ($checkRow['count'] ?? 0);
} while ((int) $existing > 0);

$stmt = $db->prepare('
    INSERT INTO invite_codes (code, max_uses, uses_count, created_by, deleted)
    VALUES (:code, :max_uses, 0, :created_by, 0)
');
$stmt->bindValue(':code', $code, SQLITE3_TEXT);
$stmt->bindValue(':max_uses', $maxUses, SQLITE3_INTEGER);
$stmt->bindValue(':created_by', $userId, SQLITE3_INTEGER);

if ($stmt->execute()) {
    die(json_encode([
        'success' => true,
        'message' => translate('invite_code_created', $i18n),
        'code' => $code
    ]));
}

die(json_encode([
    'success' => false,
    'message' => translate('error', $i18n)
]));
