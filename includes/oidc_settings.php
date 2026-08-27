<?php

require_once __DIR__ . '/ssrf_helper.php';

function wallos_get_oidc_defaults()
{
    return [
        'name' => '',
        'client_id' => '',
        'client_secret' => '',
        'authorization_url' => '',
        'token_url' => '',
        'user_info_url' => '',
        'redirect_url' => '',
        'logout_url' => '',
        'user_identifier_field' => 'sub',
        'scopes' => 'openid email profile',
        'auth_style' => 'auto',
        'auto_create_user' => 0,
        'password_login_disabled' => 0,
        'require_email_verified' => 1,
    ];
}

function wallos_get_oidc_env_value($name)
{
    $value = getenv($name);
    if ($value !== false) {
        return $value;
    }

    if (array_key_exists($name, $_ENV)) {
        return $_ENV[$name];
    }

    if (array_key_exists($name, $_SERVER)) {
        return $_SERVER[$name];
    }

    return null;
}

function wallos_has_oidc_env_value($name)
{
    return wallos_get_oidc_env_value($name) !== null;
}

function wallos_parse_oidc_boolean($value)
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return 1;
    }

    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return 0;
    }

    return null;
}

function wallos_oidc_is_http_url($value)
{
    $value = trim((string) $value);
    if ($value === '' || preg_match('/[\x00-\x1f\x7f]/', $value)) {
        return false;
    }

    $parts = parse_url($value);
    if (!$parts || !isset($parts['scheme'], $parts['host'])) {
        return false;
    }

    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }

    if (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535)) {
        return false;
    }

    return in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true);
}

function wallos_get_db_oidc_settings($db)
{
    $settings = wallos_get_oidc_defaults();
    $stmt = $db->prepare('SELECT * FROM oauth_settings WHERE id = 1');
    $result = $stmt ? $stmt->execute() : false;
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    if ($row) {
        unset($row['id']);
        $settings = array_merge($settings, $row);
    }

    $settings['require_email_verified'] = $settings['require_email_verified'] ?? 1;
    return $settings;
}

function wallos_get_db_oidc_enabled($db)
{
    $stmt = $db->prepare('SELECT oidc_oauth_enabled FROM admin WHERE id = 1');
    $result = $stmt ? $stmt->execute() : false;
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    return $row ? (int) ($row['oidc_oauth_enabled'] ?? 0) : 0;
}

/**
 * Fetch an OIDC discovery document through the same DNS-pinned SSRF policy as
 * the token and user-info requests. The optional fetcher keeps this behavior
 * testable without making a network request.
 */
function wallos_fetch_oidc_discovery_document($issuer, $db, $fetcher = null)
{
    $issuer = rtrim(trim((string) $issuer), '/');
    if ($issuer === '') {
        return [null, 'OIDC_ISSUER is empty.'];
    }

    $issuerParts = parse_url($issuer);
    if (!$issuerParts || !isset($issuerParts['scheme'], $issuerParts['host'])
        || isset($issuerParts['query']) || isset($issuerParts['fragment'])) {
        return [null, 'OIDC_ISSUER must be an http(s) issuer URL without a query or fragment.'];
    }

    $url = $issuer . '/.well-known/openid-configuration';
    $target = validate_oidc_endpoint_url($url, $db);
    if ($target === false) {
        return [null, 'OIDC discovery URL is blocked by the SSRF policy.'];
    }

    if (is_callable($fetcher)) {
        try {
            $fetchResult = $fetcher($url, $target);
        } catch (Throwable $throwable) {
            return [null, 'OIDC discovery failed: ' . $throwable->getMessage()];
        }
    } else {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_RESOLVE, [$target['resolve']]);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $body = curl_exec($ch);
        $fetchResult = [
            'body' => $body,
            'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'error' => curl_error($ch),
        ];
        curl_close($ch);
    }

    if (is_array($fetchResult) && !array_key_exists('body', $fetchResult)) {
        $document = $fetchResult;
        $status = 200;
        $error = '';
    } else {
        $body = is_array($fetchResult) ? ($fetchResult['body'] ?? false) : false;
        $status = is_array($fetchResult) ? (int) ($fetchResult['status'] ?? 0) : 0;
        $error = is_array($fetchResult) ? trim((string) ($fetchResult['error'] ?? '')) : '';
        if ($body === false || $status < 200 || $status >= 300) {
            $detail = $error !== '' ? $error : 'HTTP ' . $status;
            return [null, 'OIDC discovery failed for ' . $url . ': ' . $detail];
        }
        $document = json_decode((string) $body, true);
    }

    if (!is_array($document)) {
        return [null, 'OIDC discovery returned invalid JSON for ' . $url . '.'];
    }

    return [$document, null];
}

function wallos_get_effective_oidc_configuration($db, array $options = [])
{
    $settings = wallos_get_db_oidc_settings($db);
    $managedFields = [];
    $notes = [];
    $discoveryDocument = null;

    $enabled = wallos_get_db_oidc_enabled($db);
    if (wallos_has_oidc_env_value('OIDC_ENABLED')) {
        $parsed = wallos_parse_oidc_boolean(wallos_get_oidc_env_value('OIDC_ENABLED'));
        if ($parsed === null) {
            $notes[] = 'Ignoring invalid boolean value in OIDC_ENABLED.';
        } else {
            $enabled = $parsed;
            $managedFields['enabled'] = 'OIDC_ENABLED';
        }
    }

    if (wallos_has_oidc_env_value('OIDC_CLIENT_SECRET_FILE')) {
        $managedFields['client_secret'] = 'OIDC_CLIENT_SECRET_FILE';
        $settings['client_secret'] = '';
        $secretFile = trim((string) wallos_get_oidc_env_value('OIDC_CLIENT_SECRET_FILE'));
        if ($secretFile === '') {
            $notes[] = 'OIDC_CLIENT_SECRET_FILE is empty.';
        } elseif (!is_file($secretFile) || !is_readable($secretFile)) {
            $notes[] = 'OIDC client secret file is not readable: ' . $secretFile;
        } else {
            $secret = file_get_contents($secretFile);
            if ($secret === false) {
                $notes[] = 'OIDC client secret file could not be read: ' . $secretFile;
            } else {
                $settings['client_secret'] = rtrim($secret, "\r\n");
            }
        }
    } elseif (wallos_has_oidc_env_value('OIDC_CLIENT_SECRET')) {
        $settings['client_secret'] = (string) wallos_get_oidc_env_value('OIDC_CLIENT_SECRET');
        $managedFields['client_secret'] = 'OIDC_CLIENT_SECRET';
    }

    $booleanEnvFields = [
        'auto_create_user' => 'OIDC_AUTO_CREATE_USER',
        'password_login_disabled' => 'OIDC_DISABLE_PASSWORD_LOGIN',
        'require_email_verified' => 'OIDC_REQUIRE_EMAIL_VERIFIED',
    ];
    foreach ($booleanEnvFields as $field => $envName) {
        if (!wallos_has_oidc_env_value($envName)) {
            continue;
        }

        $parsed = wallos_parse_oidc_boolean(wallos_get_oidc_env_value($envName));
        if ($parsed === null) {
            $notes[] = 'Ignoring invalid boolean value in ' . $envName . '.';
            continue;
        }

        $settings[$field] = $parsed;
        $managedFields[$field] = $envName;
    }

    if (wallos_has_oidc_env_value('OIDC_ISSUER')) {
        $issuer = (string) wallos_get_oidc_env_value('OIDC_ISSUER');
        $managedFields['issuer'] = 'OIDC_ISSUER';
        foreach (['authorization_url', 'token_url', 'user_info_url'] as $field) {
            $settings[$field] = '';
            $managedFields[$field] = 'OIDC_ISSUER';
        }

        [$discoveryDocument, $discoveryError] = wallos_fetch_oidc_discovery_document(
            $issuer,
            $db,
            $options['discovery_fetcher'] ?? null
        );
        if ($discoveryError !== null) {
            $notes[] = $discoveryError;
        } else {
            $discoveryMap = [
                'authorization_url' => 'authorization_endpoint',
                'token_url' => 'token_endpoint',
                'user_info_url' => 'userinfo_endpoint',
            ];
            foreach ($discoveryMap as $field => $documentField) {
                if (isset($discoveryDocument[$documentField]) && is_string($discoveryDocument[$documentField])) {
                    $settings[$field] = trim($discoveryDocument[$documentField]);
                }
            }
        }
    }

    $textEnvFields = [
        'name' => 'OIDC_PROVIDER_NAME',
        'client_id' => 'OIDC_CLIENT_ID',
        'authorization_url' => 'OIDC_AUTH_URL',
        'token_url' => 'OIDC_TOKEN_URL',
        'user_info_url' => 'OIDC_USERINFO_URL',
        'redirect_url' => 'OIDC_REDIRECT_URL',
        'logout_url' => 'OIDC_LOGOUT_URL',
        'user_identifier_field' => 'OIDC_USER_IDENTIFIER',
        'scopes' => 'OIDC_SCOPES',
    ];
    foreach ($textEnvFields as $field => $envName) {
        if (wallos_has_oidc_env_value($envName)) {
            $settings[$field] = trim((string) wallos_get_oidc_env_value($envName));
            $managedFields[$field] = $envName;
        }
    }

    // Public OIDC clients intentionally have no secret.
    $requiredFields = [
        'client_id',
        'authorization_url',
        'token_url',
        'user_info_url',
        'redirect_url',
        'user_identifier_field',
    ];
    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (trim((string) ($settings[$field] ?? '')) === '') {
            $missingFields[] = $field;
        }
    }

    foreach (['authorization_url', 'token_url', 'user_info_url', 'redirect_url'] as $urlField) {
        if (!in_array($urlField, $missingFields, true)
            && !wallos_oidc_is_http_url($settings[$urlField] ?? '')) {
            $missingFields[] = $urlField;
            $notes[] = 'Ignoring invalid ' . $urlField . ': OIDC URLs must use http or https.';
        }
    }
    if (trim((string) ($settings['logout_url'] ?? '')) !== ''
        && !wallos_oidc_is_http_url($settings['logout_url'])) {
        $settings['logout_url'] = '';
        $notes[] = 'Ignoring invalid logout_url: OIDC URLs must use http or https.';
    }

    if ($enabled && $missingFields) {
        $notes[] = 'OIDC is enabled but the login button is hidden because required fields are empty: '
            . implode(', ', $missingFields) . '.';
    }

    return [
        'enabled' => (int) $enabled,
        'settings' => $settings,
        'managed_fields' => $managedFields,
        'notes' => $notes,
        'discovery_document' => $discoveryDocument,
        'missing_fields' => $missingFields,
        'is_configured' => count($missingFields) === 0,
    ];
}

function wallos_oidc_secret_is_configured(array $configuration)
{
    return trim((string) ($configuration['settings']['client_secret'] ?? '')) !== '';
}

/**
 * Return OIDC settings safe for HTML/API presentation. Runtime callers must
 * keep using the effective configuration so token exchange still receives the
 * real secret; presentation callers receive only a fixed mask and a boolean.
 */
function wallos_get_oidc_public_settings(array $configuration)
{
    $settings = $configuration['settings'] ?? wallos_get_oidc_defaults();
    $configured = wallos_oidc_secret_is_configured($configuration);
    $settings['client_secret'] = $configured ? '********' : '';
    $settings['client_secret_configured'] = $configured;

    return $settings;
}

function wallos_merge_oidc_submitted_settings(array $stored, array $submitted, array $managedFields)
{
    foreach ($submitted as $field => $value) {
        if (isset($managedFields[$field])) {
            continue;
        }
        if ($field === 'client_secret' && $value === '') {
            continue;
        }
        $stored[$field] = $value;
    }

    return $stored;
}

?>
