<?php

function wallos_is_public_decorative_background_enabled()
{
    return !isset($_COOKIE['decorativeBackground']) || $_COOKIE['decorativeBackground'] !== '0';
}

function wallos_svg_path_from_points(array $points, $close = false)
{
    if (empty($points)) {
        return '';
    }

    $commands = [];
    foreach ($points as $index => $point) {
        $x = number_format((float) $point[0], 2, '.', '');
        $y = number_format((float) $point[1], 2, '.', '');
        $commands[] = ($index === 0 ? 'M' : 'L') . $x . ' ' . $y;
    }

    if ($close) {
        $commands[] = 'Z';
    }

    return implode(' ', $commands);
}

function wallos_generate_parametric_path($steps, callable $generator, $close = false)
{
    $points = [];
    $steps = max(24, (int) $steps);
    for ($index = 0; $index <= $steps; $index++) {
        $t = $index / $steps;
        $points[] = $generator($t);
    }

    return wallos_svg_path_from_points($points, $close);
}

function wallos_generate_rose_curve_path($cx, $cy, $radius, $petals, $scaleY = 1.0, $phase = 0.0, $steps = 520)
{
    return wallos_generate_parametric_path($steps, function ($t) use ($cx, $cy, $radius, $petals, $scaleY, $phase) {
        $theta = $t * M_PI * 2;
        $r = $radius * cos(($petals * $theta) + $phase);

        return [
            $cx + ($r * cos($theta)),
            $cy + (($r * sin($theta)) * $scaleY),
        ];
    }, true);
}

function wallos_generate_hypotrochoid_path($cx, $cy, $R, $r, $d, $turns = 10, $scaleX = 1.0, $scaleY = 1.0, $steps = 900)
{
    return wallos_generate_parametric_path($steps, function ($t) use ($cx, $cy, $R, $r, $d, $turns, $scaleX, $scaleY) {
        $theta = $t * M_PI * 2 * $turns;
        $ratio = ($R - $r) / $r;
        $x = (($R - $r) * cos($theta)) + ($d * cos($ratio * $theta));
        $y = (($R - $r) * sin($theta)) - ($d * sin($ratio * $theta));

        return [
            $cx + ($x * $scaleX),
            $cy + ($y * $scaleY),
        ];
    }, true);
}

function wallos_generate_spiral_path($cx, $cy, $startRadius, $endRadius, $turns = 5, $scaleX = 1.0, $scaleY = 1.0, $steps = 520)
{
    return wallos_generate_parametric_path($steps, function ($t) use ($cx, $cy, $startRadius, $endRadius, $turns, $scaleX, $scaleY) {
        $theta = $t * M_PI * 2 * $turns;
        $radius = $startRadius + (($endRadius - $startRadius) * $t);

        return [
            $cx + (cos($theta) * $radius * $scaleX),
            $cy + (sin($theta) * $radius * $scaleY),
        ];
    }, false);
}

function wallos_get_decorative_background_tokens()
{
    return [
        ['type' => 'text', 'content' => '订阅', 'x' => '12%', 'y' => '18%', 'rotate' => '-8deg', 'scale' => '1.0', 'delay' => '0s'],
        ['type' => 'text', 'content' => '月付', 'x' => '82%', 'y' => '22%', 'rotate' => '7deg', 'scale' => '0.92', 'delay' => '-4s'],
        ['type' => 'text', 'content' => 'UTF-8', 'x' => '42%', 'y' => '80%', 'rotate' => '-2deg', 'scale' => '0.88', 'delay' => '-6s'],
        ['type' => 'text', 'content' => 'DNS', 'x' => '33%', 'y' => '30%', 'rotate' => '8deg', 'scale' => '0.8', 'delay' => '-2s'],
        ['type' => 'text', 'content' => '¥', 'x' => '88%', 'y' => '58%', 'rotate' => '-9deg', 'scale' => '1.12', 'delay' => '-10s'],
        ['type' => 'text', 'content' => '0x2A', 'x' => '64%', 'y' => '60%', 'rotate' => '5deg', 'scale' => '0.84', 'delay' => '-8s'],
        ['type' => 'icon', 'icon' => 'fa-brands fa-cc-visa', 'x' => '76%', 'y' => '12%', 'rotate' => '-8deg', 'scale' => '1.0', 'delay' => '-3s'],
        ['type' => 'icon', 'icon' => 'fa-brands fa-paypal', 'x' => '53%', 'y' => '24%', 'rotate' => '-6deg', 'scale' => '0.92', 'delay' => '-5s'],
        ['type' => 'icon', 'icon' => 'fa-brands fa-alipay', 'x' => '22%', 'y' => '84%', 'rotate' => '8deg', 'scale' => '0.94', 'delay' => '-11s'],
        ['type' => 'icon', 'icon' => 'fa-brands fa-apple-pay', 'x' => '48%', 'y' => '72%', 'rotate' => '-4deg', 'scale' => '0.9', 'delay' => '-14s'],
    ];
}

function wallos_render_decorative_background($variant = 'app')
{
    $variantClass = $variant === 'public' ? 'is-public' : 'is-app';
    $tokens = wallos_get_decorative_background_tokens();

    $mathCurvePrimary = wallos_generate_hypotrochoid_path(200, 180, 66, 18, 28, 8, 1.22, 0.82, 860);
    $mathCurveSecondary = wallos_generate_rose_curve_path(200, 180, 96, 5, 0.72, 0.0, 460);
    $blackHoleSpiralA = wallos_generate_spiral_path(180, 180, 10, 92, 4.6, 1.28, 0.72, 420);
    $blackHoleSpiralB = wallos_generate_spiral_path(180, 180, 22, 106, 4.1, 1.15, 0.64, 420);
    ?>
    <div class="wallos-decorative-background <?= $variantClass ?>" aria-hidden="true">
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

        <svg class="wallos-bg-scene wallos-bg-scene-math" viewBox="0 0 400 360" aria-hidden="true">
            <g class="wallos-bg-scene-drift">
                <path d="<?= htmlspecialchars($mathCurvePrimary, ENT_QUOTES, 'UTF-8') ?>" class="wallos-bg-path wallos-bg-path-primary" />
                <path d="<?= htmlspecialchars($mathCurveSecondary, ENT_QUOTES, 'UTF-8') ?>" class="wallos-bg-path wallos-bg-path-secondary" />
                <circle cx="200" cy="180" r="4.5" class="wallos-bg-anchor-dot" />
            </g>
        </svg>

        <svg class="wallos-bg-scene wallos-bg-scene-orbit" viewBox="0 0 420 280" aria-hidden="true">
            <defs>
                <radialGradient id="wallosOrbitStar" cx="50%" cy="50%" r="50%">
                    <stop offset="0%" stop-color="rgba(255,255,255,0.96)" />
                    <stop offset="30%" stop-color="rgba(201,74,42,0.42)" />
                    <stop offset="100%" stop-color="rgba(201,74,42,0)" />
                </radialGradient>
            </defs>
            <g class="wallos-bg-orbit-scene-core">
                <ellipse cx="210" cy="140" rx="64" ry="22" class="wallos-bg-orbit-track wallos-bg-orbit-track-a" />
                <ellipse cx="210" cy="140" rx="94" ry="34" class="wallos-bg-orbit-track wallos-bg-orbit-track-b" />
                <ellipse cx="210" cy="140" rx="126" ry="48" class="wallos-bg-orbit-track wallos-bg-orbit-track-c" />
                <circle cx="210" cy="140" r="34" class="wallos-bg-orbit-star-halo" />
                <circle cx="210" cy="140" r="7" class="wallos-bg-orbit-star-core" />
                <g class="wallos-bg-orbit-planet wallos-bg-orbit-planet-a">
                    <circle cx="274" cy="140" r="5.5" class="wallos-bg-orbit-body wallos-bg-orbit-body-a" />
                </g>
                <g class="wallos-bg-orbit-planet wallos-bg-orbit-planet-b">
                    <circle cx="304" cy="140" r="4.2" class="wallos-bg-orbit-body wallos-bg-orbit-body-b" />
                    <circle cx="317" cy="140" r="1.5" class="wallos-bg-orbit-moon" />
                </g>
                <g class="wallos-bg-orbit-planet wallos-bg-orbit-planet-c">
                    <circle cx="336" cy="140" r="3.8" class="wallos-bg-orbit-body wallos-bg-orbit-body-c" />
                </g>
            </g>
        </svg>

        <svg class="wallos-bg-scene wallos-bg-scene-blackhole" viewBox="0 0 360 360" aria-hidden="true">
            <g class="wallos-bg-blackhole-scene">
                <ellipse cx="180" cy="180" rx="112" ry="42" class="wallos-bg-blackhole-disk wallos-bg-blackhole-disk-outer" />
                <ellipse cx="180" cy="180" rx="88" ry="30" class="wallos-bg-blackhole-disk wallos-bg-blackhole-disk-inner" />
                <path d="<?= htmlspecialchars($blackHoleSpiralA, ENT_QUOTES, 'UTF-8') ?>" class="wallos-bg-blackhole-spiral wallos-bg-blackhole-spiral-a" />
                <path d="<?= htmlspecialchars($blackHoleSpiralB, ENT_QUOTES, 'UTF-8') ?>" class="wallos-bg-blackhole-spiral wallos-bg-blackhole-spiral-b" />
                <circle cx="180" cy="180" r="24" class="wallos-bg-blackhole-core" />
                <circle cx="180" cy="180" r="38" class="wallos-bg-blackhole-ring" />
            </g>
        </svg>

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
