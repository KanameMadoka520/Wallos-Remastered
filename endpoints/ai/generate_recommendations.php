<?php
set_time_limit(210);
require_once __DIR__ . '/../../includes/connect_endpoint.php';
require_once __DIR__ . '/../../includes/validate_endpoint.php';
require_once __DIR__ . '/../../includes/ai_recommendations.php';

// Other tabs must be able to save settings while the provider is responding.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(ai_generate_recommendations($db, $i18n, $userId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
