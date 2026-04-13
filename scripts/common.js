let isDropdownOpen = false;
let hasUserInteractedWithPage = false;
const toastState = {
  error: { hideTimer: null, progressTimer: null },
  success: { hideTimer: null, progressTimer: null },
};

function toggleDropdown() {
  const dropdown = document.querySelector('.dropdown');
  dropdown.classList.toggle('is-open');
  isDropdownOpen = !isDropdownOpen;
}

function setupPageNavigation() {
  const pageNavs = document.querySelectorAll(".page-nav");

  pageNavs.forEach((pageNav) => {
    const links = [...pageNav.querySelectorAll("[data-page-nav-link]")];
    const sections = links
      .map((link) => document.getElementById(link.dataset.pageNavLink))
      .filter(Boolean);

    if (!links.length || !sections.length) {
      return;
    }

    const setActiveLink = (targetId) => {
      links.forEach((link) => {
        const isActive = link.dataset.pageNavLink === targetId;
        link.classList.toggle("is-active", isActive);
      });
    };

    const initialTarget = window.location.hash ? window.location.hash.slice(1) : sections[0].id;
    setActiveLink(initialTarget);

    links.forEach((link) => {
      link.addEventListener("click", () => {
        setActiveLink(link.dataset.pageNavLink);
      });
    });

    if (!("IntersectionObserver" in window)) {
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        const visibleSections = entries
          .filter((entry) => entry.isIntersecting)
          .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);

        if (visibleSections.length > 0) {
          setActiveLink(visibleSections[0].target.id);
        }
      },
      {
        rootMargin: "-20% 0px -60% 0px",
        threshold: [0.2, 0.5, 0.8],
      }
    );

    sections.forEach((section) => observer.observe(section));
  });
}

function closeToast(type) {
  const toast = document.querySelector(type === "error" ? ".toast#errorToast" : ".toast#successToast");
  const progress = document.querySelector(type === "error" ? ".progress.error" : ".progress.success");
  if (!toast || !progress) {
    return;
  }

  toast.classList.remove("active");
  window.clearTimeout(toastState[type].hideTimer);
  window.clearTimeout(toastState[type].progressTimer);
  toastState[type].hideTimer = null;
  toastState[type].progressTimer = null;

  window.setTimeout(() => {
    progress.classList.remove("active");
  }, 220);
}

function normalizeToastContent(type, message) {
  const fallbackTitle = type === "error" ? translate("error") : translate("success");
  const fallbackBody = type === "error" ? translate("toast_error_generic") : translate("toast_success_generic");
  const rawMessage = message instanceof Error
    ? String(message.message || "").trim()
    : String(message ?? "").trim();

  if (!rawMessage) {
    return {
      title: fallbackTitle,
      body: fallbackBody,
      shouldDisplay: false,
    };
  }

  const genericCandidates = new Set([
    fallbackTitle,
    type === "error" ? "Error" : "Success",
    type === "error" ? "error" : "success",
  ]);

  if (genericCandidates.has(rawMessage)) {
    return {
      title: fallbackTitle,
      body: fallbackBody,
      shouldDisplay: false,
    };
  }

  const separatorMatch = rawMessage.match(/^([^:\n：]{1,60})[:：]\s*([\s\S]+)$/u);
  if (separatorMatch) {
    return {
      title: separatorMatch[1].trim(),
      body: separatorMatch[2].trim(),
      shouldDisplay: true,
    };
  }

  const lineMatch = rawMessage.match(/^([^\n]{1,70})\n+([\s\S]+)$/);
  if (lineMatch) {
    return {
      title: lineMatch[1].trim(),
      body: lineMatch[2].trim(),
      shouldDisplay: true,
    };
  }

  if (rawMessage.length <= 80) {
    return {
      title: rawMessage,
      body: "",
      shouldDisplay: true,
    };
  }

  return {
    title: fallbackTitle,
    body: rawMessage,
    shouldDisplay: true,
  };
}

function showToast(type, message) {
  const toast = document.querySelector(type === "error" ? ".toast#errorToast" : ".toast#successToast");
  const closeIcon = document.querySelector(type === "error" ? ".close-error" : ".close-success");
  const titleNode = document.querySelector(type === "error" ? "#errorToast .text-1" : "#successToast .text-1");
  const bodyNode = document.querySelector(type === "error" ? ".errorMessage" : ".successMessage");
  const progress = document.querySelector(type === "error" ? ".progress.error" : ".progress.success");

  if (!toast || !closeIcon || !titleNode || !bodyNode || !progress) {
    return;
  }

  const normalized = normalizeToastContent(type, message);
  if (!normalized.shouldDisplay && !hasUserInteractedWithPage) {
    return;
  }

  titleNode.textContent = normalized.title;
  bodyNode.textContent = normalized.body;
  bodyNode.classList.toggle("is-empty", normalized.body === "");

  closeToast(type);
  toast.classList.add("active");
  progress.classList.add("active");

  toastState[type].hideTimer = window.setTimeout(() => {
    closeToast(type);
  }, 5000);

  toastState[type].progressTimer = window.setTimeout(() => {
    progress.classList.remove("active");
  }, 5300);

  closeIcon.onclick = () => closeToast(type);
}

function showErrorMessage(message) {
  showToast("error", message);
}

function showSuccessMessage(message) {
  showToast("success", message);
}

function markUserInteraction() {
  hasUserInteractedWithPage = true;
}

document.addEventListener("pointerdown", markUserInteraction, { passive: true });
document.addEventListener("keydown", markUserInteraction, { passive: true });

document.addEventListener('DOMContentLoaded', function () {

  const userLocale = navigator.language || navigator.languages[0];
  document.cookie = `user_locale=${userLocale}; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/; SameSite=Lax`;

  if (window.update_theme_settings) {
    const prefersDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const themePreference = prefersDarkMode ? 'dark' : 'light';
    const darkThemeCss = document.querySelector("#dark-theme");
    darkThemeCss.disabled = themePreference === 'light';

    const existingClasses = document.body.className.split(' ').filter(cls => cls !== 'dark' && cls !== 'light');
    document.body.className = [...existingClasses, themePreference].join(' ');

    document.cookie = `inUseTheme=${themePreference}; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/; SameSite=Lax`;
    const themeColorMetaTag = document.querySelector('meta[name="theme-color"]');
    themeColorMetaTag.setAttribute('content', themePreference === 'dark' ? '#222222' : '#FFFFFF');
  }

  document.addEventListener('mousedown', function (event) {
    const dropdown = document.querySelector('.dropdown');
    if (!dropdown) {
      return;
    }

    if (!dropdown.contains(event.target) && isDropdownOpen) {
      dropdown.classList.remove('is-open');
      isDropdownOpen = false;
    }
  });

  const dropdownContent = document.querySelector('.dropdown-content');
  if (dropdownContent) {
    dropdownContent.addEventListener('focus', function () {
      isDropdownOpen = true;
    });
  }

  setupPageNavigation();
});

function getCookie(name) {
  const cookies = document.cookie.split(';');
  for (let cookie of cookies) {
    cookie = cookie.trim();
    if (cookie.startsWith(`${name}=`)) {
      return cookie.substring(name.length + 1);
    }
  }
  return null;
}
