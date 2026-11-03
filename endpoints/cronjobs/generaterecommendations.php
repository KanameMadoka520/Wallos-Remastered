<?php
set_time_limit(0);
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/ai_recommendations.php';

$runType = PHP_SAPI === 'cli' ? ($argv[1] ?? 'weekly') : ($_POST['run'] ?? $_GET['run'] ?? 'weekly');
if (!in_array($runType, ['weekly', 'monthly'], true)) $runType = 'weekly';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

// Materialize account IDs before inner reads. A broken/blocked provider only fails its own account.
$stmt = $db->prepare("SELECT DISTINCT a.user_id FROM ai_settings a JOIN user u ON u.id=a.user_id WHERE a.enabled=1 AND a.model!='' AND a.run_schedule=:schedule AND u.account_status='active' ORDER BY a.user_id");
$stmt->bindValue(':schedule', $runType, SQLITE3_TEXT);
$result = $stmt->execute();
$userIds = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) $userIds[] = (int) $row['user_id'];
$stmt->close();
$successes = 0;
$failures = [];
foreach ($userIds as $accountId) {
    $latest = ai_load_settings($db, $accountId);
    if (!$latest || empty($latest['enabled']) || ($latest['run_schedule'] ?? '') !== $runType) continue;
    $response = ai_generate_recommendations($db, $i18n ?? [], $accountId);
    if ($response['success']) $successes++;
    else $failures[] = ['user_id' => $accountId, 'reason' => $response['code'] ?? 'provider_error'];
}
if (PHP_SAPI !== 'cli') header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['success' => true, 'run_type' => $runType, 'processed' => count($userIds), 'successes' => $successes, 'failures' => $failures], JSON_UNESCAPED_SLASHES) . PHP_EOL;
