<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';

function wallos_generate_temporary_password($length = 16)
{
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $charactersLength = strlen($characters);
    $password = '';

    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[random_int(0, $charactersLength - 1)];
    }

    return $password;
}

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);
$targetUserId = (int) ($data['userId'] ?? 0);

if ($targetUserId <= 0) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n)
    ]));
}

$userLookup = $db->prepare('SELECT id, username FROM user WHERE id = :id');
$userLookup->bindValue(':id', $targetUserId, SQLITE3_INTEGER);
$userLookupResult = $userLookup->execute();
$targetUser = $userLookupResult ? $userLookupResult->fetchArray(SQLITE3_ASSOC) : false;

if ($targetUser === false) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n)
    ]));
}

$temporaryPassword = wallos_generate_temporary_password();
$hashedPassword = password_hash($temporaryPassword, PASSWORD_DEFAULT);

$db->exec('BEGIN IMMEDIATE');

try {
    $updatePasswordStmt = $db->prepare('UPDATE user SET password = :password WHERE id = :id');
    $updatePasswordStmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
    $updatePasswordStmt->bindValue(':id', $targetUserId, SQLITE3_INTEGER);
    $updatePasswordStmt->execute();

    $deleteLoginTokensStmt = $db->prepare('DELETE FROM login_tokens WHERE user_id = :id');
    $deleteLoginTokensStmt->bindValue(':id', $targetUserId, SQLITE3_INTEGER);
    $deleteLoginTokensStmt->execute();

    $passwordResetsExists = (bool) $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='password_resets'");
    if ($passwordResetsExists) {
        $deletePasswordResetsStmt = $db->prepare('DELETE FROM password_resets WHERE user_id = :id');
        $deletePasswordResetsStmt->bindValue(':id', $targetUserId, SQLITE3_INTEGER);
        $deletePasswordResetsStmt->execute();
    }

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
    'message' => translate('temporary_password_generated', $i18n),
    'username' => $targetUser['username'],
    'temporaryPassword' => $temporaryPassword
]));
