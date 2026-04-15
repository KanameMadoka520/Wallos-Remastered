function makeFetchCall(url, data, button) {
  return fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data),
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
      button.disabled = false;
    })
    .catch((error) => {
      showErrorMessage(error);
      button.disabled = false;
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

  fetch('endpoints/admin/savesmtpsettings.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data),
  })
    .then(response => response.json())
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
      showErrorMessage(error);
      button.disabled = false;
    });

}

function backupDB() {
  const button = document.getElementById("backupDB");
  button.disabled = true;
  const operationId = `manual-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
  let backupProgressPollTimer = null;

  const stopPolling = () => {
    if (backupProgressPollTimer) {
      clearTimeout(backupProgressPollTimer);
      backupProgressPollTimer = null;
    }
  };

  const renderBackupProgress = (status) => {
    const card = document.getElementById("backupProgressCard");
    const percent = document.getElementById("backupProgressPercent");
    const bar = document.getElementById("backupProgressBar");
    const message = document.getElementById("backupProgressMessage");
    const tone = document.getElementById("backupProgressTone");

    if (!card || !percent || !bar || !message || !tone) {
      return;
    }

    const progress = Math.max(0, Math.min(100, Number(status?.progress || 0)));
    const state = String(status?.state || "running");
    const toneValue = String(status?.tone || (state === "completed" ? "success" : state === "failed" ? "error" : "pending"));
    const statusMessage = String(status?.message || card.dataset.idleMessage || "");
    const backupLabel = card.dataset.backupLabel || "Backup";

    card.classList.remove("is-hidden", "is-pending", "is-success", "is-error");
    card.classList.add(toneValue === "success" ? "is-success" : toneValue === "error" ? "is-error" : "is-pending");
    percent.textContent = `${Math.round(progress)}%`;
    bar.style.width = `${progress}%`;
    message.textContent = statusMessage;
    tone.textContent = state === "completed" ? translate("success") : state === "failed" ? translate("error") : backupLabel;
  };

  const pollBackupProgress = () => {
    fetch("endpoints/admin/backupstatus.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": window.csrfToken,
      },
      body: JSON.stringify({ operationId }),
    })
      .then(response => response.json())
      .then(data => {
        if (data.success && data.status) {
          renderBackupProgress(data.status);
          if (data.status.state === "completed" || data.status.state === "failed") {
            stopPolling();
            return;
          }
        }

        backupProgressPollTimer = setTimeout(pollBackupProgress, 700);
      })
      .catch(() => {
        backupProgressPollTimer = setTimeout(pollBackupProgress, 1200);
      });
  };

  renderBackupProgress({
    state: "running",
    tone: "pending",
    progress: 1,
    message: document.getElementById("backupProgressCard")?.dataset.startingMessage || translate("backup"),
  });
  pollBackupProgress();

  fetch("endpoints/admin/createbackup.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": window.csrfToken,
    },
    body: JSON.stringify({ operationId }),
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        renderBackupProgress({
          state: "completed",
          tone: "success",
          progress: 100,
          message: data.message,
        });
        showSuccessMessage(data.message);
        const link = document.createElement("a");
        link.href = data.downloadUrl;
        link.rel = "noreferrer";
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        setTimeout(() => window.location.reload(), 900);
      } else {
        renderBackupProgress({
          state: "failed",
          tone: "error",
          progress: 100,
          message: data.message || translate("backup_failed"),
        });
        showErrorMessage(data.message || translate("backup_failed"));
      }
    })
    .catch(error => {
      console.error(error);
      renderBackupProgress({
        state: "failed",
        tone: "error",
        progress: 100,
        message: translate("backup_failed"),
      });
      showErrorMessage(translate("unknown_error"));
    })
    .finally(() => {
      stopPolling();
      button.disabled = false;
    });
}

function runPostRestoreActions() {
  fetch('endpoints/db/migrate.php')
    .then(() => {
      window.location.href = 'logout.php';
    })
    .catch(() => {
      window.location.href = 'logout.php';
    });
}

function updateBackupCardStatus(button, statusLabel, statusTone = "pending") {
  const card = button?.closest('.backup-card');
  const status = card?.querySelector('[data-backup-status]');
  if (!status) {
    return;
  }

  status.textContent = statusLabel;
  status.classList.remove('is-pending', 'is-success', 'is-warning', 'is-error');

  if (statusTone === 'success') {
    status.classList.add('is-success');
  } else if (statusTone === 'warning') {
    status.classList.add('is-warning');
  } else if (statusTone === 'error') {
    status.classList.add('is-error');
  } else {
    status.classList.add('is-pending');
  }
}

function verifyBackup(backupName, button) {
  if (!backupName || !button) {
    showErrorMessage(translate('error'));
    return;
  }

  button.disabled = true;

  fetch('endpoints/admin/verifybackup.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ name: backupName })
  })
    .then(response => response.json())
    .then(data => {
      const verification = data.verification || {};
      if (verification.statusLabel) {
        updateBackupCardStatus(button, verification.statusLabel, verification.statusTone || 'pending');
      }

      if (data.success) {
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message || translate('error'));
      }
    })
    .catch(() => {
      updateBackupCardStatus(button, translate('backup_verification_status_failed'), 'error');
      showErrorMessage(translate('error'));
    })
    .finally(() => {
      button.disabled = false;
    });
}

function restoreBackup(backupName, button) {
  const confirmMessage = button?.dataset.confirmMessage || 'Restore from this backup now?';
  const confirmSecondMessage = button?.dataset.confirmSecondMessage || 'Please confirm again.';

  if (!confirm(confirmMessage)) {
    return;
  }

  if (!confirm(confirmSecondMessage)) {
    return;
  }

  button.disabled = true;

  fetch('endpoints/admin/restorebackup.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ name: backupName })
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        runPostRestoreActions();
      } else {
        showErrorMessage(data.message || translate('restore_failed'));
      }
    })
    .catch(() => showErrorMessage(translate('restore_failed')))
    .finally(() => {
      button.disabled = false;
    });
}


function openRestoreDBFileSelect() {
  document.getElementById('restoreDBFile').click();
};

function restoreDB() {
  const input = document.getElementById('restoreDBFile');
  const file = input.files[0];

  if (!file) {
    showErrorMessage(translate('no_file_selected'));
    return;
  }

  const formData = new FormData();
  formData.append('file', file);

  const button = document.getElementById('restoreDB');
  button.disabled = true;

  fetch('endpoints/db/restore.php', {
    method: 'POST',
    headers: {
      'X-CSRF-Token': window.csrfToken, // ✅ CSRF protection
    },
    body: formData,
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        runPostRestoreActions();
      } else {
        showErrorMessage(data.message || translate('restore_failed'));
      }
    })
    .catch(error => {
      console.error(error);
      showErrorMessage(translate('unknown_error'));
    })
    .finally(() => {
      button.disabled = false;
    });
}

function saveAccountRegistrationsButton() {
  const button = document.getElementById('saveAccountRegistrations');
  button.disabled = true;

  const open_registrations = document.getElementById('registrations').checked ? 1 : 0;
  const invite_only_registration = document.getElementById('inviteOnlyRegistration').checked ? 1 : 0;
  const max_users = document.getElementById('maxUsers').value;
  const require_email_validation = document.getElementById('requireEmail').checked ? 1 : 0;
  const server_url = document.getElementById('serverUrl').value;
  const disable_login = document.getElementById('disableLogin').checked ? 1 : 0;
  const custom_edition_title = document.getElementById('customEditionTitle').value;
  const custom_edition_subtitle = document.getElementById('customEditionSubtitle').value;

  const data = {
    open_registrations: open_registrations,
    invite_only_registration: invite_only_registration,
    max_users: max_users,
    require_email_validation: require_email_validation,
    server_url: server_url,
    disable_login: disable_login,
    custom_edition_title: custom_edition_title,
    custom_edition_subtitle: custom_edition_subtitle
  };

  fetch('endpoints/admin/saveopenregistrations.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data)
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        button.disabled = false;
      } else {
        showErrorMessage(data.message);
        button.disabled = false;
      }
    })
    .catch(error => {
      showErrorMessage(error);
      button.disabled = false;
    });
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

  fetch('endpoints/admin/savesecuritysettings.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data)
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        button.disabled = false;
      } else {
        showErrorMessage(data.message);
        button.disabled = false;
      }
    })
    .catch(error => {
      showErrorMessage(error);
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

  fetch('endpoints/admin/ratelimitpresets.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({
      action: 'create',
      name: normalizedName,
      config: getCurrentRateLimitConfig(),
    }),
  })
    .then(response => response.json())
    .then((data) => {
      if (!data.success) {
        throw new Error(data.message || translate('error'));
      }

      showSuccessMessage(data.message);
      window.location.reload();
    })
    .catch((error) => showErrorMessage(error.message || translate('error')));
}

function saveRateLimitPresetButton() {
  const ui = getRateLimitPresetUi();
  const preset = getSelectedRateLimitPreset();
  if (!preset) {
    showErrorMessage(ui?.dataset.noSelection || translate('error'));
    return;
  }

  fetch('endpoints/admin/ratelimitpresets.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({
      action: 'update',
      preset_id: preset.id,
      config: getCurrentRateLimitConfig(),
    }),
  })
    .then(response => response.json())
    .then((data) => {
      if (!data.success) {
        throw new Error(data.message || translate('error'));
      }

      showSuccessMessage(data.message);
      window.location.reload();
    })
    .catch((error) => showErrorMessage(error.message || translate('error')));
}

function deleteRateLimitPresetButton() {
  const ui = getRateLimitPresetUi();
  const preset = getSelectedRateLimitPreset();
  if (!preset) {
    showErrorMessage(ui?.dataset.noSelection || translate('error'));
    return;
  }

  if (!confirm(ui?.dataset.deleteConfirm || 'Delete this preset?')) {
    return;
  }

  fetch('endpoints/admin/ratelimitpresets.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({
      action: 'delete',
      preset_id: preset.id,
    }),
  })
    .then(response => response.json())
    .then((data) => {
      if (!data.success) {
        throw new Error(data.message || translate('error'));
      }

      showSuccessMessage(data.message);
      window.location.reload();
    })
    .catch((error) => showErrorMessage(error.message || translate('error')));
}

function removeSecurityAnomaliesModal() {
  const existingModal = document.getElementById('admin-security-anomaly-backdrop');
  if (existingModal) {
    existingModal.remove();
  }
}

function renderSecurityAnomalyEntries(items, resultContainer, ui) {
  resultContainer.innerHTML = '';

  if (!Array.isArray(items) || items.length === 0) {
    const emptyState = document.createElement('div');
    emptyState.className = 'settings-notes access-log-empty';
    emptyState.innerHTML = `<p><i class="fa-solid fa-circle-info"></i>${ui.dataset.emptyLabel || 'No anomaly records yet.'}</p>`;
    resultContainer.appendChild(emptyState);
    return;
  }

  const list = document.createElement('div');
  list.className = 'access-log-list compact';

  items.forEach((item) => {
    const card = document.createElement('div');
    card.className = 'access-log-card';

    const header = document.createElement('div');
    header.className = 'access-log-header';
    header.innerHTML = `
      <div class="access-log-card-title">
        <span class="access-log-id-badge">#${String(item.id || '-')}</span>
        <strong>${String(item.anomaly_type || '-')}</strong>
      </div>
      <span>${String(item.anomaly_code || '-')}</span>
    `;

    card.appendChild(header);
    card.innerHTML += `
      <p>${ui.dataset.messageLabel}: ${String(item.message || '-')}</p>
      <p>${ui.dataset.userLabel}: ${String(item.username || '-')}</p>
      <p>${ui.dataset.ipLabel}: ${String(item.ip_address || '-')}</p>
      <p>${ui.dataset.forwardedLabel}: ${String(item.forwarded_for || '-')}</p>
      <p>${ui.dataset.agentLabel}: ${String(item.user_agent || '-')}</p>
      <p>${ui.dataset.timeLabel}: ${String(item.created_at || '-')}</p>
    `;

    const headersJson = String(item.headers_json || '').trim();
    if (headersJson !== '') {
      const details = document.createElement('details');
      const summary = document.createElement('summary');
      summary.textContent = ui.dataset.headersLabel;
      const pre = document.createElement('pre');
      pre.textContent = headersJson;
      details.appendChild(summary);
      details.appendChild(pre);
      card.appendChild(details);
    }

    const detailsJson = String(item.details_json || '').trim();
    if (detailsJson !== '') {
      const details = document.createElement('details');
      const summary = document.createElement('summary');
      summary.textContent = 'Details';
      const pre = document.createElement('pre');
      pre.textContent = detailsJson;
      details.appendChild(summary);
      details.appendChild(pre);
      card.appendChild(details);
    }

    list.appendChild(card);
  });

  resultContainer.appendChild(list);
}

function fetchSecurityAnomalies(filters, resultSummary, resultContainer, searchButton, ui) {
  searchButton.disabled = true;
  resultSummary.textContent = ui.dataset.searchLabel || 'Loading...';

  fetch('endpoints/admin/securityanomalies.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(filters),
  })
    .then(response => response.json())
    .then(data => {
      if (!data.success) {
        throw new Error(data.message || (ui.dataset.errorLabel || 'Error'));
      }

      resultContainer.dataset.logs = JSON.stringify(data.items || []);
      resultContainer.dataset.filters = JSON.stringify(data.filters || filters);
      renderSecurityAnomalyEntries(data.items || [], resultContainer, ui);
      const itemCount = Array.isArray(data.items) ? data.items.length : 0;
      resultSummary.textContent = (ui.dataset.showingLabel || 'Showing %1$d of %2$d matching access logs')
        .replace('%1$d', String(itemCount))
        .replace('%2$d', String(data.total || 0));
    })
    .catch((error) => {
      resultSummary.textContent = ui.dataset.errorLabel || 'Error';
      showErrorMessage(error.message || ui.dataset.errorLabel || 'Error');
    })
    .finally(() => {
      searchButton.disabled = false;
    });
}

function openSecurityAnomaliesModal() {
  removeSecurityAnomaliesModal();

  const ui = document.getElementById('admin-security-anomaly-ui');
  if (!ui) {
    showErrorMessage(translate('error'));
    return;
  }

  const backdrop = document.createElement('div');
  backdrop.id = 'admin-security-anomaly-backdrop';
  backdrop.className = 'access-log-modal-backdrop';

  const modal = document.createElement('div');
  modal.className = 'access-log-modal';

  const header = document.createElement('div');
  header.className = 'access-log-modal-header';
  header.innerHTML = `
    <h3>${ui.dataset.title || 'Security Anomalies'}</h3>
    <button type="button" class="secondary-button thin">${ui.dataset.closeLabel || 'Close'}</button>
  `;
  header.querySelector('button').addEventListener('click', removeSecurityAnomaliesModal);

  const body = document.createElement('div');
  body.className = 'access-log-modal-body';

  const filterGrid = document.createElement('div');
  filterGrid.className = 'access-log-filter-grid';

  const typeField = document.createElement('div');
  typeField.className = 'form-group';
  typeField.innerHTML = `
    <label for="securityAnomalyType">${ui.dataset.typeLabel}</label>
    <select id="securityAnomalyType">
      <option value="">ALL</option>
      <option value="rate_limit">rate_limit</option>
    </select>
  `;

  const keywordField = document.createElement('div');
  keywordField.className = 'form-group';
  keywordField.innerHTML = `
    <label for="securityAnomalyKeyword">${ui.dataset.keywordLabel}</label>
    <input type="text" id="securityAnomalyKeyword" autocomplete="off" placeholder="${ui.dataset.keywordPlaceholder || ''}" />
  `;

  const startField = document.createElement('div');
  startField.className = 'form-group';
  startField.innerHTML = `
    <label for="securityAnomalyStart">${ui.dataset.startLabel}</label>
    <input type="datetime-local" id="securityAnomalyStart" />
  `;

  const endField = document.createElement('div');
  endField.className = 'form-group';
  endField.innerHTML = `
    <label for="securityAnomalyEnd">${ui.dataset.endLabel}</label>
    <input type="datetime-local" id="securityAnomalyEnd" />
  `;

  const limitField = document.createElement('div');
  limitField.className = 'form-group';
  limitField.innerHTML = `
    <label for="securityAnomalyLimit">${ui.dataset.limitLabel}</label>
    <select id="securityAnomalyLimit">
      <option value="50">50</option>
      <option value="100" selected>100</option>
      <option value="200">200</option>
      <option value="300">300</option>
      <option value="500">500</option>
    </select>
  `;

  const actionField = document.createElement('div');
  actionField.className = 'form-group access-log-filter-actions';

  const searchButton = document.createElement('button');
  searchButton.type = 'button';
  searchButton.className = 'button thin';
  searchButton.textContent = ui.dataset.searchLabel || 'Search';

  const clearButton = document.createElement('button');
  clearButton.type = 'button';
  clearButton.className = 'warning-button thin';
  clearButton.textContent = ui.dataset.clearLabel || 'Clear Logs';

  actionField.appendChild(searchButton);
  actionField.appendChild(clearButton);

  filterGrid.appendChild(typeField);
  filterGrid.appendChild(keywordField);
  filterGrid.appendChild(startField);
  filterGrid.appendChild(endField);
  filterGrid.appendChild(limitField);
  filterGrid.appendChild(actionField);

  const resultSummary = document.createElement('p');
  resultSummary.className = 'access-log-results-summary';

  const resultContainer = document.createElement('div');

  const runSearch = () => {
    fetchSecurityAnomalies({
      anomaly_type: document.getElementById('securityAnomalyType')?.value || '',
      keyword: document.getElementById('securityAnomalyKeyword')?.value || '',
      start_at: document.getElementById('securityAnomalyStart')?.value || '',
      end_at: document.getElementById('securityAnomalyEnd')?.value || '',
      limit: document.getElementById('securityAnomalyLimit')?.value || '100',
    }, resultSummary, resultContainer, searchButton, ui);
  };

  searchButton.addEventListener('click', runSearch);
  clearButton.addEventListener('click', () => {
    if (!confirm(ui.dataset.clearConfirmLabel || 'Clear all anomalies now?')) {
      return;
    }

    clearButton.disabled = true;
    fetch('endpoints/admin/clearsecurityanomalies.php', {
      method: 'POST',
      headers: {
        'X-CSRF-Token': window.csrfToken,
      },
    })
      .then(response => response.json())
      .then((data) => {
        if (!data.success) {
          throw new Error(data.message || (ui.dataset.errorLabel || 'Error'));
        }
        showSuccessMessage(data.message);
        runSearch();
      })
      .catch((error) => showErrorMessage(error.message || ui.dataset.errorLabel || 'Error'))
      .finally(() => {
        clearButton.disabled = false;
      });
  });

  body.appendChild(filterGrid);
  body.appendChild(resultSummary);
  body.appendChild(resultContainer);
  modal.appendChild(header);
  modal.appendChild(body);
  backdrop.appendChild(modal);
  backdrop.addEventListener('click', (event) => {
    if (event.target === backdrop) {
      removeSecurityAnomaliesModal();
    }
  });

  document.body.appendChild(backdrop);
  runSearch();
}

function removeUser(userId) {
  const reason = prompt(translate('recycle_bin_reason_prompt'));
  if (reason === null) {
    return;
  }

  if (!reason.trim()) {
    showErrorMessage(translate('recycle_bin_reason_required'));
    return;
  }

  if (!confirm(translate('confirm_move_user_to_recycle_bin'))) {
    return;
  }

  const data = {
    userId: userId,
    reason: reason.trim()
  };

  fetch('endpoints/admin/deleteuser.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data)
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        window.location.reload();
      } else {
        showErrorMessage(data.message);
      }
    })
    .catch(error => showErrorMessage('Error:', error));

}

function resetUserPassword(userId, button) {
  const confirmMessage = button?.dataset.confirmMessage || 'Generate a new temporary password for this user now?';
  if (!confirm(confirmMessage)) {
    return;
  }

  if (button) {
    button.disabled = true;
  }

  fetch('endpoints/admin/resetuserpassword.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ userId })
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        showGeneratedPasswordModal(data.username || '', data.temporaryPassword || '');
      } else {
        showErrorMessage(data.message || translate('error'));
      }
    })
    .catch(() => showErrorMessage(translate('error')))
    .finally(() => {
      if (button) {
        button.disabled = false;
      }
    });
}

function removeGeneratedPasswordModal() {
  const existingModal = document.getElementById('generated-password-backdrop');
  if (existingModal) {
    existingModal.remove();
  }
}

function copyTextToClipboard(text, input = null) {
  const ui = document.getElementById('admin-generated-password-ui');
  const copySuccess = ui?.dataset.copySuccess || translate('copied_to_clipboard');

  const fallbackCopy = () => {
    if (!input) {
      showErrorMessage(translate('error'));
      return;
    }

    input.focus();
    input.select();
    input.setSelectionRange(0, input.value.length);
    if (document.execCommand('copy')) {
      showSuccessMessage(copySuccess);
    } else {
      showErrorMessage(translate('error'));
    }
  };

  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text)
      .then(() => showSuccessMessage(copySuccess))
      .catch(() => fallbackCopy());
    return;
  }

  fallbackCopy();
}

function copyGeneratedPassword(password, input) {
  copyTextToClipboard(password, input);
}

function copyUserId(userId, button) {
  const hiddenInput = document.createElement('input');
  hiddenInput.type = 'text';
  hiddenInput.value = String(userId);
  hiddenInput.readOnly = true;
  hiddenInput.style.position = 'fixed';
  hiddenInput.style.opacity = '0';
  hiddenInput.style.pointerEvents = 'none';
  document.body.appendChild(hiddenInput);
  copyTextToClipboard(String(userId), hiddenInput);
  document.body.removeChild(hiddenInput);

  if (button) {
    button.blur();
  }
}

function showGeneratedPasswordModal(username, temporaryPassword) {
  if (!temporaryPassword) {
    showErrorMessage(translate('error'));
    return;
  }

  removeGeneratedPasswordModal();

  const ui = document.getElementById('admin-generated-password-ui');
  if (!ui) {
    showErrorMessage(translate('error'));
    return;
  }

  const backdrop = document.createElement('div');
  backdrop.id = 'generated-password-backdrop';
  backdrop.className = 'generated-password-backdrop';

  const modal = document.createElement('div');
  modal.className = 'generated-password-modal';

  const title = document.createElement('h3');
  title.textContent = ui.dataset.title || 'Temporary Password Ready';

  const notice = document.createElement('p');
  notice.className = 'generated-password-notice';
  notice.textContent = ui.dataset.notice || '';

  const userField = document.createElement('div');
  userField.className = 'generated-password-field';
  const userLabel = document.createElement('label');
  userLabel.textContent = ui.dataset.usernameLabel || 'Username';
  const userInput = document.createElement('input');
  userInput.type = 'text';
  userInput.readOnly = true;
  userInput.value = username;
  userField.appendChild(userLabel);
  userField.appendChild(userInput);

  const passwordField = document.createElement('div');
  passwordField.className = 'generated-password-field';
  const passwordLabel = document.createElement('label');
  passwordLabel.textContent = ui.dataset.passwordLabel || 'Password';
  const passwordInput = document.createElement('input');
  passwordInput.type = 'text';
  passwordInput.readOnly = true;
  passwordInput.value = temporaryPassword;
  passwordField.appendChild(passwordLabel);
  passwordField.appendChild(passwordInput);

  const actions = document.createElement('div');
  actions.className = 'generated-password-actions';

  const closeButton = document.createElement('button');
  closeButton.type = 'button';
  closeButton.className = 'secondary-button thin';
  closeButton.textContent = ui.dataset.closeLabel || 'Close';
  closeButton.addEventListener('click', removeGeneratedPasswordModal);

  const copyButton = document.createElement('button');
  copyButton.type = 'button';
  copyButton.className = 'thin';
  copyButton.textContent = ui.dataset.copyLabel || 'Copy';
  copyButton.addEventListener('click', () => copyGeneratedPassword(temporaryPassword, passwordInput));

  actions.appendChild(closeButton);
  actions.appendChild(copyButton);

  modal.appendChild(title);
  modal.appendChild(notice);
  modal.appendChild(userField);
  modal.appendChild(passwordField);
  modal.appendChild(actions);

  backdrop.appendChild(modal);
  backdrop.addEventListener('click', (event) => {
    if (event.target === backdrop) {
      removeGeneratedPasswordModal();
    }
  });

  document.body.appendChild(backdrop);
  passwordInput.focus();
  passwordInput.select();
}

function removeAccessLogsModal() {
  const existingModal = document.getElementById('admin-access-log-backdrop');
  if (existingModal) {
    existingModal.remove();
  }
}

function renderAccessLogEntries(logs, resultContainer, ui) {
  resultContainer.innerHTML = '';

  if (!Array.isArray(logs) || logs.length === 0) {
    const emptyState = document.createElement('div');
    emptyState.className = 'settings-notes access-log-empty';
    emptyState.innerHTML = `<p><i class="fa-solid fa-circle-info"></i>${ui.dataset.emptyLabel || 'No access logs are available yet.'}</p>`;
    resultContainer.appendChild(emptyState);
    return;
  }

  const list = document.createElement('div');
  list.className = 'access-log-list compact';

  logs.forEach((log) => {
    const card = document.createElement('div');
    card.className = 'access-log-card';

    const header = document.createElement('div');
    header.className = 'access-log-header';
    header.innerHTML = `
      <div class="access-log-card-title">
        <span class="access-log-id-badge">#${String(log.id || '-')}</span>
        <strong>${String(log.method || '-')}</strong>
      </div>
      <span>${String(log.path || '-')}</span>
    `;

    const headerJson = String(log.headers_json || '').trim();

    card.appendChild(header);
    card.innerHTML += `
      <p>${ui.dataset.userLabel}: ${String(log.username || '-')}</p>
      <p>${ui.dataset.ipLabel}: ${String(log.ip_address || '-')}</p>
      <p>${ui.dataset.forwardedLabel}: ${String(log.forwarded_for || '-')}</p>
      <p>${ui.dataset.agentLabel}: ${String(log.user_agent || '-')}</p>
      <p>${ui.dataset.timeLabel}: ${String(log.created_at || '-')}</p>
    `;

    if (headerJson !== '') {
      const details = document.createElement('details');
      const summary = document.createElement('summary');
      summary.textContent = ui.dataset.headersLabel;
      const pre = document.createElement('pre');
      pre.textContent = headerJson;
      details.appendChild(summary);
      details.appendChild(pre);
      card.appendChild(details);
    }

    list.appendChild(card);
  });

  resultContainer.appendChild(list);
}

function exportAdminAccessLogs(logs, filters, ui) {
  const rows = [];
  rows.push([ui.dataset.exportRuleLabel || 'Filter', 'Value']);
  rows.push([ui.dataset.requestIdLabel, String(filters.request_id || '')]);
  rows.push([ui.dataset.keywordLabel, String(filters.keyword || '')]);
  rows.push([ui.dataset.methodLabel, String(filters.method || '')]);
  rows.push([ui.dataset.startLabel, String(filters.start_at || '')]);
  rows.push([ui.dataset.endLabel, String(filters.end_at || '')]);
  rows.push([ui.dataset.limitLabel, String(filters.limit || '')]);
  rows.push([]);
  rows.push([
    ui.dataset.idLabel || 'ID',
    ui.dataset.methodLabel || 'Method',
    'Path',
    ui.dataset.userLabel || 'Username',
    ui.dataset.ipLabel || 'IP',
    ui.dataset.forwardedLabel || 'Forwarded For',
    ui.dataset.agentLabel || 'User Agent',
    ui.dataset.timeLabel || 'Time',
    ui.dataset.headersLabel || 'Headers',
  ]);

  (logs || []).forEach((log) => {
    rows.push([
      String(log.id || ''),
      String(log.method || ''),
      String(log.path || ''),
      String(log.username || ''),
      String(log.ip_address || ''),
      String(log.forwarded_for || ''),
      String(log.user_agent || ''),
      String(log.created_at || ''),
      String(log.headers_json || ''),
    ]);
  });

  const csvContent = rows.map((row) => row.map((value) => `"${String(value).replace(/"/g, '""')}"`).join(',')).join('\r\n');
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `wallos-access-logs-${Date.now()}.csv`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

function fetchAdminAccessLogs(filters, resultSummary, resultContainer, searchButton, ui) {
  searchButton.disabled = true;
  resultSummary.textContent = ui.dataset.searchLabel || 'Loading...';

  fetch('endpoints/admin/accesslogs.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(filters),
  })
    .then(response => response.json())
    .then(data => {
      if (!data.success) {
        throw new Error(data.message || (ui.dataset.errorLabel || 'Error'));
      }

      resultContainer.dataset.logs = JSON.stringify(data.logs || []);
      resultContainer.dataset.filters = JSON.stringify(data.filters || filters);
      renderAccessLogEntries(data.logs || [], resultContainer, ui);
      const logCount = Array.isArray(data.logs) ? data.logs.length : 0;
      resultSummary.textContent = (ui.dataset.showingLabel || 'Showing %1$d of %2$d matching access logs')
        .replace('%1$d', String(logCount))
        .replace('%2$d', String(data.total || 0));
    })
    .catch((error) => {
      resultSummary.textContent = ui.dataset.errorLabel || 'Error';
      showErrorMessage(error.message || ui.dataset.errorLabel || 'Error');
    })
    .finally(() => {
      searchButton.disabled = false;
    });
}

function openAccessLogsModal() {
  removeAccessLogsModal();

  const ui = document.getElementById('admin-access-log-ui');
  if (!ui) {
    showErrorMessage(translate('error'));
    return;
  }

  const backdrop = document.createElement('div');
  backdrop.id = 'admin-access-log-backdrop';
  backdrop.className = 'access-log-modal-backdrop';

  const modal = document.createElement('div');
  modal.className = 'access-log-modal';

  const header = document.createElement('div');
  header.className = 'access-log-modal-header';
  header.innerHTML = `
    <h3>${ui.dataset.title || 'Access Logs'}</h3>
    <button type="button" class="secondary-button thin">${ui.dataset.closeLabel || 'Close'}</button>
  `;

  const closeButton = header.querySelector('button');
  closeButton.addEventListener('click', removeAccessLogsModal);

  const body = document.createElement('div');
  body.className = 'access-log-modal-body';

  const filterGrid = document.createElement('div');
  filterGrid.className = 'access-log-filter-grid';

  const requestIdField = document.createElement('div');
  requestIdField.className = 'form-group';
  requestIdField.innerHTML = `
    <label for="accessLogRequestId">${ui.dataset.requestIdLabel}</label>
    <input type="number" id="accessLogRequestId" min="0" autocomplete="off" />
  `;

  const keywordField = document.createElement('div');
  keywordField.className = 'form-group';
  keywordField.innerHTML = `
    <label for="accessLogKeyword">${ui.dataset.keywordLabel}</label>
    <input type="text" id="accessLogKeyword" autocomplete="off" placeholder="${ui.dataset.keywordPlaceholder || ''}" />
  `;

  const methodField = document.createElement('div');
  methodField.className = 'form-group';
  methodField.innerHTML = `
    <label for="accessLogMethod">${ui.dataset.methodLabel}</label>
    <select id="accessLogMethod">
      <option value="">ALL</option>
      <option value="GET">GET</option>
      <option value="POST">POST</option>
      <option value="PUT">PUT</option>
      <option value="PATCH">PATCH</option>
      <option value="DELETE">DELETE</option>
    </select>
  `;

  const limitField = document.createElement('div');
  limitField.className = 'form-group';
  limitField.innerHTML = `
    <label for="accessLogLimit">${ui.dataset.limitLabel}</label>
    <select id="accessLogLimit">
      <option value="50">50</option>
      <option value="100" selected>100</option>
      <option value="200">200</option>
      <option value="300">300</option>
      <option value="500">500</option>
    </select>
  `;

  const actionField = document.createElement('div');
  actionField.className = 'form-group access-log-filter-actions';
  const searchButton = document.createElement('button');
  searchButton.type = 'button';
  searchButton.className = 'button thin';
  searchButton.textContent = ui.dataset.searchLabel || 'Search';

  const exportButton = document.createElement('button');
  exportButton.type = 'button';
  exportButton.className = 'secondary-button thin';
  exportButton.textContent = ui.dataset.exportLabel || 'Export Logs';

  const clearButton = document.createElement('button');
  clearButton.type = 'button';
  clearButton.className = 'warning-button thin';
  clearButton.textContent = ui.dataset.clearLabel || 'Clear Logs';

  actionField.appendChild(searchButton);
  actionField.appendChild(exportButton);
  actionField.appendChild(clearButton);

  const startField = document.createElement('div');
  startField.className = 'form-group';
  startField.innerHTML = `
    <label for="accessLogStart">${ui.dataset.startLabel}</label>
    <input type="datetime-local" id="accessLogStart" />
  `;

  const endField = document.createElement('div');
  endField.className = 'form-group';
  endField.innerHTML = `
    <label for="accessLogEnd">${ui.dataset.endLabel}</label>
    <input type="datetime-local" id="accessLogEnd" />
  `;

  filterGrid.appendChild(requestIdField);
  filterGrid.appendChild(keywordField);
  filterGrid.appendChild(methodField);
  filterGrid.appendChild(startField);
  filterGrid.appendChild(endField);
  filterGrid.appendChild(limitField);
  filterGrid.appendChild(actionField);

  const resultSummary = document.createElement('p');
  resultSummary.className = 'access-log-results-summary';
  resultSummary.textContent = ui.dataset.emptyLabel || '';

  const resultContainer = document.createElement('div');

  const runSearch = () => {
    fetchAdminAccessLogs({
      request_id: document.getElementById('accessLogRequestId')?.value || '',
      keyword: document.getElementById('accessLogKeyword')?.value || '',
      method: document.getElementById('accessLogMethod')?.value || '',
      start_at: document.getElementById('accessLogStart')?.value || '',
      end_at: document.getElementById('accessLogEnd')?.value || '',
      limit: document.getElementById('accessLogLimit')?.value || '100',
    }, resultSummary, resultContainer, searchButton, ui);
  };

  searchButton.addEventListener('click', runSearch);
  exportButton.addEventListener('click', () => {
    const logs = JSON.parse(resultContainer.dataset.logs || '[]');
    const filters = JSON.parse(resultContainer.dataset.filters || '{}');
    exportAdminAccessLogs(logs, filters, ui);
  });
  clearButton.addEventListener('click', () => {
    if (!confirm(ui.dataset.clearConfirmLabel || 'Clear all access logs now?')) {
      return;
    }

    clearButton.disabled = true;
    fetch('endpoints/admin/clearaccesslogs.php', {
      method: 'POST',
      headers: {
        'X-CSRF-Token': window.csrfToken,
      },
    })
      .then(response => response.json())
      .then((data) => {
        if (!data.success) {
          throw new Error(data.message || (ui.dataset.errorLabel || 'Error'));
        }

        showSuccessMessage(data.message);
        runSearch();
      })
      .catch((error) => showErrorMessage(error.message || ui.dataset.errorLabel || 'Error'))
      .finally(() => {
        clearButton.disabled = false;
      });
  });

  body.appendChild(filterGrid);
  body.appendChild(resultSummary);
  body.appendChild(resultContainer);

  modal.appendChild(header);
  modal.appendChild(body);
  backdrop.appendChild(modal);
  backdrop.addEventListener('click', (event) => {
    if (event.target === backdrop) {
      removeAccessLogsModal();
    }
  });

  document.body.appendChild(backdrop);
  runSearch();
}

function toggleAdminSection(button) {
  const targetId = button.dataset.target;
  const target = document.getElementById(targetId);
  if (!target) {
    return;
  }

  const isExpanded = button.getAttribute('aria-expanded') === 'true';
  button.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
  target.classList.toggle('is-collapsed', isExpanded);

  const icon = button.querySelector('i');
  if (icon) {
    icon.classList.toggle('fa-chevron-up', !isExpanded);
    icon.classList.toggle('fa-chevron-down', isExpanded);
  }
}

function switchAdminTab(group, tabId, button) {
  document.querySelectorAll(`[data-tab-panel="${group}"]`).forEach((panel) => {
    panel.classList.toggle('is-active', panel.dataset.tabId === tabId);
  });

  const tabContainer = button.closest(`[data-tab-group="${group}"]`);
  if (!tabContainer) {
    return;
  }

  tabContainer.querySelectorAll('.section-tab-button').forEach((tabButton) => {
    tabButton.classList.toggle('is-active', tabButton === button);
  });
}

function restoreUser(userId) {
  fetch('endpoints/admin/restoreuser.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ userId })
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        window.location.reload();
      } else {
        showErrorMessage(data.message || translate('error'));
      }
    })
    .catch(() => showErrorMessage(translate('error')));
}

function permanentlyDeleteUser(userId) {
  if (!confirm(translate('confirm_permanently_delete_user'))) {
    return;
  }

  if (!confirm(translate('confirm_permanently_delete_user_second'))) {
    return;
  }

  fetch('endpoints/admin/permanentlydeleteuser.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ userId })
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        window.location.reload();
      } else {
        showErrorMessage(data.message || translate('error'));
      }
    })
    .catch(() => showErrorMessage(translate('error')));
}

function updateUserGroup(userId, selectElement) {
  const previousValue = selectElement.dataset.currentValue || selectElement.value;
  const nextValue = selectElement.value;

  selectElement.disabled = true;

  fetch('endpoints/admin/updateusergroup.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({
      userId: userId,
      userGroup: nextValue,
    })
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        selectElement.dataset.currentValue = nextValue;
        showSuccessMessage(data.message);
      } else {
        selectElement.value = previousValue;
        showErrorMessage(data.message || translate('error'));
      }
    })
    .catch(() => {
      selectElement.value = previousValue;
      showErrorMessage(translate('error'));
    })
    .finally(() => {
      selectElement.disabled = false;
    });
}

function addUserButton() {
  const button = document.getElementById('addUserButton');
  button.disabled = true;

  const username = document.getElementById('newUsername').value;
  const email = document.getElementById('newEmail').value;
  const password = document.getElementById('newPassword').value;

  const data = {
    username: username,
    email: email,
    password: password
  };

  fetch('endpoints/admin/adduser.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data)
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        button.disabled = false;
        window.location.reload();
      } else {
        showErrorMessage(data.message);
        button.disabled = false;
      }
    })
    .catch(error => {
      showErrorMessage(error);
      button.disabled = false;
    });
}

function saveSubscriptionImageSettingsButton() {
  const button = document.getElementById('saveSubscriptionImageSettingsButton');
  button.disabled = true;

  const data = {
    subscription_image_external_url_limit: document.getElementById('subscriptionImageExternalUrlLimit').value,
    trusted_subscription_upload_limit: document.getElementById('trustedSubscriptionUploadLimit').value,
    subscription_image_max_size_mb: document.getElementById('subscriptionImageMaxSizeMb').value,
  };

  fetch('endpoints/admin/saveimagesettings.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data),
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message || translate('error'));
      }
    })
    .catch(() => showErrorMessage(translate('error')))
    .finally(() => {
      button.disabled = false;
    });
}

function saveBackupSettingsButton() {
  const button = document.getElementById("saveBackupSettingsButton");
  button.disabled = true;

  const data = {
    backup_retention_days: document.getElementById("backupRetentionDays").value,
    backup_timezone: document.getElementById("backupTimezone").value,
  };

  fetch("endpoints/admin/savebackupsettings.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": window.csrfToken,
    },
    body: JSON.stringify(data),
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message || translate("error"));
      }
    })
    .catch(() => showErrorMessage(translate("error")))
    .finally(() => {
      button.disabled = false;
    });
}

function cleanupOldBackupsButton(button) {
  const confirmMessage = button?.dataset.confirmMessage || "Clean up old backups now?";
  if (!confirm(confirmMessage)) {
    return;
  }

  button.disabled = true;

  fetch("endpoints/admin/cleanupbackups.php", {
    method: "POST",
    headers: {
      "X-CSRF-Token": window.csrfToken,
    },
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        setTimeout(() => window.location.reload(), 500);
      } else {
        showErrorMessage(data.message || translate("error"));
      }
    })
    .catch(() => showErrorMessage(translate("error")))
    .finally(() => {
      button.disabled = false;
    });
}

function generateInviteCode() {
  const button = document.getElementById('generateInviteCodeButton');
  button.disabled = true;

  const maxUses = document.getElementById('inviteCodeMaxUses').value || 1;

  fetch('endpoints/admin/generateinvitecode.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ maxUses }),
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(`${data.message} ${data.code}`);
        window.location.reload();
      } else {
        showErrorMessage(data.message || translate('error'));
      }
    })
    .catch(() => showErrorMessage(translate('error')))
    .finally(() => {
      button.disabled = false;
    });
}

function deleteInviteCode(inviteCodeId) {
  if (!confirm(translate('confirm_delete_invite_code'))) {
    return;
  }

  fetch('endpoints/admin/deleteinvitecode.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ inviteCodeId }),
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        window.location.reload();
      } else {
        showErrorMessage(data.message || translate('error'));
      }
    })
    .catch(() => showErrorMessage(translate('error')));
}

function permanentlyDeleteInviteCode(inviteCodeId, button) {
  const confirmMessage = button?.dataset.confirmMessage || 'Permanently delete this invite code?';
  if (!confirm(confirmMessage)) {
    return;
  }

  if (button) {
    button.disabled = true;
  }

  fetch('endpoints/admin/permanentlydeleteinvitecode.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ inviteCodeId }),
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        window.location.reload();
      } else {
        showErrorMessage(data.message || translate('error'));
      }
    })
    .catch(() => showErrorMessage(translate('error')))
    .finally(() => {
      if (button) {
        button.disabled = false;
      }
    });
}

function updateScheduledDeleteAt(userId, button) {
  const card = button.closest('.ban-user-card');
  if (!card) {
    showErrorMessage(translate('error'));
    return;
  }

  const input = card.querySelector('.scheduled-delete-input');
  const display = card.querySelector('[data-scheduled-delete-display]');
  const scheduledDeleteAt = input?.value?.trim() || '';

  if (!scheduledDeleteAt) {
    showErrorMessage(translate('error'));
    return;
  }

  button.disabled = true;

  fetch('endpoints/admin/updatescheduleddeleteat.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ userId, scheduledDeleteAt }),
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        if (display && data.scheduledDeleteAt) {
          display.textContent = data.scheduledDeleteAt;
        }
        if (input && data.datetimeLocal) {
          input.value = data.datetimeLocal;
        }
      } else {
        showErrorMessage(data.message || translate('error'));
      }
    })
    .catch(() => showErrorMessage(translate('error')))
    .finally(() => {
      button.disabled = false;
    });
}

function deleteUnusedLogos() {
  const button = document.getElementById('deleteUnusedLogos');
  button.disabled = true;

  fetch('endpoints/admin/deleteunusedlogos.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    }
  })
    .then(response => response.json())
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
      showErrorMessage(error);
      button.disabled = false;
    });
}

function toggleUpdateNotification() {
  const notificationEnabledCheckbox = document.getElementById('updateNotification');
  const notificationEnabled = notificationEnabledCheckbox.checked ? 1 : 0;

  const data = {
    notificationEnabled: notificationEnabled
  };

  fetch('endpoints/admin/updatenotification.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data)
  })
    .then(response => response.json())
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
    .catch(error => showErrorMessage('Error:', error));

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

  fetch('endpoints/admin/enableoidc.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data)
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
      toggle.disabled = false;
    })
    .catch(error => {
      showErrorMessage('Error:', error);
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


  fetch('endpoints/admin/saveoidcsettings.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data)
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
      button.disabled = false;
    })
    .catch(error => {
      showErrorMessage('Error:', error);
      button.disabled = false;
    });
}
