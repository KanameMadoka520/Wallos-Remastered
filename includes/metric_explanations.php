<?php

require_once __DIR__ . '/inputvalidation.php';

function wallos_metric_explanation_decode_item_names(array $items)
{
    return array_map(static function ($item) {
        if (!is_array($item)) return $item;
        foreach (['name', 'subscription_name'] as $field) {
            if (isset($item[$field])) $item[$field] = wallos_decode_stored_text($item[$field]);
        }
        return $item;
    }, $items);
}

function wallos_render_metric_explanation_trigger($metricKey, array $metricExplanations, $label = '')
{
    if (!isset($metricExplanations[$metricKey])) {
        return;
    }

    $payloadData = $metricExplanations[$metricKey];
    // Names use the legacy escaped storage contract. Decode only this display
    // copy; the JSON attribute and browser renderer still escape their output.
    if (isset($payloadData['items']) && is_array($payloadData['items'])) {
        $payloadData['items'] = wallos_metric_explanation_decode_item_names($payloadData['items']);
    }
    foreach ($payloadData['item_groups'] ?? [] as $index => $group) {
        if (isset($group['items']) && is_array($group['items'])) {
            $payloadData['item_groups'][$index]['items'] = wallos_metric_explanation_decode_item_names($group['items']);
        }
    }
    $settings = $GLOBALS['settings'] ?? [];
    if (function_exists('wallos_screenshot_privacy_enabled')
        && is_array($settings)
        && wallos_screenshot_privacy_enabled($settings)
    ) {
        $payloadData = wallos_screenshot_privacy_mask_metric_payload(
            $payloadData,
            wallos_screenshot_privacy_seed(),
            (string) ($GLOBALS['lang'] ?? 'en')
        );
    }

    $payload = htmlspecialchars(json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
    $ariaLabel = htmlspecialchars($label !== '' ? $label : $metricKey, ENT_QUOTES, 'UTF-8');
    ?>
    <button type="button" class="metric-explanation-trigger" data-metric-explanation="<?= $payload ?>"
        aria-label="<?= $ariaLabel ?>">
        <i class="fa-solid fa-circle-info"></i>
    </button>
    <?php
}

function wallos_render_metric_explanation_modal($i18n)
{
    ?>
    <section class="subscription-modal metric-explanation-modal" id="metric-explanation-modal">
      <header>
        <h3 id="metric-explanation-title"><?= translate('metric_explanation_title', $i18n) ?></h3>
        <button type="button" class="close-form" onClick="closeMetricExplanationModal()" aria-label="<?= translate('close', $i18n) ?>">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </header>
      <div class="metric-explanation-content">
        <div class="metric-explanation-section">
          <div class="metric-explanation-label"><?= translate('metric_explanation_formula_label', $i18n) ?></div>
          <div class="metric-explanation-formula" id="metric-explanation-formula">-</div>
        </div>
        <div class="metric-explanation-summary" id="metric-explanation-summary"></div>
        <div class="metric-explanation-section">
          <div class="metric-explanation-label"><?= translate('metric_explanation_items_label', $i18n) ?></div>
          <div class="metric-explanation-items" id="metric-explanation-items"></div>
        </div>
      </div>
    </section>
    <?php
}
