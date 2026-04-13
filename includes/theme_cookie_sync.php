<?php

function wallos_map_dark_theme_to_cookie_value($darkTheme)
{
    $themeValue = (int) $darkTheme;

    if ($themeValue === 1) {
        return 'dark';
    }

    if ($themeValue === 0) {
        return 'light';
    }

    return 'automatic';
}

function wallos_fetch_theme_cookie_settings($db, $userId)
{
    $stmt = $db->prepare('SELECT dark_theme, color_theme, decorative_background, dynamic_wallpaper, dynamic_wallpaper_blur FROM settings WHERE user_id = :userId');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($result === false) {
        return null;
    }

    $settings = $result->fetchArray(SQLITE3_ASSOC);

    return $settings !== false ? $settings : null;
}

function wallos_sync_theme_cookies_from_settings_row(array $settings, $cookieExpire)
{
    $themeValue = wallos_map_dark_theme_to_cookie_value($settings['dark_theme'] ?? 2);
    $colorTheme = trim((string) ($settings['color_theme'] ?? ''));
    if ($colorTheme === '') {
        $colorTheme = 'purple';
    }

    $decorativeBackgroundEnabled = !isset($settings['decorative_background']) || (int) $settings['decorative_background'] === 1;
    $dynamicWallpaperEnabled = !empty($settings['dynamic_wallpaper']);
    $dynamicWallpaperBlurEnabled = !isset($settings['dynamic_wallpaper_blur']) || (int) $settings['dynamic_wallpaper_blur'] === 1;

    $cookieOptions = [
        'expires' => $cookieExpire,
        'path' => '/',
        'samesite' => 'Lax',
    ];

    setcookie('theme', $themeValue, $cookieOptions);
    setcookie('colorTheme', $colorTheme, $cookieOptions);
    setcookie('decorativeBackground', $decorativeBackgroundEnabled ? '1' : '0', $cookieOptions);
    setcookie('dynamicWallpaper', $dynamicWallpaperEnabled ? '1' : '0', $cookieOptions);
    setcookie('dynamicWallpaperBlur', $dynamicWallpaperBlurEnabled ? '1' : '0', $cookieOptions);
}

function wallos_sync_theme_cookies_for_user($db, $userId, $cookieExpire)
{
    $settings = wallos_fetch_theme_cookie_settings($db, $userId);

    if ($settings === null) {
        return null;
    }

    wallos_sync_theme_cookies_from_settings_row($settings, $cookieExpire);

    return $settings;
}

function wallos_apply_public_theme_view_settings_from_row(array $settings, &$theme, &$updateThemeSettings, &$colorTheme)
{
    $themeValue = wallos_map_dark_theme_to_cookie_value($settings['dark_theme'] ?? 2);

    if ($themeValue === 'automatic') {
        $updateThemeSettings = true;
    } else {
        $theme = $themeValue;
        $updateThemeSettings = false;
    }

    $resolvedColorTheme = trim((string) ($settings['color_theme'] ?? ''));
    $colorTheme = $resolvedColorTheme !== '' ? $resolvedColorTheme : 'purple';
}
