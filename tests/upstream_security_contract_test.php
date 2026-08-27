<?php

function wallos_security_contract_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_security_contract_source($relativePath)
{
    $source = file_get_contents(__DIR__ . '/../' . $relativePath);
    if ($source === false) {
        throw new RuntimeException('Unable to read ' . $relativePath);
    }
    return $source;
}

try {
    $cron = wallos_security_contract_source('endpoints/cronjobs/storetotalyearlycost.php');
    $guardPosition = strpos($cron, "require_once 'validate.php';");
    $databasePosition = strpos($cron, 'connect_endpoint_crontabs.php');
    wallos_security_contract_assert(
        $guardPosition !== false && $databasePosition !== false && $guardPosition < $databasePosition,
        'The yearly-cost cron endpoint must authenticate before opening the database.'
    );

    $cronGuard = wallos_security_contract_source('endpoints/cronjobs/validate.php');
    $adminScript = wallos_security_contract_source('scripts/admin.js');
    wallos_security_contract_assert(
        strpos($cronGuard, "REQUEST_METHOD'] ?? '') !== 'POST'") !== false
            && strpos($cronGuard, 'verify_csrf_token($csrf)') !== false
            && strpos($adminScript, 'function adminPostText(') !== false
            && strpos($adminScript, 'adminPostText(url)') !== false,
        'Browser-triggered cron jobs must use POST with CSRF validation.'
    );

    foreach ([
        'endpoints/admin/savesmtpsettings.php',
        'endpoints/notifications/saveemailnotifications.php',
    ] as $path) {
        $source = wallos_security_contract_source($path);
        wallos_security_contract_assert(
            strpos($source, 'validate_smtp_host(') !== false,
            $path . ' must validate the SMTP destination before saving it.'
        );
    }

    foreach ([
        'endpoints/notifications/testemailnotifications.php',
        'endpoints/cronjobs/sendnotifications.php',
        'endpoints/cronjobs/sendcancellationnotifications.php',
        'endpoints/cronjobs/sendverificationemails.php',
        'endpoints/cronjobs/sendresetpasswordemails.php',
    ] as $path) {
        $source = wallos_security_contract_source($path);
        wallos_security_contract_assert(
            strpos($source, 'wallos_resolve_smtp_target(') !== false
                && strpos($source, 'wallos_configure_smtp_target(') !== false,
            $path . ' must connect to a DNS-pinned SMTP destination.'
        );
    }

    foreach ([
        'endpoints/cronjobs/sendnotifications.php',
        'endpoints/cronjobs/sendcancellationnotifications.php',
    ] as $path) {
        $source = wallos_security_contract_source($path);
        $guardCount = substr_count($source, 'is_url_safe_for_ssrf(');
        wallos_security_contract_assert(
            $guardCount > 0
                && substr_count($source, 'CURLOPT_RESOLVE') === $guardCount
                && substr_count($source, 'CURLOPT_CONNECTTIMEOUT') >= $guardCount
                && substr_count($source, 'CURLOPT_TIMEOUT') >= $guardCount,
            $path . ' must pin every validated notification destination and use bounded timeouts.'
        );
    }

    $ssrfHelper = wallos_security_contract_source('includes/ssrf_helper.php');
    wallos_security_contract_assert(
        strpos($ssrfHelper, "'connect_host' => \$connectHost") !== false
            && strpos($ssrfHelper, "\$mail->Host = \$target['connect_host']") !== false
            && strpos($ssrfHelper, "\$mail->SMTPOptions['ssl']['peer_name']") !== false,
        'SMTP validation must pin the connection IP while preserving TLS hostname verification.'
    );

    $logoSearchHttp = wallos_security_contract_source('includes/logo_search_http.php');
    foreach (['endpoints/logos/search.php', 'endpoints/payments/search.php'] as $path) {
        $source = wallos_security_contract_source($path);
        wallos_security_contract_assert(
            strpos($source, "require_once '../../includes/logo_search_http.php';") !== false
                && strpos($source, 'wallos_logo_search_http_get(') !== false
                && strpos($source, 'CURLOPT_FOLLOWLOCATION, true') === false,
            $path . ' must use the shared redirect-validating HTTP client.'
        );
    }

    wallos_security_contract_assert(strpos($logoSearchHttp, "getenv('HTTP_PROXY')") === false, 'Logo search trusts HTTP_PROXY.');
    wallos_security_contract_assert(strpos($logoSearchHttp, "getenv('HTTPS_PROXY')") === false, 'Logo search trusts HTTPS_PROXY.');
    wallos_security_contract_assert(strpos($logoSearchHttp, "getenv('ALL_PROXY')") === false, 'Logo search trusts ALL_PROXY.');
    wallos_security_contract_assert(
        strpos($logoSearchHttp, 'CURLOPT_FOLLOWLOCATION, false') !== false
            && strpos($logoSearchHttp, 'CURLOPT_MAXREDIRS, 0') !== false
            && strpos($logoSearchHttp, 'CURLOPT_RESOLVE') !== false
            && strpos($logoSearchHttp, 'CURLOPT_NOPROXY') !== false
            && strpos($logoSearchHttp, 'wallos_logo_search_validate_url($currentUrl') !== false,
        'Logo search redirects must be disabled in cURL and revalidated one hop at a time.'
    );

    require_once __DIR__ . '/../includes/logo_search_http.php';
    $publicResolver = function ($host) {
        return ['93.184.216.34'];
    };
    $mixedResolver = function ($host) {
        return ['93.184.216.34', '127.0.0.1'];
    };
    $validatedLogoTarget = wallos_logo_search_validate_url(
        'https://duckduckgo.com/images?q=wallos',
        wallos_logo_search_allowed_hosts(),
        $publicResolver
    );
    wallos_security_contract_assert(
        is_array($validatedLogoTarget)
            && $validatedLogoTarget['resolve'] === 'duckduckgo.com:443:93.184.216.34',
        'An allowed logo-search URL must be pinned to its validated public IP.'
    );
    wallos_security_contract_assert(
        wallos_logo_search_validate_url(
            'https://duckduckgo.com/images',
            wallos_logo_search_allowed_hosts(),
            $mixedResolver
        ) === false,
        'Mixed public/private DNS answers must be rejected.'
    );
    $relativeRedirect = wallos_logo_search_resolve_redirect_url(
        'https://duckduckgo.com/a/b?old=1',
        '../images?q=wallos'
    );
    wallos_security_contract_assert(
        $relativeRedirect === 'https://duckduckgo.com/images?q=wallos'
            && wallos_logo_search_validate_url(
                'https://127.0.0.1/internal',
                wallos_logo_search_allowed_hosts(),
                $publicResolver
            ) === false,
        'Redirect URL normalization must not escape the allowed-host policy.'
    );

    foreach ([
        'api/subscriptions/get_ical_feed.php',
        'endpoints/subscription/exportcalendar.php',
    ] as $path) {
        $source = wallos_security_contract_source($path);
        wallos_security_contract_assert(
            strpos($source, '$currency = icalEscape(') !== false
                && strpos($source, "Price: {\$currency}{\$subscription['price']}") !== false
                && strpos($source, "{\$subscription['currency']}") === false,
            $path . ' must escape custom currency text before writing DESCRIPTION.'
        );
    }

    $monthlyCost = wallos_security_contract_source('api/subscriptions/get_monthly_cost.php');
    wallos_security_contract_assert(
        strpos($monthlyCost, 'WHERE user_id = :userId LIMIT 1') !== false
            && strpos($monthlyCost, '&& $canConvertCurrency') !== false
            && strpos($monthlyCost, 'wallos_convert_price(') !== false,
        'Monthly-cost conversion must be user-scoped and keep a safe fallback.'
    );

    $startup = wallos_security_contract_source('startup.sh');
    wallos_security_contract_assert(
        strpos($startup, 'createbackup.php cleanup') === false,
        'Container startup must not delete historical backups as a side effect of an upgrade.'
    );

    foreach (['nginx.conf', 'nginx.default.conf'] as $path) {
        $nginx = wallos_security_contract_source($path);
        foreach (['/db/', '/backups/', '/.tmp/', '/images/uploads/logos/.wallos.restore.', '/images/uploads/.wallos.restore.'] as $privatePath) {
            wallos_security_contract_assert(
                strpos($nginx, 'location ^~ ' . $privatePath) !== false,
                $path . ' must deny direct access to ' . $privatePath
            );
        }
        wallos_security_contract_assert(
            strpos($nginx, '/images/uploads/logos/') !== false
                && strpos($nginx, 'database-maintenance.lock') !== false
                && strpos($nginx, '/db/.wallos-restore-transaction') !== false
                && strpos($nginx, 'return 503') !== false,
            $path . ' must hide the public Logo tree for both runtime and durable restore markers.'
        );
        $uploadedPhpDenyPosition = strpos($nginx, 'location ~* ^/images/uploads/logos/.*\\.php');
        $genericPhpPosition = strpos($nginx, 'location ~ \\.php$');
        wallos_security_contract_assert(
            $uploadedPhpDenyPosition !== false
                && $genericPhpPosition !== false
                && $uploadedPhpDenyPosition < $genericPhpPosition,
            $path . ' must reject uploaded PHP paths before the generic PHP-FPM location.'
        );
    }

    $runtimeLock = wallos_security_contract_source('includes/database_runtime_lock.php');
    wallos_security_contract_assert(
        strpos($runtimeLock, 'LOCK_SH | LOCK_NB') !== false
            && strpos($runtimeLock, 'LOCK_EX | LOCK_NB') !== false
            && strpos($runtimeLock, 'database-maintenance.lock') !== false
            && strpos($runtimeLock, 'wallos_database_maintenance_marker_exists') !== false
            && strpos($runtimeLock, '.wallos-restore-transaction') !== false,
        'Live database users and restore operations must coordinate through the runtime lock.'
    );

    $requestLogs = wallos_security_contract_source('includes/request_logs.php');
    wallos_security_contract_assert(
        strpos($requestLogs, 'wallos_database_acquire_shared_runtime_lock') !== false
            && strpos($requestLogs, 'SQLITE3_OPEN_READWRITE') !== false
            && strpos($requestLogs, 'wallos_database_release_shared_runtime_lock') !== false,
        'Shutdown request logging must not bypass restore locks or recreate a missing database.'
    );

    echo "Upstream security compatibility contracts passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

?>
