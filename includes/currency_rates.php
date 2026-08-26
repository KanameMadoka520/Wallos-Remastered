<?php
/*
 * Request-scoped exchange-rate lookups.
 *
 * Subscription, calendar and statistics pages convert the same currencies
 * repeatedly. Load each user's rates once per SQLite connection so rendering
 * a page does not issue one query for every subscription row.
 */

/**
 * Return exchange rates keyed by currency id.
 *
 * The optional user id keeps the lookup scope identical to the old SQL
 * queries. A WeakMap keys the cache by the connection object, preventing a
 * closed connection's rates from leaking into a later request or test.
 *
 * @param SQLite3    $db
 * @param int|null   $userId
 * @return array<int, float>
 */
function wallos_currency_rates($db, $userId = null)
{
    static $cache;

    if ($cache === null) {
        $cache = new WeakMap();
    }

    $scope = $userId === null ? 'all' : (int) $userId;
    $connectionRates = $cache[$db] ?? [];
    if (array_key_exists($scope, $connectionRates)) {
        return $connectionRates[$scope];
    }

    if ($userId === null) {
        $stmt = $db->prepare('SELECT id, rate FROM currencies');
    } else {
        $stmt = $db->prepare('SELECT id, rate FROM currencies WHERE user_id = :userId');
        if ($stmt) {
            $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
        }
    }

    $rates = [];
    $result = $stmt ? $stmt->execute() : false;
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $rates[(int) $row['id']] = (float) $row['rate'];
    }

    $connectionRates[$scope] = $rates;
    $cache[$db] = $connectionRates;
    return $rates;
}

/**
 * Convert a price to the user's main currency.
 *
 * Unknown, missing, zero or negative rates leave the original amount intact;
 * this is the safe behaviour used by the existing pages.
 *
 * @param float|int|string $price
 * @param int              $currencyId
 * @param SQLite3          $db
 * @param int|null         $userId
 * @return float
 */
function wallos_convert_price($price, $currencyId, $db, $userId = null)
{
    $rate = wallos_currency_rates($db, $userId)[(int) $currencyId] ?? null;
    if ($rate === null || !is_finite((float) $rate) || (float) $rate <= 0) {
        return (float) $price;
    }

    return (float) $price / (float) $rate;
}

?>
