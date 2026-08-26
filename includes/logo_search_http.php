<?php

require_once __DIR__ . '/ssrf_helper.php';

function wallos_logo_search_allowed_hosts()
{
    return ['duckduckgo.com', 'search.brave.com'];
}

function wallos_logo_search_resolve_addresses($host)
{
    $host = trim((string) $host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return [$host];
    }

    $addresses = [];
    $recordType = 0;
    if (defined('DNS_A')) {
        $recordType |= DNS_A;
    }
    if (defined('DNS_AAAA')) {
        $recordType |= DNS_AAAA;
    }

    if ($recordType !== 0 && function_exists('dns_get_record')) {
        $records = @dns_get_record($host, $recordType);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $addresses[] = $record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }
    }

    if (!$addresses) {
        $ipv4Addresses = @gethostbynamel($host);
        if (is_array($ipv4Addresses)) {
            $addresses = $ipv4Addresses;
        }
    }

    if (!$addresses) {
        $fallback = gethostbyname($host);
        if ($fallback !== $host || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses[] = $fallback;
        }
    }

    $addresses = array_values(array_unique(array_filter($addresses, function ($address) {
        return filter_var($address, FILTER_VALIDATE_IP) !== false;
    })));

    // Prefer IPv4 when both families are available. The deployed container
    // may not have an IPv6 route even though DNS publishes AAAA records.
    usort($addresses, function ($left, $right) {
        return (strpos($left, ':') !== false) <=> (strpos($right, ':') !== false);
    });

    return $addresses;
}

function wallos_logo_search_validate_url($url, array $allowedHosts = null, $resolver = null)
{
    $url = trim((string) $url);
    if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url)) {
        return false;
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }

    $scheme = strtolower((string) $parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }

    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }

    $host = strtolower(trim((string) $parts['host'], '[]'));
    $allowedHosts = $allowedHosts ?? wallos_logo_search_allowed_hosts();
    $allowedHosts = array_map('strtolower', $allowedHosts);
    if (!in_array($host, $allowedHosts, true)) {
        return false;
    }

    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    if ($port < 1 || $port > 65535) {
        return false;
    }

    $addresses = $resolver === null
        ? wallos_logo_search_resolve_addresses($host)
        : call_user_func($resolver, $host);
    if (!is_array($addresses) || !$addresses) {
        return false;
    }

    $validatedAddresses = [];
    foreach ($addresses as $address) {
        $address = (string) $address;
        if (filter_var($address, FILTER_VALIDATE_IP) === false || wallos_ip_is_private_or_reserved($address)) {
            // Reject mixed public/private answers rather than selecting only
            // the convenient public answer and leaving a rebinding path.
            return false;
        }
        $validatedAddresses[] = $address;
    }

    $ip = $validatedAddresses[0];
    $resolveIp = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;

    return [
        'url' => $url,
        'scheme' => $scheme,
        'host' => $host,
        'port' => $port,
        'ip' => $ip,
        'resolve' => $host . ':' . $port . ':' . $resolveIp,
    ];
}

function wallos_logo_search_normalize_path($path)
{
    $path = (string) $path;
    $trailingSlash = $path !== '' && substr($path, -1) === '/';
    $segments = explode('/', $path);
    $normalized = [];

    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($normalized);
            continue;
        }
        $normalized[] = $segment;
    }

    $result = '/' . implode('/', $normalized);
    if ($trailingSlash && $result !== '/') {
        $result .= '/';
    }
    return $result;
}

function wallos_logo_search_resolve_redirect_url($baseUrl, $location)
{
    $location = trim((string) $location);
    if ($location === '' || preg_match('/[\x00-\x1f\x7f]/', $location)) {
        return null;
    }

    $locationParts = parse_url($location);
    if ($locationParts === false) {
        return null;
    }
    if (!empty($locationParts['scheme'])) {
        return $location;
    }

    $baseParts = parse_url((string) $baseUrl);
    if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
        return null;
    }

    $scheme = strtolower((string) $baseParts['scheme']);
    if (strncmp($location, '//', 2) === 0) {
        return $scheme . ':' . $location;
    }

    $host = (string) $baseParts['host'];
    if (strpos($host, ':') !== false && $host[0] !== '[') {
        $host = '[' . $host . ']';
    }
    $authority = $scheme . '://' . $host;
    if (isset($baseParts['port'])) {
        $authority .= ':' . (int) $baseParts['port'];
    }

    $locationPath = (string) ($locationParts['path'] ?? '');
    if ($locationPath === '') {
        $path = (string) ($baseParts['path'] ?? '/');
    } elseif ($locationPath[0] === '/') {
        $path = $locationPath;
    } else {
        $basePath = (string) ($baseParts['path'] ?? '/');
        $directory = substr($basePath, 0, strrpos($basePath, '/') + 1);
        $path = $directory . $locationPath;
    }

    $redirectUrl = $authority . wallos_logo_search_normalize_path($path);
    if (array_key_exists('query', $locationParts)) {
        $redirectUrl .= '?' . $locationParts['query'];
    } elseif ($locationPath === '' && isset($baseParts['query'])) {
        $redirectUrl .= '?' . $baseParts['query'];
    }

    return $redirectUrl;
}

function wallos_logo_search_apply_proxy($ch)
{
    // Uppercase proxy variables may originate from an HTTP Proxy header in
    // CGI/FastCGI deployments, so only explicit lowercase variables are used.
    $proxy = getenv('https_proxy')
        ?: getenv('http_proxy')
        ?: getenv('all_proxy')
        ?: null;

    if ($proxy) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        return true;
    }

    return false;
}

function wallos_logo_search_http_get($url, array $headers = [], array $allowedHosts = null, $maxRedirects = 3)
{
    $maxRedirects = max(0, min(3, (int) $maxRedirects));
    $currentUrl = (string) $url;

    for ($redirectIndex = 0; $redirectIndex <= $maxRedirects; $redirectIndex++) {
        $target = wallos_logo_search_validate_url($currentUrl, $allowedHosts);
        if ($target === false) {
            return null;
        }

        $location = null;
        $ch = curl_init();
        if ($ch === false) {
            return null;
        }

        curl_setopt($ch, CURLOPT_URL, $target['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_RESOLVE, [$target['resolve']]);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($handle, $line) use (&$location) {
            $length = strlen($line);
            if (stripos($line, 'HTTP/') === 0) {
                $location = null;
            } elseif (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, strlen('Location:')));
            }
            return $length;
        });

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if (!wallos_logo_search_apply_proxy($ch)) {
            curl_setopt($ch, CURLOPT_PROXY, '');
            curl_setopt($ch, CURLOPT_NOPROXY, '*');
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return null;
        }

        if ($httpCode >= 300 && $httpCode < 400) {
            if ($redirectIndex >= $maxRedirects || $location === null) {
                return null;
            }

            $currentUrl = wallos_logo_search_resolve_redirect_url($currentUrl, $location);
            if ($currentUrl === null) {
                return null;
            }
            continue;
        }

        return $response === '' ? null : $response;
    }

    return null;
}

?>
