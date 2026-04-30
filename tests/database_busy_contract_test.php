<?php

require_once __DIR__ . '/../includes/i18n/languages.php';
require_once __DIR__ . '/../includes/i18n/getlang.php';
require_once __DIR__ . '/../includes/i18n/zh_cn.php';
require_once __DIR__ . '/../includes/database_errors.php';

function wallos_database_busy_contract_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    wallos_database_busy_contract_assert(
        wallos_database_message_is_busy('Unable to execute statement: database is locked'),
        'Expected database-is-locked messages to be detected.'
    );
    wallos_database_busy_contract_assert(
        wallos_database_message_is_busy('SQLITE_BUSY: database is busy'),
        'Expected SQLITE_BUSY messages to be detected.'
    );
    wallos_database_busy_contract_assert(
        wallos_database_message_is_busy('SQLITE_LOCKED: database table is locked'),
        'Expected SQLITE_LOCKED messages to be detected.'
    );
    wallos_database_busy_contract_assert(
        !wallos_database_message_is_busy('Subscription not found.'),
        'Non-database errors should not be classified as database busy.'
    );

    $throwable = new RuntimeException('database is locked');
    wallos_database_busy_contract_assert(
        wallos_database_throwable_is_busy($throwable),
        'Expected busy throwable detection to work.'
    );

    $payload = wallos_database_build_busy_payload($i18n);
    wallos_database_busy_contract_assert($payload['success'] === false, 'Busy payload must be an error.');
    wallos_database_busy_contract_assert($payload['code'] === 'database_busy', 'Busy payload must expose code=database_busy.');
    wallos_database_busy_contract_assert($payload['error'] === 'database_busy', 'Busy payload must expose error=database_busy.');
    wallos_database_busy_contract_assert($payload['database_busy'] === true, 'Busy payload must expose database_busy=true.');
    wallos_database_busy_contract_assert((int) $payload['retry_after_seconds'] > 0, 'Busy payload must include a retry hint.');
    wallos_database_busy_contract_assert(trim((string) $payload['message']) !== '', 'Busy payload must include a user-facing message.');

    echo 'Database busy contract checks passed.' . PHP_EOL;
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
