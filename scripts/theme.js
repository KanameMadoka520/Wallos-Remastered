function setBodyThemeClass(themeName) {
  const existingClasses = document.body.className
    .split(' ')
    .filter(cls => cls && cls !== 'dark' && cls !== 'light');

  document.body.className = [...existingClasses, themeName].join(' ');
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

function applyDecorativeBackgroundState(enabled) {
  document.body.classList.toggle('decorative-background-enabled', enabled);
  document.body.classList.toggle('decorative-background-disabled', !enabled);
  document.cookie = `decorativeBackground=${enabled ? '1' : '0'}; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/; SameSite=Lax`;
  if (window.WallosDecorativeBackground && typeof window.WallosDecorativeBackground.refresh === 'function') {
    window.WallosDecorativeBackground.refresh();
  }
}

function applyDynamicWallpaperState(enabled) {
  document.body.classList.toggle('dynamic-wallpaper-enabled', enabled);
  document.body.classList.toggle('dynamic-wallpaper-disabled', !enabled);
  if (window.WallosDynamicWallpaper && typeof window.WallosDynamicWallpaper.setEnabled === 'function') {
    window.WallosDynamicWallpaper.setEnabled(enabled);
  }
  updateDynamicWallpaperControls();
}

function applyDynamicWallpaperBlurState(enabled) {
  document.body.classList.toggle('dynamic-wallpaper-blur-enabled', enabled);
  document.body.classList.toggle('dynamic-wallpaper-blur-disabled', !enabled);
  if (window.WallosDynamicWallpaper && typeof window.WallosDynamicWallpaper.setBlur === 'function') {
    window.WallosDynamicWallpaper.setBlur(enabled);
  }
}

function updateDynamicWallpaperControls() {
  const wallpaperCheckbox = document.getElementById('dynamicwallpaper');
  const blurCheckbox = document.getElementById('dynamicwallpaperblur');

  if (!wallpaperCheckbox || !blurCheckbox) {
    return;
  }

  blurCheckbox.disabled = !wallpaperCheckbox.checked;
}

function switchTheme() {
  const darkThemeCss = document.querySelector("#dark-theme");
  darkThemeCss.disabled = !darkThemeCss.disabled;

  const themeChoice = darkThemeCss.disabled ? 'light' : 'dark';
  document.cookie = 'theme=' + themeValue + '; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/; SameSite=Lax';

  setBodyThemeClass(themeChoice);

  const button = document.getElementById("switchTheme");
  button.disabled = true;

  fetch('endpoints/settings/theme.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ theme: themeChoice === 'dark' })
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
      button.disabled = false;
    }).catch(error => {
      button.disabled = false;
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

  fetch('endpoints/settings/theme.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ theme: theme })
  })
    .then(response => response.json())
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
    });
}

function setTheme(themeColor) {
  var currentTheme = 'blue';
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

  fetch('endpoints/settings/colortheme.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ color: themeColor })
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
    })
    .catch(error => {
      showErrorMessage(translate('unknown_error'));
    });

}

function resetCustomColors() {
  const button = document.getElementById("reset-colors");
  button.disabled = true;

  fetch("endpoints/settings/resettheme.php", {
    method: "POST",
    headers: {
      "X-CSRF-Token": window.csrfToken,
    },
    body: new URLSearchParams({
      action: "reset",
    }),
  })
    .then(response => response.json())
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
      } else {
        showErrorMessage(data.message || translate("failed_reset_colors"));
      }
    })
    .catch(error => {
      console.error(error);
      showErrorMessage(translate("unknown_error"));
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

  fetch('endpoints/settings/customtheme.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ mainColor: mainColor, accentColor: accentColor, hoverColor: hoverColor, textColor: textColor })
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        document.documentElement.style.setProperty('--main-color', mainColor);
        document.documentElement.style.setProperty('--accent-color', accentColor);
        document.documentElement.style.setProperty('--hover-color', hoverColor);
        document.documentElement.style.setProperty('--wallos-dynamic-text-color', textColor);
        document.documentElement.style.setProperty('--wallos-dynamic-text-color-rgb', hexToRgbString(textColor));
      } else {
        showErrorMessage(data.message);
      }
      button.disabled = false;
    })
    .catch(error => {
      showErrorMessage(translate('unknown_error'));
      button.disabled = false;
    });

}

function saveCustomCss() {
  const button = document.getElementById("save-css");
  button.disabled = true;

  const customCss = document.getElementById("customCss").value;

  fetch('endpoints/settings/customcss.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ customCss: customCss })
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
      button.disabled = false;
    })
    .catch(error => {
      showErrorMessage(translate('unknown_error'));
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

  fetch('endpoints/settings/decorative_background.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ value: enabled })
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        applyDecorativeBackgroundState(enabled);
        showSuccessMessage(data.message);
      } else {
        checkbox.checked = !enabled;
        showErrorMessage(data.message);
      }
    })
    .catch(() => {
      checkbox.checked = !enabled;
      showErrorMessage(translate('unknown_error'));
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

  fetch('endpoints/settings/dynamic_wallpaper.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ value: enabled })
  })
    .then(response => response.json())
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
    .catch(() => {
      checkbox.checked = !enabled;
      updateDynamicWallpaperControls();
      showErrorMessage(translate('unknown_error'));
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

  fetch('endpoints/settings/dynamic_wallpaper_blur.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ value: enabled })
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        applyDynamicWallpaperBlurState(enabled);
        showSuccessMessage(data.message);
      } else {
        checkbox.checked = !enabled;
        showErrorMessage(data.message);
      }
    })
    .catch(() => {
      checkbox.checked = !enabled;
      showErrorMessage(translate('unknown_error'));
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
});
