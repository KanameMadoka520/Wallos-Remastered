<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/subscription_media.php';
require_once '../../includes/subscription_sort.php';
require_once '../../includes/subscription_trash.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$subscriptionId = $data["id"];
$query = "SELECT * FROM subscriptions WHERE id = :id AND user_id = :user_id AND lifecycle_status = :lifecycle_status";
$stmt = $db->prepare($query);
$stmt->bindValue(':id', $subscriptionId, SQLITE3_INTEGER);
$stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
$result = $stmt->execute();
$subscriptionToClone = $result->fetchArray(SQLITE3_ASSOC);
if ($subscriptionToClone === false) {
    die(json_encode([
        "success" => false,
        "message" => translate("error", $i18n)
    ]));
}

$userStmt = $db->prepare('SELECT username FROM user WHERE id = :userId');
$userStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$userResult = $userStmt->execute();
$currentUser = $userResult ? $userResult->fetchArray(SQLITE3_ASSOC) : false;
$nextSortOrder = wallos_get_next_subscription_sort_order($db, $userId);

$query = "INSERT INTO subscriptions (
    name, logo, price, currency_id, next_payment, cycle, frequency, notes,
    payment_method_id, payer_user_id, category_id, notify, url, inactive,
    notify_days_before, user_id, cancellation_date, replacement_subscription_id,
    start_date, auto_renew, detail_image, detail_image_urls, sort_order, lifecycle_status, exclude_from_stats
) VALUES (
    :name, :logo, :price, :currency_id, :next_payment, :cycle, :frequency, :notes,
    :payment_method_id, :payer_user_id, :category_id, :notify, :url, :inactive,
    :notify_days_before, :user_id, :cancellation_date, :replacement_subscription_id,
    :start_date, :auto_renew, :detail_image, :detail_image_urls, :sort_order, :lifecycle_status, :exclude_from_stats
)";
$cloneStmt = $db->prepare($query);
$cloneStmt->bindValue(':name', $subscriptionToClone['name'], SQLITE3_TEXT);
$cloneStmt->bindValue(':logo', $subscriptionToClone['logo'], SQLITE3_TEXT);
$cloneStmt->bindValue(':price', $subscriptionToClone['price'], SQLITE3_TEXT);
$cloneStmt->bindValue(':currency_id', $subscriptionToClone['currency_id'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':next_payment', $subscriptionToClone['next_payment'], SQLITE3_TEXT);
$cloneStmt->bindValue(':cycle', $subscriptionToClone['cycle'], SQLITE3_TEXT);
$cloneStmt->bindValue(':frequency', $subscriptionToClone['frequency'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':notes', $subscriptionToClone['notes'], SQLITE3_TEXT);
$cloneStmt->bindValue(':payment_method_id', $subscriptionToClone['payment_method_id'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':payer_user_id', $subscriptionToClone['payer_user_id'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':category_id', $subscriptionToClone['category_id'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':notify', $subscriptionToClone['notify'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':url', $subscriptionToClone['url'], SQLITE3_TEXT);
$cloneStmt->bindValue(':inactive', $subscriptionToClone['inactive'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':notify_days_before', $subscriptionToClone['notify_days_before'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$cloneStmt->bindValue(':cancellation_date', $subscriptionToClone['cancellation_date'], SQLITE3_TEXT);
$cloneStmt->bindValue(':replacement_subscription_id', $subscriptionToClone['replacement_subscription_id'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':start_date', $subscriptionToClone['start_date'], SQLITE3_TEXT);
$cloneStmt->bindValue(':auto_renew', $subscriptionToClone['auto_renew'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':detail_image', '', SQLITE3_TEXT);
$cloneStmt->bindValue(':detail_image_urls', $subscriptionToClone['detail_image_urls'] ?? '[]', SQLITE3_TEXT);
$cloneStmt->bindValue(':sort_order', $nextSortOrder, SQLITE3_INTEGER);
$cloneStmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
$cloneStmt->bindValue(':exclude_from_stats', (int) ($subscriptionToClone['exclude_from_stats'] ?? 0), SQLITE3_INTEGER);

if ($cloneStmt->execute()) {
    $newSubscriptionId = $db->lastInsertRowID();
    wallos_clone_subscription_uploaded_images(
        $db,
        __DIR__ . '/../../',
        $subscriptionId,
        $newSubscriptionId,
        $userId,
        $currentUser['username'] ?? ('user-' . $userId)
    );

    $response = [
        "success" => true,
        "message" => translate('success', $i18n),
        "id" => $newSubscriptionId
    ];
    echo json_encode($response);
} else {
    die(json_encode([
        "success" => false,
        "message" => translate("error", $i18n)
    ]));
}

$db->close();
?>
