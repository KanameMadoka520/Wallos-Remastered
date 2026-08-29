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
  let committedFilter = "all";
  let selectionRequestSequence = 0;
  let popstateBound = false;
  let pendingSelection = null;
  let documentFallbackPending = false;

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

  function buildFilterUrl(filterValue = null) {
    const url = new URL(window.location.href);
    const nextFilter = normalizeFilter(filterValue ?? getCurrentFilter());

    if (nextFilter === "all") {
      url.searchParams.delete("subscription_page");
    } else {
      url.searchParams.set("subscription_page", nextFilter);
    }

    return `${url.pathname}${url.search}${url.hash}`;
  }

  function getFilterFromUrl() {
    const url = new URL(window.location.href);
    return normalizeFilter(url.searchParams.get("subscription_page") || "all");
  }

  function writeFilterUrl(mode = "replace", filterValue = null) {
    const method = mode === "push" ? "pushState" : "replaceState";
    if (!window.history?.[method]) {
      return false;
    }

    const nextUrl = buildFilterUrl(filterValue);
    const currentUrl = `${window.location.pathname}${window.location.search}${window.location.hash}`;
    if (nextUrl === currentUrl) {
      return false;
    }

    const currentState = window.history.state && typeof window.history.state === "object"
      ? window.history.state
      : {};
    window.history[method]({
      ...currentState,
      wallosSubscriptionPage: normalizeFilter(filterValue ?? getCurrentFilter()),
    }, "", nextUrl);
    return true;
  }

  function updateFilterUrl() {
    return writeFilterUrl("replace");
  }

  function setPageLoadingState(loading) {
    const overlay = document.getElementById("subscription-page-loading-overlay");
    const tabsContainer = document.getElementById("subscription-page-tabs");
    if (overlay) {
      overlay.classList.toggle("is-visible", loading);
      overlay.setAttribute("aria-hidden", loading ? "false" : "true");
    }

    if (tabsContainer) {
      tabsContainer.setAttribute("aria-busy", loading ? "true" : "false");
    }

    const subscriptionsContainer = document.getElementById("subscriptions");
    if (subscriptionsContainer) {
      subscriptionsContainer.classList.toggle("is-page-loading", loading);
      subscriptionsContainer.setAttribute("aria-busy", loading ? "true" : "false");
    }
  }

  function syncTabSelection() {
    const tabsContainer = document.getElementById("subscription-page-tabs");
    if (!tabsContainer) {
      return;
    }

    const activeFilter = getCurrentFilter();
    tabsContainer.dataset.currentFilter = activeFilter;
    tabsContainer.querySelectorAll("[data-page-filter]").forEach((tab) => {
      const isActive = normalizeFilter(tab.dataset.pageFilter || tab.dataset.filter) === activeFilter;
      tab.classList.toggle("is-active", isActive);
      tab.setAttribute("aria-pressed", isActive ? "true" : "false");
    });
  }

  function renderTabs() {
    const tabsContainer = document.getElementById("subscription-page-tabs");
    if (!tabsContainer) {
      return;
    }

    const previousScrollLeft = tabsContainer.scrollLeft;
    const focusedFilter = tabsContainer.contains(document.activeElement)
      ? document.activeElement?.dataset?.pageFilter || document.activeElement?.dataset?.filter || null
      : null;
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
    tabsContainer.dataset.currentFilter = activeFilter;
    tabsContainer.scrollLeft = previousScrollLeft;

    if (focusedFilter !== null) {
      const focusedTab = Array.from(tabsContainer.querySelectorAll("[data-page-filter]"))
        .find((tab) => tab.dataset.pageFilter === focusedFilter);
      if (focusedTab) {
        try {
          focusedTab.focus({ preventScroll: true });
        } catch (error) {
          focusedTab.focus();
        }
        tabsContainer.scrollLeft = previousScrollLeft;
      }
    }
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
    const payloadFilter = payload?.current_filter ?? payload?.currentFilter;
    if (payloadFilter !== undefined && payloadFilter !== null) {
      currentFilter = normalizeFilter(payloadFilter);
    }

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
      if (options.updateUrl !== false) {
        updateFilterUrl();
      }
    }

    if (options.renderTabs !== false) {
      renderTabs();
    } else {
      syncTabSelection();
    }
    if (options.renderManager !== false) {
      renderManagerList();
    }
    if (options.renderSelect !== false) {
      renderSelectOptions(options.selectedValue ?? null);
    }
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

  function runFetchSubscriptions(initiator, filterValue = null) {
    if (typeof fetchSubscriptionsHandler !== "function") {
      return Promise.resolve(null);
    }

    return Promise.resolve().then(() => fetchSubscriptionsHandler(null, null, initiator, {
      subscriptionPageFilter: normalizeFilter(filterValue ?? getCurrentFilter()),
    }));
  }

  function fallbackToDocumentNavigation(filterValue) {
    const nextUrl = buildFilterUrl(filterValue);
    const currentUrl = `${window.location.pathname}${window.location.search}${window.location.hash}`;

    documentFallbackPending = true;
    setPageLoadingState(true);

    if (nextUrl === currentUrl) {
      window.location.reload();
      return;
    }

    window.location.assign(nextUrl);
  }

  function commitAppliedFilter(filterValue, context = {}) {
    const resolvedFilter = normalizeFilter(filterValue);
    const requestedFilter = normalizeFilter(context.requestedFilter ?? resolvedFilter);
    const matchingPendingSelection = pendingSelection
      && pendingSelection.requestedFilter === requestedFilter
      ? pendingSelection
      : null;

    currentFilter = resolvedFilter;
    committedFilter = resolvedFilter;
    syncTabSelection();

    if (matchingPendingSelection) {
      let historyMode = matchingPendingSelection.historyMode;
      if (resolvedFilter !== matchingPendingSelection.requestedFilter) {
        historyMode = "replace";
      }
      if (historyMode !== "none") {
        writeFilterUrl(historyMode, resolvedFilter);
      } else if (getFilterFromUrl() !== resolvedFilter) {
        writeFilterUrl("replace", resolvedFilter);
      }
      pendingSelection = null;
      setPageLoadingState(false);
    } else if (context.canonicalize !== false && getFilterFromUrl() !== resolvedFilter) {
      writeFilterUrl("replace", resolvedFilter);
    }

    return committedFilter;
  }

  function handleFragmentFailure(filterValue, error = null) {
    const failedFilter = normalizeFilter(filterValue);
    if (!pendingSelection || pendingSelection.requestedFilter !== failedFilter) {
      return false;
    }

    const failedSelection = pendingSelection;
    pendingSelection = null;
    currentFilter = committedFilter;
    syncTabSelection();

    if (failedSelection.fallbackNavigation) {
      fallbackToDocumentNavigation(failedSelection.requestedFilter);
      return true;
    }

    setPageLoadingState(false);
    showErrorMessage(normalizeRequestError(error, translate("error")));
    return true;
  }

  function setFilterValue(filterValue, options = {}) {
    const requestedFilter = normalizeFilter(filterValue);
    const shouldFetch = options.fetch !== false;
    const historyMode = options.history
      || (options.updateUrl === false ? "none" : "replace");

    if (
      shouldFetch
      && pendingSelection?.requestedFilter === requestedFilter
      && currentFilter === requestedFilter
    ) {
      return pendingSelection.promise || Promise.resolve({
        applied: false,
        pending: true,
        currentFilter: requestedFilter,
      });
    }

    if (
      shouldFetch
      && options.force !== true
      && requestedFilter === committedFilter
      && currentFilter === committedFilter
    ) {
      syncTabSelection();
      return Promise.resolve({
        applied: false,
        unchanged: true,
        currentFilter: committedFilter,
      });
    }

    currentFilter = requestedFilter;
    syncTabSelection();

    if (!shouldFetch) {
      if (historyMode !== "none") {
        writeFilterUrl(historyMode, currentFilter);
      }
      return Promise.resolve({
        applied: false,
        currentFilter: getCurrentFilter(),
      });
    }

    const requestId = ++selectionRequestSequence;
    pendingSelection = {
      requestId,
      requestedFilter,
      historyMode,
      fallbackNavigation: options.fallbackNavigation !== false,
    };
    setPageLoadingState(true);

    const selectionPromise = runFetchSubscriptions(options.initiator || "subscription-page", requestedFilter)
      .then((result) => {
        if (requestId !== selectionRequestSequence || result?.aborted) {
          return result;
        }

        if (result?.reloadRequired) {
          return result;
        }

        if (!result || result.applied !== true) {
          throw new Error(translate("error"));
        }

        if (pendingSelection?.requestId === requestId) {
          commitAppliedFilter(result.currentFilter ?? getCurrentFilter(), {
            requestedFilter,
          });
        }

        return result;
      })
      .catch((error) => {
        if (requestId !== selectionRequestSequence || error?.name === "AbortError") {
          return {
            applied: false,
            aborted: true,
            currentFilter: getCurrentFilter(),
          };
        }

        handleFragmentFailure(requestedFilter, error);
        return {
          applied: false,
          error,
          currentFilter: committedFilter,
        };
      })
      .finally(() => {
        if (
          requestId === selectionRequestSequence
          && pendingSelection === null
          && !documentFallbackPending
        ) {
          setPageLoadingState(false);
        }
      });
    pendingSelection.promise = selectionPromise;
    return selectionPromise;
  }

  function handleHistoryNavigation() {
    setFilterValue(getFilterFromUrl(), {
      history: "none",
      fallbackNavigation: true,
      initiator: "subscription-page-history",
    });
  }

  function selectFilter(filterValue) {
    return setFilterValue(filterValue, {
      history: "push",
      fallbackNavigation: true,
      initiator: "subscription-page",
    });
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

    const deletedCurrentPage = String(pageId) === currentFilter;
    submitAction({ action: "delete", page_id: pageId }, { selectedValue: getDefaultSelection() })
      .then(() => {
        if (deletedCurrentPage) {
          return setFilterValue("unassigned", {
            history: "replace",
            fallbackNavigation: true,
            initiator: "subscription-page-delete",
          });
        }
        return runFetchSubscriptions("subscription-page-delete", getCurrentFilter());
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

    const requestedUrlFilter = getFilterFromUrl();
    currentFilter = normalizeFilter(options.state?.currentFilter ?? window.subscriptionPageState?.currentFilter ?? "all");
    applyPayload(options.state ?? window.subscriptionPageState ?? {}, {
      selectedValue: options.selectedValue ?? getDefaultSelection(),
    });
    committedFilter = getCurrentFilter();
    if (requestedUrlFilter !== committedFilter) {
      writeFilterUrl("replace", committedFilter);
    }

    if (!popstateBound) {
      window.addEventListener("popstate", handleHistoryNavigation);
      popstateBound = true;
    }
  }

  window.WallosSubscriptionPages = {
    initialize,
    getState,
    getStrings,
    normalizeFilter,
    getDefaultSelection,
    getCurrentFilter,
    commitAppliedFilter,
    handleFragmentFailure,
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
