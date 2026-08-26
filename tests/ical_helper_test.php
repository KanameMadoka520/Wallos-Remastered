<?php

require_once __DIR__ . '/../includes/ical_helper.php';

try {
    $input = "Name, value; path\\segment\r\nBEGIN:VEVENT\nSUMMARY:Injected";
    $escaped = icalEscape($input);

    if (strpos($escaped, "\r") !== false || strpos($escaped, "\n") !== false) {
        throw new RuntimeException('Escaped iCalendar values must not contain raw line breaks.');
    }
    if (strpos($escaped, '\\nBEGIN:VEVENT\\nSUMMARY:Injected') === false) {
        throw new RuntimeException('Line breaks must be represented as literal RFC 5545 escapes.');
    }
    if (strpos($escaped, 'Name\\, value\\; path\\\\segment') !== 0) {
        throw new RuntimeException('Backslashes, commas and semicolons must be escaped.');
    }

    $maliciousCurrency = "$\r\nBEGIN:VEVENT\r\nSUMMARY:Injected currency";
    $currency = icalEscape($maliciousCurrency);
    $description = "Price: {$currency}9.99\\nCategory: Test";

    if (strpos($description, "\r") !== false || strpos($description, "\n") !== false) {
        throw new RuntimeException('A custom currency must not introduce raw iCalendar property lines.');
    }
    if (strpos($description, '$\\nBEGIN:VEVENT\\nSUMMARY:Injected currency9.99') === false) {
        throw new RuntimeException('Currency CRLF must remain literal text inside DESCRIPTION.');
    }

    echo "iCalendar escaping test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

?>
