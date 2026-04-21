<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/subscription_media.php';
require_once '../../includes/subscription_trash.php';
require_once '../../includes/subscription_payment_records.php';
require_once '../../includes/subscription_payment_history.php';
require_once '../../includes/subscription_price_rules.php';

wallos_endpoint_require_authenticated($i18n);

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $userId <= 0) {
    wallos_auth_emit_async_error($i18n, 'session_expired', 401, [], 'session_expired');
}

if (!isset($_GET['id']) || $_GET['id'] === '') {
    wallos_auth_emit_async_error($i18n, 'error', 400, [], 'error');
}

$subscriptionId = intval($_GET['id']);
$query = "SELECT * FROM subscriptions WHERE id = :subscriptionId AND user_id = :userId AND lifecycle_status = :lifecycle_status";
$stmt = $db->prepare($query);
$stmt->bindParam(':subscriptionId', $subscriptionId, SQLITE3_INTEGER);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
$result = $stmt->execute();

$subscriptionData = array();
$currencies = [];
$currencyStmt = $db->prepare('SELECT id, code, name, symbol FROM currencies WHERE user_id = :user_id');
$currencyStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$currencyResult = $currencyStmt->execute();
while ($currencyResult && ($currencyRow = $currencyResult->fetchArray(SQLITE3_ASSOC))) {
    $currencies[(int) $currencyRow['id']] = $currencyRow;
}

$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
if ($row === false) {
    wallos_auth_emit_async_error($i18n, 'error', 404, [], 'error');
}

$subscriptionData['id'] = $subscriptionId;
$subscriptionData['name'] = htmlspecialchars_decode($row['name'] ?? "");
$subscriptionData['logo'] = $row['logo'];
$subscriptionData['price'] = $row['price'];
$subscriptionData['currency_id'] = $row['currency_id'];
$subscriptionData['auto_renew'] = $row['auto_renew'];
$subscriptionData['start_date'] = $row['start_date'];
$subscriptionData['next_payment'] = $row['next_payment'];
$subscriptionData['frequency'] = $row['frequency'];
$subscriptionData['cycle'] = $row['cycle'];
$subscriptionData['notes'] = htmlspecialchars_decode($row['notes'] ?? "");
$subscriptionData['payment_method_id'] = $row['payment_method_id'];
$subscriptionData['payer_user_id'] = $row['payer_user_id'];
$subscriptionData['category_id'] = $row['category_id'];
$subscriptionData['subscription_page_id'] = isset($row['subscription_page_id']) ? (int) $row['subscription_page_id'] : 0;
$subscriptionData['notify'] = $row['notify'];
$subscriptionData['inactive'] = $row['inactive'];
$subscriptionData['exclude_from_stats'] = (int) ($row['exclude_from_stats'] ?? 0);
$subscriptionData['manual_cycle_used_value_main'] = (float) ($row['manual_cycle_used_value_main'] ?? 0);
$subscriptionData['manual_cycle_used_value_cycle_start'] = (string) ($row['manual_cycle_used_value_cycle_start'] ?? '');
$subscriptionData['url'] = htmlspecialchars_decode($row['url'] ?? "");
$subscriptionData['notify_days_before'] = $row['notify_days_before'];
$subscriptionData['cancellation_date'] = $row['cancellation_date'];
$subscriptionData['replacement_subscription_id'] = $row['replacement_subscription_id'];
$subscriptionData['detail_image_urls'] = json_decode($row['detail_image_urls'] ?? '[]', true) ?: [];
$subscriptionData['uploaded_images'] = wallos_get_subscription_uploaded_images($db, $subscriptionId, $userId);
$subscriptionData['payment_records'] = wallos_get_subscription_payment_records($db, $subscriptionId, $userId, 12);
$subscriptionData['price_rules'] = wallos_get_subscription_price_rules($db, $subscriptionId, $userId, false);
$subscriptionData['remaining_value'] = wallos_build_subscription_remaining_value_snapshot(
    $db,
    $row,
    $userId,
    $subscriptionData['price_rules'],
    $subscriptionData['payment_records'],
    $currencies,
    $i18n
);
$subscriptionData['detail_image'] = !empty($subscriptionData['uploaded_images'][0]['access_url'])
    ? $subscriptionData['uploaded_images'][0]['access_url']
    : ($row['detail_image'] ?? "");

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($subscriptionData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$db->close();
exit;
?>
