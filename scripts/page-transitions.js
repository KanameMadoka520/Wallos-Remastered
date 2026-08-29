(function () {
  const html = document.documentElement;
  const transitionOverlayId = "wallos-page-transition";
  const transitionTitleId = "wallos-page-transition-title";
  const contextStorageKey = "wallos-page-transition-context";
  const enabledClass = "wallos-page-transition-enabled";
  const supportedStyles = new Set(["shutter", "bluearchive", "bluearchive_theme"]);
  const fallbackScene = "generic";
  const configuredSceneRoutes = Object.freeze({
    ...(window.WallosPageTransitionSceneRoutes || {}),
  });
  const supportedScenes = new Set([...Object.values(configuredSceneRoutes), fallbackScene]);
  // Give the overlay three frames to become visible, then start the real navigation.
  // The incoming page continues the animation, so holding the network for 520ms only
  // made every click feel slow without improving the effect.
  const leaveDurationMs = 48;
  const loadingClass = "wallos-page-transition-loading";
  const leavingClass = "wallos-page-transition-leaving";
  const revealedClass = "wallos-page-transition-revealed";
  const initialClass = "wallos-page-transition-initial";
  const resumeClass = "wallos-page-transition-resume";
  let revealScheduled = false;
  let leaveInProgress = false;
  let pageTransitionsInitialized = false;

  function normalizeTransitionRoute(urlValue) {
    let url;
    try {
      url = new URL(String(urlValue || ""), window.location.href);
    } catch (error) {
      return "";
    }

    const normalizedPath = url.pathname.replace(/\/+$/, "");
    const route = normalizedPath.slice(normalizedPath.lastIndexOf("/") + 1).toLowerCase();
    return route || "index.php";
  }

  function normalizeTransitionScene(scene) {
    const normalizedScene = String(scene || "");
    return supportedScenes.has(normalizedScene) ? normalizedScene : fallbackScene;
  }

  function resolveTransitionScene(urlValue) {
    const route = normalizeTransitionRoute(urlValue);
    return normalizeTransitionScene(configuredSceneRoutes[route] || fallbackScene);
  }

  const currentRoute = normalizeTransitionRoute(window.pageTransitionCurrentRoute || window.location.href);
  const currentScene = normalizeTransitionScene(
    window.pageTransitionCurrentScene || configuredSceneRoutes[currentRoute] || fallbackScene,
  );

  function hasOverlay() {
    return !!document.getElementById(transitionOverlayId);
  }

  function normalizeTransitionStyle(style) {
    return supportedStyles.has(style) ? style : "shutter";
  }

  function applyTransitionStyle(style) {
    const resolvedStyle = normalizeTransitionStyle(style);
    html.dataset.pageTransitionStyle = resolvedStyle;
    window.pageTransitionStyle = resolvedStyle;
    return resolvedStyle;
  }

  function applyTransitionScene(scene) {
    const resolvedScene = normalizeTransitionScene(scene);
    html.dataset.pageTransitionScene = resolvedScene;
    window.pageTransitionScene = resolvedScene;
    return resolvedScene;
  }

  function isInternalNavigationLink(anchor) {
    if (!anchor || anchor.target === "_blank" || anchor.hasAttribute("download")) {
      return false;
    }

    const href = anchor.getAttribute("href") || "";
    if (!href || href.startsWith("#") || href.startsWith("javascript:") || href.startsWith("mailto:") || href.startsWith("tel:")) {
      return false;
    }

    let url;
    try {
      url = new URL(anchor.href, window.location.href);
    } catch (error) {
      return false;
    }

    if (url.origin !== window.location.origin) {
      return false;
    }

    if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash !== window.location.hash) {
      return false;
    }

    return true;
  }

  function scheduleReveal() {
    if (revealScheduled || !hasOverlay()) {
      return;
    }

    revealScheduled = true;

    window.requestAnimationFrame(() => {
      window.requestAnimationFrame(() => {
        html.classList.add(revealedClass);
        window.setTimeout(() => {
          html.classList.remove(loadingClass);
        }, 240);
      });
    });
  }

  function updateTransitionTitle(label) {
    const title = document.getElementById(transitionTitleId);
    const normalizedLabel = String(label || "").replace(/\s+/g, " ").trim();
    if (!title || normalizedLabel === "") {
      return;
    }

    title.textContent = normalizedLabel;
  }

  function persistTransitionContext(target) {
    try {
      window.sessionStorage.setItem(contextStorageKey, JSON.stringify({
        active: true,
        route: target.route,
        scene: target.scene,
        label: String(target.label || "").replace(/\s+/g, " ").trim(),
        timestamp: Date.now(),
      }));
    } catch (error) {
      // Ignore sessionStorage persistence failures.
    }
  }

  function consumeTransitionContext() {
    const transitionContext = window.__wallosPageTransitionContext || null;

    try {
      window.sessionStorage.removeItem(contextStorageKey);
    } catch (error) {
      // Ignore sessionStorage cleanup failures.
    }

    window.__wallosPageTransitionContext = null;
    if (!transitionContext
      || String(transitionContext.route || "") !== currentRoute
      || String(transitionContext.scene || "") !== currentScene) {
      return null;
    }

    return transitionContext;
  }

  function startLeaveTransition(onComplete, target) {
    if (leaveInProgress) {
      return false;
    }

    if (!hasOverlay() || !window.pageTransitionEnabled) {
      if (typeof onComplete === "function") {
        onComplete();
      }
      return true;
    }

    leaveInProgress = true;
    persistTransitionContext(target);
    applyTransitionScene(target.scene);
    updateTransitionTitle(target.label);
    html.classList.remove(revealedClass);
    html.classList.add(loadingClass, leavingClass);

    window.setTimeout(() => {
      if (typeof onComplete === "function") {
        onComplete();
      }
    }, leaveDurationMs);

    return true;
  }

  function restoreCurrentTransitionIdentity() {
    applyTransitionScene(currentScene);
    updateTransitionTitle(window.pageTransitionTitle || "");
  }

  function replayRevealForBfcacheRestore() {
    revealScheduled = false;
    leaveInProgress = false;
    restoreCurrentTransitionIdentity();

    if (!window.pageTransitionEnabled) {
      html.classList.remove(enabledClass, loadingClass, leavingClass, revealedClass, initialClass, resumeClass);
      return;
    }

    html.classList.remove(leavingClass, revealedClass, initialClass, resumeClass);
    html.classList.add(enabledClass, loadingClass, resumeClass);
    scheduleReveal();
  }

  function initializePageTransitions(animateOnInit = true) {
    if (pageTransitionsInitialized || !hasOverlay() || !window.pageTransitionEnabled) {
      return;
    }

    pageTransitionsInitialized = true;
    html.classList.add(enabledClass);
    applyTransitionStyle(window.pageTransitionStyle || "shutter");

    if (animateOnInit) {
      html.classList.add(loadingClass);
      const transitionContext = html.classList.contains(resumeClass) ? consumeTransitionContext() : null;
      applyTransitionScene(transitionContext?.scene || currentScene);
      updateTransitionTitle(transitionContext?.label || window.pageTransitionTitle || "");
      scheduleReveal();
    } else {
      restoreCurrentTransitionIdentity();
      html.classList.remove(loadingClass, leavingClass, revealedClass, initialClass, resumeClass);
    }

    document.addEventListener("click", (event) => {
      if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
      }

      const anchor = event.target.closest("a");
      if (!isInternalNavigationLink(anchor)) {
        return;
      }

      event.preventDefault();
      if (leaveInProgress) {
        return;
      }

      const nextUrl = anchor.href;
      const nextLabel = anchor.dataset.transitionLabel || anchor.textContent || anchor.getAttribute("title") || "";
      const nextRoute = normalizeTransitionRoute(nextUrl);
      const nextScene = resolveTransitionScene(nextUrl);
      startLeaveTransition(() => {
        window.location.href = nextUrl;
      }, {
        route: nextRoute,
        scene: nextScene,
        label: nextLabel,
      });
    }, true);

    window.addEventListener("pageshow", (event) => {
      if (!event.persisted) {
        return;
      }

      replayRevealForBfcacheRestore();
    });

    window.addEventListener("pagehide", (event) => {
      revealScheduled = false;
      leaveInProgress = false;

      if (event.persisted) {
        restoreCurrentTransitionIdentity();
        html.classList.remove(leavingClass, revealedClass, initialClass, resumeClass);
        if (window.pageTransitionEnabled) {
          html.classList.add(enabledClass, loadingClass, resumeClass);
        } else {
          html.classList.remove(enabledClass, loadingClass);
        }
      }
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializePageTransitions, { once: true });
  } else {
    initializePageTransitions();
  }

  window.WallosPageTransitions = {
    configure(options = {}) {
      if (Object.prototype.hasOwnProperty.call(options, "style")) {
        applyTransitionStyle(options.style);
      }

      if (Object.prototype.hasOwnProperty.call(options, "enabled")) {
        window.pageTransitionEnabled = !!options.enabled;
      }

      if (!window.pageTransitionEnabled) {
        html.classList.remove(enabledClass, loadingClass, leavingClass, revealedClass, initialClass, resumeClass);
        try {
          window.sessionStorage.removeItem(contextStorageKey);
        } catch (error) {
          // Ignore sessionStorage cleanup failures.
        }
        return;
      }

      restoreCurrentTransitionIdentity();
      html.classList.add(enabledClass);
      if (!pageTransitionsInitialized) {
        initializePageTransitions(false);
      }
    },
  };
})();
