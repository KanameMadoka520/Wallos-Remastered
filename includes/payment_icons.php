<?php

if (!defined('WALLOS_PAYMENT_ICON_BUILTIN_PREFIX')) {
    define('WALLOS_PAYMENT_ICON_BUILTIN_PREFIX', 'images/uploads/icons/');
}

if (!defined('WALLOS_PAYMENT_ICON_UPLOAD_PREFIX')) {
    define('WALLOS_PAYMENT_ICON_UPLOAD_PREFIX', 'images/uploads/logos/');
}

function wallos_normalize_payment_icon_path($icon)
{
    $normalized = trim(str_replace('\\', '/', (string) $icon));
    if ($normalized === '') {
        return '';
    }

    if (preg_match('#^(?:https?:)?//#i', $normalized)) {
        return $normalized;
    }

    $normalized = ltrim($normalized, '/');

    while (strpos($normalized, WALLOS_PAYMENT_ICON_BUILTIN_PREFIX . WALLOS_PAYMENT_ICON_BUILTIN_PREFIX) === 0) {
        $normalized = substr($normalized, strlen(WALLOS_PAYMENT_ICON_BUILTIN_PREFIX));
    }

    if (strpos($normalized, WALLOS_PAYMENT_ICON_BUILTIN_PREFIX . WALLOS_PAYMENT_ICON_BUILTIN_PREFIX) !== false) {
        $normalized = str_replace(
            WALLOS_PAYMENT_ICON_BUILTIN_PREFIX . WALLOS_PAYMENT_ICON_BUILTIN_PREFIX,
            WALLOS_PAYMENT_ICON_BUILTIN_PREFIX,
            $normalized
        );
    }

    return $normalized;
}

function wallos_resolve_payment_icon_path($icon)
{
    $normalized = wallos_normalize_payment_icon_path($icon);
    if ($normalized === '') {
        return '';
    }

    if (
        preg_match('#^(?:https?:)?//#i', $normalized)
        || strpos($normalized, WALLOS_PAYMENT_ICON_BUILTIN_PREFIX) === 0
        || strpos($normalized, WALLOS_PAYMENT_ICON_UPLOAD_PREFIX) === 0
    ) {
        return $normalized;
    }

    return WALLOS_PAYMENT_ICON_UPLOAD_PREFIX . ltrim($normalized, '/');
}
