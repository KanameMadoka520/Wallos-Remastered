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

function wallos_security_anomalies_table_exists($db)
{
    return (bool) $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='security_anomalies'");
}

function wallos_count_security_anomalies($db, $hours = null)
{
    if (!wallos_security_anomalies_table_exists($db)) {
        return 0;
    }

    $sql = 'SELECT COUNT(*) AS total FROM security_anomalies';
    if ($hours !== null) {
        $sql .= ' WHERE created_at >= datetime(\'now\', :window)';
    }

    $stmt = $db->prepare($sql);
    if ($hours !== null) {
        $stmt->bindValue(':window', '-' . max(1, (int) $hours) . ' hours', SQLITE3_TEXT);
    }

    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    return (int) ($row['total'] ?? 0);
}

function wallos_get_security_anomaly_type_counts($db, $hours = 24)
{
    if (!wallos_security_anomalies_table_exists($db)) {
        return [];
    }

    $stmt = $db->prepare('
        SELECT anomaly_type, COUNT(*) AS total
        FROM security_anomalies
        WHERE created_at >= datetime(\'now\', :window)
        GROUP BY anomaly_type
        ORDER BY total DESC, anomaly_type ASC
    ');
    $stmt->bindValue(':window', '-' . max(1, (int) $hours) . ' hours', SQLITE3_TEXT);
    $result = $stmt->execute();

    $counts = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $type = trim((string) ($row['anomaly_type'] ?? ''));
        if ($type !== '') {
            $counts[$type] = (int) ($row['total'] ?? 0);
        }
    }

    return $counts;
}

function wallos_get_recent_security_anomalies($db, $limit = 6, $hours = 24, array $types = [])
{
    if (!wallos_security_anomalies_table_exists($db)) {
        return [];
    }

    $limit = max(1, min(20, (int) $limit));
    $conditions = ['created_at >= datetime(\'now\', :window)'];
    $params = [
        ':window' => ['-' . max(1, (int) $hours) . ' hours', SQLITE3_TEXT],
    ];

    $normalizedTypes = array_values(array_filter(array_map(static function ($type) {
        return trim((string) $type);
    }, $types)));

    if (!empty($normalizedTypes)) {
        $placeholders = [];
        foreach ($normalizedTypes as $index => $type) {
            $placeholder = ':type' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = [$type, SQLITE3_TEXT];
        }
        $conditions[] = 'anomaly_type IN (' . implode(', ', $placeholders) . ')';
    }

    $stmt = $db->prepare('
        SELECT id, user_id, username, anomaly_type, anomaly_code, message, path, method, ip_address, forwarded_for, created_at
        FROM security_anomalies
        WHERE ' . implode(' AND ', $conditions) . '
        ORDER BY id DESC
        LIMIT :limit
    ');

    foreach ($params as $name => [$value, $type]) {
        $stmt->bindValue($name, $value, $type);
    }
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);

    $result = $stmt->execute();
    $items = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $items[] = $row;
    }

    return $items;
}

function wallos_format_observability_timestamp($value, $timezone = 'UTC')
{
    $value = trim((string) $value);
    if ($value === '') {
        return '-';
    }

    try {
        $dateTime = new DateTimeImmutable($value);
        $targetTimezone = new DateTimeZone($timezone !== '' ? $timezone : 'UTC');
        return $dateTime->setTimezone($targetTimezone)->format('Y-m-d H:i:s T');
    } catch (Throwable $throwable) {
        return $value;
    }
}

function wallos_summarize_security_anomaly_type_counts(array $counts)
{
    if (empty($counts)) {
        return '-';
    }

    $parts = [];
    foreach ($counts as $type => $total) {
        $parts[] = (string) $type . '=' . (int) $total;
    }

    return implode(' | ', $parts);
}
