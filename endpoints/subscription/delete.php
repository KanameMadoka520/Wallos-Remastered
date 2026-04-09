<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/subscription_media.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$subscriptionId = $data["id"];
$detailImageStmt = $db->prepare('SELECT detail_image FROM subscriptions WHERE id = :subscriptionId AND user_id = :userId');
$detailImageStmt->bindParam(':subscriptionId', $subscriptionId, SQLITE3_INTEGER);
$detailImageStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$detailImageResult = $detailImageStmt->execute();
$detailImageRow = $detailImageResult ? $detailImageResult->fetchArray(SQLITE3_ASSOC) : false;
$detailImage = $detailImageRow['detail_image'] ?? '';

$deleteQuery = "DELETE FROM subscriptions WHERE id = :subscriptionId AND user_id = :userId";
$deleteStmt = $db->prepare($deleteQuery);
$deleteStmt->bindParam(':subscriptionId', $subscriptionId, SQLITE3_INTEGER);
$deleteStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);

if ($deleteStmt->execute()) {
    $query = "UPDATE subscriptions SET replacement_subscription_id = NULL WHERE replacement_subscription_id = :subscriptionId AND user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':subscriptionId', $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();

    if ($detailImage !== '') {
        wallos_delete_subscription_image_if_unused($db, __DIR__ . '/../../', $detailImage);
    }

    echo json_encode([
        "success" => true,
        "message" => translate('subscription_deleted', $i18n)
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => translate('error_deleting_subscription', $i18n)
    ]);
}
$db->close();
