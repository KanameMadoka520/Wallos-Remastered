(function () {
  const html = document.documentElement;
  const transitionOverlayId = "wallos-page-transition";
  const transitionTitleId = "wallos-page-transition-title";
  const contextStorageKey = "wallos-page-transition-context";
  const enabledClass = "wallos-page-transition-enabled";
  const supportedStyles = new Set(["shutter", "nova", "scanline", "ribbon"]);
  const leaveDurationMs = 420;
  const loadingClass = "wallos-page-transition-loading";
  const leavingClass = "wallos-page-transition-leaving";
  const revealedClass = "wallos-page-transition-revealed";
  const initialClass = "wallos-page-transition-initial";
  const resumeClass = "wallos-page-transition-resume";
  let revealScheduled = false;
  let leaveInProgress = false;
  let pageTransitionsInitialized = false;

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
    if (!title || !label) {
      return;
    }

    const normalizedLabel = String(label).replace(/\s+/g, " ").trim();
    if (normalizedLabel !== "") {
      title.textContent = normalizedLabel;
    }
  }

  function persistTransitionContext(label) {
    try {
      window.sessionStorage.setItem(contextStorageKey, JSON.stringify({
        active: true,
        label: String(label || "").replace(/\s+/g, " ").trim(),
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
    return transitionContext;
  }

  function startLeaveTransition(onComplete, label = "") {
    if (leaveInProgress || !hasOverlay() || !window.pageTransitionEnabled) {
      if (typeof onComplete === "function") {
        onComplete();
      }
      return;
    }

    leaveInProgress = true;
    persistTransitionContext(label);
    updateTransitionTitle(label);
    html.classList.remove(revealedClass);
    html.classList.add(loadingClass, leavingClass);

    window.setTimeout(() => {
      if (typeof onComplete === "function") {
        onComplete();
      }
    }, leaveDurationMs);
  }

  function replayRevealForBfcacheRestore() {
    revealScheduled = false;
    leaveInProgress = false;
    html.classList.add(enabledClass, loadingClass);
    html.classList.remove(leavingClass, revealedClass, initialClass, resumeClass);
    html.classList.add(resumeClass);
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
      if (transitionContext?.label) {
        updateTransitionTitle(transitionContext.label);
      }
      scheduleReveal();
    } else {
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
      const nextUrl = anchor.href;
      const nextLabel = anchor.dataset.transitionLabel || anchor.textContent || anchor.getAttribute("title") || "";
      startLeaveTransition(() => {
        window.location.href = nextUrl;
      }, nextLabel);
    }, true);

    window.addEventListener("pageshow", (event) => {
      if (!event.persisted) {
        return;
      }

      replayRevealForBfcacheRestore();
    });

    window.addEventListener("pagehide", () => {
      revealScheduled = false;
      leaveInProgress = false;
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

      html.classList.add(enabledClass);
      if (!pageTransitionsInitialized) {
        initializePageTransitions(false);
      }
    },
  };
})();
