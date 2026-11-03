const STATIC_CACHE = 'static-cache-v22';
const LOGOS_CACHE = 'logos-cache-v22';

// Keep the retired pages-cache prefix only so upgrades can delete legacy private-page caches.
const WALLOS_CACHE_PREFIXES = ['static-cache-', 'pages-cache-', 'logos-cache-'];

// Installation stays intentionally small. Page-specific assets are cached exactly when used.
const PRECACHE_ASSETS = [
    'images/icon/favicon.ico',
    'images/icon/android-chrome-192x192.png',
    'scripts/i18n/en.js',
];

const STATIC_PATH_PREFIXES = [
    'styles/',
    'scripts/',
    'webfonts/',
    'images/icon/',
    'images/siteicons/',
    'images/siteimages/',
    'images/avatars/',
    'images/screenshots/',
    'images/uploads/icons/',
];

function normalizePathname(pathname) {
    return String(pathname || '').replace(/^\/+/, '');
}

function isStaticAssetPath(pathname) {
    const normalizedPath = normalizePathname(pathname);

    return STATIC_PATH_PREFIXES.some(prefix => (
        normalizedPath.startsWith(prefix) || normalizedPath.includes('/' + prefix)
    ));
}

function pathMatchesPrefix(pathname, prefix) {
    const normalizedPath = normalizePathname(pathname);
    return normalizedPath.startsWith(prefix) || normalizedPath.includes('/' + prefix);
}

function hasExactAssetFingerprint(url) {
    return /^v=[A-Za-z0-9._-]+$/.test(url.search.slice(1));
}

function cacheOkResponse(cacheName, cacheKey, response) {
    if (!response || !response.ok) {
        return;
    }

    caches.open(cacheName).then(cache => {
        cache.put(cacheKey, response.clone()).catch(() => {});
    }).catch(() => {});
}

function fetchWithoutStore(request) {
    return fetch(request, { cache: 'no-store' });
}

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(STATIC_CACHE).then(function (cache) {
            return Promise.allSettled(
                PRECACHE_ASSETS.map(url =>
                    fetch(url).then(response => {
                        if (response.ok) {
                            return cache.put(url, response);
                        }
                        return null;
                    }).catch(() => null)
                )
            );
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    const validCaches = [STATIC_CACHE, LOGOS_CACHE];
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys
                    .filter(key => WALLOS_CACHE_PREFIXES.some(prefix => key.startsWith(prefix)))
                    .filter(key => !validCaches.includes(key))
                    .map(key => caches.delete(key))
            )
        )
    );
    self.clients.claim();
});

self.addEventListener('message', function (event) {
    if (event.data && event.data.type === 'WALLOS_CLEAR_CACHES') {
        const replyPort = event.ports && event.ports[0] ? event.ports[0] : null;
        caches.keys()
            .then(keys => Promise.all(
                keys
                    .filter(key => WALLOS_CACHE_PREFIXES.some(prefix => key.startsWith(prefix)))
                    .map(key => caches.delete(key))
            ))
            .then(() => {
                if (replyPort) {
                    replyPort.postMessage({ success: true });
                }
            })
            .catch(() => {
                if (replyPort) {
                    replyPort.postMessage({ success: false });
                }
            });
    }

    if (event.data && event.data.type === 'WALLOS_CACHE_STATUS') {
        const replyPort = event.ports && event.ports[0] ? event.ports[0] : null;
        if (!replyPort) {
            return;
        }

        caches.keys()
            .then(keys => {
                const wallosCaches = keys.filter(key => WALLOS_CACHE_PREFIXES.some(prefix => key.startsWith(prefix)));
                replyPort.postMessage({
                    success: true,
                    currentCaches: {
                        static: STATIC_CACHE,
                        logos: LOGOS_CACHE,
                    },
                    wallosCacheNames: wallosCaches,
                    wallosCacheCount: wallosCaches.length,
                });
            })
            .catch(() => {
                replyPort.postMessage({ success: false });
            });
    }
});

self.addEventListener('fetch', function (event) {
    const request = event.request;
    const url = new URL(request.url);
    const isSameOrigin = url.origin === self.location.origin;
    const isEndpointRequest = isSameOrigin && (url.pathname.includes('/endpoints/') || url.pathname.includes('/api/'));
    const isPhpRequest = isSameOrigin && /\.php(?:\/|$)/i.test(url.pathname);
    const isSubscriptionMediaRequest = isSameOrigin && pathMatchesPrefix(url.pathname, 'images/uploads/logos/subscription-media/');
    const isLogoUploadRequest = isSameOrigin && pathMatchesPrefix(url.pathname, 'images/uploads/logos/');
    const isStaticAssetRequest = isSameOrigin && isStaticAssetPath(url.pathname);
    const isDocumentRequest = request.mode === 'navigate' || request.destination === 'document';

    if (request.method !== 'GET') {
        return;
    }

    // Documents, PHP/API traffic and access-controlled media always go to the server.
    // This prevents stale CSRF tokens and cross-account private content in CacheStorage
    // or in the browser's regular HTTP cache.
    if (isDocumentRequest || isEndpointRequest || isPhpRequest || isSubscriptionMediaRequest) {
        event.respondWith(fetchWithoutStore(request));
        return;
    }

    // Ordinary uploaded service logos are public presentation assets. Private
    // subscription media was excluded above and is never cached here.
    if (isLogoUploadRequest) {
        event.respondWith(
            caches.match(request).then(response => {
                return response || fetch(request).then(networkResponse => {
                    cacheOkResponse(LOGOS_CACHE, request, networkResponse);
                    return networkResponse;
                });
            })
        );
        return;
    }

    // Fingerprinted packaged assets are immutable: use the exact request as the key.
    // Never ignore or strip the query string, otherwise a new version could receive
    // an older script or stylesheet.
    if (isStaticAssetRequest && hasExactAssetFingerprint(url)) {
        event.respondWith(
            caches.match(request).then(response => {
                return response || fetch(request).then(networkResponse => {
                    cacheOkResponse(STATIC_CACHE, request, networkResponse);
                    return networkResponse;
                });
            })
        );
        return;
    }

    // Unversioned or unexpectedly queried assets revalidate online. Offline fallback
    // remains exact-key only and therefore cannot blur two versions together.
    if (isStaticAssetRequest) {
        event.respondWith(
            fetch(request).then(networkResponse => {
                cacheOkResponse(STATIC_CACHE, request, networkResponse);
                return networkResponse;
            }).catch(() => caches.match(request))
        );
        return;
    }

    event.respondWith(fetch(request));
});

// Web Push adds events only; private pages/endpoints retain the no-store fetch
// rules above. Targets are fixed within this registration, never payload URLs.
self.addEventListener('push', function (event) {
    let payload = {};
    try { payload = event.data ? event.data.json() : {}; } catch (_) { /* generic reminder */ }
    const body = typeof payload.body === 'string' ? payload.body.slice(0, 500) : 'Wallos: a subscription reminder is available.';
    const target = new URL('index.php', self.registration.scope).href;
    const icon = new URL('images/icon/android-chrome-192x192.png', self.registration.scope).href;
    const tag = typeof payload.tag === 'string' && /^wallos-[a-z0-9-]{1,100}$/.test(payload.tag) ? payload.tag : 'wallos-reminder';
    event.waitUntil(self.registration.showNotification('Wallos', {
        body, icon, badge: icon, tag, data: { url: target },
    }));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    const target = new URL('index.php', self.registration.scope);
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async function (windows) {
        // Opening the authenticated entrypoint lets the server check the current
        // identity. Never navigate to a subscription link from an old account.
        for (const client of windows) {
            const url = new URL(client.url);
            if (url.origin === target.origin && url.pathname.startsWith(new URL(self.registration.scope).pathname)) {
                await client.navigate(target.href);
                return client.focus();
            }
        }
        return self.clients.openWindow(target.href);
    }));
});
