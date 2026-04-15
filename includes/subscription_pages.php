<?php

if (!defined('WALLOS_SUBSCRIPTION_STATUS_ACTIVE')) {
    require_once __DIR__ . '/subscription_trash.php';
}

define('WALLOS_SUBSCRIPTION_PAGE_FILTER_ALL', 'all');
define('WALLOS_SUBSCRIPTION_PAGE_FILTER_UNASSIGNED', 'unassigned');
define('WALLOS_SUBSCRIPTION_PAGE_NAME_MAX_LENGTH', 40);

function wallos_translate_with_fallback($key, $fallback, $i18n)
{
    $translated = translate($key, $i18n);
    return $translated === '[i18n String Missing]' ? $fallback : $translated;
}

function wallos_normalize_subscription_page_name($value)
{
    $name = preg_replace('/\s+/u', ' ', trim((string) $value));
    if ($name === null) {
        $name = trim((string) $value);
    }

    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, WALLOS_SUBSCRIPTION_PAGE_NAME_MAX_LENGTH, 'UTF-8');
    }

    return substr($name, 0, WALLOS_SUBSCRIPTION_PAGE_NAME_MAX_LENGTH);
}

function wallos_normalize_subscription_page_filter($value)
{
    $rawValue = strtolower(trim((string) $value));

    if ($rawValue === '' || $rawValue === WALLOS_SUBSCRIPTION_PAGE_FILTER_ALL) {
        return [
            'mode' => WALLOS_SUBSCRIPTION_PAGE_FILTER_ALL,
            'value' => WALLOS_SUBSCRIPTION_PAGE_FILTER_ALL,
            'page_id' => 0,
        ];
    }

    if ($rawValue === WALLOS_SUBSCRIPTION_PAGE_FILTER_UNASSIGNED || $rawValue === '0') {
        return [
            'mode' => WALLOS_SUBSCRIPTION_PAGE_FILTER_UNASSIGNED,
            'value' => WALLOS_SUBSCRIPTION_PAGE_FILTER_UNASSIGNED,
            'page_id' => 0,
        ];
    }

    $pageId = (int) $value;
    if ($pageId > 0) {
        return [
            'mode' => 'page',
            'value' => (string) $pageId,
            'page_id' => $pageId,
        ];
    }

    return [
        'mode' => WALLOS_SUBSCRIPTION_PAGE_FILTER_ALL,
        'value' => WALLOS_SUBSCRIPTION_PAGE_FILTER_ALL,
        'page_id' => 0,
    ];
}

function wallos_get_subscription_page_filter_value(array $filter)
{
    return (string) ($filter['value'] ?? WALLOS_SUBSCRIPTION_PAGE_FILTER_ALL);
}

function wallos_get_subscription_page_filter_mode(array $filter)
{
    return (string) ($filter['mode'] ?? WALLOS_SUBSCRIPTION_PAGE_FILTER_ALL);
}

function wallos_subscription_page_exists($db, $userId, $pageId)
{
    $pageId = (int) $pageId;
    if ($pageId <= 0) {
        return false;
    }

    $stmt = $db->prepare('SELECT 1 FROM subscription_pages WHERE id = :id AND user_id = :user_id LIMIT 1');
    $stmt->bindValue(':id', $pageId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    return ($result ? $result->fetchArray(SQLITE3_NUM) : false) !== false;
}

function wallos_resolve_subscription_page_filter($db, $userId, $rawValue)
{
    $filter = wallos_normalize_subscription_page_filter($rawValue);
    if ($filter['mode'] === 'page' && !wallos_subscription_page_exists($db, $userId, $filter['page_id'])) {
        return wallos_normalize_subscription_page_filter(WALLOS_SUBSCRIPTION_PAGE_FILTER_ALL);
    }

    return $filter;
}

function wallos_append_subscription_page_filter_clause(&$sql, array &$params, array $filter, $placeholderPrefix = 'subscription_page')
{
    $mode = wallos_get_subscription_page_filter_mode($filter);
    if ($mode === WALLOS_SUBSCRIPTION_PAGE_FILTER_UNASSIGNED) {
        $sql .= ' AND (subscription_page_id IS NULL OR subscription_page_id = 0)';
        return;
    }

    if ($mode === 'page') {
        $placeholder = ':' . $placeholderPrefix . '_id';
        $sql .= ' AND subscription_page_id = ' . $placeholder;
        $params[$placeholder] = (int) ($filter['page_id'] ?? 0);
    }
}

function wallos_get_next_subscription_page_sort_order($db, $userId)
{
    $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort_order FROM subscription_pages WHERE user_id = :user_id');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    return max(1, (int) ($row['next_sort_order'] ?? 1));
}

function wallos_get_subscription_pages($db, $userId)
{
    $pages = [];

    $stmt = $db->prepare('
        SELECT id, user_id, name, sort_order, created_at, updated_at
        FROM subscription_pages
        WHERE user_id = :user_id
        ORDER BY sort_order ASC, id ASC
    ');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $pages[] = [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $pages;
}

function wallos_get_subscription_pages_payload($db, $userId, $hideDisabled = false)
{
    $pages = wallos_get_subscription_pages($db, $userId);
    $countsByPageId = [];
    $totalCount = 0;
    $unassignedCount = 0;

    $countSql = '
        SELECT subscription_page_id, COUNT(*) AS total_count
        FROM subscriptions
        WHERE user_id = :user_id
          AND lifecycle_status = :lifecycle_status
    ';

    if ($hideDisabled) {
        $countSql .= ' AND inactive = 0';
    }

    $countSql .= ' GROUP BY subscription_page_id';

    $countStmt = $db->prepare($countSql);
    $countStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $countStmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
    $countResult = $countStmt->execute();

    while ($countResult && ($row = $countResult->fetchArray(SQLITE3_ASSOC))) {
        $pageId = (int) ($row['subscription_page_id'] ?? 0);
        $count = (int) ($row['total_count'] ?? 0);
        $totalCount += $count;

        if ($pageId <= 0) {
            $unassignedCount += $count;
            continue;
        }

        $countsByPageId[$pageId] = $count;
    }

    foreach ($pages as &$page) {
        $page['subscription_count'] = (int) ($countsByPageId[$page['id']] ?? 0);
    }
    unset($page);

    return [
        'pages' => $pages,
        'counts' => [
            'all' => $totalCount,
            'unassigned' => $unassignedCount,
        ],
    ];
}

function wallos_validate_subscription_page_name($db, $userId, $name, $i18n, $excludePageId = 0)
{
    $normalizedName = wallos_normalize_subscription_page_name($name);
    if ($normalizedName === '') {
        throw new RuntimeException(wallos_translate_with_fallback('subscription_page_name_required', 'Please enter a page name.', $i18n));
    }

    $length = function_exists('mb_strlen')
        ? mb_strlen($normalizedName, 'UTF-8')
        : strlen($normalizedName);
    if ($length > WALLOS_SUBSCRIPTION_PAGE_NAME_MAX_LENGTH) {
        throw new RuntimeException(sprintf(
            wallos_translate_with_fallback('subscription_page_name_too_long', 'Page name cannot exceed %d characters.', $i18n),
            WALLOS_SUBSCRIPTION_PAGE_NAME_MAX_LENGTH
        ));
    }

    $stmt = $db->prepare('
        SELECT id
        FROM subscription_pages
        WHERE user_id = :user_id
          AND LOWER(name) = LOWER(:name)
          AND id != :exclude_id
        LIMIT 1
    ');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':name', $normalizedName, SQLITE3_TEXT);
    $stmt->bindValue(':exclude_id', (int) $excludePageId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if (($result ? $result->fetchArray(SQLITE3_NUM) : false) !== false) {
        throw new RuntimeException(wallos_translate_with_fallback('subscription_page_name_exists', 'A page with this name already exists.', $i18n));
    }

    return $normalizedName;
}

function wallos_resolve_subscription_page_assignment($db, $userId, $rawValue, $i18n)
{
    $filter = wallos_normalize_subscription_page_filter($rawValue);
    if ($filter['mode'] === WALLOS_SUBSCRIPTION_PAGE_FILTER_ALL || $filter['mode'] === WALLOS_SUBSCRIPTION_PAGE_FILTER_UNASSIGNED) {
        return null;
    }

    if (!wallos_subscription_page_exists($db, $userId, $filter['page_id'])) {
        throw new RuntimeException(wallos_translate_with_fallback('subscription_page_invalid', 'The selected page does not exist.', $i18n));
    }

    return (int) $filter['page_id'];
}
