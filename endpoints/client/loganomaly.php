<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/security_rate_limits.php';

$anomalyType = trim((string) ($_POST['anomaly_type'] ?? ''));
$anomalyCode = trim((string) ($_POST['anomaly_code'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));
$detailsRaw = $_POST['details_json'] ?? '';

$allowedTypes = [
    'client_runtime',
    'request_failure',
];

if (!in_array($anomalyType, $allowedTypes, true)) {
    wallos_auth_emit_async_error($i18n, 'invalid_anomaly_type', 400, [], null, 'Invalid anomaly type');
}

if ($message === '') {
    wallos_auth_emit_async_error($i18n, 'invalid_message', 400, [], null, 'Missing anomaly message');
}

$details = [];
if (is_string($detailsRaw) && trim($detailsRaw) !== '') {
    $decoded = json_decode($detailsRaw, true);
    if (is_array($decoded)) {
        $details = $decoded;
    }
}

wallos_log_security_anomaly(
    $db,
    (int) $userId,
    (string) ($_SESSION['username'] ?? ''),
    $anomalyType,
    $anomalyCode !== '' ? $anomalyCode : $anomalyType,
    $message,
    $details
);

echo json_encode([
    'success' => true,
    'message' => translate('success', $i18n),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
