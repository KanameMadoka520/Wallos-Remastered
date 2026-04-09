<?php

$userColumns = [];
$userColumnsResult = $db->query("PRAGMA table_info(user)");
while ($userColumnsResult && ($row = $userColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $userColumns[] = $row['name'];
}

if (!in_array('user_group', $userColumns, true)) {
    $db->exec('ALTER TABLE user ADD COLUMN user_group TEXT DEFAULT "free"');
}

$db->exec("UPDATE user SET user_group = 'free' WHERE user_group IS NULL OR TRIM(user_group) = '' OR user_group NOT IN ('free', 'trusted')");

$subscriptionColumns = [];
$subscriptionColumnsResult = $db->query("PRAGMA table_info(subscriptions)");
while ($subscriptionColumnsResult && ($row = $subscriptionColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $subscriptionColumns[] = $row['name'];
}

if (!in_array('detail_image', $subscriptionColumns, true)) {
    $db->exec('ALTER TABLE subscriptions ADD COLUMN detail_image TEXT DEFAULT ""');
}

if (!in_array('detail_image_urls', $subscriptionColumns, true)) {
    $db->exec('ALTER TABLE subscriptions ADD COLUMN detail_image_urls TEXT DEFAULT "[]"');
}

$db->exec('UPDATE subscriptions SET detail_image = "" WHERE detail_image IS NULL');
$db->exec('UPDATE subscriptions SET detail_image_urls = "[]" WHERE detail_image_urls IS NULL OR TRIM(detail_image_urls) = ""');
