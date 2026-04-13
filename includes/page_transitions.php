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
        'bluearchive_kicker' => 'SCHALE // BLUE ARCHIVE',
        'bluearchive_system' => $isChinese ? '档案终端接入中' : 'Archive terminal handshake',
        'bluearchive_channel' => $isChinese ? '通信链路 / 战术界面 / 学园终端' : 'Link / Tactical UI / Academy Terminal',
    ];
}

function wallos_get_page_transition_setting_labels($lang)
{
    $isChinese = in_array($lang, ['zh_cn', 'zh_tw'], true);

    if ($isChinese) {
        return [
            'section_title' => '页面切换动画',
            'enable_label' => '启用页面切换动画',
            'enable_info' => '进入页面和点击站内跳转时使用带过场的切页动画。关闭后恢复普通即时切页。',
            'style_label' => '动画方案',
            'style_info' => '切幕为当前通用方案；蔚蓝档案会使用独立的浅蓝白 HUD 风格，忽视其他动画风格设定。',
            'styles' => [
                'shutter' => [
                    'name' => '切幕',
                    'description' => '多层斜切门板与光束掠过，偏舞台式换场',
                ],
                'bluearchive' => [
                    'name' => '蔚蓝档案',
                    'description' => '浅蓝白 HUD 面板、档案终端与学园战术界面感',
                ],
            ],
        ];
    }

    return [
        'section_title' => 'Page Transition Animation',
        'enable_label' => 'Enable page transition animation',
        'enable_info' => 'Use a cinematic transition overlay for page entry and internal navigation. Disable it to return to instant page switches.',
        'style_label' => 'Animation Style',
        'style_info' => 'Shutter keeps the current generic scene-wipe. Blue Archive uses its own light blue tactical HUD language and ignores other transition style settings.',
        'styles' => [
            'shutter' => [
                'name' => 'Shutter',
                'description' => 'Layered diagonal gates and a sweeping light beam',
            ],
            'bluearchive' => [
                'name' => 'Blue Archive',
                'description' => 'Light blue HUD panels with an academy terminal feel',
            ],
        ],
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

        <div class="wallos-page-transition-bluearchive-layer" aria-hidden="true">
            <div class="wallos-page-transition-ba-panel wallos-page-transition-ba-panel-left"></div>
            <div class="wallos-page-transition-ba-panel wallos-page-transition-ba-panel-right"></div>
            <div class="wallos-page-transition-ba-header-bar"></div>
            <div class="wallos-page-transition-ba-footer-bar"></div>
            <div class="wallos-page-transition-ba-gridline wallos-page-transition-ba-gridline-a"></div>
            <div class="wallos-page-transition-ba-gridline wallos-page-transition-ba-gridline-b"></div>
            <div class="wallos-page-transition-ba-hud-card wallos-page-transition-ba-hud-card-left">
                <span class="wallos-page-transition-ba-label"><?= htmlspecialchars($labels['bluearchive_system'], ENT_QUOTES, 'UTF-8') ?></span>
                <strong>01</strong>
            </div>
            <div class="wallos-page-transition-ba-hud-card wallos-page-transition-ba-hud-card-right">
                <span class="wallos-page-transition-ba-label"><?= htmlspecialchars($labels['bluearchive_channel'], ENT_QUOTES, 'UTF-8') ?></span>
                <strong>UI</strong>
            </div>
            <div class="wallos-page-transition-ba-crosshair">
                <span></span>
                <span></span>
                <span></span>
            </div>
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
