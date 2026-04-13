<?php

$settingsColumns = [];
$settingsColumnsResult = $db->query("PRAGMA table_info(settings)");
while ($settingsColumnsResult && ($row = $settingsColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $settingsColumns[] = $row['name'];
}

if (!in_array('dynamic_wallpaper', $settingsColumns, true)) {
    $db->exec('ALTER TABLE settings ADD COLUMN dynamic_wallpaper BOOLEAN DEFAULT 0');
}

if (!in_array('dynamic_wallpaper_blur', $settingsColumns, true)) {
    $db->exec('ALTER TABLE settings ADD COLUMN dynamic_wallpaper_blur BOOLEAN DEFAULT 1');
}

$db->exec('UPDATE settings SET dynamic_wallpaper = 0 WHERE dynamic_wallpaper IS NULL');
$db->exec('UPDATE settings SET dynamic_wallpaper_blur = 1 WHERE dynamic_wallpaper_blur IS NULL');
