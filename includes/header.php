<?php
require_once 'connect.php';
require_once 'checkuser.php';
require_once 'checksession.php';
require_once 'checkredirect.php';
require_once 'currency_formatter.php';

require_once 'libs/csrf.php';

require_once 'i18n/languages.php';
require_once 'i18n/getlang.php';
require_once 'i18n/' . $lang . '.php';

require_once 'getsettings.php';
require_once 'screenshot_privacy.php';
require_once 'decorative_background.php';
require_once 'dynamic_wallpaper.php';
require_once 'page_transitions.php';
require_once 'theme_color.php';
require_once 'cache_refresh.php';

require_once 'version.php';

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$pageTransitionTitle = wallos_resolve_page_transition_title($currentPage, $i18n);
$pageTransitionSceneRoutes = wallos_get_page_transition_scene_routes();
$pageTransitionCurrentRoute = wallos_normalize_page_transition_route($currentPage);
$pageTransitionCurrentScene = wallos_resolve_page_transition_scene($currentPage);

$stylesCssVersion = $version . '.' . @filemtime(__DIR__ . '/../styles/styles.css');
$decorativeBackgroundCssVersion = $version . '.' . @filemtime(__DIR__ . '/../styles/decorative-background.css');
$dynamicWallpaperCssVersion = $version . '.' . @filemtime(__DIR__ . '/../styles/dynamic-wallpaper.css');
$pageTransitionsCssVersion = $version . '.' . @filemtime(__DIR__ . '/../styles/page-transitions.css');
$screenshotPrivacyCssVersion = $version . '.' . @filemtime(__DIR__ . '/../styles/screenshot-privacy.css');
$decorativeBackgroundJsVersion = $version . '.' . @filemtime(__DIR__ . '/../scripts/decorative-background.js');
$dynamicWallpaperJsVersion = $version . '.' . @filemtime(__DIR__ . '/../scripts/dynamic-wallpaper.js');
$pageTransitionsJsVersion = $version . '.' . @filemtime(__DIR__ . '/../scripts/page-transitions.js');
$screenshotPrivacyJsVersion = $version . '.' . @filemtime(__DIR__ . '/../scripts/screenshot-privacy.js');
$i18nEnglishJsVersion = $version . '.' . @filemtime(__DIR__ . '/../scripts/i18n/en.js');
$i18nJsVersion = $version . '.' . @filemtime(__DIR__ . '/../scripts/i18n/' . $lang . '.js');
$i18nGetLangJsVersion = $version . '.' . @filemtime(__DIR__ . '/../scripts/i18n/getlang.js');
$apiJsVersion = $version . '.' . @filemtime(__DIR__ . '/../scripts/api.js');
$allJsVersion = $version . '.' . @filemtime(__DIR__ . '/../scripts/all.js');
$commonJsVersion = $version . '.' . @filemtime(__DIR__ . '/../scripts/common.js');
$serviceWorkerJsVersion = $version . '.' . @filemtime(__DIR__ . '/../service-worker.js');
$themeCssVersion = $version . '.' . @filemtime(__DIR__ . '/../styles/theme.css');
$darkThemeCssVersion = $version . '.' . @filemtime(__DIR__ . '/../styles/dark-theme.css');
$redThemeCssVersion = $version . '.' . @filemtime(__DIR__ . '/../styles/themes/red.css');
$greenThemeCssVersion = $version . '.' . @filemtime(__DIR__ . '/../styles/themes/green.css');
$yellowThemeCssVersion = $version . '.' . @filemtime(__DIR__ . '/../styles/themes/yellow.css');
$purpleThemeCssVersion = $version . '.' . @filemtime(__DIR__ . '/../styles/themes/purple.css');
$barlowCssVersion = $version . '.' . @filemtime(__DIR__ . '/../styles/barlow.css');
$fontAwesomeCssVersion = $version . '.' . @filemtime(__DIR__ . '/../styles/font-awesome.min.css');
$cacheRefreshMarker = wallos_read_cache_refresh_marker(__DIR__ . '/..');

if ($userCount == 0) {
  $db->close();
  header("Location: registration.php");
  exit();
}

$demoMode = getenv('DEMO_MODE');

$theme = "automatic";
if (isset($settings['theme'])) {
  $theme = $settings['theme'];
}

$updateThemeSettings = false;
if (isset($settings['update_theme_setttings'])) {
  $updateThemeSettings = $settings['update_theme_setttings'];
}

$colorTheme = "purple";
if (isset($settings['color_theme'])) {
  $colorTheme = $settings['color_theme'];
}

$customCss = "";
if (isset($settings['customCss'])) {
  $customCss = $settings['customCss'];
}

$cookieExpire = time() + (30 * 24 * 60 * 60);
if (isset($themeValue)) {
  setcookie('theme', $themeValue, [
    'expires' => $cookieExpire,
    'path' => '/',
    'samesite' => 'Lax'
  ]);
}

$isAdmin = $_SESSION['userId'] == 1;

$locale = isset($_COOKIE['user_locale']) ? $_COOKIE['user_locale'] : 'en_US';
$formatter = new IntlDateFormatter(
  $locale, 
  IntlDateFormatter::MEDIUM,
  IntlDateFormatter::NONE
);

function hex2rgb($hex)
{
  $hex = str_replace("#", "", $hex);
  if (strlen($hex) == 3) {
    $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
    $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
    $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
  } else {
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
  }
  return "$r, $g, $b";
}

$mobileNavigation = $settings['mobile_nav'] ? "mobile-navigation" : "";
$decorativeBackgroundEnabled = !isset($settings['decorative_background']) || (int) $settings['decorative_background'] === 1;
$decorativeBackgroundClass = $decorativeBackgroundEnabled ? "decorative-background-enabled" : "decorative-background-disabled";
$dynamicWallpaperEnabled = !empty($settings['dynamic_wallpaper']);
$dynamicWallpaperClass = $dynamicWallpaperEnabled ? "dynamic-wallpaper-enabled" : "dynamic-wallpaper-disabled";
$dynamicWallpaperBlurEnabled = !isset($settings['dynamic_wallpaper_blur']) || (int) $settings['dynamic_wallpaper_blur'] === 1;
$dynamicWallpaperBlurClass = $dynamicWallpaperBlurEnabled ? "dynamic-wallpaper-blur-enabled" : "dynamic-wallpaper-blur-disabled";
$pageTransitionEnabled = !empty($settings['pageTransitionEnabled']);
$pageTransitionStyle = $settings['pageTransitionStyle'] ?? 'shutter';
$screenshotPrivacyEnabled = wallos_screenshot_privacy_enabled($settings);
$screenshotPrivacySeed = $screenshotPrivacyEnabled ? wallos_screenshot_privacy_seed() : '';
$screenshotPrivacyClientSeed = $screenshotPrivacyEnabled
  ? hash_hmac('sha256', 'browser-display', $screenshotPrivacySeed)
  : '';
$metaThemeColor = wallos_resolve_theme_color_value(
  $theme,
  $colorTheme,
  $dynamicWallpaperEnabled,
  $settings['customColors'] ?? []
);
setcookie('decorativeBackground', $decorativeBackgroundEnabled ? '1' : '0', [
  'expires' => $cookieExpire,
  'path' => '/',
  'samesite' => 'Lax'
]);
setcookie('colorTheme', $colorTheme, [
  'expires' => $cookieExpire,
  'path' => '/',
  'samesite' => 'Lax'
]);
setcookie('dynamicWallpaper', $dynamicWallpaperEnabled ? '1' : '0', [
  'expires' => $cookieExpire,
  'path' => '/',
  'samesite' => 'Lax'
]);
setcookie('dynamicWallpaperBlur', $dynamicWallpaperBlurEnabled ? '1' : '0', [
  'expires' => $cookieExpire,
  'path' => '/',
  'samesite' => 'Lax'
]);
setcookie('wallosScreenshotPrivacy', $screenshotPrivacyEnabled ? '1' : '0', [
  'expires' => $cookieExpire,
  'path' => '/',
  'samesite' => 'Lax'
]);

?>
<!DOCTYPE html>
<html
  dir="<?= $languages[$lang]['dir'] ?>"
  class="<?= $screenshotPrivacyEnabled ? 'wallos-screenshot-privacy-enabled' : '' ?>"
  data-page-transition-style="<?= htmlspecialchars($pageTransitionStyle, ENT_QUOTES, 'UTF-8') ?>"
  data-page-transition-scene="<?= htmlspecialchars($pageTransitionCurrentScene, ENT_QUOTES, 'UTF-8') ?>"
>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Wallos - Subscription Tracker</title>
  <meta name="apple-mobile-web-app-title" content="Wallos">
  <meta name="theme-color" content="<?= htmlspecialchars($metaThemeColor, ENT_QUOTES, 'UTF-8') ?>" id="theme-color" />
  <meta name="referrer" content="no-referrer">
  <script>
    (function () {
      const html = document.documentElement;
      const contextKey = 'wallos-page-transition-context';
      const transitionEnabled = <?= $pageTransitionEnabled ? 'true' : 'false' ?>;
      const transitionStyle = <?= json_encode($pageTransitionStyle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const transitionPageTitle = <?= json_encode($pageTransitionTitle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const transitionSceneRoutes = Object.freeze(<?= json_encode($pageTransitionSceneRoutes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
      const transitionCurrentRoute = <?= json_encode($pageTransitionCurrentRoute, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const transitionCurrentScene = <?= json_encode($pageTransitionCurrentScene, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const supportedScenes = new Set([...Object.values(transitionSceneRoutes), 'generic']);
      let transitionContext = null;

      window.pageTransitionEnabled = transitionEnabled;
      window.pageTransitionStyle = transitionStyle;
      window.pageTransitionTitle = transitionPageTitle;
      window.pageTransitionCurrentRoute = transitionCurrentRoute;
      window.pageTransitionCurrentScene = transitionCurrentScene;
      window.pageTransitionScene = transitionCurrentScene;
      window.WallosPageTransitionSceneRoutes = transitionSceneRoutes;
      html.dataset.pageTransitionStyle = transitionStyle;
      html.dataset.pageTransitionScene = transitionCurrentScene;

      if (!transitionEnabled) {
        try {
          window.sessionStorage.removeItem(contextKey);
        } catch (error) {
          // Ignore sessionStorage cleanup failures.
        }
        return;
      }

      try {
        const rawContext = window.sessionStorage.getItem(contextKey);
        window.sessionStorage.removeItem(contextKey);
        if (rawContext) {
          const parsedContext = JSON.parse(rawContext);
          const expectedScene = transitionSceneRoutes[transitionCurrentRoute] || 'generic';
          const contextIsValid = parsedContext
            && parsedContext.active
            && String(parsedContext.route || '') === transitionCurrentRoute
            && String(parsedContext.scene || '') === expectedScene
            && supportedScenes.has(String(parsedContext.scene || ''))
            && (Date.now() - Number(parsedContext.timestamp || 0)) < 4000;
          if (contextIsValid) {
            transitionContext = parsedContext;
            window.__wallosPageTransitionContext = parsedContext;
          }
        }
      } catch (error) {
        transitionContext = null;
      }

      html.classList.add('wallos-page-transition-enabled', 'wallos-page-transition-loading');
      if (transitionContext) {
        html.dataset.pageTransitionScene = transitionContext.scene;
        window.pageTransitionScene = transitionContext.scene;
        html.classList.add('wallos-page-transition-resume');
      } else {
        html.classList.add('wallos-page-transition-initial');
      }
    })();
  </script>
  <link rel="icon" type="image/png" href="images/icon/favicon.ico" sizes="16x16">
  <link rel="apple-touch-icon" href="images/icon/apple-touch-icon.png">
  <link rel="apple-touch-icon" sizes="152x152" href="images/icon/apple-touch-icon-152.png">
  <link rel="apple-touch-icon" sizes="180x180" href="images/icon/apple-touch-icon-180.png">
  <link rel="manifest" href="manifest.json" crossorigin="use-credentials">
  <link rel="stylesheet" href="styles/theme.css?v=<?= $themeCssVersion ?>">
  <link rel="stylesheet" href="styles/decorative-background.css?v=<?= $decorativeBackgroundCssVersion ?>">
  <link rel="stylesheet" href="styles/dynamic-wallpaper.css?v=<?= $dynamicWallpaperCssVersion ?>">
  <link rel="stylesheet" href="styles/page-transitions.css?v=<?= $pageTransitionsCssVersion ?>">
  <link rel="stylesheet" href="styles/styles.css?v=<?= $stylesCssVersion ?>">
  <link rel="stylesheet" href="styles/dark-theme.css?v=<?= $darkThemeCssVersion ?>" id="dark-theme" <?= $theme != "dark" ? "disabled" : "" ?>>
  <link rel="stylesheet" href="styles/themes/red.css?v=<?= $redThemeCssVersion ?>" id="red-theme" <?= $colorTheme != "red" ? "disabled" : "" ?>>
  <link rel="stylesheet" href="styles/themes/green.css?v=<?= $greenThemeCssVersion ?>" id="green-theme" <?= $colorTheme != "green" ? "disabled" : "" ?>>
  <link rel="stylesheet" href="styles/themes/yellow.css?v=<?= $yellowThemeCssVersion ?>" id="yellow-theme" <?= $colorTheme != "yellow" ? "disabled" : "" ?>>
  <link rel="stylesheet" href="styles/themes/purple.css?v=<?= $purpleThemeCssVersion ?>" id="purple-theme" <?= $colorTheme != "purple" ? "disabled" : "" ?>>
  <link rel="stylesheet" href="styles/barlow.css?v=<?= $barlowCssVersion ?>">
  <link rel="stylesheet" href="styles/font-awesome.min.css?v=<?= $fontAwesomeCssVersion ?>">
  <script>
    window.WallosServiceWorkerUrl = "service-worker.js?v=<?= $serviceWorkerJsVersion ?>";
  </script>
  <script defer type="text/javascript" src="scripts/all.js?v=<?= $allJsVersion ?>"></script>
  <script defer type="text/javascript" src="scripts/common.js?v=<?= $commonJsVersion ?>"></script>
  <script defer type="text/javascript" src="scripts/decorative-background.js?v=<?= $decorativeBackgroundJsVersion ?>"></script>
  <script defer type="text/javascript" src="scripts/dynamic-wallpaper.js?v=<?= $dynamicWallpaperJsVersion ?>"></script>
  <script defer type="text/javascript" src="scripts/page-transitions.js?v=<?= $pageTransitionsJsVersion ?>"></script>
  <script defer type="text/javascript" src="scripts/screenshot-privacy.js?v=<?= $screenshotPrivacyJsVersion ?>"></script>
  <script type="text/javascript">
    window.theme = "<?= $theme ?>";
    window.update_theme_settings = "<?= $updateThemeSettings ?>";
    window.lang = "<?= $lang ?>";
    window.colorTheme = "<?= $colorTheme ?>";
    window.mobileNavigation = "<?= $settings['mobileNavigation'] == "true" ?>";
    window.dynamicWallpaperEnabled = <?= $dynamicWallpaperEnabled ? 'true' : 'false' ?>;
    window.dynamicWallpaperBlurEnabled = <?= $dynamicWallpaperBlurEnabled ? 'true' : 'false' ?>;
    window.WallosScreenshotPrivacyConfig = {
      enabled: <?= $screenshotPrivacyEnabled ? 'true' : 'false' ?>,
      seed: <?= json_encode($screenshotPrivacyClientSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      blockedMessage: <?= json_encode(translate('screenshot_privacy_mode_blocked', $i18n), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };
    window.WallosDynamicWallpaperConfig = {
      breakpoint: 768,
      desktopIndex: 3,
      mobileIndex: 1,
      sources: <?= json_encode(wallos_get_dynamic_wallpaper_sources(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };
    window.WallosCacheRefresh = <?= json_encode($cacheRefreshMarker, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.csrfToken = "<?= htmlspecialchars(generate_csrf_token()) ?>";
  </script>
  <style>
    <?= htmlspecialchars($customCss, ENT_QUOTES, 'UTF-8') ?>
  </style>
  <?php
  if (isset($settings['customColors'])) {
    ?>
    <style id="custom_theme_colors">
      :root {
        <?php if (isset($settings['customColors']['main_color']) && !empty($settings['customColors']['main_color'])): ?>
          --main-color:
            <?= $settings['customColors']['main_color'] ?>
          ;
          --main-color-rgb:
            <?= hex2rgb($settings['customColors']['main_color']) ?>
          ;
        <?php endif; ?>
        <?php if (isset($settings['customColors']['accent_color']) && !empty($settings['customColors']['accent_color'])): ?>
          --accent-color:
            <?= $settings['customColors']['accent_color'] ?>
          ;
          --accent-color-rgb:
            <?= hex2rgb($settings['customColors']['accent_color']) ?>
          ;
        <?php endif; ?>
        <?php if (isset($settings['customColors']['hover_color']) && !empty($settings['customColors']['hover_color'])): ?>
          --hover-color:
            <?= $settings['customColors']['hover_color'] ?>
          ;
          --hover-color-rgb:
            <?= hex2rgb($settings['customColors']['hover_color']) ?>
          ;
        <?php endif; ?>
        <?php if (isset($settings['customColors']['text_color']) && !empty($settings['customColors']['text_color'])): ?>
          --wallos-dynamic-text-color:
            <?= $settings['customColors']['text_color'] ?>
          ;
          --wallos-dynamic-text-color-rgb:
            <?= hex2rgb($settings['customColors']['text_color']) ?>
          ;
        <?php endif; ?>
      }
    </style>
    <?php
  }
  ?>
  <link rel="stylesheet" href="styles/screenshot-privacy.css?v=<?= $screenshotPrivacyCssVersion ?>">
  <script defer type="text/javascript" src="scripts/i18n/en.js?v=<?= $i18nEnglishJsVersion ?>"></script>
  <?php if ($lang !== 'en'): ?>
  <script defer type="text/javascript" src="scripts/i18n/<?= $lang ?>.js?v=<?= $i18nJsVersion ?>"></script>
  <?php endif; ?>
  <script defer type="text/javascript" src="scripts/i18n/getlang.js?v=<?= $i18nGetLangJsVersion ?>"></script>
  <script defer type="text/javascript" src="scripts/api.js?v=<?= $apiJsVersion ?>"></script>
</head>

<body class="<?= $theme ?> <?= $languages[$lang]['dir'] ?> <?= $mobileNavigation ?> <?= $decorativeBackgroundClass ?> <?= $dynamicWallpaperClass ?> <?= $dynamicWallpaperBlurClass ?>">
  <?php wallos_render_dynamic_wallpaper(); ?>
  <?php wallos_render_decorative_background('app'); ?>
  <?php wallos_render_page_transition_overlay($lang, $pageTransitionTitle); ?>
  <header>
    <div class="contain">
      <div class="logo">
        <a href="." class="wallos-header-brand" aria-label="Wallos Remastered"
          data-transition-label="<?= htmlspecialchars(translate('dashboard', $i18n), ENT_QUOTES, 'UTF-8') ?>">
          <div class="logo-image" title="Wallos - Subscription Tracker">
            <?php include "images/siteicons/svg/logo.php"; ?>
          </div>
          <span class="wallos-header-edition" lang="en" dir="ltr" aria-hidden="true">[Remastered]</span>
        </a>
      </div>
      <nav>
        <div class="dropdown">
          <button class="dropbtn" onClick="toggleDropdown()">
            <img src="<?= htmlspecialchars($userData['avatar'], ENT_QUOTES, 'UTF-8') ?>" alt="me" id="avatar">
            <span id="user" class="mobileNavigationHideOnMobile"><?= $userData['username'] ?></span>
          </button>
          <div class="dropdown-content">
            <a href="." class="mobileNavigationHideOnMobile">
              <?php include "images/siteicons/svg/mobile-menu/home.php"; ?>
              <?= translate('dashboard', $i18n) ?></a>
            <a href="subscriptions.php" class="mobileNavigationHideOnMobile">
              <?php include "images/siteicons/svg/mobile-menu/subscriptions.php"; ?>
              <?= translate('subscriptions', $i18n) ?></a>  
            <a href="calendar.php" class="mobileNavigationHideOnMobile">
                <?php include "images/siteicons/svg/mobile-menu/calendar.php"; ?>
                <?= translate('calendar', $i18n) ?></a>
            <a href="stats.php" class="mobileNavigationHideOnMobile">
              <?php include "images/siteicons/svg/mobile-menu/statistics.php"; ?>
              <?= translate('stats', $i18n) ?></a>
            <a href="settings.php" class="mobileNavigationHideOnMobile">
              <?php include "images/siteicons/svg/mobile-menu/settings.php"; ?>
              <?= translate('settings', $i18n) ?></a>
            <a href="profile.php">
              <?php include "images/siteicons/svg/mobile-menu/profile.php"; ?>
              <?= translate('profile', $i18n) ?></a>  
            <?php if ($isAdmin): ?>
              <a href="admin.php">
                <?php include "images/siteicons/svg/mobile-menu/admin.php"; ?>
                <?= translate('admin', $i18n) ?>
              </a>
            <?php endif; ?>
            <a href="about.php">
              <?php include "images/siteicons/svg/mobile-menu/about.php"; ?>
              <?= translate('about', $i18n) ?>
            </a>
            <?php
            if ($settings['disableLogin'] == 0) {
              ?>
              <a href="logout.php">
                <?php include "images/siteicons/svg/mobile-menu/logout.php"; ?>
                <?= translate('logout', $i18n) ?></a>
              <?php
            }
            ?>
          </div>
        </div>
      </nav>
    </div>
  </header>

  <?php
  // find out which page is being viewed
  $page = basename($_SERVER['PHP_SELF']);
  $dashboardClass = $page === 'index.php' ? 'active' : '';
  $subscriptionsClass = $page === 'subscriptions.php' ? 'active' : '';
  $calendarClass = $page === 'calendar.php' ? 'active' : '';
  $statsClass = $page === 'stats.php' ? 'active' : '';
  $settingsClass = $page === 'settings.php' ? 'active' : '';
  $profileClass = $page === 'profile.php' ? 'active' : '';
  ?>

  <?php
  if ($settings['mobile_nav'] == 1) {
    ?>
    <nav class="mobile-nav">
        <a href="." class="nav-link <?= $dashboardClass ?>" title="<?= translate('dashboard', $i18n) ?>">
          <?php include "images/siteicons/svg/mobile-menu/home.php"; ?>
          <?= translate('dashboard', $i18n) ?>
        </a>
        <a href="subscriptions.php" class="nav-link <?= $subscriptionsClass ?>" title="<?= translate('subscriptions', $i18n) ?>">
          <?php include "images/siteicons/svg/mobile-menu/subscriptions.php"; ?>
          <?= translate('subscriptions', $i18n) ?>
        </a>
        <a href="calendar.php" class="nav-link <?= $calendarClass ?>" title="<?= translate('calendar', $i18n) ?>">
          <?php include "images/siteicons/svg/mobile-menu/calendar.php"; ?>
          <?= translate('calendar', $i18n) ?>
        </a>
        <a href="stats.php" class="nav-link <?= $statsClass ?>" title="<?= translate('stats', $i18n) ?>">
          <?php include "images/siteicons/svg/mobile-menu/statistics.php"; ?>
          <?= translate('stats', $i18n) ?>
        </a>
        <a href="settings.php" class="nav-link <?= $settingsClass ?>" title="<?= translate('settings', $i18n) ?>">
          <?php include "images/siteicons/svg/mobile-menu/settings.php"; ?>
          <?= translate('settings', $i18n) ?>
        </a>
    </nav>
    <?php
  }
  ?>
  <main>
