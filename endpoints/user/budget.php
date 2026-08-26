<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/inputvalidation.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/budget_period_calculations.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$data = is_array($data) ? $data : [];
$budget = isset($data["budget"]) && is_numeric($data["budget"]) ? round((float) $data["budget"], 2) : 0;
$yearlyBudget = isset($data["yearly_budget"]) && is_numeric($data["yearly_budget"]) ? round((float) $data["yearly_budget"], 2) : 0;
$periodBudget = isset($data["period_budget"]) && is_numeric($data["period_budget"]) ? round((float) $data["period_budget"], 2) : 0;

if ($budget < 0 || $yearlyBudget < 0 || $periodBudget < 0) {
    echo json_encode([
        "success" => false,
        "message" => translate('fill_mandatory_fields', $i18n),
    ]);
    exit();
}

$sets = ['budget = :budget', 'yearly_budget = :yearly_budget'];
$hasPeriodPayload = array_key_exists('period_budget', $data)
    || array_key_exists('budget_period_type', $data)
    || array_key_exists('budget_period_anchor_date', $data);
if ($hasPeriodPayload) {
    $sets[] = 'period_budget = :period_budget';
    $sets[] = 'budget_period_type = :budget_period_type';
    $sets[] = 'budget_period_anchor_date = :budget_period_anchor_date';
}

$sql = "UPDATE user SET " . implode(', ', $sets) . " WHERE id = :userId";
$stmt = $db->prepare($sql);
$stmt->bindValue(':budget', $budget, SQLITE3_FLOAT);
$stmt->bindValue(':yearly_budget', $yearlyBudget, SQLITE3_FLOAT);
if ($hasPeriodPayload) {
    $stmt->bindValue(':period_budget', $periodBudget, SQLITE3_FLOAT);
    $stmt->bindValue(':budget_period_type', wallos_budget_period_type($data['budget_period_type'] ?? 'monthly'), SQLITE3_TEXT);
    $stmt->bindValue(':budget_period_anchor_date', wallos_budget_anchor_date($data['budget_period_anchor_date'] ?? ''), SQLITE3_TEXT);
}
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
