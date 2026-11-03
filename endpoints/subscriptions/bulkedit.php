<?php
require_once __DIR__ . '/../../includes/connect_endpoint.php';
require_once __DIR__ . '/../../includes/validate_endpoint.php';
require_once __DIR__ . '/../../includes/agent_tools.php';
require_once __DIR__ . '/../../includes/agent_bulk.php';
require_once __DIR__ . '/../../includes/screenshot_privacy.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
$db->enableExceptions(true);
ini_set('display_errors', '0');

try {
    $privacy = wallos_agent_sql($db, 'SELECT screenshot_privacy_mode FROM settings WHERE user_id=:uid', ['uid' => (int) $userId])->fetchArray(SQLITE3_ASSOC);
    if (wallos_screenshot_privacy_enabled($privacy ?: [])) {
        wallos_agent_fail('screenshot_privacy_mode_blocked', translate('screenshot_privacy_mode_blocked', $i18n), 403);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 262144) {
        wallos_agent_fail('payload_too_large', 'Request body exceeds 256 KiB.', 413);
    }
    $raw = file_get_contents('php://input', false, null, 0, 262145);
    if (strlen($raw) > 262144) {
        wallos_agent_fail('payload_too_large', 'Request body exceeds 256 KiB.', 413);
    }
    $input = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
    if (!is_object($input)) {
        wallos_agent_fail('invalid_arguments', 'Expected a JSON object.');
    }
    if (($input->action ?? '') === 'inspect') {
        wallos_agent_validate($input, wallos_agent_object([
            'action' => ['type' => 'string', 'enum' => ['inspect']],
            'ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'maxItems' => 50],
        ], ['action', 'ids']));
        $ids = wallos_subscription_bulk_ids($input->ids);
        $rows = [];
        $db->exec('BEGIN');
        try {
            foreach ($ids as $id) {
                $row = wallos_agent_present_subscription($db, $userId, $id);
                if ($row['lifecycle_status'] !== 'active') {
                    wallos_agent_fail('in_recycle_bin', 'All selected subscriptions must be active.', 409);
                }
                $rows[] = ['id' => $row['id'], 'name' => $row['name'], 'version' => $row['version']];
            }
            $db->exec('COMMIT');
        } catch (Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
        $result = ['subscriptions' => $rows];
    } else {
        wallos_agent_validate($input, wallos_agent_object([
            'action' => ['type' => 'string', 'enum' => ['update', 'trash']],
            'arguments' => wallos_agent_bulk_catalog()['wallos_subscriptions_bulk_' . (($input->action ?? '') === 'trash' ? 'trash' : 'update')]['inputSchema'],
        ], ['action', 'arguments']));
        $result = wallos_subscription_bulk_browser($db, $userId, 'wallos_subscriptions_bulk_' . $input->action, $input->arguments);
    }
    echo json_encode(['success' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'code' => 'invalid_json', 'message' => 'Malformed JSON.']);
} catch (WallosAgentError $e) {
    http_response_code($e->httpStatus);
    echo json_encode(['success' => false, 'code' => $e->errorCode, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    wallos_database_emit_busy_response_if_needed($i18n, $e, $db);
    error_log('Wallos bulk request failed: ' . get_class($e));
    http_response_code(500);
    echo json_encode(['success' => false, 'code' => 'internal_error', 'message' => 'Bulk operation failed; no changes were committed.']);
}
