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

  fetch("endpoints/db/backup.php", {
    method: "POST",
    headers: {
      "X-CSRF-Token": window.csrfToken,
    },
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        const link = document.createElement("a");
        const filename = data.file;
        link.href = ".tmp/" + filename;

        const date = new Date();
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, "0");
        const day = String(date.getDate()).padStart(2, "0");
        const hours = String(date.getHours()).padStart(2, "0");
        const minutes = String(date.getMinutes()).padStart(2, "0");
        const timestamp = `${year}${month}${day}-${hours}${minutes}`;
        link.download = `Wallos-Backup-${timestamp}.zip`;

        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
      } else {
        showErrorMessage(data.message || translate("backup_failed"));
      }
    })
    .catch(error => {
      console.error(error);
      showErrorMessage(translate("unknown_error"));
    })
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

        // After restoring, run migrations then log out (force re-login)
        fetch('endpoints/db/migrate.php')
          .then(() => {
            window.location.href = 'logout.php';
          })
          .catch(() => {
            window.location.href = 'logout.php';
          });
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

  const data = {
    open_registrations: open_registrations,
    invite_only_registration: invite_only_registration,
    max_users: max_users,
    require_email_validation: require_email_validation,
    server_url: server_url,
    disable_login: disable_login
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

  const data = {
    local_webhook_notifications_allowlist: allowlist
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

function copyGeneratedPassword(password, input) {
  const ui = document.getElementById('admin-generated-password-ui');
  const copySuccess = ui?.dataset.copySuccess || translate('copied_to_clipboard');

  const fallbackCopy = () => {
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
    navigator.clipboard.writeText(password)
      .then(() => showSuccessMessage(copySuccess))
      .catch(() => fallbackCopy());
    return;
  }

  fallbackCopy();
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
