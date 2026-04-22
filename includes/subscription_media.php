<?php

require_once __DIR__ . '/user_groups.php';

define('WALLOS_SUBSCRIPTION_IMAGE_DEFAULT_MAX_MB', 10);
define('WALLOS_SUBSCRIPTION_IMAGE_DEFAULT_EXTERNAL_URL_LIMIT', 10);
define('WALLOS_SUBSCRIPTION_IMAGE_DEFAULT_TRUSTED_UPLOAD_LIMIT', 1);
define('WALLOS_SUBSCRIPTION_IMAGE_MAX_EXTERNAL_URL_LIMIT', 50);
define('WALLOS_SUBSCRIPTION_IMAGE_MAX_TRUSTED_UPLOAD_LIMIT', 20);
define('WALLOS_SUBSCRIPTION_IMAGE_MAX_MAX_MB', 50);
define('WALLOS_SUBSCRIPTION_IMAGE_MAX_WIDTH', 8000);
define('WALLOS_SUBSCRIPTION_IMAGE_MAX_HEIGHT', 8000);
define('WALLOS_SUBSCRIPTION_IMAGE_MAX_PIXELS', 40000000);
define('WALLOS_SUBSCRIPTION_IMAGE_COMPRESSED_MAX_DIMENSION', 2200);
define('WALLOS_SUBSCRIPTION_IMAGE_PREVIEW_MAX_DIMENSION', 1600);
define('WALLOS_SUBSCRIPTION_IMAGE_THUMBNAIL_MAX_DIMENSION', 420);

function wallos_get_subscription_media_relative_dir()
{
    return 'images/uploads/logos/subscription-media/';
}

function wallos_normalize_subscription_image_variant($variant)
{
    $variant = strtolower(trim((string) $variant));
    if ($variant === 'thumb' || $variant === 'thumbnail') {
        return 'thumbnail';
    }

    if ($variant === 'preview') {
        return 'preview';
    }

    return 'original';
}

function wallos_get_subscription_uploaded_image_access_url($imageId, $variant = 'original', $download = false)
{
    $query = [
        'id' => (int) $imageId,
        'variant' => wallos_normalize_subscription_image_variant($variant),
    ];
    if ($download) {
        $query['download'] = 1;
    }

    return 'endpoints/media/subscriptionimage.php?' . http_build_query($query);
}

function wallos_append_subscription_uploaded_image_urls(array $image)
{
    $imageId = (int) ($image['id'] ?? 0);
    if ($imageId > 0) {
        $image['thumbnail_url'] = wallos_get_subscription_uploaded_image_access_url($imageId, 'thumbnail', false);
        $image['preview_url'] = wallos_get_subscription_uploaded_image_access_url($imageId, 'preview', false);
        $image['original_url'] = wallos_get_subscription_uploaded_image_access_url($imageId, 'original', false);
        $image['access_url'] = $image['preview_url'];
        $image['download_url'] = wallos_get_subscription_uploaded_image_access_url($imageId, 'original', true);
    } else {
        $image['thumbnail_url'] = '';
        $image['preview_url'] = '';
        $image['original_url'] = '';
        $image['access_url'] = '';
        $image['download_url'] = '';
    }

    $basePath = dirname(__DIR__);
    $originalSize = wallos_get_subscription_uploaded_image_size_payload(
        $basePath,
        (string) ($image['path'] ?? ''),
        isset($image['file_size']) ? (int) $image['file_size'] : null
    );
    $previewSize = wallos_get_subscription_uploaded_image_size_payload(
        $basePath,
        (string) ($image['preview_path'] ?? '')
    );
    $thumbnailSize = wallos_get_subscription_uploaded_image_size_payload(
        $basePath,
        (string) ($image['thumbnail_path'] ?? '')
    );

    $image['original_size_bytes'] = $originalSize['bytes'];
    $image['original_size_label'] = $originalSize['label'];
    $image['preview_size_bytes'] = $previewSize['bytes'];
    $image['preview_size_label'] = $previewSize['label'];
    $image['thumbnail_size_bytes'] = $thumbnailSize['bytes'];
    $image['thumbnail_size_label'] = $thumbnailSize['label'];
    $image['variant_sizes'] = [
        'thumbnail' => $thumbnailSize,
        'preview' => $previewSize,
        'original' => $originalSize,
    ];

    return $image;
}

function wallos_format_subscription_uploaded_image_size($bytes)
{
    $bytes = max(0, (int) $bytes);
    if ($bytes <= 0) {
        return '';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float) $bytes;
    $unitIndex = 0;

    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }

    return number_format($size, $unitIndex === 0 ? 0 : 1) . ' ' . $units[$unitIndex];
}

function wallos_get_subscription_uploaded_image_size_payload($basePath, $relativePath, $fallbackBytes = null)
{
    $bytes = 0;

    $absolutePath = wallos_resolve_subscription_image_absolute_path($basePath, $relativePath);
    if ($absolutePath !== '') {
        $bytes = (int) @filesize($absolutePath);
    } elseif ($fallbackBytes !== null) {
        $bytes = max(0, (int) $fallbackBytes);
    }

    return [
        'bytes' => $bytes,
        'label' => wallos_format_subscription_uploaded_image_size($bytes),
    ];
}

function wallos_get_subscription_media_disk_dir($basePath, $userId = null)
{
    $baseDirectory = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, wallos_get_subscription_media_relative_dir());

    if ($userId === null || $userId <= 0) {
        return $baseDirectory;
    }

    return $baseDirectory . 'user-' . (int) $userId . DIRECTORY_SEPARATOR;
}

function wallos_get_subscription_media_variant_disk_dir($basePath, $userId)
{
    return wallos_get_subscription_media_disk_dir($basePath, $userId) . 'derived' . DIRECTORY_SEPARATOR;
}

function wallos_ensure_subscription_media_directory($basePath, $userId = null)
{
    $directory = wallos_get_subscription_media_disk_dir($basePath, $userId);

    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    return $directory;
}

function wallos_ensure_subscription_media_variant_directory($basePath, $userId)
{
    $directory = wallos_get_subscription_media_variant_disk_dir($basePath, $userId);

    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    return $directory;
}

function wallos_get_subscription_media_variant_relative_path($userId, $fileName, $variant)
{
    $variant = wallos_normalize_subscription_image_variant($variant);
    $extension = pathinfo($fileName, PATHINFO_EXTENSION);
    $baseName = pathinfo($fileName, PATHINFO_FILENAME);

    return wallos_get_subscription_media_relative_dir()
        . 'user-' . (int) $userId
        . '/derived/'
        . $baseName
        . '--'
        . $variant
        . '.'
        . $extension;
}

function wallos_get_subscription_media_allowed_extensions()
{
    return ['jpg', 'jpeg', 'png', 'webp'];
}

function wallos_get_subscription_media_allowed_extension_label()
{
    return '.jpg, .jpeg, .png, .webp';
}

function wallos_get_subscription_media_allowed_mime_types()
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

function wallos_get_subscription_media_accept_attribute()
{
    return '.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp';
}

function wallos_get_subscription_media_policy($db)
{
    $row = $db->querySingle(
        'SELECT subscription_image_external_url_limit, trusted_subscription_upload_limit, subscription_image_max_size_mb FROM admin WHERE id = 1',
        true
    );

    $externalUrlLimit = (int) ($row['subscription_image_external_url_limit'] ?? WALLOS_SUBSCRIPTION_IMAGE_DEFAULT_EXTERNAL_URL_LIMIT);
    $trustedUploadLimit = (int) ($row['trusted_subscription_upload_limit'] ?? WALLOS_SUBSCRIPTION_IMAGE_DEFAULT_TRUSTED_UPLOAD_LIMIT);
    $maxSizeMb = (int) ($row['subscription_image_max_size_mb'] ?? WALLOS_SUBSCRIPTION_IMAGE_DEFAULT_MAX_MB);

    if ($externalUrlLimit < 1) {
        $externalUrlLimit = WALLOS_SUBSCRIPTION_IMAGE_DEFAULT_EXTERNAL_URL_LIMIT;
    }
    if ($externalUrlLimit > WALLOS_SUBSCRIPTION_IMAGE_MAX_EXTERNAL_URL_LIMIT) {
        $externalUrlLimit = WALLOS_SUBSCRIPTION_IMAGE_MAX_EXTERNAL_URL_LIMIT;
    }

    if ($trustedUploadLimit < 0) {
        $trustedUploadLimit = WALLOS_SUBSCRIPTION_IMAGE_DEFAULT_TRUSTED_UPLOAD_LIMIT;
    }
    if ($trustedUploadLimit > WALLOS_SUBSCRIPTION_IMAGE_MAX_TRUSTED_UPLOAD_LIMIT) {
        $trustedUploadLimit = WALLOS_SUBSCRIPTION_IMAGE_MAX_TRUSTED_UPLOAD_LIMIT;
    }

    if ($maxSizeMb < 1) {
        $maxSizeMb = WALLOS_SUBSCRIPTION_IMAGE_DEFAULT_MAX_MB;
    }
    if ($maxSizeMb > WALLOS_SUBSCRIPTION_IMAGE_MAX_MAX_MB) {
        $maxSizeMb = WALLOS_SUBSCRIPTION_IMAGE_MAX_MAX_MB;
    }

    return [
        'external_url_limit' => $externalUrlLimit,
        'trusted_upload_limit' => $trustedUploadLimit,
        'max_size_mb' => $maxSizeMb,
        'max_size_bytes' => $maxSizeMb * 1024 * 1024,
        'allowed_extensions' => wallos_get_subscription_media_allowed_extensions(),
        'allowed_extensions_label' => wallos_get_subscription_media_allowed_extension_label(),
    ];
}

function wallos_sanitize_subscription_media_filename_part($value)
{
    $value = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower(trim((string) $value)));
    $value = preg_replace('/-+/', '-', $value);
    $value = trim($value, '-_');

    return $value !== '' ? $value : 'subscription-image';
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

function wallos_parse_subscription_image_urls($rawValue, $i18n, $limit)
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

    if (count($urls) > $limit) {
        throw new RuntimeException(sprintf(translate('subscription_image_url_limit_dynamic', $i18n), $limit));
    }

    return $urls;
}

function wallos_restructure_uploaded_files_array($filesField)
{
    if (!is_array($filesField) || !isset($filesField['name'])) {
        return [];
    }

    if (!is_array($filesField['name'])) {
        return [$filesField];
    }

    $files = [];
    $count = count($filesField['name']);
    for ($i = 0; $i < $count; $i++) {
        $files[] = [
            'name' => $filesField['name'][$i] ?? '',
            'type' => $filesField['type'][$i] ?? '',
            'tmp_name' => $filesField['tmp_name'][$i] ?? '',
            'error' => $filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $filesField['size'][$i] ?? 0,
        ];
    }

    return $files;
}

function wallos_count_effective_uploaded_files($filesField)
{
    $files = wallos_restructure_uploaded_files_array($filesField);
    $count = 0;

    foreach ($files as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $count++;
        }
    }

    return $count;
}

function wallos_parse_uploaded_image_ids($rawValue)
{
    if (is_array($rawValue)) {
        $values = $rawValue;
    } else {
        $values = explode(',', (string) $rawValue);
    }

    $ids = array_map('intval', $values);
    $ids = array_filter($ids, function ($id) {
        return $id > 0;
    });

    return array_values(array_unique($ids));
}

function wallos_parse_subscription_image_order_tokens($rawValue)
{
    if (is_array($rawValue)) {
        $tokens = $rawValue;
    } else {
        $tokens = explode(',', (string) $rawValue);
    }

    $normalizedTokens = [];
    foreach ($tokens as $token) {
        $token = trim((string) $token);
        if ($token === '' || !preg_match('/^(existing:\d+|new:[A-Za-z0-9_-]+)$/', $token)) {
            continue;
        }

        $normalizedTokens[] = $token;
    }

    return array_values(array_unique($normalizedTokens));
}

function wallos_validate_uploaded_subscription_image(array $uploadedFile, $i18n, $maxBytes)
{
    if (!isset($uploadedFile['error']) || $uploadedFile['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(translate('subscription_image_processing_failed', $i18n));
    }

    if (($uploadedFile['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException(sprintf(translate('subscription_image_too_large_dynamic', $i18n), $maxBytes / 1024 / 1024));
    }

    if (!is_uploaded_file($uploadedFile['tmp_name'])) {
        throw new RuntimeException(translate('subscription_image_processing_failed', $i18n));
    }

    $imageInfo = @getimagesize($uploadedFile['tmp_name']);
    if ($imageInfo === false || empty($imageInfo['mime'])) {
        throw new RuntimeException(translate('subscription_image_invalid_type', $i18n));
    }

    $mimeType = $imageInfo['mime'];
    $allowedMimeTypes = wallos_get_subscription_media_allowed_mime_types();
    if (!isset($allowedMimeTypes[$mimeType])) {
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
        'extension' => $allowedMimeTypes[$mimeType],
        'file_size' => (int) ($uploadedFile['size'] ?? 0),
        'original_name' => (string) ($uploadedFile['name'] ?? ''),
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

function wallos_calculate_subscription_image_dimensions($width, $height, $maxDimension)
{
    $width = max(1, (int) $width);
    $height = max(1, (int) $height);
    $maxDimension = max(1, (int) $maxDimension);

    $scale = min(
        1,
        $maxDimension / $width,
        $maxDimension / $height
    );

    return [
        'width' => max(1, (int) floor($width * $scale)),
        'height' => max(1, (int) floor($height * $scale)),
    ];
}

function wallos_get_subscription_image_variant_settings($variant)
{
    $variant = wallos_normalize_subscription_image_variant($variant);

    if ($variant === 'thumbnail') {
        return [
            'variant' => 'thumbnail',
            'max_dimension' => WALLOS_SUBSCRIPTION_IMAGE_THUMBNAIL_MAX_DIMENSION,
            'jpeg_quality' => 76,
            'png_quality' => 7,
            'webp_quality' => 80,
        ];
    }

    return [
        'variant' => 'preview',
        'max_dimension' => WALLOS_SUBSCRIPTION_IMAGE_PREVIEW_MAX_DIMENSION,
        'jpeg_quality' => 84,
        'png_quality' => 6,
        'webp_quality' => 86,
    ];
}

function wallos_parse_memory_limit_to_bytes($value)
{
    $value = trim((string) $value);
    if ($value === '' || $value === '-1') {
        return -1;
    }

    $unit = strtolower(substr($value, -1));
    $bytes = (int) $value;

    switch ($unit) {
        case 'g':
            $bytes *= 1024;
        case 'm':
            $bytes *= 1024;
        case 'k':
            $bytes *= 1024;
            break;
    }

    return $bytes;
}

function wallos_estimate_subscription_image_processing_bytes(array $metadata, $compressImage)
{
    $sourcePixels = max(1, (int) (($metadata['width'] ?? 0) * ($metadata['height'] ?? 0)));
    $targetPixels = $sourcePixels;

    if ($compressImage) {
        $scale = min(
            1,
            WALLOS_SUBSCRIPTION_IMAGE_COMPRESSED_MAX_DIMENSION / max((int) ($metadata['width'] ?? 1), 1),
            WALLOS_SUBSCRIPTION_IMAGE_COMPRESSED_MAX_DIMENSION / max((int) ($metadata['height'] ?? 1), 1)
        );

        $targetWidth = max(1, (int) floor(((int) ($metadata['width'] ?? 1)) * $scale));
        $targetHeight = max(1, (int) floor(((int) ($metadata['height'] ?? 1)) * $scale));
        $targetPixels = $targetWidth * $targetHeight;
    }

    $bytesPerPixel = 5;
    $baseOverhead = 16 * 1024 * 1024;
    $sourceBytes = $sourcePixels * $bytesPerPixel;
    $targetBytes = $targetPixels * $bytesPerPixel;

    if (!$compressImage) {
        $targetBytes = 0;
    }

    return $baseOverhead + $sourceBytes + $targetBytes;
}

function wallos_ensure_subscription_image_memory_budget(array $metadata, $compressImage, $i18n)
{
    $memoryLimitBytes = wallos_parse_memory_limit_to_bytes(ini_get('memory_limit'));
    if ($memoryLimitBytes <= 0) {
        return;
    }

    $estimatedBytes = wallos_estimate_subscription_image_processing_bytes($metadata, $compressImage);
    $currentUsage = memory_get_usage(true);
    $safeLimit = (int) floor($memoryLimitBytes * 0.9);

    if (($currentUsage + $estimatedBytes) > $safeLimit) {
        throw new RuntimeException(translate('subscription_image_memory_limit_error', $i18n));
    }
}

function wallos_write_subscription_image_resource($image, $destination, $mimeType, $compressImage)
{
    if ($mimeType === 'image/jpeg') {
        return imagejpeg($image, $destination, $compressImage ? 90 : 100);
    }

    if ($mimeType === 'image/png') {
        return imagepng($image, $destination, $compressImage ? 6 : 0);
    }

    if ($mimeType === 'image/webp') {
        return imagewebp($image, $destination, $compressImage ? 88 : 100);
    }

    return false;
}

function wallos_write_subscription_image_variant_resource($image, $destination, $mimeType, array $variantSettings)
{
    if ($mimeType === 'image/jpeg') {
        return imagejpeg($image, $destination, (int) $variantSettings['jpeg_quality']);
    }

    if ($mimeType === 'image/png') {
        return imagepng($image, $destination, (int) $variantSettings['png_quality']);
    }

    if ($mimeType === 'image/webp') {
        return imagewebp($image, $destination, (int) $variantSettings['webp_quality']);
    }

    return false;
}

function wallos_generate_subscription_image_variant_from_resource(
    $basePath,
    $userId,
    $fileName,
    $mimeType,
    $sourceImage,
    $sourceWidth,
    $sourceHeight,
    $variant
) {
    $variantSettings = wallos_get_subscription_image_variant_settings($variant);
    $variantDimensions = wallos_calculate_subscription_image_dimensions(
        $sourceWidth,
        $sourceHeight,
        $variantSettings['max_dimension']
    );

    $variantRelativePath = wallos_get_subscription_media_variant_relative_path($userId, $fileName, $variant);
    $variantDirectory = wallos_ensure_subscription_media_variant_directory($basePath, $userId);
    $variantFileName = basename(str_replace('\\', '/', $variantRelativePath));
    $variantDestination = $variantDirectory . $variantFileName;

    $variantCanvas = wallos_prepare_subscription_image_canvas(
        $variantDimensions['width'],
        $variantDimensions['height'],
        $mimeType
    );

    imagecopyresampled(
        $variantCanvas,
        $sourceImage,
        0,
        0,
        0,
        0,
        $variantDimensions['width'],
        $variantDimensions['height'],
        $sourceWidth,
        $sourceHeight
    );

    $writeResult = wallos_write_subscription_image_variant_resource(
        $variantCanvas,
        $variantDestination,
        $mimeType,
        $variantSettings
    );
    imagedestroy($variantCanvas);

    if (!$writeResult) {
        throw new RuntimeException('Failed to write subscription image variant');
    }

    return [
        'path' => $variantRelativePath,
        'width' => $variantDimensions['width'],
        'height' => $variantDimensions['height'],
        'file_size' => (int) filesize($variantDestination),
    ];
}

function wallos_generate_subscription_image_variants_from_resource(
    $basePath,
    $userId,
    $fileName,
    $mimeType,
    $sourceImage,
    $sourceWidth,
    $sourceHeight
) {
    return [
        'preview' => wallos_generate_subscription_image_variant_from_resource(
            $basePath,
            $userId,
            $fileName,
            $mimeType,
            $sourceImage,
            $sourceWidth,
            $sourceHeight,
            'preview'
        ),
        'thumbnail' => wallos_generate_subscription_image_variant_from_resource(
            $basePath,
            $userId,
            $fileName,
            $mimeType,
            $sourceImage,
            $sourceWidth,
            $sourceHeight,
            'thumbnail'
        ),
    ];
}

function wallos_get_subscription_upload_limit_for_user($isAdmin, $userGroup, array $policy)
{
    if ($isAdmin) {
        return null;
    }

    if (wallos_normalize_user_group($userGroup) === WALLOS_USER_GROUP_TRUSTED) {
        return (int) $policy['trusted_upload_limit'];
    }

    return 0;
}

function wallos_get_subscription_uploaded_images($db, $subscriptionId, $userId = null)
{
    $query = '
        SELECT *
        FROM subscription_uploaded_images
        WHERE subscription_id = :subscription_id
    ';

    if ($userId !== null) {
        $query .= ' AND user_id = :user_id';
    }

    $query .= ' ORDER BY sort_order ASC, id ASC';

    $stmt = $db->prepare($query);
    $stmt->bindValue(':subscription_id', (int) $subscriptionId, SQLITE3_INTEGER);
    if ($userId !== null) {
        $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    }
    $result = $stmt->execute();

    $images = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $images[] = wallos_append_subscription_uploaded_image_urls($row);
    }

    return $images;
}

function wallos_get_subscription_uploaded_images_map($db, $userId)
{
    $stmt = $db->prepare('
        SELECT *
        FROM subscription_uploaded_images
        WHERE user_id = :user_id
        ORDER BY subscription_id ASC, sort_order ASC, id ASC
    ');
    $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $map = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $subscriptionId = (int) $row['subscription_id'];
        if (!isset($map[$subscriptionId])) {
            $map[$subscriptionId] = [];
        }
        $map[$subscriptionId][] = wallos_append_subscription_uploaded_image_urls($row);
    }

    return $map;
}

function wallos_get_next_subscription_image_sequence($db, $userId)
{
    $stmt = $db->prepare('SELECT MAX(upload_sequence) AS max_sequence FROM subscription_uploaded_images WHERE user_id = :user_id');
    $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    return ((int) ($row['max_sequence'] ?? 0)) + 1;
}

function wallos_get_next_subscription_image_sort_order($db, $subscriptionId, $userId)
{
    $stmt = $db->prepare('SELECT MAX(sort_order) AS max_sort_order FROM subscription_uploaded_images WHERE subscription_id = :subscription_id AND user_id = :user_id');
    $stmt->bindValue(':subscription_id', (int) $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    return ((int) ($row['max_sort_order'] ?? 0)) + 1;
}

function wallos_build_subscription_image_sort_plan(array $orderTokens, array $existingImages, $newImageCount)
{
    $retainedExistingImages = [];
    foreach ($existingImages as $image) {
        $imageId = (int) ($image['id'] ?? 0);
        if ($imageId > 0) {
            $retainedExistingImages[$imageId] = $image;
        }
    }

    $existingSortOrders = [];
    $newSortOrders = [];
    $usedExistingImageIds = [];
    $nextSortOrder = 1;
    $remainingNewImages = max(0, (int) $newImageCount);

    foreach ($orderTokens as $token) {
        if (preg_match('/^existing:(\d+)$/', $token, $matches)) {
            $imageId = (int) $matches[1];
            if (!isset($retainedExistingImages[$imageId]) || isset($usedExistingImageIds[$imageId])) {
                continue;
            }

            $existingSortOrders[$imageId] = $nextSortOrder++;
            $usedExistingImageIds[$imageId] = true;
            continue;
        }

        if (strpos($token, 'new:') === 0 && $remainingNewImages > 0) {
            $newSortOrders[] = $nextSortOrder++;
            $remainingNewImages--;
        }
    }

    foreach ($retainedExistingImages as $imageId => $image) {
        if (isset($usedExistingImageIds[$imageId])) {
            continue;
        }

        $existingSortOrders[$imageId] = $nextSortOrder++;
    }

    while ($remainingNewImages > 0) {
        $newSortOrders[] = $nextSortOrder++;
        $remainingNewImages--;
    }

    return [
        'existing' => $existingSortOrders,
        'new' => $newSortOrders,
    ];
}

function wallos_generate_subscription_uploaded_image_name($username, $sequence, $extension)
{
    return sprintf(
        '%s-%06d-%s-%s.%s',
        wallos_sanitize_subscription_media_filename_part($username),
        (int) $sequence,
        date('YmdHis'),
        bin2hex(random_bytes(4)),
        $extension
    );
}

function wallos_store_subscription_uploaded_images(
    $db,
    $filesField,
    $subscriptionName,
    $username,
    $userId,
    $subscriptionId,
    $compressImage,
    $basePath,
    array $policy,
    $i18n,
    array $sortOrders = []
) {
    $files = wallos_restructure_uploaded_files_array($filesField);
    $storedImages = [];
    $sequence = wallos_get_next_subscription_image_sequence($db, $userId);
    $nextSortOrder = wallos_get_next_subscription_image_sort_order($db, $subscriptionId, $userId);
    $sortOrderIndex = 0;

    try {
        foreach ($files as $uploadedFile) {
            $pathsPendingCleanup = [];

            try {
                $metadata = wallos_validate_uploaded_subscription_image($uploadedFile, $i18n, $policy['max_size_bytes']);
                if ($metadata === null) {
                    continue;
                }

                wallos_ensure_subscription_image_memory_budget($metadata, $compressImage, $i18n);

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

                $directory = wallos_ensure_subscription_media_directory($basePath, $userId);
                $fileName = wallos_generate_subscription_uploaded_image_name($username, $sequence, $metadata['extension']);
                $destination = $directory . $fileName;
                $sortOrder = isset($sortOrders[$sortOrderIndex]) ? max(1, (int) $sortOrders[$sortOrderIndex]) : $nextSortOrder;
                $relativePath = wallos_get_subscription_media_relative_dir() . 'user-' . (int) $userId . '/' . $fileName;
                $pathsPendingCleanup = [
                    'path' => $relativePath,
                    'preview_path' => wallos_get_subscription_media_variant_relative_path($userId, $fileName, 'preview'),
                    'thumbnail_path' => wallos_get_subscription_media_variant_relative_path($userId, $fileName, 'thumbnail'),
                ];

                $writeResult = wallos_write_subscription_image_resource($imageToWrite, $destination, $metadata['mime'], $compressImage);
                if (!$writeResult) {
                    if ($imageToWrite !== $sourceImage) {
                        imagedestroy($imageToWrite);
                    }
                    imagedestroy($sourceImage);
                    throw new RuntimeException(translate('subscription_image_processing_failed', $i18n));
                }

                $masterResource = $imageToWrite;
                $variantSourceWidth = $targetWidth;
                $variantSourceHeight = $targetHeight;
                $variants = wallos_generate_subscription_image_variants_from_resource(
                    $basePath,
                    $userId,
                    $fileName,
                    $metadata['mime'],
                    $masterResource,
                    $variantSourceWidth,
                    $variantSourceHeight
                );

                if ($imageToWrite !== $sourceImage) {
                    imagedestroy($imageToWrite);
                }
                imagedestroy($sourceImage);

                $insertStmt = $db->prepare('
                    INSERT INTO subscription_uploaded_images (
                        user_id, subscription_id, path, preview_path, thumbnail_path, file_name, original_name, mime_type, file_size, width, height, compressed, upload_sequence, sort_order
                    ) VALUES (
                        :user_id, :subscription_id, :path, :preview_path, :thumbnail_path, :file_name, :original_name, :mime_type, :file_size, :width, :height, :compressed, :upload_sequence, :sort_order
                    )
                ');
                $insertStmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
                $insertStmt->bindValue(':subscription_id', (int) $subscriptionId, SQLITE3_INTEGER);
                $insertStmt->bindValue(':path', $relativePath, SQLITE3_TEXT);
                $insertStmt->bindValue(':preview_path', $variants['preview']['path'], SQLITE3_TEXT);
                $insertStmt->bindValue(':thumbnail_path', $variants['thumbnail']['path'], SQLITE3_TEXT);
                $insertStmt->bindValue(':file_name', $fileName, SQLITE3_TEXT);
                $insertStmt->bindValue(':original_name', substr($metadata['original_name'], 0, 255), SQLITE3_TEXT);
                $insertStmt->bindValue(':mime_type', $metadata['mime'], SQLITE3_TEXT);
                $insertStmt->bindValue(':file_size', (int) filesize($destination), SQLITE3_INTEGER);
                $insertStmt->bindValue(':width', $targetWidth, SQLITE3_INTEGER);
                $insertStmt->bindValue(':height', $targetHeight, SQLITE3_INTEGER);
                $insertStmt->bindValue(':compressed', $compressImage ? 1 : 0, SQLITE3_INTEGER);
                $insertStmt->bindValue(':upload_sequence', $sequence, SQLITE3_INTEGER);
                $insertStmt->bindValue(':sort_order', $sortOrder, SQLITE3_INTEGER);
                $insertStmt->execute();

                $storedImages[] = wallos_append_subscription_uploaded_image_urls([
                    'id' => $db->lastInsertRowID(),
                    'path' => $relativePath,
                    'preview_path' => $variants['preview']['path'],
                    'thumbnail_path' => $variants['thumbnail']['path'],
                    'file_name' => $fileName,
                    'mime_type' => $metadata['mime'],
                    'width' => $targetWidth,
                    'height' => $targetHeight,
                    'compressed' => $compressImage ? 1 : 0,
                ]);

                $sequence++;
                $nextSortOrder = max($nextSortOrder, $sortOrder + 1);
                $sortOrderIndex++;
            } catch (Throwable $throwable) {
                if (!empty($pathsPendingCleanup)) {
                    wallos_delete_subscription_image_related_files($basePath, $pathsPendingCleanup);
                }

                throw $throwable;
            }
        }
    } catch (Throwable $throwable) {
        foreach ($storedImages as $storedImage) {
            if (!empty($storedImage['id'])) {
                $deleteStmt = $db->prepare('DELETE FROM subscription_uploaded_images WHERE id = :id');
                $deleteStmt->bindValue(':id', (int) $storedImage['id'], SQLITE3_INTEGER);
                $deleteStmt->execute();
            }
            wallos_delete_subscription_image_related_files($basePath, $storedImage);
        }

        throw $throwable;
    }

    return $storedImages;
}

function wallos_subscription_image_path_is_within_media_dir($relativePath)
{
    $relativePath = str_replace('\\', '/', trim((string) $relativePath));
    return $relativePath !== '' && strpos($relativePath, wallos_get_subscription_media_relative_dir()) === 0;
}

function wallos_resolve_subscription_image_absolute_path($basePath, $relativePath)
{
    if (!wallos_subscription_image_path_is_within_media_dir($relativePath)) {
        return '';
    }

    $absolutePath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    return is_file($absolutePath) ? $absolutePath : '';
}

function wallos_generate_subscription_image_variants_for_existing_image($basePath, array $imageRow, $force = false)
{
    $masterPath = wallos_resolve_subscription_image_absolute_path($basePath, $imageRow['path'] ?? '');
    if ($masterPath === '') {
        throw new RuntimeException('Original subscription image file does not exist');
    }

    $previewPath = trim((string) ($imageRow['preview_path'] ?? ''));
    $thumbnailPath = trim((string) ($imageRow['thumbnail_path'] ?? ''));

    $previewAbsolutePath = $previewPath !== '' ? wallos_resolve_subscription_image_absolute_path($basePath, $previewPath) : '';
    $thumbnailAbsolutePath = $thumbnailPath !== '' ? wallos_resolve_subscription_image_absolute_path($basePath, $thumbnailPath) : '';

    if (!$force && $previewAbsolutePath !== '' && $thumbnailAbsolutePath !== '') {
        return [
            'preview_path' => $previewPath,
            'thumbnail_path' => $thumbnailPath,
        ];
    }

    $mimeType = trim((string) ($imageRow['mime_type'] ?? ''));
    if ($mimeType === '') {
        $detectedMimeType = @mime_content_type($masterPath);
        $mimeType = is_string($detectedMimeType) ? $detectedMimeType : '';
    }

    $sourceImage = wallos_load_subscription_image_resource($masterPath, $mimeType);
    if ($sourceImage === false) {
        throw new RuntimeException('Cannot load subscription image');
    }

    $width = (int) imagesx($sourceImage);
    $height = (int) imagesy($sourceImage);

    try {
        $variants = wallos_generate_subscription_image_variants_from_resource(
            $basePath,
            (int) $imageRow['user_id'],
            (string) $imageRow['file_name'],
            $mimeType,
            $sourceImage,
            $width,
            $height
        );
    } finally {
        imagedestroy($sourceImage);
    }

    return [
        'preview_path' => $variants['preview']['path'],
        'thumbnail_path' => $variants['thumbnail']['path'],
    ];
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

    $currentDirectory = dirname($fullPath);
    $mediaRoot = rtrim(wallos_get_subscription_media_disk_dir($basePath), '/\\');

    while ($currentDirectory !== '' && $currentDirectory !== '.' && $currentDirectory !== $mediaRoot) {
        $entries = @scandir($currentDirectory);
        if ($entries === false) {
            break;
        }

        $remainingEntries = array_diff($entries, ['.', '..']);
        if (!empty($remainingEntries)) {
            break;
        }

        @rmdir($currentDirectory);
        $currentDirectory = dirname($currentDirectory);
    }
}

function wallos_delete_subscription_image_related_files($basePath, array $imageRow)
{
    $paths = array_filter([
        $imageRow['thumbnail_path'] ?? '',
        $imageRow['preview_path'] ?? '',
        $imageRow['path'] ?? '',
    ]);

    foreach ($paths as $relativePath) {
        wallos_delete_subscription_image_file($basePath, $relativePath);
    }
}

function wallos_delete_uploaded_image_records_and_files($db, $basePath, array $imageIds, $userId, $subscriptionId = null)
{
    if (empty($imageIds)) {
        return;
    }

    $normalizedIds = array_values(array_unique(array_filter(array_map('intval', $imageIds))));
    if (empty($normalizedIds)) {
        return;
    }

    $placeholders = [];
    foreach ($normalizedIds as $index => $imageId) {
        $placeholders[] = ':image_id_' . $index;
    }

    $query = 'SELECT id, path, preview_path, thumbnail_path FROM subscription_uploaded_images WHERE user_id = :user_id AND id IN (' . implode(',', $placeholders) . ')';
    if ($subscriptionId !== null) {
        $query .= ' AND subscription_id = :subscription_id';
    }

    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    if ($subscriptionId !== null) {
        $stmt->bindValue(':subscription_id', (int) $subscriptionId, SQLITE3_INTEGER);
    }
    foreach ($normalizedIds as $index => $imageId) {
        $stmt->bindValue(':image_id_' . $index, $imageId, SQLITE3_INTEGER);
    }
    $result = $stmt->execute();

    $rows = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $rows[] = wallos_append_subscription_uploaded_image_urls($row);
    }

    if (empty($rows)) {
        return;
    }

    $deleteStmt = $db->prepare('DELETE FROM subscription_uploaded_images WHERE id = :id AND user_id = :user_id');
    foreach ($rows as $row) {
        $deleteStmt->bindValue(':id', (int) $row['id'], SQLITE3_INTEGER);
        $deleteStmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
        $deleteStmt->execute();
        wallos_delete_subscription_image_related_files($basePath, $row);
    }
}

function wallos_get_uploaded_images_by_ids($db, array $imageIds, $userId, $subscriptionId = null)
{
    if (empty($imageIds)) {
        return [];
    }

    $normalizedIds = array_values(array_unique(array_filter(array_map('intval', $imageIds))));
    if (empty($normalizedIds)) {
        return [];
    }

    $placeholders = [];
    foreach ($normalizedIds as $index => $imageId) {
        $placeholders[] = ':image_id_' . $index;
    }

    $query = 'SELECT * FROM subscription_uploaded_images WHERE user_id = :user_id AND id IN (' . implode(',', $placeholders) . ')';
    if ($subscriptionId !== null) {
        $query .= ' AND subscription_id = :subscription_id';
    }
    $query .= ' ORDER BY sort_order ASC, id ASC';

    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    if ($subscriptionId !== null) {
        $stmt->bindValue(':subscription_id', (int) $subscriptionId, SQLITE3_INTEGER);
    }
    foreach ($normalizedIds as $index => $imageId) {
        $stmt->bindValue(':image_id_' . $index, $imageId, SQLITE3_INTEGER);
    }
    $result = $stmt->execute();

    $rows = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $rows[] = $row;
    }

    return $rows;
}

function wallos_delete_subscription_uploaded_images_for_subscription($db, $basePath, $subscriptionId, $userId = null)
{
    $query = 'SELECT id FROM subscription_uploaded_images WHERE subscription_id = :subscription_id';
    if ($userId !== null) {
        $query .= ' AND user_id = :user_id';
    }

    $stmt = $db->prepare($query);
    $stmt->bindValue(':subscription_id', (int) $subscriptionId, SQLITE3_INTEGER);
    if ($userId !== null) {
        $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    }
    $result = $stmt->execute();

    $imageIds = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $imageIds[] = (int) $row['id'];
    }

    if (!empty($imageIds)) {
        wallos_delete_uploaded_image_records_and_files($db, $basePath, $imageIds, $userId ?? 0, $subscriptionId);
    }
}

function wallos_collect_user_subscription_images($db, $userId)
{
    $stmt = $db->prepare('SELECT path, preview_path, thumbnail_path FROM subscription_uploaded_images WHERE user_id = :user_id');
    $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $images = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        foreach (['path', 'preview_path', 'thumbnail_path'] as $column) {
            if (!empty($row[$column])) {
                $images[] = $row[$column];
            }
        }
    }

    return array_values(array_unique($images));
}

function wallos_update_subscription_uploaded_image_order($db, $subscriptionId, $userId, array $orderedImageIds)
{
    $normalizedImageIds = array_values(array_unique(array_filter(array_map('intval', $orderedImageIds))));
    if (empty($normalizedImageIds)) {
        return;
    }

    $placeholders = [];
    foreach ($normalizedImageIds as $index => $imageId) {
        $placeholders[] = ':image_id_' . $index;
    }

    $query = 'SELECT id FROM subscription_uploaded_images WHERE subscription_id = :subscription_id AND user_id = :user_id AND id IN (' . implode(',', $placeholders) . ')';
    $stmt = $db->prepare($query);
    $stmt->bindValue(':subscription_id', (int) $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    foreach ($normalizedImageIds as $index => $imageId) {
        $stmt->bindValue(':image_id_' . $index, $imageId, SQLITE3_INTEGER);
    }
    $result = $stmt->execute();

    $existingImageIds = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $existingImageIds[] = (int) $row['id'];
    }

    if (empty($existingImageIds)) {
        return;
    }

    $existingImageIds = array_values(array_unique($existingImageIds));
    $sortOrder = 1;
    $updateStmt = $db->prepare('UPDATE subscription_uploaded_images SET sort_order = :sort_order WHERE id = :id AND subscription_id = :subscription_id AND user_id = :user_id');

    foreach ($normalizedImageIds as $imageId) {
        if (!in_array($imageId, $existingImageIds, true)) {
            continue;
        }

        $updateStmt->bindValue(':sort_order', $sortOrder++, SQLITE3_INTEGER);
        $updateStmt->bindValue(':id', $imageId, SQLITE3_INTEGER);
        $updateStmt->bindValue(':subscription_id', (int) $subscriptionId, SQLITE3_INTEGER);
        $updateStmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
        $updateStmt->execute();
    }
}

function wallos_generate_missing_subscription_image_variants($db, $userId, $basePath, $force = false)
{
    $stmt = $db->prepare('
        SELECT *
        FROM subscription_uploaded_images
        WHERE user_id = :user_id
        ORDER BY subscription_id ASC, sort_order ASC, id ASC
    ');
    $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $generatedCount = 0;
    $skippedCount = 0;
    $failedCount = 0;

    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $previewPath = trim((string) ($row['preview_path'] ?? ''));
        $thumbnailPath = trim((string) ($row['thumbnail_path'] ?? ''));
        $previewExists = $previewPath !== '' && wallos_resolve_subscription_image_absolute_path($basePath, $previewPath) !== '';
        $thumbnailExists = $thumbnailPath !== '' && wallos_resolve_subscription_image_absolute_path($basePath, $thumbnailPath) !== '';

        if (!$force && $previewExists && $thumbnailExists) {
            $skippedCount++;
            continue;
        }

        try {
            $variants = wallos_generate_subscription_image_variants_for_existing_image($basePath, $row, $force);
            $updateStmt = $db->prepare('UPDATE subscription_uploaded_images SET preview_path = :preview_path, thumbnail_path = :thumbnail_path WHERE id = :id AND user_id = :user_id');
            $updateStmt->bindValue(':preview_path', $variants['preview_path'], SQLITE3_TEXT);
            $updateStmt->bindValue(':thumbnail_path', $variants['thumbnail_path'], SQLITE3_TEXT);
            $updateStmt->bindValue(':id', (int) $row['id'], SQLITE3_INTEGER);
            $updateStmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
            $updateStmt->execute();
            $generatedCount++;
        } catch (Throwable $throwable) {
            $failedCount++;
        }
    }

    return [
        'generated_count' => $generatedCount,
        'skipped_count' => $skippedCount,
        'failed_count' => $failedCount,
    ];
}

function wallos_clone_subscription_uploaded_images($db, $basePath, $fromSubscriptionId, $toSubscriptionId, $userId, $username)
{
    $images = wallos_get_subscription_uploaded_images($db, $fromSubscriptionId, $userId);
    if (empty($images)) {
        return;
    }

    $sequence = wallos_get_next_subscription_image_sequence($db, $userId);

    foreach ($images as $image) {
        $sourcePath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $image['path']);
        if (!is_file($sourcePath)) {
            continue;
        }

        $extension = pathinfo($image['file_name'], PATHINFO_EXTENSION);
        $fileName = wallos_generate_subscription_uploaded_image_name($username, $sequence, $extension);
        $destinationDirectory = wallos_ensure_subscription_media_directory($basePath, $userId);
        $destinationPath = $destinationDirectory . $fileName;

        if (!copy($sourcePath, $destinationPath)) {
            continue;
        }

        $mimeType = trim((string) ($image['mime_type'] ?? ''));
        if ($mimeType === '') {
            $detectedMimeType = @mime_content_type($destinationPath);
            $mimeType = is_string($detectedMimeType) ? $detectedMimeType : '';
        }

        $previewPath = '';
        $thumbnailPath = '';
        if ($mimeType !== '') {
            $clonedImage = wallos_load_subscription_image_resource($destinationPath, $mimeType);
            if ($clonedImage !== false) {
                try {
                    $variants = wallos_generate_subscription_image_variants_from_resource(
                        $basePath,
                        $userId,
                        $fileName,
                        $mimeType,
                        $clonedImage,
                        (int) imagesx($clonedImage),
                        (int) imagesy($clonedImage)
                    );
                    $previewPath = $variants['preview']['path'];
                    $thumbnailPath = $variants['thumbnail']['path'];
                } finally {
                    imagedestroy($clonedImage);
                }
            }
        }

        $relativePath = wallos_get_subscription_media_relative_dir() . 'user-' . (int) $userId . '/' . $fileName;
        $insertStmt = $db->prepare('
            INSERT INTO subscription_uploaded_images (
                user_id, subscription_id, path, preview_path, thumbnail_path, file_name, original_name, mime_type, file_size, width, height, compressed, upload_sequence, sort_order
            ) VALUES (
                :user_id, :subscription_id, :path, :preview_path, :thumbnail_path, :file_name, :original_name, :mime_type, :file_size, :width, :height, :compressed, :upload_sequence, :sort_order
            )
        ');
        $insertStmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
        $insertStmt->bindValue(':subscription_id', (int) $toSubscriptionId, SQLITE3_INTEGER);
        $insertStmt->bindValue(':path', $relativePath, SQLITE3_TEXT);
        $insertStmt->bindValue(':preview_path', $previewPath, SQLITE3_TEXT);
        $insertStmt->bindValue(':thumbnail_path', $thumbnailPath, SQLITE3_TEXT);
        $insertStmt->bindValue(':file_name', $fileName, SQLITE3_TEXT);
        $insertStmt->bindValue(':original_name', $image['original_name'] ?? $image['file_name'], SQLITE3_TEXT);
        $insertStmt->bindValue(':mime_type', $mimeType, SQLITE3_TEXT);
        $insertStmt->bindValue(':file_size', (int) filesize($destinationPath), SQLITE3_INTEGER);
        $insertStmt->bindValue(':width', (int) ($image['width'] ?? 0), SQLITE3_INTEGER);
        $insertStmt->bindValue(':height', (int) ($image['height'] ?? 0), SQLITE3_INTEGER);
        $insertStmt->bindValue(':compressed', (int) ($image['compressed'] ?? 1), SQLITE3_INTEGER);
        $insertStmt->bindValue(':upload_sequence', $sequence, SQLITE3_INTEGER);
        $insertStmt->bindValue(':sort_order', (int) ($image['sort_order'] ?? $sequence), SQLITE3_INTEGER);
        $insertStmt->execute();

        $sequence++;
    }
}
