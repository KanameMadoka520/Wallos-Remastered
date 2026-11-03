<?php
// Entirely synthetic pre-feature backup, real migrations 84..86, temporary files.
require_once __DIR__.'/../includes/backup_manager.php';
$checks=0;
function feature_restore_check($ok,$label) { global $checks; if(!$ok)throw new RuntimeException($label);$checks++; }
function feature_restore_rejects($callback,$message) { try{$callback();}catch(RuntimeException $e){return str_contains($e->getMessage(),$message);}return false; }
$root=sys_get_temp_dir().'/wallos-feature-restore-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/migrations',0700);
try {
    for($number=1;$number<=83;$number++)file_put_contents(sprintf('%s/migrations/%06d.php',$root,$number),"<?php // Already applied to this synthetic old backup.\n");
    for($number=84;$number<=86;$number++)copy(sprintf('%s/../migrations/%06d.php',__DIR__,$number),sprintf('%s/migrations/%06d.php',$root,$number));
    $file=$root.'/wallos.db';$db=new SQLite3($file);$db->enableExceptions(true);
    $db->exec("CREATE TABLE migrations(id INTEGER PRIMARY KEY,migration TEXT NOT NULL,migrated_at TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE user(id INTEGER PRIMARY KEY,period_budget REAL,budget_period_type TEXT,budget_period_anchor_date TEXT);
        INSERT INTO user VALUES(3,100,'monthly','');
        CREATE TABLE admin(id INTEGER PRIMARY KEY);
        CREATE TABLE subscriptions(id INTEGER PRIMARY KEY,user_id INTEGER,payer_user_id INTEGER,category_id INTEGER,inactive INTEGER,next_payment TEXT,notify INTEGER,logo_text_color TEXT,logo_variant TEXT);
        INSERT INTO subscriptions VALUES(42,3,5,6,0,'2026-12-01',1,'','');
        CREATE TABLE household(id INTEGER PRIMARY KEY,user_id INTEGER);INSERT INTO household VALUES(5,3);
        CREATE TABLE categories(id INTEGER PRIMARY KEY,user_id INTEGER);INSERT INTO categories VALUES(6,3);
        CREATE TABLE currencies(id INTEGER PRIMARY KEY);
        CREATE TABLE settings(id INTEGER PRIMARY KEY,week_starts_sunday INTEGER,screenshot_privacy_mode INTEGER);
        CREATE TABLE notification_settings(id INTEGER PRIMARY KEY,period_summary_at_period_start INTEGER);
        CREATE TABLE cycles(id INTEGER PRIMARY KEY,days INTEGER,name TEXT);INSERT INTO cycles VALUES(5,0,'One-time');
        CREATE INDEX idx_subscriptions_user_inactive_next_payment ON subscriptions(user_id,inactive,next_payment);
        CREATE INDEX idx_subscriptions_user_notify_inactive ON subscriptions(user_id,notify,inactive);
        CREATE TABLE subscription_payment_records(id INTEGER PRIMARY KEY,subscription_id INTEGER,amount REAL,currency_code TEXT);
        INSERT INTO subscription_payment_records VALUES(7,42,10.25,'CNY');");
    for($number=1;$number<=83;$number++)$db->exec("INSERT INTO migrations(migration) VALUES('migrations/".sprintf('%06d.php',$number)."')");
    $before=$db->querySingle('SELECT * FROM subscriptions WHERE id=42',true);$payment=$db->querySingle('SELECT * FROM subscription_payment_records WHERE id=7',true);$db->close();
    wallos_run_migrations_after_restore($root,$file);
    wallos_run_migrations_after_restore($root,$file);
    $db=new SQLite3($file);$db->enableExceptions(true);$catalog=wallos_restore_migration_catalog($root);
    feature_restore_check(count($catalog)===86,'full synthetic inventory');
    feature_restore_check((int)$db->querySingle('SELECT COUNT(*) FROM migrations')===86,'83-to-86 restore and repeated restore are idempotent');
    feature_restore_check($before===$db->querySingle('SELECT * FROM subscriptions WHERE id=42',true),'subscription scalar data preserved');
    feature_restore_check($payment===$db->querySingle('SELECT * FROM subscription_payment_records WHERE id=7',true),'historic actual payment preserved');
    feature_restore_check((int)$db->querySingle('SELECT payer_id FROM subscription_payers WHERE subscription_id=42')===5,'legacy payer backfill');
    feature_restore_check((int)$db->querySingle('SELECT category_id FROM subscription_categories WHERE subscription_id=42')===6,'legacy category backfill');
    feature_restore_check((int)$db->querySingle('SELECT COUNT(*) FROM push_vapid')===0,'restoring old backup does not generate/change push credentials');
    feature_restore_check($db->querySingle('PRAGMA integrity_check')==='ok','migrated restored DB integrity');
    foreach(['subscription_payers','subscription_categories','push_vapid','push_notifications','push_subscriptions','push_delivery_claims','subscription_bulk_requests'] as $table) {
        $db->exec('SAVEPOINT corrupt_feature');$db->exec('DROP TABLE '.$table);
        feature_restore_check(feature_restore_rejects(fn()=>wallos_restore_assert_latest_schema($db,$catalog),'missing required table: '.$table),'reject completed marker with missing '.$table);
        $db->exec('ROLLBACK TO corrupt_feature');$db->exec('RELEASE corrupt_feature');
    }
    foreach(['subscription_payers'=>'position','push_notifications'=>'show_details','push_subscriptions'=>'device_hash','push_delivery_claims'=>'attempts','subscription_bulk_requests'=>'request_hash'] as $table=>$column) {
        $db->exec('SAVEPOINT corrupt_feature');$db->exec("ALTER TABLE $table RENAME COLUMN $column TO renamed_feature_column");
        feature_restore_check(feature_restore_rejects(fn()=>wallos_restore_assert_latest_schema($db,$catalog),"missing required column: $table.$column"),'reject incomplete column '.$table.'.'.$column);
        $db->exec('ROLLBACK TO corrupt_feature');$db->exec('RELEASE corrupt_feature');
    }
    foreach(['idx_subscription_payers_reference','idx_subscription_categories_reference','idx_push_subscriptions_user'] as $index) {
        $db->exec('SAVEPOINT corrupt_feature');$db->exec('DROP INDEX '.$index);
        feature_restore_check(feature_restore_rejects(fn()=>wallos_restore_assert_latest_schema($db,$catalog),'invalid index: '.$index),'reject missing feature index '.$index);
        $db->exec('ROLLBACK TO corrupt_feature');$db->exec('RELEASE corrupt_feature');
    }
    // Check the complete restore path, not only its final assertion directly.
    $db->exec('DROP TABLE push_vapid');$db->close();
    feature_restore_check(feature_restore_rejects(fn()=>wallos_run_migrations_after_restore($root,$file),'missing required table: push_vapid'),'restore refuses corrupt completed feature ledger before promotion');
    echo "PASS: $checks feature restore checks.\n";
} finally { if(isset($db)&&$db instanceof SQLite3){try{$db->close();}catch(Throwable $e){}} wallos_delete_directory_tree($root); }
