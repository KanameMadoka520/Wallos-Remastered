<?php

require_once __DIR__ . '/../includes/currency_rates.php';

function wallos_currency_rates_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = new SQLite3(':memory:');

try {
    $db->exec('CREATE TABLE currencies (id INTEGER PRIMARY KEY, user_id INTEGER, rate REAL)');
    $db->exec('INSERT INTO currencies (id, user_id, rate) VALUES
        (1, 10, 1.0),
        (2, 10, 2.0),
        (3, 10, 0.0),
        (4, 10, -2.0),
        (5, 11, 4.0)');

    wallos_currency_rates_assert(
        abs(wallos_convert_price(20, 2, $db, 10) - 10.0) < 0.0001,
        'A positive user-scoped rate should convert the amount.'
    );
    wallos_currency_rates_assert(
        abs(wallos_convert_price(20, 5, $db, 10) - 20.0) < 0.0001,
        'A currency owned by another user must not be visible in a scoped lookup.'
    );
    wallos_currency_rates_assert(
        abs(wallos_convert_price(20, 99, $db, 10) - 20.0) < 0.0001,
        'A missing rate should preserve the original amount.'
    );
    wallos_currency_rates_assert(
        abs(wallos_convert_price(20, 3, $db, 10) - 20.0) < 0.0001,
        'A zero rate should preserve the original amount.'
    );
    wallos_currency_rates_assert(
        abs(wallos_convert_price(20, 4, $db, 10) - 20.0) < 0.0001,
        'A negative rate should preserve the original amount.'
    );

    echo "Currency rate cache test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $db->close();
}

?>
