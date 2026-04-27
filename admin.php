<?php
require_once 'includes/header.php';
require_once 'includes/user_groups.php';
require_once 'includes/user_status.php';
require_once 'includes/subscription_media.php';
require_once 'includes/backup_manager.php';
require_once 'includes/backup_progress_messages.php';
require_once 'includes/timezone_settings.php';
require_once 'includes/security_rate_limit_presets.php';
require_once 'includes/runtime_observability.php';
require_once 'includes/system_maintenance.php';

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

function wallos_format_datetime_local_value($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $value);
    if (!$dateTime) {
        try {
            $dateTime = new DateTime($value);
        } catch (Throwable $throwable) {
            return '';
        }
    }

    return $dateTime->format('Y-m-d\TH:i');
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
$stmt = $db->prepare('
    SELECT
        user.id,
        user.username,
        user.email,
        user.user_group,
        user.trash_reason,
        user.trashed_at,
        user.scheduled_delete_at,
        (
            SELECT COUNT(*)
            FROM subscriptions
            WHERE subscriptions.user_id = user.id
        ) AS subscription_count,
        (
            SELECT COUNT(*)
            FROM subscription_uploaded_images
            WHERE subscription_uploaded_images.user_id = user.id
        ) AS subscription_image_count,
        (
            SELECT COUNT(*)
            FROM uploaded_avatars
            WHERE uploaded_avatars.user_id = user.id
        ) AS avatar_count
    FROM user
    WHERE account_status = :status
    ORDER BY trashed_at DESC
');
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

$activeInviteCodes = array_values(array_filter($inviteCodes, function ($inviteCode) {
    return (int) ($inviteCode['deleted'] ?? 0) !== 1;
}));

$deletedInviteCodes = array_values(array_filter($inviteCodes, function ($inviteCode) {
    return (int) ($inviteCode['deleted'] ?? 0) === 1;
}));

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
$trashedUserCount = count($trashedUsers);
$activeInviteCodeCount = count($activeInviteCodes);
$deletedInviteCodeCount = count($deletedInviteCodes);
$recentRequestLogCount = count($recentRequestLogs);
$totalRequestLogCount = (int) $db->querySingle('SELECT COUNT(*) FROM request_logs');
$securityAnomaliesTableExists = wallos_security_anomalies_table_exists($db);
$securityAnomalyCount = $securityAnomaliesTableExists ? wallos_count_security_anomalies($db) : 0;
$securityAnomalyRecentCount = $securityAnomaliesTableExists ? wallos_count_security_anomalies($db, 24) : 0;
$clientRuntimeRecentCount = $securityAnomaliesTableExists ? wallos_count_security_anomalies_by_type($db, 'client_runtime', 24) : 0;
$requestFailureRecentCount = $securityAnomaliesTableExists ? wallos_count_security_anomalies_by_type($db, 'request_failure', 24) : 0;
$serviceWorkerVersions = wallos_parse_service_worker_cache_versions(__DIR__ . '/service-worker.js');
$serviceWorkerVersionSummary = trim(implode(' | ', array_filter([
    $serviceWorkerVersions['static'] !== '' ? 'static=' . $serviceWorkerVersions['static'] : '',
    $serviceWorkerVersions['pages'] !== '' ? 'pages=' . $serviceWorkerVersions['pages'] : '',
    $serviceWorkerVersions['logos'] !== '' ? 'logos=' . $serviceWorkerVersions['logos'] : '',
])));
$serviceWorkerVersionSummary = $serviceWorkerVersionSummary !== '' ? $serviceWorkerVersionSummary : '-';
$backupRetentionDays = wallos_get_backup_retention_days($db);
$backupTimezone = wallos_normalize_timezone_identifier($settings['backup_timezone'] ?? '', wallos_get_default_backup_timezone());
$timezoneOptions = wallos_get_timezone_options($backupTimezone);
$securityAnomalyTypeCounts = $securityAnomaliesTableExists ? wallos_get_security_anomaly_type_counts($db, 24) : [];
$securityAnomalyTypeSummary = wallos_summarize_security_anomaly_type_counts($securityAnomalyTypeCounts);
$recentSecurityAnomalies = $securityAnomaliesTableExists ? wallos_get_recent_security_anomalies($db, 6, 24) : [];
$adminCacheRefreshMarker = wallos_read_cache_refresh_marker(__DIR__);
$adminCacheRefreshRequestedAt = trim((string) ($adminCacheRefreshMarker['token'] ?? '')) !== ''
    ? wallos_format_observability_timestamp($adminCacheRefreshMarker['requested_at'] ?? '', $backupTimezone)
    : translate('never_requested', $i18n);
$recentBackups = wallos_list_backups($db, 20, __DIR__);
$recentBackupCount = count($recentBackups);
$latestBackup = $recentBackups[0] ?? null;
$rateLimitPresets = wallos_get_rate_limit_presets($db);
$rateLimitPresetsJson = json_encode($rateLimitPresets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$maintenanceRetentionSummary = wallos_get_maintenance_retention_summary();
$maintenanceStorageSummary = wallos_get_storage_usage_summary($db, __DIR__);
$maintenanceStorageSummaryJson = json_encode($maintenanceStorageSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$backupProgressLabels = wallos_get_backup_progress_labels($lang);
$adminBackupsJsVersion = $version . '.' . @filemtime(__DIR__ . '/scripts/admin-backups.js');
$adminAccessLogsJsVersion = $version . '.' . @filemtime(__DIR__ . '/scripts/admin-access-logs.js');
$adminRateLimitJsVersion = $version . '.' . @filemtime(__DIR__ . '/scripts/admin-rate-limit.js');
$adminUsersJsVersion = $version . '.' . @filemtime(__DIR__ . '/scripts/admin-users.js');
$adminRegistrationJsVersion = $version . '.' . @filemtime(__DIR__ . '/scripts/admin-registration.js');
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
    <div id="admin-generated-password-ui" style="display:none;"
        data-title="<?= htmlspecialchars(translate('temporary_password_modal_title', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-password-label="<?= htmlspecialchars(translate('password', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-username-label="<?= htmlspecialchars(translate('username', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-copy-label="<?= htmlspecialchars(translate('copy_to_clipboard', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-close-label="<?= htmlspecialchars(translate('cancel', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-notice="<?= htmlspecialchars(translate('temporary_password_notice', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-copy-success="<?= htmlspecialchars(translate('copied_to_clipboard', $i18n), ENT_QUOTES, 'UTF-8') ?>"></div>
    <div id="admin-access-log-ui" style="display:none;"
        data-title="<?= htmlspecialchars(translate('access_logs', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-close-label="<?= htmlspecialchars(translate('cancel', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-open-label="<?= htmlspecialchars(translate('access_logs_open_modal', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-clear-label="<?= htmlspecialchars(translate('clear_logs', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-clear-confirm-label="<?= htmlspecialchars(translate('clear_access_logs_confirm', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-export-label="<?= htmlspecialchars(translate('export_logs', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-request-id-label="<?= htmlspecialchars(translate('request_id', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-keyword-label="<?= htmlspecialchars(translate('search', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-keyword-placeholder="<?= htmlspecialchars(translate('access_logs_keyword_placeholder', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-method-label="<?= htmlspecialchars(translate('request_method', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-start-label="<?= htmlspecialchars(translate('start_time', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-end-label="<?= htmlspecialchars(translate('end_time', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-limit-label="<?= htmlspecialchars(translate('results_limit', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-search-label="<?= htmlspecialchars(translate('search', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-empty-label="<?= htmlspecialchars(translate('access_logs_empty', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-showing-label="<?= htmlspecialchars(translate('access_logs_showing_results_dynamic', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-export-rule-label="<?= htmlspecialchars(translate('filter', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-id-label="<?= htmlspecialchars(translate('request_id', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-headers-label="<?= htmlspecialchars(translate('request_headers', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-time-label="<?= htmlspecialchars(translate('time', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-user-label="<?= htmlspecialchars(translate('username', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-ip-label="IP"
        data-forwarded-label="<?= htmlspecialchars(translate('forwarded_for', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-agent-label="<?= htmlspecialchars(translate('user_agent', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-error-label="<?= htmlspecialchars(translate('error', $i18n), ENT_QUOTES, 'UTF-8') ?>"></div>
    <div id="admin-security-anomaly-ui" style="display:none;"
        data-title="<?= htmlspecialchars(translate('security_anomalies', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-close-label="<?= htmlspecialchars(translate('cancel', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-open-label="<?= htmlspecialchars(translate('open_security_anomaly_browser', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-clear-label="<?= htmlspecialchars(translate('clear_logs', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-clear-confirm-label="<?= htmlspecialchars(translate('clear_security_anomalies_confirm', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-type-label="<?= htmlspecialchars(translate('anomaly_type', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-keyword-label="<?= htmlspecialchars(translate('search', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-keyword-placeholder="<?= htmlspecialchars(translate('security_anomalies_keyword_placeholder', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-start-label="<?= htmlspecialchars(translate('start_time', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-end-label="<?= htmlspecialchars(translate('end_time', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-limit-label="<?= htmlspecialchars(translate('results_limit', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-search-label="<?= htmlspecialchars(translate('search', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-empty-label="<?= htmlspecialchars(translate('security_anomalies_empty', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-showing-label="<?= htmlspecialchars(translate('security_anomalies_showing_results_dynamic', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-export-label="<?= htmlspecialchars(translate('export_logs', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-export-rule-label="<?= htmlspecialchars(translate('filter', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-id-label="<?= htmlspecialchars(translate('request_id', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-headers-label="<?= htmlspecialchars(translate('request_headers', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-time-label="<?= htmlspecialchars(translate('time', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-user-label="<?= htmlspecialchars(translate('username', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-ip-label="IP"
        data-forwarded-label="<?= htmlspecialchars(translate('forwarded_for', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-agent-label="<?= htmlspecialchars(translate('user_agent', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-message-label="<?= htmlspecialchars(translate('message', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-details-label="<?= htmlspecialchars(translate('details', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-error-label="<?= htmlspecialchars(translate('error', $i18n), ENT_QUOTES, 'UTF-8') ?>"></div>
    <div id="admin-runtime-observability-ui" style="display:none;"
        data-empty-label="<?= htmlspecialchars(translate('no_recent_anomalies', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-message-label="<?= htmlspecialchars(translate('message', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-user-label="<?= htmlspecialchars(translate('username', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-path-label="<?= htmlspecialchars(translate('anomaly_path', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-time-label="<?= htmlspecialchars(translate('time', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-cache-empty-label="<?= htmlspecialchars(translate('never_requested', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-refresh-success="<?= htmlspecialchars(translate('runtime_observability_refreshed', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-refresh-failed="<?= htmlspecialchars(translate('runtime_observability_refresh_failed', $i18n), ENT_QUOTES, 'UTF-8') ?>"></div>
    <div id="admin-rate-limit-preset-ui" style="display:none;"
        data-add-label="<?= htmlspecialchars(translate('add_preset', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-save-label="<?= htmlspecialchars(translate('save_preset', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-apply-label="<?= htmlspecialchars(translate('apply_preset', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-delete-label="<?= htmlspecialchars(translate('delete_preset', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-name-prompt="<?= htmlspecialchars(translate('rate_limit_preset_name_prompt', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-delete-confirm="<?= htmlspecialchars(translate('delete_rate_limit_preset_confirm', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-apply-notice="<?= htmlspecialchars(translate('rate_limit_preset_applied_notice', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-no-selection="<?= htmlspecialchars(translate('select_rate_limit_preset_first', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-presets='<?= htmlspecialchars($rateLimitPresetsJson ?: "[]", ENT_QUOTES, "UTF-8") ?>'></div>
    <div id="admin-service-worker-ui" style="display:none;"
        data-not-supported="<?= htmlspecialchars(translate('service_worker_not_supported', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-no-registration="<?= htmlspecialchars(translate('service_worker_no_registration', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-active="<?= htmlspecialchars(translate('service_worker_active', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-waiting="<?= htmlspecialchars(translate('service_worker_waiting', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-installing="<?= htmlspecialchars(translate('service_worker_installing', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-controlled="<?= htmlspecialchars(translate('service_worker_controlled', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-uncontrolled="<?= htmlspecialchars(translate('service_worker_uncontrolled', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-client-cache-status-unavailable="<?= htmlspecialchars(translate('client_cache_status_unavailable', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-client-cache-status-template="<?= htmlspecialchars(translate('client_cache_status_template', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-cache-clear-success="<?= htmlspecialchars(translate('service_worker_cache_cleared', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-cache-refresh-success="<?= htmlspecialchars(translate('service_worker_cache_refresh_requested', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        data-cache-refresh-failed="<?= htmlspecialchars(translate('service_worker_cache_refresh_failed', $i18n), ENT_QUOTES, 'UTF-8') ?>"></div>
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
                <input type="text" id="serverUrl" autocomplete="off" value="<?= htmlspecialchars($settings['server_url'], ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div class="form-group">
                <label for="customEditionTitle"><?= translate('custom_edition_title', $i18n) ?></label>
                <input type="text" id="customEditionTitle" autocomplete="off"
                    value="<?= htmlspecialchars($settings['custom_edition_title'] ?? 'Remastered', ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div class="form-group">
                <label for="customEditionSubtitle"><?= translate('custom_edition_subtitle', $i18n) ?></label>
                <input type="text" id="customEditionSubtitle" autocomplete="off"
                    value="<?= htmlspecialchars($settings['custom_edition_subtitle'] ?? '基于wallos原版深度魔改', ENT_QUOTES, 'UTF-8') ?>" />
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
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('custom_edition_info', $i18n) ?>
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
                <p>
                    <i class="fa fa-shield-halved" aria-hidden="true"></i>
                    <?= translate('disable_login_security_info', $i18n) ?>
                </p>
                <p>
                    <i class="fa fa-network-wired" aria-hidden="true"></i>
                    <?= translate('disable_login_security_info2', $i18n) ?>
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
            <header class="collapsible-section-header">
                <button type="button" class="collapsible-section-toggle" data-target="admin-users-body"
                    aria-expanded="true" onClick="toggleAdminSection(this)">
                    <span class="collapsible-section-heading">
                        <span><?= translate('user_management', $i18n) ?></span>
                        <span class="section-count-badge"><?= $userCount ?></span>
                    </span>
                    <i class="fa-solid fa-chevron-up"></i>
                </button>
            </header>
            <div class="collapsible-section-body" id="admin-users-body">
            <div class="user-card-grid">
                <?php
                foreach ($users as $user) {
                    $userIcon = $user['id'] == 1 ? 'fa-user-tie' : 'fa-id-badge';
                    $isPrimaryAdmin = (int) $user['id'] === 1;
                    ?>
                    <div class="user-card" data-userid="<?= $user['id'] ?>">
                        <div class="user-card-header">
                            <div class="user-card-title">
                                <div class="user-list-icon">
                                    <i class="fa-solid <?= $userIcon ?>"></i>
                                </div>
                                <span><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <?php
                            if ($isPrimaryAdmin) {
                                ?>
                                <span class="user-role-badge"><?= translate('administrator_user_group', $i18n) ?></span>
                                <?php
                            }
                            ?>
                        </div>
                        <div class="user-list-row">
                            <div title="<?= translate('user_id', $i18n) ?>">
                                <span class="user-card-label"><?= translate('user_id', $i18n) ?></span>
                                <div class="user-card-meta-inline">
                                    <strong><?= (int) $user['id'] ?></strong>
                                    <button type="button" class="secondary-button thin user-id-copy-button"
                                        onClick="copyUserId(<?= (int) $user['id'] ?>, this)">
                                        <i class="fa-regular fa-copy"></i>
                                        <span><?= translate('copy_user_id', $i18n) ?></span>
                                    </button>
                                </div>
                            </div>
                            <div title="<?= translate('username', $i18n) ?>">
                                <span class="user-card-label"><?= translate('username', $i18n) ?></span>
                                <strong><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <div title="<?= translate('email', $i18n) ?>">
                                <span class="user-card-label"><?= translate('email', $i18n) ?></span>
                                <a href="mailto:<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></a>
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
                            <input type="button" class="secondary-button thin"
                                value="<?= translate('reset_and_generate_temporary_password', $i18n) ?>"
                                data-confirm-message="<?= htmlspecialchars(translate('confirm_reset_and_generate_temporary_password', $i18n), ENT_QUOTES, 'UTF-8') ?>"
                                onClick="resetUserPassword(<?= (int) $user['id'] ?>, this)" />
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
            </div>
        </section>

        <?php
    }
    ?>

    <section class="account-section" id="admin-recycle-bin" data-page-section>
        <header class="collapsible-section-header">
            <button type="button" class="collapsible-section-toggle" data-target="admin-recycle-bin-body"
                aria-expanded="true" onClick="toggleAdminSection(this)">
                <span class="collapsible-section-heading">
                    <span><?= translate('recycle_bin', $i18n) ?></span>
                    <span class="section-count-badge"><?= $trashedUserCount ?></span>
                </span>
                <i class="fa-solid fa-chevron-up"></i>
            </button>
        </header>
        <div class="collapsible-section-body" id="admin-recycle-bin-body">
            <?php
            if (!empty($trashedUsers)) {
                ?>
                <div class="ban-user-list">
                    <?php
                    foreach ($trashedUsers as $trashedUser) {
                        $scheduledDeleteLocalValue = wallos_format_datetime_local_value($trashedUser['scheduled_delete_at'] ?? '');
                        $userGroupLabel = wallos_get_user_group_label($trashedUser['user_group'] ?? WALLOS_USER_GROUP_FREE, $i18n, false);
                        ?>
                        <div class="ban-user-card" data-trashed-userid="<?= (int) $trashedUser['id'] ?>">
                            <div class="user-list-row">
                                <div>
                                    <div class="user-list-icon">
                                        <i class="fa-solid fa-user-lock"></i>
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
                                <div class="ban-user-stats">
                                    <div class="ban-user-stat">
                                        <span><?= translate('user_id', $i18n) ?></span>
                                        <strong><?= (int) $trashedUser['id'] ?></strong>
                                    </div>
                                    <div class="ban-user-stat">
                                        <span><?= translate('user_group', $i18n) ?></span>
                                        <strong><?= htmlspecialchars($userGroupLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                                    </div>
                                    <div class="ban-user-stat">
                                        <span><?= translate('banned_user_subscription_count', $i18n) ?></span>
                                        <strong><?= (int) ($trashedUser['subscription_count'] ?? 0) ?></strong>
                                    </div>
                                    <div class="ban-user-stat">
                                        <span><?= translate('banned_user_uploaded_image_count', $i18n) ?></span>
                                        <strong><?= (int) ($trashedUser['subscription_image_count'] ?? 0) ?></strong>
                                    </div>
                                    <div class="ban-user-stat">
                                        <span><?= translate('banned_user_avatar_count', $i18n) ?></span>
                                        <strong><?= (int) ($trashedUser['avatar_count'] ?? 0) ?></strong>
                                    </div>
                                </div>
                                <p><strong><?= translate('recycle_bin_reason_label', $i18n) ?>:</strong> <?= htmlspecialchars($trashedUser['trash_reason'], ENT_QUOTES, 'UTF-8') ?></p>
                                <p><strong><?= translate('recycle_bin_trashed_at', $i18n) ?>:</strong> <?= htmlspecialchars($trashedUser['trashed_at'], ENT_QUOTES, 'UTF-8') ?></p>
                                <p><strong><?= translate('recycle_bin_scheduled_delete_at', $i18n) ?>:</strong> <span data-scheduled-delete-display><?= htmlspecialchars($trashedUser['scheduled_delete_at'], ENT_QUOTES, 'UTF-8') ?></span></p>
                                <div class="ban-user-schedule-controls">
                                    <input type="datetime-local" class="scheduled-delete-input" value="<?= htmlspecialchars($scheduledDeleteLocalValue, ENT_QUOTES, 'UTF-8') ?>" />
                                    <input type="button" class="secondary-button thin" value="<?= translate('save_scheduled_delete_time', $i18n) ?>"
                                        onClick="updateScheduledDeleteAt(<?= (int) $trashedUser['id'] ?>, this)" />
                                </div>
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
        </div>
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
        <header class="collapsible-section-header">
            <button type="button" class="collapsible-section-toggle" data-target="admin-invite-codes-body"
                aria-expanded="true" onClick="toggleAdminSection(this)">
                <span class="collapsible-section-heading">
                    <span><?= translate('invite_code_management', $i18n) ?></span>
                    <span class="section-count-badge"><?= $activeInviteCodeCount + $deletedInviteCodeCount ?></span>
                </span>
                <i class="fa-solid fa-chevron-up"></i>
            </button>
        </header>
        <div class="collapsible-section-body" id="admin-invite-codes-body">
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
            <div class="section-tabs" data-tab-group="invite-codes">
                <button type="button" class="section-tab-button is-active"
                    onClick="switchAdminTab('invite-codes', 'active', this)">
                    <span><?= translate('active_invite_codes', $i18n) ?></span>
                    <span class="section-count-badge"><?= $activeInviteCodeCount ?></span>
                </button>
                <button type="button" class="section-tab-button"
                    onClick="switchAdminTab('invite-codes', 'deleted', this)">
                    <span><?= translate('deleted_invite_codes', $i18n) ?></span>
                    <span class="section-count-badge"><?= $deletedInviteCodeCount ?></span>
                </button>
            </div>
            <div class="section-tab-panel is-active" data-tab-panel="invite-codes" data-tab-id="active">
                <?php
                if (!empty($activeInviteCodes)) {
                    ?>
                    <div class="invite-code-list">
                        <?php
                        foreach ($activeInviteCodes as $inviteCode) {
                            $usageSummary = $inviteCodeUsageMap[(int) $inviteCode['id']] ?? translate('invite_code_unused', $i18n);
                            ?>
                            <div class="invite-code-card" data-invite-code-id="<?= (int) $inviteCode['id'] ?>">
                                <div class="invite-code-header">
                                    <code><?= htmlspecialchars($inviteCode['code'], ENT_QUOTES, 'UTF-8') ?></code>
                                    <span class="invite-code-status"><?= translate('invite_code_active_status', $i18n) ?></span>
                                </div>
                                <p><?= translate('invite_code_max_uses', $i18n) ?>: <?= (int) $inviteCode['max_uses'] ?></p>
                                <p><?= translate('invite_code_uses_count', $i18n) ?>: <?= (int) $inviteCode['uses_count'] ?></p>
                                <p><?= translate('created_by', $i18n) ?>: <?= htmlspecialchars($inviteCode['creator_name'] ?? 'admin', ENT_QUOTES, 'UTF-8') ?></p>
                                <p><?= translate('used_by', $i18n) ?>: <?= htmlspecialchars($usageSummary, ENT_QUOTES, 'UTF-8') ?></p>
                                <div class="buttons">
                                    <input type="button" class="warning-button thin" value="<?= translate('delete_invite_code', $i18n) ?>"
                                        onClick="deleteInviteCode(<?= (int) $inviteCode['id'] ?>)" />
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
                            <?= translate('active_invite_code_list_empty', $i18n) ?>
                        </p>
                    </div>
                    <?php
                }
                ?>
            </div>
            <div class="section-tab-panel" data-tab-panel="invite-codes" data-tab-id="deleted">
                <?php
                if (!empty($deletedInviteCodes)) {
                    ?>
                    <div class="invite-code-list">
                        <?php
                        foreach ($deletedInviteCodes as $inviteCode) {
                            $usageSummary = $inviteCodeUsageMap[(int) $inviteCode['id']] ?? translate('invite_code_unused', $i18n);
                            ?>
                            <div class="invite-code-card is-deleted" data-invite-code-id="<?= (int) $inviteCode['id'] ?>">
                                <div class="invite-code-header">
                                    <code><?= htmlspecialchars($inviteCode['code'], ENT_QUOTES, 'UTF-8') ?></code>
                                    <span class="invite-code-status"><?= translate('invite_code_deleted_status', $i18n) ?></span>
                                </div>
                                <p><?= translate('invite_code_max_uses', $i18n) ?>: <?= (int) $inviteCode['max_uses'] ?></p>
                                <p><?= translate('invite_code_uses_count', $i18n) ?>: <?= (int) $inviteCode['uses_count'] ?></p>
                                <p><?= translate('created_by', $i18n) ?>: <?= htmlspecialchars($inviteCode['creator_name'] ?? 'admin', ENT_QUOTES, 'UTF-8') ?></p>
                                <p><?= translate('used_by', $i18n) ?>: <?= htmlspecialchars($usageSummary, ENT_QUOTES, 'UTF-8') ?></p>
                                <p><?= translate('deleted_at', $i18n) ?>: <?= htmlspecialchars($inviteCode['deleted_at'] ?: '-', ENT_QUOTES, 'UTF-8') ?></p>
                                <div class="buttons">
                                    <input type="button" class="warning-button thin" value="<?= translate('permanently_delete_invite_code', $i18n) ?>"
                                        data-confirm-message="<?= htmlspecialchars(translate('confirm_permanently_delete_invite_code', $i18n), ENT_QUOTES, 'UTF-8') ?>"
                                        onClick="permanentlyDeleteInviteCode(<?= (int) $inviteCode['id'] ?>, this)" />
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
                            <?= translate('deleted_invite_code_list_empty', $i18n) ?>
                        </p>
                    </div>
                    <?php
                }
                ?>
            </div>
        </div>
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
                    value="<?= htmlspecialchars($oidcSettings['name'], ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div class="form-group">
                <input type="text" id="oidcClientId" placeholder="<?= translate('client_id', $i18n) ?>" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['client_id'], ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div class="form-group">
                <input type="text" id="oidcClientSecret" placeholder="<?= translate('client_secret', $i18n) ?>" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['client_secret'], ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div class="form-group">
                <input type="text" id="oidcAuthUrl" placeholder="<?= translate('auth_url', $i18n) ?>" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['authorization_url'], ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div class="form-group">
                <input type="text" id="oidcTokenUrl" placeholder="<?= translate('token_url', $i18n) ?>" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['token_url'], ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div class="form-group">
                <input type="text" id="oidcUserInfoUrl" placeholder="<?= translate('user_info_url', $i18n) ?>" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['user_info_url'], ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div class="form-group">
                <input type="text" id="oidcRedirectUrl" placeholder="<?= translate('redirect_url', $i18n) ?>" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['redirect_url'], ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div class="form-group">
                <input type="text" id="oidcLogoutUrl" placeholder="<?= translate('logout_url', $i18n) ?>" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['logout_url'], ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div class="form-group">
                <input type="text" id="oidcUserIdentifierField" placeholder="<?= translate('user_identifier_field', $i18n) ?>" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['user_identifier_field'], ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div class="form-group">
                <input type="text" id="oidcScopes" placeholder="<?= translate('scopes', $i18n) ?>" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['scopes'], ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div class="form-group">
                <input type="hidden" id="oidcAuthStyle" placeholder="<?= translate('auth_style', $i18n) ?>" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['auth_style'], ENT_QUOTES, 'UTF-8') ?>" />
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
                    placeholder="<?= translate('smtp_address', $i18n) ?>" value="<?= htmlspecialchars($settings['smtp_address'], ENT_QUOTES, 'UTF-8') ?>" />
                <input type="text" name="smtpport" id="smtpport" autocomplete="off"
                    placeholder="<?= translate('port', $i18n) ?>" class="one-third" value="<?= htmlspecialchars($settings['smtp_port'], ENT_QUOTES, 'UTF-8') ?>" />
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
                    placeholder="<?= translate('smtp_username', $i18n) ?>" value="<?= htmlspecialchars($settings['smtp_username'], ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div class="form-group-inline">
                <input type="password" name="smtppassword" id="smtppassword" autocomplete="off"
                    placeholder="<?= translate('smtp_password', $i18n) ?>" value="<?= htmlspecialchars($settings['smtp_password'], ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div class="form-group-inline">
                <input type="text" name="fromemail" id="fromemail" autocomplete="off"
                    placeholder="<?= translate('from_email', $i18n) ?>" value="<?= htmlspecialchars($settings['from_email'], ENT_QUOTES, 'UTF-8') ?>" />
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
        <div class="form-group">
            <label for="rateLimitPresetSelect"><?= translate('rate_limit_presets', $i18n) ?></label>
            <select id="rateLimitPresetSelect">
                <option value=""><?= translate('select_a_preset', $i18n) ?></option>
                <?php
                foreach ($rateLimitPresets as $preset) {
                    ?>
                    <option value="<?= (int) $preset['id'] ?>"><?= htmlspecialchars($preset['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php
                }
                ?>
            </select>
        </div>
            <div class="buttons">
                <input type="button" class="secondary-button thin mobile-grow" value="<?= translate('apply_preset', $i18n) ?>"
                    onClick="window.WallosAdminRateLimit?.applyRateLimitPresetButton?.()" />
                <input type="button" class="secondary-button thin mobile-grow" value="<?= translate('save_preset', $i18n) ?>"
                    onClick="window.WallosAdminRateLimit?.saveRateLimitPresetButton?.()" />
                <input type="button" class="secondary-button thin mobile-grow" value="<?= translate('add_preset', $i18n) ?>"
                    onClick="window.WallosAdminRateLimit?.addRateLimitPresetButton?.()" />
                <input type="button" class="warning-button thin mobile-grow" value="<?= translate('delete_preset', $i18n) ?>"
                    onClick="window.WallosAdminRateLimit?.deleteRateLimitPresetButton?.()" />
        </div>
        <div class="form-group-inline">
            <input type="checkbox" id="advancedRateLimitEnabled" <?= !empty($settings['advanced_rate_limit_enabled']) ? 'checked' : '' ?> />
            <label for="advancedRateLimitEnabled"><?= translate('enable_advanced_rate_limits', $i18n) ?></label>
        </div>
        <div class="form-group">
            <label for="login_rate_limit_max_attempts"><?= translate('login_rate_limit_max_attempts', $i18n) ?></label>
            <input type="number" name="login_rate_limit_max_attempts" id="login_rate_limit_max_attempts" min="1" max="50"
                autocomplete="off" value="<?= (int) ($settings['login_rate_limit_max_attempts'] ?? 8) ?>" />
        </div>
        <div class="form-group">
            <label for="login_rate_limit_block_minutes"><?= translate('login_rate_limit_block_minutes', $i18n) ?></label>
            <input type="number" name="login_rate_limit_block_minutes" id="login_rate_limit_block_minutes" min="1" max="1440"
                autocomplete="off" value="<?= (int) ($settings['login_rate_limit_block_minutes'] ?? 15) ?>" />
        </div>
        <div class="account-budget-grid">
            <div class="form-group">
                <label for="backend_request_limit_per_minute"><?= translate('backend_request_limit_per_minute', $i18n) ?></label>
                <input type="number" id="backend_request_limit_per_minute" min="1" max="5000" autocomplete="off"
                    value="<?= (int) ($settings['backend_request_limit_per_minute'] ?? 240) ?>" />
            </div>
            <div class="form-group">
                <label for="backend_request_limit_per_hour"><?= translate('backend_request_limit_per_hour', $i18n) ?></label>
                <input type="number" id="backend_request_limit_per_hour" min="1" max="20000" autocomplete="off"
                    value="<?= (int) ($settings['backend_request_limit_per_hour'] ?? 3600) ?>" />
            </div>
            <div class="form-group">
                <label for="image_upload_limit_per_minute"><?= translate('image_upload_limit_per_minute', $i18n) ?></label>
                <input type="number" id="image_upload_limit_per_minute" min="1" max="1000" autocomplete="off"
                    value="<?= (int) ($settings['image_upload_limit_per_minute'] ?? 20) ?>" />
            </div>
            <div class="form-group">
                <label for="image_upload_limit_per_hour"><?= translate('image_upload_limit_per_hour', $i18n) ?></label>
                <input type="number" id="image_upload_limit_per_hour" min="1" max="10000" autocomplete="off"
                    value="<?= (int) ($settings['image_upload_limit_per_hour'] ?? 240) ?>" />
            </div>
            <div class="form-group">
                <label for="image_upload_mb_per_minute"><?= translate('image_upload_mb_per_minute', $i18n) ?></label>
                <input type="number" id="image_upload_mb_per_minute" min="1" max="50000" autocomplete="off"
                    value="<?= (int) ($settings['image_upload_mb_per_minute'] ?? 120) ?>" />
            </div>
            <div class="form-group">
                <label for="image_upload_mb_per_hour"><?= translate('image_upload_mb_per_hour', $i18n) ?></label>
                <input type="number" id="image_upload_mb_per_hour" min="1" max="200000" autocomplete="off"
                    value="<?= (int) ($settings['image_upload_mb_per_hour'] ?? 1200) ?>" />
            </div>
            <div class="form-group">
                <label for="image_download_limit_per_minute"><?= translate('image_download_limit_per_minute', $i18n) ?></label>
                <input type="number" id="image_download_limit_per_minute" min="1" max="10000" autocomplete="off"
                    value="<?= (int) ($settings['image_download_limit_per_minute'] ?? 180) ?>" />
            </div>
            <div class="form-group">
                <label for="image_download_limit_per_hour"><?= translate('image_download_limit_per_hour', $i18n) ?></label>
                <input type="number" id="image_download_limit_per_hour" min="1" max="50000" autocomplete="off"
                    value="<?= (int) ($settings['image_download_limit_per_hour'] ?? 2400) ?>" />
            </div>
            <div class="form-group">
                <label for="image_download_mb_per_minute"><?= translate('image_download_mb_per_minute', $i18n) ?></label>
                <input type="number" id="image_download_mb_per_minute" min="1" max="50000" autocomplete="off"
                    value="<?= (int) ($settings['image_download_mb_per_minute'] ?? 300) ?>" />
            </div>
            <div class="form-group">
                <label for="image_download_mb_per_hour"><?= translate('image_download_mb_per_hour', $i18n) ?></label>
                <input type="number" id="image_download_mb_per_hour" min="1" max="200000" autocomplete="off"
                    value="<?= (int) ($settings['image_download_mb_per_hour'] ?? 3000) ?>" />
            </div>
        </div>
        <div class="form-group-inline">
            <input type="text" name="local_webhook_notifications_allowlist" id="local_webhook_notifications_allowlist" autocomplete="off"
                placeholder="<?= translate('local_webhook_allowlist_placeholder', $i18n) ?>" value="<?= htmlspecialchars($settings['local_webhook_notifications_allowlist'] ?? '', ENT_QUOTES, 'UTF-8') ?>" />
        </div>
        
        <div class="buttons">
            <input type="submit" class="thin mobile-grow" value="<?= translate('save', $i18n) ?>"
                id="saveSecuritySettingsButton" onClick="window.WallosAdminRateLimit?.saveSecuritySettingsButton?.()" />
        </div>
        
        <div class="settings-notes">
            <p>
                <i class="fa-solid fa-circle-info"></i> 
                <?= translate('login_rate_limit_max_attempts_info', $i18n) ?>
            </p>
            <p>
                <i class="fa-solid fa-circle-info"></i>
                <?= translate('login_rate_limit_block_minutes_info', $i18n) ?>
            </p>
            <p>
                <i class="fa-solid fa-circle-info"></i>
                <?= translate('advanced_rate_limits_info', $i18n) ?>
            </p>
            <p>
                <i class="fa-solid fa-circle-info"></i> 
                <?= translate('ssrf_protection_info', $i18n) ?>
            </p>
            <p>
                <i class="fa-solid fa-circle-info"></i>
                <?= translate('local_webhook_info', $i18n) ?>
            </p>
        </div>
        <div class="access-log-summary-grid">
            <div class="backup-summary-card">
                <span><?= translate('security_anomalies', $i18n) ?></span>
                <strong data-observability-count="security_total"><?= (int) $securityAnomalyCount ?></strong>
            </div>
            <div class="backup-summary-card">
                <span><?= translate('recent_security_anomalies', $i18n) ?></span>
                <strong data-observability-count="security_recent_24h"><?= (int) $securityAnomalyRecentCount ?></strong>
            </div>
            <div class="backup-summary-card">
                <span><?= translate('recent_client_runtime_errors', $i18n) ?></span>
                <strong data-observability-count="client_runtime_24h"><?= (int) $clientRuntimeRecentCount ?></strong>
            </div>
            <div class="backup-summary-card">
                <span><?= translate('recent_request_failures', $i18n) ?></span>
                <strong data-observability-count="request_failure_24h"><?= (int) $requestFailureRecentCount ?></strong>
            </div>
            <div class="backup-summary-card">
                <span><?= translate('service_worker_versions', $i18n) ?></span>
                <strong class="compact-summary-text"><?= htmlspecialchars($serviceWorkerVersionSummary, ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div class="backup-summary-card">
                <span><?= translate('service_worker_registration_state', $i18n) ?></span>
                <strong id="admin-sw-registration-state">-</strong>
            </div>
            <div class="backup-summary-card">
                <span><?= translate('service_worker_controller_state', $i18n) ?></span>
                <strong id="admin-sw-controller-state">-</strong>
            </div>
            <div class="backup-summary-card">
                <span><?= translate('client_cache_status', $i18n) ?></span>
                <strong class="compact-summary-text" id="admin-client-cache-state">-</strong>
            </div>
            <div class="backup-summary-card">
                <span><?= translate('recent_anomaly_type_breakdown', $i18n) ?></span>
                <strong class="compact-summary-text" data-observability-type-summary><?= htmlspecialchars($securityAnomalyTypeSummary, ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div class="backup-summary-card">
                <span><?= translate('service_worker_last_refresh_request', $i18n) ?></span>
                <strong class="compact-summary-text" data-observability-cache-refresh><?= htmlspecialchars($adminCacheRefreshRequestedAt, ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
        </div>
        <div class="runtime-observability-panel">
            <div class="runtime-observability-header">
                <div>
                    <h3><?= translate('runtime_observability', $i18n) ?></h3>
                    <p><?= translate('runtime_observability_info', $i18n) ?></p>
                </div>
                <div class="runtime-observability-actions">
                    <button type="button" class="secondary-button thin" id="openClientRuntimeAnomaliesButton"
                        onClick="window.WallosAdminAccessLogs?.openSecurityAnomaliesModal?.({ anomaly_type: 'client_runtime' })">
                        <?= translate('open_frontend_errors', $i18n) ?>
                    </button>
                    <button type="button" class="secondary-button thin" id="openRequestFailureAnomaliesButton"
                        onClick="window.WallosAdminAccessLogs?.openSecurityAnomaliesModal?.({ anomaly_type: 'request_failure' })">
                        <?= translate('open_request_failures', $i18n) ?>
                    </button>
                    <button type="button" class="button thin" id="refreshRuntimeObservabilityButton" onClick="refreshRuntimeObservabilityButton(this)">
                        <?= translate('refresh_runtime_observability', $i18n) ?>
                    </button>
                </div>
            </div>
            <div class="runtime-observability-feed" data-observability-feed>
                <?php if (empty($recentSecurityAnomalies)) : ?>
                    <div class="settings-notes access-log-empty">
                        <p><i class="fa-solid fa-circle-info"></i><?= translate('no_recent_anomalies', $i18n) ?></p>
                    </div>
                <?php else : ?>
                    <?php foreach ($recentSecurityAnomalies as $anomaly) : ?>
                        <article class="runtime-anomaly-card">
                            <div class="runtime-anomaly-card-header">
                                <span class="access-log-id-badge">#<?= (int) ($anomaly['id'] ?? 0) ?></span>
                                <strong><?= htmlspecialchars((string) ($anomaly['anomaly_type'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                                <span><?= htmlspecialchars((string) ($anomaly['anomaly_code'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <p><?= htmlspecialchars((string) ($anomaly['message'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></p>
                            <small>
                                <?= htmlspecialchars((string) ($anomaly['username'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                · <?= htmlspecialchars((string) ($anomaly['ip_address'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                · <?= htmlspecialchars(wallos_format_observability_timestamp($anomaly['created_at'] ?? '', $backupTimezone), ENT_QUOTES, 'UTF-8') ?>
                            </small>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="buttons">
            <input type="button" class="secondary-button thin mobile-grow" id="openSecurityAnomaliesButton"
                value="<?= translate('open_security_anomaly_browser', $i18n) ?>"
                onClick="window.WallosAdminAccessLogs?.openSecurityAnomaliesModal?.()" />
            <input type="button" class="secondary-button thin mobile-grow" value="<?= translate('service_worker_clear_client_cache', $i18n) ?>"
                id="clearClientCacheButton" onClick="clearClientCacheButton(this)" />
            <input type="button" class="button thin mobile-grow" value="<?= translate('service_worker_broadcast_refresh', $i18n) ?>"
                id="requestClientCacheRefreshButton" onClick="requestClientCacheRefreshButton(this)" />
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
        <header class="collapsible-section-header">
            <button type="button" class="collapsible-section-toggle" data-target="admin-access-logs-body"
                aria-expanded="true" onClick="toggleAdminSection(this)">
                <span class="collapsible-section-heading">
                    <span><?= translate('access_logs', $i18n) ?></span>
                    <span class="section-count-badge"><?= $recentRequestLogCount ?></span>
                </span>
                <i class="fa-solid fa-chevron-up"></i>
            </button>
        </header>
        <div class="collapsible-section-body" id="admin-access-logs-body">
            <div class="access-log-summary-grid">
                <div class="backup-summary-card">
                    <span><?= translate('recent_request_logs', $i18n) ?></span>
                    <strong><?= (int) $recentRequestLogCount ?></strong>
                </div>
                <div class="backup-summary-card">
                    <span><?= translate('access_logs_total', $i18n) ?></span>
                    <strong><?= (int) $totalRequestLogCount ?></strong>
                </div>
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('access_logs_modal_info', $i18n) ?>
                </p>
            </div>
            <div class="buttons">
                <input type="button" class="button thin mobile-grow" id="openAccessLogsButton"
                    value="<?= translate('access_logs_open_modal', $i18n) ?>"
                    onClick="window.WallosAdminAccessLogs?.openAccessLogsModal?.()" />
            </div>
        </div>
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
            <h3><?= translate('maintenance_retention_strategy', $i18n) ?></h3>
            <div class="backup-summary-grid">
                <div class="backup-summary-card">
                    <span><?= translate('request_log_retention_days', $i18n) ?></span>
                    <strong><?= (int) $maintenanceRetentionSummary['request_log_retention_days'] ?></strong>
                </div>
                <div class="backup-summary-card">
                    <span><?= translate('security_anomaly_retention_days', $i18n) ?></span>
                    <strong><?= (int) $maintenanceRetentionSummary['security_anomaly_retention_days'] ?></strong>
                </div>
                <div class="backup-summary-card">
                    <span><?= translate('rate_limit_usage_retention_days', $i18n) ?></span>
                    <strong><?= (int) $maintenanceRetentionSummary['rate_limit_usage_retention_days'] ?></strong>
                </div>
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('maintenance_retention_strategy_info', $i18n) ?>
                </p>
            </div>
            <h3><?= translate('storage_usage_summary', $i18n) ?></h3>
            <div id="adminMaintenanceStorageSummary" class="backup-summary-grid"
                data-storage-summary="<?= htmlspecialchars($maintenanceStorageSummaryJson ?: '{}', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('storage_usage_info', $i18n) ?>
                </p>
                <p>
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <?= translate('log_growth_risk_info', $i18n) ?>
                </p>
            </div>
            <div class="inline-row">
                <input type="button" value="<?= translate('refresh_storage_usage', $i18n) ?>" class="button tiny mobile-grow"
                    id="refreshStorageUsageButton"
                    onclick="runAdminMaintenanceAction('get_storage_usage', this)">
            </div>
            <h3><?= translate('subscription_image_audit', $i18n) ?></h3>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('subscription_image_audit_info', $i18n) ?>
                </p>
            </div>
            <div class="inline-row">
                <input type="button" value="<?= translate('scan_subscription_images', $i18n) ?>" class="button tiny mobile-grow"
                    onclick="runAdminMaintenanceAction('scan_subscription_images', this)">
                <input type="button" value="<?= translate('export_subscription_image_audit', $i18n) ?>" class="secondary-button tiny mobile-grow"
                    onclick="exportAdminSubscriptionImageAuditCsv()">
                <input type="button" value="<?= translate('reuse_oversized_subscription_image_variants', $i18n) ?>" class="secondary-button tiny mobile-grow"
                    onclick="runAdminMaintenanceAction('reuse_oversized_subscription_image_variants', this)"
                    data-confirm-message="<?= htmlspecialchars(translate('reuse_oversized_subscription_image_variants_confirm', $i18n), ENT_QUOTES, 'UTF-8') ?>">
                <input type="button" value="<?= translate('cleanup_subscription_image_orphans', $i18n) ?>" class="secondary-button tiny mobile-grow"
                    onclick="runAdminMaintenanceAction('cleanup_subscription_image_orphans', this)"
                    data-confirm-message="<?= htmlspecialchars(translate('cleanup_subscription_image_orphans_confirm', $i18n), ENT_QUOTES, 'UTF-8') ?>">
                <input type="button" value="<?= translate('run_sqlite_maintenance', $i18n) ?>" class="secondary-button tiny mobile-grow"
                    onclick="runAdminMaintenanceAction('run_sqlite_maintenance', this)"
                    data-confirm-message="<?= htmlspecialchars(translate('sqlite_maintenance_confirm', $i18n), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="inline-row">
                <textarea id="adminMaintenanceResult" class="thin" readonly
                    placeholder="<?= htmlspecialchars(translate('maintenance_result', $i18n), ENT_QUOTES, 'UTF-8') ?>"></textarea>
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
        <div class="admin-form backup-admin-panel">
            <div class="backup-summary-grid">
                <div class="backup-summary-card">
                    <span><?= translate('recent_backups', $i18n) ?></span>
                    <strong><?= (int) $recentBackupCount ?></strong>
                </div>
                <div class="backup-summary-card">
                    <span><?= translate('latest_backup_time', $i18n) ?></span>
                    <strong><?= htmlspecialchars($latestBackup['created_at'] ?? '-', ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="backup-summary-card">
                    <span><?= translate('backup_retention_days', $i18n) ?></span>
                    <strong><?= (int) $backupRetentionDays ?></strong>
                </div>
            </div>
            <div class="form-group">
                <label for="backupRetentionDays"><?= translate('backup_retention_days', $i18n) ?></label>
                <input type="number" id="backupRetentionDays" min="1" max="365" autocomplete="off"
                    value="<?= (int) $backupRetentionDays ?>" />
            </div>
            <div class="form-group">
                <label for="backupTimezone"><?= translate('backup_timezone', $i18n) ?></label>
                <select id="backupTimezone">
                    <?php
                    foreach ($timezoneOptions as $timezoneOption) {
                        ?>
                        <option value="<?= htmlspecialchars($timezoneOption['value'], ENT_QUOTES, 'UTF-8') ?>" <?= $timezoneOption['selected'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($timezoneOption['label'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                        <?php
                    }
                    ?>
                </select>
                <div class="settings-notes">
                    <p>
                        <i class="fa-solid fa-circle-info"></i>
                        <?= translate('backup_timezone_info', $i18n) ?>
                    </p>
                </div>
            </div>
            <div class="buttons backup-action-row">
                <input type="button" class="secondary-button thin mobile-grow" value="<?= translate('save', $i18n) ?>"
                    id="saveBackupSettingsButton" onClick="window.WallosAdminBackups?.saveBackupSettingsButton?.()" />
                <input type="button" class="button thin mobile-grow" value="<?= translate('backup', $i18n) ?>" id="backupDB"
                    onClick="window.WallosAdminBackups?.backupDB?.()" />
                <input type="button" class="secondary-button thin mobile-grow" value="<?= translate('cleanup_old_backups', $i18n) ?>"
                    id="cleanupOldBackupsButton"
                    data-confirm-message="<?= htmlspecialchars(translate('cleanup_old_backups_confirm', $i18n), ENT_QUOTES, 'UTF-8') ?>"
                    onClick="window.WallosAdminBackups?.cleanupOldBackupsButton?.(this)" />
                <input type="button" class="secondary-button thin mobile-grow" value="<?= translate('restore', $i18n) ?>"
                    id="restoreDB" onClick="window.WallosAdminBackups?.openRestoreDBFileSelect?.()" />
                <input type="file" name="restoreDBFile" id="restoreDBFile" style="display: none;" onChange="window.WallosAdminBackups?.restoreDB?.()"
                    accept=".zip">
            </div>
            <div class="backup-progress-card is-hidden is-pending" id="backupProgressCard"
                data-backup-label="<?= htmlspecialchars(translate('backup', $i18n), ENT_QUOTES, 'UTF-8') ?>"
                data-idle-message="<?= htmlspecialchars($backupProgressLabels['idle_message'], ENT_QUOTES, 'UTF-8') ?>"
                data-starting-message="<?= htmlspecialchars($backupProgressLabels['starting_message'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="backup-progress-card-header">
                    <div class="backup-progress-card-title">
                        <span><?= htmlspecialchars($backupProgressLabels['panel_title'], ENT_QUOTES, 'UTF-8') ?></span>
                        <strong id="backupProgressPercent">0%</strong>
                    </div>
                    <span class="backup-progress-card-tone" id="backupProgressTone"><?= translate('backup', $i18n) ?></span>
                </div>
                <div class="backup-progress-bar" aria-hidden="true">
                    <span id="backupProgressBar"></span>
                </div>
                <p class="backup-progress-message" id="backupProgressMessage"><?= htmlspecialchars($backupProgressLabels['idle_message'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <?php
            if (!empty($recentBackups)) {
                ?>
                <div class="backup-list">
                    <?php
                    foreach ($recentBackups as $backup) {
                        $modeClass = $backup['mode'] === 'auto' ? 'auto' : 'manual';
                        $modeLabel = $backup['mode'] === 'auto'
                            ? translate('backup_type_auto', $i18n)
                            : translate('backup_type_manual', $i18n);
                        ?>
                        <div class="backup-card">
                            <div class="backup-card-header">
                                <div class="backup-card-title">
                                    <code><?= htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8') ?></code>
                                    <span class="backup-mode-badge <?= $modeClass ?>"><?= $modeLabel ?></span>
                                </div>
                            </div>
                            <div class="backup-card-meta">
                                <p><?= translate('backup_created_at', $i18n) ?>: <?= htmlspecialchars($backup['created_at'], ENT_QUOTES, 'UTF-8') ?></p>
                                <p><?= translate('backup_size', $i18n) ?>: <?= htmlspecialchars($backup['size_label'], ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <div class="backup-card-actions">
                                <a class="secondary-button thin backup-download-button"
                                    href="<?= htmlspecialchars($backup['download_url'], ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="fa-solid fa-download"></i>
                                    <span><?= translate('download_backup', $i18n) ?></span>
                                </a>
                                <button type="button" class="secondary-button thin backup-verify-button"
                                    onClick="window.WallosAdminBackups?.verifyBackup?.('<?= htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8') ?>', this)">
                                    <i class="fa-solid fa-shield-halved"></i>
                                    <span><?= translate('verify_backup', $i18n) ?></span>
                                </button>
                                <button type="button" class="thin backup-restore-button"
                                    data-confirm-message="<?= htmlspecialchars(translate('restore_selected_backup_confirm', $i18n), ENT_QUOTES, 'UTF-8') ?>"
                                    data-confirm-second-message="<?= htmlspecialchars(translate('restore_selected_backup_confirm_second', $i18n), ENT_QUOTES, 'UTF-8') ?>"
                                    onClick="window.WallosAdminBackups?.restoreBackup?.('<?= htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8') ?>', this)">
                                    <i class="fa-solid fa-clock-rotate-left"></i>
                                    <span><?= translate('restore_selected_backup', $i18n) ?></span>
                                </button>
                            </div>
                            <div class="backup-card-status is-pending" data-backup-status>
                                <?= translate('backup_verification_not_run', $i18n) ?>
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
                        <?= translate('backup_no_files_yet', $i18n) ?>
                    </p>
                </div>
                <?php
            }
            ?>
        </div>
        <div class="settings-notes">
            <p>
                <i class="fa-solid fa-circle-info"></i>
                <?= sprintf(translate('backup_retention_notice_dynamic', $i18n), (int) $backupRetentionDays) ?>
            </p>
            <p>
                <i class="fa-solid fa-circle-info"></i>
                <?= translate('backup_auto_schedule_info', $i18n) ?>
            </p>
            <p>
                <i class="fa-solid fa-circle-info"></i>
                <?= translate('backup_restore_from_list_info', $i18n) ?>
            </p>
            <p>
                <i class="fa-solid fa-circle-info"></i>
                <?= translate('restore_info', $i18n) ?>
            </p>
        </div>
    </section>

        </div>
    </div>
</section>
<script src="scripts/admin-backups.js?<?= $adminBackupsJsVersion ?>"></script>
<script src="scripts/admin-access-logs.js?<?= $adminAccessLogsJsVersion ?>"></script>
<script src="scripts/admin-rate-limit.js?<?= $adminRateLimitJsVersion ?>"></script>
<script src="scripts/admin-users.js?<?= $adminUsersJsVersion ?>"></script>
<script src="scripts/admin-registration.js?<?= $adminRegistrationJsVersion ?>"></script>
<script src="scripts/admin.js?<?= $version ?>"></script>

<?php
require_once 'includes/footer.php';
?>
