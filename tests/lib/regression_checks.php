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
    $loginThemeColor = strtolower(wallos_regression_http_meta_content($loginResponse, 'theme-color'));
    $results[] = wallos_regression_make_result(
        ($loginResponse['status'] === 200 && wallos_regression_http_has_meta($loginResponse, 'theme-color')) ? 'PASS' : 'FAIL',
        'public',
        'login-theme-color',
        wallos_regression_build_http_detail($loginResponse, 'Expected login.php to expose meta[name="theme-color"]')
    );
    $results[] = wallos_regression_make_result(
        ($loginResponse['status'] === 200 && $loginThemeColor === '#6d4aff') ? 'PASS' : 'FAIL',
        'public',
        'login-default-purple-theme',
        $loginThemeColor !== ''
            ? 'Resolved theme-color: ' . $loginThemeColor
            : wallos_regression_build_http_detail($loginResponse, 'Expected public login default theme-color #6D4AFF')
    );

    $registrationResponse = wallos_regression_http_request($client, 'GET', wallos_regression_build_url($config, 'registration.php'));
    $registrationThemeColor = strtolower(wallos_regression_http_meta_content($registrationResponse, 'theme-color'));
    $results[] = wallos_regression_make_result(
        ($registrationResponse['status'] === 200 && wallos_regression_http_has_meta($registrationResponse, 'theme-color')) ? 'PASS' : 'FAIL',
        'public',
        'registration-theme-color',
        wallos_regression_build_http_detail($registrationResponse, 'Expected registration.php to expose meta[name="theme-color"]')
    );
    $results[] = wallos_regression_make_result(
        ($registrationResponse['status'] === 200 && $registrationThemeColor === '#6d4aff') ? 'PASS' : 'FAIL',
        'public',
        'registration-default-purple-theme',
        $registrationThemeColor !== ''
            ? 'Resolved theme-color: ' . $registrationThemeColor
            : wallos_regression_build_http_detail($registrationResponse, 'Expected public registration default theme-color #6D4AFF')
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

function wallos_regression_run_static_suite(array $config, array $suiteDefinition)
{
    $results = array();

    $settingsDefaults = wallos_regression_read_repo_file($config, 'includes/settings_defaults.php');
    $themeColor = wallos_regression_read_repo_file($config, 'includes/theme_color.php');
    $defaultThemeValid = wallos_regression_text_has_all($settingsDefaults, array(
        "'color_theme' => 'purple'",
        "'page_transition_enabled' => 1",
        "'page_transition_style' => 'bluearchive_theme'",
    )) && strpos($themeColor, "'purple' => '#6D4AFF'") !== false;
    $results[] = wallos_regression_make_result(
        $defaultThemeValid ? 'PASS' : 'FAIL',
        'static',
        'default-theme-contract',
        $defaultThemeValid
            ? 'Default settings keep purple theme and Blue Archive transition enabled for new users.'
            : 'Expected purple theme defaults and Blue Archive transition defaults in settings/theme helpers.'
    );

    $subscriptionsPhp = wallos_regression_read_repo_file($config, 'subscriptions.php');
    $subscriptionDomNeedles = array(
        'id="main-actions"',
        'id="subscription-page-tabs"',
        'id="subscription-page-loading-overlay"',
        'id="subscriptions"',
        'id="subscription-pages-manager-modal"',
        'id="subscription-recycle-bin-modal"',
        'id="subscription-form"',
        'data-subscription-action="open-add-subscription"',
        'data-subscription-action="select-page-filter"',
        'data-subscription-action="open-pages-manager"',
        'data-subscription-action="set-display-columns"',
        'data-subscription-action="toggle-value-metric"',
        'data-subscription-action="open-recycle-bin"',
        'data-subscription-action="open-image-original"',
    );
    $domMissing = wallos_regression_missing_needles($subscriptionsPhp, $subscriptionDomNeedles);
    $results[] = wallos_regression_make_result(
        empty($domMissing) ? 'PASS' : 'FAIL',
        'static',
        'subscription-page-dom-contract',
        empty($domMissing)
            ? 'subscriptions.php keeps critical controls, modals, and action hooks.'
            : 'Missing DOM/action hooks: ' . implode(', ', $domMissing)
    );

    $subscriptionModules = array(
        'scripts/libs/sortable.min.js',
        'scripts/subscription-pages.js',
        'scripts/subscription-preferences.js',
        'scripts/subscription-media.js',
        'scripts/subscription-image-viewer.js',
        'scripts/subscription-price-rules.js',
        'scripts/subscription-layout.js',
        'scripts/subscription-payments.js',
        'scripts/subscription-interactions.js',
        'scripts/subscriptions.js',
    );
    $moduleOrderValid = wallos_regression_text_has_ordered_needles($subscriptionsPhp, $subscriptionModules);
    $results[] = wallos_regression_make_result(
        $moduleOrderValid ? 'PASS' : 'FAIL',
        'static',
        'subscription-module-load-order',
        $moduleOrderValid
            ? 'Subscription scripts load in dependency order.'
            : 'Expected subscription scripts to load in the documented dependency order.'
    );

    $subscriptionPagesJs = wallos_regression_read_repo_file($config, 'scripts/subscription-pages.js');
    $subscriptionsJs = wallos_regression_read_repo_file($config, 'scripts/subscriptions.js');
    $subscriptionInteractionsJs = wallos_regression_read_repo_file($config, 'scripts/subscription-interactions.js');
    $frontendLifecycleValid = wallos_regression_text_has_all($subscriptionPagesJs, array(
        'window.WallosHttp.getJson',
        'window.WallosHttp.postJson',
        'setPageLoadingState',
        'replaceState',
    )) && wallos_regression_text_has_all($subscriptionsJs, array(
        'window.WallosApi.getText',
        'isSessionFailureError',
        'WallosSubscriptionInteractions',
        'scheduleSubscriptionMasonryLayout',
    )) && wallos_regression_text_has_all($subscriptionInteractionsJs, array(
        'bindExpandActionButtons',
        'dataset.expandActionBound',
        'actions-menu-open',
    ));
    $results[] = wallos_regression_make_result(
        $frontendLifecycleValid ? 'PASS' : 'FAIL',
        'static',
        'subscription-frontend-lifecycle-contract',
        $frontendLifecycleValid
            ? 'Subscription page scripts keep shared HTTP calls, loading state, session handling, and rebinding hooks.'
            : 'Expected subscription scripts to keep shared HTTP calls, session handling, and interaction rebinding hooks.'
    );

    $commonJs = wallos_regression_read_repo_file($config, 'scripts/common.js');
    $apiJs = wallos_regression_read_repo_file($config, 'scripts/api.js');
    $subscriptionPagesEndpoint = wallos_regression_read_repo_file($config, 'endpoints/subscriptionpages.php');
    $csrfRefreshReminderValid = wallos_regression_text_has_all($commonJs, array(
        'CSRF_BACKGROUND_STALE_MS',
        'showCsrfTokenRefreshReminder',
        'isWallosCsrfFailurePayload',
        'wallos:csrf-invalid',
        'visibilitychange',
    )) && wallos_regression_text_has_all($apiJs, array(
        'isCsrfFailurePayload',
        'csrfInvalid',
    )) && wallos_regression_text_has_all($subscriptionsJs, array(
        'showCsrfTokenRefreshReminder',
    )) && wallos_regression_text_has_all($subscriptionPagesEndpoint, array(
        "'code' => 'invalid_csrf'",
        "'error' => 'invalid_csrf'",
    ));
    $results[] = wallos_regression_make_result(
        $csrfRefreshReminderValid ? 'PASS' : 'FAIL',
        'static',
        'csrf-refresh-reminder-contract',
        $csrfRefreshReminderValid
            ? 'Long-idle foreground recovery and invalid CSRF responses keep the refresh reminder path.'
            : 'Expected common/api/subscription scripts and subscriptionpages.php to keep invalid CSRF refresh reminder hooks.'
    );

    $csrfPhp = wallos_regression_read_repo_file($config, 'libs/csrf.php');
    $footerPhp = wallos_regression_read_repo_file($config, 'includes/footer.php');
    $stylesCss = wallos_regression_read_repo_file($config, 'styles/styles.css');
    $dynamicWallpaperCss = wallos_regression_read_repo_file($config, 'styles/dynamic-wallpaper.css');
    $csrfFooterValid = wallos_regression_text_has_all($csrfPhp, array(
        'csrf_token_created_at',
        'WALLOS_CSRF_REFRESH_RECOMMENDED_SECONDS = 30 * 60',
        'get_csrf_token_fingerprint',
        'get_csrf_token_expires_at',
        "substr(hash('sha256', generate_csrf_token()), 0, 12)",
    )) && wallos_regression_text_has_all($footerPhp, array(
        'page-edition-security-token',
        'get_csrf_token_fingerprint',
        'get_csrf_token_expires_at',
        "settings['user_timezone']",
        'wallos_get_timezone_offset_label',
        'csrf_token_footer_label',
        'csrf_token_footer_expires',
    )) && wallos_regression_text_has_all($stylesCss, array(
        '.page-edition-security-token',
        '.page-edition-security-token code',
    )) && wallos_regression_text_has_all($dynamicWallpaperCss, array(
        'body.dynamic-wallpaper-enabled .page-edition-security-token',
        'body.dynamic-wallpaper-enabled .page-edition-security-token code',
    ));
    $results[] = wallos_regression_make_result(
        $csrfFooterValid ? 'PASS' : 'FAIL',
        'static',
        'csrf-footer-fingerprint-contract',
        $csrfFooterValid
            ? 'Footer shows a CSRF fingerprint and estimated expiry without exposing the raw token.'
            : 'Expected CSRF helpers, footer markup, and theme styles for the footer security token fingerprint.'
    );

    $requestSecurity = wallos_regression_read_repo_file($config, 'includes/request_security.php');
    $connectEndpoint = wallos_regression_read_repo_file($config, 'includes/connect_endpoint.php');
    $apiTransportValid = wallos_regression_text_has_all($requestSecurity, array(
        'function wallos_get_api_key_from_headers',
        'HTTP_X_API_KEY',
        'HTTP_AUTHORIZATION',
        'Bearer',
        "unset(\$_GET['api_key'], \$_GET['apiKey'], \$_REQUEST['api_key'], \$_REQUEST['apiKey']);",
    )) && wallos_regression_text_has_all($connectEndpoint, array(
        "require_once __DIR__ . '/request_security.php';",
        'wallos_prepare_api_request_credentials();',
    ));
    $results[] = wallos_regression_make_result(
        $apiTransportValid ? 'PASS' : 'FAIL',
        'static',
        'api-key-transport-contract',
        $apiTransportValid
            ? 'API key handling still strips query parameters and prefers headers/POST.'
            : 'Expected request_security/connect_endpoint to keep header-first API key handling and query stripping.'
    );

    $subscriptionMediaPhp = wallos_regression_read_repo_file($config, 'includes/subscription_media.php');
    $imagePassthroughValid = wallos_regression_text_has_all($subscriptionMediaPhp, array(
        'move_uploaded_file($uploadedFile[\'tmp_name\'], $destination)',
        '$writeResult = wallos_write_subscription_image_resource($imageToWrite, $destination, $metadata[\'mime\'], true);',
        '$variantFileSize >= $sourceFileSize',
        "'reused_original' => true",
    ));
    $results[] = wallos_regression_make_result(
        $imagePassthroughValid ? 'PASS' : 'FAIL',
        'static',
        'subscription-image-original-passthrough-contract',
        $imagePassthroughValid
            ? 'Uncompressed originals are stored with move_uploaded_file and oversized same-dimension variants can reuse the original.'
            : 'Expected uncompressed originals to be passed through and same-dimension oversized variants to reuse the original.'
    );

    $subscriptionImageViewerJs = wallos_regression_read_repo_file($config, 'scripts/subscription-image-viewer.js');
    $imageSizeValid = wallos_regression_text_has_all($subscriptionsPhp, array(
        'id="subscription-image-viewer-size-thumbnail"',
        'id="subscription-image-viewer-size-preview"',
        'id="subscription-image-viewer-size-original"',
    )) && wallos_regression_text_has_all($subscriptionImageViewerJs, array(
        'viewerSizeThumbnail',
        'viewerSizePreview',
        'viewerSizeOriginal',
    ));
    $results[] = wallos_regression_make_result(
        $imageSizeValid ? 'PASS' : 'FAIL',
        'static',
        'subscription-image-size-contract',
        $imageSizeValid
            ? 'Image viewer keeps thumbnail, preview, and original size labels.'
            : 'Expected image viewer markup and JS to keep all three size labels.'
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

function wallos_regression_read_repo_file(array $config, $relativePath)
{
    $absolutePath = $config['repo_root'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $relativePath);
    if (!is_file($absolutePath)) {
        return '';
    }

    $contents = file_get_contents($absolutePath);
    return $contents === false ? '' : (string) $contents;
}

function wallos_regression_text_has_all($text, array $needles)
{
    foreach ($needles as $needle) {
        if (strpos((string) $text, (string) $needle) === false) {
            return false;
        }
    }

    return true;
}

function wallos_regression_missing_needles($text, array $needles)
{
    $missing = array();
    foreach ($needles as $needle) {
        if (strpos((string) $text, (string) $needle) === false) {
            $missing[] = (string) $needle;
        }
    }

    return $missing;
}

function wallos_regression_text_has_ordered_needles($text, array $needles)
{
    $offset = 0;
    foreach ($needles as $needle) {
        $position = strpos((string) $text, (string) $needle, $offset);
        if ($position === false) {
            return false;
        }

        $offset = $position + strlen((string) $needle);
    }

    return true;
}
