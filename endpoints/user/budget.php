<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/inputvalidation.php';
require_once '../../includes/validate_endpoint.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$budget = isset($data["budget"]) && is_numeric($data["budget"]) ? round((float) $data["budget"], 2) : 0;
$yearlyBudget = isset($data["yearly_budget"]) && is_numeric($data["yearly_budget"]) ? round((float) $data["yearly_budget"], 2) : 0;

if ($budget < 0 || $yearlyBudget < 0) {
    echo json_encode([
        "success" => false,
        "message" => translate('fill_mandatory_fields', $i18n),
    ]);
    exit();
}

$sql = "UPDATE user SET budget = :budget, yearly_budget = :yearly_budget WHERE id = :userId";
$stmt = $db->prepare($sql);
$stmt->bindValue(':budget', $budget, SQLITE3_FLOAT);
$stmt->bindValue(':yearly_budget', $yearlyBudget, SQLITE3_FLOAT);
$stmt->bindValue(':userId', $userId, SQLITE3_TEXT);
$result = $stmt->execute();

if ($result) {
    $response = [
        "success" => true,
        "message" => translate('user_details_saved', $i18n)
    ];
    echo json_encode($response);
} else {
    $response = [
        "success" => false,
        "message" => translate('error_updating_user_data', $i18n)
    ];
    echo json_encode($response);
}


?>
