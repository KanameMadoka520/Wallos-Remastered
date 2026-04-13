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

function wallos_get_page_transition_setting_labels($lang)
{
    $isChinese = in_array($lang, ['zh_cn', 'zh_tw'], true);

    if ($isChinese) {
        return [
            'section_title' => '页面切换动画',
            'enable_label' => '启用页面切换动画',
            'enable_info' => '进入页面和点击站内跳转时使用带过场的切幕动画。关闭后恢复普通即时切页。',
            'style_label' => '动画风格',
            'style_info' => '这些风格会同时适配桌面横屏与移动端。',
            'styles' => [
                'shutter' => ['name' => '切幕', 'description' => '多层斜切门板，偏二次元界面换场'],
                'nova' => ['name' => '星轨', 'description' => '环形脉冲与能量束，强调宇宙感'],
                'scanline' => ['name' => '扫描线', 'description' => '冷色 HUD 扫描与上下闸门'],
                'ribbon' => ['name' => '光带', 'description' => '高速掠影与长条光幕，节奏更利落'],
            ],
        ];
    }

    return [
        'section_title' => 'Page Transition Animation',
        'enable_label' => 'Enable page transition animation',
        'enable_info' => 'Use a cinematic transition overlay for page entry and internal navigation. Disable it to return to instant page switches.',
        'style_label' => 'Animation style',
        'style_info' => 'All styles are adapted for desktop landscape and mobile layouts.',
        'styles' => [
            'shutter' => ['name' => 'Shutter', 'description' => 'Layered diagonal gates with an anime UI feel'],
            'nova' => ['name' => 'Nova', 'description' => 'Orbital pulses and energy beams with a cosmic vibe'],
            'scanline' => ['name' => 'Scanline', 'description' => 'Cool-toned HUD scan with top and bottom shutters'],
            'ribbon' => ['name' => 'Ribbon', 'description' => 'Fast light ribbons and a cleaner, sharper pace'],
        ],
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
