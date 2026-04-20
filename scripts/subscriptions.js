let isSortOptionsOpen = false;
let scrollTopBeforeOpening = 0;
const shouldScroll = window.innerWidth <= 768;
let currentPaymentHistorySubscriptionId = 0;
let currentPaymentHistorySubscriptionName = "";
let currentPaymentHistoryRecords = [];
let currentPaymentHistorySummary = {};
let currentPaymentHistoryCashflow = [];
let currentPaymentHistoryForecast = [];
let currentPaymentHistoryAvailableYears = [];
let currentPaymentHistoryTab = "records";
let currentPaymentHistoryYear = new Date().getFullYear();
let currentPaymentHistoryRangeMonths = 12;
let reopenPaymentHistoryAfterPaymentModalClose = false;
let currentPaymentModalSubscription = null;
let currentPaymentModalMode = "create";

function toggleOpenSubscription(subId) {
  const subscriptionElement = document.querySelector('.subscription[data-id="' + subId + '"]');
  subscriptionElement.classList.toggle('is-open');
  scheduleSubscriptionMasonryLayout();
}

function toggleSortOptions() {
  const sortOptions = document.querySelector("#sort-options");
  sortOptions.classList.toggle("is-open");
  isSortOptionsOpen = !isSortOptionsOpen;
}

function toggleNotificationDays() {
  const notifyCheckbox = document.querySelector("#notifications");
  const notifyDaysBefore = document.querySelector("#notify_days_before");
  notifyDaysBefore.disabled = !notifyCheckbox.checked;
}

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function normalizeSubscriptionRequestError(error, fallbackMessage = null) {
  if (window.WallosHttp?.normalizeError) {
    return window.WallosHttp.normalizeError(error, fallbackMessage || translate("unknown_error"));
  }

  if (error instanceof Error && String(error.message || "").trim() !== "") {
    return error.message.trim();
  }

  return fallbackMessage || translate("unknown_error");
}

function getSubscriptionPageStrings() {
  return window.WallosSubscriptionPages?.getStrings?.() || {
    pagesTitle: window.subscriptionPageStrings?.pagesTitle || "Subscription Pages",
    manage: window.subscriptionPageStrings?.manage || "Manage Pages",
    all: window.subscriptionPageStrings?.all || "All",
    unassigned: window.subscriptionPageStrings?.unassigned || "Unassigned",
    fieldLabel: window.subscriptionPageStrings?.fieldLabel || "Subscription Page",
    add: window.subscriptionPageStrings?.add || "Add Page",
    empty: window.subscriptionPageStrings?.empty || "No custom pages yet. Create one above.",
    namePlaceholder: window.subscriptionPageStrings?.namePlaceholder || "New page name",
    deleteConfirm: window.subscriptionPageStrings?.deleteConfirm || "Delete this page now? Subscriptions inside it will move to Unassigned.",
    saveAction: window.subscriptionPageStrings?.saveAction || "Save Name",
    deleteAction: window.subscriptionPageStrings?.deleteAction || "Delete Page",
    manageHint: window.subscriptionPageStrings?.manageHint || "After editing a page name, click \"Save Name\". Deleting a page only moves subscriptions back to \"Unassigned\".",
    dragHandleTitle: window.subscriptionPageStrings?.dragHandleTitle || "Drag to reorder pages",
  };
}

function normalizeSubscriptionPageFilter(value) {
  return window.WallosSubscriptionPages?.normalizeFilter?.(value) || "all";
}

function getDefaultSubscriptionPageSelection() {
  return window.WallosSubscriptionPages?.getDefaultSelection?.() || "";
}

function getCurrentSubscriptionPageFilter() {
  return window.WallosSubscriptionPages?.getCurrentFilter?.() || "all";
}

function updateSubscriptionPageFilterUrl() {
  return window.WallosSubscriptionPages?.setFilterValue?.(getCurrentSubscriptionPageFilter(), {
    fetch: false,
    updateUrl: true,
  });
}

function setSubscriptionPageFilterValue(filterValue, options = {}) {
  return window.WallosSubscriptionPages?.setFilterValue?.(filterValue, options);
}

function selectSubscriptionPageFilter(filterValue) {
  return window.WallosSubscriptionPages?.selectFilter?.(filterValue);
}

function renderSubscriptionPageTabs() {
  window.WallosSubscriptionPages?.renderTabs?.();
}

function renderSubscriptionPageSelectOptions(selectedValue = null) {
  window.WallosSubscriptionPages?.renderSelectOptions?.(selectedValue);
}

function renderSubscriptionPagesManagerList() {
  window.WallosSubscriptionPages?.renderManagerList?.();
}

function applySubscriptionPagesPayload(payload, options = {}) {
  return window.WallosSubscriptionPages?.applyPayload?.(payload, options);
}

function refreshSubscriptionPages(options = {}) {
  return window.WallosSubscriptionPages?.refresh?.(options);
}

function openSubscriptionPagesManager(event) {
  window.WallosSubscriptionPages?.openManager?.(event);
}

function closeSubscriptionPagesManager() {
  window.WallosSubscriptionPages?.closeManager?.();
}

function createSubscriptionPage() {
  window.WallosSubscriptionPages?.createPage?.();
}

function renameSubscriptionPage(pageId, button = null) {
  window.WallosSubscriptionPages?.renamePage?.(pageId, button);
}

function deleteSubscriptionPage(pageId) {
  window.WallosSubscriptionPages?.deletePage?.(pageId);
}

function normalizeSubscriptionDisplayColumnsPreference(value) {
  return window.WallosSubscriptionPreferences?.normalizeDisplayColumns?.(value) || 1;
}

function normalizeSubscriptionImageLayoutPreference(value) {
  return window.WallosSubscriptionPreferences?.normalizeImageLayout?.(value) || "focus";
}

function normalizeSubscriptionValueVisibilityPreference(value) {
  return window.WallosSubscriptionPreferences?.normalizeValueVisibility?.(value) || {
    metrics: true,
    payment_records: true,
  };
}

function updateSubscriptionPagePreferencesCache() {
  window.WallosSubscriptionPreferences?.updatePreferencesCache?.();
}

function scheduleSubscriptionPagePreferencesSave() {
  window.WallosSubscriptionPreferences?.scheduleSave?.();
}

function loadSubscriptionValueVisibility() {
  window.WallosSubscriptionPreferences?.loadValueVisibility?.();
}

function persistSubscriptionValueVisibility() {
  scheduleSubscriptionPagePreferencesSave();
}

function applySubscriptionValueVisibility() {
  window.WallosSubscriptionPreferences?.applyValueVisibility?.();
}

function toggleSubscriptionValueMetric(metricKey) {
  window.WallosSubscriptionPreferences?.toggleValueMetric?.(metricKey);
}

function createSubscriptionPriceRuleTempId() {
  return window.WallosSubscriptionPriceRules?.createTempId?.() || `price-rule-${Date.now()}`;
}

function normalizeSubscriptionPriceRule(rule = {}, index = 0) {
  return window.WallosSubscriptionPriceRules?.normalizeRule?.(rule, index) || rule;
}

function getSubscriptionImageLayoutMode(scope) {
  return window.WallosSubscriptionPreferences?.getImageLayoutMode?.(scope) || "focus";
}

function getSubscriptionImageGalleryTargets(scope) {
  if (scope === "form") {
    return Array.from(document.querySelectorAll("#detail-image-gallery"));
  }

  if (scope === "detail") {
    return Array.from(document.querySelectorAll(".subscription-media-gallery"));
  }

  return [];
}

function updateSubscriptionImageLayoutButtons(scope, mode) {
  return window.WallosSubscriptionPreferences?.applyImageLayoutMode?.(scope, mode);
}

function applySubscriptionImageLayoutMode(scope, mode = null) {
  return window.WallosSubscriptionPreferences?.applyImageLayoutMode?.(scope, mode);
}

function setSubscriptionImageLayoutMode(scope, mode, button = null) {
  return window.WallosSubscriptionPreferences?.setImageLayoutMode?.(scope, mode, button);
}

function applyAllSubscriptionImageLayoutModes() {
  window.WallosSubscriptionPreferences?.applyAllImageLayoutModes?.();
}

function getSubscriptionDisplayColumns() {
  return window.WallosSubscriptionPreferences?.getDisplayColumns?.() || 1;
}

function getSubscriptionOccurrenceIndexForDueDate(subscription, dueDate) {
  return window.WallosSubscriptionPriceRules?.getOccurrenceIndexForDueDate?.(subscription, dueDate) ?? null;
}

function doesSubscriptionPriceRuleMatch(rule, subscription, dueDate) {
  return !!window.WallosSubscriptionPriceRules?.doesRuleMatch?.(rule, subscription, dueDate);
}

function getEffectiveSubscriptionPaymentRule(subscription, dueDate) {
  return window.WallosSubscriptionPriceRules?.getEffectiveRule?.(subscription, dueDate) || null;
}

function applyPaymentRulePreviewForDueDate(dueDate) {
  window.WallosSubscriptionPriceRules?.applyPaymentPreview?.(currentPaymentModalSubscription, currentPaymentModalMode, dueDate);
}

function updateSubscriptionDisplayColumnButtons(columns) {
  return window.WallosSubscriptionPreferences?.applyDisplayColumns?.(columns);
}

function applySubscriptionDisplayColumns(columns = null) {
  return window.WallosSubscriptionPreferences?.applyDisplayColumns?.(columns);
}

function setSubscriptionDisplayColumns(columns, button = null) {
  return window.WallosSubscriptionPreferences?.setDisplayColumns?.(columns, button);
}

function bindSubscriptionMasonryImageEvents() {
  document.querySelectorAll("#subscriptions img").forEach((image) => {
    if (image.dataset.subscriptionMasonryBound === "1") {
      return;
    }

    image.dataset.subscriptionMasonryBound = "1";
    image.addEventListener("load", scheduleSubscriptionMasonryLayout);
    image.addEventListener("error", scheduleSubscriptionMasonryLayout);
  });
}

function applySubscriptionMasonryLayout() {
  return window.WallosSubscriptionLayout?.applySubscriptionMasonryLayout?.();
}

function scheduleSubscriptionMasonryLayout() {
  return window.WallosSubscriptionLayout?.scheduleSubscriptionMasonryLayout?.();
}

function handleSubscriptionMasonryResize() {
  return window.WallosSubscriptionLayout?.handleSubscriptionMasonryResize?.();
}

function getCurrentSubscriptionSortOrder() {
  const rawValue = getCookie("sortOrder");
  return rawValue ? decodeURIComponent(rawValue) : "manual_order";
}

function hasActiveSubscriptionFilters() {
  return window.WallosSubscriptionInteractions?.hasActiveFilters?.(activeFilters) || false;
}

function canReorderSubscriptions() {
  const searchInput = document.querySelector("#search");
  const searchTerm = searchInput?.value.trim() || "";
  const currentSort = getCurrentSubscriptionSortOrder();
  const isReorderSort = currentSort === "manual_order" || currentSort === "next_payment";

  return isReorderSort && searchTerm === "" && !hasActiveSubscriptionFilters();
}

function updateSubscriptionReorderState() {
  return window.WallosSubscriptionLayout?.updateSubscriptionReorderState?.();
}

function setSubscriptionSortCookie(sortOption) {
  const expirationDate = new Date();
  expirationDate.setDate(expirationDate.getDate() + 30);
  document.cookie = `sortOrder=${encodeURIComponent(sortOption)}; expires=${expirationDate.toUTCString()}; path=/; SameSite=Lax`;
}

function persistManualSubscriptionSortPreference() {
  if (getCurrentSubscriptionSortOrder() === "manual_order") {
    updateSubscriptionReorderState();
    return;
  }

  setSubscriptionSortCookie("manual_order");
  updateSortOptionSelection("manual_order");
  updateSubscriptionReorderState();
}

function updateSortOptionSelection(sortOption) {
  const sortOptionsContainer = document.querySelector("#sort-options");
  if (!sortOptionsContainer) {
    return;
  }

  sortOptionsContainer.querySelectorAll("li").forEach((option) => {
    option.classList.toggle("selected", option.getAttribute("id") === `sort-${sortOption}`);
  });
}

function persistSubscriptionOrder() {
  const container = document.querySelector("#subscriptions");
  if (!container) {
    return;
  }

  const subscriptionIds = Array.from(container.querySelectorAll(".subscription-container[data-id]"))
    .filter((item) => window.getComputedStyle(item).display !== "none")
    .map((item) => Number(item.dataset.id || 0))
    .filter((subscriptionId) => subscriptionId > 0);

  if (subscriptionIds.length < 2) {
    return;
  }

  window.WallosHttp.postJson("endpoints/subscription/reordersubscriptions.php", {
      subscriptionIds,
    })
    .then((data) => {
      if (!data.success) {
        showErrorMessage(data.message || translate("error"));
        return;
      }

      persistManualSubscriptionSortPreference();
    })
    .catch(() => showErrorMessage(translate("error")));
}

function initializeSubscriptionCardSortable() {
  return window.WallosSubscriptionLayout?.initializeSubscriptionCardSortable?.();
}

function getDetailImageConfig() {
  return window.WallosSubscriptionMedia?.getDetailImageConfig?.() || {
    canUpload: false,
    compressionMode: "disabled",
    maxBytes: 0,
    maxMb: 0,
    uploadLimit: null,
    externalUrlLimit: 0,
    allowedExtensions: "",
    tooLargeMessage: translate("unknown_error"),
    invalidTypeMessage: translate("unknown_error"),
    uploadBlockedMessage: translate("unknown_error"),
    uploadLimitMessage: translate("unknown_error"),
  };
}

function setDetailImageUploadProgress(percentage, label) {
  window.WallosSubscriptionMedia?.setUploadProgress?.(percentage, label);
}

function hideDetailImageUploadProgress() {
  window.WallosSubscriptionMedia?.hideUploadProgress?.();
}

function resetDetailImageCompression() {
  window.WallosSubscriptionMedia?.resetCompression?.();
}

function initializeSubscriptionMediaSortables() {
  window.WallosSubscriptionMedia?.initializeSubscriptionMediaSortables?.();
}

function getUploadedImageDisplayName(image) {
  return String(image?.original_name || image?.file_name || "").trim() || translate("subscription_image_source_server");
}

function buildFormDetailImageViewerItems() {
  return window.WallosSubscriptionMedia?.buildFormViewerItems?.() || [];
}

function mountSubscriptionOverlayToBody(selector) {
  const overlay = document.querySelector(selector);
  if (!overlay || overlay.dataset.mountedToBody === "1" || !document.body) {
    return;
  }

  document.body.appendChild(overlay);
  overlay.dataset.mountedToBody = "1";
}

function resetSubscriptionPaymentForm() {
  return window.WallosSubscriptionPayments?.resetSubscriptionPaymentForm?.();
}

function getOpenSubscriptionIds() {
  return Array.from(document.querySelectorAll(".subscription.is-open"))
    .map((element) => Number(element.dataset.id || 0))
    .filter((id) => id > 0);
}

function reopenSubscriptionCards(subscriptionIds = []) {
  subscriptionIds.forEach((id) => {
    const subscriptionElement = document.querySelector(`.subscription[data-id="${id}"]`);
    if (subscriptionElement && !subscriptionElement.classList.contains("is-open")) {
      subscriptionElement.classList.add("is-open");
    }
  });

  if (subscriptionIds.length > 0) {
    scheduleSubscriptionMasonryLayout();
  }
}

function refreshSubscriptionsPreservingState(options = {}) {
  const openSubscriptionIds = Array.isArray(options.openSubscriptionIds) && options.openSubscriptionIds.length > 0
    ? options.openSubscriptionIds
    : getOpenSubscriptionIds();

  return fetchSubscriptions(null, null, options.initiator || "refresh")
    .then(() => {
      reopenSubscriptionCards(openSubscriptionIds);

      if (options.reopenHistory && Number(options.subscriptionId || 0) > 0) {
        if (options.historyTab) {
          currentPaymentHistoryTab = options.historyTab;
        }
        if (options.historyYear) {
          currentPaymentHistoryYear = Number(options.historyYear);
        }
        if (options.historyRangeMonths) {
          currentPaymentHistoryRangeMonths = Number(options.historyRangeMonths);
        }
        return openSubscriptionPaymentHistoryModal(null, Number(options.subscriptionId || 0), { preserveTab: true, preserveFilters: true });
      }

      return null;
    });
}

function closeSubscriptionPaymentModal(options = {}) {
  const modal = document.getElementById("subscription-payment-modal");
  if (!modal) {
    return;
  }

  modal.classList.remove("is-open");
  const historyModal = document.getElementById("subscription-payment-history-modal");
  if (!historyModal || !historyModal.classList.contains("is-open")) {
    document.body.classList.remove('no-scroll');
  }
  resetSubscriptionPaymentForm();

  if (!options.skipReopenHistory && reopenPaymentHistoryAfterPaymentModalClose && currentPaymentHistorySubscriptionId > 0) {
    reopenPaymentHistoryAfterPaymentModalClose = false;
    const historyModalElement = document.getElementById("subscription-payment-history-modal");
    if (historyModalElement) {
      historyModalElement.classList.add("is-open");
      document.body.classList.add('no-scroll');
      renderSubscriptionPaymentHistoryModal();
    }
    return;
  }

  reopenPaymentHistoryAfterPaymentModalClose = false;
}

function fillSubscriptionPaymentForm(subscription) {
  return window.WallosSubscriptionPayments?.fillSubscriptionPaymentForm?.(subscription);
}

function fillSubscriptionPaymentFormFromRecord(subscriptionId, subscriptionName, record) {
  return window.WallosSubscriptionPayments?.fillSubscriptionPaymentFormFromRecord?.(subscriptionId, subscriptionName, record);
}

function openSubscriptionPaymentModal(event, id) {
  return window.WallosSubscriptionPayments?.openSubscriptionPaymentModal?.(event, id);
}

function closeSubscriptionPaymentHistoryModal() {
  return window.WallosSubscriptionPayments?.closeSubscriptionPaymentHistoryModal?.();
}

function openSubscriptionRecycleBinModal(event = null) {
  if (event) {
    event.stopPropagation();
    event.preventDefault();
  }

  const modal = document.getElementById("subscription-recycle-bin-modal");
  if (!modal) {
    return;
  }

  modal.classList.add("is-open");
  document.body.classList.add('no-scroll');
}

function closeSubscriptionRecycleBinModal() {
  const modal = document.getElementById("subscription-recycle-bin-modal");
  if (!modal) {
    return;
  }

  modal.classList.remove("is-open");
  document.body.classList.remove('no-scroll');
}

function formatSubscriptionPaymentHistoryAmount(value, currencyCode) {
  return window.WallosSubscriptionPayments?.formatSubscriptionPaymentHistoryAmount?.(value, currencyCode)
    || new Intl.NumberFormat(navigator.language).format(Number(value || 0));
}

function sanitizeSubscriptionPaymentHistoryFilenamePart(value) {
  return String(value || "")
    .trim()
    .replace(/[\\/:*?"<>|]+/g, "-")
    .replace(/\s+/g, "-")
    .replace(/-+/g, "-")
    .replace(/^-|-$/g, "") || "subscription";
}

function escapeSubscriptionPaymentHistoryCsv(value) {
  const normalized = String(value ?? "").replace(/\r?\n/g, " ").trim();
  if (/[",\n]/.test(normalized)) {
    return `"${normalized.replace(/"/g, '""')}"`;
  }

  return normalized;
}

function buildSubscriptionPaymentHistoryCsv(rows) {
  if (!rows.length) {
    return "";
  }

  const headers = Object.keys(rows[0]);
  const body = rows.map((row) => headers.map((header) => escapeSubscriptionPaymentHistoryCsv(row[header])).join(","));
  return [headers.join(","), ...body].join("\n");
}

function downloadSubscriptionPaymentHistoryTextFile(content, filename, mimeType) {
  const blob = new Blob([content], { type: mimeType });
  const blobUrl = window.URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = blobUrl;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  window.URL.revokeObjectURL(blobUrl);
}

function getSubscriptionPaymentHistoryExportPayload() {
  const subscriptionName = currentPaymentHistorySubscriptionName || "subscription";
  const baseName = sanitizeSubscriptionPaymentHistoryFilenamePart(subscriptionName);
  const yearSuffix = currentPaymentHistoryYear ? `-${currentPaymentHistoryYear}` : "";
  const rangeSuffix = currentPaymentHistoryRangeMonths ? `-${currentPaymentHistoryRangeMonths}m` : "";

  if (currentPaymentHistoryTab === "cashflow") {
    return {
      view: "cashflow",
      filenameBase: `${baseName}-cashflow${yearSuffix}`,
      rows: currentPaymentHistoryCashflow.map((row) => ({
        month_number: row.month_number,
        actual_total: row.actual_total,
        predicted_total: row.predicted_total,
        total: row.total,
      })),
      json: {
        subscription_id: currentPaymentHistorySubscriptionId,
        subscription_name: currentPaymentHistorySubscriptionName,
        view: "cashflow",
        selected_year: currentPaymentHistoryYear,
        summary: currentPaymentHistorySummary,
        rows: currentPaymentHistoryCashflow,
      },
    };
  }

  if (currentPaymentHistoryTab === "forecast") {
    return {
      view: "forecast",
      filenameBase: `${baseName}-forecast${rangeSuffix}`,
      rows: currentPaymentHistoryForecast.map((item) => ({
        due_date: item.due_date,
        amount_original: item.amount_original,
        currency_code: item.currency_code,
        amount_main: item.amount_main,
        main_currency_code: item.main_currency_code,
        rule_summary: item.rule_summary || "",
      })),
      json: {
        subscription_id: currentPaymentHistorySubscriptionId,
        subscription_name: currentPaymentHistorySubscriptionName,
        view: "forecast",
        selected_range_months: currentPaymentHistoryRangeMonths,
        summary: currentPaymentHistorySummary,
        rows: currentPaymentHistoryForecast,
      },
    };
  }

  return {
    view: "ledger",
    filenameBase: `${baseName}-ledger`,
    rows: currentPaymentHistoryRecords.map((record) => ({
      due_date: record.due_date,
      paid_at: record.paid_at,
      amount_original: record.amount_original,
      currency_code_snapshot: record.currency_code_snapshot,
      amount_main_snapshot: record.amount_main_snapshot,
      main_currency_code_snapshot: record.main_currency_code_snapshot,
      expected_amount_main: record.expected_amount_main,
      ledger_difference_main: record.ledger_difference_main,
      rule_summary_current: record.rule_summary_current || "",
      note: record.note || "",
    })),
    json: {
      subscription_id: currentPaymentHistorySubscriptionId,
      subscription_name: currentPaymentHistorySubscriptionName,
      view: "ledger",
      summary: currentPaymentHistorySummary,
      rows: currentPaymentHistoryRecords,
    },
  };
}

function exportSubscriptionPaymentHistoryCurrentView(format) {
  return window.WallosSubscriptionPayments?.exportSubscriptionPaymentHistoryCurrentView?.(format);
}

function getSubscriptionPaymentHistorySummaryHtml() {
  const summary = currentPaymentHistorySummary || {};
  const currencyCode =
    currentPaymentHistorySummary?.remaining_value?.main_currency_code ||
    currentPaymentHistoryRecords[0]?.main_currency_code_snapshot ||
    currentPaymentHistoryForecast[0]?.main_currency_code ||
    '';
  const remainingValue = currentPaymentHistorySummary?.remaining_value || {};
  const summaryCards = [
    {
      label: translate('subscription_invested_total'),
      value: formatSubscriptionPaymentHistoryAmount(summary.invested_total || 0, currencyCode),
    },
    {
      label: translate('subscription_payment_summary_actual_this_year'),
      value: formatSubscriptionPaymentHistoryAmount(summary.actual_this_year_total || 0, currencyCode),
    },
    {
      label: translate('subscription_payment_summary_predicted_remaining'),
      value: formatSubscriptionPaymentHistoryAmount(summary.predicted_remaining_total || 0, currencyCode),
    },
    {
      label: translate('subscription_payment_summary_projected_total'),
      value: formatSubscriptionPaymentHistoryAmount(summary.projected_total || 0, currencyCode),
    },
    {
      label: translate('subscription_payment_summary_record_count'),
      value: new Intl.NumberFormat(navigator.language).format(Number(summary.record_count || 0)),
    },
  ];

  if (remainingValue.available) {
    summaryCards.push({
      label: translate('subscription_remaining_value'),
      value: formatSubscriptionPaymentHistoryAmount(remainingValue.remaining_value_main || 0, currencyCode),
    });
  }

  return `
    <div class="subscription-payment-history-summary">
      ${summaryCards.map((card) => `
        <article class="subscription-payment-history-summary-card">
          <span>${escapeHtml(card.label)}</span>
          <strong>${escapeHtml(card.value)}</strong>
        </article>
      `).join('')}
    </div>
    <div class="subscription-payment-history-notes">
      <div class="subscription-payment-history-note">
        <i class="fa-solid fa-circle-info"></i>
        <span>${escapeHtml(translate('subscription_payment_ledger_notice'))}</span>
      </div>
      <div class="subscription-payment-history-note">
        <i class="fa-solid fa-shuffle"></i>
        <span>${escapeHtml(translate('subscription_payment_rule_replay_notice'))}</span>
      </div>
      ${remainingValue.available ? `
        <div class="subscription-payment-history-note">
          <i class="fa-solid fa-hourglass-half"></i>
          <span>${escapeHtml(`${translate('subscription_remaining_value')}: ${formatSubscriptionPaymentHistoryAmount(remainingValue.remaining_value_main || 0, currencyCode)} | ${translate('subscription_remaining_value_days_inline').replace('%1$s', String(remainingValue.remaining_days || 0)).replace('%2$s', String(remainingValue.total_days || 0)).replace('%3$s', new Intl.NumberFormat(navigator.language, { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(Number(remainingValue.remaining_ratio || 0)))} | ${remainingValue.value_source_summary || ''}`)}</span>
        </div>
      ` : ''}
    </div>
  `;
}

function getSubscriptionPaymentHistoryTabsHtml() {
  const tabs = [
    { id: 'records', label: translate('subscription_payment_history_tab_records'), icon: 'fa-clock-rotate-left' },
    { id: 'cashflow', label: translate('subscription_payment_history_tab_cashflow'), icon: 'fa-chart-column' },
    { id: 'forecast', label: translate('subscription_payment_history_tab_forecast'), icon: 'fa-wand-magic-sparkles' },
  ];

  return `
    <div class="subscription-payment-history-tabs">
      ${tabs.map((tab) => `
        <button type="button"
          class="subscription-payment-history-tab ${currentPaymentHistoryTab === tab.id ? 'is-active' : ''}"
          data-subscription-action="set-payment-history-tab"
          data-payment-history-tab="${escapeHtml(tab.id)}">
          <i class="fa-solid ${tab.icon}"></i>
          <span>${escapeHtml(tab.label)}</span>
        </button>
      `).join('')}
    </div>
  `;
}

function syncSubscriptionPaymentHistoryControls() {
  const yearSelect = document.getElementById("subscription-payment-history-year");
  const rangeSelect = document.getElementById("subscription-payment-history-range");

  if (yearSelect) {
    yearSelect.innerHTML = currentPaymentHistoryAvailableYears.map((year) => `
      <option value="${escapeHtml(year)}">${escapeHtml(year)}</option>
    `).join('');
    yearSelect.value = String(currentPaymentHistoryYear);
    yearSelect.onchange = function () {
      currentPaymentHistoryYear = Number(this.value || new Date().getFullYear());
      openSubscriptionPaymentHistoryModal(null, currentPaymentHistorySubscriptionId, { preserveTab: true, preserveFilters: true });
    };
  }

  if (rangeSelect) {
    rangeSelect.value = String(currentPaymentHistoryRangeMonths);
    rangeSelect.onchange = function () {
      currentPaymentHistoryRangeMonths = Number(this.value || 12);
      openSubscriptionPaymentHistoryModal(null, currentPaymentHistorySubscriptionId, { preserveTab: true, preserveFilters: true });
    };
  }
}

function renderSubscriptionPaymentHistoryRecordsHtml() {
  if (!currentPaymentHistoryRecords.length) {
    return `<div class="subscription-payment-record-empty">${translate('subscription_payment_history_empty')}</div>`;
  }

  return currentPaymentHistoryRecords.map((record) => {
    const noteHtml = record.note_html || "";
    const amountLabel = formatSubscriptionPaymentHistoryAmount(record.amount_original || 0, record.currency_code_snapshot || '');
    const mainAmountLabel = formatSubscriptionPaymentHistoryAmount(record.amount_main_snapshot || 0, record.main_currency_code_snapshot || '');
    const expectedAmountLabel = formatSubscriptionPaymentHistoryAmount(record.expected_amount_main || 0, record.main_currency_code_snapshot || '');
    const ledgerDifference = Number(record.ledger_difference_main || 0);
    const differencePrefix = ledgerDifference > 0 ? '+' : '';
    const differenceLabel = `${differencePrefix}${formatSubscriptionPaymentHistoryAmount(ledgerDifference, record.main_currency_code_snapshot || '')}`;

    return `
      <article class="subscription-payment-record-item">
        <div class="subscription-payment-record-topline">
          <strong>${escapeHtml(record.paid_at || '-')}</strong>
          <span>${escapeHtml(amountLabel)}</span>
        </div>
        <div class="subscription-payment-record-meta">
          <span>${escapeHtml(`${translate('subscription_payment_due_date')}: ${record.due_date || '-'}`)}</span>
          <span>${escapeHtml(`${translate('subscription_payment_main_amount')}: ${mainAmountLabel}`)}</span>
          <span>${escapeHtml(`${translate('subscription_payment_expected_amount')}: ${expectedAmountLabel}`)}</span>
          <span>${escapeHtml(`${translate('metric_explanation_rule_source')}: ${record.rule_summary_current || translate('metric_explanation_regular_price_source')}`)}</span>
          ${Math.abs(ledgerDifference) > 0.001 ? `<span>${escapeHtml(`${translate('subscription_payment_ledger_difference')}: ${differenceLabel}`)}</span>` : ''}
        </div>
        ${noteHtml ? `<div class="subscription-markdown subscription-payment-record-note">${noteHtml}</div>` : ''}
        <div class="buttons subscription-payment-record-history-actions">
          <button type="button" class="button secondary-button thin subscription-payment-history-toolbar-button"
            data-subscription-action="edit-payment-record"
            data-subscription-id="${Number(currentPaymentHistorySubscriptionId)}"
            data-record-id="${Number(record.id)}">
            <i class="fa-solid fa-pen-to-square"></i>
            <span>${translate('subscription_edit_payment')}</span>
          </button>
          <button type="button" class="button warning-button thin subscription-payment-history-toolbar-button"
            data-subscription-action="delete-payment-record"
            data-subscription-id="${Number(currentPaymentHistorySubscriptionId)}"
            data-record-id="${Number(record.id)}">
            <i class="fa-solid fa-trash"></i>
            <span>${translate('delete')}</span>
          </button>
        </div>
      </article>
    `;
  }).join('');
}

function renderSubscriptionPaymentCashflowHtml() {
  if (!currentPaymentHistoryCashflow.length) {
    return `<div class="subscription-payment-record-empty">${translate('subscription_payment_cashflow_empty')}</div>`;
  }

  const summary = currentPaymentHistorySummary || {};
  const year = Number(summary.current_year || new Date().getFullYear());
  const currencyCode =
    currentPaymentHistoryRecords[0]?.main_currency_code_snapshot ||
    currentPaymentHistoryForecast[0]?.main_currency_code ||
    '';
  const maxTotal = Math.max(...currentPaymentHistoryCashflow.map((row) => Number(row.total || 0)), 0.01);
  const actualTotal = currentPaymentHistoryCashflow.reduce((sum, row) => sum + Number(row.actual_total || 0), 0);
  const predictedTotal = currentPaymentHistoryCashflow.reduce((sum, row) => sum + Number(row.predicted_total || 0), 0);

  return `
    <div class="subscription-payment-section-intro">
      <strong>${escapeHtml(`${translate('subscription_payment_history_year_label')}: ${currentPaymentHistoryYear}`)}</strong>
      <span>${escapeHtml(`${translate('subscription_payment_cashflow_actual')}: ${formatSubscriptionPaymentHistoryAmount(actualTotal, currencyCode)} / ${translate('subscription_payment_cashflow_predicted')}: ${formatSubscriptionPaymentHistoryAmount(predictedTotal, currencyCode)}`)}</span>
    </div>
    <div class="subscription-payment-cashflow-list">
      ${currentPaymentHistoryCashflow.map((row) => {
        const actualWidth = Math.max(0, Math.min(100, (Number(row.actual_total || 0) / maxTotal) * 100));
        const predictedWidth = Math.max(0, Math.min(100 - actualWidth, (Number(row.predicted_total || 0) / maxTotal) * 100));
        const monthLabel = new Date(year, Number(row.month_number || 1) - 1, 1).toLocaleDateString(navigator.language, {
          month: 'short',
          year: 'numeric',
        });

        return `
          <article class="subscription-payment-cashflow-row">
            <div class="subscription-payment-cashflow-topline">
              <strong>${escapeHtml(monthLabel)}</strong>
              <span>${escapeHtml(formatSubscriptionPaymentHistoryAmount(row.total || 0, currencyCode))}</span>
            </div>
            <div class="subscription-payment-cashflow-bar" aria-hidden="true">
              <span class="actual" style="width: ${actualWidth}%;"></span>
              <span class="predicted" style="width: ${predictedWidth}%;"></span>
            </div>
            <div class="subscription-payment-record-meta">
              <span>${escapeHtml(`${translate('subscription_payment_cashflow_actual')}: ${formatSubscriptionPaymentHistoryAmount(row.actual_total || 0, currencyCode)}`)}</span>
              <span>${escapeHtml(`${translate('subscription_payment_cashflow_predicted')}: ${formatSubscriptionPaymentHistoryAmount(row.predicted_total || 0, currencyCode)}`)}</span>
            </div>
          </article>
        `;
      }).join('')}
    </div>
  `;
}

function renderSubscriptionPaymentForecastHtml() {
  if (!currentPaymentHistoryForecast.length) {
    return `<div class="subscription-payment-record-empty">${translate('subscription_payment_forecast_empty')}</div>`;
  }

  return `
    <div class="subscription-payment-section-intro">
      <strong>${escapeHtml(`${translate('subscription_payment_history_range_label')}: ${currentPaymentHistoryRangeMonths} ${translate('months')}`)}</strong>
      <span>${escapeHtml(translate('subscription_payment_forecast_range_notice'))}</span>
    </div>
    ${currentPaymentHistoryForecast.map((item) => {
    const amountLabel = formatSubscriptionPaymentHistoryAmount(item.amount_main || 0, item.main_currency_code || '');
    const originalAmountLabel = formatSubscriptionPaymentHistoryAmount(item.amount_original || 0, item.currency_code || '');

    return `
      <article class="subscription-payment-record-item">
        <div class="subscription-payment-record-topline">
          <strong>${escapeHtml(item.due_date || '-')}</strong>
          <span>${escapeHtml(amountLabel)}</span>
        </div>
        <div class="subscription-payment-record-meta">
          <span>${escapeHtml(`${translate('subscription_payment_forecast_original_amount')}: ${originalAmountLabel}`)}</span>
          <span>${escapeHtml(`${translate('metric_explanation_rule_source')}: ${item.rule_summary || translate('metric_explanation_regular_price_source')}`)}</span>
        </div>
      </article>
    `;
  }).join('')}`;
}

function renderSubscriptionPaymentHistoryActiveTabHtml() {
  if (currentPaymentHistoryTab === 'cashflow') {
    return renderSubscriptionPaymentCashflowHtml();
  }

  if (currentPaymentHistoryTab === 'forecast') {
    return renderSubscriptionPaymentForecastHtml();
  }

  return renderSubscriptionPaymentHistoryRecordsHtml();
}

function setSubscriptionPaymentHistoryTab(tab) {
  return window.WallosSubscriptionPayments?.setSubscriptionPaymentHistoryTab?.(tab);
}

function renderSubscriptionPaymentHistoryModal() {
  return window.WallosSubscriptionPayments?.renderSubscriptionPaymentHistoryModal?.();
}

function openSubscriptionPaymentHistoryModal(event, id, options = {}) {
  return window.WallosSubscriptionPayments?.openSubscriptionPaymentHistoryModal?.(event, id, options);
}

function openEditSubscriptionPaymentModal(event, subscriptionId, recordId) {
  return window.WallosSubscriptionPayments?.openEditSubscriptionPaymentModal?.(event, subscriptionId, recordId);
}

function deleteSubscriptionPaymentRecord(event, subscriptionId, recordId) {
  return window.WallosSubscriptionPayments?.deleteSubscriptionPaymentRecord?.(event, subscriptionId, recordId);
}

function getSubscriptionPriceRulesCurrencyOptionsHtml() {
  return window.WallosSubscriptionPriceRules?.getCurrencyOptionsHtml?.() || "";
}

function serializeSubscriptionPriceRules() {
  window.WallosSubscriptionPriceRules?.serializeRules?.();
}

function updateSubscriptionPriceRuleField(tempId, field, value, rerender = false, isCheckbox = false) {
  window.WallosSubscriptionPriceRules?.updateField?.(tempId, field, value, rerender, isCheckbox);
}

function addSubscriptionPriceRule(ruleType = "first_n_cycles") {
  window.WallosSubscriptionPriceRules?.addRule?.(ruleType);
}

function removeSubscriptionPriceRule(tempId) {
  window.WallosSubscriptionPriceRules?.removeRule?.(tempId);
}

function setSubscriptionPriceRules(rules = []) {
  window.WallosSubscriptionPriceRules?.setRules?.(rules);
}

function resetSubscriptionPriceRules() {
  window.WallosSubscriptionPriceRules?.resetRules?.();
}

function renderSubscriptionPriceRules() {
  window.WallosSubscriptionPriceRules?.render?.();
}

function openSubscriptionImageViewerItems(items, startIndex = 0) {
  window.WallosSubscriptionImageViewer?.openItems?.(items, startIndex);
}

function openSubscriptionImageViewerFromElement(element) {
  window.WallosSubscriptionImageViewer?.openFromElement?.(element);
}

function resetDetailImageControls() {
  window.WallosSubscriptionMedia?.resetDetailImageControls?.();
}

function handleDetailImageSelect(event) {
  window.WallosSubscriptionMedia?.handleDetailImageSelect?.(event);
}

function setExistingUploadedImages(images) {
  window.WallosSubscriptionMedia?.setExistingUploadedImages?.(images);
}

function getSelectedDetailImageFiles() {
  return window.WallosSubscriptionMedia?.getSelectedDetailImageFiles?.() || [];
}

function getRemovedUploadedImageIds() {
  return window.WallosSubscriptionMedia?.getRemovedUploadedImageIds?.() || [];
}

function closeSubscriptionImageViewer() {
  window.WallosSubscriptionImageViewer?.close?.();
}

function showPreviousSubscriptionImage() {
  window.WallosSubscriptionImageViewer?.showPrevious?.();
}

function showNextSubscriptionImage() {
  window.WallosSubscriptionImageViewer?.showNext?.();
}

function openSubscriptionImageOriginal() {
  window.WallosSubscriptionImageViewer?.openOriginal?.();
}

function downloadSubscriptionImage() {
  window.WallosSubscriptionImageViewer?.download?.();
}

function handleSubscriptionImageViewerKeydown(event) {
  window.WallosSubscriptionImageViewer?.handleKeydown?.(event);
}

function handleSubscriptionImageViewerTouchStart(event) {
  window.WallosSubscriptionImageViewer?.handleTouchStart?.(event);
}

function handleSubscriptionImageViewerTouchEnd(event) {
  window.WallosSubscriptionImageViewer?.handleTouchEnd?.(event);
}

function resetForm() {
  const id = document.querySelector("#id");
  id.value = "";
  const formTitle = document.querySelector("#form-title");
  formTitle.textContent = translate('add_subscription');
  const logo = document.querySelector("#form-logo");
  logo.src = "";
  logo.style = 'display: none';
  const logoUrl = document.querySelector("#logo-url");
  logoUrl.value = "";
  const logoSearchButton = document.querySelector("#logo-search-button");
  logoSearchButton.classList.add("disabled");
  const submitButton = document.querySelector("#save-button");
  submitButton.disabled = false;
  const autoRenew = document.querySelector("#auto_renew");
  autoRenew.checked = true;
  const startDate = document.querySelector("#start_date");
  startDate.value = new Date().toISOString().split('T')[0];
  const manualUsedValueInput = document.querySelector("#manual_cycle_used_value_main");
  if (manualUsedValueInput) {
    manualUsedValueInput.value = "";
  }
  const notifyDaysBefore = document.querySelector("#notify_days_before");
  notifyDaysBefore.disabled = true;
  const excludeFromStats = document.querySelector("#exclude_from_stats");
  if (excludeFromStats) {
    excludeFromStats.checked = false;
  }
  const replacementSubscriptionIdSelect = document.querySelector("#replacement_subscription_id");
  replacementSubscriptionIdSelect.value = "0";
  const replacementSubscription = document.querySelector(`#replacement_subscritpion`);
  replacementSubscription.classList.add("hide");
  const form = document.querySelector("#subs-form");
  form.reset();
  renderSubscriptionPageSelectOptions(getDefaultSubscriptionPageSelection());
  resetSubscriptionPriceRules();
  resetDetailImageControls();
  closeLogoSearch();
  const deleteButton = document.querySelector("#deletesub");
  deleteButton.style = 'display: none';
  deleteButton.removeAttribute("onClick");
}

function fillEditFormFields(subscription) {
  const formTitle = document.querySelector("#form-title");
  formTitle.textContent = translate('edit_subscription');
  const logo = document.querySelector("#form-logo");
  const logoFile = subscription.logo !== null ? "images/uploads/logos/" + subscription.logo : "";
  if (logoFile) {
    logo.src = logoFile;
    logo.style = 'display: block';
  }
  const logoSearchButton = document.querySelector("#logo-search-button");
  logoSearchButton.classList.remove("disabled");
  const id = document.querySelector("#id");
  id.value = subscription.id;
  const name = document.querySelector("#name");
  name.value = subscription.name;
  const price = document.querySelector("#price");
  price.value = subscription.price;

  const currencySelect = document.querySelector("#currency");
  currencySelect.value = subscription.currency_id.toString();
  const frequencySelect = document.querySelector("#frequency");
  frequencySelect.value = subscription.frequency;
  const cycleSelect = document.querySelector("#cycle");
  cycleSelect.value = subscription.cycle;
  const paymentSelect = document.querySelector("#payment_method");
  paymentSelect.value = subscription.payment_method_id;
  const categorySelect = document.querySelector("#category");
  categorySelect.value = subscription.category_id;
  renderSubscriptionPageSelectOptions(subscription.subscription_page_id ? String(subscription.subscription_page_id) : "");
  const payerSelect = document.querySelector("#payer_user");
  payerSelect.value = subscription.payer_user_id;

  const startDate = document.querySelector("#start_date");
  startDate.value = subscription.start_date;
  const nextPament = document.querySelector("#next_payment");
  nextPament.value = subscription.next_payment;
  const manualUsedValueInput = document.querySelector("#manual_cycle_used_value_main");
  if (manualUsedValueInput) {
    const effectiveManualValue = Number(subscription?.remaining_value?.manual_used_value_main || subscription?.manual_cycle_used_value_main || 0);
    manualUsedValueInput.value = effectiveManualValue > 0 ? effectiveManualValue : "";
  }
  const cancellationDate = document.querySelector("#cancellation_date");
  cancellationDate.value = subscription.cancellation_date;

  const notes = document.querySelector("#notes");
  notes.value = subscription.notes;
  const detailImageUrls = document.querySelector("#detail-image-urls");
  if (detailImageUrls) {
    detailImageUrls.value = Array.isArray(subscription.detail_image_urls)
      ? subscription.detail_image_urls.join("\n")
      : "";
  }
  const detailImageInput = document.querySelector("#detail-image-upload");
  if (detailImageInput) {
    detailImageInput.value = "";
  }
  setSubscriptionPriceRules(subscription.price_rules || []);
  resetDetailImageCompression();
  setExistingUploadedImages(subscription.uploaded_images || []);
  const inactive = document.querySelector("#inactive");
  inactive.checked = subscription.inactive;
  const url = document.querySelector("#url");
  url.value = subscription.url;

  const autoRenew = document.querySelector("#auto_renew");
  if (autoRenew) {
    autoRenew.checked = subscription.auto_renew;
  }

  const notifications = document.querySelector("#notifications");
  if (notifications) {
    notifications.checked = subscription.notify;
  }
  const excludeFromStats = document.querySelector("#exclude_from_stats");
  if (excludeFromStats) {
    excludeFromStats.checked = Number(subscription.exclude_from_stats || 0) === 1;
  }

  const notifyDaysBefore = document.querySelector("#notify_days_before");
  notifyDaysBefore.value = subscription.notify_days_before ?? 0;
  if (subscription.notify === 1) {
    notifyDaysBefore.disabled = false;
  }

  const replacementSubscriptionIdSelect = document.querySelector("#replacement_subscription_id");
  replacementSubscriptionIdSelect.value = subscription.replacement_subscription_id ?? 0;

  const replacementSubscription = document.querySelector(`#replacement_subscritpion`);
  if (subscription.inactive) {
    replacementSubscription.classList.remove("hide");
  } else {
    replacementSubscription.classList.add("hide");
  }

  const deleteButton = document.querySelector("#deletesub");
  deleteButton.style = 'display: block';
  deleteButton.dataset.subscriptionAction = "delete-subscription";
  deleteButton.dataset.subscriptionId = String(subscription.id);

  const modal = document.getElementById('subscription-form');
  modal.classList.add("is-open");
}

function openEditSubscription(event, id) {
  event.stopPropagation();
  scrollTopBeforeOpening = window.scrollY;
  const body = document.querySelector('body');
  body.classList.add('no-scroll');
  window.WallosApi.getJson(`endpoints/subscription/get.php?id=${id}`, {
    includeCsrf: false,
    requireOk: true,
    fallbackErrorMessage: translate('failed_to_load_subscription'),
  })
    .then((subscription) => {
      if (subscription?.error || subscription === "Error") {
        showErrorMessage(translate('failed_to_load_subscription'));
        return;
      }

      fillEditFormFields(subscription);
    })
    .catch((error) => {
      console.log(error);
      showErrorMessage(normalizeSubscriptionRequestError(error, translate('failed_to_load_subscription')));
    });
}

function addSubscription() {
  resetForm();
  const modal = document.getElementById('subscription-form');
  
  const startDate = document.querySelector("#start_date");
  startDate.value = new Date().toISOString().split('T')[0];

  modal.classList.add("is-open");
  const body = document.querySelector('body');
  body.classList.add('no-scroll');
}

function closeAddSubscription() {
  const modal = document.getElementById('subscription-form');
  modal.classList.remove("is-open");
  const body = document.querySelector('body');
  body.classList.remove('no-scroll');
  if (shouldScroll) {
    window.scrollTo(0, scrollTopBeforeOpening);
  }
  resetForm();
}

function handleFileSelect(event) {
  const fileInput = event.target;
  const logoPreview = document.querySelector('.logo-preview');
  const logoImg = logoPreview.querySelector('img');
  const logoUrl = document.querySelector("#logo-url");
  logoUrl.value = "";

  if (fileInput.files && fileInput.files[0]) {
    const reader = new FileReader();

    reader.onload = function (e) {
      logoImg.src = e.target.result;
      logoImg.style.display = 'block';
    };

    reader.readAsDataURL(fileInput.files[0]);
  }
}

function deleteSubscription(event, id) {
  event.stopPropagation();
  event.preventDefault();

  if (!confirm(translate('confirm_move_subscription_to_recycle_bin'))) {
    return;
  }

  window.WallosHttp.postJson("endpoints/subscription/delete.php", { id })
    .then((data) => {
      if (data.success) {
        showSuccessMessage(data.message || translate('subscription_deleted'));
        closeAddSubscription();
        window.setTimeout(() => window.location.reload(), 350);
      } else {
        showErrorMessage(data.message || translate('error_deleting_subscription'));
      }
    })
    .catch((error) => {
      console.error("Error:", error);
      showErrorMessage(normalizeSubscriptionRequestError(error, translate('error_deleting_subscription')));
    });
}

function toggleSubscriptionSection(button) {
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

function restoreSubscriptionFromRecycleBin(id) {
  window.WallosHttp.postJson("endpoints/subscription/restore.php", { id })
    .then((data) => {
      if (data.success) {
        showSuccessMessage(data.message || translate("success"));
        window.setTimeout(() => window.location.reload(), 350);
      } else {
        showErrorMessage(data.message || translate("error"));
      }
    })
    .catch((error) => showErrorMessage(normalizeSubscriptionRequestError(error, translate("error"))));
}

function permanentlyDeleteSubscription(id) {
  if (!confirm(translate('confirm_permanently_delete_subscription'))) {
    return;
  }

  window.WallosHttp.postJson("endpoints/subscription/permanentdelete.php", { id })
    .then((data) => {
      if (data.success) {
        showSuccessMessage(data.message || translate("success"));
        window.setTimeout(() => window.location.reload(), 350);
      } else {
        showErrorMessage(data.message || translate("error"));
      }
    })
    .catch((error) => showErrorMessage(normalizeSubscriptionRequestError(error, translate("error"))));
}


function cloneSubscription(event, id) {
  event.stopPropagation();
  event.preventDefault();

  window.WallosHttp.postJson("endpoints/subscription/clone.php", { id }, {
    requireOk: true,
    fallbackErrorMessage: translate("network_response_error"),
  })
    .then((data) => {
      if (data.success) {
        const newId = data.id;
        fetchSubscriptions(newId, event, "clone");
        showSuccessMessage(decodeURI(data.message));
      } else {
        showErrorMessage(data.message || translate("error"));
      }
    })
    .catch((error) => {
      showErrorMessage(normalizeSubscriptionRequestError(error, translate("error")));
    });
}


function renewSubscription(event, id) {
  event.stopPropagation();
  event.preventDefault();

  window.WallosHttp.postJson("endpoints/subscription/renew.php", { id }, {
    requireOk: true,
    fallbackErrorMessage: translate("network_response_error"),
  })
    .then((data) => {
      if (data.success) {
        const newId = data.id;
        fetchSubscriptions(newId, event, "renew");
        showSuccessMessage(decodeURI(data.message));
      } else {
        showErrorMessage(data.message || translate("error"));
      }
    })
    .catch((error) => {
      showErrorMessage(normalizeSubscriptionRequestError(error, translate("error")));
    });
}


function setSearchButtonStatus() {

  const nameInput = document.querySelector("#name");
  const hasSearchTerm = nameInput.value.trim().length > 0;
  const logoSearchButton = document.querySelector("#logo-search-button");
  if (hasSearchTerm) {
    logoSearchButton.classList.remove("disabled");
  } else {
    logoSearchButton.classList.add("disabled");
  }

}

function searchLogo() {
  const nameInput = document.querySelector("#name");
  const searchTerm = nameInput.value.trim();
  if (searchTerm !== "") {
    const logoSearchPopup = document.querySelector("#logo-search-results");
    logoSearchPopup.classList.add("is-open");
    const imageSearchUrl = `endpoints/logos/search.php?search=${searchTerm}`;
    window.WallosApi.getJson(imageSearchUrl, {
      includeCsrf: false,
      fallbackErrorMessage: translate("unknown_error"),
    })
      .then(data => {
        if (data.results) {
          displayImageResults(data.results);
        } else if (data.error) {
          console.error(data.error);
        }
      })
      .catch(error => {
        console.error(normalizeSubscriptionRequestError(error, translate('error_fetching_image_results')), error);
      });
  } else {
    nameInput.focus();
  }
}

function displayImageResults(imageSources) {
  const logoResults = document.querySelector("#logo-search-images");
  logoResults.innerHTML = "";

  imageSources.forEach(src => {
    const img = document.createElement("img");
    img.src = src.thumbnail || src.image;
    img.addEventListener("click", function () {
      selectWebLogo(src.thumbnail || src.image);
    });
    img.addEventListener("error", function () {
      this.parentNode.removeChild(this);
    });
    logoResults.appendChild(img);
  });
}

function selectWebLogo(url) {
  closeLogoSearch();
  const logoPreview = document.querySelector("#form-logo");
  const logoUrl = document.querySelector("#logo-url");
  logoPreview.src = url;
  logoPreview.style.display = 'block';
  logoUrl.value = url;
}

function closeLogoSearch() {
  const logoSearchPopup = document.querySelector("#logo-search-results");
  logoSearchPopup.classList.remove("is-open");
  const logoResults = document.querySelector("#logo-search-images");
  logoResults.innerHTML = "";
}

function getActionSubscriptionId(trigger) {
  return Number(
    trigger?.dataset?.subscriptionId
    || trigger?.closest("[data-subscription-id]")?.dataset?.subscriptionId
    || 0
  );
}

function getActionPageId(trigger) {
  return Number(
    trigger?.dataset?.pageId
    || trigger?.closest("[data-page-id]")?.dataset?.pageId
    || 0
  );
}

function shouldSkipSubscriptionToggle(trigger, originalTarget) {
  if (!trigger || !originalTarget) {
    return false;
  }

  const interactiveTarget = originalTarget.closest("a, input, select, textarea, label");
  return !!interactiveTarget && interactiveTarget !== trigger;
}

function syncSubscriptionPriceRuleField(target, rerenderFallback = false) {
  window.WallosSubscriptionPriceRules?.syncField?.(target, rerenderFallback);
}

function handleStaticSubscriptionAction(event) {
  const trigger = event.target.closest("[data-subscription-action]");
  if (!trigger) {
    return;
  }

  const action = trigger.dataset.subscriptionAction || "";

  switch (action) {
  case "open-add-subscription":
    addSubscription();
    break;
  case "open-recycle-bin":
    openSubscriptionRecycleBinModal(event);
    break;
  case "close-recycle-bin":
    closeSubscriptionRecycleBinModal();
    break;
  case "generate-image-variants":
    generateSubscriptionImageVariants();
    break;
  case "set-display-columns":
    setSubscriptionDisplayColumns(Number(trigger.dataset.columns || 1), trigger);
    break;
  case "toggle-value-metric":
    toggleSubscriptionValueMetric(trigger.dataset.metric || "");
    break;
  case "toggle-sort-options":
    toggleSortOptions();
    break;
  case "select-page-filter":
    selectSubscriptionPageFilter(trigger.dataset.filter || "all");
    break;
  case "open-pages-manager":
    openSubscriptionPagesManager(event);
    break;
  case "save-page":
    renameSubscriptionPage(getActionPageId(trigger), trigger);
    break;
  case "delete-page":
    deleteSubscriptionPage(getActionPageId(trigger));
    break;
  case "close-pages-manager":
    closeSubscriptionPagesManager();
    break;
  case "clear-search":
    clearSearch();
    break;
  case "create-page":
    createSubscriptionPage();
    break;
  case "close-add-subscription":
    closeAddSubscription();
    break;
  case "search-logo":
    searchLogo();
    break;
  case "close-logo-search":
    closeLogoSearch();
    break;
  case "autofill-next-payment":
    autoFillNextPaymentDate(event);
    break;
  case "add-price-rule":
    addSubscriptionPriceRule();
    break;
  case "set-image-layout":
    event.stopPropagation();
    event.preventDefault();
    setSubscriptionImageLayoutMode(
      trigger.dataset.layoutScope || "form",
      trigger.dataset.layoutMode || "focus",
      trigger
    );
    break;
  case "close-payment-modal":
    closeSubscriptionPaymentModal();
    break;
  case "close-payment-history-modal":
    closeSubscriptionPaymentHistoryModal();
    break;
  case "export-payment-history":
    exportSubscriptionPaymentHistoryCurrentView(trigger.dataset.exportFormat || "csv");
    break;
  case "image-viewer-previous":
    showPreviousSubscriptionImage();
    break;
  case "image-viewer-next":
    showNextSubscriptionImage();
    break;
  case "close-image-viewer":
    closeSubscriptionImageViewer();
    break;
  case "open-image-original":
    openSubscriptionImageOriginal();
    break;
  case "download-image":
    downloadSubscriptionImage();
    break;
  case "restore-from-recycle-bin":
    restoreSubscriptionFromRecycleBin(Number(trigger.dataset.subscriptionId || 0));
    break;
  case "permanently-delete-subscription":
    permanentlyDeleteSubscription(Number(trigger.dataset.subscriptionId || 0));
    break;
  case "open-edit-subscription":
    openEditSubscription(event, getActionSubscriptionId(trigger));
    break;
  case "delete-subscription":
    deleteSubscription(event, getActionSubscriptionId(trigger));
    break;
  case "clone-subscription":
    cloneSubscription(event, getActionSubscriptionId(trigger));
    break;
  case "renew-subscription":
    renewSubscription(event, getActionSubscriptionId(trigger));
    break;
  case "toggle-open-subscription":
    if (!shouldSkipSubscriptionToggle(trigger, event.target)) {
      toggleOpenSubscription(getActionSubscriptionId(trigger));
    }
    break;
  case "prevent-subscription-toggle":
    event.stopPropagation();
    break;
  case "expand-subscription-actions":
    expandActions(event, getActionSubscriptionId(trigger));
    break;
  case "open-subscription-image-viewer":
    event.stopPropagation();
    event.preventDefault();
    openSubscriptionImageViewerFromElement(trigger);
    break;
  case "open-payment-history":
    openSubscriptionPaymentHistoryModal(event, getActionSubscriptionId(trigger));
    break;
  case "open-payment-modal":
    openSubscriptionPaymentModal(event, getActionSubscriptionId(trigger));
    break;
  case "toggle-filter-submenu":
    toggleSubMenu(trigger.dataset.filterSubmenu || "");
    break;
  case "clear-filters":
    clearFilters();
    break;
  case "set-sort-option":
    setSortOption(trigger.dataset.sortOption || "manual_order");
    break;
  case "set-payment-history-tab":
    setSubscriptionPaymentHistoryTab(trigger.dataset.paymentHistoryTab || "records");
    break;
  case "edit-payment-record":
    openEditSubscriptionPaymentModal(
      event,
      Number(trigger.dataset.subscriptionId || 0),
      Number(trigger.dataset.recordId || 0)
    );
    break;
  case "delete-payment-record":
    deleteSubscriptionPaymentRecord(
      event,
      Number(trigger.dataset.subscriptionId || 0),
      Number(trigger.dataset.recordId || 0)
    );
    break;
  case "remove-price-rule":
    removeSubscriptionPriceRule(trigger.dataset.ruleTempId || "");
    break;
  default:
    break;
  }
}

function handleStaticSubscriptionInput(event) {
  const inputType = event.target?.dataset?.subscriptionInput;
  if (!inputType) {
    return;
  }

  if (inputType === "search") {
    searchSubscriptions();
    return;
  }

  if (inputType === "subscription-name") {
    setSearchButtonStatus();
    return;
  }

  if (inputType === "price-rule-field") {
    syncSubscriptionPriceRuleField(event.target);
  }
}

function handleStaticSubscriptionChange(event) {
  const changeType = event.target?.dataset?.subscriptionChange;
  if (!changeType) {
    return;
  }

  switch (changeType) {
  case "logo-file":
    handleFileSelect(event);
    break;
  case "notifications-toggle":
    toggleNotificationDays();
    break;
  case "detail-image-upload":
    handleDetailImageSelect(event);
    break;
  case "inactive-toggle":
    toggleReplacementSub();
    break;
  case "price-rule-field":
    syncSubscriptionPriceRuleField(event.target, event.target.dataset.ruleRerender === "1");
    break;
  default:
    break;
  }
}

function initializeStaticSubscriptionInteractions() {
  document.addEventListener("click", handleStaticSubscriptionAction);
  document.addEventListener("input", handleStaticSubscriptionInput);
  document.addEventListener("change", handleStaticSubscriptionChange);
}

function fetchSubscriptions(id, event, initiator) {
  const subscriptionsContainer = document.querySelector("#subscriptions");
  let getSubscriptions = "endpoints/subscriptions/get.php";

  const handleSubscriptionReloadRequired = () => {
    window.location.reload();
  };

  if (subscriptionCardSortable) {
    subscriptionCardSortable.destroy();
    subscriptionCardSortable = null;
  }

  if (activeFilters['categories'].length > 0) {
    getSubscriptions += `?categories=${activeFilters['categories']}`;
  }
  if (activeFilters['members'].length > 0) {
    getSubscriptions += getSubscriptions.includes("?") ? `&members=${activeFilters['members']}` : `?members=${activeFilters['members']}`;
  }
  if (activeFilters['payments'].length > 0) {
    getSubscriptions += getSubscriptions.includes("?") ? `&payments=${activeFilters['payments']}` : `?payments=${activeFilters['payments']}`;
  }
  if (activeFilters['state'] !== "") {
    getSubscriptions += getSubscriptions.includes("?") ? `&state=${activeFilters['state']}` : `?state=${activeFilters['state']}`;
  }
  if (activeFilters['renewalType'] !== "") {
    getSubscriptions += getSubscriptions.includes("?") ? `&renewalType=${activeFilters['renewalType']}` : `?renewalType=${activeFilters['renewalType']}`;
  }
  if (getCurrentSubscriptionPageFilter() !== "all") {
    getSubscriptions += getSubscriptions.includes("?")
      ? `&subscription_page=${encodeURIComponent(getCurrentSubscriptionPageFilter())}`
      : `?subscription_page=${encodeURIComponent(getCurrentSubscriptionPageFilter())}`;
  }

  return window.WallosApi.getText(getSubscriptions, {
    includeCsrf: false,
    requireOk: true,
    fallbackErrorMessage: translate('error_reloading_subscription'),
  })
    .then(data => {
      if (data) {
        subscriptionsContainer.innerHTML = data;
        const mainActions = document.querySelector("#main-actions");
        if (data.includes("no-matching-subscriptions")) {
          // mainActions.classList.add("hidden");
        } else {
          mainActions.classList.remove("hidden");
        }
      }

      return refreshSubscriptionPages({
        selectedValue: document.querySelector("#subscription_page_id")?.value || getDefaultSubscriptionPageSelection(),
        silent: true,
      }).catch((error) => {
        console.error("Failed to refresh subscription pages.", error);
        return null;
      });
    })
    .then(() => {
      if (initiator == "clone" && id && event) {
        openEditSubscription(event, id);
      }

      setSwipeElements();
      applySubscriptionDisplayColumns();
      applySubscriptionValueVisibility();
      applySubscriptionImageLayoutMode("detail");
      initializeSubscriptionMediaSortables();
      initializeSubscriptionCardSortable();
      renderSubscriptionPageTabs();
      if (initiator === "add") {
        if (document.getElementsByClassName('subscription').length === 1) {
          setTimeout(() => {
            swipeHintAnimation();
          }, 1000);
        }
      }
    })
    .catch(error => {
      if (window.WallosApi?.isSessionFailureError?.(error) || Number(error?.status || error?.response?.status || 0) === 401) {
        handleSubscriptionReloadRequired();
        return;
      }

      console.error(translate('error_reloading_subscription'), error);
      throw error;
    });
}

function setSortOption(sortOption) {
  updateSortOptionSelection(sortOption);
  setSubscriptionSortCookie(sortOption);
  fetchSubscriptions(null, null, "sort");
  toggleSortOptions();
}

function convertSvgToPng(file, callback) {
  const reader = new FileReader();

  reader.onload = function (e) {
    const img = new Image();
    img.src = e.target.result;
    img.onload = function () {
      const canvas = document.createElement('canvas');
      canvas.width = img.width;
      canvas.height = img.height;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0);
      const pngDataUrl = canvas.toDataURL('image/png');
      const pngFile = dataURLtoFile(pngDataUrl, file.name.replace(".svg", ".png"));
      callback(pngFile);
    };
  };

  reader.readAsDataURL(file);
}

function dataURLtoFile(dataurl, filename) {
  let arr = dataurl.split(','),
    mime = arr[0].match(/:(.*?);/)[1],
    bstr = atob(arr[1]),
    n = bstr.length,
    u8arr = new Uint8Array(n);

  while (n--) {
    u8arr[n] = bstr.charCodeAt(n);
  }

  return new File([u8arr], filename, { type: mime });
}

function submitFormData(formData, submitButton, endpoint) {
  const request = new XMLHttpRequest();
  let processingProgress = 82;
  let processingTimer = null;

  const stopProcessingTimer = () => {
    if (processingTimer) {
      clearInterval(processingTimer);
      processingTimer = null;
    }
  };

  request.open("POST", endpoint, true);
  request.setRequestHeader("X-CSRF-Token", window.csrfToken);

  setDetailImageUploadProgress(0, translate("subscription_image_upload_progress_uploading"));

  request.upload.onprogress = (event) => {
    if (!event.lengthComputable || event.total <= 0) {
      return;
    }

    const uploadProgress = (event.loaded / event.total) * 78;
    setDetailImageUploadProgress(uploadProgress, translate("subscription_image_upload_progress_uploading"));
  };

  request.upload.onload = () => {
    setDetailImageUploadProgress(82, translate("subscription_image_upload_progress_processing"));
    processingTimer = setInterval(() => {
      processingProgress = Math.min(98, processingProgress + 2);
      setDetailImageUploadProgress(processingProgress, translate("subscription_image_upload_progress_processing"));
    }, 220);
  };

  request.onload = () => {
    stopProcessingTimer();
    setDetailImageUploadProgress(100, translate("subscription_image_upload_progress_processing"));

    let data = null;
    try {
      data = JSON.parse(request.responseText || "{}");
    } catch (error) {
      console.error(error);
      showErrorMessage(request.responseText || translate("unknown_error"));
      hideDetailImageUploadProgress();
      submitButton.disabled = false;
      return;
    }

    if (request.status >= 200 && request.status < 300 && data.status === "Success") {
      showSuccessMessage(data.message);
      fetchSubscriptions(null, null, "add");
      closeAddSubscription();
    } else {
      showErrorMessage(data.message || translate("unknown_error"));
    }

    hideDetailImageUploadProgress();
    submitButton.disabled = false;
  };

  request.onerror = () => {
    stopProcessingTimer();
    hideDetailImageUploadProgress();
    submitButton.disabled = false;
    showErrorMessage(translate("unknown_error"));
  };

  request.onabort = () => {
    stopProcessingTimer();
    hideDetailImageUploadProgress();
    submitButton.disabled = false;
  };

  request.send(formData);
}

document.addEventListener('DOMContentLoaded', function () {
  window.WallosSubscriptionPages?.initialize?.({
    state: window.subscriptionPageState || {},
    fetchSubscriptions,
  });
  window.WallosSubscriptionPriceRules?.initialize?.();
  window.WallosSubscriptionPreferences?.initialize?.({
    preferences: window.subscriptionPagePreferences || {},
    bindMasonryImageEvents: bindSubscriptionMasonryImageEvents,
    scheduleMasonryLayout: scheduleSubscriptionMasonryLayout,
  });
  window.WallosSubscriptionImageViewer?.initialize?.({
    buildFormItems: buildFormDetailImageViewerItems,
  });
  window.WallosSubscriptionMedia?.initialize?.({
    openViewerFromElement: openSubscriptionImageViewerFromElement,
    applyImageLayoutMode: applySubscriptionImageLayoutMode,
  });
  window.WallosSubscriptionLayout?.initialize?.({
    canReorder: canReorderSubscriptions,
    persistOrder: persistSubscriptionOrder,
    persistManualPreference: persistManualSubscriptionSortPreference,
  });
  window.WallosSubscriptionPayments?.initialize?.({
    refreshSubscriptionsPreservingState,
    getOpenSubscriptionIds,
  });
  const subscriptionForm = document.querySelector("#subs-form");
  const submitButton = document.querySelector("#save-button");
  const endpoint = "endpoints/subscription/add.php";
  const subscriptionPageCreateInput = document.querySelector("#subscription-page-create-name");

  initializeStaticSubscriptionInteractions();
  mountSubscriptionOverlayToBody("#subscription-form");
  mountSubscriptionOverlayToBody("#subscription-pages-manager-modal");
  mountSubscriptionOverlayToBody("#subscription-recycle-bin-modal");
  mountSubscriptionOverlayToBody("#subscription-payment-modal");
  mountSubscriptionOverlayToBody("#subscription-payment-history-modal");
  mountSubscriptionOverlayToBody("#subscription-image-viewer");

  if (subscriptionPageCreateInput) {
    subscriptionPageCreateInput.addEventListener("keydown", function (event) {
      if (event.key === "Enter") {
        event.preventDefault();
        createSubscriptionPage();
      }
    });
  }

  subscriptionForm.addEventListener("submit", function (e) {
    e.preventDefault();

    submitButton.disabled = true;
    const formData = new FormData(subscriptionForm);
    const selectedDetailImageFiles = getSelectedDetailImageFiles();
    const removedUploadedImageIds = getRemovedUploadedImageIds();
    const detailImageConfig = getDetailImageConfig();
    const compressCheckbox = document.querySelector("#compress_subscription_image");

    const shouldCompressDetailImage =
      detailImageConfig.compressionMode === "optional"
        ? (compressCheckbox?.checked ? "1" : "0")
        : "1";

    formData.set("compress_subscription_image", shouldCompressDetailImage);
    formData.set("remove_uploaded_image_ids", removedUploadedImageIds.join(","));
    formData.set("manual_cycle_used_value_main", document.querySelector("#manual_cycle_used_value_main")?.value || "");
    formData.delete("detail_images[]");
    selectedDetailImageFiles.forEach((file) => {
      formData.append("detail_images[]", file, file.name);
    });

    const fileInput = document.querySelector("#logo");
    const file = fileInput.files[0];

    if (file && file.type === "image/svg+xml") {
      convertSvgToPng(file, function (pngFile) {
        formData.set("logo", pngFile);
        submitFormData(formData, submitButton, endpoint);
      });
    } else {
      submitFormData(formData, submitButton, endpoint);
    }
  });

  document.addEventListener('mousedown', function (event) {
    const sortOptions = document.querySelector('#sort-options');
    const sortButton = document.querySelector("#sort-button");

    if (!sortOptions.contains(event.target) && !sortButton.contains(event.target) && isSortOptionsOpen) {
      sortOptions.classList.remove('is-open');
      isSortOptionsOpen = false;
    }
  });

  document.querySelector('#sort-options').addEventListener('focus', function () {
    isSortOptionsOpen = true;
  });

  const subscriptionImageViewerContent = document.querySelector("#subscription-image-viewer .subscription-image-viewer-content");
  if (subscriptionImageViewerContent) {
    subscriptionImageViewerContent.addEventListener("touchstart", handleSubscriptionImageViewerTouchStart, { passive: true });
    subscriptionImageViewerContent.addEventListener("touchend", handleSubscriptionImageViewerTouchEnd, { passive: true });
  }

  document.addEventListener("keydown", handleSubscriptionImageViewerKeydown);
  window.addEventListener("resize", handleSubscriptionMasonryResize, { passive: true });
  loadSubscriptionValueVisibility();
  applySubscriptionDisplayColumns();
  applySubscriptionValueVisibility();
  applyAllSubscriptionImageLayoutModes();
  setSearchButtonStatus();
  toggleNotificationDays();
  toggleReplacementSub();
  renderSubscriptionPageTabs();
  closeSubscriptionImageViewer();
  initializeSubscriptionMediaSortables();
  initializeSubscriptionCardSortable();
});

function searchSubscriptions() {
  return window.WallosSubscriptionInteractions?.searchSubscriptions?.(
    activeFilters,
    updateSubscriptionReorderState,
    scheduleSubscriptionMasonryLayout
  );
}

function clearSearch() {
  return window.WallosSubscriptionInteractions?.clearSearch?.(
    activeFilters,
    updateSubscriptionReorderState,
    scheduleSubscriptionMasonryLayout
  );
}

function generateSubscriptionImageVariants() {
  const button = document.querySelector("#generateSubscriptionImageVariantsButton");
  if (!button) {
    return;
  }

  button.disabled = true;

  window.WallosHttp.postJson("endpoints/subscription/generatevariants.php")
    .then((data) => {
      if (data.success) {
        showSuccessMessage(data.message);
        fetchSubscriptions(null, null, "variants");
      } else {
        showErrorMessage(data.message || translate("error"));
      }
    })
    .catch((error) => showErrorMessage(normalizeSubscriptionRequestError(error, translate("error"))))
    .finally(() => {
      button.disabled = false;
    });
}

function closeSubMenus() {
  return window.WallosSubscriptionInteractions?.closeSubMenus?.();
}

function setSwipeElements() {
  return window.WallosSubscriptionInteractions?.setSwipeElements?.();
}

const activeFilters = [];
activeFilters['categories'] = [];
activeFilters['members'] = [];
activeFilters['payments'] = [];
activeFilters['state'] = "";
activeFilters['renewalType'] = "";

document.addEventListener("DOMContentLoaded", function () {
  window.WallosSubscriptionInteractions?.initialize?.(
    activeFilters,
    fetchSubscriptions,
    updateSubscriptionReorderState,
    scheduleSubscriptionMasonryLayout
  );
});

function toggleSubMenu(subMenu) {
  return window.WallosSubscriptionInteractions?.toggleSubMenu?.(subMenu);
}

function toggleReplacementSub() {
  const checkbox = document.getElementById('inactive');
  const replacementSubscription = document.querySelector(`#replacement_subscritpion`);

  if (checkbox.checked) {
    replacementSubscription.classList.remove("hide");
  } else {
    replacementSubscription.classList.add("hide");
  }
}

function clearFilters() {
  return window.WallosSubscriptionInteractions?.clearFilters?.(activeFilters, fetchSubscriptions);
}

function expandActions(event, subscriptionId) {
  return window.WallosSubscriptionInteractions?.expandActions?.(event, subscriptionId);
}

function swipeHintAnimation() {
  return window.WallosSubscriptionInteractions?.swipeHintAnimation?.();
}

function autoFillNextPaymentDate(e) {
  e.preventDefault();
  const frequencySelect = document.querySelector("#frequency");
  const cycleSelect = document.querySelector("#cycle"); 
  const startDate = document.querySelector("#start_date");
  const nextPayment = document.querySelector("#next_payment"); 

  // Do nothing if frequency, cycle, or start date is not set
  if (!frequencySelect.value || !cycleSelect.value || !startDate.value || isNaN(Date.parse(startDate.value))) {
    console.log(frequencySelect.value, cycleSelect.value, startDate.value);
    return;
  }
  
  const today = new Date();  
  const cycle = cycleSelect.value;
  const frequency = Number(frequencySelect.value);

  const nextDate = new Date(startDate.value);
  let safetyCounter = 0;
  const maxIterations = 1000;

  while (nextDate <= today && safetyCounter < maxIterations) {
    switch (cycle) {
    case '1': // Days
      nextDate.setDate(nextDate.getDate() + frequency);
      break;
    case '2': // Weeks
      nextDate.setDate(nextDate.getDate() + 7 * frequency);
      break;
    case '3': // Months  
      nextDate.setMonth(nextDate.getMonth() + frequency);
      break;
    case '4': // Years
      nextDate.setFullYear(nextDate.getFullYear() + frequency);
      break;
    default:
    }
    safetyCounter++;
  }

if (safetyCounter === maxIterations) {
  return;
}

nextPayment.value = toISOStringWithTimezone(nextDate).substring(0, 10);
}

function toISOStringWithTimezone(date) {
  const pad = n => String(Math.floor(Math.abs(n))).padStart(2, '0');
  const tzOffset = -date.getTimezoneOffset();
  const sign = tzOffset >= 0 ? '+' : '-';
  const hoursOffset = pad(tzOffset / 60);
  const minutesOffset = pad(tzOffset % 60);

  return date.getFullYear() +
    '-' + pad(date.getMonth() + 1) +
    '-' + pad(date.getDate()) +
    'T' + pad(date.getHours()) +
    ':' + pad(date.getMinutes()) +
    ':' + pad(date.getSeconds()) +
    sign + hoursOffset +
    ':' + minutesOffset;
}

window.addEventListener('load', () => {
  if (document.querySelector('.subscription')) {
    swipeHintAnimation();
  }
});
