<?php

class WallosAgentError extends RuntimeException
{
    public $errorCode;
    public $httpStatus;
    public function __construct($code, $message, $status = 400)
    {
        parent::__construct($message);
        $this->errorCode = $code;
        $this->httpStatus = $status;
    }
}

function wallos_agent_fail($code, $message, $status = 400)
{
    throw new WallosAgentError($code, $message, $status);
}

function wallos_agent_sql($db, $sql, array $values = [])
{
    $stmt = $db->prepare($sql);
    foreach ($values as $key => $value) {
        $type = $value === null ? SQLITE3_NULL : (is_int($value) ? SQLITE3_INTEGER : (is_float($value) ? SQLITE3_FLOAT : SQLITE3_TEXT));
        $stmt->bindValue(':' . ltrim($key, ':'), $value, $type);
    }
    return $stmt->execute();
}

function wallos_agent_rows($result)
{
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

function wallos_agent_scopes()
{
    return ['subscriptions:read', 'subscriptions:write', 'subscriptions:delete',
        'payments:read', 'payments:write', 'payments:delete',
        'references:read', 'references:write', 'references:delete',
        'budgets:read', 'budgets:write', 'audit:read'];
}

function wallos_agent_create_key($db, $userId, $name, array $scopes, $days = 30)
{
    if (!is_string($name) || trim($name) === '' || strlen($name) > 100 || !is_int($days) || $days < 1 || $days > 365) {
        wallos_agent_fail('invalid_key', 'Name (1–100 bytes) and expiry (1–365 days) are required.');
    }
    if (!$scopes || count($scopes) > count(wallos_agent_scopes()) || array_filter($scopes, function ($scope) { return !is_string($scope) || !in_array($scope, wallos_agent_scopes(), true); })) {
        wallos_agent_fail('invalid_scope', 'Choose explicitly supported scopes.');
    }
    // Reads are necessary to resolve identifiers and obtain concurrency versions.
    foreach (['subscriptions', 'payments', 'references', 'budgets'] as $resource) {
        if (in_array($resource . ':write', $scopes, true) || in_array($resource . ':delete', $scopes, true)) {
            $scopes[] = $resource . ':read';
        }
    }
    if (array_intersect($scopes, ['payments:read', 'payments:write', 'payments:delete'])) {
        $scopes[] = 'subscriptions:read';
    }
    $scopes = array_values(array_unique($scopes));
    $token = 'wls_' . bin2hex(random_bytes(32));
    $expires = time() + $days * 86400;
    wallos_agent_sql($db, 'INSERT INTO agent_keys (user_id,name,token_hash,token_prefix,scopes,expires_at,created_at)
        VALUES (:uid,:name,:hash,:prefix,:scopes,:expires,:now)', [
        'uid' => (int) $userId, 'name' => trim($name), 'hash' => hash('sha256', $token),
        'prefix' => substr($token, 0, 12), 'scopes' => json_encode($scopes), 'expires' => $expires, 'now' => time(),
    ]);
    return ['id' => (int) $db->lastInsertRowID(), 'token' => $token, 'scopes' => $scopes, 'expires_at' => $expires];
}

function wallos_agent_rate($db, $bucket, $limit)
{
    $window = intdiv(time(), 60) * 60;
    wallos_agent_sql($db, 'INSERT INTO agent_rate_limits (bucket,window_start,count) VALUES (:bucket,:window,1)
        ON CONFLICT(bucket) DO UPDATE SET count=CASE WHEN window_start=:window THEN count+1 ELSE 1 END, window_start=:window',
        ['bucket' => $bucket, 'window' => $window]);
    $row = wallos_agent_sql($db, 'SELECT count FROM agent_rate_limits WHERE bucket=:bucket', ['bucket' => $bucket])->fetchArray(SQLITE3_ASSOC);
    if ($row['count'] > $limit) {
        wallos_agent_fail('rate_limited', 'Too many requests. Retry after the next minute.', 429);
    }
    // Bound stale IP buckets without retaining raw addresses.
    wallos_agent_sql($db, 'DELETE FROM agent_rate_limits WHERE window_start < :old', ['old' => $window - 3600]);
}

function wallos_agent_authenticate($db, $authorization)
{
    if (!is_string($authorization) || !preg_match('/^Bearer (wls_[a-f0-9]{64})$/D', $authorization, $matches)) {
        wallos_agent_fail('unauthorized', 'A valid agent Bearer key is required.', 401);
    }
    $key = wallos_agent_sql($db, 'SELECT k.*, u.account_status FROM agent_keys k JOIN user u ON u.id=k.user_id
        WHERE k.token_hash=:hash', ['hash' => hash('sha256', $matches[1])])->fetchArray(SQLITE3_ASSOC);
    if (!$key || $key['revoked_at'] !== null || (int) $key['expires_at'] <= time() || $key['account_status'] !== 'active') {
        wallos_agent_fail('unauthorized', 'A valid agent Bearer key is required.', 401);
    }
    $key['scopes'] = json_decode($key['scopes'], true);
    return $key;
}

function wallos_agent_refresh_key($db, array $key)
{
    $current = wallos_agent_sql($db, 'SELECT k.revoked_at,k.expires_at,u.account_status FROM agent_keys k JOIN user u ON u.id=k.user_id
        WHERE k.id=:id', ['id' => (int) $key['id']])->fetchArray(SQLITE3_ASSOC);
    if (!$current || $current['revoked_at'] !== null || (int) $current['expires_at'] <= time() || $current['account_status'] !== 'active') {
        wallos_agent_fail('unauthorized', 'Agent key expired or was revoked.', 401);
    }
}
