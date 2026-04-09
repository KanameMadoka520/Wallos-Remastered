<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/subscription_media.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$userIdToDelete = $data['userId'];

if ($userIdToDelete == 1 || $userIdToDelete != $userId) {
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
} else {
    $detailImages = wallos_collect_user_subscription_images($db, $userIdToDelete);
    $avatarPaths = [];
    $avatarStmt = $db->prepare('SELECT path FROM uploaded_avatars WHERE user_id = :id');
    $avatarStmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $avatarResult = $avatarStmt->execute();
    while ($avatarResult && ($avatarRow = $avatarResult->fetchArray(SQLITE3_ASSOC))) {
        $avatarPaths[] = $avatarRow['path'];
    }

    // Delete user
    $stmt = $db->prepare('DELETE FROM user WHERE id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Delete subscriptions
    $stmt = $db->prepare('DELETE FROM subscriptions WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Delete settings
    $stmt = $db->prepare('DELETE FROM settings WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Delete fixer
    $stmt = $db->prepare('DELETE FROM fixer WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Delete custom colors
    $stmt = $db->prepare('DELETE FROM custom_colors WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Delete currencies
    $stmt = $db->prepare('DELETE FROM currencies WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Delete categories
    $stmt = $db->prepare('DELETE FROM categories WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Delete household
    $stmt = $db->prepare('DELETE FROM household WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Delete payment methods
    $stmt = $db->prepare('DELETE FROM payment_methods WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Delete email notifications
    $stmt = $db->prepare('DELETE FROM email_notifications WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Delete telegram notifications
    $stmt = $db->prepare('DELETE FROM telegram_notifications WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Delete webhook notifications
    $stmt = $db->prepare('DELETE FROM webhook_notifications WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Delete gotify notifications
    $stmt = $db->prepare('DELETE FROM gotify_notifications WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Delete pushover notifications
    $stmt = $db->prepare('DELETE FROM pushover_notifications WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Dele notification settings
    $stmt = $db->prepare('DELETE FROM notification_settings WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Delete last exchange update
    $stmt = $db->prepare('DELETE FROM last_exchange_update WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Delete email verification
    $stmt = $db->prepare('DELETE FROM email_verification WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Delete totp
    $stmt = $db->prepare('DELETE FROM totp WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    // Delete total yearly cost
    $stmt = $db->prepare('DELETE FROM total_yearly_cost WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $stmt = $db->prepare('DELETE FROM uploaded_avatars WHERE user_id = :id');
    $stmt->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $stmt->execute();

    foreach ($detailImages as $detailImage) {
        wallos_delete_subscription_image_if_unused($db, __DIR__ . '/../../', $detailImage);
    }

    foreach ($avatarPaths as $avatarPath) {
        $fullAvatarPath = __DIR__ . '/../../' . str_replace('/', DIRECTORY_SEPARATOR, $avatarPath);
        if (is_file($fullAvatarPath)) {
            unlink($fullAvatarPath);
        }
    }

    die(json_encode([
        "success" => true,
        "message" => translate('success', $i18n)
    ]));

}
