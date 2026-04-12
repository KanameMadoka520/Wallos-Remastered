(function () {
  function refresh() {
    var body = document.body;
    if (!body) {
      return;
    }

    var isCompact = window.innerWidth < 768;
    body.classList.toggle('decorative-background-compact', isCompact);
  }

  window.WallosDecorativeBackground = {
    refresh: refresh,
    start: refresh,
    stop: function () {},
  };

  var resizeTimer = 0;
  window.addEventListener('resize', function () {
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(refresh, 120);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refresh, { once: true });
  } else {
    refresh();
  }
})();
