<?php

$subscriptionImageColumns = [];
$subscriptionImageColumnsResult = $db->query("PRAGMA table_info(subscription_uploaded_images)");
while ($subscriptionImageColumnsResult && ($row = $subscriptionImageColumnsResult->fetchArray(SQLITE3_ASSOC))) {
    $subscriptionImageColumns[] = $row['name'];
}

if (!in_array('preview_path', $subscriptionImageColumns, true)) {
    $db->exec("ALTER TABLE subscription_uploaded_images ADD COLUMN preview_path TEXT DEFAULT ''");
}

if (!in_array('thumbnail_path', $subscriptionImageColumns, true)) {
    $db->exec("ALTER TABLE subscription_uploaded_images ADD COLUMN thumbnail_path TEXT DEFAULT ''");
}

if (!in_array('sort_order', $subscriptionImageColumns, true)) {
    $db->exec('ALTER TABLE subscription_uploaded_images ADD COLUMN sort_order INTEGER DEFAULT 0');
}

$db->exec("UPDATE subscription_uploaded_images SET preview_path = '' WHERE preview_path IS NULL");
$db->exec("UPDATE subscription_uploaded_images SET thumbnail_path = '' WHERE thumbnail_path IS NULL");
$db->exec("
    UPDATE subscription_uploaded_images
    SET sort_order = CASE
        WHEN upload_sequence IS NOT NULL AND upload_sequence > 0 THEN upload_sequence
        ELSE id
    END
    WHERE sort_order IS NULL OR sort_order < 1
");
