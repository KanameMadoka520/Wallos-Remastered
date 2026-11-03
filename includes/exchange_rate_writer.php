<?php

/** Store a validated provider response and its freshness marker atomically. */
function wallos_store_exchange_rates($db, $userId, $base, array $rates)
{
    $baseRate = $rates[$base] ?? null;
    if (!is_numeric($baseRate) || !is_finite((float) $baseRate) || (float) $baseRate <= 0) {
        return false;
    }
    $converted = [];
    foreach ($rates as $code => $rate) {
        if (preg_match('/^[A-Z]{3}$/', (string) $code) !== 1 || !is_numeric($rate)
            || !is_finite((float) $rate) || (float) $rate <= 0) {
            return false;
        }
        $value = $code === $base ? 1.0 : (float) $rate / (float) $baseRate;
        if (!is_finite($value) || $value <= 0) {
            return false;
        }
        $converted[$code] = $value;
    }
    if (!$db->exec('BEGIN IMMEDIATE')) {
        return false;
    }
    try {
        $stmt = $db->prepare('UPDATE currencies SET rate = :rate WHERE code = :code AND user_id = :userId');
        foreach ($converted as $code => $rate) {
            $stmt->bindValue(':rate', $rate, SQLITE3_FLOAT);
            $stmt->bindValue(':code', $code, SQLITE3_TEXT);
            $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
            if ($stmt->execute() === false) {
                throw new RuntimeException('Currency write failed.');
            }
            $stmt->reset();
        }
        $delete = $db->prepare('DELETE FROM last_exchange_update WHERE user_id = :uid');
        $delete->bindValue(':uid', (int) $userId, SQLITE3_INTEGER);
        if ($delete->execute() === false) {
            throw new RuntimeException('Freshness marker write failed.');
        }
        $insert = $db->prepare('INSERT INTO last_exchange_update(date, user_id) VALUES(:date, :uid)');
        $insert->bindValue(':date', date('Y-m-d'), SQLITE3_TEXT);
        $insert->bindValue(':uid', (int) $userId, SQLITE3_INTEGER);
        if ($insert->execute() === false || !$db->exec('COMMIT')) {
            throw new RuntimeException('Freshness marker write failed.');
        }
        return true;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        error_log('Wallos: exchange update rolled back.');
        return false;
    }
}
