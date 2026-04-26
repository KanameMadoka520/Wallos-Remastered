<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/runtime_observability.php';
require_once '../../includes/cache_refresh.php';
require_once '../../includes/timezone_settings.php';

$backupTimezone = wallos_get_default_backup_timezone();
$adminSettings = $db->query('SELECT backup_timezone FROM admin LIMIT 1');
$adminSettingsRow = $adminSettings ? $adminSettings->fetchArray(SQLITE3_ASSOC) : false;
if (is_array($adminSettingsRow)) {
    $backupTimezone = wallos_normalize_timezone_identifier(
        $adminSettingsRow['backup_timezone'] ?? '',
        wallos_get_default_backup_timezone()
    );
}

$securityAnomaliesTableExists = wallos_security_anomalies_table_exists($db);
$typeCounts = $securityAnomaliesTableExists ? wallos_get_security_anomaly_type_counts($db, 24) : [];
$recentAnomalies = $securityAnomaliesTableExists ? wallos_get_recent_security_anomalies($db, 8, 24) : [];
$cacheRefreshMarker = wallos_read_cache_refresh_marker(__DIR__ . '/../..');

echo json_encode([
    'success' => true,
    'counts' => [
        'security_total' => $securityAnomaliesTableExists ? wallos_count_security_anomalies($db) : 0,
        'security_recent_24h' => $securityAnomaliesTableExists ? wallos_count_security_anomalies($db, 24) : 0,
        'client_runtime_24h' => $securityAnomaliesTableExists ? wallos_count_security_anomalies_by_type($db, 'client_runtime', 24) : 0,
        'request_failure_24h' => $securityAnomaliesTableExists ? wallos_count_security_anomalies_by_type($db, 'request_failure', 24) : 0,
    ],
    'type_counts' => $typeCounts,
    'type_summary' => wallos_summarize_security_anomaly_type_counts($typeCounts),
    'recent_anomalies' => array_map(static function ($item) use ($backupTimezone) {
        $item['created_at_display'] = wallos_format_observability_timestamp($item['created_at'] ?? '', $backupTimezone);
        return $item;
    }, $recentAnomalies),
    'service_worker_versions' => wallos_parse_service_worker_cache_versions(__DIR__ . '/../../service-worker.js'),
    'cache_refresh' => [
        'token_short' => substr((string) ($cacheRefreshMarker['token'] ?? ''), 0, 12),
        'requested_at' => (string) ($cacheRefreshMarker['requested_at'] ?? ''),
        'requested_at_display' => wallos_format_observability_timestamp($cacheRefreshMarker['requested_at'] ?? '', $backupTimezone),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
