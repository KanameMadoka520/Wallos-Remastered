function mountMetricExplanationModalToBody() {
  const modal = document.getElementById('metric-explanation-modal');
  if (!modal || modal.dataset.mountedToBody === '1' || !document.body) {
    return;
  }

  document.body.appendChild(modal);
  modal.dataset.mountedToBody = '1';
}

function closeMetricExplanationModal() {
  const modal = document.getElementById('metric-explanation-modal');
  if (!modal) {
    return;
  }

  modal.classList.remove('is-open');
  document.body.classList.remove('no-scroll');
}

function escapeMetricExplanationHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function formatMetricExplanationValue(value, currencyCode, format = 'number') {
  const numericValue = Number(value || 0);
  if (format === 'percent') {
    return `${new Intl.NumberFormat(navigator.language, {
      minimumFractionDigits: 0,
      maximumFractionDigits: 2,
    }).format(numericValue)}%`;
  }

  if (format === 'currency' && currencyCode) {
    return new Intl.NumberFormat(navigator.language, { style: 'currency', currency: currencyCode }).format(numericValue);
  }

  return new Intl.NumberFormat(navigator.language).format(numericValue);
}

function renderMetricExplanationSummary(payload) {
  const summary = document.getElementById('metric-explanation-summary');
  if (!summary) {
    return;
  }

  const summaryCards = Array.isArray(payload.summary_cards) ? payload.summary_cards : [];
  if (summaryCards.length) {
    summary.innerHTML = summaryCards.map((card) => `
      <div class="metric-explanation-summary-card">
        <span>${escapeMetricExplanationHtml(card.label || '-')}</span>
        <strong>${escapeMetricExplanationHtml(
          formatMetricExplanationValue(
            card.value || 0,
            card.currency_code || payload.currency_code || '',
            card.format || 'number',
          ),
        )}</strong>
      </div>
    `).join('');
    return;
  }

  const cards = [];
  cards.push(`
    <div class="metric-explanation-summary-card">
      <span>${translate('metric_explanation_total_label')}</span>
      <strong>${formatMetricExplanationValue(payload.total || 0, payload.currency_code || '', payload.total_format || (payload.currency_code ? 'currency' : 'number'))}</strong>
    </div>
  `);

  if (payload.reference_total !== undefined) {
    cards.push(`
      <div class="metric-explanation-summary-card">
        <span>${translate('metric_explanation_reference_total')}</span>
        <strong>${formatMetricExplanationValue(payload.reference_total || 0, payload.currency_code || '', 'currency')}</strong>
      </div>
    `);
  }

  if (payload.cost_total !== undefined) {
    cards.push(`
      <div class="metric-explanation-summary-card">
        <span>${translate('metric_explanation_cost_total')}</span>
        <strong>${formatMetricExplanationValue(payload.cost_total || 0, payload.currency_code || '', 'currency')}</strong>
      </div>
    `);
  }

  if (payload.actual_paid_total !== undefined) {
    cards.push(`
      <div class="metric-explanation-summary-card">
        <span>${translate('metric_explanation_actual_paid_total')}</span>
        <strong>${formatMetricExplanationValue(payload.actual_paid_total || 0, payload.currency_code || '', 'currency')}</strong>
      </div>
    `);
  }

  if (payload.projected_remaining_total !== undefined) {
    cards.push(`
      <div class="metric-explanation-summary-card">
        <span>${translate('metric_explanation_projected_remaining_total')}</span>
        <strong>${formatMetricExplanationValue(payload.projected_remaining_total || 0, payload.currency_code || '', 'currency')}</strong>
      </div>
    `);
  }

  summary.innerHTML = cards.join('');
}

function renderMetricExplanationItemsList(items, payload) {
  if (!items.length) {
    return `<div class="metric-explanation-empty">${escapeMetricExplanationHtml(translate('metric_explanation_no_items'))}</div>`;
  }

  return items.map((item) => {
    const itemName = item.subscription_name || item.name || '-';
    const itemCurrencyCode = item.currency_code || item.main_currency_code_snapshot || payload.currency_code || '';
    const primaryAmount =
      item.total_amount !== undefined
        ? formatMetricExplanationValue(item.total_amount, itemCurrencyCode, 'currency')
        : item.amount_main_snapshot !== undefined
          ? formatMetricExplanationValue(item.amount_main_snapshot, item.main_currency_code_snapshot || payload.currency_code || '', 'currency')
          : item.monthly_equivalent !== undefined
            ? formatMetricExplanationValue(item.monthly_equivalent, itemCurrencyCode, 'currency')
            : '-';

    const meta = [];
    if (item.billing_cycle) {
      meta.push(`${translate('cycle')}: ${item.billing_cycle}`);
    }
    if (item.price_per_charge !== undefined) {
      meta.push(`${translate('price')}: ${formatMetricExplanationValue(item.price_per_charge, itemCurrencyCode, 'currency')}`);
    }
    if (item.unit_amount !== undefined) {
      meta.push(`${translate('subscription_payment_amount')}: ${formatMetricExplanationValue(item.unit_amount, itemCurrencyCode, 'currency')}`);
    }
    if (item.count !== undefined) {
      meta.push(`${translate('frequency')}: ${new Intl.NumberFormat(navigator.language).format(item.count)}`);
    }
    if (item.next_due) {
      meta.push(`${translate('next_payment')}: ${item.next_due}`);
    }
    if (item.due_date) {
      meta.push(`${translate('subscription_payment_due_date')}: ${item.due_date}`);
    }
    if (item.paid_at) {
      meta.push(`${translate('subscription_payment_paid_at')}: ${item.paid_at}`);
    }
    if (item.amount_original !== undefined) {
      meta.push(`${translate('subscription_payment_amount')}: ${formatMetricExplanationValue(item.amount_original, item.currency_code_snapshot || '', 'currency')}`);
    }
    if (item.rule_summary) {
      meta.push(`${translate('metric_explanation_rule_source')}: ${item.rule_summary}`);
    }
    if (item.note) {
      meta.push(`${translate('notes')}: ${item.note}`);
    }

    return `
      <article class="metric-explanation-item">
        <div class="metric-explanation-item-headline">
          <strong>${escapeMetricExplanationHtml(itemName)}</strong>
          <span>${escapeMetricExplanationHtml(primaryAmount)}</span>
        </div>
        <div class="metric-explanation-item-meta">${meta.map((line) => `<span>${escapeMetricExplanationHtml(line)}</span>`).join('')}</div>
      </article>
    `;
  }).join('');
}

function renderMetricExplanationItems(payload) {
  const itemsContainer = document.getElementById('metric-explanation-items');
  if (!itemsContainer) {
    return;
  }

  const itemGroups = Array.isArray(payload.item_groups) ? payload.item_groups : [];
  if (itemGroups.length) {
    itemsContainer.innerHTML = itemGroups.map((group) => `
      <section class="metric-explanation-group">
        <div class="metric-explanation-group-title">${escapeMetricExplanationHtml(group.title || '-')}</div>
        <div class="metric-explanation-group-items">
          ${renderMetricExplanationItemsList(Array.isArray(group.items) ? group.items : [], payload)}
        </div>
      </section>
    `).join('');
    return;
  }

  const items = Array.isArray(payload.items) ? payload.items : [];
  itemsContainer.innerHTML = renderMetricExplanationItemsList(items, payload);
}

function openMetricExplanationModal(payload) {
  const modal = document.getElementById('metric-explanation-modal');
  const title = document.getElementById('metric-explanation-title');
  const formula = document.getElementById('metric-explanation-formula');

  if (!modal || !title || !formula) {
    return;
  }

  title.textContent = payload.title || translate('metric_explanation_title');
  formula.textContent = payload.formula || '-';
  renderMetricExplanationSummary(payload);
  renderMetricExplanationItems(payload);

  modal.classList.add('is-open');
  document.body.classList.add('no-scroll');
}

document.addEventListener('DOMContentLoaded', function () {
  mountMetricExplanationModalToBody();

  document.querySelectorAll('[data-metric-explanation]').forEach((trigger) => {
    trigger.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();

      try {
        const payload = JSON.parse(this.dataset.metricExplanation || '{}');
        openMetricExplanationModal(payload);
      } catch (error) {
        showErrorMessage(translate('error'));
      }
    });
  });
});
