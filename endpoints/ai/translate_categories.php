<?php

set_time_limit(210);
require_once __DIR__ . '/../../includes/connect_endpoint.php';
require_once __DIR__ . '/../../includes/validate_endpoint.php';
require_once __DIR__ . '/../../includes/ai_client.php';
require_once __DIR__ . '/../../includes/ai_category_translation.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
ini_set('display_errors', '0');
$db->enableExceptions(true);

function wallos_ai_category_reply($success, $code, $messageKey, $status = 200, array $extra = [])
{
    global $i18n;
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'code' => $code, 'message' => translate($messageKey, $i18n)], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
        wallos_ai_category_reply(false, 'invalid_request', 'error', 400);
    }
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if (strlen($raw) > 4096) {
        wallos_ai_category_reply(false, 'invalid_request', 'error', 400);
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !in_array($data['action'] ?? '', ['preview', 'apply'], true)) {
        wallos_ai_category_reply(false, 'invalid_request', 'error', 400);
    }
    if (array_diff(array_keys($data), $data['action'] === 'apply' ? ['action', 'token'] : ['action'])) {
        wallos_ai_category_reply(false, 'invalid_request', 'error', 400);
    }
    $state = wallos_ai_category_state($db, $userId);
    if ($state['privacy']) {
        wallos_ai_category_reply(false, 'screenshot_privacy_mode_blocked', 'screenshot_privacy_mode_blocked', 403);
    }
    if ($data['action'] === 'apply') {
        $preview = $_SESSION['wallos_ai_category_preview'] ?? [];
        $appliedSnapshot = wallos_ai_category_apply_preview($db, $userId, $preview, $data['token'] ?? null);
        $_SESSION['wallos_ai_category_preview']['applied_snapshot'] = $appliedSnapshot;
        wallos_ai_category_reply(true, 'applied', 'ai_categories_applied', 200, ['translations' => $preview['translations']]);
    }

    if (!$state['settings'] || empty($state['settings']['enabled']) || trim((string) ($state['settings']['model'] ?? '')) === '') {
        wallos_ai_category_reply(false, 'ai_not_configured', 'ai_categories_not_configured', 400);
    }
    if (!$state['categories']) {
        wallos_ai_category_reply(false, 'no_categories', 'ai_categories_empty', 400);
    }
    if (count($state['categories']) > 1000 || strlen(json_encode($state['categories'])) > 131072) {
        wallos_ai_category_reply(false, 'categories_too_large', 'ai_categories_too_large', 400);
    }
    // Release the session lock during the provider request so cancellation,
    // logout and other tabs continue to work. A subsequent preview invalidates
    // an older result even if provider replies arrive out of order.
    $generation = bin2hex(random_bytes(16));
    unset($_SESSION['wallos_ai_category_preview']);
    $_SESSION['wallos_ai_category_generation'] = $generation;
    session_write_close();
    $result = ai_complete($state['settings'], wallos_ai_category_prompt($state), $db, $i18n, $userId);
    wallos_auth_start_session();
    $revalidatedAuth = wallos_auth_resolve_authenticated_user($db, ['session_only' => true]);
    if (empty($revalidatedAuth['authenticated']) || (int) ($revalidatedAuth['user_id'] ?? 0) !== (int) $userId) {
        wallos_ai_category_reply(false, 'session_expired', 'session_expired', 401);
    }
    if (!hash_equals($generation, (string) ($_SESSION['wallos_ai_category_generation'] ?? ''))) {
        wallos_ai_category_reply(false, 'data_changed', 'ai_categories_changed', 409);
    }
    $current = wallos_ai_category_state($db, $userId);
    if ($current['privacy']) {
        wallos_ai_category_reply(false, 'screenshot_privacy_mode_blocked', 'screenshot_privacy_mode_blocked', 403);
    }
    if (!hash_equals($state['snapshot'], $current['snapshot'])) {
        wallos_ai_category_reply(false, 'data_changed', 'ai_categories_changed', 409);
    }
    if (empty($result['success'])) {
        wallos_ai_category_reply(false, 'provider_error', 'ai_provider_error', 502);
    }
    $translations = wallos_ai_category_validate_translations(ai_extract_json($result['content']), $state['categories']);
    $preview = wallos_ai_category_make_preview($state, $translations, $userId);
    $_SESSION['wallos_ai_category_preview'] = $preview;
    wallos_ai_category_reply(true, 'preview', 'ai_categories_preview_ready', 200, [
        'token' => $preview['token'], 'expires_at' => $preview['expires_at'],
        'language' => $state['language'], 'language_name' => $state['language_name'], 'translations' => $translations,
    ]);
} catch (InvalidArgumentException $error) {
    $expired = $error->getMessage() === 'preview_expired';
    wallos_ai_category_reply(false, $expired ? 'preview_expired' : 'invalid_response',
        $expired ? 'ai_categories_preview_expired' : 'ai_categories_invalid_response', $expired ? 409 : 502);
} catch (RuntimeException $error) {
    if ($error->getMessage() === 'data_changed') {
        wallos_ai_category_reply(false, 'data_changed', 'ai_categories_changed', 409);
    }
    if ($error->getMessage() === 'screenshot_privacy_mode_blocked') {
        wallos_ai_category_reply(false, 'screenshot_privacy_mode_blocked', 'screenshot_privacy_mode_blocked', 403);
    }
    throw $error; // Preserve the repository's database_busy/error contract.
}
