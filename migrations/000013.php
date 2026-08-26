<?php

/**
 * This migration script updates the avatar field of the user table to use the new avatar path.
 */

$sql = "SELECT id, avatar FROM user";
$stmt = $db->prepare($sql);
$result = $stmt->execute();

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $avatar = (string) ($row['avatar'] ?? '');
    if (!preg_match('/^[0-9]$/', $avatar)) {
        continue;
    }

    $avatarFullPath = "images/avatars/" . $avatar . ".svg";
    $updateStmt = $db->prepare('UPDATE user SET avatar = :avatar_path WHERE id = :id AND avatar = :legacy_avatar');
    $updateStmt->bindValue(':avatar_path', $avatarFullPath, SQLITE3_TEXT);
    $updateStmt->bindValue(':id', (int) $row['id'], SQLITE3_INTEGER);
    $updateStmt->bindValue(':legacy_avatar', $avatar, SQLITE3_TEXT);
    $updateStmt->execute();
}

?>
