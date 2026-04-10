<?php

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/login_rate_limit.php';
require_once __DIR__ . '/../../includes/security_maintenance.php';

$db->exec("DELETE FROM request_logs WHERE created_at <= datetime('now', '-" . WALLOS_REQUEST_LOG_RETENTION_DAYS . " days')");
$requestLogsDeleted = (int) $db->changes();

$loginAttemptsDeleted = wallos_prune_login_attempts($db);

echo "Request logs cleaned up: {$requestLogsDeleted}\n";
echo "Expired login attempts cleaned up: {$loginAttemptsDeleted}\n";

$db->close();
