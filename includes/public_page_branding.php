<?php

require_once __DIR__ . '/custom_edition.php';

function wallos_get_public_page_branding($db)
{
    $fallback = [
        'title' => 'Remastered',
        'subtitle' => '基于wallos原版深度魔改',
    ];

    if (!isset($db)) {
        return $fallback;
    }

    $result = $db->query('SELECT custom_edition_title, custom_edition_subtitle FROM admin LIMIT 1');
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    if ($row === false) {
        return $fallback;
    }

    return [
        'title' => wallos_normalize_custom_edition_value($row['custom_edition_title'] ?? '', $fallback['title']),
        'subtitle' => wallos_normalize_custom_edition_value($row['custom_edition_subtitle'] ?? '', $fallback['subtitle']),
    ];
}
