<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$payload = json_decode(file_get_contents('php://input'), true);
$value = $payload['value'] ?? null;

if (!is_bool($value)) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
    exit;
}

$stmt = $db->prepare('UPDATE settings SET week_starts_sunday = :value WHERE user_id = :userId');
$stmt->bindValue(':value', $value ? 1 : 0, SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();

echo json_encode([
    'success' => (bool) $result,
    'message' => translate($result ? 'success' : 'error', $i18n),
]);
