(function () {
  'use strict';
  const toolbar = document.getElementById('subscription-bulk-toolbar');
  if (!toolbar) return;
  const strings = window.subscriptionBulkStrings || {};
  const endpoint = 'endpoints/subscriptions/bulkedit.php';
  const selected = new Set();
  const controls = Object.fromEntries(['action', 'days', 'categories', 'payment', 'count', 'error', 'targets', 'apply', 'cancel', 'retry-hint']
    .map((key) => [key, document.getElementById(`subscription-bulk-${key}`)]));
  const preview = document.getElementById('subscription-bulk-preview');
  const previewButton = document.getElementById('subscription-bulk-preview-button');
  let pending = null;
  let busy = false;
  let generation = 0;
  let unconfirmed = false;
  let applying = false;
  const requestId = () => `browser-bulk-${crypto.randomUUID ? crypto.randomUUID() : Array.from(crypto.getRandomValues(new Uint8Array(16)), (n) => n.toString(16).padStart(2, '0')).join('')}`;

  function showError(message) {
    controls.error.textContent = message || '';
    controls.error.hidden = !message;
  }
  function resetPreview() {
    if (unconfirmed) return;
    pending = null;
    generation++;
    preview.hidden = true;
    controls['retry-hint'].hidden = true;
    controls.apply.textContent = strings.apply;
    showError('');
  }
  function updateCount() {
    controls.count.textContent = (strings.selected || '{count} selected').replace('{count}', String(selected.size));
  }
  function updateCards() {
    // Only subscription cards inside the live list, never recycle-bin entries.
    document.querySelectorAll('.subscription[data-subscription-id]').forEach((card) => {
      if (card.closest('#subscription-recycle-bin-modal')) return;
      let label = card.querySelector(':scope > .subscription-bulk-selection');
      if (!label) {
        label = document.createElement('label');
        label.className = 'subscription-bulk-selection';
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.setAttribute('aria-label', strings.select || 'Select this subscription');
        const text = document.createElement('span');
        text.textContent = strings.select || 'Select this subscription';
        label.append(checkbox, text);
        label.addEventListener('click', (event) => event.stopPropagation());
        checkbox.addEventListener('change', () => {
          if (unconfirmed || busy) {
            checkbox.checked = selected.has(Number(card.dataset.subscriptionId));
            return;
          }
          const id = Number(card.dataset.subscriptionId);
          if (checkbox.checked && selected.size >= 50) {
            checkbox.checked = false;
            showError(strings.limit);
            return;
          }
          if (checkbox.checked) selected.add(id); else selected.delete(id);
          card.classList.toggle('subscription-bulk-selected', checkbox.checked);
          resetPreview();
          updateCount();
        });
        card.prepend(label);
      }
      const checked = selected.has(Number(card.dataset.subscriptionId));
      label.querySelector('input').checked = checked;
      card.classList.toggle('subscription-bulk-selected', checked);
    });
  }
  function allCards() {
    return Array.from(document.querySelectorAll('.subscription[data-subscription-id]'))
      .filter((card) => !card.closest('#subscription-recycle-bin-modal'));
  }
  function visibleCards() {
    return allCards().filter((card) => card.getClientRects().length > 0 && getComputedStyle(card).visibility !== 'hidden');
  }
  function invalidateSelection() {
    if (unconfirmed || applying) return;
    selected.clear();
    resetPreview();
    updateCards();
    updateCount();
  }
  function clear() {
    if (busy || unconfirmed) return;
    selected.clear();
    resetPreview();
    updateCards();
    updateCount();
  }
  function changes() {
    switch (controls.action.value) {
      case 'notifyOn': return { notify: true };
      case 'notifyOff': return { notify: false };
      case 'days': {
        const value = Number(controls.days.value);
        if (controls.days.value === '' || !Number.isInteger(value) || value < 0 || value > 365) throw new Error(strings.valueRequired);
        return { notify_days_before: value };
      }
      case 'categories': {
        const ids = Array.from(controls.categories.selectedOptions, (option) => Number(option.value));
        if (!ids.length) throw new Error(strings.valueRequired);
        return { category_ids: ids };
      }
      case 'payment': {
        const id = Number(controls.payment.value);
        if (!Number.isInteger(id) || id < 1) throw new Error(strings.valueRequired);
        return { payment_method_id: id };
      }
      case 'trash': return null;
      default: throw new Error(strings.empty);
    }
  }
  async function post(payload) {
    const response = await window.WallosHttp.postJson(endpoint, payload, { fallbackErrorMessage: strings.failed });
    if (!response || response.success !== true) {
      const error = new Error(response?.message || strings.failed);
      error.code = response?.code;
      throw error;
    }
    return response.result;
  }
  function setBusy(value) {
    busy = value;
    toolbar.querySelectorAll('button,select,input').forEach((element) => { element.disabled = value || (unconfirmed && element !== controls.apply); });
  }
  toolbar.addEventListener('toggle', () => {
    document.body.classList.toggle('subscription-bulk-mode', toolbar.open);
    updateCards();
    if (!toolbar.open) clear();
  });
  document.getElementById('subscription-bulk-select-page').addEventListener('click', () => {
    if (busy || unconfirmed) return;
    const current = visibleCards();
    if (current.length > 50) { showError(strings.limit); return; }
    selected.clear();
    current.forEach((card) => selected.add(Number(card.dataset.subscriptionId)));
    resetPreview();
    updateCards();
    updateCount();
  });
  document.getElementById('subscription-bulk-clear').addEventListener('click', clear);
  document.getElementById('search')?.addEventListener('input', invalidateSelection);
  document.addEventListener('click', (event) => {
    if (event.target.closest('[data-subscription-action="clear-search"]')) invalidateSelection();
  });
  ['action', 'days', 'categories', 'payment'].forEach((key) => controls[key].addEventListener('change', () => {
    controls.days.hidden = controls.action.value !== 'days';
    controls.categories.hidden = controls.action.value !== 'categories';
    controls.payment.hidden = controls.action.value !== 'payment';
    resetPreview();
  }));
  controls.cancel.addEventListener('click', resetPreview);
  previewButton.addEventListener('click', async () => {
    if (busy || unconfirmed) return;
    resetPreview();
    const currentGeneration = generation;
    try {
      if (!selected.size || !controls.action.value) throw new Error(strings.empty);
      const changed = changes();
      const ids = Array.from(selected);
      const action = controls.action.value === 'trash' ? 'trash' : 'update';
      setBusy(true);
      const snapshot = await post({ action: 'inspect', ids });
      const args = {
        targets: snapshot.subscriptions.map((row) => ({ id: row.id, expected_version: row.version })),
        request_id: requestId(), dry_run: true,
        ...(action === 'trash' ? { confirm_ids: ids } : { changes: changed }),
      };
      await post({ action, arguments: args });
      if (currentGeneration !== generation) return;
      pending = { action, arguments: { ...args, request_id: requestId(), dry_run: false } };
      controls.targets.replaceChildren(...snapshot.subscriptions.map((row) => {
        const item = document.createElement('li');
        item.textContent = `#${row.id} ${row.name}`;
        return item;
      }));
      let actionText = controls.action.selectedOptions[0].textContent;
      if (controls.action.value === 'days') actionText += `: ${changed.notify_days_before}`;
      if (controls.action.value === 'categories') actionText += `: ${Array.from(controls.categories.selectedOptions, (option) => option.textContent).join(', ')}`;
      if (controls.action.value === 'payment') actionText += `: ${controls.payment.selectedOptions[0].textContent}`;
      document.getElementById('subscription-bulk-preview-action').textContent = actionText;
      preview.hidden = false;
    } catch (error) {
      showError(error.message || strings.failed);
    } finally {
      setBusy(false);
    }
  });
  controls.apply.addEventListener('click', async () => {
    if (busy || !pending) return;
    applying = true;
    setBusy(true);
    try {
      await post(pending);
      unconfirmed = false;
      window.location.reload();
    } catch (error) {
      const rejected = ['conflict', 'not_found', 'invalid_arguments', 'confirmation_mismatch', 'in_recycle_bin', 'unauthorized', 'forbidden', 'invalid_csrf', 'session_expired'].includes(error.code);
      if (rejected) {
        // A definitive validation rejection requires a fresh preview and user
        // review; never refresh versions and silently apply the original edit.
        unconfirmed = false;
        resetPreview();
      } else {
        // Preserve exact payload and request ID for an uncertain response. A
        // retry replays a committed result, including after lost network.
        unconfirmed = true;
        controls.apply.textContent = strings.retry;
        controls['retry-hint'].hidden = false;
      }
      showError(error.message || strings.failed);
    } finally {
      applying = false;
      setBusy(false);
    }
  });
  // AJAX pagination replaces cards. Observe only child-list changes, not our
  // checkbox/class state, and discard stale selection/preview on list refresh.
  let lastNodes = [];
  const observer = new MutationObserver(() => {
    const nodes = allCards();
    if (nodes.length === lastNodes.length && nodes.every((node, index) => node === lastNodes[index])) return;
    lastNodes = nodes;
    invalidateSelection();
    updateCards();
  });
  observer.observe(document.querySelector('.subscriptions-page-layout') || document.body, { childList: true, subtree: true });
  lastNodes = allCards();
  updateCards();
  updateCount();
})();
