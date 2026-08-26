<?php

/*
 * Read-only API for the optional weekly, fortnightly, or anchored monthly
 * budget. It accepts the same api_key/apiKey transport as the other v1
 * subscription endpoints and never changes user or subscription data.
 */
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/subscription_trash.php';
require_once '../../includes/budget_period_calculations.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'title' => 'Invalid request method']);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];
$apiKey = $_REQUEST['api_key'] ?? $_REQUEST['apiKey'] ?? $body['api_key'] ?? $body['apiKey'] ?? '';
if ($apiKey === '') {
    echo json_encode(['success' => false, 'title' => 'Missing parameters']);
    exit;
}

$userStmt = $db->prepare('SELECT * FROM user WHERE api_key = :apiKey LIMIT 1');
$userStmt->bindValue(':apiKey', $apiKey, SQLITE3_TEXT);
$userResult = $userStmt->execute();
$user = $userResult ? $userResult->fetchArray(SQLITE3_ASSOC) : false;
if (!$user) {
    echo json_encode(['success' => false, 'title' => 'Invalid API key']);
    exit;
}

$userId = (int) $user['id'];
$referenceRaw = $_REQUEST['reference_date'] ?? $body['reference_date'] ?? '';
if ($referenceRaw === '') {
    $referenceDate = new DateTime('today');
} else {
    $referenceDate = DateTime::createFromFormat('!Y-m-d', (string) $referenceRaw);
    if (!$referenceDate || $referenceDate->format('Y-m-d') !== (string) $referenceRaw) {
        echo json_encode([
            'success' => false,
            'title' => 'Invalid parameter',
            'notes' => ['reference_date must use a valid YYYY-MM-DD date.'],
        ]);
        exit;
    }
}

$periodType = wallos_budget_period_type($user['budget_period_type'] ?? 'monthly');
$anchorDate = wallos_budget_anchor_date($user['budget_period_anchor_date'] ?? '');
$period = wallos_get_active_budget_period($referenceDate, $periodType, $anchorDate);

$subscriptionStmt = $db->prepare(
    'SELECT * FROM subscriptions
     WHERE user_id = :userId AND inactive = 0
       AND lifecycle_status = :status AND exclude_from_stats = 0'
);
$subscriptionStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$subscriptionStmt->bindValue(':status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
$subscriptionResult = $subscriptionStmt->execute();
$subscriptions = [];
while ($subscriptionResult && ($subscription = $subscriptionResult->fetchArray(SQLITE3_ASSOC))) {
    $subscriptions[] = $subscription;
}

$rulesMap = wallos_get_subscription_price_rules_map($db, $userId, true);
$amountNeeded = wallos_budget_period_amount(
    $subscriptions,
    $referenceDate,
    $period['end'],
    $db,
    $userId,
    $rulesMap
);
$amountNeededFullPeriod = wallos_budget_period_amount(
    $subscriptions,
    $period['start'],
    $period['end'],
    $db,
    $userId,
    $rulesMap
);
$periodBudget = max(0, (float) ($user['period_budget'] ?? 0));
$remaining = max(0, round($periodBudget - $amountNeeded, 2));
$over = max(0, round($amountNeeded - $periodBudget, 2));
$isOver = $periodBudget > 0 && $over > 0;

$currencyStmt = $db->prepare(
    'SELECT code, symbol FROM currencies WHERE id = :currencyId AND user_id = :userId LIMIT 1'
);
$currencyStmt->bindValue(':currencyId', (int) ($user['main_currency'] ?? 0), SQLITE3_INTEGER);
$currencyStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$currencyResult = $currencyStmt->execute();
$currency = $currencyResult ? $currencyResult->fetchArray(SQLITE3_ASSOC) : false;

$notes = [];
if ($periodBudget <= 0) {
    $notes[] = 'Period budget is disabled because its value is zero.';
}

echo json_encode([
    'success' => true,
    'title' => 'period_budget',
    'period_budget' => round($periodBudget, 2),
    'amount_needed_this_period' => $amountNeeded,
    'amount_needed_full_period' => $amountNeededFullPeriod,
    'amount_remaining_this_period' => $remaining,
    'amount_over_budget' => $over,
    'is_over_budget' => $isOver,
    'budget_period_type' => $period['type'],
    'budget_period_anchor_date' => $period['anchor_date'],
    'period_start' => $period['start']->format('Y-m-d'),
    'period_end' => $period['end']->format('Y-m-d'),
    'period_label' => $period['label'],
    'reference_date' => $referenceDate->format('Y-m-d'),
    'currency_code' => $currency['code'] ?? null,
    'currency_symbol' => $currency['symbol'] ?? null,
    'notes' => $notes,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$db->close();

?>
