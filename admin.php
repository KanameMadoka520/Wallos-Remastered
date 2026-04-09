<?php
require_once 'includes/header.php';
require_once 'includes/user_groups.php';
require_once 'includes/user_status.php';
require_once 'includes/subscription_media.php';

if ($isAdmin != 1) {
    header('Location: index.php');
    exit;
}

// get admin settings from admin table
$stmt = $db->prepare('SELECT * FROM admin');
$result = $stmt->execute();
$settings = $result->fetchArray(SQLITE3_ASSOC);
$subscriptionImagePolicy = wallos_get_subscription_media_policy($db);

// get OIDC settings
$stmt = $db->prepare('SELECT * FROM oauth_settings WHERE id = 1');
$result = $stmt->execute();
$oidcSettings = $result->fetchArray(SQLITE3_ASSOC);

if ($oidcSettings === false) {
    // Table is empty or no row with id=1, set defaults
    $oidcSettings = [
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
        'password_login_disabled' => 0
    ];
}

// get active user accounts
$stmt = $db->prepare('SELECT id, username, email, user_group, account_status FROM user WHERE account_status = :status ORDER BY id ASC');
$stmt->bindValue(':status', WALLOS_USER_STATUS_ACTIVE, SQLITE3_TEXT);
$result = $stmt->execute();

$users = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $users[] = $row;
}
$userCount = is_array($users) ? count($users) : 0;

// get trashed user accounts
$stmt = $db->prepare('SELECT id, username, email, user_group, trash_reason, trashed_at, scheduled_delete_at FROM user WHERE account_status = :status ORDER BY trashed_at DESC');
$stmt->bindValue(':status', WALLOS_USER_STATUS_TRASHED, SQLITE3_TEXT);
$result = $stmt->execute();

$trashedUsers = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $trashedUsers[] = $row;
}

// get invite codes
$inviteCodes = [];
$inviteCodeUsageMap = [];
$stmt = $db->prepare('
    SELECT invite_codes.*, user.username AS creator_name
    FROM invite_codes
    LEFT JOIN user ON user.id = invite_codes.created_by
    ORDER BY invite_codes.created_at DESC
');
$result = $stmt->execute();
while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
    $inviteCodes[] = $row;
}

$usageQuery = $db->query("
    SELECT invite_code_id,
           GROUP_CONCAT(
               CASE
                   WHEN TRIM(used_by_username) != '' AND TRIM(used_by_email) != '' THEN used_by_username || ' (' || used_by_email || ')'
                   WHEN TRIM(used_by_username) != '' THEN used_by_username
                   ELSE used_by_email
               END,
               ' | '
           ) AS usage_summary
    FROM invite_code_usages
    GROUP BY invite_code_id
");
while ($usageQuery && ($row = $usageQuery->fetchArray(SQLITE3_ASSOC))) {
    $inviteCodeUsageMap[(int) $row['invite_code_id']] = $row['usage_summary'];
}

// recent request logs
$recentRequestLogs = [];
$logsQuery = $db->prepare('SELECT id, user_id, username, path, method, ip_address, forwarded_for, user_agent, headers_json, created_at FROM request_logs ORDER BY id DESC LIMIT 50');
$logsResult = $logsQuery->execute();
while ($logsResult && ($row = $logsResult->fetchArray(SQLITE3_ASSOC))) {
    $recentRequestLogs[] = $row;
}

$loginDisabledAllowed = $userCount == 1 && $settings['registrations_open'] == 0;
require_once 'includes/page_navigation.php';

$pageSections = [
    ['id' => 'admin-registrations', 'label' => translate('registrations', $i18n)],
    ['id' => 'admin-users', 'label' => translate('user_management', $i18n)],
    ['id' => 'admin-recycle-bin', 'label' => translate('recycle_bin', $i18n)],
    ['id' => 'admin-image-settings', 'label' => translate('subscription_image_settings', $i18n)],
    ['id' => 'admin-invite-codes', 'label' => translate('invite_code_management', $i18n)],
    ['id' => 'admin-oidc', 'label' => translate('oidc_settings', $i18n)],
    ['id' => 'admin-smtp', 'label' => translate('smtp_settings', $i18n)],
    ['id' => 'admin-security', 'label' => translate('security_settings', $i18n)],
    ['id' => 'admin-access-logs', 'label' => translate('access_logs', $i18n)],
    ['id' => 'admin-maintenance', 'label' => translate('maintenance_tasks', $i18n)],
    ['id' => 'admin-backup', 'label' => translate('backup_and_restore', $i18n)],
];
?>

<section class="contain settings has-page-nav">
    <div class="page-layout">
        <?php render_page_navigation(translate('admin', $i18n), $pageSections); ?>
        <div class="page-content">

    <section class="account-section" id="admin-registrations" data-page-section>
        <header>
            <h2><?= translate('registrations', $i18n) ?></h2>
        </header>
        <div class="admin-form">
            <div class="form-group-inline">
                <input type="checkbox" id="registrations" <?= $settings['registrations_open'] ? 'checked' : '' ?> />
                <label for="registrations"><?= translate('enable_user_registrations', $i18n) ?></label>
            </div>
            <div class="form-group-inline">
                <input type="checkbox" id="inviteOnlyRegistration" <?= !empty($settings['invite_only_registration']) ? 'checked' : '' ?> />
                <label for="inviteOnlyRegistration"><?= translate('invite_only_registration', $i18n) ?></label>
            </div>
            <div class="form-group">
                <label for="maxUsers"><?= translate('maximum_number_users', $i18n) ?></label>
                <input type="number" id="maxUsers" autocomplete="off" value="<?= $settings['max_users'] ?>" />
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('max_users_info', $i18n) ?>
                </p>
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('registration_disable_login_info', $i18n) ?>
                </p>
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('invite_only_registration_info', $i18n) ?>
                </p>
            </div>
            <div class="form-group-inline">
                <input type="checkbox" id="requireEmail" <?= $settings['require_email_verification'] ? 'checked' : '' ?>
                    <?= empty($settings['smtp_address']) ? 'disabled' : '' ?> />
                <label for="requireEmail">
                    <?= translate('require_email_verification', $i18n) ?>
                </label>
            </div>
            <?php
            if (empty($settings['smtp_address'])) {
                ?>
                <div class="settings-notes">
                    <p>
                        <i class="fa-solid fa-circle-info"></i>
                        <?= translate('configure_smtp_settings_to_enable', $i18n) ?>
                    </p>
                </div>
                <?php
            }
            ?>
            <div class="form-group">
                <label for="serverUrl"><?= translate('server_url', $i18n) ?></label>
                <input type="text" id="serverUrl" autocomplete="off" value="<?= $settings['server_url'] ?>" />
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('server_url_info', $i18n) ?>
                </p>
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('server_url_password_reset', $i18n) ?>
                </p>
            </div>
            <hr>
            <div class="form-group-inline">
                <input type="checkbox" id="disableLogin" <?= $settings['login_disabled'] ? 'checked' : '' ?>
                    <?= $loginDisabledAllowed ? '' : 'disabled' ?> />
                <label for="disableLogin"><?= translate('disable_login', $i18n) ?></label>
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                    <?= translate('disable_login_info', $i18n) ?>
                </p>
                <p>
                    <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                    <?= translate('disable_login_info2', $i18n) ?>
                </p>
            </div>
            <div class="buttons">
                <input type="submit" class="thin mobile-grow" value="<?= translate('save', $i18n) ?>"
                    id="saveAccountRegistrations" onClick="saveAccountRegistrationsButton()" />
            </div>
        </div>
    </section>

    <?php
    if ($userCount >= 0) {
        ?>

        <section class="account-section" id="admin-users" data-page-section>
            <header>
                <h2><?= translate('user_management', $i18n) ?></h2>
            </header>
            <div class="user-list">
                <?php
                foreach ($users as $user) {
                    $userIcon = $user['id'] == 1 ? 'fa-user-tie' : 'fa-id-badge';
                    $isPrimaryAdmin = (int) $user['id'] === 1;
                    ?>
                    <div class="form-group-inline" data-userid="<?= $user['id'] ?>">
                        <div class="user-list-row">
                            <div title="<?= translate('username', $i18n) ?>">
                                <div class="user-list-icon">
                                    <i class="fa-solid <?= $userIcon ?>"></i>
                                </div>
                                <?= $user['username'] ?>
                            </div>
                            <div title="<?= translate('email', $i18n) ?>">
                                <div class="user-list-icon">
                                    <i class="fa-solid fa-envelope"></i>
                                </div>
                                <a href="mailto:<?= $user['email'] ?>"><?= $user['email'] ?></a>
                            </div>
                        </div>
                        <div class="user-list-actions">
                            <div class="user-group-control">
                                <label for="user-group-<?= $user['id'] ?>"><?= translate('user_group', $i18n) ?></label>
                                <?php
                                if ($isPrimaryAdmin) {
                                    ?>
                                    <span class="user-group-badge admin">
                                        <?= wallos_get_user_group_label($user['user_group'] ?? WALLOS_USER_GROUP_FREE, $i18n, true) ?>
                                    </span>
                                    <?php
                                } else {
                                    $normalizedGroup = wallos_normalize_user_group($user['user_group'] ?? WALLOS_USER_GROUP_FREE);
                                    ?>
                                    <select id="user-group-<?= $user['id'] ?>" class="user-group-select"
                                        data-current-value="<?= $normalizedGroup ?>"
                                        onchange="updateUserGroup(<?= $user['id'] ?>, this)">
                                        <option value="free" <?= $normalizedGroup === WALLOS_USER_GROUP_FREE ? 'selected' : '' ?>>
                                            <?= translate('free_user_group', $i18n) ?>
                                        </option>
                                        <option value="trusted" <?= $normalizedGroup === WALLOS_USER_GROUP_TRUSTED ? 'selected' : '' ?>>
                                            <?= translate('trusted_user_group', $i18n) ?>
                                        </option>
                                    </select>
                                    <?php
                                }
                                ?>
                            </div>
                            <?php
                            if (!$isPrimaryAdmin) {
                                ?>
                                <button class="image-button medium" onClick="removeUser(<?= $user['id'] ?>)"
                                    title="<?= translate('delete_user', $i18n) ?>">
                                    <?php include "images/siteicons/svg/delete.php"; ?>
                                </button>
                                <?php
                            } else {
                                ?>
                                <button class="image-button medium disabled" disabled
                                    title="<?= translate('delete_user', $i18n) ?>">
                                    <?php include "images/siteicons/svg/delete.php"; ?>
                                </button>
                                <?php
                            }
                            ?>

                        </div>
                    </div>
                    <?php
                }
                ?>
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('delete_user_info', $i18n) ?>
                </p>
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('user_group_management_info', $i18n) ?>
                </p>
            </div>
            <h2><?= translate('create_user', $i18n) ?></h2>
            <div class="form-group">
                <input type="text" id="newUsername" autocomplete="off"
                    placeholder="<?= translate('username', $i18n) ?>" />
            </div>
            <div class="form-group">
                <input type="email" id="newEmail" autocomplete="off"
                    placeholder="<?= translate('email', $i18n) ?>" />
            </div>
            <div class="form-group-inline">
                <input type="password" id="newPassword" autocomplete="off"
                    placeholder="<?= translate('password', $i18n) ?>" />
                <input type="submit" class="thin" value="<?= translate('add', $i18n) ?>" id="addUserButton"
                    onClick="addUserButton()" />
            </div>
        </section>

        <?php
    }
    ?>

    <section class="account-section" id="admin-recycle-bin" data-page-section>
        <header>
            <h2><?= translate('recycle_bin', $i18n) ?></h2>
        </header>
        <?php
        if (!empty($trashedUsers)) {
            ?>
            <div class="user-list recycle-bin-list">
                <?php
                foreach ($trashedUsers as $trashedUser) {
                    ?>
                    <div class="form-group-inline" data-trashed-userid="<?= $trashedUser['id'] ?>">
                        <div class="user-list-row">
                            <div>
                                <div class="user-list-icon">
                                    <i class="fa-solid fa-trash-can"></i>
                                </div>
                                <?= htmlspecialchars($trashedUser['username'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div>
                                <div class="user-list-icon">
                                    <i class="fa-solid fa-envelope"></i>
                                </div>
                                <a href="mailto:<?= htmlspecialchars($trashedUser['email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($trashedUser['email'], ENT_QUOTES, 'UTF-8') ?></a>
                            </div>
                        </div>
                        <div class="recycle-bin-user-meta">
                            <p><strong><?= translate('recycle_bin_reason_label', $i18n) ?>:</strong> <?= htmlspecialchars($trashedUser['trash_reason'], ENT_QUOTES, 'UTF-8') ?></p>
                            <p><strong><?= translate('recycle_bin_trashed_at', $i18n) ?>:</strong> <?= htmlspecialchars($trashedUser['trashed_at'], ENT_QUOTES, 'UTF-8') ?></p>
                            <p><strong><?= translate('recycle_bin_scheduled_delete_at', $i18n) ?>:</strong> <?= htmlspecialchars($trashedUser['scheduled_delete_at'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <div class="user-list-actions">
                            <input type="button" class="secondary-button thin" value="<?= translate('restore_user', $i18n) ?>"
                                onClick="restoreUser(<?= (int) $trashedUser['id'] ?>)" />
                            <input type="button" class="warning-button thin" value="<?= translate('permanently_delete_user', $i18n) ?>"
                                onClick="permanentlyDeleteUser(<?= (int) $trashedUser['id'] ?>)" />
                        </div>
                    </div>
                    <?php
                }
                ?>
            </div>
            <?php
        } else {
            ?>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('recycle_bin_empty', $i18n) ?>
                </p>
            </div>
            <?php
        }
        ?>
    </section>

    <section class="account-section" id="admin-image-settings" data-page-section>
        <header>
            <h2><?= translate('subscription_image_settings', $i18n) ?></h2>
        </header>
        <div class="admin-form">
            <div class="form-group-inline">
                <div class="grow">
                    <label for="subscriptionImageExternalUrlLimit"><?= translate('subscription_image_external_url_limit', $i18n) ?></label>
                    <input type="number" id="subscriptionImageExternalUrlLimit" min="1" max="<?= WALLOS_SUBSCRIPTION_IMAGE_MAX_EXTERNAL_URL_LIMIT ?>" value="<?= (int) ($settings['subscription_image_external_url_limit'] ?? $subscriptionImagePolicy['external_url_limit']) ?>" />
                </div>
                <div class="grow">
                    <label for="trustedSubscriptionUploadLimit"><?= translate('trusted_subscription_upload_limit', $i18n) ?></label>
                    <input type="number" id="trustedSubscriptionUploadLimit" min="0" max="<?= WALLOS_SUBSCRIPTION_IMAGE_MAX_TRUSTED_UPLOAD_LIMIT ?>" value="<?= (int) ($settings['trusted_subscription_upload_limit'] ?? $subscriptionImagePolicy['trusted_upload_limit']) ?>" />
                </div>
            </div>
            <div class="form-group">
                <label for="subscriptionImageMaxSizeMb"><?= translate('subscription_image_max_size_mb', $i18n) ?></label>
                <input type="number" id="subscriptionImageMaxSizeMb" min="1" max="<?= WALLOS_SUBSCRIPTION_IMAGE_MAX_MAX_MB ?>" value="<?= (int) ($settings['subscription_image_max_size_mb'] ?? $subscriptionImagePolicy['max_size_mb']) ?>" />
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= sprintf(translate('subscription_image_allowed_extensions_info', $i18n), htmlspecialchars(wallos_get_subscription_media_allowed_extension_label(), ENT_QUOTES, 'UTF-8')) ?>
                </p>
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('subscription_image_storage_info', $i18n) ?>
                </p>
            </div>
            <div class="buttons">
                <input type="button" class="thin mobile-grow" value="<?= translate('save', $i18n) ?>"
                    id="saveSubscriptionImageSettingsButton" onClick="saveSubscriptionImageSettingsButton()" />
            </div>
        </div>
    </section>

    <section class="account-section" id="admin-invite-codes" data-page-section>
        <header>
            <h2><?= translate('invite_code_management', $i18n) ?></h2>
        </header>
        <div class="admin-form">
            <div class="form-group-inline">
                <div class="grow">
                    <label for="inviteCodeMaxUses"><?= translate('invite_code_max_uses', $i18n) ?></label>
                    <input type="number" id="inviteCodeMaxUses" min="1" value="1" />
                </div>
                <input type="button" class="thin" value="<?= translate('generate_invite_code', $i18n) ?>"
                    id="generateInviteCodeButton" onClick="generateInviteCode()" />
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('invite_code_usage_info', $i18n) ?>
                </p>
            </div>
            <?php
            if (!empty($inviteCodes)) {
                ?>
                <div class="invite-code-list">
                    <?php
                    foreach ($inviteCodes as $inviteCode) {
                        $usageSummary = $inviteCodeUsageMap[(int) $inviteCode['id']] ?? translate('invite_code_unused', $i18n);
                        ?>
                        <div class="invite-code-card<?= (int) $inviteCode['deleted'] === 1 ? ' is-deleted' : '' ?>" data-invite-code-id="<?= (int) $inviteCode['id'] ?>">
                            <div class="invite-code-header">
                                <code><?= htmlspecialchars($inviteCode['code'], ENT_QUOTES, 'UTF-8') ?></code>
                                <span class="invite-code-status"><?= (int) $inviteCode['deleted'] === 1 ? translate('invite_code_deleted_status', $i18n) : translate('invite_code_active_status', $i18n) ?></span>
                            </div>
                            <p><?= translate('invite_code_max_uses', $i18n) ?>: <?= (int) $inviteCode['max_uses'] ?></p>
                            <p><?= translate('invite_code_uses_count', $i18n) ?>: <?= (int) $inviteCode['uses_count'] ?></p>
                            <p><?= translate('created_by', $i18n) ?>: <?= htmlspecialchars($inviteCode['creator_name'] ?? 'admin', ENT_QUOTES, 'UTF-8') ?></p>
                            <p><?= translate('used_by', $i18n) ?>: <?= htmlspecialchars($usageSummary, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php
                            if ((int) $inviteCode['deleted'] !== 1) {
                                ?>
                                <div class="buttons">
                                    <input type="button" class="warning-button thin" value="<?= translate('delete_invite_code', $i18n) ?>"
                                        onClick="deleteInviteCode(<?= (int) $inviteCode['id'] ?>)" />
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                        <?php
                    }
                    ?>
                </div>
                <?php
            } else {
                ?>
                <div class="settings-notes">
                    <p>
                        <i class="fa-solid fa-circle-info"></i>
                        <?= translate('invite_code_list_empty', $i18n) ?>
                    </p>
                </div>
                <?php
            }
            ?>
        </div>
    </section>

    <section class="account-section" id="admin-oidc" data-page-section>
        <header>
            <h2><?= translate('oidc_settings', $i18n) ?></h2>
        </header>
        <div class="admin-form">
            <div class="form-group-inline">
                <input type="checkbox" id="oidcEnabled" <?= $settings['oidc_oauth_enabled'] ? 'checked' : '' ?>
                    onchange="toggleOidcEnabled()" />
                <label for="oidcEnabled"><?= translate('oidc_oauth_enabled', $i18n) ?></label>
            </div>
            <div class="form-group">
                <input type="text" id="oidcName" placeholder="<?= translate('provider_name', $i18n) ?>" autocomplete="off"
                    value="<?= $oidcSettings['name'] ?>" />
            </div>
            <div class="form-group">
                <input type="text" id="oidcClientId" placeholder="<?= translate('client_id', $i18n) ?>" autocomplete="off"
                    value="<?= $oidcSettings['client_id'] ?>" />
            </div>
            <div class="form-group">
                <input type="text" id="oidcClientSecret" placeholder="<?= translate('client_secret', $i18n) ?>" autocomplete="off"
                    value="<?= $oidcSettings['client_secret'] ?>" />
            </div>
            <div class="form-group">
                <input type="text" id="oidcAuthUrl" placeholder="<?= translate('auth_url', $i18n) ?>" autocomplete="off"
                    value="<?= $oidcSettings['authorization_url'] ?>" />
            </div>
            <div class="form-group">
                <input type="text" id="oidcTokenUrl" placeholder="<?= translate('token_url', $i18n) ?>" autocomplete="off"
                    value="<?= $oidcSettings['token_url'] ?>" />
            </div>
            <div class="form-group">
                <input type="text" id="oidcUserInfoUrl" placeholder="<?= translate('user_info_url', $i18n) ?>" autocomplete="off"
                    value="<?= $oidcSettings['user_info_url'] ?>" />
            </div>
            <div class="form-group">
                <input type="text" id="oidcRedirectUrl" placeholder="<?= translate('redirect_url', $i18n) ?>" autocomplete="off"
                    value="<?= $oidcSettings['redirect_url'] ?>" />
            </div>
            <div class="form-group">
                <input type="text" id="oidcLogoutUrl" placeholder="<?= translate('logout_url', $i18n) ?>" autocomplete="off"
                    value="<?= $oidcSettings['logout_url'] ?>" />
            </div>
            <div class="form-group">
                <input type="text" id="oidcUserIdentifierField" placeholder="<?= translate('user_identifier_field', $i18n) ?>" autocomplete="off"
                    value="<?= $oidcSettings['user_identifier_field'] ?>" />
            </div>
            <div class="form-group">
                <input type="text" id="oidcScopes" placeholder="<?= translate('scopes', $i18n) ?>" autocomplete="off"
                    value="<?= $oidcSettings['scopes'] ?>" />
            </div>
            <div class="form-group">
                <input type="hidden" id="oidcAuthStyle" placeholder="<?= translate('auth_style', $i18n) ?>" autocomplete="off"
                    value="<?= $oidcSettings['auth_style'] ?>" />
            </div>
            <div class="form-group-inline">
                <input type="checkbox" id="oidcAutoCreateUser" <?= $oidcSettings['auto_create_user'] ? 'checked' : '' ?> />
                <label for="oidcAutoCreateUser"><?= translate('create_user_automatically', $i18n) ?></label>
            </div>
            <div class="form-group-inline">
                <input type="checkbox" id="oidcPasswordLoginDisabled"
                    <?= $oidcSettings['password_login_disabled'] ? 'checked' : '' ?> />
                <label for="oidcPasswordLoginDisabled"><?= translate('disable_password_login', $i18n) ?></label>
            </div>
            <div class="buttons">
                <input type="submit" class="thin mobile-grow" value="<?= translate('save', $i18n) ?>"
                    id="saveOidcSettingsButton" onClick="saveOidcSettingsButton()" />
            </div>
        </div>

    </section>

    <section class="account-section" id="admin-smtp" data-page-section>
        <header>
            <h2><?= translate('smtp_settings', $i18n) ?></h2>
        </header>
        <div class="admin-form">
            <div class="form-group-inline">
                <input type="text" name="smtpaddress" id="smtpaddress" autocomplete="off"
                    placeholder="<?= translate('smtp_address', $i18n) ?>" value="<?= $settings['smtp_address'] ?>" />
                <input type="text" name="smtpport" id="smtpport" autocomplete="off"
                    placeholder="<?= translate('port', $i18n) ?>" class="one-third" value="<?= $settings['smtp_port'] ?>" />
            </div>
            <div class="form-group-inline">
                <div>
                    <input type="radio" name="encryption" id="encryptionnone" value="none"
                        <?= empty($settings['encryption']) || $settings['encryption'] == "none" ? "checked" : "" ?> />
                    <label for="encryptionnone"><?= translate('none', $i18n) ?></label>
                </div>
                <div>
                    <input type="radio" name="encryption" id="encryptiontls" value="tls"
                        <?= $settings['encryption'] == "tls" ? "checked" : "" ?> />
                    <label for="encryptiontls"><?= translate('tls', $i18n) ?></label>
                </div>
                <div>
                    <input type="radio" name="encryption" id="encryptionssl" value="ssl"
                        <?= $settings['encryption'] == "ssl" ? "checked" : "" ?> />
                    <label for="encryptionssl"><?= translate('ssl', $i18n) ?></label>
                </div>
            </div>
            <div class="form-group-inline">
                <input type="text" name="smtpusername" id="smtpusername" autocomplete="off"
                    placeholder="<?= translate('smtp_username', $i18n) ?>" value="<?= $settings['smtp_username'] ?>" />
            </div>
            <div class="form-group-inline">
                <input type="password" name="smtppassword" id="smtppassword" autocomplete="off"
                    placeholder="<?= translate('smtp_password', $i18n) ?>" value="<?= $settings['smtp_password'] ?>" />
            </div>
            <div class="form-group-inline">
                <input type="text" name="fromemail" id="fromemail" autocomplete="off"
                    placeholder="<?= translate('from_email', $i18n) ?>" value="<?= $settings['from_email'] ?>" />
            </div>
            <div class="buttons">
                <input type="button" class="secondary-button thin mobile-grow" value="<?= translate('test', $i18n) ?>"
                    id="testSmtpSettingsButton" onClick="testSmtpSettingsButton()" />
                <input type="submit" class="thin mobile-grow" value="<?= translate('save', $i18n) ?>"
                    id="saveSmtpSettingsButton" onClick="saveSmtpSettingsButton()" />
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i> <?= translate('smtp_info', $i18n) ?>
                </p>
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('smtp_usage_info', $i18n) ?>
                </p>
            </div>
        </div>
    </section>

    <section class="account-section" id="admin-security" data-page-section>
    <header>
        <h2><?= translate('security_settings', $i18n) ?></h2> </header>
    <div class="admin-form">
        <div class="form-group-inline">
            <input type="text" name="local_webhook_notifications_allowlist" id="local_webhook_notifications_allowlist" autocomplete="off"
                placeholder="<?= translate('local_webhook_allowlist_placeholder', $i18n) ?>" value="<?= htmlspecialchars($settings['local_webhook_notifications_allowlist'] ?? '', ENT_QUOTES, 'UTF-8') ?>" />
        </div>
        
        <div class="buttons">
            <input type="submit" class="thin mobile-grow" value="<?= translate('save', $i18n) ?>"
                id="saveSecuritySettingsButton" onClick="saveSecuritySettingsButton()" />
        </div>
        
        <div class="settings-notes">
            <p>
                <i class="fa-solid fa-circle-info"></i> 
                <?= translate('ssrf_protection_info', $i18n) ?>
            </p>
            <p>
                <i class="fa-solid fa-circle-info"></i>
                <?= translate('local_webhook_info', $i18n) ?>
            </p>
        </div>
    </div>
</section>

    <?php
    // Get latest version from admin table
    if (!is_null($settings['latest_version'])) {
        $latestVersion = $settings['latest_version'];
        $hasUpdate = version_compare($version, $latestVersion) == -1;
    } else {
        $hasUpdate = false;
    }

    // find unused upload logos

    // Get all logos in the subscriptions table
    $query = 'SELECT logo FROM subscriptions';
    $stmt = $db->prepare($query);
    $result = $stmt->execute();

    $logosOnDisk = [];
    $logosOnDB = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $logosOnDB[] = $row['logo'];
    }

    // Get all logos in the payment_methods table
    $query = 'SELECT icon FROM payment_methods';
    $stmt = $db->prepare($query);
    $result = $stmt->execute();

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (!strstr($row['icon'], "images/uploads/icons/")) {
            $logosOnDB[] = $row['icon'];
        }
    }

    $logosOnDB = array_unique($logosOnDB);

    // Get all logos in the uploads folder
    $uploadDir = 'images/uploads/logos/';
    $uploadFiles = scandir($uploadDir);

    foreach ($uploadFiles as $file) {
        if ($file != '.' && $file != '..' && $file != 'avatars' && $file != 'subscription-media' && !is_dir($uploadDir . $file)) {
            $logosOnDisk[] = ['logo' => $file];
        }
    }

    // Find unused logos
    $unusedLogos = [];
    foreach ($logosOnDisk as $disk) {
        $found = false;
        foreach ($logosOnDB as $dbLogo) {
            if ($disk['logo'] == $dbLogo) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $unusedLogos[] = $disk;
        }
    }

    $logosToDelete = count($unusedLogos);

    ?>

    <section class="account-section" id="admin-access-logs" data-page-section>
        <header>
            <h2><?= translate('access_logs', $i18n) ?></h2>
        </header>
        <?php
        if (!empty($recentRequestLogs)) {
            ?>
            <div class="access-log-list">
                <?php
                foreach ($recentRequestLogs as $log) {
                    ?>
                    <div class="access-log-card">
                        <div class="access-log-header">
                            <strong><?= htmlspecialchars($log['method'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars($log['path'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <p><?= translate('username', $i18n) ?>: <?= htmlspecialchars($log['username'] ?: '-', ENT_QUOTES, 'UTF-8') ?></p>
                        <p>IP: <?= htmlspecialchars($log['ip_address'] ?: '-', ENT_QUOTES, 'UTF-8') ?></p>
                        <p><?= translate('forwarded_for', $i18n) ?>: <?= htmlspecialchars($log['forwarded_for'] ?: '-', ENT_QUOTES, 'UTF-8') ?></p>
                        <p><?= translate('user_agent', $i18n) ?>: <?= htmlspecialchars($log['user_agent'] ?: '-', ENT_QUOTES, 'UTF-8') ?></p>
                        <p><?= translate('time', $i18n) ?>: <?= htmlspecialchars($log['created_at'], ENT_QUOTES, 'UTF-8') ?></p>
                        <details>
                            <summary><?= translate('request_headers', $i18n) ?></summary>
                            <pre><?= htmlspecialchars($log['headers_json'], ENT_QUOTES, 'UTF-8') ?></pre>
                        </details>
                    </div>
                    <?php
                }
                ?>
            </div>
            <?php
        } else {
            ?>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('access_logs_empty', $i18n) ?>
                </p>
            </div>
            <?php
        }
        ?>
    </section>

    <section class="account-section" id="admin-maintenance" data-page-section>
        <header>
            <h2>
                <?= translate('maintenance_tasks', $i18n) ?>
            </h2>
        </header>
        <div class="maintenance-tasks">
            <h3><?= translate('update', $i18n) ?></h3>
            <div class="form-group">
                <?php
                if ($hasUpdate) {
                    ?>
                    <div class="updates-list">
                        <p><?= translate('new_version_available', $i18n) ?>.</p>
                        <p>
                            <?= translate('current_version', $i18n) ?>:
                            <span>
                                <?= $version ?>
                                <a href="https://github.com/ellite/Wallos/releases/tag/<?= $version ?>" target="_blank">
                                    <i class="fa-solid fa-external-link"></i>
                                </a>
                            </span>
                        </p>
                        <p>
                            <?= translate('latest_version', $i18n) ?>:
                            <span>
                                <?= $latestVersion ?>
                                <a href="https://github.com/ellite/Wallos/releases/tag/<?= $latestVersion ?>"
                                    target="_blank">
                                    <i class="fa-solid fa-external-link"></i>
                                </a>
                            </span>
                        </p>
                    </div>
                    <?php
                } else {
                    ?>
                    <?= translate('on_current_version', $i18n) ?>
                    <?php
                }
                ?>
            </div>
            <div class="form-group-inline">
                <input type="checkbox" id="updateNotification" <?= $settings['update_notification'] ? 'checked' : '' ?>
                    onchange="toggleUpdateNotification()" />
                <label for="updateNotification"><?= translate('show_update_notification', $i18n) ?></label>
            </div>
            <h3><?= translate('orphaned_logos', $i18n) ?></h3>
            <div class="form-group-inline">
                <input type="button" class="button thin mobile-grow" value="<?= translate('delete', $i18n) ?>"
                    id="deleteUnusedLogos" onClick="deleteUnusedLogos()" <?= $logosToDelete == 0 ? 'disabled' : '' ?> />
                <span class="number-of-logos bold"><?= $logosToDelete ?></span>
                <?= translate('orphaned_logos', $i18n) ?>
            </div>
            <h3><?= translate('cronjobs', $i18n) ?></h3>
            <div>
                <div class="inline-row">
                    <input type="button" value="<?= translate('check_for_updates', $i18n) ?>" class="button tiny mobile-grow"
                        onclick="executeCronJob('checkforupdates')">
                    <input type="button" value="<?= translate('send_notifications', $i18n) ?>" class="button tiny mobile-grow"
                        onclick="executeCronJob('sendnotifications')">
                    <input type="button" value="<?= translate('send_cancellation_notifications', $i18n) ?>" class="button tiny mobile-grow"
                        onclick="executeCronJob('sendcancellationnotifications')">
                    <input type="button" value="<?= translate('send_password_reset_emails', $i18n) ?>" class="button tiny mobile-grow"
                        onclick="executeCronJob('sendresetpasswordemails')">
                    <input type="button" value="<?= translate('send_verification_emails', $i18n) ?>" class="button tiny mobile-grow"
                        onclick="executeCronJob('sendverificationemails')">
                    <input type="button" value="<?= translate('update_exchange_rates', $i18n) ?>" class="button tiny mobile-grow"
                        onclick="executeCronJob('updateexchange')">
                    <input type="button" value="<?= translate('update_next_payments', $i18n) ?>" class="button tiny mobile-grow"
                        onclick="executeCronJob('updatenextpayment')">
                    <input type="button" value="<?= translate('store_total_yearly_cost', $i18n) ?>" class="button tiny mobile-grow"
                        onclick="executeCronJob('storetotalyearlycost')">
                    <input type="button" value="<?= translate('generate_recommendations', $i18n) ?>" class="button tiny mobile-grow"
                        onclick="executeCronJob('generaterecommendations')">
                </div>
                <div class="inline-row">
                    <textarea id="cronjobResult" class="thin" readonly></textarea>
                </div>
            </div>
        </div>
    </section>

    <section class="account-section" id="admin-backup" data-page-section>
        <header>
            <h2><?= translate('backup_and_restore', $i18n) ?></h2>
        </header>
        <div class="form-group-inline">
            <input type="button" class="button thin mobile-grow" value="<?= translate('backup', $i18n) ?>" id="backupDB"
                onClick="backupDB()" />
            <input type="button" class="secondary-button thin mobile-grow" value="<?= translate('restore', $i18n) ?>"
                id="restoreDB" onClick="openRestoreDBFileSelect()" />
            <input type="file" name="restoreDBFile" id="restoreDBFile" style="display: none;" onChange="restoreDB()"
                accept=".zip">
        </div>
        <div class="settings-notes">
            <p>
                <i class="fa-solid fa-circle-info"></i>
                <?= translate('restore_info', $i18n) ?>
            </p>
        </div>
    </section>

        </div>
    </div>
</section>
<script src="scripts/admin.js?<?= $version ?>"></script>

<?php
require_once 'includes/footer.php';
?>
