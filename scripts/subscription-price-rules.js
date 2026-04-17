(function () {
  let subscriptionPriceRules = [];
  let subscriptionPriceRuleTempIdCounter = 0;

  function escapeSubscriptionPriceRuleHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function createTempId() {
    subscriptionPriceRuleTempIdCounter += 1;
    return `price-rule-${Date.now()}-${subscriptionPriceRuleTempIdCounter}`;
  }

  function normalizeRule(rule = {}, index = 0) {
    return {
      id: Number(rule.id || 0),
      tempId: rule.tempId || createTempId(),
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

  function getOccurrenceIndexForDueDate(subscription, dueDate) {
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
      const currentString = current.toISOString().split("T")[0];
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

  function doesRuleMatch(rule, subscription, dueDate) {
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
      const occurrenceIndex = getOccurrenceIndexForDueDate(subscription, dueDate);
      return occurrenceIndex !== null && occurrenceIndex <= Math.max(0, Number(rule.max_cycles || 0));
    }

    return false;
  }

  function getEffectiveRule(subscription, dueDate) {
    const rules = Array.isArray(subscription?.price_rules) ? subscription.price_rules : [];
    const normalizedRules = rules
      .map((rule, index) => normalizeRule(rule, index))
      .sort((left, right) => (left.priority - right.priority) || (left.id - right.id));

    return normalizedRules.find((rule) => doesRuleMatch(rule, subscription, dueDate)) || null;
  }

  function applyPaymentPreview(subscription, mode, dueDate) {
    if (!subscription || mode !== "create") {
      return;
    }

    const amountInput = document.querySelector("#subscription-payment-amount");
    const currencyInput = document.querySelector("#subscription-payment-currency");
    if (!amountInput || !currencyInput) {
      return;
    }

    const matchedRule = getEffectiveRule(subscription, dueDate);
    if (matchedRule) {
      amountInput.value = matchedRule.price;
      currencyInput.value = String(matchedRule.currency_id || subscription.currency_id || "");
      return;
    }

    amountInput.value = subscription.price || "";
    currencyInput.value = String(subscription.currency_id || "");
  }

  function getCurrencyOptionsHtml() {
    const currencySelect = document.querySelector("#currency");
    return currencySelect ? currencySelect.innerHTML : "";
  }

  function serializeRules() {
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

  function updateField(tempId, field, value, rerender = false, isCheckbox = false) {
    const rule = subscriptionPriceRules.find((item) => item.tempId === tempId);
    if (!rule) {
      return;
    }

    rule[field] = isCheckbox ? !!value : value;
    serializeRules();

    if (rerender) {
      render();
    }
  }

  function addRule(ruleType = "first_n_cycles") {
    subscriptionPriceRules.push(normalizeRule({
      rule_type: ruleType,
      currency_id: document.querySelector("#currency")?.value || "",
      max_cycles: "1",
      enabled: true,
    }, subscriptionPriceRules.length));
    render();
  }

  function removeRule(tempId) {
    subscriptionPriceRules = subscriptionPriceRules.filter((rule) => rule.tempId !== tempId);
    render();
  }

  function setRules(rules = []) {
    subscriptionPriceRules = Array.isArray(rules)
      ? rules.map((rule, index) => normalizeRule(rule, index))
      : [];
    render();
  }

  function resetRules() {
    setRules([]);
  }

  function render() {
    const list = document.querySelector("#subscription-price-rules-list");
    if (!list) {
      return;
    }

    const currencyOptionsHtml = getCurrencyOptionsHtml();
    if (!subscriptionPriceRules.length) {
      list.innerHTML = `<div class="subscription-price-rules-empty">${translate("subscription_price_rules_empty")}</div>`;
      serializeRules();
      return;
    }

    subscriptionPriceRules.forEach((rule, index) => {
      rule.priority = index + 1;
    });

    list.innerHTML = subscriptionPriceRules.map((rule, index) => `
      <article class="subscription-price-rule-card" data-rule-temp-id="${escapeSubscriptionPriceRuleHtml(rule.tempId)}" data-rule-type="${escapeSubscriptionPriceRuleHtml(rule.rule_type)}">
        <div class="subscription-price-rule-card-header">
          <strong>${translate("subscription_price_rule_card_title")} ${index + 1}</strong>
          <button type="button" class="warning-button thin subscription-price-rule-remove"
            data-subscription-action="remove-price-rule"
            data-rule-temp-id="${escapeSubscriptionPriceRuleHtml(rule.tempId)}">
            <i class="fa-solid fa-trash"></i>
            <span>${translate("delete")}</span>
          </button>
        </div>
        <div class="subscription-price-rule-grid">
          <div class="form-group">
            <label>${translate("subscription_price_rule_type")}</label>
            <select data-subscription-change="price-rule-field" data-rule-temp-id="${escapeSubscriptionPriceRuleHtml(rule.tempId)}" data-rule-field="rule_type" data-rule-rerender="1">
              <option value="first_n_cycles" ${rule.rule_type === "first_n_cycles" ? "selected" : ""}>${translate("subscription_price_rule_type_first_n_cycles")}</option>
              <option value="date_range" ${rule.rule_type === "date_range" ? "selected" : ""}>${translate("subscription_price_rule_type_date_range")}</option>
              <option value="one_time" ${rule.rule_type === "one_time" ? "selected" : ""}>${translate("subscription_price_rule_type_one_time")}</option>
            </select>
          </div>
          <div class="form-group">
            <label>${translate("subscription_price_rule_price")}</label>
            <input type="number" step="0.01" min="0" value="${escapeSubscriptionPriceRuleHtml(rule.price)}" data-subscription-input="price-rule-field" data-rule-temp-id="${escapeSubscriptionPriceRuleHtml(rule.tempId)}" data-rule-field="price">
          </div>
          <div class="form-group">
            <label>${translate("subscription_price_rule_currency")}</label>
            <select data-subscription-change="price-rule-field" data-rule-temp-id="${escapeSubscriptionPriceRuleHtml(rule.tempId)}" data-rule-field="currency_id">${currencyOptionsHtml}</select>
          </div>
          <div class="form-group subscription-price-rule-conditional subscription-price-rule-first-cycles">
            <label>${translate("subscription_price_rule_max_cycles")}</label>
            <input type="number" min="1" step="1" value="${escapeSubscriptionPriceRuleHtml(rule.max_cycles)}" data-subscription-input="price-rule-field" data-rule-temp-id="${escapeSubscriptionPriceRuleHtml(rule.tempId)}" data-rule-field="max_cycles">
          </div>
          <div class="form-group subscription-price-rule-conditional subscription-price-rule-one-time">
            <label>${translate("subscription_price_rule_due_date")}</label>
            <div class="date-wrapper">
              <input type="date" value="${escapeSubscriptionPriceRuleHtml(rule.start_date)}" data-subscription-change="price-rule-field" data-rule-temp-id="${escapeSubscriptionPriceRuleHtml(rule.tempId)}" data-rule-field="start_date">
            </div>
          </div>
          <div class="subscription-price-rule-date-range subscription-price-rule-conditional">
            <div class="form-group">
              <label>${translate("subscription_price_rule_start_date")}</label>
              <div class="date-wrapper">
                <input type="date" value="${escapeSubscriptionPriceRuleHtml(rule.start_date)}" data-subscription-change="price-rule-field" data-rule-temp-id="${escapeSubscriptionPriceRuleHtml(rule.tempId)}" data-rule-field="start_date">
              </div>
            </div>
            <div class="form-group">
              <label>${translate("subscription_price_rule_end_date")}</label>
              <div class="date-wrapper">
                <input type="date" value="${escapeSubscriptionPriceRuleHtml(rule.end_date)}" data-subscription-change="price-rule-field" data-rule-temp-id="${escapeSubscriptionPriceRuleHtml(rule.tempId)}" data-rule-field="end_date">
              </div>
            </div>
          </div>
          <div class="form-group subscription-price-rule-note-group">
            <label>${translate("notes")}</label>
            <textarea rows="3" data-subscription-input="price-rule-field" data-rule-temp-id="${escapeSubscriptionPriceRuleHtml(rule.tempId)}" data-rule-field="note">${escapeSubscriptionPriceRuleHtml(rule.note)}</textarea>
          </div>
          <div class="form-group-inline grow subscription-price-rule-enabled">
            <input type="checkbox" id="subscription-price-rule-enabled-${escapeSubscriptionPriceRuleHtml(rule.tempId)}" ${rule.enabled ? "checked" : ""} data-subscription-change="price-rule-field" data-rule-temp-id="${escapeSubscriptionPriceRuleHtml(rule.tempId)}" data-rule-field="enabled" data-rule-checkbox="1">
            <label for="subscription-price-rule-enabled-${escapeSubscriptionPriceRuleHtml(rule.tempId)}" class="grow">${translate("subscription_price_rule_enabled")}</label>
          </div>
        </div>
      </article>
    `).join("");

    subscriptionPriceRules.forEach((rule) => {
      const card = list.querySelector(`[data-rule-temp-id="${rule.tempId}"]`);
      const currencySelect = card?.querySelectorAll("select")[1];
      if (currencySelect) {
        currencySelect.value = String(rule.currency_id || "");
      }
    });

    serializeRules();
  }

  function syncField(target, rerenderFallback = false) {
    const tempId = target?.dataset?.ruleTempId || "";
    const field = target?.dataset?.ruleField || "";
    if (!tempId || !field) {
      return;
    }

    const isCheckbox = target.dataset.ruleCheckbox === "1";
    const rerender = target.dataset.ruleRerender === "1" || rerenderFallback;
    const value = isCheckbox ? target.checked : target.value;
    updateField(tempId, field, value, rerender, isCheckbox);
  }

  function initialize() {
    render();
  }

  window.WallosSubscriptionPriceRules = {
    initialize,
    createTempId,
    normalizeRule,
    getOccurrenceIndexForDueDate,
    doesRuleMatch,
    getEffectiveRule,
    applyPaymentPreview,
    getCurrencyOptionsHtml,
    serializeRules,
    updateField,
    addRule,
    removeRule,
    setRules,
    resetRules,
    render,
    syncField,
  };
})();
