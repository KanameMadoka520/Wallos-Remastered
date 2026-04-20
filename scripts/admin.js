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
          fetch('endpoints/cronjobs/checkforupdates.php');
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

  fetch(url)
    .then(response => {
      return response.text();
    })
    .then(data => {
      const formattedData = data.replace(/<br\s*\/?>/gi, '\n');
      resultTextArea.value = formattedData;
    })
    .catch(error => {
      console.error('Fetch error:', error);
      showErrorMessage('Error:', error);
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

