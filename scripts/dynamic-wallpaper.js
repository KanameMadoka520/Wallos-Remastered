(function () {
  const defaultConfig = {
    breakpoint: 768,
    desktopIndex: 3,
    mobileIndex: 1,
    sources: [
      {
        src: "https://b2.akz.moe/awesome-pictures/ffv76cb_x264.mp4",
        poster: "https://b2.akz.moe/awesome-pictures/ffv76cb_frame_1.webp",
      },
      {
        src: "https://b2.akz.moe/awesome-pictures/jfhcdfd5_x264_1080p_crf24.mp4",
        poster: "https://b2.akz.moe/awesome-pictures/jfhcdfd5_frame_1.webp",
      },
      {
        src: "https://b2.akz.moe/awesome-pictures/%E8%93%9D%E8%8E%93%E9%9B%AA%E7%B3%95/1080p_60fps_crf25_x265_an.mp4",
        poster: "https://b2.akz.moe/awesome-pictures/%E8%93%9D%E8%8E%93%E9%9B%AA%E7%B3%95/poster.webp",
      },
      {
        src: "https://b2.akz.moe/awesome-pictures/snare/7jmgdh.mp4",
        poster: "https://b2.akz.moe/awesome-pictures/snare/poster.webp",
      },
    ],
  };

  let resizeTimer = 0;

  function getConfig() {
    return Object.assign({}, defaultConfig, window.WallosDynamicWallpaperConfig || {});
  }

  function getContainer() {
    return document.querySelector(".wallos-dynamic-wallpaper");
  }

  function getVideo() {
    return document.querySelector(".wallos-dynamic-wallpaper-video");
  }

  function isEnabled() {
    return document.body && document.body.classList.contains("dynamic-wallpaper-enabled");
  }

  function isBlurEnabled() {
    return document.body && document.body.classList.contains("dynamic-wallpaper-blur-enabled");
  }

  function pickSourceIndex() {
    const config = getConfig();
    return window.innerWidth < config.breakpoint ? config.mobileIndex : config.desktopIndex;
  }

  function loadSelectedSource(forceReload) {
    const video = getVideo();
    if (!video || !isEnabled()) {
      return;
    }

    const config = getConfig();
    const sourceIndex = pickSourceIndex();
    const source = config.sources[sourceIndex] || config.sources[0];
    if (!source || !source.src) {
      return;
    }

    if (!forceReload && video.dataset.currentSrc === source.src) {
      return;
    }

    video.dataset.currentSrc = source.src;
    video.dataset.currentIndex = String(sourceIndex);
    video.poster = source.poster || "";
    video.src = source.src;
    video.load();
    video.play().catch(function () {});
  }

  function stopVideo() {
    const video = getVideo();
    if (!video) {
      return;
    }

    video.pause();
    video.removeAttribute("src");
    video.dataset.currentSrc = "";
    video.dataset.currentIndex = "";
    video.load();
  }

  function refresh() {
    const container = getContainer();
    const video = getVideo();
    if (!container || !video || !document.body) {
      return;
    }

    container.hidden = !isEnabled();
    document.body.classList.toggle("dynamic-wallpaper-blur-enabled", isBlurEnabled());
    document.body.classList.toggle("dynamic-wallpaper-blur-disabled", !isBlurEnabled());

    if (!isEnabled()) {
      stopVideo();
      return;
    }

    loadSelectedSource(false);
  }

  function setEnabled(enabled) {
    if (!document.body) {
      return;
    }

    document.body.classList.toggle("dynamic-wallpaper-enabled", enabled);
    document.body.classList.toggle("dynamic-wallpaper-disabled", !enabled);
    refresh();
  }

  function setBlur(enabled) {
    if (!document.body) {
      return;
    }

    document.body.classList.toggle("dynamic-wallpaper-blur-enabled", enabled);
    document.body.classList.toggle("dynamic-wallpaper-blur-disabled", !enabled);
    refresh();
  }

  function applySettings(options) {
    if (options && typeof options.enabled === "boolean") {
      setEnabled(options.enabled);
    }

    if (options && typeof options.blurEnabled === "boolean") {
      setBlur(options.blurEnabled);
    }
  }

  window.WallosDynamicWallpaper = {
    refresh: refresh,
    start: function () {
      setEnabled(true);
    },
    stop: function () {
      setEnabled(false);
    },
    setEnabled: setEnabled,
    setBlur: setBlur,
    applySettings: applySettings,
  };

  window.addEventListener("resize", function () {
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(function () {
      if (isEnabled()) {
        loadSelectedSource(false);
      }
    }, 140);
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", refresh, { once: true });
  } else {
    refresh();
  }
})();
