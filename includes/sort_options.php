<div class="sort-options" id="sort-options">
    <ul>
        <li <?= $sortOrder == "manual_order" ? 'class="selected"' : "" ?> data-subscription-action="set-sort-option" data-sort-option="manual_order" id="sort-manual_order">
            <?= translate('manual_order', $i18n) ?>
        </li>
        <li <?= $sortOrder == "name" ? 'class="selected"' : "" ?> data-subscription-action="set-sort-option" data-sort-option="name" id="sort-name">
            <?= translate('name', $i18n) ?>
        </li>
        <li <?= $sortOrder == "id" ? 'class="selected"' : "" ?> data-subscription-action="set-sort-option" data-sort-option="id" id="sort-id">
            <?= translate('last_added', $i18n) ?>
        </li>
        <li <?= $sortOrder == "price" ? 'class="selected"' : "" ?> data-subscription-action="set-sort-option" data-sort-option="price" id="sort-price">
            <?= translate('price', $i18n) ?>
        </li>
        <li <?= $sortOrder == "payment_total_main" ? 'class="selected"' : "" ?> data-subscription-action="set-sort-option" data-sort-option="payment_total_main" id="sort-payment_total_main">
            <?= translate('subscription_invested_total', $i18n) ?>
        </li>
        <li <?= $sortOrder == "remaining_value" ? 'class="selected"' : "" ?> data-subscription-action="set-sort-option" data-sort-option="remaining_value" id="sort-remaining_value">
            <?= translate('subscription_remaining_value', $i18n) ?>
        </li>
        <li <?= $sortOrder == "next_payment" ? 'class="selected"' : "" ?> data-subscription-action="set-sort-option" data-sort-option="next_payment"
            id="sort-next_payment"><?= translate('next_payment', $i18n) ?></li>
        <li <?= $sortOrder == "payer_user_id" ? 'class="selected"' : "" ?> data-subscription-action="set-sort-option" data-sort-option="payer_user_id"
            id="sort-payer_user_id"><?= translate('member', $i18n) ?></li>
        <li <?= $sortOrder == "category_id" ? 'class="selected"' : "" ?> data-subscription-action="set-sort-option" data-sort-option="category_id"
            id="sort-category_id"><?= translate('category', $i18n) ?></li>
        <li <?= $sortOrder == "payment_method_id" ? 'class="selected"' : "" ?> data-subscription-action="set-sort-option" data-sort-option="payment_method_id"
            id="sort-payment_method_id">
            <?= translate('payment_method', $i18n) ?>
        </li>
        <?php
        if (!isset($settings['hideDisabledSubscriptions']) || $settings['hideDisabledSubscriptions'] !== 'true') {
            ?>
            <li <?= $sortOrder == "inactive" ? 'class="selected"' : "" ?> data-subscription-action="set-sort-option" data-sort-option="inactive"
                id="sort-inactive"><?= translate('state', $i18n) ?></li>
            <?php
        }
        ?>
        <li <?= $sortOrder == "alphanumeric" ? 'class="selected"' : "" ?> data-subscription-action="set-sort-option" data-sort-option="alphanumeric"
            id="sort-alphanumeric"><?= translate('alphanumeric', $i18n) ?></li>
        <li <?= $sortOrder == "renewal_type" ? 'class="selected"' : "" ?> data-subscription-action="set-sort-option" data-sort-option="renewal_type"
            id="sort-renewal_type"><?= translate('renewal_type', $i18n) ?></li>
    </ul>
</div>
