<?php
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/logo_search_http.php';

if (isset($_GET['search'])) {
    $searchTermRaw = $_GET['search'] . " logo";
    $searchTerm    = urlencode($searchTermRaw);

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
            'f'   => ',,,,',   // size,color,type,layout,license → all unset
            'p'   => '1',      // safesearch on
        ]);

        $response = wallos_logo_search_http_get("https://duckduckgo.com/i.js?{$params}", [
            'Accept: application/json',
            'Referer: https://duckduckgo.com/',
        ]);

        if (!$response) return null;

        $data = json_decode($response, true);
        if (!isset($data['results']) || empty($data['results'])) return null;

        return array_column($data['results'], 'image');
    }

    function fetchBraveImages($query) {
        $url  = "https://search.brave.com/images?q={$query}";
        $html = wallos_logo_search_http_get($url, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Referer: https://search.brave.com/',
        ]);

        if (!$html) return null;

        $doc = new DOMDocument();
        @$doc->loadHTML($html);

        $blockedDomains = ['cdn.search.brave.com', 'search.brave.com/static'];

        $imageUrls = [];
        $imgTags   = $doc->getElementsByTagName('img');

        foreach ($imgTags as $imgTag) {
            $src   = $imgTag->getAttribute('src');
            $class = $imgTag->getAttribute('class');

            if (str_contains($class, 'favicon') || str_contains($class, 'logo')) continue;
            if (!filter_var($src, FILTER_VALIDATE_URL)) continue;

            foreach ($blockedDomains as $blocked) {
                if (str_contains($src, $blocked)) {
                    continue 2; // skip to next <img>
                }
            }

            $imageUrls[] = $src;
        }

        return !empty($imageUrls) ? $imageUrls : null;
    }

    // Main flow: DDG first, Brave fallback
    $vqd       = getVqdToken($searchTerm);
    $imageUrls = $vqd ? fetchDDGImages($searchTerm, $vqd) : null;

    if (!$imageUrls) {
        $imageUrls = fetchBraveImages($searchTerm);
    }

    header('Content-Type: application/json');

    if ($imageUrls) {
        echo json_encode(['imageUrls' => $imageUrls]);
    } else {
        echo json_encode(['error' => 'Failed to fetch images from DuckDuckGo and Brave.']);
    }

} else {
    echo json_encode(['error' => 'Invalid request.']);
}
