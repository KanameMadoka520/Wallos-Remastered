<?php

/**
 * Presentation-only helpers for the screenshot privacy mode.
 *
 * These functions never write subscription data. Callers pass a display row or
 * payload and receive a copy containing synthetic values. The real row remains
 * available for calculations, sorting and write operations.
 */

const WALLOS_SCREENSHOT_PRIVACY_SESSION_KEY = 'wallos_screenshot_privacy_seed';

function wallos_screenshot_privacy_enabled(array $settings)
{
    $value = $settings['screenshotPrivacyMode']
        ?? $settings['screenshot_privacy_mode']
        ?? false;

    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return (int) $value === 1;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

function wallos_screenshot_privacy_session_seed()
{
    static $requestFallbackSeed = null;

    if (isset($_SESSION)
        && is_array($_SESSION)
        && isset($_SESSION[WALLOS_SCREENSHOT_PRIVACY_SESSION_KEY])
    ) {
        $storedSeed = trim((string) $_SESSION[WALLOS_SCREENSHOT_PRIVACY_SESSION_KEY]);
        if (preg_match('/^[a-f0-9]{64}$/i', $storedSeed) === 1) {
            return strtolower($storedSeed);
        }
    }

    try {
        $seed = bin2hex(random_bytes(32));
    } catch (Throwable $throwable) {
        $seed = hash('sha256', uniqid('wallos-screenshot-privacy-', true) . '|' . microtime(true));
    }

    if (isset($_SESSION) && is_array($_SESSION)) {
        $_SESSION[WALLOS_SCREENSHOT_PRIVACY_SESSION_KEY] = $seed;
    } else {
        if ($requestFallbackSeed === null) {
            $requestFallbackSeed = $seed;
        }
        $seed = $requestFallbackSeed;
    }

    return $seed;
}

function wallos_screenshot_privacy_seed()
{
    return wallos_screenshot_privacy_session_seed();
}

function wallos_screenshot_privacy_current_user_id()
{
    return isset($_SESSION) && is_array($_SESSION)
        ? (int) ($_SESSION['userId'] ?? 0)
        : 0;
}

function wallos_screenshot_privacy_resolve_seed($seed = null)
{
    $seed = trim((string) $seed);
    return $seed !== '' ? $seed : wallos_screenshot_privacy_session_seed();
}

function wallos_screenshot_privacy_hash($scope, $identity, $userId = 0, $seed = null)
{
    $message = implode('|', [
        'wallos-screenshot-privacy-v1',
        (string) (int) $userId,
        trim((string) $scope),
        trim((string) $identity),
    ]);

    return hash_hmac('sha256', $message, wallos_screenshot_privacy_resolve_seed($seed));
}

function wallos_screenshot_privacy_hash_number($scope, $identity, $userId = 0, $seed = null)
{
    $hash = wallos_screenshot_privacy_hash($scope, $identity, $userId, $seed);
    return (int) hexdec(substr($hash, 0, 7));
}

function wallos_screenshot_privacy_language_family($lang)
{
    $lang = strtolower(str_replace('-', '_', trim((string) $lang)));
    if ($lang === 'zh_cn' || strpos($lang, 'zh_cn') === 0 || $lang === 'zh') {
        return 'zh_cn';
    }
    if ($lang === 'zh_tw' || strpos($lang, 'zh_tw') === 0 || strpos($lang, 'zh_hant') === 0) {
        return 'zh_tw';
    }

    return 'en';
}

function wallos_screenshot_privacy_fake_name_internal($identity, $lang = 'en', $userId = 0, $seed = null)
{
    $family = wallos_screenshot_privacy_language_family($lang);
    $pools = [
        'en' => [
            'Aurora Cloud', 'Bluebird Studio', 'Cedar Notes', 'Comet Music',
            'Harbor Plus', 'Juniper Media', 'Lighthouse Tools', 'Maple Play',
            'Nimbus Library', 'Orbit Workspace', 'Pebble Box', 'Silverline Service',
        ],
        'zh_cn' => [
            '极光云端', '蓝鸟工坊', '雪松笔记', '彗星音乐',
            '港湾会员', '杜松影音', '灯塔工具箱', '枫叶乐园',
            '云层书库', '星轨空间', '卵石盒子', '银线服务',
        ],
        'zh_tw' => [
            '極光雲端', '藍鳥工坊', '雪松筆記', '彗星音樂',
            '港灣會員', '杜松影音', '燈塔工具箱', '楓葉樂園',
            '雲層書庫', '星軌空間', '卵石盒子', '銀線服務',
        ],
    ];

    $hash = wallos_screenshot_privacy_hash('name', $identity, $userId, $seed);
    $pool = $pools[$family];
    $nameIndex = (int) hexdec(substr($hash, 0, 4)) % count($pool);
    $suffix = strtoupper(substr($hash, 4, 3));

    return $pool[$nameIndex] . ' ' . $suffix;
}

function wallos_screenshot_privacy_fake_name($id, $seed = null, $lang = 'en')
{
    return wallos_screenshot_privacy_fake_name_internal(
        $id,
        $lang,
        wallos_screenshot_privacy_current_user_id(),
        $seed
    );
}

function wallos_screenshot_privacy_fake_description($identity, $lang = 'en', $userId = 0, $seed = null)
{
    $family = wallos_screenshot_privacy_language_family($lang);
    $pools = [
        'en' => [
            'Demo description for a private subscription screenshot.',
            'Sample plan with fictional benefits and placeholder details.',
            'Masked service notes; the original description is unchanged.',
            'Illustrative membership information generated for sharing.',
        ],
        'zh_cn' => [
            '用于截图展示的虚构订阅说明。',
            '这是一段包含模拟权益的示例描述。',
            '真实备注已隐藏，原始内容没有被修改。',
            '用于分享页面效果的演示会员信息。',
        ],
        'zh_tw' => [
            '用於截圖展示的虛構訂閱說明。',
            '這是一段包含模擬權益的範例描述。',
            '真實備註已隱藏，原始內容沒有被修改。',
            '用於分享頁面效果的展示會員資訊。',
        ],
    ];

    $pool = $pools[$family];
    $index = wallos_screenshot_privacy_hash_number('description', $identity, $userId, $seed) % count($pool);
    return $pool[$index];
}

function wallos_screenshot_privacy_fake_price($identity, $context = 'subscription-price', $userId = 0, $seed = null)
{
    $hash = wallos_screenshot_privacy_hash((string) $context, $identity, $userId, $seed);
    $whole = 4 + ((int) hexdec(substr($hash, 0, 6)) % 196);
    $centOptions = [0, 29, 49, 79, 88, 90, 99];
    $cents = $centOptions[(int) hexdec(substr($hash, 6, 2)) % count($centOptions)];

    return round($whole + ($cents / 100), 2);
}

function wallos_screenshot_privacy_fake_amount($key, $seed = null)
{
    return wallos_screenshot_privacy_fake_price(
        $key,
        'display-amount',
        wallos_screenshot_privacy_current_user_id(),
        $seed
    );
}

function wallos_screenshot_privacy_fake_icon_data_uri($identity, $userId = 0, $seed = null)
{
    $hash = wallos_screenshot_privacy_hash('icon', $identity, $userId, $seed);
    $colors = [
        ['#5B8CFF', '#7967FF'],
        ['#25B99A', '#49D17D'],
        ['#FF7A8A', '#FFAA62'],
        ['#A868F1', '#DF6DD5'],
        ['#24A6D9', '#55D5E0'],
        ['#F0A93B', '#F16B5C'],
    ];
    $paths = [
        '<path d="M22 45 45 22 68 45 45 68Z"/>',
        '<path d="M25 60V30h14l6 9 6-9h14v30H51V46l-6 8-6-8v14Z"/>',
        '<path d="M24 48c0-12 9-22 21-22s21 10 21 22-9 18-21 18-21-6-21-18Zm13-3h16M45 37v16"/>',
        '<path d="M28 28h34v34H28Zm8 8h7v7h-7Zm11 0h7v7h-7Zm-11 11h7v7h-7Zm11 0h7v7h-7Z"/>',
        '<path d="m31 63 4-37 30 18-34 19Z"/>',
        '<path d="M24 55c8-20 14-28 21-28 7 0 13 8 21 28-7 7-14 10-21 10s-14-3-21-10Z"/>',
    ];
    $colorIndex = (int) hexdec(substr($hash, 0, 2)) % count($colors);
    $pathIndex = (int) hexdec(substr($hash, 2, 2)) % count($paths);
    [$startColor, $endColor] = $colors[$colorIndex];
    $gradientId = 'g' . substr($hash, 4, 6);

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 90 90">'
        . '<defs><linearGradient id="' . $gradientId . '" x1="0" y1="0" x2="1" y2="1">'
        . '<stop stop-color="' . $startColor . '"/><stop offset="1" stop-color="' . $endColor . '"/>'
        . '</linearGradient></defs>'
        . '<rect width="90" height="90" rx="22" fill="url(#' . $gradientId . ')"/>'
        . '<g fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round">'
        . $paths[$pathIndex]
        . '</g></svg>';

    return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($svg);
}

function wallos_screenshot_privacy_fake_icon($key, $seed = null)
{
    return wallos_screenshot_privacy_fake_icon_data_uri(
        $key,
        wallos_screenshot_privacy_current_user_id(),
        $seed
    );
}

function wallos_screenshot_privacy_identity(array $value, $fallback)
{
    foreach (['subscription_id', 'id', 'record_id'] as $key) {
        if (isset($value[$key]) && trim((string) $value[$key]) !== '') {
            return $key . ':' . trim((string) $value[$key]);
        }
    }

    return (string) $fallback;
}

function wallos_screenshot_privacy_placeholder_image($identity, $lang, $userId, $seed)
{
    $icon = wallos_screenshot_privacy_fake_icon_data_uri($identity, $userId, $seed);
    $family = wallos_screenshot_privacy_language_family($lang);
    $name = $family === 'zh_cn'
        ? '演示图片.svg'
        : ($family === 'zh_tw' ? '展示圖片.svg' : 'demo-image.svg');

    return [
        'id' => 0,
        'file_name' => $name,
        'original_name' => $name,
        'access_url' => $icon,
        'thumbnail_url' => $icon,
        'preview_url' => $icon,
        'original_url' => $icon,
        'download_url' => '',
        'thumbnail_size_label' => '',
        'preview_size_label' => '',
        'original_size_label' => '',
        'preview_reused_original' => true,
        'thumbnail_reused_original' => true,
        'screenshot_privacy_placeholder' => true,
    ];
}

function wallos_screenshot_privacy_mask_subscription_internal(array $subscription, $seed, $lang, $context, $userId)
{
    $copy = $subscription;
    $identity = wallos_screenshot_privacy_identity($subscription, (string) $context);
    $seed = wallos_screenshot_privacy_resolve_seed($seed);
    $fakeName = wallos_screenshot_privacy_fake_name_internal($identity, $lang, $userId, $seed);
    $fakeDescription = wallos_screenshot_privacy_fake_description($identity, $lang, $userId, $seed);
    $fakeIcon = wallos_screenshot_privacy_fake_icon_data_uri($identity, $userId, $seed);

    $copy['name'] = $fakeName;
    if (array_key_exists('subscription_name', $copy)) {
        $copy['subscription_name'] = $fakeName;
    }
    if (array_key_exists('description', $copy)) {
        $copy['description'] = $fakeDescription;
    }
    $copy['notes'] = $fakeDescription;
    if (array_key_exists('notes_html', $copy)) {
        $copy['notes_html'] = htmlspecialchars($fakeDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    $copy['privacy_icon'] = $fakeIcon;
    $copy['logo'] = $fakeIcon;
    if (array_key_exists('logo_variant', $copy)) {
        $copy['logo_variant'] = '';
    }
    if (array_key_exists('logo_text_color', $copy)) {
        $copy['logo_text_color'] = null;
    }
    if (array_key_exists('payment_method_icon', $copy)) {
        $copy['payment_method_icon'] = $fakeIcon;
    }

    if (array_key_exists('price', $copy)) {
        $copy['price'] = wallos_screenshot_privacy_fake_price($identity, 'subscription-price', $userId, $seed);
    }
    if (array_key_exists('original_price', $copy)) {
        $copy['original_price'] = wallos_screenshot_privacy_fake_price($identity, 'subscription-original-price', $userId, $seed);
    }

    // Subscription cards also expose aggregate amounts such as invested totals
    // and manually tracked value. Keep those in the same presentation-only
    // copy so an expanded card cannot reveal a real amount beside a fake name.
    foreach ($copy as $amountKey => $amountValue) {
        if (in_array((string) $amountKey, ['price', 'original_price'], true)
            || !(is_int($amountValue) || is_float($amountValue) || (is_string($amountValue) && is_numeric($amountValue)))
            || !wallos_screenshot_privacy_is_sensitive_amount_key($amountKey)
        ) {
            continue;
        }

        $fakeAmount = wallos_screenshot_privacy_fake_price(
            $identity . '|' . (string) $amountKey,
            'subscription-amount',
            $userId,
            $seed
        );
        if (is_string($amountValue)) {
            $copy[$amountKey] = number_format($fakeAmount, 2, '.', '');
        } elseif (is_int($amountValue)) {
            $copy[$amountKey] = (int) round($fakeAmount);
        } else {
            $copy[$amountKey] = $fakeAmount;
        }
    }

    foreach (['url', 'detail_image_url', 'external_url'] as $urlKey) {
        if (array_key_exists($urlKey, $copy)) {
            $copy[$urlKey] = '';
        }
    }

    $hadDetailImages = !empty($subscription['uploaded_images'])
        || !empty($subscription['detail_image'])
        || (is_array($subscription['detail_image_urls'] ?? null) && !empty($subscription['detail_image_urls']))
        || (is_string($subscription['detail_image_urls'] ?? null)
            && trim((string) $subscription['detail_image_urls']) !== ''
            && trim((string) $subscription['detail_image_urls']) !== '[]');

    if (array_key_exists('detail_image', $copy)) {
        $copy['detail_image'] = $hadDetailImages ? $fakeIcon : '';
    }
    if (array_key_exists('detail_image_urls', $copy)) {
        $copy['detail_image_urls'] = is_array($copy['detail_image_urls']) ? [] : '[]';
    }
    if (array_key_exists('uploaded_images', $copy)) {
        $copy['uploaded_images'] = $hadDetailImages
            ? [wallos_screenshot_privacy_placeholder_image($identity, $lang, $userId, $seed)]
            : [];
    }

    foreach (['payment_records', 'price_rules', 'remaining_value', 'payment_total_original'] as $payloadKey) {
        if (isset($copy[$payloadKey]) && is_array($copy[$payloadKey])) {
            $copy[$payloadKey] = wallos_screenshot_privacy_mask_metric_payload_internal(
                $copy[$payloadKey],
                $lang,
                $userId,
                $identity . '.' . $payloadKey,
                $seed
            );
        }
    }

    if (array_key_exists('category', $copy)) {
        $copy['category'] = wallos_screenshot_privacy_fake_group_label('category', $identity, $lang, $userId, $seed);
    }
    if (array_key_exists('payer_user', $copy)) {
        $copy['payer_user'] = wallos_screenshot_privacy_fake_group_label('member', $identity, $lang, $userId, $seed);
    }
    if (array_key_exists('payment_method', $copy)) {
        $copy['payment_method'] = wallos_screenshot_privacy_fake_group_label('payment', $identity, $lang, $userId, $seed);
    }
    if (array_key_exists('payment_method_name', $copy)) {
        $copy['payment_method_name'] = wallos_screenshot_privacy_fake_group_label('payment', $identity, $lang, $userId, $seed);
    }

    $copy['screenshot_privacy_masked'] = true;
    return $copy;
}

function wallos_screenshot_privacy_mask_subscription(array $row, $seed, $lang, $context = 'display')
{
    return wallos_screenshot_privacy_mask_subscription_internal(
        $row,
        $seed,
        $lang,
        $context,
        wallos_screenshot_privacy_current_user_id()
    );
}

function wallos_screenshot_privacy_mask_subscriptions(array $subscriptions, $seed, $lang, $context = 'display')
{
    $masked = [];
    foreach ($subscriptions as $key => $subscription) {
        $masked[$key] = is_array($subscription)
            ? wallos_screenshot_privacy_mask_subscription(
                $subscription,
                $seed,
                $lang,
                $context . '.' . (string) $key
            )
            : $subscription;
    }

    return $masked;
}

function wallos_screenshot_privacy_fake_group_label($type, $identity, $lang = 'en', $userId = 0, $seed = null)
{
    $family = wallos_screenshot_privacy_language_family($lang);
    $labels = [
        'en' => [
            'category' => ['Demo Category', 'Sample Group', 'Example Services'],
            'member' => ['Demo Member', 'Example User', 'Sample Payer'],
            'payment' => ['Demo Wallet', 'Sample Card', 'Example Payment'],
        ],
        'zh_cn' => [
            'category' => ['演示分类', '示例分组', '虚构服务'],
            'member' => ['演示成员', '示例用户', '虚构付款人'],
            'payment' => ['演示钱包', '示例卡片', '虚构付款方式'],
        ],
        'zh_tw' => [
            'category' => ['展示分類', '範例分組', '虛構服務'],
            'member' => ['展示成員', '範例使用者', '虛構付款人'],
            'payment' => ['展示錢包', '範例卡片', '虛構付款方式'],
        ],
    ];
    $type = isset($labels[$family][$type]) ? $type : 'category';
    $pool = $labels[$family][$type];
    $index = wallos_screenshot_privacy_hash_number('group-' . $type, $identity, $userId, $seed) % count($pool);
    return $pool[$index];
}

function wallos_screenshot_privacy_is_sensitive_amount_key($key)
{
    $key = strtolower(trim((string) $key));
    if ($key === 'y') {
        return true;
    }
    if ($key === ''
        || preg_match('/(?:^|_)(?:id|count|days?|months?|years?|frequency|ratio|percent|percentage|available|timestamp)(?:_|$)/', $key)
    ) {
        return false;
    }

    return preg_match('/(?:^|_)(?:price|amount|cost|budget|paid|payment|saving|savings|value|total)(?:_|$)/', $key) === 1;
}

function wallos_screenshot_privacy_mask_metric_payload_internal(array $payload, $lang, $userId, $context, $seed)
{
    $seed = wallos_screenshot_privacy_resolve_seed($seed);
    $masked = $payload;
    $baseIdentity = wallos_screenshot_privacy_identity($payload, $context);

    foreach ($payload as $key => $value) {
        $path = $context . '.' . (string) $key;
        if (is_array($value)) {
            $masked[$key] = wallos_screenshot_privacy_mask_metric_payload_internal($value, $lang, $userId, $path, $seed);
            continue;
        }

        $normalizedKey = strtolower((string) $key);
        if (in_array($normalizedKey, ['name', 'subscription_name'], true)) {
            $masked[$key] = wallos_screenshot_privacy_fake_name_internal($baseIdentity, $lang, $userId, $seed);
            continue;
        }
        if (in_array($normalizedKey, ['note', 'notes', 'description'], true)) {
            $masked[$key] = wallos_screenshot_privacy_fake_description($baseIdentity . '|' . $path, $lang, $userId, $seed);
            continue;
        }
        if (in_array($normalizedKey, ['note_html', 'notes_html', 'description_html'], true)) {
            $description = wallos_screenshot_privacy_fake_description($baseIdentity . '|' . $path, $lang, $userId, $seed);
            $masked[$key] = htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            continue;
        }
        if (in_array($normalizedKey, ['rule_summary', 'rule_summary_current', 'value_source_summary', 'remaining_mode_summary'], true)) {
            $masked[$key] = wallos_screenshot_privacy_fake_description($baseIdentity . '|' . $path, $lang, $userId, $seed);
            continue;
        }
        if (preg_match('/(?:logo|image)(?:_url|_src|_path)?$/', $normalizedKey) === 1) {
            $masked[$key] = wallos_screenshot_privacy_fake_icon_data_uri($baseIdentity . '|' . $path, $userId, $seed);
            continue;
        }
        if ($normalizedKey === 'url' || substr($normalizedKey, -4) === '_url') {
            $masked[$key] = '';
            continue;
        }
        if ((is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))
            && wallos_screenshot_privacy_is_sensitive_amount_key($normalizedKey)
        ) {
            $fakeAmount = wallos_screenshot_privacy_fake_price($baseIdentity . '|' . $path, 'metric-amount', $userId, $seed);
            if (strpos($normalizedKey, 'difference') !== false && (float) $value < 0) {
                $fakeAmount *= -1;
            }
            if (is_string($value)) {
                $masked[$key] = number_format($fakeAmount, 2, '.', '');
            } elseif (is_int($value)) {
                $masked[$key] = (int) round($fakeAmount);
            } else {
                $masked[$key] = $fakeAmount;
            }
        }
    }

    return $masked;
}

function wallos_screenshot_privacy_mask_metric_payload(array $payload, $seed, $lang)
{
    return wallos_screenshot_privacy_mask_metric_payload_internal(
        $payload,
        $lang,
        wallos_screenshot_privacy_current_user_id(),
        'metric',
        $seed
    );
}
