<?php
// Extends only the disposable agent fixture. Never accepts a production account.
if (PHP_SAPI !== 'cli' || getenv('WALLOS_AGENT_TEST_FIXTURE') !== '1') exit(1);
require '/var/www/html/includes/connect.php';
require_once '/var/www/html/libs/csrf.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
if ($db->querySingle('SELECT username FROM user WHERE id=1') !== 'agent-ui-test'
    || (int)$db->querySingle('SELECT COUNT(*) FROM user') !== 1) {
    throw new RuntimeException('Requires a fresh disposable agent fixture.');
}
$db->enableExceptions(true);
$password = bin2hex(random_bytes(16));
$stmt = $db->prepare("UPDATE user SET password=:password, firstname='Fixture', avatar='images/avatars/0.svg', email='fixture@example.invalid' WHERE id=1");
$stmt->bindValue(':password',password_hash($password,PASSWORD_DEFAULT),SQLITE3_TEXT);$stmt->execute();
$db->exec("UPDATE settings SET dark_theme=2 WHERE user_id=1");
$db->exec("UPDATE currencies SET code='EUR',symbol='€',name='Euro' WHERE id=100 AND user_id=1");
$db->exec("INSERT INTO currencies(id,name,symbol,code,rate,user_id) VALUES(101,'US Dollar','$','USD',1.1,1),(102,'Yuan','¥','CNY',8,1),(200,'Foreign pound','£','GBP',1,2)");
$db->exec("INSERT INTO user(id,username,email,password,main_currency,language) VALUES(2,'upstream-test-2','other@example.invalid','unusable',200,'en')");
$db->exec('INSERT INTO settings(user_id) VALUES(2)');
$db->exec("INSERT INTO categories(id,name,user_id) VALUES(200,'Foreign category',2)");
$db->exec("INSERT INTO household(id,name,user_id) VALUES(200,'Foreign member',2)");
$db->exec("INSERT INTO payment_methods(id,name,icon,user_id) VALUES(200,'Foreign cash','images/uploads/icons/banktransfer.png',2)");
$credentials = ['username'=>'agent-ui-test','password'=>$password,'sessions'=>[]];
foreach ([1=>'wallos-upstream-owner',2=>'wallos-upstream-other'] as $id=>$session) {
    session_id($session);session_start();
    $_SESSION=['loggedin'=>true,'userId'=>$id,'username'=>$id===1?'agent-ui-test':'upstream-test-2','main_currency'=>$id===1?100:200];
    $credentials['sessions'][(string)$id]=['id'=>$session,'csrf'=>generate_csrf_token()];
    session_write_close();
}
file_put_contents('/var/www/html/db/upstream-fixture.json',json_encode($credentials));
chmod('/var/www/html/db/upstream-fixture.json',0600);
echo "Extended disposable fixture ready. Credentials remain private.\n";
