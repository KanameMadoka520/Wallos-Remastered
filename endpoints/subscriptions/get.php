<?php
require_once '../../includes/connect_endpoint.php';

require_once '../../includes/currency_formatter.php';
require_once '../../includes/getdbkeys.php';
require_once '../../includes/subscription_media.php';
require_once '../../includes/subscription_trash.php';
require_once '../../includes/subscription_payment_records.php';
require_once '../../includes/subscription_payment_history.php';
require_once '../../includes/subscription_price_rules.php';

include_once '../../includes/list_subscriptions.php';

require_once '../../includes/getsettings.php';

$theme = "light";
if (isset($settings['theme'])) {
  $theme = $settings['theme'];
}

$colorTheme = "blue";
if (isset($settings['color_theme'])) {
  $colorTheme = $settings['color_theme'];
}

$formatter = new IntlDateFormatter(
  'en', // Force English locale
  IntlDateFormatter::SHORT,
  IntlDateFormatter::NONE,
  null,
  null,
  'MMM d, yyyy'
);

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
  $mainCurrencyId = 0;
  $mainCurrencyStmt = $db->prepare('SELECT main_currency FROM user WHERE id = :userId');
  $mainCurrencyStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
  $mainCurrencyResult = $mainCurrencyStmt->execute();
  $mainCurrencyRow = $mainCurrencyResult ? $mainCurrencyResult->fetchArray(SQLITE3_ASSOC) : false;
  if ($mainCurrencyRow !== false) {
    $mainCurrencyId = (int) ($mainCurrencyRow['main_currency'] ?? 0);
  }

  $uploadedImagesMap = wallos_get_subscription_uploaded_images_map($db, $userId);
  $paymentRecordsMap = wallos_get_subscription_payment_records_map($db, $userId, 6);
  $paymentRecordCountMap = wallos_get_subscription_payment_record_count_map($db, $userId);
  $paymentTotalMap = wallos_get_subscription_payment_total_map($db, $userId);
  $priceRulesMap = wallos_get_subscription_price_rules_map($db, $userId, true);


  $sort = "manual_order";
  $sortOrder = $sort;
  $order = "ASC";

  $params = array();
  $sql = "SELECT * FROM subscriptions WHERE user_id = :userId AND lifecycle_status = :lifecycle_status";

  if (isset($_GET['categories']) && $_GET['categories'] != "") {
    $allCategories = explode(',', $_GET['categories']);
    $placeholders = array_map(function ($idx) {
      return ":categories{$idx}";
    }, array_keys($allCategories));

    $sql .= " AND (" . implode(' OR ', array_map(function ($placeholder) {
      return "category_id = {$placeholder}";
    }, $placeholders)) . ")";

    foreach ($allCategories as $idx => $category) {
      $params[":categories{$idx}"] = $category;
    }
  }

  if (isset($_GET['payments']) && $_GET['payments'] !== "") {
    $allPayments = explode(',', $_GET['payments']);
    $placeholders = array_map(function ($idx) {
      return ":payments{$idx}";
    }, array_keys($allPayments));

    $sql .= " AND (" . implode(' OR ', array_map(function ($placeholder) {
      return "payment_method_id = {$placeholder}";
    }, $placeholders)) . ")";

    foreach ($allPayments as $idx => $payment) {
      $params[":payments{$idx}"] = $payment;
    }
  }

  if (isset($_GET['members']) && $_GET['members'] != "") {
    $allMembers = explode(',', $_GET['members']);
    $placeholders = array_map(function ($idx) {
      return ":members{$idx}";
    }, array_keys($allMembers));

    $sql .= " AND (" . implode(' OR ', array_map(function ($placeholder) {
      return "payer_user_id = {$placeholder}";
    }, $placeholders)) . ")";

    foreach ($allMembers as $idx => $member) {
      $params[":members{$idx}"] = $member;
    }
  }

  if (isset($_GET['state']) && $_GET['state'] != "") {
    $sql .= " AND inactive = :inactive";
    $params[':inactive'] = $_GET['state'];
  }

  if (isset($_GET['renewalType']) && $_GET['renewalType'] != "") {
    $sql .= " AND auto_renew = :auto_renew";
    $params[':auto_renew'] = $_GET['renewalType'];
  }

  if (isset($_COOKIE['sortOrder']) && $_COOKIE['sortOrder'] != "") {
    $sort = $_COOKIE['sortOrder'];
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

  foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
  }

  $result = $stmt->execute();
  if ($result) {
    $subscriptions = array();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
      $subscriptions[] = $row;
    }
  }

  foreach ($subscriptions as $subscription) {
    if ($subscription['inactive'] == 1 && isset($settings['hideDisabledSubscriptions']) && $settings['hideDisabledSubscriptions'] === 'true') {
      continue;
    }
    $id = $subscription['id'];
    $print[$id]['id'] = $id;
    $print[$id]['logo'] = $subscription['logo'] != "" ? "images/uploads/logos/" . $subscription['logo'] : "";
    $print[$id]['name'] = $subscription['name'] ?? "";
    $cycle = $subscription['cycle'];
    $frequency = $subscription['frequency'];
    $print[$id]['billing_cycle'] = getBillingCycle($cycle, $frequency, $i18n);
    $paymentMethodId = $subscription['payment_method_id'];
    $print[$id]['currency_code'] = $currencies[$subscription['currency_id']]['code'];
    $currencyId = $subscription['currency_id'];
    $next_payment_timestamp = strtotime($subscription['next_payment']);
    $formatted_date = $formatter->format($next_payment_timestamp);
    $print[$id]['next_payment'] = $formatted_date;
    $print[$id]['auto_renew'] = $subscription['auto_renew'];
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
    $print[$id]['url'] = $subscription['url'] ?? "";
    $print[$id]['notes'] = $subscription['notes'] ?? "";
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

  if ($sortOrder == "category_id") {
    usort($print, function ($a, $b) use ($categories) {
      return $categories[$a['category_id']]['order'] - $categories[$b['category_id']]['order'];
    });
  }
  
  if ($sortOrder == "payment_method_id") {
    usort($print, function ($a, $b) use ($payment_methods) {
      return $payment_methods[$a['payment_method_id']]['order'] - $payment_methods[$b['payment_method_id']]['order'];
    });
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

  if (isset($print)) {
    printSubscriptions($print, $sort, $categories, $members, $i18n, $colorTheme, "../../", $settings['disabledToBottom'], $settings['mobileNavigation'], $settings['showSubscriptionProgress'], $currencies, $lang);
  }

  if (count($subscriptions) == 0) {
    ?>
    <div class="no-matching-subscriptions">
      <p>
        <?= translate('no_matching_subscriptions', $i18n) ?>
      </p>
      <button class="button" onClick="clearFilters()">
        <span clasS="fa-solid fa-minus-circle"></span>
        <?= translate('clear_filters', $i18n) ?>
      </button>
      <img src="images/siteimages/empty.png" alt="<?= translate('empty_page', $i18n) ?>" />
    </div>
    <?php
  }
}

$db->close();
?>
