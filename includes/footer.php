</main>

<section class="page-edition-footer" data-page-ui-hide-target>
  <div class="contain">
    <span class="custom-edition-badge"><?= htmlspecialchars($settings['custom_edition_title'] ?? 'Remastered', ENT_QUOTES, 'UTF-8') ?></span>
    <span class="custom-edition-text"><?= htmlspecialchars($settings['custom_edition_subtitle'] ?? '基于wallos原版深度魔改', ENT_QUOTES, 'UTF-8') ?></span>
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
