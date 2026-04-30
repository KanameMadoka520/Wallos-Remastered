<?php

$requestLogColumns = [];
$columnsResult = $db->query("PRAGMA table_info(request_logs)");
while ($columnsResult && ($row = $columnsResult->fetchArray(SQLITE3_ASSOC))) {
    $requestLogColumns[] = $row['name'];
}

if (!in_array('duration_ms', $requestLogColumns, true)) {
    $db->exec('ALTER TABLE request_logs ADD COLUMN duration_ms INTEGER DEFAULT 0');
}

if (!in_array('status_code', $requestLogColumns, true)) {
    $db->exec('ALTER TABLE request_logs ADD COLUMN status_code INTEGER DEFAULT 0');
}

if (!in_array('completed_at', $requestLogColumns, true)) {
    $db->exec("ALTER TABLE request_logs ADD COLUMN completed_at TEXT DEFAULT ''");
}

$db->exec('UPDATE request_logs SET duration_ms = 0 WHERE duration_ms IS NULL');
$db->exec('UPDATE request_logs SET status_code = 0 WHERE status_code IS NULL');
$db->exec("UPDATE request_logs SET completed_at = '' WHERE completed_at IS NULL");

$db->exec('CREATE INDEX IF NOT EXISTS idx_request_logs_duration_created_id ON request_logs(duration_ms, created_at, id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_request_logs_status_created_id ON request_logs(status_code, created_at, id)');

?>
