<?php
require_once __DIR__ . '/../includes/subscription_relations.php';
$db = new SQLite3(':memory:');
$db->enableExceptions(true);
$db->exec(file_get_contents(__DIR__ . '/fixtures/agent_schema.sql'));
$db->exec("INSERT INTO user(id,username,email,password,main_currency) VALUES(1,'one','one@example.invalid','none',10),(2,'two','two@example.invalid','none',20)");
$db->exec("INSERT INTO household(id,name,user_id) VALUES(10,'First',1),(11,'Second',1),(12,'Third',1),(20,'Other',2)");
$db->exec("INSERT INTO categories(id,name,user_id) VALUES(10,'First',1),(11,'Second',1),(12,'Third',1),(20,'Other',2)");
$db->exec("INSERT INTO subscriptions(id,name,price,payer_user_id,category_id,user_id) VALUES(1,'Existing',120,10,10,1)");
require __DIR__ . '/../migrations/000084.php';
require __DIR__ . '/../migrations/000084.php';
$checks=0;
function relationsCheck($test,$message) { global $checks; if (!$test) throw new RuntimeException($message); echo '[OK] '.$message."\n"; $checks++; }
function relationsReject($fn,$message) { try {$fn();} catch (InvalidArgumentException $e) { relationsCheck(true,$message); return; } throw new RuntimeException($message); }
$row = fn() => $db->querySingle('SELECT * FROM subscriptions WHERE id=1',true);
relationsCheck(wallos_subscription_relation_ids($db,$row(),'payer') === [10], 'migration backfills primary payer idempotently');
relationsCheck(wallos_subscription_relation_ids($db,$row(),'category') === [10], 'migration backfills primary category idempotently');
$db->exec('BEGIN');
wallos_save_subscription_relations($db,1,[11,10],[11,10]);
$db->exec('COMMIT');
relationsCheck((int)$row()['payer_user_id']===11 && (int)$row()['category_id']===11, 'explicit lists update scalar primaries');
relationsCheck(wallos_subscription_relation_ids($db,$row(),'payer') === [11,10], 'complete ordered payer list retained');
relationsCheck(wallos_subscription_relation_input($db,1,'payer',['name'=>'new'],$row())===[11,10], 'omitted lists preserve secondary assignments');
relationsCheck(wallos_subscription_relation_input($db,1,'payer',['payer_user_id'=>12],$row())===[12,11,10], 'legacy scalar promotes primary without dropping members');
relationsCheck(wallos_subscription_relation_input($db,1,'category',['category_ids'=>[12,10]],$row())===[12,10], 'explicit array replaces complete list');
relationsReject(fn()=>wallos_subscription_relation_input($db,1,'category',['category_ids'=>[12,10],'category_id'=>10],$row()),'ambiguous primary rejected');
foreach ([[20],[],[true],[1.0],['1e1'],['10.0'],['10x'],['9999999999999999999999999999999999999999']] as $bad) {
 relationsReject(fn()=>wallos_subscription_relation_validate($db,1,'payer',$bad),'invalid or foreign reference rejected '.json_encode($bad));
}
$before = $db->querySingle('SELECT COUNT(*) FROM subscription_payers');
relationsReject(fn()=>wallos_save_subscription_relations($db,1,[10],[20]),'save validates all references before mutation');
relationsCheck($db->querySingle('SELECT COUNT(*) FROM subscription_payers')===$before,'invalid save preserves existing relations');
$db->exec('UPDATE subscriptions SET payer_user_id=12 WHERE id=1');
relationsCheck(wallos_subscription_relation_ids($db,$row(),'payer')===[12,11,10],'legacy database update preserves complete associations');
$db->exec("INSERT INTO subscriptions(id,name,price,payer_user_id,category_id,user_id) VALUES(2,'New',50,10,10,1)");
relationsCheck($db->querySingle('SELECT COUNT(*) FROM subscription_payers WHERE subscription_id=2')===1,'legacy insert establishes its primary association');
$db->exec('PRAGMA foreign_keys=ON');
try {$db->exec('DELETE FROM household WHERE id=11'); throw new RuntimeException('in-use secondary reference was deleted');} catch (Exception $e) {relationsCheck(str_contains($e->getMessage(),'constraint'),'secondary member deletion protected by foreign key');}
$db->exec('UPDATE subscriptions SET lifecycle_status="trashed" WHERE id=1');
relationsCheck(count(wallos_subscription_relation_ids($db,$row(),'payer'))===3,'recycle bin retains relationships');
$db->exec('UPDATE subscriptions SET lifecycle_status="active" WHERE id=1');
relationsCheck(count(wallos_subscription_relation_ids($db,$row(),'category'))===2,'restore retains relationships');
$db->exec('PRAGMA foreign_keys=OFF');
$db->exec('DELETE FROM subscriptions WHERE id=1');
relationsCheck($db->querySingle('SELECT COUNT(*) FROM subscription_payers WHERE subscription_id=1')===0 && $db->querySingle('SELECT COUNT(*) FROM subscription_categories WHERE subscription_id=1')===0,'permanent deletion cleans relations even with FK disabled');
echo "$checks subscription relation checks passed.\n";
