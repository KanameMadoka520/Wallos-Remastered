<?php
error_reporting(E_ERROR | E_PARSE);
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/inputvalidation.php';
require_once '../../includes/getsettings.php';
require_once '../../includes/subscription_media.php';
require_once '../../includes/subscription_sort.php';
require_once '../../includes/subscription_trash.php';
require_once '../../includes/subscription_price_rules.php';
require_once '../../includes/user_groups.php';
if (!file_exists('../../images/uploads/logos')) {
    mkdir('../../images/uploads/logos', 0777, true);
    mkdir('../../images/uploads/logos/avatars', 0777, true);
}

wallos_ensure_subscription_media_directory(__DIR__ . '/../../');

function subscription_error_response($message)
{
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'Error',
        'message' => $message,
    ]);
    exit();
}

function sanitizeFilename($filename)
{
    $filename = preg_replace("/[^a-zA-Z0-9\s]/", "", $filename);
    $filename = str_replace(" ", "-", $filename);
    $filename = str_replace(".", "", $filename);
    return $filename;
}

function validateFileExtension($fileExtension)
{
    $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    return in_array($fileExtension, $allowedExtensions);
}

function getLogoFromUrl($url, $uploadDir, $name, $settings, $i18n)
{
    $maxRedirects = 3;
    $currentUrl = $url;

    for ($i = 0; $i <= $maxRedirects; $i++) {
        if (!filter_var($currentUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $currentUrl)) {
            return ['success' => false, 'message' => 'Invalid URL format.'];
        }

        $parts = parse_url($currentUrl);
        $host = $parts['host'];
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $ip = gethostbyname($host);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['success' => false, 'message' => 'Invalid IP Address.'];
        }

        $ch = curl_init($currentUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_RESOLVE, ["$host:$port:$ip"]);

        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode >= 300 && $httpCode < 400) {
            $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            unset($ch);

            if (!$redirectUrl) {
                break;
            }

            $currentUrl = $redirectUrl;
            continue;
        }

        if ($imageData !== false && $httpCode === 200) {
            $timestamp = time();
            $fileName = $timestamp . '-' . sanitizeFilename($name) . '.png';
            $uploadFile = '../../images/uploads/logos/' . $fileName;

            if (saveLogo($imageData, $uploadFile, $name, $settings)) {
                unset($ch);
                return ['success' => true, 'filename' => $fileName];
            }
        }

        $error = curl_error($ch);
        unset($ch);
        return ['success' => false, 'message' => translate('error_fetching_image', $i18n) . ': ' . $error];
    }

    return ['success' => false, 'message' => translate('error_fetching_image', $i18n)];
}

function saveLogo($imageData, $uploadFile, $name, $settings)
{
    $image = imagecreatefromstring($imageData);
    $removeBackground = isset($settings['removeBackground']) && $settings['removeBackground'] === 'true';

    if ($image !== false) {
        $tempFile = tempnam(sys_get_temp_dir(), 'logo');

        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagepng($image, $tempFile);
        imagedestroy($image);

        if (extension_loaded('imagick')) {
            $imagick = new Imagick($tempFile);

            if ($removeBackground) {
                $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);

                $pixel = $imagick->getImagePixelColor(0, 0);
                $color = $pixel->getColor();
                if ($color['a'] > 0) {
                    $bgColor = "rgb({$color['r']},{$color['g']},{$color['b']})";
                    $fuzz = Imagick::getQuantum() * 0.1;
                    $imagick->transparentPaintImage($bgColor, 0, $fuzz, false);
                }
            }

            $imagick->setImageFormat('png');
            $imagick->writeImage($uploadFile);
            $imagick->clear();
            $imagick->destroy();

        } else {
            $newImage = imagecreatefrompng($tempFile);
            if ($newImage !== false) {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);

                if ($removeBackground) {
                    $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
                    imagefill($newImage, 0, 0, $transparent);
                }

                imagepng($newImage, $uploadFile);
                imagedestroy($newImage);
            } else {
                unlink($tempFile);
                return false;
            }
        }

        unlink($tempFile);
        return true;
    }

    return false;
}

function resizeAndUploadLogo($uploadedFile, $uploadDir, $name, $settings)
{
    $targetWidth = 135;
    $targetHeight = 42;

    $timestamp = time();
    $originalFileName = $uploadedFile['name'];
    $fileExtension = pathinfo($originalFileName, PATHINFO_EXTENSION);
    $fileExtension = validateFileExtension($fileExtension) ? $fileExtension : 'png';
    $fileName = $timestamp . '-' . sanitizeFilename($name) . '.' . $fileExtension;
    $uploadFile = $uploadDir . $fileName;

    if (move_uploaded_file($uploadedFile['tmp_name'], $uploadFile)) {
        $fileInfo = getimagesize($uploadFile);

        if ($fileInfo !== false) {
            $width = $fileInfo[0];
            $height = $fileInfo[1];

            if ($fileExtension === 'png') {
                $image = imagecreatefrompng($uploadFile);
            } elseif ($fileExtension === 'jpg' || $fileExtension === 'jpeg') {
                $image = imagecreatefromjpeg($uploadFile);
            } elseif ($fileExtension === 'gif') {
                $image = imagecreatefromgif($uploadFile);
            } elseif ($fileExtension === 'webp') {
                $image = imagecreatefromwebp($uploadFile);
            } else {
                return "";
            }

            if ($fileExtension === 'png') {
                imagesavealpha($image, true);
            }

            $newWidth = $width;
            $newHeight = $height;

            if ($width > $targetWidth) {
                $newWidth = (int) $targetWidth;
                $newHeight = (int) (($targetWidth / $width) * $height);
            }

            if ($newHeight > $targetHeight) {
                $newWidth = (int) (($targetHeight / $newHeight) * $newWidth);
                $newHeight = (int) $targetHeight;
            }

            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
            imagesavealpha($resizedImage, true);
            $transparency = imagecolorallocatealpha($resizedImage, 0, 0, 0, 127);
            imagefill($resizedImage, 0, 0, $transparency);
            imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            if ($fileExtension === 'png') {
                imagepng($resizedImage, $uploadFile);
            } elseif ($fileExtension === 'jpg' || $fileExtension === 'jpeg') {
                imagejpeg($resizedImage, $uploadFile);
            } elseif ($fileExtension === 'gif') {
                imagegif($resizedImage, $uploadFile);
            } elseif ($fileExtension === 'webp') {
                imagewebp($resizedImage, $uploadFile);
            } else {
                return "";
            }

            imagedestroy($image);
            imagedestroy($resizedImage);

            return $fileName;
        }
    }

    return "";
}

$isEdit = isset($_POST['id']) && $_POST['id'] != "";
$name = validate($_POST["name"]);
$price = $_POST['price'];
$currencyId = $_POST["currency_id"];
$frequency = $_POST["frequency"];
$cycle = $_POST["cycle"];
$nextPayment = $_POST["next_payment"];
$autoRenew = isset($_POST['auto_renew']) ? true : false;
$startDate = $_POST["start_date"];
$paymentMethodId = $_POST["payment_method_id"];
$payerUserId = $_POST["payer_user_id"];
$categoryId = $_POST['category_id'];
$notes = validate($_POST["notes"]);
$url = validate($_POST['url']);
$logoUrl = validate($_POST['logo-url']);
$logo = "";
$logoError = "";
$notify = isset($_POST['notifications']) ? true : false;
$notifyDaysBefore = $_POST['notify_days_before'];
$inactive = isset($_POST['inactive']) ? true : false;
$excludeFromStats = isset($_POST['exclude_from_stats']) ? true : false;
$cancellationDate = $_POST['cancellation_date'] ?? null;
$replacementSubscriptionId = $_POST['replacement_subscription_id'];
$detailImageUrlsRaw = $_POST['detail_image_urls'] ?? '';
$subscriptionPriceRulesJson = $_POST['subscription_price_rules_json'] ?? '[]';
$removeUploadedImageIds = wallos_parse_uploaded_image_ids($_POST['remove_uploaded_image_ids'] ?? []);
$detailImageOrderTokens = wallos_parse_subscription_image_order_tokens($_POST['detail_image_order'] ?? []);
$subscriptionPriceRules = wallos_decode_subscription_price_rules_input($subscriptionPriceRulesJson, $currencyId);

if ($replacementSubscriptionId == 0 || $inactive == 0) {
    $replacementSubscriptionId = null;
}

$userStmt = $db->prepare('SELECT username, user_group FROM user WHERE id = :userId');
$userStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$userResult = $userStmt->execute();
$currentUser = $userResult ? $userResult->fetchArray(SQLITE3_ASSOC) : false;

$isAdminUser = $userId === 1;
$currentUserGroup = wallos_normalize_user_group($currentUser['user_group'] ?? WALLOS_USER_GROUP_FREE);
$canUploadDetailImages = wallos_can_upload_subscription_images($isAdminUser, $currentUserGroup);
$compressDetailImages = $isAdminUser
    ? (isset($_POST['compress_subscription_image']) && $_POST['compress_subscription_image'] === '1')
    : $canUploadDetailImages;
$mediaPolicy = wallos_get_subscription_media_policy($db);
$uploadLimit = wallos_get_subscription_upload_limit_for_user($isAdminUser, $currentUserGroup, $mediaPolicy);

try {
    $detailImageUrls = wallos_parse_subscription_image_urls(
        $detailImageUrlsRaw,
        $i18n,
        $mediaPolicy['external_url_limit']
    );
} catch (RuntimeException $exception) {
    subscription_error_response($exception->getMessage());
}

$detailImageUrlsJson = wallos_encode_subscription_image_urls($detailImageUrls);
$uploadedFileCount = wallos_count_effective_uploaded_files($_FILES['detail_images'] ?? null);

if (!$canUploadDetailImages && $uploadedFileCount > 0) {
    subscription_error_response(translate('subscription_image_no_upload_permission', $i18n));
}

$subscriptionId = $isEdit ? (int) $_POST['id'] : 0;
$existingUploadedImages = [];
$imagesPendingFileDeletion = [];
$storedUploadedImages = [];

if ($isEdit) {
    $existingStmt = $db->prepare('SELECT id FROM subscriptions WHERE id = :id AND user_id = :userId AND lifecycle_status = :lifecycle_status');
    $existingStmt->bindValue(':id', $subscriptionId, SQLITE3_INTEGER);
    $existingStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $existingStmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
    $existingResult = $existingStmt->execute();
    $existingSubscription = $existingResult ? $existingResult->fetchArray(SQLITE3_ASSOC) : false;

    if ($existingSubscription === false) {
        subscription_error_response(translate('error', $i18n));
    }

    $existingUploadedImages = wallos_get_subscription_uploaded_images($db, $subscriptionId, $userId);
}

$remainingUploadedImageCount = count($existingUploadedImages);
if (!empty($removeUploadedImageIds)) {
    $existingImageIds = array_map(function ($image) {
        return (int) $image['id'];
    }, $existingUploadedImages);
    $remainingUploadedImageCount -= count(array_intersect($existingImageIds, $removeUploadedImageIds));
    if ($remainingUploadedImageCount < 0) {
        $remainingUploadedImageCount = 0;
    }
}

if ($uploadLimit !== null && ($remainingUploadedImageCount + $uploadedFileCount) > $uploadLimit) {
    subscription_error_response(sprintf(translate('subscription_image_upload_limit_dynamic', $i18n), $uploadLimit));
}

$retainedUploadedImages = array_values(array_filter($existingUploadedImages, function ($image) use ($removeUploadedImageIds) {
    return !in_array((int) ($image['id'] ?? 0), $removeUploadedImageIds, true);
}));

$imageSortPlan = wallos_build_subscription_image_sort_plan(
    $detailImageOrderTokens,
    $retainedUploadedImages,
    $uploadedFileCount
);

if ($logoUrl !== "") {
    $result = getLogoFromUrl($logoUrl, '../../images/uploads/logos/', $name, $settings, $i18n);
    if ($result['success']) {
        $logo = $result['filename'];
    } else {
        $logoError = $result['message'];
    }
} else {
    if (!empty($_FILES['logo']['name'])) {
        $fileType = mime_content_type($_FILES['logo']['tmp_name']);
        if (strpos($fileType, 'image') === false) {
            subscription_error_response(translate("fill_all_fields", $i18n));
        }
        $logo = resizeAndUploadLogo($_FILES['logo'], '../../images/uploads/logos/', $name, $settings);
    }
}

try {
    $db->exec('BEGIN IMMEDIATE');
    $nextSortOrder = !$isEdit ? wallos_get_next_subscription_sort_order($db, $userId) : 0;

    if (!$isEdit) {
        $sql = "INSERT INTO subscriptions (
                            name, logo, price, currency_id, next_payment, cycle, frequency, notes,
                            payment_method_id, payer_user_id, category_id, notify, inactive, url,
                            notify_days_before, user_id, cancellation_date, replacement_subscription_id,
                            auto_renew, start_date, detail_image, detail_image_urls, sort_order,
                            lifecycle_status, exclude_from_stats
                        ) VALUES (
                            :name, :logo, :price, :currencyId, :nextPayment, :cycle, :frequency, :notes,
                            :paymentMethodId, :payerUserId, :categoryId, :notify, :inactive, :url,
                            :notifyDaysBefore, :userId, :cancellationDate, :replacement_subscription_id,
                            :autoRenew, :startDate, '', :detailImageUrls, :sortOrder,
                            :lifecycleStatus, :excludeFromStats
                        )";
    } else {
        $sql = "UPDATE subscriptions SET
                            name = :name,
                            price = :price,
                            currency_id = :currencyId,
                            next_payment = :nextPayment,
                            auto_renew = :autoRenew,
                            start_date = :startDate,
                            cycle = :cycle,
                            frequency = :frequency,
                            notes = :notes,
                            payment_method_id = :paymentMethodId,
                            payer_user_id = :payerUserId,
                            category_id = :categoryId,
                            notify = :notify,
                            inactive = :inactive,
                            url = :url,
                            notify_days_before = :notifyDaysBefore,
                            cancellation_date = :cancellationDate,
                            replacement_subscription_id = :replacement_subscription_id,
                            detail_image = '',
                            detail_image_urls = :detailImageUrls,
                            exclude_from_stats = :excludeFromStats";

        if ($logo != "") {
            $sql .= ", logo = :logo";
        }

        $sql .= " WHERE id = :id AND user_id = :userId";
    }

    $stmt = $db->prepare($sql);
    $stmt->bindParam(':name', $name, SQLITE3_TEXT);
    if ($logo != "") {
        $stmt->bindParam(':logo', $logo, SQLITE3_TEXT);
    }
    $stmt->bindParam(':price', $price, SQLITE3_FLOAT);
    $stmt->bindParam(':currencyId', $currencyId, SQLITE3_INTEGER);
    $stmt->bindParam(':nextPayment', $nextPayment, SQLITE3_TEXT);
    $stmt->bindParam(':autoRenew', $autoRenew, SQLITE3_INTEGER);
    $stmt->bindParam(':startDate', $startDate, SQLITE3_TEXT);
    $stmt->bindParam(':cycle', $cycle, SQLITE3_INTEGER);
    $stmt->bindParam(':frequency', $frequency, SQLITE3_INTEGER);
    $stmt->bindParam(':notes', $notes, SQLITE3_TEXT);
    $stmt->bindParam(':paymentMethodId', $paymentMethodId, SQLITE3_INTEGER);
    $stmt->bindParam(':payerUserId', $payerUserId, SQLITE3_INTEGER);
    $stmt->bindParam(':categoryId', $categoryId, SQLITE3_INTEGER);
    $stmt->bindParam(':notify', $notify, SQLITE3_INTEGER);
    $stmt->bindParam(':inactive', $inactive, SQLITE3_INTEGER);
    $stmt->bindParam(':url', $url, SQLITE3_TEXT);
    $stmt->bindParam(':notifyDaysBefore', $notifyDaysBefore, SQLITE3_INTEGER);
    $stmt->bindParam(':cancellationDate', $cancellationDate, SQLITE3_TEXT);
    if ($isEdit) {
        $stmt->bindParam(':id', $subscriptionId, SQLITE3_INTEGER);
    }
    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindParam(':replacement_subscription_id', $replacementSubscriptionId, SQLITE3_INTEGER);
    $stmt->bindParam(':detailImageUrls', $detailImageUrlsJson, SQLITE3_TEXT);
    $stmt->bindParam(':excludeFromStats', $excludeFromStats, SQLITE3_INTEGER);
    if (!$isEdit) {
        $stmt->bindParam(':sortOrder', $nextSortOrder, SQLITE3_INTEGER);
        $lifecycleStatus = WALLOS_SUBSCRIPTION_STATUS_ACTIVE;
        $stmt->bindParam(':lifecycleStatus', $lifecycleStatus, SQLITE3_TEXT);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException(translate('error', $i18n) . ": " . $db->lastErrorMsg());
    }

    if (!$isEdit) {
        $subscriptionId = $db->lastInsertRowID();
    }

    if (!empty($removeUploadedImageIds)) {
        $imagesPendingFileDeletion = wallos_get_uploaded_images_by_ids($db, $removeUploadedImageIds, $userId, $subscriptionId);
        if (!empty($imagesPendingFileDeletion)) {
            $deleteStmt = $db->prepare('DELETE FROM subscription_uploaded_images WHERE id = :id AND user_id = :user_id AND subscription_id = :subscription_id');
            foreach ($imagesPendingFileDeletion as $imageRow) {
                $deleteStmt->bindValue(':id', (int) $imageRow['id'], SQLITE3_INTEGER);
                $deleteStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                $deleteStmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
                $deleteStmt->execute();
            }
        }
    }

    if (!empty($imageSortPlan['existing'])) {
        $existingSortUpdateStmt = $db->prepare('UPDATE subscription_uploaded_images SET sort_order = :sort_order WHERE id = :id AND user_id = :user_id AND subscription_id = :subscription_id');
        foreach ($imageSortPlan['existing'] as $imageId => $sortOrder) {
            $existingSortUpdateStmt->bindValue(':sort_order', (int) $sortOrder, SQLITE3_INTEGER);
            $existingSortUpdateStmt->bindValue(':id', (int) $imageId, SQLITE3_INTEGER);
            $existingSortUpdateStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $existingSortUpdateStmt->bindValue(':subscription_id', $subscriptionId, SQLITE3_INTEGER);
            $existingSortUpdateStmt->execute();
        }
    }

    if ($uploadedFileCount > 0) {
        $storedUploadedImages = wallos_store_subscription_uploaded_images(
            $db,
            $_FILES['detail_images'],
            $name,
            $currentUser['username'] ?? ('user-' . $userId),
            $userId,
            $subscriptionId,
            $compressDetailImages,
            __DIR__ . '/../../',
            $mediaPolicy,
            $i18n,
            $imageSortPlan['new']
        );
    }

    wallos_replace_subscription_price_rules($db, $subscriptionId, $userId, $subscriptionPriceRules);

    $db->exec('COMMIT');

    foreach ($imagesPendingFileDeletion as $imageRow) {
        wallos_delete_subscription_image_related_files(__DIR__ . '/../../', $imageRow);
    }

    $success['status'] = "Success";
    $text = $isEdit ? "updated" : "added";
    $success['message'] = translate('subscription_' . $text . '_successfuly', $i18n);
    if ($logoError !== "") {
        $success['logo_warning'] = $logoError;
    }
    header('Content-Type: application/json');
    echo json_encode($success);
    exit();
} catch (Throwable $throwable) {
    $db->exec('ROLLBACK');

    foreach ($storedUploadedImages as $storedImage) {
        wallos_delete_subscription_image_related_files(__DIR__ . '/../../', $storedImage);
    }

    subscription_error_response($throwable->getMessage());
}
$db->close();
?>
