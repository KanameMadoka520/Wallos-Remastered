(function () {
  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function removeSecurityAnomaliesModal() {
    const existingModal = document.getElementById('admin-security-anomaly-backdrop');
    if (existingModal) existingModal.remove();
  }

  function renderSecurityAnomalyEntries(items, resultContainer, ui) {
    resultContainer.innerHTML = '';
    if (!Array.isArray(items) || items.length === 0) {
      const emptyState = document.createElement('div');
      emptyState.className = 'settings-notes access-log-empty';
      emptyState.innerHTML = `<p><i class="fa-solid fa-circle-info"></i>${ui.dataset.emptyLabel || 'No anomaly records yet.'}</p>`;
      resultContainer.appendChild(emptyState);
      return;
    }
    const list = document.createElement('div');
    list.className = 'access-log-list compact';
    items.forEach((item) => {
      const card = document.createElement('div');
      card.className = 'access-log-card';
      const header = document.createElement('div');
      header.className = 'access-log-header';
      header.innerHTML = `<div class="access-log-card-title"><span class="access-log-id-badge">#${escapeHtml(item.id || '-')}</span><strong>${escapeHtml(item.anomaly_type || '-')}</strong></div><span>${escapeHtml(item.anomaly_code || '-')}</span>`;
      card.appendChild(header);
      card.innerHTML += `<p>${escapeHtml(ui.dataset.messageLabel)}: ${escapeHtml(item.message || '-')}</p><p>${escapeHtml(ui.dataset.userLabel)}: ${escapeHtml(item.username || '-')}</p><p>${escapeHtml(ui.dataset.ipLabel)}: ${escapeHtml(item.ip_address || '-')}</p><p>${escapeHtml(ui.dataset.forwardedLabel)}: ${escapeHtml(item.forwarded_for || '-')}</p><p>${escapeHtml(ui.dataset.agentLabel)}: ${escapeHtml(item.user_agent || '-')}</p><p>${escapeHtml(ui.dataset.timeLabel)}: ${escapeHtml(item.created_at || '-')}</p>`;
      const headersJson = String(item.headers_json || '').trim();
      if (headersJson !== '') {
        const details = document.createElement('details');
        const summary = document.createElement('summary');
        summary.textContent = ui.dataset.headersLabel;
        const pre = document.createElement('pre');
        pre.textContent = headersJson;
        details.appendChild(summary);
        details.appendChild(pre);
        card.appendChild(details);
      }
      const detailsJson = String(item.details_json || '').trim();
      if (detailsJson !== '') {
        const details = document.createElement('details');
        const summary = document.createElement('summary');
        summary.textContent = ui.dataset.detailsLabel || 'Details';
        const pre = document.createElement('pre');
        pre.textContent = detailsJson;
        details.appendChild(summary);
        details.appendChild(pre);
        card.appendChild(details);
      }
      list.appendChild(card);
    });
    resultContainer.appendChild(list);
  }

  function fetchSecurityAnomalies(filters, resultSummary, resultContainer, searchButton, ui) {
    searchButton.disabled = true;
    resultSummary.textContent = ui.dataset.searchLabel || 'Loading...';
    window.WallosApi.postJson('endpoints/admin/securityanomalies.php', filters, {
      fallbackErrorMessage: ui.dataset.errorLabel || 'Error',
    })
      .then((data) => {
        if (!data.success) throw new Error(data.message || (ui.dataset.errorLabel || 'Error'));
        resultContainer.dataset.logs = JSON.stringify(data.items || []);
        resultContainer.dataset.filters = JSON.stringify(data.filters || filters);
        renderSecurityAnomalyEntries(data.items || [], resultContainer, ui);
        const itemCount = Array.isArray(data.items) ? data.items.length : 0;
        resultSummary.textContent = (ui.dataset.showingLabel || 'Showing %1$d of %2$d matching access logs').replace('%1$d', String(itemCount)).replace('%2$d', String(data.total || 0));
      })
      .catch((error) => { resultSummary.textContent = ui.dataset.errorLabel || 'Error'; showErrorMessage(window.WallosApi?.normalizeError?.(error, ui.dataset.errorLabel || 'Error') || ui.dataset.errorLabel || 'Error'); })
      .finally(() => { searchButton.disabled = false; });
  }

  function openSecurityAnomaliesModal(initialFilters = {}) {
    removeSecurityAnomaliesModal();
    const ui = document.getElementById('admin-security-anomaly-ui');
    if (!ui) { showErrorMessage(translate('error')); return; }
    const defaults = initialFilters && typeof initialFilters === 'object' ? initialFilters : {};
    const backdrop = document.createElement('div');
    backdrop.id = 'admin-security-anomaly-backdrop';
    backdrop.className = 'access-log-modal-backdrop';
    const modal = document.createElement('div');
    modal.className = 'access-log-modal';
    const header = document.createElement('div');
    header.className = 'access-log-modal-header';
    header.innerHTML = `<h3>${ui.dataset.title || 'Security Anomalies'}</h3><button type="button" class="secondary-button thin">${ui.dataset.closeLabel || 'Close'}</button>`;
    header.querySelector('button').addEventListener('click', removeSecurityAnomaliesModal);
    const body = document.createElement('div');
    body.className = 'access-log-modal-body';
    const filterGrid = document.createElement('div');
    filterGrid.className = 'access-log-filter-grid';
    const typeField = document.createElement('div'); typeField.className = 'form-group'; typeField.innerHTML = `<label for="securityAnomalyType">${ui.dataset.typeLabel}</label><select id="securityAnomalyType"><option value="">ALL</option><option value="rate_limit">rate_limit</option><option value="client_runtime">client_runtime</option><option value="request_failure">request_failure</option></select>`;
    const keywordField = document.createElement('div'); keywordField.className = 'form-group'; keywordField.innerHTML = `<label for="securityAnomalyKeyword">${ui.dataset.keywordLabel}</label><input type="text" id="securityAnomalyKeyword" autocomplete="off" placeholder="${ui.dataset.keywordPlaceholder || ''}" />`;
    const startField = document.createElement('div'); startField.className = 'form-group'; startField.innerHTML = `<label for="securityAnomalyStart">${ui.dataset.startLabel}</label><input type="datetime-local" id="securityAnomalyStart" />`;
    const endField = document.createElement('div'); endField.className = 'form-group'; endField.innerHTML = `<label for="securityAnomalyEnd">${ui.dataset.endLabel}</label><input type="datetime-local" id="securityAnomalyEnd" />`;
    const limitField = document.createElement('div'); limitField.className = 'form-group'; limitField.innerHTML = `<label for="securityAnomalyLimit">${ui.dataset.limitLabel}</label><select id="securityAnomalyLimit"><option value="50">50</option><option value="100" selected>100</option><option value="200">200</option><option value="300">300</option><option value="500">500</option></select>`;
    const actionField = document.createElement('div'); actionField.className = 'form-group access-log-filter-actions';
    const searchButton = document.createElement('button'); searchButton.type = 'button'; searchButton.className = 'button thin'; searchButton.textContent = ui.dataset.searchLabel || 'Search';
    const clearButton = document.createElement('button'); clearButton.type = 'button'; clearButton.className = 'warning-button thin'; clearButton.textContent = ui.dataset.clearLabel || 'Clear Logs';
    actionField.appendChild(searchButton); actionField.appendChild(clearButton);
    [typeField, keywordField, startField, endField, limitField, actionField].forEach((node) => filterGrid.appendChild(node));
    const resultSummary = document.createElement('p'); resultSummary.className = 'access-log-results-summary';
    const resultContainer = document.createElement('div');
    typeField.querySelector('select').value = String(defaults.anomaly_type || '');
    keywordField.querySelector('input').value = String(defaults.keyword || '');
    startField.querySelector('input').value = String(defaults.start_at || '');
    endField.querySelector('input').value = String(defaults.end_at || '');
    limitField.querySelector('select').value = String(defaults.limit || '100');
    const runSearch = () => {
      fetchSecurityAnomalies({ anomaly_type: document.getElementById('securityAnomalyType')?.value || '', keyword: document.getElementById('securityAnomalyKeyword')?.value || '', start_at: document.getElementById('securityAnomalyStart')?.value || '', end_at: document.getElementById('securityAnomalyEnd')?.value || '', limit: document.getElementById('securityAnomalyLimit')?.value || '100' }, resultSummary, resultContainer, searchButton, ui);
    };
    searchButton.addEventListener('click', runSearch);
    clearButton.addEventListener('click', () => {
      if (!confirm(ui.dataset.clearConfirmLabel || 'Clear all anomalies now?')) return;
      clearButton.disabled = true;
      window.WallosApi.requestJson('endpoints/admin/clearsecurityanomalies.php', {
        method: 'POST',
        fallbackErrorMessage: ui.dataset.errorLabel || 'Error',
      })
        .then((data) => { if (!data.success) throw new Error(data.message || (ui.dataset.errorLabel || 'Error')); showSuccessMessage(data.message); runSearch(); })
        .catch((error) => showErrorMessage(window.WallosApi?.normalizeError?.(error, ui.dataset.errorLabel || 'Error') || ui.dataset.errorLabel || 'Error'))
        .finally(() => { clearButton.disabled = false; });
    });
    body.appendChild(filterGrid); body.appendChild(resultSummary); body.appendChild(resultContainer);
    modal.appendChild(header); modal.appendChild(body); backdrop.appendChild(modal);
    backdrop.addEventListener('click', (event) => { if (event.target === backdrop) removeSecurityAnomaliesModal(); });
    document.body.appendChild(backdrop); runSearch();
  }

  function removeAccessLogsModal() {
    const existingModal = document.getElementById('admin-access-log-backdrop');
    if (existingModal) existingModal.remove();
  }

  function renderAccessLogEntries(logs, resultContainer, ui) {
    resultContainer.innerHTML = '';
    if (!Array.isArray(logs) || logs.length === 0) {
      const emptyState = document.createElement('div');
      emptyState.className = 'settings-notes access-log-empty';
      emptyState.innerHTML = `<p><i class="fa-solid fa-circle-info"></i>${ui.dataset.emptyLabel || 'No access logs are available yet.'}</p>`;
      resultContainer.appendChild(emptyState);
      return;
    }
    const list = document.createElement('div');
    list.className = 'access-log-list compact';
    logs.forEach((log) => {
      const card = document.createElement('div');
      card.className = 'access-log-card';
      const header = document.createElement('div');
      header.className = 'access-log-header';
      header.innerHTML = `<div class="access-log-card-title"><span class="access-log-id-badge">#${escapeHtml(log.id || '-')}</span><strong>${escapeHtml(log.method || '-')}</strong></div><span>${escapeHtml(log.path || '-')}</span>`;
      const headerJson = String(log.headers_json || '').trim();
      card.appendChild(header);
      card.innerHTML += `<p>${escapeHtml(ui.dataset.userLabel)}: ${escapeHtml(log.username || '-')}</p><p>${escapeHtml(ui.dataset.ipLabel)}: ${escapeHtml(log.ip_address || '-')}</p><p>${escapeHtml(ui.dataset.forwardedLabel)}: ${escapeHtml(log.forwarded_for || '-')}</p><p>${escapeHtml(ui.dataset.agentLabel)}: ${escapeHtml(log.user_agent || '-')}</p><p>${escapeHtml(ui.dataset.timeLabel)}: ${escapeHtml(log.created_at || '-')}</p>`;
      if (headerJson !== '') {
        const details = document.createElement('details');
        const summary = document.createElement('summary');
        summary.textContent = ui.dataset.headersLabel;
        const pre = document.createElement('pre');
        pre.textContent = headerJson;
        details.appendChild(summary); details.appendChild(pre); card.appendChild(details);
      }
      list.appendChild(card);
    });
    resultContainer.appendChild(list);
  }

  function exportAdminAccessLogs(logs, filters, ui) {
    const rows = [];
    rows.push([ui.dataset.exportRuleLabel || 'Filter', 'Value']);
    rows.push([ui.dataset.requestIdLabel, String(filters.request_id || '')]);
    rows.push([ui.dataset.keywordLabel, String(filters.keyword || '')]);
    rows.push([ui.dataset.methodLabel, String(filters.method || '')]);
    rows.push([ui.dataset.startLabel, String(filters.start_at || '')]);
    rows.push([ui.dataset.endLabel, String(filters.end_at || '')]);
    rows.push([ui.dataset.limitLabel, String(filters.limit || '')]);
    rows.push([]);
    rows.push([ui.dataset.idLabel || 'ID', ui.dataset.methodLabel || 'Method', 'Path', ui.dataset.userLabel || 'Username', ui.dataset.ipLabel || 'IP', ui.dataset.forwardedLabel || 'Forwarded For', ui.dataset.agentLabel || 'User Agent', ui.dataset.timeLabel || 'Time', ui.dataset.headersLabel || 'Headers']);
    (logs || []).forEach((log) => rows.push([String(log.id || ''), String(log.method || ''), String(log.path || ''), String(log.username || ''), String(log.ip_address || ''), String(log.forwarded_for || ''), String(log.user_agent || ''), String(log.created_at || ''), String(log.headers_json || '')]));
    const csvContent = rows.map((row) => row.map((value) => `"${String(value).replace(/"/g, '""')}"`).join(',')).join('\r\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url; link.download = `wallos-access-logs-${Date.now()}.csv`;
    document.body.appendChild(link); link.click(); document.body.removeChild(link); URL.revokeObjectURL(url);
  }

  function fetchAdminAccessLogs(filters, resultSummary, resultContainer, searchButton, ui) {
    searchButton.disabled = true;
    resultSummary.textContent = ui.dataset.searchLabel || 'Loading...';
    window.WallosApi.postJson('endpoints/admin/accesslogs.php', filters, {
      fallbackErrorMessage: ui.dataset.errorLabel || 'Error',
    })
      .then((data) => {
        if (!data.success) throw new Error(data.message || (ui.dataset.errorLabel || 'Error'));
        resultContainer.dataset.logs = JSON.stringify(data.logs || []);
        resultContainer.dataset.filters = JSON.stringify(data.filters || filters);
        renderAccessLogEntries(data.logs || [], resultContainer, ui);
        const logCount = Array.isArray(data.logs) ? data.logs.length : 0;
        resultSummary.textContent = (ui.dataset.showingLabel || 'Showing %1$d of %2$d matching access logs').replace('%1$d', String(logCount)).replace('%2$d', String(data.total || 0));
      })
      .catch((error) => { resultSummary.textContent = ui.dataset.errorLabel || 'Error'; showErrorMessage(window.WallosApi?.normalizeError?.(error, ui.dataset.errorLabel || 'Error') || ui.dataset.errorLabel || 'Error'); })
      .finally(() => { searchButton.disabled = false; });
  }

  function openAccessLogsModal() {
    removeAccessLogsModal();
    const ui = document.getElementById('admin-access-log-ui');
    if (!ui) { showErrorMessage(translate('error')); return; }
    const backdrop = document.createElement('div'); backdrop.id = 'admin-access-log-backdrop'; backdrop.className = 'access-log-modal-backdrop';
    const modal = document.createElement('div'); modal.className = 'access-log-modal';
    const header = document.createElement('div'); header.className = 'access-log-modal-header';
    header.innerHTML = `<h3>${ui.dataset.title || 'Access Logs'}</h3><button type="button" class="secondary-button thin">${ui.dataset.closeLabel || 'Close'}</button>`;
    header.querySelector('button').addEventListener('click', removeAccessLogsModal);
    const body = document.createElement('div'); body.className = 'access-log-modal-body';
    const filterGrid = document.createElement('div'); filterGrid.className = 'access-log-filter-grid';
    const requestIdField = document.createElement('div'); requestIdField.className = 'form-group'; requestIdField.innerHTML = `<label for="accessLogRequestId">${ui.dataset.requestIdLabel}</label><input type="number" id="accessLogRequestId" min="0" autocomplete="off" />`;
    const keywordField = document.createElement('div'); keywordField.className = 'form-group'; keywordField.innerHTML = `<label for="accessLogKeyword">${ui.dataset.keywordLabel}</label><input type="text" id="accessLogKeyword" autocomplete="off" placeholder="${ui.dataset.keywordPlaceholder || ''}" />`;
    const methodField = document.createElement('div'); methodField.className = 'form-group'; methodField.innerHTML = `<label for="accessLogMethod">${ui.dataset.methodLabel}</label><select id="accessLogMethod"><option value="">ALL</option><option value="GET">GET</option><option value="POST">POST</option><option value="PUT">PUT</option><option value="PATCH">PATCH</option><option value="DELETE">DELETE</option></select>`;
    const limitField = document.createElement('div'); limitField.className = 'form-group'; limitField.innerHTML = `<label for="accessLogLimit">${ui.dataset.limitLabel}</label><select id="accessLogLimit"><option value="50">50</option><option value="100" selected>100</option><option value="200">200</option><option value="300">300</option><option value="500">500</option></select>`;
    const actionField = document.createElement('div'); actionField.className = 'form-group access-log-filter-actions';
    const searchButton = document.createElement('button'); searchButton.type = 'button'; searchButton.className = 'button thin'; searchButton.textContent = ui.dataset.searchLabel || 'Search';
    const exportButton = document.createElement('button'); exportButton.type = 'button'; exportButton.className = 'secondary-button thin'; exportButton.textContent = ui.dataset.exportLabel || 'Export Logs';
    const clearButton = document.createElement('button'); clearButton.type = 'button'; clearButton.className = 'warning-button thin'; clearButton.textContent = ui.dataset.clearLabel || 'Clear Logs';
    actionField.appendChild(searchButton); actionField.appendChild(exportButton); actionField.appendChild(clearButton);
    const startField = document.createElement('div'); startField.className = 'form-group'; startField.innerHTML = `<label for="accessLogStart">${ui.dataset.startLabel}</label><input type="datetime-local" id="accessLogStart" />`;
    const endField = document.createElement('div'); endField.className = 'form-group'; endField.innerHTML = `<label for="accessLogEnd">${ui.dataset.endLabel}</label><input type="datetime-local" id="accessLogEnd" />`;
    [requestIdField, keywordField, methodField, startField, endField, limitField, actionField].forEach((node) => filterGrid.appendChild(node));
    const resultSummary = document.createElement('p'); resultSummary.className = 'access-log-results-summary'; resultSummary.textContent = ui.dataset.emptyLabel || '';
    const resultContainer = document.createElement('div');
    const runSearch = () => {
      fetchAdminAccessLogs({ request_id: document.getElementById('accessLogRequestId')?.value || '', keyword: document.getElementById('accessLogKeyword')?.value || '', method: document.getElementById('accessLogMethod')?.value || '', start_at: document.getElementById('accessLogStart')?.value || '', end_at: document.getElementById('accessLogEnd')?.value || '', limit: document.getElementById('accessLogLimit')?.value || '100' }, resultSummary, resultContainer, searchButton, ui);
    };
    searchButton.addEventListener('click', runSearch);
    exportButton.addEventListener('click', () => {
      const logs = JSON.parse(resultContainer.dataset.logs || '[]');
      const filters = JSON.parse(resultContainer.dataset.filters || '{}');
      exportAdminAccessLogs(logs, filters, ui);
    });
    clearButton.addEventListener('click', () => {
      if (!confirm(ui.dataset.clearConfirmLabel || 'Clear all access logs now?')) return;
      clearButton.disabled = true;
      window.WallosApi.requestJson('endpoints/admin/clearaccesslogs.php', {
        method: 'POST',
        fallbackErrorMessage: ui.dataset.errorLabel || 'Error',
      })
        .then((data) => { if (!data.success) throw new Error(data.message || (ui.dataset.errorLabel || 'Error')); showSuccessMessage(data.message); runSearch(); })
        .catch((error) => showErrorMessage(window.WallosApi?.normalizeError?.(error, ui.dataset.errorLabel || 'Error') || ui.dataset.errorLabel || 'Error'))
        .finally(() => { clearButton.disabled = false; });
    });
    body.appendChild(filterGrid); body.appendChild(resultSummary); body.appendChild(resultContainer);
    modal.appendChild(header); modal.appendChild(body); backdrop.appendChild(modal);
    backdrop.addEventListener('click', (event) => { if (event.target === backdrop) removeAccessLogsModal(); });
    document.body.appendChild(backdrop); runSearch();
  }

  window.WallosAdminAccessLogs = { removeSecurityAnomaliesModal, openSecurityAnomaliesModal, removeAccessLogsModal, openAccessLogsModal, exportAdminAccessLogs };
})();
