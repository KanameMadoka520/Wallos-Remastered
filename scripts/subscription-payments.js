(function () {
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
let refreshSubscriptionsPreservingStateHandler = null;
let getOpenSubscriptionIdsHandler = null;
let initialized = false;
function escapeHtml(value) { return String(value ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\"/g, "&quot;").replace(/'/g, "&#039;"); }
function normalizeError(error, fallbackMessage = null) { if (window.WallosHttp?.normalizeError) { return window.WallosHttp.normalizeError(error, fallbackMessage || translate("unknown_error")); } if (error instanceof Error && String(error.message || "").trim() !== "") { return error.message.trim(); } return fallbackMessage || translate("unknown_error"); }
function applyCurrentPriceRulePreview(dueDate) { window.WallosSubscriptionPriceRules?.applyPaymentPreview?.(currentPaymentModalSubscription, currentPaymentModalMode, dueDate); }

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
    applyCurrentPriceRulePreview(dueDateInput.value || "");
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
      showErrorMessage(normalizeError(error, translate("error")));
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

function formatSubscriptionPaymentHistoryAmount(value, currencyCode) {
  const numericValue = Number(value || 0);
  if (currencyCode) {
    return new Intl.NumberFormat(navigator.language, { style: 'currency', currency: currencyCode }).format(numericValue);
  }

  return new Intl.NumberFormat(navigator.language).format(numericValue);
}

function isSubscriptionPaymentMainCurrencyAvailable(value) {
  return value !== false && value !== 0;
}

function hasSubscriptionPaymentMainCurrencyConversion(item) {
  return isSubscriptionPaymentMainCurrencyAvailable(item?.main_currency_conversion_available);
}

function getSubscriptionPaymentMissingExchangeRateLabel() {
  return translate('subscription_payment_exchange_rate_missing');
}

function formatSubscriptionPaymentHistoryMainOrOriginalAmount(mainValue, mainCurrencyCode, mainAvailable, originalTotal) {
  if (isSubscriptionPaymentMainCurrencyAvailable(mainAvailable)) {
    return formatSubscriptionPaymentHistoryAmount(mainValue || 0, mainCurrencyCode || '');
  }

  if (originalTotal?.available && originalTotal.currency_code) {
    return formatSubscriptionPaymentHistoryAmount(originalTotal.amount || 0, originalTotal.currency_code);
  }

  return getSubscriptionPaymentMissingExchangeRateLabel();
}

function getSubscriptionPaymentDisplayAmountNumber(mainValue, mainAvailable, originalTotal) {
  if (isSubscriptionPaymentMainCurrencyAvailable(mainAvailable)) {
    return Number(mainValue || 0);
  }

  if (originalTotal?.available) {
    return Number(originalTotal.amount || 0);
  }

  return 0;
}

function buildSubscriptionPaymentCombinedOriginalTotal(items, originalTotalKey) {
  let total = 0;
  let currencyCode = "";
  let hasItems = false;
  let mixedCurrency = false;

  items.forEach((item) => {
    const originalTotal = item?.[originalTotalKey];
    if (!originalTotal?.available || !originalTotal.currency_code) {
      return;
    }

    const itemCurrencyCode = String(originalTotal.currency_code).toUpperCase();
    if (!currencyCode) {
      currencyCode = itemCurrencyCode;
    } else if (currencyCode !== itemCurrencyCode) {
      mixedCurrency = true;
    }

    total += Number(originalTotal.amount || 0);
    hasItems = true;
  });

  return {
    available: hasItems && !mixedCurrency && Boolean(currencyCode),
    amount: total,
    currency_code: currencyCode,
    mixed_currency: mixedCurrency,
  };
}

function getSubscriptionPaymentRemainingValueLabel(remainingValue, mainCurrencyCode) {
  return formatSubscriptionPaymentHistoryMainOrOriginalAmount(
    remainingValue?.remaining_value_main || 0,
    mainCurrencyCode || '',
    remainingValue?.main_currency_conversion_available,
    {
      available: remainingValue?.remaining_value_original_available,
      amount: remainingValue?.remaining_value_original || 0,
      currency_code: remainingValue?.currency_code || '',
    }
  );
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
      value: formatSubscriptionPaymentHistoryMainOrOriginalAmount(
        summary.invested_total || 0,
        currencyCode,
        summary.invested_total_main_currency_available,
        summary.invested_total_original
      ),
    },
    {
      label: translate('subscription_payment_summary_actual_this_year'),
      value: formatSubscriptionPaymentHistoryMainOrOriginalAmount(
        summary.actual_this_year_total || 0,
        currencyCode,
        summary.actual_this_year_total_main_currency_available,
        summary.actual_this_year_total_original
      ),
    },
    {
      label: translate('subscription_payment_summary_predicted_remaining'),
      value: formatSubscriptionPaymentHistoryMainOrOriginalAmount(
        summary.predicted_remaining_total || 0,
        currencyCode,
        summary.predicted_remaining_total_main_currency_available,
        summary.predicted_remaining_total_original
      ),
    },
    {
      label: translate('subscription_payment_summary_projected_total'),
      value: formatSubscriptionPaymentHistoryMainOrOriginalAmount(
        summary.projected_total || 0,
        currencyCode,
        summary.projected_total_main_currency_available,
        summary.projected_total_original
      ),
    },
    {
      label: translate('subscription_payment_summary_record_count'),
      value: new Intl.NumberFormat(navigator.language).format(Number(summary.record_count || 0)),
    },
  ];

  if (remainingValue.available) {
    summaryCards.push({
      label: translate('subscription_remaining_value'),
      value: getSubscriptionPaymentRemainingValueLabel(remainingValue, currencyCode),
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
      ${summary.has_unavailable_exchange_snapshots ? `
        <div class="subscription-payment-history-note">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <span>${escapeHtml(translate('subscription_payment_exchange_rate_missing_notice'))}</span>
        </div>
      ` : ''}
      ${remainingValue.available ? `
        <div class="subscription-payment-history-note">
          <i class="fa-solid fa-hourglass-half"></i>
          <span>${escapeHtml(`${translate('subscription_remaining_value')}: ${getSubscriptionPaymentRemainingValueLabel(remainingValue, currencyCode)} | ${translate('subscription_remaining_value_days_inline').replace('%1$s', String(remainingValue.remaining_days || 0)).replace('%2$s', String(remainingValue.total_days || 0)).replace('%3$s', new Intl.NumberFormat(navigator.language, { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(Number(remainingValue.remaining_ratio || 0)))} | ${remainingValue.value_source_summary || ''}`)}</span>
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
    const hasMainConversion = hasSubscriptionPaymentMainCurrencyConversion(record);
    const amountLabel = formatSubscriptionPaymentHistoryAmount(record.amount_original || 0, record.currency_code_snapshot || '');
    const mainAmountLabel = hasMainConversion
      ? formatSubscriptionPaymentHistoryAmount(record.amount_main_snapshot || 0, record.main_currency_code_snapshot || '')
      : getSubscriptionPaymentMissingExchangeRateLabel();
    const expectedAmountLabel = hasMainConversion
      ? formatSubscriptionPaymentHistoryAmount(record.expected_amount_main || 0, record.main_currency_code_snapshot || '')
      : getSubscriptionPaymentMissingExchangeRateLabel();
    const ledgerDifference = Number(record.ledger_difference_main || 0);
    const differencePrefix = ledgerDifference > 0 ? '+' : '';
    const differenceLabel = hasMainConversion
      ? `${differencePrefix}${formatSubscriptionPaymentHistoryAmount(ledgerDifference, record.main_currency_code_snapshot || '')}`
      : '';

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
          ${hasMainConversion && Math.abs(ledgerDifference) > 0.001 ? `<span>${escapeHtml(`${translate('subscription_payment_ledger_difference')}: ${differenceLabel}`)}</span>` : ''}
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
  const maxTotal = Math.max(...currentPaymentHistoryCashflow.map((row) => getSubscriptionPaymentDisplayAmountNumber(
    row.total || 0,
    row.total_main_currency_available,
    row.total_original
  )), 0.01);
  const actualTotal = currentPaymentHistoryCashflow.reduce((sum, row) => sum + Number(row.actual_total || 0), 0);
  const predictedTotal = currentPaymentHistoryCashflow.reduce((sum, row) => sum + Number(row.predicted_total || 0), 0);
  const actualTotalMainAvailable = currentPaymentHistoryCashflow.every((row) => isSubscriptionPaymentMainCurrencyAvailable(row.actual_total_main_currency_available));
  const predictedTotalMainAvailable = currentPaymentHistoryCashflow.every((row) => isSubscriptionPaymentMainCurrencyAvailable(row.predicted_total_main_currency_available));
  const actualTotalOriginal = buildSubscriptionPaymentCombinedOriginalTotal(currentPaymentHistoryCashflow, "actual_total_original");
  const predictedTotalOriginal = buildSubscriptionPaymentCombinedOriginalTotal(currentPaymentHistoryCashflow, "predicted_total_original");

  return `
    <div class="subscription-payment-section-intro">
      <strong>${escapeHtml(`${translate('subscription_payment_history_year_label')}: ${currentPaymentHistoryYear}`)}</strong>
      <span>${escapeHtml(`${translate('subscription_payment_cashflow_actual')}: ${formatSubscriptionPaymentHistoryMainOrOriginalAmount(actualTotal, currencyCode, actualTotalMainAvailable, actualTotalOriginal)} / ${translate('subscription_payment_cashflow_predicted')}: ${formatSubscriptionPaymentHistoryMainOrOriginalAmount(predictedTotal, currencyCode, predictedTotalMainAvailable, predictedTotalOriginal)}`)}</span>
    </div>
    <div class="subscription-payment-cashflow-list">
      ${currentPaymentHistoryCashflow.map((row) => {
        const actualDisplayValue = getSubscriptionPaymentDisplayAmountNumber(
          row.actual_total || 0,
          row.actual_total_main_currency_available,
          row.actual_total_original
        );
        const predictedDisplayValue = getSubscriptionPaymentDisplayAmountNumber(
          row.predicted_total || 0,
          row.predicted_total_main_currency_available,
          row.predicted_total_original
        );
        const actualWidth = Math.max(0, Math.min(100, (actualDisplayValue / maxTotal) * 100));
        const predictedWidth = Math.max(0, Math.min(100 - actualWidth, (predictedDisplayValue / maxTotal) * 100));
        const monthLabel = new Date(year, Number(row.month_number || 1) - 1, 1).toLocaleDateString(navigator.language, {
          month: 'short',
          year: 'numeric',
        });
        const totalLabel = formatSubscriptionPaymentHistoryMainOrOriginalAmount(
          row.total || 0,
          currencyCode,
          row.total_main_currency_available,
          row.total_original
        );
        const actualLabel = formatSubscriptionPaymentHistoryMainOrOriginalAmount(
          row.actual_total || 0,
          currencyCode,
          row.actual_total_main_currency_available,
          row.actual_total_original
        );
        const predictedLabel = formatSubscriptionPaymentHistoryMainOrOriginalAmount(
          row.predicted_total || 0,
          currencyCode,
          row.predicted_total_main_currency_available,
          row.predicted_total_original
        );

        return `
          <article class="subscription-payment-cashflow-row">
            <div class="subscription-payment-cashflow-topline">
              <strong>${escapeHtml(monthLabel)}</strong>
              <span>${escapeHtml(totalLabel)}</span>
            </div>
            <div class="subscription-payment-cashflow-bar" aria-hidden="true">
              <span class="actual" style="width: ${actualWidth}%;"></span>
              <span class="predicted" style="width: ${predictedWidth}%;"></span>
            </div>
            <div class="subscription-payment-record-meta">
              <span>${escapeHtml(`${translate('subscription_payment_cashflow_actual')}: ${actualLabel}`)}</span>
              <span>${escapeHtml(`${translate('subscription_payment_cashflow_predicted')}: ${predictedLabel}`)}</span>
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
    const hasMainConversion = hasSubscriptionPaymentMainCurrencyConversion(item);
    const originalAmountLabel = formatSubscriptionPaymentHistoryAmount(item.amount_original || 0, item.currency_code || '');
    const amountLabel = hasMainConversion
      ? formatSubscriptionPaymentHistoryAmount(item.amount_main || 0, item.main_currency_code || '')
      : originalAmountLabel;

    return `
      <article class="subscription-payment-record-item">
        <div class="subscription-payment-record-topline">
          <strong>${escapeHtml(item.due_date || '-')}</strong>
          <span>${escapeHtml(amountLabel)}</span>
        </div>
        <div class="subscription-payment-record-meta">
          <span>${escapeHtml(`${translate('subscription_payment_forecast_original_amount')}: ${originalAmountLabel}`)}</span>
          ${hasMainConversion ? '' : `<span>${escapeHtml(`${translate('subscription_payment_main_amount')}: ${getSubscriptionPaymentMissingExchangeRateLabel()}`)}</span>`}
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
      showErrorMessage(normalizeError(error, translate("error")));
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

  if (!confirm(translate("confirm_delete_subscription_payment_record"))) {
    return;
  }

  window.WallosHttp.postJson("endpoints/subscription/deletepayment.php", {
    id: subscriptionId,
    record_id: recordId,
  })
    .then((data) => {
      if (data.success) {
        showSuccessMessage(data.message || translate("success"));
        if (typeof refreshSubscriptionsPreservingStateHandler === "function") {
          refreshSubscriptionsPreservingStateHandler({
            initiator: "payment-history",
            subscriptionId,
            historyTab: currentPaymentHistoryTab,
            historyYear: currentPaymentHistoryYear,
            historyRangeMonths: currentPaymentHistoryRangeMonths,
            reopenHistory: true,
          }).catch(() => showErrorMessage(translate("error")));
        }
      } else {
        showErrorMessage(data.message || translate("error"));
      }
    })
    .catch((error) => showErrorMessage(normalizeError(error, translate("error"))));
}

function handleSubscriptionPaymentFormSubmit(event) {
  event.preventDefault();

  const saveButton = document.querySelector("#subscription-payment-save-button");
  if (!saveButton) {
    return;
  }

  saveButton.disabled = true;
  const payload = {
    id: Number(document.querySelector("#subscription-payment-subscription-id")?.value || 0),
    record_id: Number(document.querySelector("#subscription-payment-record-id")?.value || 0),
    due_date: document.querySelector("#subscription-payment-due-date")?.value || "",
    paid_at: document.querySelector("#subscription-payment-paid-at")?.value || "",
    amount_original: document.querySelector("#subscription-payment-amount")?.value || "",
    currency_id: Number(document.querySelector("#subscription-payment-currency")?.value || 0),
    payment_method_id: Number(document.querySelector("#subscription-payment-method")?.value || 0),
    note: document.querySelector("#subscription-payment-note")?.value || "",
  };

  window.WallosHttp.postJson(
    payload.record_id > 0 ? "endpoints/subscription/updatepayment.php" : "endpoints/subscription/recordpayment.php",
    payload
  )
    .then((data) => {
      if (data.success) {
        showSuccessMessage(data.message || translate("success"));
        const shouldReopenHistory = reopenPaymentHistoryAfterPaymentModalClose && Number(payload.id || 0) > 0;
        const openSubscriptionIds = typeof getOpenSubscriptionIdsHandler === "function" ? getOpenSubscriptionIdsHandler() : [];
        closeSubscriptionPaymentModal({ skipReopenHistory: true });
        if (typeof refreshSubscriptionsPreservingStateHandler === "function") {
          refreshSubscriptionsPreservingStateHandler({
            initiator: "payment-save",
            openSubscriptionIds,
            subscriptionId: payload.id,
            historyTab: currentPaymentHistoryTab,
            historyYear: currentPaymentHistoryYear,
            historyRangeMonths: currentPaymentHistoryRangeMonths,
            reopenHistory: shouldReopenHistory,
          }).catch(() => showErrorMessage(translate("error")));
        }
      } else {
        showErrorMessage(data.message || translate("error"));
      }
    })
    .catch(() => showErrorMessage(translate("error")))
    .finally(() => {
      saveButton.disabled = false;
    });
}
function initialize(options = {}) {
  refreshSubscriptionsPreservingStateHandler = typeof options.refreshSubscriptionsPreservingState === "function" ? options.refreshSubscriptionsPreservingState : null;
  getOpenSubscriptionIdsHandler = typeof options.getOpenSubscriptionIds === "function" ? options.getOpenSubscriptionIds : null;
  if (initialized) { return; }
  const dueDateInput = document.querySelector("#subscription-payment-due-date");
  const paymentForm = document.querySelector("#subscription-payment-form");
  if (dueDateInput) { dueDateInput.addEventListener("change", function () { applyCurrentPriceRulePreview(this.value || ""); }); }
  if (paymentForm) { paymentForm.addEventListener("submit", handleSubscriptionPaymentFormSubmit); }
  initialized = true;
}
window.WallosSubscriptionPayments = {
  initialize,
  resetSubscriptionPaymentForm,
  closeSubscriptionPaymentModal,
  fillSubscriptionPaymentForm,
  fillSubscriptionPaymentFormFromRecord,
  openSubscriptionPaymentModal,
  closeSubscriptionPaymentHistoryModal,
  formatSubscriptionPaymentHistoryAmount,
  exportSubscriptionPaymentHistoryCurrentView,
  setSubscriptionPaymentHistoryTab,
  renderSubscriptionPaymentHistoryModal,
  openSubscriptionPaymentHistoryModal,
  openEditSubscriptionPaymentModal,
  deleteSubscriptionPaymentRecord,
  applyCurrentPriceRulePreview
};
})();


