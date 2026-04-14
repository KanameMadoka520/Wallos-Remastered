<?php

function wallos_get_public_entry_animation_copy($page, $lang, $i18n)
{
    $isChinese = in_array($lang, ['zh_cn', 'zh_tw'], true);
    $isRegistration = $page === 'registration';

    return [
        'kicker' => 'WALLOS // REMASTERED',
        'title' => $isRegistration ? translate('register', $i18n) : translate('login', $i18n),
        'subtitle' => $isChinese
            ? ($isRegistration ? '创建你的订阅中枢' : '连接你的订阅中枢')
            : ($isRegistration ? 'Create your subscription hub' : 'Connect to your subscription hub'),
        'status' => $isChinese
            ? ($isRegistration ? '初始化注册终端 / 偏好同步 / 视觉启动' : '初始化登录终端 / 安全校验 / 视觉启动')
            : ($isRegistration ? 'Registration terminal / Preference sync / Visual boot' : 'Login terminal / Secure auth / Visual boot'),
        'accent' => $isChinese
            ? ($isRegistration ? '账户建立 / 货币偏好 / 订阅管理' : '账户验证 / 主题偏好 / 订阅管理')
            : ($isRegistration ? 'Account creation / Currency defaults / Subscription control' : 'Account auth / Theme defaults / Subscription control'),
        'page_code' => $isRegistration ? 'CREATE-ACCOUNT' : 'LOGIN-ACCESS',
    ];
}

function wallos_render_public_entry_overlay($page, $lang, $i18n)
{
    $copy = wallos_get_public_entry_animation_copy($page, $lang, $i18n);
    ?>
    <div class="wallos-public-entry" id="wallos-public-entry" aria-hidden="true">
        <div class="wallos-public-entry-backdrop"></div>
        <div class="wallos-public-entry-grid"></div>
        <div class="wallos-public-entry-aura wallos-public-entry-aura-a"></div>
        <div class="wallos-public-entry-aura wallos-public-entry-aura-b"></div>
        <div class="wallos-public-entry-ring wallos-public-entry-ring-a"></div>
        <div class="wallos-public-entry-ring wallos-public-entry-ring-b"></div>
        <div class="wallos-public-entry-scanline"></div>
        <div class="wallos-public-entry-shards" aria-hidden="true">
            <span style="--translate-x:-32vw; --translate-y:-18vh; --rotate:-38deg; --delay:0.02s;"></span>
            <span style="--translate-x:30vw; --translate-y:-14vh; --rotate:32deg; --delay:0.12s;"></span>
            <span style="--translate-x:-24vw; --translate-y:22vh; --rotate:18deg; --delay:0.22s;"></span>
            <span style="--translate-x:28vw; --translate-y:24vh; --rotate:-24deg; --delay:0.32s;"></span>
            <span style="--translate-x:6vw; --translate-y:-28vh; --rotate:72deg; --delay:0.18s;"></span>
            <span style="--translate-x:-4vw; --translate-y:30vh; --rotate:-72deg; --delay:0.26s;"></span>
        </div>
        <div class="wallos-public-entry-corners" aria-hidden="true">
            <span class="wallos-public-entry-corner wallos-public-entry-corner-top-left"></span>
            <span class="wallos-public-entry-corner wallos-public-entry-corner-top-right"></span>
            <span class="wallos-public-entry-corner wallos-public-entry-corner-bottom-left"></span>
            <span class="wallos-public-entry-corner wallos-public-entry-corner-bottom-right"></span>
        </div>
        <div class="wallos-public-entry-stream wallos-public-entry-stream-left" aria-hidden="true">
            <span data-public-entry-token>CNY</span>
            <span data-public-entry-token>JPY</span>
            <span data-public-entry-token>USD</span>
            <span data-public-entry-token><i class="fa-brands fa-cc-visa"></i></span>
            <span data-public-entry-token><i class="fa-brands fa-cc-mastercard"></i></span>
            <span data-public-entry-token><i class="fa-brands fa-paypal"></i></span>
        </div>
        <div class="wallos-public-entry-stream wallos-public-entry-stream-right" aria-hidden="true">
            <span data-public-entry-token><?= htmlspecialchars($copy['page_code'], ENT_QUOTES, 'UTF-8') ?></span>
            <span data-public-entry-token>SYNC</span>
            <span data-public-entry-token>UI</span>
            <span data-public-entry-token><i class="fa-solid fa-bolt"></i></span>
            <span data-public-entry-token><i class="fa-solid fa-lock"></i></span>
            <span data-public-entry-token><i class="fa-solid fa-sparkles"></i></span>
        </div>
        <div class="wallos-public-entry-center">
            <span class="wallos-public-entry-kicker"><?= htmlspecialchars($copy['kicker'], ENT_QUOTES, 'UTF-8') ?></span>
            <strong class="wallos-public-entry-title"><?= htmlspecialchars($copy['title'], ENT_QUOTES, 'UTF-8') ?></strong>
            <span class="wallos-public-entry-subtitle"><?= htmlspecialchars($copy['subtitle'], ENT_QUOTES, 'UTF-8') ?></span>
            <div class="wallos-public-entry-progress" aria-hidden="true">
                <span></span>
            </div>
            <div class="wallos-public-entry-meta">
                <span><?= htmlspecialchars($copy['status'], ENT_QUOTES, 'UTF-8') ?></span>
                <span><?= htmlspecialchars($copy['accent'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
    </div>
    <?php
}
