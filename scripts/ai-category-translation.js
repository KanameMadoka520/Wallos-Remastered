(() => {
  const root = document.getElementById('ai-category-translation');
  if (!root) return;
  const start = document.getElementById('ai-category-translate');
  const panel = document.getElementById('ai-category-preview');
  const rows = document.getElementById('ai-category-preview-rows');
  const apply = document.getElementById('ai-category-apply');
  const cancel = document.getElementById('ai-category-cancel');
  const status = document.getElementById('ai-category-status');
  let preview = null;
  let busy = false;
  let generation = 0;
  const privateMode = () => root.dataset.privacy === '1' || window.WallosScreenshotPrivacy?.isEnabled?.();
  const api = async data => {
    const result = await window.WallosApi.postJson('endpoints/ai/translate_categories.php', data, {
      requireOk: true, checkSuccess: true, fallbackErrorMessage: translate('ai_categories_failed'),
    });
    // The shared WallosHttp adapter historically ignores checkSuccess; keep
    // the application-level check here as well as HTTP status validation.
    if (!result.success) {
      const error = new Error(result.message || translate('ai_categories_failed'));
      error.data = result;
      throw error;
    }
    return result;
  };
  function clearPreview() {
    preview = null;
    panel.hidden = true;
    rows.replaceChildren();
  }
  function controls() {
    start.disabled = busy || privateMode();
    apply.disabled = busy || !preview || privateMode();
    cancel.disabled = busy;
    root.setAttribute('aria-busy', busy ? 'true' : 'false');
  }
  function fail(error) {
    status.textContent = window.WallosApi.getErrorMessage(error, translate('ai_categories_failed'));
    showErrorMessage(status.textContent);
  }
  function categoriesMatch(data) {
    const inputs = [...document.querySelectorAll('#categories [data-categoryid] input[name="category"]')];
    return inputs.length === data.length && data.every(item => {
      const input = inputs.find(input => Number(input.closest('[data-categoryid]').dataset.categoryid) === item.id);
      return input && input.value === item.before;
    });
  }
  start.addEventListener('click', async () => {
    if (busy || privateMode()) return;
    clearPreview();
    busy = true;
    const current = ++generation;
    controls();
    status.textContent = translate('ai_categories_translating');
    try {
      const result = await api({action: 'preview'});
      if (current !== generation || privateMode()) return;
      if (!Array.isArray(result.translations) || !categoriesMatch(result.translations)) {
        throw new Error(translate('ai_categories_unsaved'));
      }
      preview = result;
      for (const item of result.translations) {
        const row = document.createElement('tr');
        for (const value of [item.before, item.name]) {
          const cell = document.createElement('td');
          // AI labels are text, including any HTML-looking content.
          cell.textContent = value;
          row.append(cell);
        }
        rows.append(row);
      }
      panel.hidden = false;
      status.textContent = translate('ai_categories_preview_ready') + ' ' + result.language_name;
    } catch (error) {
      if (current === generation && !privateMode()) fail(error);
    } finally {
      busy = false;
      controls();
    }
  });
  apply.addEventListener('click', async () => {
    if (busy || !preview || privateMode()) return;
    if (!categoriesMatch(preview.translations)) {
      clearPreview();
      controls();
      fail(new Error(translate('ai_categories_unsaved')));
      return;
    }
    busy = true;
    controls();
    try {
      const result = await api({action: 'apply', token: preview.token});
      if (privateMode()) return;
      for (const item of result.translations) {
        const input = document.querySelector(`#categories [data-categoryid="${item.id}"] input[name="category"]`);
        if (input && input.value === item.before) input.value = item.name;
      }
      clearPreview();
      status.textContent = translate('ai_categories_applied');
      showSuccessMessage(status.textContent);
    } catch (error) {
      // A stale/expired preview must be regenerated. Transport failure leaves
      // the token available for a safe retry of the same server-side preview.
      if (['data_changed', 'preview_expired', 'screenshot_privacy_mode_blocked'].includes(error.data?.code)) clearPreview();
      if (!privateMode()) fail(error);
    } finally {
      busy = false;
      controls();
    }
  });
  cancel.addEventListener('click', () => {
    clearPreview();
    status.textContent = translate('ai_categories_cancelled');
    controls();
  });
  window.addEventListener('wallos:screenshot-privacy-change', event => {
    if (event.detail?.enabled) {
      generation++;
      clearPreview();
      document.getElementById('categories')?.replaceChildren();
      status.textContent = translate('screenshot_privacy_mode_blocked');
    }
    controls();
  });
  controls();
})();
