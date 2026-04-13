<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

function wallos_normalize_subscription_display_columns($value)
{
    $columns = (int) $value;
    return in_array($columns, [1, 2, 3], true) ? $columns : 1;
}

function wallos_normalize_subscription_image_layout($value)
{
    $mode = trim((string) $value);
    return in_array($mode, ['focus', 'grid'], true) ? $mode : 'focus';
}

function wallos_normalize_subscription_value_visibility($value)
{
    $decoded = is_array($value) ? $value : [];

    $legacyMetricsVisible = !(
        array_key_exists('invested', $decoded)
        && $decoded['invested'] === false
        && array_key_exists('remaining', $decoded)
        && $decoded['remaining'] === false
        && array_key_exists('used', $decoded)
        && $decoded['used'] === false
    );

    return [
        'metrics' => array_key_exists('metrics', $decoded) ? (bool) $decoded['metrics'] : $legacyMetricsVisible,
        'payment_records' => array_key_exists('payment_records', $decoded) ? (bool) $decoded['payment_records'] : true,
    ];
}

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

if (!is_array($data)) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]));
}

$displayColumns = wallos_normalize_subscription_display_columns($data['display_columns'] ?? 1);
$valueVisibility = wallos_normalize_subscription_value_visibility($data['value_visibility'] ?? []);
$imageLayoutForm = wallos_normalize_subscription_image_layout($data['image_layout_form'] ?? 'focus');
$imageLayoutDetail = wallos_normalize_subscription_image_layout($data['image_layout_detail'] ?? 'focus');

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
