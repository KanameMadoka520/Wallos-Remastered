<?php
require_once __DIR__ . '/../includes/agent_tools.php';
function agent_test_db($path = ':memory:') {
    $db = new SQLite3($path);
    $db->enableExceptions(true);
    $db->exec(file_get_contents(__DIR__ . '/fixtures/agent_schema.sql'));
    require __DIR__ . '/../migrations/000083.php';
    require __DIR__ . '/../migrations/000083.php';
    $db->exec("INSERT INTO cycles VALUES(1,1,'day'),(2,7,'week'),(3,30,'month'),(4,365,'year'),(5,0,'one-time')");
    $db->exec("INSERT INTO frequencies(id,name) VALUES(1,'1'),(2,'2')");
    foreach ([1, 2] as $uid) {
        $base = $uid * 100;
        $db->exec("INSERT INTO currencies(id,name,symbol,code,rate,user_id) VALUES($base,'Test dollar','$','USD','1',$uid)");
        $db->exec("INSERT INTO user(id,username,email,password,main_currency) VALUES($uid,'agent-test-$uid','test$uid@example.invalid','invalid-password',$base)");
        $db->exec("INSERT INTO categories(id,name,user_id) VALUES($base,'Test category',$uid)");
        $db->exec("INSERT INTO household(id,name,user_id) VALUES($base,'Test member',$uid)");
        $db->exec("INSERT INTO payment_methods(id,name,user_id) VALUES($base,'Test cash',$uid)");
        $db->exec("INSERT INTO settings(user_id) VALUES($uid)");
    }
    $db->exec('PRAGMA foreign_keys=ON');
    return $db;
}
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
    echo "[OK] $label\n";
}
function call_tool($db, $key, $name, $a = []) {
    return wallos_agent_call($db, $key, $name, json_decode(json_encode((object) $a)));
}
function rejects($code, callable $fn, $label) {
    try { $fn(); } catch (WallosAgentError $e) { check($e->errorCode === $code, "$label ($code)"); return; }
    throw new RuntimeException("Did not reject: $label");
}
$checks = 0;
try {
    if (($argv[1] ?? '') === '--init') {
        $db = agent_test_db($argv[2]);
        $key = wallos_agent_create_key($db, 1, 'isolated-http-test', wallos_agent_scopes());
        file_put_contents(dirname($argv[2]) . '/test-key.json', json_encode($key));
        chmod(dirname($argv[2]) . '/test-key.json', 0600);
        echo "Isolated fixture initialized.\n";
        exit;
    }
    $db = agent_test_db();
    $issued = wallos_agent_create_key($db, 1, 'test', wallos_agent_scopes());
    $key = wallos_agent_authenticate($db, 'Bearer ' . $issued['token']);
    $key2 = wallos_agent_authenticate($db, 'Bearer ' . wallos_agent_create_key($db, 2, 'other', wallos_agent_scopes())['token']);
    check($key['user_id'] === 1, 'key resolves the owning account');
    check($db->querySingle('SELECT token_hash FROM agent_keys WHERE id=1') !== $issued['token'], 'only key hashes are stored');
    rejects('unauthorized', fn() => wallos_agent_authenticate($db, 'Bearer legacy-key'), 'legacy key is not accepted');
    $data = ['name' => 'A & B <test> \\ path', 'price' => 12.50, 'currency_id' => 100, 'next_payment' => '2026-11-01',
        'start_date' => '2026-10-01', 'cycle' => 3, 'frequency' => 1, 'payment_method_id' => 100, 'payer_user_id' => 100, 'category_id' => 100];
    $preview = call_tool($db, $key, 'wallos_subscriptions_create', ['data' => $data, 'request_id' => 'preview-001', 'dry_run' => true]);
    check($db->querySingle('SELECT COUNT(*) FROM subscriptions') === 0 && $preview['dry_run'], 'dry run rolls back subscription insertion');
    check($db->querySingle('SELECT COUNT(*) FROM agent_requests') === 0, 'dry run never reserves idempotency ids');
    $request = ['data' => $data, 'request_id' => 'create-001'];
    $created = call_tool($db, $key, 'wallos_subscriptions_create', $request)['subscription'];
    $id = $created['id'];
    check($created['name'] === $data['name'], 'escaped storage round trips ampersands, HTML and backslashes');
    check(strlen($created['version']) === 64, 'reads include concurrency version');
    $again = call_tool($db, $key, 'wallos_subscriptions_create', $request);
    check($again['replayed'] && $db->querySingle('SELECT COUNT(*) FROM subscriptions') === 1, 'identical retry creates exactly one subscription');
    rejects('idempotency_conflict', fn() => call_tool($db, $key, 'wallos_subscriptions_create', ['data' => array_replace($data, ['price' => 99]), 'request_id' => 'create-001']), 'request id reuse with changed payload');
    rejects('not_found', fn() => call_tool($db, $key2, 'wallos_subscriptions_get', ['id' => $id]), 'cross-account read');
    rejects('not_found', fn() => call_tool($db, $key2, 'wallos_subscriptions_update', ['id' => $id, 'expected_version' => $created['version'], 'changes' => ['price' => 99], 'request_id' => 'cross-edit']), 'cross-account update');
    rejects('not_found', fn() => call_tool($db, $key, 'wallos_subscriptions_create', ['data' => array_replace($data, ['category_id' => 200]), 'request_id' => 'cross-ref-001']), 'cross-account reference assignment');
    rejects('invalid_arguments', fn() => call_tool($db, $key, 'wallos_subscriptions_create', ['data' => array_replace($data, ['next_payment' => '2026-02-30']), 'request_id' => 'bad-date-001']), 'invalid calendar date');
    rejects('invalid_arguments', fn() => call_tool($db, $key, 'wallos_subscriptions_update', ['id' => $id, 'expected_version' => $created['version'], 'changes' => ['user_id' => 2], 'request_id' => 'bad-field-001']), 'mass assignment rejected');
    rejects('invalid_arguments', fn() => call_tool($db, $key, 'wallos_subscriptions_update', ['id' => $id, 'expected_version' => $created['version'], 'changes' => ['url' => 'javascript:alert(1)'], 'request_id' => 'bad-url-001']), 'script URLs rejected');
    rejects('invalid_arguments', fn() => call_tool($db, $key, 'wallos_subscriptions_update', ['id' => $id, 'expected_version' => $created['version'], 'changes' => ['inactive' => 'false'], 'request_id' => 'bad-type-001']), 'string booleans rejected');
    rejects('invalid_arguments', fn() => wallos_agent_call($db, $key, 'wallos_subscriptions_list', []), 'array arguments rejected');
    $changed = call_tool($db, $key, 'wallos_subscriptions_update', ['id' => $id, 'expected_version' => $created['version'], 'changes' => ['price' => 20], 'request_id' => 'update-001'])['subscription'];
    check((float) $changed['price'] === 20.0 && $changed['name'] === $data['name'], 'sparse update preserves omitted fields');
    rejects('conflict', fn() => call_tool($db, $key, 'wallos_subscriptions_update', ['id' => $id, 'expected_version' => $created['version'], 'changes' => ['price' => 30], 'request_id' => 'stale-001']), 'stale agent version rejected');
    $db->exec("UPDATE subscriptions SET notes='browser-edit' WHERE id=$id");
    rejects('conflict', fn() => call_tool($db, $key, 'wallos_subscriptions_update', ['id' => $id, 'expected_version' => $changed['version'], 'changes' => ['price' => 30], 'request_id' => 'stale-ui-001']), 'browser edits invalidate version without UI changes');
    $get = fn() => call_tool($db, $key, 'wallos_subscriptions_get', ['id' => $id])['subscription'];
    $current = $get();
    $promoted = call_tool($db, $key, 'wallos_price_rules_set', ['id' => $id, 'expected_version' => $current['version'], 'request_id' => 'rules-001', 'rules' => [
        ['rule_type' => 'first_n_cycles', 'price' => 5, 'currency_id' => 100, 'max_cycles' => 2],
    ]])['subscription'];
    check(count($promoted['price_rules']) === 1 && $promoted['version'] !== $current['version'], 'promotions update aggregate version');
    rejects('not_found', fn() => call_tool($db, $key, 'wallos_price_rules_set', ['id' => $id, 'expected_version' => $promoted['version'], 'request_id' => 'rules-bad-001', 'rules' => [
        ['rule_type' => 'first_n_cycles', 'price' => 1, 'currency_id' => 200, 'max_cycles' => 1],
    ]]), 'invalid promotion rejected before replacing rules');
    check(count($get()['price_rules']) === 1, 'failed rule replacement preserves old rules');
    $payment = ['due_date' => '2026-11-01', 'paid_at' => '2026-11-01', 'amount_original' => 20, 'currency_id' => 100, 'payment_method_id' => 100];
    $payReq = ['subscription_id' => $id, 'expected_version' => $get()['version'], 'data' => $payment, 'request_id' => 'payment-001'];
    $paid = call_tool($db, $key, 'wallos_payments_create', $payReq)['payment'];
    check((float) $paid['amount_main_snapshot'] === 20.0, 'payments reuse correct currency snapshot logic');
    check($get()['next_payment'] === '2026-11-01', 'payment recording preserves schedule by default');
    check(call_tool($db, $key, 'wallos_payments_create', $payReq)['replayed'], 'payment retry is idempotent despite changed aggregate version');
    rejects('duplicate_payment', fn() => call_tool($db, $key, 'wallos_payments_create', array_replace($payReq, ['expected_version' => $get()['version'], 'request_id' => 'payment-duplicate'])), 'same due date cannot be recorded twice');
    $list = call_tool($db, $key, 'wallos_payments_list', ['subscription_id' => $id]);
    check($list['payments'][0]['version'] === $paid['version'], 'payment list and update use the same record version');
    $updatedPay = call_tool($db, $key, 'wallos_payments_update', ['subscription_id' => $id, 'id' => $paid['id'], 'expected_version' => $list['payments'][0]['version'], 'data' => array_replace($payment, ['amount_original' => 21]), 'request_id' => 'pay-update-001'])['payment'];
    check((float) $updatedPay['amount_original'] === 21.0, 'payment update succeeds with list version');
    rejects('confirmation_mismatch', fn() => call_tool($db, $key, 'wallos_payments_delete', ['subscription_id' => $id, 'id' => $paid['id'], 'expected_version' => $updatedPay['version'], 'confirm_id' => 999, 'request_id' => 'pay-bad-del']), 'payment delete requires exact confirmation');
    call_tool($db, $key, 'wallos_payments_delete', ['subscription_id' => $id, 'id' => $paid['id'], 'expected_version' => $updatedPay['version'], 'confirm_id' => $paid['id'], 'request_id' => 'pay-delete-001']);
    check(count(call_tool($db, $key, 'wallos_payments_list', ['subscription_id' => $id])['payments']) === 0, 'payment deletion scoped to the selected subscription');
    call_tool($db, $key, 'wallos_payments_create', ['subscription_id' => $id, 'expected_version' => $get()['version'], 'data' => $payment, 'advance_next_payment' => true, 'request_id' => 'pay-advance-001']);
    check($get()['next_payment'] === '2026-12-01', 'explicit schedule advance uses existing recurrence logic');
    rejects('schedule_range_exceeded', fn() => call_tool($db, $key, 'wallos_payments_create', [
        'subscription_id' => $id, 'expected_version' => $get()['version'],
        'data' => array_replace($payment, ['due_date' => '9999-01-01', 'paid_at' => '9999-01-01']),
        'advance_next_payment' => true, 'request_id' => 'far-future-001',
    ]), 'far-future payment cannot trigger unbounded schedule walking');
    $reference = call_tool($db, $key, 'wallos_references_save', ['kind' => 'categories', 'data' => ['name' => 'New category'], 'request_id' => 'ref-create-001'])['reference'];
    $reference2 = call_tool($db, $key, 'wallos_references_save', ['kind' => 'categories', 'id' => $reference['id'], 'expected_version' => $reference['version'], 'data' => ['name' => 'Updated category'], 'request_id' => 'ref-update-001'])['reference'];
    check($reference2['name'] === 'Updated category', 'reference create and update');
    call_tool($db, $key, 'wallos_references_delete', ['kind' => 'categories', 'id' => $reference2['id'], 'expected_version' => $reference2['version'], 'confirm_id' => $reference2['id'], 'request_id' => 'ref-delete-001']);
    rejects('not_found', fn() => wallos_agent_reference($db, 1, 'categories', $reference2['id']), 'unused reference deleted');
    $used = wallos_agent_reference($db, 1, 'categories', 100);
    rejects('reference_in_use', fn() => call_tool($db, $key, 'wallos_references_delete', ['kind' => 'categories', 'id' => 100, 'confirm_id' => 100, 'expected_version' => wallos_agent_version($used), 'request_id' => 'used-ref-001']), 'reference in use cannot be deleted');
    rejects('read_only_dictionary', fn() => call_tool($db, $key, 'wallos_references_save', ['kind' => 'cycles', 'data' => ['name' => 'Oops'], 'request_id' => 'bad-dict-001']), 'global dictionaries remain read-only');
    $budget = call_tool($db, $key, 'wallos_budgets_get')['budgets'];
    $budget = call_tool($db, $key, 'wallos_budgets_update', ['expected_version' => $budget['version'], 'changes' => ['yearly_budget' => 1200], 'request_id' => 'budget-001'])['budgets'];
    check((float) $budget['yearly_budget'] === 1200.0, 'budget update is version checked');
    $trashed = call_tool($db, $key, 'wallos_subscriptions_trash', ['id' => $id, 'confirm_id' => $id, 'expected_version' => $get()['version'], 'request_id' => 'trash-001'])['subscription'];
    check($trashed['lifecycle_status'] === 'trashed' && $trashed['scheduled_delete_at'] !== '', 'subscription deletion uses existing recycle-bin retention');
    rejects('in_recycle_bin', fn() => call_tool($db, $key, 'wallos_subscriptions_update', ['id' => $id, 'expected_version' => $trashed['version'], 'changes' => ['price' => 90], 'request_id' => 'trash-edit-001']), 'trashed subscription cannot be edited');
    $restored = call_tool($db, $key, 'wallos_subscriptions_restore', ['id' => $id, 'expected_version' => $trashed['version'], 'request_id' => 'restore-001'])['subscription'];
    check($restored['lifecycle_status'] === 'active', 'subscription restoration works');
    $readKey = wallos_agent_authenticate($db, 'Bearer ' . wallos_agent_create_key($db, 1, 'readonly', ['subscriptions:read'])['token']);
    rejects('forbidden', fn() => call_tool($db, $readKey, 'wallos_subscriptions_create', ['data' => $data, 'request_id' => 'readonly-write']), 'read-only key cannot write');
    wallos_agent_sql($db, 'UPDATE agent_keys SET revoked_at=:now WHERE id=:id', ['now' => time(), 'id' => (int) $readKey['id']]);
    rejects('unauthorized', fn() => call_tool($db, $readKey, 'wallos_context'), 'revocation rechecked at execution');
    $db->exec("UPDATE user SET account_status='trashed' WHERE id=2");
    rejects('unauthorized', fn() => call_tool($db, $key2, 'wallos_context'), 'trashed account cannot access data');
    $db->exec('UPDATE agent_keys SET expires_at=1 WHERE id=2');
    rejects('unauthorized', fn() => wallos_agent_refresh_key($db, $key2), 'expired keys rejected');
    wallos_agent_rate($db, 'test-rate', 1);
    rejects('rate_limited', fn() => wallos_agent_rate($db, 'test-rate', 1), 'rate limiting enforced');
    $audit = call_tool($db, $key, 'wallos_audit_list', ['limit' => 100])['audit'];
    check(count($audit) > 10, 'commits, dry-runs and rejected writes audited');
    check(!str_contains(json_encode($audit), 'browser-edit') && !str_contains(json_encode($audit), $issued['token']), 'audit omits content and secrets');
    check($db->query('PRAGMA foreign_key_check')->fetchArray() === false, 'all operations preserve foreign keys');
    $writeOnly = wallos_agent_authenticate($db, 'Bearer ' . wallos_agent_create_key($db, 1, 'writer', ['subscriptions:write'])['token']);
    check(in_array('subscriptions:read', $writeOnly['scopes'], true), 'writes automatically include required reads');
    rejects('forbidden', fn() => call_tool($db, $writeOnly, 'wallos_subscriptions_trash', ['id' => $id, 'confirm_id' => $id, 'expected_version' => $get()['version'], 'request_id' => 'writer-delete']), 'write scope does not implicitly grant deletion');
    $escapedRef = call_tool($db, $key, 'wallos_references_save', ['kind' => 'categories', 'data' => ['name' => 'A & B <x>'], 'request_id' => 'escaped-ref-001'])['reference'];
    check($escapedRef['name'] === 'A & B <x>', 'reference text is decoded exactly once');
    $refList = call_tool($db, $key, 'wallos_references_list', ['kind' => 'categories'])['references'];
    $listedRef = array_values(array_filter($refList, fn($r) => $r['id'] === $escapedRef['id']))[0];
    check($listedRef['version'] === $escapedRef['version'] && $listedRef['name'] === $escapedRef['name'], 'reference listing and editing share version and text contract');
    $rowsBefore = $db->querySingle('SELECT COUNT(*) FROM subscriptions');
    $db->exec("CREATE TRIGGER reject_agent_audit BEFORE INSERT ON agent_audit BEGIN SELECT RAISE(ABORT, 'audit fixture failure'); END");
    try {
        call_tool($db, $key, 'wallos_subscriptions_create', ['data' => $data, 'request_id' => 'audit-failure-001']);
        throw new RuntimeException('audit failure must abort write');
    } catch (Exception $e) {
        check($db->querySingle('SELECT COUNT(*) FROM subscriptions') === $rowsBefore, 'failed audit atomically rolls back data write');
        check(!wallos_agent_sql($db, 'SELECT 1 FROM agent_requests WHERE request_id=:id', ['id' => 'audit-failure-001'])->fetchArray(), 'failed audit never stores a false success response');
    }
    $db->exec('DROP TRIGGER reject_agent_audit');
    require_once __DIR__ . '/../includes/user_data_cleanup.php';
    $db->exec('CREATE TABLE login_tokens (user_id INTEGER)');
    $db->exec("UPDATE user SET account_status='active' WHERE id=2");
    $db->exec('UPDATE agent_keys SET expires_at=4102444800 WHERE id=2');
    wallos_trash_user($db, 2, 'test account lifecycle');
    check($db->querySingle('SELECT revoked_at FROM agent_keys WHERE id=2') !== null, 'account trash permanently revokes issued agent keys');
    wallos_restore_user($db, 2);
    rejects('unauthorized', fn() => call_tool($db, $key2, 'wallos_context'), 'restoring an account does not resurrect revoked keys');
    echo "PASS: $checks agent API checks.\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
