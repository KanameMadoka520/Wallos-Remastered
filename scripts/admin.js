function makeFetchCall(url, data, button) {
  return window.WallosApi.postJson(url, data)
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
      button.disabled = false;
    })
    .catch((error) => {
      showErrorMessage(window.WallosApi.getErrorMessage(error, translate("error")));
      button.disabled = false;
    });

}

function getAdminRequestErrorMessage(error) {
  return window.WallosApi.getErrorMessage(error, translate("error"));
}

function adminPostJson(url, data, options = {}) {
  return window.WallosApi.postJson(url, data, {
    fallbackErrorMessage: translate("error"),
    ...options,
  });
}

function adminGetText(url, options = {}) {
  return window.WallosApi.getText(url, {
    fallbackErrorMessage: translate("error"),
    ...options,
  });
}

function adminTranslateWithFallback(key, fallback) {
  if (typeof translateWithFallback === "function") {
    return translateWithFallback(key, fallback);
  }

  if (typeof translate === "function") {
    const value = translate(key);
    if (value && value !== "[Translation Missing]") {
      return value;
    }
  }

  return fallback;
}

let latestAdminSubscriptionImageAudit = null;

function escapeAdminHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function formatAdminNumber(value) {
  const numericValue = Number(value ?? 0);
  if (!Number.isFinite(numericValue)) {
    return "0";
  }

  return numericValue.toLocaleString();
}

function getAdminLogRiskLabel(risk) {
  const normalizedRisk = String(risk || "normal");
  const labels = {
    normal: adminTranslateWithFallback("log_growth_risk_normal", "Normal"),
    watch: adminTranslateWithFallback("log_growth_risk_watch", "Watch"),
    high: adminTranslateWithFallback("log_growth_risk_high", "High"),
  };

  return labels[normalizedRisk] || labels.normal;
}

function renderAdminMaintenanceStorageCard(label, value, detail, iconClass = "fa-solid fa-hard-drive") {
  return `
    <div class="backup-summary-card maintenance-storage-card">
      <span><i class="${escapeAdminHtml(iconClass)}"></i> ${escapeAdminHtml(label)}</span>
      <strong>${escapeAdminHtml(value)}</strong>
      ${detail ? `<small>${escapeAdminHtml(detail)}</small>` : ""}
    </div>
  `;
}

function buildAdminDirectoryStorageDetail(directory) {
  const fileCount = formatAdminNumber(directory?.file_count ?? 0);
  const directoryCount = formatAdminNumber(directory?.directory_count ?? 0);
  const scanErrors = Number(directory?.scan_errors ?? 0);
  const detail = `${adminTranslateWithFallback("file_count", "Files")}: ${fileCount} · ${adminTranslateWithFallback("directory_count", "Directories")}: ${directoryCount}`;

  if (scanErrors > 0) {
    return `${detail} · ${adminTranslateWithFallback("scan_errors", "Scan Errors")}: ${formatAdminNumber(scanErrors)}`;
  }

  return detail;
}

function buildAdminLogStorageDetail(logInfo) {
  const retentionDays = Number(logInfo?.retention_days ?? 0);
  const riskLabel = getAdminLogRiskLabel(logInfo?.risk);

  return `${adminTranslateWithFallback("retention_days", "Retention Days")}: ${formatAdminNumber(retentionDays)} · ${adminTranslateWithFallback("log_growth_risk", "Log Growth Risk")}: ${riskLabel}`;
}

function renderAdminMaintenanceStorageSummary(storage) {
  const container = document.getElementById("adminMaintenanceStorageSummary");
  if (!container || !storage || typeof storage !== "object") {
    return;
  }

  const database = storage.database || {};
  const directories = storage.directories || {};
  const logs = storage.logs || {};
  const databaseDetail = [
    `${adminTranslateWithFallback("sqlite_page_count", "SQLite Pages")}: ${formatAdminNumber(database.page_count ?? 0)}`,
    `${adminTranslateWithFallback("sqlite_free_pages", "Free Pages")}: ${formatAdminNumber(database.freelist_count ?? 0)}`,
    `${adminTranslateWithFallback("sqlite_free_size", "Free Size")}: ${database.free_size_label || "0 B"}`,
  ].join(" · ");

  const cards = [
    renderAdminMaintenanceStorageCard(
      adminTranslateWithFallback("database_file_size", "Database File Size"),
      database.size_label || "0 B",
      databaseDetail,
      "fa-solid fa-database"
    ),
    renderAdminMaintenanceStorageCard(
      adminTranslateWithFallback("logos_storage_size", "Upload Root Storage"),
      directories.logos?.size_label || "0 B",
      buildAdminDirectoryStorageDetail(directories.logos),
      "fa-solid fa-folder-open"
    ),
    renderAdminMaintenanceStorageCard(
      adminTranslateWithFallback("subscription_media_storage_size", "Subscription Media Storage"),
      directories.subscription_media?.size_label || "0 B",
      buildAdminDirectoryStorageDetail(directories.subscription_media),
      "fa-solid fa-images"
    ),
    renderAdminMaintenanceStorageCard(
      adminTranslateWithFallback("backup_storage_size", "Backup Storage"),
      directories.backups?.size_label || "0 B",
      buildAdminDirectoryStorageDetail(directories.backups),
      "fa-solid fa-box-archive"
    ),
    renderAdminMaintenanceStorageCard(
      adminTranslateWithFallback("request_log_rows", "Request Log Rows"),
      logs.request_logs?.rows_label || formatAdminNumber(logs.request_logs?.rows ?? 0),
      buildAdminLogStorageDetail(logs.request_logs),
      "fa-solid fa-list"
    ),
    renderAdminMaintenanceStorageCard(
      adminTranslateWithFallback("security_anomaly_rows", "Security Anomaly Rows"),
      logs.security_anomalies?.rows_label || formatAdminNumber(logs.security_anomalies?.rows ?? 0),
      buildAdminLogStorageDetail(logs.security_anomalies),
      "fa-solid fa-shield-halved"
    ),
    renderAdminMaintenanceStorageCard(
      adminTranslateWithFallback("rate_limit_usage_rows", "Rate-Limit Usage Rows"),
      logs.rate_limit_usage?.rows_label || formatAdminNumber(logs.rate_limit_usage?.rows ?? 0),
      buildAdminLogStorageDetail(logs.rate_limit_usage),
      "fa-solid fa-gauge-high"
    ),
  ];

  container.innerHTML = cards.join("");
}

function formatAdminStorageSummary(storage) {
  if (!storage || typeof storage !== "object") {
    return translate("success");
  }

  const directories = storage.directories || {};
  const logs = storage.logs || {};

  return [
    `${adminTranslateWithFallback("generated_at", "Generated At")}: ${storage.generated_at || "-"}`,
    `${adminTranslateWithFallback("database_file_size", "Database File Size")}: ${storage.database?.size_label || "0 B"}`,
    `${adminTranslateWithFallback("sqlite_page_count", "SQLite Pages")}: ${formatAdminNumber(storage.database?.page_count ?? 0)}`,
    `${adminTranslateWithFallback("sqlite_free_pages", "Free Pages")}: ${formatAdminNumber(storage.database?.freelist_count ?? 0)}`,
    `${adminTranslateWithFallback("logos_storage_size", "Upload Root Storage")}: ${directories.logos?.size_label || "0 B"}`,
    `${adminTranslateWithFallback("subscription_media_storage_size", "Subscription Media Storage")}: ${directories.subscription_media?.size_label || "0 B"}`,
    `${adminTranslateWithFallback("backup_storage_size", "Backup Storage")}: ${directories.backups?.size_label || "0 B"}`,
    `${adminTranslateWithFallback("request_log_rows", "Request Log Rows")}: ${logs.request_logs?.rows_label || "0"} (${getAdminLogRiskLabel(logs.request_logs?.risk)})`,
    `${adminTranslateWithFallback("security_anomaly_rows", "Security Anomaly Rows")}: ${logs.security_anomalies?.rows_label || "0"} (${getAdminLogRiskLabel(logs.security_anomalies?.risk)})`,
    `${adminTranslateWithFallback("rate_limit_usage_rows", "Rate-Limit Usage Rows")}: ${logs.rate_limit_usage?.rows_label || "0"} (${getAdminLogRiskLabel(logs.rate_limit_usage?.risk)})`,
  ].join("\n");
}

function formatAdminSqliteMaintenanceResult(result) {
  if (!result || typeof result !== "object") {
    return translate("success");
  }

  const before = result.before || {};
  const after = result.after || {};

  return [
    adminTranslateWithFallback("sqlite_maintenance_completed", "SQLite maintenance completed."),
    `${adminTranslateWithFallback("sqlite_before_size", "Before Size")}: ${before.size_label || "0 B"}`,
    `${adminTranslateWithFallback("sqlite_after_size", "After Size")}: ${after.size_label || "0 B"}`,
    `${adminTranslateWithFallback("sqlite_before_free_pages", "Before Free Pages")}: ${formatAdminNumber(before.freelist_count ?? 0)} (${before.free_size_label || "0 B"})`,
    `${adminTranslateWithFallback("sqlite_after_free_pages", "After Free Pages")}: ${formatAdminNumber(after.freelist_count ?? 0)} (${after.free_size_label || "0 B"})`,
    `${adminTranslateWithFallback("sqlite_page_count", "SQLite Pages")}: ${formatAdminNumber(before.page_count ?? 0)} -> ${formatAdminNumber(after.page_count ?? 0)}`,
    `${adminTranslateWithFallback("duration", "Duration")}: ${formatAdminNumber(result.duration_ms ?? 0)} ms`,
  ].join("\n");
}

function escapeAdminCsvCell(value) {
  const text = String(value ?? "");
  if (/[",\r\n]/.test(text)) {
    return `"${text.replace(/"/g, '""')}"`;
  }

  return text;
}

function downloadAdminCsvFile(fileName, rows) {
  const csvContent = "\uFEFF" + rows.map((row) => row.map(escapeAdminCsvCell).join(",")).join("\r\n");
  const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = fileName;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

function exportAdminSubscriptionImageAuditCsv() {
  const audit = latestAdminSubscriptionImageAudit;
  if (!audit || typeof audit !== "object") {
    showErrorMessage(adminTranslateWithFallback("subscription_image_audit_export_empty", "Run an image audit before exporting CSV."));
    return;
  }

  const rows = [
    ["section", "key", "value", "size_bytes", "size_label", "path"],
    ["summary", "indexed_rows", audit.indexed_rows ?? 0, "", "", ""],
    ["summary", "indexed_files", audit.indexed_files ?? 0, "", "", ""],
    ["summary", "disk_files", audit.disk_files ?? 0, "", "", ""],
    ["summary", "orphan_files", audit.orphan_files ?? 0, audit.orphan_bytes ?? 0, audit.orphan_size_label || "", ""],
    ["summary", "missing_variant_rows", audit.missing_variant_rows ?? 0, "", "", ""],
  ];

  if (Array.isArray(audit.orphan_details)) {
    audit.orphan_details.forEach((item) => {
      rows.push([
        "orphan_file",
        "path",
        "",
        item?.size_bytes ?? 0,
        item?.size_label || "",
        item?.path || "",
      ]);
    });
  }

  downloadAdminCsvFile(`wallos-subscription-image-audit-${Date.now()}.csv`, rows);
  showSuccessMessage(adminTranslateWithFallback("subscription_image_audit_exported", "Subscription image audit CSV exported."));
}

function initializeAdminMaintenanceStorageSummary() {
  const container = document.getElementById("adminMaintenanceStorageSummary");
  if (!container?.dataset.storageSummary) {
    return;
  }

  try {
    renderAdminMaintenanceStorageSummary(JSON.parse(container.dataset.storageSummary));
  } catch (error) {
    console.warn("Unable to render maintenance storage summary:", error);
  }
}

function testSmtpSettingsButton() {
  const button = document.getElementById("testSmtpSettingsButton");
  button.disabled = true;

  const smtpAddress = document.getElementById("smtpaddress").value;
  const smtpPort = document.getElementById("smtpport").value;
  const encryption = document.querySelector('input[name="encryption"]:checked').value;
  const smtpUsername = document.getElementById("smtpusername").value;
  const smtpPassword = document.getElementById("smtppassword").value;
  const fromEmail = document.getElementById("fromemail").value;

  const data = {
    smtpaddress: smtpAddress,
    smtpport: smtpPort,
    encryption: encryption,
    smtpusername: smtpUsername,
    smtppassword: smtpPassword,
    fromemail: fromEmail
  };

  makeFetchCall('endpoints/notifications/testemailnotifications.php', data, button);
}

function saveSmtpSettingsButton() {
  const button = document.getElementById("saveSmtpSettingsButton");
  button.disabled = true;

  const smtpAddress = document.getElementById("smtpaddress").value;
  const smtpPort = document.getElementById("smtpport").value;
  const encryption = document.querySelector('input[name="encryption"]:checked').value;
  const smtpUsername = document.getElementById("smtpusername").value;
  const smtpPassword = document.getElementById("smtppassword").value;
  const fromEmail = document.getElementById("fromemail").value;

  const data = {
    smtpaddress: smtpAddress,
    smtpport: smtpPort,
    encryption: encryption,
    smtpusername: smtpUsername,
    smtppassword: smtpPassword,
    fromemail: fromEmail
  };

  window.WallosApi.postJson('endpoints/admin/savesmtpsettings.php', data)
    .then(data => {
      if (data.success) {
        const emailVerificationCheckbox = document.getElementById('requireEmail');
        emailVerificationCheckbox.disabled = false;
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
      button.disabled = false;
    })
    .catch((error) => {
      showErrorMessage(window.WallosApi.getErrorMessage(error, translate("error")));
      button.disabled = false;
    });

}

function backupDB() {
  window.WallosAdminBackups?.backupDB?.();
}

function runPostRestoreActions() {
  window.WallosAdminBackups?.runPostRestoreActions?.();
}

function updateBackupCardStatus(button, statusLabel, statusTone = "pending") {
  window.WallosAdminBackups?.updateBackupCardStatus?.(button, statusLabel, statusTone);
}

function verifyBackup(backupName, button) {
  window.WallosAdminBackups?.verifyBackup?.(backupName, button);
}

function restoreBackup(backupName, button) {
  window.WallosAdminBackups?.restoreBackup?.(backupName, button);
}

function openRestoreDBFileSelect() {
  window.WallosAdminBackups?.openRestoreDBFileSelect?.();
}

function restoreDB() {
  window.WallosAdminBackups?.restoreDB?.();
}
function saveAccountRegistrationsButton() {
  window.WallosAdminRegistration?.saveAccountRegistrationsButton?.();
}

function saveSecuritySettingsButton() {
  const button = document.getElementById('saveSecuritySettingsButton');
  button.disabled = true;

  const allowlist = document.getElementById('local_webhook_notifications_allowlist').value;
  const loginRateLimitMaxAttempts = document.getElementById('login_rate_limit_max_attempts').value;
  const advancedRateLimitEnabled = document.getElementById('advancedRateLimitEnabled').checked ? 1 : 0;

  const data = {
    local_webhook_notifications_allowlist: allowlist,
    login_rate_limit_max_attempts: loginRateLimitMaxAttempts,
    login_rate_limit_block_minutes: document.getElementById('login_rate_limit_block_minutes').value,
    advanced_rate_limit_enabled: advancedRateLimitEnabled,
    backend_request_limit_per_minute: document.getElementById('backend_request_limit_per_minute').value,
    backend_request_limit_per_hour: document.getElementById('backend_request_limit_per_hour').value,
    image_upload_limit_per_minute: document.getElementById('image_upload_limit_per_minute').value,
    image_upload_limit_per_hour: document.getElementById('image_upload_limit_per_hour').value,
    image_upload_mb_per_minute: document.getElementById('image_upload_mb_per_minute').value,
    image_upload_mb_per_hour: document.getElementById('image_upload_mb_per_hour').value,
    image_download_limit_per_minute: document.getElementById('image_download_limit_per_minute').value,
    image_download_limit_per_hour: document.getElementById('image_download_limit_per_hour').value,
    image_download_mb_per_minute: document.getElementById('image_download_mb_per_minute').value,
    image_download_mb_per_hour: document.getElementById('image_download_mb_per_hour').value,
  };

  adminPostJson('endpoints/admin/savesecuritysettings.php', data)
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
    })
    .catch(error => {
      showErrorMessage(getAdminRequestErrorMessage(error));
    })
    .finally(() => {
      button.disabled = false;
    });
}

function getRateLimitPresetUi() {
  return document.getElementById('admin-rate-limit-preset-ui');
}

function getCurrentRateLimitConfig() {
  return {
    advanced_rate_limit_enabled: document.getElementById('advancedRateLimitEnabled').checked ? 1 : 0,
    login_rate_limit_max_attempts: document.getElementById('login_rate_limit_max_attempts').value,
    login_rate_limit_block_minutes: document.getElementById('login_rate_limit_block_minutes').value,
    backend_request_limit_per_minute: document.getElementById('backend_request_limit_per_minute').value,
    backend_request_limit_per_hour: document.getElementById('backend_request_limit_per_hour').value,
    image_upload_limit_per_minute: document.getElementById('image_upload_limit_per_minute').value,
    image_upload_limit_per_hour: document.getElementById('image_upload_limit_per_hour').value,
    image_upload_mb_per_minute: document.getElementById('image_upload_mb_per_minute').value,
    image_upload_mb_per_hour: document.getElementById('image_upload_mb_per_hour').value,
    image_download_limit_per_minute: document.getElementById('image_download_limit_per_minute').value,
    image_download_limit_per_hour: document.getElementById('image_download_limit_per_hour').value,
    image_download_mb_per_minute: document.getElementById('image_download_mb_per_minute').value,
    image_download_mb_per_hour: document.getElementById('image_download_mb_per_hour').value,
  };
}

function applyRateLimitConfigToForm(config) {
  if (!config || typeof config !== 'object') {
    return;
  }

  const mappings = {
    advancedRateLimitEnabled: 'advanced_rate_limit_enabled',
    login_rate_limit_max_attempts: 'login_rate_limit_max_attempts',
    login_rate_limit_block_minutes: 'login_rate_limit_block_minutes',
    backend_request_limit_per_minute: 'backend_request_limit_per_minute',
    backend_request_limit_per_hour: 'backend_request_limit_per_hour',
    image_upload_limit_per_minute: 'image_upload_limit_per_minute',
    image_upload_limit_per_hour: 'image_upload_limit_per_hour',
    image_upload_mb_per_minute: 'image_upload_mb_per_minute',
    image_upload_mb_per_hour: 'image_upload_mb_per_hour',
    image_download_limit_per_minute: 'image_download_limit_per_minute',
    image_download_limit_per_hour: 'image_download_limit_per_hour',
    image_download_mb_per_minute: 'image_download_mb_per_minute',
    image_download_mb_per_hour: 'image_download_mb_per_hour',
  };

  Object.entries(mappings).forEach(([elementId, configKey]) => {
    const element = document.getElementById(elementId);
    if (!element || !(configKey in config)) {
      return;
    }

    if (element.type === 'checkbox') {
      element.checked = Number(config[configKey]) === 1;
    } else {
      element.value = config[configKey];
    }
  });
}

function getRateLimitPresetsMap() {
  const ui = getRateLimitPresetUi();
  if (!ui) {
    return new Map();
  }

  let presets = [];
  try {
    presets = JSON.parse(ui.dataset.presets || '[]');
  } catch (error) {
    presets = [];
  }

  return new Map((presets || []).map((preset) => [String(preset.id), preset]));
}

function getSelectedRateLimitPreset() {
  const select = document.getElementById('rateLimitPresetSelect');
  const presets = getRateLimitPresetsMap();
  if (!select || !select.value || !presets.has(select.value)) {
    return null;
  }

  return presets.get(select.value);
}

function applyRateLimitPresetButton() {
  const ui = getRateLimitPresetUi();
  const preset = getSelectedRateLimitPreset();
  if (!preset) {
    showErrorMessage(ui?.dataset.noSelection || translate('error'));
    return;
  }

  applyRateLimitConfigToForm(preset.config || {});
  showSuccessMessage(ui?.dataset.applyNotice || translate('success'));
}

function addRateLimitPresetButton() {
  const ui = getRateLimitPresetUi();
  const presetName = prompt(ui?.dataset.namePrompt || 'Preset name');
  if (presetName === null) {
    return;
  }

  const normalizedName = String(presetName).trim();
  if (!normalizedName) {
    showErrorMessage(translate('error'));
    return;
  }

  adminPostJson('endpoints/admin/ratelimitpresets.php', {
      action: 'create',
      name: normalizedName,
      config: getCurrentRateLimitConfig(),
    })
    .then((data) => {
      if (!data.success) {
        throw new Error(data.message || translate('error'));
      }

      showSuccessMessage(data.message);
      window.location.reload();
    })
    .catch((error) => showErrorMessage(getAdminRequestErrorMessage(error)));
}

function saveRateLimitPresetButton() {
  const ui = getRateLimitPresetUi();
  const preset = getSelectedRateLimitPreset();
  if (!preset) {
    showErrorMessage(ui?.dataset.noSelection || translate('error'));
    return;
  }

  adminPostJson('endpoints/admin/ratelimitpresets.php', {
      action: 'update',
      preset_id: preset.id,
      config: getCurrentRateLimitConfig(),
    })
    .then((data) => {
      if (!data.success) {
        throw new Error(data.message || translate('error'));
      }

      showSuccessMessage(data.message);
      window.location.reload();
    })
    .catch((error) => showErrorMessage(getAdminRequestErrorMessage(error)));
}

function deleteRateLimitPresetButton() {
  window.WallosAdminRateLimit?.deleteRateLimitPresetButton?.();
}

function removeSecurityAnomaliesModal() { window.WallosAdminAccessLogs?.removeSecurityAnomaliesModal?.(); }
function renderSecurityAnomalyEntries(items, resultContainer, ui) { window.WallosAdminAccessLogs?.renderSecurityAnomalyEntries?.(items, resultContainer, ui); }
function fetchSecurityAnomalies(filters, resultSummary, resultContainer, searchButton, ui) { window.WallosAdminAccessLogs?.fetchSecurityAnomalies?.(filters, resultSummary, resultContainer, searchButton, ui); }
function openSecurityAnomaliesModal() { window.WallosAdminAccessLogs?.openSecurityAnomaliesModal?.(); }
function removeAccessLogsModal() { window.WallosAdminAccessLogs?.removeAccessLogsModal?.(); }
function renderAccessLogEntries(logs, resultContainer, ui) { window.WallosAdminAccessLogs?.renderAccessLogEntries?.(logs, resultContainer, ui); }
function exportAdminAccessLogs(logs, filters, ui) { window.WallosAdminAccessLogs?.exportAdminAccessLogs?.(logs, filters, ui); }
function fetchAdminAccessLogs(filters, resultSummary, resultContainer, searchButton, ui) { window.WallosAdminAccessLogs?.fetchAdminAccessLogs?.(filters, resultSummary, resultContainer, searchButton, ui); }
function openAccessLogsModal() { window.WallosAdminAccessLogs?.openAccessLogsModal?.(); }
function removeUser(userId) {
  window.WallosAdminUsers?.removeUser?.(userId);
}

function resetUserPassword(userId, button) {
  window.WallosAdminUsers?.resetUserPassword?.(userId, button);
}

function removeGeneratedPasswordModal() {
  window.WallosAdminUsers?.removeGeneratedPasswordModal?.();
}

function copyTextToClipboard(text, input = null) {
  window.WallosAdminUsers?.copyTextToClipboard?.(text, input);
}

function copyGeneratedPassword(password, input) {
  window.WallosAdminUsers?.copyGeneratedPassword?.(password, input);
}

function copyUserId(userId, button) {
  window.WallosAdminUsers?.copyUserId?.(userId, button);
}

function showGeneratedPasswordModal(username, temporaryPassword) {
  window.WallosAdminUsers?.showGeneratedPasswordModal?.(username, temporaryPassword);
}
function toggleAdminSection(button) {
  window.WallosAdminUsers?.toggleAdminSection?.(button);
}

function switchAdminTab(group, tabId, button) {
  window.WallosAdminUsers?.switchAdminTab?.(group, tabId, button);
}

function restoreUser(userId) {
  window.WallosAdminUsers?.restoreUser?.(userId);
}

function permanentlyDeleteUser(userId) {
  window.WallosAdminUsers?.permanentlyDeleteUser?.(userId);
}

function updateUserGroup(userId, selectElement) {
  window.WallosAdminUsers?.updateUserGroup?.(userId, selectElement);
}

function addUserButton() {
  window.WallosAdminUsers?.addUserButton?.();
}

function saveSubscriptionImageSettingsButton() {
  const button = document.getElementById('saveSubscriptionImageSettingsButton');
  button.disabled = true;

  const data = {
    subscription_image_external_url_limit: document.getElementById('subscriptionImageExternalUrlLimit').value,
    trusted_subscription_upload_limit: document.getElementById('trustedSubscriptionUploadLimit').value,
    subscription_image_max_size_mb: document.getElementById('subscriptionImageMaxSizeMb').value,
  };

  adminPostJson('endpoints/admin/saveimagesettings.php', data)
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message || translate('error'));
      }
    })
    .catch((error) => showErrorMessage(getAdminRequestErrorMessage(error)))
    .finally(() => {
      button.disabled = false;
    });
}

function saveBackupSettingsButton() {
  window.WallosAdminBackups?.saveBackupSettingsButton?.();
}

function cleanupOldBackupsButton(button) {
  window.WallosAdminBackups?.cleanupOldBackupsButton?.(button);
}

function generateInviteCode() {
  window.WallosAdminRegistration?.generateInviteCode?.();
}

function deleteInviteCode(inviteCodeId) {
  window.WallosAdminRegistration?.deleteInviteCode?.(inviteCodeId);
}

function permanentlyDeleteInviteCode(inviteCodeId, button) {
  window.WallosAdminRegistration?.permanentlyDeleteInviteCode?.(inviteCodeId, button);
}

function updateScheduledDeleteAt(userId, button) {
  window.WallosAdminUsers?.updateScheduledDeleteAt?.(userId, button);
}

function deleteUnusedLogos() {
  const button = document.getElementById('deleteUnusedLogos');
  button.disabled = true;

  adminPostJson('endpoints/admin/deleteunusedlogos.php', {})
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        const numberOfLogos = document.querySelector('.number-of-logos');
        numberOfLogos.innerText = '0';
      } else {
        showErrorMessage(data.message);
        button.disabled = false;
      }
    })
    .catch(error => {
      showErrorMessage(getAdminRequestErrorMessage(error));
    })
    .finally(() => {
      button.disabled = false;
    });
}

function formatAdminMaintenanceAudit(audit) {
  if (!audit || typeof audit !== "object") {
    return translate("success");
  }

  const lines = [
    `${adminTranslateWithFallback("subscription_image_indexed_rows", "Indexed Image Rows")}: ${audit.indexed_rows ?? 0}`,
    `${adminTranslateWithFallback("subscription_image_indexed_files", "Indexed Image Files")}: ${audit.indexed_files ?? 0}`,
    `${adminTranslateWithFallback("subscription_image_disk_files", "Files On Disk")}: ${audit.disk_files ?? 0}`,
    `${adminTranslateWithFallback("subscription_image_orphan_files", "Orphan Files")}: ${audit.orphan_files ?? 0}`,
    `${adminTranslateWithFallback("subscription_image_orphan_bytes", "Orphan File Size")}: ${audit.orphan_size_label || "0 B"}`,
    `${adminTranslateWithFallback("subscription_image_missing_variants", "Rows Missing Variants")}: ${audit.missing_variant_rows ?? 0}`,
  ];

  if (Array.isArray(audit.orphan_samples) && audit.orphan_samples.length > 0) {
    lines.push("");
    lines.push("Samples:");
    audit.orphan_samples.forEach((item) => lines.push(`- ${item}`));
  }

  return lines.join("\n");
}

function formatAdminOversizedVariantResult(result) {
  if (!result || typeof result !== "object") {
    return translate("success");
  }

  return [
    `${adminTranslateWithFallback("subscription_image_checked_rows", "Checked Image Rows")}: ${result.checked_rows ?? 0}`,
    `${adminTranslateWithFallback("subscription_image_updated_rows", "Updated Image Rows")}: ${result.updated_rows ?? 0}`,
    `${adminTranslateWithFallback("subscription_image_reused_variants", "Reused Variants")}: ${result.reused_variants ?? 0}`,
    `${adminTranslateWithFallback("subscription_image_deleted_oversized_files", "Deleted Oversized Files")}: ${result.deleted_files ?? 0}`,
    `${adminTranslateWithFallback("subscription_image_missing_originals", "Missing Originals")}: ${result.missing_originals ?? 0}`,
  ].join("\n");
}

function runAdminMaintenanceAction(action, button) {
  const resultTextArea = document.getElementById('adminMaintenanceResult');
  if (button?.dataset.confirmMessage && !confirm(button.dataset.confirmMessage)) {
    return;
  }

  if (button) {
    button.disabled = true;
  }
  if (resultTextArea) {
    resultTextArea.value = adminTranslateWithFallback("maintenance_running", "Maintenance task is running...");
  }

  adminPostJson('endpoints/admin/systemmaintenance.php', { action })
    .then((data) => {
      if (!data.success) {
        throw new Error(data.message || translate("error"));
      }

      showSuccessMessage(data.message || translate("success"));
      if (resultTextArea) {
        if (data.storage) {
          renderAdminMaintenanceStorageSummary(data.storage);
          resultTextArea.value = formatAdminStorageSummary(data.storage);
        } else if (data.audit) {
          latestAdminSubscriptionImageAudit = data.audit;
          resultTextArea.value = formatAdminMaintenanceAudit(data.audit);
        } else if (data.oversized_variant_result) {
          resultTextArea.value = formatAdminOversizedVariantResult(data.oversized_variant_result);
        } else if (data.result) {
          resultTextArea.value = formatAdminSqliteMaintenanceResult(data.result);
        } else {
          resultTextArea.value = data.message || translate("success");
        }
      }
    })
    .catch((error) => {
      const message = getAdminRequestErrorMessage(error);
      showErrorMessage(message);
      if (resultTextArea) {
        resultTextArea.value = message;
      }
    })
    .finally(() => {
      if (button) {
        button.disabled = false;
      }
    });
}

function toggleUpdateNotification() {
  const notificationEnabledCheckbox = document.getElementById('updateNotification');
  const notificationEnabled = notificationEnabledCheckbox.checked ? 1 : 0;

  const data = {
    notificationEnabled: notificationEnabled
  };

  adminPostJson('endpoints/admin/updatenotification.php', data)
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        if (notificationEnabled === 1) {
          adminGetText('endpoints/cronjobs/checkforupdates.php').catch(() => {
            // Keep this trigger fire-and-forget.
          });
        }
      } else {
        showErrorMessage(data.message);
      }
    })
    .catch(error => showErrorMessage(getAdminRequestErrorMessage(error)));

}

function executeCronJob(job) {
  const url = `endpoints/cronjobs/${job}.php`;
  const resultTextArea = document.getElementById('cronjobResult');

  adminGetText(url)
    .then(data => {
      const formattedData = data.replace(/<br\s*\/?>/gi, '\n');
      resultTextArea.value = formattedData;
    })
    .catch(error => {
      console.error('Fetch error:', error);
      showErrorMessage(getAdminRequestErrorMessage(error));
    });
}

function toggleOidcEnabled() {
  const toggle = document.getElementById("oidcEnabled");
  toggle.disabled = true;

  const oidcEnabled = toggle.checked ? 1 : 0;

  const data = {
    oidcEnabled: oidcEnabled
  };

  adminPostJson('endpoints/admin/enableoidc.php', data)
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
      toggle.disabled = false;
    })
    .catch(error => {
      showErrorMessage(getAdminRequestErrorMessage(error));
      toggle.disabled = false;
    });

}

function saveOidcSettingsButton() {
  const button = document.getElementById("saveOidcSettingsButton");
  button.disabled = true;

  const oidcName = document.getElementById("oidcName").value;
  const oidcClientId = document.getElementById("oidcClientId").value;
  const oidcClientSecret = document.getElementById("oidcClientSecret").value;
  const oidcAuthUrl = document.getElementById("oidcAuthUrl").value;
  const oidcTokenUrl = document.getElementById("oidcTokenUrl").value;
  const oidcUserInfoUrl = document.getElementById("oidcUserInfoUrl").value;
  const oidcRedirectUrl = document.getElementById("oidcRedirectUrl").value;
  const oidcLogoutUrl = document.getElementById("oidcLogoutUrl").value;
  const oidcUserIdentifierField = document.getElementById("oidcUserIdentifierField").value;
  const oidcScopes = document.getElementById("oidcScopes").value;
  const oidcAuthStyle = document.getElementById("oidcAuthStyle").value;
  const oidcAutoCreateUser = document.getElementById("oidcAutoCreateUser").checked ? 1 : 0;
  const oidcPasswordLoginDisabled = document.getElementById("oidcPasswordLoginDisabled").checked ? 1 : 0;

  const data = {
    oidcName: oidcName,
    oidcClientId: oidcClientId,
    oidcClientSecret: oidcClientSecret,
    oidcAuthUrl: oidcAuthUrl,
    oidcTokenUrl: oidcTokenUrl,
    oidcUserInfoUrl: oidcUserInfoUrl,
    oidcRedirectUrl: oidcRedirectUrl,
    oidcLogoutUrl: oidcLogoutUrl,
    oidcUserIdentifierField: oidcUserIdentifierField,
    oidcScopes: oidcScopes,
    oidcAuthStyle: oidcAuthStyle,
    oidcAutoCreateUser: oidcAutoCreateUser,
    oidcPasswordLoginDisabled: oidcPasswordLoginDisabled
  };


  adminPostJson('endpoints/admin/saveoidcsettings.php', data)
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
      button.disabled = false;
    })
    .catch(error => {
      showErrorMessage(getAdminRequestErrorMessage(error));
      button.disabled = false;
    });
}

function initializeAdminServiceWorkerStatus() {
  const ui = document.getElementById("admin-service-worker-ui");
  const registrationNode = document.getElementById("admin-sw-registration-state");
  const controllerNode = document.getElementById("admin-sw-controller-state");

  if (!ui || !registrationNode || !controllerNode) {
    return;
  }

  if (!("serviceWorker" in navigator)) {
    registrationNode.textContent = ui.dataset.notSupported || "Not supported";
    controllerNode.textContent = ui.dataset.notSupported || "Not supported";
    return;
  }

  const controller = navigator.serviceWorker.controller;
  controllerNode.textContent = controller
    ? (ui.dataset.controlled || "Controlled")
    : (ui.dataset.uncontrolled || "Uncontrolled");

  navigator.serviceWorker.getRegistration()
    .then((registration) => {
      if (!registration) {
        registrationNode.textContent = ui.dataset.noRegistration || "No registration";
        return;
      }

      if (registration.waiting) {
        registrationNode.textContent = ui.dataset.waiting || "Waiting";
        return;
      }

      if (registration.installing) {
        registrationNode.textContent = ui.dataset.installing || "Installing";
        return;
      }

      if (registration.active) {
        registrationNode.textContent = ui.dataset.active || "Active";
        return;
      }

      registrationNode.textContent = ui.dataset.noRegistration || "No registration";
    })
    .catch(() => {
      registrationNode.textContent = ui.dataset.noRegistration || "No registration";
    });
}

document.addEventListener("DOMContentLoaded", initializeAdminServiceWorkerStatus);

function clearClientCacheButton(button) {
  const ui = document.getElementById("admin-service-worker-ui");
  if (button) {
    button.disabled = true;
  }

  Promise.resolve(window.WallosClientCache?.clear?.())
    .then(() => {
      showSuccessMessage(ui?.dataset.cacheClearSuccess || translate("success"));
      initializeAdminServiceWorkerStatus();
    })
    .catch(() => showErrorMessage(ui?.dataset.cacheRefreshFailed || translate("error")))
    .finally(() => {
      if (button) {
        button.disabled = false;
      }
    });
}

function requestClientCacheRefreshButton(button) {
  const ui = document.getElementById("admin-service-worker-ui");
  if (button) {
    button.disabled = true;
  }

  adminPostJson('endpoints/admin/requestcacherefresh.php', {})
    .then((data) => {
      if (!data.success) {
        throw new Error(data.message || ui?.dataset.cacheRefreshFailed || translate("error"));
      }

      showSuccessMessage(data.message || ui?.dataset.cacheRefreshSuccess || translate("success"));
      return Promise.resolve(window.WallosClientCache?.clear?.());
    })
    .then(() => initializeAdminServiceWorkerStatus())
    .catch((error) => showErrorMessage(getAdminRequestErrorMessage(error)))
    .finally(() => {
      if (button) {
        button.disabled = false;
      }
    });
}

function createRuntimeAnomalyCard(item, ui) {
  const card = document.createElement("article");
  card.className = "runtime-anomaly-card";

  const header = document.createElement("div");
  header.className = "runtime-anomaly-card-header";

  const idBadge = document.createElement("span");
  idBadge.className = "access-log-id-badge";
  idBadge.textContent = `#${String(item?.id || "-")}`;

  const typeNode = document.createElement("strong");
  typeNode.textContent = String(item?.anomaly_type || "-");

  const codeNode = document.createElement("span");
  codeNode.textContent = String(item?.anomaly_code || "-");

  header.append(idBadge, typeNode, codeNode);

  const message = document.createElement("p");
  message.textContent = String(item?.message || "-");

  const meta = document.createElement("small");
  const user = String(item?.username || "-");
  const ip = String(item?.ip_address || "-");
  const path = String(item?.path || "");
  const time = String(item?.created_at_display || item?.created_at || "-");
  meta.textContent = path
    ? `${user} · ${ip} · ${ui?.dataset.pathLabel || "Path"}: ${path} · ${time}`
    : `${user} · ${ip} · ${time}`;

  card.append(header, message, meta);
  return card;
}

function renderRuntimeObservabilityFeed(items) {
  const ui = document.getElementById("admin-runtime-observability-ui");
  const feed = document.querySelector("[data-observability-feed]");
  if (!feed) {
    return;
  }

  feed.innerHTML = "";
  if (!Array.isArray(items) || items.length === 0) {
    const emptyState = document.createElement("div");
    emptyState.className = "settings-notes access-log-empty";
    const paragraph = document.createElement("p");
    const icon = document.createElement("i");
    icon.className = "fa-solid fa-circle-info";
    paragraph.append(icon, document.createTextNode(ui?.dataset.emptyLabel || "No recent anomalies."));
    emptyState.appendChild(paragraph);
    feed.appendChild(emptyState);
    return;
  }

  items.forEach((item) => {
    feed.appendChild(createRuntimeAnomalyCard(item, ui));
  });
}

function updateRuntimeObservabilitySummary(data) {
  const counts = data?.counts || {};
  document.querySelectorAll("[data-observability-count]").forEach((node) => {
    const key = node.getAttribute("data-observability-count");
    if (key && Object.prototype.hasOwnProperty.call(counts, key)) {
      node.textContent = String(counts[key] || 0);
    }
  });

  const typeSummary = document.querySelector("[data-observability-type-summary]");
  if (typeSummary) {
    typeSummary.textContent = data?.type_summary || "-";
  }

  const cacheRefresh = document.querySelector("[data-observability-cache-refresh]");
  if (cacheRefresh) {
    const ui = document.getElementById("admin-runtime-observability-ui");
    cacheRefresh.textContent = data?.cache_refresh?.token_short
      ? (data?.cache_refresh?.requested_at_display || "-")
      : (ui?.dataset.cacheEmptyLabel || "-");
  }

  renderRuntimeObservabilityFeed(data?.recent_anomalies || []);
}

function refreshRuntimeObservabilityButton(button) {
  const ui = document.getElementById("admin-runtime-observability-ui");
  if (button) {
    button.disabled = true;
  }

  window.WallosApi.postJson("endpoints/admin/runtimeobservability.php", {}, {
    fallbackErrorMessage: ui?.dataset.refreshFailed || translate("error"),
  })
    .then((data) => {
      if (!data.success) {
        throw new Error(data.message || ui?.dataset.refreshFailed || translate("error"));
      }

      updateRuntimeObservabilitySummary(data);
      showSuccessMessage(ui?.dataset.refreshSuccess || translate("success"));
      initializeAdminServiceWorkerStatus();
    })
    .catch((error) => showErrorMessage(window.WallosApi?.normalizeError?.(error, ui?.dataset.refreshFailed || translate("error")) || translate("error")))
    .finally(() => {
      if (button) {
        button.disabled = false;
      }
    });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initializeAdminMaintenanceStorageSummary);
} else {
  initializeAdminMaintenanceStorageSummary();
}
