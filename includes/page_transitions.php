<?php

function wallos_get_page_transition_labels($lang)
{
    $isChinese = in_array($lang, ['zh_cn', 'zh_tw'], true);

    return [
        'kicker' => 'WALLOS // REMASTERED',
        'title' => '',
        'subtitle' => $isChinese ? '正在载入下一页，请稍候' : 'Loading the next scene',
        'status' => $isChinese ? '初始化动态场景' : 'Initializing dynamic scene',
        'accent' => $isChinese ? '视觉同步 / 背景演算 / 页面切幕' : 'Visual Sync / Background Render / Scene Wipe',
    ];
}

function wallos_get_page_transition_setting_labels($lang)
{
    $isChinese = in_array($lang, ['zh_cn', 'zh_tw'], true);

    if ($isChinese) {
        return [
            'section_title' => '页面切换动画',
            'enable_label' => '启用页面切换动画',
            'enable_info' => '进入页面和点击站内跳转时使用带过场的切幕动画。关闭后恢复普通即时切页。',
        ];
    }

    return [
        'section_title' => 'Page Transition Animation',
        'enable_label' => 'Enable page transition animation',
        'enable_info' => 'Use a cinematic transition overlay for page entry and internal navigation. Disable it to return to instant page switches.',
    ];
}

function wallos_resolve_page_transition_title($page, $i18n)
{
    $map = [
        'index.php' => translate('dashboard', $i18n),
        'subscriptions.php' => translate('subscriptions', $i18n),
        'calendar.php' => translate('calendar', $i18n),
        'stats.php' => translate('stats', $i18n),
        'settings.php' => translate('settings', $i18n),
        'profile.php' => translate('profile', $i18n),
        'admin.php' => translate('admin', $i18n),
        'about.php' => translate('about', $i18n),
    ];

    return $map[$page] ?? 'Wallos';
}

function wallos_render_page_transition_overlay($lang, $pageTitle)
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
            <h2 class="wallos-page-transition-title" id="wallos-page-transition-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h2>
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
