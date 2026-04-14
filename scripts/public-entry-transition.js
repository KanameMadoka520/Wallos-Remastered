(function () {
  const html = document.documentElement;

  function getNavigationEntry() {
    if (!window.performance || typeof window.performance.getEntriesByType !== "function") {
      return null;
    }

    const entries = window.performance.getEntriesByType("navigation");
    return Array.isArray(entries) && entries.length > 0 ? entries[0] : null;
  }

  function markComplete(overlay) {
    if (!document.body) {
      return;
    }

    document.body.classList.remove("public-entry-pending", "public-entry-active");
    document.body.classList.add("public-entry-complete");
    html.classList.remove("public-entry-active");
    html.classList.add("public-entry-complete");

    window.setTimeout(function () {
      if (overlay) {
        overlay.hidden = true;
      }
    }, 760);
  }

  function initPublicEntryTransition() {
    const overlay = document.getElementById("wallos-public-entry");
    if (!overlay || !document.body || !document.body.classList.contains("public-page")) {
      return;
    }

    const navigationEntry = getNavigationEntry();
    if (navigationEntry && navigationEntry.type === "back_forward") {
      markComplete(overlay);
      return;
    }

    overlay.querySelectorAll("[data-public-entry-token]").forEach(function (token, index) {
      token.style.setProperty("--token-index", String(index));
    });

    let completed = false;

    function finish() {
      if (completed) {
        return;
      }

      completed = true;
      markComplete(overlay);
    }

    html.classList.remove("public-entry-complete");
    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(function () {
        document.body.classList.remove("public-entry-pending");
        document.body.classList.add("public-entry-active");
        html.classList.add("public-entry-active");
      });
    });

    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const duration = reducedMotion ? 180 : 1680;
    const timer = window.setTimeout(finish, duration);

    function finishEarly() {
      window.clearTimeout(timer);
      finish();
    }

    window.addEventListener("pageshow", function (event) {
      if (event.persisted) {
        finishEarly();
      }
    }, { once: true });

    document.addEventListener("visibilitychange", function handleVisibilityChange() {
      if (document.hidden) {
        document.removeEventListener("visibilitychange", handleVisibilityChange);
        finishEarly();
      }
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPublicEntryTransition, { once: true });
  } else {
    initPublicEntryTransition();
  }
})();
