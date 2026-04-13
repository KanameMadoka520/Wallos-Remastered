<?php

$customColorsColumns = [];
$customColorsColumnsResult = $db->query("PRAGMA table_info(custom_colors)");
while ($customColorsColumnsResult && ($row = $customColorsColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $customColorsColumns[] = $row['name'];
}

if (!in_array('text_color', $customColorsColumns, true)) {
    $db->exec("ALTER TABLE custom_colors ADD COLUMN text_color TEXT DEFAULT ''");
}

$db->exec("UPDATE custom_colors SET text_color = '' WHERE text_color IS NULL");
