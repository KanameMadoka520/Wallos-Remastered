<?php

require_once __DIR__ . '/subscription_trash.php';
require_once __DIR__ . '/subscription_payment_records.php';

function getPricePerMonth($cycle, $frequency, $price)
{
    switch ($cycle) {
        case 1:
            $numberOfPaymentsPerMonth = (30 / $frequency);
            return $price * $numberOfPaymentsPerMonth;
        case 2:
            $numberOfPaymentsPerMonth = (4.35 / $frequency);
            return $price * $numberOfPaymentsPerMonth;
        case 3:
            $numberOfPaymentsPerMonth = (1 / $frequency);
            return $price * $numberOfPaymentsPerMonth;
        case 4:
            $numberOfMonths = (12 * $frequency);
            return $price / $numberOfMonths;
    }
}

function getPriceConverted($price, $currency, $database, $userId)
{
    $query = "SELECT rate FROM currencies WHERE id = :currency AND user_id = :userId";
    $stmt = $database->prepare($query);
    $stmt->bindParam(':currency', $currency, SQLITE3_INTEGER);
    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $exchangeRate = $result->fetchArray(SQLITE3_ASSOC);
    if ($exchangeRate === false) {
        return $price;
    } else {
        $fromRate = $exchangeRate['rate'];
        return $price / $fromRate;
    }
}

// Get categories
$categories = array();
$query = "SELECT * FROM categories WHERE user_id = :userId ORDER BY 'order' ASC";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $categoryId = $row['id'];
  $categories[$categoryId] = $row;
  $categories[$categoryId]['count'] = 0;
  $categoryCost[$categoryId]['cost'] = 0;
  $categoryCost[$categoryId]['name'] = $row['name'];
}

// Get payment methods
$paymentMethods = array();
$query = "SELECT * FROM payment_methods WHERE user_id = :userId AND enabled = 1";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $paymentMethodId = $row['id'];
  $paymentMethods[$paymentMethodId] = $row;
  $paymentMethods[$paymentMethodId]['count'] = 0;
  $paymentMethodsCount[$paymentMethodId]['count'] = 0;
  $paymentMethodsCount[$paymentMethodId]['name'] = $row['name'];
}

//Get household members
$members = array();
$query = "SELECT * FROM household WHERE user_id = :userId";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $memberId = $row['id'];
  $members[$memberId] = $row;
  $members[$memberId]['count'] = 0;
  $memberCost[$memberId]['cost'] = 0;
  $memberCost[$memberId]['name'] = $row['name'];
}

$activeSubscriptions = 0;
$inactiveSubscriptions = 0;
$currentDateForPayments = new DateTime('today');
$currentMonthStart = $currentDateForPayments->format('Y-m-01');
$currentMonthEnd = $currentDateForPayments->format('Y-m-t');
$currentYearStart = $currentDateForPayments->format('Y-01-01');
$currentYearEnd = $currentDateForPayments->format('Y-12-31');
$currentMonthActualPaid = wallos_get_subscription_payment_total($db, $userId, $currentMonthStart, $currentMonthEnd, true);
$currentYearActualPaid = wallos_get_subscription_payment_total($db, $userId, $currentYearStart, $currentYearEnd, true);
$paidDueDatesThisYear = wallos_get_paid_due_dates_map($db, $userId, $currentYearStart, $currentYearEnd, true);
$projectedRemainingYearCost = 0;
$mainCurrencyCode = $currencies[$userData['main_currency']]['code'] ?? '';
$monthlyCostBreakdown = [];
$amountDueThisMonthSummary = [];
$projectedYearSummary = [];
$actualPaidThisMonthRecords = wallos_get_subscription_payment_records_for_period($db, $userId, $currentMonthStart, $currentMonthEnd, true);
$actualPaidThisYearRecords = wallos_get_subscription_payment_records_for_period($db, $userId, $currentYearStart, $currentYearEnd, true);
// Calculate total monthly price
$mostExpensiveSubscription = array();
$mostExpensiveSubscription['price'] = 0;
$amountDueThisMonth = 0;
$totalCostPerMonth = 0;
$totalSavingsPerMonth = 0;
$totalCostsInReplacementsPerMonth = 0;

$statsSubtitleParts = [];
$query = "SELECT name, price, logo, frequency, cycle, currency_id, next_payment, payer_user_id, category_id, payment_method_id, inactive, replacement_subscription_id FROM subscriptions";
$conditions = [];
$params = [];

if (isset($_GET['member'])) {
    $conditions[] = "payer_user_id = :member";
    $params[':member'] = $_GET['member'];
    $statsSubtitleParts[] = $members[$_GET['member']]['name'];
}

if (isset($_GET['category'])) {
    $conditions[] = "category_id = :category";
    $params[':category'] = $_GET['category'];
    $statsSubtitleParts[] = $categories[$_GET['category']]['name'] == "No category" ? translate("no_category", $i18n) : $categories[$_GET['category']]['name'];
}

if (isset($_GET['payment'])) {
    $conditions[] = "payment_method_id = :payment";
    $params[':payment'] = $_GET['payment'];
    $statsSubtitleParts[] = $paymentMethodsCount[$_GET['payment']]['name'];
}

$conditions[] = "user_id = :userId";
$conditions[] = "lifecycle_status = :lifecycle_status";
$conditions[] = "exclude_from_stats = 0";
$params[':userId'] = $userId;
$params[':lifecycle_status'] = WALLOS_SUBSCRIPTION_STATUS_ACTIVE;

if (!empty($conditions)) {
    $query .= " WHERE " . implode(' AND ', $conditions);
}

$stmt = $db->prepare($query);
$statsSubtitle = !empty($statsSubtitleParts) ? '(' . implode(', ', $statsSubtitleParts) . ')' : "";

foreach ($params as $key => $value) {
    $type = $key === ':lifecycle_status' ? SQLITE3_TEXT : SQLITE3_INTEGER;
    $stmt->bindValue($key, $value, $type);
}

$result = $stmt->execute();
$usesMultipleCurrencies = false;

if ($result) {
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $subscriptions[] = $row;
    }
    if (isset($subscriptions)) {
        $replacementSubscriptions = array();

        foreach ($subscriptions as $subscription) {
            $name = $subscription['name'];
            $price = $subscription['price'];
            $logo = $subscription['logo'];
            $frequency = $subscription['frequency'];
            $cycle = $subscription['cycle'];
            $currency = $subscription['currency_id'];
            if ($currency != $userData['main_currency']) {
                $usesMultipleCurrencies = true;
            }
            $next_payment = $subscription['next_payment'];
            $payerId = $subscription['payer_user_id'];
            $members[$payerId]['count'] += 1;
            $categoryId = $subscription['category_id'];
            $categories[$categoryId]['count'] += 1;
            $paymentMethodId = $subscription['payment_method_id'];
            $paymentMethods[$paymentMethodId]['count'] += 1;
            $inactive = $subscription['inactive'];
            $replacementSubscriptionId = $subscription['replacement_subscription_id'];
            $subscriptionId = (int) ($subscription['id'] ?? 0);
            $originalSubscriptionPrice = getPriceConverted($price, $currency, $db, $userId);
            $price = getPricePerMonth($cycle, $frequency, $originalSubscriptionPrice);

            if ($inactive == 0) {
                $activeSubscriptions++;
                $totalCostPerMonth += $price;
                $memberCost[$payerId]['cost'] += $price;
                $categoryCost[$categoryId]['cost'] += $price;
                $paymentMethodsCount[$paymentMethodId]['count'] += 1;
                if ($price > $mostExpensiveSubscription['price']) {
                    $mostExpensiveSubscription['price'] = $price;
                    $mostExpensiveSubscription['name'] = $name;
                    $mostExpensiveSubscription['logo'] = $logo;
                }

                $monthlyCostBreakdown[] = [
                    'name' => $name,
                    'billing_cycle' => wallos_stats_get_billing_cycle_label($cycle, $frequency, $i18n),
                    'price_per_charge' => round((float) $originalSubscriptionPrice, 2),
                    'monthly_equivalent' => round((float) $price, 2),
                    'next_payment' => $next_payment,
                ];

                // Calculate ammount due this month
                $nextPaymentDate = DateTime::createFromFormat('Y-m-d', trim($next_payment));
                $tomorrow = new DateTime('tomorrow');
                $endOfMonth = new DateTime('last day of this month');

                if ($nextPaymentDate >= $tomorrow && $nextPaymentDate <= $endOfMonth) {
                    $nextPaymentDateKey = $nextPaymentDate->format('Y-m-d');
                    if (!empty($paidDueDatesThisYear[$subscriptionId][$nextPaymentDateKey])) {
                        // Already settled by an actual payment record.
                    } else {
                        $timesToPay = 1;
                        $daysInMonth = $endOfMonth->diff($tomorrow)->days + 1;
                        $daysRemaining = $endOfMonth->diff($nextPaymentDate)->days + 1;
                        if ($cycle == 1) {
                            $timesToPay = $daysRemaining / $frequency;
                        }
                        if ($cycle == 2) {
                            $weeksInMonth = ceil($daysInMonth / 7);
                            $weeksRemaining = ceil($daysRemaining / 7);
                            $timesToPay = $weeksRemaining / $frequency;
                        }
                        $lineAmount = $originalSubscriptionPrice * $timesToPay;
                        $amountDueThisMonth += $lineAmount;

                        if (!isset($amountDueThisMonthSummary[$subscriptionId])) {
                            $amountDueThisMonthSummary[$subscriptionId] = [
                                'name' => $name,
                                'billing_cycle' => wallos_stats_get_billing_cycle_label($cycle, $frequency, $i18n),
                                'count' => 0,
                                'unit_amount' => round((float) $originalSubscriptionPrice, 2),
                                'total_amount' => 0,
                                'next_due' => $nextPaymentDateKey,
                            ];
                        }

                        $amountDueThisMonthSummary[$subscriptionId]['count'] += $timesToPay;
                        $amountDueThisMonthSummary[$subscriptionId]['total_amount'] += round((float) $lineAmount, 2);
                    }
                }
                if (!empty($subscription['next_payment'])) {
                    try {
                        $forecastDate = new DateTime($subscription['next_payment']);
                        $yearEndDate = new DateTime($currentYearEnd);
                        $todayDate = new DateTime($currentDateForPayments->format('Y-m-d'));
                        $intervalSpec = wallos_get_subscription_interval_spec((int) $cycle, (int) $frequency);
                        $interval = new DateInterval($intervalSpec);

                        while ($forecastDate <= $yearEndDate) {
                            $forecastDateString = $forecastDate->format('Y-m-d');
                            if ($forecastDate >= $todayDate && empty($paidDueDatesThisYear[$subscriptionId][$forecastDateString])) {
                                $projectedRemainingYearCost += $originalSubscriptionPrice;

                                if (!isset($projectedYearSummary[$subscriptionId])) {
                                    $projectedYearSummary[$subscriptionId] = [
                                        'name' => $name,
                                        'billing_cycle' => wallos_stats_get_billing_cycle_label($cycle, $frequency, $i18n),
                                        'count' => 0,
                                        'unit_amount' => round((float) $originalSubscriptionPrice, 2),
                                        'total_amount' => 0,
                                        'next_due' => $forecastDateString,
                                    ];
                                }

                                $projectedYearSummary[$subscriptionId]['count'] += 1;
                                $projectedYearSummary[$subscriptionId]['total_amount'] += round((float) $originalSubscriptionPrice, 2);
                            }
                            $forecastDate->add($interval);
                        }
                    } catch (Throwable $throwable) {
                        // Ignore malformed future forecast calculations for a single subscription.
                    }
                }
            } else {
                $inactiveSubscriptions++;
                $totalSavingsPerMonth += $price;

                // Check if it has a replacement subscription and if it was not already counted
                if ($replacementSubscriptionId && !in_array($replacementSubscriptionId, $replacementSubscriptions)) {
                    $query = "SELECT price, currency_id, cycle, frequency FROM subscriptions WHERE id = :replacementSubscriptionId AND lifecycle_status = :lifecycle_status AND exclude_from_stats = 0";
                    $stmt = $db->prepare($query);
                    $stmt->bindValue(':replacementSubscriptionId', $replacementSubscriptionId, SQLITE3_INTEGER);
                    $stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
                    $result = $stmt->execute();
                    $replacementSubscription = $result->fetchArray(SQLITE3_ASSOC);
                    if ($replacementSubscription) {
                        $replacementSubscriptionPrice = getPriceConverted($replacementSubscription['price'], $replacementSubscription['currency_id'], $db, $userId);
                        $replacementSubscriptionPrice = getPricePerMonth($replacementSubscription['cycle'], $replacementSubscription['frequency'], $replacementSubscriptionPrice);
                        $totalCostsInReplacementsPerMonth += $replacementSubscriptionPrice;
                    }
                }

                $replacementSubscriptions[] = $replacementSubscriptionId;
            }

        }

        // Subtract the total cost of replacement subscriptions from the total savings
        $totalSavingsPerMonth -= $totalCostsInReplacementsPerMonth;

        // Calculate yearly price
        $totalCostPerYear = $totalCostPerMonth * 12;
        $currentYearProjectedSpend = $currentYearActualPaid + $projectedRemainingYearCost;

        // Calculate average subscription monthly cost
        if ($activeSubscriptions > 0) {
            $averageSubscriptionCost = $totalCostPerMonth / $activeSubscriptions;
        } else {
            $totalCostPerYear = 0;
            $averageSubscriptionCost = 0;
            $currentYearProjectedSpend = $currentYearActualPaid;
        }
    } else {
        $totalCostPerYear = 0;
        $averageSubscriptionCost = 0;
        $currentYearProjectedSpend = $currentYearActualPaid;
    }
}

function wallos_stats_get_billing_cycle_label($cycle, $frequency, $i18n)
{
    $frequency = max(1, (int) $frequency);
    $cycle = (int) $cycle;

    switch ($cycle) {
        case 1:
            return $frequency === 1 ? translate('Daily', $i18n) : $frequency . ' ' . translate('days', $i18n);
        case 2:
            return $frequency === 1 ? translate('Weekly', $i18n) : $frequency . ' ' . translate('weeks', $i18n);
        case 3:
            return $frequency === 1 ? translate('Monthly', $i18n) : $frequency . ' ' . translate('months', $i18n);
        default:
            return $frequency === 1 ? translate('Yearly', $i18n) : $frequency . ' ' . translate('years', $i18n);
    }
}

$showVsBudgetGraph = false;
$vsBudgetDataPoints = [];
if (isset($userData['budget']) && $userData['budget'] > 0) {
    $budget = $userData['budget'];
    $budgetLeft = $budget - $totalCostPerMonth;
    $budgetLeft = $budgetLeft < 0 ? 0 : $budgetLeft;
    $budgetUsed = ($totalCostPerMonth / $budget) * 100;
    $budgetUsed = $budgetUsed > 100 ? 100 : $budgetUsed;
    if ($totalCostPerMonth > $budget) {
        $overBudgetAmount = $totalCostPerMonth - $budget;
    }
    $showVsBudgetGraph = true;
    $vsBudgetDataPoints = [
        [
            "label" => translate('budget_remaining', $i18n),
            "y" => $budgetLeft,
        ],
        [
            "label" => translate('total_cost', $i18n),
            "y" => $totalCostPerMonth,
        ],
    ];
}

$showCantConverErrorMessage = false;
if ($usesMultipleCurrencies) {
    $query = "SELECT api_key FROM fixer WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    if ($result->fetchArray(SQLITE3_ASSOC) === false) {
        $showCantConverErrorMessage = true;
    }
}

$query = "SELECT * FROM total_yearly_cost WHERE user_id = :userId";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();

$totalMonthlyCostDataPoints = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $totalMonthlyCostDataPoints[] = [
        "label" => html_entity_decode($row['date']),
        "y" => round($row['cost'] / 12, 2),
    ];
}

$showTotalMonthlyCostGraph = count($totalMonthlyCostDataPoints) > 1;

$metricExplanations = [
    'monthly_cost' => [
        'title' => translate('monthly_cost', $i18n),
        'formula' => translate('monthly_cost_explanation_formula', $i18n),
        'currency_code' => $mainCurrencyCode,
        'total' => round((float) $totalCostPerMonth, 2),
        'items' => array_values($monthlyCostBreakdown),
    ],
    'yearly_cost' => [
        'title' => translate('yearly_cost', $i18n),
        'formula' => translate('yearly_cost_explanation_formula', $i18n),
        'currency_code' => $mainCurrencyCode,
        'total' => round((float) $totalCostPerYear, 2),
        'items' => array_values($monthlyCostBreakdown),
    ],
    'amount_due' => [
        'title' => translate('amount_due', $i18n),
        'formula' => translate('amount_due_explanation_formula', $i18n),
        'currency_code' => $mainCurrencyCode,
        'total' => round((float) $amountDueThisMonth, 2),
        'items' => array_values($amountDueThisMonthSummary),
    ],
    'actual_paid_this_month' => [
        'title' => translate('actual_paid_this_month', $i18n),
        'formula' => translate('actual_paid_this_month_explanation_formula', $i18n),
        'currency_code' => $mainCurrencyCode,
        'total' => round((float) $currentMonthActualPaid, 2),
        'items' => $actualPaidThisMonthRecords,
    ],
    'actual_paid_this_year' => [
        'title' => translate('actual_paid_this_year', $i18n),
        'formula' => translate('actual_paid_this_year_explanation_formula', $i18n),
        'currency_code' => $mainCurrencyCode,
        'total' => round((float) $currentYearActualPaid, 2),
        'items' => $actualPaidThisYearRecords,
    ],
    'projected_yearly_spend' => [
        'title' => translate('projected_yearly_spend', $i18n),
        'formula' => translate('projected_yearly_spend_explanation_formula', $i18n),
        'currency_code' => $mainCurrencyCode,
        'total' => round((float) $currentYearProjectedSpend, 2),
        'items' => array_values($projectedYearSummary),
        'actual_paid_total' => round((float) $currentYearActualPaid, 2),
        'projected_remaining_total' => round((float) $projectedRemainingYearCost, 2),
    ],
];

if (isset($budget) && $budget > 0) {
    $metricExplanations['budget'] = [
        'title' => translate('budget', $i18n),
        'formula' => translate('budget_explanation_formula', $i18n),
        'currency_code' => $mainCurrencyCode,
        'total' => round((float) $budget, 2),
        'items' => [],
    ];
    $metricExplanations['budget_used'] = [
        'title' => translate('budget_used', $i18n),
        'formula' => translate('budget_used_explanation_formula', $i18n),
        'currency_code' => $mainCurrencyCode,
        'total' => round((float) ($budgetUsed ?? 0), 2),
        'reference_total' => round((float) $budget, 2),
        'cost_total' => round((float) $totalCostPerMonth, 2),
        'items' => array_values($monthlyCostBreakdown),
    ];
    $metricExplanations['budget_remaining'] = [
        'title' => translate('budget_remaining', $i18n),
        'formula' => translate('budget_remaining_explanation_formula', $i18n),
        'currency_code' => $mainCurrencyCode,
        'total' => round((float) ($budgetLeft ?? 0), 2),
        'reference_total' => round((float) $budget, 2),
        'cost_total' => round((float) $totalCostPerMonth, 2),
        'items' => array_values($monthlyCostBreakdown),
    ];
    $metricExplanations['amount_over_budget'] = [
        'title' => translate('amount_over_budget', $i18n),
        'formula' => translate('amount_over_budget_explanation_formula', $i18n),
        'currency_code' => $mainCurrencyCode,
        'total' => round((float) ($overBudgetAmount ?? 0), 2),
        'reference_total' => round((float) $budget, 2),
        'cost_total' => round((float) $totalCostPerMonth, 2),
        'items' => array_values($monthlyCostBreakdown),
    ];
}

?>
