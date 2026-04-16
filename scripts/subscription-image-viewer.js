(function () {
  const SUBSCRIPTION_IMAGE_VIEWER_SWIPE_THRESHOLD = 50;

  let currentSubscriptionImageViewerSrc = "";
  let currentSubscriptionImageOriginalUrl = "";
  let currentSubscriptionImageDownloadUrl = "";
  let currentSubscriptionImageViewerItems = [];
  let currentSubscriptionImageViewerIndex = -1;
  let currentSubscriptionImageOriginalRequest = null;
  let currentSubscriptionImagePreviewToken = 0;
  let subscriptionImageViewerPreviewProgressTimer = null;
  const prefetchedSubscriptionImageViewerSources = new Set();
  let subscriptionImageViewerTouchStartX = 0;
  let subscriptionImageViewerTouchStartY = 0;
  let buildFormItemsHandler = null;

  function setOriginalProgress(percentage, label) {
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

  function hideOriginalProgress() {
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

  function setPreviewProgress(percentage) {
    const progress = document.querySelector("#subscription-image-viewer-progress");
    const fill = document.querySelector("#subscription-image-viewer-progress-fill");
    if (!progress || !fill) {
      return;
    }

    const normalizedValue = Math.max(0, Math.min(100, Number(percentage || 0)));
    progress.classList.toggle("is-hidden", normalizedValue <= 0);
    fill.style.width = `${normalizedValue}%`;
  }

  function hidePreviewProgress() {
    if (subscriptionImageViewerPreviewProgressTimer) {
      clearInterval(subscriptionImageViewerPreviewProgressTimer);
      subscriptionImageViewerPreviewProgressTimer = null;
    }

    setPreviewProgress(0);
  }

  function startPreviewProgress(loadToken) {
    if (subscriptionImageViewerPreviewProgressTimer) {
      clearInterval(subscriptionImageViewerPreviewProgressTimer);
      subscriptionImageViewerPreviewProgressTimer = null;
    }

    let currentProgress = 14;
    setPreviewProgress(currentProgress);

    subscriptionImageViewerPreviewProgressTimer = setInterval(() => {
      if (loadToken !== currentSubscriptionImagePreviewToken) {
        hidePreviewProgress();
        return;
      }

      currentProgress = Math.min(86, currentProgress + Math.max(2, (88 - currentProgress) * 0.16));
      setPreviewProgress(currentProgress);
    }, 90);
  }

  function finishPreviewProgress(loadToken) {
    if (subscriptionImageViewerPreviewProgressTimer) {
      clearInterval(subscriptionImageViewerPreviewProgressTimer);
      subscriptionImageViewerPreviewProgressTimer = null;
    }

    setPreviewProgress(100);
    window.setTimeout(() => {
      if (loadToken === currentSubscriptionImagePreviewToken) {
        hidePreviewProgress();
      }
    }, 140);
  }

  function prefetchSource(src) {
    const normalizedSrc = String(src || "").trim();
    if (normalizedSrc === "" || prefetchedSubscriptionImageViewerSources.has(normalizedSrc)) {
      return;
    }

    prefetchedSubscriptionImageViewerSources.add(normalizedSrc);
    const image = new Image();
    image.decoding = "async";
    image.src = normalizedSrc;
  }

  function prefetchAdjacentItems() {
    if (!Array.isArray(currentSubscriptionImageViewerItems) || currentSubscriptionImageViewerIndex < 0) {
      return;
    }

    [currentSubscriptionImageViewerIndex - 1, currentSubscriptionImageViewerIndex + 1].forEach((index) => {
      const item = currentSubscriptionImageViewerItems[index];
      if (item?.src) {
        prefetchSource(item.src);
      }
    });
  }

  function openItems(items, startIndex = 0) {
    if (!Array.isArray(items) || items.length === 0) {
      return;
    }

    currentSubscriptionImageViewerItems = items;
    currentSubscriptionImageViewerIndex = Math.max(0, Math.min(startIndex, items.length - 1));
    renderCurrentItem();
  }

  function openFromElement(element) {
    if (!element) {
      return;
    }

    const formPreview = element.closest("#detail-image-gallery");
    if (formPreview) {
      const items = typeof buildFormItemsHandler === "function" ? buildFormItemsHandler() : [];
      const previewButtons = Array.from(formPreview.querySelectorAll(".subscription-detail-image-preview"));
      const index = Math.max(0, previewButtons.indexOf(element));
      openItems(items, index);
      return;
    }

    const gallery = element.closest(".subscription-media-gallery");
    if (gallery) {
      const itemButtons = Array.from(gallery.querySelectorAll(".subscription-media-item"));
      const index = Math.max(0, itemButtons.indexOf(element));
      openItems(getViewerItemsFromGallery(gallery), index);
    }
  }

  function renderCurrentItem() {
    const viewer = document.querySelector("#subscription-image-viewer");
    const viewerContent = document.querySelector("#subscription-image-viewer .subscription-image-viewer-content");
    const preview = document.querySelector("#subscription-image-viewer-preview");
    const openLink = document.querySelector("#subscription-image-viewer-open");
    const downloadLink = document.querySelector("#subscription-image-viewer-download");
    const previousButton = document.querySelector("#subscription-image-viewer-prev");
    const nextButton = document.querySelector("#subscription-image-viewer-next");
    const counter = document.querySelector("#subscription-image-viewer-counter");

    if (!viewer || !viewerContent || !preview || currentSubscriptionImageViewerIndex < 0 || !currentSubscriptionImageViewerItems.length) {
      return;
    }

    const item = currentSubscriptionImageViewerItems[currentSubscriptionImageViewerIndex];
    const loadToken = ++currentSubscriptionImagePreviewToken;
    currentSubscriptionImageViewerSrc = item.src || "";
    currentSubscriptionImageOriginalUrl = item.originalUrl || item.src || "";
    currentSubscriptionImageDownloadUrl = item.downloadUrl || item.src || "";

    if (currentSubscriptionImageOriginalRequest) {
      currentSubscriptionImageOriginalRequest.abort();
      currentSubscriptionImageOriginalRequest = null;
    }

    hideOriginalProgress();
    hidePreviewProgress();
    viewerContent.classList.toggle("is-loading", currentSubscriptionImageViewerSrc !== "");
    preview.alt = item.label || "";
    preview.removeAttribute("src");
    preview.onload = null;
    preview.onerror = null;
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

    if (currentSubscriptionImageViewerSrc === "") {
      viewerContent.classList.remove("is-loading");
      return;
    }

    startPreviewProgress(loadToken);
    preview.onload = () => {
      if (loadToken !== currentSubscriptionImagePreviewToken) {
        return;
      }

      viewerContent.classList.remove("is-loading");
      finishPreviewProgress(loadToken);
      prefetchAdjacentItems();
    };
    preview.onerror = () => {
      if (loadToken !== currentSubscriptionImagePreviewToken) {
        return;
      }

      viewerContent.classList.remove("is-loading");
      hidePreviewProgress();
      showErrorMessage(translate("error"));
    };

    window.requestAnimationFrame(() => {
      if (loadToken !== currentSubscriptionImagePreviewToken) {
        return;
      }

      preview.src = currentSubscriptionImageViewerSrc;
    });
  }

  function close() {
    const viewer = document.querySelector("#subscription-image-viewer");
    const viewerContent = document.querySelector("#subscription-image-viewer .subscription-image-viewer-content");
    const preview = document.querySelector("#subscription-image-viewer-preview");
    const counter = document.querySelector("#subscription-image-viewer-counter");
    const openLink = document.querySelector("#subscription-image-viewer-open");
    const downloadLink = document.querySelector("#subscription-image-viewer-download");
    const previousButton = document.querySelector("#subscription-image-viewer-prev");
    const nextButton = document.querySelector("#subscription-image-viewer-next");

    if (viewer) {
      viewer.classList.remove("is-open");
    }
    if (viewerContent) {
      viewerContent.classList.remove("is-loading");
    }
    if (preview) {
      preview.onload = null;
      preview.onerror = null;
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
    currentSubscriptionImagePreviewToken += 1;
    hidePreviewProgress();
    prefetchedSubscriptionImageViewerSources.clear();
    hideOriginalProgress();
    currentSubscriptionImageViewerItems = [];
    currentSubscriptionImageViewerIndex = -1;
    currentSubscriptionImageViewerSrc = "";
    currentSubscriptionImageOriginalUrl = "";
    currentSubscriptionImageDownloadUrl = "";
  }

  function showPrevious() {
    if (currentSubscriptionImageViewerIndex > 0) {
      currentSubscriptionImageViewerIndex -= 1;
      renderCurrentItem();
    }
  }

  function showNext() {
    if (currentSubscriptionImageViewerIndex >= 0 && currentSubscriptionImageViewerIndex < currentSubscriptionImageViewerItems.length - 1) {
      currentSubscriptionImageViewerIndex += 1;
      renderCurrentItem();
    }
  }

  function openOriginal() {
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

    setOriginalProgress(0, translate("subscription_image_original_loading"));

    request.onprogress = (event) => {
      if (event.lengthComputable && event.total > 0) {
        setOriginalProgress((event.loaded / event.total) * 100, translate("subscription_image_original_loading"));
      } else {
        setOriginalProgress(50, translate("subscription_image_original_loading"));
      }
    };

    request.onload = () => {
      currentSubscriptionImageOriginalRequest = null;

      if (request.status >= 200 && request.status < 300) {
        setOriginalProgress(100, translate("subscription_image_original_loading"));
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
          hideOriginalProgress();
        }, 300);
        return;
      }

      hideOriginalProgress();
      showErrorMessage(translate("error"));
    };

    request.onerror = () => {
      currentSubscriptionImageOriginalRequest = null;
      hideOriginalProgress();
      showErrorMessage(translate("error"));
    };

    request.onabort = () => {
      currentSubscriptionImageOriginalRequest = null;
      hideOriginalProgress();
    };

    request.send();
  }

  function download() {
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

  function handleKeydown(event) {
    const viewer = document.querySelector("#subscription-image-viewer");
    if (!viewer || !viewer.classList.contains("is-open")) {
      return;
    }

    if (event.key === "Escape") {
      close();
    } else if (event.key === "ArrowLeft") {
      showPrevious();
    } else if (event.key === "ArrowRight") {
      showNext();
    }
  }

  function handleTouchStart(event) {
    if (!event.touches || event.touches.length === 0) {
      return;
    }

    subscriptionImageViewerTouchStartX = event.touches[0].clientX;
    subscriptionImageViewerTouchStartY = event.touches[0].clientY;
  }

  function handleTouchEnd(event) {
    if (!event.changedTouches || event.changedTouches.length === 0) {
      return;
    }

    const deltaX = event.changedTouches[0].clientX - subscriptionImageViewerTouchStartX;
    const deltaY = event.changedTouches[0].clientY - subscriptionImageViewerTouchStartY;

    if (Math.abs(deltaX) < SUBSCRIPTION_IMAGE_VIEWER_SWIPE_THRESHOLD || Math.abs(deltaX) <= Math.abs(deltaY)) {
      return;
    }

    if (deltaX > 0) {
      showPrevious();
    } else {
      showNext();
    }
  }

  function initialize(options = {}) {
    buildFormItemsHandler = typeof options.buildFormItems === "function"
      ? options.buildFormItems
      : null;
  }

  window.WallosSubscriptionImageViewer = {
    initialize,
    openItems,
    openFromElement,
    close,
    showPrevious,
    showNext,
    openOriginal,
    download,
    handleKeydown,
    handleTouchStart,
    handleTouchEnd,
  };
})();
