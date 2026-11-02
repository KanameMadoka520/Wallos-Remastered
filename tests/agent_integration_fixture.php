<?php
// Only seeds a brand new, disposable Wallos database. Never enable in production.
if (PHP_SAPI !== 'cli' || getenv('WALLOS_AGENT_TEST_FIXTURE') !== '1') {
    http_response_code(404);
    exit(1);
}
$root = getenv('WALLOS_AGENT_TEST_APP_ROOT') ?: '/var/www/html';
require $root . '/includes/connect.php';
require $root . '/includes/agent_auth.php';
$db->enableExceptions(true);
if ((int) $db->querySingle('SELECT COUNT(*) FROM user') !== 0) {
    throw new RuntimeException('Fixture requires a fresh, empty database; refusing to touch existing accounts.');
}
$db->exec('BEGIN IMMEDIATE');
try {
    $db->exec("INSERT INTO currencies(id,name,symbol,code,rate,user_id) VALUES(100,'Fixture currency','$','XTS','1',1)");
    $db->exec("INSERT INTO user(id,username,email,password,main_currency) VALUES(1,'agent-ui-test','fixture@example.invalid','unusable-test-password',100)");
    $db->exec('INSERT INTO settings(user_id) VALUES(1)');
    $db->exec("INSERT INTO categories(id,name,user_id) VALUES(100,'Fixture category',1)");
    $db->exec("INSERT INTO household(id,name,user_id) VALUES(100,'Fixture member',1)");
    $db->exec("INSERT INTO payment_methods(id,name,icon,user_id) VALUES(100,'Fixture cash','images/uploads/icons/banktransfer.png',1)");
    $key = wallos_agent_create_key($db, 1, 'nginx-protocol-test', wallos_agent_scopes());
    $db->exec('COMMIT');
} catch (Throwable $e) {
    $db->exec('ROLLBACK');
    throw $e;
}
$keyFile = $root . '/db/test-key.json';
file_put_contents($keyFile, json_encode($key));
chmod($keyFile, 0600);
session_id('wallos-agent-ui-fixture');
session_start();
$_SESSION = ['loggedin' => true, 'userId' => 1, 'username' => 'agent-ui-test', 'main_currency' => 100];
session_write_close();
echo "Disposable fixture ready. Test credential was written privately to db/test-key.json.\n";
