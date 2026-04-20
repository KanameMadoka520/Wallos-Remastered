<?php

function wallos_regression_create_http_client(array $config = array())
{
    $client = array(
        'timeout' => isset($config['timeout']) ? max(1, (int) $config['timeout']) : 20,
        'cookies' => array(),
    );

    if (!empty($config['cookie'])) {
        $client['cookies'] = wallos_regression_parse_cookie_header($config['cookie']);
    }

    return $client;
}

function wallos_regression_parse_cookie_header($cookieHeader)
{
    $cookies = array();
    $parts = preg_split('/;\s*/', trim((string) $cookieHeader));
    foreach ($parts as $part) {
        if ($part === '' || strpos($part, '=') === false) {
            continue;
        }

        list($name, $value) = explode('=', $part, 2);
        $name = trim($name);
        if ($name === '') {
            continue;
        }

        $cookies[$name] = array(
            'value' => $value,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'expires' => null,
        );
    }

    return $cookies;
}

function wallos_regression_http_request(array &$client, $method, $url, array $options = array())
{
    $method = strtoupper((string) $method);
    $headers = array();
    if (isset($options['headers']) && is_array($options['headers'])) {
        $headers = $options['headers'];
    }

    $body = isset($options['body']) ? $options['body'] : null;
    $followRedirects = !empty($options['follow_redirects']);
    $maxRedirects = isset($options['max_redirects']) ? max(0, (int) $options['max_redirects']) : 5;

    $currentUrl = $url;
    $currentMethod = $method;
    $currentBody = $body;
    $redirects = array();

    for ($attempt = 0; $attempt <= $maxRedirects; $attempt++) {
        $response = wallos_regression_http_single_request($client, $currentMethod, $currentUrl, $headers, $currentBody);
        $response['redirects'] = $redirects;

        if (!$followRedirects) {
            return $response;
        }

        $location = wallos_regression_http_header($response, 'Location');
        if ($location === '' || !in_array((int) $response['status'], array(301, 302, 303, 307, 308), true)) {
            return $response;
        }

        $redirects[] = array(
            'status' => (int) $response['status'],
            'location' => $location,
        );

        $currentUrl = wallos_regression_resolve_redirect_url($currentUrl, $location);
        if (in_array((int) $response['status'], array(301, 302, 303), true)) {
            $currentMethod = 'GET';
            $currentBody = null;
        }
    }

    return wallos_regression_http_single_request($client, $currentMethod, $currentUrl, $headers, $currentBody);
}

function wallos_regression_http_single_request(array &$client, $method, $url, array $headers, $body)
{
    if (function_exists('curl_init')) {
        return wallos_regression_http_single_request_curl($client, $method, $url, $headers, $body);
    }

    return wallos_regression_http_single_request_stream($client, $method, $url, $headers, $body);
}

function wallos_regression_http_single_request_curl(array &$client, $method, $url, array $headers, $body)
{
    $curl = curl_init($url);
    $normalizedHeaders = wallos_regression_prepare_request_headers($client, $url, $headers, $body);

    curl_setopt_array($curl, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => (int) $client['timeout'],
        CURLOPT_CONNECTTIMEOUT => (int) $client['timeout'],
        CURLOPT_HTTPHEADER => $normalizedHeaders,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_ENCODING => '',
    ));

    if ($body !== null && $method !== 'GET') {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $rawResponse = curl_exec($curl);
    $error = '';
    if ($rawResponse === false) {
        $error = curl_error($curl);
    }

    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($rawResponse === false) {
        return array(
            'status' => 0,
            'headers' => array(),
            'header_lines' => array(),
            'body' => '',
            'error' => $error !== '' ? $error : 'Unknown cURL error',
            'effective_url' => $url,
        );
    }

    $rawHeaders = substr($rawResponse, 0, $headerSize);
    $responseBody = substr($rawResponse, $headerSize);
    $parsedHeaders = wallos_regression_parse_response_headers($rawHeaders);
    wallos_regression_store_response_cookies($client, $parsedHeaders);

    return array(
        'status' => $statusCode,
        'headers' => $parsedHeaders,
        'header_lines' => preg_split('/\r\n|\n|\r/', trim($rawHeaders)),
        'body' => $responseBody,
        'error' => '',
        'effective_url' => $url,
    );
}

function wallos_regression_http_single_request_stream(array &$client, $method, $url, array $headers, $body)
{
    $normalizedHeaders = wallos_regression_prepare_request_headers($client, $url, $headers, $body);
    $context = stream_context_create(array(
        'http' => array(
            'method' => $method,
            'header' => implode("\r\n", $normalizedHeaders),
            'content' => ($body !== null && $method !== 'GET') ? (string) $body : '',
            'timeout' => (int) $client['timeout'],
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ),
    ));

    $responseBody = @file_get_contents($url, false, $context);
    $headerLines = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : array();
    $statusCode = wallos_regression_extract_status_code_from_lines($headerLines);
    $parsedHeaders = wallos_regression_parse_response_headers(implode("\r\n", $headerLines));
    wallos_regression_store_response_cookies($client, $parsedHeaders);

    return array(
        'status' => $statusCode,
        'headers' => $parsedHeaders,
        'header_lines' => $headerLines,
        'body' => $responseBody !== false ? $responseBody : '',
        'error' => $responseBody === false ? 'HTTP stream request failed' : '',
        'effective_url' => $url,
    );
}

function wallos_regression_prepare_request_headers(array $client, $url, array $headers, $body)
{
    $normalized = array(
        'Accept: text/html,application/json;q=0.9,*/*;q=0.8',
        'User-Agent: WallosRegressionRunner/1.0',
    );

    if ($body !== null) {
        $hasContentType = false;
        foreach ($headers as $headerLine) {
            if (stripos((string) $headerLine, 'Content-Type:') === 0) {
                $hasContentType = true;
                break;
            }
        }
        if (!$hasContentType) {
            $normalized[] = 'Content-Type: application/x-www-form-urlencoded';
        }
    }

    foreach ($headers as $headerLine) {
        $normalized[] = (string) $headerLine;
    }

    $cookieHeader = wallos_regression_build_cookie_header($client['cookies'], $url);
    if ($cookieHeader !== '') {
        $normalized[] = 'Cookie: ' . $cookieHeader;
    }

    return $normalized;
}

function wallos_regression_build_cookie_header(array $cookies, $url)
{
    if (empty($cookies)) {
        return '';
    }

    $parts = parse_url($url);
    $requestPath = isset($parts['path']) ? $parts['path'] : '/';
    $pairs = array();

    foreach ($cookies as $name => $cookie) {
        if (!isset($cookie['value'])) {
            continue;
        }

        $path = isset($cookie['path']) ? (string) $cookie['path'] : '/';
        if ($path !== '/' && strpos($requestPath, $path) !== 0) {
            continue;
        }

        $pairs[] = $name . '=' . $cookie['value'];
    }

    return implode('; ', $pairs);
}

function wallos_regression_parse_response_headers($rawHeaders)
{
    $headers = array();
    $lines = preg_split('/\r\n|\n|\r/', trim((string) $rawHeaders));
    foreach ($lines as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }

        list($name, $value) = explode(':', $line, 2);
        $normalizedName = strtolower(trim($name));
        $normalizedValue = trim($value);
        if (!isset($headers[$normalizedName])) {
            $headers[$normalizedName] = array();
        }
        $headers[$normalizedName][] = $normalizedValue;
    }

    return $headers;
}

function wallos_regression_store_response_cookies(array &$client, array $headers)
{
    if (!isset($headers['set-cookie'])) {
        return;
    }

    foreach ($headers['set-cookie'] as $cookieLine) {
        $segments = preg_split('/;\s*/', $cookieLine);
        $firstSegment = array_shift($segments);
        if ($firstSegment === null || strpos($firstSegment, '=') === false) {
            continue;
        }

        list($name, $value) = explode('=', $firstSegment, 2);
        $name = trim($name);
        if ($name === '') {
            continue;
        }

        $cookie = array(
            'value' => $value,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'expires' => null,
        );

        foreach ($segments as $segment) {
            if (stripos($segment, 'Path=') === 0) {
                $cookie['path'] = (string) substr($segment, 5);
                continue;
            }
            if (stripos($segment, 'Domain=') === 0) {
                $cookie['domain'] = (string) substr($segment, 7);
                continue;
            }
            if (stripos($segment, 'Expires=') === 0) {
                $cookie['expires'] = (string) substr($segment, 8);
                continue;
            }
            if (strcasecmp($segment, 'Secure') === 0) {
                $cookie['secure'] = true;
            }
        }

        $client['cookies'][$name] = $cookie;
    }
}

function wallos_regression_http_header(array $response, $name)
{
    $values = wallos_regression_http_header_values($response, $name);
    return empty($values) ? '' : (string) $values[0];
}

function wallos_regression_http_header_values(array $response, $name)
{
    $normalizedName = strtolower((string) $name);
    if (!isset($response['headers'][$normalizedName]) || !is_array($response['headers'][$normalizedName])) {
        return array();
    }

    return $response['headers'][$normalizedName];
}

function wallos_regression_http_has_cookie(array $client, $cookieName)
{
    return isset($client['cookies'][(string) $cookieName]) && $client['cookies'][(string) $cookieName]['value'] !== '';
}

function wallos_regression_http_decode_json(array $response)
{
    $data = json_decode((string) $response['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return array(
            'ok' => false,
            'data' => null,
            'error' => json_last_error_msg(),
        );
    }

    return array(
        'ok' => true,
        'data' => $data,
        'error' => '',
    );
}

function wallos_regression_http_has_meta(array $response, $metaName)
{
    $pattern = '/<meta[^>]+name=["\']' . preg_quote((string) $metaName, '/') . '["\'][^>]*content=["\'][^"\']+["\']/i';
    return preg_match($pattern, (string) $response['body']) === 1;
}

function wallos_regression_http_body_contains(array $response, $needle)
{
    return strpos((string) $response['body'], (string) $needle) !== false;
}

function wallos_regression_extract_status_code_from_lines(array $headerLines)
{
    foreach ($headerLines as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', (string) $line, $matches)) {
            return (int) $matches[1];
        }
    }

    return 0;
}

function wallos_regression_resolve_redirect_url($currentUrl, $location)
{
    if (preg_match('#^https?://#i', (string) $location)) {
        return $location;
    }

    $currentParts = parse_url($currentUrl);
    if ($location !== '' && $location[0] === '/') {
        $port = isset($currentParts['port']) ? ':' . $currentParts['port'] : '';
        return $currentParts['scheme'] . '://' . $currentParts['host'] . $port . $location;
    }

    $basePath = isset($currentParts['path']) ? dirname($currentParts['path']) : '';
    $basePath = $basePath === DIRECTORY_SEPARATOR ? '' : $basePath;
    $port = isset($currentParts['port']) ? ':' . $currentParts['port'] : '';
    return $currentParts['scheme'] . '://' . $currentParts['host'] . $port . $basePath . '/' . ltrim($location, '/');
}
