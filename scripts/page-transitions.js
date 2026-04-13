(function () {
  const html = document.documentElement;
  const transitionOverlayId = "wallos-page-transition";
  const transitionTitleId = "wallos-page-transition-title";
  const leaveDurationMs = 420;
  const loadingClass = "wallos-page-transition-loading";
  const leavingClass = "wallos-page-transition-leaving";
  const revealedClass = "wallos-page-transition-revealed";
  let revealScheduled = false;
  let leaveInProgress = false;
  let pageTransitionsInitialized = false;

  function hasOverlay() {
    return !!document.getElementById(transitionOverlayId);
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

  function startLeaveTransition(onComplete, label = "") {
    if (leaveInProgress || !hasOverlay()) {
      if (typeof onComplete === "function") {
        onComplete();
      }
      return;
    }

    leaveInProgress = true;
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
    html.classList.add("wallos-page-transition-enabled", loadingClass);
    html.classList.remove(leavingClass, revealedClass);
    scheduleReveal();
  }

  function initializePageTransitions() {
    if (pageTransitionsInitialized || !hasOverlay()) {
      return;
    }

    pageTransitionsInitialized = true;
    html.classList.add("wallos-page-transition-enabled", loadingClass);
    scheduleReveal();

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
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializePageTransitions, { once: true });
  } else {
    initializePageTransitions();
  }
})();
