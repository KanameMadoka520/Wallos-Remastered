<?php

function wallos_ajax_pagination_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_ajax_pagination_source($relativePath)
{
    $source = file_get_contents(__DIR__ . '/../' . ltrim($relativePath, '/'));
    if ($source === false) {
        throw new RuntimeException('Unable to read ' . $relativePath . '.');
    }

    return $source;
}

function wallos_ajax_pagination_has_all($source, array $needles)
{
    foreach ($needles as $needle) {
        if (strpos($source, $needle) === false) {
            return false;
        }
    }

    return true;
}

try {
    $pages = wallos_ajax_pagination_source('scripts/subscription-pages.js');
    wallos_ajax_pagination_assert(
        wallos_ajax_pagination_has_all($pages, [
            'historyMode',
            'pushState',
            'addEventListener("popstate"',
            'selectionRequestSequence',
            'fallbackToDocumentNavigation',
            'requestedFilter === committedFilter',
            'subscription-page-history',
        ]),
        'Subscription page selection must use AJAX history, ignore stale selections, support back/forward, and retain a failure fallback.'
    );
    wallos_ajax_pagination_assert(
        strpos($pages, 'setFilterValue(filterValue, { navigate: true })') === false,
        'Normal subscription page selection must not force document navigation.'
    );

    $subscriptions = wallos_ajax_pagination_source('scripts/subscriptions.js');
    wallos_ajax_pagination_assert(
        wallos_ajax_pagination_has_all($subscriptions, [
            'format: "json"',
            'window.WallosApi.getJson',
            'subscriptionsRequestSequence',
            'subscriptionsRequestController?.abort()',
            'requestId !== subscriptionsRequestSequence',
            'signal: requestController.signal',
            'destroySubscriptionMediaSortables()',
            'destroySubscriptionCardSortable()',
            'subscriptionsContainer.innerHTML = data.html',
            'rehydrateSubscriptionCards(',
            'scheduleSubscriptionLayoutAfterImagesSettle(',
            'image.decode()',
        ]),
        'Subscription fragments must be a cancellable, latest-response-only JSON update with complete layout rehydration.'
    );
    wallos_ajax_pagination_assert(
        strpos($subscriptions, 'return refreshSubscriptionPages({') === false,
        'A fragment update must not wait for a second subscription-pages request before rehydrating cards.'
    );
    $fetchStart = strpos($subscriptions, 'function fetchSubscriptions(');
    $fetchEnd = $fetchStart === false ? false : strpos($subscriptions, "\nfunction setSortOption(", $fetchStart);
    wallos_ajax_pagination_assert(
        $fetchStart !== false && $fetchEnd !== false,
        'Unable to isolate fetchSubscriptions() for lifecycle ordering checks.'
    );
    $fetchBody = substr($subscriptions, $fetchStart, $fetchEnd - $fetchStart);
    $guardPosition = strpos($fetchBody, 'isSubscriptionsRequestAbort(null, requestController, requestId)');
    $destroyMediaPosition = strpos($fetchBody, 'destroySubscriptionMediaSortables();');
    $destroyCardsPosition = strpos($fetchBody, 'destroySubscriptionCardSortable();');
    $replacePosition = strpos($fetchBody, 'subscriptionsContainer.innerHTML = data.html;');
    $payloadPosition = strpos($fetchBody, 'applySubscriptionPagesPayload(data, {');
    $rehydratePosition = strpos($fetchBody, 'rehydrateSubscriptionCards(');
    wallos_ajax_pagination_assert(
        $guardPosition !== false
            && $destroyMediaPosition > $guardPosition
            && $destroyCardsPosition > $guardPosition
            && $replacePosition > $destroyMediaPosition
            && $replacePosition > $destroyCardsPosition
            && $payloadPosition > $replacePosition
            && $rehydratePosition > $payloadPosition,
        'Fragment commits must guard freshness, destroy old lifecycle, replace HTML, apply state, then rehydrate in that order.'
    );
    $rehydrateStart = strpos($subscriptions, 'function rehydrateSubscriptionCards(');
    $rehydrateEnd = $rehydrateStart === false ? false : strpos($subscriptions, "\nfunction fetchSubscriptions(", $rehydrateStart);
    wallos_ajax_pagination_assert(
        $rehydrateStart !== false && $rehydrateEnd !== false,
        'Unable to isolate rehydrateSubscriptionCards() for layout ordering checks.'
    );
    $rehydrateBody = substr($subscriptions, $rehydrateStart, $rehydrateEnd - $rehydrateStart);
    $cardSortablePosition = strpos($rehydrateBody, 'initializeSubscriptionCardSortable();');
    $columnsPosition = strpos($rehydrateBody, 'applySubscriptionDisplayColumns();');
    $immediateMasonryPosition = strpos($rehydrateBody, 'scheduleSubscriptionMasonryLayout();');
    $settledMasonryPosition = strpos($rehydrateBody, 'scheduleSubscriptionLayoutAfterImagesSettle(');
    wallos_ajax_pagination_assert(
        $cardSortablePosition !== false
            && $columnsPosition > $cardSortablePosition
            && $immediateMasonryPosition > $columnsPosition
            && $settledMasonryPosition > $immediateMasonryPosition,
        'Card rehydration must finish Sortable teardown before scheduling immediate and image-settled Masonry passes.'
    );

    $endpoint = wallos_ajax_pagination_source('endpoints/subscriptions/get.php');
    wallos_ajax_pagination_assert(
        wallos_ajax_pagination_has_all($endpoint, [
            "(\$_GET['format'] ?? '')",
            "=== 'json'",
            'ob_start()',
            "'html' => \$subscriptionsHtml",
            "'current_filter' => wallos_get_subscription_page_filter_value",
            "'visible_count' => \$visibleSubscriptionCount",
            "'pages' => \$pagePayload['pages']",
            "'counts' => \$pagePayload['counts']",
            'JSON_INVALID_UTF8_SUBSTITUTE',
            'data-subscription-action="select-page-filter"',
        ]),
        'The subscription endpoint must preserve HTML mode and expose a complete, UTF-8-safe JSON fragment mode.'
    );

    foreach (['scripts/common.js', 'scripts/api.js'] as $relativePath) {
        $httpSource = wallos_ajax_pagination_source($relativePath);
        wallos_ajax_pagination_assert(
            strpos($httpSource, 'signal = null') !== false
                && preg_match('/fetch\s*\(\s*url\s*,\s*\{[\s\S]*?\bsignal\s*,[\s\S]*?\}\s*\)/', $httpSource) === 1,
            $relativePath . ' must pass AbortSignal through to fetch().'
        );
    }

    $layout = wallos_ajax_pagination_source('scripts/subscription-layout.js');
    $media = wallos_ajax_pagination_source('scripts/subscription-media.js');
    wallos_ajax_pagination_assert(
        strpos($layout, 'destroySubscriptionCardSortable') !== false
            && strpos($media, 'destroySubscriptionMediaSortables') !== false,
        'Sortable instances must be explicitly destroyed before fragment DOM replacement.'
    );

    echo "Subscription AJAX pagination contract test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

?>
