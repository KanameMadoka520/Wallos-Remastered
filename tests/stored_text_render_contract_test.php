<?php

// Contract test for the stored-text rendering pipeline.
//
// validate() HTML-escapes text at storage time (legacy upstream contract that
// migrations keep). Render paths that HTML-escape again at output time must
// therefore decode the stored value once first, otherwise characters like &
// surface as &amp;amp;. This test pins both the decoder helper and its use at
// every known render path that re-escapes stored text.

function wallos_stored_text_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_stored_text_source($relativePath)
{
    $source = file_get_contents(__DIR__ . '/../' . ltrim($relativePath, '/'));
    if ($source === false) {
        throw new RuntimeException('Unable to read ' . $relativePath . '.');
    }

    return $source;
}

try {
    require_once __DIR__ . '/../includes/inputvalidation.php';

    // The decoder must invert validate() exactly for the characters
    // htmlspecialchars() escapes by default (ENT_QUOTES | ENT_HTML401).
    $samples = [
        'L站枫佬车 美国家宽50Mbps独享 AT&T06 个人用 理论无限流量',
        '5 < 10 & "quotes" and \'apostrophes\'',
        'plain text without specials',
    ];
    foreach ($samples as $sample) {
        wallos_stored_text_assert(
            wallos_decode_stored_text(validate($sample)) === $sample,
            'Decode round-trip changed a stored value for: ' . $sample
        );
    }
    wallos_stored_text_assert(
        wallos_decode_stored_text(null) === '',
        'Decoder must tolerate null stored values.'
    );

    $listSubscriptions = wallos_stored_text_source('includes/list_subscriptions.php');
    wallos_stored_text_assert(
        strpos($listSubscriptions, "require_once __DIR__ . '/inputvalidation.php';") !== false,
        'list_subscriptions.php must load the stored-text decoder.'
    );
    foreach ([
        "\$subscriptions[\$index]['name'] = wallos_decode_stored_text(",
        "\$subscriptions[\$index]['url'] = wallos_decode_stored_text(",
        "\$subscriptions[\$index]['notes'] = wallos_decode_stored_text(",
        "\$subscriptions[\$index]['payment_method_name'] = wallos_decode_stored_text(",
        "\$categories[\$index]['name'] = wallos_decode_stored_text(",
        "\$members[\$index]['name'] = wallos_decode_stored_text(",
    ] as $needle) {
        wallos_stored_text_assert(
            strpos($listSubscriptions, $needle) !== false,
            'printSubscriptions must decode stored text before escaping: ' . $needle
        );
    }

    $subscriptionsPage = wallos_stored_text_source('subscriptions.php');
    foreach ([
        'htmlspecialchars(wallos_decode_stored_text($trashedSubscription[\'name\']), ENT_QUOTES, \'UTF-8\')',
        'htmlspecialchars(wallos_decode_stored_text($replacementSubscriptionName), ENT_QUOTES, \'UTF-8\')',
        'htmlspecialchars(wallos_decode_stored_text($currency[\'name\']), ENT_QUOTES, \'UTF-8\')',
        'htmlspecialchars(wallos_decode_stored_text($payment[\'name\']), ENT_QUOTES, \'UTF-8\')',
    ] as $needle) {
        wallos_stored_text_assert(
            strpos($subscriptionsPage, $needle) !== false,
            'subscriptions.php must decode stored text before escaping: ' . $needle
        );
    }

    $indexPage = wallos_stored_text_source('index.php');
    wallos_stored_text_assert(
        strpos($indexPage, "foreach (['upcomingSubscriptions', 'overdueSubscriptions', 'upcomingCancellations']") !== false
        && strpos($indexPage, "wallos_decode_stored_text(\$dashboardSubscription['name'] ?? '')") !== false,
        'index.php must decode stored subscription names before rendering the dashboard lists.'
    );
    wallos_stored_text_assert(
        strpos($indexPage, 'htmlspecialchars(wallos_decode_stored_text($first_name), ENT_QUOTES, \'UTF-8\')') !== false,
        'index.php must decode the stored first name before greeting.'
    );

    $calendarPage = wallos_stored_text_source('calendar.php');
    wallos_stored_text_assert(
        substr_count($calendarPage, 'htmlspecialchars(wallos_decode_stored_text($subscription[\'name\'])') === 2,
        'calendar.php must decode the stored subscription name in both visible and aria output.'
    );

    $getCalendar = wallos_stored_text_source('endpoints/subscription/getcalendar.php');
    wallos_stored_text_assert(
        strpos($getCalendar, 'wallos_render_markdown(wallos_decode_stored_text($subscription[\'notes\'] ?? \'\'))') !== false,
        'getcalendar.php must decode stored notes before the markdown renderer escapes them.'
    );

    foreach ([
        'endpoints/subscription/exportcalendar.php' => [
            "icalEscape(html_entity_decode((string) (\$subscription['category'] ?? ''), ENT_QUOTES, 'UTF-8'))",
            "icalEscape(html_entity_decode((string) (\$subscription['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8'))",
            "icalEscape(html_entity_decode((string) (\$subscription['payer_user'] ?? ''), ENT_QUOTES, 'UTF-8'))",
            "icalEscape(html_entity_decode((string) (isset(\$subscription['url']) ? \$subscription['url'] : ''), ENT_QUOTES, 'UTF-8'))",
        ],
        'api/subscriptions/get_ical_feed.php' => [
            "icalEscape(html_entity_decode((string) \$subscription['category'], ENT_QUOTES, 'UTF-8'))",
            "icalEscape(html_entity_decode((string) \$subscription['payment_method'], ENT_QUOTES, 'UTF-8'))",
            "icalEscape(html_entity_decode((string) \$subscription['payer_user'], ENT_QUOTES, 'UTF-8'))",
            "icalEscape(html_entity_decode((string) (isset(\$subscription['url']) ? \$subscription['url'] : ''), ENT_QUOTES, 'UTF-8'))",
        ],
    ] as $icsPath => $icsNeedles) {
        $icsSource = wallos_stored_text_source($icsPath);
        foreach ($icsNeedles as $needle) {
            wallos_stored_text_assert(
                strpos($icsSource, $needle) !== false,
                $icsPath . ' must decode stored text before iCalendar escaping: ' . $needle
            );
        }
    }

    $settingsPage = wallos_stored_text_source('settings.php');
    foreach ([
        'htmlspecialchars(wallos_decode_stored_text($member[\'name\']), ENT_QUOTES, \'UTF-8\')',
        'htmlspecialchars(wallos_decode_stored_text($member[\'email\'] ?? ""), ENT_QUOTES, \'UTF-8\')',
        'htmlspecialchars(wallos_decode_stored_text($category[\'name\']), ENT_QUOTES, \'UTF-8\')',
        'htmlspecialchars(wallos_decode_stored_text($currency[\'symbol\']), ENT_QUOTES, \'UTF-8\')',
        'htmlspecialchars(wallos_decode_stored_text($currency[\'name\']), ENT_QUOTES, \'UTF-8\')',
        'htmlspecialchars(wallos_decode_stored_text($currency[\'code\']), ENT_QUOTES, \'UTF-8\')',
        'htmlspecialchars(wallos_decode_stored_text($payment[\'name\']), ENT_QUOTES, \'UTF-8\')',
    ] as $needle) {
        wallos_stored_text_assert(
            strpos($settingsPage, $needle) !== false,
            'settings.php must decode stored text before escaping: ' . $needle
        );
    }

    $profilePage = wallos_stored_text_source('profile.php');
    foreach (['username', 'firstname', 'lastname', 'email'] as $userField) {
        wallos_stored_text_assert(
            strpos($profilePage, 'htmlspecialchars(wallos_decode_stored_text($userData[\'' . $userField . '\']), ENT_QUOTES, \'UTF-8\')') !== false,
            'profile.php must decode the stored user ' . $userField . ' before escaping.'
        );
    }

    $adminPage = wallos_stored_text_source('admin.php');
    foreach ([
        'htmlspecialchars(wallos_decode_stored_text($user[\'username\']), ENT_QUOTES, \'UTF-8\')',
        'htmlspecialchars(wallos_decode_stored_text($user[\'email\']), ENT_QUOTES, \'UTF-8\')',
        'htmlspecialchars(wallos_decode_stored_text($trashedUser[\'email\']), ENT_QUOTES, \'UTF-8\')',
    ] as $needle) {
        wallos_stored_text_assert(
            strpos($adminPage, $needle) !== false,
            'admin.php must decode stored text before escaping: ' . $needle
        );
    }

    $statsPage = wallos_stored_text_source('stats.php');
    wallos_stored_text_assert(
        strpos($statsPage, 'htmlspecialchars(wallos_decode_stored_text($displayMostExpensiveSubscription[\'name\']), ENT_QUOTES, \'UTF-8\')') !== false,
        'stats.php must decode the stored subscription name before escaping.'
    );

    $addUserEndpoint = wallos_stored_text_source('endpoints/admin/adduser.php');
    wallos_stored_text_assert(
        strpos($addUserEndpoint, 'htmlentities') === false,
        'endpoints/admin/adduser.php must not double-encode stored text with htmlentities on top of htmlspecialchars.'
    );

    echo "Stored-text decode contract passed for list, page, dashboard, calendar, settings, profile, admin and iCalendar render paths.\n";
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . "\n");
    exit(1);
}
