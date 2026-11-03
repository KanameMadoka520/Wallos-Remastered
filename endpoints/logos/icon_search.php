<?php

require_once __DIR__ . '/../../includes/connect_endpoint.php';
wallos_endpoint_require_authenticated($i18n);
require_once __DIR__ . '/../../includes/validate_endpoint.php';
require_once __DIR__ . '/../../includes/logo_catalog.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
try {
    $request = wallos_logo_catalog_validate_request($_POST);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['success' => false, 'code' => 'invalid_catalog_search', 'message' => $error->getMessage()]);
    exit;
}
// Public upstream fetches must not retain the user's PHP session lock.
session_write_close();
$catalog = wallos_logo_catalog_load($request['source']);
if ($catalog === null) {
    http_response_code(502);
    echo json_encode(['success' => false, 'code' => 'logo_catalog_unavailable', 'message' => 'Icon catalog is temporarily unavailable.']);
    exit;
}
$response = wallos_logo_catalog_search($catalog['items'], $request);
$response['stale'] = $catalog['stale'];
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
