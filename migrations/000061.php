<?php

$adminColumns = [];
$adminColumnsResult = $db->query("PRAGMA table_info(admin)");
while ($adminColumnsResult && ($row = $adminColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $adminColumns[] = $row['name'];
}

if (!in_array('custom_edition_title', $adminColumns, true)) {
    $db->exec("ALTER TABLE admin ADD COLUMN custom_edition_title TEXT DEFAULT 'Remastered'");
}

if (!in_array('custom_edition_subtitle', $adminColumns, true)) {
    $db->exec("ALTER TABLE admin ADD COLUMN custom_edition_subtitle TEXT DEFAULT '基于wallos原版深度魔改'");
}

$db->exec("UPDATE admin SET custom_edition_title = 'Remastered' WHERE custom_edition_title IS NULL OR TRIM(custom_edition_title) = ''");
$db->exec("UPDATE admin SET custom_edition_subtitle = '基于wallos原版深度魔改' WHERE custom_edition_subtitle IS NULL OR TRIM(custom_edition_subtitle) = ''");
