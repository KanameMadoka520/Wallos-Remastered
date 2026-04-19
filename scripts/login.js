function changePublicPageLanguage(selectedLanguage) {
  if (!selectedLanguage) {
    return;
  }

  const url = new URL(window.location.href);
  url.searchParams.set("set_language", selectedLanguage);
  window.location.href = url.toString();
}

window.changePublicPageLanguage = changePublicPageLanguage;

function rgbStringToHex(rgbString) {
  const matches = String(rgbString || "").match(/\d+/g);
  if (!matches || matches.length < 3) {
    return "";
  }

  const [r, g, b] = matches.slice(0, 3).map((value) => {
    const normalized = Math.max(0, Math.min(255, Number.parseInt(value, 10) || 0));
    return normalized.toString(16).padStart(2, "0");
  });

  return `#${r}${g}${b}`.toUpperCase();
}

function updatePublicThemeColorMetaTag() {
  const themeColorMetaTag = document.querySelector('meta[name="theme-color"]');
  if (!themeColorMetaTag || !document.documentElement || !document.body) {
    return;
  }

  const computed = window.getComputedStyle(document.documentElement);
  const isDark = document.body.classList.contains('dark');
  const rgbSource = isDark
    ? computed.getPropertyValue('--header-background-color-rgb') || computed.getPropertyValue('--box-background-color-rgb')
    : computed.getPropertyValue('--main-color-rgb') || computed.getPropertyValue('--header-background-color-rgb');
  const resolvedColor = rgbStringToHex(rgbSource);

  if (resolvedColor) {
    themeColorMetaTag.setAttribute('content', resolvedColor);
  }
}

function cleanupLanguageQueryParam() {
  const url = new URL(window.location.href);
  if (!url.searchParams.has("set_language")) {
    return;
  }

  url.searchParams.delete("set_language");
  window.history.replaceState({}, document.title, url.toString());
}

document.addEventListener('DOMContentLoaded', function () {
  cleanupLanguageQueryParam();

  const userLocale = navigator.language || navigator.languages[0];
  document.cookie = `user_locale=${userLocale}; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/; SameSite=Lax`;

  if (window.update_theme_settings) {
    const prefersDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const themePreference = prefersDarkMode ? 'dark' : 'light';
    const darkThemeCss = document.querySelector("#dark-theme");
    darkThemeCss.disabled = themePreference === 'light';
    const existingClasses = document.body.className.split(' ').filter(cls => cls && cls !== 'dark' && cls !== 'light');
    document.body.className = [...existingClasses, themePreference].join(' ');
    document.cookie = `inUseTheme=${themePreference}; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/; SameSite=Lax`;
  }

  updatePublicThemeColorMetaTag();

  const languageSelect = document.getElementById('public-page-language-login');
  if (languageSelect) {
    languageSelect.addEventListener('change', function () {
      changePublicPageLanguage(this.value);
    });
  }

});
