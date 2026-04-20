<?php

function wallos_parse_service_worker_cache_versions($filePath)
{
    $versions = [
        'static' => '',
        'pages' => '',
        'logos' => '',
    ];

    if (!is_file($filePath)) {
        return $versions;
    }

    $contents = (string) @file_get_contents($filePath);
    if ($contents === '') {
        return $versions;
    }

    $patterns = [
        'static' => "/const\\s+STATIC_CACHE\\s*=\\s*'([^']+)'/i",
        'pages' => "/const\\s+PAGES_CACHE\\s*=\\s*'([^']+)'/i",
        'logos' => "/const\\s+LOGOS_CACHE\\s*=\\s*'([^']+)'/i",
    ];

    foreach ($patterns as $key => $pattern) {
        if (preg_match($pattern, $contents, $matches)) {
            $versions[$key] = trim((string) ($matches[1] ?? ''));
        }
    }

    return $versions;
}

function wallos_count_security_anomalies_by_type($db, $anomalyType, $hours = null)
{
    $anomalyType = trim((string) $anomalyType);
    if ($anomalyType === '') {
        return 0;
    }

    $sql = 'SELECT COUNT(*) AS total FROM security_anomalies WHERE anomaly_type = :anomaly_type';
    if ($hours !== null) {
        $sql .= ' AND created_at >= datetime(\'now\', :window)';
    }

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':anomaly_type', $anomalyType, SQLITE3_TEXT);
    if ($hours !== null) {
        $stmt->bindValue(':window', '-' . max(1, (int) $hours) . ' hours', SQLITE3_TEXT);
    }

    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    return (int) ($row['total'] ?? 0);
}
