<?php

function wallos_request_logging_skip_paths()
{
    return [
        '/health.php',
        '/endpoints/cronjobs/',
        '/endpoints/db/migrate.php',
        '/endpoints/admin/accesslogs.php',
        '/endpoints/admin/clearaccesslogs.php',
        '/endpoints/admin/securityanomalies.php',
        '/endpoints/admin/clearsecurityanomalies.php',
        '/endpoints/admin/backupstatus.php',
    ];
}

function wallos_should_log_request($path)
{
    $path = '/' . ltrim((string) $path, '/');

    if (wallos_is_prefetch_request()) {
        return false;
    }

    foreach (wallos_request_logging_skip_paths() as $skipPath) {
        if (strpos($path, $skipPath) === 0) {
            return false;
        }
    }

    return true;
}

function wallos_is_prefetch_request()
{
    $headers = [
        strtolower((string) ($_SERVER['HTTP_PURPOSE'] ?? '')),
        strtolower((string) ($_SERVER['HTTP_X_PURPOSE'] ?? '')),
        strtolower((string) ($_SERVER['HTTP_SEC_PURPOSE'] ?? '')),
    ];

    foreach ($headers as $headerValue) {
        if ($headerValue !== '' && (strpos($headerValue, 'prefetch') !== false || strpos($headerValue, 'prerender') !== false)) {
            return true;
        }
    }

    return false;
}

function wallos_get_request_ip_address()
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    ];

    foreach ($headers as $headerName) {
        $value = trim((string) ($_SERVER[$headerName] ?? ''));
        if ($value !== '') {
            return substr($value, 0, 255);
        }
    }

    return '';
}

function wallos_get_forwarded_for_value()
{
    $value = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    return substr($value, 0, 512);
}

function wallos_get_safe_request_headers()
{
    if (!function_exists('getallheaders')) {
        return [];
    }

    $headers = getallheaders();
    if (!is_array($headers)) {
        return [];
    }

    $blockedHeaders = [
        'cookie',
        'authorization',
        'x-csrf-token',
        'proxy-authorization',
    ];

    $safeHeaders = [];
    foreach ($headers as $name => $value) {
        $normalizedName = strtolower((string) $name);
        if (in_array($normalizedName, $blockedHeaders, true)) {
            continue;
        }

        $safeHeaders[$name] = substr((string) $value, 0, 1000);
    }

    return $safeHeaders;
}

function wallos_log_request($db, $userId = 0, $username = '')
{
    static $requestLogged = false;

    if ($requestLogged) {
        return;
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    if (!wallos_should_log_request($path)) {
        return;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $headersJson = json_encode(wallos_get_safe_request_headers(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($headersJson === false) {
        $headersJson = '{}';
    }

    $stmt = $db->prepare('
        INSERT INTO request_logs (
            user_id, username, path, method, ip_address, forwarded_for, user_agent, headers_json
        ) VALUES (
            :user_id, :username, :path, :method, :ip_address, :forwarded_for, :user_agent, :headers_json
        )
    ');
    if ($stmt === false) {
        return;
    }

    $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':username', substr((string) $username, 0, 255), SQLITE3_TEXT);
    $stmt->bindValue(':path', substr((string) $path, 0, 512), SQLITE3_TEXT);
    $stmt->bindValue(':method', substr((string) $method, 0, 16), SQLITE3_TEXT);
    $stmt->bindValue(':ip_address', wallos_get_request_ip_address(), SQLITE3_TEXT);
    $stmt->bindValue(':forwarded_for', wallos_get_forwarded_for_value(), SQLITE3_TEXT);
    $stmt->bindValue(':user_agent', substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000), SQLITE3_TEXT);
    $stmt->bindValue(':headers_json', substr($headersJson, 0, 20000), SQLITE3_TEXT);
    @$stmt->execute();

    $requestLogged = true;
}
