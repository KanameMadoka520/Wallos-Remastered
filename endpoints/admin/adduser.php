<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/default_user_seed.php';

function validate($value)
{
    $value = trim((string) $value);
    $value = stripslashes($value);
    $value = htmlspecialchars($value);
    $value = htmlentities($value);
    return $value;
}

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);

$loggedInUserId = $userId;

$email = validate($data['email'] ?? '');
$username = validate($data['username'] ?? '');
$password = (string) ($data['password'] ?? '');

if ($username === '' || $password === '' || $email === '') {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n)
    ]));
}

$stmt = $db->prepare('SELECT COUNT(*) FROM user WHERE username = :username OR email = :email');
$stmt->bindValue(':username', $username, SQLITE3_TEXT);
$stmt->bindValue(':email', $email, SQLITE3_TEXT);
$result = $stmt->execute();
$row = $result ? $result->fetchArray(SQLITE3_NUM) : false;

if (($row[0] ?? 0) > 0) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n)
    ]));
}

$stmt = $db->prepare('SELECT main_currency, language FROM user WHERE id = :id');
$stmt->bindValue(':id', $loggedInUserId, SQLITE3_INTEGER);
$result = $stmt->execute();
$adminUser = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

$language = wallos_resolve_supported_language($adminUser['language'] ?? 'en', ['en', 'zh_cn', 'zh_tw']);
$avatar = 'images/avatars/0.svg';
$seedCurrencies = wallos_get_default_currencies($language);
$seedCategories = wallos_get_default_categories($language);
$seedPaymentMethods = wallos_get_default_payment_methods($language);

$mainCurrencyCode = 'EUR';
$mainCurrencyId = (int) ($adminUser['main_currency'] ?? 1);
$stmt = $db->prepare('SELECT code FROM currencies WHERE id = :id');
$stmt->bindValue(':id', $mainCurrencyId, SQLITE3_INTEGER);
$currencyResult = $stmt->execute();
$currencyRow = $currencyResult ? $currencyResult->fetchArray(SQLITE3_ASSOC) : false;
if (!empty($currencyRow['code'])) {
    $mainCurrencyCode = $currencyRow['code'];
}

$query = 'INSERT INTO user (username, email, password, main_currency, avatar, language, budget) VALUES (:username, :email, :password, :main_currency, :avatar, :language, :budget)';
$stmt = $db->prepare($query);
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$stmt->bindValue(':username', $username, SQLITE3_TEXT);
$stmt->bindValue(':email', $email, SQLITE3_TEXT);
$stmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
$stmt->bindValue(':main_currency', 1, SQLITE3_INTEGER);
$stmt->bindValue(':avatar', $avatar, SQLITE3_TEXT);
$stmt->bindValue(':language', $language, SQLITE3_TEXT);
$stmt->bindValue(':budget', 0, SQLITE3_INTEGER);
$result = $stmt->execute();

if (!$result) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n)
    ]));
}

$newUserId = $db->lastInsertRowID();

$query = 'INSERT INTO household (name, user_id) VALUES (:name, :user_id)';
$stmt = $db->prepare($query);
$stmt->bindValue(':name', $username, SQLITE3_TEXT);
$stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
$stmt->execute();

if ($newUserId > 1) {
    $query = 'INSERT INTO categories (name, "order", user_id) VALUES (:name, :order, :user_id)';
    $stmt = $db->prepare($query);
    foreach ($seedCategories as $index => $category) {
        $stmt->bindValue(':name', $category['name'], SQLITE3_TEXT);
        $stmt->bindValue(':order', $index + 1, SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    $query = 'INSERT INTO payment_methods (name, icon, "order", user_id) VALUES (:name, :icon, :order, :user_id)';
    $stmt = $db->prepare($query);
    foreach ($seedPaymentMethods as $index => $paymentMethod) {
        $stmt->bindValue(':name', $paymentMethod['name'], SQLITE3_TEXT);
        $stmt->bindValue(':icon', $paymentMethod['icon'], SQLITE3_TEXT);
        $stmt->bindValue(':order', $index + 1, SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    $query = 'INSERT INTO currencies (name, symbol, code, rate, user_id) VALUES (:name, :symbol, :code, :rate, :user_id)';
    $stmt = $db->prepare($query);
    foreach ($seedCurrencies as $currency) {
        $stmt->bindValue(':name', $currency['name'], SQLITE3_TEXT);
        $stmt->bindValue(':symbol', $currency['symbol'], SQLITE3_TEXT);
        $stmt->bindValue(':code', $currency['code'], SQLITE3_TEXT);
        $stmt->bindValue(':rate', 1, SQLITE3_FLOAT);
        $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    $query = 'SELECT id FROM currencies WHERE code = :code AND user_id = :user_id';
    $stmt = $db->prepare($query);
    $stmt->bindValue(':code', $mainCurrencyCode, SQLITE3_TEXT);
    $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $currency = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    if ($currency !== false && isset($currency['id'])) {
        $query = 'UPDATE user SET main_currency = :main_currency WHERE id = :user_id';
        $stmt = $db->prepare($query);
        $stmt->bindValue(':main_currency', $currency['id'], SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    $query = "INSERT INTO settings (dark_theme, monthly_price, convert_currency, remove_background, color_theme, hide_disabled, user_id, disabled_to_bottom, show_original_price, mobile_nav)
              VALUES (2, 0, 0, 0, 'blue', 0, :user_id, 0, 0, 0)";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
    $stmt->execute();
}

$db->close();

die(json_encode([
    'success' => true,
    'message' => translate('success', $i18n)
]));
