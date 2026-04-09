<?php

define('WALLOS_USER_GROUP_FREE', 'free');
define('WALLOS_USER_GROUP_TRUSTED', 'trusted');

function wallos_normalize_user_group($group)
{
    $group = strtolower(trim((string) $group));

    if ($group === WALLOS_USER_GROUP_TRUSTED) {
        return WALLOS_USER_GROUP_TRUSTED;
    }

    return WALLOS_USER_GROUP_FREE;
}

function wallos_get_effective_user_group($userGroup, $isAdmin = false)
{
    if ($isAdmin) {
        return 'admin';
    }

    return wallos_normalize_user_group($userGroup);
}

function wallos_can_upload_subscription_images($isAdmin, $userGroup)
{
    return $isAdmin || wallos_normalize_user_group($userGroup) === WALLOS_USER_GROUP_TRUSTED;
}

function wallos_get_user_group_label($userGroup, $i18n, $isAdmin = false)
{
    if ($isAdmin) {
        return translate('administrator_user_group', $i18n);
    }

    $normalizedGroup = wallos_normalize_user_group($userGroup);

    if ($normalizedGroup === WALLOS_USER_GROUP_TRUSTED) {
        return translate('trusted_user_group', $i18n);
    }

    return translate('free_user_group', $i18n);
}
