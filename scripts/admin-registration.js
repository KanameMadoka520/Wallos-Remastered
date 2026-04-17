(function () {
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

    fetch("endpoints/admin/saveopenregistrations.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": window.csrfToken,
      },
      body: JSON.stringify(data),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          showSuccessMessage(data.message);
        } else {
          showErrorMessage(data.message);
        }
      })
      .catch((error) => {
        showErrorMessage(error);
      })
      .finally(() => {
        button.disabled = false;
      });
  }

  function generateInviteCode() {
    const button = document.getElementById("generateInviteCodeButton");
    button.disabled = true;

    const maxUses = document.getElementById("inviteCodeMaxUses").value || 1;

    fetch("endpoints/admin/generateinvitecode.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": window.csrfToken,
      },
      body: JSON.stringify({ maxUses }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          showSuccessMessage(`${data.message} ${data.code}`);
          window.location.reload();
        } else {
          showErrorMessage(data.message || translate("error"));
        }
      })
      .catch(() => showErrorMessage(translate("error")))
      .finally(() => {
        button.disabled = false;
      });
  }

  function deleteInviteCode(inviteCodeId) {
    if (!confirm(translate("confirm_delete_invite_code"))) {
      return;
    }

    fetch("endpoints/admin/deleteinvitecode.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": window.csrfToken,
      },
      body: JSON.stringify({ inviteCodeId }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          showSuccessMessage(data.message);
          window.location.reload();
        } else {
          showErrorMessage(data.message || translate("error"));
        }
      })
      .catch(() => showErrorMessage(translate("error")));
  }

  function permanentlyDeleteInviteCode(inviteCodeId, button) {
    const confirmMessage = button?.dataset.confirmMessage || "Permanently delete this invite code?";
    if (!confirm(confirmMessage)) {
      return;
    }

    if (button) {
      button.disabled = true;
    }

    fetch("endpoints/admin/permanentlydeleteinvitecode.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": window.csrfToken,
      },
      body: JSON.stringify({ inviteCodeId }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          showSuccessMessage(data.message);
          window.location.reload();
        } else {
          showErrorMessage(data.message || translate("error"));
        }
      })
      .catch(() => showErrorMessage(translate("error")))
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
