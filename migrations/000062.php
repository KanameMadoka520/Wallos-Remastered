<?php

$settingsColumnsResult = $db->query("PRAGMA table_info('settings')");
$settingsColumns = [];
while ($settingsColumnsResult && ($column = $settingsColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $settingsColumns[] = $column['name'];
}

if (!in_array('subscription_display_columns', $settingsColumns, true)) {
    $db->exec('ALTER TABLE settings ADD COLUMN subscription_display_columns INTEGER DEFAULT 1');
}

if (!in_array('subscription_value_visibility', $settingsColumns, true)) {
    $db->exec('ALTER TABLE settings ADD COLUMN subscription_value_visibility TEXT DEFAULT \'{"invested":true,"remaining":true,"used":true}\'');
}

if (!in_array('subscription_image_layout_form', $settingsColumns, true)) {
    $db->exec("ALTER TABLE settings ADD COLUMN subscription_image_layout_form TEXT DEFAULT 'focus'");
}

if (!in_array('subscription_image_layout_detail', $settingsColumns, true)) {
    $db->exec("ALTER TABLE settings ADD COLUMN subscription_image_layout_detail TEXT DEFAULT 'focus'");
}

$db->exec('UPDATE settings SET subscription_display_columns = 1 WHERE subscription_display_columns IS NULL OR subscription_display_columns NOT IN (1, 2, 3)');
$db->exec('UPDATE settings SET subscription_value_visibility = \'{"invested":true,"remaining":true,"used":true}\' WHERE subscription_value_visibility IS NULL OR TRIM(subscription_value_visibility) = \'\'');
$db->exec("UPDATE settings SET subscription_image_layout_form = 'focus' WHERE subscription_image_layout_form IS NULL OR subscription_image_layout_form NOT IN ('focus', 'grid')");
$db->exec("UPDATE settings SET subscription_image_layout_detail = 'focus' WHERE subscription_image_layout_detail IS NULL OR subscription_image_layout_detail NOT IN ('focus', 'grid')");
