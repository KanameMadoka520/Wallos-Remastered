(() => {
  const root = document.querySelector('[data-agent-settings]');
  if (!root) return;
  const labels = JSON.parse(document.getElementById('agent-labels').textContent);
  const feedback = root.querySelector('[data-agent-feedback]');
  const form = root.querySelector('[data-agent-create]');
  const secret = root.querySelector('[data-agent-secret]');
  const panel = root.querySelector('[data-agent-secret-panel]');
  root.querySelector('[data-agent-mcp]').textContent = new URL('api/agent/mcp.php', location.href).href;
  root.querySelector('[data-agent-http]').textContent = new URL('api/agent/v1/index.php', location.href).href;
  const clearSecret = () => { secret.value = ''; panel.hidden = true; };
  window.addEventListener('pagehide', clearSecret);
  root.querySelector('[data-agent-hide]').addEventListener('click', clearSecret);
  const call = async (action, data = new FormData()) => {
    data.set('action', action);
    const response = await window.WallosApi.postForm('endpoints/agent/keys.php', data);
    if (!response.success) throw new Error(response.error?.message || labels.error);
    return response.result;
  };
  const date = value => value ? new Date(value * 1000).toLocaleString() : labels.never;
  const element = (tag, text) => { const el = document.createElement(tag); el.textContent = text; return el; };
  const showError = error => { feedback.textContent = error.message || labels.error; };
  async function loadKeys() {
    const {keys} = await call('list');
    const list = root.querySelector('[data-agent-keys]');
    list.replaceChildren();
    if (!keys.length) list.append(element('p', labels.empty));
    for (const key of keys) {
      const card = element('article', '');
      const status = key.revoked_at ? labels.revoked : key.expires_at <= Date.now() / 1000 ? labels.expired : labels.active;
      card.append(element('strong', `${key.name} · ${key.token_prefix}… · ${status}`));
      card.append(element('p', JSON.parse(key.scopes).join(', ')));
      card.append(element('p', `${labels.expires}: ${date(key.expires_at)} · ${labels.used}: ${date(key.last_used_at)}`));
      if (!key.revoked_at && key.expires_at > Date.now() / 1000) {
        const button = element('button', labels.revoke);
        button.type = 'button'; button.className = 'button thin';
        button.addEventListener('click', async () => {
          if (!window.confirm(`${key.name}\n${labels.confirm}`)) return;
          button.disabled = true;
          const data = new FormData(); data.set('id', key.id); data.set('confirm_id', key.id);
          try { await call('revoke', data); await loadKeys(); }
          catch (error) { showError(error); button.disabled = false; }
        });
        card.append(button);
      }
      list.append(card);
    }
  }
  async function loadAudit() {
    const {audit} = await call('audit');
    const list = root.querySelector('[data-agent-audit]'); list.replaceChildren();
    if (!audit.length) list.append(element('p', labels.audit_empty));
    for (const row of audit) {
      list.append(element('p', `${date(row.created_at)} · #${row.key_id} · ${row.tool} · ${row.status} · ${row.target_id ?? ''} · ${row.request_id}`));
    }
  }
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const button = form.querySelector('button[type=submit]'); button.disabled = true;
    feedback.textContent = ''; clearSecret();
    try {
      const result = await call('create', new FormData(form));
      secret.value = result.token; panel.hidden = false; feedback.textContent = labels.saved;
      await loadKeys();
    } catch (error) { showError(error); }
    finally { button.disabled = false; }
  });
  root.querySelector('[data-agent-copy]').addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(secret.value); feedback.textContent = labels.copied; }
    catch { secret.focus(); secret.select(); }
  });
  Promise.all([loadKeys(), loadAudit()]).catch(showError);
})();
