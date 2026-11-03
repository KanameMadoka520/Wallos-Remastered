<?php
require_once __DIR__ . '/../includes/ai_recommendations.php';
$checks = 0;
function expect_recommendation($condition, $description) { global $checks; if (!$condition) throw new RuntimeException($description); $checks++; }
function recommendation_rows($db, $uid) { return ai_account_rows($db, 'SELECT * FROM ai_recommendations WHERE user_id=:uid ORDER BY id', $uid); }
$db = new SQLite3(':memory:');
$db->enableExceptions(true);
$db->exec(file_get_contents(__DIR__ . '/fixtures/agent_schema.sql'));
$db->exec("CREATE TABLE ai_settings(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,type TEXT,enabled INTEGER,api_key TEXT,model TEXT,url TEXT,run_schedule TEXT,last_successful_run TEXT)");
$db->exec("CREATE TABLE ai_recommendations(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,type TEXT,title TEXT,description TEXT,savings TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE subscription_payers(subscription_id INTEGER,payer_id INTEGER,position INTEGER)");
$db->exec("CREATE TABLE subscription_categories(subscription_id INTEGER,category_id INTEGER,position INTEGER)");
$db->exec("INSERT INTO user(id,username,email,password,main_currency,account_status,language) VALUES(1,'a','a@example.invalid','hash',1,'active','en'),(2,'b','b@example.invalid','hash',2,'active','zh_cn')");
$db->exec("INSERT INTO currencies(id,name,symbol,code,rate,user_id) VALUES(1,'Dollar','$','USD',1,1),(2,'Euro','€','EUR',1,2)");
$db->exec("INSERT INTO household(id,name,user_id) VALUES(1,'A &amp; B',1),(2,'Shared',1),(3,'OTHER OWNER',2)");
$db->exec("INSERT INTO categories(id,name,user_id) VALUES(1,'Cloud &amp; Storage',1),(2,'Work',1),(3,'PRIVATE CATEGORY',2)");
$db->exec("INSERT INTO subscriptions(id,name,price,currency_id,cycle,frequency,payer_user_id,category_id,user_id,next_payment,start_date,notes) VALUES(1,'Service &amp; Tool',12,1,3,1,1,1,1,'2026-12-01','2026-01-01','PRIVATE NOTE'),(2,'Other account',99,2,3,1,3,3,2,'2026-12-01','2026-01-01','PRIVATE')");
$db->exec("INSERT INTO subscriptions(id,name,price,user_id,inactive,lifecycle_status,exclude_from_stats) VALUES(3,'INACTIVE',1,1,1,'active',0),(4,'TRASHED',1,1,0,'trashed',0),(5,'EXCLUDED',1,1,0,'active',1)");
$db->exec("INSERT INTO subscription_payers VALUES(1,1,0),(1,2,1),(1,3,2)");
$db->exec("INSERT INTO subscription_categories VALUES(1,1,0),(1,2,1),(1,3,2)");
$db->exec("INSERT INTO subscription_price_rules(subscription_id,user_id,rule_type,price,currency_id,start_date,end_date,enabled) VALUES(1,1,'date_range',6,1,'2026-11-01','2026-12-31',1)");
$db->exec("INSERT INTO subscription_payment_records(subscription_id,user_id,due_date,paid_at,amount_original,currency_id,currency_code_snapshot,main_currency_code_snapshot,fx_rate_to_main_snapshot,amount_main_snapshot) VALUES(1,1,'2026-10-01','2026-10-01',5,1,'OLD','USD',2,2.5)");
$db->exec("INSERT INTO ai_settings(user_id,type,enabled,api_key,model,url,run_schedule) VALUES(1,'chatgpt',1,'fixture-secret','test','','weekly'),(2,'chatgpt',1,'other-secret','test','','monthly')");
$db->exec("INSERT INTO ai_recommendations(user_id,type,title,description,savings) VALUES(1,'subscription','Existing','Keep me','1 USD'),(2,'subscription','Other account recommendation','Keep private','2 EUR')");
$snapshot = ai_recommendation_snapshot($db, 1);
expect_recommendation(count($snapshot['subscriptions']) === 1, 'Only owned active, enabled, included subscriptions');
$sub = $snapshot['subscriptions'][0];
expect_recommendation($sub['name'] === 'Service & Tool', 'Decode escaped storage once');
expect_recommendation($sub['payers'] === ['A & B', 'Shared'], 'All owned payers');
expect_recommendation($sub['categories'] === ['Cloud & Storage', 'Work'], 'All owned categories');
expect_recommendation($sub['base_price']['amount'] === 12.0 && $sub['next_payment_price']['amount'] === 6.0, 'Base versus effective promotion price');
expect_recommendation(!array_key_exists('recent_recorded_payments', $sub), 'Ledger records remain local and are not shared with the provider');
$prompt = ai_recommendation_prompt($snapshot);
foreach (['PRIVATE NOTE', 'PRIVATE CATEGORY', 'OTHER OWNER', 'TRASHED', 'EXCLUDED', 'Other account', 'fixture-secret', 'other-secret', '"OLD"', '"amount":5', 'recent_recorded_payments'] as $private) expect_recommendation(!str_contains($prompt, $private), 'Private and excluded data omitted');
expect_recommendation(str_contains(ai_describe_frequency(5, 1), 'no recurring cost'), 'Lifetime is not recurring');
$valid = [['title' => 'Review overlap', 'description' => 'Check before renewing.', 'savings' => '1 USD/month']];
foreach ([[], ['title' => 'object'], [['title' => 12, 'description' => 'd']], [['title' => 't', 'description' => []]], [['title' => ' ', 'description' => 'd']], [['title' => 't', 'description' => 'd', 'extra' => true]], array_fill(0, 8, $valid[0]), [['title' => str_repeat('t', 181), 'description' => 'd']], [['title' => 't', 'description' => "bad\0value"]]] as $invalid) {
    expect_recommendation(ai_validate_recommendations($invalid) === null, 'Malformed recommendation rejected');
    expect_recommendation(!ai_replace_recommendations($db, 1, $snapshot, $invalid, [])['success'], 'Invalid output cannot replace rows');
}
expect_recommendation(recommendation_rows($db, 1)[0]['title'] === 'Existing', 'Invalid output preserves old rows');
$db->exec("UPDATE ai_settings SET model='changed' WHERE user_id=1");
expect_recommendation(ai_replace_recommendations($db, 1, $snapshot, $valid, [])['code'] === 'data_changed', 'Concurrent settings change');
$snapshot = ai_recommendation_snapshot($db, 1);
$db->exec("UPDATE subscriptions SET price=13 WHERE id=1");
expect_recommendation(ai_replace_recommendations($db, 1, $snapshot, $valid, [])['code'] === 'data_changed', 'Concurrent subscription change');
$snapshot = ai_recommendation_snapshot($db, 1);
$db->exec("UPDATE subscription_payers SET position=5 WHERE subscription_id=1 AND payer_id=2");
// Position changes which retain the same order do not alter meaning; membership changes must conflict.
$db->exec("DELETE FROM subscription_payers WHERE subscription_id=1 AND payer_id=2");
expect_recommendation(ai_replace_recommendations($db, 1, $snapshot, $valid, [])['code'] === 'data_changed', 'Concurrent relation change');
$snapshot = ai_recommendation_snapshot($db, 1);
$db->exec("UPDATE subscription_payment_records SET amount_original=6 WHERE subscription_id=1");
expect_recommendation(ai_replace_recommendations($db, 1, $snapshot, $valid, [])['code'] === 'data_changed', 'Concurrent ledger change');
$snapshot = ai_recommendation_snapshot($db, 1);
$db->exec("CREATE TRIGGER reject_ai_insert BEFORE INSERT ON ai_recommendations WHEN NEW.user_id=1 BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
expect_recommendation(!ai_replace_recommendations($db, 1, $snapshot, $valid, [])['success'], 'Write failure handled');
expect_recommendation(recommendation_rows($db, 1)[0]['title'] === 'Existing', 'Write failure rolls DELETE back');
$db->exec('DROP TRIGGER reject_ai_insert');
$snapshot = ai_recommendation_snapshot($db, 1);
expect_recommendation(ai_replace_recommendations($db, 1, $snapshot, $valid, [])['success'], 'Valid replacement');
expect_recommendation(recommendation_rows($db, 1)[0]['title'] === 'Review overlap', 'Replacement persisted');
expect_recommendation(recommendation_rows($db, 2)[0]['title'] === 'Other account recommendation', 'Other account unaffected');
expect_recommendation($db->querySingle('SELECT last_successful_run FROM ai_settings WHERE user_id=1') !== null, 'Successful manual run updates timestamp');
expect_recommendation(ai_replace_recommendations($db, 1, $snapshot, $valid, [])['code'] === 'data_changed', 'Concurrent/duplicate generation cannot overwrite newer recommendation set');
$snapshot = ai_recommendation_snapshot($db, 1);
$db->exec("UPDATE user SET account_status='trashed' WHERE id=1");
expect_recommendation(!ai_replace_recommendations($db, 1, $snapshot, $valid, [])['success'], 'Account trash prevents write');
echo "AI recommendations: $checks checks passed\n";
