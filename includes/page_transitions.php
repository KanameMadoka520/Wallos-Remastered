<?php

function wallos_get_page_transition_labels($lang)
{
    $isChinese = in_array($lang, ['zh_cn', 'zh_tw'], true);

    return [
        'kicker' => 'WALLOS // REMASTERED',
        'title' => $isChinese ? '界面切换中' : 'Scene Transition',
        'subtitle' => $isChinese ? '正在载入下一页，请稍候' : 'Loading the next scene',
        'status' => $isChinese ? '初始化动态场景' : 'Initializing dynamic scene',
        'accent' => $isChinese ? '视觉同步 / 背景演算 / 页面切幕' : 'Visual Sync / Background Render / Scene Wipe',
    ];
}

function wallos_render_page_transition_overlay($lang)
{
    $labels = wallos_get_page_transition_labels($lang);
    ?>
    <div class="wallos-page-transition" id="wallos-page-transition" aria-hidden="true">
        <div class="wallos-page-transition-backdrop"></div>
        <div class="wallos-page-transition-grid"></div>
        <div class="wallos-page-transition-panel wallos-page-transition-panel-left"></div>
        <div class="wallos-page-transition-panel wallos-page-transition-panel-right"></div>
        <div class="wallos-page-transition-panel wallos-page-transition-panel-top"></div>
        <div class="wallos-page-transition-panel wallos-page-transition-panel-bottom"></div>
        <div class="wallos-page-transition-beam"></div>
        <div class="wallos-page-transition-rings" aria-hidden="true">
            <span></span>
            <span></span>
            <span></span>
        </div>
        <div class="wallos-page-transition-center">
            <p class="wallos-page-transition-kicker"><?= htmlspecialchars($labels['kicker'], ENT_QUOTES, 'UTF-8') ?></p>
            <h2 class="wallos-page-transition-title" id="wallos-page-transition-title"><?= htmlspecialchars($labels['title'], ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="wallos-page-transition-subtitle"><?= htmlspecialchars($labels['subtitle'], ENT_QUOTES, 'UTF-8') ?></p>
            <div class="wallos-page-transition-progress" aria-hidden="true">
                <span></span>
            </div>
            <p class="wallos-page-transition-status"><?= htmlspecialchars($labels['status'], ENT_QUOTES, 'UTF-8') ?></p>
            <p class="wallos-page-transition-accent"><?= htmlspecialchars($labels['accent'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>
    <?php
}
