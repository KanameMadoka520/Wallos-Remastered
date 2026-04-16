<?php

require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/user_groups.php';
require_once 'includes/subscription_trash.php';
require_once 'includes/subscription_media.php';
require_once 'includes/metric_explanations.php';
require_once 'includes/page_immersive_toggle.php';

function formatPrice($price, $currencyCode, $currencies)
{
    $formattedPrice = CurrencyFormatter::format($price, $currencyCode);
    if (strstr($formattedPrice, $currencyCode)) {
        $symbol = $currencyCode;

        foreach ($currencies as $currency) {

            if ($currency['code'] === $currencyCode) {
                if ($currency['symbol'] != "") {
                    $symbol = $currency['symbol'];
                }
                break;
            }
        }
        $formattedPrice = str_replace($currencyCode, $symbol, $formattedPrice);
    }

    return $formattedPrice;
}

function formatDate($date, $lang = 'en')
{
    $currentYear = date('Y');
    $dateYear = date('Y', strtotime($date));

    // Determine the date format based on whether the year matches the current year
    $dateFormat = ($currentYear == $dateYear) ? 'MMM d' : 'MMM yyyy';

    // Validate the locale and fallback to 'en' if unsupported
    if (!in_array($lang, ResourceBundle::getLocales(''))) {
        $lang = 'en'; // Fallback to English
    }

    // Create an IntlDateFormatter instance for the specified language
    $formatter = new IntlDateFormatter(
        $lang,
        IntlDateFormatter::SHORT,
        IntlDateFormatter::NONE,
        null,
        null,
        $dateFormat
    );

    // Format the date
    $formattedDate = $formatter->format(new DateTime($date));

    return $formattedDate;
}

// Get the first name of the user
$stmt = $db->prepare("SELECT username, firstname FROM user WHERE id = :userId");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$user = $result->fetchArray(SQLITE3_ASSOC);
$first_name = $user['firstname'] ?? $user['username'] ?? '';
$effectiveUserGroup = wallos_get_effective_user_group($userData['user_group'] ?? WALLOS_USER_GROUP_FREE, $isAdmin);
$subscriptionImagePolicy = wallos_get_subscription_media_policy($db);

// Fetch the next 3 enabled subscriptions up for payment
$stmt = $db->prepare("SELECT id, logo, name, price, currency_id, next_payment, inactive FROM subscriptions WHERE user_id = :userId AND lifecycle_status = :lifecycle_status AND next_payment >= date('now') AND inactive = 0 ORDER BY next_payment ASC LIMIT 3");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
$result = $stmt->execute();
$upcomingSubscriptions = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $upcomingSubscriptions[] = $row;
}

// Fetch enabled subscriptions with manual renewal that are overdue
$stmt = $db->prepare("SELECT id, logo, name, price, currency_id, next_payment, inactive, auto_renew FROM subscriptions WHERE user_id = :userId AND lifecycle_status = :lifecycle_status AND next_payment < date('now') AND auto_renew = 0 AND inactive = 0 ORDER BY next_payment ASC");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
$result = $stmt->execute();
$overdueSubscriptions = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $overdueSubscriptions[] = $row;
}
$hasOverdueSubscriptions = !empty($overdueSubscriptions);

require_once 'includes/stats_calculations.php';

// Get AI Recommendations for user
$stmt = $db->prepare("SELECT * FROM ai_recommendations WHERE user_id = :userId");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$aiRecommendations = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $aiRecommendations[] = $row;
}

?>

<section class="contain dashboard" data-page-ui-hide-target>
    <?php
        if ($isAdmin && $settings['update_notification']) {
            if (!is_null($settings['latest_version'])) {
                $latestVersion = $settings['latest_version'];
                if (version_compare($version, $latestVersion) == -1) {
                    ?>
                    <div class="update-banner">
                    <?= translate('new_version_available', $i18n) ?>:
                        <span><a href="https://github.com/ellite/Wallos/releases/tag/<?= htmlspecialchars($latestVersion) ?>"
                        target="_blank" rel="noreferer">
                        <?= htmlspecialchars($latestVersion) ?>
                        </a></span>
                    </div>
                    <?php
                }
            }
        }
        if ($demoMode) {
            ?>
            <div class="demo-banner">
            Running in <b>Demo Mode</b>, certain actions and settings are disabled.<br>
            The database will be reset every 120 minutes.
            </div>
            <?php
        }
    ?>
    <h1><?= translate('hello', $i18n) ?> <?= htmlspecialchars($first_name) ?></h1>
    <div class="subscription-upload-policy-banner">
        <div class="subscription-upload-policy-header">
            <h2><?= translate('homepage_upload_policy_title', $i18n) ?></h2>
            <span class="user-group-badge <?= htmlspecialchars($effectiveUserGroup, ENT_QUOTES, 'UTF-8') ?>">
                <?= wallos_get_user_group_label($userData['user_group'] ?? WALLOS_USER_GROUP_FREE, $i18n, $isAdmin) ?>
            </span>
        </div>
        <p><?= translate('homepage_upload_policy_summary', $i18n) ?></p>
        <p>
            <?php
            if ($effectiveUserGroup === 'admin') {
                echo translate('homepage_upload_policy_admin', $i18n);
            } elseif ($effectiveUserGroup === WALLOS_USER_GROUP_TRUSTED) {
                echo sprintf(translate('homepage_upload_policy_trusted_dynamic', $i18n), (int) $subscriptionImagePolicy['trusted_upload_limit']);
            } else {
                echo translate('homepage_upload_policy_free', $i18n);
            }
            ?>
        </p>
    </div>

    <?php
    // If there are overdue subscriptions, display them
    if ($hasOverdueSubscriptions) {
        ?>
        <div class="overdue-subscriptions">
            <h2><?= translate('overdue_renewals', $i18n) ?></h2>
            <div class="dashboard-subscriptions-container">
                <div class="dashboard-subscriptions-list">
                    <?php

                    foreach ($overdueSubscriptions as $subscription) {
                        $subscriptionLogo = "images/uploads/logos/" . $subscription['logo'];
                        $subscriptionName = htmlspecialchars($subscription['name']);
                        $subscriptionPrice = $subscription['price'];
                        $subscriptionCurrency = $subscription['currency_id'];
                        $subscriptionNextPayment = $subscription['next_payment'];
                        $subscriptionDisplayNextPayment = date('F j', strtotime($subscriptionNextPayment));
                        $subscriptionDisplayPrice = formatPrice($subscriptionPrice, $currencies[$subscriptionCurrency]['code'], $currencies);

                        ?>
                        <div class="subscription-item">
                            <?php
                            if (empty($subscription['logo'])) {
                                ?>
                                <p class="subscription-item-title"><?= $subscriptionName ?></p>
                                <?php
                            } else {
                                ?>
                                <img src="<?= $subscriptionLogo ?>" alt="<?= $subscriptionName ?> logo"
                                    class="subscription-item-logo" title="<?= $subscriptionName ?>">
                                <?php
                            }
                            ?>
                            <div class="subscription-item-info">
                                <p class="subscription-item-date"> <?= formatDate($subscriptionDisplayNextPayment, $lang) ?>
                                </p>
                                <p class="subscription-item-price"> <?= $subscriptionDisplayPrice ?></p>
                            </div>
                        </div>
                        <?php
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }
    ?>

    <div class="upcoming-subscriptions">
        <h2><?= translate('upcoming_payments', $i18n) ?></h2>
        <div class="dashboard-subscriptions-container">
            <div class="dashboard-subscriptions-list">
                <?php
                if (empty($upcomingSubscriptions)) {
                    ?>
                    <p><?= translate('no_upcoming_payments', $i18n) ?></p>
                    <?php
                } else {
                    foreach ($upcomingSubscriptions as $subscription) {
                        $subscriptionLogo = "images/uploads/logos/" . $subscription['logo'];
                        $subscriptionName = htmlspecialchars($subscription['name']);
                        $subscriptionPrice = $subscription['price'];
                        $subscriptionCurrency = $subscription['currency_id'];
                        $subscriptionNextPayment = $subscription['next_payment'];
                        $subscriptionDisplayNextPayment = date('F j', strtotime($subscriptionNextPayment));
                        $subscriptionDisplayPrice = formatPrice($subscriptionPrice, $currencies[$subscriptionCurrency]['code'], $currencies);

                        ?>
                        <div class="subscription-item">
                            <?php
                            if (empty($subscription['logo'])) {
                                ?>
                                <p class="subscription-item-title"><?= $subscriptionName ?></p>
                                <?php
                            } else {
                                ?>
                                <img src="<?= $subscriptionLogo ?>" alt="<?= $subscriptionName ?> logo"
                                    class="subscription-item-logo" title="<?= $subscriptionName ?>">
                                <?php
                            }
                            ?>
                            <div class="subscription-item-info">
                                <p class="subscription-item-date"> <?= formatDate($subscriptionDisplayNextPayment, $lang) ?></p>
                                <p class="subscription-item-price"> <?= $subscriptionDisplayPrice ?></p>
                            </div>
                        </div>
                        <?php
                    }
                }
                ?>
            </div>
        </div>

        <?php if (!empty($aiRecommendations)) { ?>
            <div class="ai-recommendations">
                <h2><?= translate('ai_recommendations', $i18n) ?></h2>
                <div class="ai-recommendations-container">
                    <ul class="ai-recommendations-list">
                        <?php

                        foreach ($aiRecommendations as $key => $recommendation) { ?>
                            <li class="ai-recommendation-item" data-id="<?= $recommendation['id'] ?>">
                                <div class="ai-recommendation-header">
                                    <h3>
                                        <span><?= ($key + 1) . ". " ?></span>
                                        <?= htmlspecialchars($recommendation['title']) ?>
                                    </h3>
                                    <span class="item-arrow-down fa fa-caret-down"></span>
                                </div>
                                <p class="collapsible"><?= htmlspecialchars($recommendation['description']) ?></p>
                                <p class="ai-recommendation-savings">
                                    <?= htmlspecialchars($recommendation['savings']) ?>
                                    <span>
                                        <a href="#" class="delete-ai-recommendation" title="<?= translate('delete', $i18n) ?>">
                                            <i class="fa fa-trash"></i>
                                        </a>
                                    </span>
                                </p>
                            </li>
                        <?php } ?>
                    </ul>
                </div>
            </div>

        <?php } ?>

        <?php if (isset($amountDueThisMonth) || isset($currentMonthActualPaid) || isset($currentYearActualPaid) || isset($currentYearProjectedSpend) || isset($budget) || isset($budgetUsed) || isset($budgetLeft) || isset($overBudgetAmount)) { ?>
            <div class="budget-subscriptions">
                <h2><?= translate('your_budget', $i18n) ?></h2>
                <div class="dashboard-subscriptions-container">
                    <?php if (isset($yearlyBudget) && $yearlyBudget > 0 && !empty($yearlyBudgetVisualizationSegments)) { ?>
                        <section class="budget-visualizer">
                            <div class="budget-visualizer-header">
                                <div>
                                    <h3><?= translate('yearly_budget_breakdown', $i18n) ?></h3>
                                    <p><?= translate('yearly_budget_breakdown_info', $i18n) ?></p>
                                </div>
                                <div class="budget-visualizer-figures">
                                    <strong><?= CurrencyFormatter::format($currentYearProjectedSpend, $currencies[$userData['main_currency']]['code']) ?></strong>
                                    <span>/ <?= CurrencyFormatter::format($yearlyBudget, $currencies[$userData['main_currency']]['code']) ?></span>
                                </div>
                            </div>
                            <div class="budget-visualizer-bar" aria-hidden="true">
                                <?php foreach ($yearlyBudgetVisualizationSegments as $segment): ?>
                                    <?php if (($segment['value'] ?? 0) <= 0) { continue; } ?>
                                    <span class="budget-visualizer-segment"
                                        style="width: <?= max(4, round(((float) ($segment['ratio'] ?? 0)) * 100, 2)) ?>%; background-color: <?= htmlspecialchars($segment['color'], ENT_QUOTES, 'UTF-8') ?>;"
                                        title="<?= htmlspecialchars($segment['label'] . ': ' . CurrencyFormatter::format($segment['value'], $currencies[$userData['main_currency']]['code']), ENT_QUOTES, 'UTF-8') ?>"></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="budget-visualizer-legend">
                                <?php foreach ($yearlyBudgetVisualizationSegments as $segment): ?>
                                    <?php if (($segment['value'] ?? 0) <= 0) { continue; } ?>
                                    <div class="budget-visualizer-legend-item">
                                        <span class="budget-visualizer-swatch"
                                            style="background-color: <?= htmlspecialchars($segment['color'], ENT_QUOTES, 'UTF-8') ?>;"></span>
                                        <span class="budget-visualizer-label"><?= htmlspecialchars($segment['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <strong><?= CurrencyFormatter::format($segment['value'], $currencies[$userData['main_currency']]['code']) ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php } ?>
                    <div class="dashboard-subscriptions-list">
                        <?php if (isset($amountDueThisMonth)) { ?>
                            <div class="subscription-item thin">
                                <p class="subscription-item-title"><?= translate("amount_due", $i18n) ?></p>
                                <div class="subscription-item-info">
                                    <p class="subscription-item-value">
                                        <?= CurrencyFormatter::format($amountDueThisMonth, $currencies[$userData['main_currency']]['code']) ?>
                                    </p>
                                </div>
                                <?php wallos_render_metric_explanation_trigger('amount_due', $metricExplanations, translate('amount_due', $i18n)); ?>
                            </div>
                        <?php } ?>
                        <?php if (isset($currentMonthActualPaid)) { ?>
                            <div class="subscription-item thin">
                                <p class="subscription-item-title"><?= translate("actual_paid_this_month", $i18n) ?></p>
                                <div class="subscription-item-info">
                                    <p class="subscription-item-value">
                                        <?= CurrencyFormatter::format($currentMonthActualPaid, $currencies[$userData['main_currency']]['code']) ?>
                                    </p>
                                </div>
                                <?php wallos_render_metric_explanation_trigger('actual_paid_this_month', $metricExplanations, translate('actual_paid_this_month', $i18n)); ?>
                            </div>
                        <?php } ?>
                        <?php if (isset($currentYearActualPaid)) { ?>
                            <div class="subscription-item thin">
                                <p class="subscription-item-title"><?= translate("actual_paid_this_year", $i18n) ?></p>
                                <div class="subscription-item-info">
                                    <p class="subscription-item-value">
                                        <?= CurrencyFormatter::format($currentYearActualPaid, $currencies[$userData['main_currency']]['code']) ?>
                                    </p>
                                </div>
                                <?php wallos_render_metric_explanation_trigger('actual_paid_this_year', $metricExplanations, translate('actual_paid_this_year', $i18n)); ?>
                            </div>
                        <?php } ?>
                        <?php if (isset($currentYearProjectedSpend)) { ?>
                            <div class="subscription-item thin">
                                <p class="subscription-item-title"><?= translate("projected_yearly_spend", $i18n) ?></p>
                                <div class="subscription-item-info">
                                    <p class="subscription-item-value">
                                        <?= CurrencyFormatter::format($currentYearProjectedSpend, $currencies[$userData['main_currency']]['code']) ?>
                                    </p>
                                </div>
                                <?php wallos_render_metric_explanation_trigger('projected_yearly_spend', $metricExplanations, translate('projected_yearly_spend', $i18n)); ?>
                            </div>
                        <?php } ?>
                        <?php if (isset($budget) && $budget > 0) { ?>
                            <div class="subscription-item thin">
                                <p class="subscription-item-title"><?= translate("monthly_budget", $i18n) ?></p>
                                <div class="subscription-item-info">
                                    <p class="subscription-item-value">
                                        <?= formatPrice($budget, $currencies[$userData['main_currency']]['code'], $currencies) ?>
                                    </p>
                                </div>
                                <?php wallos_render_metric_explanation_trigger('budget', $metricExplanations, translate('monthly_budget', $i18n)); ?>
                            </div>
                        <?php } ?>
                        <?php if (isset($budgetUsed)) { ?>
                            <div class="subscription-item thin">
                                <p class="subscription-item-title"><?= translate("monthly_budget_used", $i18n) ?></p>
                                <div class="subscription-item-info">
                                    <p class="subscription-item-value">
                                        <?= number_format($budgetUsed, 2) ?>%
                                    </p>
                                </div>
                                <?php wallos_render_metric_explanation_trigger('budget_used', $metricExplanations, translate('monthly_budget_used', $i18n)); ?>
                            </div>
                        <?php } ?>
                        <?php if (isset($budgetLeft)) { ?>
                            <div class="subscription-item thin">
                                <p class="subscription-item-title"><?= translate("monthly_budget_remaining", $i18n) ?></p>
                                <div class="subscription-item-info">
                                    <p class="subscription-item-value">
                                        <?= formatPrice($budgetLeft, $currencies[$userData['main_currency']]['code'], $currencies) ?>
                                    </p>
                                </div>
                                <?php wallos_render_metric_explanation_trigger('budget_remaining', $metricExplanations, translate('monthly_budget_remaining', $i18n)); ?>
                            </div>
                        <?php } ?>
                        <?php if (isset($overBudgetAmount) && $overBudgetAmount > 0) { ?>
                            <div class="subscription-item thin">
                                <p class="subscription-item-title"><?= translate("monthly_amount_over_budget", $i18n) ?></p>
                                <div class="subscription-item-info">
                                    <p class="subscription-item-value">
                                        <?= formatPrice($overBudgetAmount, $currencies[$userData['main_currency']]['code'], $currencies) ?>
                                    </p>
                                </div>
                                <?php wallos_render_metric_explanation_trigger('amount_over_budget', $metricExplanations, translate('monthly_amount_over_budget', $i18n)); ?>
                            </div>
                        <?php } ?>
                        <?php if (isset($yearlyBudget) && $yearlyBudget > 0) { ?>
                            <div class="subscription-item thin">
                                <p class="subscription-item-title"><?= translate("yearly_budget", $i18n) ?></p>
                                <div class="subscription-item-info">
                                    <p class="subscription-item-value">
                                        <?= formatPrice($yearlyBudget, $currencies[$userData['main_currency']]['code'], $currencies) ?>
                                    </p>
                                </div>
                                <?php wallos_render_metric_explanation_trigger('yearly_budget', $metricExplanations, translate('yearly_budget', $i18n)); ?>
                            </div>
                        <?php } ?>
                        <?php if (isset($yearlyBudgetUsed)) { ?>
                            <div class="subscription-item thin">
                                <p class="subscription-item-title"><?= translate("yearly_budget_used", $i18n) ?></p>
                                <div class="subscription-item-info">
                                    <p class="subscription-item-value">
                                        <?= number_format($yearlyBudgetUsed, 2) ?>%
                                    </p>
                                </div>
                                <?php wallos_render_metric_explanation_trigger('yearly_budget_used', $metricExplanations, translate('yearly_budget_used', $i18n)); ?>
                            </div>
                        <?php } ?>
                        <?php if (isset($yearlyBudgetRemaining)) { ?>
                            <div class="subscription-item thin">
                                <p class="subscription-item-title"><?= translate("yearly_budget_remaining", $i18n) ?></p>
                                <div class="subscription-item-info">
                                    <p class="subscription-item-value">
                                        <?= formatPrice($yearlyBudgetRemaining, $currencies[$userData['main_currency']]['code'], $currencies) ?>
                                    </p>
                                </div>
                                <?php wallos_render_metric_explanation_trigger('yearly_budget_remaining', $metricExplanations, translate('yearly_budget_remaining', $i18n)); ?>
                            </div>
                        <?php } ?>
                        <?php if (isset($yearlyOverBudgetAmount) && $yearlyOverBudgetAmount > 0) { ?>
                            <div class="subscription-item thin">
                                <p class="subscription-item-title"><?= translate("yearly_amount_over_budget", $i18n) ?></p>
                                <div class="subscription-item-info">
                                    <p class="subscription-item-value">
                                        <?= formatPrice($yearlyOverBudgetAmount, $currencies[$userData['main_currency']]['code'], $currencies) ?>
                                    </p>
                                </div>
                                <?php wallos_render_metric_explanation_trigger('yearly_amount_over_budget', $metricExplanations, translate('yearly_amount_over_budget', $i18n)); ?>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        <?php } ?>
    </div>

    <?php if (isset($activeSubscriptions) && $activeSubscriptions > 0) { ?>
        <div class="current-subscriptions">
            <h2><?= translate('your_subscriptions', $i18n) ?></h2>
            <div class="dashboard-subscriptions-container">
                <div class="dashboard-subscriptions-list">
                    <div class="subscription-item thin">
                        <p class="subscription-item-title"><?= translate('active_subscriptions', $i18n) ?></p>
                        <div class="subscription-item-info">
                            <p class="subscription-item-value"><?= $activeSubscriptions ?></p>
                        </div>
                    </div>

                    <?php if (isset($totalCostPerMonth)) { ?>
                        <div class="subscription-item thin">
                            <p class="subscription-item-title"><?= translate('monthly_cost', $i18n) ?></p>
                            <div class="subscription-item-info">
                                <p class="subscription-item-value">
                                    <?= CurrencyFormatter::format($totalCostPerMonth, $currencies[$userData['main_currency']]['code']) ?>
                                </p>
                            </div>
                            <?php wallos_render_metric_explanation_trigger('monthly_cost', $metricExplanations, translate('monthly_cost', $i18n)); ?>
                        </div>
                    <?php } ?>

                    <?php if (isset($totalCostPerYear)) { ?>
                        <div class="subscription-item thin">
                            <p class="subscription-item-title"><?= translate('yearly_cost', $i18n) ?></p>
                            <div class="subscription-item-info">
                                <p class="subscription-item-value">
                                    <?= CurrencyFormatter::format($totalCostPerYear, $currencies[$userData['main_currency']]['code']) ?>
                                </p>
                            </div>
                            <?php wallos_render_metric_explanation_trigger('yearly_cost', $metricExplanations, translate('yearly_cost', $i18n)); ?>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    <?php } ?>

    <?php if (isset($inactiveSubscriptions) && $inactiveSubscriptions > 0) { ?>
        <div class="savings-subscriptions">
            <h2><?= translate('your_savings', $i18n) ?></h2>
            <div class="dashboard-subscriptions-container">
                <div class="dashboard-subscriptions-list">
                    <div class="subscription-item thin">
                        <p class="subscription-item-title"><?= translate('inactive_subscriptions', $i18n) ?></p>
                        <div class="subscription-item-info">
                            <p class="subscription-item-value"><?= $inactiveSubscriptions ?></p>
                        </div>
                    </div>

                    <?php if (isset($totalSavingsPerMonth) && $totalSavingsPerMonth > 0) { ?>
                        <div class="subscription-item thin">
                            <p class="subscription-item-title"><?= translate('monthly_savings', $i18n) ?></p>
                            <div class="subscription-item-info">
                                <p class="subscription-item-value">
                                    <?= CurrencyFormatter::format($totalSavingsPerMonth, $currencies[$userData['main_currency']]['code']) ?>
                                </p>
                            </div>
                        </div>

                        <div class="subscription-item thin">
                            <p class="subscription-item-title"><?= translate('yearly_savings', $i18n) ?></p>
                            <div class="subscription-item-info">
                                <p class="subscription-item-value">
                                    <?= CurrencyFormatter::format($totalSavingsPerMonth * 12, $currencies[$userData['main_currency']]['code']) ?>
                                </p>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    <?php } ?>

</section>

<?php wallos_render_metric_explanation_modal($i18n); ?>
<?php wallos_render_page_immersive_toggle($lang); ?>


<script src="scripts/dashboard.js?<?= $version ?>"></script>
<script src="scripts/metric-explanations.js?<?= $version . '.' . @filemtime(__DIR__ . '/scripts/metric-explanations.js') ?>"></script>

<?php
require_once 'includes/footer.php';
?>
