<?php

require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/user_groups.php';
require_once 'includes/subscription_media.php';

include_once 'includes/list_subscriptions.php';

$sort = "next_payment";
$sortOrder = $sort;

if ($settings['disabledToBottom'] === 'true') {
  $sql = "SELECT * FROM subscriptions WHERE user_id = :userId ORDER BY inactive ASC, next_payment ASC";
} else {
  $sql = "SELECT * FROM subscriptions WHERE user_id = :userId ORDER BY next_payment ASC, inactive ASC";
}

$params = array();

if (isset($_COOKIE['sortOrder']) && $_COOKIE['sortOrder'] != "") {
  $sort = $_COOKIE['sortOrder'] ?? 'next_payment';
}

$sortOrder = $sort;
$allowedSortCriteria = ['name', 'id', 'next_payment', 'price', 'payer_user_id', 'category_id', 'payment_method_id', 'inactive', 'alphanumeric', 'renewal_type'];
$order = ($sort == "price" || $sort == "id") ? "DESC" : "ASC";

if ($sort == "alphanumeric") {
  $sort = "name";
}

if (!in_array($sort, $allowedSortCriteria)) {
  $sort = "next_payment";
}

if ($sort == "renewal_type") {
  $sort = "auto_renew";
}

$sql = "SELECT * FROM subscriptions WHERE user_id = :userId";

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

if ($sort != "next_payment") {
  $orderByClauses[] = "next_payment ASC";
}

$sql .= " ORDER BY " . implode(", ", $orderByClauses);

$stmt = $db->prepare($sql);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

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

$headerClass = count($subscriptions) > 0 ? "main-actions" : "main-actions hidden";
$effectiveUserGroup = wallos_get_effective_user_group($userData['user_group'] ?? WALLOS_USER_GROUP_FREE, $isAdmin);
$canUploadSubscriptionImages = wallos_can_upload_subscription_images($isAdmin, $userData['user_group'] ?? WALLOS_USER_GROUP_FREE);
$subscriptionImagePolicy = wallos_get_subscription_media_policy($db);
$uploadedImagesMap = wallos_get_subscription_uploaded_images_map($db, $userId);
?>
<style>
  .logo-preview:after {
    content: '<?= translate('upload_logo', $i18n) ?>';
  }
</style>

<section class="contain">
  <header class="<?= $headerClass ?>" id="main-actions">
    <button class="button" onClick="addSubscription()">
      <i class="fa-solid fa-circle-plus"></i>
      <?= translate('new_subscription', $i18n) ?>
    </button>
    <div class="top-actions">
      <div class="search">
        <input type="text" autocomplete="off" name="search" id="search" placeholder="<?= translate('search', $i18n) ?>"
          onkeyup="searchSubscriptions()" />
        <span class="fa-solid fa-magnifying-glass search-icon"></span>
        <span class="fa-solid fa-xmark clear-search" onClick="clearSearch()"></span>
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
  </header>
  <div class="subscriptions" id="subscriptions">
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
      $print[$id]['url'] = $subscription['url'];
      $print[$id]['notes'] = $subscription['notes'];
      $print[$id]['replacement_subscription_id'] = $subscription['replacement_subscription_id'];
      $print[$id]['detail_image_urls'] = $subscription['detail_image_urls'] ?? '[]';
      $print[$id]['uploaded_images'] = $uploadedImagesMap[$id] ?? [];
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

    if (isset($print)) {
      printSubscriptions($print, $sort, $categories, $members, $i18n, $colorTheme, "", $settings['disabledToBottom'], $settings['mobileNavigation'], $settings['showSubscriptionProgress'], $currencies, $lang);
    }
    $db->close();

    if (count($subscriptions) == 0) {
      ?>
      <div class="empty-page">
        <img src="images/siteimages/empty.png" alt="<?= translate('empty_page', $i18n) ?>" />
        <p>
          <?= translate('no_subscriptions_yet', $i18n) ?>
        </p>
        <button class="button" onClick="addSubscription()">
          <i class="fa-solid fa-circle-plus"></i>
          <?= translate('add_first_subscription', $i18n) ?>
        </button>
      </div>
      <?php
    }
    ?>
  </div>
</section>
<section class="subscription-form" id="subscription-form">
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

    <div class="form-group-inline grow">
      <input type="checkbox" id="notifications" name="notifications" onchange="toggleNotificationDays()">
      <label for="notifications" class="grow"><?= translate('enable_notifications', $i18n) ?></label>
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
      <input type="text" id="notes" name="notes" autocomplete="off" placeholder="<?= translate('notes', $i18n) ?>">
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
          <div class="subscription-image-selection-meta" id="detail-image-selection-meta">
            <?= translate('subscription_image_no_selection', $i18n) ?>
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
<section class="subscription-image-viewer" id="subscription-image-viewer">
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
<script src="scripts/subscriptions.js?<?= $version ?>"></script>
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
