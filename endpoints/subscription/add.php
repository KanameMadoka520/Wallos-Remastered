<?php
error_reporting(E_ERROR | E_PARSE);
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/inputvalidation.php';
require_once '../../includes/getsettings.php';
require_once '../../includes/subscription_media.php';
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
$cancellationDate = $_POST['cancellation_date'] ?? null;
$replacementSubscriptionId = $_POST['replacement_subscription_id'];
$detailImageUrlsRaw = $_POST['detail_image_urls'] ?? '';
$removeDetailImage = isset($_POST['remove-detail-image']) && $_POST['remove-detail-image'] === '1';

if ($replacementSubscriptionId == 0 || $inactive == 0) {
    $replacementSubscriptionId = null;
}

$userStmt = $db->prepare('SELECT user_group FROM user WHERE id = :userId');
$userStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$userResult = $userStmt->execute();
$currentUser = $userResult ? $userResult->fetchArray(SQLITE3_ASSOC) : false;

$isAdminUser = $userId === 1;
$currentUserGroup = wallos_normalize_user_group($currentUser['user_group'] ?? WALLOS_USER_GROUP_FREE);
$canUploadDetailImage = wallos_can_upload_subscription_images($isAdminUser, $currentUserGroup);
$compressDetailImage = $isAdminUser
    ? (isset($_POST['compress_subscription_image']) && $_POST['compress_subscription_image'] === '1')
    : $canUploadDetailImage;

try {
    $detailImageUrls = wallos_parse_subscription_image_urls($detailImageUrlsRaw, $i18n);
} catch (RuntimeException $exception) {
    subscription_error_response($exception->getMessage());
}

$existingSubscription = null;
$detailImage = '';
$oldDetailImage = '';
$newDetailImage = '';

if ($isEdit) {
    $id = (int) $_POST['id'];
    $existingStmt = $db->prepare('SELECT detail_image FROM subscriptions WHERE id = :id AND user_id = :userId');
    $existingStmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $existingStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $existingResult = $existingStmt->execute();
    $existingSubscription = $existingResult ? $existingResult->fetchArray(SQLITE3_ASSOC) : false;

    if ($existingSubscription === false) {
        subscription_error_response(translate('error', $i18n));
    }

    $oldDetailImage = $existingSubscription['detail_image'] ?? '';
    $detailImage = $oldDetailImage;
}

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

if (!$canUploadDetailImage && !empty($_FILES['detail_image']['name'])) {
    subscription_error_response(translate('subscription_image_no_upload_permission', $i18n));
}

if ($removeDetailImage) {
    $detailImage = '';
}

if ($canUploadDetailImage && !empty($_FILES['detail_image']['name'])) {
    try {
        $newDetailImage = wallos_store_uploaded_subscription_image(
            $_FILES['detail_image'],
            $name,
            __DIR__ . '/../../',
            $compressDetailImage,
            $i18n
        );
        $detailImage = $newDetailImage;
    } catch (RuntimeException $exception) {
        subscription_error_response($exception->getMessage());
    }
}

$detailImageUrlsJson = wallos_encode_subscription_image_urls($detailImageUrls);

if (!$isEdit) {
    $sql = "INSERT INTO subscriptions (
                        name, logo, price, currency_id, next_payment, cycle, frequency, notes,
                        payment_method_id, payer_user_id, category_id, notify, inactive, url,
                        notify_days_before, user_id, cancellation_date, replacement_subscription_id,
                        auto_renew, start_date, detail_image, detail_image_urls
                    ) VALUES (
                        :name, :logo, :price, :currencyId, :nextPayment, :cycle, :frequency, :notes,
                        :paymentMethodId, :payerUserId, :categoryId, :notify, :inactive, :url,
                        :notifyDaysBefore, :userId, :cancellationDate, :replacement_subscription_id,
                        :autoRenew, :startDate, :detailImage, :detailImageUrls
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
                        detail_image = :detailImage,
                        detail_image_urls = :detailImageUrls";

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
    $stmt->bindParam(':id', $id, SQLITE3_INTEGER);
}
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindParam(':replacement_subscription_id', $replacementSubscriptionId, SQLITE3_INTEGER);
$stmt->bindParam(':detailImage', $detailImage, SQLITE3_TEXT);
$stmt->bindParam(':detailImageUrls', $detailImageUrlsJson, SQLITE3_TEXT);

if ($stmt->execute()) {
    if ($oldDetailImage !== '' && $oldDetailImage !== $detailImage) {
        wallos_delete_subscription_image_if_unused($db, __DIR__ . '/../../', $oldDetailImage);
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
} else {
    if ($newDetailImage !== '') {
        wallos_delete_subscription_image_file(__DIR__ . '/../../', $newDetailImage);
    }
    subscription_error_response(translate('error', $i18n) . ": " . $db->lastErrorMsg());
}
$db->close();
?>
