if ('serviceWorker' in navigator) {
  let wallosServiceWorkerUpdateCheckedAt = 0;
  const wallosServiceWorkerUpdateStorageKey = 'wallos-service-worker-update-checked-at';

  function wallosResolveServiceWorkerUrl() {
    return window.WallosServiceWorkerUrl || 'service-worker.js';
  }

  function wallosCheckServiceWorkerUpdate(registration) {
    if (!registration || typeof registration.update !== 'function') {
      return;
    }

    const now = Date.now();
    let previousCheck = wallosServiceWorkerUpdateCheckedAt;
    try {
      previousCheck = Math.max(previousCheck, Number.parseInt(
        window.sessionStorage.getItem(wallosServiceWorkerUpdateStorageKey) || '0',
        10
      ) || 0);
    } catch (error) {
      // In-memory throttling still works if sessionStorage is unavailable.
    }

    if ((now - previousCheck) < 10 * 60 * 1000) {
      return;
    }

    wallosServiceWorkerUpdateCheckedAt = now;
    try {
      window.sessionStorage.setItem(wallosServiceWorkerUpdateStorageKey, String(now));
    } catch (error) {
      // The update check itself remains best-effort.
    }
    registration.update().catch(function () {
      // Update checks are best-effort. Runtime observability covers hard failures elsewhere.
    });
  }

  navigator.serviceWorker.addEventListener('controllerchange', function () {
    window.WallosServiceWorkerControllerChanged = true;
  });

  window.addEventListener('load', function() {
    navigator.serviceWorker.register(wallosResolveServiceWorkerUrl()).then(function(registration) {
      window.WallosServiceWorkerRegistration = registration;
      wallosCheckServiceWorkerUpdate(registration);
    }, function(err) {
      console.log('ServiceWorker registration failed: ', err);
    });
  });

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState !== 'visible') {
      return;
    }

    navigator.serviceWorker.getRegistration().then(wallosCheckServiceWorkerUpdate).catch(function () {});
  });
}
