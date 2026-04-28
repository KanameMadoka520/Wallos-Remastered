if ('serviceWorker' in navigator) {
  let wallosServiceWorkerUpdateCheckedAt = 0;

  function wallosResolveServiceWorkerUrl() {
    return window.WallosServiceWorkerUrl || 'service-worker.js';
  }

  function wallosCheckServiceWorkerUpdate(registration) {
    if (!registration || typeof registration.update !== 'function') {
      return;
    }

    const now = Date.now();
    if ((now - wallosServiceWorkerUpdateCheckedAt) < 10 * 60 * 1000) {
      return;
    }

    wallosServiceWorkerUpdateCheckedAt = now;
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
