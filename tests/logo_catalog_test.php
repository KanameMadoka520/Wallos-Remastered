<?php

require_once __DIR__ . '/../includes/logo_catalog.php';

function catalogAssert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/wallos-catalog-test-' . bin2hex(random_bytes(5));
mkdir($directory, 0700);
try {
    $body = json_encode([
        ['Name' => 'GitHub', 'Reference' => 'github', 'PNG' => 'Yes'],
        ['Name' => '<img src=x onerror=alert(1)>', 'Reference' => 'github-alt', 'PNG' => 'Yes'],
        ['Name' => 'Unsafe', 'Reference' => '../private', 'PNG' => 'Yes'],
        ['Name' => 'No PNG', 'Reference' => 'vector', 'PNG' => 'No'],
        ['Name' => 'Bad type', 'Reference' => [], 'PNG' => 'Yes'],
    ]);
    $items = wallos_logo_catalog_parse('selfhst', $body);
    catalogAssert(count($items) === 2, 'Unsafe slugs or non-PNG records were accepted.');
    catalogAssert($items[1]['name'] === '<img src=x onerror=alert(1)>', 'Names must remain plain data for client text rendering.');
    $dashboard = wallos_logo_catalog_parse('dashboardicons', json_encode(['png' => ['github.png', 'github-dark.png', '../private.png', 'other.svg', 'github.png']]));
    catalogAssert(count($dashboard) === 1 && $dashboard[0]['slug'] === 'github', 'Dashboard names, themes or deduplication failed.');
    foreach (['{}', 'null', str_repeat('x', WALLOS_LOGO_CATALOG_MAX_BYTES + 1), json_encode(array_fill(0, WALLOS_LOGO_CATALOG_MAX_ENTRIES + 1, ['PNG' => 'Yes']))] as $invalid) {
        catalogAssert(wallos_logo_catalog_parse('selfhst', $invalid) === null, 'Malformed or oversized catalog accepted.');
    }
    foreach ([['source' => 'evil', 'search' => 'github'], ['source' => 'selfhst', 'search' => []], ['source' => 'selfhst', 'search' => "bad\nname"], ['source' => 'selfhst', 'search' => ''], ['source' => 'selfhst', 'search' => str_repeat('x', 129)], ['source' => 'selfhst', 'search' => 'github', 'limit' => 49], ['source' => 'selfhst', 'search' => 'github', 'page' => 0], ['source' => 'selfhst', 'search' => 'github', 'page' => 251]] as $invalid) {
        try {
            wallos_logo_catalog_validate_request($invalid);
            throw new RuntimeException('Invalid search request accepted.');
        } catch (InvalidArgumentException $expected) {
        }
    }
    $request = wallos_logo_catalog_validate_request(['source' => 'selfhst', 'search' => 'github', 'limit' => 1]);
    $first = wallos_logo_catalog_search($items, $request);
    catalogAssert(count($first['results']) === 1 && $first['has_more'] && $first['total'] === 2, 'Catalog pagination metadata failed.');
    catalogAssert($first['results'][0]['image'] === 'https://raw.githubusercontent.com/selfhst/icons/main/png/github.png', 'Catalog URL or exact-match rank failed.');
    $request['page'] = 2;
    catalogAssert(!wallos_logo_catalog_search($items, $request)['has_more'], 'Final page incorrectly advertises more results.');
    $url = wallos_logo_catalog_sources()['selfhst']['index'];
    catalogAssert(wallos_logo_catalog_target($url, fn() => ['8.8.8.8']) !== false, 'Fixed public catalog host rejected.');
    foreach ([['127.0.0.1'], ['10.0.0.1'], ['169.254.169.254'], ['::1'], ['8.8.8.8', '192.168.1.1']] as $addresses) {
        catalogAssert(wallos_logo_catalog_target($url, fn() => $addresses) === false, 'Private or mixed DNS answers accepted.');
    }
    foreach (['http://raw.githubusercontent.com/selfhst/icons/main/index.json', $url . '?q=private', 'https://raw.githubusercontent.com/evil/repo/index.json', 'https://raw.githubusercontent.com:444/selfhst/icons/main/index.json', 'https://localhost/index.json'] as $invalid) {
        catalogAssert(wallos_logo_catalog_target($invalid, fn() => ['8.8.8.8']) === false, 'Arbitrary catalog URL accepted.');
    }
    $calls = 0;
    $fetch = function ($url) use (&$calls, $body) { $calls++; return $body; };
    $now = time();
    catalogAssert(wallos_logo_catalog_load('selfhst', $fetch, $directory, $now)['stale'] === false, 'Fresh catalog load failed.');
    catalogAssert(wallos_logo_catalog_load('selfhst', $fetch, $directory, $now)['stale'] === false && $calls === 1, 'Public catalog cache was not reused.');
    touch($directory . '/selfhst.json', $now - WALLOS_LOGO_CATALOG_TTL - 1);
    clearstatcache();
    $failure = function () use (&$calls) { $calls++; return null; };
    $stale = wallos_logo_catalog_load('selfhst', $failure, $directory, $now + 61);
    catalogAssert($stale !== null && $stale['stale'] && $calls === 2, 'Bounded stale fallback failed.');
    wallos_logo_catalog_load('selfhst', $failure, $directory, $now + 62);
    catalogAssert($calls === 2, 'Outage retry cooldown was ignored.');
    touch($directory . '/selfhst.json', $now - WALLOS_LOGO_CATALOG_STALE_TTL - 100);
    clearstatcache();
    catalogAssert(wallos_logo_catalog_load('selfhst', $failure, $directory, $now + 122) === null, 'Expired catalog accepted.');
    catalogAssert(count(glob($directory . '/*')) === 2, 'Cache must contain only a public index and refresh state.');
    $endpoint = file_get_contents(__DIR__ . '/../endpoints/logos/icon_search.php');
    catalogAssert(strpos($endpoint, 'wallos_endpoint_require_authenticated') < strpos($endpoint, 'wallos_logo_catalog_load'), 'Catalog fetch precedes authentication.');
    catalogAssert(strpos($endpoint, 'validate_endpoint.php') !== false && strpos($endpoint, 'private, no-store') !== false, 'Catalog endpoint lost CSRF or private response protection.');
    $script = file_get_contents(__DIR__ . '/../scripts/logo-catalog-search.js');
    catalogAssert(strpos($script, 'innerHTML') === false && strpos($script, 'caption.textContent') !== false, 'Catalog names are rendered as HTML.');
    echo "Logo catalog parser, security, pagination, cache and endpoint contracts passed.\n";
} finally {
    foreach (glob($directory . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($directory);
}
