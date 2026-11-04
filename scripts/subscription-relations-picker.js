(function () {
  'use strict';
  const locale = String(window.lang || document.documentElement.lang || '').toLowerCase();
  const zh = locale.startsWith('zh');
  const traditional = locale === 'zh_tw' || locale === 'zh-tw';
  const hint = zh ? (traditional ? '勾選可多選' : '勾选可多选') : 'Select one or more';
  const empty = zh ? (traditional ? '請選擇' : '请选择') : 'Choose';
  let active = null;

  function enhance(select) {
    if (select.dataset.relationPickerReady) return;
    select.dataset.relationPickerReady = 'true';
    const wrapper = document.createElement('div');
    wrapper.className = 'subscription-relation-picker';
    select.before(wrapper);
    wrapper.append(select);
    select.classList.add('subscription-relation-native');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');
    const label = document.querySelector(`label[for="${select.id}"]`);
    const title = select.getAttribute('aria-label') || label?.textContent.trim() || empty;
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.id = `${select.id}-picker-toggle`;
    toggle.className = 'subscription-relation-toggle';
    toggle.setAttribute('aria-label', title);
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-controls', `${select.id}-picker-panel`);
    const value = document.createElement('span');
    value.className = 'subscription-relation-value';
    const count = document.createElement('span');
    count.className = 'subscription-relation-count';
    const chevron = document.createElement('i');
    chevron.className = 'fa-solid fa-chevron-down subscription-relation-chevron';
    chevron.setAttribute('aria-hidden', 'true');
    toggle.append(value, count, chevron);
    wrapper.append(toggle);

    // A body-level panel stays visible inside the scrollable subscription form
    // and is bounded to the viewport instead of extending its scroll height.
    const panel = document.createElement('div');
    panel.id = `${select.id}-picker-panel`;
    panel.className = 'subscription-relation-panel';
    panel.setAttribute('data-page-ui-hide-target', '');
    panel.hidden = true;
    panel.setAttribute('role', 'group');
    panel.setAttribute('aria-label', title);
    const help = document.createElement('div');
    help.className = 'subscription-relation-hint';
    help.textContent = hint;
    panel.append(help);
    document.body.append(panel);
    let entries = [];

    function position() {
      if (panel.hidden) return;
      const rect = toggle.getBoundingClientRect();
      const margin = 8;
      if (!toggle.getClientRects().length || rect.bottom <= margin || rect.top >= window.innerHeight - margin) {
        close();
        return;
      }
      const width = Math.min(Math.max(rect.width, 220), window.innerWidth - margin * 2);
      panel.style.width = `${width}px`;
      panel.style.left = `${Math.max(margin, Math.min(rect.left, window.innerWidth - width - margin))}px`;
      const below = window.innerHeight - rect.bottom - margin;
      const above = rect.top - margin;
      const upwards = below < 160 && above > below;
      panel.style.maxHeight = `${Math.max(1, Math.min(260, upwards ? above - 4 : below - 4))}px`;
      panel.style.top = upwards ? `${rect.top - panel.offsetHeight - 4}px` : `${rect.bottom + 4}px`;
    }
    function close(focus = false) {
      panel.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      if (active?.select === select) active = null;
      if (focus) toggle.focus();
    }
    function open(focus = false) {
      if (select.disabled || select.hidden) return;
      if (active?.select !== select) active?.close();
      refresh();
      panel.hidden = false;
      toggle.setAttribute('aria-expanded', 'true');
      active = { select, wrapper, panel, close, position };
      position();
      if (focus) (entries.find((entry) => entry.option.selected) || entries[0])?.checkbox.focus();
    }
    function refresh() {
      const options = Array.from(select.options);
      if (entries.length !== options.length || entries.some((entry, index) => entry.option !== options[index] || entry.text !== options[index].textContent)) {
        entries.forEach((entry) => entry.label.remove());
        entries = options.map((option) => {
          const optionLabel = document.createElement('label');
          optionLabel.className = 'subscription-relation-option';
          const checkbox = document.createElement('input');
          checkbox.type = 'checkbox';
          checkbox.value = option.value;
          const text = document.createElement('span');
          text.textContent = option.textContent;
          optionLabel.append(checkbox, text);
          panel.append(optionLabel);
          checkbox.addEventListener('change', () => {
            option.selected = checkbox.checked;
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
          });
          return { option, checkbox, label: optionLabel, text: option.textContent };
        });
      }
      entries.forEach(({ option, checkbox }) => {
        checkbox.checked = option.selected;
        checkbox.disabled = select.disabled || option.disabled;
      });
      const selected = options.filter((option) => option.selected).map((option) => option.textContent.trim());
      value.textContent = selected.length ? selected.join(', ') : empty;
      toggle.setAttribute('aria-label', `${title}: ${value.textContent}`);
      toggle.title = selected.length ? selected.join(', ') : title;
      count.textContent = String(selected.length);
      count.hidden = selected.length < 2;
      toggle.disabled = select.disabled;
      wrapper.hidden = select.hidden;
      if (select.hidden || select.disabled || !wrapper.getClientRects().length) close();
      else position();
    }

    toggle.addEventListener('click', () => panel.hidden ? open() : close());
    toggle.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        open(true);
      }
    });
    panel.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') { event.preventDefault(); close(true); }
    });
    label?.addEventListener('click', (event) => {
      event.preventDefault();
      toggle.focus();
      open();
    });
    select.addEventListener('change', refresh);
    select.addEventListener('invalid', (event) => {
      event.preventDefault();
      open(true);
    });
    select.form?.addEventListener('reset', () => queueMicrotask(() => { close(); refresh(); }));
    new MutationObserver(refresh).observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden', 'disabled', 'selected', 'label'] });
    refresh();
  }

  document.addEventListener('pointerdown', (event) => {
    if (active && !active.wrapper.contains(event.target) && !active.panel.contains(event.target)) active.close();
  });
  document.addEventListener('focusin', (event) => {
    if (active && !active.wrapper.contains(event.target) && !active.panel.contains(event.target)) active.close();
  });
  window.addEventListener('resize', () => active?.position());
  document.addEventListener('scroll', (event) => {
    if (active && !active.panel.contains(event.target)) active.position();
  }, true);
  document.querySelectorAll('select[data-subscription-relation-picker]').forEach(enhance);
})();
