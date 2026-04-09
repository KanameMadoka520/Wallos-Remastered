<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/default_user_seed.php';

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
$scope = $data['scope'] ?? '';

$allowedScopes = [
    'categories' => [
        'table' => 'categories',
        'message_key' => 'localized_defaults_reset_categories_success',
    ],
    'payment_methods' => [
        'table' => 'payment_methods',
        'message_key' => 'localized_defaults_reset_payment_methods_success',
    ],
    'currencies' => [
        'table' => 'currencies',
        'message_key' => 'localized_defaults_reset_currencies_success',
    ],
];

if (!isset($allowedScopes[$scope])) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
    exit();
}

$userStmt = $db->prepare('SELECT language FROM user WHERE id = :userId');
$userStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$userResult = $userStmt->execute();
$user = $userResult ? $userResult->fetchArray(SQLITE3_ASSOC) : false;

$targetLanguage = wallos_get_seed_translation_language($user['language'] ?? 'en');
$resetMap = wallos_get_seed_reset_map($scope, $targetLanguage);
$table = $allowedScopes[$scope]['table'];

$rowStmt = $db->prepare("SELECT id, name FROM {$table} WHERE user_id = :userId");
$rowStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$rowResult = $rowStmt->execute();

$updates = [];
while ($row = $rowResult->fetchArray(SQLITE3_ASSOC)) {
    foreach ($resetMap as $map) {
        if (in_array($row['name'], $map['variants'], true)) {
            if ($row['name'] !== $map['target_name']) {
                $updates[] = [
                    'id' => (int) $row['id'],
                    'name' => $map['target_name'],
                ];
            }
            break;
        }
    }
}

if (count($updates) === 0) {
    echo json_encode([
        'success' => true,
        'message' => translate('localized_defaults_reset_no_changes', $i18n),
        'items' => [],
    ]);
    exit();
}

$db->exec('BEGIN IMMEDIATE');
$updateStmt = $db->prepare("UPDATE {$table} SET name = :name WHERE id = :id AND user_id = :userId");

foreach ($updates as $update) {
    $updateStmt->bindValue(':name', $update['name'], SQLITE3_TEXT);
    $updateStmt->bindValue(':id', $update['id'], SQLITE3_INTEGER);
    $updateStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $updateStmt->execute();
}

$db->exec('COMMIT');

echo json_encode([
    'success' => true,
    'message' => translate($allowedScopes[$scope]['message_key'], $i18n),
    'items' => $updates,
]);
