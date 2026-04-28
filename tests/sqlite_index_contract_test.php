<?php

function wallos_sqlite_index_contract_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_sqlite_index_contract_index_exists($db, $tableName, $indexName)
{
    $result = $db->query("PRAGMA index_list('" . SQLite3::escapeString($tableName) . "')");
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        if (($row['name'] ?? '') === $indexName) {
            return true;
        }
    }

    return false;
}

require_once __DIR__ . '/../includes/system_maintenance.php';

$db = new SQLite3(':memory:');

try {
    $db->exec('
        CREATE TABLE subscriptions (
            id INTEGER,
            user_id INTEGER,
            lifecycle_status TEXT,
            sort_order INTEGER,
            subscription_page_id INTEGER,
            inactive BOOLEAN,
            next_payment DATE,
            exclude_from_stats BOOLEAN,
            category_id INTEGER,
            payment_method_id INTEGER,
            payer_user_id INTEGER,
            trashed_at TEXT,
            scheduled_delete_at TEXT
        )
    ');
    $db->exec('
        CREATE TABLE subscription_uploaded_images (
            id INTEGER,
            user_id INTEGER,
            subscription_id INTEGER,
            path TEXT,
            preview_path TEXT,
            thumbnail_path TEXT,
            upload_sequence INTEGER,
            sort_order INTEGER
        )
    ');
    $db->exec('
        CREATE TABLE subscription_payment_records (
            id INTEGER,
            user_id INTEGER,
            subscription_id INTEGER,
            due_date TEXT,
            paid_at TEXT,
            status TEXT
        )
    ');
    $db->exec('
        CREATE TABLE subscription_price_rules (
            id INTEGER,
            user_id INTEGER,
            subscription_id INTEGER,
            enabled BOOLEAN,
            priority INTEGER
        )
    ');
    $db->exec('
        CREATE TABLE request_logs (
            id INTEGER,
            user_id INTEGER,
            method TEXT,
            created_at TEXT
        )
    ');
    $db->exec('
        CREATE TABLE security_anomalies (
            id INTEGER,
            user_id INTEGER,
            anomaly_type TEXT,
            created_at TEXT
        )
    ');
    $db->exec('
        CREATE TABLE rate_limit_usage (
            id INTEGER,
            user_id INTEGER,
            category TEXT,
            created_at TEXT
        )
    ');
    $db->exec('
        CREATE TABLE user (
            id INTEGER,
            account_status TEXT,
            scheduled_delete_at TEXT
        )
    ');

    $migration71Path = __DIR__ . '/../migrations/000071.php';
    $migration72Path = __DIR__ . '/../migrations/000072.php';
    require $migration71Path;
    require $migration72Path;
    require $migration72Path;

    $expectedIndexes = [
        'subscriptions' => [
            'idx_subscriptions_user_status_sort',
            'idx_subscriptions_user_page_status_sort',
            'idx_subscriptions_user_status_next_payment',
            'idx_subscriptions_user_status_stats',
            'idx_subscriptions_user_status_category',
            'idx_subscriptions_user_status_payment',
            'idx_subscriptions_user_status_payer',
            'idx_subscriptions_user_status_trash',
            'idx_subscriptions_status_scheduled_delete',
        ],
        'subscription_uploaded_images' => [
            'idx_subscription_uploaded_images_user_subscription_sort',
            'idx_subscription_uploaded_images_subscription_user_sort',
            'idx_subscription_uploaded_images_user_sequence',
            'idx_subscription_uploaded_images_path',
            'idx_subscription_uploaded_images_preview_path',
            'idx_subscription_uploaded_images_thumbnail_path',
        ],
        'subscription_payment_records' => [
            'idx_subscription_payment_records_subscription_user_paid',
            'idx_subscription_payment_records_user_subscription_paid',
            'idx_subscription_payment_records_user_status_paid',
            'idx_subscription_payment_records_subscription_due_status',
        ],
        'subscription_price_rules' => [
            'idx_subscription_price_rules_user_subscription_priority',
        ],
        'request_logs' => [
            'idx_request_logs_created_id',
            'idx_request_logs_method_created_id',
            'idx_request_logs_user_created_id',
        ],
        'security_anomalies' => [
            'idx_security_anomalies_created_id',
            'idx_security_anomalies_type_created_id',
            'idx_security_anomalies_user_created_id',
        ],
        'rate_limit_usage' => [
            'idx_rate_limit_usage_user_category_created',
            'idx_rate_limit_usage_created_id',
        ],
        'user' => [
            'idx_user_status_scheduled_delete',
        ],
    ];

    foreach ($expectedIndexes as $tableName => $indexes) {
        foreach ($indexes as $indexName) {
            wallos_sqlite_index_contract_assert(
                wallos_sqlite_index_contract_index_exists($db, $tableName, $indexName),
                'Missing expected SQLite index ' . $indexName . ' on table ' . $tableName . '.'
            );
        }
    }

    $health = wallos_check_sqlite_index_health($db);
    wallos_sqlite_index_contract_assert(!empty($health['success']), 'SQLite index health helper should pass on a migrated database.');
    wallos_sqlite_index_contract_assert((int) ($health['missing_indexes'] ?? -1) === 0, 'SQLite index health helper reported missing indexes.');
    wallos_sqlite_index_contract_assert((int) ($health['invalid_indexes'] ?? -1) === 0, 'SQLite index health helper reported invalid indexes.');

    echo 'SQLite index migration contract checks passed.' . PHP_EOL;
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $db->close();
}
