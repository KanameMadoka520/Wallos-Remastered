<?php

function wallos_request_is_https()
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https === 'on' || $https === '1') {
        return true;
    }

    $serverPort = (string) ($_SERVER['SERVER_PORT'] ?? '');
    if ($serverPort === '443') {
        return true;
    }

    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($forwardedProto === 'https') {
        return true;
    }

    $forwardedSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
    if ($forwardedSsl === 'on' || $forwardedSsl === '1') {
        return true;
    }

    $cfVisitor = (string) ($_SERVER['HTTP_CF_VISITOR'] ?? '');
    if ($cfVisitor !== '' && stripos($cfVisitor, '"https"') !== false) {
        return true;
    }

    return false;
}

function wallos_build_cookie_options($expires = null, array $extra = [])
{
    $options = [
        'path' => '/',
        'samesite' => 'Lax',
        'secure' => wallos_request_is_https(),
    ];

    if ($expires !== null) {
        $options['expires'] = (int) $expires;
    }

    foreach ($extra as $key => $value) {
        $options[$key] = $value;
    }

    return $options;
}

function wallos_build_session_cookie_params($lifetimeSeconds, array $extra = [])
{
    $params = [
        'lifetime' => max(0, (int) $lifetimeSeconds),
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => wallos_request_is_https(),
    ];

    foreach ($extra as $key => $value) {
        $params[$key] = $value;
    }

    return $params;
}

function wallos_get_direct_remote_addr()
{
    return trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
}

function wallos_request_has_forwarding_headers()
{
    $forwardedHeaders = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'HTTP_X_REAL_IP',
        'HTTP_CF_CONNECTING_IP',
        'HTTP_TRUE_CLIENT_IP',
        'HTTP_X_ORIGINAL_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
    ];

    foreach ($forwardedHeaders as $headerName) {
        if (trim((string) ($_SERVER[$headerName] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

function wallos_is_private_or_loopback_ip($ip)
{
    $ip = trim((string) $ip);
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }

        $ranges = [
            ['10.0.0.0', '10.255.255.255'],
            ['127.0.0.0', '127.255.255.255'],
            ['169.254.0.0', '169.254.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['100.64.0.0', '100.127.255.255'],
        ];

        foreach ($ranges as [$start, $end]) {
            if ($long >= ip2long($start) && $long <= ip2long($end)) {
                return true;
            }
        }

        return false;
    }

    $normalizedIp = strtolower($ip);
    if ($normalizedIp === '::1') {
        return true;
    }

    if (strpos($normalizedIp, 'fc') === 0 || strpos($normalizedIp, 'fd') === 0) {
        return true;
    }

    return strpos($normalizedIp, 'fe80:') === 0;
}

function wallos_request_allows_local_login_bypass()
{
    $directRemoteAddr = wallos_get_direct_remote_addr();
    if ($directRemoteAddr === '') {
        return false;
    }

    if (wallos_request_has_forwarding_headers()) {
        return false;
    }

    return wallos_is_private_or_loopback_ip($directRemoteAddr);
}

function wallos_request_is_api_context()
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
    return strpos($scriptName, '/api/') !== false;
}

function wallos_get_api_key_from_headers()
{
    $headerCandidates = [
        trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? '')),
        trim((string) ($_SERVER['HTTP_API_KEY'] ?? '')),
    ];

    foreach ($headerCandidates as $candidate) {
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $authorizationHeader = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($authorizationHeader !== '' && preg_match('/^Bearer\s+(.+)$/i', $authorizationHeader, $matches)) {
        return trim((string) $matches[1]);
    }

    return '';
}

function wallos_prepare_api_request_credentials()
{
    if (!wallos_request_is_api_context()) {
        return;
    }

    $headerApiKey = wallos_get_api_key_from_headers();
    $postApiKey = trim((string) ($_POST['api_key'] ?? ($_POST['apiKey'] ?? '')));
    $safeApiKey = $headerApiKey !== '' ? $headerApiKey : $postApiKey;

    unset($_GET['api_key'], $_GET['apiKey'], $_REQUEST['api_key'], $_REQUEST['apiKey']);

    if ($safeApiKey !== '') {
        $_REQUEST['api_key'] = $safeApiKey;
        $_REQUEST['apiKey'] = $safeApiKey;
    }
}
