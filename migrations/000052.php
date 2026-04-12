<?php

$columnQuery = $db->query("SELECT * FROM pragma_table_info('settings') WHERE name='decorative_background'");
$columnExists = $columnQuery ? $columnQuery->fetchArray(SQLITE3_ASSOC) : false;

if ($columnExists === false) {
    $db->exec('ALTER TABLE settings ADD COLUMN decorative_background BOOLEAN DEFAULT 1');
}

$db->exec('UPDATE settings SET decorative_background = 1 WHERE decorative_background IS NULL');

