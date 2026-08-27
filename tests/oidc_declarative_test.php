<?php

require_once __DIR__ . '/../includes/oidc_settings.php';

function wallos_oidc_test_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$oidcEnvironmentNames = [
    'OIDC_ENABLED',
    'OIDC_PROVIDER_NAME',
    'OIDC_CLIENT_ID',
    'OIDC_CLIENT_SECRET',
    'OIDC_CLIENT_SECRET_FILE',
    'OIDC_ISSUER',
    'OIDC_AUTH_URL',
    'OIDC_TOKEN_URL',
    'OIDC_USERINFO_URL',
    'OIDC_REDIRECT_URL',
    'OIDC_LOGOUT_URL',
    'OIDC_USER_IDENTIFIER',
    'OIDC_SCOPES',
    'OIDC_AUTO_CREATE_USER',
    'OIDC_DISABLE_PASSWORD_LOGIN',
    'OIDC_REQUIRE_EMAIL_VERIFIED',
];
$originalEnvironment = [];
$secretFile = tempnam(sys_get_temp_dir(), 'wallos-oidc-secret-');

try {
    foreach ($oidcEnvironmentNames as $name) {
        $originalEnvironment[$name] = getenv($name);
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    $db = new SQLite3(':memory:');
    $db->enableExceptions(true);
    $db->exec('CREATE TABLE admin (
        id INTEGER PRIMARY KEY,
        oidc_oauth_enabled INTEGER,
        local_webhook_notifications_allowlist TEXT
    )');
    $db->exec('CREATE TABLE oauth_settings (
        id INTEGER PRIMARY KEY,
        name TEXT,
        client_id TEXT,
        client_secret TEXT,
        authorization_url TEXT,
        token_url TEXT,
        user_info_url TEXT,
        redirect_url TEXT,
        logout_url TEXT,
        user_identifier_field TEXT,
        scopes TEXT,
        auth_style TEXT,
        auto_create_user INTEGER,
        password_login_disabled INTEGER,
        require_email_verified INTEGER
    )');
    $db->exec("INSERT INTO admin VALUES (1, 0, '')");
    $db->exec("INSERT INTO oauth_settings VALUES (
        1, 'Database IdP', 'db-client', 'database-secret',
        'https://db.example/authorize', 'https://db.example/token',
        'https://db.example/userinfo', 'https://wallos.example/callback',
        'https://db.example/logout', 'sub', 'openid email', 'auto', 0, 0, 1
    )");

    $databaseConfiguration = wallos_get_effective_oidc_configuration($db);
    wallos_oidc_test_assert($databaseConfiguration['enabled'] === 0, 'Database OIDC enablement was not preserved.');
    wallos_oidc_test_assert($databaseConfiguration['is_configured'], 'A complete database configuration was rejected.');
    wallos_oidc_test_assert(
        wallos_oidc_is_http_url('https://id.example/authorize?prompt=login'),
        'A valid authorization URL with a query was rejected.'
    );
    wallos_oidc_test_assert(
        !wallos_oidc_is_http_url("https://id.example/logout\r\nX-Test: injected")
            && !wallos_oidc_is_http_url('https://user:password@id.example/authorize'),
        'OIDC URL validation accepted control characters or embedded credentials.'
    );

    file_put_contents($secretFile, "super-secret-from-file\n");
    putenv('OIDC_ENABLED=true');
    putenv('OIDC_PROVIDER_NAME=Declarative IdP');
    putenv('OIDC_CLIENT_ID=env-client');
    putenv('OIDC_CLIENT_SECRET=must-not-win');
    putenv('OIDC_CLIENT_SECRET_FILE=' . $secretFile);
    putenv('OIDC_ISSUER=https://93.184.216.34/issuer');
    putenv('OIDC_TOKEN_URL=https://tokens.example/token');
    putenv('OIDC_REDIRECT_URL=https://wallos.example/oidc-callback');
    putenv('OIDC_AUTO_CREATE_USER=yes');
    putenv('OIDC_DISABLE_PASSWORD_LOGIN=on');
    putenv('OIDC_REQUIRE_EMAIL_VERIFIED=false');

    $discoveryCalls = 0;
    $configuration = wallos_get_effective_oidc_configuration($db, [
        'discovery_fetcher' => static function ($url, $target) use (&$discoveryCalls) {
            $discoveryCalls++;
            wallos_oidc_test_assert(strpos($url, '/.well-known/openid-configuration') !== false, 'Wrong discovery URL.');
            wallos_oidc_test_assert(!empty($target['resolve']), 'Discovery was not DNS-pinned.');
            return [
                'authorization_endpoint' => 'https://issuer.example/authorize',
                'token_endpoint' => 'https://issuer.example/token',
                'userinfo_endpoint' => 'https://issuer.example/userinfo',
            ];
        },
    ]);

    wallos_oidc_test_assert($discoveryCalls === 1, 'OIDC issuer discovery did not run exactly once.');
    wallos_oidc_test_assert($configuration['enabled'] === 1 && $configuration['is_configured'], 'Declarative OIDC is not enabled/configured.');
    wallos_oidc_test_assert($configuration['settings']['name'] === 'Declarative IdP', 'Provider name override failed.');
    wallos_oidc_test_assert($configuration['settings']['client_id'] === 'env-client', 'Client ID override failed.');
    wallos_oidc_test_assert($configuration['settings']['client_secret'] === 'super-secret-from-file', 'Secret-file precedence failed.');
    wallos_oidc_test_assert($configuration['settings']['token_url'] === 'https://tokens.example/token', 'Direct endpoint override must win over discovery.');
    wallos_oidc_test_assert((int) $configuration['settings']['auto_create_user'] === 1, 'Boolean true parsing failed.');
    wallos_oidc_test_assert((int) $configuration['settings']['password_login_disabled'] === 1, 'Password-login override failed.');
    wallos_oidc_test_assert((int) $configuration['settings']['require_email_verified'] === 0, 'Verified-email override failed.');

    $publicSettings = wallos_get_oidc_public_settings($configuration);
    wallos_oidc_test_assert($publicSettings['client_secret'] === '********', 'Presentation settings must mask configured secrets.');
    wallos_oidc_test_assert($publicSettings['client_secret_configured'] === true, 'Presentation settings lost the configured flag.');
    wallos_oidc_test_assert(
        strpos(json_encode($publicSettings), 'super-secret-from-file') === false,
        'Presentation settings leaked the secret-file contents.'
    );

    $merged = wallos_merge_oidc_submitted_settings(
        ['client_secret' => 'keep-me', 'client_id' => 'old-client', 'name' => 'old-name'],
        ['client_secret' => '', 'client_id' => 'new-client', 'name' => 'submitted-name'],
        ['name' => 'OIDC_PROVIDER_NAME']
    );
    wallos_oidc_test_assert($merged['client_secret'] === 'keep-me', 'An empty secret submission erased the stored secret.');
    wallos_oidc_test_assert($merged['client_id'] === 'new-client', 'An unmanaged field was not updated.');
    wallos_oidc_test_assert($merged['name'] === 'old-name', 'An environment-managed field was overwritten.');

    $adminSource = file_get_contents(__DIR__ . '/../admin.php');
    $apiSource = file_get_contents(__DIR__ . '/../api/admin/get_oidc_settings.php');
    $logoutSource = file_get_contents(__DIR__ . '/../logout.php');
    wallos_oidc_test_assert(
        strpos($adminSource, 'type="password" id="oidcClientSecret"') !== false
            && strpos($adminSource, "htmlspecialchars(\$oidcSettings['client_secret']") === false,
        'Admin HTML must not render the effective secret.'
    );
    wallos_oidc_test_assert(
        strpos($apiSource, 'wallos_get_oidc_public_settings($oidcConfiguration)') !== false
            && strpos($apiSource, "\$oidcConfiguration['settings']") === false,
        'OIDC settings API must use the redacted presentation payload.'
    );
    wallos_oidc_test_assert(
        strpos($logoutSource, "strpos(\$logoutUrl, '?') === false ? '?' : '&'") !== false
            && strpos($logoutSource, "wallos_oidc_is_http_url(\$logoutUrl)") !== false
            && strpos($logoutSource, "http_build_query([") !== false,
        'OIDC logout must validate the URL and append parameters without creating a second question mark.'
    );

    echo "Declarative OIDC compatibility test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if (is_string($secretFile) && is_file($secretFile)) {
        @unlink($secretFile);
    }
    foreach ($originalEnvironment as $name => $value) {
        if ($value === false) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }
}

?>
