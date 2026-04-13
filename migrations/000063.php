<?php

$settingsColumnsResult = $db->query("PRAGMA table_info('settings')");
$settingsColumns = [];
while ($settingsColumnsResult && ($column = $settingsColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $settingsColumns[] = $column['name'];
}

if (!in_array('page_transition_enabled', $settingsColumns, true)) {
    $db->exec('ALTER TABLE settings ADD COLUMN page_transition_enabled BOOLEAN DEFAULT 1');
}

if (!in_array('page_transition_style', $settingsColumns, true)) {
    $db->exec("ALTER TABLE settings ADD COLUMN page_transition_style TEXT DEFAULT 'shutter'");
}

$db->exec('UPDATE settings SET page_transition_enabled = 1 WHERE page_transition_enabled IS NULL');
$db->exec("UPDATE settings SET page_transition_style = 'shutter' WHERE page_transition_style IS NULL OR page_transition_style NOT IN ('shutter', 'nova', 'scanline', 'ribbon')");
