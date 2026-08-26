<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$shouldUpdate = true;

if (isset($_POST['force']) && $_POST['force'] === "true") {
    $shouldUpdate = true;
} else {
    $query = "SELECT date FROM last_exchange_update WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($result) {
        $lastUpdateDate = new DateTime($result);
        $currentDate = new DateTime();
        $lastUpdateDateString = $lastUpdateDate->format('Y-m-d');
        $currentDateString = $currentDate->format('Y-m-d');
        $shouldUpdate = $lastUpdateDateString < $currentDateString;
    }

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

        if ($provider === 1) {
            $api_url = "https://api.apilayer.com/fixer/latest?base=EUR&symbols=" . $codes;
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => 'apikey: ' . $apiKey,
                ]
            ]);
            $response = file_get_contents($api_url, false, $context);
        } else {
            $api_url = "http://data.fixer.io/api/latest?access_key=" . $apiKey . "&base=EUR&symbols=" . $codes;
            $response = file_get_contents($api_url);
        }

        $apiData = json_decode((string) $response, true);
        $mainCurrencyToEUR = $apiData['rates'][$mainCurrencyCode] ?? null;

        if (!is_array($apiData) || !isset($apiData['rates']) || !is_numeric($mainCurrencyToEUR) || (float) $mainCurrencyToEUR <= 0) {
            echo "Exchange rates update skipped. The provider returned invalid data.";
            exit;
        }

        $db->exec('BEGIN IMMEDIATE');
        $updateStmt = $db->prepare("UPDATE currencies SET rate = :rate WHERE code = :code AND user_id = :userId");
        $updateFailed = false;
        foreach ($apiData['rates'] as $currencyCode => $rate) {
            if (!is_numeric($rate)) {
                $updateFailed = true;
                break;
            }
            $exchangeRate = $currencyCode === $mainCurrencyCode ? 1.0 : ((float) $rate / (float) $mainCurrencyToEUR);
            $updateStmt->bindValue(':rate', $exchangeRate, SQLITE3_FLOAT);
            $updateStmt->bindValue(':code', $currencyCode, SQLITE3_TEXT);
            $updateStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $updateResult = $updateStmt->execute();
            $updateStmt->reset();
            if (!$updateResult) {
                $updateFailed = true;
                break;
            }
        }

        if ($updateFailed) {
            $db->exec('ROLLBACK');
            echo "Exchange rates update rolled back.";
        } else {
            $currentDate = new DateTime();
            $formattedDate = $currentDate->format('Y-m-d');

            $updateQuery = "UPDATE last_exchange_update SET date = :formattedDate WHERE user_id = :userId";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':formattedDate', $formattedDate, SQLITE3_TEXT);
            $updateStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
            $updateResult = $updateStmt->execute();

            $db->exec('COMMIT');
            $db->close();
            echo "Rates updated successfully!";
        }
    } else {
        echo "Exchange rates update skipped. No fixer.io api key provided";
        $apiKey = null;
    }
} else {
    echo "Exchange rates update skipped. No fixer.io api key provided";
    $apiKey = null;
}
