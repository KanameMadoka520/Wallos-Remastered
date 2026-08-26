<?php
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/logo_search_http.php';
if (isset($_GET['search'])) {
    $searchTerm = urlencode($_GET['search'] . " logo");

    function getVqdToken($query) {
        $html = wallos_logo_search_http_get("https://duckduckgo.com/?q={$query}&ia=images");
        if ($html && preg_match('/vqd="?([\d-]+)"?/', $html, $matches)) {
            return $matches[1];
        }
        return null;
    }

    function fetchDDGImages($query, $vqd) {
        $params = http_build_query([
            'l'   => 'us-en',
            'o'   => 'json',
            'q'   => urldecode($query),
            'vqd' => $vqd,
            'f'   => ',,transparent,Wide,',
            'p'   => '1',
        ]);

        $response = wallos_logo_search_http_get("https://duckduckgo.com/i.js?{$params}", [
            'Accept: application/json',
            'Referer: https://duckduckgo.com/',
        ]);

        if (!$response) return null;

        $data = json_decode($response, true);
        if (!isset($data['results']) || empty($data['results'])) return null;

        $out = [];
        foreach ($data['results'] as $row) {
            $out[] = [
                'thumbnail' => $row['thumbnail'] ?? $row['image'] ?? null,
                'image'     => $row['image'] ?? null,
                'width'     => $row['width'] ?? null,
                'height'    => $row['height'] ?? null,
            ];
        }
        return $out;
    }

    function fetchBraveImages($query) {
    $url = "https://search.brave.com/images?q={$query}";
    $html = wallos_logo_search_http_get($url, [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.5',
        'Referer: https://search.brave.com/',
    ]);

    if (!$html) return null;

    $doc = new DOMDocument();
    @$doc->loadHTML($html);

    $imageUrls = [];
    $imgTags = $doc->getElementsByTagName('img');
    foreach ($imgTags as $imgTag) {
        $src = $imgTag->getAttribute('src');
        $class = $imgTag->getAttribute('class');

        if (str_contains($class, 'favicon') || str_contains($class, 'logo')) continue;
        if (!filter_var($src, FILTER_VALIDATE_URL)) continue;
        if (str_contains($src, 'cdn.search.brave.com')) continue;  // filter Brave UI assets

        $imageUrls[] = $src;
    }

    return !empty($imageUrls) ? $imageUrls : null;
}

    // --- Main flow ---

    // Try DuckDuckGo first
    $vqd = getVqdToken($searchTerm);
    $results = $vqd ? fetchDDGImages($searchTerm, $vqd) : null;

    if (!$results) {
        $braveUrls = fetchBraveImages($searchTerm);
        if ($braveUrls) {
            $results = array_map(function($url) {
                return [
                    'thumbnail' => $url,
                    'image'     => $url,
                    'width'     => null,
                    'height'    => null,
                ];
            }, $braveUrls);
        }
    }

    header('Content-Type: application/json');

    if ($results) {
        echo json_encode(['results' => $results]);
    } else {
        echo json_encode(['error' => 'Failed to fetch images from both DuckDuckGo and Brave.']);
    }

} else {
    echo json_encode(['error' => 'Invalid request.']);
}
?>
