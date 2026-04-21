(function () {
  const SUBSCRIPTION_PREFERENCES_ENDPOINT = "endpoints/settings/subscription_preferences.php";

  let displayColumns = 1;
  let imageLayoutPreferences = {
    form: "focus",
    detail: "focus",
  };
  let valueVisibility = {
    metrics: true,
    payment_records: true,
  };
  let preferencesSaveTimer = null;
  let shouldReloadAfterSave = false;
  let pendingReloadPreferenceSave = false;
  let bindMasonryImageEventsHandler = null;
  let scheduleMasonryLayoutHandler = null;

  function normalizeDisplayColumns(value) {
    const columns = Number(value);
    return columns === 2 || columns === 3 ? columns : 1;
  }

  function normalizeImageLayout(value) {
    return value === "grid" ? "grid" : "focus";
  }

  function normalizeValueVisibility(value) {
    const visibility = value && typeof value === "object" ? value : {};
    return {
      metrics: visibility.metrics !== false,
      payment_records: visibility.payment_records !== false,
    };
  }

  function updatePreferencesCache() {
    window.subscriptionPagePreferences = {
      displayColumns: displayColumns,
      valueVisibility: { ...valueVisibility },
      imageLayout: {
        form: imageLayoutPreferences.form,
        detail: imageLayoutPreferences.detail,
      },
    };
  }

  function setPendingReloadControls(disabled) {
    document.querySelectorAll(
      '[data-subscription-action="set-display-columns"], [data-subscription-action="toggle-value-metric"]'
    ).forEach((button) => {
      button.disabled = disabled;
      button.setAttribute("aria-disabled", disabled ? "true" : "false");
    });
  }

  function persistPreferences() {
    return window.WallosApi.postJson(SUBSCRIPTION_PREFERENCES_ENDPOINT, {
        display_columns: displayColumns,
        value_visibility: valueVisibility,
        image_layout_form: imageLayoutPreferences.form,
        image_layout_detail: imageLayoutPreferences.detail,
      }, {
        fallbackErrorMessage: translate("error"),
      });
  }

  function scheduleSave(options = {}) {
    updatePreferencesCache();

    if (options.reload === true) {
      shouldReloadAfterSave = true;
    }

    if (preferencesSaveTimer !== null) {
      window.clearTimeout(preferencesSaveTimer);
    }

    const runSave = () => {
      preferencesSaveTimer = null;

      persistPreferences()
        .then((data) => {
          if (!data?.success) {
            throw new Error(data?.message || translate("error"));
          }

          if (data?.success && shouldReloadAfterSave) {
            shouldReloadAfterSave = false;
            window.location.reload();
            return;
          }

          pendingReloadPreferenceSave = false;
          setPendingReloadControls(false);
        })
        .catch((error) => {
          console.error("Failed to persist subscription page preferences.", error);
          shouldReloadAfterSave = false;
          pendingReloadPreferenceSave = false;
          setPendingReloadControls(false);
          showErrorMessage(window.WallosApi?.normalizeError?.(error, translate("error")) || translate("error"));
        });
    };

    if (options.immediate === true) {
      runSave();
      return;
    }

    preferencesSaveTimer = window.setTimeout(runSave, 160);
  }

  function loadValueVisibility(preferences = null) {
    valueVisibility = normalizeValueVisibility(
      preferences?.valueVisibility ?? window.subscriptionPagePreferences?.valueVisibility
    );
  }

  function getImageLayoutMode(scope) {
    return normalizeImageLayout(imageLayoutPreferences[scope]);
  }

  function getImageGalleryTargets(scope) {
    if (scope === "form") {
      return Array.from(document.querySelectorAll("#detail-image-gallery"));
    }

    if (scope === "detail") {
      return Array.from(document.querySelectorAll(".subscription-media-gallery"));
    }

    return [];
  }

  function updateImageLayoutButtons(scope, mode) {
    document.querySelectorAll(`.media-layout-toggle[data-image-layout-scope="${scope}"] .media-layout-button`).forEach((button) => {
      const isActive = button.dataset.mode === mode;
      button.classList.toggle("is-active", isActive);
      button.setAttribute("aria-pressed", isActive ? "true" : "false");
    });
  }

  function applyImageLayoutMode(scope, mode = null) {
    const resolvedMode = mode || getImageLayoutMode(scope);
    getImageGalleryTargets(scope).forEach((gallery) => {
      gallery.classList.remove("layout-focus", "layout-grid");
      gallery.classList.add(`layout-${resolvedMode}`);
    });
    updateImageLayoutButtons(scope, resolvedMode);
  }

  function setImageLayoutMode(scope, mode, button = null) {
    const resolvedMode = mode === "grid" ? "grid" : "focus";
    if (scope in imageLayoutPreferences) {
      imageLayoutPreferences[scope] = resolvedMode;
      scheduleSave();
    }

    applyImageLayoutMode(scope, resolvedMode);

    if (button) {
      button.blur();
    }
  }

  function applyAllImageLayoutModes() {
    applyImageLayoutMode("form");
    applyImageLayoutMode("detail");
  }

  function getDisplayColumns() {
    return normalizeDisplayColumns(displayColumns);
  }

  function updateDisplayColumnButtons(columns) {
    document.querySelectorAll(".subscription-column-toggle .media-layout-button").forEach((button) => {
      const isActive = Number(button.dataset.subscriptionColumns) === columns;
      button.classList.toggle("is-active", isActive);
      button.setAttribute("aria-pressed", isActive ? "true" : "false");
    });
  }

  function applyDisplayColumns(columns = null) {
    const container = document.querySelector("#subscriptions");
    const resolvedColumns = Number(columns) === 2 || Number(columns) === 3 ? Number(columns) : getDisplayColumns();

    if (!container) {
      updateDisplayColumnButtons(resolvedColumns);
      return;
    }

    container.classList.add("subscription-columns");
    container.classList.toggle("subscription-columns-1", resolvedColumns === 1);
    container.classList.toggle("subscription-columns-2", resolvedColumns === 2);
    container.classList.toggle("subscription-columns-3", resolvedColumns === 3);
    container.classList.toggle("subscription-columns-multi", resolvedColumns > 1);
    updateDisplayColumnButtons(resolvedColumns);

    if (typeof bindMasonryImageEventsHandler === "function") {
      bindMasonryImageEventsHandler();
    }

    if (typeof scheduleMasonryLayoutHandler === "function") {
      scheduleMasonryLayoutHandler();
    }
  }

  function setDisplayColumns(columns, button = null) {
    if (pendingReloadPreferenceSave) {
      return;
    }

    displayColumns = normalizeDisplayColumns(columns);
    pendingReloadPreferenceSave = true;
    setPendingReloadControls(true);
    scheduleSave({ reload: true, immediate: true });

    if (button) {
      button.blur();
    }
  }

  function applyValueVisibility() {
    const container = document.getElementById("subscriptions");
    if (container) {
      container.classList.toggle("hide-cost-value-metrics", !valueVisibility.metrics);
      container.classList.toggle("hide-payment-records", !valueVisibility.payment_records);
    }

    document.querySelectorAll("[data-subscription-value-toggle]").forEach((button) => {
      const metricKey = button.getAttribute("data-subscription-value-toggle");
      const visible = !!valueVisibility[metricKey];
      button.classList.toggle("is-active", visible);
      button.setAttribute("aria-pressed", visible ? "true" : "false");
    });
  }

  function toggleValueMetric(metricKey) {
    if (!(metricKey in valueVisibility)) {
      return;
    }

    if (pendingReloadPreferenceSave) {
      return;
    }

    valueVisibility[metricKey] = !valueVisibility[metricKey];
    pendingReloadPreferenceSave = true;
    setPendingReloadControls(true);
    scheduleSave({ reload: true, immediate: true });
  }

  function initialize(options = {}) {
    if (typeof options.bindMasonryImageEvents === "function") {
      bindMasonryImageEventsHandler = options.bindMasonryImageEvents;
    }

    if (typeof options.scheduleMasonryLayout === "function") {
      scheduleMasonryLayoutHandler = options.scheduleMasonryLayout;
    }

    const preferences = options.preferences ?? window.subscriptionPagePreferences ?? {};
    displayColumns = normalizeDisplayColumns(preferences.displayColumns);
    imageLayoutPreferences = {
      form: normalizeImageLayout(preferences?.imageLayout?.form),
      detail: normalizeImageLayout(preferences?.imageLayout?.detail),
    };
    loadValueVisibility(preferences);
    updatePreferencesCache();
  }

  window.WallosSubscriptionPreferences = {
    initialize,
    normalizeDisplayColumns,
    normalizeImageLayout,
    normalizeValueVisibility,
    updatePreferencesCache,
    scheduleSave,
    loadValueVisibility,
    applyValueVisibility,
    toggleValueMetric,
    getImageLayoutMode,
    applyImageLayoutMode,
    setImageLayoutMode,
    applyAllImageLayoutModes,
    getDisplayColumns,
    applyDisplayColumns,
    setDisplayColumns,
  };
})();
