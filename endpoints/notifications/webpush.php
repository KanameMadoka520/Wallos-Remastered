<?php
require_once __DIR__ . '/../../includes/connect_endpoint.php';
wallos_endpoint_require_authenticated($i18n);
require_once __DIR__ . '/../../includes/validate_endpoint.php';
require_once __DIR__ . '/../../includes/webpush.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
try {
    $raw = file_get_contents('php://input', false, null, 0, 10001);
    if (strlen($raw) > 10000) throw new InvalidArgumentException('Request too large.');
    $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid request.');
    $action = $input['action'] ?? 'status';
    wallos_webpush_sync_browser_account($db, $userId);
    if ($action === 'register') {
        // Browser UI already requires secure context. Permit localhost only for
        // local development; reverse-proxy HTTPS uses the existing trusted logic.
        if (!wallos_request_is_https() && !in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost','127.0.0.1','[::1]'], true)) {
            throw new InvalidArgumentException('Web Push requires HTTPS.');
        }
        $cookie = $_COOKIE['wallos_push_device'] ?? '';
        if (wallos_webpush_device_hash($cookie) === '') $cookie = bin2hex(random_bytes(32));
        if (!is_array($input['subscription'] ?? null)) throw new InvalidArgumentException('Missing browser subscription.');
        wallos_webpush_register($db, $userId, $input['subscription'], $input['device_name'] ?? '', $cookie);
        setcookie('wallos_push_device', $cookie, wallos_build_cookie_options(time()+365*86400, ['httponly'=>true]));
        $_COOKIE['wallos_push_device'] = $cookie;
    } elseif ($action === 'save') {
        foreach (['enabled','show_details'] as $field) {
            if (!in_array($input[$field] ?? null, [0,1], true)) throw new InvalidArgumentException('Invalid notification setting.');
        }
        wallos_webpush_query($db, 'INSERT INTO push_notifications(user_id,enabled,show_details) VALUES(:uid,:enabled,:details)
            ON CONFLICT(user_id) DO UPDATE SET enabled=excluded.enabled,show_details=excluded.show_details', ['uid'=>$userId,'enabled'=>$input['enabled'],'details'=>$input['show_details']]);
    } elseif (in_array($action, ['remove','rename','test'], true)) {
        $id = $input['id'] ?? null;
        if (!is_int($id) || $id <= 0) throw new InvalidArgumentException('Invalid device.');
        $device = wallos_webpush_query($db, 'SELECT id,device_hash,last_attempt_at FROM push_subscriptions WHERE id=:id AND user_id=:uid', ['id'=>$id,'uid'=>$userId])->fetchArray(SQLITE3_ASSOC);
        if (!$device) { http_response_code(404); throw new InvalidArgumentException('Device not found.'); }
        if ($action === 'remove') {
            wallos_webpush_query($db, 'DELETE FROM push_subscriptions WHERE id=:id AND user_id=:uid', ['id'=>$id,'uid'=>$userId]);
            if (hash_equals($device['device_hash'], wallos_webpush_device_hash())) wallos_webpush_clear_cookie();
        } elseif ($action === 'rename') {
            if (!is_string($input['device_name'] ?? null)) throw new InvalidArgumentException('Invalid device name.');
            $name = trim($input['device_name']);
            if ($name === '' || mb_strlen($name)>80 || preg_match('/[\x00-\x1f]/', $name)) throw new InvalidArgumentException('Invalid device name.');
            wallos_webpush_query($db, 'UPDATE push_subscriptions SET device_name=:name WHERE id=:id AND user_id=:uid', ['name'=>$name,'id'=>$id,'uid'=>$userId]);
        } else {
            if ((int)$device['last_attempt_at'] > time()-60) { http_response_code(429); throw new InvalidArgumentException('Wait one minute before sending another test.'); }
            session_write_close();
            $delivery = wallos_webpush_deliver($db, $userId, 'test', [], $i18n, $id);
            if (!$delivery['sent']) { http_response_code(502); throw new InvalidArgumentException('The push service did not accept the test. Re-enable this device if its subscription has expired.'); }
        }
    } elseif ($action !== 'status') {
        throw new InvalidArgumentException('Unknown action.');
    }
    $devices = [];
    $result = wallos_webpush_query($db, 'SELECT id,device_name,device_hash,created_at,last_used_at,last_status FROM push_subscriptions WHERE user_id=:uid ORDER BY created_at,id', ['uid'=>$userId]);
    while ($device = $result->fetchArray(SQLITE3_ASSOC)) {
        $device['current'] = hash_equals($device['device_hash'], wallos_webpush_device_hash());
        unset($device['device_hash']);
        $devices[] = $device;
    }
    $keys = webpush_get_vapid_keys($db);
    if ($keys === false) throw new RuntimeException('Push keys unavailable.');
    echo json_encode(['success'=>true,'settings'=>wallos_webpush_settings($db,$userId),'devices'=>$devices,'public_key'=>$keys['public']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException | JsonException $e) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['success'=>false,'code'=>'invalid_webpush_request','message'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('Wallos Web Push request failed: ' . get_class($e));
    http_response_code(500);
    echo json_encode(['success'=>false,'code'=>'webpush_error','message'=>translate('error',$i18n)]);
}
