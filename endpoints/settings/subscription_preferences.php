<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/subscription_preferences.php';
require_once '../../includes/validate_endpoint.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

if (!is_array($data)) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]));
}

$displayColumns = wallos_normalize_subscription_display_columns_setting($data['display_columns'] ?? 1);
$valueVisibility = wallos_normalize_subscription_value_visibility_setting($data['value_visibility'] ?? []);
$imageLayoutForm = wallos_normalize_subscription_image_layout_setting($data['image_layout_form'] ?? 'focus');
$imageLayoutDetail = wallos_normalize_subscription_image_layout_setting($data['image_layout_detail'] ?? 'focus');

$stmt = $db->prepare('
    UPDATE settings
    SET subscription_display_columns = :displayColumns,
        subscription_value_visibility = :valueVisibility,
        subscription_image_layout_form = :imageLayoutForm,
        subscription_image_layout_detail = :imageLayoutDetail
    WHERE user_id = :userId
');
$stmt->bindValue(':displayColumns', $displayColumns, SQLITE3_INTEGER);
$stmt->bindValue(':valueVisibility', json_encode($valueVisibility, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
$stmt->bindValue(':imageLayoutForm', $imageLayoutForm, SQLITE3_TEXT);
$stmt->bindValue(':imageLayoutDetail', $imageLayoutDetail, SQLITE3_TEXT);
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
