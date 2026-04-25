</main>

<?php
$csrfTokenFingerprint = function_exists('get_csrf_token_fingerprint') ? get_csrf_token_fingerprint() : '';
$csrfTokenExpiresAt = function_exists('get_csrf_token_expires_at') ? get_csrf_token_expires_at() : 0;
$csrfTokenExpiresDisplay = '';
if ($csrfTokenExpiresAt > 0) {
  try {
    $csrfTokenDisplayTimezone = date_default_timezone_get();
    if (function_exists('wallos_normalize_timezone_identifier')) {
      $csrfTokenDisplayTimezone = wallos_normalize_timezone_identifier(
        $settings['user_timezone'] ?? $csrfTokenDisplayTimezone,
        function_exists('wallos_get_default_user_timezone') ? wallos_get_default_user_timezone() : $csrfTokenDisplayTimezone
      );
    }
    $csrfTokenExpiryDateTime = (new DateTimeImmutable('@' . $csrfTokenExpiresAt))
      ->setTimezone(new DateTimeZone($csrfTokenDisplayTimezone));
    $csrfTokenTimezoneLabel = $csrfTokenDisplayTimezone;
    if (function_exists('wallos_get_timezone_offset_label')) {
      $csrfTokenTimezoneLabel .= ' (' . wallos_get_timezone_offset_label($csrfTokenDisplayTimezone, $csrfTokenExpiryDateTime) . ')';
    }
    $csrfTokenExpiresDisplay = $csrfTokenExpiryDateTime->format('Y-m-d H:i:s') . ' ' . $csrfTokenTimezoneLabel;
  } catch (Throwable $throwable) {
    $csrfTokenExpiresDisplay = date('Y-m-d H:i:s P', $csrfTokenExpiresAt);
  }
}
?>

<section class="page-edition-footer" data-page-ui-hide-target>
  <div class="contain">
    <span class="custom-edition-badge"><?= htmlspecialchars($settings['custom_edition_title'] ?? 'Remastered', ENT_QUOTES, 'UTF-8') ?></span>
    <span class="custom-edition-text"><?= htmlspecialchars($settings['custom_edition_subtitle'] ?? '基于wallos原版深度魔改', ENT_QUOTES, 'UTF-8') ?></span>
    <?php if ($csrfTokenFingerprint !== ''): ?>
      <span class="page-edition-security-token" title="<?= htmlspecialchars(translate('csrf_token_footer_note', $i18n), ENT_QUOTES, 'UTF-8') ?>">
        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
        <span><?= translate('csrf_token_footer_label', $i18n) ?> <code><?= htmlspecialchars($csrfTokenFingerprint, ENT_QUOTES, 'UTF-8') ?></code></span>
        <?php if ($csrfTokenExpiresDisplay !== ''): ?>
          <span><?= translate('csrf_token_footer_expires', $i18n) ?> <?= htmlspecialchars($csrfTokenExpiresDisplay, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
      </span>
    <?php endif; ?>
  </div>
</section>

<div class="toast" id="errorToast">
  <div class="toast-content">
    <i class="fas fa-solid fa-x toast-icon error"></i>
    <div class="message">
      <span class="text text-1"><?= translate("error", $i18n) ?></span>
      <span class="text text-2 errorMessage"></span>
    </div>
  </div>
  <i class="fa-solid fa-xmark close close-error"></i>
  <div class="progress error"></div>
</div>

<div class="toast" id="successToast">
  <div class="toast-content">
    <i class="fas fa-solid fa-check toast-icon success"></i>
    <div class="message">
      <span class="text text-1"><?= translate("success", $i18n) ?></span>
      <span class="text text-2 successMessage"></span>
    </div>
  </div>
  <i class="fa-solid fa-xmark close close-success"></i>
  <div class="progress success"></div>
</div>

<?php
if (isset($db)) {
  $db->close();
}
?>

</body>

</html>
