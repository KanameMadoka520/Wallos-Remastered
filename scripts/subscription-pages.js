(function () {
  const DEFAULT_STRINGS = {
    pagesTitle: "Subscription Pages",
    manage: "Manage Pages",
    all: "All",
    unassigned: "Unassigned",
    fieldLabel: "Subscription Page",
    add: "Add Page",
    empty: "No custom pages yet. Create one above.",
    namePlaceholder: "New page name",
    deleteConfirm: "Delete this page now? Subscriptions inside it will move to Unassigned.",
    saveAction: "Save Name",
    deleteAction: "Delete Page",
    manageHint: "After editing a page name, click \"Save Name\". Deleting a page only moves subscriptions back to \"Unassigned\".",
    dragHandleTitle: "Drag to reorder pages",
  };
  const SUBSCRIPTION_PAGES_ENDPOINT = "endpoints/subscriptionpages.php";

  let currentFilter = "all";
  let pages = [];
  let counts = {
    all: 0,
    unassigned: 0,
  };
  let managerSortable = null;
  let fetchSubscriptionsHandler = null;
  let loadingRequestCount = 0;

  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function normalizeRequestError(error, fallbackMessage = null) {
    if (window.WallosHttp?.normalizeError) {
      return window.WallosHttp.normalizeError(error, fallbackMessage || translate("unknown_error"));
    }

    if (error instanceof Error && String(error.message || "").trim() !== "") {
      return error.message.trim();
    }

    return fallbackMessage || translate("unknown_error");
  }

  function getStrings() {
    return {
      ...DEFAULT_STRINGS,
      ...(window.subscriptionPageStrings || {}),
    };
  }

  function normalizeFilter(value) {
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

  function getDefaultSelection() {
    return /^\d+$/.test(currentFilter) ? currentFilter : "";
  }

  function getCurrentFilter() {
    return normalizeFilter(currentFilter);
  }

  function updateFilterUrl() {
    if (!window.history?.replaceState) {
      return;
    }

    const url = new URL(window.location.href);
    const filterValue = getCurrentFilter();
    if (filterValue === "all") {
      url.searchParams.delete("subscription_page");
    } else {
      url.searchParams.set("subscription_page", filterValue);
    }

    window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash}`);
  }

  function setPageLoadingState(loading) {
    const overlay = document.getElementById("subscription-page-loading-overlay");
    const tabsContainer = document.getElementById("subscription-page-tabs");
    if (!overlay) {
      return;
    }

    overlay.classList.toggle("is-visible", loading);
    overlay.setAttribute("aria-hidden", loading ? "false" : "true");

    if (tabsContainer) {
      tabsContainer.setAttribute("aria-busy", loading ? "true" : "false");
    }
  }

  function renderTabs() {
    const tabsContainer = document.getElementById("subscription-page-tabs");
    if (!tabsContainer) {
      return;
    }

    const strings = getStrings();
    const activeFilter = getCurrentFilter();
    const tabItems = [
      {
        filter: "all",
        label: strings.all,
        count: Number(counts.all || 0),
      },
      ...pages.map((page) => ({
        filter: String(page.id),
        label: page.name || strings.fieldLabel,
        count: Number(page.subscription_count || 0),
      })),
      {
        filter: "unassigned",
        label: strings.unassigned,
        count: Number(counts.unassigned || 0),
      },
    ];

    tabsContainer.innerHTML = tabItems.map((item) => `
      <button type="button" class="subscription-page-tab${activeFilter === item.filter ? " is-active" : ""}"
        data-page-filter="${escapeHtml(item.filter)}"
        aria-pressed="${activeFilter === item.filter ? "true" : "false"}"
        data-subscription-action="select-page-filter"
        data-filter="${escapeHtml(item.filter)}">
        <span>${escapeHtml(item.label)}</span>
        <span class="section-count-badge">${Number(item.count || 0)}</span>
      </button>
    `).join("");
  }

  function renderSelectOptions(selectedValue = null) {
    const select = document.getElementById("subscription_page_id");
    if (!select) {
      return;
    }

    const strings = getStrings();
    const preservedValue = selectedValue !== null
      ? String(selectedValue)
      : (select.value || getDefaultSelection());

    const optionsHtml = [
      `<option value="">${escapeHtml(strings.unassigned)}</option>`,
      ...pages.map((page) => `<option value="${Number(page.id)}">${escapeHtml(page.name || strings.fieldLabel)}</option>`),
    ];

    select.innerHTML = optionsHtml.join("");
    select.value = pages.some((page) => String(page.id) === preservedValue)
      ? preservedValue
      : "";
  }

  function destroyManagerSortable() {
    if (managerSortable) {
      managerSortable.destroy();
      managerSortable = null;
    }
  }

  function renderManagerList() {
    const list = document.getElementById("subscription-pages-manager-list");
    if (!list) {
      return;
    }

    const strings = getStrings();
    destroyManagerSortable();

    if (!pages.length) {
      list.innerHTML = `<div class="subscription-pages-manager-empty">${escapeHtml(strings.empty)}</div>`;
      return;
    }

    list.innerHTML = pages.map((page) => `
      <div class="subscription-pages-manager-item" data-page-id="${Number(page.id)}">
        <div class="subscription-pages-manager-item-main">
          <button type="button" class="subscription-page-drag-handle" title="${escapeHtml(strings.dragHandleTitle)}" aria-label="${escapeHtml(strings.dragHandleTitle)}">
            <i class="fa-solid fa-grip-vertical"></i>
          </button>
          <input type="text" class="subscription-page-name-input"
            value="${escapeHtml(page.name || "")}"
            maxlength="40">
          <span class="section-count-badge">${Number(page.subscription_count || 0)}</span>
        </div>
        <div class="subscription-pages-manager-item-actions">
          <button type="button" class="button secondary-button thin" data-subscription-action="save-page">
            <i class="fa-solid fa-floppy-disk"></i>
            <span>${escapeHtml(strings.saveAction)}</span>
          </button>
          <button type="button" class="button secondary-button thin danger" data-subscription-action="delete-page">
            <i class="fa-solid fa-trash-can"></i>
            <span>${escapeHtml(strings.deleteAction)}</span>
          </button>
        </div>
      </div>
    `).join("");

    initializeManagerSortable();
  }

  function getState() {
    return {
      currentFilter: getCurrentFilter(),
      pages: pages.map((page) => ({ ...page })),
      counts: {
        all: Number(counts.all || 0),
        unassigned: Number(counts.unassigned || 0),
      },
    };
  }

  function applyPayload(payload, options = {}) {
    pages = Array.isArray(payload?.pages)
      ? payload.pages.map((page) => ({
        id: Number(page.id || 0),
        name: String(page.name || ""),
        sort_order: Number(page.sort_order || 0),
        subscription_count: Number(page.subscription_count || 0),
      }))
      : [];

    counts = {
      all: Number(payload?.counts?.all || 0),
      unassigned: Number(payload?.counts?.unassigned || 0),
    };

    if (/^\d+$/.test(currentFilter) && !pages.some((page) => String(page.id) === currentFilter)) {
      currentFilter = "all";
      updateFilterUrl();
    }

    renderTabs();
    renderManagerList();
    renderSelectOptions(options.selectedValue ?? null);
    return getState();
  }

  function requestPages(method, payload = null) {
    if (method === "GET") {
      return window.WallosHttp.getJson(SUBSCRIPTION_PAGES_ENDPOINT, {
        includeCsrf: false,
        fallbackErrorMessage: translate("unknown_error"),
      });
    }

    return window.WallosHttp.postJson(SUBSCRIPTION_PAGES_ENDPOINT, payload || {}, {
      fallbackErrorMessage: translate("unknown_error"),
    });
  }

  function persistOrder(pageIds) {
    return requestPages("POST", {
      action: "reorder",
      page_ids: pageIds,
    }).then((data) => {
      if (!data || typeof data !== "object") {
        throw new Error(translate("unknown_error"));
      }

      if (!data.success) {
        throw new Error(data.message || translate("error"));
      }

      applyPayload(data, {
        selectedValue: document.querySelector("#subscription_page_id")?.value || getDefaultSelection(),
      });
      return data;
    });
  }

  function initializeManagerSortable() {
    const list = document.getElementById("subscription-pages-manager-list");
    if (!list || typeof Sortable === "undefined") {
      return;
    }

    if (list.querySelectorAll(".subscription-pages-manager-item").length <= 1) {
      return;
    }

    managerSortable = new Sortable(list, {
      animation: 180,
      handle: ".subscription-page-drag-handle",
      draggable: ".subscription-pages-manager-item",
      ghostClass: "subscription-pages-manager-item-ghost",
      chosenClass: "subscription-pages-manager-item-chosen",
      dragClass: "subscription-pages-manager-item-dragging",
      onEnd() {
        const orderedPageIds = Array.from(list.querySelectorAll(".subscription-pages-manager-item"))
          .map((item) => Number(item.dataset.pageId || 0))
          .filter((pageId) => pageId > 0);

        persistOrder(orderedPageIds).catch((error) => {
          showErrorMessage(normalizeRequestError(error, translate("error")));
          renderManagerList();
        });
      },
    });
  }

  function refresh(options = {}) {
    return requestPages("GET")
      .then((data) => {
        if (!data || typeof data !== "object") {
          throw new Error(translate("unknown_error"));
        }

        if (!data.success) {
          throw new Error(data.message || translate("error"));
        }

        applyPayload(data, options);
        return data;
      })
      .catch((error) => {
        if (!options.silent) {
          showErrorMessage(normalizeRequestError(error, translate("error")));
        }
        throw error;
      });
  }

  function submitAction(payload, options = {}) {
    return requestPages("POST", payload)
      .then((data) => {
        if (!data || typeof data !== "object") {
          throw new Error(translate("unknown_error"));
        }

        if (!data.success) {
          throw new Error(data.message || translate("error"));
        }

        applyPayload(data, options);
        showSuccessMessage(data.message || translate("success"));
        return data;
      })
      .catch((error) => {
        showErrorMessage(normalizeRequestError(error, translate("error")));
        throw error;
      });
  }

  function runFetchSubscriptions(initiator) {
    if (typeof fetchSubscriptionsHandler !== "function") {
      return Promise.resolve(null);
    }

    return Promise.resolve(fetchSubscriptionsHandler(null, null, initiator));
  }

  function setFilterValue(filterValue, options = {}) {
    currentFilter = normalizeFilter(filterValue);
    renderTabs();

    if (options.updateUrl !== false) {
      updateFilterUrl();
    }

    if (options.fetch !== false) {
      loadingRequestCount += 1;
      setPageLoadingState(true);

      return runFetchSubscriptions("subscription-page")
        .catch(() => {
          showErrorMessage(translate("error"));
        })
        .finally(() => {
          loadingRequestCount = Math.max(0, loadingRequestCount - 1);
          if (loadingRequestCount === 0) {
            setPageLoadingState(false);
          }
        });
    }

    return Promise.resolve(null);
  }

  function selectFilter(filterValue) {
    return setFilterValue(filterValue);
  }

  function openManager(event) {
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
    renderManagerList();
  }

  function closeManager() {
    const modal = document.getElementById("subscription-pages-manager-modal");
    if (!modal) {
      return;
    }

    destroyManagerSortable();
    modal.classList.remove("is-open");
    if (!document.querySelector(".subscription-form.is-open, .subscription-modal.is-open, .subscription-image-viewer.is-open")) {
      document.body.classList.remove("no-scroll");
    }
  }

  function createPage() {
    const input = document.getElementById("subscription-page-create-name");
    if (!input) {
      return;
    }

    submitAction({ action: "create", name: input.value.trim() }, { selectedValue: getDefaultSelection() })
      .then(() => {
        input.value = "";
      })
      .catch(() => {});
  }

  function renamePage(pageId, button = null) {
    const pageRow = document.querySelector(`.subscription-pages-manager-item[data-page-id="${pageId}"]`);
    const input = pageRow?.querySelector(".subscription-page-name-input");
    if (!input || pageId <= 0) {
      return;
    }

    if (button) {
      button.disabled = true;
    }

    submitAction(
      { action: "update", page_id: pageId, name: input.value },
      { selectedValue: getDefaultSelection() }
    ).finally(() => {
      if (button) {
        button.disabled = false;
      }
    });
  }

  function deletePage(pageId) {
    if (pageId <= 0) {
      return;
    }

    if (!confirm(getStrings().deleteConfirm)) {
      return;
    }

    submitAction({ action: "delete", page_id: pageId }, { selectedValue: getDefaultSelection() })
      .then(() => {
        if (String(pageId) === currentFilter) {
          currentFilter = "unassigned";
          updateFilterUrl();
        }
        return runFetchSubscriptions("subscription-page-delete");
      })
      .catch(() => {});
  }

  function initialize(options = {}) {
    if (typeof options.fetchSubscriptions === "function") {
      fetchSubscriptionsHandler = options.fetchSubscriptions;
    }

    const overlay = document.getElementById("subscription-page-loading-overlay");
    if (overlay && overlay.parentElement !== document.body && document.body) {
      document.body.appendChild(overlay);
    }

    currentFilter = normalizeFilter(options.state?.currentFilter ?? window.subscriptionPageState?.currentFilter ?? "all");
    applyPayload(options.state ?? window.subscriptionPageState ?? {}, {
      selectedValue: options.selectedValue ?? getDefaultSelection(),
    });
  }

  window.WallosSubscriptionPages = {
    initialize,
    getState,
    getStrings,
    normalizeFilter,
    getDefaultSelection,
    getCurrentFilter,
    setFilterValue,
    selectFilter,
    renderTabs,
    renderSelectOptions,
    renderManagerList,
    applyPayload,
    refresh,
    openManager,
    closeManager,
    createPage,
    renamePage,
    deletePage,
  };
})();
