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
    $stylesCss = wallos_regression_read_repo_file($config, 'styles/styles.css');
    $subscriptionPagesEndpoint = wallos_regression_read_repo_file($config, 'endpoints/subscriptionpages.php');
    $csrfRefreshReminderValid = wallos_regression_text_has_all($commonJs, array(
        'CSRF_BACKGROUND_STALE_MS',
        'showCsrfTokenRefreshReminder',
        'isWallosCsrfFailurePayload',
        'wallos:csrf-invalid',
        'visibilitychange',
        'showErrorMessage(message, { persistent: true })',
        'function shouldPersistToast',
        'toast-persistent',
    )) && wallos_regression_text_has_all($apiJs, array(
        'isCsrfFailurePayload',
        'csrfInvalid',
    )) && wallos_regression_text_has_all($subscriptionsJs, array(
        'showCsrfTokenRefreshReminder',
    )) && wallos_regression_text_has_all($subscriptionPagesEndpoint, array(
        "'code' => 'invalid_csrf'",
        "'error' => 'invalid_csrf'",
    )) && wallos_regression_text_has_all($stylesCss, array(
        '.toast.toast-persistent .progress',
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
    $dynamicWallpaperCss = wallos_regression_read_repo_file($config, 'styles/dynamic-wallpaper.css');
    $csrfFooterValid = wallos_regression_text_has_all($csrfPhp, array(
        'csrf_token_created_at',
        'WALLOS_CSRF_REFRESH_RECOMMENDED_SECONDS = 30 * 60',
        'function wallos_csrf_token_is_expired',
        'wallos_rotate_csrf_token',
        "unset(\$_SESSION['csrf_token'], \$_SESSION[WALLOS_CSRF_TOKEN_CREATED_AT_SESSION_KEY]);",
        'get_csrf_token_fingerprint',
        'get_csrf_token_expires_at',
        "substr(hash('sha256', generate_csrf_token()), 0, 12)",
    )) && wallos_regression_text_has_all($footerPhp, array(
        'page-edition-security-token',
        'get_csrf_token_fingerprint',
        'get_csrf_token_created_at',
        'get_csrf_token_expires_at',
        'csrf_token_footer_page_loaded',
        'csrf_token_footer_created',
        'csrf_token_footer_remaining_dynamic',
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
    )) && wallos_regression_text_has_all($commonJs, array(
        'initializeCsrfFooterStatus',
        '[data-csrf-token-remaining]',
        'expiresAtSeconds',
    ));
    $results[] = wallos_regression_make_result(
        $csrfFooterValid ? 'PASS' : 'FAIL',
        'static',
        'csrf-footer-fingerprint-contract',
        $csrfFooterValid
            ? 'Footer shows a CSRF fingerprint and estimated expiry without exposing the raw token.'
            : 'Expected CSRF helpers, footer markup, and theme styles for the footer security token fingerprint.'
    );

    $headerPhp = wallos_regression_read_repo_file($config, 'includes/header.php');
    $serviceWorkerJs = wallos_regression_read_repo_file($config, 'service-worker.js');
    $adminPhp = wallos_regression_read_repo_file($config, 'admin.php');
    $adminJs = wallos_regression_read_repo_file($config, 'scripts/admin.js');
    $cacheRefreshEndpoint = wallos_regression_read_repo_file($config, 'endpoints/admin/requestcacherefresh.php');
    $cacheRefreshValid = wallos_regression_text_has_all($headerPhp, array(
        'cache_refresh.php',
        'window.WallosCacheRefresh',
        '@filemtime(__DIR__ . \'/../scripts/common.js\')',
    )) && wallos_regression_text_has_all($serviceWorkerJs, array(
        "static-cache-v17",
        "WALLOS_CLEAR_CACHES",
        "WALLOS_CACHE_STATUS",
        "currentCaches",
        "WALLOS_CACHE_PREFIXES",
    )) && wallos_regression_text_has_all($commonJs, array(
        'initializeCacheRefreshMarker',
        'clearWallosClientCaches',
        'getWallosClientCacheStatus',
        'status: getWallosClientCacheStatus',
        'client_cache_refresh_prompt',
        'persistent: true',
        'wallos-client-cache-refresh-token',
    )) && wallos_regression_text_has_all($adminPhp, array(
        'service_worker_broadcast_refresh',
        'requestClientCacheRefreshButton',
        'admin-client-cache-state',
    )) && wallos_regression_text_has_all($adminJs, array(
        'requestClientCacheRefreshButton',
        'endpoints/admin/requestcacherefresh.php',
        'WallosClientCache',
        'WallosClientCache.status',
    )) && wallos_regression_text_has_all($cacheRefreshEndpoint, array(
        'wallos_write_cache_refresh_marker',
        'validate_endpoint_admin.php',
    ));
    $results[] = wallos_regression_make_result(
        $cacheRefreshValid ? 'PASS' : 'FAIL',
        'static',
        'service-worker-refresh-contract',
        $cacheRefreshValid
            ? 'Service Worker cache refresh marker, admin trigger, and filemtime static versions are wired.'
            : 'Expected cache refresh marker, SW clear message, admin trigger, and stricter static asset versioning.'
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
    )) && strpos($subscriptionMediaPhp, '$variantDimensions[\'width\'] === (int) $sourceWidth') === false;
    $results[] = wallos_regression_make_result(
        $imagePassthroughValid ? 'PASS' : 'FAIL',
        'static',
        'subscription-image-original-passthrough-contract',
        $imagePassthroughValid
            ? 'Uncompressed originals are stored with move_uploaded_file and any oversized variants can reuse the original.'
            : 'Expected uncompressed originals to be passed through and oversized variants to reuse the original without requiring identical dimensions.'
    );

    $subscriptionImageViewerJs = wallos_regression_read_repo_file($config, 'scripts/subscription-image-viewer.js');
    $imageSizeValid = wallos_regression_text_has_all($subscriptionsPhp, array(
        'id="subscription-image-viewer-size-thumbnail"',
        'id="subscription-image-viewer-size-preview"',
        'id="subscription-image-viewer-size-original"',
        'subscription_image_processing_strategy_info',
    )) && wallos_regression_text_has_all($subscriptionImageViewerJs, array(
        'viewerSizeThumbnail',
        'viewerSizePreview',
        'viewerSizeOriginal',
        'viewerPreviewReusedOriginal',
    )) && wallos_regression_text_has_all($subscriptionMediaPhp, array(
        'preview_reused_original',
        'thumbnail_reused_original',
    ));
    $results[] = wallos_regression_make_result(
        $imageSizeValid ? 'PASS' : 'FAIL',
        'static',
        'subscription-image-size-contract',
        $imageSizeValid
            ? 'Image viewer keeps thumbnail, preview, and original size labels.'
            : 'Expected image viewer markup and JS to keep all three size labels.'
    );

    $systemMaintenancePhp = wallos_regression_read_repo_file($config, 'includes/system_maintenance.php');
    $systemMaintenanceEndpoint = wallos_regression_read_repo_file($config, 'endpoints/admin/systemmaintenance.php');
    $maintenanceValid = wallos_regression_text_has_all($systemMaintenancePhp, array(
        'wallos_audit_subscription_image_storage',
        'wallos_get_storage_usage_summary',
        'wallos_collect_directory_usage',
        'orphan_details',
        'wallos_run_sqlite_maintenance',
        'WALLOS_REQUEST_LOG_RETENTION_DAYS',
        'VACUUM',
        'ANALYZE',
    )) && wallos_regression_text_has_all($subscriptionMediaPhp, array(
        'wallos_reuse_oversized_subscription_image_variants',
        'wallos_subscription_image_path_is_referenced',
    )) && wallos_regression_text_has_all($systemMaintenanceEndpoint, array(
        'get_storage_usage',
        'scan_subscription_images',
        'reuse_oversized_subscription_image_variants',
        'run_sqlite_maintenance',
        'validate_endpoint_admin.php',
    )) && wallos_regression_text_has_all($adminPhp, array(
        'maintenance_retention_strategy',
        'adminMaintenanceStorageSummary',
        'export_subscription_image_audit',
        'reuse_oversized_subscription_image_variants',
        'adminMaintenanceResult',
    )) && wallos_regression_text_has_all($adminJs, array(
        'runAdminMaintenanceAction',
        'renderAdminMaintenanceStorageSummary',
        'exportAdminSubscriptionImageAuditCsv',
        'formatAdminSqliteMaintenanceResult',
        'formatAdminOversizedVariantResult',
        'endpoints/admin/systemmaintenance.php',
    ));
    $results[] = wallos_regression_make_result(
        $maintenanceValid ? 'PASS' : 'FAIL',
        'static',
        'maintenance-tools-contract',
        $maintenanceValid
            ? 'Admin maintenance tools expose retention policy, subscription image audit, and SQLite maintenance.'
            : 'Expected system maintenance helper, admin endpoint, and admin UI wiring.'
    );

    $subscriptionsE2e = wallos_regression_read_repo_file($config, 'tests/e2e/subscriptions_smoke.mjs');
    $subscriptionsE2eValid = wallos_regression_text_has_all($subscriptionsE2e, array(
        'attachDiagnostics',
        'writeFailureArtifacts',
        'subscription page tabs navigate and reload cleanly',
        'add subscription saves, closes modal, and refreshes card list',
        'three-dot menu opens edit modal',
        'payment history and record-payment modal open',
        'display and value preference toggles persist by reload instead of half-rendering',
        'dynamic wallpaper mode keeps immersive toggle clickable',
        'CSRF refresh warning stays visible until manually closed',
        'restorePreferences(originalPreferences)',
        'cleanupCreatedSubscription',
    ));
    $results[] = wallos_regression_make_result(
        $subscriptionsE2eValid ? 'PASS' : 'FAIL',
        'static',
        'subscription-browser-e2e-contract',
        $subscriptionsE2eValid
            ? 'Subscription browser E2E keeps critical UI flows, diagnostics, and cleanup coverage.'
            : 'Expected tests/e2e/subscriptions_smoke.mjs to keep critical UI-flow coverage and failure artifacts.'
    );

    $adminE2e = wallos_regression_read_repo_file($config, 'tests/e2e/admin_smoke.mjs');
    $adminE2eValid = wallos_regression_text_has_all($adminE2e, array(
        'administrator shell opens cleanly',
        'runtime observability refreshes cleanly',
        'service worker cache refresh request updates observability marker',
        'client cache refresh prompt stays visible until closed',
        'access log modal opens, searches, and closes',
        'security anomaly modal opens with scoped filter and closes',
        'storage usage refresh repaints maintenance summary',
        'backup settings save and manual backup flow complete',
        'backup verification updates card state',
        'writeFailureArtifacts',
        'assertNoBrowserRuntimeErrors',
    ));
    $results[] = wallos_regression_make_result(
        $adminE2eValid ? 'PASS' : 'FAIL',
        'static',
        'admin-browser-e2e-contract',
        $adminE2eValid
            ? 'Admin browser E2E keeps maintenance, observability, logs, cache, and backup flows covered.'
            : 'Expected tests/e2e/admin_smoke.mjs to keep admin UI flow coverage and diagnostic artifacts.'
    );

    $runtimeObservabilityEndpoint = wallos_regression_read_repo_file($config, 'endpoints/admin/runtimeobservability.php');
    $runtimeObservability = wallos_regression_read_repo_file($config, 'includes/runtime_observability.php');
    $adminAccessLogsJs = wallos_regression_read_repo_file($config, 'scripts/admin-access-logs.js');
    $dynamicWallpaperCss = wallos_regression_read_repo_file($config, 'styles/dynamic-wallpaper.css');
    $adminObservabilityValid = wallos_regression_text_has_all($runtimeObservability, array(
        'wallos_get_recent_security_anomalies',
        'wallos_get_security_anomaly_type_counts',
        'wallos_format_observability_timestamp',
    )) && wallos_regression_text_has_all($runtimeObservabilityEndpoint, array(
        'validate_endpoint_admin.php',
        'recent_anomalies',
        'cache_refresh',
        'service_worker_versions',
    )) && wallos_regression_text_has_all($adminPhp, array(
        'admin-runtime-observability-ui',
        'runtime-observability-panel',
        'data-observability-feed',
        'refreshRuntimeObservabilityButton',
        "openSecurityAnomaliesModal?.({ anomaly_type: 'client_runtime' })",
        "openSecurityAnomaliesModal?.({ anomaly_type: 'request_failure' })",
    )) && wallos_regression_text_has_all($adminJs, array(
        'refreshRuntimeObservabilityButton',
        'WallosApi.postJson("endpoints/admin/runtimeobservability.php", {}',
        'endpoints/admin/runtimeobservability.php',
        'renderRuntimeObservabilityFeed',
    )) && wallos_regression_text_has_all($adminAccessLogsJs, array(
        'function escapeHtml',
        'openSecurityAnomaliesModal(initialFilters = {})',
        'ui.dataset.detailsLabel',
    )) && wallos_regression_text_has_all($stylesCss, array(
        '.runtime-observability-panel',
        '.runtime-anomaly-card',
    )) && wallos_regression_text_has_all($dynamicWallpaperCss, array(
        '.runtime-observability-panel',
        '.runtime-anomaly-card',
    ));
    $results[] = wallos_regression_make_result(
        $adminObservabilityValid ? 'PASS' : 'FAIL',
        'static',
        'admin-observability-contract',
        $adminObservabilityValid
            ? 'Admin observability keeps runtime summaries, filtered anomaly shortcuts, and safe log rendering.'
            : 'Expected admin runtime observability endpoint, UI, styling, filtered anomaly browser, and HTML escaping.'
    );

    $ssrfHelperPhp = wallos_regression_read_repo_file($config, 'includes/ssrf_helper.php');
    $logoSearchPhp = wallos_regression_read_repo_file($config, 'endpoints/logos/search.php');
    $paymentSearchPhp = wallos_regression_read_repo_file($config, 'endpoints/payments/search.php');
    $subscriptionAddPhp = wallos_regression_read_repo_file($config, 'endpoints/subscription/add.php');
    $paymentAddPhp = wallos_regression_read_repo_file($config, 'endpoints/payments/add.php');
    $aiFetchModelsPhp = wallos_regression_read_repo_file($config, 'endpoints/ai/fetch_models.php');
    $ssrfHardeningValid = wallos_regression_text_has_all($ssrfHelperPhp, array(
        'function is_cgnat_ip($ip)',
        "str_replace('::ffff:', '', \$ip)",
        'function validate_webhook_url_for_ssrf($url, $db, $i18n, $userId = null)',
        'Standard users are not permitted to use internal network addresses',
        'function is_url_safe_for_ssrf($url, $db, $userId = null)',
    )) && wallos_regression_text_has_all($logoSearchPhp, array(
        "require_once '../../includes/ssrf_helper.php';",
        'is_cgnat_ip($ip)',
        'CURLOPT_RESOLVE',
        'CURLOPT_MAXREDIRS',
    )) && wallos_regression_text_has_all($paymentSearchPhp, array(
        "require_once '../../includes/ssrf_helper.php';",
        'is_cgnat_ip($ip)',
        'CURLOPT_RESOLVE',
        'CURLOPT_MAXREDIRS',
    )) && wallos_regression_text_has_all($subscriptionAddPhp, array(
        "require_once '../../includes/ssrf_helper.php';",
        'is_cgnat_ip($ip)',
        'CURLOPT_FOLLOWLOCATION, false',
        'CURLOPT_RESOLVE',
    )) && wallos_regression_text_has_all($paymentAddPhp, array(
        "require_once '../../includes/ssrf_helper.php';",
        'is_cgnat_ip($ip)',
        'CURLOPT_FOLLOWLOCATION, false',
        'CURLOPT_RESOLVE',
    )) && wallos_regression_text_has_all($aiFetchModelsPhp, array(
        '$ssrf = null;',
        'validate_webhook_url_for_ssrf($aiOllamaHost, $db, $i18n, $userId)',
        'CURLOPT_RESOLVE',
    ));
    $results[] = wallos_regression_make_result(
        $ssrfHardeningValid ? 'PASS' : 'FAIL',
        'static',
        'upstream-4-8-3-ssrf-hardening-contract',
        $ssrfHardeningValid
            ? 'Upstream 4.8.1 SSRF and DNS rebinding hardening is preserved in Remastered endpoints.'
            : 'Expected SSRF helper, logo/payment fetchers, subscription/payment adders, and AI endpoint to keep upstream-style SSRF hardening.'
    );

    $profilePhp = wallos_regression_read_repo_file($config, 'profile.php');
    $settingsPhp = wallos_regression_read_repo_file($config, 'settings.php');
    $selfXssEscapingValid = wallos_regression_text_has_all($adminPhp, array(
        "htmlspecialchars(\$settings['server_url'], ENT_QUOTES, 'UTF-8')",
        "htmlspecialchars(\$user['email'], ENT_QUOTES, 'UTF-8')",
        "htmlspecialchars(\$oidcSettings['client_secret'], ENT_QUOTES, 'UTF-8')",
        "htmlspecialchars(\$settings['smtp_password'], ENT_QUOTES, 'UTF-8')",
    )) && wallos_regression_text_has_all($profilePhp, array(
        "htmlspecialchars(\$userData['username'], ENT_QUOTES, 'UTF-8')",
        "htmlspecialchars(\$userData['email'], ENT_QUOTES, 'UTF-8')",
        "htmlspecialchars(\$userData['api_key'], ENT_QUOTES, 'UTF-8')",
    )) && wallos_regression_text_has_all($settingsPhp, array(
        "htmlspecialchars(\$notificationsEmail['smtp_password'], ENT_QUOTES, 'UTF-8')",
        "htmlspecialchars(\$notificationsDiscord['webhook_url'], ENT_QUOTES, 'UTF-8')",
        "htmlspecialchars(\$notificationsWebhook['payload'], ENT_QUOTES, 'UTF-8')",
        "htmlspecialchars(\$category['name'], ENT_QUOTES, 'UTF-8')",
        "htmlspecialchars(\$payment['name'], ENT_QUOTES, 'UTF-8')",
    ));
    $results[] = wallos_regression_make_result(
        $selfXssEscapingValid ? 'PASS' : 'FAIL',
        'static',
        'upstream-4-8-3-self-xss-escaping-contract',
        $selfXssEscapingValid
            ? 'Private admin/profile/settings outputs keep upstream-style self-XSS escaping.'
            : 'Expected admin/profile/settings private fields to keep htmlspecialchars escaping on user-controlled values.'
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
    $subscriptionsShellResponse = wallos_regression_http_request(
        $authClient,
        'GET',
        wallos_regression_build_url($config, 'subscriptions.php')
    );
    $subscriptionsShellValid = $subscriptionsShellResponse['status'] === 200
        && strpos((string) $subscriptionsShellResponse['body'], 'id="subscriptions"') !== false
        && strpos((string) $subscriptionsShellResponse['body'], 'id="subscription-form"') !== false
        && !wallos_regression_body_has_php_warning($subscriptionsShellResponse['body']);
    $results[] = wallos_regression_make_result(
        $subscriptionsShellValid ? 'PASS' : 'FAIL',
        'auth',
        'subscriptions-page-shell',
        wallos_regression_build_http_detail($subscriptionsShellResponse, 'Expected authenticated subscriptions.php shell without warnings')
    );

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

    $subscriptionsHtml = (string) $subscriptionsResponse['body'];
    $firstSubscriptionId = wallos_regression_extract_first_subscription_id($subscriptionsHtml);
    $actionHooksValid = $subscriptionsHtmlValid
        && (
            strpos($subscriptionsHtml, 'no-matching-subscriptions') !== false
            || wallos_regression_text_has_all($subscriptionsHtml, array(
                'data-subscription-action="expand-subscription-actions"',
                'data-subscription-action="open-edit-subscription"',
                'data-subscription-action="open-payment-history"',
            ))
        );
    $results[] = wallos_regression_make_result(
        $actionHooksValid ? 'PASS' : 'FAIL',
        'auth',
        'subscription-action-hooks',
        $actionHooksValid
            ? 'Authenticated subscription HTML keeps action, edit, payment-history, and viewer hook contracts.'
            : 'Expected subscription HTML to include action-menu/edit/payment-history hooks for visible cards.'
    );

    if ($firstSubscriptionId > 0) {
        $editResponse = wallos_regression_http_request(
            $authClient,
            'GET',
            wallos_regression_build_url($config, 'endpoints/subscription/get.php?id=' . $firstSubscriptionId)
        );
        $editJson = wallos_regression_http_decode_json($editResponse);
        $editJsonValid = $editResponse['status'] === 200
            && $editJson['ok']
            && is_array($editJson['data'])
            && (int) ($editJson['data']['id'] ?? 0) === $firstSubscriptionId
            && array_key_exists('name', $editJson['data'])
            && !wallos_regression_body_has_php_warning($editResponse['body']);
        $results[] = wallos_regression_make_result(
            $editJsonValid ? 'PASS' : 'FAIL',
            'auth',
            'subscription-edit-json',
            $editJsonValid
                ? 'subscription/get.php returned editable JSON for a visible subscription.'
                : wallos_regression_build_json_failure_detail($editResponse, $editJson, 'Expected editable subscription JSON.')
        );

        $historyResponse = wallos_regression_http_request(
            $authClient,
            'GET',
            wallos_regression_build_url($config, 'endpoints/subscription/paymenthistory.php?id=' . $firstSubscriptionId)
        );
        $historyJson = wallos_regression_http_decode_json($historyResponse);
        $historyValid = $historyResponse['status'] === 200
            && $historyJson['ok']
            && is_array($historyJson['data'])
            && !empty($historyJson['data']['success'])
            && isset($historyJson['data']['subscription'], $historyJson['data']['summary'], $historyJson['data']['records'])
            && !wallos_regression_body_has_php_warning($historyResponse['body']);
        $results[] = wallos_regression_make_result(
            $historyValid ? 'PASS' : 'FAIL',
            'auth',
            'subscription-payment-history-json',
            $historyValid
                ? 'paymenthistory.php returned the modal payload for a visible subscription.'
                : wallos_regression_build_json_failure_detail($historyResponse, $historyJson, 'Expected payment-history JSON payload.')
        );
    } else {
        $results[] = wallos_regression_make_result('SKIP', 'auth', 'subscription-edit-json', 'Skipped because the authenticated account has no visible subscriptions.');
        $results[] = wallos_regression_make_result('SKIP', 'auth', 'subscription-payment-history-json', 'Skipped because the authenticated account has no visible subscriptions.');
    }

    $mediaCheck = wallos_regression_check_subscription_media_access($authClient, $config, $subscriptionsHtml);
    $results[] = $mediaCheck;

    if (!empty($config['allow_mutations'])) {
        $results[] = wallos_regression_run_mutating_subscription_flow(
            $authClient,
            $config,
            (string) $subscriptionsShellResponse['body']
        );
    } else {
        $results[] = wallos_regression_make_result(
            'SKIP',
            'auth',
            'subscription-mutating-flow',
            'Skipped by default. Re-run with --mutating-auth-checks to create/edit/delete temporary test data.'
        );
    }

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

function wallos_regression_extract_csrf_token($html)
{
    if (preg_match('/window\.csrfToken\s*=\s*"([^"]+)"/', (string) $html, $matches) !== 1) {
        return '';
    }

    return html_entity_decode((string) $matches[1], ENT_QUOTES, 'UTF-8');
}

function wallos_regression_extract_first_subscription_id($html)
{
    if (preg_match('/class="subscription[^"]*"[^>]*data-id="(\d+)"/', (string) $html, $matches) === 1) {
        return (int) $matches[1];
    }

    if (preg_match('/data-subscription-id="(\d+)"/', (string) $html, $matches) === 1) {
        return (int) $matches[1];
    }

    return 0;
}

function wallos_regression_extract_first_uploaded_image_id($html)
{
    if (preg_match('/data-uploaded-image-id="(\d+)"/', (string) $html, $matches) !== 1) {
        return 0;
    }

    return (int) $matches[1];
}

function wallos_regression_extract_selected_form_option_value($html, $selectId)
{
    $pattern = '/<select\b[^>]*id="' . preg_quote((string) $selectId, '/') . '"[^>]*>(.*?)<\/select>/is';
    if (preg_match($pattern, (string) $html, $matches) !== 1) {
        return '';
    }

    $selectHtml = (string) $matches[1];
    if (preg_match('/<option\b[^>]*value="([^"]*)"[^>]*selected[^>]*>/is', $selectHtml, $selectedMatches) === 1) {
        return html_entity_decode((string) $selectedMatches[1], ENT_QUOTES, 'UTF-8');
    }

    if (preg_match('/<option\b[^>]*value="([^"]*)"[^>]*>/is', $selectHtml, $firstMatches) === 1) {
        return html_entity_decode((string) $firstMatches[1], ENT_QUOTES, 'UTF-8');
    }

    return '';
}

function wallos_regression_post_json_with_csrf(array &$client, array $config, $path, $csrfToken, array $payload)
{
    return wallos_regression_http_request(
        $client,
        'POST',
        wallos_regression_build_url($config, $path),
        array(
            'headers' => array(
                'Content-Type: application/json',
                'X-CSRF-Token: ' . $csrfToken,
            ),
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        )
    );
}

function wallos_regression_check_subscription_media_access(array &$authClient, array $config, $subscriptionsHtml)
{
    $imageId = wallos_regression_extract_first_uploaded_image_id($subscriptionsHtml);
    if ($imageId <= 0) {
        return wallos_regression_make_result(
            'SKIP',
            'auth',
            'subscription-media-access',
            'Skipped because no uploaded subscription image is visible for this account.'
        );
    }

    $previewResponse = wallos_regression_http_request(
        $authClient,
        'GET',
        wallos_regression_build_url($config, 'endpoints/media/subscriptionimage.php?id=' . $imageId . '&variant=preview')
    );
    $originalResponse = wallos_regression_http_request(
        $authClient,
        'GET',
        wallos_regression_build_url($config, 'endpoints/media/subscriptionimage.php?id=' . $imageId . '&variant=original')
    );

    $previewType = wallos_regression_http_header($previewResponse, 'Content-Type');
    $originalType = wallos_regression_http_header($originalResponse, 'Content-Type');
    $valid = $previewResponse['status'] === 200
        && $originalResponse['status'] === 200
        && stripos($previewType, 'image/') !== false
        && stripos($originalType, 'image/') !== false
        && strlen((string) $previewResponse['body']) > 0
        && strlen((string) $originalResponse['body']) > 0;

    return wallos_regression_make_result(
        $valid ? 'PASS' : 'FAIL',
        'auth',
        'subscription-media-access',
        $valid
            ? 'Uploaded subscription image preview/original endpoints returned image content for the authenticated owner.'
            : 'Expected preview/original image endpoints to return image content. Preview: ' . wallos_regression_build_http_detail($previewResponse, 'No preview body') . ' Original: ' . wallos_regression_build_http_detail($originalResponse, 'No original body')
    );
}

function wallos_regression_run_mutating_subscription_flow(array &$authClient, array $config, $subscriptionsShellHtml)
{
    $csrfToken = wallos_regression_extract_csrf_token($subscriptionsShellHtml);
    $currencyId = wallos_regression_extract_selected_form_option_value($subscriptionsShellHtml, 'currency');
    $paymentMethodId = wallos_regression_extract_selected_form_option_value($subscriptionsShellHtml, 'payment_method');
    $payerUserId = wallos_regression_extract_selected_form_option_value($subscriptionsShellHtml, 'payer_user');
    $categoryId = wallos_regression_extract_selected_form_option_value($subscriptionsShellHtml, 'category');

    if ($csrfToken === '' || $currencyId === '' || $paymentMethodId === '' || $payerUserId === '' || $categoryId === '') {
        return wallos_regression_make_result(
            'FAIL',
            'auth',
            'subscription-mutating-flow',
            'Could not extract CSRF token or required select defaults from subscriptions.php.'
        );
    }

    $today = new DateTimeImmutable('today');
    $nextPayment = $today->modify('+1 month')->format('Y-m-d');
    $testName = 'Wallos Regression Smoke ' . gmdate('YmdHis');
    $basePayload = array(
        'csrf_token' => $csrfToken,
        'name' => $testName,
        'price' => '1.23',
        'currency_id' => $currencyId,
        'frequency' => '1',
        'cycle' => '3',
        'next_payment' => $nextPayment,
        'start_date' => $today->format('Y-m-d'),
        'payment_method_id' => $paymentMethodId,
        'payer_user_id' => $payerUserId,
        'category_id' => $categoryId,
        'notes' => 'Temporary regression subscription. Safe to delete.',
        'url' => '',
        'logo-url' => '',
        'notify_days_before' => '-1',
        'cancellation_date' => '',
        'replacement_subscription_id' => '0',
        'detail_image_urls' => '',
        'subscription_price_rules_json' => '[]',
        'remove_uploaded_image_ids' => '',
        'detail_image_order' => '',
        'manual_cycle_used_value_main' => '',
        'compress_subscription_image' => '0',
        'auto_renew' => 'on',
    );

    $addResponse = wallos_regression_http_request(
        $authClient,
        'POST',
        wallos_regression_build_url($config, 'endpoints/subscription/add.php'),
        array('body' => http_build_query($basePayload))
    );
    $addJson = wallos_regression_http_decode_json($addResponse);
    if ($addResponse['status'] !== 200 || !$addJson['ok'] || (($addJson['data']['status'] ?? '') !== 'Success')) {
        return wallos_regression_make_result(
            'FAIL',
            'auth',
            'subscription-mutating-flow',
            wallos_regression_build_json_failure_detail($addResponse, $addJson, 'Expected temporary subscription add to succeed.')
        );
    }

    $listResponse = wallos_regression_http_request(
        $authClient,
        'GET',
        wallos_regression_build_url($config, 'endpoints/subscriptions/get.php?subscription_page=all')
    );
    $subscriptionId = wallos_regression_extract_subscription_id_by_name($listResponse['body'], $testName);
    if ($subscriptionId <= 0) {
        return wallos_regression_make_result(
            'FAIL',
            'auth',
            'subscription-mutating-flow',
            'Temporary subscription was added but could not be found in refreshed subscription HTML.'
        );
    }

    $updatedName = $testName . ' Edited';
    $editPayload = $basePayload;
    $editPayload['id'] = (string) $subscriptionId;
    $editPayload['name'] = $updatedName;
    $editPayload['price'] = '2.34';
    $editResponse = wallos_regression_http_request(
        $authClient,
        'POST',
        wallos_regression_build_url($config, 'endpoints/subscription/add.php'),
        array('body' => http_build_query($editPayload))
    );
    $editJson = wallos_regression_http_decode_json($editResponse);

    $paymentResponse = wallos_regression_post_json_with_csrf(
        $authClient,
        $config,
        'endpoints/subscription/recordpayment.php',
        $csrfToken,
        array(
            'id' => $subscriptionId,
            'due_date' => $today->format('Y-m-d'),
            'paid_at' => $today->format('Y-m-d'),
            'amount_original' => 2.34,
            'currency_id' => (int) $currencyId,
            'payment_method_id' => (int) $paymentMethodId,
            'note' => 'Temporary regression payment.',
        )
    );
    $paymentJson = wallos_regression_http_decode_json($paymentResponse);

    $deleteResponse = wallos_regression_post_json_with_csrf(
        $authClient,
        $config,
        'endpoints/subscription/delete.php',
        $csrfToken,
        array('id' => $subscriptionId)
    );
    $deleteJson = wallos_regression_http_decode_json($deleteResponse);

    $permanentDeleteResponse = wallos_regression_post_json_with_csrf(
        $authClient,
        $config,
        'endpoints/subscription/permanentdelete.php',
        $csrfToken,
        array('id' => $subscriptionId)
    );
    $permanentDeleteJson = wallos_regression_http_decode_json($permanentDeleteResponse);

    $valid = $editResponse['status'] === 200
        && $editJson['ok']
        && (($editJson['data']['status'] ?? '') === 'Success')
        && $paymentResponse['status'] === 200
        && $paymentJson['ok']
        && !empty($paymentJson['data']['success'])
        && $deleteResponse['status'] === 200
        && $deleteJson['ok']
        && !empty($deleteJson['data']['success'])
        && $permanentDeleteResponse['status'] === 200
        && $permanentDeleteJson['ok']
        && !empty($permanentDeleteJson['data']['success']);

    return wallos_regression_make_result(
        $valid ? 'PASS' : 'FAIL',
        'auth',
        'subscription-mutating-flow',
        $valid
            ? 'Temporary subscription create, edit, payment record, recycle-bin move, and permanent cleanup succeeded.'
            : 'Mutating flow failed. Edit: ' . wallos_regression_build_json_failure_detail($editResponse, $editJson, 'edit failed')
                . ' Payment: ' . wallos_regression_build_json_failure_detail($paymentResponse, $paymentJson, 'payment failed')
                . ' Delete: ' . wallos_regression_build_json_failure_detail($deleteResponse, $deleteJson, 'delete failed')
                . ' Permanent delete: ' . wallos_regression_build_json_failure_detail($permanentDeleteResponse, $permanentDeleteJson, 'permanent delete failed')
    );
}

function wallos_regression_extract_subscription_id_by_name($html, $subscriptionName)
{
    $quotedName = preg_quote(htmlspecialchars((string) $subscriptionName, ENT_QUOTES, 'UTF-8'), '/');
    $pattern = '/<div class="subscription[^"]*"[^>]*data-id="(\d+)"[^>]*data-name="' . $quotedName . '"/is';
    if (preg_match($pattern, (string) $html, $matches) === 1) {
        return (int) $matches[1];
    }

    $plainPattern = '/<div class="subscription[^"]*"[^>]*data-id="(\d+)"[^>]*data-name="([^"]+)"/is';
    if (preg_match_all($plainPattern, (string) $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            if (html_entity_decode((string) $match[2], ENT_QUOTES, 'UTF-8') === (string) $subscriptionName) {
                return (int) $match[1];
            }
        }
    }

    return 0;
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
        $preview = json_encode($decodedJson['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($preview)) {
            $preview = '';
        }
        return 'JSON decoded, but required keys or success flag were missing. Body: ' . substr($preview, 0, 500);
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
