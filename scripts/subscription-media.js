(function () {
  let detailImageGallerySortable = null;
  let detailSubscriptionGallerySortables = [];
  let detailImageTempIdCounter = 0;
  let selectedDetailImageFiles = [];
  let existingUploadedImages = [];
  let removedUploadedImageIds = [];
  let openViewerFromElementHandler = null;
  let applyImageLayoutModeHandler = null;

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

  function setUploadProgress(percentage, label) {
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

  function hideUploadProgress() {
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

  function resetCompression() {
    const compressCheckbox = document.querySelector("#compress_subscription_image");
    const config = getDetailImageConfig();

    if (!compressCheckbox) {
      return;
    }

    compressCheckbox.checked = config.compressionMode !== "disabled";
    compressCheckbox.disabled = config.compressionMode === "disabled";
  }

  function rebuildInput() {
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

  function updateSelectionMeta() {
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

  function updateOrderField() {
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

  function syncStateFromGallery() {
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
    rebuildInput();
    updateOrderField();
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
        syncStateFromGallery();
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

    window.WallosApi.postJson("endpoints/subscription/reorderimages.php", {
        subscriptionId,
        imageIds,
    })
      .then((data) => {
        if (!data.success) {
          showErrorMessage(data.message || translate("error"));
        }
      })
      .catch((error) => showErrorMessage(window.WallosApi?.normalizeError?.(error, translate("error")) || translate("error")));
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

  function getUploadedImageSizeLabel(image, variant) {
    const key = `${variant}_size_label`;
    const value = String(image?.[key] || "").trim();
    if (value !== "") {
      return value;
    }

    return translate("subscription_image_size_unknown");
  }

  function buildUploadedImageSizeSummary(image) {
    if (!image || !image.id) {
      return "";
    }

    return [
      `${translate("subscription_image_variant_thumbnail")}: ${getUploadedImageSizeLabel(image, "thumbnail")}`,
      `${translate("subscription_image_variant_preview")}: ${getUploadedImageSizeLabel(image, "preview")}`,
      `${translate("subscription_image_variant_original")}: ${getUploadedImageSizeLabel(image, "original")}`,
    ].join(" / ");
  }

  function formatClientFileSize(bytes) {
    const normalizedBytes = Math.max(0, Number(bytes || 0));
    if (normalizedBytes <= 0) {
      return translate("subscription_image_size_unknown");
    }

    const units = ["B", "KB", "MB", "GB", "TB"];
    let size = normalizedBytes;
    let unitIndex = 0;

    while (size >= 1024 && unitIndex < units.length - 1) {
      size /= 1024;
      unitIndex += 1;
    }

    return `${unitIndex === 0 ? Math.round(size) : size.toFixed(1)} ${units[unitIndex]}`;
  }

  function buildFormViewerItems() {
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
        sizeLabels: {
          thumbnail: getUploadedImageSizeLabel(image, "thumbnail"),
          preview: getUploadedImageSizeLabel(image, "preview"),
          original: getUploadedImageSizeLabel(image, "original"),
        },
      });
    });

    selectedDetailImageFiles.forEach((file) => {
      const objectUrl = URL.createObjectURL(file);
      items.push({
        src: objectUrl,
        originalUrl: objectUrl,
        downloadUrl: objectUrl,
        label: file.name,
        sizeLabels: {
          thumbnail: translate("subscription_image_size_unknown"),
          preview: translate("subscription_image_size_unknown"),
          original: formatClientFileSize(file.size),
        },
      });
    });

    return items;
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
          sizeSummary: buildUploadedImageSizeSummary(image),
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
          sizeSummary: "",
          extraClassName: "new",
          orderToken: `new:${ensureSelectedDetailImageFileToken(file)}`,
          onRemove: () => removeSelectedDetailImage(index),
        }),
      );
    });

    updateSelectionMeta();
    updateOrderField();
    if (typeof applyImageLayoutModeHandler === "function") {
      applyImageLayoutModeHandler("form");
    }
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
    sizeSummary = "",
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
      if (typeof openViewerFromElementHandler === "function") {
        openViewerFromElementHandler(previewButton);
      }
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

    const sizeElement = document.createElement("span");
    sizeElement.className = "subscription-detail-image-size-summary";
    sizeElement.textContent = sizeSummary;
    sizeElement.hidden = sizeSummary === "";

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
    meta.appendChild(sizeElement);
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

    resetCompression();
    hideUploadProgress();
    rebuildInput();
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
      rebuildInput();
      return;
    }

    selectedDetailImageFiles = selectedDetailImageFiles.concat(validFiles);
    fileInput.value = "";
    rebuildInput();
    renderDetailImageGallery();
  }

  function removeSelectedDetailImage(index) {
    selectedDetailImageFiles.splice(index, 1);
    rebuildInput();
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

  function setExistingUploadedImages(images, options = {}) {
    const preserveSelected = options.preserveSelected === true;
    existingUploadedImages = Array.isArray(images)
      ? images
        .filter((image) => image && (image.access_url || image.path))
        .map((image) => ({ ...image, id: Number(image.id) }))
      : [];
    removedUploadedImageIds = [];

    if (!preserveSelected) {
      selectedDetailImageFiles = [];
      rebuildInput();
    }

    const removeUploadedImageIdsInput = document.querySelector("#remove-uploaded-image-ids");
    if (removeUploadedImageIdsInput) {
      removeUploadedImageIdsInput.value = "";
    }

    renderDetailImageGallery();
  }

  function getSelectedDetailImageFiles() {
    return [...selectedDetailImageFiles];
  }

  function getRemovedUploadedImageIds() {
    return [...removedUploadedImageIds];
  }

  function initialize(options = {}) {
    openViewerFromElementHandler = typeof options.openViewerFromElement === "function"
      ? options.openViewerFromElement
      : null;
    applyImageLayoutModeHandler = typeof options.applyImageLayoutMode === "function"
      ? options.applyImageLayoutMode
      : null;
  }

  window.WallosSubscriptionMedia = {
    initialize,
    getDetailImageConfig,
    setUploadProgress,
    hideUploadProgress,
    resetCompression,
    initializeSubscriptionMediaSortables,
    buildFormViewerItems,
    resetDetailImageControls,
    handleDetailImageSelect,
    setExistingUploadedImages,
    getSelectedDetailImageFiles,
    getRemovedUploadedImageIds,
  };
})();
