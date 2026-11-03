<?php

require_once __DIR__ . '/logo_search_http.php';

const WALLOS_LOGO_CATALOG_MAX_BYTES = 2097152;
const WALLOS_LOGO_CATALOG_MAX_ENTRIES = 12000;
const WALLOS_LOGO_CATALOG_TTL = 3600;
const WALLOS_LOGO_CATALOG_STALE_TTL = 86400;
const WALLOS_LOGO_CATALOG_RETRY_DELAY = 60;

function wallos_logo_catalog_sources()
{
    return [
        'selfhst' => [
            'name' => 'selfh.st',
            'index' => 'https://raw.githubusercontent.com/selfhst/icons/main/index.json',
            'images' => 'https://raw.githubusercontent.com/selfhst/icons/main/png/',
        ],
        'dashboardicons' => [
            'name' => 'Dashboard Icons',
            'index' => 'https://raw.githubusercontent.com/homarr-labs/dashboard-icons/main/tree.json',
            'images' => 'https://raw.githubusercontent.com/homarr-labs/dashboard-icons/main/png/',
        ],
    ];
}

function wallos_logo_catalog_validate_request(array $input)
{
    $provider = $input['source'] ?? '';
    $query = $input['search'] ?? '';
    if (!is_string($provider) || !isset(wallos_logo_catalog_sources()[$provider])
        || !is_string($query) || strlen($query) > 256
        || !preg_match('//u', $query) || preg_match('/[\x00-\x1f\x7f]/', $query)
    ) {
        throw new InvalidArgumentException('Invalid catalog search.');
    }
    $query = trim($query);
    if ($query === '' || preg_match_all('/./us', $query) > 128) {
        throw new InvalidArgumentException('Enter an icon name of 1–128 characters.');
    }
    $page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 250]]);
    $limit = filter_var($input['limit'] ?? 24, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 48]]);
    if ($page === false || $limit === false) {
        throw new InvalidArgumentException('Invalid catalog page.');
    }
    return ['source' => $provider, 'search' => $query, 'page' => $page, 'limit' => $limit];
}

function wallos_logo_catalog_slug_valid($slug)
{
    return is_string($slug) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}\z/D', $slug)
        && strpos($slug, '..') === false;
}

function wallos_logo_catalog_parse($provider, $body)
{
    if (!isset(wallos_logo_catalog_sources()[$provider]) || !is_string($body)
        || strlen($body) > WALLOS_LOGO_CATALOG_MAX_BYTES
    ) {
        return null;
    }
    $index = json_decode($body, true, 16);
    if (!is_array($index) || json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    $rows = $provider === 'selfhst' ? $index : ($index['png'] ?? null);
    if (!is_array($rows) || !array_is_list($rows) || !$rows || count($rows) > WALLOS_LOGO_CATALOG_MAX_ENTRIES) {
        return null;
    }
    $items = [];
    foreach ($rows as $row) {
        if ($provider === 'selfhst') {
            if (!is_array($row) || ($row['PNG'] ?? '') !== 'Yes') {
                continue;
            }
            $slug = $row['Reference'] ?? '';
            $name = $row['Name'] ?? $slug;
        } else {
            if (!is_string($row) || substr($row, -4) !== '.png') {
                continue;
            }
            $slug = substr($row, 0, -4);
            if (preg_match('/-(light|dark)$/', $slug)) {
                continue;
            }
            $name = $slug;
        }
        if (!wallos_logo_catalog_slug_valid($slug) || !is_string($name)
            || strlen($name) > 256 || !preg_match('//u', $name)
            || preg_match('/[\x00-\x1f\x7f]/', $name)
        ) {
            continue;
        }
        $items[$slug] = ['slug' => $slug, 'name' => $name];
    }
    return $items ? array_values($items) : null;
}

function wallos_logo_catalog_target($url, $resolver = null)
{
    $urls = array_column(wallos_logo_catalog_sources(), 'index');
    // No caller-supplied URLs, alternate ports, credentials, query strings or redirects.
    if (!in_array($url, $urls, true)) {
        return false;
    }
    return wallos_logo_search_validate_url($url, ['raw.githubusercontent.com'], $resolver);
}

function wallos_logo_catalog_fetch($url)
{
    $target = wallos_logo_catalog_target($url);
    if ($target === false) {
        return null;
    }
    $body = '';
    $ch = curl_init($target['url']);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
        CURLOPT_RESOLVE => [$target['resolve']],
        CURLOPT_USERAGENT => 'Wallos-Remastered Icon Catalog',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body) {
            if (strlen($body) + strlen($chunk) > WALLOS_LOGO_CATALOG_MAX_BYTES) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    if (defined('CURLOPT_PROTOCOLS')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    }
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $ok !== false && $status === 200 && $body !== '' ? $body : null;
}

function wallos_logo_catalog_cache_directory()
{
    $directory = sys_get_temp_dir() . '/wallos-public-icon-catalog-' . substr(hash('sha256', __DIR__), 0, 16);
    if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700, true))) {
        return null;
    }
    // Cache only public upstream indexes. No search terms or account data enter these files.
    return $directory;
}

function wallos_logo_catalog_read_cache($path, $provider, $maxAge, $now)
{
    if (!is_file($path) || is_link($path) || filesize($path) > WALLOS_LOGO_CATALOG_MAX_BYTES
        || $now - (int) filemtime($path) > $maxAge
    ) {
        return null;
    }
    return wallos_logo_catalog_parse($provider, @file_get_contents($path));
}

function wallos_logo_catalog_load($provider, $fetcher = null, $directory = null, $now = null)
{
    $source = wallos_logo_catalog_sources()[$provider] ?? null;
    if ($source === null) {
        return null;
    }
    $now = $now ?? time();
    $directory = $directory ?? wallos_logo_catalog_cache_directory();
    $path = $directory ? $directory . '/' . $provider . '.json' : null;
    $cached = $path ? wallos_logo_catalog_read_cache($path, $provider, WALLOS_LOGO_CATALOG_TTL, $now) : null;
    if ($cached !== null) {
        return ['items' => $cached, 'stale' => false];
    }
    // Serialize refreshes. A failed refresh is retried at most once per minute.
    $lock = $path && !is_link($path . '.lock') ? @fopen($path . '.lock', 'c+') : false;
    if ($path && $lock === false) {
        return null;
    }
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        $stale = wallos_logo_catalog_read_cache($path, $provider, WALLOS_LOGO_CATALOG_STALE_TTL, $now);
        return $stale !== null ? ['items' => $stale, 'stale' => true] : null;
    }
    try {
        $cached = $path ? wallos_logo_catalog_read_cache($path, $provider, WALLOS_LOGO_CATALOG_TTL, $now) : null;
        if ($cached !== null) {
            return ['items' => $cached, 'stale' => false];
        }
        $lastAttempt = $lock ? (int) stream_get_contents($lock) : 0;
        if ($now - $lastAttempt >= WALLOS_LOGO_CATALOG_RETRY_DELAY) {
            if ($lock) {
                ftruncate($lock, 0);
                rewind($lock);
                fwrite($lock, (string) $now);
                fflush($lock);
            }
            $body = $fetcher ? call_user_func($fetcher, $source['index']) : wallos_logo_catalog_fetch($source['index']);
            $items = wallos_logo_catalog_parse($provider, $body);
            if ($items !== null) {
                if ($path) {
                    $temporary = tempnam($directory, 'catalog-');
                    if ($temporary !== false) {
                        if (file_put_contents($temporary, $body) === strlen($body)) {
                            @chmod($temporary, 0600);
                            @rename($temporary, $path);
                        }
                        @unlink($temporary);
                    }
                }
                return ['items' => $items, 'stale' => false];
            }
        }
        $stale = $path ? wallos_logo_catalog_read_cache($path, $provider, WALLOS_LOGO_CATALOG_STALE_TTL, $now) : null;
        return $stale !== null ? ['items' => $stale, 'stale' => true] : null;
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

function wallos_logo_catalog_search(array $items, array $request)
{
    $query = $request['search'];
    $matches = array_values(array_filter($items, function ($item) use ($query) {
        return stripos($item['name'], $query) !== false || stripos($item['slug'], $query) !== false;
    }));
    usort($matches, function ($a, $b) use ($query) {
        $aExact = strcasecmp($a['slug'], $query) === 0 || strcasecmp($a['name'], $query) === 0;
        $bExact = strcasecmp($b['slug'], $query) === 0 || strcasecmp($b['name'], $query) === 0;
        return ($bExact <=> $aExact) ?: (strlen($a['slug']) <=> strlen($b['slug'])) ?: strcmp($a['slug'], $b['slug']);
    });
    $source = wallos_logo_catalog_sources()[$request['source']];
    $offset = ($request['page'] - 1) * $request['limit'];
    $results = array_map(function ($item) use ($source, $request) {
        $url = $source['images'] . rawurlencode($item['slug']) . '.png';
        return [
            'name' => $item['name'], 'source' => $source['name'], 'provider' => $request['source'],
            'thumbnail' => $url, 'image' => $url, 'width' => null, 'height' => null,
        ];
    }, array_slice($matches, $offset, $request['limit']));
    return [
        'success' => true, 'results' => $results, 'source' => $source['name'],
        'page' => $request['page'], 'limit' => $request['limit'], 'total' => count($matches),
        'has_more' => $request['page'] < 250 && $offset + $request['limit'] < count($matches),
    ];
}
