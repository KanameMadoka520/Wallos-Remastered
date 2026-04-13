<?php

require_once __DIR__ . '/custom_edition.php';

function wallos_normalize_subscription_display_columns_setting($value)
{
    $columns = (int) $value;
    return in_array($columns, [1, 2, 3], true) ? $columns : 1;
}

function wallos_normalize_subscription_image_layout_setting($value)
{
    $mode = trim((string) $value);
    return in_array($mode, ['focus', 'grid'], true) ? $mode : 'focus';
}

function wallos_normalize_page_transition_style_setting($value)
{
    $style = trim((string) $value);
    return in_array($style, ['shutter', 'bluearchive', 'bluearchive_theme'], true) ? $style : 'shutter';
}

function wallos_normalize_subscription_value_visibility_setting($value)
{
    $decoded = json_decode((string) $value, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    $legacyMetricsVisible = !(
        array_key_exists('invested', $decoded)
        && $decoded['invested'] === false
        && array_key_exists('remaining', $decoded)
        && $decoded['remaining'] === false
        && array_key_exists('used', $decoded)
        && $decoded['used'] === false
    );

    return [
        'metrics' => array_key_exists('metrics', $decoded) ? (bool) $decoded['metrics'] : $legacyMetricsVisible,
        'payment_records' => array_key_exists('payment_records', $decoded) ? (bool) $decoded['payment_records'] : true,
    ];
}

$query = "SELECT * FROM settings WHERE user_id = :userId";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();

$settings = $result->fetchArray(SQLITE3_ASSOC);
if ($settings !== false) {
    $themeMapping = array(0 => 'light', 1 => 'dark', 2 => 'automatic');
    $themeKey = isset($settings['dark_theme']) ? $settings['dark_theme'] : 2;
    $themeValue = $themeMapping[$themeKey];
    $settings['update_theme_setttings'] = false;
    if (isset($_COOKIE['inUseTheme']) && $settings['dark_theme'] == 2) {
        $inUseTheme = $_COOKIE['inUseTheme'];
        $settings['theme'] = $inUseTheme;
    } else {
        $settings['theme'] = $themeValue;
    }
    if ($themeValue == "automatic") {
        $settings['update_theme_setttings'] = true;
    }
    $settings['color_theme'] = $settings['color_theme'] ? $settings['color_theme'] : "purple";
    $settings['showMonthlyPrice'] = $settings['monthly_price'] ? 'true': 'false';
    $settings['convertCurrency'] = $settings['convert_currency'] ? 'true': 'false';
    $settings['removeBackground'] = $settings['remove_background'] ? 'true': 'false';
    $settings['hideDisabledSubscriptions'] = $settings['hide_disabled'] ? 'true': 'false';
    $settings['disabledToBottom'] = $settings['disabled_to_bottom'] ? 'true': 'false';
    $settings['showOriginalPrice'] = $settings['show_original_price'] ? 'true': 'false';
    $settings['mobileNavigation'] = $settings['mobile_nav'] ? 'true': 'false';
    $settings['showSubscriptionProgress'] = $settings['show_subscription_progress'] ? 'true': 'false';
    $settings['decorativeBackground'] = !isset($settings['decorative_background']) || $settings['decorative_background'] ? 'true' : 'false';
    $settings['dynamicWallpaper'] = !empty($settings['dynamic_wallpaper']) ? 'true' : 'false';
    $settings['dynamicWallpaperBlur'] = !isset($settings['dynamic_wallpaper_blur']) || $settings['dynamic_wallpaper_blur'] ? 'true' : 'false';
    $settings['pageTransitionEnabled'] = !isset($settings['page_transition_enabled']) || (int) $settings['page_transition_enabled'] === 1;
    $settings['pageTransitionStyle'] = wallos_normalize_page_transition_style_setting($settings['page_transition_style'] ?? 'shutter');
    $settings['subscriptionDisplayColumns'] = wallos_normalize_subscription_display_columns_setting($settings['subscription_display_columns'] ?? 1);
    $settings['subscriptionValueVisibility'] = wallos_normalize_subscription_value_visibility_setting($settings['subscription_value_visibility'] ?? '');
    $settings['subscriptionImageLayoutForm'] = wallos_normalize_subscription_image_layout_setting($settings['subscription_image_layout_form'] ?? 'focus');
    $settings['subscriptionImageLayoutDetail'] = wallos_normalize_subscription_image_layout_setting($settings['subscription_image_layout_detail'] ?? 'focus');
}

$query = "SELECT * FROM custom_colors WHERE user_id = :userId";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$customColors = $result->fetchArray(SQLITE3_ASSOC);

if ($customColors !== false) {
    $settings['customColors'] = $customColors;
}

$query = "SELECT * FROM custom_css_style WHERE user_id = :userId";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$customCss = $result->fetchArray(SQLITE3_ASSOC);
if ($customCss !== false) {
    $settings['customCss'] = $customCss['css'];
}

$query = "SELECT * FROM admin";
$result = $db->query($query);
$adminSettings = $result->fetchArray(SQLITE3_ASSOC);

if ($adminSettings !== false) {
    $settings['disableLogin'] = $adminSettings['login_disabled'];
    $settings['update_notification'] = $adminSettings['update_notification'];
    $settings['latest_version'] = $adminSettings['latest_version'];
    $settings['custom_edition_title'] = wallos_normalize_custom_edition_value(
        $adminSettings['custom_edition_title'] ?? '',
        'Remastered'
    );
    $settings['custom_edition_subtitle'] = wallos_normalize_custom_edition_value(
        $adminSettings['custom_edition_subtitle'] ?? '',
        '基于wallos原版深度魔改'
    );
}

?>
