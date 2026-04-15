<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';

$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);
if (!is_array($data)) {
    $data = [];
}

$requestId = max(0, (int) ($data['request_id'] ?? 0));
$keyword = trim((string) ($data['keyword'] ?? ''));
$method = strtoupper(trim((string) ($data['method'] ?? '')));
$limit = max(20, min(500, (int) ($data['limit'] ?? 100)));
$startAt = trim((string) ($data['start_at'] ?? ''));
$endAt = trim((string) ($data['end_at'] ?? ''));

function wallos_normalize_access_log_filter_datetime($value)
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

$allowedMethods = ['', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
if (!in_array($method, $allowedMethods, true)) {
    $method = '';
}

$conditions = [];
$params = [];

if ($requestId > 0) {
    $conditions[] = 'id = :requestId';
    $params[':requestId'] = [$requestId, SQLITE3_INTEGER];
}

if ($keyword !== '') {
    $conditions[] = '(
        CAST(id AS TEXT) LIKE :keyword
        OR COALESCE(username, \'\') LIKE :keyword
        OR COALESCE(path, \'\') LIKE :keyword
        OR COALESCE(ip_address, \'\') LIKE :keyword
        OR COALESCE(forwarded_for, \'\') LIKE :keyword
        OR COALESCE(user_agent, \'\') LIKE :keyword
        OR COALESCE(headers_json, \'\') LIKE :keyword
    )';
    $params[':keyword'] = ['%' . $keyword . '%', SQLITE3_TEXT];
}

if ($method !== '') {
    $conditions[] = 'method = :method';
    $params[':method'] = [$method, SQLITE3_TEXT];
}

$normalizedStartAt = wallos_normalize_access_log_filter_datetime($startAt);
if ($normalizedStartAt !== '') {
    $conditions[] = 'created_at >= :startAt';
    $params[':startAt'] = [$normalizedStartAt, SQLITE3_TEXT];
}

$normalizedEndAt = wallos_normalize_access_log_filter_datetime($endAt);
if ($normalizedEndAt !== '') {
    $conditions[] = 'created_at <= :endAt';
    $params[':endAt'] = [$normalizedEndAt, SQLITE3_TEXT];
}

$whereSql = '';
if (!empty($conditions)) {
    $whereSql = 'WHERE ' . implode(' AND ', $conditions);
}

$countStmt = $db->prepare('SELECT COUNT(*) AS total FROM request_logs ' . $whereSql);
foreach ($params as $name => [$value, $type]) {
    $countStmt->bindValue($name, $value, $type);
}
$countResult = $countStmt->execute();
$countRow = $countResult ? $countResult->fetchArray(SQLITE3_ASSOC) : false;
$total = (int) ($countRow['total'] ?? 0);

$query = '
    SELECT id, user_id, username, path, method, ip_address, forwarded_for, user_agent, headers_json, created_at
    FROM request_logs
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

$logs = [];
while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
    $logs[] = $row;
}

echo json_encode([
    'success' => true,
    'logs' => $logs,
    'total' => $total,
    'limit' => $limit,
    'filters' => [
        'request_id' => $requestId,
        'keyword' => $keyword,
        'method' => $method,
        'start_at' => $normalizedStartAt,
        'end_at' => $normalizedEndAt,
        'limit' => $limit,
    ],
]);
