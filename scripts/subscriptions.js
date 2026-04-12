let isSortOptionsOpen = false;
let scrollTopBeforeOpening = 0;
const shouldScroll = window.innerWidth <= 768;
const SUBSCRIPTION_IMAGE_VIEWER_SWIPE_THRESHOLD = 50;
let currentSubscriptionImageViewerSrc = "";
let currentSubscriptionImageOriginalUrl = "";
let currentSubscriptionImageDownloadUrl = "";
let currentSubscriptionImageViewerItems = [];
let currentSubscriptionImageViewerIndex = -1;
let currentSubscriptionImageOriginalRequest = null;
let subscriptionImageViewerTouchStartX = 0;
let subscriptionImageViewerTouchStartY = 0;
let detailImageGallerySortable = null;
let detailSubscriptionGallerySortables = [];
let detailImageTempIdCounter = 0;
const SUBSCRIPTION_IMAGE_LAYOUT_STORAGE_KEYS = {
  form: "wallos-subscription-image-layout-form",
  detail: "wallos-subscription-image-layout-detail",
};
const SUBSCRIPTION_DISPLAY_COLUMNS_STORAGE_KEY = "wallos-subscriptions-display-columns";
let subscriptionMasonryLayoutFrame = null;
let subscriptionMasonryResizeTimer = null;
let subscriptionCardSortable = null;
let isSubscriptionSortDragging = false;

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

function getSubscriptionImageLayoutMode(scope) {
  const storageKey = SUBSCRIPTION_IMAGE_LAYOUT_STORAGE_KEYS[scope];
  if (!storageKey) {
    return "focus";
  }

  try {
    const stored = localStorage.getItem(storageKey);
    return stored === "grid" ? "grid" : "focus";
  } catch (error) {
    return "focus";
  }
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
  const storageKey = SUBSCRIPTION_IMAGE_LAYOUT_STORAGE_KEYS[scope];

  if (storageKey) {
    try {
      localStorage.setItem(storageKey, resolvedMode);
    } catch (error) {
      // Ignore localStorage write failures.
    }
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
  try {
    const storedValue = Number(localStorage.getItem(SUBSCRIPTION_DISPLAY_COLUMNS_STORAGE_KEY));
    return storedValue === 2 || storedValue === 3 ? storedValue : 1;
  } catch (error) {
    return 1;
  }
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

  try {
    localStorage.setItem(SUBSCRIPTION_DISPLAY_COLUMNS_STORAGE_KEY, String(resolvedColumns));
  } catch (error) {
    // Ignore localStorage write failures.
  }

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

function mountSubscriptionImageViewerToBody() {
  const viewer = document.querySelector("#subscription-image-viewer");
  if (!viewer || viewer.dataset.mountedToBody === "1" || !document.body) {
    return;
  }

  document.body.appendChild(viewer);
  viewer.dataset.mountedToBody = "1";
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

  const popup = window.open("about:blank", "_blank");
  if (!popup) {
    showErrorMessage(translate("error"));
    return;
  }

  try {
    popup.opener = null;
    popup.document.title = translate("subscription_image_original_loading");
    popup.document.body.innerHTML = `<p style="font-family:sans-serif;padding:16px;">${translate("subscription_image_original_loading")}</p>`;
  } catch (error) {
    // Ignore cross-window setup failures and continue with the image request.
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
      if (!popup.closed) {
        popup.location.replace(blobUrl);
      }

      setTimeout(() => {
        URL.revokeObjectURL(blobUrl);
      }, 60000);
      setTimeout(() => {
        hideOriginalImageProgress();
      }, 300);
      return;
    }

    hideOriginalImageProgress();
    if (popup && !popup.closed) {
      popup.close();
    }
    showErrorMessage(translate("error"));
  };

  request.onerror = () => {
    currentSubscriptionImageOriginalRequest = null;
    hideOriginalImageProgress();
    if (popup && !popup.closed) {
      popup.close();
    }
    showErrorMessage(translate("error"));
  };

  request.onabort = () => {
    currentSubscriptionImageOriginalRequest = null;
    hideOriginalImageProgress();
    if (popup && !popup.closed) {
      popup.close();
    }
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
  const notifyDaysBefore = document.querySelector("#notify_days_before");
  notifyDaysBefore.disabled = true;
  const replacementSubscriptionIdSelect = document.querySelector("#replacement_subscription_id");
  replacementSubscriptionIdSelect.value = "0";
  const replacementSubscription = document.querySelector(`#replacement_subscritpion`);
  replacementSubscription.classList.add("hide");
  const form = document.querySelector("#subs-form");
  form.reset();
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
  const payerSelect = document.querySelector("#payer_user");
  payerSelect.value = subscription.payer_user_id;

  const startDate = document.querySelector("#start_date");
  startDate.value = subscription.start_date;
  const nextPament = document.querySelector("#next_payment");
  nextPament.value = subscription.next_payment;
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

  if (!confirm(translate('confirm_delete_subscription'))) {
    return;
  }

  fetch("endpoints/subscription/delete.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": window.csrfToken,
    },
    body: JSON.stringify({ id: id }),
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        showSuccessMessage(translate('subscription_deleted'));
        fetchSubscriptions(null, null, "delete");
        closeAddSubscription();
      } else {
        showErrorMessage(data.message || translate('error_deleting_subscription'));
      }
    })
    .catch((error) => {
      console.error("Error:", error);
      showErrorMessage(translate('error_deleting_subscription'));
    });
}


function cloneSubscription(event, id) {
  event.stopPropagation();
  event.preventDefault();

  fetch("endpoints/subscription/clone.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": window.csrfToken,
    },
    body: JSON.stringify({ id: id }),
  })
    .then((response) => {
      if (!response.ok) {
        throw new Error(translate("network_response_error"));
      }
      return response.json();
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
      showErrorMessage(error.message || translate("error"));
    });
}


function renewSubscription(event, id) {
  event.stopPropagation();
  event.preventDefault();

  fetch("endpoints/subscription/renew.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": window.csrfToken,
    },
    body: JSON.stringify({ id: id }),
  })
    .then((response) => {
      if (!response.ok) {
        throw new Error(translate("network_response_error"));
      }
      return response.json();
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
      showErrorMessage(error.message || translate("error"));
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
    img.onclick = function () {
      selectWebLogo(src.thumbnail || src.image);
    };
    img.onerror = function () {
      this.parentNode.removeChild(this);
    };
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

  fetch(getSubscriptions)
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

      if (initiator == "clone" && id && event) {
        openEditSubscription(event, id);
      }

      setSwipeElements();
      applySubscriptionDisplayColumns();
      applySubscriptionImageLayoutMode("detail");
      initializeSubscriptionMediaSortables();
      initializeSubscriptionCardSortable();
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
  const subscriptionForm = document.querySelector("#subs-form");
  const submitButton = document.querySelector("#save-button");
  const endpoint = "endpoints/subscription/add.php";

  mountSubscriptionImageViewerToBody();

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
  applySubscriptionDisplayColumns();
  applyAllSubscriptionImageLayoutModes();
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

  fetch("endpoints/subscription/generatevariants.php", {
    method: "POST",
    headers: {
      "X-CSRF-Token": window.csrfToken,
    },
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        showSuccessMessage(data.message);
        fetchSubscriptions(null, null, "variants");
      } else {
        showErrorMessage(data.message || translate("error"));
      }
    })
    .catch(() => showErrorMessage(translate("error")))
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
    currentActions = null;
  }
});

function expandActions(event, subscriptionId) {
  event.stopPropagation();
  event.preventDefault();
  const subscriptionDiv = document.querySelector(`.subscription[data-id="${subscriptionId}"]`);
  const actions = subscriptionDiv.querySelector('.actions');

  // Close all other open actions
  const allActions = document.querySelectorAll('.actions.is-open');
  allActions.forEach((openAction) => {
    if (openAction !== actions) {
      openAction.classList.remove('is-open');
    }
  });

  // Toggle the clicked actions
  actions.classList.toggle('is-open');

  // Update currentActions
  if (actions.classList.contains('is-open')) {
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
