<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/oidc_settings.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$oidcName = isset($data['oidcName']) ? trim($data['oidcName']) : '';
$oidcClientId = isset($data['oidcClientId']) ? trim($data['oidcClientId']) : '';
$oidcClientSecret = isset($data['oidcClientSecret']) ? trim($data['oidcClientSecret']) : '';
$oidcAuthUrl = isset($data['oidcAuthUrl']) ? trim($data['oidcAuthUrl']) : '';
$oidcTokenUrl = isset($data['oidcTokenUrl']) ? trim($data['oidcTokenUrl']) : '';
$oidcUserInfoUrl = isset($data['oidcUserInfoUrl']) ? trim($data['oidcUserInfoUrl']) : '';
$oidcRedirectUrl = isset($data['oidcRedirectUrl']) ? trim($data['oidcRedirectUrl']) : '';
$oidcLogoutUrl = isset($data['oidcLogoutUrl']) ? trim($data['oidcLogoutUrl']) : '';
$oidcUserIdentifierField = isset($data['oidcUserIdentifierField']) ? trim($data['oidcUserIdentifierField']) : '';
$oidcScopes = isset($data['oidcScopes']) ? trim($data['oidcScopes']) : '';
$oidcAuthStyle = isset($data['oidcAuthStyle']) ? trim($data['oidcAuthStyle']) : '';
$oidcAutoCreateUser = isset($data['oidcAutoCreateUser']) ? (int) $data['oidcAutoCreateUser'] : 0;
$oidcPasswordLoginDisabled = isset($data['oidcPasswordLoginDisabled']) ? (int) $data['oidcPasswordLoginDisabled'] : 0;
$oidcRequireEmailVerified = isset($data['oidcRequireEmailVerified']) ? (int) $data['oidcRequireEmailVerified'] : 1;

$oidcConfiguration = wallos_get_effective_oidc_configuration($db);
$managedFields = $oidcConfiguration['managed_fields'];
$dbSettings = wallos_get_db_oidc_settings($db);
$submittedSettings = [
    'name' => $oidcName,
    'client_id' => $oidcClientId,
    'client_secret' => $oidcClientSecret,
    'authorization_url' => $oidcAuthUrl,
    'token_url' => $oidcTokenUrl,
    'user_info_url' => $oidcUserInfoUrl,
    'redirect_url' => $oidcRedirectUrl,
    'logout_url' => $oidcLogoutUrl,
    'user_identifier_field' => $oidcUserIdentifierField,
    'scopes' => $oidcScopes,
    'auth_style' => $oidcAuthStyle,
    'auto_create_user' => $oidcAutoCreateUser,
    'password_login_disabled' => $oidcPasswordLoginDisabled,
    'require_email_verified' => $oidcRequireEmailVerified,
];
// The UI never receives the current secret. An empty password field means
// "keep it", while environment-managed fields always keep their DB fallback.
$dbSettings = wallos_merge_oidc_submitted_settings($dbSettings, $submittedSettings, $managedFields);

foreach (['authorization_url', 'redirect_url', 'logout_url'] as $urlField) {
    if ($dbSettings[$urlField] !== '' && !wallos_oidc_is_http_url($dbSettings[$urlField])) {
        die(json_encode([
            'success' => false,
            'message' => 'Security Error: OIDC URLs must use http or https.',
        ]));
    }
}
foreach (['token_url', 'user_info_url'] as $urlField) {
    if ($dbSettings[$urlField] !== '' && validate_oidc_endpoint_url($dbSettings[$urlField], $db) === false) {
        die(json_encode([
            'success' => false,
            'message' => 'Security Error: OIDC server endpoints are blocked by the SSRF policy.',
        ]));
    }
}

$checkStmt = $db->prepare('SELECT COUNT(*) as count FROM oauth_settings WHERE id = 1');
$result = $checkStmt->execute();
$row = $result->fetchArray(SQLITE3_ASSOC);

if ($row['count'] > 0) {
    // Update existing row
    $stmt = $db->prepare('UPDATE oauth_settings SET 
            name = :oidcName, 
            client_id = :oidcClientId, 
            client_secret = :oidcClientSecret, 
            authorization_url = :oidcAuthUrl, 
            token_url = :oidcTokenUrl, 
            user_info_url = :oidcUserInfoUrl, 
            redirect_url = :oidcRedirectUrl, 
            logout_url = :oidcLogoutUrl, 
            user_identifier_field = :oidcUserIdentifierField, 
            scopes = :oidcScopes,
            auth_style = :oidcAuthStyle,
            auto_create_user = :oidcAutoCreateUser,
            password_login_disabled = :oidcPasswordLoginDisabled,
            require_email_verified = :oidcRequireEmailVerified
            WHERE id = 1');
} else {
    // Insert new row
    $stmt = $db->prepare('INSERT INTO oauth_settings (
            id, name, client_id, client_secret, authorization_url, token_url, user_info_url, redirect_url, logout_url, user_identifier_field, scopes, auth_style, auto_create_user, password_login_disabled, require_email_verified
        ) VALUES (
            1, :oidcName, :oidcClientId, :oidcClientSecret, :oidcAuthUrl, :oidcTokenUrl, :oidcUserInfoUrl, :oidcRedirectUrl, :oidcLogoutUrl, :oidcUserIdentifierField, :oidcScopes, :oidcAuthStyle, :oidcAutoCreateUser, :oidcPasswordLoginDisabled, :oidcRequireEmailVerified
        )');
}

$stmt->bindValue(':oidcName', $dbSettings['name'], SQLITE3_TEXT);
$stmt->bindValue(':oidcClientId', $dbSettings['client_id'], SQLITE3_TEXT);
$stmt->bindValue(':oidcClientSecret', $dbSettings['client_secret'], SQLITE3_TEXT);
$stmt->bindValue(':oidcAuthUrl', $dbSettings['authorization_url'], SQLITE3_TEXT);
$stmt->bindValue(':oidcTokenUrl', $dbSettings['token_url'], SQLITE3_TEXT);
$stmt->bindValue(':oidcUserInfoUrl', $dbSettings['user_info_url'], SQLITE3_TEXT);
$stmt->bindValue(':oidcRedirectUrl', $dbSettings['redirect_url'], SQLITE3_TEXT);
$stmt->bindValue(':oidcLogoutUrl', $dbSettings['logout_url'], SQLITE3_TEXT);
$stmt->bindValue(':oidcUserIdentifierField', $dbSettings['user_identifier_field'], SQLITE3_TEXT);
$stmt->bindValue(':oidcScopes', $dbSettings['scopes'], SQLITE3_TEXT);
$stmt->bindValue(':oidcAuthStyle', $dbSettings['auth_style'], SQLITE3_TEXT);
$stmt->bindValue(':oidcAutoCreateUser', (int) $dbSettings['auto_create_user'], SQLITE3_INTEGER);
$stmt->bindValue(':oidcPasswordLoginDisabled', (int) $dbSettings['password_login_disabled'], SQLITE3_INTEGER);
$stmt->bindValue(':oidcRequireEmailVerified', (int) $dbSettings['require_email_verified'], SQLITE3_INTEGER);
$saved = $stmt->execute();

if ($saved !== false) {
    $db->close();
    die(json_encode([
        "success" => true,
        "message" => translate('success', $i18n)
    ]));
} else {
    $db->close();
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
}
