<?php

function wallos_legacy_migration_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_legacy_migration_memory_db()
{
    $db = new SQLite3(':memory:');
    $db->enableExceptions(true);
    return $db;
}

try {
    $avatarDb = wallos_legacy_migration_memory_db();
    $avatarDb->exec('CREATE TABLE user (id INTEGER PRIMARY KEY, avatar TEXT)');
    $avatarDb->exec("INSERT INTO user (id, avatar) VALUES (1, '1'), (2, '2'), (3, 'custom-avatar')");
    $db = $avatarDb;
    require __DIR__ . '/../migrations/000013.php';
    wallos_legacy_migration_assert(
        $avatarDb->querySingle('SELECT avatar FROM user WHERE id = 1') === 'images/avatars/1.svg'
            && $avatarDb->querySingle('SELECT avatar FROM user WHERE id = 2') === 'images/avatars/2.svg'
            && $avatarDb->querySingle('SELECT avatar FROM user WHERE id = 3') === 'custom-avatar',
        'Migration 000013 must update each legacy avatar independently and preserve custom values.'
    );

    $notificationDb = wallos_legacy_migration_memory_db();
    $notificationDb->exec('CREATE TABLE notifications (
        enabled INTEGER, smtp_address TEXT, smtp_port INTEGER, smtp_username TEXT,
        smtp_password TEXT, from_email TEXT, encryption TEXT, days INTEGER
    )');
    $notificationDb->exec("INSERT INTO notifications VALUES (1, 'smtp.example.test', 465, 'user', 'secret', 'from@example.test', 'ssl', 9)");
    $db = $notificationDb;
    require __DIR__ . '/../migrations/000016.php';
    wallos_legacy_migration_assert(
        $notificationDb->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'notifications'") === null
            && (int) $notificationDb->querySingle('SELECT COUNT(*) FROM notifications_legacy_000016') === 1
            && $notificationDb->querySingle('SELECT smtp_address FROM email_notifications') === 'smtp.example.test'
            && (int) $notificationDb->querySingle('SELECT days FROM notification_settings') === 9,
        'Migration 000016 must copy legacy settings and retain the original table as an archive.'
    );

    $ambiguousNotificationDb = wallos_legacy_migration_memory_db();
    $ambiguousNotificationDb->exec('CREATE TABLE notifications (
        enabled INTEGER, smtp_address TEXT, smtp_port INTEGER, smtp_username TEXT,
        smtp_password TEXT, from_email TEXT, encryption TEXT, days INTEGER
    )');
    $ambiguousNotificationDb->exec("INSERT INTO notifications VALUES (1, 'legacy.example.test', 587, '', '', '', 'tls', 3)");
    $ambiguousNotificationDb->exec('CREATE TABLE email_notifications (
        enabled INTEGER, smtp_address TEXT, smtp_port INTEGER, smtp_username TEXT,
        smtp_password TEXT, from_email TEXT, encryption TEXT
    )');
    $ambiguousNotificationDb->exec("INSERT INTO email_notifications VALUES (1, 'custom.example.test', 587, '', '', '', 'tls')");
    $ambiguousNotificationDb->exec('BEGIN IMMEDIATE');
    $db = $ambiguousNotificationDb;
    $ambiguousRejected = false;
    try {
        require __DIR__ . '/../migrations/000016.php';
        $ambiguousNotificationDb->exec('COMMIT');
    } catch (RuntimeException $runtimeException) {
        $ambiguousNotificationDb->exec('ROLLBACK');
        $ambiguousRejected = strpos($runtimeException->getMessage(), 'refusing to overwrite') !== false;
    }
    wallos_legacy_migration_assert(
        $ambiguousRejected
            && $ambiguousNotificationDb->querySingle('SELECT smtp_address FROM notifications') === 'legacy.example.test'
            && $ambiguousNotificationDb->querySingle('SELECT smtp_address FROM email_notifications') === 'custom.example.test',
        'Migration 000016 must reject ambiguous partial state without changing either settings source.'
    );

    $webhookDb = wallos_legacy_migration_memory_db();
    $webhookDb->exec("CREATE TABLE webhook_notifications (iterator TEXT DEFAULT '')");
    $webhookDb->exec("INSERT INTO webhook_notifications (iterator) VALUES ('custom-iterator')");
    $db = $webhookDb;
    require __DIR__ . '/../migrations/000036.php';
    wallos_legacy_migration_assert(
        $webhookDb->querySingle("SELECT 1 FROM pragma_table_info('webhook_notifications') WHERE name = 'iterator'") !== null
            && $webhookDb->querySingle('SELECT iterator FROM webhook_notifications') === 'custom-iterator',
        'Migration 000036 must retain the legacy webhook iterator data.'
    );

    $uploadedAvatarDb = wallos_legacy_migration_memory_db();
    $uploadedAvatarDb->exec('CREATE TABLE user (id INTEGER PRIMARY KEY, avatar TEXT)');
    $uploadedAvatarDb->exec("INSERT INTO user VALUES
        (1, 'images/uploads/logos/avatars/one.png'),
        (2, 'images/uploads/logos/avatars/two.png')");
    $db = $uploadedAvatarDb;
    require __DIR__ . '/../migrations/000044.php';
    require __DIR__ . '/../migrations/000044.php';
    wallos_legacy_migration_assert(
        (int) $uploadedAvatarDb->querySingle('SELECT COUNT(*) FROM uploaded_avatars') === 2,
        'Migration 000044 must backfill avatar ownership repeatably without duplicate rows.'
    );

    $groupDb = wallos_legacy_migration_memory_db();
    $groupDb->exec('CREATE TABLE user (id INTEGER PRIMARY KEY, user_group TEXT)');
    $groupDb->exec("INSERT INTO user VALUES (1, 'custom-tier')");
    $groupDb->exec('CREATE TABLE subscriptions (id INTEGER PRIMARY KEY, detail_image TEXT, detail_image_urls TEXT)');
    $db = $groupDb;
    require __DIR__ . '/../migrations/000046.php';
    wallos_legacy_migration_assert(
        $groupDb->querySingle('SELECT user_group FROM user WHERE id = 1') === 'custom-tier',
        'Migration 000046 must preserve custom user groups.'
    );

    $accountDb = wallos_legacy_migration_memory_db();
    $accountDb->exec('CREATE TABLE admin (id INTEGER PRIMARY KEY)');
    $accountDb->exec('INSERT INTO admin (id) VALUES (1)');
    $accountDb->exec('CREATE TABLE user (
        id INTEGER PRIMARY KEY, account_status TEXT, trash_reason TEXT,
        trashed_at TEXT, scheduled_delete_at TEXT
    )');
    $accountDb->exec("INSERT INTO user VALUES (1, 'suspended', '', '', '')");
    $accountDb->exec('CREATE TABLE subscriptions (id INTEGER PRIMARY KEY, user_id INTEGER, detail_image TEXT)');
    $db = $accountDb;
    require __DIR__ . '/../migrations/000047.php';
    wallos_legacy_migration_assert(
        $accountDb->querySingle('SELECT account_status FROM user WHERE id = 1') === 'suspended',
        'Migration 000047 must preserve custom account states.'
    );

    $layoutDb = wallos_legacy_migration_memory_db();
    $layoutDb->exec('CREATE TABLE settings (
        subscription_display_columns INTEGER,
        subscription_value_visibility TEXT,
        subscription_image_layout_form TEXT,
        subscription_image_layout_detail TEXT,
        page_transition_enabled INTEGER,
        page_transition_style TEXT
    )');
    $layoutDb->exec("INSERT INTO settings VALUES (4, '{\"custom\":true}', 'carousel', 'masonry', 1, 'custom-transition')");
    $db = $layoutDb;
    require __DIR__ . '/../migrations/000062.php';
    require __DIR__ . '/../migrations/000063.php';
    $layout = $layoutDb->querySingle('SELECT * FROM settings', true);
    wallos_legacy_migration_assert(
        (int) $layout['subscription_display_columns'] === 4
            && $layout['subscription_image_layout_form'] === 'carousel'
            && $layout['subscription_image_layout_detail'] === 'masonry'
            && $layout['page_transition_style'] === 'custom-transition',
        'Display and transition migrations must retain non-empty custom values.'
    );

    $rateLimitDb = wallos_legacy_migration_memory_db();
    $rateLimitDb->exec('CREATE TABLE admin (id INTEGER PRIMARY KEY, login_rate_limit_block_minutes INTEGER)');
    $rateLimitDb->exec('INSERT INTO admin VALUES (1, 30)');
    $rateLimitDb->exec('CREATE TABLE rate_limit_presets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE,
        config_json TEXT,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $rateLimitDb->exec("INSERT INTO rate_limit_presets (name, config_json) VALUES ('custom', '{\"future_extension\":\"keep-me\",\"login_rate_limit_max_attempts\":11}')");
    $db = $rateLimitDb;
    require __DIR__ . '/../migrations/000068.php';
    $preset = json_decode((string) $rateLimitDb->querySingle("SELECT config_json FROM rate_limit_presets WHERE name = 'custom'"), true);
    wallos_legacy_migration_assert(
        is_array($preset)
            && ($preset['future_extension'] ?? '') === 'keep-me'
            && (int) ($preset['login_rate_limit_max_attempts'] ?? 0) === 11,
        'Migration 000068 must preserve unknown rate-limit preset keys.'
    );

    echo "Legacy migration safety tests passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

?>
