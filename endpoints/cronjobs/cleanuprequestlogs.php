<?php

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/login_rate_limit.php';
require_once __DIR__ . '/../../includes/security_maintenance.php';

$db->exec("DELETE FROM request_logs WHERE created_at <= datetime('now', '-" . WALLOS_REQUEST_LOG_RETENTION_DAYS . " days')");
$requestLogsDeleted = (int) $db->changes();

$db->exec("DELETE FROM rate_limit_usage WHERE created_at <= datetime('now', '-" . WALLOS_RATE_LIMIT_USAGE_RETENTION_DAYS . " days')");
$rateLimitUsageDeleted = (int) $db->changes();

$db->exec("DELETE FROM security_anomalies WHERE created_at <= datetime('now', '-" . WALLOS_SECURITY_ANOMALY_RETENTION_DAYS . " days')");
$securityAnomaliesDeleted = (int) $db->changes();

$loginAttemptsDeleted = wallos_prune_login_attempts($db);

echo "Request logs cleaned up: {$requestLogsDeleted}\n";
echo "Rate limit usage rows cleaned up: {$rateLimitUsageDeleted}\n";
echo "Security anomalies cleaned up: {$securityAnomaliesDeleted}\n";
echo "Expired login attempts cleaned up: {$loginAttemptsDeleted}\n";

$db->close();
