<?php

require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/user_groups.php';
require_once 'includes/subscription_media.php';
require_once 'includes/subscription_trash.php';
require_once 'includes/subscription_payment_records.php';
require_once 'includes/subscription_payment_history.php';
require_once 'includes/subscription_price_rules.php';
require_once 'includes/subscription_pages.php';
require_once 'includes/page_immersive_toggle.php';

include_once 'includes/list_subscriptions.php';

$sort = "manual_order";
$sortOrder = $sort;

if ($settings['disabledToBottom'] === 'true') {
  $sql = "SELECT * FROM subscriptions WHERE user_id = :userId AND lifecycle_status = :lifecycle_status ORDER BY inactive ASC, sort_order ASC, next_payment ASC";
} else {
  $sql = "SELECT * FROM subscriptions WHERE user_id = :userId AND lifecycle_status = :lifecycle_status ORDER BY sort_order ASC, next_payment ASC, inactive ASC";
}

$params = array();
$currentSubscriptionPageFilter = wallos_resolve_subscription_page_filter(
  $db,
  $userId,
  $_GET['subscription_page'] ?? WALLOS_SUBSCRIPTION_PAGE_FILTER_ALL
);
$subscriptionPagesPayload = wallos_get_subscription_pages_payload(
  $db,
  $userId,
  isset($settings['hideDisabledSubscriptions']) && $settings['hideDisabledSubscriptions'] === 'true'
);
$subscriptionPages = $subscriptionPagesPayload['pages'];
$subscriptionPageCounts = $subscriptionPagesPayload['counts'];

if (isset($_COOKIE['sortOrder']) && $_COOKIE['sortOrder'] != "") {
  $sort = $_COOKIE['sortOrder'] ?? 'manual_order';
}

$sortOrder = $sort;
$allowedSortCriteria = ['manual_order', 'name', 'id', 'next_payment', 'price', 'payment_total_main', 'remaining_value', 'payer_user_id', 'category_id', 'payment_method_id', 'inactive', 'alphanumeric', 'renewal_type'];
$order = ($sort == "price" || $sort == "id" || $sort == "payment_total_main" || $sort == "remaining_value") ? "DESC" : "ASC";

if ($sort == "alphanumeric") {
  $sort = "name";
}

if (!in_array($sort, $allowedSortCriteria)) {
  $sort = "manual_order";
}

if ($sort == "renewal_type") {
  $sort = "auto_renew";
}

if ($sort == "manual_order") {
  $sort = "sort_order";
}

if ($sort == "payment_total_main" || $sort == "remaining_value") {
  $sort = "sort_order";
}

$sql = "SELECT * FROM subscriptions WHERE user_id = :userId AND lifecycle_status = :lifecycle_status";
wallos_append_subscription_page_filter_clause($sql, $params, $currentSubscriptionPageFilter, 'subscription_page');

if (isset($_GET['member'])) {
  $memberIds = explode(',', $_GET['member']);
  $placeholders = array_map(function ($key) {
    return ":member{$key}";
  }, array_keys($memberIds));

  $sql .= " AND payer_user_id IN (" . implode(',', $placeholders) . ")";

  foreach ($memberIds as $key => $memberId) {
    $params[":member{$key}"] = $memberId;
  }
}

if (isset($_GET['category'])) {
  $categoryIds = explode(',', $_GET['category']);
  $placeholders = array_map(function ($key) {
    return ":category{$key}";
  }, array_keys($categoryIds));

  $sql .= " AND category_id IN (" . implode(',', $placeholders) . ")";

  foreach ($categoryIds as $key => $categoryId) {
    $params[":category{$key}"] = $categoryId;
  }
}

if (isset($_GET['payment'])) {
  $paymentIds = explode(',', $_GET['payment']);
  $placeholders = array_map(function ($key) {
    return ":payment{$key}";
  }, array_keys($paymentIds));

  $sql .= " AND payment_method_id IN (" . implode(',', $placeholders) . ")";

  foreach ($paymentIds as $key => $paymentId) {
    $params[":payment{$key}"] = $paymentId;
  }
}

if (!isset($settings['hideDisabledSubscriptions']) || $settings['hideDisabledSubscriptions'] !== 'true') {
  if (isset($_GET['state']) && $_GET['state'] != "") {
    $sql .= " AND inactive = :inactive";
    $params[':inactive'] = $_GET['state'];
  }
}

$orderByClauses = [];

if ($settings['disabledToBottom'] === 'true') {
  if (in_array($sort, ["payer_user_id", "category_id", "payment_method_id"])) {
    $orderByClauses[] = "$sort $order";
    $orderByClauses[] = "inactive ASC";
  } else {
    $orderByClauses[] = "inactive ASC";
    $orderByClauses[] = "$sort $order";
  }
} else {
  $orderByClauses[] = "$sort $order";
  if ($sort != "inactive") {
    $orderByClauses[] = "inactive ASC";
  }
}

if ($sort != "next_payment" && $sort != "sort_order") {
  $orderByClauses[] = "next_payment ASC";
}

$sql .= " ORDER BY " . implode(", ", $orderByClauses);

$stmt = $db->prepare($sql);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);

if (!empty($params)) {
  foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, SQLITE3_INTEGER);
  }
}

$result = $stmt->execute();
if ($result) {
  $subscriptions = array();
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $subscriptions[] = $row;
  }
}

$trashedSubscriptions = [];
$trashedStmt = $db->prepare('
  SELECT *
  FROM subscriptions
  WHERE user_id = :userId AND lifecycle_status = :lifecycle_status
  ORDER BY trashed_at DESC, id DESC
');
$trashedStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$trashedStmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_TRASHED, SQLITE3_TEXT);
$trashedResult = $trashedStmt->execute();
while ($trashedResult && ($row = $trashedResult->fetchArray(SQLITE3_ASSOC))) {
  $trashedSubscriptions[] = $row;
}

foreach ($subscriptions as $subscription) {
  $memberId = $subscription['payer_user_id'];
  $members[$memberId]['count']++;
  $categoryId = $subscription['category_id'];
  $categories[$categoryId]['count']++;
  $paymentMethodId = $subscription['payment_method_id'];
  $payment_methods[$paymentMethodId]['count']++;
}

if ($sortOrder == "category_id") {
  usort($subscriptions, function ($a, $b) use ($categories) {
    return $categories[$a['category_id']]['order'] - $categories[$b['category_id']]['order'];
  });
}

if ($sortOrder == "payment_method_id") {
  usort($subscriptions, function ($a, $b) use ($payment_methods) {
    return $payment_methods[$a['payment_method_id']]['order'] - $payment_methods[$b['payment_method_id']]['order'];
  });
}

$headerClass = (
  (int) ($subscriptionPageCounts['all'] ?? count($subscriptions))) > 0
  || !empty($subscriptionPages)
    ? "main-actions"
    : "main-actions hidden";
$effectiveUserGroup = wallos_get_effective_user_group($userData['user_group'] ?? WALLOS_USER_GROUP_FREE, $isAdmin);
$canUploadSubscriptionImages = wallos_can_upload_subscription_images($isAdmin, $userData['user_group'] ?? WALLOS_USER_GROUP_FREE);
$subscriptionImagePolicy = wallos_get_subscription_media_policy($db);
$mainCurrencyId = (int) ($userData['main_currency'] ?? $main_currency ?? 0);
$uploadedImagesMap = wallos_get_subscription_uploaded_images_map($db, $userId);
$paymentRecordsMap = wallos_get_subscription_payment_records_map($db, $userId, 6);
$paymentRecordCountMap = wallos_get_subscription_payment_record_count_map($db, $userId);
$paymentTotalMap = wallos_get_subscription_payment_total_map($db, $userId);
$priceRulesMap = wallos_get_subscription_price_rules_map($db, $userId, true);
$subscriptionsJsVersion = $version . '.' . @filemtime(__DIR__ . '/scripts/subscriptions.js');
$subscriptionDisplayColumns = (int) ($settings['subscriptionDisplayColumns'] ?? 1);
if (!in_array($subscriptionDisplayColumns, [1, 2, 3], true)) {
  $subscriptionDisplayColumns = 1;
}
$subscriptionValueVisibility = $settings['subscriptionValueVisibility'] ?? [
  'metrics' => true,
  'payment_records' => true,
];
$subscriptionImageLayoutForm = $settings['subscriptionImageLayoutForm'] ?? 'focus';
$subscriptionImageLayoutDetail = $settings['subscriptionImageLayoutDetail'] ?? 'focus';
$subscriptionPagePreferences = [
  'displayColumns' => $subscriptionDisplayColumns,
  'valueVisibility' => $subscriptionValueVisibility,
  'imageLayout' => [
    'form' => $subscriptionImageLayoutForm,
    'detail' => $subscriptionImageLayoutDetail,
  ],
];
?>
<style>
  .logo-preview:after {
    content: '<?= translate('upload_logo', $i18n) ?>';
  }

  .subscription-page-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    width: 100%;
    flex: 1 1 100%;
    margin-bottom: 0;
    order: 2;
  }

  .subscription-page-tabs {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1 1 auto;
    min-width: 0;
    max-width: 100%;
    overflow-x: auto;
    padding-bottom: 4px;
    scrollbar-width: thin;
  }

  .subscription-page-tab {
    border: 1px solid var(--border-color, rgba(0, 0, 0, 0.12));
    background: var(--card-background-secondary, rgba(255, 255, 255, 0.92));
    color: inherit;
    border-radius: 999px;
    min-height: 38px;
    padding: 0 14px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
    transition: transform .18s ease, border-color .18s ease, background-color .18s ease, box-shadow .18s ease;
  }

  .subscription-page-tab:hover {
    transform: translateY(-1px);
  }

  .subscription-page-tab.is-active {
    border-color: var(--theme-color, #7c5cff);
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.08);
  }

  .subscription-page-manager-trigger {
    white-space: nowrap;
  }

  .subscription-pages-manager-modal {
    max-width: 860px;
    width: min(860px, 92vw);
  }

  .subscription-pages-manager-toolbar,
  .subscription-pages-manager-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .subscription-pages-manager-create {
    display: flex;
    gap: 12px;
    align-items: center;
  }

  .subscription-pages-manager-create input {
    flex: 1;
  }

  .subscription-pages-manager-item {
    display: flex;
    gap: 12px;
    align-items: center;
    justify-content: space-between;
    border: 1px solid var(--border-color, rgba(0, 0, 0, 0.1));
    border-radius: 18px;
    padding: 14px 16px;
    background: var(--card-background-secondary, rgba(255, 255, 255, 0.92));
  }

  .subscription-pages-manager-item-main {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    min-width: 0;
  }

  .subscription-page-name-input {
    flex: 1;
    min-width: 0;
  }

  .subscription-pages-manager-item-actions {
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .subscription-pages-manager-empty {
    border: 1px dashed var(--border-color, rgba(0, 0, 0, 0.14));
    border-radius: 18px;
    padding: 20px 18px;
    text-align: center;
    opacity: .78;
  }

  @media (max-width: 900px) {
    .subscription-page-toolbar {
      flex-direction: column;
      align-items: stretch;
    }

    .subscription-page-manager-trigger {
      align-self: flex-start;
    }

    .subscription-pages-manager-item,
    .subscription-pages-manager-create {
      flex-direction: column;
      align-items: stretch;
    }

    .subscription-pages-manager-item-actions {
      justify-content: flex-end;
      flex-wrap: wrap;
    }
  }

  .subscriptions-page-layout .inline-row {
    flex: 1 1 100%;
  }

  .subscriptions-page-layout .top-actions {
    order: 3;
    flex: 1 1 100%;
    width: 100%;
    justify-content: flex-start;
  }

  .subscriptions-page-layout .top-actions .search {
    width: min(100%, 360px);
    min-width: min(100%, 280px);
  }
</style>

<section class="contain contain-wide subscriptions-page-layout" data-page-ui-hide-target>
  <header class="<?= $headerClass ?>" id="main-actions">
    <div class="inline-row">
      <button class="button" onClick="addSubscription()">
        <i class="fa-solid fa-circle-plus"></i>
        <?= translate('new_subscription', $i18n) ?>
      </button>
      <button class="button secondary-button tiny subscription-recycle-bin-trigger" type="button"
        onClick="openSubscriptionRecycleBinModal(event)" title="<?= translate('subscription_recycle_bin', $i18n) ?>">
        <i class="fa-solid fa-trash-can"></i>
        <span><?= translate('subscription_recycle_bin', $i18n) ?></span>
        <span class="section-count-badge"><?= count($trashedSubscriptions) ?></span>
      </button>
      <button class="button secondary-button tiny mobile-grow" type="button" id="generateSubscriptionImageVariantsButton"
        onClick="generateSubscriptionImageVariants()">
        <i class="fa-solid fa-wand-magic-sparkles"></i>
        <?= translate('subscription_image_generate_variants', $i18n) ?>
      </button>
      <div class="media-layout-toggle subscription-column-toggle" role="group"
        aria-label="<?= translate('subscription_layout_switch', $i18n) ?>">
        <button type="button" class="media-layout-button<?= $subscriptionDisplayColumns === 1 ? ' is-active' : '' ?>" data-subscription-columns="1"
          title="<?= translate('subscription_layout_single_column', $i18n) ?>"
          aria-pressed="<?= $subscriptionDisplayColumns === 1 ? 'true' : 'false' ?>" onClick="setSubscriptionDisplayColumns(1, this)">
          <i class="fa-solid fa-list"></i>
          <span><?= translate('subscription_layout_single_column', $i18n) ?></span>
        </button>
        <button type="button" class="media-layout-button<?= $subscriptionDisplayColumns === 2 ? ' is-active' : '' ?>" data-subscription-columns="2"
          title="<?= translate('subscription_layout_two_columns', $i18n) ?>"
          aria-pressed="<?= $subscriptionDisplayColumns === 2 ? 'true' : 'false' ?>" onClick="setSubscriptionDisplayColumns(2, this)">
          <i class="fa-solid fa-table-columns"></i>
          <span><?= translate('subscription_layout_two_columns', $i18n) ?></span>
        </button>
        <button type="button" class="media-layout-button<?= $subscriptionDisplayColumns === 3 ? ' is-active' : '' ?>" data-subscription-columns="3"
          title="<?= translate('subscription_layout_three_columns', $i18n) ?>"
          aria-pressed="<?= $subscriptionDisplayColumns === 3 ? 'true' : 'false' ?>" onClick="setSubscriptionDisplayColumns(3, this)">
          <i class="fa-solid fa-grip"></i>
          <span><?= translate('subscription_layout_three_columns', $i18n) ?></span>
        </button>
      </div>

      <div class="media-layout-toggle subscription-value-toggle" role="group"
        aria-label="<?= translate('subscription_value_metrics_display', $i18n) ?>">
        <button type="button" class="media-layout-button<?= !empty($subscriptionValueVisibility['metrics']) ? ' is-active' : '' ?>" data-subscription-value-toggle="metrics"
          title="<?= translate('subscription_value_metrics_display', $i18n) ?>" aria-pressed="<?= !empty($subscriptionValueVisibility['metrics']) ? 'true' : 'false' ?>"
          onClick="toggleSubscriptionValueMetric('metrics')">
          <i class="fa-solid fa-sack-dollar"></i>
          <span><?= translate('subscription_value_metrics_display', $i18n) ?></span>
        </button>
        <button type="button" class="media-layout-button<?= !empty($subscriptionValueVisibility['payment_records']) ? ' is-active' : '' ?>" data-subscription-value-toggle="payment_records"
          title="<?= translate('subscription_payment_history', $i18n) ?>" aria-pressed="<?= !empty($subscriptionValueVisibility['payment_records']) ? 'true' : 'false' ?>"
          onClick="toggleSubscriptionValueMetric('payment_records')">
          <i class="fa-solid fa-receipt"></i>
          <span><?= translate('subscription_payment_history', $i18n) ?></span>
        </button>
      </div>

      <div class="filtermenu on-dashboard">
        <button class="button secondary-button" id="filtermenu-button" title="<?= translate("filter", $i18n) ?>">
          <i class="fa-solid fa-filter"></i>
        </button>
        <?php include 'includes/filters_menu.php'; ?>
      </div>

      <div class="sort-container">
        <button class="button secondary-button" value="Sort" onClick="toggleSortOptions()" id="sort-button"
          title="<?= translate('sort', $i18n) ?>">
          <i class="fa-solid fa-arrow-down-wide-short"></i>
        </button>
        <?php include 'includes/sort_options.php'; ?>
      </div>
    </div>
    <div class="subscription-page-toolbar">
      <div class="subscription-page-tabs" id="subscription-page-tabs" role="tablist"
        data-current-filter="<?= htmlspecialchars(wallos_get_subscription_page_filter_value($currentSubscriptionPageFilter), ENT_QUOTES, 'UTF-8') ?>">
        <?php
        $allFilterValue = WALLOS_SUBSCRIPTION_PAGE_FILTER_ALL;
        $allPageActive = wallos_get_subscription_page_filter_value($currentSubscriptionPageFilter) === $allFilterValue;
        ?>
        <button type="button" class="subscription-page-tab<?= $allPageActive ? ' is-active' : '' ?>"
          data-page-filter="<?= $allFilterValue ?>" aria-pressed="<?= $allPageActive ? 'true' : 'false' ?>"
          onClick="selectSubscriptionPageFilter('<?= $allFilterValue ?>', this)">
          <span><?= wallos_translate_with_fallback('subscription_page_all', 'All', $i18n) ?></span>
          <span class="section-count-badge"><?= (int) ($subscriptionPageCounts['all'] ?? count($subscriptions)) ?></span>
        </button>
        <?php
        $unassignedFilterValue = WALLOS_SUBSCRIPTION_PAGE_FILTER_UNASSIGNED;
        $unassignedPageActive = wallos_get_subscription_page_filter_value($currentSubscriptionPageFilter) === $unassignedFilterValue;
        ?>
        <button type="button" class="subscription-page-tab<?= $unassignedPageActive ? ' is-active' : '' ?>"
          data-page-filter="<?= $unassignedFilterValue ?>" aria-pressed="<?= $unassignedPageActive ? 'true' : 'false' ?>"
          onClick="selectSubscriptionPageFilter('<?= $unassignedFilterValue ?>', this)">
          <span><?= wallos_translate_with_fallback('subscription_page_unassigned', 'Unassigned', $i18n) ?></span>
          <span class="section-count-badge"><?= (int) ($subscriptionPageCounts['unassigned'] ?? 0) ?></span>
        </button>
        <?php foreach ($subscriptionPages as $subscriptionPage): ?>
          <?php
          $pageFilterValue = (string) (int) $subscriptionPage['id'];
          $pageActive = wallos_get_subscription_page_filter_value($currentSubscriptionPageFilter) === $pageFilterValue;
          ?>
          <button type="button" class="subscription-page-tab<?= $pageActive ? ' is-active' : '' ?>"
            data-page-filter="<?= htmlspecialchars($pageFilterValue, ENT_QUOTES, 'UTF-8') ?>"
            aria-pressed="<?= $pageActive ? 'true' : 'false' ?>"
            onClick="selectSubscriptionPageFilter('<?= htmlspecialchars($pageFilterValue, ENT_QUOTES, 'UTF-8') ?>', this)">
            <span><?= htmlspecialchars($subscriptionPage['name'], ENT_QUOTES, 'UTF-8') ?></span>
            <span class="section-count-badge"><?= (int) ($subscriptionPage['subscription_count'] ?? 0) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
      <button type="button" class="button secondary-button tiny subscription-page-manager-trigger"
        onClick="openSubscriptionPagesManager(event)">
        <i class="fa-solid fa-table-list"></i>
        <span><?= wallos_translate_with_fallback('subscription_pages_manage', 'Manage Pages', $i18n) ?></span>
      </button>
    </div>
    <div class="top-actions">
      <div class="search">
        <input type="text" autocomplete="off" name="search" id="search" placeholder="<?= translate('search', $i18n) ?>"
          onkeyup="searchSubscriptions()" />
        <span class="fa-solid fa-magnifying-glass search-icon"></span>
        <span class="fa-solid fa-xmark clear-search" onClick="clearSearch()"></span>
      </div>
    </div>
  </header>
  <div class="subscriptions subscription-columns subscription-columns-<?= $subscriptionDisplayColumns ?><?= $subscriptionDisplayColumns > 1 ? ' subscription-columns-multi' : '' ?><?= empty($subscriptionValueVisibility['metrics']) ? ' hide-cost-value-metrics' : '' ?><?= empty($subscriptionValueVisibility['payment_records']) ? ' hide-payment-records' : '' ?>" id="subscriptions">
    <?php
    $formatter = new IntlDateFormatter(
      'en', // Force English locale
      IntlDateFormatter::SHORT,
      IntlDateFormatter::NONE,
      null,
      null,
      'MMM d, yyyy'
    );

    foreach ($subscriptions as $subscription) {
      if ($subscription['inactive'] == 1 && isset($settings['hideDisabledSubscriptions']) && $settings['hideDisabledSubscriptions'] === 'true') {
        continue;
      }
      $id = $subscription['id'];
      $print[$id]['id'] = $id;
      $print[$id]['logo'] = $subscription['logo'] != "" ? "images/uploads/logos/" . $subscription['logo'] : "";
      $print[$id]['name'] = $subscription['name'];
      $cycle = $subscription['cycle'];
      $frequency = $subscription['frequency'];
      $print[$id]['billing_cycle'] = getBillingCycle($cycle, $frequency, $i18n);
      $paymentMethodId = $subscription['payment_method_id'];
      $print[$id]['currency_code'] = $currencies[$subscription['currency_id']]['code'];
      $currencyId = $subscription['currency_id'];
      $print[$id]['auto_renew'] = $subscription['auto_renew'];
      $next_payment_timestamp = strtotime($subscription['next_payment']);
      $formatted_date = $formatter->format($next_payment_timestamp);
      $print[$id]['next_payment'] = $formatted_date;
      $paymentIconFolder = (strpos($payment_methods[$paymentMethodId]['icon'], 'images/uploads/icons/') !== false) ? "" : "images/uploads/logos/";
      $print[$id]['payment_method_icon'] = $paymentIconFolder . $payment_methods[$paymentMethodId]['icon'];
      $print[$id]['payment_method_name'] = $payment_methods[$paymentMethodId]['name'];
      $print[$id]['payment_method_id'] = $paymentMethodId;
      $print[$id]['category_id'] = $subscription['category_id'];
      $print[$id]['payer_user_id'] = $subscription['payer_user_id'];
      $print[$id]['price'] = floatval($subscription['price']);
      $print[$id]['progress'] = getSubscriptionProgress($cycle, $frequency, $subscription['next_payment']);
      $print[$id]['inactive'] = $subscription['inactive'];
      $print[$id]['exclude_from_stats'] = (int) ($subscription['exclude_from_stats'] ?? 0);
      $print[$id]['url'] = $subscription['url'];
      $print[$id]['notes'] = $subscription['notes'];
      $print[$id]['replacement_subscription_id'] = $subscription['replacement_subscription_id'];
      $print[$id]['detail_image_urls'] = $subscription['detail_image_urls'] ?? '[]';
      $print[$id]['uploaded_images'] = $uploadedImagesMap[$id] ?? [];
      $print[$id]['payment_records'] = $paymentRecordsMap[$id] ?? [];
      $print[$id]['payment_record_count'] = (int) ($paymentRecordCountMap[$id] ?? 0);
      $print[$id]['payment_total_main'] = (float) ($paymentTotalMap[$id] ?? 0);
      $print[$id]['payment_total_currency_code'] = $currencies[$mainCurrencyId]['code'] ?? $print[$id]['currency_code'];
      $print[$id]['price_rules'] = $priceRulesMap[$id] ?? [];
      $print[$id]['remaining_value'] = wallos_build_subscription_remaining_value_snapshot(
        $db,
        $subscription,
        $userId,
        $print[$id]['price_rules'],
        $print[$id]['payment_records'],
        $currencies,
        $i18n
      );
      $print[$id]['detail_image'] = !empty($print[$id]['uploaded_images'][0]['access_url'])
        ? $print[$id]['uploaded_images'][0]['access_url']
        : ($subscription['detail_image'] ?? '');

      if (isset($settings['convertCurrency']) && $settings['convertCurrency'] === 'true' && $currencyId != $mainCurrencyId) {
        $print[$id]['price'] = getPriceConverted($print[$id]['price'], $currencyId, $db);
        $print[$id]['currency_code'] = $currencies[$mainCurrencyId]['code'];
      }
      if (isset($settings['showMonthlyPrice']) && $settings['showMonthlyPrice'] === 'true') {
        $print[$id]['price'] = getPricePerMonth($cycle, $frequency, $print[$id]['price']);
      }
      if (isset($settings['showOriginalPrice']) && $settings['showOriginalPrice'] === 'true') {
        $print[$id]['original_price'] = floatval($subscription['price']);
        $print[$id]['original_currency_code'] = $currencies[$subscription['currency_id']]['code'];
      }
    }

    if ($sortOrder == "alphanumeric") {
      usort($print, function ($a, $b) {
        return strnatcmp(strtolower($a['name']), strtolower($b['name']));
      });
      if ($settings['disabledToBottom'] === 'true') {
        usort($print, function ($a, $b) {
          return $a['inactive'] - $b['inactive'];
        });
      }
    }

    if ($sortOrder == "payment_total_main") {
      usort($print, function ($a, $b) {
        $totalA = (float) ($a['payment_total_main'] ?? 0);
        $totalB = (float) ($b['payment_total_main'] ?? 0);
        if ($totalA === $totalB) {
          return strnatcmp(strtolower((string) ($a['name'] ?? '')), strtolower((string) ($b['name'] ?? '')));
        }

        return $totalA < $totalB ? 1 : -1;
      });
    }

    if ($sortOrder == "remaining_value") {
      usort($print, function ($a, $b) {
        $remainingA = (float) (($a['remaining_value']['remaining_value_main'] ?? 0));
        $remainingB = (float) (($b['remaining_value']['remaining_value_main'] ?? 0));
        if ($remainingA === $remainingB) {
          return strnatcmp(strtolower((string) ($a['name'] ?? '')), strtolower((string) ($b['name'] ?? '')));
        }

        return $remainingA < $remainingB ? 1 : -1;
      });
    }

    $visibleSubscriptionCount = count($print ?? []);
    if ($visibleSubscriptionCount > 0) {
      printSubscriptions($print, $sort, $categories, $members, $i18n, $colorTheme, "", $settings['disabledToBottom'], $settings['mobileNavigation'], $settings['showSubscriptionProgress'], $currencies, $lang);
    }
    $db->close();

    if ($visibleSubscriptionCount === 0) {
      ?>
      <div class="empty-page">
        <img src="images/siteimages/empty.png" alt="<?= translate('empty_page', $i18n) ?>" />
        <p>
          <?= wallos_get_subscription_page_filter_value($currentSubscriptionPageFilter) === WALLOS_SUBSCRIPTION_PAGE_FILTER_ALL
            ? translate('no_subscriptions_yet', $i18n)
            : translate('no_matching_subscriptions', $i18n) ?>
        </p>
        <?php if (wallos_get_subscription_page_filter_value($currentSubscriptionPageFilter) !== WALLOS_SUBSCRIPTION_PAGE_FILTER_ALL): ?>
          <button class="button" onClick="selectSubscriptionPageFilter('all')">
            <i class="fa-solid fa-table-list"></i>
            <?= wallos_translate_with_fallback('subscription_page_all', 'All', $i18n) ?>
          </button>
        <?php else: ?>
          <button class="button" onClick="addSubscription()">
            <i class="fa-solid fa-circle-plus"></i>
            <?= translate('add_first_subscription', $i18n) ?>
          </button>
        <?php endif; ?>
      </div>
      <?php
    }
    ?>
  </div>
</section>
<section class="subscription-modal subscription-recycle-bin-modal" id="subscription-recycle-bin-modal" data-page-ui-hide-target>
  <header>
    <h3>
      <?= translate('subscription_recycle_bin', $i18n) ?>
      <span class="section-count-badge"><?= count($trashedSubscriptions) ?></span>
    </h3>
    <span class="fa-solid fa-xmark close-form" onClick="closeSubscriptionRecycleBinModal()"></span>
  </header>
  <div class="subscription-recycle-bin-modal-body">
    <?php if (!empty($trashedSubscriptions)): ?>
      <div class="subscription-recycle-bin-list">
        <?php foreach ($trashedSubscriptions as $trashedSubscription): ?>
          <article class="subscription-trash-card">
            <div class="subscription-trash-card-header">
              <div>
                <h3><?= htmlspecialchars($trashedSubscription['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                <p><?= translate('deleted_at', $i18n) ?>: <?= htmlspecialchars($trashedSubscription['trashed_at'] ?? '-', ENT_QUOTES, 'UTF-8') ?></p>
                <p><?= translate('subscription_recycle_bin_scheduled_delete_at', $i18n) ?>: <?= htmlspecialchars($trashedSubscription['scheduled_delete_at'] ?? '-', ENT_QUOTES, 'UTF-8') ?></p>
              </div>
              <?php if (!empty($trashedSubscription['logo'])): ?>
                <img src="images/uploads/logos/<?= htmlspecialchars($trashedSubscription['logo'], ENT_QUOTES, 'UTF-8') ?>"
                  alt="<?= htmlspecialchars($trashedSubscription['name'], ENT_QUOTES, 'UTF-8') ?>">
              <?php endif; ?>
            </div>
            <div class="subscription-trash-card-meta">
              <p><strong><?= translate('price', $i18n) ?>:</strong>
                <?php
                $trashedCurrencyCode = $currencies[$trashedSubscription['currency_id']]['code'] ?? '';
                echo htmlspecialchars($trashedCurrencyCode !== '' ? CurrencyFormatter::format((float) $trashedSubscription['price'], $trashedCurrencyCode) : (string) $trashedSubscription['price'], ENT_QUOTES, 'UTF-8');
                ?>
              </p>
              <p><strong><?= translate('next_payment', $i18n) ?>:</strong> <?= htmlspecialchars($trashedSubscription['next_payment'] ?? '-', ENT_QUOTES, 'UTF-8') ?></p>
              <p><strong><?= translate('subscription_excluded_from_stats_badge', $i18n) ?>:</strong> <?= !empty($trashedSubscription['exclude_from_stats']) ? translate('enabled', $i18n) : translate('disabled', $i18n) ?></p>
            </div>
            <div class="buttons subscription-trash-card-actions">
              <button type="button" class="secondary-button thin" onClick="restoreSubscriptionFromRecycleBin(<?= (int) $trashedSubscription['id'] ?>)">
                <?= translate('subscription_restore', $i18n) ?>
              </button>
              <button type="button" class="warning-button thin" onClick="permanentlyDeleteSubscription(<?= (int) $trashedSubscription['id'] ?>)">
                <?= translate('subscription_permanently_delete', $i18n) ?>
              </button>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="subscription-recycle-bin-empty">
        <?= translate('subscription_recycle_bin_empty', $i18n) ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<section class="subscription-modal subscription-pages-manager-modal" id="subscription-pages-manager-modal" data-page-ui-hide-target>
  <header>
    <h3><?= wallos_translate_with_fallback('subscription_pages', 'Subscription Pages', $i18n) ?></h3>
    <span class="fa-solid fa-xmark close-form" onClick="closeSubscriptionPagesManager()"></span>
  </header>
  <div class="subscription-pages-manager-toolbar">
    <div class="subscription-pages-manager-create">
      <input type="text" id="subscription-page-create-name"
        placeholder="<?= wallos_translate_with_fallback('subscription_page_name_placeholder', 'New page name', $i18n) ?>"
        maxlength="<?= (int) WALLOS_SUBSCRIPTION_PAGE_NAME_MAX_LENGTH ?>">
      <button type="button" class="button thin" onClick="createSubscriptionPage()">
        <i class="fa-solid fa-plus"></i>
        <span><?= wallos_translate_with_fallback('subscription_page_add', 'Add Page', $i18n) ?></span>
      </button>
    </div>
    <div class="settings-notes compact">
      <p>
        <i class="fa-solid fa-circle-info"></i>
        <?= wallos_translate_with_fallback('subscription_page_create_hint', 'Use tabs to split a large subscription list into manageable pages.', $i18n) ?>
      </p>
    </div>
  </div>
  <div class="subscription-pages-manager-list" id="subscription-pages-manager-list">
    <?php if (empty($subscriptionPages)): ?>
      <div class="subscription-pages-manager-empty">
        <?= wallos_translate_with_fallback('subscription_page_empty', 'No custom pages yet. Create one above.', $i18n) ?>
      </div>
    <?php else: ?>
      <?php foreach ($subscriptionPages as $subscriptionPage): ?>
        <div class="subscription-pages-manager-item" data-page-id="<?= (int) $subscriptionPage['id'] ?>">
          <div class="subscription-pages-manager-item-main">
            <input type="text" class="subscription-page-name-input"
              value="<?= htmlspecialchars($subscriptionPage['name'], ENT_QUOTES, 'UTF-8') ?>"
              maxlength="<?= (int) WALLOS_SUBSCRIPTION_PAGE_NAME_MAX_LENGTH ?>">
            <span class="section-count-badge"><?= (int) ($subscriptionPage['subscription_count'] ?? 0) ?></span>
          </div>
          <div class="subscription-pages-manager-item-actions">
            <button type="button" class="button secondary-button thin"
              onClick="renameSubscriptionPage(<?= (int) $subscriptionPage['id'] ?>, this)">
              <i class="fa-solid fa-floppy-disk"></i>
              <span><?= translate('save', $i18n) ?></span>
            </button>
            <button type="button" class="button secondary-button thin danger"
              onClick="deleteSubscriptionPage(<?= (int) $subscriptionPage['id'] ?>)">
              <i class="fa-solid fa-trash-can"></i>
              <span><?= translate('delete', $i18n) ?></span>
            </button>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <div class="buttons">
    <button type="button" class="button secondary-button thin" onClick="closeSubscriptionPagesManager()">
      <?= translate('close', $i18n) ?>
    </button>
  </div>
</section>
<section class="subscription-form" id="subscription-form" data-page-ui-hide-target>
  <header>
    <h3 id="form-title"><?= translate('add_subscription', $i18n) ?></h3>
    <span class="fa-solid fa-xmark close-form" onClick="closeAddSubscription()"></span>
  </header>
  <form action="endpoints/subscription/add.php" method="post" id="subs-form" enctype="multipart/form-data"
    data-effective-user-group="<?= $effectiveUserGroup ?>"
    data-can-upload-detail-image="<?= $canUploadSubscriptionImages ? '1' : '0' ?>"
    data-compression-mode="<?= $canUploadSubscriptionImages ? 'optional' : 'disabled' ?>"
    data-detail-image-max-bytes="<?= (int) $subscriptionImagePolicy['max_size_bytes'] ?>"
    data-detail-image-max-mb="<?= (int) $subscriptionImagePolicy['max_size_mb'] ?>"
    data-external-url-limit="<?= (int) $subscriptionImagePolicy['external_url_limit'] ?>"
    data-upload-limit="<?= $isAdmin ? '' : ($canUploadSubscriptionImages ? (int) $subscriptionImagePolicy['trusted_upload_limit'] : 0) ?>"
    data-allowed-extensions="<?= htmlspecialchars($subscriptionImagePolicy['allowed_extensions_label'], ENT_QUOTES, 'UTF-8') ?>"
    data-detail-image-too-large="<?= htmlspecialchars(sprintf(translate('subscription_image_too_large_dynamic', $i18n), (int) $subscriptionImagePolicy['max_size_mb']), ENT_QUOTES, 'UTF-8') ?>"
    data-detail-image-invalid-type="<?= htmlspecialchars(translate('subscription_image_invalid_type', $i18n), ENT_QUOTES, 'UTF-8') ?>"
    data-detail-image-upload-blocked="<?= htmlspecialchars(translate('subscription_image_no_upload_permission', $i18n), ENT_QUOTES, 'UTF-8') ?>"
    data-detail-image-upload-limit-message="<?= htmlspecialchars(sprintf(translate('subscription_image_upload_limit_dynamic', $i18n), (int) $subscriptionImagePolicy['trusted_upload_limit']), ENT_QUOTES, 'UTF-8') ?>">

    <div class="form-group-inline">
      <input type="text" id="name" name="name" autocomplete="off"
        placeholder="<?= translate('subscription_name', $i18n) ?>"
        onchange="setSearchButtonStatus()" onkeypress="this.onchange();" onpaste="this.onchange();"
        oninput="this.onchange();" required>
      <label for="logo" class="logo-preview">
        <img src="" alt="<?= translate('logo_preview', $i18n) ?>" id="form-logo">
      </label>
      <input type="file" id="logo" name="logo" accept="image/jpeg, image/png, image/gif, image/webp, image/svg+xml"
        onchange="handleFileSelect(event)" class="hidden-input">
      <input type="hidden" id="logo-url" name="logo-url">
      <div id="logo-search-button" class="image-button medium disabled" title="<?= translate('search_logo', $i18n) ?>"
        onClick="searchLogo()">
        <?php include "images/siteicons/svg/websearch.php"; ?>
      </div>
      <input type="hidden" id="id" name="id">
      <div id="logo-search-results" class="logo-search">
        <header>
          <?= translate('web_search', $i18n) ?>
          <span class="fa-solid fa-xmark close-logo-search" onClick="closeLogoSearch()"></span>
        </header>
        <div id="logo-search-images"></div>
      </div>
    </div>

    <div class="form-group-inline">
      <input type="number" step="0.01" id="price" name="price" autocomplete="off"
        placeholder="<?= translate('price', $i18n) ?>" required>
      <select id="currency" name="currency_id" placeholder="<?= translate('add_subscription', $i18n) ?>">
        <?php
        foreach ($currencies as $currency) {
          $selected = ($currency['id'] == $main_currency) ? 'selected' : '';
          ?>
          <option value="<?= $currency['id'] ?>" <?= $selected ?>><?= $currency['name'] ?></option>
          <?php
        }
        ?>
      </select>
    </div>

    <div class="form-group">
      <div class="inline">
        <div class="split66">
          <label for="cycle"><?= translate('payment_every', $i18n) ?></label>
          <div class="inline">
            <select id="frequency" name="frequency" placeholder="<?= translate('frequency', $i18n) ?>">
              <?php
              for ($i = 1; $i <= 366; $i++) {
                ?>
                <option value="<?= $i ?>"><?= $i ?></option>
                <?php
              }
              ?>
            </select>
            <select id="cycle" name="cycle" placeholder="<?= translate('cycle', $i18n) ?>">
              <?php
              foreach ($cycles as $cycle) {
                ?>
                <option value="<?= $cycle['id'] ?>" <?= $cycle['id'] == 3 ? "selected" : "" ?>>
                  <?= translate(strtolower($cycle['name']), $i18n) ?>
                </option>
                <?php
              }
              ?>
            </select>
          </div>
        </div>
        <div class="split33">
          <label><?= translate('auto_renewal', $i18n) ?></label>
          <div class="inline height50">
            <input type="checkbox" id="auto_renew" name="auto_renew" checked>
            <label for="auto_renew"><?= translate('automatically_renews', $i18n) ?></label>
          </div>
        </div>
      </div>
    </div>

    <div class="form-group">
      <div class="inline">
        <div class="split50">
          <label for="start_date"><?= translate('start_date', $i18n) ?></label>
          <div class="date-wrapper">
            <input type="date" id="start_date" name="start_date" autocomplete="off">
          </div>
        </div>
        <button type="button" id="autofill-next-payment-button"
          class="button secondary-button autofill-next-payment hideOnMobile"
          title="<?= translate('calculate_next_payment_date', $i18n) ?>" onClick="autoFillNextPaymentDate(event)">
          <i class="fa-solid fa-wand-magic-sparkles"></i>
        </button>
        <div class="split50">
          <label for="next_payment" class="split-label">
            <?= translate('next_payment', $i18n) ?>
            <div id="autofill-next-payment-button" class="autofill-next-payment hideOnDesktop"
              title="<?= translate('calculate_next_payment_date', $i18n) ?>" onClick="autoFillNextPaymentDate(event)">
              <i class="fa-solid fa-wand-magic-sparkles"></i>
            </div>
          </label>
          <div class="date-wrapper">
            <input type="date" id="next_payment" name="next_payment" autocomplete="off" required>
          </div>
        </div>
      </div>
    </div>

    <div class="form-group">
      <div class="inline">
        <div class="split50">
          <label for="payment_method"><?= translate('payment_method', $i18n) ?></label>
          <select id="payment_method" name="payment_method_id">
            <?php
            foreach ($payment_methods as $payment) {
              ?>
              <option value="<?= $payment['id'] ?>">
                <?= $payment['name'] ?>
              </option>
              <?php
            }
            ?>
          </select>
        </div>
        <div class="split50">
          <label for="payer_user"><?= translate('paid_by', $i18n) ?></label>
          <select id="payer_user" name="payer_user_id">
            <?php
            foreach ($members as $member) {
              ?>
              <option value="<?= $member['id'] ?>"><?= $member['name'] ?></option>
              <?php
            }
            ?>
          </select>
        </div>
      </div>
    </div>

    <div class="form-group">
      <label for="category"><?= translate('category', $i18n) ?></label>
      <select id="category" name="category_id">
        <?php
        foreach ($categories as $category) {
          ?>
          <option value="<?= $category['id'] ?>">
            <?= $category['name'] ?>
          </option>
          <?php
        }
        ?>
      </select>
    </div>

    <div class="form-group">
      <label for="subscription_page_id"><?= wallos_translate_with_fallback('subscription_page_field_label', 'Subscription Page', $i18n) ?></label>
      <select id="subscription_page_id" name="subscription_page_id">
        <option value=""><?= wallos_translate_with_fallback('subscription_page_unassigned', 'Unassigned', $i18n) ?></option>
        <?php foreach ($subscriptionPages as $subscriptionPage): ?>
          <option value="<?= (int) $subscriptionPage['id'] ?>"><?= htmlspecialchars($subscriptionPage['name'], ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group-inline grow">
      <input type="checkbox" id="notifications" name="notifications" onchange="toggleNotificationDays()">
      <label for="notifications" class="grow"><?= translate('enable_notifications', $i18n) ?></label>
    </div>

    <div class="form-group-inline grow">
      <input type="checkbox" id="exclude_from_stats" name="exclude_from_stats">
      <label for="exclude_from_stats" class="grow"><?= translate('subscription_exclude_from_stats', $i18n) ?></label>
    </div>
    <div class="settings-notes subscription-stats-exclusion-note">
      <p>
        <i class="fa-solid fa-circle-info"></i>
        <?= translate('subscription_exclude_from_stats_help', $i18n) ?>
      </p>
    </div>

    <div class="form-group">
      <label for="manual_cycle_used_value_main"><?= translate('subscription_manual_used_value', $i18n) ?></label>
      <div class="form-group-inline">
        <label for="manual_cycle_used_value_main"><?= htmlspecialchars($currencies[$mainCurrencyId]['symbol'] ?: $currencies[$mainCurrencyId]['code'], ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" step="0.01" min="0" id="manual_cycle_used_value_main" name="manual_cycle_used_value_main"
          autocomplete="off" placeholder="<?= translate('subscription_manual_used_value', $i18n) ?>">
      </div>
      <div class="settings-notes compact">
        <p>
          <i class="fa-solid fa-circle-info"></i>
          <?= translate('subscription_manual_used_value_help', $i18n) ?>
        </p>
      </div>
    </div>

    <div class="form-group">
      <div class="inline">
        <div class="split66 mobile-split-50">
          <label for="notify_days_before"><?= translate('notify_me', $i18n) ?></label>
          <select id="notify_days_before" name="notify_days_before" disabled>
            <option value="-1"><?= translate('default_value_from_settings', $i18n) ?></option>
            <option value="0"><?= translate('on_due_date', $i18n) ?></option>
            <option value="1">1 <?= translate('day_before', $i18n) ?></option>
            <?php
            for ($i = 2; $i <= 180; $i++) {
              ?>
              <option value="<?= $i ?>"><?= $i ?>   <?= translate('days_before', $i18n) ?></option>
              <?php
            }
            ?>
          </select>
        </div>
        <div class="split33 mobile-split-50">
          <label for="cancellation_date"><?= translate('cancellation_notification', $i18n) ?></label>
          <div class="date-wrapper">
            <input type="date" id="cancellation_date" name="cancellation_date" autocomplete="off">
          </div>
        </div>
      </div>
    </div>

    <div class="form-group">
      <input type="text" id="url" name="url" autocomplete="off" placeholder="<?= translate('url', $i18n) ?>">
    </div>

    <div class="form-group">
      <label for="notes"><?= translate('notes', $i18n) ?></label>
      <textarea id="notes" name="notes" autocomplete="off" rows="8"
        class="subscription-notes-field"
        placeholder="<?= translate('notes', $i18n) ?>"></textarea>
      <div class="settings-notes subscription-notes-help">
        <p>
          <i class="fa-brands fa-markdown"></i>
          <?= translate('subscription_notes_markdown_hint', $i18n) ?>
        </p>
      </div>
    </div>

    <div class="form-group subscription-price-rules-group">
      <label><?= translate('subscription_price_rules', $i18n) ?></label>
      <div class="subscription-price-rules-panel">
        <div class="subscription-price-rules-toolbar">
          <span class="subscription-price-rules-hint"><?= translate('subscription_price_rules_hint', $i18n) ?></span>
          <button type="button" class="secondary-button thin" onClick="addSubscriptionPriceRule()">
            <i class="fa-solid fa-tags"></i>
            <span><?= translate('subscription_price_rule_add', $i18n) ?></span>
          </button>
        </div>
        <div class="subscription-price-rules-list" id="subscription-price-rules-list"></div>
        <input type="hidden" id="subscription-price-rules-json" name="subscription_price_rules_json" value="[]">
      </div>
    </div>

    <div class="form-group subscription-detail-images-group">
      <label for="detail-image-urls"><?= translate('subscription_images', $i18n) ?></label>
      <div class="subscription-detail-image-panel">
        <div class="subscription-detail-image-gallery-shell">
          <div class="subscription-detail-image-toolbar">
            <span class="subscription-detail-image-toolbar-hint"><?= translate('subscription_image_click_to_enlarge', $i18n) ?></span>
            <div class="media-layout-toggle" data-image-layout-scope="form">
              <button type="button" class="media-layout-button" data-mode="focus"
                title="<?= translate('subscription_image_layout_focus', $i18n) ?>"
                onClick="setSubscriptionImageLayoutMode('form', 'focus', this)">
                <i class="fa-solid fa-images"></i>
                <span><?= translate('subscription_image_layout_focus', $i18n) ?></span>
              </button>
              <button type="button" class="media-layout-button" data-mode="grid"
                title="<?= translate('subscription_image_layout_grid', $i18n) ?>"
                onClick="setSubscriptionImageLayoutMode('form', 'grid', this)">
                <i class="fa-solid fa-table-cells"></i>
                <span><?= translate('subscription_image_layout_grid', $i18n) ?></span>
              </button>
            </div>
          </div>
          <div class="subscription-detail-image-gallery is-empty" id="detail-image-gallery"
            data-empty="<?= htmlspecialchars(translate('subscription_image_no_selection', $i18n), ENT_QUOTES, 'UTF-8') ?>"></div>
        </div>
        <div class="subscription-detail-image-actions">
          <label for="detail-image-upload"
            class="button secondary-button thin upload-detail-image-button<?= $canUploadSubscriptionImages ? '' : ' disabled' ?>">
            <i class="fa-solid fa-arrow-up-from-bracket"></i>
            <?= translate('subscription_image_upload', $i18n) ?>
          </label>
          <input type="file" id="detail-image-upload" name="detail_images[]"
            accept="<?= htmlspecialchars(wallos_get_subscription_media_accept_attribute(), ENT_QUOTES, 'UTF-8') ?>"
            multiple
            onchange="handleDetailImageSelect(event)" class="hidden-input"
            <?= $canUploadSubscriptionImages ? '' : 'disabled' ?>>
          <input type="hidden" id="remove-uploaded-image-ids" name="remove_uploaded_image_ids" value="">
          <input type="hidden" id="detail-image-order" name="detail_image_order" value="">
          <div class="subscription-image-selection-meta" id="detail-image-selection-meta">
            <?= translate('subscription_image_no_selection', $i18n) ?>
          </div>
          <div class="subscription-image-upload-progress is-hidden" id="detail-image-upload-progress">
            <div class="subscription-image-upload-progress-label" id="detail-image-upload-progress-label">
              <?= translate('subscription_image_upload_progress_idle', $i18n) ?>
            </div>
            <div class="subscription-image-upload-progress-bar">
              <span id="detail-image-upload-progress-bar-fill"></span>
            </div>
            <div class="subscription-image-upload-progress-value" id="detail-image-upload-progress-value">0%</div>
          </div>
          <div class="form-group-inline grow subscription-image-compress-inline">
            <input type="checkbox" id="compress_subscription_image" name="compress_subscription_image"
              value="1" <?= $canUploadSubscriptionImages ? 'checked' : '' ?> <?= $canUploadSubscriptionImages ? '' : 'disabled' ?>>
            <label for="compress_subscription_image" class="grow">
              <?= translate('subscription_image_compress_toggle', $i18n) ?>
            </label>
          </div>
        </div>
      </div>
      <textarea id="detail-image-urls" name="detail_image_urls" rows="4"
        placeholder="<?= sprintf(translate('subscription_image_external_urls_placeholder_dynamic', $i18n), (int) $subscriptionImagePolicy['external_url_limit']) ?>"></textarea>
      <div class="settings-notes subscription-image-notes">
        <p>
          <i class="fa-solid fa-circle-info"></i>
          <?= sprintf(
            translate('subscription_image_limits_notice_dynamic', $i18n),
            (int) $subscriptionImagePolicy['max_size_mb'],
            htmlspecialchars($subscriptionImagePolicy['allowed_extensions_label'], ENT_QUOTES, 'UTF-8'),
            (int) $subscriptionImagePolicy['external_url_limit']
          ) ?>
        </p>
        <p>
          <i class="fa-solid fa-circle-info"></i>
          <?php
          if ($isAdmin) {
            echo translate('subscription_image_admin_notice', $i18n);
          } elseif ($canUploadSubscriptionImages) {
            echo sprintf(translate('subscription_image_trusted_notice_dynamic', $i18n), (int) $subscriptionImagePolicy['trusted_upload_limit']);
          } else {
            echo sprintf(translate('subscription_image_free_notice_dynamic', $i18n), (int) $subscriptionImagePolicy['external_url_limit']);
          }
          ?>
        </p>
      </div>
    </div>

    <div class="form-group">
      <div class="inline grow">
        <input type="checkbox" id="inactive" name="inactive" onchange="toggleReplacementSub()">
        <label for="inactive" class="grow"><?= translate('inactive', $i18n) ?></label>
      </div>
    </div>

    <?php
    $orderedSubscriptions = $subscriptions;
    usort($orderedSubscriptions, function ($a, $b) {
      return strnatcmp(strtolower($a['name']), strtolower($b['name']));
    });
    ?>

    <div class="form-group hide" id="replacement_subscritpion">
      <label for="replacement_subscription_id"><?= translate('replaced_with', $i18n) ?>:</label>
      <select id="replacement_subscription_id" name="replacement_subscription_id">
        <option value="0"><?= translate('none', $i18n) ?></option>
        <?php
        foreach ($orderedSubscriptions as $sub) {
          if ($sub['inactive'] == 0) {
            ?>
            <option value="<?= htmlspecialchars($sub['id']) ?>"><?= htmlspecialchars($sub['name']) ?>
            </option>
            <?php
          }
        }
        ?>
      </select>
    </div>

    <div class="buttons">
      <input type="button" value="<?= translate('delete', $i18n) ?>" class="warning-button left thin" id="deletesub"
        style="display: none">
      <input type="button" value="<?= translate('cancel', $i18n) ?>" class="secondary-button thin"
        onClick="closeAddSubscription()">
      <input type="submit" value="<?= translate('save', $i18n) ?>" class="thin" id="save-button">
    </div>
  </form>
</section>
<section class="subscription-modal subscription-payment-modal" id="subscription-payment-modal" data-page-ui-hide-target>
  <header>
    <h3 id="subscription-payment-modal-title"><?= translate('subscription_record_payment', $i18n) ?></h3>
    <span class="fa-solid fa-xmark close-form" onClick="closeSubscriptionPaymentModal()"></span>
  </header>
  <form id="subscription-payment-form">
    <input type="hidden" id="subscription-payment-subscription-id" name="subscription_id" value="">
    <input type="hidden" id="subscription-payment-record-id" name="record_id" value="">

    <div class="form-group">
      <label for="subscription-payment-due-date"><?= translate('subscription_payment_due_date', $i18n) ?></label>
      <div class="date-wrapper">
        <input type="date" id="subscription-payment-due-date" name="due_date" autocomplete="off" required>
      </div>
    </div>

    <div class="form-group">
      <label for="subscription-payment-paid-at"><?= translate('subscription_payment_paid_at', $i18n) ?></label>
      <div class="date-wrapper">
        <input type="date" id="subscription-payment-paid-at" name="paid_at" autocomplete="off" required>
      </div>
    </div>

    <div class="form-group">
      <label for="subscription-payment-amount"><?= translate('subscription_payment_amount', $i18n) ?></label>
      <input type="number" step="0.01" min="0" id="subscription-payment-amount" name="amount_original" autocomplete="off" required>
    </div>

    <div class="form-group">
      <div class="inline">
        <div class="split50">
          <label for="subscription-payment-currency"><?= translate('currency', $i18n) ?></label>
          <select id="subscription-payment-currency" name="currency_id">
            <?php foreach ($currencies as $currency): ?>
              <option value="<?= (int) $currency['id'] ?>"><?= htmlspecialchars($currency['name'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="split50">
          <label for="subscription-payment-method"><?= translate('payment_method', $i18n) ?></label>
          <select id="subscription-payment-method" name="payment_method_id">
            <?php foreach ($payment_methods as $payment): ?>
              <option value="<?= (int) $payment['id'] ?>"><?= htmlspecialchars($payment['name'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="form-group">
      <label for="subscription-payment-note"><?= translate('notes', $i18n) ?></label>
      <textarea id="subscription-payment-note" name="note" rows="5" class="subscription-payment-note-field"
        placeholder="<?= translate('subscription_payment_note_placeholder', $i18n) ?>"></textarea>
    </div>

    <div class="buttons">
      <button type="button" class="button secondary-button thin" onClick="closeSubscriptionPaymentModal()">
        <?= translate('cancel', $i18n) ?>
      </button>
      <button type="submit" class="button thin" id="subscription-payment-save-button">
        <?= translate('save', $i18n) ?>
      </button>
    </div>
  </form>
</section>
<section class="subscription-modal subscription-payment-history-modal" id="subscription-payment-history-modal" data-page-ui-hide-target>
  <header>
    <h3 id="subscription-payment-history-modal-title"><?= translate('subscription_payment_history', $i18n) ?></h3>
    <span class="fa-solid fa-xmark close-form" onClick="closeSubscriptionPaymentHistoryModal()"></span>
  </header>
  <div class="subscription-payment-history-toolbar">
    <div class="subscription-payment-history-controls">
      <div class="subscription-payment-history-control">
        <label for="subscription-payment-history-year"><?= translate('subscription_payment_history_year', $i18n) ?></label>
        <select id="subscription-payment-history-year"></select>
      </div>
      <div class="subscription-payment-history-control">
        <label for="subscription-payment-history-range"><?= translate('subscription_payment_history_range', $i18n) ?></label>
        <select id="subscription-payment-history-range">
          <option value="6"><?= translate('subscription_payment_history_range_6_months', $i18n) ?></option>
          <option value="12"><?= translate('subscription_payment_history_range_12_months', $i18n) ?></option>
          <option value="24"><?= translate('subscription_payment_history_range_24_months', $i18n) ?></option>
          <option value="36"><?= translate('subscription_payment_history_range_36_months', $i18n) ?></option>
        </select>
      </div>
    </div>
    <div class="subscription-payment-history-export-actions">
      <button type="button" class="button secondary-button thin subscription-payment-history-toolbar-button" onClick="exportSubscriptionPaymentHistoryCurrentView('csv')">
        <i class="fa-solid fa-file-csv"></i>
        <span><?= translate('export_as_csv', $i18n) ?></span>
      </button>
      <button type="button" class="button secondary-button thin subscription-payment-history-toolbar-button" onClick="exportSubscriptionPaymentHistoryCurrentView('json')">
        <i class="fa-solid fa-file-code"></i>
        <span><?= translate('export_as_json', $i18n) ?></span>
      </button>
    </div>
    <button type="button" class="button secondary-button thin subscription-payment-history-toolbar-button" id="subscription-payment-history-add-button">
      <i class="fa-solid fa-plus"></i>
      <span><?= translate('subscription_record_payment', $i18n) ?></span>
    </button>
  </div>
  <div class="subscription-payment-history-content" id="subscription-payment-history-content"></div>
  <div class="buttons">
    <button type="button" class="button secondary-button thin" onClick="closeSubscriptionPaymentHistoryModal()">
      <?= translate('close', $i18n) ?>
    </button>
  </div>
</section>
<section class="subscription-image-viewer" id="subscription-image-viewer" data-page-ui-hide-target>
  <header>
    <div class="subscription-image-viewer-header">
      <div>
        <h3><?= translate('subscription_image_viewer_title', $i18n) ?></h3>
        <div class="subscription-image-viewer-counter" id="subscription-image-viewer-counter">1 / 1</div>
      </div>
      <div class="subscription-image-viewer-header-actions">
        <button type="button" class="secondary-button thin subscription-image-action-button" id="subscription-image-viewer-prev"
          onClick="showPreviousSubscriptionImage()">
          <i class="fa-solid fa-chevron-left"></i>
          <span><?= translate('subscription_image_previous', $i18n) ?></span>
        </button>
        <button type="button" class="secondary-button thin subscription-image-action-button" id="subscription-image-viewer-next"
          onClick="showNextSubscriptionImage()">
          <span><?= translate('subscription_image_next', $i18n) ?></span>
          <i class="fa-solid fa-chevron-right"></i>
        </button>
      </div>
    </div>
    <span class="fa-solid fa-xmark close-form" onClick="closeSubscriptionImageViewer()"></span>
  </header>
  <div class="subscription-image-viewer-content">
    <img src="" alt="<?= translate('subscription_image_viewer_title', $i18n) ?>" id="subscription-image-viewer-preview">
    <div class="subscription-image-original-progress is-hidden" id="subscription-image-original-progress">
      <div class="subscription-image-original-progress-label" id="subscription-image-original-progress-label">
        <?= translate('subscription_image_original_loading', $i18n) ?>
      </div>
      <div class="subscription-image-upload-progress-bar">
        <span id="subscription-image-original-progress-fill"></span>
      </div>
      <div class="subscription-image-upload-progress-value" id="subscription-image-original-progress-value">0%</div>
    </div>
  </div>
  <div class="buttons">
    <button type="button" class="secondary-button thin subscription-image-action-button"
      onClick="closeSubscriptionImageViewer()">
      <i class="fa-solid fa-xmark"></i>
      <span><?= translate('cancel', $i18n) ?></span>
    </button>
    <button type="button" class="secondary-button thin subscription-image-action-button"
      id="subscription-image-viewer-open" onClick="openSubscriptionImageOriginal()">
      <i class="fa-solid fa-up-right-from-square"></i>
      <span><?= translate('subscription_image_open_original', $i18n) ?></span>
    </button>
    <button type="button" class="thin subscription-image-action-button"
      id="subscription-image-viewer-download" onClick="downloadSubscriptionImage()">
      <i class="fa-solid fa-download"></i>
      <span><?= translate('subscription_image_download', $i18n) ?></span>
    </button>
  </div>
</section>
<?php wallos_render_page_immersive_toggle($lang); ?>
<script>
  window.subscriptionPagePreferences = <?= json_encode($subscriptionPagePreferences, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.subscriptionPageState = <?= json_encode([
    'currentFilter' => wallos_get_subscription_page_filter_value($currentSubscriptionPageFilter),
    'pages' => $subscriptionPages,
    'counts' => $subscriptionPageCounts,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.subscriptionPageStrings = <?= json_encode([
    'pagesTitle' => wallos_translate_with_fallback('subscription_pages', 'Subscription Pages', $i18n),
    'manage' => wallos_translate_with_fallback('subscription_pages_manage', 'Manage Pages', $i18n),
    'all' => wallos_translate_with_fallback('subscription_page_all', 'All', $i18n),
    'unassigned' => wallos_translate_with_fallback('subscription_page_unassigned', 'Unassigned', $i18n),
    'fieldLabel' => wallos_translate_with_fallback('subscription_page_field_label', 'Subscription Page', $i18n),
    'add' => wallos_translate_with_fallback('subscription_page_add', 'Add Page', $i18n),
    'empty' => wallos_translate_with_fallback('subscription_page_empty', 'No custom pages yet. Create one above.', $i18n),
    'namePlaceholder' => wallos_translate_with_fallback('subscription_page_name_placeholder', 'New page name', $i18n),
    'deleteConfirm' => wallos_translate_with_fallback('subscription_page_delete_confirm', 'Delete this page now? Subscriptions inside it will move to Unassigned.', $i18n),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="scripts/libs/sortable.min.js"></script>
<script src="scripts/subscriptions.js?<?= $subscriptionsJsVersion ?>"></script>
<?php
if (isset($_GET['add'])) {
  ?>
  <script>
    addSubscription();
  </script>
  <?php
}

require_once 'includes/footer.php';
?>
