<?php

/**
 * Static contracts for the authenticated-navigation performance work.
 *
 * These checks intentionally inspect source instead of booting Wallos: they are
 * meant to catch accidental regressions in load order, browser caching, and
 * private-response handling during review and image builds.
 */

function wallos_navigation_contract_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_navigation_contract_source($relativePath)
{
    $path = __DIR__ . '/../' . ltrim($relativePath, '/');
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Unable to read ' . $relativePath);
    }

    return $source;
}

function wallos_navigation_contract_strip_php($source)
{
    return preg_replace('/<\?(?:php|=)?[\s\S]*?\?>/i', '__WALLOS_PHP__', $source);
}

function wallos_navigation_contract_external_scripts($source)
{
    $html = wallos_navigation_contract_strip_php($source);
    preg_match_all('/<script\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1[^>]*>/is', $html, $matches);

    $scripts = [];
    foreach ($matches[0] as $index => $tag) {
        $scripts[] = [
            'tag' => $tag,
            'src' => html_entity_decode($matches[2][$index], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ];
    }

    return $scripts;
}

function wallos_navigation_contract_asset_name($sourceUrl)
{
    $path = preg_split('/[?#]/', trim($sourceUrl), 2)[0] ?? '';
    return strtolower(basename(str_replace('\\', '/', $path)));
}

function wallos_navigation_contract_skip_space($source, $offset)
{
    $length = strlen($source);
    while ($offset < $length && preg_match('/\s/', $source[$offset]) === 1) {
        $offset++;
    }

    return $offset;
}

/**
 * Finds the matching bracket while ignoring quoted strings and JS comments.
 */
function wallos_navigation_contract_matching_delimiter($source, $openOffset, $openCharacter, $closeCharacter)
{
    $length = strlen($source);
    $depth = 0;
    $state = 'code';

    for ($index = $openOffset; $index < $length; $index++) {
        $character = $source[$index];
        $next = $index + 1 < $length ? $source[$index + 1] : '';

        if ($state === 'line-comment') {
            if ($character === "\n") {
                $state = 'code';
            }
            continue;
        }

        if ($state === 'block-comment') {
            if ($character === '*' && $next === '/') {
                $state = 'code';
                $index++;
            }
            continue;
        }

        if ($state === 'single-quote' || $state === 'double-quote' || $state === 'template') {
            if ($character === '\\') {
                $index++;
                continue;
            }

            $closingQuote = $state === 'single-quote' ? "'" : ($state === 'double-quote' ? '"' : '`');
            if ($character === $closingQuote) {
                $state = 'code';
            }
            continue;
        }

        if ($character === '/' && $next === '/') {
            $state = 'line-comment';
            $index++;
            continue;
        }
        if ($character === '/' && $next === '*') {
            $state = 'block-comment';
            $index++;
            continue;
        }
        if ($character === "'") {
            $state = 'single-quote';
            continue;
        }
        if ($character === '"') {
            $state = 'double-quote';
            continue;
        }
        if ($character === '`') {
            $state = 'template';
            continue;
        }

        if ($character === $openCharacter) {
            $depth++;
        } elseif ($character === $closeCharacter) {
            $depth--;
            if ($depth === 0) {
                return $index;
            }
        }
    }

    return false;
}

function wallos_navigation_contract_if_blocks($source, $identifier)
{
    preg_match_all('/\bif\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE);
    $blocks = [];

    foreach ($matches[0] as $match) {
        $ifOffset = $match[1];
        $openParenthesis = strpos($source, '(', $ifOffset);
        if ($openParenthesis === false) {
            continue;
        }

        $closeParenthesis = wallos_navigation_contract_matching_delimiter($source, $openParenthesis, '(', ')');
        if ($closeParenthesis === false) {
            continue;
        }

        $condition = substr($source, $openParenthesis + 1, $closeParenthesis - $openParenthesis - 1);
        if (preg_match('/\b' . preg_quote($identifier, '/') . '\b/', $condition) !== 1) {
            continue;
        }

        $openBrace = wallos_navigation_contract_skip_space($source, $closeParenthesis + 1);
        if (($source[$openBrace] ?? '') !== '{') {
            continue;
        }

        $closeBrace = wallos_navigation_contract_matching_delimiter($source, $openBrace, '{', '}');
        if ($closeBrace === false) {
            continue;
        }

        $blocks[] = [
            'offset' => $ifOffset,
            'condition' => $condition,
            'body' => substr($source, $openBrace + 1, $closeBrace - $openBrace - 1),
        ];
    }

    return $blocks;
}

function wallos_navigation_contract_function_bodies($source)
{
    preg_match_all(
        '/\bfunction\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*\([^)]*\)\s*\{/',
        $source,
        $matches,
        PREG_OFFSET_CAPTURE
    );

    $functions = [];
    foreach ($matches[0] as $index => $match) {
        $openBrace = strpos($source, '{', $match[1]);
        if ($openBrace === false) {
            continue;
        }
        $closeBrace = wallos_navigation_contract_matching_delimiter($source, $openBrace, '{', '}');
        if ($closeBrace === false) {
            continue;
        }

        $functions[$matches[1][$index][0]] = substr(
            $source,
            $openBrace + 1,
            $closeBrace - $openBrace - 1
        );
    }

    return $functions;
}

function wallos_navigation_contract_expand_helpers($source, array $functions, $depth = 0, array $visited = [])
{
    if ($depth >= 4) {
        return $source;
    }

    $expanded = $source;
    foreach ($functions as $name => $body) {
        if (isset($visited[$name]) || preg_match('/\b' . preg_quote($name, '/') . '\s*\(/', $source) !== 1) {
            continue;
        }

        $nextVisited = $visited;
        $nextVisited[$name] = true;
        $expanded .= "\n" . wallos_navigation_contract_expand_helpers($body, $functions, $depth + 1, $nextVisited);
    }

    return $expanded;
}

function wallos_navigation_contract_uses_no_store_fetch($source)
{
    return preg_match('/\bfetch\s*\(/', $source) === 1
        && preg_match('/\bcache\s*:\s*["\']no-store["\']/', $source) === 1;
}

function wallos_navigation_contract_has_cache_operation($source)
{
    return preg_match('/\bcaches?\s*\.\s*(?:match|open|put|add|addAll)\s*\(/', $source) === 1
        || preg_match('/\bcacheOkResponse\s*\(/', $source) === 1;
}

function wallos_navigation_contract_nginx_locations($source)
{
    preg_match_all('/\blocation\b[^\{]*\{/', $source, $matches, PREG_OFFSET_CAPTURE);
    $locations = [];

    foreach ($matches[0] as $match) {
        $openBrace = strpos($source, '{', $match[1]);
        if ($openBrace === false) {
            continue;
        }
        $closeBrace = wallos_navigation_contract_matching_delimiter($source, $openBrace, '{', '}');
        if ($closeBrace === false) {
            continue;
        }

        $locations[] = [
            'offset' => $match[1],
            'open' => $openBrace,
            'close' => $closeBrace,
            'header' => trim(substr($source, $match[1], $openBrace - $match[1])),
            'body' => substr($source, $openBrace + 1, $closeBrace - $openBrace - 1),
        ];
    }

    return $locations;
}

function wallos_navigation_contract_is_safe_static_location($header)
{
    $hasPackagedRoot = preg_match('/\b(?:styles|scripts|webfonts)\b/i', $header) === 1
        || preg_match('#/images/.*\b(?:icon|siteicons|siteimages|avatars|screenshots|uploads/icons)\b#i', $header) === 1;

    return $hasPackagedRoot
        && stripos($header, 'subscription-media') === false
        && stripos($header, 'uploads/logos') === false
        && strpos($header, '\\.php') === false;
}

function wallos_navigation_contract_no_store_header($source)
{
    return preg_match('/Cache-Control:[^\r\n"\']*\bno-store\b/i', $source) === 1;
}

try {
    $transitions = wallos_navigation_contract_source('scripts/page-transitions.js');
    wallos_navigation_contract_assert(
        preg_match('/\b(?:const|let|var)\s+leaveDurationMs\s*=\s*(\d+(?:\.\d+)?)\s*;/', $transitions, $durationMatch) === 1,
        'page-transitions.js must declare a numeric leaveDurationMs.'
    );
    wallos_navigation_contract_assert(
        (float) $durationMatch[1] >= 0 && (float) $durationMatch[1] <= 100,
        'Authenticated navigation must not wait more than 100ms before starting the next request.'
    );

    $header = wallos_navigation_contract_source('includes/header.php');
    $headerScripts = wallos_navigation_contract_external_scripts($header);
    wallos_navigation_contract_assert(!empty($headerScripts), 'Authenticated header must load its shared scripts.');

    $dynamicWallpaperCount = 0;
    foreach ($headerScripts as $script) {
        wallos_navigation_contract_assert(
            preg_match('/\bdefer(?:\s|=|\/?>)/i', $script['tag']) === 1,
            'Authenticated shared script must be deferred: ' . $script['src']
        );
        if (wallos_navigation_contract_asset_name($script['src']) === 'dynamic-wallpaper.js') {
            $dynamicWallpaperCount++;
        }
    }
    wallos_navigation_contract_assert(
        $dynamicWallpaperCount === 1,
        'Authenticated header must load dynamic-wallpaper.js exactly once.'
    );
    wallos_navigation_contract_assert(
        stripos($header, 'brands.css') === false,
        'Authenticated pages must not load the unused brands.css bundle.'
    );
    wallos_navigation_contract_assert(
        preg_match('/\bPREFETCH_PAGES\b/', $header) !== 1,
        'Authenticated header must not trigger speculative private-page prefetching.'
    );

    $profileScripts = array_map(
        function ($script) {
            return wallos_navigation_contract_asset_name($script['src']);
        },
        wallos_navigation_contract_external_scripts(wallos_navigation_contract_source('profile.php'))
    );
    foreach (['sortable.min.js', 'theme.js', 'notifications.js'] as $unusedProfileAsset) {
        wallos_navigation_contract_assert(
            !in_array($unusedProfileAsset, $profileScripts, true),
            'profile.php must not load unused ' . $unusedProfileAsset
        );
    }
    foreach (['qrcode.min.js', 'profile.js'] as $requiredProfileAsset) {
        wallos_navigation_contract_assert(
            in_array($requiredProfileAsset, $profileScripts, true),
            'profile.php must keep required ' . $requiredProfileAsset
        );
    }

    $settingsScripts = array_map(
        function ($script) {
            return wallos_navigation_contract_asset_name($script['src']);
        },
        wallos_navigation_contract_external_scripts(wallos_navigation_contract_source('settings.php'))
    );
    wallos_navigation_contract_assert(
        in_array('sortable.min.js', $settingsScripts, true),
        'settings.php must keep sortable.min.js.'
    );
    wallos_navigation_contract_assert(
        !in_array('qrcode.min.js', $settingsScripts, true),
        'settings.php must not load unused qrcode.min.js.'
    );

    $subscriptionsPhp = wallos_navigation_contract_source('subscriptions.php');
    $subscriptionsJs = wallos_navigation_contract_source('scripts/subscriptions.js');
    wallos_navigation_contract_assert(
        strpos($subscriptionsPhp, 'wallos:subscriptions-ready') !== false
            && strpos($subscriptionsJs, 'wallos:subscriptions-ready') !== false
            && strpos($subscriptionsJs, 'WallosSubscriptionsReady = true') !== false,
        'subscriptions.php?add must wait for deferred subscription modules before opening the form.'
    );

    $serviceWorker = wallos_navigation_contract_source('service-worker.js');
    foreach (['PAGES_CACHE', 'pagesToPrefetch'] as $forbiddenPrivateCacheSymbol) {
        wallos_navigation_contract_assert(
            preg_match('/\b' . preg_quote($forbiddenPrivateCacheSymbol, '/') . '\b/', $serviceWorker) !== 1,
            'service-worker.js must not define or use ' . $forbiddenPrivateCacheSymbol
        );
    }
    wallos_navigation_contract_assert(
        preg_match('/\bcaches\s*\.\s*match\s*\(\s*normalizedPath\b/', $serviceWorker) !== 1
            && preg_match('/\bignoreSearch\s*:/', $serviceWorker) !== 1,
        'Service-worker cache lookup must not fall back across query-string fingerprints.'
    );
    wallos_navigation_contract_assert(
        preg_match('/\bconst\s+isPhpRequest\b/', $serviceWorker) === 1,
        'service-worker.js must identify PHP requests before static-cache routing.'
    );
    wallos_navigation_contract_assert(
        strpos($serviceWorker, "normalizedPath.includes('/' + prefix)") !== false,
        'Service-worker static matching must keep working when Wallos is hosted below an origin subpath.'
    );

    $serviceWorkerFunctions = wallos_navigation_contract_function_bodies($serviceWorker);
    $privateRouteNames = [
        'isDocumentRequest' => 'HTML document navigation',
        'isEndpointRequest' => 'endpoint request',
        'isPhpRequest' => 'PHP request',
        'isSubscriptionMediaRequest' => 'private subscription media request',
    ];
    $privateRouteOffsets = [];
    foreach ($privateRouteNames as $identifier => $label) {
        $blocks = wallos_navigation_contract_if_blocks($serviceWorker, $identifier);
        wallos_navigation_contract_assert(!empty($blocks), 'service-worker.js must route each ' . $label . '.');

        $block = $blocks[0];
        $privateRouteOffsets[$identifier] = $block['offset'];
        $expandedBody = wallos_navigation_contract_expand_helpers($block['body'], $serviceWorkerFunctions);
        wallos_navigation_contract_assert(
            wallos_navigation_contract_uses_no_store_fetch($expandedBody),
            'The ' . $label . ' must use a network request with cache: no-store.'
        );
        wallos_navigation_contract_assert(
            !wallos_navigation_contract_has_cache_operation($expandedBody),
            'The ' . $label . ' must never read from or write to a service-worker cache.'
        );
    }

    $versionedStaticBlocks = wallos_navigation_contract_if_blocks($serviceWorker, 'isStaticAssetRequest');
    $versionedStaticBlock = null;
    foreach ($versionedStaticBlocks as $candidate) {
        $expandedCondition = wallos_navigation_contract_expand_helpers(
            $candidate['condition'],
            $serviceWorkerFunctions
        );
        if (preg_match('/\burl\s*\.\s*search\b/', $expandedCondition) === 1) {
            $versionedStaticBlock = $candidate;
            break;
        }
    }
    wallos_navigation_contract_assert(
        is_array($versionedStaticBlock),
        'service-worker.js must have a distinct versioned-static-asset route.'
    );
    $expandedStaticBody = wallos_navigation_contract_expand_helpers(
        $versionedStaticBlock['body'],
        $serviceWorkerFunctions
    );
    wallos_navigation_contract_assert(
        preg_match('/\bcaches\s*\.\s*match\s*\(\s*request\s*\)/', $expandedStaticBody) === 1,
        'Versioned static assets must use cache-first lookup with the exact Request key.'
    );
    preg_match_all('/\bcaches\s*\.\s*match\s*\(\s*([^,\)]+)/', $expandedStaticBody, $staticCacheMatches);
    foreach ($staticCacheMatches[1] as $cacheKey) {
        wallos_navigation_contract_assert(
            trim($cacheKey) === 'request',
            'Versioned static assets must not use a normalized or fuzzy cache lookup key.'
        );
    }
    wallos_navigation_contract_assert(
        preg_match('/\bcacheOkResponse\s*\(\s*STATIC_CACHE\s*,\s*request\s*,/', $expandedStaticBody) === 1
            || preg_match('/\bcache\s*\.\s*put\s*\(\s*request\s*,/', $expandedStaticBody) === 1,
        'Versioned static responses must be stored under the exact Request key.'
    );
    foreach ($privateRouteOffsets as $identifier => $offset) {
        wallos_navigation_contract_assert(
            $offset < $versionedStaticBlock['offset'],
            $identifier . ' must be routed before versioned static assets.'
        );
    }

    foreach (['nginx.conf', 'nginx.default.conf'] as $nginxPath) {
        $nginx = wallos_navigation_contract_source($nginxPath);
        wallos_navigation_contract_assert(
            preg_match('/\bgzip\s+on\s*;/', $nginx) === 1,
            $nginxPath . ' must enable gzip.'
        );
        wallos_navigation_contract_assert(
            preg_match('/\bgzip_types\s+([^;]+);/', $nginx, $gzipTypesMatch) === 1,
            $nginxPath . ' must explicitly limit gzip to static asset MIME types.'
        );
        $gzipTypes = preg_split('/\s+/', trim($gzipTypesMatch[1]));
        wallos_navigation_contract_assert(
            in_array('text/css', $gzipTypes, true)
                && (in_array('application/javascript', $gzipTypes, true) || in_array('text/javascript', $gzipTypes, true))
                && in_array('image/svg+xml', $gzipTypes, true),
            $nginxPath . ' gzip types must cover CSS, JavaScript, and SVG assets.'
        );
        foreach (['text/html', 'text/plain', 'application/xml', 'application/octet-stream'] as $dynamicOrPrivateType) {
            wallos_navigation_contract_assert(
                !in_array($dynamicOrPrivateType, $gzipTypes, true),
                $nginxPath . ' must not opt dynamic/private type ' . $dynamicOrPrivateType . ' into gzip.'
            );
        }

        $locations = wallos_navigation_contract_nginx_locations($nginx);
        preg_match_all('/\bgzip\s+on\s*;/', $nginx, $gzipOnMatches, PREG_OFFSET_CAPTURE);
        foreach ($gzipOnMatches[0] as $gzipOnMatch) {
            $gzipLocation = null;
            foreach ($locations as $location) {
                if ($gzipOnMatch[1] > $location['open'] && $gzipOnMatch[1] < $location['close']) {
                    $gzipLocation = $location;
                    break;
                }
            }
            wallos_navigation_contract_assert(
                is_array($gzipLocation)
                    && wallos_navigation_contract_is_safe_static_location($gzipLocation['header']),
                $nginxPath . ' must enable gzip only inside packaged static-asset locations.'
            );
        }

        $phpNoStoreFound = false;
        $safeStaticPolicyFound = false;
        $cachePolicyVariable = null;

        if (preg_match('/\bmap\s+\$args\s+\$([A-Za-z_][A-Za-z0-9_]*)\s*\{/', $nginx, $cacheMapMatch) === 1) {
            $cachePolicyVariable = $cacheMapMatch[1];
            $cacheMapStart = strpos($nginx, $cacheMapMatch[0]);
            $cacheMapOpen = $cacheMapStart === false ? false : strpos($nginx, '{', $cacheMapStart);
            $cacheMapClose = $cacheMapOpen === false
                ? false
                : wallos_navigation_contract_matching_delimiter($nginx, $cacheMapOpen, '{', '}');
            $cacheMapBody = $cacheMapClose === false
                ? ''
                : substr($nginx, $cacheMapOpen + 1, $cacheMapClose - $cacheMapOpen - 1);
            wallos_navigation_contract_assert(
                preg_match('/~\^v=/', $cacheMapBody) === 1
                    && preg_match('/\bimmutable\b/', $cacheMapBody) === 1,
                $nginxPath . ' map must make only an exact v= fingerprint immutable.'
            );
        } else {
            // nginx.default.conf is a server-only example, where an http-level map
            // is not legal. Accept a variable changed by a strict $args condition.
            foreach ($locations as $location) {
                $isSafeStaticLocation = wallos_navigation_contract_is_safe_static_location($location['header']);
                if ($isSafeStaticLocation
                    && preg_match('/\badd_header\s+Cache-Control\s+\$([A-Za-z_][A-Za-z0-9_]*)\b/i', $location['body'], $policyMatch) === 1
                ) {
                    $cachePolicyVariable = $policyMatch[1];
                    break;
                }
            }

            wallos_navigation_contract_assert(
                is_string($cachePolicyVariable)
                    && preg_match('/\bset\s+\$' . preg_quote((string) $cachePolicyVariable, '/') . '\s+[^;]+;/', $nginx) === 1,
                $nginxPath . ' must define a static cache-control variable.'
            );
            $argsConditionSetsImmutable = false;
            foreach (wallos_navigation_contract_if_blocks($nginx, 'args') as $argsBlock) {
                if (preg_match('/\^v=/', $argsBlock['condition']) === 1
                    && preg_match(
                    '/\bset\s+\$' . preg_quote((string) $cachePolicyVariable, '/') . '\s+[^;]*\bimmutable\b[^;]*;/',
                    $argsBlock['body']
                    ) === 1
                ) {
                    $argsConditionSetsImmutable = true;
                    break;
                }
            }
            wallos_navigation_contract_assert(
                $argsConditionSetsImmutable,
                $nginxPath . ' must select immutable caching only when a version query fingerprint is present.'
            );
        }

        foreach ($locations as $location) {
            if (strpos($location['header'], '\\.php$') !== false) {
                $phpNoStoreFound = preg_match(
                    '/\badd_header\s+Cache-Control\s+[^;]*\bno-store\b[^;]*;/i',
                    $location['body']
                ) === 1;
            }

            if (is_string($cachePolicyVariable)
                && preg_match('/\badd_header\s+Cache-Control\s+\$' . preg_quote($cachePolicyVariable, '/') . '\b/i', $location['body']) === 1
            ) {
                wallos_navigation_contract_assert(
                    wallos_navigation_contract_is_safe_static_location($location['header']),
                    $nginxPath . ' must not apply immutable caching outside packaged static roots.'
                );
                $safeStaticPolicyFound = true;
            }
        }
        wallos_navigation_contract_assert(
            $safeStaticPolicyFound,
            $nginxPath . ' must apply the fingerprint-aware cache policy only to packaged static roots.'
        );
        wallos_navigation_contract_assert(
            $phpNoStoreFound,
            $nginxPath . ' PHP location must emit Cache-Control: no-store.'
        );
    }

    foreach ([
        'endpoints/media/subscriptionimage.php' => 'private subscription images',
        'endpoints/admin/downloadbackup.php' => 'database backup downloads',
    ] as $endpointPath => $description) {
        $endpoint = wallos_navigation_contract_source($endpointPath);
        wallos_navigation_contract_assert(
            wallos_navigation_contract_no_store_header($endpoint),
            $endpointPath . ' must mark ' . $description . ' as no-store.'
        );
    }

    echo "Navigation performance and privacy contracts passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

?>
