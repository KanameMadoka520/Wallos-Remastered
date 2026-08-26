<?php
/*
 * Dashboard subscription details popup.
 * The popup reads its subscription through endpoints/subscription/get.php;
 * this include only emits the shell and user-scoped lookup tables.
 */
require_once __DIR__ . '/getdbkeys.php';

$detailsLookups = [
    'categories' => new stdClass(),
    'members' => new stdClass(),
    'paymentMethods' => new stdClass(),
    'currencies' => new stdClass(),
    'subscriptionNames' => new stdClass(),
    'cycles' => [
        1 => ['one' => translate('Daily', $i18n), 'many' => translate('days', $i18n)],
        2 => ['one' => translate('Weekly', $i18n), 'many' => translate('weeks', $i18n)],
        3 => ['one' => translate('Monthly', $i18n), 'many' => translate('months', $i18n)],
        4 => ['one' => translate('Yearly', $i18n), 'many' => translate('years', $i18n)],
        5 => ['one' => translate('One-time', $i18n), 'many' => translate('One-time', $i18n)],
    ],
    'i18n' => [
        'automatic' => translate('automatic', $i18n),
        'manual_renewal' => translate('manual_renewal', $i18n),
        'inactive' => translate('disabled', $i18n),
        'one_time' => translate('One-time', $i18n),
        'enabled' => translate('enabled', $i18n),
        'disabled' => translate('disabled', $i18n),
        'on_due_date' => translate('on_due_date', $i18n),
        'day_before' => translate('day_before', $i18n),
        'days_before' => translate('days_before', $i18n),
        'none' => translate('none', $i18n),
    ],
];

foreach ($categories as $categoryId => $category) {
    $detailsLookups['categories']->{$categoryId} = $category['name'];
}
foreach ($members as $memberId => $member) {
    $detailsLookups['members']->{$memberId} = $member['name'];
}
foreach ($payment_methods as $paymentMethodId => $paymentMethod) {
    $detailsLookups['paymentMethods']->{$paymentMethodId} = [
        'name' => $paymentMethod['name'],
        'icon' => function_exists('wallos_resolve_payment_icon_path')
            ? wallos_resolve_payment_icon_path($paymentMethod['icon'] ?? '')
            : (string) ($paymentMethod['icon'] ?? ''),
    ];
}
foreach ($currencies as $currencyId => $currency) {
    $detailsLookups['currencies']->{$currencyId} = [
        'code' => $currency['code'],
        'symbol' => $currency['symbol'],
    ];
}
if (isset($subscriptions) && is_array($subscriptions)) {
    foreach ($subscriptions as $detailsSubscription) {
        if (isset($detailsSubscription['id'])) {
            $detailsLookups['subscriptionNames']->{$detailsSubscription['id']} = $detailsSubscription['name'];
        }
    }
}
?>

<div class="details-backdrop" id="details-backdrop" aria-hidden="true"></div>
<section class="subscription-details" id="subscription-details" role="dialog" aria-modal="true"
    aria-labelledby="details-name" aria-hidden="true">
    <button type="button" class="details-close" id="details-close" title="<?= htmlspecialchars(translate('cancel', $i18n), ENT_QUOTES, 'UTF-8') ?>"
        aria-label="<?= htmlspecialchars(translate('cancel', $i18n), ENT_QUOTES, 'UTF-8') ?>">
        <i class="fa-solid fa-xmark"></i>
    </button>
    <header class="details-hero">
        <div class="details-heading">
            <span class="details-logo" id="details-logo"></span>
            <div class="details-chips" id="details-chips"></div>
        </div>
        <h3 id="details-name"></h3>
    </header>
    <div class="details-price-row">
        <span class="details-price" id="details-price"></span>
        <span class="details-cycle" id="details-billing-cycle"></span>
        <button type="button" class="button secondary-button details-action-button details-export-button"
            id="details-export-button" title="<?= htmlspecialchars(translate('export_icalendar', $i18n), ENT_QUOTES, 'UTF-8') ?>"
            aria-label="<?= htmlspecialchars(translate('export_icalendar', $i18n), ENT_QUOTES, 'UTF-8') ?>">
            <i class="fa-solid fa-calendar-plus"></i>
        </button>
        <a class="button secondary-button details-action-button hide" id="details-url-button" href="#" target="_blank"
            rel="noreferrer" title="<?= htmlspecialchars(translate('external_url', $i18n), ENT_QUOTES, 'UTF-8') ?>"
            aria-label="<?= htmlspecialchars(translate('external_url', $i18n), ENT_QUOTES, 'UTF-8') ?>">
            <i class="fa-solid fa-globe"></i>
        </a>
    </div>
    <div class="details-progress-track" id="details-progress-track">
        <span class="details-progress" id="details-progress"></span>
    </div>
    <dl class="details-grid">
        <div class="details-item"><dt><?= translate('next_payment', $i18n) ?></dt><dd id="details-next-payment"></dd></div>
        <div class="details-item"><dt><?= translate('start_date', $i18n) ?></dt><dd id="details-start-date"></dd></div>
        <div class="details-item"><dt><?= translate('category', $i18n) ?></dt><dd id="details-category"></dd></div>
        <div class="details-item"><dt><?= translate('paid_by', $i18n) ?></dt><dd id="details-payer"></dd></div>
        <div class="details-item">
            <dt><?= translate('payment_method', $i18n) ?></dt>
            <dd id="details-payment-method"><img id="details-payment-icon" src="" alt=""><span id="details-payment-name"></span></dd>
        </div>
        <div class="details-item"><dt><?= translate('notifications', $i18n) ?></dt><dd id="details-notifications"></dd></div>
        <div class="details-item hide" id="details-cancellation-item"><dt><?= translate('cancellation_notification', $i18n) ?></dt><dd id="details-cancellation"></dd></div>
        <div class="details-item hide" id="details-replacement-item"><dt><?= translate('replaced_with', $i18n) ?></dt><dd id="details-replacement"></dd></div>
    </dl>
    <div class="details-notes hide" id="details-notes-item"><i class="fa-solid fa-note-sticky"></i><span id="details-notes"></span></div>
</section>

<script>
    window.subscriptionLookups = <?= json_encode($detailsLookups, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>
<script src="scripts/subscription-details.js?<?= $version . '.' . @filemtime(__DIR__ . '/../scripts/subscription-details.js') ?>"></script>
