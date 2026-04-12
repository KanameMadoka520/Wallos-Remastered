<?php

function wallos_is_public_decorative_background_enabled()
{
    return !isset($_COOKIE['decorativeBackground']) || $_COOKIE['decorativeBackground'] !== '0';
}

function wallos_get_decorative_background_tokens()
{
    return [
        ['type' => 'text', 'content' => '订阅', 'x' => '9%', 'y' => '14%', 'rotate' => '-10deg', 'scale' => '1.12', 'delay' => '0s'],
        ['type' => 'text', 'content' => '账单', 'x' => '18%', 'y' => '68%', 'rotate' => '6deg', 'scale' => '1.04', 'delay' => '-5s'],
        ['type' => 'text', 'content' => '月付', 'x' => '84%', 'y' => '18%', 'rotate' => '8deg', 'scale' => '0.98', 'delay' => '-7s'],
        ['type' => 'text', 'content' => '年付', 'x' => '72%', 'y' => '72%', 'rotate' => '-8deg', 'scale' => '1.08', 'delay' => '-12s'],
        ['type' => 'text', 'content' => 'const', 'x' => '33%', 'y' => '16%', 'rotate' => '-7deg', 'scale' => '0.92', 'delay' => '-3s'],
        ['type' => 'text', 'content' => 'TPS:20.0', 'x' => '6%', 'y' => '28%', 'rotate' => '8deg', 'scale' => '0.86', 'delay' => '-4s'],
        ['type' => 'text', 'content' => '{ }', 'x' => '58%', 'y' => '12%', 'rotate' => '4deg', 'scale' => '1.2', 'delay' => '-9s'],
        ['type' => 'text', 'content' => 'UTF-8', 'x' => '41%', 'y' => '82%', 'rotate' => '-3deg', 'scale' => '0.96', 'delay' => '-11s'],
        ['type' => 'text', 'content' => '127.0.0.1', 'x' => '56%', 'y' => '22%', 'rotate' => '-5deg', 'scale' => '0.86', 'delay' => '-6s'],
        ['type' => 'text', 'content' => '0x2A', 'x' => '62%', 'y' => '58%', 'rotate' => '7deg', 'scale' => '0.94', 'delay' => '-13s'],
        ['type' => 'text', 'content' => 'async', 'x' => '11%', 'y' => '44%', 'rotate' => '8deg', 'scale' => '0.88', 'delay' => '-6s'],
        ['type' => 'text', 'content' => 'DNS', 'x' => '36%', 'y' => '32%', 'rotate' => '9deg', 'scale' => '0.84', 'delay' => '-2s'],
        ['type' => 'text', 'content' => '¥', 'x' => '90%', 'y' => '56%', 'rotate' => '-11deg', 'scale' => '1.3', 'delay' => '-10s'],
        ['type' => 'text', 'content' => '$', 'x' => '79%', 'y' => '36%', 'rotate' => '11deg', 'scale' => '1.18', 'delay' => '-4s'],
        ['type' => 'text', 'content' => 'class', 'x' => '28%', 'y' => '52%', 'rotate' => '-6deg', 'scale' => '0.9', 'delay' => '-8s'],
        ['type' => 'text', 'content' => 'CDN', 'x' => '86%', 'y' => '66%', 'rotate' => '4deg', 'scale' => '0.82', 'delay' => '-9s'],
        ['type' => 'icon', 'icon' => 'fa-brands fa-cc-visa', 'x' => '76%', 'y' => '10%', 'rotate' => '-8deg', 'scale' => '1.15', 'delay' => '-2s'],
        ['type' => 'icon', 'icon' => 'fa-brands fa-cc-mastercard', 'x' => '87%', 'y' => '82%', 'rotate' => '7deg', 'scale' => '1.08', 'delay' => '-14s'],
        ['type' => 'icon', 'icon' => 'fa-brands fa-paypal', 'x' => '54%', 'y' => '26%', 'rotate' => '-9deg', 'scale' => '1.02', 'delay' => '-1s'],
        ['type' => 'icon', 'icon' => 'fa-brands fa-alipay', 'x' => '23%', 'y' => '84%', 'rotate' => '9deg', 'scale' => '1.1', 'delay' => '-15s'],
        ['type' => 'icon', 'icon' => 'fa-brands fa-weixin', 'x' => '67%', 'y' => '86%', 'rotate' => '-7deg', 'scale' => '1.08', 'delay' => '-16s'],
        ['type' => 'icon', 'icon' => 'fa-brands fa-bitcoin', 'x' => '6%', 'y' => '82%', 'rotate' => '6deg', 'scale' => '1.04', 'delay' => '-17s'],
        ['type' => 'icon', 'icon' => 'fa-brands fa-apple-pay', 'x' => '47%', 'y' => '70%', 'rotate' => '-6deg', 'scale' => '1.06', 'delay' => '-18s'],
    ];
}

function wallos_render_decorative_background($variant = 'app')
{
    $variantClass = $variant === 'public' ? 'is-public' : 'is-app';
    $tokens = wallos_get_decorative_background_tokens();
    ?>
    <div class="wallos-decorative-background <?= $variantClass ?>" aria-hidden="true">
        <canvas class="wallos-bg-flow"></canvas>
        <div class="wallos-bg-grid"></div>
        <div class="wallos-bg-noise"></div>
        <div class="wallos-bg-glow wallos-bg-glow-a"></div>
        <div class="wallos-bg-glow wallos-bg-glow-b"></div>
        <div class="wallos-bg-glow wallos-bg-glow-c"></div>
        <div class="wallos-bg-orbit wallos-bg-orbit-a"></div>
        <div class="wallos-bg-orbit wallos-bg-orbit-b"></div>
        <div class="wallos-bg-orbit wallos-bg-orbit-c"></div>
        <div class="wallos-bg-plate wallos-bg-plate-a"></div>
        <div class="wallos-bg-plate wallos-bg-plate-b"></div>
        <div class="wallos-bg-plate wallos-bg-plate-c"></div>
        <div class="wallos-bg-float-layer"></div>
        <div class="wallos-bg-meteor-layer"></div>
        <div class="wallos-bg-token-layer">
            <?php foreach ($tokens as $token): ?>
                <span class="wallos-bg-token <?= $token['type'] === 'icon' ? 'is-icon' : 'is-text' ?>"
                    style="--x: <?= htmlspecialchars($token['x'], ENT_QUOTES, 'UTF-8') ?>; --y: <?= htmlspecialchars($token['y'], ENT_QUOTES, 'UTF-8') ?>; --rotate: <?= htmlspecialchars($token['rotate'], ENT_QUOTES, 'UTF-8') ?>; --scale: <?= htmlspecialchars($token['scale'], ENT_QUOTES, 'UTF-8') ?>; --delay: <?= htmlspecialchars($token['delay'], ENT_QUOTES, 'UTF-8') ?>;">
                    <?php if ($token['type'] === 'icon'): ?>
                        <i class="<?= htmlspecialchars($token['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                    <?php else: ?>
                        <?= htmlspecialchars($token['content'], ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}
