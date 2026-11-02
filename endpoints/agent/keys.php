<?php
require_once __DIR__ . '/../../includes/connect_endpoint.php';
require_once __DIR__ . '/../../includes/validate_endpoint.php';
require_once __DIR__ . '/../../includes/agent_auth.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
try {
    $action = $_POST['action'] ?? 'list';
    if ($action === 'create') {
        $scopes = $_POST['scopes'] ?? [];
        if (!is_array($scopes)) {
            wallos_agent_fail('invalid_scope', 'Scopes must be an array.');
        }
        $result = wallos_agent_create_key($db, $userId, $_POST['name'] ?? '', $scopes, (int) ($_POST['days'] ?? 30));
    } elseif ($action === 'revoke') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id || (string) ($_POST['confirm_id'] ?? '') !== (string) $id) {
            wallos_agent_fail('confirmation_mismatch', 'Confirm the key identifier.');
        }
        wallos_agent_sql($db, 'UPDATE agent_keys SET revoked_at=:now WHERE id=:id AND user_id=:uid AND revoked_at IS NULL', ['now' => time(), 'id' => $id, 'uid' => $userId]);
        $result = ['revoked_id' => $id];
    } elseif ($action === 'list') {
        $result = ['keys' => wallos_agent_rows(wallos_agent_sql($db, 'SELECT id,name,token_prefix,scopes,expires_at,revoked_at,created_at,last_used_at FROM agent_keys WHERE user_id=:uid ORDER BY id DESC', ['uid' => $userId]))];
    } elseif ($action === 'audit') {
        $result = ['audit' => wallos_agent_rows(wallos_agent_sql($db, 'SELECT id,key_id,tool,request_id,status,target_id,changed_fields,created_at FROM agent_audit WHERE user_id=:uid ORDER BY id DESC LIMIT 100', ['uid' => $userId]))];
    } else {
        wallos_agent_fail('invalid_action', 'Unknown key management action.');
    }
    echo json_encode(['success' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (WallosAgentError $e) {
    http_response_code($e->httpStatus);
    echo json_encode(['success' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]);
}
