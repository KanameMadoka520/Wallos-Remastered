<?php

function wallos_resolve_supported_language($language, array $supportedLanguages = ['en'])
{
    $fallback = in_array('en', $supportedLanguages, true) ? 'en' : $supportedLanguages[0];

    if (!is_string($language) || trim($language) === '') {
        return $fallback;
    }

    $normalized = strtolower(str_replace('-', '_', trim($language)));

    $aliases = [
        'zh' => 'zh_cn',
        'zh_cn' => 'zh_cn',
        'zh_hans' => 'zh_cn',
        'zh_sg' => 'zh_cn',
        'zh_my' => 'zh_cn',
        'zh_tw' => 'zh_tw',
        'zh_hk' => 'zh_tw',
        'zh_mo' => 'zh_tw',
        'zh_hant' => 'zh_tw',
        'ja' => 'jp',
        'ja_jp' => 'jp',
        'pt_br' => 'pt_br',
        'pt-br' => 'pt_br',
    ];

    if (isset($aliases[$normalized])) {
        $normalized = $aliases[$normalized];
    }

    if (in_array($normalized, $supportedLanguages, true)) {
        return $normalized;
    }

    $baseLanguage = strtok($normalized, '_');
    if ($baseLanguage !== false) {
        if (isset($aliases[$baseLanguage]) && in_array($aliases[$baseLanguage], $supportedLanguages, true)) {
            return $aliases[$baseLanguage];
        }

        if (in_array($baseLanguage, $supportedLanguages, true)) {
            return $baseLanguage;
        }
    }

    return $fallback;
}

function wallos_get_seed_translation_language($language)
{
    $language = wallos_resolve_supported_language($language, ['en', 'zh_cn', 'zh_tw']);

    if ($language === 'zh_cn' || $language === 'zh_tw') {
        return $language;
    }

    return 'en';
}

function wallos_localize_seed_rows(array $rows, array $translations, $language, $matchField)
{
    $translationLanguage = wallos_get_seed_translation_language($language);
    $languageMap = $translations[$translationLanguage] ?? [];

    foreach ($rows as &$row) {
        $translationKey = $row[$matchField];
        if (isset($languageMap[$translationKey])) {
            $row['name'] = $languageMap[$translationKey];
        }
    }
    unset($row);

    return $rows;
}

function wallos_get_default_currencies($language = 'en')
{
    $currencies = [
        ['id' => 1, 'name' => 'Euro', 'symbol' => '€', 'code' => 'EUR'],
        ['id' => 2, 'name' => 'US Dollar', 'symbol' => '$', 'code' => 'USD'],
        ['id' => 3, 'name' => 'Japanese Yen', 'symbol' => '¥', 'code' => 'JPY'],
        ['id' => 4, 'name' => 'Bulgarian Lev', 'symbol' => 'лв', 'code' => 'BGN'],
        ['id' => 5, 'name' => 'Czech Republic Koruna', 'symbol' => 'Kč', 'code' => 'CZK'],
        ['id' => 6, 'name' => 'Danish Krone', 'symbol' => 'kr', 'code' => 'DKK'],
        ['id' => 7, 'name' => 'British Pound Sterling', 'symbol' => '£', 'code' => 'GBP'],
        ['id' => 8, 'name' => 'Hungarian Forint', 'symbol' => 'Ft', 'code' => 'HUF'],
        ['id' => 9, 'name' => 'Polish Zloty', 'symbol' => 'zł', 'code' => 'PLN'],
        ['id' => 10, 'name' => 'Romanian Leu', 'symbol' => 'lei', 'code' => 'RON'],
        ['id' => 11, 'name' => 'Swedish Krona', 'symbol' => 'kr', 'code' => 'SEK'],
        ['id' => 12, 'name' => 'Swiss Franc', 'symbol' => 'Fr', 'code' => 'CHF'],
        ['id' => 13, 'name' => 'Icelandic Krona', 'symbol' => 'kr', 'code' => 'ISK'],
        ['id' => 14, 'name' => 'Norwegian Krone', 'symbol' => 'kr', 'code' => 'NOK'],
        ['id' => 15, 'name' => 'Russian Ruble', 'symbol' => '₽', 'code' => 'RUB'],
        ['id' => 16, 'name' => 'Turkish Lira', 'symbol' => '₺', 'code' => 'TRY'],
        ['id' => 17, 'name' => 'Australian Dollar', 'symbol' => '$', 'code' => 'AUD'],
        ['id' => 18, 'name' => 'Brazilian Real', 'symbol' => 'R$', 'code' => 'BRL'],
        ['id' => 19, 'name' => 'Canadian Dollar', 'symbol' => '$', 'code' => 'CAD'],
        ['id' => 20, 'name' => 'Chinese Yuan', 'symbol' => '¥', 'code' => 'CNY'],
        ['id' => 21, 'name' => 'Hong Kong Dollar', 'symbol' => 'HK$', 'code' => 'HKD'],
        ['id' => 22, 'name' => 'Indonesian Rupiah', 'symbol' => 'Rp', 'code' => 'IDR'],
        ['id' => 23, 'name' => 'Israeli New Sheqel', 'symbol' => '₪', 'code' => 'ILS'],
        ['id' => 24, 'name' => 'Indian Rupee', 'symbol' => '₹', 'code' => 'INR'],
        ['id' => 25, 'name' => 'South Korean Won', 'symbol' => '₩', 'code' => 'KRW'],
        ['id' => 26, 'name' => 'Mexican Peso', 'symbol' => 'Mex$', 'code' => 'MXN'],
        ['id' => 27, 'name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'code' => 'MYR'],
        ['id' => 28, 'name' => 'New Zealand Dollar', 'symbol' => 'NZ$', 'code' => 'NZD'],
        ['id' => 29, 'name' => 'Philippine Peso', 'symbol' => '₱', 'code' => 'PHP'],
        ['id' => 30, 'name' => 'Singapore Dollar', 'symbol' => 'S$', 'code' => 'SGD'],
        ['id' => 31, 'name' => 'Thai Baht', 'symbol' => '฿', 'code' => 'THB'],
        ['id' => 32, 'name' => 'South African Rand', 'symbol' => 'R', 'code' => 'ZAR'],
        ['id' => 33, 'name' => 'Ukrainian Hryvnia', 'symbol' => '₴', 'code' => 'UAH'],
        ['id' => 34, 'name' => 'New Taiwan Dollar', 'symbol' => 'NT$', 'code' => 'TWD'],
    ];

    $translations = [
        'zh_cn' => [
            'EUR' => '欧元',
            'USD' => '美元',
            'JPY' => '日元',
            'BGN' => '保加利亚列弗',
            'CZK' => '捷克克朗',
            'DKK' => '丹麦克朗',
            'GBP' => '英镑',
            'HUF' => '匈牙利福林',
            'PLN' => '波兰兹罗提',
            'RON' => '罗马尼亚列伊',
            'SEK' => '瑞典克朗',
            'CHF' => '瑞士法郎',
            'ISK' => '冰岛克朗',
            'NOK' => '挪威克朗',
            'RUB' => '俄罗斯卢布',
            'TRY' => '土耳其里拉',
            'AUD' => '澳大利亚元',
            'BRL' => '巴西雷亚尔',
            'CAD' => '加拿大元',
            'CNY' => '人民币',
            'HKD' => '港元',
            'IDR' => '印尼盾',
            'ILS' => '以色列新谢克尔',
            'INR' => '印度卢比',
            'KRW' => '韩元',
            'MXN' => '墨西哥比索',
            'MYR' => '马来西亚林吉特',
            'NZD' => '新西兰元',
            'PHP' => '菲律宾比索',
            'SGD' => '新加坡元',
            'THB' => '泰铢',
            'ZAR' => '南非兰特',
            'UAH' => '乌克兰格里夫纳',
            'TWD' => '新台币',
        ],
        'zh_tw' => [
            'EUR' => '歐元',
            'USD' => '美元',
            'JPY' => '日圓',
            'BGN' => '保加利亞列弗',
            'CZK' => '捷克克朗',
            'DKK' => '丹麥克朗',
            'GBP' => '英鎊',
            'HUF' => '匈牙利福林',
            'PLN' => '波蘭茲羅提',
            'RON' => '羅馬尼亞列伊',
            'SEK' => '瑞典克朗',
            'CHF' => '瑞士法郎',
            'ISK' => '冰島克朗',
            'NOK' => '挪威克朗',
            'RUB' => '俄羅斯盧布',
            'TRY' => '土耳其里拉',
            'AUD' => '澳大利亞元',
            'BRL' => '巴西雷亞爾',
            'CAD' => '加拿大元',
            'CNY' => '人民幣',
            'HKD' => '港元',
            'IDR' => '印尼盾',
            'ILS' => '以色列新謝克爾',
            'INR' => '印度盧比',
            'KRW' => '韓元',
            'MXN' => '墨西哥比索',
            'MYR' => '馬來西亞林吉特',
            'NZD' => '紐西蘭元',
            'PHP' => '菲律賓比索',
            'SGD' => '新加坡元',
            'THB' => '泰銖',
            'ZAR' => '南非蘭特',
            'UAH' => '烏克蘭格里夫納',
            'TWD' => '新台幣',
        ],
    ];

    return wallos_localize_seed_rows($currencies, $translations, $language, 'code');
}

function wallos_get_default_categories($language = 'en')
{
    $categories = [
        ['id' => 1, 'key' => 'no_category', 'name' => 'No category'],
        ['id' => 2, 'key' => 'entertainment', 'name' => 'Entertainment'],
        ['id' => 3, 'key' => 'music', 'name' => 'Music'],
        ['id' => 4, 'key' => 'utilities', 'name' => 'Utilities'],
        ['id' => 5, 'key' => 'food_and_beverages', 'name' => 'Food & Beverages'],
        ['id' => 6, 'key' => 'health_and_wellbeing', 'name' => 'Health & Wellbeing'],
        ['id' => 7, 'key' => 'productivity', 'name' => 'Productivity'],
        ['id' => 8, 'key' => 'banking', 'name' => 'Banking'],
        ['id' => 9, 'key' => 'transport', 'name' => 'Transport'],
        ['id' => 10, 'key' => 'education', 'name' => 'Education'],
        ['id' => 11, 'key' => 'insurance', 'name' => 'Insurance'],
        ['id' => 12, 'key' => 'gaming', 'name' => 'Gaming'],
        ['id' => 13, 'key' => 'news_and_magazines', 'name' => 'News & Magazines'],
        ['id' => 14, 'key' => 'software', 'name' => 'Software'],
        ['id' => 15, 'key' => 'technology', 'name' => 'Technology'],
        ['id' => 16, 'key' => 'cloud_services', 'name' => 'Cloud Services'],
        ['id' => 17, 'key' => 'charity_and_donations', 'name' => 'Charity & Donations'],
    ];

    $translations = [
        'zh_cn' => [
            'no_category' => '无分类',
            'entertainment' => '娱乐',
            'music' => '音乐',
            'utilities' => '生活服务',
            'food_and_beverages' => '餐饮',
            'health_and_wellbeing' => '健康与身心',
            'productivity' => '生产力',
            'banking' => '银行',
            'transport' => '交通',
            'education' => '教育',
            'insurance' => '保险',
            'gaming' => '游戏',
            'news_and_magazines' => '新闻与杂志',
            'software' => '软件',
            'technology' => '科技',
            'cloud_services' => '云服务',
            'charity_and_donations' => '慈善与捐赠',
        ],
        'zh_tw' => [
            'no_category' => '未分類',
            'entertainment' => '娛樂',
            'music' => '音樂',
            'utilities' => '生活服務',
            'food_and_beverages' => '餐飲',
            'health_and_wellbeing' => '健康與身心',
            'productivity' => '生產力',
            'banking' => '銀行',
            'transport' => '交通',
            'education' => '教育',
            'insurance' => '保險',
            'gaming' => '遊戲',
            'news_and_magazines' => '新聞與雜誌',
            'software' => '軟體',
            'technology' => '科技',
            'cloud_services' => '雲端服務',
            'charity_and_donations' => '慈善與捐贈',
        ],
    ];

    return wallos_localize_seed_rows($categories, $translations, $language, 'key');
}

function wallos_get_default_payment_methods($language = 'en')
{
    $paymentMethods = [
        ['id' => 1, 'key' => 'paypal', 'name' => 'PayPal', 'icon' => 'images/uploads/icons/paypal.png'],
        ['id' => 2, 'key' => 'credit_card', 'name' => 'Credit Card', 'icon' => 'images/uploads/icons/creditcard.png'],
        ['id' => 3, 'key' => 'bank_transfer', 'name' => 'Bank Transfer', 'icon' => 'images/uploads/icons/banktransfer.png'],
        ['id' => 4, 'key' => 'direct_debit', 'name' => 'Direct Debit', 'icon' => 'images/uploads/icons/directdebit.png'],
        ['id' => 5, 'key' => 'money', 'name' => 'Money', 'icon' => 'images/uploads/icons/money.png'],
        ['id' => 6, 'key' => 'google_pay', 'name' => 'Google Pay', 'icon' => 'images/uploads/icons/googlepay.png'],
        ['id' => 7, 'key' => 'samsung_pay', 'name' => 'Samsung Pay', 'icon' => 'images/uploads/icons/samsungpay.png'],
        ['id' => 8, 'key' => 'apple_pay', 'name' => 'Apple Pay', 'icon' => 'images/uploads/icons/applepay.png'],
        ['id' => 9, 'key' => 'crypto', 'name' => 'Crypto', 'icon' => 'images/uploads/icons/crypto.png'],
        ['id' => 10, 'key' => 'klarna', 'name' => 'Klarna', 'icon' => 'images/uploads/icons/klarna.png'],
        ['id' => 11, 'key' => 'amazon_pay', 'name' => 'Amazon Pay', 'icon' => 'images/uploads/icons/amazonpay.png'],
        ['id' => 12, 'key' => 'sepa', 'name' => 'SEPA', 'icon' => 'images/uploads/icons/sepa.png'],
        ['id' => 13, 'key' => 'skrill', 'name' => 'Skrill', 'icon' => 'images/uploads/icons/skrill.png'],
        ['id' => 14, 'key' => 'sofort', 'name' => 'Sofort', 'icon' => 'images/uploads/icons/sofort.png'],
        ['id' => 15, 'key' => 'stripe', 'name' => 'Stripe', 'icon' => 'images/uploads/icons/stripe.png'],
        ['id' => 16, 'key' => 'affirm', 'name' => 'Affirm', 'icon' => 'images/uploads/icons/affirm.png'],
        ['id' => 17, 'key' => 'alipay', 'name' => 'AliPay', 'icon' => 'images/uploads/icons/alipay.png'],
        ['id' => 18, 'key' => 'elo', 'name' => 'Elo', 'icon' => 'images/uploads/icons/elo.png'],
        ['id' => 19, 'key' => 'facebook_pay', 'name' => 'Facebook Pay', 'icon' => 'images/uploads/icons/facebookpay.png'],
        ['id' => 20, 'key' => 'giropay', 'name' => 'GiroPay', 'icon' => 'images/uploads/icons/giropay.png'],
        ['id' => 21, 'key' => 'ideal', 'name' => 'iDeal', 'icon' => 'images/uploads/icons/ideal.png'],
        ['id' => 22, 'key' => 'union_pay', 'name' => 'Union Pay', 'icon' => 'images/uploads/icons/unionpay.png'],
        ['id' => 23, 'key' => 'interac', 'name' => 'Interac', 'icon' => 'images/uploads/icons/interac.png'],
        ['id' => 24, 'key' => 'wechat', 'name' => 'WeChat', 'icon' => 'images/uploads/icons/wechat.png'],
        ['id' => 25, 'key' => 'paysafe', 'name' => 'Paysafe', 'icon' => 'images/uploads/icons/paysafe.png'],
        ['id' => 26, 'key' => 'poli', 'name' => 'Poli', 'icon' => 'images/uploads/icons/poli.png'],
        ['id' => 27, 'key' => 'qiwi', 'name' => 'Qiwi', 'icon' => 'images/uploads/icons/qiwi.png'],
        ['id' => 28, 'key' => 'shoppay', 'name' => 'ShopPay', 'icon' => 'images/uploads/icons/shoppay.png'],
        ['id' => 29, 'key' => 'venmo', 'name' => 'Venmo', 'icon' => 'images/uploads/icons/venmo.png'],
        ['id' => 30, 'key' => 'verifone', 'name' => 'VeriFone', 'icon' => 'images/uploads/icons/verifone.png'],
        ['id' => 31, 'key' => 'webmoney', 'name' => 'WebMoney', 'icon' => 'images/uploads/icons/webmoney.png'],
    ];

    $translations = [
        'zh_cn' => [
            'credit_card' => '信用卡',
            'bank_transfer' => '银行转账',
            'direct_debit' => '自动扣款',
            'money' => '现金',
            'crypto' => '加密货币',
            'alipay' => '支付宝',
            'union_pay' => '银联',
            'wechat' => '微信支付',
        ],
        'zh_tw' => [
            'credit_card' => '信用卡',
            'bank_transfer' => '銀行轉帳',
            'direct_debit' => '自動扣款',
            'money' => '現金',
            'crypto' => '加密貨幣',
            'alipay' => '支付寶',
            'union_pay' => '銀聯',
            'wechat' => '微信支付',
        ],
    ];

    return wallos_localize_seed_rows($paymentMethods, $translations, $language, 'key');
}
