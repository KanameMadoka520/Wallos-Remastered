<?php
/*
This API Endpoint accepts both POST and GET requests.
It receives the following parameters:
- month: the month for which the cost is to be calculated (integer).
- year: the year for which the cost is to be calculated (integer).
- api_key: the API key of the user (string).

It returns a JSON object with the following properties:
- success: whether the request was successful (boolean).
- title: a string with "${month} ${year}" (e.g., "March 2025").
- monthly_cost: a float with the total cost for the given month.
- localized_monthly_cost: a string with the total cost formatted according to the user's locale and currency.
- currency_code: a string with the currency code of the user's main currency.
- currency_symbol: a string with the currency symbol of the user's main currency.
- notes: warning messages or additional information (array).

Example response:
{
  "success": true,
  "title": "March 2025",
  "monthly_cost": "120.24",
  "localized_monthly_cost": "€120.24",
  "currency_code": "EUR",
  "currency_symbol": "€",
  "notes": []
}
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/subscription_trash.php';
require_once '../../includes/currency_rates.php';
require_once '../../includes/calendar_calculations.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER["REQUEST_METHOD"] === "POST" || $_SERVER["REQUEST_METHOD"] === "GET") {
    // if the parameters are not set, return an error

    $apiKey = $_REQUEST['api_key'] ?? $_REQUEST['apiKey'] ?? null;

    if (!$apiKey || !isset($_REQUEST['month']) || !isset($_REQUEST['year'])) {
        $response = [
            "success" => false,
            "title" => "Missing parameters"
        ];
        echo json_encode($response);
        exit;
    }

    $month = $_REQUEST['month'];
    $year = $_REQUEST['year'];

    $sql = "SELECT * FROM user WHERE api_key = :apiKey";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':apiKey', $apiKey);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    // If the user is not found or the API key is invalid, return an error
    if (!$user) {
        echo json_encode([
            "success" => false,
            "title" => "Invalid API key",
            "notes" => ["User not found or API key invalid."]
        ]);
        exit;
    }

    $userId = $user['id'];
    $userCurrencyId = $user['main_currency'];
    $needsCurrencyConversion = false;
    $sql = "SELECT date FROM last_exchange_update WHERE user_id = :userId LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $lastExchangeUpdate = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    $canConvertCurrency = empty($lastExchangeUpdate['date']) ? false : true;

    $sql = "SELECT * FROM currencies WHERE id = :currencyId AND user_id = :userId";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':currencyId', $userCurrencyId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $currency = $result->fetchArray(SQLITE3_ASSOC);
    $currency_code = $currency['code'];
    $currency_symbol = $currency['symbol'];


    $title = date('F Y', strtotime($year . '-' . $month . '-01'));
    $monthlyCost = 0;
    $notes = [];
    $currencies = [];

    $sql = "SELECT * FROM subscriptions WHERE user_id = :userId AND inactive = 0 AND lifecycle_status = :lifecycle_status AND exclude_from_stats = 0";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':userId', $userId);
    $stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
    $result = $stmt->execute();
    $subscriptions = [];
    while ($subscription = $result->fetchArray(SQLITE3_ASSOC)) {
        $subscriptions[] = $subscription;
        if ($subscription['currency_id'] !== $userCurrencyId) {
            $needsCurrencyConversion = true;
        }
    }

    if ($needsCurrencyConversion) {
        if (!$canConvertCurrency) {
            $notes[] = "You are using multiple currencies, but the exchange rates have not been updated yet. Please check your Fixer API Key.";
        } else {
            $sql = "SELECT * FROM currencies WHERE user_id = :userId";
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':userId', $userId);
            $result = $stmt->execute();
            $currencies = wallos_currency_rates($db, $userId);
        }
    }

    // Use the same recurrence projection as the calendar and budget views so
    // month-end and leap-day anchors cannot drift between API responses.
    foreach ($subscriptions as $subscription) {
        $nextPaymentTimestamp = wallos_calendar_parse_date($subscription['next_payment'] ?? '');
        $yearsToLoad = $nextPaymentTimestamp === false
            ? 1
            : max(1, (int) $year - (int) date('Y', $nextPaymentTimestamp) + 1);
        $paymentDates = wallos_calendar_get_payment_dates(
            $subscription,
            (int) $year,
            (int) $month,
            $yearsToLoad
        );

        foreach ($paymentDates as $paymentDate) {
            $price = $subscription['price'];
            if (
                (int) $userCurrencyId !== (int) $subscription['currency_id']
                && $canConvertCurrency
                && isset($currencies[(int) $subscription['currency_id']])
                && (float) $currencies[(int) $subscription['currency_id']] > 0
            ) {
                $price = wallos_convert_price($price, $subscription['currency_id'], $db, $userId);
            }
            $monthlyCost += $price;
        }
    }

    $formatter = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
    $localizedMonthlyCost = $formatter->formatCurrency($monthlyCost, $currency_code);

    echo json_encode([
        'success' => true,
        'title' => $title,
        'monthly_cost' => number_format($monthlyCost, 2),
        'localized_monthly_cost' => $localizedMonthlyCost,
        'currency_code' => $currency_code,
        'currency_symbol' => $currency_symbol,
        'notes' => $notes
    ], JSON_UNESCAPED_UNICODE);

}
?>
