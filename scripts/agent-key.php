<?php
// Management requires host/container access. This file is never an HTTP endpoint.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/agent_auth.php';
$db->enableExceptions(true);
$options = getopt('', ['user:', 'name:', 'scopes:', 'days:', 'revoke:']);
try {
    $uid = (int) ($options['user'] ?? 0);
    if (!$uid || !wallos_agent_sql($db, "SELECT id FROM user WHERE id=:uid AND account_status='active'", ['uid' => $uid])->fetchArray(SQLITE3_ASSOC)) {
        throw new RuntimeException('Specify an existing active account with --user.');
    }
    if (isset($options['revoke'])) {
        wallos_agent_sql($db, 'UPDATE agent_keys SET revoked_at=:now WHERE id=:id AND user_id=:uid', ['now' => time(), 'id' => (int) $options['revoke'], 'uid' => $uid]);
        echo "Key revoked.\n";
    } else {
        $result = wallos_agent_create_key($db, $uid, $options['name'] ?? '', explode(',', $options['scopes'] ?? ''), (int) ($options['days'] ?? 30));
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
