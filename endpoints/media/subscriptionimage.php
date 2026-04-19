<?php
require_once '../../includes/user_status.php';
require_once '../../includes/subscription_media.php';
require_once '../../includes/security_rate_limits.php';
require_once '../../includes/i18n/languages.php';

function wallos_media_deny($statusCode = 403)
{
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo $statusCode === 404 ? 'Not Found' : 'Forbidden';
    exit;
}

function wallos_media_deny_rate_limit(array $violation)
{
    wallos_set_rate_limit_notice_cookie(
        $violation['message'] ?? 'Rate limit triggered',
        $violation['retry_at'] ?? '',
        $violation['code'] ?? 'rate_limit'
    );

    http_response_code(429);
    header('Retry-After: 60');
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo $violation['message'] ?? 'Too many requests';
    exit;
}

function wallos_media_start_session()
{
    if (session_status() === PHP_SESSION_NONE) {
        $secondsInMonth = 30 * 24 * 60 * 60;
        ini_set('session.gc_maxlifetime', (string) $secondsInMonth);
        session_set_cookie_params([
            'lifetime' => $secondsInMonth,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function wallos_media_get_authenticated_user($db)
{
    wallos_media_start_session();

    $userId = (int) ($_SESSION['userId'] ?? 0);
    if ($userId > 0) {
        $stmt = $db->prepare('SELECT * FROM user WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $user = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
        if ($user !== false && !wallos_is_user_trashed($user['account_status'] ?? WALLOS_USER_STATUS_ACTIVE)) {
            return $user;
        }
    }

    $cookieValue = $_COOKIE['wallos_login'] ?? '';
    if ($cookieValue === '') {
        return null;
    }

    $parts = explode('|', $cookieValue, 3);
    if (count($parts) < 2) {
        return null;
    }

    $username = trim((string) $parts[0]);
    $token = trim((string) $parts[1]);
    if ($username === '' || $token === '') {
        return null;
    }

    $stmt = $db->prepare('SELECT * FROM user WHERE username = :username LIMIT 1');
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    if ($user === false || wallos_is_user_trashed($user['account_status'] ?? WALLOS_USER_STATUS_ACTIVE)) {
        return null;
    }

    $tokenStmt = $db->prepare('SELECT 1 FROM login_tokens WHERE user_id = :user_id AND token = :token LIMIT 1');
    $tokenStmt->bindValue(':user_id', (int) $user['id'], SQLITE3_INTEGER);
    $tokenStmt->bindValue(':token', $token, SQLITE3_TEXT);
    $tokenResult = $tokenStmt->execute();
    $tokenRow = $tokenResult ? $tokenResult->fetchArray(SQLITE3_NUM) : false;
    if ($tokenRow === false) {
        return null;
    }

    $_SESSION['username'] = $user['username'];
    $_SESSION['token'] = $token;
    $_SESSION['loggedin'] = true;
    $_SESSION['main_currency'] = $user['main_currency'] ?? 1;
    $_SESSION['userId'] = $user['id'];

    return $user;
}

$imageId = (int) ($_GET['id'] ?? 0);
if ($imageId <= 0) {
    wallos_media_deny(404);
}

$db = new SQLite3(__DIR__ . '/../../db/wallos.db');
$db->busyTimeout(5000);
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('PRAGMA synchronous = NORMAL');
$db->exec('PRAGMA foreign_keys = ON');

$currentUser = wallos_media_get_authenticated_user($db);
if ($currentUser === null) {
    wallos_media_deny(403);
}

$lang = 'en';
$userLanguage = trim((string) ($currentUser['language'] ?? ''));
if (array_key_exists($userLanguage, $languages)) {
    $lang = $userLanguage;
}
require_once '../../includes/i18n/getlang.php';
require_once '../../includes/i18n/' . $lang . '.php';

$stmt = $db->prepare('SELECT * FROM subscription_uploaded_images WHERE id = :id LIMIT 1');
$stmt->bindValue(':id', $imageId, SQLITE3_INTEGER);
$result = $stmt->execute();
$image = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
if ($image === false) {
    wallos_media_deny(404);
}

$isAdmin = (int) ($currentUser['id'] ?? 0) === 1;
if (!$isAdmin && (int) ($image['user_id'] ?? 0) !== (int) ($currentUser['id'] ?? 0)) {
    wallos_media_deny(403);
}

$download = (string) ($_GET['download'] ?? '') === '1';
$variant = wallos_normalize_subscription_image_variant($_GET['variant'] ?? 'original');

$relativePath = (string) ($image['path'] ?? '');
if (!$download) {
    if ($variant === 'thumbnail' && !empty($image['thumbnail_path'])) {
        $relativePath = (string) $image['thumbnail_path'];
    } elseif ($variant === 'preview' && !empty($image['preview_path'])) {
        $relativePath = (string) $image['preview_path'];
    }
}

if (!wallos_subscription_image_path_is_within_media_dir($relativePath)) {
    wallos_media_deny(403);
}

$mediaRoot = realpath(__DIR__ . '/../../' . wallos_get_subscription_media_relative_dir());
$absolutePath = realpath(__DIR__ . '/../../' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

if ($absolutePath === false && $relativePath !== (string) ($image['path'] ?? '')) {
    $relativePath = (string) ($image['path'] ?? '');
    $absolutePath = realpath(__DIR__ . '/../../' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
}

if ($mediaRoot === false || $absolutePath === false || !is_file($absolutePath)) {
    wallos_media_deny(404);
}

$normalizedMediaRoot = rtrim(str_replace('\\', '/', $mediaRoot), '/');
$normalizedAbsolutePath = str_replace('\\', '/', $absolutePath);
if (strpos($normalizedAbsolutePath, $normalizedMediaRoot . '/') !== 0) {
    wallos_media_deny(403);
}

$mimeType = trim((string) ($image['mime_type'] ?? ''));
if ($mimeType === '') {
    $detectedMimeType = @mime_content_type($absolutePath);
    $mimeType = is_string($detectedMimeType) && $detectedMimeType !== '' ? $detectedMimeType : 'application/octet-stream';
}

$originalName = trim((string) ($image['original_name'] ?? ''));
$downloadName = $originalName !== '' ? $originalName : (trim((string) ($image['file_name'] ?? '')) ?: ('subscription-image-' . $imageId));
$safeFallbackName = preg_replace('/[^A-Za-z0-9._-]/', '-', $downloadName);
$safeFallbackName = trim((string) $safeFallbackName, '-');
if ($safeFallbackName === '') {
    $safeFallbackName = 'subscription-image-' . $imageId;
}

$fileSize = (int) @filesize($absolutePath);
$rateLimitViolation = wallos_enforce_subscription_image_download_rate_limit(
    $db,
    (int) ($currentUser['id'] ?? 0),
    (string) ($currentUser['username'] ?? ''),
    $i18n,
    $fileSize
);
if ($rateLimitViolation !== null) {
    wallos_media_deny_rate_limit($rateLimitViolation);
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header(
    'Content-Disposition: '
    . ($download ? 'attachment' : 'inline')
    . '; filename="' . $safeFallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName)
);

readfile($absolutePath);
exit;
