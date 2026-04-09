<?php

define('WALLOS_USER_STATUS_ACTIVE', 'active');
define('WALLOS_USER_STATUS_TRASHED', 'trashed');
define('WALLOS_TRASH_RETENTION_DAYS', 30);

function wallos_normalize_user_status($status)
{
    $status = strtolower(trim((string) $status));

    if ($status === WALLOS_USER_STATUS_TRASHED) {
        return WALLOS_USER_STATUS_TRASHED;
    }

    return WALLOS_USER_STATUS_ACTIVE;
}

function wallos_is_user_trashed($status)
{
    return wallos_normalize_user_status($status) === WALLOS_USER_STATUS_TRASHED;
}

function wallos_calculate_scheduled_delete_at($trashedAt = null)
{
    $timestamp = $trashedAt ? strtotime((string) $trashedAt) : time();
    if ($timestamp === false) {
        $timestamp = time();
    }

    return date('Y-m-d H:i:s', strtotime('+' . WALLOS_TRASH_RETENTION_DAYS . ' days', $timestamp));
}

function wallos_prepare_trashed_login_redirect_query(array $user)
{
    $reason = trim((string) ($user['trash_reason'] ?? ''));
    $scheduledDeleteAt = trim((string) ($user['scheduled_delete_at'] ?? ''));

    return http_build_query([
        'error' => 'account_trashed',
        'reason' => $reason,
        'scheduled_delete_at' => $scheduledDeleteAt,
    ]);
}

