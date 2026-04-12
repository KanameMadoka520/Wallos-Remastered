function changePublicPageLanguage(selectedLanguage) {
  if (!selectedLanguage) {
    return;
  }

  const url = new URL(window.location.href);
  url.searchParams.set("set_language", selectedLanguage);
  window.location.href = url.toString();
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
  document.cookie = `user_locale=${userLocale}; expires=Fri, 31 Dec 9999 23:59:59 GMT; SameSite=Lax`;

  if (window.update_theme_settings) {
    const prefersDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const themePreference = prefersDarkMode ? 'dark' : 'light';
    const darkThemeCss = document.querySelector("#dark-theme");
    darkThemeCss.disabled = themePreference === 'light';
    document.body.className = themePreference;
    const themeColorMetaTag = document.querySelector('meta[name="theme-color"]');
    themeColorMetaTag.setAttribute('content', themePreference === 'dark' ? '#222222' : '#FFFFFF');
  }

});
