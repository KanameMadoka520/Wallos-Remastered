<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';

$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);
if (!is_array($data)) {
    $data = [];
}

$anomalyType = trim((string) ($data['anomaly_type'] ?? ''));
$keyword = trim((string) ($data['keyword'] ?? ''));
$limit = max(20, min(500, (int) ($data['limit'] ?? 100)));
$startAt = trim((string) ($data['start_at'] ?? ''));
$endAt = trim((string) ($data['end_at'] ?? ''));

function wallos_normalize_security_filter_datetime($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $dateTime = DateTime::createFromFormat('Y-m-d\TH:i', $value);
    if (!$dateTime) {
        $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $value);
    }

    return $dateTime ? $dateTime->format('Y-m-d H:i:s') : '';
}

$conditions = [];
$params = [];

if ($anomalyType !== '') {
    $conditions[] = 'anomaly_type = :anomalyType';
    $params[':anomalyType'] = [$anomalyType, SQLITE3_TEXT];
}

if ($keyword !== '') {
    $conditions[] = '(
        CAST(id AS TEXT) LIKE :keyword
        OR COALESCE(username, \'\') LIKE :keyword
        OR COALESCE(anomaly_type, \'\') LIKE :keyword
        OR COALESCE(anomaly_code, \'\') LIKE :keyword
        OR COALESCE(message, \'\') LIKE :keyword
        OR COALESCE(ip_address, \'\') LIKE :keyword
        OR COALESCE(forwarded_for, \'\') LIKE :keyword
        OR COALESCE(user_agent, \'\') LIKE :keyword
        OR COALESCE(headers_json, \'\') LIKE :keyword
        OR COALESCE(details_json, \'\') LIKE :keyword
    )';
    $params[':keyword'] = ['%' . $keyword . '%', SQLITE3_TEXT];
}

$normalizedStartAt = wallos_normalize_security_filter_datetime($startAt);
if ($normalizedStartAt !== '') {
    $conditions[] = 'created_at >= :startAt';
    $params[':startAt'] = [$normalizedStartAt, SQLITE3_TEXT];
}

$normalizedEndAt = wallos_normalize_security_filter_datetime($endAt);
if ($normalizedEndAt !== '') {
    $conditions[] = 'created_at <= :endAt';
    $params[':endAt'] = [$normalizedEndAt, SQLITE3_TEXT];
}

$whereSql = '';
if (!empty($conditions)) {
    $whereSql = 'WHERE ' . implode(' AND ', $conditions);
}

$countStmt = $db->prepare('SELECT COUNT(*) AS total FROM security_anomalies ' . $whereSql);
foreach ($params as $name => [$value, $type]) {
    $countStmt->bindValue($name, $value, $type);
}
$countResult = $countStmt->execute();
$countRow = $countResult ? $countResult->fetchArray(SQLITE3_ASSOC) : false;
$total = (int) ($countRow['total'] ?? 0);

$query = '
    SELECT id, user_id, username, anomaly_type, anomaly_code, message, path, method, ip_address, forwarded_for, user_agent, headers_json, details_json, created_at
    FROM security_anomalies
    ' . $whereSql . '
    ORDER BY id DESC
    LIMIT :limit
';
$stmt = $db->prepare($query);
foreach ($params as $name => [$value, $type]) {
    $stmt->bindValue($name, $value, $type);
}
$stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
$result = $stmt->execute();

$items = [];
while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
    $items[] = $row;
}

echo json_encode([
    'success' => true,
    'items' => $items,
    'total' => $total,
    'limit' => $limit,
    'filters' => [
        'anomaly_type' => $anomalyType,
        'keyword' => $keyword,
        'start_at' => $normalizedStartAt,
        'end_at' => $normalizedEndAt,
        'limit' => $limit,
    ],
]);
