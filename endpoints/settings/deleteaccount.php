<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/user_data_cleanup.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$userIdToDelete = $data['userId'];

if ($userIdToDelete == 1 || $userIdToDelete != $userId) {
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
} else {
    try {
        wallos_delete_user_data($db, $userIdToDelete, __DIR__ . '/../../');
    } catch (Throwable $throwable) {
        die(json_encode([
            "success" => false,
            "message" => translate('error', $i18n)
        ]));
    }

    die(json_encode([
        "success" => true,
        "message" => translate('success', $i18n)
    ]));

}
