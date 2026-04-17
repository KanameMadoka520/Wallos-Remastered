(function () {
  function postJson(url, payload = {}, headers = {}) {
    return fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': window.csrfToken,
        ...headers,
      },
      body: JSON.stringify(payload),
    }).then((response) => response.json());
  }

  function runPostRestoreActions() {
    fetch('endpoints/db/migrate.php')
      .then(() => { window.location.href = 'logout.php'; })
      .catch(() => { window.location.href = 'logout.php'; });
  }

  function updateBackupCardStatus(button, statusLabel, statusTone = 'pending') {
    const card = button?.closest('.backup-card');
    const status = card?.querySelector('[data-backup-status]');
    if (!status) return;
    status.textContent = statusLabel;
    status.classList.remove('is-pending', 'is-success', 'is-warning', 'is-error');
    status.classList.add(statusTone === 'success' ? 'is-success' : statusTone === 'warning' ? 'is-warning' : statusTone === 'error' ? 'is-error' : 'is-pending');
  }

  function backupDB() {
    const button = document.getElementById('backupDB');
    if (!button) return;
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
      const card = document.getElementById('backupProgressCard');
      const percent = document.getElementById('backupProgressPercent');
      const bar = document.getElementById('backupProgressBar');
      const message = document.getElementById('backupProgressMessage');
      const tone = document.getElementById('backupProgressTone');
      if (!card || !percent || !bar || !message || !tone) return;
      const progress = Math.max(0, Math.min(100, Number(status?.progress || 0)));
      const state = String(status?.state || 'running');
      const toneValue = String(status?.tone || (state === 'completed' ? 'success' : state === 'failed' ? 'error' : 'pending'));
      const statusMessage = String(status?.message || card.dataset.idleMessage || '');
      const backupLabel = card.dataset.backupLabel || 'Backup';
      card.classList.remove('is-hidden', 'is-pending', 'is-success', 'is-error');
      card.classList.add(toneValue === 'success' ? 'is-success' : toneValue === 'error' ? 'is-error' : 'is-pending');
      percent.textContent = `${Math.round(progress)}%`;
      bar.style.width = `${progress}%`;
      message.textContent = statusMessage;
      tone.textContent = state === 'completed' ? translate('success') : state === 'failed' ? translate('error') : backupLabel;
    };
    const pollBackupProgress = () => {
      postJson('endpoints/admin/backupstatus.php', { operationId })
        .then((data) => {
          if (data.success && data.status) {
            renderBackupProgress(data.status);
            if (data.status.state === 'completed' || data.status.state === 'failed') {
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
    renderBackupProgress({ state: 'running', tone: 'pending', progress: 1, message: document.getElementById('backupProgressCard')?.dataset.startingMessage || translate('backup') });
    pollBackupProgress();
    postJson('endpoints/admin/createbackup.php', { operationId })
      .then((data) => {
        if (data.success) {
          renderBackupProgress({ state: 'completed', tone: 'success', progress: 100, message: data.message });
          showSuccessMessage(data.message);
          const link = document.createElement('a');
          link.href = data.downloadUrl;
          link.rel = 'noreferrer';
          document.body.appendChild(link);
          link.click();
          document.body.removeChild(link);
          setTimeout(() => window.location.reload(), 900);
        } else {
          renderBackupProgress({ state: 'failed', tone: 'error', progress: 100, message: data.message || translate('backup_failed') });
          showErrorMessage(data.message || translate('backup_failed'));
        }
      })
      .catch((error) => {
        console.error(error);
        renderBackupProgress({ state: 'failed', tone: 'error', progress: 100, message: translate('backup_failed') });
        showErrorMessage(translate('unknown_error'));
      })
      .finally(() => {
        stopPolling();
        button.disabled = false;
      });
  }

  function verifyBackup(backupName, button) {
    if (!backupName || !button) {
      showErrorMessage(translate('error'));
      return;
    }
    button.disabled = true;
    postJson('endpoints/admin/verifybackup.php', { name: backupName })
      .then((data) => {
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
      .finally(() => { button.disabled = false; });
  }

  function restoreBackup(backupName, button) {
    const confirmMessage = button?.dataset.confirmMessage || 'Restore from this backup now?';
    const confirmSecondMessage = button?.dataset.confirmSecondMessage || 'Please confirm again.';
    if (!confirm(confirmMessage) || !confirm(confirmSecondMessage)) return;
    button.disabled = true;
    postJson('endpoints/admin/restorebackup.php', { name: backupName })
      .then((data) => {
        if (data.success) {
          showSuccessMessage(data.message);
          runPostRestoreActions();
        } else {
          showErrorMessage(data.message || translate('restore_failed'));
        }
      })
      .catch(() => showErrorMessage(translate('restore_failed')))
      .finally(() => { button.disabled = false; });
  }

  function openRestoreDBFileSelect() { document.getElementById('restoreDBFile')?.click(); }

  function restoreDB() {
    const input = document.getElementById('restoreDBFile');
    const file = input?.files?.[0];
    if (!file) {
      showErrorMessage(translate('no_file_selected'));
      return;
    }
    const formData = new FormData();
    formData.append('file', file);
    const button = document.getElementById('restoreDB');
    if (button) button.disabled = true;
    fetch('endpoints/db/restore.php', { method: 'POST', headers: { 'X-CSRF-Token': window.csrfToken }, body: formData })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          showSuccessMessage(data.message);
          runPostRestoreActions();
        } else {
          showErrorMessage(data.message || translate('restore_failed'));
        }
      })
      .catch((error) => { console.error(error); showErrorMessage(translate('unknown_error')); })
      .finally(() => { if (button) button.disabled = false; });
  }

  function saveBackupSettingsButton() {
    const button = document.getElementById('saveBackupSettingsButton');
    if (!button) return;
    button.disabled = true;
    postJson('endpoints/admin/savebackupsettings.php', {
      backup_retention_days: document.getElementById('backupRetentionDays')?.value,
      backup_timezone: document.getElementById('backupTimezone')?.value,
    })
      .then((data) => {
        if (data.success) { showSuccessMessage(data.message); }
        else { showErrorMessage(data.message || translate('error')); }
      })
      .catch(() => showErrorMessage(translate('error')))
      .finally(() => { button.disabled = false; });
  }

  function cleanupOldBackupsButton(button) {
    const confirmMessage = button?.dataset.confirmMessage || 'Clean up old backups now?';
    if (!confirm(confirmMessage)) return;
    if (button) button.disabled = true;
    fetch('endpoints/admin/cleanupbackups.php', { method: 'POST', headers: { 'X-CSRF-Token': window.csrfToken } })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          showSuccessMessage(data.message);
          setTimeout(() => window.location.reload(), 500);
        } else {
          showErrorMessage(data.message || translate('error'));
        }
      })
      .catch(() => showErrorMessage(translate('error')))
      .finally(() => { if (button) button.disabled = false; });
  }

  window.WallosAdminBackups = { backupDB, runPostRestoreActions, updateBackupCardStatus, verifyBackup, restoreBackup, openRestoreDBFileSelect, restoreDB, saveBackupSettingsButton, cleanupOldBackupsButton };
})();
