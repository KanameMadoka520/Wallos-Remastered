<?php
require_once __DIR__ . '/../includes/ai_client.php';
require_once __DIR__ . '/../includes/ai_category_translation.php';

$checks = 0;
function category_check($ok, $label) {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
function category_rejects($call, $code, $label) {
    try { $call(); } catch (Throwable $e) {
        category_check($code === null || $e->getMessage() === $code, $label . ': ' . $e->getMessage());
        return;
    }
    throw new RuntimeException($label . ': accepted');
}

$db = new SQLite3(':memory:');
$db->enableExceptions(true);
$db->exec('CREATE TABLE user(id INTEGER PRIMARY KEY, language TEXT);
    CREATE TABLE categories(id INTEGER PRIMARY KEY, name TEXT, "order" INTEGER DEFAULT 1, user_id INTEGER);
    CREATE TABLE settings(user_id INTEGER PRIMARY KEY, screenshot_privacy_mode INTEGER DEFAULT 0);
    CREATE TABLE ai_settings(id INTEGER PRIMARY KEY, user_id INTEGER, enabled INTEGER, type TEXT, model TEXT, api_key TEXT, url TEXT, run_schedule INTEGER);
    INSERT INTO user VALUES(1,"zh_cn"),(2,"en");
    INSERT INTO settings(user_id) VALUES(1),(2);
    INSERT INTO categories(id,name,user_id) VALUES(1,"No category",1),(2,"Cloud &amp; Hosting",1),(3,"Entertainment",1),(200,"Foreign category",2);
    INSERT INTO ai_settings VALUES(1,1,1,"ollama","fixture-model","fixture-secret","http://example.invalid",0);');
$state = wallos_ai_category_state($db, 1);
category_check(count($state['categories']) === 2, 'Only owned, non-reserved category IDs are sent');
category_check($state['categories'][0]['name'] === 'Cloud & Hosting', 'Stored text is decoded exactly once');
$prompt = wallos_ai_category_prompt($state);
category_check(str_contains($prompt, 'zh_cn') && str_contains($prompt, 'Cloud & Hosting'), 'Prompt contains target language and category text');
category_check(!str_contains($prompt, 'fixture-secret') && !str_contains($prompt, 'Foreign category') && !str_contains($prompt, 'fixture-model'), 'Prompt omits credentials, model configuration and other accounts');
$valid = [['id' => 3, 'name' => '娱乐'], ['id' => 2, 'name' => '云服务 & 托管']];
$translations = wallos_ai_category_validate_translations($valid, $state['categories']);
category_check(array_column($translations, 'id') === [2, 3], 'Exact ID set is normalized into stable order');
$invalid = [null, [], ['items' => $valid], [reset($valid)], [...$valid, ['id'=>200,'name'=>'其他']],
    [['id'=>3,'name'=>'娱乐'],['id'=>3,'name'=>'重复']],
    [['id'=>'2','name'=>'云'],['id'=>3,'name'=>'娱乐']],
    [['id'=>2,'name'=>''],['id'=>3,'name'=>'娱乐']],
    [['id'=>2,'name'=>"\u{00a0}"],['id'=>3,'name'=>'娱乐']],
    [['id'=>2,'name'=>"x\ntext"],['id'=>3,'name'=>'娱乐']],
    [['id'=>2,'name'=>str_repeat('云',101)],['id'=>3,'name'=>'娱乐']],
    [['id'=>2,'name'=>['oops']],['id'=>3,'name'=>'娱乐']],
    [['id'=>2,'name'=>"\xff"],['id'=>3,'name'=>'娱乐']],
    [['id'=>2,'name'=>'云','action'=>'delete'],['id'=>3,'name'=>'娱乐']],
    [['id'=>1,'name'=>'保留'],['id'=>3,'name'=>'娱乐']],
    [['id'=>200,'name'=>'跨账户'],['id'=>3,'name'=>'娱乐']]];
foreach ($invalid as $index => $value) category_rejects(fn()=>wallos_ai_category_validate_translations($value,$state['categories']), 'invalid_response', 'Reject invalid result ' . $index);
$preview = wallos_ai_category_make_preview($state,$translations,1,1000);
category_check($preview['expires_at'] === 1600 && strlen($preview['token']) === 64, 'Preview is time-limited with an unguessable session token');
foreach ([['',1,1001],[$preview['token'],2,1001],[$preview['token'],1,1600]] as [$token,$uid,$time]) {
    category_rejects(fn()=>wallos_ai_category_apply_preview($db,$uid,$preview,$token,$time), 'preview_expired', 'Reject invalid token, owner or expiry');
}
category_check($db->querySingle('SELECT name FROM categories WHERE id=2') === 'Cloud &amp; Hosting', 'Preview and invalid apply leave names unchanged');

// Every kind of concurrent account change invalidates the complete preview.
foreach ([
    ['UPDATE categories SET name="Renamed" WHERE id=2','UPDATE categories SET name="Cloud &amp; Hosting" WHERE id=2'],
    ['UPDATE categories SET "order"=9 WHERE id=2','UPDATE categories SET "order"=1 WHERE id=2'],
    ['INSERT INTO categories(id,name,user_id) VALUES(4,"Added",1)','DELETE FROM categories WHERE id=4'],
    ['DELETE FROM categories WHERE id=3','INSERT INTO categories(id,name,user_id) VALUES(3,"Entertainment",1)'],
    ['UPDATE user SET language="en" WHERE id=1','UPDATE user SET language="zh_cn" WHERE id=1'],
    ['UPDATE ai_settings SET model="different" WHERE user_id=1','UPDATE ai_settings SET model="fixture-model" WHERE user_id=1'],
    ['UPDATE ai_settings SET api_key="rotated" WHERE user_id=1','UPDATE ai_settings SET api_key="fixture-secret" WHERE user_id=1'],
    ['UPDATE ai_settings SET enabled=0 WHERE user_id=1','UPDATE ai_settings SET enabled=1 WHERE user_id=1'],
] as [$change,$restore]) {
    $db->exec($change);
    category_rejects(fn()=>wallos_ai_category_apply_preview($db,1,$preview,$preview['token'],1001),'data_changed','Concurrent data/configuration change rejected');
    $db->exec($restore);
}
$db->exec('UPDATE settings SET screenshot_privacy_mode=1 WHERE user_id=1');
category_rejects(fn()=>wallos_ai_category_apply_preview($db,1,$preview,$preview['token'],1001),'screenshot_privacy_mode_blocked','Screenshot mode blocks apply');
$db->exec('UPDATE settings SET screenshot_privacy_mode=0 WHERE user_id=1');
$db->exec('CREATE TRIGGER fail_second_translation BEFORE UPDATE ON categories WHEN NEW.id=3 BEGIN SELECT RAISE(ABORT,"fixture-write-failed"); END');
category_rejects(fn()=>wallos_ai_category_apply_preview($db,1,$preview,$preview['token'],1001),null,'A later write failure rejects the complete apply');
category_check($db->querySingle('SELECT name FROM categories WHERE id=2') === 'Cloud &amp; Hosting', 'Transaction rolls back earlier category update');
$db->exec('DROP TRIGGER fail_second_translation');
$snapshot = wallos_ai_category_apply_preview($db,1,$preview,$preview['token'],1001);
category_check(wallos_decode_stored_text($db->querySingle('SELECT name FROM categories WHERE id=2')) === '云服务 & 托管', 'Apply preserves escaped-storage convention');
category_check($db->querySingle('SELECT name FROM categories WHERE id=200') === 'Foreign category', 'Other account category remains unchanged');
category_check($db->querySingle('SELECT name FROM categories WHERE id=1') === 'No category', 'Reserved category remains unchanged');
category_check((int)$db->querySingle('SELECT COUNT(*) FROM categories') === 4, 'Apply creates and deletes no categories');
$preview['applied_snapshot']=$snapshot;
category_check(wallos_ai_category_apply_preview($db,1,$preview,$preview['token'],1002) === $snapshot, 'Lost response retry is idempotent');
$db->exec('UPDATE categories SET name="Later rename" WHERE id=2');
category_rejects(fn()=>wallos_ai_category_apply_preview($db,1,$preview,$preview['token'],1003),'data_changed','Replayed apply cannot overwrite a later rename');

$state=wallos_ai_category_state($db,1);
$literal='<img src=x onerror="alert(1)"> & \\cloud';
$translations=wallos_ai_category_validate_translations([['id'=>2,'name'=>$literal],['id'=>3,'name'=>'Text &amp; literal']],$state['categories']);
$preview=wallos_ai_category_make_preview($state,$translations,1);
wallos_ai_category_apply_preview($db,1,$preview,$preview['token']);
category_check(wallos_decode_stored_text($db->querySingle('SELECT name FROM categories WHERE id=2')) === $literal, 'HTML-looking text and backslashes round-trip as literal text');
category_check(!str_contains($db->querySingle('SELECT name FROM categories WHERE id=2'),'<img'), 'HTML-looking result is escaped in storage');
category_check(wallos_decode_stored_text($db->querySingle('SELECT name FROM categories WHERE id=3')) === 'Text &amp; literal', 'Literal entities are decoded only once');
echo "PASS: {$checks} category translation checks.\n";
