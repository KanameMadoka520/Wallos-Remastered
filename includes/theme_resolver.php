<?php

function wallos_resolve_public_theme_preferences()
{
    $storedTheme = strtolower(trim((string) ($_COOKIE['theme'] ?? '')));
    $inUseTheme = strtolower(trim((string) ($_COOKIE['inUseTheme'] ?? '')));

    if ($storedTheme === 'automatic') {
        return [
            'theme' => $inUseTheme === 'dark' ? 'dark' : 'light',
            'update_theme_settings' => true,
        ];
    }

    if ($storedTheme === 'dark') {
        return [
            'theme' => 'dark',
            'update_theme_settings' => false,
        ];
    }

    return [
        'theme' => 'light',
        'update_theme_settings' => false,
    ];
}

function wallos_resolve_public_theme_cookie()
{
    $preferences = wallos_resolve_public_theme_preferences();
    return $preferences['theme'];
}

function wallos_public_theme_requires_live_update()
{
    $preferences = wallos_resolve_public_theme_preferences();
    return $preferences['update_theme_settings'];
}

function wallos_resolve_public_color_theme_cookie()
{
    $rawColorTheme = trim((string) ($_COOKIE['colorTheme'] ?? ''));
    $allowedThemes = ['blue', 'red', 'green', 'yellow', 'purple'];

    if (!in_array($rawColorTheme, $allowedThemes, true)) {
        $rawColorTheme = 'purple';
        setcookie('colorTheme', $rawColorTheme, [
            'expires' => time() + (365 * 24 * 60 * 60),
            'path' => '/',
            'samesite' => 'Lax',
        ]);
    }

    return $rawColorTheme;
}
