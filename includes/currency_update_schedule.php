<?php

/**
 * Decide whether a user's exchange rates need refreshing today.
 *
 * Missing or malformed stored dates are treated as stale. This keeps a bad
 * marker recoverable and avoids passing SQLite3Result objects to DateTime.
 */
function wallos_currency_rates_should_update($storedDate, DateTimeInterface $today = null)
{
    $storedDate = trim((string) $storedDate);
    if ($storedDate === '') {
        return true;
    }

    $lastUpdate = DateTimeImmutable::createFromFormat('!Y-m-d', $storedDate);
    $parseErrors = DateTimeImmutable::getLastErrors();
    if (!$lastUpdate
        || ($parseErrors !== false
            && ((int) ($parseErrors['warning_count'] ?? 0) > 0
                || (int) ($parseErrors['error_count'] ?? 0) > 0))
        || $lastUpdate->format('Y-m-d') !== $storedDate) {
        return true;
    }

    $today = $today ?: new DateTimeImmutable('today');
    $todayDate = DateTimeImmutable::createFromFormat('!Y-m-d', $today->format('Y-m-d'));

    return $lastUpdate < $todayDate;
}

?>
