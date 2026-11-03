<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/instance_config.php';

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
}
$configuration = wallos_get_effective_admin_configuration($db);
$fieldMap = [
    'smtpaddress' => 'smtp_address', 'smtpport' => 'smtp_port',
    'encryption' => 'encryption', 'smtpusername' => 'smtp_username',
    'smtppassword' => 'smtp_password', 'fromemail' => 'from_email',
];
$effective = $configuration['settings'];
$changes = [];
foreach ($fieldMap as $field => $column) {
    if (isset($configuration['managed_fields'][$column])) {
        continue;
    }
    if (isset($data[$field]) && !is_scalar($data[$field])) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
    }
    $effective[$column] = (string) ($data[$field] ?? '');
    $changes[$column] = $effective[$column];
}
$port = filter_var($effective['smtp_port'] ?? '', FILTER_VALIDATE_INT);
$encryption = $effective['encryption'] ?? 'tls';
if (empty($effective['smtp_address']) || $port === false || $port < 1 || $port > 65535
    || !in_array($encryption, ['none', 'tls', 'ssl'], true)) {
    die(json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]));
}
if (!validate_smtp_host($effective['smtp_address'], $port, $db)) {
    die(json_encode(['success' => false, 'message' => 'Security Error: invalid SMTP target.']));
}
$result = true;
if ($changes) {
    $assignments = array_map(fn($column) => "$column = :$column", array_keys($changes));
    $stmt = $db->prepare('UPDATE admin SET ' . implode(', ', $assignments) . ' WHERE id = 1');
    foreach ($changes as $column => $value) {
        $stmt->bindValue(':' . $column, $value, SQLITE3_TEXT);
    }
    $result = $stmt->execute() !== false;
}
echo json_encode(['success' => $result, 'message' => translate($result ? 'success' : 'error', $i18n)]);
