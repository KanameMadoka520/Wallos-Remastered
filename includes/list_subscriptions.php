<?php

require_once 'i18n/getlang.php';
require_once __DIR__ . '/subscription_media.php';
require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/subscription_price_rules.php';

function getBillingCycle($cycle, $frequency, $i18n)
{
    switch ($cycle) {
        case 1:
            return $frequency == 1 ? translate('Daily', $i18n) : $frequency . " " . translate('days', $i18n);
        case 2:
            return $frequency == 1 ? translate('Weekly', $i18n) : $frequency . " " . translate('weeks', $i18n);
        case 3:
            return $frequency == 1 ? translate('Monthly', $i18n) : $frequency . " " . translate('months', $i18n);
        case 4:
            return $frequency == 1 ? translate('Yearly', $i18n) : $frequency . " " . translate('years', $i18n);
    }
}

function getSubscriptionProgress($cycle, $frequency, $next_payment)
{
    $nextPaymentDate = new DateTime($next_payment);
    $currentDate = new DateTime('now');

    $paymentCycleDays = 30; // Default to monthly
    if ($cycle === 1) {
        $paymentCycleDays = 1 * $frequency;
    } else if ($cycle === 2) {
        $paymentCycleDays = 7 * $frequency;
    } else if ($cycle === 3) {
        $paymentCycleDays = 30 * $frequency;
    } else if ($cycle === 4) {
        $paymentCycleDays = 365 * $frequency;
    }

    $lastPaymentDate = clone $nextPaymentDate;
    $lastPaymentDate->modify("-$paymentCycleDays days");

    $totalCycleDays = $lastPaymentDate->diff($nextPaymentDate)->days;
    $daysSinceLastPayment = $lastPaymentDate->diff($currentDate)->days;

    $subscriptionProgress = 0;
    if ($totalCycleDays > 0) {
        $subscriptionProgress = ($daysSinceLastPayment / $totalCycleDays) * 100;
    }

    return floor($subscriptionProgress);
}

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


function getPriceConverted($price, $currency, $database)
{
    $query = "SELECT rate FROM currencies WHERE id = :currency";
    $stmt = $database->prepare($query);
    $stmt->bindParam(':currency', $currency, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $exchangeRate = $result->fetchArray(SQLITE3_ASSOC);
    if ($exchangeRate === false) {
        return $price;
    } else {
        $fromRate = $exchangeRate['rate'];
        return $price / $fromRate;
    }
}

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

function formatSnapshotPrice($amount, $currencyCode)
{
    $currencyCode = trim((string) $currencyCode);
    if ($currencyCode === '') {
        return number_format((float) $amount, 2);
    }

    return CurrencyFormatter::format((float) $amount, $currencyCode);
}

function formatDate($date, $lang = 'en')
{
    if (!$date) {
        return '';
    }

    $dateTime = new DateTime($date);
    $normalizedLang = strtolower(str_replace('-', '_', (string) $lang));

    if ($normalizedLang === 'zh_cn' || $normalizedLang === 'zh_tw') {
        return $dateTime->format('Y年n月j日');
    }

    $dateFormat = 'MMM d, yyyy';

    if (!in_array($lang, ResourceBundle::getLocales(''))) {
        $lang = 'en';
    }

    $formatter = new IntlDateFormatter(
        $lang,
        IntlDateFormatter::SHORT,
        IntlDateFormatter::NONE,
        null,
        null,
        $dateFormat
    );

    return $formatter->format($dateTime);
}

function printSubscriptions($subscriptions, $sort, $categories, $members, $i18n, $colorTheme, $imagePath, $disabledToBottom, $mobileNavigation, $showSubscriptionProgress, $currencies, $lang)
{
    if ($sort === "price") {
        usort($subscriptions, function ($a, $b) {
            return $a['price'] < $b['price'] ? 1 : -1;
        });
        if ($disabledToBottom === 'true') {
            usort($subscriptions, function ($a, $b) {
                return $a['inactive'] - $b['inactive'];
            });
        }
    }

    $currentCategory = 0;
    $currentPayerUserId = 0;
    $currentPaymentMethodId = 0;
    foreach ($subscriptions as $subscription) {
        if ($sort == "category_id" && $subscription['category_id'] != $currentCategory) {
            ?>
            <div class="subscription-list-title">
                <?php
                if ($subscription['category_id'] == 1) {
                    echo translate('no_category', $i18n);
                } else {
                    echo htmlspecialchars($categories[$subscription['category_id']]['name'], ENT_QUOTES, 'UTF-8');
                }
                ?>
            </div>
            <?php
            $currentCategory = $subscription['category_id'];
        }
        if ($sort == "payer_user_id" && $subscription['payer_user_id'] != $currentPayerUserId) {
            ?>
            <div class="subscription-list-title">
                <?= htmlspecialchars($members[$subscription['payer_user_id']]['name'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php
            $currentPayerUserId = $subscription['payer_user_id'];
        }
        if ($sort == "payment_method_id" && $subscription['payment_method_id'] != $currentPaymentMethodId) {
            ?>
            <div class="subscription-list-title">
                <?= htmlspecialchars($subscription['payment_method_name'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php
            $currentPaymentMethodId = $subscription['payment_method_id'];
        }
        ?>
        <div class="subscription-container" data-id="<?= (int) $subscription['id'] ?>">
            <?php
            if ($mobileNavigation === 'true') {
                ?>
                <div class="mobile-actions" data-id="<?= $subscription['id'] ?>">
                    <button class="mobile-action-clone"></button>
                    <button class="mobile-action-clone" data-subscription-action="clone-subscription" data-subscription-id="<?= (int) $subscription['id'] ?>">
                        <?php include $imagePath . "images/siteicons/svg/mobile-menu/clone.php"; ?>
                        Clone
                    </button>
                    <button class="mobile-action-delete" data-subscription-action="delete-subscription" data-subscription-id="<?= (int) $subscription['id'] ?>">
                        <?php include $imagePath . "images/siteicons/svg/mobile-menu/delete.php"; ?>
                        Delete
                    </button>
                    <?php
                    if ($subscription['auto_renew'] != 1) {
                        ?>
                        <button class="mobile-action-renew" data-subscription-action="renew-subscription" data-subscription-id="<?= (int) $subscription['id'] ?>">
                            <?php include $imagePath . "images/siteicons/svg/mobile-menu/renew.php"; ?>
                            Renew
                        </button>
                        <?php
                    }
                    ?>
                    <button class="mobile-action-edit" data-subscription-action="open-edit-subscription" data-subscription-id="<?= (int) $subscription['id'] ?>">
                        <?php include $imagePath . "images/siteicons/svg/mobile-menu/edit.php"; ?>
                        Edit
                    </button>
                </div>
                <?php
            }

            $subscriptionExtraClasses = "";
            if ($subscription['inactive']) {
                $subscriptionExtraClasses .= " inactive";
            }
            if ($subscription['auto_renew'] != 1) {
                $subscriptionExtraClasses .= " manual";
            }
            if (!empty($subscription['exclude_from_stats'])) {
                $subscriptionExtraClasses .= " no-stats";
            }

            $hasLogo = false;
            if ($subscription['logo'] != "") {
                $hasLogo = true;
            }

            ?>

            <div class="subscription<?= $subscriptionExtraClasses ?>" data-subscription-action="toggle-open-subscription"
                data-subscription-id="<?= (int) $subscription['id'] ?>" data-id="<?= $subscription['id'] ?>"
                data-name="<?= htmlspecialchars($subscription['name'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="subscription-main">
                    <button type="button" class="subscription-drag-handle"
                        title="<?= translate('subscription_reorder_handle_title', $i18n) ?>"
                        aria-label="<?= translate('subscription_reorder_handle_title', $i18n) ?>"
                        data-subscription-action="prevent-subscription-toggle">
                        <i class="fa-solid fa-grip-vertical"></i>
                    </button>
                    <span class="logo <?= !$hasLogo ? 'hideOnMobile' : '' ?>">
                        <?php
                        if ($hasLogo) {
                            ?>
                            <img src="<?= htmlspecialchars($subscription['logo'], ENT_QUOTES, 'UTF-8') ?>">
                            <?php
                        } else {
                            include $imagePath . "images/siteicons/svg/logo.php";
                        }
                        ?>
                    </span>
                    <span class="name <?= $hasLogo ? 'hideOnMobile' : '' ?>"><?= htmlspecialchars($subscription['name'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if (!empty($subscription['exclude_from_stats'])): ?>
                        <span class="subscription-inline-flag no-stats" title="<?= translate('subscription_exclude_from_stats_help', $i18n) ?>">
                            <i class="fa-solid fa-chart-line"></i>
                            <?= translate('subscription_excluded_from_stats_badge', $i18n) ?>
                        </span>
                    <?php endif; ?>
                    <span class="cycle"
                        title="<?= $subscription['auto_renew'] ? translate("automatically_renews", $i18n) : translate("manual_renewal", $i18n) ?>">
                        <?php
                        if ($subscription['auto_renew']) {
                            include $imagePath . "images/siteicons/svg/automatic.php";
                        } else {
                            include $imagePath . "images/siteicons/svg/manual.php";
                        }
                        ?>
                        <?= $subscription['billing_cycle'] ?>
                    </span>
                    <span class="next" title="<?= translate('theoretical_renewal_date', $i18n) ?>">
                        <span class="next-label"><?= translate('theoretical_renewal_date', $i18n) ?></span>
                        <span class="next-value"><?= formatDate($subscription['next_payment'], $lang) ?></span>
                    </span>
                    <span class="price">
                        <span class="value">
                            <?= formatPrice($subscription['price'], $subscription['currency_code'], $currencies) ?>
                            <?php
                            if (isset($subscription['original_price']) && $subscription['original_price'] != $subscription['price']) {
                                ?>
                                <span
                                    class="original_price">(<?= formatPrice($subscription['original_price'], $subscription['original_currency_code'], $currencies) ?>)</span>
                                <?php
                            }
                            ?>
                        </span>

                    </span>
                    <span class="payment_method">
                        <img src="<?= htmlspecialchars($subscription['payment_method_icon'], ENT_QUOTES, 'UTF-8') ?>"
                            title="<?= translate('payment_method', $i18n) ?>: <?= htmlspecialchars($subscription['payment_method_name'], ENT_QUOTES, 'UTF-8') ?>" />
                    </span>
                    <?php
                    $desktopMenuButtonClass = ""; {
                    }
                    if ($mobileNavigation === "true") {
                        $desktopMenuButtonClass = "mobileNavigationHideOnMobile";
                    }
                    ?>
                    <button type="button" class="actions-expand <?= $desktopMenuButtonClass ?>"
                        data-subscription-action="expand-subscription-actions" data-subscription-id="<?= (int) $subscription['id'] ?>">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <ul class="actions">
                        <li class="edit" title="<?= translate('edit_subscription', $i18n) ?>" data-subscription-action="open-edit-subscription"
                            data-subscription-id="<?= (int) $subscription['id'] ?>">
                            <?php include $imagePath . "images/siteicons/svg/edit.php"; ?>
                            <?= translate('edit_subscription', $i18n) ?>
                        </li>
                        <li class="delete" title="<?= translate('delete', $i18n) ?>" data-subscription-action="delete-subscription"
                            data-subscription-id="<?= (int) $subscription['id'] ?>">
                            <?php include $imagePath . "images/siteicons/svg/delete.php"; ?>
                            <?= translate('delete', $i18n) ?>
                        </li>
                        <li class="clone" title="<?= translate('clone', $i18n) ?>" data-subscription-action="clone-subscription"
                            data-subscription-id="<?= (int) $subscription['id'] ?>">
                            <?php include $imagePath . "images/siteicons/svg/clone.php"; ?>
                            <?= translate('clone', $i18n) ?>
                        </li>
                        <?php
                        if ($subscription['auto_renew'] != 1) {
                            ?>
                            <li class="renew" title="<?= translate('renew', $i18n) ?>" data-subscription-action="renew-subscription"
                                data-subscription-id="<?= (int) $subscription['id'] ?>">
                                <?php include $imagePath . "images/siteicons/svg/renew.php"; ?>
                                <?= translate('renew', $i18n) ?>
                            </li>
                            <?php
                        }
                        ?>
                    </ul>
                </div>
                <div class="subscription-secondary">
                    <span
                        class="name"><?php include $imagePath . "images/siteicons/svg/subscription.php"; ?><?= htmlspecialchars($subscription['name'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="payer_user"
                        title="<?= translate('paid_by', $i18n) ?>"><?php include $imagePath . "images/siteicons/svg/payment.php"; ?><?= htmlspecialchars($members[$subscription['payer_user_id']]['name'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="category"
                        title="<?= translate('category', $i18n) ?>"><?php include $imagePath . "images/siteicons/svg/category.php"; ?><?= htmlspecialchars($categories[$subscription['category_id']]['name'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php
                    if ($subscription['url'] != "") {
                        $url = $subscription['url'];
                        if (!preg_match('/^https?:\/\//', $url)) {
                            $url = "https://" . $url;
                        }
                        ?>
                        <span class="url" title="<?= translate('external_url', $i18n) ?>"><a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank"
                                rel="noreferrer"><?php include $imagePath . "images/siteicons/svg/web.php"; ?></a></span>
                        <?php
                    }
                    ?>
                </div>
                <?php
                $uploadedImages = $subscription['uploaded_images'] ?? [];
                $detailImageUrls = wallos_decode_subscription_image_urls($subscription['detail_image_urls'] ?? '[]');
                $hasDetailImages = !empty($uploadedImages) || !empty($detailImageUrls);

                if ($subscription['notes'] != "") {
                    ?>
                    <div class="subscription-notes">
                        <div class="subscription-notes-marker">
                            <?php include $imagePath . "images/siteicons/svg/notes.php"; ?>
                        </div>
                        <div class="subscription-markdown subscription-notes-content">
                            <?= wallos_render_markdown($subscription['notes']) ?>
                        </div>
                    </div>
                    <?php
                }

                $priceRules = $subscription['price_rules'] ?? [];
                if (!empty($priceRules)) {
                    ?>
                    <div class="subscription-price-rules">
                        <div class="subscription-price-rules-header">
                            <span class="subscription-price-rules-title">
                                <i class="fa-solid fa-tags"></i>
                                <?= translate('subscription_price_rules', $i18n) ?>
                            </span>
                            <span class="subscription-price-rules-count">
                                <?= sprintf(translate('subscription_price_rules_count_dynamic', $i18n), count($priceRules)) ?>
                            </span>
                        </div>
                        <div class="subscription-price-rules-summary-list">
                            <?php foreach ($priceRules as $rule): ?>
                                <article class="subscription-price-rule-summary-card">
                                    <div class="subscription-price-rule-summary-headline">
                                        <strong><?= htmlspecialchars(wallos_format_subscription_price_rule_summary($rule, $currencies, $i18n), ENT_QUOTES, 'UTF-8') ?></strong>
                                    </div>
                                    <?php if (!empty($rule['note'])): ?>
                                        <div class="subscription-markdown subscription-price-rule-summary-note">
                                            <?= wallos_render_markdown($rule['note']) ?>
                                        </div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php
                }

                if ($hasDetailImages) {
                    $mediaItemCount = count($uploadedImages) + count($detailImageUrls);
                    ?>
                    <div class="subscription-media">
                        <div class="subscription-media-header">
                            <span class="subscription-media-title">
                                <i class="fa-regular fa-image"></i>
                                <?= translate('subscription_images', $i18n) ?>
                            </span>
                            <div class="subscription-media-header-actions">
                                <span class="subscription-media-hint"><?= translate('subscription_image_click_to_enlarge', $i18n) ?></span>
                                <div class="media-layout-toggle" data-image-layout-scope="detail">
                                    <button type="button" class="media-layout-button" data-mode="focus"
                                        title="<?= translate('subscription_image_layout_focus', $i18n) ?>"
                                        data-subscription-action="set-image-layout" data-layout-scope="detail" data-layout-mode="focus">
                                        <i class="fa-solid fa-images"></i>
                                        <span><?= translate('subscription_image_layout_focus', $i18n) ?></span>
                                    </button>
                                    <button type="button" class="media-layout-button" data-mode="grid"
                                        title="<?= translate('subscription_image_layout_grid', $i18n) ?>"
                                        data-subscription-action="set-image-layout" data-layout-scope="detail" data-layout-mode="grid">
                                        <i class="fa-solid fa-table-cells"></i>
                                        <span><?= translate('subscription_image_layout_grid', $i18n) ?></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="subscription-media-gallery<?= $mediaItemCount > 1 ? ' has-multiple' : '' ?>"
                            data-subscription-id="<?= (int) $subscription['id'] ?>">
                            <?php
                            foreach ($uploadedImages as $uploadedImage) {
                                $imageThumbUrl = trim((string) ($uploadedImage['thumbnail_url'] ?? ''));
                                $imagePreviewUrl = trim((string) ($uploadedImage['preview_url'] ?? ''));
                                $imageOriginalUrl = trim((string) ($uploadedImage['original_url'] ?? ''));
                                $imageDownloadUrl = trim((string) ($uploadedImage['download_url'] ?? ''));
                                if ($imagePreviewUrl === '') {
                                    continue;
                                }
                                $uploadedImageName = trim((string) ($uploadedImage['original_name'] ?? $uploadedImage['file_name'] ?? ''));
                                if ($uploadedImageName === '') {
                                    $uploadedImageName = translate('subscription_image_source_server', $i18n);
                                }
                                ?>
                                <button type="button" class="subscription-media-item"
                                    data-uploaded-image-id="<?= (int) ($uploadedImage['id'] ?? 0) ?>"
                                    title="<?= translate('subscription_image_click_to_enlarge', $i18n) ?>"
                                    data-viewer-src="<?= htmlspecialchars($imagePreviewUrl, ENT_QUOTES, 'UTF-8') ?>"
                                    data-viewer-original="<?= htmlspecialchars($imageOriginalUrl !== '' ? $imageOriginalUrl : $imagePreviewUrl, ENT_QUOTES, 'UTF-8') ?>"
                                    data-viewer-download="<?= htmlspecialchars($imageDownloadUrl !== '' ? $imageDownloadUrl : ($imageOriginalUrl !== '' ? $imageOriginalUrl : $imagePreviewUrl), ENT_QUOTES, 'UTF-8') ?>"
                                    data-viewer-label="<?= htmlspecialchars($uploadedImageName, ENT_QUOTES, 'UTF-8') ?>"
                                    data-viewer-size-thumbnail="<?= htmlspecialchars((string) ($uploadedImage['thumbnail_size_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                    data-viewer-size-preview="<?= htmlspecialchars((string) ($uploadedImage['preview_size_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                    data-viewer-size-original="<?= htmlspecialchars((string) ($uploadedImage['original_size_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                    data-viewer-preview-reused-original="<?= !empty($uploadedImage['preview_reused_original']) ? '1' : '0' ?>"
                                    data-viewer-thumbnail-reused-original="<?= !empty($uploadedImage['thumbnail_reused_original']) ? '1' : '0' ?>"
                                    data-subscription-action="open-subscription-image-viewer">
                                    <img src="<?= htmlspecialchars($imageThumbUrl !== '' ? $imageThumbUrl : $imagePreviewUrl, ENT_QUOTES, 'UTF-8') ?>"
                                        alt="<?= htmlspecialchars($uploadedImageName, ENT_QUOTES, 'UTF-8') ?>"
                                        loading="lazy" decoding="async" />
                                    <span class="subscription-media-badge server"><?= translate('subscription_image_source_server', $i18n) ?></span>
                                    <span class="subscription-media-zoom"><i class="fa-solid fa-magnifying-glass-plus"></i></span>
                                </button>
                                <?php
                            }

                            foreach ($detailImageUrls as $detailImageUrl) {
                                ?>
                                <button type="button" class="subscription-media-item"
                                    title="<?= translate('subscription_image_click_to_enlarge', $i18n) ?>"
                                    data-viewer-src="<?= htmlspecialchars($detailImageUrl, ENT_QUOTES, 'UTF-8') ?>"
                                    data-viewer-original="<?= htmlspecialchars($detailImageUrl, ENT_QUOTES, 'UTF-8') ?>"
                                    data-viewer-download="<?= htmlspecialchars($detailImageUrl, ENT_QUOTES, 'UTF-8') ?>"
                                    data-viewer-label="<?= htmlspecialchars($subscription['name'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-subscription-action="open-subscription-image-viewer">
                                    <img src="<?= htmlspecialchars($detailImageUrl, ENT_QUOTES, 'UTF-8') ?>"
                                        alt="<?= htmlspecialchars($subscription['name'], ENT_QUOTES, 'UTF-8') ?>"
                                        loading="lazy" decoding="async" referrerpolicy="no-referrer" />
                                    <span class="subscription-media-badge external"><?= translate('subscription_image_source_external', $i18n) ?></span>
                                    <span class="subscription-media-zoom"><i class="fa-solid fa-magnifying-glass-plus"></i></span>
                                </button>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                    <?php
                }

                $paymentRecords = $subscription['payment_records'] ?? [];
                $paymentRecordCount = (int) ($subscription['payment_record_count'] ?? count($paymentRecords));
                $latestPaymentRecord = $paymentRecords[0] ?? null;
                ?>
                <div class="subscription-payment-records">
                    <div class="subscription-payment-records-header">
                        <span class="subscription-payment-records-title">
                            <i class="fa-solid fa-receipt"></i>
                            <?= translate('subscription_payment_history', $i18n) ?>
                        </span>
                        <div class="subscription-payment-record-actions">
                        <button type="button" class="button secondary-button thin subscription-payment-record-button"
                            data-subscription-action="open-payment-history" data-subscription-id="<?= (int) $subscription['id'] ?>">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                            <span><?= translate('subscription_view_payment_history', $i18n) ?></span>
                        </button>
                        <button type="button" class="button secondary-button thin subscription-payment-record-button"
                            data-subscription-action="open-payment-modal" data-subscription-id="<?= (int) $subscription['id'] ?>">
                            <i class="fa-solid fa-plus"></i>
                            <span><?= translate('subscription_record_payment', $i18n) ?></span>
                        </button>
                        </div>
                    </div>
                <?php if (!empty($paymentRecords)): ?>
                        <div class="subscription-payment-record-summary">
                            <span><?= sprintf(translate('subscription_payment_history_count_dynamic', $i18n), $paymentRecordCount) ?></span>
                            <?php if ($latestPaymentRecord): ?>
                                <span><?= translate('subscription_payment_latest_record', $i18n) ?>:
                                    <?= htmlspecialchars($latestPaymentRecord['paid_at'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                    /
                                    <?= htmlspecialchars(formatSnapshotPrice($latestPaymentRecord['amount_original'] ?? 0, $latestPaymentRecord['currency_code_snapshot'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="subscription-payment-record-empty">
                            <?= translate('subscription_payment_history_empty', $i18n) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php
                $remainingValue = $subscription['remaining_value'] ?? ['available' => false];
                $paymentTotalMain = (float) ($subscription['payment_total_main'] ?? 0);
                ?>
                <div class="subscription-value-metrics">
                    <article class="subscription-value-metric-card metric-invested">
                        <span class="subscription-value-metric-label"><?= translate('subscription_invested_total', $i18n) ?></span>
                        <strong><?= htmlspecialchars(formatPrice($paymentTotalMain, $subscription['payment_total_currency_code'] ?? $subscription['currency_code'], $currencies), ENT_QUOTES, 'UTF-8') ?></strong>
                    </article>
                    <?php if (!empty($remainingValue['available'])): ?>
                        <article class="subscription-value-metric-card emphasis metric-remaining">
                            <span class="subscription-value-metric-label"><?= translate('subscription_remaining_value', $i18n) ?></span>
                            <strong><?= htmlspecialchars(formatPrice((float) ($remainingValue['remaining_value_main'] ?? 0), $subscription['payment_total_currency_code'] ?? $subscription['currency_code'], $currencies), ENT_QUOTES, 'UTF-8') ?></strong>
                            <div class="subscription-value-metric-meta">
                                <span><?= sprintf(
                                    translate('subscription_remaining_value_days_dynamic', $i18n),
                                    (int) ($remainingValue['remaining_days'] ?? 0),
                                    (int) ($remainingValue['total_days'] ?? 0),
                                    number_format((float) ($remainingValue['remaining_ratio'] ?? 0), 2)
                                ) ?></span>
                                <span><?= htmlspecialchars((string) ($remainingValue['value_source_summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                <span><?= htmlspecialchars((string) ($remainingValue['remaining_mode_summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </article>
                    <?php endif; ?>
                    <?php if (!empty($remainingValue['manual_used_value_active'])): ?>
                        <article class="subscription-value-metric-card metric-used">
                            <span class="subscription-value-metric-label"><?= translate('subscription_manual_used_value', $i18n) ?></span>
                            <strong><?= htmlspecialchars(formatPrice((float) ($remainingValue['manual_used_value_main'] ?? 0), $subscription['payment_total_currency_code'] ?? $subscription['currency_code'], $currencies), ENT_QUOTES, 'UTF-8') ?></strong>
                            <div class="subscription-value-metric-meta">
                                <span><?= htmlspecialchars(translate('subscription_manual_used_value_manual_badge', $i18n), ENT_QUOTES, 'UTF-8') ?></span>
                                <span><?= htmlspecialchars(formatPrice((float) ($remainingValue['manual_unused_value_main'] ?? 0), $subscription['payment_total_currency_code'] ?? $subscription['currency_code'], $currencies), ENT_QUOTES, 'UTF-8') ?> <?= translate('subscription_manual_unused_value_suffix', $i18n) ?></span>
                            </div>
                        </article>
                    <?php endif; ?>
                </div>
                <?php
                ?>
            </div>
            <?php
            if ($showSubscriptionProgress === 'true') {
                $progress = $subscription['progress'] > 100 ? 100 : $subscription['progress'];
                ?>
                <div class="subscription-progress-container">
                    <span class="subscription-progress" style="width: <?= $progress ?>%;"></span>
                </div>
                <?php
            }
            ?>
        </div>
        <?php
    }
}
?>
