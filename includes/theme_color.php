<?php

function wallos_normalize_theme_color_hex($color, $fallback = '#007BFF')
{
    $candidate = trim((string) $color);
    if ($candidate === '') {
        return strtoupper($fallback);
    }

    if ($candidate[0] !== '#') {
        $candidate = '#' . $candidate;
    }

    if (!preg_match('/^#([0-9a-fA-F]{6})$/', $candidate)) {
        return strtoupper($fallback);
    }

    return strtoupper($candidate);
}

function wallos_resolve_theme_color_value($theme, $colorTheme = 'blue', $dynamicWallpaperEnabled = false, array $customColors = [])
{
    if ($dynamicWallpaperEnabled) {
        return '#10161E';
    }

    if ($theme === 'dark') {
        return '#222222';
    }

    if (!empty($customColors['main_color'])) {
        return wallos_normalize_theme_color_hex($customColors['main_color']);
    }

    $palette = [
        'blue' => '#007BFF',
        'red' => '#F45A40',
        'green' => '#6B8E23',
        'yellow' => '#FFAE00',
        'purple' => '#6D4AFF',
    ];

    return $palette[$colorTheme] ?? $palette['blue'];
}
