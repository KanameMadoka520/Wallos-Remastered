<?php

require_once __DIR__ . '/../includes/default_user_seed.php';

$userQuery = $db->query("SELECT id, language FROM user WHERE language IN ('zh_cn', 'zh_tw')");

if (!$userQuery) {
    return;
}

$db->exec('BEGIN IMMEDIATE');

$categoryUpdate = $db->prepare('UPDATE categories SET name = :new_name WHERE user_id = :user_id AND name = :old_name');
$paymentUpdate = $db->prepare('UPDATE payment_methods SET name = :new_name WHERE user_id = :user_id AND name = :old_name');
$currencyUpdate = $db->prepare('UPDATE currencies SET name = :new_name WHERE user_id = :user_id AND name = :old_name');

while ($user = $userQuery->fetchArray(SQLITE3_ASSOC)) {
    $userId = (int) $user['id'];
    $language = $user['language'];

    $englishCategories = wallos_get_default_categories('en');
    $localizedCategories = wallos_get_default_categories($language);
    foreach ($englishCategories as $index => $category) {
        $oldName = $category['name'];
        $newName = $localizedCategories[$index]['name'];

        if ($oldName === $newName) {
            continue;
        }

        $categoryUpdate->bindValue(':new_name', $newName, SQLITE3_TEXT);
        $categoryUpdate->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $categoryUpdate->bindValue(':old_name', $oldName, SQLITE3_TEXT);
        $categoryUpdate->execute();
    }

    $englishPayments = wallos_get_default_payment_methods('en');
    $localizedPayments = wallos_get_default_payment_methods($language);
    foreach ($englishPayments as $index => $paymentMethod) {
        $oldName = $paymentMethod['name'];
        $newName = $localizedPayments[$index]['name'];

        if ($oldName === $newName) {
            continue;
        }

        $paymentUpdate->bindValue(':new_name', $newName, SQLITE3_TEXT);
        $paymentUpdate->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $paymentUpdate->bindValue(':old_name', $oldName, SQLITE3_TEXT);
        $paymentUpdate->execute();
    }

    $englishCurrencies = wallos_get_default_currencies('en');
    $localizedCurrencies = wallos_get_default_currencies($language);
    foreach ($englishCurrencies as $index => $currency) {
        $oldName = $currency['name'];
        $newName = $localizedCurrencies[$index]['name'];

        if ($oldName === $newName) {
            continue;
        }

        $currencyUpdate->bindValue(':new_name', $newName, SQLITE3_TEXT);
        $currencyUpdate->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $currencyUpdate->bindValue(':old_name', $oldName, SQLITE3_TEXT);
        $currencyUpdate->execute();
    }
}

$db->exec('COMMIT');
