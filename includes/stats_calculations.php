<?php

require_once __DIR__ . '/subscription_trash.php';
require_once __DIR__ . '/subscription_payment_records.php';
require_once __DIR__ . '/subscription_price_rules.php';
require_once __DIR__ . '/budget_metrics.php';
require_once __DIR__ . '/currency_rates.php';
require_once __DIR__ . '/budget_period_calculations.php';

function getPricePerMonth($cycle, $frequency, $price)
{
    $frequency = max(1, (int) $frequency);
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
        case 5:
            // One-time purchases have no recurring monthly equivalent.
            return 0;
        default:
            return 0;
    }
}

function getPriceConverted($price, $currency, $database, $userId)
{
    return wallos_convert_price($price, $currency, $database, $userId);
}

function wallos_stats_build_summary_card($label, $value, $format = 'currency', $currencyCode = '')
{
    return [
        'label' => (string) $label,
        'value' => round((float) $value, 2),
        'format' => (string) $format,
        'currency_code' => (string) $currencyCode,
    ];
}

function wallos_stats_build_item_group($title, array $items)
{
    return [
        'title' => (string) $title,
        'items' => array_values($items),
    ];
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
$currentMonthActualPaid = 0;
$currentYearActualPaid = 0;
$paidDueDatesThisYear = [];
$projectedRemainingYearCost = 0;
$mainCurrencyCode = $currencies[$userData['main_currency']]['code'] ?? '';
$monthlyCostBreakdown = [];
$yearlyCostBreakdown = [];
$amountDueThisMonthSummary = [];
$projectedYearSummary = [];
$actualPaidThisMonthRecords = [];
$actualPaidThisYearRecords = [];
$priceRulesMap = wallos_get_subscription_price_rules_map($db, $userId, true);
// Calculate total monthly price
$mostExpensiveSubscription = array();
$mostExpensiveSubscription['price'] = 0;
$amountDueThisMonth = 0;
$totalCostPerMonth = 0;
$totalSavingsPerMonth = 0;
$totalCostsInReplacementsPerMonth = 0;

$statsSubtitleParts = [];
$query = "SELECT id, name, price, logo, frequency, cycle, currency_id, next_payment, start_date, auto_renew, payer_user_id, category_id, payment_method_id, inactive, replacement_subscription_id, lifecycle_status, exclude_from_stats FROM subscriptions";
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
        $hasStatsFilter = false;
        foreach (['member', 'category', 'payment'] as $filterKey) {
            if (isset($_GET[$filterKey]) && trim((string) $_GET[$filterKey]) !== '') {
                $hasStatsFilter = true;
                break;
            }
        }
        $filteredSubscriptionIds = array_map(
            static function ($subscription) {
                return (int) ($subscription['id'] ?? 0);
            },
            $subscriptions
        );
        $paymentScopeIds = $hasStatsFilter ? $filteredSubscriptionIds : null;
        $currentMonthActualPaid = wallos_get_subscription_payment_total(
            $db,
            $userId,
            $currentMonthStart,
            $currentMonthEnd,
            true,
            $paymentScopeIds
        );
        $currentYearActualPaid = wallos_get_subscription_payment_total(
            $db,
            $userId,
            $currentYearStart,
            $currentYearEnd,
            true,
            $paymentScopeIds
        );
        $paidDueDatesThisYear = wallos_get_paid_due_dates_map(
            $db,
            $userId,
            $currentYearStart,
            $currentYearEnd,
            true,
            $paymentScopeIds
        );
        $actualPaidThisMonthRecords = wallos_get_subscription_payment_records_for_period(
            $db,
            $userId,
            $currentMonthStart,
            $currentMonthEnd,
            true,
            $paymentScopeIds
        );
        $actualPaidThisYearRecords = wallos_get_subscription_payment_records_for_period(
            $db,
            $userId,
            $currentYearStart,
            $currentYearEnd,
            true,
            $paymentScopeIds
        );
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
            $priceRules = $priceRulesMap[$subscriptionId] ?? [];
            $originalSubscriptionPrice = getPriceConverted($price, $currency, $db, $userId);
            $price = getPricePerMonth($cycle, $frequency, $originalSubscriptionPrice);

            if ($inactive == 0) {
                if ((int) $cycle !== 5) {
                    $activeSubscriptions++;
                    $totalCostPerMonth += $price;
                    $memberCost[$payerId]['cost'] += $price;
                    $categoryCost[$categoryId]['cost'] += $price;
                    $paymentMethodsCount[$paymentMethodId]['count'] += 1;
                    if ($price > $mostExpensiveSubscription['price']) {
                        $mostExpensiveSubscription['id'] = $subscriptionId;
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
                        'currency_code' => $mainCurrencyCode,
                    ];
                    $yearlyCostBreakdown[] = [
                        'name' => $name,
                        'billing_cycle' => wallos_stats_get_billing_cycle_label($cycle, $frequency, $i18n),
                        'price_per_charge' => round((float) $originalSubscriptionPrice, 2),
                        'monthly_equivalent' => round((float) $price, 2),
                        'total_amount' => round((float) ($price * 12), 2),
                        'next_payment' => $next_payment,
                        'currency_code' => $mainCurrencyCode,
                    ];

                }

                if (!empty($subscription['next_payment'])) {
                    try {
                        $todayDate = new DateTime($currentDateForPayments->format('Y-m-d'));
                        $endOfMonth = new DateTime('last day of this month');
                        $monthOccurrences = wallos_budget_subscription_occurrences(
                            $subscription,
                            $todayDate,
                            $endOfMonth
                        );

                        foreach ($monthOccurrences as $monthOccurrence) {
                            $dueDate = $monthOccurrence->format('Y-m-d');
                            if (!empty($paidDueDatesThisYear[$subscriptionId][$dueDate])) {
                                continue;
                            }

                            $effectivePrice = wallos_get_effective_subscription_price_for_due_date(
                                $subscription,
                                $priceRules,
                                $dueDate,
                                $db,
                                $userId
                            );
                            $ruleSourceSummary = $effectivePrice['matched_rule']
                                ? wallos_format_subscription_price_rule_summary($effectivePrice['matched_rule'], $currencies, $i18n)
                                : translate('metric_explanation_regular_price_source', $i18n);
                            $lineAmount = (float) ($effectivePrice['amount_main'] ?? 0);
                            $amountDueThisMonth += $lineAmount;
                            $amountDueThisMonthSummary[] = [
                                'name' => $name,
                                'billing_cycle' => wallos_stats_get_billing_cycle_label($cycle, $frequency, $i18n),
                                'count' => 1,
                                'unit_amount' => round($lineAmount, 2),
                                'total_amount' => round($lineAmount, 2),
                                'next_due' => $dueDate,
                                'currency_code' => $mainCurrencyCode,
                                'rule_summary' => $ruleSourceSummary,
                            ];
                        }

                        $yearEndDate = new DateTime($currentYearEnd);
                        $yearOccurrences = wallos_budget_subscription_occurrences(
                            $subscription,
                            $todayDate,
                            $yearEndDate
                        );

                        foreach ($yearOccurrences as $yearOccurrence) {
                            $dueDate = $yearOccurrence->format('Y-m-d');
                            if (!empty($paidDueDatesThisYear[$subscriptionId][$dueDate])) {
                                continue;
                            }

                            $effectivePrice = wallos_get_effective_subscription_price_for_due_date(
                                $subscription,
                                $priceRules,
                                $dueDate,
                                $db,
                                $userId
                            );
                            $ruleSourceSummary = $effectivePrice['matched_rule']
                                ? wallos_format_subscription_price_rule_summary($effectivePrice['matched_rule'], $currencies, $i18n)
                                : translate('metric_explanation_regular_price_source', $i18n);
                            $projectedRemainingYearCost += $effectivePrice['amount_main'];
                            $projectedYearSummary[] = [
                                'name' => $name,
                                'billing_cycle' => wallos_stats_get_billing_cycle_label($cycle, $frequency, $i18n),
                                'count' => 1,
                                'unit_amount' => round((float) $effectivePrice['amount_main'], 2),
                                'total_amount' => round((float) $effectivePrice['amount_main'], 2),
                                'next_due' => $dueDate,
                                'currency_code' => $mainCurrencyCode,
                                'rule_summary' => $ruleSourceSummary,
                            ];
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
                    $query = "SELECT price, currency_id, cycle, frequency FROM subscriptions WHERE id = :replacementSubscriptionId AND user_id = :userId AND lifecycle_status = :lifecycle_status AND exclude_from_stats = 0";
                    $stmt = $db->prepare($query);
                    $stmt->bindValue(':replacementSubscriptionId', $replacementSubscriptionId, SQLITE3_INTEGER);
                    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
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
$showYearlyBudgetGraph = false;
$yearlyBudgetDataPoints = [];
$yearlyBudgetVisualizationSegments = [];
$showVsPeriodBudgetGraph = false;
$vsPeriodBudgetDataPoints = [];
$periodBudget = 0.0;
$periodBudgetUsed = null;
$periodBudgetLeft = null;
$periodOverBudgetAmount = null;
$periodBudgetType = wallos_budget_period_type($userData['budget_period_type'] ?? 'monthly');
$periodBudgetAnchorDate = wallos_budget_anchor_date($userData['budget_period_anchor_date'] ?? '');
$periodBudgetPeriod = wallos_get_active_budget_period(
    new DateTime('today'),
    $periodBudgetType,
    $periodBudgetAnchorDate
);
$periodBudgetReferenceDate = new DateTime('today');
if ($periodBudgetReferenceDate < $periodBudgetPeriod['start']) {
    $periodBudgetReferenceDate = clone $periodBudgetPeriod['start'];
}
$periodBudgetAmount = wallos_budget_period_amount(
    $subscriptions ?? [],
    $periodBudgetReferenceDate,
    $periodBudgetPeriod['end'],
    $db,
    $userId,
    $priceRulesMap ?? []
);
if (isset($userData['period_budget']) && (float) $userData['period_budget'] > 0) {
    $periodBudgetMetrics = wallos_calculate_budget_metrics($userData['period_budget'], $periodBudgetAmount);
    $periodBudget = $periodBudgetMetrics['budget'];
    $periodBudgetUsed = $periodBudgetMetrics['used_percent'];
    $periodBudgetLeft = $periodBudgetMetrics['remaining'];
    $periodOverBudgetAmount = $periodBudgetMetrics['over_amount'];
    $showVsPeriodBudgetGraph = true;
    $vsPeriodBudgetDataPoints = [
        [
            'label' => translate('period_budget_remaining', $i18n),
            'y' => $periodBudgetLeft,
        ],
        [
            'label' => translate('period_amount_needed', $i18n),
            'y' => $periodBudgetAmount,
        ],
    ];
}
if (isset($userData['budget']) && $userData['budget'] > 0) {
    $monthlyBudgetMetrics = wallos_calculate_budget_metrics($userData['budget'], $totalCostPerMonth);
    $budget = $monthlyBudgetMetrics['budget'];
    $budgetLeft = $monthlyBudgetMetrics['remaining'];
    $budgetUsed = $monthlyBudgetMetrics['used_percent'];
    $overBudgetAmount = $monthlyBudgetMetrics['over_amount'];
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

if (isset($userData['yearly_budget']) && $userData['yearly_budget'] > 0) {
    $yearlyBudgetMetrics = wallos_calculate_yearly_budget_metrics(
        $userData['yearly_budget'],
        $currentYearActualPaid,
        $projectedRemainingYearCost,
        $totalCostPerYear
    );
    $yearlyBudget = $yearlyBudgetMetrics['budget'];
    $yearlyBudgetRemaining = $yearlyBudgetMetrics['remaining'];
    $yearlyBudgetUsed = $yearlyBudgetMetrics['used_percent'];
    $yearlyOverBudgetAmount = $yearlyBudgetMetrics['over_amount'];
    $showYearlyBudgetGraph = true;

    $yearlyBudgetDataPoints = [
        [
            'label' => translate('actual_paid_this_year', $i18n),
            'y' => round((float) $currentYearActualPaid, 2),
            'color' => 'rgba(43, 112, 255, 0.92)',
            'borderColor' => 'rgba(43, 112, 255, 1)',
        ],
        [
            'label' => translate('metric_explanation_projected_remaining_total', $i18n),
            'y' => round((float) $projectedRemainingYearCost, 2),
            'color' => 'rgba(245, 166, 35, 0.88)',
            'borderColor' => 'rgba(245, 166, 35, 1)',
        ],
    ];

    if ($yearlyBudgetRemaining > 0) {
        $yearlyBudgetDataPoints[] = [
            'label' => translate('yearly_budget_remaining', $i18n),
            'y' => round((float) $yearlyBudgetRemaining, 2),
            'color' => 'rgba(37, 203, 128, 0.88)',
            'borderColor' => 'rgba(37, 203, 128, 1)',
        ];
    } elseif ($yearlyOverBudgetAmount > 0) {
        $yearlyBudgetDataPoints[] = [
            'label' => translate('yearly_amount_over_budget', $i18n),
            'y' => round((float) $yearlyOverBudgetAmount, 2),
            'color' => 'rgba(237, 84, 84, 0.88)',
            'borderColor' => 'rgba(237, 84, 84, 1)',
        ];
    }

    $yearlyBudgetVisualizationBase = max((float) $yearlyBudget, (float) $yearlyBudgetMetrics['projected_total'], 0.01);
    $yearlyBudgetVisualizationSegments = [
        [
            'label' => translate('actual_paid_this_year', $i18n),
            'value' => round((float) $currentYearActualPaid, 2),
            'ratio' => round((float) ($currentYearActualPaid / $yearlyBudgetVisualizationBase), 6),
            'color' => 'rgba(43, 112, 255, 0.92)',
        ],
        [
            'label' => translate('metric_explanation_projected_remaining_total', $i18n),
            'value' => round((float) $projectedRemainingYearCost, 2),
            'ratio' => round((float) ($projectedRemainingYearCost / $yearlyBudgetVisualizationBase), 6),
            'color' => 'rgba(245, 166, 35, 0.88)',
        ],
    ];

    if ($yearlyBudgetRemaining > 0) {
        $yearlyBudgetVisualizationSegments[] = [
            'label' => translate('yearly_budget_remaining', $i18n),
            'value' => round((float) $yearlyBudgetRemaining, 2),
            'ratio' => round((float) ($yearlyBudgetRemaining / $yearlyBudgetVisualizationBase), 6),
            'color' => 'rgba(37, 203, 128, 0.88)',
        ];
    } elseif ($yearlyOverBudgetAmount > 0) {
        $yearlyBudgetVisualizationSegments[] = [
            'label' => translate('yearly_amount_over_budget', $i18n),
            'value' => round((float) $yearlyOverBudgetAmount, 2),
            'ratio' => round((float) ($yearlyOverBudgetAmount / $yearlyBudgetVisualizationBase), 6),
            'color' => 'rgba(237, 84, 84, 0.88)',
        ];
    }
}

// Rolling forecast for the next twelve calendar months. This is additive to
// the existing current-year forecast and respects the payment ledger and
// special price rules already used by the statistics page.
$projectionDataPoints = [];
$showProjectionGraph = false;
$projectionStart = new DateTime('first day of next month');
$projectionEnd = (clone $projectionStart)->modify('+12 months');
$projectionBuckets = [];
for ($projectionIndex = 0; $projectionIndex < 12; $projectionIndex++) {
    $bucketDate = (clone $projectionStart)->modify('+' . $projectionIndex . ' months');
    $projectionBuckets[$bucketDate->format('Y-m')] = [
        'label' => $bucketDate->format('Y-m'),
        'total' => 0.0,
    ];
}

$projectionRangeEnd = (clone $projectionEnd)->modify('-1 day');
$projectionPaidDueDates = wallos_get_paid_due_dates_map(
    $db,
    $userId,
    $projectionStart->format('Y-m-d'),
    $projectionRangeEnd->format('Y-m-d'),
    true,
    isset($filteredSubscriptionIds) ? $filteredSubscriptionIds : null
);

foreach (($subscriptions ?? []) as $subscription) {
    if ((int) ($subscription['inactive'] ?? 0) !== 0
        || ((string) ($subscription['lifecycle_status'] ?? 'active') !== 'active')
        || (int) ($subscription['exclude_from_stats'] ?? 0) === 1) {
        continue;
    }

    $subscriptionId = (int) ($subscription['id'] ?? 0);
    $rules = $priceRulesMap[$subscriptionId] ?? [];
    $occurrences = wallos_budget_subscription_occurrences($subscription, $projectionStart, $projectionRangeEnd);
    foreach ($occurrences as $occurrence) {
        $dueDate = $occurrence->format('Y-m-d');
        if (!empty($projectionPaidDueDates[$subscriptionId][$dueDate])) {
            continue;
        }

        if ($rules) {
            $effectivePrice = wallos_get_effective_subscription_price_for_due_date(
                $subscription,
                $rules,
                $dueDate,
                $db,
                $userId
            );
            $amountMain = (float) ($effectivePrice['amount_main'] ?? 0);
        } else {
            $amountMain = wallos_convert_price(
                $subscription['price'] ?? 0,
                $subscription['currency_id'] ?? 0,
                $db,
                $userId
            );
        }

        $bucketKey = $occurrence->format('Y-m');
        if (isset($projectionBuckets[$bucketKey])) {
            $projectionBuckets[$bucketKey]['total'] += $amountMain;
        }
    }
}

foreach ($projectionBuckets as $bucket) {
    $projectionDataPoints[] = [
        'label' => $bucket['label'],
        'y' => round((float) $bucket['total'], 2),
    ];
}
$showProjectionGraph = count(array_filter($projectionDataPoints, static function ($point) {
    return (float) ($point['y'] ?? 0) > 0;
})) > 0;

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
        'items' => array_values($yearlyCostBreakdown),
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
        'summary_cards' => [
            wallos_stats_build_summary_card(translate('metric_explanation_total_label', $i18n), $currentYearProjectedSpend, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_actual_paid_total', $i18n), $currentYearActualPaid, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_projected_remaining_total', $i18n), $projectedRemainingYearCost, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_standardized_total', $i18n), $totalCostPerYear, 'currency', $mainCurrencyCode),
        ],
        'item_groups' => [
            wallos_stats_build_item_group(translate('metric_explanation_group_standardized_reference', $i18n), $yearlyCostBreakdown),
            wallos_stats_build_item_group(translate('metric_explanation_group_actual_paid_history', $i18n), $actualPaidThisYearRecords),
            wallos_stats_build_item_group(translate('metric_explanation_group_projected_remaining', $i18n), $projectedYearSummary),
        ],
    ],
];

if (isset($budget) && $budget > 0) {
    $metricExplanations['budget'] = [
        'title' => translate('monthly_budget', $i18n),
        'formula' => translate('budget_explanation_formula', $i18n),
        'currency_code' => $mainCurrencyCode,
        'total' => round((float) $budget, 2),
        'summary_cards' => [
            wallos_stats_build_summary_card(translate('metric_explanation_total_label', $i18n), $budget, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_cost_total', $i18n), $totalCostPerMonth, 'currency', $mainCurrencyCode),
        ],
        'item_groups' => [
            wallos_stats_build_item_group(translate('metric_explanation_group_standardized_monthly_cost', $i18n), $monthlyCostBreakdown),
        ],
    ];
    $metricExplanations['budget_used'] = [
        'title' => translate('monthly_budget_used', $i18n),
        'formula' => translate('budget_used_explanation_formula', $i18n),
        'currency_code' => $mainCurrencyCode,
        'total' => round((float) ($budgetUsed ?? 0), 2),
        'summary_cards' => [
            wallos_stats_build_summary_card(translate('metric_explanation_total_label', $i18n), $budgetUsed ?? 0, 'percent'),
            wallos_stats_build_summary_card(translate('metric_explanation_reference_total', $i18n), $budget, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_cost_total', $i18n), $totalCostPerMonth, 'currency', $mainCurrencyCode),
        ],
        'item_groups' => [
            wallos_stats_build_item_group(translate('metric_explanation_group_standardized_monthly_cost', $i18n), $monthlyCostBreakdown),
        ],
    ];
    $metricExplanations['budget_remaining'] = [
        'title' => translate('monthly_budget_remaining', $i18n),
        'formula' => translate('budget_remaining_explanation_formula', $i18n),
        'currency_code' => $mainCurrencyCode,
        'total' => round((float) ($budgetLeft ?? 0), 2),
        'summary_cards' => [
            wallos_stats_build_summary_card(translate('metric_explanation_total_label', $i18n), $budgetLeft ?? 0, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_reference_total', $i18n), $budget, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_cost_total', $i18n), $totalCostPerMonth, 'currency', $mainCurrencyCode),
        ],
        'item_groups' => [
            wallos_stats_build_item_group(translate('metric_explanation_group_standardized_monthly_cost', $i18n), $monthlyCostBreakdown),
        ],
    ];
    $metricExplanations['amount_over_budget'] = [
        'title' => translate('monthly_amount_over_budget', $i18n),
        'formula' => translate('amount_over_budget_explanation_formula', $i18n),
        'currency_code' => $mainCurrencyCode,
        'total' => round((float) ($overBudgetAmount ?? 0), 2),
        'summary_cards' => [
            wallos_stats_build_summary_card(translate('metric_explanation_total_label', $i18n), $overBudgetAmount ?? 0, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_reference_total', $i18n), $budget, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_cost_total', $i18n), $totalCostPerMonth, 'currency', $mainCurrencyCode),
        ],
        'item_groups' => [
            wallos_stats_build_item_group(translate('metric_explanation_group_standardized_monthly_cost', $i18n), $monthlyCostBreakdown),
        ],
    ];
}

if (isset($yearlyBudget) && $yearlyBudget > 0) {
    $yearlyBudgetGroups = [
        wallos_stats_build_item_group(translate('metric_explanation_group_standardized_reference', $i18n), $yearlyCostBreakdown),
        wallos_stats_build_item_group(translate('metric_explanation_group_actual_paid_history', $i18n), $actualPaidThisYearRecords),
        wallos_stats_build_item_group(translate('metric_explanation_group_projected_remaining', $i18n), $projectedYearSummary),
    ];

    $metricExplanations['yearly_budget'] = [
        'title' => translate('yearly_budget', $i18n),
        'formula' => translate('yearly_budget_explanation_formula', $i18n),
        'currency_code' => $mainCurrencyCode,
        'total' => round((float) $yearlyBudget, 2),
        'summary_cards' => [
            wallos_stats_build_summary_card(translate('metric_explanation_total_label', $i18n), $yearlyBudget, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_projected_total', $i18n), $currentYearProjectedSpend, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_actual_paid_total', $i18n), $currentYearActualPaid, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_projected_remaining_total', $i18n), $projectedRemainingYearCost, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_standardized_total', $i18n), $totalCostPerYear, 'currency', $mainCurrencyCode),
        ],
        'item_groups' => $yearlyBudgetGroups,
    ];
    $metricExplanations['yearly_budget_used'] = [
        'title' => translate('yearly_budget_used', $i18n),
        'formula' => translate('yearly_budget_used_explanation_formula', $i18n),
        'currency_code' => $mainCurrencyCode,
        'total' => round((float) ($yearlyBudgetUsed ?? 0), 2),
        'summary_cards' => [
            wallos_stats_build_summary_card(translate('metric_explanation_total_label', $i18n), $yearlyBudgetUsed ?? 0, 'percent'),
            wallos_stats_build_summary_card(translate('metric_explanation_reference_total', $i18n), $yearlyBudget, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_projected_total', $i18n), $currentYearProjectedSpend, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_standardized_total', $i18n), $totalCostPerYear, 'currency', $mainCurrencyCode),
        ],
        'item_groups' => $yearlyBudgetGroups,
    ];
    $metricExplanations['yearly_budget_remaining'] = [
        'title' => translate('yearly_budget_remaining', $i18n),
        'formula' => translate('yearly_budget_remaining_explanation_formula', $i18n),
        'currency_code' => $mainCurrencyCode,
        'total' => round((float) ($yearlyBudgetRemaining ?? 0), 2),
        'summary_cards' => [
            wallos_stats_build_summary_card(translate('metric_explanation_total_label', $i18n), $yearlyBudgetRemaining ?? 0, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_reference_total', $i18n), $yearlyBudget, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_projected_total', $i18n), $currentYearProjectedSpend, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_standardized_total', $i18n), $totalCostPerYear, 'currency', $mainCurrencyCode),
        ],
        'item_groups' => $yearlyBudgetGroups,
    ];
    $metricExplanations['yearly_amount_over_budget'] = [
        'title' => translate('yearly_amount_over_budget', $i18n),
        'formula' => translate('yearly_amount_over_budget_explanation_formula', $i18n),
        'currency_code' => $mainCurrencyCode,
        'total' => round((float) ($yearlyOverBudgetAmount ?? 0), 2),
        'summary_cards' => [
            wallos_stats_build_summary_card(translate('metric_explanation_total_label', $i18n), $yearlyOverBudgetAmount ?? 0, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_reference_total', $i18n), $yearlyBudget, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_projected_total', $i18n), $currentYearProjectedSpend, 'currency', $mainCurrencyCode),
            wallos_stats_build_summary_card(translate('metric_explanation_standardized_total', $i18n), $totalCostPerYear, 'currency', $mainCurrencyCode),
        ],
        'item_groups' => $yearlyBudgetGroups,
    ];
}

?>
