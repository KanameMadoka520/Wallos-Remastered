<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/backup_manager.php';

if ((int) $userId !== 1) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden';
    exit;
}

$backup = wallos_find_backup_by_name($_GET['name'] ?? '', __DIR__ . '/../../');
if ($backup === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not Found';
    exit;
}

$downloadName = $backup['name'];
$safeFallbackName = preg_replace('/[^A-Za-z0-9._-]/', '-', $downloadName);
$safeFallbackName = trim((string) $safeFallbackName, '-');
if ($safeFallbackName === '') {
    $safeFallbackName = 'wallos-backup.zip';
}

header('Content-Type: application/zip');
header('Content-Length: ' . filesize($backup['path']));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $safeFallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));

readfile($backup['path']);
exit;
