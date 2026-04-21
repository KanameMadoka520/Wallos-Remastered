(function () {
  function adminRateLimitPostJson(url, payload = {}, options = {}) {
    return window.WallosApi.postJson(url, payload, {
      fallbackErrorMessage: options.fallbackErrorMessage || translate("error"),
    });
  }

  function normalizeAdminRateLimitError(error, fallbackMessage = null) {
    return window.WallosApi?.normalizeError?.(error, fallbackMessage || translate("error"))
      || fallbackMessage
      || translate("error");
  }

  function getRateLimitPresetUi() {
    return document.getElementById("admin-rate-limit-preset-ui");
  }

  function saveSecuritySettingsButton() {
    const button = document.getElementById("saveSecuritySettingsButton");
    button.disabled = true;

    const allowlist = document.getElementById("local_webhook_notifications_allowlist").value;
    const loginRateLimitMaxAttempts = document.getElementById("login_rate_limit_max_attempts").value;
    const advancedRateLimitEnabled = document.getElementById("advancedRateLimitEnabled").checked ? 1 : 0;

    const data = {
      local_webhook_notifications_allowlist: allowlist,
      login_rate_limit_max_attempts: loginRateLimitMaxAttempts,
      login_rate_limit_block_minutes: document.getElementById("login_rate_limit_block_minutes").value,
      advanced_rate_limit_enabled: advancedRateLimitEnabled,
      backend_request_limit_per_minute: document.getElementById("backend_request_limit_per_minute").value,
      backend_request_limit_per_hour: document.getElementById("backend_request_limit_per_hour").value,
      image_upload_limit_per_minute: document.getElementById("image_upload_limit_per_minute").value,
      image_upload_limit_per_hour: document.getElementById("image_upload_limit_per_hour").value,
      image_upload_mb_per_minute: document.getElementById("image_upload_mb_per_minute").value,
      image_upload_mb_per_hour: document.getElementById("image_upload_mb_per_hour").value,
      image_download_limit_per_minute: document.getElementById("image_download_limit_per_minute").value,
      image_download_limit_per_hour: document.getElementById("image_download_limit_per_hour").value,
      image_download_mb_per_minute: document.getElementById("image_download_mb_per_minute").value,
      image_download_mb_per_hour: document.getElementById("image_download_mb_per_hour").value,
    };

    adminRateLimitPostJson("endpoints/admin/savesecuritysettings.php", data)
      .then((data) => {
        if (data.success) {
          showSuccessMessage(data.message);
        } else {
          showErrorMessage(data.message || translate("error"));
        }
      })
      .catch((error) => {
        showErrorMessage(normalizeAdminRateLimitError(error));
      })
      .finally(() => {
        button.disabled = false;
      });
  }

  function getCurrentRateLimitConfig() {
    return {
      advanced_rate_limit_enabled: document.getElementById("advancedRateLimitEnabled").checked ? 1 : 0,
      login_rate_limit_max_attempts: document.getElementById("login_rate_limit_max_attempts").value,
      login_rate_limit_block_minutes: document.getElementById("login_rate_limit_block_minutes").value,
      backend_request_limit_per_minute: document.getElementById("backend_request_limit_per_minute").value,
      backend_request_limit_per_hour: document.getElementById("backend_request_limit_per_hour").value,
      image_upload_limit_per_minute: document.getElementById("image_upload_limit_per_minute").value,
      image_upload_limit_per_hour: document.getElementById("image_upload_limit_per_hour").value,
      image_upload_mb_per_minute: document.getElementById("image_upload_mb_per_minute").value,
      image_upload_mb_per_hour: document.getElementById("image_upload_mb_per_hour").value,
      image_download_limit_per_minute: document.getElementById("image_download_limit_per_minute").value,
      image_download_limit_per_hour: document.getElementById("image_download_limit_per_hour").value,
      image_download_mb_per_minute: document.getElementById("image_download_mb_per_minute").value,
      image_download_mb_per_hour: document.getElementById("image_download_mb_per_hour").value,
    };
  }

  function applyRateLimitConfigToForm(config) {
    if (!config || typeof config !== "object") {
      return;
    }

    const mappings = {
      advancedRateLimitEnabled: "advanced_rate_limit_enabled",
      login_rate_limit_max_attempts: "login_rate_limit_max_attempts",
      login_rate_limit_block_minutes: "login_rate_limit_block_minutes",
      backend_request_limit_per_minute: "backend_request_limit_per_minute",
      backend_request_limit_per_hour: "backend_request_limit_per_hour",
      image_upload_limit_per_minute: "image_upload_limit_per_minute",
      image_upload_limit_per_hour: "image_upload_limit_per_hour",
      image_upload_mb_per_minute: "image_upload_mb_per_minute",
      image_upload_mb_per_hour: "image_upload_mb_per_hour",
      image_download_limit_per_minute: "image_download_limit_per_minute",
      image_download_limit_per_hour: "image_download_limit_per_hour",
      image_download_mb_per_minute: "image_download_mb_per_minute",
      image_download_mb_per_hour: "image_download_mb_per_hour",
    };

    Object.entries(mappings).forEach(([elementId, configKey]) => {
      const element = document.getElementById(elementId);
      if (!element || !(configKey in config)) {
        return;
      }

      if (element.type === "checkbox") {
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
      presets = JSON.parse(ui.dataset.presets || "[]");
    } catch (error) {
      presets = [];
    }

    return new Map((presets || []).map((preset) => [String(preset.id), preset]));
  }

  function getSelectedRateLimitPreset() {
    const select = document.getElementById("rateLimitPresetSelect");
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
      showErrorMessage(ui?.dataset.noSelection || translate("error"));
      return;
    }

    applyRateLimitConfigToForm(preset.config || {});
    showSuccessMessage(ui?.dataset.applyNotice || translate("success"));
  }

  function addRateLimitPresetButton() {
    const ui = getRateLimitPresetUi();
    const presetName = prompt(ui?.dataset.namePrompt || "Preset name");
    if (presetName === null) {
      return;
    }

    const normalizedName = String(presetName).trim();
    if (!normalizedName) {
      showErrorMessage(translate("error"));
      return;
    }

    adminRateLimitPostJson("endpoints/admin/ratelimitpresets.php", {
        action: "create",
        name: normalizedName,
        config: getCurrentRateLimitConfig(),
    })
      .then((data) => {
        if (!data.success) {
          throw new Error(data.message || translate("error"));
        }

        showSuccessMessage(data.message);
        window.location.reload();
      })
      .catch((error) => showErrorMessage(normalizeAdminRateLimitError(error)));
  }

  function saveRateLimitPresetButton() {
    const ui = getRateLimitPresetUi();
    const preset = getSelectedRateLimitPreset();
    if (!preset) {
      showErrorMessage(ui?.dataset.noSelection || translate("error"));
      return;
    }

    adminRateLimitPostJson("endpoints/admin/ratelimitpresets.php", {
        action: "update",
        preset_id: preset.id,
        config: getCurrentRateLimitConfig(),
    })
      .then((data) => {
        if (!data.success) {
          throw new Error(data.message || translate("error"));
        }

        showSuccessMessage(data.message);
        window.location.reload();
      })
      .catch((error) => showErrorMessage(normalizeAdminRateLimitError(error)));
  }

  function deleteRateLimitPresetButton() {
    const ui = getRateLimitPresetUi();
    const preset = getSelectedRateLimitPreset();
    if (!preset) {
      showErrorMessage(ui?.dataset.noSelection || translate("error"));
      return;
    }

    if (!confirm(ui?.dataset.deleteConfirm || "Delete this preset?")) {
      return;
    }

    adminRateLimitPostJson("endpoints/admin/ratelimitpresets.php", {
        action: "delete",
        preset_id: preset.id,
    })
      .then((data) => {
        if (!data.success) {
          throw new Error(data.message || translate("error"));
        }

        showSuccessMessage(data.message);
        window.location.reload();
      })
      .catch((error) => showErrorMessage(normalizeAdminRateLimitError(error)));
  }

  window.WallosAdminRateLimit = {
    saveSecuritySettingsButton,
    getRateLimitPresetUi,
    getCurrentRateLimitConfig,
    applyRateLimitConfigToForm,
    getRateLimitPresetsMap,
    getSelectedRateLimitPreset,
    applyRateLimitPresetButton,
    addRateLimitPresetButton,
    saveRateLimitPresetButton,
    deleteRateLimitPresetButton,
  };
})();
