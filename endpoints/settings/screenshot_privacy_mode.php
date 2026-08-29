<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');

$payload = json_decode(file_get_contents('php://input'), true);
$value = is_array($payload) ? ($payload['value'] ?? null) : null;

if (!is_bool($value)) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
    exit;
}

$stmt = $db->prepare('UPDATE settings SET screenshot_privacy_mode = :value WHERE user_id = :userId');
$stmt->bindValue(':value', $value ? 1 : 0, SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();

if ($result) {
    setcookie('wallosScreenshotPrivacy', $value ? '1' : '0', [
        'expires' => time() + (30 * 24 * 60 * 60),
        'path' => '/',
        'samesite' => 'Lax',
    ]);
}

echo json_encode([
    'success' => (bool) $result,
    'message' => translate($result ? 'success' : 'error', $i18n),
]);
