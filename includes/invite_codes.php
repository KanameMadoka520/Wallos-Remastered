<?php

function wallos_generate_invite_code($length = 12)
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $maxIndex = strlen($alphabet) - 1;
    $output = '';

    for ($i = 0; $i < $length; $i++) {
        $output .= $alphabet[random_int(0, $maxIndex)];
    }

    return $output;
}

function wallos_get_invite_only_registration_enabled($db)
{
    $value = $db->querySingle('SELECT invite_only_registration FROM admin WHERE id = 1');
    return (int) $value === 1;
}

function wallos_find_available_invite_code($db, $code)
{
    $stmt = $db->prepare('
        SELECT *
        FROM invite_codes
        WHERE code = :code
          AND deleted = 0
          AND uses_count < max_uses
        LIMIT 1
    ');
    $stmt->bindValue(':code', strtoupper(trim((string) $code)), SQLITE3_TEXT);
    $result = $stmt->execute();
    return $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
}

function wallos_consume_invite_code($db, $inviteCodeId, $userId, $username, $email)
{
    $updateStmt = $db->prepare('
        UPDATE invite_codes
        SET uses_count = uses_count + 1
        WHERE id = :id
          AND deleted = 0
          AND uses_count < max_uses
    ');
    $updateStmt->bindValue(':id', $inviteCodeId, SQLITE3_INTEGER);
    $updateStmt->execute();

    if ($db->changes() < 1) {
        return false;
    }

    $insertStmt = $db->prepare('
        INSERT INTO invite_code_usages (
            invite_code_id, used_by_user_id, used_by_username, used_by_email
        ) VALUES (
            :invite_code_id, :used_by_user_id, :used_by_username, :used_by_email
        )
    ');
    $insertStmt->bindValue(':invite_code_id', $inviteCodeId, SQLITE3_INTEGER);
    $insertStmt->bindValue(':used_by_user_id', $userId, SQLITE3_INTEGER);
    $insertStmt->bindValue(':used_by_username', $username, SQLITE3_TEXT);
    $insertStmt->bindValue(':used_by_email', $email, SQLITE3_TEXT);
    $insertStmt->execute();

    return true;
}
