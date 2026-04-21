function setBodyThemeClass(themeName) {
  const existingClasses = document.body.className
    .split(' ')
    .filter(cls => cls && cls !== 'dark' && cls !== 'light');

  document.body.className = [...existingClasses, themeName].join(' ');
  window.WallosThemeColor?.update?.();
}

function hexToRgbString(hex) {
  const normalized = String(hex || "").replace("#", "");
  if (normalized.length !== 6) {
    return "";
  }

  const r = parseInt(normalized.slice(0, 2), 16);
  const g = parseInt(normalized.slice(2, 4), 16);
  const b = parseInt(normalized.slice(4, 6), 16);
  return `${r}, ${g}, ${b}`;
}

function themePostJson(url, payload = {}, options = {}) {
  return window.WallosApi.postJson(url, payload, {
    fallbackErrorMessage: options.fallbackErrorMessage || translate('unknown_error'),
  });
}

function themePostForm(url, payload = {}, options = {}) {
  return window.WallosApi.postForm(url, payload, {
    fallbackErrorMessage: options.fallbackErrorMessage || translate('unknown_error'),
  });
}

function normalizeThemeRequestError(error, fallbackMessage = null) {
  return window.WallosApi?.normalizeError?.(error, fallbackMessage || translate('unknown_error'))
    || fallbackMessage
    || translate('unknown_error');
}

function applyDecorativeBackgroundState(enabled) {
  document.body.classList.toggle('decorative-background-enabled', enabled);
  document.body.classList.toggle('decorative-background-disabled', !enabled);
  document.cookie = `decorativeBackground=${enabled ? '1' : '0'}; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/; SameSite=Lax`;
  if (window.WallosDecorativeBackground && typeof window.WallosDecorativeBackground.refresh === 'function') {
    window.WallosDecorativeBackground.refresh();
  }
  window.WallosThemeColor?.update?.();
}

function applyDynamicWallpaperState(enabled) {
  document.body.classList.toggle('dynamic-wallpaper-enabled', enabled);
  document.body.classList.toggle('dynamic-wallpaper-disabled', !enabled);
  if (window.WallosDynamicWallpaper && typeof window.WallosDynamicWallpaper.setEnabled === 'function') {
    window.WallosDynamicWallpaper.setEnabled(enabled);
  }
  updateDynamicWallpaperControls();
  window.WallosThemeColor?.update?.();
}

function applyDynamicWallpaperBlurState(enabled) {
  document.body.classList.toggle('dynamic-wallpaper-blur-enabled', enabled);
  document.body.classList.toggle('dynamic-wallpaper-blur-disabled', !enabled);
  if (window.WallosDynamicWallpaper && typeof window.WallosDynamicWallpaper.setBlur === 'function') {
    window.WallosDynamicWallpaper.setBlur(enabled);
  }
  window.WallosThemeColor?.update?.();
}

function updateDynamicWallpaperControls() {
  const wallpaperCheckbox = document.getElementById('dynamicwallpaper');
  const blurCheckbox = document.getElementById('dynamicwallpaperblur');

  if (!wallpaperCheckbox || !blurCheckbox) {
    return;
  }

  blurCheckbox.disabled = !wallpaperCheckbox.checked;
}

function updatePageTransitionControls() {
  const enabledCheckbox = document.getElementById('pagetransitionenabled');
  const options = document.querySelectorAll('input[name="page-transition-style"]');
  const selectedStyle = window.pageTransitionStyle || 'shutter';

  if (!enabledCheckbox || !options.length) {
    return;
  }

  options.forEach((option) => {
    const isSelected = option.value === selectedStyle;
    option.checked = isSelected;
    option.disabled = !enabledCheckbox.checked;
    option.closest('.page-transition-style-option')?.classList.toggle('is-selected', isSelected);
  });
}

function setPageTransitionSettings(forcedStyle = null) {
  const enabledCheckbox = document.getElementById('pagetransitionenabled');
  if (!enabledCheckbox) {
    return;
  }

  const checkedStyle = forcedStyle || document.querySelector('input[name="page-transition-style"]:checked')?.value || window.pageTransitionStyle || 'shutter';
  const enabled = enabledCheckbox.checked;
  enabledCheckbox.disabled = true;

  document.querySelectorAll('input[name="page-transition-style"]').forEach((input) => {
    input.disabled = true;
  });

  themePostJson('endpoints/settings/page_transition.php', { enabled: enabled, style: checkedStyle })
    .then(data => {
      if (data.success) {
        window.pageTransitionEnabled = enabled;
        window.pageTransitionStyle = checkedStyle;
        if (window.WallosPageTransitions && typeof window.WallosPageTransitions.configure === 'function') {
          window.WallosPageTransitions.configure({ enabled: enabled, style: checkedStyle });
        }
        showSuccessMessage(data.message);
      } else {
        enabledCheckbox.checked = !enabled;
        showErrorMessage(data.message);
      }
    })
    .catch((error) => {
      enabledCheckbox.checked = !enabled;
      showErrorMessage(normalizeThemeRequestError(error));
    })
    .finally(() => {
      enabledCheckbox.disabled = false;
      updatePageTransitionControls();
    });
}

function switchTheme() {
  const darkThemeCss = document.querySelector("#dark-theme");
  const previousThemeChoice = darkThemeCss.disabled ? 'light' : 'dark';
  darkThemeCss.disabled = !darkThemeCss.disabled;

  const themeChoice = darkThemeCss.disabled ? 'light' : 'dark';
  const previousThemeCookie = getCookie('theme') || '';
  document.cookie = `theme=${themeChoice}; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/; SameSite=Lax`;

  setBodyThemeClass(themeChoice);

  const button = document.getElementById("switchTheme");
  button.disabled = true;

  themePostJson('endpoints/settings/theme.php', { theme: themeChoice === 'dark' ? 1 : 0 })
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        window.WallosThemeColor?.update?.();
      } else {
        document.cookie = `theme=${previousThemeCookie}; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/; SameSite=Lax`;
        darkThemeCss.disabled = previousThemeChoice === 'light';
        setBodyThemeClass(previousThemeChoice);
        showErrorMessage(data.message);
      }
      button.disabled = false;
    }).catch(error => {
      document.cookie = `theme=${previousThemeCookie}; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/; SameSite=Lax`;
      darkThemeCss.disabled = previousThemeChoice === 'light';
      setBodyThemeClass(previousThemeChoice);
      button.disabled = false;
      showErrorMessage(normalizeThemeRequestError(error));
    });
}

function setDarkTheme(theme) {
  const darkThemeButton = document.querySelector("#theme-dark");
  const lightThemeButton = document.querySelector("#theme-light");
  const automaticThemeButton = document.querySelector("#theme-automatic");
  const darkThemeCss = document.querySelector("#dark-theme");
  const themes = { 0: 'light', 1: 'dark', 2: 'automatic' };
  const themeValue = themes[theme];
  const prefersDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;

  darkThemeButton.disabled = true;
  lightThemeButton.disabled = true;
  automaticThemeButton.disabled = true;

  themePostJson('endpoints/settings/theme.php', { theme: theme })
    .then(data => {
      if (data.success) {
        darkThemeButton.disabled = false;
        lightThemeButton.disabled = false;
        automaticThemeButton.disabled = false;
        darkThemeButton.classList.remove('selected');
        lightThemeButton.classList.remove('selected');
        automaticThemeButton.classList.remove('selected');

        document.cookie = `theme=${themeValue}; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/; SameSite=Lax`;

        if (theme == 0) {
          darkThemeCss.disabled = true;
          setBodyThemeClass('light');
          lightThemeButton.classList.add('selected');
        }

        if (theme == 1) {
          darkThemeCss.disabled = false;
          setBodyThemeClass('dark');
          darkThemeButton.classList.add('selected');
        }

        if (theme == 2) {
          darkThemeCss.disabled = !prefersDarkMode;
          setBodyThemeClass(prefersDarkMode ? 'dark' : 'light');
          automaticThemeButton.classList.add('selected');
          document.cookie = `inUseTheme=${prefersDarkMode ? 'dark' : 'light'}; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/; SameSite=Lax`;
        }

        window.WallosThemeColor?.update?.();
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
        darkThemeButton.disabled = false;
        lightThemeButton.disabled = false;
        automaticThemeButton.disabled = false;
      }
    }).catch(error => {
      darkThemeButton.disabled = false;
      lightThemeButton.disabled = false;
      automaticThemeButton.disabled = false;
      showErrorMessage(normalizeThemeRequestError(error));
    });
}

function setTheme(themeColor) {
  var currentTheme = window.colorTheme === 'blue' ? 'blue' : 'purple';
  var themeIds = ['red-theme', 'green-theme', 'yellow-theme', 'purple-theme'];

  themeIds.forEach(function (id) {
    var themeStylesheet = document.getElementById(id);
    if (themeStylesheet && !themeStylesheet.disabled) {
      currentTheme = id.replace('-theme', '');
      themeStylesheet.disabled = true;
    }
  });

  if (themeColor !== "blue") {
    var enableTheme = document.getElementById(themeColor + '-theme');
    enableTheme.disabled = false;
  }

  var images = document.querySelectorAll('img');
  images.forEach(function (img) {
    if (img.src.includes('siteicons/' + currentTheme)) {
      img.src = img.src.replace(currentTheme, themeColor);
    }
  });

  var labels = document.querySelectorAll('.theme-preview');
  labels.forEach(function (label) {
    label.classList.remove('is-selected');
  });

  var targetLabel = document.querySelector(`.theme-preview.${themeColor}`);
  if (targetLabel) {
    targetLabel.classList.add('is-selected');
  }

  document.cookie = `colorTheme=${themeColor}; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/; SameSite=Lax`;

  themePostJson('endpoints/settings/colortheme.php', { color: themeColor })
    .then(data => {
      if (data.success) {
        window.WallosThemeColor?.update?.();
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
    })
    .catch(error => {
      showErrorMessage(normalizeThemeRequestError(error));
    });

}

function resetCustomColors() {
  const button = document.getElementById("reset-colors");
  button.disabled = true;

  themePostForm("endpoints/settings/resettheme.php", {
    action: "reset",
  })
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);

        const customThemeColors = document.getElementById("custom_theme_colors");
        if (customThemeColors) {
          customThemeColors.remove();
        }

        document.documentElement.style.removeProperty("--main-color");
        document.documentElement.style.removeProperty("--accent-color");
        document.documentElement.style.removeProperty("--hover-color");
        document.documentElement.style.removeProperty("--wallos-dynamic-text-color");
        document.documentElement.style.removeProperty("--wallos-dynamic-text-color-rgb");

        document.getElementById("mainColor").value = "#FFFFFF";
        document.getElementById("accentColor").value = "#FFFFFF";
        document.getElementById("hoverColor").value = "#FFFFFF";
        document.getElementById("textColor").value = "#202020";
        window.WallosThemeColor?.update?.();
      } else {
        showErrorMessage(data.message || translate("failed_reset_colors"));
      }
    })
    .catch(error => {
      console.error(error);
      showErrorMessage(normalizeThemeRequestError(error));
    })
    .finally(() => {
      button.disabled = false;
    });
}


function saveCustomColors() {
  const button = document.getElementById("save-colors");
  button.disabled = true;

  const mainColor = document.getElementById("mainColor").value;
  const accentColor = document.getElementById("accentColor").value;
  const hoverColor = document.getElementById("hoverColor").value;
  const textColor = document.getElementById("textColor").value;

  themePostJson('endpoints/settings/customtheme.php', { mainColor: mainColor, accentColor: accentColor, hoverColor: hoverColor, textColor: textColor })
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        document.documentElement.style.setProperty('--main-color', mainColor);
        document.documentElement.style.setProperty('--accent-color', accentColor);
        document.documentElement.style.setProperty('--hover-color', hoverColor);
        document.documentElement.style.setProperty('--wallos-dynamic-text-color', textColor);
        document.documentElement.style.setProperty('--wallos-dynamic-text-color-rgb', hexToRgbString(textColor));
        window.WallosThemeColor?.update?.();
      } else {
        showErrorMessage(data.message);
      }
      button.disabled = false;
    })
    .catch(error => {
      showErrorMessage(normalizeThemeRequestError(error));
      button.disabled = false;
    });

}

function saveCustomCss() {
  const button = document.getElementById("save-css");
  button.disabled = true;

  const customCss = document.getElementById("customCss").value;

  themePostJson('endpoints/settings/customcss.php', { customCss: customCss })
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
      button.disabled = false;
    })
    .catch(error => {
      showErrorMessage(normalizeThemeRequestError(error));
      button.disabled = false;
    });
}

function setDecorativeBackground() {
  const checkbox = document.getElementById('decorativebackground');
  if (!checkbox) {
    return;
  }

  const enabled = checkbox.checked;
  checkbox.disabled = true;

  themePostJson('endpoints/settings/decorative_background.php', { value: enabled })
    .then(data => {
      if (data.success) {
        applyDecorativeBackgroundState(enabled);
        showSuccessMessage(data.message);
      } else {
        checkbox.checked = !enabled;
        showErrorMessage(data.message);
      }
    })
    .catch((error) => {
      checkbox.checked = !enabled;
      showErrorMessage(normalizeThemeRequestError(error));
    })
    .finally(() => {
      checkbox.disabled = false;
    });
}

function setDynamicWallpaper() {
  const checkbox = document.getElementById('dynamicwallpaper');
  if (!checkbox) {
    return;
  }

  const enabled = checkbox.checked;
  checkbox.disabled = true;

  themePostJson('endpoints/settings/dynamic_wallpaper.php', { value: enabled })
    .then(data => {
      if (data.success) {
        applyDynamicWallpaperState(enabled);
        showSuccessMessage(data.message);
      } else {
        checkbox.checked = !enabled;
        updateDynamicWallpaperControls();
        showErrorMessage(data.message);
      }
    })
    .catch((error) => {
      checkbox.checked = !enabled;
      updateDynamicWallpaperControls();
      showErrorMessage(normalizeThemeRequestError(error));
    })
    .finally(() => {
      checkbox.disabled = false;
    });
}

function setDynamicWallpaperBlur() {
  const checkbox = document.getElementById('dynamicwallpaperblur');
  if (!checkbox) {
    return;
  }

  const enabled = checkbox.checked;
  checkbox.disabled = true;

  themePostJson('endpoints/settings/dynamic_wallpaper_blur.php', { value: enabled })
    .then(data => {
      if (data.success) {
        applyDynamicWallpaperBlurState(enabled);
        showSuccessMessage(data.message);
      } else {
        checkbox.checked = !enabled;
        showErrorMessage(data.message);
      }
    })
    .catch((error) => {
      checkbox.checked = !enabled;
      showErrorMessage(normalizeThemeRequestError(error));
    })
    .finally(() => {
      checkbox.disabled = false;
      updateDynamicWallpaperControls();
    });
}

document.addEventListener('DOMContentLoaded', function () {
  applyDynamicWallpaperState(!!window.dynamicWallpaperEnabled);
  applyDynamicWallpaperBlurState(!!window.dynamicWallpaperBlurEnabled);
  updateDynamicWallpaperControls();
  updatePageTransitionControls();
});
