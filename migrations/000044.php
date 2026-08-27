<?php

/* * This migration creates the uploaded_avatars table to isolate custom avatars
 * by user_id, preventing IDOR deletion vulnerabilities. It also migrates existing
 * avatars based on whether the instance is single-tenant or multi-tenant.
 */

$db->exec("
    CREATE TABLE IF NOT EXISTS uploaded_avatars (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        path TEXT NOT NULL
    )
");

$insertAvatar = $db->prepare(
    'INSERT INTO uploaded_avatars (user_id, path)
     SELECT :user_id, :path
     WHERE NOT EXISTS (
         SELECT 1 FROM uploaded_avatars WHERE user_id = :existing_user_id AND path = :existing_path
     )'
);
$recordAvatar = static function ($userId, $path) use ($insertAvatar) {
    $insertAvatar->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    $insertAvatar->bindValue(':path', (string) $path, SQLITE3_TEXT);
    $insertAvatar->bindValue(':existing_user_id', (int) $userId, SQLITE3_INTEGER);
    $insertAvatar->bindValue(':existing_path', (string) $path, SQLITE3_TEXT);
    $insertAvatar->execute();
};

$userCount = (int) $db->querySingle("SELECT COUNT(*) FROM user");
if ($userCount === 1) {
    $userId = (int) $db->querySingle("SELECT id FROM user LIMIT 1");
    $avatarRoot = isset($wallosRestoreLogosDirectory) && is_string($wallosRestoreLogosDirectory)
        ? rtrim($wallosRestoreLogosDirectory, '/\\')
        : __DIR__ . '/../images/uploads/logos';
    $avatarDir = $avatarRoot . '/avatars';

    if (is_dir($avatarDir)) {
        $files = scandir($avatarDir);
        if ($files === false) {
            throw new RuntimeException('Unable to scan the uploaded avatar directory.');
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || !is_file($avatarDir . '/' . $file)) {
                continue;
            }
            $recordAvatar($userId, 'images/uploads/logos/avatars/' . $file);
        }
    }
} elseif ($userCount > 1) {
    $results = $db->query("SELECT id, avatar FROM user");
    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        $avatarPath = (string) ($row['avatar'] ?? '');
        if (strpos($avatarPath, 'images/uploads/logos/avatars/') === 0) {
            $recordAvatar((int) $row['id'], $avatarPath);
        }
    }
}

?>
