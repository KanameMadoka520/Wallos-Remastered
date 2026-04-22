<?php

require_once __DIR__ . '/../theme_cookie_sync.php';
require_once __DIR__ . '/../request_security.php';

if (!isset($userData)) {
    die("User data missing for OIDC login.");
}

$userId = $userData['id'];
$username = $userData['username'];
$language = $userData['language'];
$main_currency = $userData['main_currency'];

$_SESSION['username'] = $username;
$_SESSION['loggedin'] = true;
$_SESSION['main_currency'] = $main_currency;
$_SESSION['userId'] = $userId;
$_SESSION['from_oidc'] = true; // Indicate this session is from OIDC login

$cookieExpire = time() + (86400 * 30); // 30 days

// generate remember token
$token = bin2hex(random_bytes(32));
$addLoginTokens = "INSERT INTO login_tokens (user_id, token) VALUES (:userId, :token)";
$addLoginTokensStmt = $db->prepare($addLoginTokens);
$addLoginTokensStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$addLoginTokensStmt->bindParam(':token', $token, SQLITE3_TEXT);
$addLoginTokensStmt->execute();

$_SESSION['token'] = $token;
$cookieValue = $username . "|" . $token . "|" . $main_currency;
setcookie('wallos_login', $cookieValue, wallos_build_cookie_options($cookieExpire, ['httponly' => true]));

// Set language cookie
setcookie('language', $language, wallos_build_cookie_options($cookieExpire));

// Set sort order default
if (!isset($_COOKIE['sortOrder'])) {
    setcookie('sortOrder', 'manual_order', wallos_build_cookie_options($cookieExpire));
}

wallos_sync_theme_cookies_for_user($db, $userId, $cookieExpire);

// Done
$db->close();
header("Location: .");
exit();
