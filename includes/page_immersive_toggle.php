<?php

function wallos_get_page_immersive_toggle_labels($lang)
{
    if ($lang === 'zh_cn' || $lang === 'zh_tw') {
        return [
            'hide' => '隐藏界面',
            'show' => '显示界面',
        ];
    }

    return [
        'hide' => 'Hide UI',
        'show' => 'Show UI',
    ];
}

function wallos_render_page_immersive_toggle($lang)
{
    $labels = wallos_get_page_immersive_toggle_labels($lang);
    ?>
    <button
        type="button"
        class="button secondary-button thin wallos-page-immersive-toggle"
        data-page-immersive-toggle="true"
        data-hide-label="<?= htmlspecialchars($labels['hide'], ENT_QUOTES, 'UTF-8') ?>"
        data-show-label="<?= htmlspecialchars($labels['show'], ENT_QUOTES, 'UTF-8') ?>"
        aria-pressed="false"
        aria-label="<?= htmlspecialchars($labels['hide'], ENT_QUOTES, 'UTF-8') ?>"
        title="<?= htmlspecialchars($labels['hide'], ENT_QUOTES, 'UTF-8') ?>">
        <i class="fa-solid fa-eye-slash" aria-hidden="true"></i>
        <span><?= htmlspecialchars($labels['hide'], ENT_QUOTES, 'UTF-8') ?></span>
    </button>
    <?php
}
