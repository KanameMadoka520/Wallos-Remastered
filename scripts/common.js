let isDropdownOpen = false;
let hasUserInteractedWithPage = false;
let lastRateLimitNoticeId = null;
const toastState = {
  error: { hideTimer: null, progressTimer: null },
  success: { hideTimer: null, progressTimer: null },
};
const requestQueueNoticeState = {
  nextRequestId: 0,
  pendingCount: 0,
  hideTimer: null,
};
const REQUEST_QUEUE_NOTICE_DELAY = 650;
const REQUEST_QUEUE_SUCCESS_HIDE_DELAY = 1400;
const REQUEST_QUEUE_TRACKED_METHODS = new Set(["POST", "PUT", "PATCH", "DELETE"]);
const CLIENT_ANOMALY_ENDPOINT = "endpoints/client/loganomaly.php";
const CLIENT_ANOMALY_DEDUPE_WINDOW_MS = 30000;
const CSRF_BACKGROUND_STALE_MS = 30 * 60 * 1000;
const CSRF_REMINDER_DEDUPE_MS = 60 * 1000;
const recentClientAnomalyFingerprints = new Map();
let csrfBackgroundHiddenAt = 0;
let csrfRefreshReminderLastShownAt = 0;
let csrfRefreshPromptOpen = false;

function getPreferredUiLanguage() {
  const languageCookie = getCookie("language");
  if (languageCookie) {
    return String(languageCookie).toLowerCase();
  }

  return String(navigator.language || "").toLowerCase();
}

function translateWithFallback(key, fallback, localizedFallbacks = null) {
  if (typeof translate === "function") {
    try {
      const translated = translate(key);
      if (translated && translated !== "[Translation Missing]") {
        return translated;
      }
    } catch (error) {
      // Fall back to the provided copy below.
    }
  }

  if (localizedFallbacks && typeof localizedFallbacks === "object") {
    const uiLanguage = getPreferredUiLanguage();
    if (uiLanguage.startsWith("zh_tw") || uiLanguage.startsWith("zh-hk")) {
      return localizedFallbacks.zh_tw || localizedFallbacks.zh_cn || localizedFallbacks.en || fallback;
    }

    if (uiLanguage.startsWith("zh")) {
      return localizedFallbacks.zh_cn || localizedFallbacks.zh_tw || localizedFallbacks.en || fallback;
    }

    return localizedFallbacks.en || fallback;
  }

  return fallback;
}

function isPublicAuthPage() {
  const pathname = String(window.location.pathname || "").toLowerCase();
  return pathname.endsWith("/login.php") || pathname.endsWith("/registration.php");
}

function getCsrfTokenRefreshReminderMessage() {
  return translateWithFallback(
    "csrf_token_refresh_reminder",
    "【提醒】为防止跨站请求伪造，invalid CSRF token，请刷新本页面以重载页面安全令牌。",
    {
      en: "[Reminder] To prevent cross-site request forgery, the CSRF token may be invalid. Please refresh this page to reload the page security token.",
      zh_cn: "【提醒】为防止跨站请求伪造，invalid CSRF token，请刷新本页面以重载页面安全令牌。",
      zh_tw: "【提醒】為防止跨站請求偽造，invalid CSRF token，請重新整理本頁面以重新載入頁面安全令牌。",
    }
  );
}

function getCsrfTokenRefreshActionMessage() {
  return translateWithFallback(
    "csrf_token_refresh_action",
    "点击“确定”立即刷新页面；点击“取消”后，请在继续编辑前手动刷新。",
    {
      en: "Click OK to refresh this page now. If you click Cancel, refresh manually before continuing to edit.",
      zh_cn: "点击“确定”立即刷新页面；点击“取消”后，请在继续编辑前手动刷新。",
      zh_tw: "點擊「確定」立即重新整理頁面；點擊「取消」後，請在繼續編輯前手動重新整理。",
    }
  );
}

function showCsrfTokenRefreshReminder() {
  if (!window.csrfToken || isPublicAuthPage()) {
    return false;
  }

  const now = Date.now();
  if ((now - csrfRefreshReminderLastShownAt) < CSRF_REMINDER_DEDUPE_MS) {
    return false;
  }

  csrfRefreshReminderLastShownAt = now;
  const message = getCsrfTokenRefreshReminderMessage();
  showErrorMessage(message);

  if (csrfRefreshPromptOpen) {
    return true;
  }

  window.setTimeout(() => {
    if (csrfRefreshPromptOpen) {
      return;
    }

    csrfRefreshPromptOpen = true;
    const shouldReload = window.confirm(`${message}\n\n${getCsrfTokenRefreshActionMessage()}`);
    csrfRefreshPromptOpen = false;
    if (shouldReload) {
      window.location.reload();
    }
  }, 80);

  return true;
}

function maybeShowCsrfBackgroundReminder() {
  if (!csrfBackgroundHiddenAt) {
    return;
  }

  const hiddenDuration = Date.now() - csrfBackgroundHiddenAt;
  csrfBackgroundHiddenAt = 0;

  if (hiddenDuration >= CSRF_BACKGROUND_STALE_MS) {
    showCsrfTokenRefreshReminder();
  }
}

function getRequestQueueNoticeMessages(status, pendingCount = 0) {
  if (status === "success") {
    return {
      title: translateWithFallback("request_queue_success_title", "处理完成", {
        en: "Processing complete",
        zh_cn: "处理完成",
        zh_tw: "處理完成",
      }),
      body: translateWithFallback("request_queue_success_body", "排队中的后台写入已经处理完成。", {
        en: "Queued backend writes have finished.",
        zh_cn: "排队中的后台写入已经处理完成。",
        zh_tw: "排隊中的後台寫入已處理完成。",
      }),
    };
  }

  const multipleFallback = `当前仍有 ${pendingCount} 个写入请求等待完成。`;
  const body = pendingCount > 1
    ? translateWithFallback("request_queue_pending_body_multiple", multipleFallback, {
      en: `%1$d write requests are still waiting to finish.`,
      zh_cn: "当前仍有 %1$d 个写入请求等待完成。",
      zh_tw: "目前仍有 %1$d 個寫入請求等待完成。",
    }).replace("%1$d", String(pendingCount))
    : translateWithFallback("request_queue_pending_body", "后台写入较忙，正在排队处理，请稍候。", {
      en: "The server is busy writing data. Your request is queued and will finish shortly.",
      zh_cn: "服务器写入较忙，当前请求正在排队处理，请稍候。",
      zh_tw: "伺服器寫入較忙，目前請求正在排隊處理，請稍候。",
    });

  return {
    title: translateWithFallback("request_queue_pending_title", "后台操作排队处理中", {
      en: "Backend queue in progress",
      zh_cn: "后台操作排队处理中",
      zh_tw: "後台操作排隊處理中",
    }),
    body,
  };
}

function getRequestQueueNoticeElement() {
  if (!document.body) {
    return null;
  }

  let notice = document.getElementById("requestQueueNotice");
  if (notice) {
    return notice;
  }

  notice = document.createElement("div");
  notice.id = "requestQueueNotice";
  notice.className = "request-queue-notice";
  notice.setAttribute("role", "status");
  notice.setAttribute("aria-live", "polite");
  notice.innerHTML = `
    <div class="request-queue-notice__badge" aria-hidden="true">
      <span class="request-queue-notice__spinner"></span>
      <i class="fa-solid fa-check request-queue-notice__check"></i>
    </div>
    <div class="request-queue-notice__message">
      <span class="request-queue-notice__title"></span>
      <span class="request-queue-notice__body"></span>
    </div>
  `;

  document.body.appendChild(notice);
  return notice;
}

function clearRequestQueueNoticeHideTimer() {
  if (requestQueueNoticeState.hideTimer) {
    window.clearTimeout(requestQueueNoticeState.hideTimer);
    requestQueueNoticeState.hideTimer = null;
  }
}

function hideRequestQueueNotice() {
  const notice = document.getElementById("requestQueueNotice");
  clearRequestQueueNoticeHideTimer();

  if (!notice) {
    return;
  }

  notice.classList.remove("active", "is-pending", "is-success");
}

function renderRequestQueueNotice(status, pendingCount = 0) {
  const notice = getRequestQueueNoticeElement();
  if (!notice) {
    return;
  }

  const titleNode = notice.querySelector(".request-queue-notice__title");
  const bodyNode = notice.querySelector(".request-queue-notice__body");
  const copy = getRequestQueueNoticeMessages(status, pendingCount);

  if (!titleNode || !bodyNode) {
    return;
  }

  clearRequestQueueNoticeHideTimer();
  titleNode.textContent = copy.title;
  bodyNode.textContent = copy.body;
  notice.classList.add("active");
  notice.classList.toggle("is-pending", status === "pending");
  notice.classList.toggle("is-success", status === "success");
}

function shouldTrackRequestQueueNotice(method, options = {}) {
  if (options.queueNotice === false) {
    return false;
  }

  if (options.queueNotice === true) {
    return true;
  }

  return REQUEST_QUEUE_TRACKED_METHODS.has(String(method || "GET").toUpperCase());
}

function startRequestQueueTracking(method, options = {}) {
  if (!shouldTrackRequestQueueNotice(method, options)) {
    return null;
  }

  const delay = Number.isFinite(Number(options.queueNoticeDelay))
    ? Math.max(0, Number(options.queueNoticeDelay))
    : REQUEST_QUEUE_NOTICE_DELAY;
  const tracker = {
    id: ++requestQueueNoticeState.nextRequestId,
    visible: false,
    settled: false,
    timerId: null,
  };

  tracker.timerId = window.setTimeout(() => {
    if (tracker.settled) {
      return;
    }

    tracker.visible = true;
    requestQueueNoticeState.pendingCount += 1;
    renderRequestQueueNotice("pending", requestQueueNoticeState.pendingCount);
  }, delay);

  return tracker;
}

function finalizeRequestQueueTracking(tracker, succeeded) {
  if (!tracker || tracker.settled) {
    return;
  }

  tracker.settled = true;
  if (tracker.timerId) {
    window.clearTimeout(tracker.timerId);
    tracker.timerId = null;
  }

  if (!tracker.visible) {
    return;
  }

  requestQueueNoticeState.pendingCount = Math.max(0, requestQueueNoticeState.pendingCount - 1);

  if (requestQueueNoticeState.pendingCount > 0) {
    renderRequestQueueNotice("pending", requestQueueNoticeState.pendingCount);
    return;
  }

  if (!succeeded) {
    hideRequestQueueNotice();
    return;
  }

  renderRequestQueueNotice("success", 0);
  requestQueueNoticeState.hideTimer = window.setTimeout(() => {
    hideRequestQueueNotice();
  }, REQUEST_QUEUE_SUCCESS_HIDE_DELAY);
}

function didWallosRequestSucceed(response, data) {
  if (!response || !response.ok) {
    return false;
  }

  if (data && typeof data === "object" && Object.prototype.hasOwnProperty.call(data, "success")) {
    return Boolean(data.success);
  }

  return true;
}

function rgbStringToHex(rgbString) {
  const matches = String(rgbString || "").match(/\d+/g);
  if (!matches || matches.length < 3) {
    return "";
  }

  const [r, g, b] = matches.slice(0, 3).map((value) => {
    const normalized = Math.max(0, Math.min(255, Number.parseInt(value, 10) || 0));
    return normalized.toString(16).padStart(2, "0");
  });

  return `#${r}${g}${b}`.toUpperCase();
}

function updateThemeColorMetaTag() {
  const themeColorMetaTag = document.querySelector('meta[name="theme-color"]');
  if (!themeColorMetaTag || !document.documentElement || !document.body) {
    return;
  }

  const computed = window.getComputedStyle(document.documentElement);
  let rgbSource = "";

  if (document.body.classList.contains("dynamic-wallpaper-enabled")) {
    rgbSource = computed.getPropertyValue("--feedback-surface-rgb");
  } else if (document.body.classList.contains("dark")) {
    rgbSource = computed.getPropertyValue("--header-background-color-rgb")
      || computed.getPropertyValue("--box-background-color-rgb");
  } else {
    rgbSource = computed.getPropertyValue("--main-color-rgb")
      || computed.getPropertyValue("--header-background-color-rgb");
  }

  const resolvedColor = rgbStringToHex(rgbSource);
  if (resolvedColor) {
    themeColorMetaTag.setAttribute("content", resolvedColor);
  }
}

function toggleDropdown() {
  const dropdown = document.querySelector('.dropdown');
  dropdown.classList.toggle('is-open');
  isDropdownOpen = !isDropdownOpen;
}

function setupPageNavigation() {
  const pageNavs = document.querySelectorAll(".page-nav");

  pageNavs.forEach((pageNav) => {
    const links = [...pageNav.querySelectorAll("[data-page-nav-link]")];
    const sections = links
      .map((link) => document.getElementById(link.dataset.pageNavLink))
      .filter(Boolean);

    if (!links.length || !sections.length) {
      return;
    }

    const setActiveLink = (targetId) => {
      links.forEach((link) => {
        const isActive = link.dataset.pageNavLink === targetId;
        link.classList.toggle("is-active", isActive);
      });
    };

    const initialTarget = window.location.hash ? window.location.hash.slice(1) : sections[0].id;
    setActiveLink(initialTarget);

    links.forEach((link) => {
      link.addEventListener("click", () => {
        setActiveLink(link.dataset.pageNavLink);
      });
    });

    if (!("IntersectionObserver" in window)) {
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        const visibleSections = entries
          .filter((entry) => entry.isIntersecting)
          .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);

        if (visibleSections.length > 0) {
          setActiveLink(visibleSections[0].target.id);
        }
      },
      {
        rootMargin: "-20% 0px -60% 0px",
        threshold: [0.2, 0.5, 0.8],
      }
    );

    sections.forEach((section) => observer.observe(section));
  });
}

function getPageImmersiveStorageKey() {
  const normalizedPath = String(window.location.pathname || "")
    .replace(/[^a-z0-9/_-]/gi, "")
    .replace(/^\/+/, "");

  return `wallos-page-ui-hidden:${normalizedPath || "root"}`;
}

function updatePageImmersiveToggleButton(hidden) {
  const button = document.querySelector("[data-page-immersive-toggle]");
  if (!button) {
    return;
  }

  const label = hidden ? (button.dataset.showLabel || "Show UI") : (button.dataset.hideLabel || "Hide UI");
  const icon = button.querySelector("i");
  const text = button.querySelector("span");

  button.setAttribute("aria-pressed", hidden ? "true" : "false");
  button.setAttribute("title", label);
  button.setAttribute("aria-label", label);

  if (text) {
    text.textContent = label;
  }

  if (icon) {
    icon.className = hidden ? "fa-solid fa-eye" : "fa-solid fa-eye-slash";
  }
}

function setPageImmersiveUiState(hidden) {
  document.body.classList.toggle("page-ui-hidden", hidden);
  updatePageImmersiveToggleButton(hidden);
}

function initializePageImmersiveToggle() {
  const button = document.querySelector("[data-page-immersive-toggle]");
  if (!button) {
    return;
  }

  if (button.parentElement !== document.body) {
    document.body.appendChild(button);
  }

  let hidden = false;
  const storageKey = getPageImmersiveStorageKey();

  try {
    hidden = window.sessionStorage.getItem(storageKey) === "1";
  } catch (error) {
    hidden = false;
  }

  setPageImmersiveUiState(hidden);

  button.addEventListener("click", (event) => {
    event.preventDefault();
    event.stopPropagation();
    const nextHidden = !document.body.classList.contains("page-ui-hidden");

    try {
      window.sessionStorage.setItem(storageKey, nextHidden ? "1" : "0");
    } catch (error) {
      // Ignore sessionStorage persistence failures.
    }

    if (nextHidden) {
      document.body.classList.add("page-ui-fading-out");
      window.setTimeout(() => {
        document.body.classList.remove("page-ui-fading-out");
        setPageImmersiveUiState(true);
      }, 180);
      return;
    }

    document.body.classList.remove("page-ui-hidden");
    updatePageImmersiveToggleButton(false);
    document.body.classList.add("page-ui-fading-in");
    window.requestAnimationFrame(() => {
      window.requestAnimationFrame(() => {
        document.body.classList.remove("page-ui-fading-in");
      });
    });
  });
}

function buildWallosRequestHeaders(options = {}) {
  const {
    headers = {},
    contentType = null,
    includeCsrf = true,
  } = options;
  const finalHeaders = new Headers(headers);

  if (contentType && !finalHeaders.has("Content-Type")) {
    finalHeaders.set("Content-Type", contentType);
  }

  if (includeCsrf && window.csrfToken && !finalHeaders.has("X-CSRF-Token")) {
    finalHeaders.set("X-CSRF-Token", window.csrfToken);
  }

  return finalHeaders;
}

function normalizeWallosRequestError(error, fallbackMessage = null) {
  if (error && typeof error === "object") {
    if (typeof error.message === "string" && error.message.trim() !== "") {
      return error.message.trim();
    }

    if (error.data && typeof error.data === "object") {
      if (typeof error.data.message === "string" && error.data.message.trim() !== "") {
        return error.data.message.trim();
      }
      if (typeof error.data.error === "string" && error.data.error.trim() !== "") {
        return error.data.error.trim();
      }
    }
  }

  if (error instanceof Error && String(error.message || "").trim() !== "") {
    return error.message.trim();
  }

  if (typeof error === "string" && error.trim() !== "") {
    return error.trim();
  }

  return fallbackMessage || translate("unknown_error");
}

function extractWallosResponseMessage(data, fallbackMessage = null) {
  if (typeof data === "string" && data.trim() !== "") {
    return data.trim();
  }

  if (data && typeof data === "object") {
    if (typeof data.message === "string" && data.message.trim() !== "") {
      return data.message.trim();
    }

    if (typeof data.error === "string" && data.error.trim() !== "") {
      return data.error.trim();
    }
  }

  return fallbackMessage || translate("unknown_error");
}

function tryParseWallosJsonPayload(rawBody) {
  const normalizedBody = String(rawBody || "").trim();
  if (normalizedBody === "" || (normalizedBody[0] !== "{" && normalizedBody[0] !== "[")) {
    return null;
  }

  try {
    return JSON.parse(normalizedBody);
  } catch (error) {
    return null;
  }
}

function isWallosSessionFailurePayload(data) {
  return Boolean(
    data
    && typeof data === "object"
    && (
      data.session_expired === true
      || data.code === "session_expired"
      || data.error === "session_expired"
      || data.requires_relogin === true
    )
  );
}

function isWallosCsrfFailurePayload(data) {
  if (!data || typeof data !== "object") {
    return false;
  }

  const code = String(data.code || data.error || "").toLowerCase();
  if (code === "invalid_csrf") {
    return true;
  }

  const message = String(data.message || "").toLowerCase();
  return message.includes("invalid csrf token");
}

function isWallosAccountTrashedPayload(data) {
  return Boolean(
    data
    && typeof data === "object"
    && (
      data.account_trashed === true
      || data.code === "account_trashed"
      || data.error === "account_trashed"
    )
  );
}

function createWallosRequestError(message, context = {}) {
  const error = new Error(String(message || translate("unknown_error")));
  error.response = context.response || null;
  error.status = Number(context.status || context.response?.status || 0);
  error.data = context.data ?? null;
  error.rawBody = context.rawBody || "";
  error.code = context.code || (context.data && typeof context.data === "object"
    ? String(context.data.code || context.data.error || "")
    : "");
  error.sessionExpired = isWallosSessionFailurePayload(context.data);
  error.csrfInvalid = isWallosCsrfFailurePayload(context.data);
  error.accountTrashed = isWallosAccountTrashedPayload(context.data);
  error.rateLimit = Boolean(context.data && typeof context.data === "object" && context.data.rate_limit === true);
  return error;
}

let wallosSessionFailureDispatched = false;

function isWallosSessionFailureError(error) {
  if (!error || typeof error !== "object") {
    return false;
  }

  if (error.sessionExpired === true) {
    return true;
  }

  return isWallosSessionFailurePayload(error.data);
}

function dispatchWallosSessionFailure(error) {
  if (!isWallosSessionFailureError(error) || wallosSessionFailureDispatched) {
    return;
  }

  wallosSessionFailureDispatched = true;
  window.dispatchEvent(new CustomEvent("wallos:session-expired", {
    detail: error,
  }));

  window.setTimeout(() => {
    wallosSessionFailureDispatched = false;
  }, 1200);
}

function isWallosCsrfFailureError(error) {
  if (!error || typeof error !== "object") {
    return false;
  }

  if (error.csrfInvalid === true) {
    return true;
  }

  return isWallosCsrfFailurePayload(error.data);
}

function dispatchWallosCsrfFailure(error = null) {
  const csrfError = error || createWallosRequestError(getCsrfTokenRefreshReminderMessage(), {
    data: {
      success: false,
      code: "invalid_csrf",
      message: "Invalid CSRF token",
    },
  });

  if (!isWallosCsrfFailureError(csrfError)) {
    return false;
  }

  window.dispatchEvent(new CustomEvent("wallos:csrf-invalid", {
    detail: csrfError,
  }));
  showCsrfTokenRefreshReminder();
  return true;
}

function handleWallosSessionFailure(error, callback = null) {
  if (!isWallosSessionFailureError(error)) {
    return false;
  }

  if (typeof callback === "function") {
    callback(error);
    return true;
  }

  dispatchWallosSessionFailure(error);
  return true;
}

function pruneRecentClientAnomalyFingerprints() {
  const now = Date.now();
  for (const [fingerprint, timestamp] of recentClientAnomalyFingerprints.entries()) {
    if ((now - timestamp) > CLIENT_ANOMALY_DEDUPE_WINDOW_MS) {
      recentClientAnomalyFingerprints.delete(fingerprint);
    }
  }
}

function canLogClientAnomaly(payload) {
  const message = String(payload?.message || "").trim();
  if (!window.csrfToken || message === "") {
    return false;
  }

  const pathname = String(window.location.pathname || "").toLowerCase();
  if (pathname.endsWith("/login.php") || pathname.endsWith("/registration.php")) {
    return false;
  }

  const fingerprint = `${payload.anomaly_type}|${payload.anomaly_code}|${message}`;
  pruneRecentClientAnomalyFingerprints();
  if (recentClientAnomalyFingerprints.has(fingerprint)) {
    return false;
  }

  recentClientAnomalyFingerprints.set(fingerprint, Date.now());
  return true;
}

function reportClientAnomaly(payload) {
  if (!canLogClientAnomaly(payload)) {
    return;
  }

  const formData = new FormData();
  formData.append("csrf_token", window.csrfToken);
  formData.append("anomaly_type", String(payload.anomaly_type || ""));
  formData.append("anomaly_code", String(payload.anomaly_code || ""));
  formData.append("message", String(payload.message || ""));
  formData.append("details_json", JSON.stringify(payload.details || {}));

  if (navigator.sendBeacon) {
    navigator.sendBeacon(CLIENT_ANOMALY_ENDPOINT, formData);
    return;
  }

  fetch(CLIENT_ANOMALY_ENDPOINT, {
    method: "POST",
    body: formData,
    credentials: "same-origin",
    keepalive: true,
  }).catch(() => {
    // Ignore client anomaly logging failures.
  });
}

function maybeLogWallosRequestFailure(url, method, error) {
  if (!error || typeof error !== "object") {
    return;
  }

  if (error.sessionExpired || error.rateLimit || error.accountTrashed) {
    return;
  }

  const normalizedUrl = String(url || "");
  if (normalizedUrl.includes(CLIENT_ANOMALY_ENDPOINT)) {
    return;
  }

  reportClientAnomaly({
    anomaly_type: "request_failure",
    anomaly_code: error.code || `http_${Number(error.status || 0) || 0}`,
    message: error.message || translate("unknown_error"),
    details: {
      url: normalizedUrl,
      method: String(method || "GET").toUpperCase(),
      status: Number(error.status || 0),
      rawBody: String(error.rawBody || "").slice(0, 1500),
    },
  });
}

async function wallosRequest(url, options = {}) {
  const {
    method = "GET",
    headers = {},
    body = null,
    contentType = null,
    includeCsrf = true,
    responseType = "json",
    requireOk = false,
    credentials = "same-origin",
    fallbackErrorMessage = null,
    allowEmptyJsonResponse = false,
  } = options;
  const normalizedMethod = String(method || "GET").toUpperCase();
  const requestQueueTracker = startRequestQueueTracking(normalizedMethod, options);

  try {
    const response = await fetch(url, {
      method: normalizedMethod,
      headers: buildWallosRequestHeaders({
        headers,
        contentType,
        includeCsrf,
      }),
      body,
      credentials,
    });

    let data = null;
    let rawBody = "";

    if (responseType === "json") {
      rawBody = await response.text();
      if (rawBody.trim() === "") {
        if (allowEmptyJsonResponse) {
          data = null;
        } else {
          const requestError = createWallosRequestError(
            fallbackErrorMessage || translate("unknown_error"),
            {
              response,
              status: response.status,
              data: null,
              rawBody,
            }
          );
          maybeLogWallosRequestFailure(url, normalizedMethod, requestError);
          throw requestError;
        }
      } else {
        try {
          data = JSON.parse(rawBody);
        } catch (error) {
          const requestError = createWallosRequestError(
            fallbackErrorMessage || translate("unknown_error"),
            {
              response,
              status: response.status,
              data: null,
              rawBody,
            }
          );
          maybeLogWallosRequestFailure(url, normalizedMethod, requestError);
          throw requestError;
        }
      }
    } else if (responseType === "text") {
      rawBody = await response.text();
      data = rawBody;
    } else if (responseType === "blob") {
      data = await response.blob();
    }

    const requestSucceeded = didWallosRequestSucceed(response, data);
    finalizeRequestQueueTracking(requestQueueTracker, requestSucceeded);

    if (isWallosCsrfFailurePayload(data)) {
      dispatchWallosCsrfFailure(createWallosRequestError(
        getCsrfTokenRefreshReminderMessage(),
        {
          response,
          status: response.status,
          data,
          rawBody,
        }
      ));
    }

    if (requireOk && !response.ok) {
      const errorData = responseType === "text" ? (tryParseWallosJsonPayload(rawBody) || data) : data;
      const requestError = createWallosRequestError(
        extractWallosResponseMessage(
          errorData,
          fallbackErrorMessage || translate("network_response_error")
        ),
        {
          response,
          status: response.status,
          data: errorData,
          rawBody,
        }
      );
      dispatchWallosSessionFailure(requestError);
      dispatchWallosCsrfFailure(requestError);
      maybeLogWallosRequestFailure(url, normalizedMethod, requestError);
      throw requestError;
    }

    return { response, data };
  } catch (error) {
    finalizeRequestQueueTracking(requestQueueTracker, false);
    throw error;
  }
}

async function wallosRequestJson(url, options = {}) {
  const result = await wallosRequest(url, {
    ...options,
    responseType: "json",
  });

  return result.data;
}

function wallosGetJson(url, options = {}) {
  return wallosRequestJson(url, {
    ...options,
    method: "GET",
  });
}

function wallosPostJson(url, payload = {}, options = {}) {
  return wallosRequestJson(url, {
    ...options,
    method: options.method || "POST",
    contentType: "application/json",
    body: JSON.stringify(payload),
  });
}

function wallosPostForm(url, payload = {}, options = {}) {
  const body = payload instanceof URLSearchParams || payload instanceof FormData
    ? payload
    : new URLSearchParams(payload);

  return wallosRequestJson(url, {
    ...options,
    method: options.method || "POST",
    contentType: body instanceof FormData ? null : "application/x-www-form-urlencoded",
    body,
  });
}

function wallosHandleJsonResult(data, options = {}) {
  const {
    successMessage = null,
    errorMessage = null,
    silentSuccess = false,
    silentError = false,
  } = options;

  if (data?.success) {
    if (!silentSuccess) {
      showSuccessMessage(successMessage || data.message || translate("success"));
    }
    return true;
  }

  if (isWallosCsrfFailurePayload(data)) {
    dispatchWallosCsrfFailure(createWallosRequestError(getCsrfTokenRefreshReminderMessage(), {
      data,
      status: 400,
    }));
    return false;
  }

  if (!silentError) {
    showErrorMessage(errorMessage || data?.message || translate("error"));
  }
  return false;
}

function getRequestQueueNoticeMessages(status, pendingCount = 0) {
  if (status === "success") {
    return {
      title: translateWithFallback("request_queue_success_title", "处理完成", {
        en: "Processing complete",
        zh_cn: "处理完成",
        zh_tw: "處理完成",
      }),
      body: translateWithFallback("request_queue_success_body", "排队中的后台写入已经处理完成。", {
        en: "Queued backend writes have finished.",
        zh_cn: "排队中的后台写入已经处理完成。",
        zh_tw: "排隊中的後台寫入已經處理完成。",
      }),
    };
  }

  const multipleFallback = `当前仍有 ${pendingCount} 个写入请求等待完成。`;
  const body = pendingCount > 1
    ? translateWithFallback("request_queue_pending_body_multiple", multipleFallback, {
      en: `%1$d write requests are still waiting to finish.`,
      zh_cn: "当前仍有 %1$d 个写入请求等待完成。",
      zh_tw: "目前仍有 %1$d 個寫入請求等待完成。",
    }).replace("%1$d", String(pendingCount))
    : translateWithFallback("request_queue_pending_body", "后台写入较忙，当前请求正在排队处理，请稍候。", {
      en: "The server is busy writing data. Your request is queued and will finish shortly.",
      zh_cn: "后台写入较忙，当前请求正在排队处理，请稍候。",
      zh_tw: "後台寫入較忙，目前請求正在排隊處理，請稍候。",
    });

  return {
    title: translateWithFallback("request_queue_pending_title", "后台操作排队处理中", {
      en: "Backend queue in progress",
      zh_cn: "后台操作排队处理中",
      zh_tw: "後台操作排隊處理中",
    }),
    body,
  };
}

function normalizeToastContent(type, message) {
  const fallbackTitle = type === "error" ? translate("error") : translate("success");
  const fallbackBody = type === "error" ? translate("toast_error_generic") : translate("toast_success_generic");
  const rawMessage = message instanceof Error
    ? String(message.message || "").trim()
    : String(message ?? "").trim();

  if (!rawMessage) {
    return {
      title: fallbackTitle,
      body: fallbackBody,
      shouldDisplay: false,
    };
  }

  const genericCandidates = new Set([
    fallbackTitle,
    type === "error" ? "Error" : "Success",
    type === "error" ? "error" : "success",
  ]);

  if (genericCandidates.has(rawMessage)) {
    return {
      title: fallbackTitle,
      body: fallbackBody,
      shouldDisplay: false,
    };
  }

  const separatorMatch = rawMessage.match(/^([^:\n：]{1,60})[:：]\s*([\s\S]+)$/u);
  if (separatorMatch) {
    return {
      title: separatorMatch[1].trim(),
      body: separatorMatch[2].trim(),
      shouldDisplay: true,
    };
  }

  const lineMatch = rawMessage.match(/^([^\n]{1,70})\n+([\s\S]+)$/);
  if (lineMatch) {
    return {
      title: lineMatch[1].trim(),
      body: lineMatch[2].trim(),
      shouldDisplay: true,
    };
  }

  if (rawMessage.length <= 80) {
    return {
      title: rawMessage,
      body: "",
      shouldDisplay: true,
    };
  }

  return {
    title: fallbackTitle,
    body: rawMessage,
    shouldDisplay: true,
  };
}

window.WallosHttp = {
  request: wallosRequest,
  requestJson: wallosRequestJson,
  getJson: wallosGetJson,
  postJson: wallosPostJson,
  postForm: wallosPostForm,
  handleJsonResult: wallosHandleJsonResult,
  normalizeError: normalizeWallosRequestError,
  isSessionFailurePayload: isWallosSessionFailurePayload,
  isSessionFailureError: isWallosSessionFailureError,
  handleSessionFailure: handleWallosSessionFailure,
  isCsrfFailurePayload: isWallosCsrfFailurePayload,
  isCsrfFailureError: isWallosCsrfFailureError,
  showCsrfTokenRefreshReminder,
};

window.WallosThemeColor = {
  update: updateThemeColorMetaTag,
};

window.addEventListener("wallos:session-expired", () => {
  if (isPublicAuthPage()) {
    return;
  }

  showErrorMessage(translate("session_expired"));
  window.setTimeout(() => {
    window.location.reload();
  }, 180);
});

window.addEventListener("wallos:csrf-invalid", () => {
  showCsrfTokenRefreshReminder();
});

document.addEventListener("visibilitychange", () => {
  if (document.hidden) {
    csrfBackgroundHiddenAt = Date.now();
    return;
  }

  window.setTimeout(maybeShowCsrfBackgroundReminder, 250);
});

window.addEventListener("pageshow", (event) => {
  if (event.persisted) {
    window.setTimeout(maybeShowCsrfBackgroundReminder, 250);
  }
});

window.addEventListener("error", (event) => {
  const message = String(event.message || "").trim();
  if (message === "") {
    return;
  }

  reportClientAnomaly({
    anomaly_type: "client_runtime",
    anomaly_code: "window_error",
    message,
    details: {
      filename: String(event.filename || ""),
      lineno: Number(event.lineno || 0),
      colno: Number(event.colno || 0),
      stack: String(event.error?.stack || "").slice(0, 4000),
    },
  });
});

window.addEventListener("unhandledrejection", (event) => {
  const reason = event.reason;
  const message = reason instanceof Error
    ? String(reason.message || "").trim()
    : String(reason || "").trim();

  if (message === "") {
    return;
  }

  reportClientAnomaly({
    anomaly_type: "client_runtime",
    anomaly_code: "unhandled_rejection",
    message,
    details: {
      stack: String(reason?.stack || "").slice(0, 4000),
    },
  });
});

function closeToast(type) {
  const toast = document.querySelector(type === "error" ? ".toast#errorToast" : ".toast#successToast");
  const progress = document.querySelector(type === "error" ? ".progress.error" : ".progress.success");
  if (!toast || !progress) {
    return;
  }

  toast.classList.remove("active");
  window.clearTimeout(toastState[type].hideTimer);
  window.clearTimeout(toastState[type].progressTimer);
  toastState[type].hideTimer = null;
  toastState[type].progressTimer = null;

  window.setTimeout(() => {
    progress.classList.remove("active");
  }, 220);
}

function normalizeToastContent(type, message) {
  const fallbackTitle = type === "error" ? translate("error") : translate("success");
  const fallbackBody = type === "error" ? translate("toast_error_generic") : translate("toast_success_generic");
  const rawMessage = message instanceof Error
    ? String(message.message || "").trim()
    : String(message ?? "").trim();

  if (!rawMessage) {
    return {
      title: fallbackTitle,
      body: fallbackBody,
      shouldDisplay: false,
    };
  }

  const genericCandidates = new Set([
    fallbackTitle,
    type === "error" ? "Error" : "Success",
    type === "error" ? "error" : "success",
  ]);

  if (genericCandidates.has(rawMessage)) {
    return {
      title: fallbackTitle,
      body: fallbackBody,
      shouldDisplay: false,
    };
  }

  const separatorMatch = rawMessage.match(/^([^:\n：]{1,60})[:：]\s*([\s\S]+)$/u);
  if (separatorMatch) {
    return {
      title: separatorMatch[1].trim(),
      body: separatorMatch[2].trim(),
      shouldDisplay: true,
    };
  }

  const lineMatch = rawMessage.match(/^([^\n]{1,70})\n+([\s\S]+)$/);
  if (lineMatch) {
    return {
      title: lineMatch[1].trim(),
      body: lineMatch[2].trim(),
      shouldDisplay: true,
    };
  }

  if (rawMessage.length <= 80) {
    return {
      title: rawMessage,
      body: "",
      shouldDisplay: true,
    };
  }

  return {
    title: fallbackTitle,
    body: rawMessage,
    shouldDisplay: true,
  };
}

function showToast(type, message) {
  const toast = document.querySelector(type === "error" ? ".toast#errorToast" : ".toast#successToast");
  const closeIcon = document.querySelector(type === "error" ? ".close-error" : ".close-success");
  const titleNode = document.querySelector(type === "error" ? "#errorToast .text-1" : "#successToast .text-1");
  const bodyNode = document.querySelector(type === "error" ? ".errorMessage" : ".successMessage");
  const progress = document.querySelector(type === "error" ? ".progress.error" : ".progress.success");

  if (!toast || !closeIcon || !titleNode || !bodyNode || !progress) {
    return;
  }

  const normalized = normalizeToastContent(type, message);
  if (!normalized.shouldDisplay) {
    return;
  }

  titleNode.textContent = normalized.title;
  bodyNode.textContent = normalized.body;
  bodyNode.classList.toggle("is-empty", normalized.body === "");

  closeToast(type);
  toast.classList.add("active");
  progress.classList.add("active");

  toastState[type].hideTimer = window.setTimeout(() => {
    closeToast(type);
  }, 5000);

  toastState[type].progressTimer = window.setTimeout(() => {
    progress.classList.remove("active");
  }, 5300);

  closeIcon.onclick = () => closeToast(type);
}

function showErrorMessage(message) {
  showToast("error", message);
}

function showSuccessMessage(message) {
  showToast("success", message);
}

function markUserInteraction() {
  hasUserInteractedWithPage = true;
}

document.addEventListener("pointerdown", markUserInteraction, { passive: true });
document.addEventListener("keydown", markUserInteraction, { passive: true });

document.addEventListener('DOMContentLoaded', function () {

  const userLocale = navigator.language || navigator.languages[0];
  document.cookie = `user_locale=${userLocale}; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/; SameSite=Lax`;

  if (window.update_theme_settings) {
    const prefersDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const themePreference = prefersDarkMode ? 'dark' : 'light';
    const darkThemeCss = document.querySelector("#dark-theme");
    darkThemeCss.disabled = themePreference === 'light';

    const existingClasses = document.body.className.split(' ').filter(cls => cls !== 'dark' && cls !== 'light');
    document.body.className = [...existingClasses, themePreference].join(' ');

    document.cookie = `inUseTheme=${themePreference}; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/; SameSite=Lax`;
  }

  updateThemeColorMetaTag();

  document.addEventListener('mousedown', function (event) {
    const dropdown = document.querySelector('.dropdown');
    if (!dropdown) {
      return;
    }

    if (!dropdown.contains(event.target) && isDropdownOpen) {
      dropdown.classList.remove('is-open');
      isDropdownOpen = false;
    }
  });

  const dropdownContent = document.querySelector('.dropdown-content');
  if (dropdownContent) {
    dropdownContent.addEventListener('focus', function () {
      isDropdownOpen = true;
    });
  }

  setupPageNavigation();
  initializePageImmersiveToggle();
  consumeRateLimitNoticeCookie();
  window.setInterval(consumeRateLimitNoticeCookie, 1500);
});

function getCookie(name) {
  const cookies = document.cookie.split(';');
  for (let cookie of cookies) {
    cookie = cookie.trim();
    if (cookie.startsWith(`${name}=`)) {
      return cookie.substring(name.length + 1);
    }
  }
  return null;
}

function clearCookie(name) {
  document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=Lax`;
}

function consumeRateLimitNoticeCookie() {
  const rawCookie = getCookie('wallos_rate_limit_notice');
  if (!rawCookie) {
    return;
  }

  try {
    const payload = JSON.parse(decodeURIComponent(rawCookie));
    clearCookie('wallos_rate_limit_notice');

    if (!payload || !payload.id || payload.id === lastRateLimitNoticeId) {
      return;
    }

    lastRateLimitNoticeId = payload.id;
    if (payload.message) {
      showErrorMessage(payload.message);
    }
  } catch (error) {
    clearCookie('wallos_rate_limit_notice');
  }
}
