<?php

function wallos_get_ssrf_allowlist_env_value()
{
    $value = getenv('SSRF_ALLOWLIST');
    if ($value !== false) {
        return $value;
    }

    if (array_key_exists('SSRF_ALLOWLIST', $_ENV)) {
        return $_ENV['SSRF_ALLOWLIST'];
    }

    if (array_key_exists('SSRF_ALLOWLIST', $_SERVER)) {
        return $_SERVER['SSRF_ALLOWLIST'];
    }

    return null;
}

function wallos_get_effective_ssrf_allowlist($db)
{
    $stmt = $db->prepare('SELECT local_webhook_notifications_allowlist FROM admin LIMIT 1');
    $result = $stmt ? $stmt->execute() : false;
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    $dbValue = $row ? ($row['local_webhook_notifications_allowlist'] ?? '') : '';

    $envValue = wallos_get_ssrf_allowlist_env_value();
    $isManaged = $envValue !== null && trim((string) $envValue) !== '';
    $rawValue = $isManaged ? $envValue : $dbValue;

    return array_values(array_filter(array_map('trim', explode(',', (string) $rawValue))));
}

function wallos_extract_embedded_ipv4($ip)
{
    $packed = @inet_pton((string) $ip);
    if ($packed === false || strlen($packed) !== 16) {
        return null;
    }

    $bytes = unpack('C16', $packed);
    $high80Zero = true;
    for ($index = 1; $index <= 10; $index++) {
        if ($bytes[$index] !== 0) {
            $high80Zero = false;
            break;
        }
    }

    if ($high80Zero && $bytes[11] === 0xff && $bytes[12] === 0xff) {
        return "$bytes[13].$bytes[14].$bytes[15].$bytes[16]";
    }

    // NAT64 64:ff9b::/96.
    if ($bytes[1] === 0x00 && $bytes[2] === 0x64 && $bytes[3] === 0xff && $bytes[4] === 0x9b) {
        return "$bytes[13].$bytes[14].$bytes[15].$bytes[16]";
    }

    // 6to4 2002::/16.
    if ($bytes[1] === 0x20 && $bytes[2] === 0x02) {
        return "$bytes[3].$bytes[4].$bytes[5].$bytes[6]";
    }

    // Teredo 2001:0000::/32 stores the client address inverted.
    if ($bytes[1] === 0x20 && $bytes[2] === 0x01 && $bytes[3] === 0x00 && $bytes[4] === 0x00) {
        return sprintf('%d.%d.%d.%d', ~$bytes[13] & 0xff, ~$bytes[14] & 0xff, ~$bytes[15] & 0xff, ~$bytes[16] & 0xff);
    }

    return null;
}

function wallos_ip_is_private_or_reserved($ip)
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false || is_cgnat_ip($ip)) {
        return true;
    }

    return wallos_extract_embedded_ipv4($ip) !== null;
}

/**
 * Checks if an IP falls in the RFC 6598 Carrier-Grade NAT range (100.64.0.0/10).
 * PHP's FILTER_FLAG_NO_PRIV_RANGE does not cover this range.
 * Used by Tailscale and corporate CGNAT environments.
 */
function is_cgnat_ip($ip) {
    // Handle IPv4-mapped IPv6 addresses (::ffff:100.64.0.1)
    if (strpos($ip, ':') !== false) {
        $ip = str_replace('::ffff:', '', $ip);
    }

    $long = ip2long($ip);
    return $long !== false
        && $long >= ip2long('100.64.0.0')
        && $long <= ip2long('100.127.255.255');
}

/**
 * Validates a webhook URL against SSRF attacks and checks the admin allowlist.
 * If validation fails, it kills the script and outputs a JSON error response.
 * * @param string $url The destination URL to check
 * @param SQLite3 $db The database connection
 * @param array $i18n The translation array
 * @return array Returns an array with ['host', 'ip', 'port'] for cURL hardening
 */
function validate_webhook_url_for_ssrf($url, $db, $i18n, $userId = null) {
    $parsedUrl = parse_url($url);
    
    // Fallback if parse_url fails completely
    if (!$parsedUrl || !isset($parsedUrl['host'])) {
        die(json_encode([
            "success" => false,
            "message" => translate("error", $i18n)
        ]));
    }

    $urlHost = $parsedUrl['host'];
    $port = $parsedUrl['port'] ?? '';
    $ip = gethostbyname($urlHost);

    // CATCH DNS FAILURES
    if ($ip === $urlHost && filter_var($urlHost, FILTER_VALIDATE_IP) === false) {
        die(json_encode([
            "success" => false,
            "message" => "Error: Could not resolve the hostname. Please check the URL or your server's DNS."
        ]));
    }

    $hostWithPort = $port ? $urlHost . ':' . $port : $urlHost;
    $ipWithPort = $port ? $ip . ':' . $port : $ip;

    // Check if it's a private IP
    $is_private = wallos_ip_is_private_or_reserved($ip);

    if ($is_private) {
        if ($userId != 1) {
            die(json_encode([
                "success" => false,
                "message" => "Security Block: Standard users are not permitted to use internal network addresses."
            ]));
        }

        $allowlist = wallos_get_effective_ssrf_allowlist($db);
        
        if (!in_array($urlHost, $allowlist) && 
            !in_array($ip, $allowlist) && 
            !in_array($hostWithPort, $allowlist) && 
            !in_array($ipWithPort, $allowlist)) {
            
            die(json_encode([
                "success" => false,
                "message" => "Security Block: The target IP/Port is private and not present in the Webhook Allowlist."
            ]));
        }
    }

    // Determine the exact port being targeted for cURL DNS rebinding protection
    $targetPort = $port ?: (strtolower($parsedUrl['scheme'] ?? 'http') === 'https' ? 443 : 80);

    return [
        'host' => $urlHost,
        'ip'   => $ip,
        'port' => $targetPort
    ];
}

/**
 * Non-fatal variant for use in cron jobs (sendnotifications.php).
 * Returns the same ['host', 'ip', 'port'] array on success, or false on failure.
 * Never calls die() — caller should use continue/skip on false.
 * Respects the admin allowlist for private IPs, just like the main function.
 *
 * @param string $url The destination URL to check
 * @param SQLite3 $db The database connection
 * @return array|false
 */
function is_url_safe_for_ssrf($url, $db, $userId = null) {
    $parsedUrl = parse_url($url);
    if (!$parsedUrl || !isset($parsedUrl['host'])) return false;

    $scheme = strtolower($parsedUrl['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'])) return false;

    $urlHost = $parsedUrl['host'];
    $port    = $parsedUrl['port'] ?? '';
    $ip      = gethostbyname($urlHost);

    // DNS failure
    if ($ip === $urlHost && filter_var($urlHost, FILTER_VALIDATE_IP) === false) return false;

    $hostWithPort = $port ? $urlHost . ':' . $port : $urlHost;
    $ipWithPort   = $port ? $ip . ':' . $port : $ip;

    $is_private = wallos_ip_is_private_or_reserved($ip);

    if ($is_private) {
        if ($userId != 1) {
            return false; // private and user is not admin — skip silently
        }

        $allowlist = wallos_get_effective_ssrf_allowlist($db);

        if (
            !in_array($urlHost, $allowlist) &&
            !in_array($ip, $allowlist) &&
            !in_array($hostWithPort, $allowlist) &&
            !in_array($ipWithPort, $allowlist)
        ) {
            return false; // private and not in allowlist — skip silently
        }
    }

    $targetPort = $port ?: ($scheme === 'https' ? 443 : 80);

    return [
        'host' => $urlHost,
        'ip'   => $ip,
        'port' => $targetPort
    ];
}

/**
 * Validates an administrator-configured OIDC endpoint and returns a
 * DNS-pinned target for cURL. Private destinations require the same explicit
 * allowlist as webhook and notification endpoints.
 */
function validate_oidc_endpoint_url($url, $db) {
    $parsed = parse_url((string) $url);
    if (!$parsed || !isset($parsed['host'])) {
        return false;
    }

    $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }

    $host = (string) $parsed['host'];
    $port = isset($parsed['port']) ? (int) $parsed['port'] : ($scheme === 'https' ? 443 : 80);
    if ($port < 1 || $port > 65535) {
        return false;
    }

    $ip = gethostbyname($host);
    if ($ip === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    if (wallos_ip_is_private_or_reserved($ip)) {
        $allowlist = wallos_get_effective_ssrf_allowlist($db);
        $hostWithPort = $host . ':' . $port;
        $ipWithPort = $ip . ':' . $port;
        if (!in_array($host, $allowlist, true)
            && !in_array($ip, $allowlist, true)
            && !in_array($hostWithPort, $allowlist, true)
            && !in_array($ipWithPort, $allowlist, true)) {
            return false;
        }
    }

    return [
        'host' => $host,
        'ip' => $ip,
        'port' => $port,
        'resolve' => $host . ':' . $port . ':' . $ip,
    ];
}
