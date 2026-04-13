<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

function wallos_normalize_page_transition_style($value)
{
    $style = trim((string) $value);
    return in_array($style, ['shutter', 'bluearchive'], true) ? $style : 'shutter';
}

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);

$enabled = $data['enabled'] ?? null;
if (!is_bool($enabled)) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]));
}

$style = wallos_normalize_page_transition_style($data['style'] ?? 'shutter');

$stmt = $db->prepare('UPDATE settings SET page_transition_enabled = :enabled, page_transition_style = :style WHERE user_id = :userId');
$stmt->bindValue(':enabled', $enabled ? 1 : 0, SQLITE3_INTEGER);
$stmt->bindValue(':style', $style, SQLITE3_TEXT);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

if ($stmt->execute()) {
    die(json_encode([
        'success' => true,
        'message' => translate('success', $i18n),
    ]));
}

die(json_encode([
    'success' => false,
    'message' => translate('error', $i18n),
]));
