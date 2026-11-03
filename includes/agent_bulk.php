<?php
// Loaded by agent_tools.php after its core declarations. Both browser and MCP
// batches use the same single-record business operations inside ONE transaction.

function wallos_agent_bulk_catalog()
{
    $target = wallos_agent_object([
        'id' => ['type' => 'integer', 'minimum' => 1],
        'expected_version' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
    ], ['id', 'expected_version']);
    $targets = ['type' => 'array', 'items' => $target, 'maxItems' => 50];
    $fields = wallos_agent_subscription_fields();
    $changes = [];
    foreach (['notify', 'notify_days_before', 'category_id', 'category_ids', 'payment_method_id'] as $field) {
        if (isset($fields[$field])) {
            $changes[$field] = $fields[$field];
        }
    }
    $common = [
        'targets' => $targets,
        'request_id' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128, 'pattern' => '^[A-Za-z0-9._:-]+$'],
        'dry_run' => ['type' => 'boolean'],
    ];
    $tools = [];
    foreach (['update', 'trash'] as $action) {
        $trash = $action === 'trash';
        $name = 'wallos_subscriptions_bulk_' . $action;
        $properties = $common + ($trash
            ? ['confirm_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'maxItems' => 50]]
            : ['changes' => wallos_agent_object($changes)]);
        $tools[$name] = [
            'name' => $name,
            'description' => $trash
                ? 'Atomically move 1–50 explicitly selected owned subscriptions into the 180-day recycle bin. No permanent deletion. Each latest version is required; confirm_ids must exactly match target IDs. All targets succeed or the whole batch rolls back. Preview with dry_run=true.'
                : 'Atomically patch notification, category or payment method fields on 1–50 explicitly selected owned subscriptions. Each latest version is required. Omitted fields stay intact. All targets succeed or the whole batch rolls back. Preview with dry_run=true.',
            'scope' => $trash ? 'subscriptions:delete' : 'subscriptions:write',
            'inputSchema' => wallos_agent_object($properties, ['targets', 'request_id', $trash ? 'confirm_ids' : 'changes']),
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => $trash, 'idempotentHint' => true, 'openWorldHint' => false],
        ];
    }
    return $tools;
}

function wallos_subscription_bulk_ids(array $ids)
{
    if (!$ids || count($ids) > 50) {
        wallos_agent_fail('invalid_arguments', 'Select between 1 and 50 subscriptions.');
    }
    foreach ($ids as $id) {
        if (!is_int($id) || $id < 1) {
            wallos_agent_fail('invalid_arguments', 'Subscription IDs must be positive integers.');
        }
    }
    if (count(array_unique($ids)) !== count($ids)) {
        wallos_agent_fail('invalid_arguments', 'Duplicate target IDs are not allowed.');
    }
    return $ids;
}

function wallos_subscription_bulk_audit($db, array $key, $name, array $a, array $target, $status, $after = null)
{
    // No amounts, names or note bodies in audit metadata. A NULL key identifies
    // a browser session operation, which is never authority for an MCP request.
    wallos_agent_sql($db, 'INSERT INTO agent_audit (user_id,key_id,tool,request_id,status,target_id,changed_fields,before_version,after_version,created_at)
        VALUES (:uid,:key,:tool,:request,:status,:target,:fields,:before,:after,:now)', [
        'uid' => (int) $key['user_id'], 'key' => $key['id'] ?? null,
        'tool' => $name, 'request' => $a['request_id'], 'status' => $status,
        'target' => $target['id'], 'fields' => json_encode(array_keys($a['changes'] ?? ['lifecycle_status' => true])),
        'before' => $target['expected_version'], 'after' => $after, 'now' => time(),
    ]);
}

function wallos_agent_execute_bulk($db, array $key, $name, array $a)
{
    $catalog = wallos_agent_bulk_catalog();
    if (!isset($catalog[$name])) {
        wallos_agent_fail('unknown_tool', 'Bulk tool not found.', 404);
    }
    if (!in_array($catalog[$name]['scope'], $key['scopes'], true)) {
        wallos_agent_fail('forbidden', 'Required scope: ' . $catalog[$name]['scope'], 403);
    }
    $uid = (int) $key['user_id'];
    $ids = wallos_subscription_bulk_ids(array_column($a['targets'], 'id'));
    $trash = $name === 'wallos_subscriptions_bulk_trash';
    if ($trash) {
        $confirmed = wallos_subscription_bulk_ids($a['confirm_ids']);
        $sorted = $ids;
        sort($sorted);
        sort($confirmed);
        if ($sorted !== $confirmed) {
            wallos_agent_fail('confirmation_mismatch', 'confirm_ids must exactly match all target IDs.');
        }
    } elseif (empty($a['changes'])) {
        wallos_agent_fail('invalid_arguments', 'changes must not be empty.');
    }
    // Validate every original snapshot before executing any operation. Trash can
    // clear replacement links between targets: later checks must not mistake
    // changes made by this same batch for a concurrent external edit.
    foreach ($a['targets'] as $target) {
        $state = wallos_agent_subscription_state($db, $uid, $target['id']);
        wallos_agent_check_version($state, $target['expected_version']);
        if ($state['subscription']['lifecycle_status'] !== 'active') {
            wallos_agent_fail('in_recycle_bin', 'All selected subscriptions must be active.', 409);
        }
    }
    foreach ($a['targets'] as $target) {
        $single = [
            'id' => $target['id'],
            'expected_version' => wallos_agent_version(wallos_agent_subscription_state($db, $uid, $target['id'])),
        ];
        if ($trash) {
            $single['confirm_id'] = $target['id'];
        } else {
            $single['changes'] = $a['changes'];
        }
        wallos_agent_execute_tool($db, $key, $trash ? 'wallos_subscriptions_trash' : 'wallos_subscriptions_update', $single);
    }
    $rows = [];
    foreach ($a['targets'] as $target) {
        $row = wallos_agent_present_subscription($db, $uid, $target['id']);
        $rows[] = $row;
        wallos_subscription_bulk_audit($db, $key,
            empty($key['id']) ? 'browser.' . $name : $name,
            $a, $target, !empty($a['dry_run']) ? 'dry_run' : 'committed', $row['version']);
    }
    return ['count' => count($rows), 'subscriptions' => $rows, 'atomic' => true];
}

function wallos_subscription_bulk_browser($db, $uid, $name, $arguments)
{
    // Legacy browser connections default to warning/false on SQL errors. A
    // batch must throw on BEGIN/write/COMMIT failures to preserve atomicity.
    $db->enableExceptions(true);
    $catalog = wallos_agent_bulk_catalog();
    if (!isset($catalog[$name])) {
        wallos_agent_fail('unknown_tool', 'Bulk tool not found.', 404);
    }
    wallos_agent_validate($arguments, $catalog[$name]['inputSchema']);
    $a = json_decode(json_encode($arguments, JSON_THROW_ON_ERROR), true);
    $key = ['id' => null, 'user_id' => (int) $uid, 'scopes' => ['subscriptions:write', 'subscriptions:delete']];
    $hash = wallos_agent_version(['tool' => $name, 'arguments' => $arguments]);
    $transaction = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $transaction = true;
        $user = wallos_agent_sql($db, 'SELECT account_status FROM user WHERE id=:id', ['id' => (int) $uid])->fetchArray(SQLITE3_ASSOC);
        if (!$user || $user['account_status'] !== 'active') {
            wallos_agent_fail('unauthorized', 'Session account is unavailable.', 401);
        }
        $prior = wallos_agent_sql($db, 'SELECT request_hash,result_json FROM subscription_bulk_requests WHERE user_id=:uid AND request_id=:request',
            ['uid' => (int) $uid, 'request' => $a['request_id']])->fetchArray(SQLITE3_ASSOC);
        if ($prior) {
            if (!hash_equals($prior['request_hash'], $hash)) {
                wallos_agent_fail('idempotency_conflict', 'request_id was already used with different arguments.', 409);
            }
            $db->exec('COMMIT');
            $transaction = false;
            return array_replace(json_decode($prior['result_json'], true), ['replayed' => true]);
        }
        $result = wallos_agent_execute_bulk($db, $key, $name, $a);
        $result['dry_run'] = !empty($a['dry_run']);
        if (!empty($a['dry_run'])) {
            $db->exec('ROLLBACK');
            $transaction = false;
            // Keep only operation metadata after rolling back the preview.
            foreach ($a['targets'] as $target) {
                wallos_subscription_bulk_audit($db, $key, 'browser.' . $name, $a, $target, 'dry_run');
            }
            return $result;
        }
        wallos_agent_sql($db, 'INSERT INTO subscription_bulk_requests(user_id,request_id,request_hash,result_json,created_at) VALUES(:uid,:request,:hash,:result,:now)', [
            'uid' => (int) $uid, 'request' => $a['request_id'], 'hash' => $hash,
            'result' => json_encode($result, JSON_THROW_ON_ERROR), 'now' => time(),
        ]);
        $db->exec('COMMIT');
        $transaction = false;
        return $result;
    } catch (Throwable $e) {
        if ($transaction) {
            $db->exec('ROLLBACK');
        }
        foreach ($a['targets'] as $target) {
            try {
                wallos_subscription_bulk_audit($db, $key, 'browser.' . $name, $a, $target, $e instanceof WallosAgentError ? $e->errorCode : 'internal_error');
            } catch (Throwable $ignored) {
                error_log('Wallos browser bulk: failed to record rejected operation.');
            }
        }
        throw $e;
    }
}
