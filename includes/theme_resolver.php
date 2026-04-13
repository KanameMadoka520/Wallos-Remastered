<?php

function wallos_resolve_public_theme_cookie()
{
    $rawTheme = strtolower(trim((string) ($_COOKIE['theme'] ?? '')));

    if ($rawTheme === 'dark') {
        return 'dark';
    }

    return 'light';
}

function wallos_resolve_public_color_theme_cookie()
{
    $rawColorTheme = trim((string) ($_COOKIE['colorTheme'] ?? ''));
    return $rawColorTheme !== '' ? $rawColorTheme : 'purple';
}
