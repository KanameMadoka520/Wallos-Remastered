(function () {
  "use strict";

  let currentDetailsId = null;
  let detailsRequestSequence = 0;
  let previousDetailsFocus = null;

  function detailsTranslate(key, fallback) {
    if (typeof window.translate === "function") {
      const translated = window.translate(key);
      if (translated && translated !== "[Translation Missing]") return translated;
    }
    return fallback || key;
  }

  function detailsLocale() {
    return String(window.lang || "en").replace(/_/g, "-");
  }

  function lookup(group, id) {
    const values = (window.subscriptionLookups && window.subscriptionLookups[group]) || {};
    return values[id] || values[String(id)];
  }

  function detailsFormatPrice(price, currencyId) {
    const currency = lookup("currencies", currencyId);
    const parsedValue = Number(price);
    const value = Number.isFinite(parsedValue) ? parsedValue : 0;
    if (currency && currency.code) {
      try {
        return new Intl.NumberFormat(detailsLocale(), { style: "currency", currency: currency.code }).format(value);
      } catch (error) {
        // Fall through for an unknown or unsupported currency code.
      }
    }
    return `${currency ? (currency.symbol || currency.code || "") : ""}${value.toFixed(2)}`;
  }

  function detailsFormatDate(dateString) {
    if (!dateString) return "";
    const date = new Date(`${dateString}T00:00:00`);
    if (Number.isNaN(date.getTime())) return dateString;
    try {
      return date.toLocaleDateString(detailsLocale(), { day: "numeric", month: "short", year: "numeric" });
    } catch (error) {
      return date.toLocaleDateString("en", { day: "numeric", month: "short", year: "numeric" });
    }
  }

  function detailsBillingCycleText(cycle, frequency) {
    const units = lookup("cycles", cycle);
    if (!units) return "";
    return Number(frequency) === 1 || Number(cycle) === 5 ? units.one : `${frequency} ${units.many}`;
  }

  function detailsProgressPercentage(subscription) {
    const cycleDays = { 1: 1, 2: 7, 3: 30, 4: 365 }[Number(subscription.cycle)];
    if (!cycleDays || !subscription.next_payment) return null;
    const nextPayment = new Date(`${subscription.next_payment}T00:00:00`);
    if (Number.isNaN(nextPayment.getTime())) return null;
    const totalDays = cycleDays * Math.max(1, Number(subscription.frequency) || 1);
    const daysUntil = (nextPayment.getTime() - Date.now()) / 86400000;
    return Math.min(100, Math.max(0, Math.floor(((totalDays - daysUntil) / totalDays) * 100)));
  }

  function addChip(container, text, style) {
    const chip = document.createElement("span");
    chip.className = `details-chip${style ? ` ${style}` : ""}`;
    chip.textContent = text;
    container.appendChild(chip);
  }

  function showModal() {
    const modal = document.getElementById("subscription-details");
    const backdrop = document.getElementById("details-backdrop");
    if (!modal || !backdrop) return;
    if (!modal.classList.contains("is-open")) {
      previousDetailsFocus = document.activeElement || null;
    }
    modal.classList.add("is-open");
    backdrop.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
    backdrop.setAttribute("aria-hidden", "false");
    document.body.classList.add("details-open");
    document.getElementById("details-close")?.focus();
  }

  function renderSubscriptionLogo(subscription) {
    const logoContainer = document.getElementById("details-logo");
    if (!logoContainer) return;

    logoContainer.replaceChildren();
    if (!subscription.logo) {
      const fallback = document.createElement("span");
      fallback.className = "details-logo-fallback";
      fallback.textContent = (subscription.name || "?").charAt(0).toUpperCase();
      logoContainer.appendChild(fallback);
      return;
    }

    const appendLogo = (filename, className = "") => {
      const image = document.createElement("img");
      image.src = `images/uploads/logos/${encodeURIComponent(filename)}`;
      image.alt = "";
      if (className) image.className = className;
      image.addEventListener("error", () => image.remove(), { once: true });
      logoContainer.appendChild(image);
    };

    if (subscription.logo_variant && subscription.logo_text_color) {
      const nativeTheme = String(subscription.logo_text_color).toLowerCase() === "dark" ? "light" : "dark";
      appendLogo(subscription.logo, "logo-theme-original");
      logoContainer.lastElementChild.dataset.nativeTheme = nativeTheme;
      appendLogo(subscription.logo_variant, "logo-theme-variant");
      logoContainer.lastElementChild.dataset.nativeTheme = nativeTheme;
    } else {
      appendLogo(subscription.logo);
    }
  }

  function renderSubscriptionDetails(subscription) {
    const strings = window.subscriptionLookups.i18n;
    currentDetailsId = Number(subscription.id);
    renderSubscriptionLogo(subscription);

    document.getElementById("details-name").textContent = subscription.name || "";
    const chips = document.getElementById("details-chips");
    chips.replaceChildren();
    const oneTime = Number(subscription.cycle) === 5;
    if (Number(subscription.inactive) === 1) addChip(chips, strings.inactive, "warn");
    if (oneTime) addChip(chips, strings.one_time, "muted");
    else if (Number(subscription.auto_renew) === 1) addChip(chips, strings.automatic, "ok");
    else addChip(chips, strings.manual_renewal, "manual");

    document.getElementById("details-price").textContent = detailsFormatPrice(subscription.price, subscription.currency_id);
    document.getElementById("details-billing-cycle").textContent = oneTime ? "" : detailsBillingCycleText(subscription.cycle, subscription.frequency);

    const progressTrack = document.getElementById("details-progress-track");
    const progress = detailsProgressPercentage(subscription);
    progressTrack.classList.toggle("hide", progress === null || Number(subscription.inactive) === 1);
    if (progress !== null) document.getElementById("details-progress").style.width = `${progress}%`;

    document.getElementById("details-next-payment").textContent = detailsFormatDate(subscription.next_payment);
    document.getElementById("details-start-date").textContent = detailsFormatDate(subscription.start_date) || strings.none;
    document.getElementById("details-category").textContent = lookup("categories", subscription.category_id) || strings.none;
    document.getElementById("details-payer").textContent = lookup("members", subscription.payer_user_id) || strings.none;

    const paymentMethod = lookup("paymentMethods", subscription.payment_method_id);
    const paymentIcon = document.getElementById("details-payment-icon");
    paymentIcon.classList.toggle("hide", !paymentMethod || !paymentMethod.icon);
    if (paymentMethod && paymentMethod.icon) paymentIcon.src = paymentMethod.icon;
    document.getElementById("details-payment-name").textContent = paymentMethod ? paymentMethod.name : strings.none;

    let notificationText = Number(subscription.notify) === 1 ? strings.enabled : strings.disabled;
    if (Number(subscription.notify) === 1 && Number(subscription.notify_days_before) >= 0) {
      const days = Number(subscription.notify_days_before);
      notificationText += ` · ${days === 0 ? strings.on_due_date : `${days} ${days === 1 ? strings.day_before : strings.days_before}`}`;
    }
    document.getElementById("details-notifications").textContent = notificationText;

    const cancellationItem = document.getElementById("details-cancellation-item");
    cancellationItem.classList.toggle("hide", !subscription.cancellation_date);
    if (subscription.cancellation_date) document.getElementById("details-cancellation").textContent = detailsFormatDate(subscription.cancellation_date);

    const replacementItem = document.getElementById("details-replacement-item");
    const replacementName = subscription.replacement_subscription_id ? lookup("subscriptionNames", subscription.replacement_subscription_id) : null;
    replacementItem.classList.toggle("hide", !replacementName);
    if (replacementName) document.getElementById("details-replacement").textContent = replacementName;

    const notesItem = document.getElementById("details-notes-item");
    notesItem.classList.toggle("hide", !subscription.notes);
    if (subscription.notes) document.getElementById("details-notes").textContent = subscription.notes;

    const urlButton = document.getElementById("details-url-button");
    urlButton.classList.toggle("hide", !subscription.url);
    if (subscription.url) {
      const rawUrl = String(subscription.url).trim();
      const normalizedUrl = /^https?:\/\//i.test(rawUrl) ? rawUrl : `https://${rawUrl}`;
      try {
        const parsedUrl = new URL(normalizedUrl, window.location.href);
        if (parsedUrl.protocol === "http:" || parsedUrl.protocol === "https:") {
          urlButton.href = parsedUrl.href;
        } else {
          urlButton.classList.add("hide");
        }
      } catch (error) {
        urlButton.classList.add("hide");
      }
    }

    document.getElementById("details-export-button").onclick = function () {
      exportSubscriptionCalendar(subscription.id);
    };
    showModal();
  }

  function showSubscriptionDetails(event, id) {
    if (event) event.preventDefault();
    const subscriptionId = Number(id);
    if (!Number.isInteger(subscriptionId) || subscriptionId <= 0) return;
    const requestSequence = ++detailsRequestSequence;
    const modal = document.getElementById("subscription-details");
    if (modal) modal.setAttribute("aria-busy", "true");
    const request = window.WallosApi?.getJson
      ? window.WallosApi.getJson(`endpoints/subscription/get.php?id=${encodeURIComponent(subscriptionId)}`, {
        includeCsrf: false,
        requireOk: true,
        fallbackErrorMessage: detailsTranslate("failed_to_load_subscription", "Failed to load subscription"),
      })
      : fetch(`endpoints/subscription/get.php?id=${encodeURIComponent(subscriptionId)}`, { headers: { Accept: "application/json" } })
        .then((response) => response.ok ? response.json() : Promise.reject(new Error("request failed")));
    request
      .then((data) => {
        if (requestSequence !== detailsRequestSequence) return;
        if (!data || data.error || data === "Error") throw new Error("invalid subscription response");
        renderSubscriptionDetails(data);
      })
      .catch((error) => {
        console.error(error);
        if (window.WallosApi?.isSessionFailureError?.(error)) {
          window.location.reload();
          return;
        }
        if (typeof showErrorMessage === "function") showErrorMessage(detailsTranslate("failed_to_load_subscription", "Failed to load subscription"));
      })
      .finally(() => {
        if (modal) modal.setAttribute("aria-busy", "false");
      });
  }

  function closeSubscriptionDetails() {
    currentDetailsId = null;
    detailsRequestSequence += 1;
    const modal = document.getElementById("subscription-details");
    const backdrop = document.getElementById("details-backdrop");
    if (modal) {
      modal.classList.remove("is-open");
      modal.setAttribute("aria-hidden", "true");
    }
    if (backdrop) {
      backdrop.classList.remove("is-open");
      backdrop.setAttribute("aria-hidden", "true");
    }
    document.body.classList.remove("details-open");
    if (previousDetailsFocus && typeof previousDetailsFocus.focus === "function" && document.contains(previousDetailsFocus)) {
      previousDetailsFocus.focus();
    }
    previousDetailsFocus = null;
  }

  function exportSubscriptionCalendar(subscriptionId) {
    const request = window.WallosApi?.postJson
      ? window.WallosApi.postJson("endpoints/subscription/exportcalendar.php", { id: subscriptionId }, {
        fallbackErrorMessage: detailsTranslate("error", "Error"),
      })
      : fetch("endpoints/subscription/exportcalendar.php", {
        method: "POST",
        body: JSON.stringify({ id: subscriptionId }),
        headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
      }).then((response) => response.json());
    request
      .then((data) => {
        if (!data.success || !data.ics) throw new Error(data.message || "export failed");
        const url = window.URL.createObjectURL(new Blob([data.ics], { type: "text/calendar" }));
        const link = document.createElement("a");
        link.href = url;
        link.download = `${String(data.name || "subscription").replace(/[\\/:*?"<>|]/g, "_").toLowerCase()}.ics`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
      })
      .catch((error) => {
        console.error(error);
        if (window.WallosApi?.isSessionFailureError?.(error)) {
          window.location.reload();
          return;
        }
        if (typeof showErrorMessage === "function") showErrorMessage(error.message || detailsTranslate("error", "Error"));
      });
  }

  window.showSubscriptionDetails = showSubscriptionDetails;
  window.closeSubscriptionDetails = closeSubscriptionDetails;

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".dashboard-subscription-trigger").forEach((item) => {
      item.addEventListener("click", (event) => showSubscriptionDetails(event, item.dataset.subscriptionId));
      item.addEventListener("keydown", (event) => {
        if (event.key === "Enter" || event.key === " ") showSubscriptionDetails(event, item.dataset.subscriptionId);
      });
    });
    const closeButton = document.getElementById("details-close");
    if (closeButton) closeButton.addEventListener("click", closeSubscriptionDetails);
    const backdrop = document.getElementById("details-backdrop");
    if (backdrop) backdrop.addEventListener("click", closeSubscriptionDetails);
    document.addEventListener("keydown", (event) => {
      const modal = document.getElementById("subscription-details");
      if (!modal || !modal.classList.contains("is-open")) return;
      if (event.key === "Escape") {
        closeSubscriptionDetails();
        return;
      }
      if (event.key !== "Tab") return;
      const focusable = modal.querySelectorAll("button:not([disabled]), a[href], [tabindex]:not([tabindex=\"-1\"])");
      if (focusable.length === 0) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
  });
})();
