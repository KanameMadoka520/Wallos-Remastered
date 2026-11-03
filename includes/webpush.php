<?php
require_once __DIR__ . '/webpush_helper.php';
require_once __DIR__ . '/ssrf_helper.php';

function wallos_webpush_query($db, $sql, array $params = [])
{
    $stmt = $db->prepare($sql);
    foreach ($params as $name => $value) {
        $stmt->bindValue(':' . $name, $value, is_int($value) ? SQLITE3_INTEGER : ($value === null ? SQLITE3_NULL : SQLITE3_TEXT));
    }
    return $stmt->execute();
}

function wallos_webpush_available($db)
{
    return (bool) $db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='push_subscriptions'");
}

function wallos_webpush_settings($db, $userId)
{
    $row = wallos_webpush_query($db, 'SELECT enabled,show_details FROM push_notifications WHERE user_id=:uid', ['uid'=>(int)$userId])->fetchArray(SQLITE3_ASSOC);
    return $row ?: ['enabled'=>0, 'show_details'=>0];
}

function wallos_webpush_allowed_endpoint($endpoint)
{
    if (!is_string($endpoint) || strlen($endpoint) > 2048 || preg_match('/[\x00-\x20\x7f]/', $endpoint)) return false;
    $url = parse_url($endpoint);
    if (!$url || ($url['scheme'] ?? '') !== 'https' || !isset($url['host']) || isset($url['user'], $url['pass'])
        || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || ($url['port'] ?? 443) !== 443) return false;
    $host = strtolower($url['host']);
    // Only browser-vendor services. The webhook local-network allowlist never
    // weakens this boundary; a browser subscription cannot target a LAN service.
    foreach (['fcm.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'] as $vendor) {
        if ($host === $vendor || str_ends_with($host, '.' . $vendor)) return true;
    }
    return false;
}

function wallos_webpush_resolve_endpoint($endpoint)
{
    if (!wallos_webpush_allowed_endpoint($endpoint)) return false;
    $host = strtolower(parse_url($endpoint, PHP_URL_HOST));
    $ip = gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || wallos_ip_is_private_or_reserved($ip)) return false;
    return ['host'=>$host, 'ip'=>$ip, 'port'=>443];
}

function wallos_webpush_validate_subscription(array $input)
{
    $endpoint = $input['endpoint'] ?? null;
    $p256dh = $input['keys']['p256dh'] ?? null;
    $auth = $input['keys']['auth'] ?? null;
    if (!wallos_webpush_allowed_endpoint($endpoint) || !is_string($p256dh) || !is_string($auth)
        || !preg_match('/^[A-Za-z0-9_-]{87}=?$/D', $p256dh) || !preg_match('/^[A-Za-z0-9_-]{22}={0,2}$/D', $auth)
        || strlen(webpush_base64url_decode($auth)) !== 16
        || !webpush_ec_public_key_from_raw(webpush_base64url_decode($p256dh))) {
        throw new InvalidArgumentException('Invalid browser push subscription.');
    }
    return ['endpoint'=>$endpoint, 'p256dh'=>$p256dh, 'auth'=>$auth];
}

function wallos_webpush_device_hash($cookie = null)
{
    $cookie = $cookie ?? ($_COOKIE['wallos_push_device'] ?? '');
    return is_string($cookie) && preg_match('/^[a-f0-9]{64}$/D', $cookie) ? hash('sha256', $cookie) : '';
}

function wallos_webpush_clear_cookie()
{
    if (function_exists('wallos_build_cookie_options') && !headers_sent()) {
        setcookie('wallos_push_device', '', wallos_build_cookie_options(time()-3600, ['httponly'=>true]));
    }
    unset($_COOKIE['wallos_push_device']);
}

function wallos_webpush_revoke_browser($db)
{
    $hash = wallos_webpush_device_hash();
    if ($hash !== '' && wallos_webpush_available($db)) {
        wallos_webpush_query($db, 'DELETE FROM push_subscriptions WHERE device_hash=:hash', ['hash'=>$hash]);
    }
    wallos_webpush_clear_cookie();
}

function wallos_webpush_sync_browser_account($db, $userId)
{
    $hash = wallos_webpush_device_hash();
    if ($hash === '' || !wallos_webpush_available($db)) return;
    // A browser changing identity must explicitly subscribe again. No endpoint
    // or auth key is transferred from the previous account to the next one.
    wallos_webpush_query($db, 'DELETE FROM push_subscriptions WHERE device_hash=:hash AND user_id != :uid', ['hash'=>$hash, 'uid'=>(int)$userId]);
    if ($db->changes() > 0) wallos_webpush_clear_cookie();
}

function wallos_webpush_register($db, $userId, array $subscription, $deviceName, $cookie)
{
    $data = wallos_webpush_validate_subscription($subscription);
    $hash = wallos_webpush_device_hash($cookie);
    if ($hash === '') throw new InvalidArgumentException('Invalid device identity.');
    if (!is_string($deviceName)) throw new InvalidArgumentException('Invalid device name.');
    $deviceName = trim($deviceName);
    if ($deviceName === '' || mb_strlen($deviceName) > 80 || preg_match('/[\x00-\x1f]/', $deviceName)) {
        throw new InvalidArgumentException('Use a device name between 1 and 80 characters.');
    }
    $db->exec('SAVEPOINT push_register');
    try {
        $existing = wallos_webpush_query($db, 'SELECT user_id,device_hash FROM push_subscriptions WHERE endpoint=:endpoint', ['endpoint'=>$data['endpoint']])->fetchArray(SQLITE3_ASSOC);
        if ($existing && ((int)$existing['user_id'] !== (int)$userId || !hash_equals($existing['device_hash'], $hash))) {
            throw new InvalidArgumentException('This browser subscription is already bound. Disable it in the browser and subscribe again.');
        }
        $count = (int)wallos_webpush_query($db, 'SELECT COUNT(*) AS n FROM push_subscriptions WHERE user_id=:uid AND device_hash != :hash', ['uid'=>(int)$userId, 'hash'=>$hash])->fetchArray(SQLITE3_ASSOC)['n'];
        if ($count >= 20) throw new InvalidArgumentException('Remove an old device before adding another (maximum 20).');
        wallos_webpush_query($db, 'INSERT INTO push_subscriptions(user_id,endpoint,p256dh,auth,device_hash,device_name,created_at)
            VALUES(:uid,:endpoint,:p256dh,:auth,:hash,:name,:now)
            ON CONFLICT(device_hash) DO UPDATE SET endpoint=excluded.endpoint,p256dh=excluded.p256dh,auth=excluded.auth,device_name=excluded.device_name
            WHERE push_subscriptions.user_id=excluded.user_id', ['uid'=>(int)$userId]+$data+['hash'=>$hash,'name'=>$deviceName,'now'=>time()]);
        wallos_webpush_query($db, 'INSERT INTO push_notifications(user_id,enabled) VALUES(:uid,1) ON CONFLICT(user_id) DO UPDATE SET enabled=1', ['uid'=>(int)$userId]);
        $db->exec('RELEASE push_register');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK TO push_register'); $db->exec('RELEASE push_register'); throw $e;
    }
}

function wallos_webpush_payload($kind, $showDetails, array $subscription, array $i18n)
{
    $isCancellation = $kind === 'cancellation';
    $key = $isCancellation ? 'webpush_cancellation_body' : 'webpush_reminder_body';
    $body = translate($kind === 'test' ? 'test_notification' : $key, $i18n);
    if ($showDetails) {
        $name = mb_substr(html_entity_decode((string)($subscription['name'] ?? ''), ENT_QUOTES, 'UTF-8'), 0, 100);
        $date = (string)($isCancellation ? ($subscription['cancellation_date'] ?? '') : ($subscription['next_payment'] ?? ''));
        $body .= ' ' . $name . ' · ' . $date;
    }
    return json_encode(['title'=>'Wallos', 'body'=>$body, 'url'=>'index.php', 'tag'=>'wallos-' . $kind . '-' . (int)($subscription['id'] ?? 0)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function wallos_webpush_claim($db, $deviceId, $eventKey, $now)
{
    wallos_webpush_query($db, 'INSERT INTO push_delivery_claims(device_id,event_key,claimed_at) VALUES(:id,:event,:now)
        ON CONFLICT(device_id,event_key) DO UPDATE SET claimed_at=excluded.claimed_at, attempts=attempts+1
        WHERE delivered_at IS NULL AND attempts < 3 AND claimed_at <= :retry',
        ['id'=>(int)$deviceId,'event'=>$eventKey,'now'=>(int)$now,'retry'=>(int)$now-300]);
    return $db->changes() > 0;
}

function wallos_webpush_deliver($db, $userId, $kind, array $subscription, array $i18n, $deviceId = null)
{
    $settings = wallos_webpush_settings($db, $userId);
    if ($kind !== 'test' && !(int)$settings['enabled']) return ['sent'=>0,'failed'=>0];
    // Disabled or trashed accounts never receive push, even if a cron races an admin action.
    $active = wallos_webpush_query($db, "SELECT id FROM user WHERE id=:uid AND COALESCE(account_status,'active')='active'", ['uid'=>(int)$userId])->fetchArray(SQLITE3_ASSOC);
    if (!$active) return ['sent'=>0,'failed'=>0];
    $query = 'SELECT * FROM push_subscriptions WHERE user_id=:uid';
    $params = ['uid'=>(int)$userId];
    if ($deviceId !== null) { $query .= ' AND id=:id'; $params['id']=(int)$deviceId; }
    $result = wallos_webpush_query($db, $query, $params);
    $devices = [];
    while ($device = $result->fetchArray(SQLITE3_ASSOC)) $devices[]=$device;
    if (!$devices) return ['sent'=>0,'failed'=>0];
    $keys = webpush_get_vapid_keys($db);
    if ($keys === false) return ['sent'=>0,'failed'=>count($devices)];
    $serverUrl = (string)$db->querySingle('SELECT server_url FROM admin LIMIT 1');
    $subject = filter_var($serverUrl, FILTER_VALIDATE_URL) && str_starts_with($serverUrl, 'https://') ? $serverUrl : 'https://wallosapp.com';
    $payload = wallos_webpush_payload($kind, (int)$settings['show_details'] === 1 && $kind !== 'test', $subscription, $i18n);
    $eventKey = $kind . ':' . (int)($subscription['id'] ?? 0) . ':' . ($kind==='test' ? date('Y-m-d-H-i') : ($subscription[$kind==='cancellation'?'cancellation_date':'next_payment'] ?? ''));
    $summary = ['sent'=>0,'failed'=>0];
    foreach ($devices as $device) {
        if (!wallos_webpush_claim($db,$device['id'],$eventKey,time())) continue;
        // No redirects, local allowlist or unchecked DNS result can bypass this.
        $pin = wallos_webpush_resolve_endpoint($device['endpoint']);
        $response = $pin === false ? ['success'=>false,'status'=>0,'prune'=>false]
            : webpush_send($device, $payload, $keys, $subject, 86400, $pin);
        if ($response['prune']) {
            wallos_webpush_query($db, 'DELETE FROM push_subscriptions WHERE id=:id AND user_id=:uid', ['id'=>(int)$device['id'],'uid'=>(int)$userId]);
        } else {
            wallos_webpush_query($db, 'UPDATE push_subscriptions SET last_attempt_at=:now,last_status=:status,
                last_used_at=CASE WHEN :success=1 THEN :now ELSE last_used_at END,
                failure_count=CASE WHEN :success=1 THEN 0 ELSE failure_count+1 END WHERE id=:id AND user_id=:uid',
                ['now'=>time(),'status'=>(int)$response['status'],'success'=>$response['success']?1:0,'id'=>(int)$device['id'],'uid'=>(int)$userId]);
            if ($response['success']) wallos_webpush_query($db, 'UPDATE push_delivery_claims SET delivered_at=:now WHERE device_id=:id AND event_key=:event', ['now'=>time(),'id'=>(int)$device['id'],'event'=>$eventKey]);
        }
        $summary[$response['success']?'sent':'failed']++;
    }
    wallos_webpush_query($db, 'DELETE FROM push_delivery_claims WHERE claimed_at < :old', ['old'=>time()-90*86400]);
    return $summary;
}
