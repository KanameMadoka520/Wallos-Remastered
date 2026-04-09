<?php

require_once __DIR__ . '/user_groups.php';

define('WALLOS_SUBSCRIPTION_IMAGE_MAX_BYTES', 10 * 1024 * 1024);
define('WALLOS_SUBSCRIPTION_IMAGE_MAX_EXTERNAL_URLS', 10);
define('WALLOS_SUBSCRIPTION_IMAGE_MAX_WIDTH', 8000);
define('WALLOS_SUBSCRIPTION_IMAGE_MAX_HEIGHT', 8000);
define('WALLOS_SUBSCRIPTION_IMAGE_MAX_PIXELS', 40000000);
define('WALLOS_SUBSCRIPTION_IMAGE_COMPRESSED_MAX_DIMENSION', 2200);

function wallos_get_subscription_media_relative_dir()
{
    return 'images/uploads/logos/subscription-media/';
}

function wallos_get_subscription_media_disk_dir($basePath)
{
    return rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, wallos_get_subscription_media_relative_dir());
}

function wallos_ensure_subscription_media_directory($basePath)
{
    $directory = wallos_get_subscription_media_disk_dir($basePath);

    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    return $directory;
}

function wallos_sanitize_subscription_media_filename($filename)
{
    $filename = preg_replace('/[^a-zA-Z0-9\s_-]/', '', (string) $filename);
    $filename = preg_replace('/\s+/', '-', trim($filename));
    $filename = trim($filename, '-_');

    if ($filename === '') {
        return 'subscription-image';
    }

    return strtolower($filename);
}

function wallos_get_subscription_media_extension_from_mime($mimeType)
{
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    return $map[$mimeType] ?? null;
}

function wallos_get_subscription_media_allowed_mime_types()
{
    return ['image/jpeg', 'image/png', 'image/webp'];
}

function wallos_decode_subscription_image_urls($rawValue)
{
    if (is_array($rawValue)) {
        $urls = $rawValue;
    } else {
        $decoded = json_decode((string) $rawValue, true);
        $urls = is_array($decoded) ? $decoded : [];
    }

    $sanitized = [];
    foreach ($urls as $url) {
        if (!is_string($url)) {
            continue;
        }

        $trimmed = trim($url);
        if ($trimmed === '') {
            continue;
        }

        $sanitized[] = $trimmed;
    }

    return array_values(array_unique($sanitized));
}

function wallos_encode_subscription_image_urls(array $urls)
{
    return json_encode(array_values($urls), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function wallos_parse_subscription_image_urls($rawValue, $i18n)
{
    if (is_array($rawValue)) {
        $lines = $rawValue;
    } else {
        $lines = preg_split('/\r\n|\r|\n/', (string) $rawValue);
    }

    $urls = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        if (strlen($line) > 2048) {
            throw new RuntimeException(translate('subscription_image_invalid_url', $i18n));
        }

        if (!filter_var($line, FILTER_VALIDATE_URL)) {
            throw new RuntimeException(translate('subscription_image_invalid_url', $i18n));
        }

        $parts = parse_url($line);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException(translate('subscription_image_invalid_url', $i18n));
        }

        $urls[] = $line;
    }

    $urls = array_values(array_unique($urls));

    if (count($urls) > WALLOS_SUBSCRIPTION_IMAGE_MAX_EXTERNAL_URLS) {
        throw new RuntimeException(translate('subscription_image_url_limit', $i18n));
    }

    return $urls;
}

function wallos_validate_uploaded_subscription_image(array $uploadedFile, $i18n)
{
    if (!isset($uploadedFile['error']) || $uploadedFile['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(translate('subscription_image_processing_failed', $i18n));
    }

    if (($uploadedFile['size'] ?? 0) > WALLOS_SUBSCRIPTION_IMAGE_MAX_BYTES) {
        throw new RuntimeException(translate('subscription_image_too_large', $i18n));
    }

    if (!is_uploaded_file($uploadedFile['tmp_name'])) {
        throw new RuntimeException(translate('subscription_image_processing_failed', $i18n));
    }

    $imageInfo = @getimagesize($uploadedFile['tmp_name']);
    if ($imageInfo === false || empty($imageInfo['mime'])) {
        throw new RuntimeException(translate('subscription_image_invalid_type', $i18n));
    }

    $mimeType = $imageInfo['mime'];
    if (!in_array($mimeType, wallos_get_subscription_media_allowed_mime_types(), true)) {
        throw new RuntimeException(translate('subscription_image_invalid_type', $i18n));
    }

    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);
    $pixels = $width * $height;

    if (
        $width < 1
        || $height < 1
        || $width > WALLOS_SUBSCRIPTION_IMAGE_MAX_WIDTH
        || $height > WALLOS_SUBSCRIPTION_IMAGE_MAX_HEIGHT
        || $pixels > WALLOS_SUBSCRIPTION_IMAGE_MAX_PIXELS
    ) {
        throw new RuntimeException(translate('subscription_image_dimensions_error', $i18n));
    }

    return [
        'mime' => $mimeType,
        'width' => $width,
        'height' => $height,
        'extension' => wallos_get_subscription_media_extension_from_mime($mimeType),
    ];
}

function wallos_load_subscription_image_resource($tmpName, $mimeType)
{
    if ($mimeType === 'image/jpeg') {
        return imagecreatefromjpeg($tmpName);
    }

    if ($mimeType === 'image/png') {
        return imagecreatefrompng($tmpName);
    }

    if ($mimeType === 'image/webp') {
        return imagecreatefromwebp($tmpName);
    }

    return false;
}

function wallos_prepare_subscription_image_canvas($width, $height, $mimeType)
{
    $canvas = imagecreatetruecolor($width, $height);

    if ($mimeType !== 'image/jpeg') {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);
    }

    return $canvas;
}

function wallos_write_subscription_image_resource($image, $destination, $mimeType, $compressImage)
{
    if ($mimeType === 'image/jpeg') {
        $quality = $compressImage ? 82 : 100;
        return imagejpeg($image, $destination, $quality);
    }

    if ($mimeType === 'image/png') {
        $quality = $compressImage ? 6 : 0;
        return imagepng($image, $destination, $quality);
    }

    if ($mimeType === 'image/webp') {
        $quality = $compressImage ? 80 : 100;
        return imagewebp($image, $destination, $quality);
    }

    return false;
}

function wallos_store_uploaded_subscription_image(array $uploadedFile, $subscriptionName, $basePath, $compressImage, $i18n)
{
    $metadata = wallos_validate_uploaded_subscription_image($uploadedFile, $i18n);
    if ($metadata === null) {
        return '';
    }

    $sourceImage = wallos_load_subscription_image_resource($uploadedFile['tmp_name'], $metadata['mime']);
    if ($sourceImage === false) {
        throw new RuntimeException(translate('subscription_image_processing_failed', $i18n));
    }

    $targetWidth = $metadata['width'];
    $targetHeight = $metadata['height'];

    if ($compressImage) {
        $scale = min(
            1,
            WALLOS_SUBSCRIPTION_IMAGE_COMPRESSED_MAX_DIMENSION / max($metadata['width'], 1),
            WALLOS_SUBSCRIPTION_IMAGE_COMPRESSED_MAX_DIMENSION / max($metadata['height'], 1)
        );

        $targetWidth = max(1, (int) floor($metadata['width'] * $scale));
        $targetHeight = max(1, (int) floor($metadata['height'] * $scale));
    }

    $imageToWrite = $sourceImage;
    if ($targetWidth !== $metadata['width'] || $targetHeight !== $metadata['height']) {
        $resizedImage = wallos_prepare_subscription_image_canvas($targetWidth, $targetHeight, $metadata['mime']);
        imagecopyresampled(
            $resizedImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $metadata['width'],
            $metadata['height']
        );
        $imageToWrite = $resizedImage;
    }

    $directory = wallos_ensure_subscription_media_directory($basePath);
    $fileName = sprintf(
        '%s-%s-%s.%s',
        date('YmdHis'),
        bin2hex(random_bytes(6)),
        wallos_sanitize_subscription_media_filename($subscriptionName),
        $metadata['extension']
    );
    $destination = $directory . $fileName;

    $writeResult = wallos_write_subscription_image_resource($imageToWrite, $destination, $metadata['mime'], $compressImage);

    if ($imageToWrite !== $sourceImage) {
        imagedestroy($imageToWrite);
    }
    imagedestroy($sourceImage);

    if (!$writeResult) {
        throw new RuntimeException(translate('subscription_image_processing_failed', $i18n));
    }

    return wallos_get_subscription_media_relative_dir() . $fileName;
}

function wallos_subscription_image_path_is_within_media_dir($relativePath)
{
    $relativePath = str_replace('\\', '/', trim((string) $relativePath));
    return $relativePath !== '' && strpos($relativePath, wallos_get_subscription_media_relative_dir()) === 0;
}

function wallos_delete_subscription_image_file($basePath, $relativePath)
{
    if (!wallos_subscription_image_path_is_within_media_dir($relativePath)) {
        return;
    }

    $fullPath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (is_file($fullPath)) {
        unlink($fullPath);
    }
}

function wallos_delete_subscription_image_if_unused($db, $basePath, $relativePath)
{
    if (!wallos_subscription_image_path_is_within_media_dir($relativePath)) {
        return;
    }

    $stmt = $db->prepare('SELECT COUNT(*) AS count FROM subscriptions WHERE detail_image = :detailImage');
    $stmt->bindValue(':detailImage', $relativePath, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    $count = (int) ($row['count'] ?? 0);

    if ($count === 0) {
        wallos_delete_subscription_image_file($basePath, $relativePath);
    }
}

function wallos_collect_user_subscription_images($db, $userId)
{
    $stmt = $db->prepare('SELECT detail_image FROM subscriptions WHERE user_id = :userId AND detail_image IS NOT NULL AND detail_image != ""');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $images = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $detailImage = $row['detail_image'] ?? '';
        if (wallos_subscription_image_path_is_within_media_dir($detailImage)) {
            $images[] = $detailImage;
        }
    }

    return array_values(array_unique($images));
}
