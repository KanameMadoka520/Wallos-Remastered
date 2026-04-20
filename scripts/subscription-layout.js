(function () {
  let subscriptionMasonryLayoutFrame = null;
  let subscriptionMasonryResizeTimer = null;
  let subscriptionCardSortable = null;
  let isSubscriptionSortDragging = false;
  let canReorderHandler = null;
  let persistOrderHandler = null;
  let persistManualPreferenceHandler = null;

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

  function updateSubscriptionReorderState() {
    const container = document.querySelector("#subscriptions");
    const enabled = !!container && (typeof canReorderHandler === "function" ? canReorderHandler() : false);

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
      disabled: !(typeof canReorderHandler === "function" ? canReorderHandler() : false),
      onStart: () => {
        isSubscriptionSortDragging = true;
        container.classList.add("is-sorting");
      },
      onEnd: () => {
        isSubscriptionSortDragging = false;
        container.classList.remove("is-sorting");
        if (typeof persistOrderHandler === "function") {
          persistOrderHandler();
        }
        scheduleSubscriptionMasonryLayout();
      },
    });

    updateSubscriptionReorderState();
  }

  function initialize(options = {}) {
    canReorderHandler = typeof options.canReorder === "function" ? options.canReorder : null;
    persistOrderHandler = typeof options.persistOrder === "function" ? options.persistOrder : null;
    persistManualPreferenceHandler = typeof options.persistManualPreference === "function" ? options.persistManualPreference : null;
  }

  window.WallosSubscriptionLayout = {
    initialize,
    applySubscriptionMasonryLayout,
    scheduleSubscriptionMasonryLayout,
    handleSubscriptionMasonryResize,
    updateSubscriptionReorderState,
    initializeSubscriptionCardSortable,
  };
})();
