<?php
require_once '../includes/connect_endpoint.php';
require_once '../includes/getsettings.php';
require_once '../includes/subscription_pages.php';
require_once '../libs/csrf.php';

header('Content-Type: application/json; charset=UTF-8');

function subscription_pages_json_response($success, $message, array $extra = [])
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function subscription_pages_get_payload($db, $userId, $hideDisabled)
{
    return wallos_get_subscription_pages_payload($db, $userId, $hideDisabled);
}

function subscription_pages_execute_or_throw($statement, $errorMessage)
{
    $result = $statement->execute();
    if ($result === false) {
        throw new RuntimeException($errorMessage);
    }

    return $result;
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $userId <= 0) {
    wallos_endpoint_require_authenticated($i18n);
}

$hideDisabled = isset($settings['hideDisabledSubscriptions']) && $settings['hideDisabledSubscriptions'] === 'true';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    subscription_pages_json_response(
        true,
        translate('success', $i18n),
        subscription_pages_get_payload($db, $userId, $hideDisabled)
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    subscription_pages_json_response(false, translate('error', $i18n));
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verify_csrf_token($csrfToken)) {
    subscription_pages_json_response(false, 'Invalid CSRF token');
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    subscription_pages_json_response(false, translate('error', $i18n));
}

$action = trim((string) ($data['action'] ?? ''));

try {
    if ($action === 'create') {
        $pageName = wallos_validate_subscription_page_name($db, $userId, $data['name'] ?? '', $i18n);
        $sortOrder = wallos_get_next_subscription_page_sort_order($db, $userId);

        $stmt = $db->prepare('
            INSERT INTO subscription_pages (user_id, name, sort_order)
            VALUES (:user_id, :name, :sort_order)
        ');
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':name', $pageName, SQLITE3_TEXT);
        $stmt->bindValue(':sort_order', $sortOrder, SQLITE3_INTEGER);
        subscription_pages_execute_or_throw($stmt, translate('error', $i18n));

        subscription_pages_json_response(
            true,
            wallos_translate_with_fallback('subscription_page_created', 'Subscription page created.', $i18n),
            subscription_pages_get_payload($db, $userId, $hideDisabled)
        );
    }

    if ($action === 'update') {
        $pageId = (int) ($data['page_id'] ?? 0);
        if ($pageId <= 0 || !wallos_subscription_page_exists($db, $userId, $pageId)) {
            throw new RuntimeException(wallos_translate_with_fallback('subscription_page_invalid', 'The selected page does not exist.', $i18n));
        }

        $pageName = wallos_validate_subscription_page_name($db, $userId, $data['name'] ?? '', $i18n, $pageId);
        $stmt = $db->prepare('
            UPDATE subscription_pages
            SET name = :name, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND user_id = :user_id
        ');
        $stmt->bindValue(':name', $pageName, SQLITE3_TEXT);
        $stmt->bindValue(':id', $pageId, SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        subscription_pages_execute_or_throw($stmt, translate('error', $i18n));

        if ($db->changes() < 1) {
            throw new RuntimeException(wallos_translate_with_fallback('subscription_page_invalid', 'The selected page does not exist.', $i18n));
        }

        subscription_pages_json_response(
            true,
            wallos_translate_with_fallback('subscription_page_updated', 'Subscription page updated.', $i18n),
            subscription_pages_get_payload($db, $userId, $hideDisabled)
        );
    }

    if ($action === 'reorder') {
        wallos_reorder_subscription_pages($db, $userId, $data['page_ids'] ?? [], $i18n);

        subscription_pages_json_response(
            true,
            wallos_translate_with_fallback('subscription_page_updated', 'Subscription page updated.', $i18n),
            subscription_pages_get_payload($db, $userId, $hideDisabled)
        );
    }

    if ($action === 'delete') {
        $pageId = (int) ($data['page_id'] ?? 0);
        if ($pageId <= 0 || !wallos_subscription_page_exists($db, $userId, $pageId)) {
            throw new RuntimeException(wallos_translate_with_fallback('subscription_page_invalid', 'The selected page does not exist.', $i18n));
        }

        if ($db->exec('BEGIN IMMEDIATE') === false) {
            throw new RuntimeException(translate('error', $i18n));
        }

        $resetStmt = $db->prepare('
            UPDATE subscriptions
            SET subscription_page_id = NULL
            WHERE user_id = :user_id AND subscription_page_id = :page_id
        ');
        $resetStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $resetStmt->bindValue(':page_id', $pageId, SQLITE3_INTEGER);
        subscription_pages_execute_or_throw($resetStmt, translate('error', $i18n));

        $deleteStmt = $db->prepare('DELETE FROM subscription_pages WHERE id = :id AND user_id = :user_id');
        $deleteStmt->bindValue(':id', $pageId, SQLITE3_INTEGER);
        $deleteStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        subscription_pages_execute_or_throw($deleteStmt, translate('error', $i18n));

        if ($db->changes() < 1) {
            throw new RuntimeException(wallos_translate_with_fallback('subscription_page_invalid', 'The selected page does not exist.', $i18n));
        }

        if ($db->exec('COMMIT') === false) {
            throw new RuntimeException(translate('error', $i18n));
        }

        subscription_pages_json_response(
            true,
            wallos_translate_with_fallback('subscription_page_deleted', 'Subscription page deleted.', $i18n),
            subscription_pages_get_payload($db, $userId, $hideDisabled)
        );
    }

    throw new RuntimeException(translate('error', $i18n));
} catch (Throwable $throwable) {
    @ $db->exec('ROLLBACK');

    subscription_pages_json_response(false, $throwable->getMessage());
}
