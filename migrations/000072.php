<?php

$wallosMigration72QuoteIdentifier = function ($identifier) {
    return '"' . str_replace('"', '""', (string) $identifier) . '"';
};

$wallosMigration72TableColumns = function ($db, $tableName) use ($wallosMigration72QuoteIdentifier) {
    $tableExists = (bool) $db->querySingle(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . SQLite3::escapeString($tableName) . "'"
    );
    if (!$tableExists) {
        return [];
    }

    $columns = [];
    $result = $db->query('PRAGMA table_info(' . $wallosMigration72QuoteIdentifier($tableName) . ')');
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $columns[] = (string) ($row['name'] ?? '');
    }

    return $columns;
};

$wallosMigration72Indexes = [
    ['subscriptions', 'idx_subscriptions_user_status_sort', ['user_id', 'lifecycle_status', 'sort_order', 'id']],
    ['subscriptions', 'idx_subscriptions_user_page_status_sort', ['user_id', 'subscription_page_id', 'lifecycle_status', 'sort_order', 'id']],
    ['subscriptions', 'idx_subscriptions_user_status_next_payment', ['user_id', 'lifecycle_status', 'inactive', 'next_payment', 'id']],
    ['subscriptions', 'idx_subscriptions_user_status_stats', ['user_id', 'lifecycle_status', 'inactive', 'exclude_from_stats', 'next_payment', 'id']],
    ['subscriptions', 'idx_subscriptions_user_status_category', ['user_id', 'lifecycle_status', 'category_id']],
    ['subscriptions', 'idx_subscriptions_user_status_payment', ['user_id', 'lifecycle_status', 'payment_method_id']],
    ['subscriptions', 'idx_subscriptions_user_status_payer', ['user_id', 'lifecycle_status', 'payer_user_id']],
    ['subscriptions', 'idx_subscriptions_user_status_trash', ['user_id', 'lifecycle_status', 'trashed_at', 'id']],
    ['subscriptions', 'idx_subscriptions_status_scheduled_delete', ['lifecycle_status', 'scheduled_delete_at', 'id']],
    ['subscription_uploaded_images', 'idx_subscription_uploaded_images_user_subscription_sort', ['user_id', 'subscription_id', 'sort_order', 'id']],
    ['subscription_uploaded_images', 'idx_subscription_uploaded_images_subscription_user_sort', ['subscription_id', 'user_id', 'sort_order', 'id']],
    ['subscription_uploaded_images', 'idx_subscription_uploaded_images_user_sequence', ['user_id', 'upload_sequence']],
    ['subscription_uploaded_images', 'idx_subscription_uploaded_images_path', ['path']],
    ['subscription_uploaded_images', 'idx_subscription_uploaded_images_preview_path', ['preview_path']],
    ['subscription_uploaded_images', 'idx_subscription_uploaded_images_thumbnail_path', ['thumbnail_path']],
    ['subscription_payment_records', 'idx_subscription_payment_records_subscription_user_paid', ['subscription_id', 'user_id', 'paid_at', 'id']],
    ['subscription_payment_records', 'idx_subscription_payment_records_user_subscription_paid', ['user_id', 'subscription_id', 'paid_at', 'id']],
    ['subscription_payment_records', 'idx_subscription_payment_records_user_status_paid', ['user_id', 'status', 'paid_at', 'id']],
    ['subscription_payment_records', 'idx_subscription_payment_records_subscription_due_status', ['subscription_id', 'user_id', 'due_date', 'status', 'paid_at', 'id']],
    ['subscription_price_rules', 'idx_subscription_price_rules_user_subscription_priority', ['user_id', 'subscription_id', 'enabled', 'priority', 'id']],
    ['request_logs', 'idx_request_logs_created_id', ['created_at', 'id']],
    ['request_logs', 'idx_request_logs_method_created_id', ['method', 'created_at', 'id']],
    ['request_logs', 'idx_request_logs_user_created_id', ['user_id', 'created_at', 'id']],
    ['security_anomalies', 'idx_security_anomalies_created_id', ['created_at', 'id']],
    ['security_anomalies', 'idx_security_anomalies_type_created_id', ['anomaly_type', 'created_at', 'id']],
    ['security_anomalies', 'idx_security_anomalies_user_created_id', ['user_id', 'created_at', 'id']],
    ['rate_limit_usage', 'idx_rate_limit_usage_user_category_created', ['user_id', 'category', 'created_at']],
    ['rate_limit_usage', 'idx_rate_limit_usage_created_id', ['created_at', 'id']],
    ['user', 'idx_user_status_scheduled_delete', ['account_status', 'scheduled_delete_at', 'id']],
];

foreach ($wallosMigration72Indexes as $indexDefinition) {
    [$tableName, $indexName, $columns] = $indexDefinition;
    $availableColumns = $wallosMigration72TableColumns($db, $tableName);
    if (empty($availableColumns)) {
        continue;
    }

    $missingColumn = false;
    foreach ($columns as $column) {
        if (!in_array($column, $availableColumns, true)) {
            $missingColumn = true;
            break;
        }
    }
    if ($missingColumn) {
        continue;
    }

    $quotedColumns = array_map($wallosMigration72QuoteIdentifier, $columns);
    $statement = sprintf(
        'CREATE INDEX IF NOT EXISTS %s ON %s(%s)',
        $wallosMigration72QuoteIdentifier($indexName),
        $wallosMigration72QuoteIdentifier($tableName),
        implode(', ', $quotedColumns)
    );
    if (!$db->exec($statement)) {
        throw new RuntimeException('Failed to create SQLite index ' . $indexName . ': ' . $db->lastErrorMsg());
    }
}
