(function () {
  function adminRegistrationPostJson(url, payload = {}, options = {}) {
    return window.WallosApi.postJson(url, payload, {
      fallbackErrorMessage: options.fallbackErrorMessage || translate("error"),
    });
  }

  function normalizeAdminRegistrationError(error, fallbackMessage = null) {
    return window.WallosApi?.normalizeError?.(error, fallbackMessage || translate("error"))
      || fallbackMessage
      || translate("error");
  }

  function saveAccountRegistrationsButton() {
    const button = document.getElementById("saveAccountRegistrations");
    button.disabled = true;

    const data = {
      open_registrations: document.getElementById("registrations").checked ? 1 : 0,
      invite_only_registration: document.getElementById("inviteOnlyRegistration").checked ? 1 : 0,
      max_users: document.getElementById("maxUsers").value,
      require_email_validation: document.getElementById("requireEmail").checked ? 1 : 0,
      server_url: document.getElementById("serverUrl").value,
      disable_login: document.getElementById("disableLogin").checked ? 1 : 0,
      custom_edition_title: document.getElementById("customEditionTitle").value,
      custom_edition_subtitle: document.getElementById("customEditionSubtitle").value,
    };

    adminRegistrationPostJson("endpoints/admin/saveopenregistrations.php", data)
      .then((data) => {
        if (data.success) {
          showSuccessMessage(data.message);
        } else {
          showErrorMessage(data.message || translate("error"));
        }
      })
      .catch((error) => {
        showErrorMessage(normalizeAdminRegistrationError(error));
      })
      .finally(() => {
        button.disabled = false;
      });
  }

  function generateInviteCode() {
    const button = document.getElementById("generateInviteCodeButton");
    button.disabled = true;

    const maxUses = document.getElementById("inviteCodeMaxUses").value || 1;

    adminRegistrationPostJson("endpoints/admin/generateinvitecode.php", { maxUses })
      .then((data) => {
        if (data.success) {
          showSuccessMessage(`${data.message} ${data.code}`);
          window.location.reload();
        } else {
          showErrorMessage(data.message || translate("error"));
        }
      })
      .catch((error) => showErrorMessage(normalizeAdminRegistrationError(error)))
      .finally(() => {
        button.disabled = false;
      });
  }

  function deleteInviteCode(inviteCodeId) {
    if (!confirm(translate("confirm_delete_invite_code"))) {
      return;
    }

    adminRegistrationPostJson("endpoints/admin/deleteinvitecode.php", { inviteCodeId })
      .then((data) => {
        if (data.success) {
          showSuccessMessage(data.message);
          window.location.reload();
        } else {
          showErrorMessage(data.message || translate("error"));
        }
      })
      .catch((error) => showErrorMessage(normalizeAdminRegistrationError(error)));
  }

  function permanentlyDeleteInviteCode(inviteCodeId, button) {
    const confirmMessage = button?.dataset.confirmMessage || "Permanently delete this invite code?";
    if (!confirm(confirmMessage)) {
      return;
    }

    if (button) {
      button.disabled = true;
    }

    adminRegistrationPostJson("endpoints/admin/permanentlydeleteinvitecode.php", { inviteCodeId })
      .then((data) => {
        if (data.success) {
          showSuccessMessage(data.message);
          window.location.reload();
        } else {
          showErrorMessage(data.message || translate("error"));
        }
      })
      .catch((error) => showErrorMessage(normalizeAdminRegistrationError(error)))
      .finally(() => {
        if (button) {
          button.disabled = false;
        }
      });
  }

  window.WallosAdminRegistration = {
    saveAccountRegistrationsButton,
    generateInviteCode,
    deleteInviteCode,
    permanentlyDeleteInviteCode,
  };
})();
