let isSortOptionsOpen = false;
let scrollTopBeforeOpening = 0;
const shouldScroll = window.innerWidth <= 768;
const SUBSCRIPTION_IMAGE_VIEWER_SWIPE_THRESHOLD = 50;
const SUBSCRIPTION_PAGES_ENDPOINT = "endpoints/subscriptionpages.php";
let currentSubscriptionImageViewerSrc = "";
let currentSubscriptionImageOriginalUrl = "";
let currentSubscriptionImageDownloadUrl = "";
let currentSubscriptionImageViewerItems = [];
let currentSubscriptionImageViewerIndex = -1;
let currentSubscriptionImageOriginalRequest = null;
let subscriptionImageViewerTouchStartX = 0;
let subscriptionImageViewerTouchStartY = 0;
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
let subscriptionPriceRules = [];
let subscriptionPriceRuleTempIdCounter = 0;
let detailImageGallerySortable = null;
let detailSubscriptionGallerySortables = [];
let detailImageTempIdCounter = 0;
const SUBSCRIPTION_PREFERENCES_ENDPOINT = "endpoints/settings/subscription_preferences.php";
let subscriptionMasonryLayoutFrame = null;
let subscriptionMasonryResizeTimer = null;
let subscriptionCardSortable = null;
let isSubscriptionSortDragging = false;
let subscriptionDisplayColumns = 1;
let subscriptionImageLayoutPreferences = {
  form: "focus",
  detail: "focus",
};
let subscriptionPreferencesSaveTimer = null;
let subscriptionValueVisibility = {
  metrics: true,
  payment_records: true,
};
let currentSubscriptionPageFilter = "all";
let subscriptionPages = [];
let subscriptionPageCounts = {
  all: 0,
  unassigned: 0,
};

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

let selectedDetailImageFiles = [];
let existingUploadedImages = [];
let removedUploadedImageIds = [];

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
  return {
    pagesTitle: window.subscriptionPageStrings?.pagesTitle || "Subscription Pages",
    manage: window.subscriptionPageStrings?.manage || "Manage Pages",
    all: window.subscriptionPageStrings?.all || "All",
    unassigned: window.subscriptionPageStrings?.unassigned || "Unassigned",
    fieldLabel: window.subscriptionPageStrings?.fieldLabel || "Subscription Page",
    add: window.subscriptionPageStrings?.add || "Add Page",
    empty: window.subscriptionPageStrings?.empty || "No custom pages yet. Create one above.",
    namePlaceholder: window.subscriptionPageStrings?.namePlaceholder || "New page name",
    deleteConfirm: window.subscriptionPageStrings?.deleteConfirm || "Delete this page now? Subscriptions inside it will move to Unassigned.",
  };
}

function normalizeSubscriptionPageFilter(value) {
  const rawValue = String(value ?? "").trim().toLowerCase();
  if (rawValue === "" || rawValue === "all") {
    return "all";
  }

  if (rawValue === "unassigned" || rawValue === "0") {
    return "unassigned";
  }

  const pageId = Number(rawValue);
  if (Number.isInteger(pageId) && pageId > 0) {
    return String(pageId);
  }

  return "all";
}

function getDefaultSubscriptionPageSelection() {
  return /^\d+$/.test(currentSubscriptionPageFilter) ? currentSubscriptionPageFilter : "";
}

function getCurrentSubscriptionPageFilter() {
  return normalizeSubscriptionPageFilter(currentSubscriptionPageFilter);
}

function updateSubscriptionPageFilterUrl() {
  if (!window.history?.replaceState) {
    return;
  }

  const url = new URL(window.location.href);
  const filterValue = getCurrentSubscriptionPageFilter();
  if (filterValue === "all") {
    url.searchParams.delete("subscription_page");
  } else {
    url.searchParams.set("subscription_page", filterValue);
  }

  window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash}`);
}

function setSubscriptionPageFilterValue(filterValue, options = {}) {
  currentSubscriptionPageFilter = normalizeSubscriptionPageFilter(filterValue);
  renderSubscriptionPageTabs();

  if (options.updateUrl !== false) {
    updateSubscriptionPageFilterUrl();
  }

  if (options.fetch !== false) {
    fetchSubscriptions(null, null, "subscription-page").catch(() => showErrorMessage(translate("error")));
  }
}

function selectSubscriptionPageFilter(filterValue) {
  setSubscriptionPageFilterValue(filterValue);
}

function renderSubscriptionPageTabs() {
  const tabsContainer = document.getElementById("subscription-page-tabs");
  if (!tabsContainer) {
    return;
  }

  const strings = getSubscriptionPageStrings();
  const activeFilter = getCurrentSubscriptionPageFilter();
  const tabItems = [
    {
      filter: "all",
      label: strings.all,
      count: Number(subscriptionPageCounts.all || 0),
    },
    {
      filter: "unassigned",
      label: strings.unassigned,
      count: Number(subscriptionPageCounts.unassigned || 0),
    },
    ...subscriptionPages.map((page) => ({
      filter: String(page.id),
      label: page.name || strings.fieldLabel,
      count: Number(page.subscription_count || 0),
    })),
  ];

  tabsContainer.innerHTML = tabItems.map((item) => `
    <button type="button" class="subscription-page-tab${activeFilter === item.filter ? " is-active" : ""}"
      data-page-filter="${escapeHtml(item.filter)}"
      aria-pressed="${activeFilter === item.filter ? "true" : "false"}">
      <span>${escapeHtml(item.label)}</span>
      <span class="section-count-badge">${Number(item.count || 0)}</span>
    </button>
  `).join("");

  tabsContainer.querySelectorAll("[data-page-filter]").forEach((button) => {
    button.addEventListener("click", function () {
      setSubscriptionPageFilterValue(this.getAttribute("data-page-filter"));
    });
  });
}

function renderSubscriptionPageSelectOptions(selectedValue = null) {
  const select = document.getElementById("subscription_page_id");
  if (!select) {
    return;
  }

  const strings = getSubscriptionPageStrings();
  const preservedValue = selectedValue !== null
    ? String(selectedValue)
    : (select.value || getDefaultSubscriptionPageSelection());

  const optionsHtml = [
    `<option value="">${escapeHtml(strings.unassigned)}</option>`,
    ...subscriptionPages.map((page) => `<option value="${Number(page.id)}">${escapeHtml(page.name || strings.fieldLabel)}</option>`),
  ];

  select.innerHTML = optionsHtml.join("");
  if (subscriptionPages.some((page) => String(page.id) === preservedValue)) {
    select.value = preservedValue;
  } else {
    select.value = "";
  }
}

function renderSubscriptionPagesManagerList() {
  const list = document.getElementById("subscription-pages-manager-list");
  if (!list) {
    return;
  }

  const strings = getSubscriptionPageStrings();
  if (!subscriptionPages.length) {
    list.innerHTML = `<div class="subscription-pages-manager-empty">${escapeHtml(strings.empty)}</div>`;
    return;
  }

  list.innerHTML = subscriptionPages.map((page) => `
    <div class="subscription-pages-manager-item" data-page-id="${Number(page.id)}">
      <div class="subscription-pages-manager-item-main">
        <input type="text" class="subscription-page-name-input"
          value="${escapeHtml(page.name || "")}"
          maxlength="40">
        <span class="section-count-badge">${Number(page.subscription_count || 0)}</span>
      </div>
      <div class="subscription-pages-manager-item-actions">
        <button type="button" class="button secondary-button thin" data-subscription-page-action="save">
          <i class="fa-solid fa-floppy-disk"></i>
          <span>${escapeHtml(translate("save"))}</span>
        </button>
        <button type="button" class="button secondary-button thin danger" data-subscription-page-action="delete">
          <i class="fa-solid fa-trash-can"></i>
          <span>${escapeHtml(translate("delete"))}</span>
        </button>
      </div>
    </div>
  `).join("");

  list.querySelectorAll("[data-subscription-page-action='save']").forEach((button) => {
    button.addEventListener("click", function () {
      const pageId = Number(this.closest("[data-page-id]")?.getAttribute("data-page-id") || 0);
      renameSubscriptionPage(pageId, this);
    });
  });

  list.querySelectorAll("[data-subscription-page-action='delete']").forEach((button) => {
    button.addEventListener("click", function () {
      const pageId = Number(this.closest("[data-page-id]")?.getAttribute("data-page-id") || 0);
      deleteSubscriptionPage(pageId);
    });
  });
}

function applySubscriptionPagesPayload(payload, options = {}) {
  subscriptionPages = Array.isArray(payload?.pages)
    ? payload.pages.map((page) => ({
      id: Number(page.id || 0),
      name: String(page.name || ""),
      sort_order: Number(page.sort_order || 0),
      subscription_count: Number(page.subscription_count || 0),
    }))
    : [];

  subscriptionPageCounts = {
    all: Number(payload?.counts?.all || 0),
    unassigned: Number(payload?.counts?.unassigned || 0),
  };

  if (/^\d+$/.test(currentSubscriptionPageFilter) && !subscriptionPages.some((page) => String(page.id) === currentSubscriptionPageFilter)) {
    currentSubscriptionPageFilter = "all";
    updateSubscriptionPageFilterUrl();
  }

  renderSubscriptionPageTabs();
  renderSubscriptionPagesManagerList();
  renderSubscriptionPageSelectOptions(options.selectedValue ?? null);
}

function refreshSubscriptionPages(options = {}) {
  return window.WallosHttp.getJson(SUBSCRIPTION_PAGES_ENDPOINT, {
    includeCsrf: false,
  })
    .then((data) => {
      if (!data.success) {
        throw new Error(data.message || translate("error"));
      }

      applySubscriptionPagesPayload(data, options);
      return data;
    })
    .catch((error) => {
      if (!options.silent) {
        showErrorMessage(normalizeSubscriptionRequestError(error, translate("error")));
      }
      throw error;
    });
}

function submitSubscriptionPageAction(payload, options = {}) {
  return window.WallosHttp.postJson(SUBSCRIPTION_PAGES_ENDPOINT, payload)
    .then((data) => {
      if (!data.success) {
        throw new Error(data.message || translate("error"));
      }

      applySubscriptionPagesPayload(data, options);
      showSuccessMessage(data.message || translate("success"));
      return data;
    })
    .catch((error) => {
      showErrorMessage(normalizeSubscriptionRequestError(error, translate("error")));
      throw error;
    });
}

function openSubscriptionPagesManager(event) {
  if (event) {
    event.stopPropagation();
    event.preventDefault();
  }

  const modal = document.getElementById("subscription-pages-manager-modal");
  if (!modal) {
    return;
  }

  modal.classList.add("is-open");
  document.body.classList.add("no-scroll");
  renderSubscriptionPagesManagerList();
}

function closeSubscriptionPagesManager() {
  const modal = document.getElementById("subscription-pages-manager-modal");
  if (!modal) {
    return;
  }

  modal.classList.remove("is-open");
  if (!document.querySelector(".subscription-form.is-open, .subscription-modal.is-open, .subscription-image-viewer.is-open")) {
    document.body.classList.remove("no-scroll");
  }
}

function createSubscriptionPage() {
  const input = document.getElementById("subscription-page-create-name");
  if (!input) {
    return;
  }

  const name = input.value.trim();
  submitSubscriptionPageAction({ action: "create", name }, { selectedValue: getDefaultSubscriptionPageSelection() })
    .then(() => {
      input.value = "";
    })
    .catch(() => {});
}

function renameSubscriptionPage(pageId, button = null) {
  const pageRow = document.querySelector(`.subscription-pages-manager-item[data-page-id="${pageId}"]`);
  const input = pageRow?.querySelector(".subscription-page-name-input");
  if (!input || pageId <= 0) {
    return;
  }

  if (button) {
    button.disabled = true;
  }

  submitSubscriptionPageAction({ action: "update", page_id: pageId, name: input.value }, { selectedValue: getDefaultSubscriptionPageSelection() })
    .finally(() => {
      if (button) {
        button.disabled = false;
      }
    });
}

function deleteSubscriptionPage(pageId) {
  if (pageId <= 0) {
    return;
  }

  if (!confirm(getSubscriptionPageStrings().deleteConfirm)) {
    return;
  }

  submitSubscriptionPageAction({ action: "delete", page_id: pageId }, { selectedValue: getDefaultSubscriptionPageSelection() })
    .then(() => {
      if (String(pageId) === currentSubscriptionPageFilter) {
        currentSubscriptionPageFilter = "unassigned";
        updateSubscriptionPageFilterUrl();
      }
      return fetchSubscriptions(null, null, "subscription-page-delete");
    })
    .catch(() => {});
}

function normalizeSubscriptionDisplayColumnsPreference(value) {
  const columns = Number(value);
  return columns === 2 || columns === 3 ? columns : 1;
}

function normalizeSubscriptionImageLayoutPreference(value) {
  return value === "grid" ? "grid" : "focus";
}

function normalizeSubscriptionValueVisibilityPreference(value) {
  const visibility = value && typeof value === "object" ? value : {};
  return {
    metrics: visibility.metrics !== false,
    payment_records: visibility.payment_records !== false,
  };
}

function updateSubscriptionPagePreferencesCache() {
  window.subscriptionPagePreferences = {
    displayColumns: subscriptionDisplayColumns,
    valueVisibility: { ...subscriptionValueVisibility },
    imageLayout: {
      form: subscriptionImageLayoutPreferences.form,
      detail: subscriptionImageLayoutPreferences.detail,
    },
  };
}

function scheduleSubscriptionPagePreferencesSave() {
  updateSubscriptionPagePreferencesCache();

  if (subscriptionPreferencesSaveTimer !== null) {
    window.clearTimeout(subscriptionPreferencesSaveTimer);
  }

  subscriptionPreferencesSaveTimer = window.setTimeout(() => {
    subscriptionPreferencesSaveTimer = null;

    fetch(SUBSCRIPTION_PREFERENCES_ENDPOINT, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": window.csrfToken,
      },
      body: JSON.stringify({
        display_columns: subscriptionDisplayColumns,
        value_visibility: subscriptionValueVisibility,
        image_layout_form: subscriptionImageLayoutPreferences.form,
        image_layout_detail: subscriptionImageLayoutPreferences.detail,
      }),
    }).catch((error) => {
      console.error("Failed to persist subscription page preferences.", error);
    });
  }, 160);
}

function loadSubscriptionValueVisibility() {
  subscriptionValueVisibility = normalizeSubscriptionValueVisibilityPreference(
    window.subscriptionPagePreferences?.valueVisibility
  );
}

function persistSubscriptionValueVisibility() {
  scheduleSubscriptionPagePreferencesSave();
}

function applySubscriptionValueVisibility() {
  const container = document.getElementById("subscriptions");
  if (container) {
    container.classList.toggle("hide-cost-value-metrics", !subscriptionValueVisibility.metrics);
    container.classList.toggle("hide-payment-records", !subscriptionValueVisibility.payment_records);
  }

  document.querySelectorAll("[data-subscription-value-toggle]").forEach((button) => {
    const metricKey = button.getAttribute("data-subscription-value-toggle");
    const visible = !!subscriptionValueVisibility[metricKey];
    button.classList.toggle("is-active", visible);
    button.setAttribute("aria-pressed", visible ? "true" : "false");
  });

}

function toggleSubscriptionValueMetric(metricKey) {
  if (!(metricKey in subscriptionValueVisibility)) {
    return;
  }

  subscriptionValueVisibility[metricKey] = !subscriptionValueVisibility[metricKey];
  persistSubscriptionValueVisibility();
  applySubscriptionValueVisibility();
}

function createSubscriptionPriceRuleTempId() {
  subscriptionPriceRuleTempIdCounter += 1;
  return `price-rule-${Date.now()}-${subscriptionPriceRuleTempIdCounter}`;
}

function normalizeSubscriptionPriceRule(rule = {}, index = 0) {
  return {
    id: Number(rule.id || 0),
    tempId: rule.tempId || createSubscriptionPriceRuleTempId(),
    rule_type: rule.rule_type || "first_n_cycles",
    price: rule.price === undefined || rule.price === null ? "" : String(rule.price),
    currency_id: String(rule.currency_id || document.querySelector("#currency")?.value || ""),
    start_date: rule.start_date || "",
    end_date: rule.end_date || "",
    max_cycles: rule.max_cycles === undefined || rule.max_cycles === null ? "1" : String(rule.max_cycles),
    priority: Number(rule.priority || index + 1),
    note: rule.note || "",
    enabled: rule.enabled === undefined ? true : (Number(rule.enabled) === 1 || rule.enabled === true),
  };
}

function getSubscriptionImageLayoutMode(scope) {
  return normalizeSubscriptionImageLayoutPreference(subscriptionImageLayoutPreferences[scope]);
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
  document.querySelectorAll(`.media-layout-toggle[data-image-layout-scope="${scope}"] .media-layout-button`).forEach((button) => {
    const isActive = button.dataset.mode === mode;
    button.classList.toggle("is-active", isActive);
    button.setAttribute("aria-pressed", isActive ? "true" : "false");
  });
}

function applySubscriptionImageLayoutMode(scope, mode = null) {
  const resolvedMode = mode || getSubscriptionImageLayoutMode(scope);
  getSubscriptionImageGalleryTargets(scope).forEach((gallery) => {
    gallery.classList.remove("layout-focus", "layout-grid");
    gallery.classList.add(`layout-${resolvedMode}`);
  });
  updateSubscriptionImageLayoutButtons(scope, resolvedMode);
}

function setSubscriptionImageLayoutMode(scope, mode, button = null) {
  const resolvedMode = mode === "grid" ? "grid" : "focus";
  if (scope in subscriptionImageLayoutPreferences) {
    subscriptionImageLayoutPreferences[scope] = resolvedMode;
    scheduleSubscriptionPagePreferencesSave();
  }

  applySubscriptionImageLayoutMode(scope, resolvedMode);

  if (button) {
    button.blur();
  }
}

function applyAllSubscriptionImageLayoutModes() {
  applySubscriptionImageLayoutMode("form");
  applySubscriptionImageLayoutMode("detail");
}

function getSubscriptionDisplayColumns() {
  return normalizeSubscriptionDisplayColumnsPreference(subscriptionDisplayColumns);
}

function getSubscriptionOccurrenceIndexForDueDate(subscription, dueDate) {
  if (!subscription || !dueDate) {
    return null;
  }

  const startDateValue = subscription.start_date || "";
  const nextPaymentValue = subscription.next_payment || "";

  if (!startDateValue) {
    return nextPaymentValue === dueDate ? 1 : null;
  }

  const startDate = new Date(`${startDateValue}T00:00:00`);
  const targetDate = new Date(`${dueDate}T00:00:00`);
  if (Number.isNaN(startDate.getTime()) || Number.isNaN(targetDate.getTime()) || targetDate < startDate) {
    return null;
  }

  let current = new Date(startDate.getTime());
  let occurrenceIndex = 1;
  const cycle = Number(subscription.cycle || 3);
  const frequency = Math.max(1, Number(subscription.frequency || 1));

  while (current <= targetDate && occurrenceIndex <= 2400) {
    const currentString = current.toISOString().split('T')[0];
    if (currentString === dueDate) {
      return occurrenceIndex;
    }

    if (cycle === 1) {
      current.setDate(current.getDate() + frequency);
    } else if (cycle === 2) {
      current.setDate(current.getDate() + (frequency * 7));
    } else if (cycle === 3) {
      current.setMonth(current.getMonth() + frequency);
    } else {
      current.setFullYear(current.getFullYear() + frequency);
    }

    occurrenceIndex += 1;
  }

  return null;
}

function doesSubscriptionPriceRuleMatch(rule, subscription, dueDate) {
  if (!rule || !subscription || !dueDate || !rule.enabled) {
    return false;
  }

  if (rule.rule_type === "one_time") {
    return rule.start_date === dueDate;
  }

  if (rule.rule_type === "date_range") {
    if (rule.start_date && dueDate < rule.start_date) {
      return false;
    }
    if (rule.end_date && dueDate > rule.end_date) {
      return false;
    }
    return !!(rule.start_date || rule.end_date);
  }

  if (rule.rule_type === "first_n_cycles") {
    const occurrenceIndex = getSubscriptionOccurrenceIndexForDueDate(subscription, dueDate);
    return occurrenceIndex !== null && occurrenceIndex <= Math.max(0, Number(rule.max_cycles || 0));
  }

  return false;
}

function getEffectiveSubscriptionPaymentRule(subscription, dueDate) {
  const rules = Array.isArray(subscription?.price_rules) ? subscription.price_rules : [];
  const normalizedRules = rules
    .map((rule, index) => normalizeSubscriptionPriceRule(rule, index))
    .sort((left, right) => (left.priority - right.priority) || (left.id - right.id));

  return normalizedRules.find((rule) => doesSubscriptionPriceRuleMatch(rule, subscription, dueDate)) || null;
}

function applyPaymentRulePreviewForDueDate(dueDate) {
  if (!currentPaymentModalSubscription || currentPaymentModalMode !== "create") {
    return;
  }

  const amountInput = document.querySelector("#subscription-payment-amount");
  const currencyInput = document.querySelector("#subscription-payment-currency");
  if (!amountInput || !currencyInput) {
    return;
  }

  const matchedRule = getEffectiveSubscriptionPaymentRule(currentPaymentModalSubscription, dueDate);
  if (matchedRule) {
    amountInput.value = matchedRule.price;
    currencyInput.value = String(matchedRule.currency_id || currentPaymentModalSubscription.currency_id || "");
    return;
  }

  amountInput.value = currentPaymentModalSubscription.price || "";
  currencyInput.value = String(currentPaymentModalSubscription.currency_id || "");
}

function updateSubscriptionDisplayColumnButtons(columns) {
  document.querySelectorAll(".subscription-column-toggle .media-layout-button").forEach((button) => {
    const isActive = Number(button.dataset.subscriptionColumns) === columns;
    button.classList.toggle("is-active", isActive);
    button.setAttribute("aria-pressed", isActive ? "true" : "false");
  });
}

function applySubscriptionDisplayColumns(columns = null) {
  const container = document.querySelector("#subscriptions");
  const resolvedColumns = Number(columns) === 2 || Number(columns) === 3 ? Number(columns) : getSubscriptionDisplayColumns();

  if (!container) {
    updateSubscriptionDisplayColumnButtons(resolvedColumns);
    return;
  }

  container.classList.add("subscription-columns");
  container.classList.toggle("subscription-columns-1", resolvedColumns === 1);
  container.classList.toggle("subscription-columns-2", resolvedColumns === 2);
  container.classList.toggle("subscription-columns-3", resolvedColumns === 3);
  container.classList.toggle("subscription-columns-multi", resolvedColumns > 1);
  updateSubscriptionDisplayColumnButtons(resolvedColumns);
  bindSubscriptionMasonryImageEvents();
  scheduleSubscriptionMasonryLayout();
}

function setSubscriptionDisplayColumns(columns, button = null) {
  const resolvedColumns = Number(columns) === 2 || Number(columns) === 3 ? Number(columns) : 1;
  subscriptionDisplayColumns = resolvedColumns;
  scheduleSubscriptionPagePreferencesSave();

  applySubscriptionDisplayColumns(resolvedColumns);

  if (button) {
    button.blur();
  }
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
  const container = document.querySelector("#subscriptions");
  if (!container || !container.classList.contains("subscription-columns") || isSubscriptionSortDragging) {
    return;
  }

  const computedStyles = window.getComputedStyle(container);
  const rowHeight = parseFloat(computedStyles.gridAutoRows);
  const rowGap = parseFloat(computedStyles.rowGap);

  if (!Number.isFinite(rowHeight) || rowHeight <= 0) {
    return;
  }

  Array.from(container.children).forEach((item) => {
    if (!(item instanceof HTMLElement)) {
      return;
    }

    if (window.getComputedStyle(item).display === "none") {
      item.style.gridRowEnd = "";
      return;
    }

    item.style.gridRowEnd = "span 1";
    const itemHeight = item.getBoundingClientRect().height;
    const span = Math.max(1, Math.ceil((itemHeight + rowGap) / (rowHeight + rowGap)));
    item.style.gridRowEnd = `span ${span}`;
  });
}

function scheduleSubscriptionMasonryLayout() {
  if (isSubscriptionSortDragging) {
    return;
  }

  if (subscriptionMasonryLayoutFrame !== null) {
    window.cancelAnimationFrame(subscriptionMasonryLayoutFrame);
  }

  subscriptionMasonryLayoutFrame = window.requestAnimationFrame(() => {
    subscriptionMasonryLayoutFrame = null;
    applySubscriptionMasonryLayout();
  });
}

function handleSubscriptionMasonryResize() {
  if (subscriptionMasonryResizeTimer !== null) {
    window.clearTimeout(subscriptionMasonryResizeTimer);
  }

  subscriptionMasonryResizeTimer = window.setTimeout(() => {
    subscriptionMasonryResizeTimer = null;
    scheduleSubscriptionMasonryLayout();
  }, 80);
}

function getCurrentSubscriptionSortOrder() {
  const rawValue = getCookie("sortOrder");
  return rawValue ? decodeURIComponent(rawValue) : "manual_order";
}

function hasActiveSubscriptionFilters() {
  return activeFilters['categories'].length > 0
    || activeFilters['members'].length > 0
    || activeFilters['payments'].length > 0
    || activeFilters['state'] !== ""
    || activeFilters['renewalType'] !== "";
}

function canReorderSubscriptions() {
  const searchInput = document.querySelector("#search");
  const searchTerm = searchInput?.value.trim() || "";
  const currentSort = getCurrentSubscriptionSortOrder();
  const isReorderSort = currentSort === "manual_order" || currentSort === "next_payment";

  return isReorderSort && searchTerm === "" && !hasActiveSubscriptionFilters();
}

function updateSubscriptionReorderState() {
  const container = document.querySelector("#subscriptions");
  const enabled = !!container && canReorderSubscriptions();

  if (container) {
    container.classList.toggle("subscription-reorder-enabled", enabled);
  }

  document.querySelectorAll(".subscription-drag-handle").forEach((handle) => {
    handle.disabled = !enabled;
    handle.setAttribute("title", translate(enabled ? "subscription_reorder_handle_title" : "subscription_reorder_unavailable"));
    handle.setAttribute("aria-label", translate(enabled ? "subscription_reorder_handle_title" : "subscription_reorder_unavailable"));
  });

  if (subscriptionCardSortable) {
    subscriptionCardSortable.option("disabled", !enabled);
  }
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

  fetch("endpoints/subscription/reordersubscriptions.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": window.csrfToken,
    },
    body: JSON.stringify({
      subscriptionIds,
    }),
  })
    .then((response) => response.json())
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
  const container = document.querySelector("#subscriptions");

  if (subscriptionCardSortable) {
    subscriptionCardSortable.destroy();
    subscriptionCardSortable = null;
  }

  if (!container || typeof Sortable === "undefined") {
    updateSubscriptionReorderState();
    return;
  }

  subscriptionCardSortable = new Sortable(container, {
    animation: 160,
    draggable: ".subscription-container[data-id]",
    handle: ".subscription-drag-handle",
    disabled: !canReorderSubscriptions(),
    onStart: () => {
      isSubscriptionSortDragging = true;
      container.classList.add("is-sorting");
    },
    onEnd: () => {
      isSubscriptionSortDragging = false;
      container.classList.remove("is-sorting");
      persistSubscriptionOrder();
      scheduleSubscriptionMasonryLayout();
    },
  });

  updateSubscriptionReorderState();
}

function getDetailImageConfig() {
  const form = document.querySelector("#subs-form");
  const rawUploadLimit = form?.dataset.uploadLimit;
  const parsedUploadLimit = rawUploadLimit === "" || rawUploadLimit === undefined
    ? null
    : Number(rawUploadLimit);

  return {
    canUpload: form?.dataset.canUploadDetailImage === "1",
    compressionMode: form?.dataset.compressionMode || "disabled",
    maxBytes: Number(form?.dataset.detailImageMaxBytes || 0),
    maxMb: Number(form?.dataset.detailImageMaxMb || 0),
    uploadLimit: Number.isFinite(parsedUploadLimit) ? parsedUploadLimit : null,
    externalUrlLimit: Number(form?.dataset.externalUrlLimit || 0),
    allowedExtensions: form?.dataset.allowedExtensions || "",
    tooLargeMessage: form?.dataset.detailImageTooLarge || translate("unknown_error"),
    invalidTypeMessage: form?.dataset.detailImageInvalidType || translate("unknown_error"),
    uploadBlockedMessage: form?.dataset.detailImageUploadBlocked || translate("unknown_error"),
    uploadLimitMessage: form?.dataset.detailImageUploadLimitMessage || translate("unknown_error"),
  };
}

function ensureSelectedDetailImageFileToken(file) {
  if (!file) {
    return "";
  }

  if (!file._wallosTempId) {
    detailImageTempIdCounter += 1;
    file._wallosTempId = `temp-${Date.now()}-${detailImageTempIdCounter}`;
  }

  return file._wallosTempId;
}

function setDetailImageUploadProgress(percentage, label) {
  const container = document.querySelector("#detail-image-upload-progress");
  const fill = document.querySelector("#detail-image-upload-progress-bar-fill");
  const value = document.querySelector("#detail-image-upload-progress-value");
  const labelElement = document.querySelector("#detail-image-upload-progress-label");

  if (!container || !fill || !value || !labelElement) {
    return;
  }

  const safePercentage = Math.max(0, Math.min(100, Math.round(percentage)));
  container.classList.remove("is-hidden");
  fill.style.width = `${safePercentage}%`;
  value.textContent = `${safePercentage}%`;
  labelElement.textContent = label || translate("subscription_image_upload_progress_idle");
}

function hideDetailImageUploadProgress() {
  const container = document.querySelector("#detail-image-upload-progress");
  const fill = document.querySelector("#detail-image-upload-progress-bar-fill");
  const value = document.querySelector("#detail-image-upload-progress-value");
  const labelElement = document.querySelector("#detail-image-upload-progress-label");

  if (!container || !fill || !value || !labelElement) {
    return;
  }

  container.classList.add("is-hidden");
  fill.style.width = "0%";
  value.textContent = "0%";
  labelElement.textContent = translate("subscription_image_upload_progress_idle");
}

function setOriginalImageProgress(percentage, label) {
  const container = document.querySelector("#subscription-image-original-progress");
  const fill = document.querySelector("#subscription-image-original-progress-fill");
  const value = document.querySelector("#subscription-image-original-progress-value");
  const labelElement = document.querySelector("#subscription-image-original-progress-label");

  if (!container || !fill || !value || !labelElement) {
    return;
  }

  const safePercentage = Math.max(0, Math.min(100, Math.round(percentage)));
  container.classList.remove("is-hidden");
  fill.style.width = `${safePercentage}%`;
  value.textContent = `${safePercentage}%`;
  labelElement.textContent = label || translate("subscription_image_original_loading");
}

function hideOriginalImageProgress() {
  const container = document.querySelector("#subscription-image-original-progress");
  const fill = document.querySelector("#subscription-image-original-progress-fill");
  const value = document.querySelector("#subscription-image-original-progress-value");
  const labelElement = document.querySelector("#subscription-image-original-progress-label");

  if (!container || !fill || !value || !labelElement) {
    return;
  }

  container.classList.add("is-hidden");
  fill.style.width = "0%";
  value.textContent = "0%";
  labelElement.textContent = translate("subscription_image_original_loading");
}

function resetDetailImageCompression() {
  const compressCheckbox = document.querySelector("#compress_subscription_image");
  const config = getDetailImageConfig();

  if (!compressCheckbox) {
    return;
  }

  compressCheckbox.checked = config.compressionMode !== "disabled";
  compressCheckbox.disabled = config.compressionMode === "disabled";
}

function rebuildDetailImageInput() {
  const detailImageInput = document.querySelector("#detail-image-upload");
  if (!detailImageInput || typeof DataTransfer === "undefined") {
    return;
  }

  const dataTransfer = new DataTransfer();
  selectedDetailImageFiles.forEach((file) => {
    dataTransfer.items.add(file);
  });
  detailImageInput.files = dataTransfer.files;
}

function updateDetailImageSelectionMeta() {
  const meta = document.querySelector("#detail-image-selection-meta");
  if (!meta) {
    return;
  }

  const selectedCount = selectedDetailImageFiles.length;
  const existingCount = existingUploadedImages.length;

  if (selectedCount === 0 && existingCount === 0) {
    meta.textContent = translate("subscription_image_no_selection");
    return;
  }

  const parts = [];
  if (existingCount > 0) {
    parts.push(`${translate("subscription_image_selected_existing")}: ${existingCount}`);
  }
  if (selectedCount > 0) {
    parts.push(`${translate("subscription_image_selected_new")}: ${selectedCount}`);
  }
  meta.textContent = `${parts.join(" / ")}. ${translate("subscription_image_click_to_enlarge")}`;
}

function updateDetailImageOrderField() {
  const orderInput = document.querySelector("#detail-image-order");
  const gallery = document.querySelector("#detail-image-gallery");

  if (!orderInput || !gallery) {
    return;
  }

  const tokens = Array.from(gallery.querySelectorAll(".subscription-detail-image-card"))
    .map((card) => card.dataset.orderToken || "")
    .filter((token) => token !== "");

  orderInput.value = tokens.join(",");
}

function syncDetailImageStateFromGallery() {
  const gallery = document.querySelector("#detail-image-gallery");
  if (!gallery) {
    return;
  }

  const orderedCards = Array.from(gallery.querySelectorAll(".subscription-detail-image-card"));
  const existingById = new Map(existingUploadedImages.map((image) => [Number(image.id), image]));
  const newFilesByToken = new Map(selectedDetailImageFiles.map((file) => [ensureSelectedDetailImageFileToken(file), file]));

  const nextExistingImages = [];
  const nextSelectedFiles = [];

  orderedCards.forEach((card) => {
    const orderToken = card.dataset.orderToken || "";
    if (orderToken.startsWith("existing:")) {
      const imageId = Number(orderToken.split(":")[1]);
      const image = existingById.get(imageId);
      if (image) {
        nextExistingImages.push(image);
      }
    } else if (orderToken.startsWith("new:")) {
      const token = orderToken.split(":")[1];
      const file = newFilesByToken.get(token);
      if (file) {
        nextSelectedFiles.push(file);
      }
    }
  });

  existingUploadedImages = nextExistingImages;
  selectedDetailImageFiles = nextSelectedFiles;
  rebuildDetailImageInput();
  updateDetailImageOrderField();
}

function initializeDetailImageGallerySortable() {
  const gallery = document.querySelector("#detail-image-gallery");
  if (!gallery || typeof Sortable === "undefined") {
    return;
  }

  if (detailImageGallerySortable) {
    detailImageGallerySortable.destroy();
    detailImageGallerySortable = null;
  }

  detailImageGallerySortable = new Sortable(gallery, {
    animation: 150,
    draggable: ".subscription-detail-image-card",
    onEnd: () => {
      syncDetailImageStateFromGallery();
      renderDetailImageGallery();
    },
  });
}

function normalizeDetailGalleryOrderAfterDrag(gallery) {
  const uploadedItems = Array.from(gallery.querySelectorAll('.subscription-media-item[data-uploaded-image-id]'));
  const externalItems = Array.from(gallery.querySelectorAll('.subscription-media-item:not([data-uploaded-image-id])'));
  uploadedItems.forEach((item) => gallery.appendChild(item));
  externalItems.forEach((item) => gallery.appendChild(item));
}

function persistSubscriptionImageOrder(gallery) {
  const subscriptionId = Number(gallery?.dataset.subscriptionId || 0);
  const imageIds = Array.from(gallery.querySelectorAll('.subscription-media-item[data-uploaded-image-id]'))
    .map((item) => Number(item.dataset.uploadedImageId || 0))
    .filter((imageId) => imageId > 0);

  if (!subscriptionId || imageIds.length < 2) {
    return;
  }

  fetch("endpoints/subscription/reorderimages.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": window.csrfToken,
    },
    body: JSON.stringify({
      subscriptionId,
      imageIds,
    }),
  })
    .then((response) => response.json())
    .then((data) => {
      if (!data.success) {
        showErrorMessage(data.message || translate("error"));
      }
    })
    .catch(() => showErrorMessage(translate("error")));
}

function initializeSubscriptionMediaSortables() {
  detailSubscriptionGallerySortables.forEach((sortableInstance) => sortableInstance.destroy());
  detailSubscriptionGallerySortables = [];

  if (typeof Sortable === "undefined") {
    return;
  }

  document.querySelectorAll(".subscription-media-gallery[data-subscription-id]").forEach((gallery) => {
    const uploadedItems = gallery.querySelectorAll('.subscription-media-item[data-uploaded-image-id]');
    if (uploadedItems.length < 2) {
      return;
    }

    const sortableInstance = new Sortable(gallery, {
      animation: 150,
      draggable: '.subscription-media-item[data-uploaded-image-id]',
      onEnd: () => {
        normalizeDetailGalleryOrderAfterDrag(gallery);
        persistSubscriptionImageOrder(gallery);
      },
    });

    detailSubscriptionGallerySortables.push(sortableInstance);
  });
}

function getUploadedImageDisplayName(image) {
  const candidate = String(image?.original_name || image?.file_name || "").trim();
  if (candidate !== "") {
    return candidate;
  }

  return translate("subscription_image_source_server");
}

function buildFormDetailImageViewerItems() {
  const items = [];

  existingUploadedImages.forEach((image) => {
    const previewUrl = image?.preview_url || image?.access_url || image?.path || "";
    const originalUrl = image?.original_url || previewUrl;
    const downloadUrl = image?.download_url || originalUrl || previewUrl;
    if (!previewUrl) {
      return;
    }

    items.push({
      src: previewUrl,
      originalUrl,
      downloadUrl,
      label: getUploadedImageDisplayName(image),
    });
  });

  selectedDetailImageFiles.forEach((file) => {
    const objectUrl = URL.createObjectURL(file);
    items.push({
      src: objectUrl,
      originalUrl: objectUrl,
      downloadUrl: null,
      label: file.name || translate("subscription_image_source_new"),
    });
  });

  return items;
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
  const form = document.querySelector("#subscription-payment-form");
  if (!form) {
    return;
  }

  form.reset();
  const subscriptionIdInput = document.querySelector("#subscription-payment-subscription-id");
  const recordIdInput = document.querySelector("#subscription-payment-record-id");
  if (subscriptionIdInput) {
    subscriptionIdInput.value = "";
  }
  if (recordIdInput) {
    recordIdInput.value = "";
  }

  const dueDateInput = document.querySelector("#subscription-payment-due-date");
  const paidAtInput = document.querySelector("#subscription-payment-paid-at");
  const today = new Date().toISOString().split('T')[0];
  if (dueDateInput) {
    dueDateInput.value = today;
  }
  if (paidAtInput) {
    paidAtInput.value = today;
  }

  currentPaymentModalSubscription = null;
  currentPaymentModalMode = "create";
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
  const title = document.querySelector("#subscription-payment-modal-title");
  const subscriptionIdInput = document.querySelector("#subscription-payment-subscription-id");
  const dueDateInput = document.querySelector("#subscription-payment-due-date");
  const paidAtInput = document.querySelector("#subscription-payment-paid-at");
  const amountInput = document.querySelector("#subscription-payment-amount");
  const currencyInput = document.querySelector("#subscription-payment-currency");
  const paymentMethodInput = document.querySelector("#subscription-payment-method");

  currentPaymentModalSubscription = subscription;
  currentPaymentModalMode = "create";

  if (title) {
    title.textContent = `${translate('subscription_record_payment')}: ${subscription.name || ''}`;
  }
  if (subscriptionIdInput) {
    subscriptionIdInput.value = subscription.id || "";
  }
  if (dueDateInput) {
    dueDateInput.value = subscription.next_payment || new Date().toISOString().split('T')[0];
  }
  if (paidAtInput) {
    paidAtInput.value = new Date().toISOString().split('T')[0];
  }
  if (currencyInput) {
    currencyInput.value = String(subscription.currency_id || "");
  }
  if (paymentMethodInput) {
    paymentMethodInput.value = String(subscription.payment_method_id || "");
  }
  if (dueDateInput) {
    applyPaymentRulePreviewForDueDate(dueDateInput.value || "");
  } else if (amountInput) {
    amountInput.value = subscription.price || "";
  }
}

function fillSubscriptionPaymentFormFromRecord(subscriptionId, subscriptionName, record) {
  const title = document.querySelector("#subscription-payment-modal-title");
  const subscriptionIdInput = document.querySelector("#subscription-payment-subscription-id");
  const recordIdInput = document.querySelector("#subscription-payment-record-id");
  const dueDateInput = document.querySelector("#subscription-payment-due-date");
  const paidAtInput = document.querySelector("#subscription-payment-paid-at");
  const amountInput = document.querySelector("#subscription-payment-amount");
  const currencyInput = document.querySelector("#subscription-payment-currency");
  const paymentMethodInput = document.querySelector("#subscription-payment-method");
  const noteInput = document.querySelector("#subscription-payment-note");

  currentPaymentModalMode = "edit";

  if (title) {
    title.textContent = `${translate('subscription_edit_payment')}: ${subscriptionName || ''}`;
  }
  if (subscriptionIdInput) {
    subscriptionIdInput.value = subscriptionId || "";
  }
  if (recordIdInput) {
    recordIdInput.value = record.id || "";
  }
  if (dueDateInput) {
    dueDateInput.value = record.due_date || "";
  }
  if (paidAtInput) {
    paidAtInput.value = record.paid_at || "";
  }
  if (amountInput) {
    amountInput.value = record.amount_original || "";
  }
  if (currencyInput) {
    currencyInput.value = String(record.currency_id || "");
  }
  if (paymentMethodInput) {
    paymentMethodInput.value = String(record.payment_method_id || "");
  }
  if (noteInput) {
    noteInput.value = record.note || "";
  }
}

function openSubscriptionPaymentModal(event, id) {
  if (event) {
    event.stopPropagation();
    event.preventDefault();
  }

  const modal = document.getElementById("subscription-payment-modal");
  const historyModal = document.getElementById("subscription-payment-history-modal");
  if (!modal) {
    return;
  }

  resetSubscriptionPaymentForm();
  document.body.classList.add('no-scroll');
  reopenPaymentHistoryAfterPaymentModalClose = !!(historyModal && historyModal.classList.contains("is-open"));
  if (historyModal && historyModal.classList.contains("is-open")) {
    historyModal.classList.remove("is-open");
  }

  window.WallosHttp.getJson(`endpoints/subscription/get.php?id=${id}`, {
    includeCsrf: false,
    requireOk: true,
    fallbackErrorMessage: translate('failed_to_load_subscription'),
  })
    .then((subscription) => {
      fillSubscriptionPaymentForm(subscription);
      modal.classList.add("is-open");
    })
    .catch((error) => {
      document.body.classList.remove('no-scroll');
      showErrorMessage(normalizeSubscriptionRequestError(error, translate("error")));
    });
}

function closeSubscriptionPaymentHistoryModal() {
  const modal = document.getElementById("subscription-payment-history-modal");
  if (!modal) {
    return;
  }

  modal.classList.remove("is-open");
  const paymentModal = document.getElementById("subscription-payment-modal");
  if (!paymentModal || !paymentModal.classList.contains("is-open")) {
    document.body.classList.remove('no-scroll');
  }
  currentPaymentHistorySubscriptionId = 0;
  currentPaymentHistorySubscriptionName = "";
  currentPaymentHistoryRecords = [];
  currentPaymentHistorySummary = {};
  currentPaymentHistoryCashflow = [];
  currentPaymentHistoryForecast = [];
  currentPaymentHistoryAvailableYears = [];
  currentPaymentHistoryTab = "records";
  currentPaymentHistoryYear = new Date().getFullYear();
  currentPaymentHistoryRangeMonths = 12;
  reopenPaymentHistoryAfterPaymentModalClose = false;
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
  const numericValue = Number(value || 0);
  if (currencyCode) {
    return new Intl.NumberFormat(navigator.language, { style: 'currency', currency: currencyCode }).format(numericValue);
  }

  return new Intl.NumberFormat(navigator.language).format(numericValue);
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
  const payload = getSubscriptionPaymentHistoryExportPayload();
  if (!payload.rows.length) {
    showErrorMessage(translate("subscription_payment_export_empty"));
    return;
  }

  const exportedAt = new Date().toISOString().replace(/[:.]/g, "-");
  if (format === "json") {
    const jsonContent = JSON.stringify({
      exported_at: new Date().toISOString(),
      ...payload.json,
    }, null, 2);
    downloadSubscriptionPaymentHistoryTextFile(jsonContent, `${payload.filenameBase}-${exportedAt}.json`, "application/json;charset=utf-8");
    return;
  }

  const csvContent = buildSubscriptionPaymentHistoryCsv(payload.rows);
  downloadSubscriptionPaymentHistoryTextFile(csvContent, `${payload.filenameBase}-${exportedAt}.csv`, "text/csv;charset=utf-8");
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
          onclick="setSubscriptionPaymentHistoryTab('${tab.id}')">
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
          <button type="button" class="button secondary-button thin subscription-payment-history-toolbar-button" onclick="openEditSubscriptionPaymentModal(event, ${currentPaymentHistorySubscriptionId}, ${record.id})">
            <i class="fa-solid fa-pen-to-square"></i>
            <span>${translate('subscription_edit_payment')}</span>
          </button>
          <button type="button" class="button warning-button thin subscription-payment-history-toolbar-button" onclick="deleteSubscriptionPaymentRecord(event, ${currentPaymentHistorySubscriptionId}, ${record.id})">
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
  currentPaymentHistoryTab = tab;
  renderSubscriptionPaymentHistoryModal();
}

function renderSubscriptionPaymentHistoryModal() {
  const content = document.getElementById("subscription-payment-history-content");
  const title = document.getElementById("subscription-payment-history-modal-title");
  const addButton = document.getElementById("subscription-payment-history-add-button");
  if (!content || !title || !addButton) {
    return;
  }

  title.textContent = `${translate('subscription_payment_history')}: ${currentPaymentHistorySubscriptionName || ''}`;
  addButton.onclick = (event) => openSubscriptionPaymentModal(event, currentPaymentHistorySubscriptionId);
  content.innerHTML = `
    ${getSubscriptionPaymentHistorySummaryHtml()}
    ${getSubscriptionPaymentHistoryTabsHtml()}
    <div class="subscription-payment-history-tabpanel">
      ${renderSubscriptionPaymentHistoryActiveTabHtml()}
    </div>
  `;
  syncSubscriptionPaymentHistoryControls();
}

function openSubscriptionPaymentHistoryModal(event, id, options = {}) {
  if (event) {
    event.stopPropagation();
    event.preventDefault();
  }

  const modal = document.getElementById("subscription-payment-history-modal");
  if (!modal) {
    return;
  }

  document.body.classList.add('no-scroll');
  if (!options.preserveTab || currentPaymentHistorySubscriptionId !== Number(id || 0)) {
    currentPaymentHistoryTab = "records";
  }
  if (!options.preserveFilters || currentPaymentHistorySubscriptionId !== Number(id || 0)) {
    currentPaymentHistoryYear = new Date().getFullYear();
    currentPaymentHistoryRangeMonths = 12;
  }

  const params = new URLSearchParams({
    id: String(id),
    year: String(currentPaymentHistoryYear),
    range: String(currentPaymentHistoryRangeMonths),
  });

  window.WallosHttp.getJson(`endpoints/subscription/paymenthistory.php?${params.toString()}`, {
    includeCsrf: false,
    requireOk: true,
    fallbackErrorMessage: translate('failed_to_load_subscription'),
  })
    .then((data) => {
      if (!data.success) {
        throw new Error(data.message || translate('error'));
      }
      currentPaymentHistorySubscriptionId = Number(data.subscription?.id || 0);
      currentPaymentHistorySubscriptionName = data.subscription?.name || '';
      currentPaymentHistoryAvailableYears = Array.isArray(data.filters?.available_years) ? data.filters.available_years.map((year) => Number(year)) : [new Date().getFullYear()];
      currentPaymentHistoryYear = Number(data.filters?.selected_year || currentPaymentHistoryYear);
      currentPaymentHistoryRangeMonths = Number(data.filters?.selected_range_months || currentPaymentHistoryRangeMonths);
      currentPaymentHistorySummary = data.summary || {};
      currentPaymentHistoryCashflow = Array.isArray(data.cashflow) ? data.cashflow : [];
      currentPaymentHistoryForecast = Array.isArray(data.forecast) ? data.forecast : [];
      currentPaymentHistoryRecords = Array.isArray(data.records) ? data.records : [];
      renderSubscriptionPaymentHistoryModal();
      modal.classList.add("is-open");
    })
    .catch((error) => {
      document.body.classList.remove('no-scroll');
      showErrorMessage(normalizeSubscriptionRequestError(error, translate("error")));
    });
}

function openEditSubscriptionPaymentModal(event, subscriptionId, recordId) {
  if (event) {
    event.stopPropagation();
    event.preventDefault();
  }

  const modal = document.getElementById("subscription-payment-modal");
  const historyModal = document.getElementById("subscription-payment-history-modal");
  if (!modal) {
    return;
  }

  const record = currentPaymentHistoryRecords.find((item) => Number(item.id) === Number(recordId));
  if (!record) {
    showErrorMessage(translate("error"));
    return;
  }

  reopenPaymentHistoryAfterPaymentModalClose = true;
  if (historyModal && historyModal.classList.contains("is-open")) {
    historyModal.classList.remove("is-open");
  }
  fillSubscriptionPaymentFormFromRecord(subscriptionId, currentPaymentHistorySubscriptionName, record);
  modal.classList.add("is-open");
}

function deleteSubscriptionPaymentRecord(event, subscriptionId, recordId) {
  if (event) {
    event.stopPropagation();
    event.preventDefault();
  }

  if (!confirm(translate('confirm_delete_subscription_payment_record'))) {
    return;
  }

  window.WallosHttp.postJson("endpoints/subscription/deletepayment.php", {
    id: subscriptionId,
    record_id: recordId,
  })
    .then((data) => {
      if (data.success) {
        showSuccessMessage(data.message || translate("success"));
        const preservedTab = currentPaymentHistoryTab;
        const preservedYear = currentPaymentHistoryYear;
        const preservedRangeMonths = currentPaymentHistoryRangeMonths;
        refreshSubscriptionsPreservingState({
          initiator: "payment-history",
          subscriptionId,
          historyTab: preservedTab,
          historyYear: preservedYear,
          historyRangeMonths: preservedRangeMonths,
          reopenHistory: true,
        }).catch(() => showErrorMessage(translate("error")));
      } else {
        showErrorMessage(data.message || translate("error"));
      }
    })
    .catch((error) => showErrorMessage(normalizeSubscriptionRequestError(error, translate("error"))));
}

function getSubscriptionPriceRulesCurrencyOptionsHtml() {
  const currencySelect = document.querySelector("#currency");
  return currencySelect ? currencySelect.innerHTML : "";
}

function serializeSubscriptionPriceRules() {
  const input = document.querySelector("#subscription-price-rules-json");
  if (!input) {
    return;
  }

  const serialized = subscriptionPriceRules.map(({ tempId, ...rule }, index) => ({
    ...rule,
    priority: index + 1,
  }));

  input.value = JSON.stringify(serialized);
}

function updateSubscriptionPriceRuleField(tempId, field, value, rerender = false, isCheckbox = false) {
  const rule = subscriptionPriceRules.find((item) => item.tempId === tempId);
  if (!rule) {
    return;
  }

  rule[field] = isCheckbox ? !!value : value;
  serializeSubscriptionPriceRules();

  if (rerender) {
    renderSubscriptionPriceRules();
  }
}

function addSubscriptionPriceRule(ruleType = "first_n_cycles") {
  subscriptionPriceRules.push(normalizeSubscriptionPriceRule({
    rule_type: ruleType,
    currency_id: document.querySelector("#currency")?.value || "",
    max_cycles: "1",
    enabled: true,
  }, subscriptionPriceRules.length));
  renderSubscriptionPriceRules();
}

function removeSubscriptionPriceRule(tempId) {
  subscriptionPriceRules = subscriptionPriceRules.filter((rule) => rule.tempId !== tempId);
  renderSubscriptionPriceRules();
}

function setSubscriptionPriceRules(rules = []) {
  subscriptionPriceRules = Array.isArray(rules)
    ? rules.map((rule, index) => normalizeSubscriptionPriceRule(rule, index))
    : [];
  renderSubscriptionPriceRules();
}

function resetSubscriptionPriceRules() {
  setSubscriptionPriceRules([]);
}

function renderSubscriptionPriceRules() {
  const list = document.querySelector("#subscription-price-rules-list");
  if (!list) {
    return;
  }

  const currencyOptionsHtml = getSubscriptionPriceRulesCurrencyOptionsHtml();
  if (!subscriptionPriceRules.length) {
    list.innerHTML = `<div class="subscription-price-rules-empty">${translate('subscription_price_rules_empty')}</div>`;
    serializeSubscriptionPriceRules();
    return;
  }

  subscriptionPriceRules.forEach((rule, index) => {
    rule.priority = index + 1;
  });

  list.innerHTML = subscriptionPriceRules.map((rule, index) => `
    <article class="subscription-price-rule-card" data-rule-temp-id="${escapeHtml(rule.tempId)}" data-rule-type="${escapeHtml(rule.rule_type)}">
      <div class="subscription-price-rule-card-header">
        <strong>${translate('subscription_price_rule_card_title')} ${index + 1}</strong>
        <button type="button" class="warning-button thin subscription-price-rule-remove"
          onClick="removeSubscriptionPriceRule('${escapeHtml(rule.tempId)}')">
          <i class="fa-solid fa-trash"></i>
          <span>${translate('delete')}</span>
        </button>
      </div>
      <div class="subscription-price-rule-grid">
        <div class="form-group">
          <label>${translate('subscription_price_rule_type')}</label>
          <select onchange="updateSubscriptionPriceRuleField('${escapeHtml(rule.tempId)}', 'rule_type', this.value, true)">
            <option value="first_n_cycles" ${rule.rule_type === 'first_n_cycles' ? 'selected' : ''}>${translate('subscription_price_rule_type_first_n_cycles')}</option>
            <option value="date_range" ${rule.rule_type === 'date_range' ? 'selected' : ''}>${translate('subscription_price_rule_type_date_range')}</option>
            <option value="one_time" ${rule.rule_type === 'one_time' ? 'selected' : ''}>${translate('subscription_price_rule_type_one_time')}</option>
          </select>
        </div>
        <div class="form-group">
          <label>${translate('subscription_price_rule_price')}</label>
          <input type="number" step="0.01" min="0" value="${escapeHtml(rule.price)}" oninput="updateSubscriptionPriceRuleField('${escapeHtml(rule.tempId)}', 'price', this.value)">
        </div>
        <div class="form-group">
          <label>${translate('subscription_price_rule_currency')}</label>
          <select onchange="updateSubscriptionPriceRuleField('${escapeHtml(rule.tempId)}', 'currency_id', this.value)">${currencyOptionsHtml}</select>
        </div>
        <div class="form-group subscription-price-rule-conditional subscription-price-rule-first-cycles">
          <label>${translate('subscription_price_rule_max_cycles')}</label>
          <input type="number" min="1" step="1" value="${escapeHtml(rule.max_cycles)}" oninput="updateSubscriptionPriceRuleField('${escapeHtml(rule.tempId)}', 'max_cycles', this.value)">
        </div>
        <div class="form-group subscription-price-rule-conditional subscription-price-rule-one-time">
          <label>${translate('subscription_price_rule_due_date')}</label>
          <div class="date-wrapper">
            <input type="date" value="${escapeHtml(rule.start_date)}" onchange="updateSubscriptionPriceRuleField('${escapeHtml(rule.tempId)}', 'start_date', this.value)">
          </div>
        </div>
        <div class="subscription-price-rule-date-range subscription-price-rule-conditional">
          <div class="form-group">
            <label>${translate('subscription_price_rule_start_date')}</label>
            <div class="date-wrapper">
              <input type="date" value="${escapeHtml(rule.start_date)}" onchange="updateSubscriptionPriceRuleField('${escapeHtml(rule.tempId)}', 'start_date', this.value)">
            </div>
          </div>
          <div class="form-group">
            <label>${translate('subscription_price_rule_end_date')}</label>
            <div class="date-wrapper">
              <input type="date" value="${escapeHtml(rule.end_date)}" onchange="updateSubscriptionPriceRuleField('${escapeHtml(rule.tempId)}', 'end_date', this.value)">
            </div>
          </div>
        </div>
        <div class="form-group subscription-price-rule-note-group">
          <label>${translate('notes')}</label>
          <textarea rows="3" oninput="updateSubscriptionPriceRuleField('${escapeHtml(rule.tempId)}', 'note', this.value)">${escapeHtml(rule.note)}</textarea>
        </div>
        <div class="form-group-inline grow subscription-price-rule-enabled">
          <input type="checkbox" id="subscription-price-rule-enabled-${escapeHtml(rule.tempId)}" ${rule.enabled ? 'checked' : ''} onchange="updateSubscriptionPriceRuleField('${escapeHtml(rule.tempId)}', 'enabled', this.checked, false, true)">
          <label for="subscription-price-rule-enabled-${escapeHtml(rule.tempId)}" class="grow">${translate('subscription_price_rule_enabled')}</label>
        </div>
      </div>
    </article>
  `).join('');

  subscriptionPriceRules.forEach((rule) => {
    const card = list.querySelector(`[data-rule-temp-id="${rule.tempId}"]`);
    const currencySelect = card?.querySelectorAll('select')[1];
    if (currencySelect) {
      currencySelect.value = String(rule.currency_id || "");
    }
  });

  serializeSubscriptionPriceRules();
}

function getViewerItemsFromGallery(gallery) {
  if (!gallery) {
    return [];
  }

  const itemButtons = Array.from(gallery.querySelectorAll("[data-viewer-src]"));
  return itemButtons.map((button) => ({
    src: button.dataset.viewerSrc || "",
    originalUrl: button.dataset.viewerOriginal || button.dataset.viewerSrc || "",
    downloadUrl: button.dataset.viewerDownload || button.dataset.viewerSrc || "",
    label: button.dataset.viewerLabel || "",
  })).filter((item) => item.src !== "");
}

function openSubscriptionImageViewerItems(items, startIndex = 0) {
  if (!Array.isArray(items) || items.length === 0) {
    return;
  }

  currentSubscriptionImageViewerItems = items;
  currentSubscriptionImageViewerIndex = Math.max(0, Math.min(startIndex, items.length - 1));
  renderCurrentSubscriptionImageViewerItem();
}

function openSubscriptionImageViewerFromElement(element) {
  if (!element) {
    return;
  }

  const formPreview = element.closest("#detail-image-gallery");
  if (formPreview) {
    const items = buildFormDetailImageViewerItems();
    const previewButtons = Array.from(formPreview.querySelectorAll(".subscription-detail-image-preview"));
    const index = Math.max(0, previewButtons.indexOf(element));
    openSubscriptionImageViewerItems(items, index);
    return;
  }

  const gallery = element.closest(".subscription-media-gallery");
  if (gallery) {
    const itemButtons = Array.from(gallery.querySelectorAll(".subscription-media-item"));
    const index = Math.max(0, itemButtons.indexOf(element));
    openSubscriptionImageViewerItems(getViewerItemsFromGallery(gallery), index);
  }
}

function renderCurrentSubscriptionImageViewerItem() {
  const viewer = document.querySelector("#subscription-image-viewer");
  const preview = document.querySelector("#subscription-image-viewer-preview");
  const openLink = document.querySelector("#subscription-image-viewer-open");
  const downloadLink = document.querySelector("#subscription-image-viewer-download");
  const previousButton = document.querySelector("#subscription-image-viewer-prev");
  const nextButton = document.querySelector("#subscription-image-viewer-next");
  const counter = document.querySelector("#subscription-image-viewer-counter");

  if (!viewer || !preview || currentSubscriptionImageViewerIndex < 0 || !currentSubscriptionImageViewerItems.length) {
    return;
  }

  const item = currentSubscriptionImageViewerItems[currentSubscriptionImageViewerIndex];
  currentSubscriptionImageViewerSrc = item.src || "";
  currentSubscriptionImageOriginalUrl = item.originalUrl || item.src || "";
  currentSubscriptionImageDownloadUrl = item.downloadUrl || item.src || "";

  if (currentSubscriptionImageOriginalRequest) {
    currentSubscriptionImageOriginalRequest.abort();
    currentSubscriptionImageOriginalRequest = null;
  }
  hideOriginalImageProgress();
  preview.src = currentSubscriptionImageViewerSrc;
  preview.alt = item.label || "";
  viewer.classList.add("is-open");

  if (openLink) {
    openLink.disabled = currentSubscriptionImageViewerSrc === "";
  }
  if (downloadLink) {
    downloadLink.disabled = currentSubscriptionImageDownloadUrl === "";
  }
  if (previousButton) {
    previousButton.disabled = currentSubscriptionImageViewerIndex <= 0;
  }
  if (nextButton) {
    nextButton.disabled = currentSubscriptionImageViewerIndex >= currentSubscriptionImageViewerItems.length - 1;
  }
  if (counter) {
    counter.textContent = `${currentSubscriptionImageViewerIndex + 1} / ${currentSubscriptionImageViewerItems.length}`;
  }
}

function renderDetailImageGallery() {
  const gallery = document.querySelector("#detail-image-gallery");
  if (!gallery) {
    return;
  }

  gallery.innerHTML = "";
  const totalCount = existingUploadedImages.length + selectedDetailImageFiles.length;
  gallery.classList.toggle("is-empty", totalCount === 0);
  gallery.classList.toggle("has-multiple", totalCount > 1);

  existingUploadedImages.forEach((image) => {
    const thumbUrl = image?.thumbnail_url || image?.preview_url || image?.access_url || image?.path || "";
    const previewUrl = image?.preview_url || image?.access_url || thumbUrl;
    const originalUrl = image?.original_url || previewUrl;
    const downloadUrl = image?.download_url || originalUrl;
    gallery.appendChild(
      createDetailImageCard({
        src: thumbUrl,
        viewerSrc: previewUrl,
        originalUrl,
        downloadUrl,
        badgeText: translate("subscription_image_existing_badge"),
        fileName: getUploadedImageDisplayName(image),
        sourceText: translate("subscription_image_source_server"),
        extraClassName: "existing",
        orderToken: `existing:${Number(image.id)}`,
        onRemove: () => removeExistingUploadedImage(image.id),
      }),
    );
  });

  selectedDetailImageFiles.forEach((file, index) => {
    const objectUrl = URL.createObjectURL(file);
    gallery.appendChild(
      createDetailImageCard({
        src: objectUrl,
        viewerSrc: objectUrl,
        originalUrl: objectUrl,
        downloadUrl: objectUrl,
        badgeText: translate("subscription_image_new_badge"),
        fileName: file.name,
        sourceText: translate("subscription_image_source_new"),
        extraClassName: "new",
        orderToken: `new:${ensureSelectedDetailImageFileToken(file)}`,
        onRemove: () => removeSelectedDetailImage(index),
      }),
    );
  });

  updateDetailImageSelectionMeta();
  updateDetailImageOrderField();
  applySubscriptionImageLayoutMode("form");
  initializeDetailImageGallerySortable();
}

function createDetailImageCard({
  src,
  viewerSrc = "",
  originalUrl = "",
  downloadUrl = "",
  badgeText,
  fileName = "",
  sourceText = "",
  extraClassName = "",
  orderToken = "",
  onRemove,
}) {
  const card = document.createElement("div");
  card.className = `subscription-detail-image-card ${extraClassName}`.trim();
  card.dataset.orderToken = orderToken;

  const previewButton = document.createElement("button");
  previewButton.type = "button";
  previewButton.className = "subscription-detail-image-preview";
  previewButton.dataset.viewerSrc = viewerSrc || src;
  previewButton.dataset.viewerOriginal = originalUrl || viewerSrc || src;
  previewButton.dataset.viewerDownload = downloadUrl || originalUrl || viewerSrc || src;
  previewButton.dataset.viewerLabel = fileName || sourceText || badgeText;
  previewButton.addEventListener("click", (event) => {
    event.preventDefault();
    openSubscriptionImageViewerFromElement(previewButton);
  });

  const image = document.createElement("img");
  image.src = src;
  image.alt = fileName || sourceText || "";
  previewButton.appendChild(image);

  const badge = document.createElement("span");
  badge.className = "subscription-detail-image-badge";
  badge.textContent = badgeText;

  const zoom = document.createElement("span");
  zoom.className = "subscription-detail-image-zoom";
  zoom.innerHTML = '<i class="fa-solid fa-magnifying-glass-plus"></i>';

  const meta = document.createElement("div");
  meta.className = "subscription-detail-image-card-meta";

  const nameElement = document.createElement("strong");
  nameElement.textContent = fileName || sourceText || badgeText;

  const sourceElement = document.createElement("span");
  sourceElement.textContent = sourceText || badgeText;

  const removeButton = document.createElement("button");
  removeButton.type = "button";
  removeButton.className = "subscription-detail-image-remove";
  removeButton.setAttribute("aria-label", translate("subscription_image_remove"));
  removeButton.innerHTML = '<i class="fa-solid fa-xmark"></i>';
  removeButton.addEventListener("click", (event) => {
    event.preventDefault();
    event.stopPropagation();
    if (typeof onRemove === "function") {
      onRemove();
    }
  });

  card.appendChild(previewButton);
  previewButton.appendChild(zoom);
  card.appendChild(badge);
  meta.appendChild(nameElement);
  meta.appendChild(sourceElement);
  card.appendChild(meta);
  card.appendChild(removeButton);

  return card;
}

function resetDetailImageControls() {
  const detailImageInput = document.querySelector("#detail-image-upload");
  const detailImageUrls = document.querySelector("#detail-image-urls");
  const removeUploadedImageIdsInput = document.querySelector("#remove-uploaded-image-ids");

  if (detailImageInput) {
    detailImageInput.value = "";
  }
  if (detailImageUrls) {
    detailImageUrls.value = "";
  }
  if (removeUploadedImageIdsInput) {
    removeUploadedImageIdsInput.value = "";
  }

  selectedDetailImageFiles = [];
  existingUploadedImages = [];
  removedUploadedImageIds = [];

  resetDetailImageCompression();
  hideDetailImageUploadProgress();
  rebuildDetailImageInput();
  renderDetailImageGallery();
}

function validateDetailImageFile(file) {
  const config = getDetailImageConfig();
  const allowedTypes = ["image/jpeg", "image/png", "image/webp", "image/jpg"];
  const fileName = String(file?.name || "").toLowerCase();
  const hasAllowedExtension = [".jpg", ".jpeg", ".png", ".webp"].some((extension) =>
    fileName.endsWith(extension),
  );
  const hasAllowedType = allowedTypes.includes(file.type);

  if (!config.canUpload) {
    showErrorMessage(config.uploadBlockedMessage);
    return false;
  }

  if (!hasAllowedType && !hasAllowedExtension) {
    showErrorMessage(config.invalidTypeMessage);
    return false;
  }

  if (config.maxBytes > 0 && file.size > config.maxBytes) {
    showErrorMessage(config.tooLargeMessage);
    return false;
  }

  return true;
}

function handleDetailImageSelect(event) {
  const fileInput = event.target;
  const config = getDetailImageConfig();

  if (!fileInput.files || !fileInput.files.length) {
    return;
  }

  const incomingFiles = Array.from(fileInput.files);
  const validFiles = [];

  for (const file of incomingFiles) {
    if (!validateDetailImageFile(file)) {
      continue;
    }
    validFiles.push(file);
  }

  if (!validFiles.length) {
    fileInput.value = "";
    return;
  }

  if (config.uploadLimit !== null && (existingUploadedImages.length + selectedDetailImageFiles.length + validFiles.length) > config.uploadLimit) {
    showErrorMessage(config.uploadLimitMessage);
    fileInput.value = "";
    rebuildDetailImageInput();
    return;
  }

  selectedDetailImageFiles = selectedDetailImageFiles.concat(validFiles);
  fileInput.value = "";
  rebuildDetailImageInput();
  renderDetailImageGallery();
}

function removeSelectedDetailImage(index) {
  selectedDetailImageFiles.splice(index, 1);
  rebuildDetailImageInput();
  renderDetailImageGallery();
}

function removeExistingUploadedImage(imageId) {
  removedUploadedImageIds.push(Number(imageId));
  existingUploadedImages = existingUploadedImages.filter((image) => Number(image.id) !== Number(imageId));
  const removeUploadedImageIdsInput = document.querySelector("#remove-uploaded-image-ids");
  if (removeUploadedImageIdsInput) {
    removeUploadedImageIdsInput.value = removedUploadedImageIds.join(",");
  }
  renderDetailImageGallery();
}

function setExistingUploadedImages(images) {
  existingUploadedImages = Array.isArray(images)
    ? images
      .filter((image) => image && (image.access_url || image.path))
      .map((image) => ({ ...image, id: Number(image.id) }))
    : [];
  removedUploadedImageIds = [];
  const removeUploadedImageIdsInput = document.querySelector("#remove-uploaded-image-ids");
  if (removeUploadedImageIdsInput) {
    removeUploadedImageIdsInput.value = "";
  }
  renderDetailImageGallery();
}

function closeSubscriptionImageViewer() {
  const viewer = document.querySelector("#subscription-image-viewer");
  const preview = document.querySelector("#subscription-image-viewer-preview");
  const counter = document.querySelector("#subscription-image-viewer-counter");
  const openLink = document.querySelector("#subscription-image-viewer-open");
  const downloadLink = document.querySelector("#subscription-image-viewer-download");
  const previousButton = document.querySelector("#subscription-image-viewer-prev");
  const nextButton = document.querySelector("#subscription-image-viewer-next");

  if (viewer) {
    viewer.classList.remove("is-open");
  }
  if (preview) {
    preview.src = "";
    preview.alt = "";
  }
  if (counter) {
    counter.textContent = "1 / 1";
  }
  if (openLink) {
    openLink.disabled = true;
  }
  if (downloadLink) {
    downloadLink.disabled = true;
  }
  if (previousButton) {
    previousButton.disabled = true;
  }
  if (nextButton) {
    nextButton.disabled = true;
  }
  if (currentSubscriptionImageOriginalRequest) {
    currentSubscriptionImageOriginalRequest.abort();
    currentSubscriptionImageOriginalRequest = null;
  }
  hideOriginalImageProgress();
  currentSubscriptionImageViewerItems = [];
  currentSubscriptionImageViewerIndex = -1;
  currentSubscriptionImageViewerSrc = "";
  currentSubscriptionImageOriginalUrl = "";
  currentSubscriptionImageDownloadUrl = "";
}

function showPreviousSubscriptionImage() {
  if (currentSubscriptionImageViewerIndex > 0) {
    currentSubscriptionImageViewerIndex -= 1;
    renderCurrentSubscriptionImageViewerItem();
  }
}

function showNextSubscriptionImage() {
  if (currentSubscriptionImageViewerIndex >= 0 && currentSubscriptionImageViewerIndex < currentSubscriptionImageViewerItems.length - 1) {
    currentSubscriptionImageViewerIndex += 1;
    renderCurrentSubscriptionImageViewerItem();
  }
}

function openSubscriptionImageOriginal() {
  if (!currentSubscriptionImageOriginalUrl) {
    return;
  }

  if (currentSubscriptionImageOriginalRequest) {
    currentSubscriptionImageOriginalRequest.abort();
    currentSubscriptionImageOriginalRequest = null;
  }

  const request = new XMLHttpRequest();
  currentSubscriptionImageOriginalRequest = request;
  request.open("GET", currentSubscriptionImageOriginalUrl, true);
  request.responseType = "blob";

  setOriginalImageProgress(0, translate("subscription_image_original_loading"));

  request.onprogress = (event) => {
    if (event.lengthComputable && event.total > 0) {
      setOriginalImageProgress((event.loaded / event.total) * 100, translate("subscription_image_original_loading"));
    } else {
      setOriginalImageProgress(50, translate("subscription_image_original_loading"));
    }
  };

  request.onload = () => {
    currentSubscriptionImageOriginalRequest = null;

    if (request.status >= 200 && request.status < 300) {
      setOriginalImageProgress(100, translate("subscription_image_original_loading"));
      const blobUrl = URL.createObjectURL(request.response);
      const link = document.createElement("a");
      link.href = blobUrl;
      link.target = "_blank";
      link.rel = "noopener noreferrer";
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);

      setTimeout(() => {
        URL.revokeObjectURL(blobUrl);
      }, 60000);
      setTimeout(() => {
        hideOriginalImageProgress();
      }, 300);
      return;
    }

    hideOriginalImageProgress();
    showErrorMessage(translate("error"));
  };

  request.onerror = () => {
    currentSubscriptionImageOriginalRequest = null;
    hideOriginalImageProgress();
    showErrorMessage(translate("error"));
  };

  request.onabort = () => {
    currentSubscriptionImageOriginalRequest = null;
    hideOriginalImageProgress();
  };

  request.send();
}

function downloadSubscriptionImage() {
  if (!currentSubscriptionImageDownloadUrl) {
    return;
  }

  const link = document.createElement("a");
  link.href = currentSubscriptionImageDownloadUrl;
  link.download = "";
  link.rel = "noreferrer";
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}

function handleSubscriptionImageViewerKeydown(event) {
  const viewer = document.querySelector("#subscription-image-viewer");
  if (!viewer || !viewer.classList.contains("is-open")) {
    return;
  }

  if (event.key === "Escape") {
    closeSubscriptionImageViewer();
  } else if (event.key === "ArrowLeft") {
    showPreviousSubscriptionImage();
  } else if (event.key === "ArrowRight") {
    showNextSubscriptionImage();
  }
}

function handleSubscriptionImageViewerTouchStart(event) {
  if (!event.touches || event.touches.length === 0) {
    return;
  }

  subscriptionImageViewerTouchStartX = event.touches[0].clientX;
  subscriptionImageViewerTouchStartY = event.touches[0].clientY;
}

function handleSubscriptionImageViewerTouchEnd(event) {
  if (!event.changedTouches || event.changedTouches.length === 0) {
    return;
  }

  const deltaX = event.changedTouches[0].clientX - subscriptionImageViewerTouchStartX;
  const deltaY = event.changedTouches[0].clientY - subscriptionImageViewerTouchStartY;

  if (Math.abs(deltaX) < SUBSCRIPTION_IMAGE_VIEWER_SWIPE_THRESHOLD || Math.abs(deltaX) <= Math.abs(deltaY)) {
    return;
  }

  if (deltaX > 0) {
    showPreviousSubscriptionImage();
  } else {
    showNextSubscriptionImage();
  }
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
  selectedDetailImageFiles = [];
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
  deleteButton.setAttribute("onClick", `deleteSubscription(event, ${subscription.id})`);

  const modal = document.getElementById('subscription-form');
  modal.classList.add("is-open");
}

function openEditSubscription(event, id) {
  event.stopPropagation();
  scrollTopBeforeOpening = window.scrollY;
  const body = document.querySelector('body');
  body.classList.add('no-scroll');
  const url = `endpoints/subscription/get.php?id=${id}`;
  fetch(url)
    .then((response) => {
      if (response.ok) {
        return response.json();
      } else {
        showErrorMessage(translate('failed_to_load_subscription'));
      }
    })
    .then((data) => {
      if (data.error || data === "Error") {
        showErrorMessage(translate('failed_to_load_subscription'));
      } else {
        const subscription = data;
        fillEditFormFields(subscription);
      }
    })
    .catch((error) => {
      console.log(error);
      showErrorMessage(translate('failed_to_load_subscription'));
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
    fetch(imageSearchUrl)
      .then(response => response.json())
      .then(data => {
        if (data.results) {
          displayImageResults(data.results);
        } else if (data.error) {
          console.error(data.error);
        }
      })
      .catch(error => {
        console.error(translate('error_fetching_image_results'), error);
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

  return fetch(getSubscriptions)
    .then(response => response.text())
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
  subscriptionDisplayColumns = normalizeSubscriptionDisplayColumnsPreference(
    window.subscriptionPagePreferences?.displayColumns
  );
  currentSubscriptionPageFilter = normalizeSubscriptionPageFilter(
    window.subscriptionPageState?.currentFilter
  );
  subscriptionImageLayoutPreferences = {
    form: normalizeSubscriptionImageLayoutPreference(window.subscriptionPagePreferences?.imageLayout?.form),
    detail: normalizeSubscriptionImageLayoutPreference(window.subscriptionPagePreferences?.imageLayout?.detail),
  };
  const subscriptionForm = document.querySelector("#subs-form");
  const submitButton = document.querySelector("#save-button");
  const endpoint = "endpoints/subscription/add.php";
  const subscriptionPaymentForm = document.querySelector("#subscription-payment-form");
  const subscriptionPaymentSaveButton = document.querySelector("#subscription-payment-save-button");
  const subscriptionPaymentDueDateInput = document.querySelector("#subscription-payment-due-date");
  const subscriptionPageCreateInput = document.querySelector("#subscription-page-create-name");

  initializeStaticSubscriptionInteractions();
  mountSubscriptionOverlayToBody("#subscription-form");
  mountSubscriptionOverlayToBody("#subscription-pages-manager-modal");
  mountSubscriptionOverlayToBody("#subscription-recycle-bin-modal");
  mountSubscriptionOverlayToBody("#subscription-payment-modal");
  mountSubscriptionOverlayToBody("#subscription-payment-history-modal");
  mountSubscriptionOverlayToBody("#subscription-image-viewer");
  applySubscriptionPagesPayload(window.subscriptionPageState || {}, {
    selectedValue: getDefaultSubscriptionPageSelection(),
  });
  renderSubscriptionPriceRules();

  if (subscriptionPaymentDueDateInput) {
    subscriptionPaymentDueDateInput.addEventListener("change", function () {
      applyPaymentRulePreviewForDueDate(this.value || "");
    });
  }

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

  if (subscriptionPaymentForm) {
    subscriptionPaymentForm.addEventListener("submit", function (e) {
      e.preventDefault();

      if (!subscriptionPaymentSaveButton) {
        return;
      }

      subscriptionPaymentSaveButton.disabled = true;
      const recordId = Number(document.querySelector("#subscription-payment-record-id")?.value || 0);

      const payload = {
        id: Number(document.querySelector("#subscription-payment-subscription-id")?.value || 0),
        record_id: recordId,
        due_date: document.querySelector("#subscription-payment-due-date")?.value || "",
        paid_at: document.querySelector("#subscription-payment-paid-at")?.value || "",
        amount_original: document.querySelector("#subscription-payment-amount")?.value || "",
        currency_id: Number(document.querySelector("#subscription-payment-currency")?.value || 0),
        payment_method_id: Number(document.querySelector("#subscription-payment-method")?.value || 0),
        note: document.querySelector("#subscription-payment-note")?.value || "",
      };

      window.WallosHttp.postJson(
        recordId > 0 ? "endpoints/subscription/updatepayment.php" : "endpoints/subscription/recordpayment.php",
        payload
      )
        .then((data) => {
          if (data.success) {
            showSuccessMessage(data.message || translate("success"));
            const shouldReopenHistory = reopenPaymentHistoryAfterPaymentModalClose && Number(payload.id || 0) > 0;
            const preservedTab = currentPaymentHistoryTab;
            const preservedYear = currentPaymentHistoryYear;
            const preservedRangeMonths = currentPaymentHistoryRangeMonths;
            const openSubscriptionIds = getOpenSubscriptionIds();
            closeSubscriptionPaymentModal({ skipReopenHistory: true });
            refreshSubscriptionsPreservingState({
              initiator: "payment-save",
              openSubscriptionIds,
              subscriptionId: payload.id,
              historyTab: preservedTab,
              historyYear: preservedYear,
              historyRangeMonths: preservedRangeMonths,
              reopenHistory: shouldReopenHistory,
            }).catch(() => showErrorMessage(translate("error")));
          } else {
            showErrorMessage(data.message || translate("error"));
          }
        })
        .catch(() => showErrorMessage(translate("error")))
        .finally(() => {
          subscriptionPaymentSaveButton.disabled = false;
        });
    });
  }

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
  const searchInput = document.querySelector("#search");
  const searchContainer = searchInput.parentElement;
  const searchTerm = searchInput.value.trim().toLowerCase();

  if (searchTerm.length > 0) {
    searchContainer.classList.add("has-text");
  } else {
    searchContainer.classList.remove("has-text");
  }

  const subscriptions = document.querySelectorAll(".subscription");
  subscriptions.forEach(subscription => {
    const name = subscription.getAttribute('data-name').toLowerCase();
    if (!name.includes(searchTerm)) {
      subscription.parentElement.classList.add("hide");
    } else {
      subscription.parentElement.classList.remove("hide");
    }
  });

  updateSubscriptionReorderState();
  scheduleSubscriptionMasonryLayout();
}

function clearSearch() {
  const searchInput = document.querySelector("#search");

  searchInput.value = "";
  searchSubscriptions();
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
  var subMenus = document.querySelectorAll('.filtermenu-submenu-content');
  subMenus.forEach(subMenu => {
    subMenu.classList.remove('is-open');
  });

}

function setSwipeElements() {
  if (window.mobileNavigation) {
    const swipeElements = document.querySelectorAll('.subscription');

    swipeElements.forEach((element) => {
      let startX = 0;
      let startY = 0;
      let currentX = 0;
      let currentY = 0;
      let translateX = 0;
      const maxTranslateX = element.classList.contains('manual') ? -240 : -180;

      element.addEventListener('touchstart', (e) => {
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        element.style.transition = ''; // Remove transition for smooth dragging
      });

      element.addEventListener('touchmove', (e) => {
        currentX = e.touches[0].clientX;
        currentY = e.touches[0].clientY;

        const diffX = currentX - startX;
        const diffY = currentY - startY;

        // Check if the swipe is more horizontal than vertical
        if (Math.abs(diffX) > Math.abs(diffY)) {
          e.preventDefault(); // Prevent vertical scrolling

          // Only update translateX if swiping within allowed range
          if (!(translateX === maxTranslateX && diffX < 0)) {
            translateX = Math.min(0, Math.max(maxTranslateX, diffX)); // Clamp translateX between -180 and 0
            element.style.transform = `translateX(${translateX}px)`;
          }
        }
      });

      element.addEventListener('touchend', () => {
        // Check the final swipe position to determine snap behavior
        if (translateX < maxTranslateX / 2) {
          // If more than halfway to the left, snap fully open
          translateX = maxTranslateX;
        } else {
          // If swiped less than halfway left or swiped right, snap back to closed
          translateX = 0;
        }
        element.style.transition = 'transform 0.2s ease'; // Smooth snap effect
        element.style.transform = `translateX(${translateX}px)`;
        element.style.zIndex = '1';
      });
    });

  }
}

const activeFilters = [];
activeFilters['categories'] = [];
activeFilters['members'] = [];
activeFilters['payments'] = [];
activeFilters['state'] = "";
activeFilters['renewalType'] = "";

document.addEventListener("DOMContentLoaded", function () {
  var filtermenu = document.querySelector('#filtermenu-button');
  filtermenu.addEventListener('click', function () {
    this.parentElement.querySelector('.filtermenu-content').classList.toggle('is-open');
    closeSubMenus();
  });

  document.addEventListener('click', function (e) {
    var filtermenuContent = document.querySelector('.filtermenu-content');
    if (filtermenuContent.classList.contains('is-open')) {
      var subMenus = document.querySelectorAll('.filtermenu-submenu');
      var clickedInsideSubmenu = Array.from(subMenus).some(subMenu => subMenu.contains(e.target) || subMenu === e.target);

      if (!filtermenu.contains(e.target) && !clickedInsideSubmenu) {
        closeSubMenus();
        filtermenuContent.classList.remove('is-open');
      }
    }
  });

  setSwipeElements();

});

function toggleSubMenu(subMenu) {
  var subMenu = document.getElementById("filter-" + subMenu);
  if (subMenu.classList.contains("is-open")) {
    closeSubMenus();
  } else {
    closeSubMenus();
    subMenu.classList.add("is-open");
  }
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

document.querySelectorAll('.filter-item').forEach(function (item) {
  item.addEventListener('click', function (e) {
    const searchInput = document.querySelector("#search");
    searchInput.value = "";

    if (this.hasAttribute('data-categoryid')) {
      const categoryId = this.getAttribute('data-categoryid');
      if (activeFilters['categories'].includes(categoryId)) {
        const categoryIndex = activeFilters['categories'].indexOf(categoryId);
        activeFilters['categories'].splice(categoryIndex, 1);
        this.classList.remove('selected');
      } else {
        activeFilters['categories'].push(categoryId);
        this.classList.add('selected');
      }
    } else if (this.hasAttribute('data-memberid')) {
      const memberId = this.getAttribute('data-memberid');
      if (activeFilters['members'].includes(memberId)) {
        const memberIndex = activeFilters['members'].indexOf(memberId);
        activeFilters['members'].splice(memberIndex, 1);
        this.classList.remove('selected');
      } else {
        activeFilters['members'].push(memberId);
        this.classList.add('selected');
      }
    } else if (this.hasAttribute('data-paymentid')) {
      const paymentId = this.getAttribute('data-paymentid');
      if (activeFilters['payments'].includes(paymentId)) {
        const paymentIndex = activeFilters['payments'].indexOf(paymentId);
        activeFilters['payments'].splice(paymentIndex, 1);
        this.classList.remove('selected');
      } else {
        activeFilters['payments'].push(paymentId);
        this.classList.add('selected');
      }
    } else if (this.hasAttribute('data-state')) {
      const state = this.getAttribute('data-state');
      if (activeFilters['state'] === state) {
        activeFilters['state'] = "";
        this.classList.remove('selected');
      } else {
        activeFilters['state'] = state;
        Array.from(this.parentNode.children).forEach(sibling => {
          sibling.classList.remove('selected');
        });
        this.classList.add('selected');
      }
    } else if (this.hasAttribute('data-renewaltype')) {
      const renewalType = this.getAttribute('data-renewaltype');
      if (activeFilters['renewalType'] === renewalType) {
        activeFilters['renewalType'] = "";
        this.classList.remove('selected');
      } else {
        activeFilters['renewalType'] = renewalType;
        Array.from(this.parentNode.children).forEach(sibling => {
          sibling.classList.remove('selected');
        });
        this.classList.add('selected');
      }
    }

    if (activeFilters['categories'].length > 0 || activeFilters['members'].length > 0 ||
       activeFilters['payments'].length > 0 || activeFilters['state'] !== "" || 
       activeFilters['renewalType'] !== "") {
      document.querySelector('#clear-filters').classList.remove('hide');
    } else {
      document.querySelector('#clear-filters').classList.add('hide');
    }

    fetchSubscriptions(null, null, "filter");
  });
});

function clearFilters() {
  const searchInput = document.querySelector("#search");
  searchInput.value = "";
  activeFilters['categories'] = [];
  activeFilters['members'] = [];
  activeFilters['payments'] = [];
  activeFilters['state'] = "";
  activeFilters['renewalType'] = "";
  
  document.querySelectorAll('.filter-item').forEach(function (item) {
    item.classList.remove('selected');
  });
  document.querySelector('#clear-filters').classList.add('hide');
  fetchSubscriptions(null, null, "clearfilters");
}

let currentActions = null;

document.addEventListener('click', function (event) {
  // Check if click was outside currentActions
  if (currentActions && !currentActions.contains(event.target)) {
    // Click was outside currentActions, close currentActions
    currentActions.classList.remove('is-open');
    const currentContainer = currentActions.closest('.subscription-container');
    if (currentContainer) {
      currentContainer.classList.remove('actions-menu-open');
    }
    currentActions = null;
  }
});

function expandActions(event, subscriptionId) {
  event.stopPropagation();
  event.preventDefault();
  const subscriptionDiv = document.querySelector(`.subscription[data-id="${subscriptionId}"]`);
  const actions = subscriptionDiv.querySelector('.actions');
  const subscriptionContainer = subscriptionDiv.closest('.subscription-container');

  // Close all other open actions
  const allActions = document.querySelectorAll('.actions.is-open');
  allActions.forEach((openAction) => {
    if (openAction !== actions) {
      openAction.classList.remove('is-open');
      const openContainer = openAction.closest('.subscription-container');
      if (openContainer) {
        openContainer.classList.remove('actions-menu-open');
      }
    }
  });

  // Toggle the clicked actions
  const shouldOpen = !actions.classList.contains('is-open');
  actions.classList.toggle('is-open');
  if (subscriptionContainer) {
    subscriptionContainer.classList.toggle('actions-menu-open', shouldOpen);
  }

  // Update currentActions
  if (shouldOpen) {
    currentActions = actions;
  } else {
    currentActions = null;
  }
}

function swipeHintAnimation() {
  if (window.mobileNavigation && window.matchMedia('(max-width: 768px)').matches) {
    const maxAnimations = 3;
    const cookieName = 'swipeHintCount';

    let count = parseInt(getCookie(cookieName)) || 0;
    if (count < maxAnimations) {
      const firstElement = document.querySelector('.subscription');
      if (firstElement) {
        firstElement.style.transition = 'transform 0.3s ease';
        firstElement.style.transform = 'translateX(-80px)';

        setTimeout(() => {
          firstElement.style.transform = 'translateX(0px)';
          firstElement.style.zIndex = '1';
        }, 600);
      }

      count++;
      document.cookie = `${cookieName}=${count}; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/; SameSite=Lax`;
    }
  }
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
