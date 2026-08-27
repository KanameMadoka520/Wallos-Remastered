<?php

require_once __DIR__ . '/../includes/currency_update_schedule.php';

function wallos_currency_schedule_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $today = new DateTimeImmutable('2026-08-27');

    wallos_currency_schedule_assert(
        wallos_currency_rates_should_update('2026-08-26', $today),
        'A previous-day exchange marker must be refreshed.'
    );
    wallos_currency_schedule_assert(
        !wallos_currency_rates_should_update('2026-08-27', $today),
        'A current-day exchange marker must not refresh again.'
    );
    wallos_currency_schedule_assert(
        !wallos_currency_rates_should_update('2026-08-28', $today),
        'A future marker must not trigger a refresh loop.'
    );
    foreach ([null, '', 'not-a-date', '2026-02-30'] as $invalidMarker) {
        wallos_currency_schedule_assert(
            wallos_currency_rates_should_update($invalidMarker, $today),
            'A missing or malformed exchange marker must be recoverable.'
        );
    }

    $endpointSource = file_get_contents(__DIR__ . '/../endpoints/currency/update_exchange.php');
    wallos_currency_schedule_assert(
        strpos($endpointSource, "fetchArray(SQLITE3_ASSOC)") !== false
            && strpos($endpointSource, 'wallos_currency_rates_should_update') !== false
            && strpos($endpointSource, 'new DateTime($result)') === false,
        'The non-force exchange endpoint still treats SQLite3Result as a date.'
    );

    echo "Currency update schedule regression test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

?>
