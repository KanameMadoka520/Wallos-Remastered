<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';

$result = $db->exec('DELETE FROM request_logs');

echo json_encode([
    'success' => $result !== false,
    'message' => $result !== false ? translate('access_logs_cleared', $i18n) : translate('error', $i18n),
]);
