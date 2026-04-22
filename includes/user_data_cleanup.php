<?php

require_once __DIR__ . '/subscription_media.php';
require_once __DIR__ . '/user_status.php';

function wallos_trash_user($db, $targetUserId, $reason)
{
    $reason = trim((string) $reason);
    $trashedAt = date('Y-m-d H:i:s');
    $scheduledDeleteAt = wallos_calculate_scheduled_delete_at($trashedAt);

    $stmt = $db->prepare('
        UPDATE user
        SET account_status = :account_status,
            trash_reason = :trash_reason,
            trashed_at = :trashed_at,
            scheduled_delete_at = :scheduled_delete_at
        WHERE id = :id AND id != 1
    ');
    $stmt->bindValue(':account_status', WALLOS_USER_STATUS_TRASHED, SQLITE3_TEXT);
    $stmt->bindValue(':trash_reason', $reason, SQLITE3_TEXT);
    $stmt->bindValue(':trashed_at', $trashedAt, SQLITE3_TEXT);
    $stmt->bindValue(':scheduled_delete_at', $scheduledDeleteAt, SQLITE3_TEXT);
    $stmt->bindValue(':id', (int) $targetUserId, SQLITE3_INTEGER);
    $stmt->execute();

    $tokenStmt = $db->prepare('DELETE FROM login_tokens WHERE user_id = :id');
    $tokenStmt->bindValue(':id', (int) $targetUserId, SQLITE3_INTEGER);
    $tokenStmt->execute();
}

function wallos_restore_user($db, $targetUserId)
{
    $stmt = $db->prepare('
        UPDATE user
        SET account_status = :account_status,
            trash_reason = "",
            trashed_at = "",
            scheduled_delete_at = ""
        WHERE id = :id AND id != 1
    ');
    $stmt->bindValue(':account_status', WALLOS_USER_STATUS_ACTIVE, SQLITE3_TEXT);
    $stmt->bindValue(':id', (int) $targetUserId, SQLITE3_INTEGER);
    $stmt->execute();
}

function wallos_delete_user_data($db, $targetUserId, $basePath)
{
    $targetUserId = (int) $targetUserId;

    $avatarPaths = [];
    $avatarStmt = $db->prepare('SELECT path FROM uploaded_avatars WHERE user_id = :id');
    $avatarStmt->bindValue(':id', $targetUserId, SQLITE3_INTEGER);
    $avatarResult = $avatarStmt->execute();
    while ($avatarResult && ($avatarRow = $avatarResult->fetchArray(SQLITE3_ASSOC))) {
        $avatarPaths[] = $avatarRow['path'];
    }

    $imagePaths = wallos_collect_user_subscription_images($db, $targetUserId);

    $deleteStatements = [
        'DELETE FROM login_tokens WHERE user_id = :id',
        'DELETE FROM subscription_payment_records WHERE user_id = :id',
        'DELETE FROM subscription_price_rules WHERE user_id = :id',
        'DELETE FROM subscription_uploaded_images WHERE user_id = :id',
        'DELETE FROM subscriptions WHERE user_id = :id',
        'DELETE FROM settings WHERE user_id = :id',
        'DELETE FROM fixer WHERE user_id = :id',
        'DELETE FROM custom_colors WHERE user_id = :id',
        'DELETE FROM currencies WHERE user_id = :id',
        'DELETE FROM categories WHERE user_id = :id',
        'DELETE FROM household WHERE user_id = :id',
        'DELETE FROM payment_methods WHERE user_id = :id',
        'DELETE FROM email_notifications WHERE user_id = :id',
        'DELETE FROM telegram_notifications WHERE user_id = :id',
        'DELETE FROM webhook_notifications WHERE user_id = :id',
        'DELETE FROM gotify_notifications WHERE user_id = :id',
        'DELETE FROM pushover_notifications WHERE user_id = :id',
        'DELETE FROM discord_notifications WHERE user_id = :id',
        'DELETE FROM ntfy_notifications WHERE user_id = :id',
        'DELETE FROM pushplus_notifications WHERE user_id = :id',
        'DELETE FROM mattermost_notifications WHERE user_id = :id',
        'DELETE FROM serverchan_notifications WHERE user_id = :id',
        'DELETE FROM notification_settings WHERE user_id = :id',
        'DELETE FROM last_exchange_update WHERE user_id = :id',
        'DELETE FROM email_verification WHERE user_id = :id',
        'DELETE FROM password_resets WHERE user_id = :id',
        'DELETE FROM custom_css_style WHERE user_id = :id',
        'DELETE FROM totp WHERE user_id = :id',
        'DELETE FROM total_yearly_cost WHERE user_id = :id',
        'DELETE FROM ai_settings WHERE user_id = :id',
        'DELETE FROM ai_recommendations WHERE user_id = :id',
        'DELETE FROM uploaded_avatars WHERE user_id = :id',
        'DELETE FROM invite_code_usages WHERE used_by_user_id = :id',
        'DELETE FROM request_logs WHERE user_id = :id',
        'DELETE FROM user WHERE id = :id',
    ];

    $db->exec('PRAGMA foreign_keys = OFF');
    $db->exec('BEGIN IMMEDIATE');

    try {
        foreach ($deleteStatements as $sql) {
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                throw new RuntimeException($db->lastErrorMsg());
            }

            $stmt->bindValue(':id', $targetUserId, SQLITE3_INTEGER);
            $result = @$stmt->execute();
            if ($result === false) {
                throw new RuntimeException($db->lastErrorMsg());
            }
        }

        $db->exec('COMMIT');
    } catch (Throwable $throwable) {
        $db->exec('ROLLBACK');
        throw $throwable;
    } finally {
        $db->exec('PRAGMA foreign_keys = ON');
    }

    foreach ($imagePaths as $imagePath) {
        wallos_delete_subscription_image_file($basePath, $imagePath);
    }

    foreach ($avatarPaths as $avatarPath) {
        $fullAvatarPath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $avatarPath);
        if (is_file($fullAvatarPath)) {
            unlink($fullAvatarPath);
        }
    }
}
