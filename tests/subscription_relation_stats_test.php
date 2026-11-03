<?php
// Each scenario loads the actual statistics module in an isolated process/database.
if (!isset($argv[1])) {
    foreach (['all', 'member', 'category', 'intersection', 'unrelated-replacement'] as $scenario) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($scenario), $code);
        if ($code) exit($code);
    }
    echo "Passed five complete relation statistics scenarios.\n";
    exit;
}
require_once __DIR__ . '/../includes/subscription_relations.php';
function translate($key, $translations) { return $key; }
$db = new SQLite3(':memory:');
$db->enableExceptions(true);
$db->exec(file_get_contents(__DIR__ . '/fixtures/agent_schema.sql'));
require __DIR__ . '/../migrations/000084.php';
$db->exec('CREATE TABLE total_yearly_cost(user_id INTEGER,date TEXT,cost REAL)');
$db->exec("INSERT INTO currencies(id,name,symbol,code,rate,user_id) VALUES(100,'Test dollar','$','USD','1',1)");
$db->exec("INSERT INTO user(id,username,email,password,main_currency,budget,yearly_budget,period_budget) VALUES(1,'stats-fixture','fixture@example.invalid','invalid',100,1000,12000,1000)");
$db->exec("INSERT INTO categories(id,name,user_id) VALUES(100,'First category',1),(101,'Second category',1),(102,'Other category',1)");
$db->exec("INSERT INTO household(id,name,user_id) VALUES(100,'First payer',1),(101,'Second payer',1),(102,'Other payer',1)");
$db->exec("INSERT INTO payment_methods(id,name,user_id) VALUES(100,'Cash',1)");
$today = (new DateTime('today'))->format('Y-m-d');
$insert = $db->prepare("INSERT INTO subscriptions(id,name,price,currency_id,payment_method_id,payer_user_id,category_id,user_id,frequency,cycle,start_date,next_payment,inactive,replacement_subscription_id,auto_renew) VALUES(:id,:name,:price,100,100,100,100,1,1,3,:date,:date,:inactive,:replacement,1)");
foreach ([[1,'Old plan',120,1,2],[2,'Replacement',60,0,null],[3,'Second old plan',40,1,2]] as [$id,$name,$price,$inactive,$replacement]) {
    foreach (['id'=>$id,'name'=>$name,'price'=>$price,'inactive'=>$inactive,'replacement'=>$replacement,'date'=>$today] as $key=>$value) $insert->bindValue(':'.$key,$value,is_int($value)?SQLITE3_INTEGER:($value===null?SQLITE3_NULL:SQLITE3_TEXT));
    $insert->execute();
    wallos_save_subscription_relations($db,$id,[100,101],[100,101]);
}
if ($argv[1]==='unrelated-replacement') wallos_save_subscription_relations($db,2,[102],[102]);
$_GET = match($argv[1]) { 'member'=>['member'=>101], 'category'=>['category'=>101], 'intersection','unrelated-replacement'=>['member'=>101,'category'=>101], default=>[] };
$userId=1;
$userData=$db->querySingle('SELECT * FROM user WHERE id=1',true);
$currencies=[100=>$db->querySingle('SELECT * FROM currencies WHERE id=100',true)];
$i18n=[];
require __DIR__ . '/../includes/stats_calculations.php';
function statsClose($actual,$expected,$message) { if(abs((float)$actual-(float)$expected)>0.001) throw new RuntimeException($message.' expected '.$expected.' got '.$actual); }
$share = match($argv[1]) { 'member','category'=>0.5,'intersection','unrelated-replacement'=>0.25,default=>1.0 };
$unrelated=$argv[1]==='unrelated-replacement';
statsClose($totalSavingsPerMonth,160*$share-($unrelated?0:60*$share),'replacement cost must use the same intersection share and be subtracted once');
statsClose($totalCostPerMonth,$unrelated?0:60*$share,'active monthly cost uses one share');
statsClose($periodBudgetAmount,$unrelated?0:60*$share,'period budget projection uses one share');
foreach ($projectionDataPoints as $point) statsClose($point['y'],$unrelated?0:60*$share,'rolling forecast matches share');
statsClose($userData['budget'],1000,'account budget remains intact');
statsClose($userData['yearly_budget'],12000,'yearly budget remains intact');
statsClose($userData['period_budget'],1000,'period budget remains intact');
if(!$unrelated) {
    statsClose(array_sum(array_column($memberCost,'cost')),60*$share,'payer breakdown sums to filtered total');
    statsClose(array_sum(array_column($categoryCost,'cost')),60*$share,'category breakdown sums to filtered total');
}
echo '[OK] actual statistics module: '.$argv[1]."\n";
