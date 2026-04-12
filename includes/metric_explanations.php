<?php

function wallos_render_metric_explanation_trigger($metricKey, array $metricExplanations, $label = '')
{
    if (!isset($metricExplanations[$metricKey])) {
        return;
    }

    $payload = htmlspecialchars(json_encode($metricExplanations[$metricKey], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
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
        <span class="fa-solid fa-xmark close-form" onClick="closeMetricExplanationModal()"></span>
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
      <div class="buttons">
        <button type="button" class="secondary-button thin" onClick="closeMetricExplanationModal()">
          <?= translate('close', $i18n) ?>
        </button>
      </div>
    </section>
    <?php
}
