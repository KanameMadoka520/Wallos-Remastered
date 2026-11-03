(function (window, document) {
  'use strict';

  const text = (key, fallback) => {
    const translated = typeof window.translate === 'function' ? window.translate(key) : '';
    return translated && translated !== '[Translation Missing]' ? translated : fallback;
  };
  const safeImageUrl = (value, catalog) => {
    try {
      const url = new URL(value);
      if (!['https:', 'http:'].includes(url.protocol) || url.username || url.password) return null;
      if (catalog && (url.protocol !== 'https:' || url.host !== 'raw.githubusercontent.com'
        || !/^\/(selfhst\/icons|homarr-labs\/dashboard-icons)\/main\/png\/[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}\.png$/.test(url.pathname)
        || url.search || url.hash || url.pathname.includes('..'))) return null;
      return url.href;
    } catch (_) {
      return null;
    }
  };

  function install(config) {
    const popup = document.getElementById(config.popup);
    const images = document.getElementById(config.images);
    const nameInput = document.getElementById(config.name);
    if (!popup || !images || !nameInput || typeof window[config.open] !== 'function') return;
    const state = { source: 'web', renderedSource: '', renderedQuery: '', page: 1, request: 0, hasMore: false };
    const privacyEnabled = () => window.WallosScreenshotPrivacy?.isEnabled?.() === true
      || document.documentElement.classList.contains('wallos-screenshot-privacy-enabled');
    popup.classList.add('logo-catalog-popup');
    const toolbar = document.createElement('div');
    toolbar.className = 'logo-catalog-toolbar';
    const label = document.createElement('label');
    label.textContent = text('logo_source', 'Icon source');
    const source = document.createElement('select');
    source.dataset.logoCatalogSource = config.kind;
    const providers = [
      ['web', text('logo_source_web', 'Web search (DuckDuckGo / Brave)')],
      ['selfhst', 'selfh.st'],
      ['dashboardicons', 'Dashboard Icons'],
    ];
    providers.forEach(([value, name]) => source.add(new Option(name, value)));
    label.appendChild(source);
    const queryLabel = document.createElement('label');
    queryLabel.textContent = text('logo_catalog_query', 'Icon name');
    const query = document.createElement('input');
    query.type = 'search';
    query.maxLength = 128;
    query.autocomplete = 'off';
    query.dataset.logoCatalogQuery = config.kind;
    queryLabel.appendChild(query);
    const search = document.createElement('button');
    search.type = 'button';
    search.className = 'button thin';
    search.textContent = text('logo_catalog_search', 'Search icons');
    toolbar.append(label, queryLabel, search);
    const status = document.createElement('p');
    status.className = 'logo-catalog-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    popup.insertBefore(toolbar, images);
    popup.insertBefore(status, images);
    const pagination = document.createElement('div');
    pagination.className = 'logo-catalog-pagination';
    const previous = document.createElement('button');
    previous.type = 'button';
    previous.textContent = text('logo_catalog_previous', 'Previous');
    const pageLabel = document.createElement('span');
    const next = document.createElement('button');
    next.type = 'button';
    next.textContent = text('logo_catalog_next', 'Next');
    pagination.append(previous, pageLabel, next);
    popup.appendChild(pagination);
    images.classList.add('logo-catalog-images');

    function fitPopup() {
      popup.style.transform = '';
      const bounds = popup.getBoundingClientRect();
      const shift = bounds.left < 12 ? 12 - bounds.left
        : bounds.right > window.innerWidth - 12 ? window.innerWidth - 12 - bounds.right : 0;
      if (shift) popup.style.transform = `translateX(${shift}px)`;
    }
    function updatePagination() {
      pagination.hidden = state.source === 'web';
      const current = state.renderedSource === state.source && state.renderedQuery === query.value.trim();
      previous.disabled = !current || state.page <= 1;
      next.disabled = !current || !state.hasMore;
      pageLabel.textContent = current ? String(state.page) : '';
    }
    function render(results, catalog) {
      const fragment = document.createDocumentFragment();
      results.slice(0, 48).forEach(result => {
        const previewUrl = safeImageUrl(result.thumbnail || result.image, catalog);
        const imageUrl = safeImageUrl(result.image || result.thumbnail, catalog);
        if (!previewUrl || !imageUrl) return;
        const card = document.createElement('button');
        card.type = 'button';
        card.className = 'logo-catalog-result';
        const image = document.createElement('img');
        image.src = previewUrl;
        image.loading = 'lazy';
        image.referrerPolicy = 'no-referrer';
        image.alt = String(result.name || query.value);
        image.addEventListener('error', () => card.remove(), { once: true });
        const caption = document.createElement('span');
        caption.className = 'logo-catalog-caption';
        // Directory labels remain data, even if an upstream name contains markup.
        caption.textContent = String(result.name || query.value);
        const attribution = document.createElement('small');
        attribution.textContent = providers.find(provider => provider[0] === state.source)?.[1] || '';
        card.append(image, caption, attribution);
        card.addEventListener('click', () => window[config.select](imageUrl));
        fragment.appendChild(card);
      });
      images.replaceChildren(fragment);
    }
    async function run(page = 1) {
      if (privacyEnabled()) {
        window[config.close]();
        query.value = '';
        window.showErrorMessage?.(text('screenshot_privacy_mode_blocked', 'Disable screenshot privacy mode before searching icons.'));
        return;
      }
      const term = query.value.trim();
      if (!term) { query.focus(); return; }
      const requestId = ++state.request;
      const provider = source.value;
      state.source = provider;
      search.disabled = true;
      previous.disabled = true;
      next.disabled = true;
      status.textContent = text('logo_catalog_loading', 'Searching icons…');
      try {
        let data;
        if (provider === 'web') {
          data = await window.WallosApi.getJson(`${config.web}?search=${encodeURIComponent(term)}`, {
            includeCsrf: false,
            fallbackErrorMessage: text('error_fetching_image_results', 'Unable to fetch images'),
          });
        } else {
          data = await window.WallosApi.postForm('endpoints/logos/icon_search.php', {
            source: provider, search: term, page, limit: 24,
          }, { fallbackErrorMessage: text('logo_catalog_failed', 'Icon catalog unavailable') });
        }
        if (requestId !== state.request || !popup.classList.contains('is-open') || privacyEnabled()) return;
        if (!data || data.success === false || data.error) throw new Error('Icon search unavailable');
        const results = Array.isArray(data.results) ? data.results
          : Array.isArray(data.imageUrls) ? data.imageUrls.map(url => ({ image: url })) : [];
        render(results, provider !== 'web');
        state.page = page;
        state.renderedSource = provider;
        state.renderedQuery = term;
        state.hasMore = data.has_more === true;
        status.textContent = results.length === 0 ? text('logo_catalog_empty', 'No matching icons')
          : data.stale ? text('logo_catalog_stale', 'Using a recently cached public icon catalog') : '';
      } catch (error) {
        if (requestId !== state.request) return;
        // Preserve previous results and keep the web provider available after an outage.
        status.textContent = text('logo_catalog_failed', 'Catalog unavailable. Retry or select web search.');
        if (window.WallosApi?.isSessionFailureError?.(error) || window.WallosApi?.isCsrfFailureError?.(error)) {
          window.showErrorMessage?.(window.WallosApi.normalizeError(error, status.textContent));
        }
      } finally {
        if (requestId === state.request) {
          search.disabled = false;
          updatePagination();
        }
      }
    }
    window[config.open] = function () {
      if (privacyEnabled()) {
        window[config.close]();
        query.value = '';
        window.showErrorMessage?.(text('screenshot_privacy_mode_blocked', 'Disable screenshot privacy mode before searching icons.'));
        return;
      }
      if (!nameInput.value.trim()) { nameInput.focus(); return; }
      query.value = nameInput.value.trim().slice(0, 128);
      popup.classList.add('is-open');
      fitPopup();
      run(1);
    };
    const legacyClose = window[config.close];
    window[config.close] = function () {
      ++state.request;
      search.disabled = false;
      status.textContent = '';
      legacyClose();
    };
    window.addEventListener('wallos:screenshot-privacy-change', () => {
      if (privacyEnabled()) {
        window[config.close]();
        query.value = '';
      }
    });
    query.addEventListener('input', updatePagination);
    search.addEventListener('click', () => run(1));
    query.addEventListener('keydown', event => {
      if (event.key === 'Enter') { event.preventDefault(); run(1); }
    });
    source.addEventListener('change', () => {
      state.source = source.value;
      updatePagination();
      run(1);
    });
    window.addEventListener('resize', () => {
      if (popup.classList.contains('is-open')) fitPopup();
    });
    previous.addEventListener('click', () => run(state.page - 1));
    next.addEventListener('click', () => run(state.page + 1));
    updatePagination();
  }

  install({ kind: 'subscription', popup: 'logo-search-results', images: 'logo-search-images', name: 'name',
    open: 'searchLogo', close: 'closeLogoSearch', select: 'selectWebLogo', web: 'endpoints/logos/search.php' });
  install({ kind: 'payment', popup: 'icon-search-results', images: 'icon-search-images', name: 'paymentname',
    open: 'searchPaymentIcon', close: 'closeIconSearch', select: 'selectWebIcon', web: 'endpoints/payments/search.php' });
})(window, document);
