<?php

require_once __DIR__ . '/request_logs.php';

define('WALLOS_RATE_LIMIT_NOTICE_COOKIE', 'wallos_rate_limit_notice');

function wallos_get_rate_limit_settings($db)
{
    $adminColumnsResult = $db->query("PRAGMA table_info('admin')");
    $adminColumns = [];
    while ($adminColumnsResult && ($column = $adminColumnsResult->fetchArray(SQLITE3_ASSOC))) {
        $adminColumns[] = $column['name'];
    }

    $requiredColumns = [
        'advanced_rate_limit_enabled',
        'backend_request_limit_per_minute',
        'backend_request_limit_per_hour',
        'image_upload_limit_per_minute',
        'image_upload_limit_per_hour',
        'image_upload_mb_per_minute',
        'image_upload_mb_per_hour',
        'image_download_limit_per_minute',
        'image_download_limit_per_hour',
        'image_download_mb_per_minute',
        'image_download_mb_per_hour',
    ];

    $row = [];
    if (empty(array_diff($requiredColumns, $adminColumns))) {
        $row = $db->querySingle('
            SELECT
                advanced_rate_limit_enabled,
                backend_request_limit_per_minute,
                backend_request_limit_per_hour,
                image_upload_limit_per_minute,
                image_upload_limit_per_hour,
                image_upload_mb_per_minute,
                image_upload_mb_per_hour,
                image_download_limit_per_minute,
                image_download_limit_per_hour,
                image_download_mb_per_minute,
                image_download_mb_per_hour
            FROM admin
            WHERE id = 1
        ', true);
    }

    return [
        'enabled' => !empty($row['advanced_rate_limit_enabled']),
        'backend_request_limit_per_minute' => max(1, (int) ($row['backend_request_limit_per_minute'] ?? 240)),
        'backend_request_limit_per_hour' => max(1, (int) ($row['backend_request_limit_per_hour'] ?? 3600)),
        'image_upload_limit_per_minute' => max(1, (int) ($row['image_upload_limit_per_minute'] ?? 20)),
        'image_upload_limit_per_hour' => max(1, (int) ($row['image_upload_limit_per_hour'] ?? 240)),
        'image_upload_mb_per_minute' => max(1, (int) ($row['image_upload_mb_per_minute'] ?? 120)),
        'image_upload_mb_per_hour' => max(1, (int) ($row['image_upload_mb_per_hour'] ?? 1200)),
        'image_download_limit_per_minute' => max(1, (int) ($row['image_download_limit_per_minute'] ?? 180)),
        'image_download_limit_per_hour' => max(1, (int) ($row['image_download_limit_per_hour'] ?? 2400)),
        'image_download_mb_per_minute' => max(1, (int) ($row['image_download_mb_per_minute'] ?? 300)),
        'image_download_mb_per_hour' => max(1, (int) ($row['image_download_mb_per_hour'] ?? 3000)),
    ];
}

function wallos_get_rate_limit_request_path()
{
    return '/' . ltrim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? ''), '/');
}

function wallos_rate_limit_is_exempt_user($userId)
{
    return (int) $userId === 1;
}

function wallos_rate_limit_is_admin_only_path($path)
{
    $path = '/' . ltrim((string) $path, '/');

    $adminOnlyPrefixes = [
        '/endpoints/admin/',
    ];

    foreach ($adminOnlyPrefixes as $prefix) {
        if (strpos($path, $prefix) === 0) {
            return true;
        }
    }

    $adminOnlyExactPaths = [
        '/endpoints/db/backup.php',
        '/endpoints/db/restore.php',
    ];

    return in_array($path, $adminOnlyExactPaths, true);
}

function wallos_rate_limit_is_exempt_backend_path($path)
{
    $exemptPaths = [
        '/endpoints/admin/backupstatus.php',
        '/endpoints/admin/accesslogs.php',
        '/endpoints/admin/securityanomalies.php',
    ];

    foreach ($exemptPaths as $exemptPath) {
        if (strpos((string) $path, $exemptPath) === 0) {
            return true;
        }
    }

    return false;
}

function wallos_rate_limit_now_utc()
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function wallos_rate_limit_utc_threshold_string($windowSeconds)
{
    return wallos_rate_limit_now_utc()
        ->modify('-' . (int) $windowSeconds . ' seconds')
        ->format('Y-m-d H:i:s');
}

function wallos_rate_limit_record_usage($db, $userId, $username, $category, $unitCount, $byteCount, $path)
{
    $tableExists = (bool) $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='rate_limit_usage'");
    if (!$tableExists) {
        return;
    }

    $stmt = $db->prepare('
        INSERT INTO rate_limit_usage (user_id, username, category, unit_count, byte_count, path, created_at)
        VALUES (:user_id, :username, :category, :unit_count, :byte_count, :path, :created_at)
    ');
    $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':username', substr((string) $username, 0, 255), SQLITE3_TEXT);
    $stmt->bindValue(':category', (string) $category, SQLITE3_TEXT);
    $stmt->bindValue(':unit_count', (int) $unitCount, SQLITE3_INTEGER);
    $stmt->bindValue(':byte_count', (int) $byteCount, SQLITE3_INTEGER);
    $stmt->bindValue(':path', substr((string) $path, 0, 512), SQLITE3_TEXT);
    $stmt->bindValue(':created_at', wallos_rate_limit_now_utc()->format('Y-m-d H:i:s'), SQLITE3_TEXT);
    @$stmt->execute();
}

function wallos_rate_limit_get_usage($db, $userId, $category, $windowSeconds)
{
    $tableExists = (bool) $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='rate_limit_usage'");
    if (!$tableExists) {
        return [
            'units' => 0,
            'bytes' => 0,
        ];
    }

    $stmt = $db->prepare('
        SELECT
            COALESCE(SUM(unit_count), 0) AS unit_total,
            COALESCE(SUM(byte_count), 0) AS byte_total
        FROM rate_limit_usage
        WHERE user_id = :user_id
          AND category = :category
          AND created_at >= :threshold
    ');
    $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':category', (string) $category, SQLITE3_TEXT);
    $stmt->bindValue(':threshold', wallos_rate_limit_utc_threshold_string($windowSeconds), SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    return [
        'units' => (int) ($row['unit_total'] ?? 0),
        'bytes' => (int) ($row['byte_total'] ?? 0),
    ];
}

function wallos_get_rate_limit_label($i18n, $category, $dimension, $window)
{
    $map = [
        'backend_request' => [
            'count' => [
                'minute' => translate('backend_request_limit_minute_label', $i18n),
                'hour' => translate('backend_request_limit_hour_label', $i18n),
            ],
        ],
        'subscription_image_upload' => [
            'count' => [
                'minute' => translate('image_upload_count_limit_minute_label', $i18n),
                'hour' => translate('image_upload_count_limit_hour_label', $i18n),
            ],
            'bytes' => [
                'minute' => translate('image_upload_traffic_limit_minute_label', $i18n),
                'hour' => translate('image_upload_traffic_limit_hour_label', $i18n),
            ],
        ],
        'subscription_image_download' => [
            'count' => [
                'minute' => translate('image_download_count_limit_minute_label', $i18n),
                'hour' => translate('image_download_count_limit_hour_label', $i18n),
            ],
            'bytes' => [
                'minute' => translate('image_download_traffic_limit_minute_label', $i18n),
                'hour' => translate('image_download_traffic_limit_hour_label', $i18n),
            ],
        ],
    ];

    return $map[$category][$dimension][$window] ?? translate('rate_limit_generic_label', $i18n);
}

function wallos_build_rate_limit_retry_at($windowSeconds)
{
    return (new DateTimeImmutable('now'))->modify('+' . (int) $windowSeconds . ' seconds')->format('Y-m-d H:i:s');
}

function wallos_build_rate_limit_message($i18n, $label, $retryAt)
{
    return sprintf(translate('rate_limit_triggered_message', $i18n), $label, $retryAt);
}

function wallos_log_security_anomaly($db, $userId, $username, $anomalyType, $anomalyCode, $message, array $details = [])
{
    $tableExists = (bool) $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='security_anomalies'");
    if (!$tableExists) {
        return;
    }

    $headersJson = json_encode(wallos_get_safe_request_headers(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($headersJson === false) {
        $headersJson = '{}';
    }

    $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($detailsJson === false) {
        $detailsJson = '{}';
    }

    $stmt = $db->prepare('
        INSERT INTO security_anomalies (
            user_id, username, anomaly_type, anomaly_code, message, path, method,
            ip_address, forwarded_for, user_agent, headers_json, details_json, created_at
        ) VALUES (
            :user_id, :username, :anomaly_type, :anomaly_code, :message, :path, :method,
            :ip_address, :forwarded_for, :user_agent, :headers_json, :details_json, :created_at
        )
    ');

    $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':username', substr((string) $username, 0, 255), SQLITE3_TEXT);
    $stmt->bindValue(':anomaly_type', substr((string) $anomalyType, 0, 64), SQLITE3_TEXT);
    $stmt->bindValue(':anomaly_code', substr((string) $anomalyCode, 0, 128), SQLITE3_TEXT);
    $stmt->bindValue(':message', substr((string) $message, 0, 1000), SQLITE3_TEXT);
    $stmt->bindValue(':path', substr(wallos_get_rate_limit_request_path(), 0, 512), SQLITE3_TEXT);
    $stmt->bindValue(':method', substr((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), 0, 16), SQLITE3_TEXT);
    $stmt->bindValue(':ip_address', wallos_get_request_ip_address(), SQLITE3_TEXT);
    $stmt->bindValue(':forwarded_for', wallos_get_forwarded_for_value(), SQLITE3_TEXT);
    $stmt->bindValue(':user_agent', substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000), SQLITE3_TEXT);
    $stmt->bindValue(':headers_json', substr($headersJson, 0, 20000), SQLITE3_TEXT);
    $stmt->bindValue(':details_json', substr($detailsJson, 0, 20000), SQLITE3_TEXT);
    $stmt->bindValue(':created_at', wallos_rate_limit_now_utc()->format('Y-m-d H:i:s'), SQLITE3_TEXT);
    @$stmt->execute();
}

function wallos_set_rate_limit_notice_cookie($message, $retryAt, $code)
{
    $payload = [
        'id' => bin2hex(random_bytes(6)),
        'message' => (string) $message,
        'retryAt' => (string) $retryAt,
        'code' => (string) $code,
    ];

    setcookie(WALLOS_RATE_LIMIT_NOTICE_COOKIE, rawurlencode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), [
        'expires' => time() + 180,
        'path' => '/',
        'samesite' => 'Lax',
    ]);
}

function wallos_build_rate_limit_violation($db, $userId, $username, $i18n, $category, $dimension, $window, $limitValue, $currentUnits, $currentBytes, $incomingUnits, $incomingBytes)
{
    $windowSeconds = $window === 'minute' ? 60 : 3600;
    $label = wallos_get_rate_limit_label($i18n, $category, $dimension, $window);
    $retryAt = wallos_build_rate_limit_retry_at($windowSeconds);
    $message = wallos_build_rate_limit_message($i18n, $label, $retryAt);
    $code = $category . '_' . $dimension . '_' . $window;

    wallos_log_security_anomaly($db, $userId, $username, 'rate_limit', $code, $message, [
        'category' => $category,
        'dimension' => $dimension,
        'window' => $window,
        'limit' => $limitValue,
        'current_units' => $currentUnits,
        'current_bytes' => $currentBytes,
        'incoming_units' => $incomingUnits,
        'incoming_bytes' => $incomingBytes,
        'retry_at' => $retryAt,
    ]);

    return [
        'message' => $message,
        'retry_at' => $retryAt,
        'code' => $code,
        'status' => 429,
    ];
}

function wallos_enforce_backend_request_rate_limit($db, $userId, $username, $i18n)
{
    $settings = wallos_get_rate_limit_settings($db);
    $path = wallos_get_rate_limit_request_path();
    if (
        !$settings['enabled']
        || wallos_rate_limit_is_exempt_user($userId)
        || wallos_rate_limit_is_admin_only_path($path)
        || wallos_rate_limit_is_exempt_backend_path($path)
    ) {
        return null;
    }

    $minuteUsage = wallos_rate_limit_get_usage($db, $userId, 'backend_request', 60);
    if (($minuteUsage['units'] + 1) > $settings['backend_request_limit_per_minute']) {
        return wallos_build_rate_limit_violation($db, $userId, $username, $i18n, 'backend_request', 'count', 'minute', $settings['backend_request_limit_per_minute'], $minuteUsage['units'], $minuteUsage['bytes'], 1, 0);
    }

    $hourUsage = wallos_rate_limit_get_usage($db, $userId, 'backend_request', 3600);
    if (($hourUsage['units'] + 1) > $settings['backend_request_limit_per_hour']) {
        return wallos_build_rate_limit_violation($db, $userId, $username, $i18n, 'backend_request', 'count', 'hour', $settings['backend_request_limit_per_hour'], $hourUsage['units'], $hourUsage['bytes'], 1, 0);
    }

    wallos_rate_limit_record_usage($db, $userId, $username, 'backend_request', 1, 0, $path);
    return null;
}

function wallos_enforce_subscription_image_upload_rate_limit($db, $userId, $username, $i18n, $fileCount, $byteCount)
{
    $settings = wallos_get_rate_limit_settings($db);
    if (!$settings['enabled'] || $fileCount <= 0 || wallos_rate_limit_is_exempt_user($userId)) {
        return null;
    }

    $minuteUsage = wallos_rate_limit_get_usage($db, $userId, 'subscription_image_upload', 60);
    if (($minuteUsage['units'] + $fileCount) > $settings['image_upload_limit_per_minute']) {
        return wallos_build_rate_limit_violation($db, $userId, $username, $i18n, 'subscription_image_upload', 'count', 'minute', $settings['image_upload_limit_per_minute'], $minuteUsage['units'], $minuteUsage['bytes'], $fileCount, $byteCount);
    }
    if (($minuteUsage['bytes'] + $byteCount) > ($settings['image_upload_mb_per_minute'] * 1024 * 1024)) {
        return wallos_build_rate_limit_violation($db, $userId, $username, $i18n, 'subscription_image_upload', 'bytes', 'minute', $settings['image_upload_mb_per_minute'], $minuteUsage['units'], $minuteUsage['bytes'], $fileCount, $byteCount);
    }

    $hourUsage = wallos_rate_limit_get_usage($db, $userId, 'subscription_image_upload', 3600);
    if (($hourUsage['units'] + $fileCount) > $settings['image_upload_limit_per_hour']) {
        return wallos_build_rate_limit_violation($db, $userId, $username, $i18n, 'subscription_image_upload', 'count', 'hour', $settings['image_upload_limit_per_hour'], $hourUsage['units'], $hourUsage['bytes'], $fileCount, $byteCount);
    }
    if (($hourUsage['bytes'] + $byteCount) > ($settings['image_upload_mb_per_hour'] * 1024 * 1024)) {
        return wallos_build_rate_limit_violation($db, $userId, $username, $i18n, 'subscription_image_upload', 'bytes', 'hour', $settings['image_upload_mb_per_hour'], $hourUsage['units'], $hourUsage['bytes'], $fileCount, $byteCount);
    }

    wallos_rate_limit_record_usage($db, $userId, $username, 'subscription_image_upload', $fileCount, $byteCount, wallos_get_rate_limit_request_path());
    return null;
}

function wallos_enforce_subscription_image_download_rate_limit($db, $userId, $username, $i18n, $byteCount)
{
    $settings = wallos_get_rate_limit_settings($db);
    if (!$settings['enabled'] || wallos_rate_limit_is_exempt_user($userId)) {
        return null;
    }

    $minuteUsage = wallos_rate_limit_get_usage($db, $userId, 'subscription_image_download', 60);
    if (($minuteUsage['units'] + 1) > $settings['image_download_limit_per_minute']) {
        return wallos_build_rate_limit_violation($db, $userId, $username, $i18n, 'subscription_image_download', 'count', 'minute', $settings['image_download_limit_per_minute'], $minuteUsage['units'], $minuteUsage['bytes'], 1, $byteCount);
    }
    if (($minuteUsage['bytes'] + $byteCount) > ($settings['image_download_mb_per_minute'] * 1024 * 1024)) {
        return wallos_build_rate_limit_violation($db, $userId, $username, $i18n, 'subscription_image_download', 'bytes', 'minute', $settings['image_download_mb_per_minute'], $minuteUsage['units'], $minuteUsage['bytes'], 1, $byteCount);
    }

    $hourUsage = wallos_rate_limit_get_usage($db, $userId, 'subscription_image_download', 3600);
    if (($hourUsage['units'] + 1) > $settings['image_download_limit_per_hour']) {
        return wallos_build_rate_limit_violation($db, $userId, $username, $i18n, 'subscription_image_download', 'count', 'hour', $settings['image_download_limit_per_hour'], $hourUsage['units'], $hourUsage['bytes'], 1, $byteCount);
    }
    if (($hourUsage['bytes'] + $byteCount) > ($settings['image_download_mb_per_hour'] * 1024 * 1024)) {
        return wallos_build_rate_limit_violation($db, $userId, $username, $i18n, 'subscription_image_download', 'bytes', 'hour', $settings['image_download_mb_per_hour'], $hourUsage['units'], $hourUsage['bytes'], 1, $byteCount);
    }

    wallos_rate_limit_record_usage($db, $userId, $username, 'subscription_image_download', 1, $byteCount, wallos_get_rate_limit_request_path());
    return null;
}
