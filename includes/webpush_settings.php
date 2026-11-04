<section class="account-notifications-section" id="webpush-settings">
    <header class="account-notification-section-header" onclick="openNotificationsSettings('webpush')">
        <h3><i class="fa-solid fa-bell"></i> Web Push</h3>
    </header>
    <div class="account-notification-section-settings" data-type="webpush">
        <p><?= translate('webpush_intro', $i18n) ?></p>
        <p id="webpush-status" role="status" aria-live="polite"></p>
        <div class="form-group-inline">
            <input type="checkbox" id="webpush-enabled">
            <label for="webpush-enabled"><?= translate('enabled', $i18n) ?></label>
        </div>
        <div class="form-group-inline">
            <input type="checkbox" id="webpush-details">
            <label for="webpush-details"><?= translate('webpush_show_details', $i18n) ?></label>
        </div>
        <p><?= translate('webpush_privacy', $i18n) ?></p>
        <div class="webpush-device-controls">
            <label class="webpush-device-field" for="webpush-device-name">
                <span><?= translate('webpush_device_name', $i18n) ?></span>
                <input id="webpush-device-name" type="text" maxlength="80" placeholder="<?= translate('webpush_device_name', $i18n) ?>" aria-label="<?= translate('webpush_device_name', $i18n) ?>">
            </label>
            <button type="button" class="button thin" id="webpush-subscribe"><?= translate('webpush_enable_device', $i18n) ?></button>
            <button type="button" class="secondary-button thin" id="webpush-save"><?= translate('save', $i18n) ?></button>
        </div>
        <ul id="webpush-devices"></ul>
    </div>
</section>
