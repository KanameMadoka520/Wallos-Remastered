<?php

// Add the optional calendar preference without changing existing settings.
$columnResult = $db->query("SELECT 1 FROM pragma_table_info('settings') WHERE name = 'week_starts_sunday'");
if ($columnResult === false || $columnResult->fetchArray(SQLITE3_ASSOC) === false) {
    $db->exec('ALTER TABLE settings ADD COLUMN week_starts_sunday BOOLEAN DEFAULT 0');
}

$db->exec('UPDATE settings SET week_starts_sunday = 0 WHERE week_starts_sunday IS NULL');

