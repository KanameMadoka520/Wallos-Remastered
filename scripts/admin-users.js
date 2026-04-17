(function () {
  function removeUser(userId) {
    const reason = prompt(translate("recycle_bin_reason_prompt"));
    if (reason === null) {
      return;
    }

    if (!reason.trim()) {
      showErrorMessage(translate("recycle_bin_reason_required"));
      return;
    }

    if (!confirm(translate("confirm_move_user_to_recycle_bin"))) {
      return;
    }

    fetch("endpoints/admin/deleteuser.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": window.csrfToken,
      },
      body: JSON.stringify({
        userId,
        reason: reason.trim(),
      }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          showSuccessMessage(data.message);
          window.location.reload();
        } else {
          showErrorMessage(data.message);
        }
      })
      .catch((error) => showErrorMessage("Error:", error));
  }

  function resetUserPassword(userId, button) {
    const confirmMessage = button?.dataset.confirmMessage || "Generate a new temporary password for this user now?";
    if (!confirm(confirmMessage)) {
      return;
    }

    if (button) {
      button.disabled = true;
    }

    fetch("endpoints/admin/resetuserpassword.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": window.csrfToken,
      },
      body: JSON.stringify({ userId }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          showSuccessMessage(data.message);
          showGeneratedPasswordModal(data.username || "", data.temporaryPassword || "");
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

  function removeGeneratedPasswordModal() {
    const existingModal = document.getElementById("generated-password-backdrop");
    if (existingModal) {
      existingModal.remove();
    }
  }

  function copyTextToClipboard(text, input = null) {
    const ui = document.getElementById("admin-generated-password-ui");
    const copySuccess = ui?.dataset.copySuccess || translate("copied_to_clipboard");

    const fallbackCopy = () => {
      if (!input) {
        showErrorMessage(translate("error"));
        return;
      }

      input.focus();
      input.select();
      input.setSelectionRange(0, input.value.length);
      if (document.execCommand("copy")) {
        showSuccessMessage(copySuccess);
      } else {
        showErrorMessage(translate("error"));
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
    const hiddenInput = document.createElement("input");
    hiddenInput.type = "text";
    hiddenInput.value = String(userId);
    hiddenInput.readOnly = true;
    hiddenInput.style.position = "fixed";
    hiddenInput.style.opacity = "0";
    hiddenInput.style.pointerEvents = "none";
    document.body.appendChild(hiddenInput);
    copyTextToClipboard(String(userId), hiddenInput);
    document.body.removeChild(hiddenInput);

    if (button) {
      button.blur();
    }
  }

  function showGeneratedPasswordModal(username, temporaryPassword) {
    if (!temporaryPassword) {
      showErrorMessage(translate("error"));
      return;
    }

    removeGeneratedPasswordModal();

    const ui = document.getElementById("admin-generated-password-ui");
    if (!ui) {
      showErrorMessage(translate("error"));
      return;
    }

    const backdrop = document.createElement("div");
    backdrop.id = "generated-password-backdrop";
    backdrop.className = "generated-password-backdrop";

    const modal = document.createElement("div");
    modal.className = "generated-password-modal";

    const title = document.createElement("h3");
    title.textContent = ui.dataset.title || "Temporary Password Ready";

    const notice = document.createElement("p");
    notice.className = "generated-password-notice";
    notice.textContent = ui.dataset.notice || "";

    const userField = document.createElement("div");
    userField.className = "generated-password-field";
    const userLabel = document.createElement("label");
    userLabel.textContent = ui.dataset.usernameLabel || "Username";
    const userInput = document.createElement("input");
    userInput.type = "text";
    userInput.readOnly = true;
    userInput.value = username;
    userField.appendChild(userLabel);
    userField.appendChild(userInput);

    const passwordField = document.createElement("div");
    passwordField.className = "generated-password-field";
    const passwordLabel = document.createElement("label");
    passwordLabel.textContent = ui.dataset.passwordLabel || "Password";
    const passwordInput = document.createElement("input");
    passwordInput.type = "text";
    passwordInput.readOnly = true;
    passwordInput.value = temporaryPassword;
    passwordField.appendChild(passwordLabel);
    passwordField.appendChild(passwordInput);

    const actions = document.createElement("div");
    actions.className = "generated-password-actions";

    const closeButton = document.createElement("button");
    closeButton.type = "button";
    closeButton.className = "secondary-button thin";
    closeButton.textContent = ui.dataset.closeLabel || "Close";
    closeButton.addEventListener("click", removeGeneratedPasswordModal);

    const copyButton = document.createElement("button");
    copyButton.type = "button";
    copyButton.className = "thin";
    copyButton.textContent = ui.dataset.copyLabel || "Copy";
    copyButton.addEventListener("click", () => copyGeneratedPassword(temporaryPassword, passwordInput));

    actions.appendChild(closeButton);
    actions.appendChild(copyButton);

    modal.appendChild(title);
    modal.appendChild(notice);
    modal.appendChild(userField);
    modal.appendChild(passwordField);
    modal.appendChild(actions);

    backdrop.appendChild(modal);
    backdrop.addEventListener("click", (event) => {
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

    const isExpanded = button.getAttribute("aria-expanded") === "true";
    button.setAttribute("aria-expanded", isExpanded ? "false" : "true");
    target.classList.toggle("is-collapsed", isExpanded);

    const icon = button.querySelector("i");
    if (icon) {
      icon.classList.toggle("fa-chevron-up", !isExpanded);
      icon.classList.toggle("fa-chevron-down", isExpanded);
    }
  }

  function switchAdminTab(group, tabId, button) {
    document.querySelectorAll(`[data-tab-panel="${group}"]`).forEach((panel) => {
      panel.classList.toggle("is-active", panel.dataset.tabId === tabId);
    });

    const tabContainer = button.closest(`[data-tab-group="${group}"]`);
    if (!tabContainer) {
      return;
    }

    tabContainer.querySelectorAll(".section-tab-button").forEach((tabButton) => {
      tabButton.classList.toggle("is-active", tabButton === button);
    });
  }

  function restoreUser(userId) {
    fetch("endpoints/admin/restoreuser.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": window.csrfToken,
      },
      body: JSON.stringify({ userId }),
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

  function permanentlyDeleteUser(userId) {
    if (!confirm(translate("confirm_permanently_delete_user"))) {
      return;
    }

    if (!confirm(translate("confirm_permanently_delete_user_second"))) {
      return;
    }

    fetch("endpoints/admin/permanentlydeleteuser.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": window.csrfToken,
      },
      body: JSON.stringify({ userId }),
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

  function updateUserGroup(userId, selectElement) {
    const previousValue = selectElement.dataset.currentValue || selectElement.value;
    const nextValue = selectElement.value;

    selectElement.disabled = true;

    fetch("endpoints/admin/updateusergroup.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": window.csrfToken,
      },
      body: JSON.stringify({
        userId,
        userGroup: nextValue,
      }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          selectElement.dataset.currentValue = nextValue;
          showSuccessMessage(data.message);
        } else {
          selectElement.value = previousValue;
          showErrorMessage(data.message || translate("error"));
        }
      })
      .catch(() => {
        selectElement.value = previousValue;
        showErrorMessage(translate("error"));
      })
      .finally(() => {
        selectElement.disabled = false;
      });
  }

  function addUserButton() {
    const button = document.getElementById("addUserButton");
    button.disabled = true;

    const username = document.getElementById("newUsername").value;
    const email = document.getElementById("newEmail").value;
    const password = document.getElementById("newPassword").value;

    fetch("endpoints/admin/adduser.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": window.csrfToken,
      },
      body: JSON.stringify({
        username,
        email,
        password,
      }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          showSuccessMessage(data.message);
          window.location.reload();
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

  function updateScheduledDeleteAt(userId, button) {
    const card = button.closest(".ban-user-card");
    if (!card) {
      showErrorMessage(translate("error"));
      return;
    }

    const input = card.querySelector(".scheduled-delete-input");
    const display = card.querySelector("[data-scheduled-delete-display]");
    const scheduledDeleteAt = input?.value?.trim() || "";

    if (!scheduledDeleteAt) {
      showErrorMessage(translate("error"));
      return;
    }

    button.disabled = true;

    fetch("endpoints/admin/updatescheduleddeleteat.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": window.csrfToken,
      },
      body: JSON.stringify({ userId, scheduledDeleteAt }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          showSuccessMessage(data.message);
          if (display && data.scheduledDeleteAt) {
            display.textContent = data.scheduledDeleteAt;
          }
          if (input && data.datetimeLocal) {
            input.value = data.datetimeLocal;
          }
        } else {
          showErrorMessage(data.message || translate("error"));
        }
      })
      .catch(() => showErrorMessage(translate("error")))
      .finally(() => {
        button.disabled = false;
      });
  }

  window.WallosAdminUsers = {
    removeUser,
    resetUserPassword,
    removeGeneratedPasswordModal,
    copyTextToClipboard,
    copyGeneratedPassword,
    copyUserId,
    showGeneratedPasswordModal,
    toggleAdminSection,
    switchAdminTab,
    restoreUser,
    permanentlyDeleteUser,
    updateUserGroup,
    addUserButton,
    updateScheduledDeleteAt,
  };
})();
