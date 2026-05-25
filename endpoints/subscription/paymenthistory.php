<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/subscription_payment_records.php';
require_once '../../includes/subscription_payment_history.php';
require_once '../../includes/subscription_price_rules.php';
require_once '../../includes/subscription_trash.php';
require_once '../../includes/markdown.php';

wallos_endpoint_require_authenticated($i18n);

$subscriptionId = (int) ($_GET['id'] ?? 0);
if ($subscriptionId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
    exit;
}

$stmt = $db->prepare('
    SELECT id, name, price, currency_id, cycle, frequency, start_date, next_payment, payment_method_id
    FROM subscriptions
    WHERE id = :id AND user_id = :user_id AND lifecycle_status = :lifecycle_status
');
$stmt->bindValue(':id', $subscriptionId, SQLITE3_INTEGER);
$stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
$result = $stmt->execute();
$subscription = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

if ($subscription === false) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
    exit;
}

$currencies = [];
$currencyStmt = $db->prepare('SELECT id, code, name, symbol FROM currencies WHERE user_id = :user_id');
$currencyStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$currencyResult = $currencyStmt->execute();
while ($currencyResult && ($row = $currencyResult->fetchArray(SQLITE3_ASSOC))) {
    $currencies[(int) $row['id']] = $row;
}

$records = wallos_get_subscription_payment_records($db, $subscriptionId, $userId, 0);
$priceRules = wallos_get_subscription_price_rules($db, $subscriptionId, $userId, true);
$records = wallos_enrich_subscription_payment_records_with_rule_replay($db, $subscription, $userId, $records, $priceRules, $currencies, $i18n);
$remainingValue = wallos_build_subscription_remaining_value_snapshot($db, $subscription, $userId, $priceRules, $records, $currencies, $i18n);

foreach ($records as &$record) {
    $record['note_html'] = wallos_render_markdown($record['note'] ?? '');
}
unset($record);

$today = new DateTime('today');
$availableYears = wallos_build_subscription_payment_history_available_years($subscription, $records, $today);
$currentYear = (int) $today->format('Y');
$selectedYear = (int) ($_GET['year'] ?? $currentYear);
if (!in_array($selectedYear, $availableYears, true)) {
    $selectedYear = $currentYear;
}

$allowedRanges = [6, 12, 24, 36];
$selectedRangeMonths = (int) ($_GET['range'] ?? 12);
if (!in_array($selectedRangeMonths, $allowedRanges, true)) {
    $selectedRangeMonths = 12;
}

$currentYear = (int) date('Y');
$yearStart = new DateTime($currentYear . '-01-01');
$yearEnd = new DateTime($currentYear . '-12-31');
$paidDueDates = wallos_get_subscription_paid_due_dates_from_records($records);
$summaryForecast = wallos_build_subscription_future_payment_forecast($db, $subscription, $userId, $priceRules, $paidDueDates, $currencies, $i18n, 600, $today, $yearEnd);

$forecastEndDate = clone $today;
$forecastEndDate->add(new DateInterval('P' . $selectedRangeMonths . 'M'));
$forecast = wallos_build_subscription_future_payment_forecast($db, $subscription, $userId, $priceRules, $paidDueDates, $currencies, $i18n, 600, $today, $forecastEndDate);

$selectedYearStart = new DateTime($selectedYear . '-01-01');
$selectedYearEnd = new DateTime($selectedYear . '-12-31');
$selectedYearForecast = wallos_build_subscription_future_payment_forecast($db, $subscription, $userId, $priceRules, $paidDueDates, $currencies, $i18n, 600, $selectedYearStart, $selectedYearEnd);
$cashflow = wallos_build_subscription_yearly_cashflow($records, $selectedYearForecast, $selectedYear);

$actualThisYearTotal = 0.0;
$actualThisYearRecords = [];
foreach ($records as $record) {
    $paidAt = trim((string) ($record['paid_at'] ?? ''));
    if ($paidAt === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidAt)) {
        continue;
    }

    if ((int) substr($paidAt, 0, 4) !== $currentYear) {
        continue;
    }

    $actualThisYearTotal += (float) ($record['amount_main_snapshot'] ?? 0);
    $actualThisYearRecords[] = $record;
}

$predictedRemainingTotal = 0.0;
foreach ($summaryForecast as $forecastItem) {
    $predictedRemainingTotal += (float) ($forecastItem['amount_main'] ?? 0);
}

$investedTotal = 0.0;
foreach ($records as $record) {
    $investedTotal += (float) ($record['amount_main_snapshot'] ?? 0);
}

$projectedOriginalItems = [];
foreach ($actualThisYearRecords as $record) {
    $projectedOriginalItems[] = [
        'amount' => (float) ($record['amount_original'] ?? 0),
        'currency_code' => (string) ($record['currency_code_snapshot'] ?? ''),
    ];
}
foreach ($summaryForecast as $forecastItem) {
    $projectedOriginalItems[] = [
        'amount' => (float) ($forecastItem['amount_original'] ?? 0),
        'currency_code' => (string) ($forecastItem['currency_code'] ?? ''),
    ];
}

$investedOriginalTotal = wallos_build_single_currency_original_total($records, 'amount_original', 'currency_code_snapshot');
$actualThisYearOriginalTotal = wallos_build_single_currency_original_total($actualThisYearRecords, 'amount_original', 'currency_code_snapshot');
$predictedRemainingOriginalTotal = wallos_build_single_currency_original_total($summaryForecast, 'amount_original', 'currency_code');
$projectedOriginalTotal = wallos_build_single_currency_original_total($projectedOriginalItems, 'amount', 'currency_code');

$investedTotalMainCurrencyAvailable = wallos_all_payment_main_conversions_are_available($records);
$actualThisYearTotalMainCurrencyAvailable = wallos_all_payment_main_conversions_are_available($actualThisYearRecords);
$predictedRemainingTotalMainCurrencyAvailable = wallos_all_payment_main_conversions_are_available($summaryForecast);
$projectedTotalMainCurrencyAvailable = $actualThisYearTotalMainCurrencyAvailable && $predictedRemainingTotalMainCurrencyAvailable;

$hasUnavailableExchangeSnapshots = false;
foreach (array_merge($records, $forecast, $summaryForecast, $selectedYearForecast, [$remainingValue]) as $paymentItem) {
    if (array_key_exists('main_currency_conversion_available', $paymentItem)
        && empty($paymentItem['main_currency_conversion_available'])
    ) {
        $hasUnavailableExchangeSnapshots = true;
        break;
    }
}

echo json_encode([
    'success' => true,
    'subscription' => [
        'id' => (int) $subscription['id'],
        'name' => htmlspecialchars_decode($subscription['name'] ?? '', ENT_QUOTES),
        'next_payment' => (string) ($subscription['next_payment'] ?? ''),
    ],
    'filters' => [
        'available_years' => $availableYears,
        'selected_year' => $selectedYear,
        'selected_range_months' => $selectedRangeMonths,
    ],
    'summary' => [
        'record_count' => count($records),
        'invested_total' => round($investedTotal, 2),
        'actual_this_year_total' => round($actualThisYearTotal, 2),
        'predicted_remaining_total' => round($predictedRemainingTotal, 2),
        'projected_total' => round($actualThisYearTotal + $predictedRemainingTotal, 2),
        'invested_total_main_currency_available' => $investedTotalMainCurrencyAvailable,
        'actual_this_year_total_main_currency_available' => $actualThisYearTotalMainCurrencyAvailable,
        'predicted_remaining_total_main_currency_available' => $predictedRemainingTotalMainCurrencyAvailable,
        'projected_total_main_currency_available' => $projectedTotalMainCurrencyAvailable,
        'invested_total_original' => $investedOriginalTotal,
        'actual_this_year_total_original' => $actualThisYearOriginalTotal,
        'predicted_remaining_total_original' => $predictedRemainingOriginalTotal,
        'projected_total_original' => $projectedOriginalTotal,
        'current_year' => $currentYear,
        'remaining_value' => $remainingValue,
        'has_unavailable_exchange_snapshots' => $hasUnavailableExchangeSnapshots,
    ],
    'cashflow' => $cashflow,
    'forecast' => $forecast,
    'records' => $records,
]);

$db->close();
