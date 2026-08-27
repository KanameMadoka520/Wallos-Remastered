<?php

require_once __DIR__ . '/budget_period_calculations.php';
require_once __DIR__ . '/currency_formatter.php';
require_once __DIR__ . '/subscription_payment_records.php';
require_once __DIR__ . '/subscription_price_rules.php';
require_once __DIR__ . '/subscription_trash.php';

function wallos_format_period_summary_price($price, $currencyCode, $currencySymbol)
{
    $formatted = CurrencyFormatter::format((float) $price, (string) $currencyCode);
    if ($currencyCode !== '' && strpos($formatted, (string) $currencyCode) !== false) {
        $formatted = str_replace((string) $currencyCode, (string) $currencySymbol . ' ', $formatted);
        $formatted = preg_replace('/\s+/', ' ', $formatted);
    }

    return trim((string) $formatted);
}

function wallos_build_notification_message($name, array $subscriptions, $periodSummaryLine = '', $includePeriodSummary = false)
{
    $periodSummaryLine = trim((string) $periodSummaryLine);
    $includePeriodSummary = (bool) $includePeriodSummary && $periodSummaryLine !== '';
    if (!$subscriptions && !$includePeriodSummary) {
        return '';
    }

    if (!$subscriptions) {
        return (trim((string) $name) !== '' ? trim((string) $name) . ', ' : '')
            . $periodSummaryLine . "\n";
    }

    $message = trim((string) $name) !== ''
        ? trim((string) $name) . ", the following subscriptions are up for renewal:\n"
        : "The following subscriptions are up for renewal:\n";
    foreach ($subscriptions as $subscription) {
        $days = (int) ($subscription['days'] ?? 0);
        $dayText = function_exists('getDaysText')
            ? getDaysText($days)
            : ($days === 0 ? 'Today' : ($days === 1 ? 'Tomorrow' : 'In ' . $days . ' days'));
        $message .= (string) ($subscription['name'] ?? '') . ' for '
            . (string) ($subscription['formatted_price'] ?? '') . ' (' . $dayText . ")\n";
    }

    if ($includePeriodSummary) {
        $message .= "\n" . $periodSummaryLine . "\n";
    }

    return $message;
}

function wallos_get_period_summary_snapshot($db, $userId, DateTime $referenceDate, array $i18n)
{
    $userStmt = $db->prepare(
        'SELECT main_currency, period_budget, budget_period_type, budget_period_anchor_date
         FROM user WHERE id = :user_id LIMIT 1'
    );
    $userStmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    $userResult = $userStmt->execute();
    $user = $userResult ? $userResult->fetchArray(SQLITE3_ASSOC) : false;
    if (!$user) {
        return null;
    }

    $period = wallos_get_active_budget_period(
        $referenceDate,
        wallos_budget_period_type($user['budget_period_type'] ?? 'monthly'),
        wallos_budget_anchor_date($user['budget_period_anchor_date'] ?? '')
    );
    $isPeriodStart = $period['start']->format('Y-m-d') === $referenceDate->format('Y-m-d');

    $subscriptionStmt = $db->prepare(
        'SELECT * FROM subscriptions
         WHERE user_id = :user_id
           AND inactive = 0
           AND lifecycle_status = :lifecycle_status
           AND exclude_from_stats = 0'
    );
    $subscriptionStmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    $subscriptionStmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
    $subscriptionResult = $subscriptionStmt->execute();
    $subscriptions = [];
    while ($subscriptionResult && ($subscription = $subscriptionResult->fetchArray(SQLITE3_ASSOC))) {
        $subscriptions[] = $subscription;
    }

    $rulesMap = wallos_get_subscription_price_rules_map($db, $userId, true);
    $amountNeeded = wallos_budget_period_amount(
        $subscriptions,
        $referenceDate,
        $period['end'],
        $db,
        $userId,
        $rulesMap
    );

    $currencyStmt = $db->prepare(
        'SELECT code, symbol FROM currencies
         WHERE id = :currency_id AND user_id = :user_id LIMIT 1'
    );
    $currencyStmt->bindValue(':currency_id', (int) ($user['main_currency'] ?? 0), SQLITE3_INTEGER);
    $currencyStmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    $currencyResult = $currencyStmt->execute();
    $currency = $currencyResult ? $currencyResult->fetchArray(SQLITE3_ASSOC) : false;
    $currencyCode = (string) ($currency['code'] ?? 'USD');
    $currencySymbol = (string) ($currency['symbol'] ?? '$');

    $line = translate('period_amount_needed', $i18n) . ': '
        . wallos_format_period_summary_price($amountNeeded, $currencyCode, $currencySymbol);
    $periodBudget = max(0, (float) ($user['period_budget'] ?? 0));
    if ($periodBudget > 0) {
        $remaining = max(0, round($periodBudget - $amountNeeded, 2));
        $line .= ' | ' . translate('period_budget_remaining', $i18n) . ': '
            . wallos_format_period_summary_price($remaining, $currencyCode, $currencySymbol);
    }

    return [
        'is_period_start' => $isPeriodStart,
        'line' => $line,
        'amount_needed' => $amountNeeded,
        'period_budget' => $periodBudget,
        'period_start' => $period['start']->format('Y-m-d'),
        'period_end' => $period['end']->format('Y-m-d'),
        'currency_code' => $currencyCode,
        'currency_symbol' => $currencySymbol,
    ];
}

?>
