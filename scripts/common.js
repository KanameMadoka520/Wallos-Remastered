let isDropdownOpen = false;

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

function showErrorMessage(message) {
  const toast = document.querySelector(".toast#errorToast");
  const closeIcon = document.querySelector(".close-error");
  const errorTitle = document.querySelector("#errorToast .text-1");
  const errorMessage = document.querySelector(".errorMessage");
  const progress = document.querySelector(".progress.error");
  let timer1, timer2;
  const normalizedContent = normalizeToastContent("error", message);
  errorTitle.textContent = normalizedContent.title;
  errorMessage.textContent = normalizedContent.body;
  errorMessage.classList.toggle("is-empty", normalizedContent.body === "");
  toast.classList.add("active");
  progress.classList.add("active");
  timer1 = setTimeout(() => {
    toast.classList.remove("active");
    closeIcon.removeEventListener("click", () => { });
  }, 5000);

  timer2 = setTimeout(() => {
    progress.classList.remove("active");
  }, 5300);

  closeIcon.addEventListener("click", () => {
    toast.classList.remove("active");

    setTimeout(() => {
      progress.classList.remove("active");
    }, 300);

    clearTimeout(timer1);
    clearTimeout(timer2);
    closeIcon.removeEventListener("click", () => { });
  });
}

function showSuccessMessage(message) {
  const toast = document.querySelector(".toast#successToast");
  const closeIcon = document.querySelector(".close-success");
  const successTitle = document.querySelector("#successToast .text-1");
  const successMessage = document.querySelector(".successMessage");
  const progress = document.querySelector(".progress.success");
  let timer1, timer2;
  const normalizedContent = normalizeToastContent("success", message);
  successTitle.textContent = normalizedContent.title;
  successMessage.textContent = normalizedContent.body;
  successMessage.classList.toggle("is-empty", normalizedContent.body === "");
  toast.classList.add("active");
  progress.classList.add("active");
  timer1 = setTimeout(() => {
    toast.classList.remove("active");
    closeIcon.removeEventListener("click", () => { });
  }, 5000);

  timer2 = setTimeout(() => {
    progress.classList.remove("active");
  }, 5300);

  closeIcon.addEventListener("click", () => {
    toast.classList.remove("active");

    setTimeout(() => {
      progress.classList.remove("active");
    }, 300);

    clearTimeout(timer1);
    clearTimeout(timer2);
    closeIcon.removeEventListener("click", () => { });
  });
}

document.addEventListener('DOMContentLoaded', function () {

  const userLocale = navigator.language || navigator.languages[0];
  document.cookie = `user_locale=${userLocale}; expires=Fri, 31 Dec 9999 23:59:59 GMT; SameSite=Lax`;

  if (window.update_theme_settings) {
    const prefersDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const themePreference = prefersDarkMode ? 'dark' : 'light';
    const darkThemeCss = document.querySelector("#dark-theme");
    darkThemeCss.disabled = themePreference === 'light';

    // Preserve existing classes on the body tag
    const existingClasses = document.body.className.split(' ').filter(cls => cls !== 'dark' && cls !== 'light');
    document.body.className = [...existingClasses, themePreference].join(' ');

    document.cookie = `inUseTheme=${themePreference}; expires=Fri, 31 Dec 9999 23:59:59 GMT; SameSite=Lax`;
    const themeColorMetaTag = document.querySelector('meta[name="theme-color"]');
    themeColorMetaTag.setAttribute('content', themePreference === 'dark' ? '#222222' : '#FFFFFF');
  }

  document.addEventListener('mousedown', function (event) {
    var dropdown = document.querySelector('.dropdown');
    var dropdownContent = document.querySelector('.dropdown-content');

    if (!dropdown.contains(event.target) && isDropdownOpen) {
      dropdown.classList.remove('is-open');
      isDropdownOpen = false;
    }
  });

  document.querySelector('.dropdown-content').addEventListener('focus', function () {
    isDropdownOpen = true;
  });

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

function normalizeToastContent(type, message) {
  const fallbackTitle = type === "error" ? translate("error") : translate("success");
  const fallbackBody = type === "error" ? translate("toast_error_generic") : translate("toast_success_generic");
  const rawMessage = String(message ?? "").trim();

  if (!rawMessage) {
    return {
      title: fallbackTitle,
      body: fallbackBody,
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
    };
  }

  const separatorMatch = rawMessage.match(/^([^:\n：]{1,60})[:：]\s*([\s\S]+)$/);
  if (separatorMatch) {
    return {
      title: separatorMatch[1].trim(),
      body: separatorMatch[2].trim(),
    };
  }

  const lineMatch = rawMessage.match(/^([^\n]{1,70})\n+([\s\S]+)$/);
  if (lineMatch) {
    return {
      title: lineMatch[1].trim(),
      body: lineMatch[2].trim(),
    };
  }

  if (rawMessage.length <= 80) {
    return {
      title: rawMessage,
      body: "",
    };
  }

  return {
    title: fallbackTitle,
    body: rawMessage,
  };
}
