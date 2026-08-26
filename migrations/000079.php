<?php

// Payment-period budgets are additive. Existing monthly and yearly budget
// columns remain untouched; a zero period budget means the new view is off.
$userColumns = [];
$result = $db->query("PRAGMA table_info('user')");
while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
    $userColumns[] = $row['name'];
}

if (!in_array('period_budget', $userColumns, true)) {
    $db->exec('ALTER TABLE user ADD COLUMN period_budget REAL DEFAULT 0');
}
if (!in_array('budget_period_type', $userColumns, true)) {
    $db->exec("ALTER TABLE user ADD COLUMN budget_period_type TEXT DEFAULT 'monthly'");
}
if (!in_array('budget_period_anchor_date', $userColumns, true)) {
    $db->exec("ALTER TABLE user ADD COLUMN budget_period_anchor_date TEXT DEFAULT ''");
}

$db->exec("UPDATE user SET period_budget = 0 WHERE period_budget IS NULL");
$db->exec("UPDATE user SET budget_period_type = 'monthly' WHERE budget_period_type IS NULL OR budget_period_type = ''");

$today = (new DateTime('today'))->format('Y-m-d');
$anchorStmt = $db->prepare("UPDATE user SET budget_period_anchor_date = :today
                            WHERE budget_period_anchor_date IS NULL
                               OR budget_period_anchor_date = ''");
$anchorStmt->bindValue(':today', $today, SQLITE3_TEXT);
$anchorStmt->execute();

?>
