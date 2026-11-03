<?php
require_once __DIR__ . '/../includes/agent_tools.php';
require_once __DIR__ . '/../includes/agent_bulk.php';

$db = new SQLite3(':memory:');
$db->enableExceptions(true);
$db->exec(file_get_contents(__DIR__ . '/fixtures/agent_schema.sql'));
require __DIR__ . '/../migrations/000083.php';
require __DIR__ . '/../migrations/000084.php';
require __DIR__ . '/../migrations/000086.php';
require __DIR__ . '/../migrations/000086.php';
$db->exec("INSERT INTO cycles VALUES(1,1,'day'),(2,7,'week'),(3,30,'month'),(4,365,'year'),(5,0,'one-time')");
$db->exec("INSERT INTO frequencies(id,name) VALUES(1,'1')");
foreach ([1, 2] as $uid) {
    $base = $uid * 100;
    $db->exec("INSERT INTO currencies(id,name,symbol,code,rate,user_id) VALUES($base,'Test dollar','$','USD','1',$uid)");
    $db->exec("INSERT INTO user(id,username,email,password,main_currency) VALUES($uid,'bulk-test-$uid','bulk$uid@example.invalid','invalid-password',$base)");
    $db->exec("INSERT INTO categories(id,name,user_id) VALUES($base,'Category',$uid)");
    $db->exec("INSERT INTO household(id,name,user_id) VALUES($base,'Member',$uid)");
    $db->exec("INSERT INTO payment_methods(id,name,user_id) VALUES($base,'Cash',$uid)");
    $db->exec("INSERT INTO settings(user_id) VALUES($uid)");
}
$db->exec('PRAGMA foreign_keys=ON');
$db->exec("INSERT INTO categories(id,name,user_id) VALUES(101,'Second category',1)");
$key = wallos_agent_authenticate($db, 'Bearer ' . wallos_agent_create_key($db, 1, 'bulk-test', wallos_agent_scopes())['token']);
$readonly = wallos_agent_authenticate($db, 'Bearer ' . wallos_agent_create_key($db, 1, 'read-only', ['subscriptions:read'])['token']);
$checks = 0;
function bulk_check($condition, $label)
{
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
    echo "[OK] $label\n";
}
function bulk_call($db, $key, $tool, array $args)
{
    return wallos_agent_call($db, $key, $tool, json_decode(json_encode((object) $args)));
}
function bulk_reject($code, callable $call, $label)
{
    try { $call(); } catch (WallosAgentError $e) {
        bulk_check($e->errorCode === $code, $label . ': ' . $e->errorCode);
        return;
    }
    throw new RuntimeException('Not rejected: ' . $label);
}
function bulk_targets($db, array $ids)
{
    return array_map(fn($id) => ['id' => $id, 'expected_version' => wallos_agent_present_subscription($db, 1, $id)['version']], $ids);
}
$base = ['price' => 12, 'currency_id' => 100, 'next_payment' => '2026-11-01', 'start_date' => '2026-10-01',
    'cycle' => 3, 'frequency' => 1, 'payment_method_id' => 100, 'payer_user_id' => 100, 'category_id' => 100];
$ids = [];
foreach (['one', 'two', 'three'] as $name) {
    $created = bulk_call($db, $key, 'wallos_subscriptions_create', ['data' => $base + ['name' => $name], 'request_id' => 'bulk-create-' . $name]);
    $ids[] = (int) $created['subscription']['id'];
}
$targets = bulk_targets($db, $ids);
$args = ['targets' => $targets, 'changes' => ['notify' => true], 'request_id' => 'bulk-preview-001', 'dry_run' => true];
$preview = bulk_call($db, $key, 'wallos_subscriptions_bulk_update', $args);
bulk_check($preview['count'] === 3 && $preview['atomic'] && $preview['dry_run'], 'MCP batch preview returns every target');
bulk_check((int) $db->querySingle('SELECT sum(notify) FROM subscriptions') === 0, 'preview rolls back all target writes');
bulk_check((int) $db->querySingle("SELECT count(*) FROM agent_requests WHERE request_id='bulk-preview-001'") === 0, 'preview does not reserve request IDs');
bulk_reject('forbidden', fn() => bulk_call($db, $readonly, 'wallos_subscriptions_bulk_update', $args), 'read-only key cannot write a batch');
$args['request_id'] = 'bulk-update-001';
$args['dry_run'] = false;
$result = bulk_call($db, $key, 'wallos_subscriptions_bulk_update', $args);
bulk_check((int) $db->querySingle('SELECT sum(notify) FROM subscriptions') === 3, 'MCP batch commits all targets');
bulk_check((int) $db->querySingle("SELECT count(*) FROM agent_audit WHERE tool='wallos_subscriptions_bulk_update' AND status='committed' AND target_id IS NOT NULL") === 3, 'committed batch has per-target audit metadata');
$replay = bulk_call($db, $key, 'wallos_subscriptions_bulk_update', $args);
bulk_check(!empty($replay['replayed']), 'batch retry replays committed result before stale-version checks');
bulk_reject('idempotency_conflict', fn() => bulk_call($db, $key, 'wallos_subscriptions_bulk_update', array_replace($args, ['changes' => ['notify' => false]])), 'changed retry arguments are rejected');
bulk_reject('conflict', fn() => bulk_call($db, $key, 'wallos_subscriptions_bulk_update', array_replace($args, ['request_id' => 'bulk-stale-001'])), 'old target version rejects whole batch');
$current = bulk_targets($db, $ids);
$db->exec("UPDATE subscriptions SET notes='concurrent browser edit' WHERE id=" . $ids[2]);
bulk_reject('conflict', fn() => bulk_call($db, $key, 'wallos_subscriptions_bulk_update', ['targets' => $current, 'changes' => ['notify' => false], 'request_id' => 'bulk-conflict-001']), 'one stale target rejects the entire batch');
bulk_check((int) $db->querySingle('SELECT sum(notify) FROM subscriptions') === 3, 'earlier targets remain unchanged after batch conflict');
$db->exec('CREATE TEMP TRIGGER bulk_test_failure BEFORE UPDATE OF notify ON subscriptions WHEN NEW.id=' . $ids[2] . " AND NEW.notify=0 BEGIN SELECT RAISE(ABORT,'isolated batch write failure'); END");
foreach (['mcp', 'browser'] as $transport) {
    $lateFailure = ['targets' => bulk_targets($db, $ids), 'changes' => ['notify' => false], 'request_id' => 'bulk-rollback-' . $transport];
    if ($transport === 'browser') $db->enableExceptions(false); // Legacy connect.php default.
    try {
        if ($transport === 'mcp') bulk_call($db, $key, 'wallos_subscriptions_bulk_update', $lateFailure);
        else wallos_subscription_bulk_browser($db, 1, 'wallos_subscriptions_bulk_update', json_decode(json_encode($lateFailure)));
        throw new RuntimeException('Expected isolated SQL failure');
    } catch (Exception $e) {
        bulk_check(str_contains($e->getMessage(), 'isolated batch write failure'), $transport . ' reaches an actual late SQL failure');
    }
    bulk_check((int) $db->querySingle('SELECT sum(notify) FROM subscriptions') === 3, $transport . ' rolls back earlier writes on late SQL failure');
}
$db->exec('DROP TRIGGER bulk_test_failure');
$foreign = bulk_targets($db, [$ids[0]]);
$db->exec('UPDATE subscriptions SET user_id=2 WHERE id=' . $ids[1]);
$foreign[] = ['id' => $ids[1], 'expected_version' => str_repeat('a', 64)];
bulk_reject('not_found', fn() => bulk_call($db, $key, 'wallos_subscriptions_bulk_update', ['targets' => $foreign, 'changes' => ['notify' => false], 'request_id' => 'bulk-owner-001']), 'one foreign target rejects batch without leaking data');
$db->exec('UPDATE subscriptions SET user_id=1 WHERE id=' . $ids[1]);
$current = bulk_targets($db, $ids);
bulk_reject('invalid_arguments', fn() => bulk_call($db, $key, 'wallos_subscriptions_bulk_update', ['targets' => [$current[0], $current[0]], 'changes' => ['notify' => false], 'request_id' => 'bulk-dupe-001']), 'duplicate targets rejected');
bulk_reject('invalid_arguments', fn() => bulk_call($db, $key, 'wallos_subscriptions_bulk_update', ['targets' => [], 'changes' => ['notify' => false], 'request_id' => 'bulk-empty-001']), 'empty batch rejected');
bulk_reject('invalid_arguments', fn() => bulk_call($db, $key, 'wallos_subscriptions_bulk_update', ['targets' => array_fill(0, 51, $current[0]), 'changes' => ['notify' => false], 'request_id' => 'bulk-large-001']), 'batch limit enforced by schema');
bulk_reject('invalid_arguments', fn() => bulk_call($db, $key, 'wallos_subscriptions_bulk_update', ['targets' => $current, 'changes' => ['price' => 0], 'request_id' => 'bulk-field-001']), 'unsupported bulk fields rejected');
bulk_reject('invalid_arguments', fn() => bulk_call($db, $key, 'wallos_subscriptions_bulk_update', ['targets' => $current, 'changes' => new stdClass(), 'request_id' => 'bulk-noop-001']), 'empty changes rejected');
bulk_reject('not_found', fn() => bulk_call($db, $key, 'wallos_subscriptions_bulk_update', ['targets' => $current, 'changes' => ['payment_method_id' => 200], 'request_id' => 'bulk-ref-001']), 'foreign payment method rejected');
$categories = bulk_call($db, $key, 'wallos_subscriptions_bulk_update', ['targets' => $current, 'changes' => ['category_ids' => [101, 100]], 'request_id' => 'bulk-category-001']);
bulk_check($categories['subscriptions'][0]['category_ids'] === [101, 100] && $categories['subscriptions'][2]['category_id'] === 101, 'batch replaces category lists and primary reference consistently');
$browserArgs = ['targets' => bulk_targets($db, $ids), 'changes' => ['notify_days_before' => 7], 'request_id' => 'browser-preview-001', 'dry_run' => true];
$browser = fn($a) => wallos_subscription_bulk_browser($db, 1, 'wallos_subscriptions_bulk_update', json_decode(json_encode($a)));
$browserPreview = $browser($browserArgs);
bulk_check($browserPreview['dry_run'] && (int) $db->querySingle('SELECT sum(notify_days_before) FROM subscriptions') === 0, 'browser preview shares rolled-back business behavior');
$browserArgs['request_id'] = 'browser-apply-001';
$browserArgs['dry_run'] = false;
$browser($browserArgs);
bulk_check((int) $db->querySingle('SELECT sum(notify_days_before) FROM subscriptions') === 21, 'browser batch commits');
bulk_check(!empty($browser($browserArgs)['replayed']), 'browser exact retry replays atomically persisted result');
bulk_check((int) $db->querySingle("SELECT count(*) FROM agent_audit WHERE tool='browser.wallos_subscriptions_bulk_update' AND key_id IS NULL AND status='committed'") === 3, 'browser audit does not invent an Agent key');
bulk_reject('idempotency_conflict', fn() => $browser(array_replace($browserArgs, ['changes' => ['notify_days_before' => 8]])), 'browser request-ID reuse rejected');
$db->exec('UPDATE subscriptions SET replacement_subscription_id=' . $ids[1] . ',inactive=1 WHERE id=' . $ids[0]);
$trashArgs = ['targets' => bulk_targets($db, $ids), 'confirm_ids' => $ids, 'request_id' => 'bulk-trash-001'];
bulk_reject('confirmation_mismatch', fn() => bulk_call($db, $key, 'wallos_subscriptions_bulk_trash', array_replace($trashArgs, ['confirm_ids' => [$ids[0]]])), 'trash confirmation requires the complete exact ID set');
$trashed = bulk_call($db, $key, 'wallos_subscriptions_bulk_trash', $trashArgs);
bulk_check((int) $db->querySingle("SELECT count(*) FROM subscriptions WHERE lifecycle_status='trashed'") === 3, 'bulk trash retains all records in recycle bin');
bulk_check((int) $db->querySingle("SELECT count(*) FROM subscriptions WHERE scheduled_delete_at > datetime('now','+179 days')") === 3, 'trash uses existing 180-day retention');
bulk_check($trashed['subscriptions'][0]['replacement_subscription_id'] === null, 'batch trash safely clears links between selected records');
foreach ($trashed['subscriptions'] as $row) {
    bulk_check($row['version'] === wallos_agent_present_subscription($db, 1, $row['id'])['version'], 'returned final version includes all changes made by the batch');
}
bulk_check(!empty(bulk_call($db, $key, 'wallos_subscriptions_bulk_trash', $trashArgs)['replayed']), 'trash replay succeeds without duplicate side effects');
echo "Passed $checks bulk checks.\n";
