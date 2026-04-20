<?php

function wallos_regression_run_public_suite(array $config, array $suiteDefinition)
{
    $client = wallos_regression_create_http_client($config);
    $results = array();

    $healthResponse = wallos_regression_http_request($client, 'GET', wallos_regression_build_url($config, 'health.php'));
    $results[] = wallos_regression_make_result(
        ($healthResponse['status'] === 200 && trim((string) $healthResponse['body']) === 'OK') ? 'PASS' : 'FAIL',
        'public',
        'health-endpoint',
        wallos_regression_build_http_detail($healthResponse, 'Expected HTTP 200 with body OK')
    );

    $loginResponse = wallos_regression_http_request($client, 'GET', wallos_regression_build_url($config, 'login.php'));
    $results[] = wallos_regression_make_result(
        ($loginResponse['status'] === 200 && wallos_regression_http_has_meta($loginResponse, 'theme-color')) ? 'PASS' : 'FAIL',
        'public',
        'login-theme-color',
        wallos_regression_build_http_detail($loginResponse, 'Expected login.php to expose meta[name="theme-color"]')
    );

    $registrationResponse = wallos_regression_http_request($client, 'GET', wallos_regression_build_url($config, 'registration.php'));
    $results[] = wallos_regression_make_result(
        ($registrationResponse['status'] === 200 && wallos_regression_http_has_meta($registrationResponse, 'theme-color')) ? 'PASS' : 'FAIL',
        'public',
        'registration-theme-color',
        wallos_regression_build_http_detail($registrationResponse, 'Expected registration.php to expose meta[name="theme-color"]')
    );

    $allJsPath = $config['repo_root'] . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'all.js';
    $allJsContents = file_exists($allJsPath) ? (string) file_get_contents($allJsPath) : '';
    $results[] = wallos_regression_make_result(
        (strpos($allJsContents, "navigator.serviceWorker.register('service-worker.js')") !== false) ? 'PASS' : 'FAIL',
        'public',
        'service-worker-registration',
        file_exists($allJsPath)
            ? 'Expected scripts/all.js to keep registering service-worker.js'
            : 'scripts/all.js not found'
    );

    $serviceWorkerPath = $config['repo_root'] . DIRECTORY_SEPARATOR . 'service-worker.js';
    $serviceWorkerContents = file_exists($serviceWorkerPath) ? (string) file_get_contents($serviceWorkerPath) : '';
    $hasCacheConstants = strpos($serviceWorkerContents, 'static-cache-v') !== false
        && strpos($serviceWorkerContents, 'pages-cache-v') !== false
        && strpos($serviceWorkerContents, 'logos-cache-v') !== false;
    $results[] = wallos_regression_make_result(
        $hasCacheConstants ? 'PASS' : 'FAIL',
        'public',
        'service-worker-cache-contract',
        file_exists($serviceWorkerPath)
            ? 'Expected service-worker.js to keep cache version constants for static/pages/logos caches'
            : 'service-worker.js not found'
    );

    $hasEndpointGuard = strpos($serviceWorkerContents, "const isEndpointRequest = isSameOrigin && url.pathname.includes('/endpoints/');") !== false
        && strpos($serviceWorkerContents, 'if (isEndpointRequest) {') !== false
        && strpos($serviceWorkerContents, 'event.respondWith(fetch(request));') !== false;
    $hasNoIgnoreSearchFallback = strpos($serviceWorkerContents, 'ignoreSearch: true') === false;
    $results[] = wallos_regression_make_result(
        ($hasEndpointGuard && $hasNoIgnoreSearchFallback) ? 'PASS' : 'FAIL',
        'public',
        'service-worker-dynamic-cache-guard',
        file_exists($serviceWorkerPath)
            ? 'Expected service-worker.js to keep endpoints network-only and avoid ignoreSearch cache fallbacks for dynamic pages.'
            : 'service-worker.js not found'
    );

    return $results;
}

function wallos_regression_run_auth_suite(array $config, array $suiteDefinition)
{
    $results = array();

    $guestClient = wallos_regression_create_http_client($config);
    $guestSubscriptionsResponse = wallos_regression_http_request(
        $guestClient,
        'GET',
        wallos_regression_build_url($config, 'endpoints/subscriptions/get.php?subscription_page=all')
    );
    $guestSubscriptionsJson = wallos_regression_http_decode_json($guestSubscriptionsResponse);
    $guestSubscriptionsClean = wallos_regression_json_session_expired_contract_is_valid($guestSubscriptionsResponse, $guestSubscriptionsJson);
    $results[] = wallos_regression_make_result(
        $guestSubscriptionsClean ? 'PASS' : 'FAIL',
        'auth',
        'subscriptions-unauth-401',
        $guestSubscriptionsClean
            ? 'Unauthenticated subscriptions/get.php returned the standardized JSON 401 contract.'
            : wallos_regression_build_json_failure_detail($guestSubscriptionsResponse, $guestSubscriptionsJson, 'Expected standardized session-expired JSON contract.')
    );

    $guestSubscriptionPagesResponse = wallos_regression_http_request(
        $guestClient,
        'GET',
        wallos_regression_build_url($config, 'endpoints/subscriptionpages.php')
    );
    $guestSubscriptionPagesJson = wallos_regression_http_decode_json($guestSubscriptionPagesResponse);
    $guestSubscriptionPagesClean = wallos_regression_json_session_expired_contract_is_valid($guestSubscriptionPagesResponse, $guestSubscriptionPagesJson);
    $results[] = wallos_regression_make_result(
        $guestSubscriptionPagesClean ? 'PASS' : 'FAIL',
        'auth',
        'subscription-pages-unauth-401',
        $guestSubscriptionPagesClean
            ? 'Unauthenticated subscriptionpages.php returned the standardized JSON 401 contract.'
            : wallos_regression_build_json_failure_detail($guestSubscriptionPagesResponse, $guestSubscriptionPagesJson, 'Expected standardized session-expired JSON contract.')
    );

    $guestPaymentsResponse = wallos_regression_http_request(
        $guestClient,
        'GET',
        wallos_regression_build_url($config, 'endpoints/payments/get.php')
    );
    $guestPaymentsJson = wallos_regression_http_decode_json($guestPaymentsResponse);
    $guestPaymentsClean = wallos_regression_json_session_expired_contract_is_valid($guestPaymentsResponse, $guestPaymentsJson);
    $results[] = wallos_regression_make_result(
        $guestPaymentsClean ? 'PASS' : 'FAIL',
        'auth',
        'payments-unauth-401',
        $guestPaymentsClean
            ? 'Unauthenticated payments/get.php returned the standardized JSON 401 contract.'
            : wallos_regression_build_json_failure_detail($guestPaymentsResponse, $guestPaymentsJson, 'Expected standardized session-expired JSON contract.')
    );

    $authState = wallos_regression_acquire_authenticated_client($config);
    $results[] = wallos_regression_make_result(
        $authState['status'],
        'auth',
        'login-or-cookie',
        $authState['detail']
    );

    if ($authState['status'] === 'SKIP') {
        $results[] = wallos_regression_make_result('SKIP', 'auth', 'subscription-pages-json', 'Skipped because no auth inputs were provided.');
        $results[] = wallos_regression_make_result('SKIP', 'auth', 'subscriptions-html', 'Skipped because no auth inputs were provided.');
        return $results;
    }

    if ($authState['status'] !== 'PASS') {
        $results[] = wallos_regression_make_result('FAIL', 'auth', 'subscription-pages-json', 'Cannot continue because the login/cookie bootstrap failed.');
        $results[] = wallos_regression_make_result('FAIL', 'auth', 'subscriptions-html', 'Cannot continue because the login/cookie bootstrap failed.');
        return $results;
    }

    $authClient = $authState['client'];
    $pagesResponse = wallos_regression_http_request(
        $authClient,
        'GET',
        wallos_regression_build_url($config, 'endpoints/subscriptionpages.php')
    );
    $pagesJson = wallos_regression_http_decode_json($pagesResponse);
    $pagesJsonValid = $pagesResponse['status'] === 200
        && $pagesJson['ok']
        && is_array($pagesJson['data'])
        && array_key_exists('success', $pagesJson['data'])
        && array_key_exists('message', $pagesJson['data'])
        && array_key_exists('pages', $pagesJson['data'])
        && array_key_exists('counts', $pagesJson['data'])
        && !empty($pagesJson['data']['success']);
    $results[] = wallos_regression_make_result(
        $pagesJsonValid ? 'PASS' : 'FAIL',
        'auth',
        'subscription-pages-json',
        $pagesJsonValid
            ? 'Authenticated subscriptionpages.php returned expected JSON keys.'
            : wallos_regression_build_json_failure_detail($pagesResponse, $pagesJson, 'Expected JSON with success/message/pages/counts.')
    );

    $subscriptionsResponse = wallos_regression_http_request(
        $authClient,
        'GET',
        wallos_regression_build_url($config, 'endpoints/subscriptions/get.php?subscription_page=all')
    );
    $subscriptionsHtmlValid = $subscriptionsResponse['status'] === 200
        && trim((string) $subscriptionsResponse['body']) !== ''
        && !wallos_regression_body_has_php_warning($subscriptionsResponse['body']);
    $results[] = wallos_regression_make_result(
        $subscriptionsHtmlValid ? 'PASS' : 'FAIL',
        'auth',
        'subscriptions-html',
        wallos_regression_build_http_detail($subscriptionsResponse, 'Expected authenticated HTML without warnings or dumped code')
    );

    return $results;
}

function wallos_regression_acquire_authenticated_client(array $config)
{
    $hasCookie = trim((string) $config['cookie']) !== '';
    $hasCredentials = trim((string) $config['username']) !== '' && trim((string) $config['password']) !== '';

    if (!$hasCookie && !$hasCredentials) {
        return array(
            'status' => 'SKIP',
            'detail' => 'No --cookie or username/password credentials were supplied. Authenticated smoke checks were skipped.',
            'client' => null,
        );
    }

    $client = wallos_regression_create_http_client($config);
    if ($hasCookie) {
        return array(
            'status' => 'PASS',
            'detail' => 'Reused cookie header from configuration.',
            'client' => $client,
        );
    }

    $loginResponse = wallos_regression_http_request(
        $client,
        'POST',
        wallos_regression_build_url($config, 'login.php'),
        array(
            'body' => http_build_query(array(
                'username' => $config['username'],
                'password' => $config['password'],
                'remember' => 'on',
            )),
        )
    );

    $hasSessionCookie = wallos_regression_http_has_cookie($client, 'PHPSESSID');
    $hasRememberCookie = wallos_regression_http_has_cookie($client, 'wallos_login');
    $loginSucceeded = ($loginResponse['status'] === 302 || $loginResponse['status'] === 303 || $loginResponse['status'] === 200)
        && ($hasSessionCookie || $hasRememberCookie)
        && !wallos_regression_body_has_php_warning($loginResponse['body']);

    return array(
        'status' => $loginSucceeded ? 'PASS' : 'FAIL',
        'detail' => $loginSucceeded
            ? 'Authenticated via login.php and captured session cookies.'
            : wallos_regression_build_http_detail($loginResponse, 'Expected login.php to set session or remember-me cookies'),
        'client' => $client,
    );
}

function wallos_regression_build_url(array $config, $path)
{
    return rtrim((string) $config['base_url'], '/') . '/' . ltrim((string) $path, '/');
}

function wallos_regression_build_http_detail(array $response, $fallbackMessage)
{
    $detail = 'HTTP ' . (int) $response['status'];
    if (!empty($response['error'])) {
        $detail .= ' (' . $response['error'] . ')';
    }

    $bodyPreview = trim(substr(preg_replace('/\s+/', ' ', (string) $response['body']), 0, 180));
    if ($bodyPreview !== '') {
        $detail .= ' | ' . $bodyPreview;
    } else {
        $detail .= ' | ' . $fallbackMessage;
    }

    return $detail;
}

function wallos_regression_build_json_failure_detail(array $response, array $decodedJson, $fallbackMessage)
{
    if ($decodedJson['ok']) {
        return 'JSON decoded, but required keys or success flag were missing.';
    }

    $errorMessage = trim((string) $decodedJson['error']);
    if ($errorMessage !== '') {
        return $fallbackMessage . ' JSON decode failed: ' . $errorMessage;
    }

    return wallos_regression_build_http_detail($response, $fallbackMessage);
}

function wallos_regression_body_has_php_warning($body)
{
    $needles = array(
        'Warning:',
        '<b>Warning</b>',
        'Fatal error',
        'Notice:',
        'Undefined variable',
        'Parse error',
    );

    foreach ($needles as $needle) {
        if (strpos((string) $body, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function wallos_regression_json_session_expired_contract_is_valid(array $response, array $decodedJson)
{
    if ((int) $response['status'] !== 401 || !$decodedJson['ok'] || !is_array($decodedJson['data'])) {
        return false;
    }

    $payload = $decodedJson['data'];
    return isset($payload['success'], $payload['code'], $payload['message'])
        && $payload['success'] === false
        && $payload['code'] === 'session_expired'
        && !empty($payload['session_expired']);
}
