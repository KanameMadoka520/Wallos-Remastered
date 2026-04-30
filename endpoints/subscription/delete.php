<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/subscription_trash.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$subscriptionId = $data["id"];

try {
    wallos_trash_subscription($db, $subscriptionId, $userId);
    echo json_encode([
        "success" => true,
        "message" => translate('subscription_moved_to_recycle_bin', $i18n)
    ]);
} catch (Throwable $throwable) {
    wallos_database_emit_busy_response_if_needed($i18n, $throwable, $db ?? null);
    echo json_encode([
        "success" => false,
        "message" => translate('error_deleting_subscription', $i18n)
    ]);
}
$db->close();
