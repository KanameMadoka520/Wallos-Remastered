<?php

$adminColumnsResult = $db->query("PRAGMA table_info('admin')");
$adminColumns = [];
while ($adminColumnsResult && ($column = $adminColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $adminColumns[] = $column['name'];
}

$adminRateLimitColumns = [
    'advanced_rate_limit_enabled' => 'INTEGER DEFAULT 0',
    'backend_request_limit_per_minute' => 'INTEGER DEFAULT 240',
    'backend_request_limit_per_hour' => 'INTEGER DEFAULT 3600',
    'image_upload_limit_per_minute' => 'INTEGER DEFAULT 20',
    'image_upload_limit_per_hour' => 'INTEGER DEFAULT 240',
    'image_upload_mb_per_minute' => 'INTEGER DEFAULT 120',
    'image_upload_mb_per_hour' => 'INTEGER DEFAULT 1200',
    'image_download_limit_per_minute' => 'INTEGER DEFAULT 180',
    'image_download_limit_per_hour' => 'INTEGER DEFAULT 2400',
    'image_download_mb_per_minute' => 'INTEGER DEFAULT 300',
    'image_download_mb_per_hour' => 'INTEGER DEFAULT 3000',
];

foreach ($adminRateLimitColumns as $columnName => $definition) {
    if (!in_array($columnName, $adminColumns, true)) {
        $db->exec('ALTER TABLE admin ADD COLUMN ' . $columnName . ' ' . $definition);
    }
}

$db->exec('
    CREATE TABLE IF NOT EXISTS rate_limit_usage (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        username TEXT DEFAULT "",
        category TEXT NOT NULL,
        unit_count INTEGER DEFAULT 0,
        byte_count INTEGER DEFAULT 0,
        path TEXT DEFAULT "",
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )
');
$db->exec('CREATE INDEX IF NOT EXISTS idx_rate_limit_usage_lookup ON rate_limit_usage(user_id, category, created_at)');

$db->exec('
    CREATE TABLE IF NOT EXISTS security_anomalies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER DEFAULT 0,
        username TEXT DEFAULT "",
        anomaly_type TEXT NOT NULL,
        anomaly_code TEXT NOT NULL,
        message TEXT DEFAULT "",
        path TEXT DEFAULT "",
        method TEXT DEFAULT "",
        ip_address TEXT DEFAULT "",
        forwarded_for TEXT DEFAULT "",
        user_agent TEXT DEFAULT "",
        headers_json TEXT DEFAULT "{}",
        details_json TEXT DEFAULT "{}",
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )
');
$db->exec('CREATE INDEX IF NOT EXISTS idx_security_anomalies_created_at ON security_anomalies(created_at)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_security_anomalies_type ON security_anomalies(anomaly_type, anomaly_code, created_at)');
