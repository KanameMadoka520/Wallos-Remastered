<?php
require_once __DIR__ . '/../../includes/frankfurter.php';
require_once __DIR__ . '/../../includes/exchange_rate_writer.php';
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/currency_update_schedule.php';

$shouldUpdate = true;

if (isset($_POST['force']) && $_POST['force'] === "true") {
    $shouldUpdate = true;
} else {
    $query = "SELECT date FROM last_exchange_update WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $lastUpdateRow = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    $shouldUpdate = wallos_currency_rates_should_update($lastUpdateRow['date'] ?? null);

    if (!$shouldUpdate) {
        echo "Rates are current, no need to update.";
        exit;
    }
}

$query = "SELECT api_key, provider FROM fixer WHERE user_id = :userId";
$stmt = $db->prepare($query);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();

if ($result) {
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if ($row) {
        $apiKey = $row['api_key'];
        $provider = $row['provider'];

        $codes = "";
        $query = "SELECT id, name, symbol, code FROM currencies WHERE user_id = :userId";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $codes .= $row['code'] . ",";
        }
        $codes = rtrim($codes, ',');
        $query = "SELECT u.main_currency, c.code FROM user u LEFT JOIN currencies c ON u.main_currency = c.id WHERE u.id = :userId";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $mainCurrencyCode = $row['code'];
        $mainCurrencyId = $row['main_currency'];

        if ((int) $provider === 2) {
            $apiData = frankfurter_latest_rates($mainCurrencyCode, $codes);
        } elseif ($provider === 1) {
            $api_url = "https://api.apilayer.com/fixer/latest?base=EUR&symbols=" . $codes;
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => 'apikey: ' . $apiKey,
                ]
            ]);
            $response = file_get_contents($api_url, false, $context);
        } else {
            $api_url = "https://data.fixer.io/api/latest?access_key=" . $apiKey . "&base=EUR&symbols=" . $codes;
            $response = file_get_contents($api_url);
        }

        if ((int) $provider !== 2) { $apiData = json_decode((string) $response, true); }
        $mainCurrencyToEUR = $apiData['rates'][$mainCurrencyCode] ?? null;

        if (!is_array($apiData) || !isset($apiData['rates']) || !is_numeric($mainCurrencyToEUR) || (float) $mainCurrencyToEUR <= 0) {
            echo "Exchange rates update skipped. The provider returned invalid data.";
            exit;
        }

        if (wallos_store_exchange_rates($db, $userId, $mainCurrencyCode, $apiData['rates'])) {
            echo 'Rates updated successfully!';
        } else {
            echo 'Exchange rates update rolled back.';
        }
    } else {
        echo "Exchange rates update skipped. No fixer.io api key provided";
        $apiKey = null;
    }
} else {
    echo "Exchange rates update skipped. No fixer.io api key provided";
    $apiKey = null;
}
