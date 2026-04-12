<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/subscription_payment_records.php';
require_once '../../includes/subscription_trash.php';
require_once '../../includes/markdown.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode([
        'success' => false,
        'message' => translate('session_expired', $i18n),
    ]);
    exit;
}

$subscriptionId = (int) ($_GET['id'] ?? 0);
if ($subscriptionId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
    exit;
}

$stmt = $db->prepare('SELECT id, name FROM subscriptions WHERE id = :id AND user_id = :user_id AND lifecycle_status = :lifecycle_status');
$stmt->bindValue(':id', $subscriptionId, SQLITE3_INTEGER);
$stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
$result = $stmt->execute();
$subscription = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

if ($subscription === false) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
    exit;
}

$records = wallos_get_subscription_payment_records($db, $subscriptionId, $userId, 0);
foreach ($records as &$record) {
    $record['note_html'] = wallos_render_markdown($record['note'] ?? '');
}
unset($record);

echo json_encode([
    'success' => true,
    'subscription' => [
        'id' => (int) $subscription['id'],
        'name' => htmlspecialchars_decode($subscription['name'] ?? '', ENT_QUOTES),
    ],
    'records' => $records,
]);

$db->close();
