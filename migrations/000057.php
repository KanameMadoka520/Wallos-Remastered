<?php

$userColumns = [];
$userColumnsResult = $db->query("PRAGMA table_info(user)");
while ($userColumnsResult && ($row = $userColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $userColumns[] = $row['name'];
}

if (!in_array('yearly_budget', $userColumns, true)) {
    $db->exec('ALTER TABLE user ADD COLUMN yearly_budget REAL DEFAULT 0');
}

$db->exec('UPDATE user SET yearly_budget = 0 WHERE yearly_budget IS NULL');
