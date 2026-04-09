<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/user_status.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$openRegistrations = $data['open_registrations'];
$maxUsers = $data['max_users'];
$requireEmailVerification = $data['require_email_validation'];
$serverUrl = $data['server_url'];
$disableLogin = $data['disable_login'];
$inviteOnlyRegistration = (int) ($data['invite_only_registration'] ?? 0);

if ($disableLogin == 1) {
    if ($openRegistrations == 1) {
        echo json_encode([
            "success" => false,
            "message" => translate('error', $i18n)
        ]);
        die();
    }

    $sql = "SELECT COUNT(*) as userCount FROM user WHERE account_status = :account_status";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':account_status', WALLOS_USER_STATUS_ACTIVE, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $userCount = $row['userCount'];

    if ($userCount > 1) {
        echo json_encode([
            "success" => false,
            "message" => translate('error', $i18n)
        ]);
        die();
    }
}

if ($requireEmailVerification == 1 && $serverUrl == "") {
    echo json_encode([
        "success" => false,
        "message" => translate('fill_all_fields', $i18n)
    ]);
    die();
}

$sql = "UPDATE admin SET registrations_open = :openRegistrations, max_users = :maxUsers, require_email_verification = :requireEmailVerification, server_url = :serverUrl, login_disabled = :disableLogin, invite_only_registration = :inviteOnlyRegistration WHERE id = 1";
$stmt = $db->prepare($sql);
$stmt->bindParam(':openRegistrations', $openRegistrations, SQLITE3_INTEGER);
$stmt->bindParam(':maxUsers', $maxUsers, SQLITE3_INTEGER);
$stmt->bindParam(':requireEmailVerification', $requireEmailVerification, SQLITE3_INTEGER);
$stmt->bindParam(':serverUrl', $serverUrl, SQLITE3_TEXT);
$stmt->bindParam(':disableLogin', $disableLogin, SQLITE3_INTEGER);
$stmt->bindParam(':inviteOnlyRegistration', $inviteOnlyRegistration, SQLITE3_INTEGER);
$result = $stmt->execute();

if ($result) {
    echo json_encode([
        "success" => true,
        "message" => translate('success', $i18n)
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]);
}
