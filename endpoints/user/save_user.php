<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/inputvalidation.php';
require_once '../../includes/validate_endpoint.php';

header('Content-Type: application/json');

$avatarUploadDirectory = '../../images/uploads/logos/avatars/';
if (!is_dir($avatarUploadDirectory)) {
    mkdir($avatarUploadDirectory, 0755, true);
}

function save_user_response($success, $message, array $extra = [])
{
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message,
    ], $extra));
    exit();
}

function update_exchange_rate($db, $userId)
{
    $query = "SELECT api_key, provider FROM fixer WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($result) {
        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row) {
            $apiKey = $row['api_key'];
            $provider = $row['provider'];

            $codes = "";
            $query = "SELECT id, name, symbol, code FROM currencies";
            $result = $db->query($query);
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $codes .= $row['code'] . ",";
            }
            $codes = rtrim($codes, ',');

            $query = "SELECT u.main_currency, c.code FROM user u LEFT JOIN currencies c ON u.main_currency = c.id WHERE u.id = :userId";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $mainCurrencyCode = $row['code'];
            $mainCurrencyId = $row['main_currency'];

            if ($provider === 1) {
                $api_url = "https://api.apilayer.com/fixer/latest?base=EUR&symbols=" . $codes;
                $context = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'header' => 'apikey: ' . $apiKey,
                    ]
                ]);
                $response = file_get_contents($api_url, false, $context);
            } else {
                $api_url = "http://data.fixer.io/api/latest?access_key=" . $apiKey . "&base=EUR&symbols=" . $codes;
                $response = file_get_contents($api_url);
            }

            $apiData = json_decode($response, true);

            $mainCurrencyToEUR = $apiData['rates'][$mainCurrencyCode];

            if ($apiData !== null && isset($apiData['rates'])) {
                foreach ($apiData['rates'] as $currencyCode => $rate) {
                    if ($currencyCode === $mainCurrencyCode) {
                        $exchangeRate = 1.0;
                    } else {
                        $exchangeRate = $rate / $mainCurrencyToEUR;
                    }
                    $updateQuery = "UPDATE currencies SET rate = :rate WHERE code = :code AND user_id = :userId";
                    $updateStmt = $db->prepare($updateQuery);
                    $updateStmt->bindParam(':rate', $exchangeRate, SQLITE3_TEXT);
                    $updateStmt->bindParam(':code', $currencyCode, SQLITE3_TEXT);
                    $updateStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
                    $updateResult = $updateStmt->execute();
                }
                $currentDate = new DateTime();
                $formattedDate = $currentDate->format('Y-m-d');

                $query = "SELECT * FROM last_exchange_update WHERE user_id = :userId";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
                $result = $stmt->execute();
                $row = $result->fetchArray(SQLITE3_ASSOC);

                if ($row) {
                    $query = "UPDATE last_exchange_update SET date = :formattedDate WHERE user_id = :userId";
                } else {
                    $query = "INSERT INTO last_exchange_update (date, user_id) VALUES (:formattedDate, :userId)";
                }

                $stmt = $db->prepare($query);
                $stmt->bindParam(':formattedDate', $formattedDate, SQLITE3_TEXT);
                $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
                $resutl = $stmt->execute();

                $db->close();
            }
        }
    }
}

$demoMode = getenv('DEMO_MODE');

$query = "SELECT main_currency FROM user WHERE id = :userId";
$stmt = $db->prepare($query);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$row = $result->fetchArray(SQLITE3_ASSOC);
$mainCurrencyId = $row['main_currency'];

function sanitizeFilename($filename)
{
    $filename = preg_replace("/[^a-zA-Z0-9\s]/", "", $filename);
    $filename = str_replace(" ", "-", $filename);
    $filename = str_replace(".", "", $filename);
    return $filename !== "" ? $filename : "avatar";
}

function getAvatarMimeTypeMap()
{
    return [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
}

function loadAvatarImageResource($tmpName, $mimeType)
{
    if ($mimeType === 'image/png') {
        return imagecreatefrompng($tmpName);
    }

    if ($mimeType === 'image/jpeg') {
        return imagecreatefromjpeg($tmpName);
    }

    if ($mimeType === 'image/gif') {
        return imagecreatefromgif($tmpName);
    }

    if ($mimeType === 'image/webp') {
        return imagecreatefromwebp($tmpName);
    }

    return false;
}

function writeAvatarImageResource($image, $destination, $mimeType)
{
    if ($mimeType === 'image/png') {
        return imagepng($image, $destination);
    }

    if ($mimeType === 'image/jpeg') {
        return imagejpeg($image, $destination, 92);
    }

    if ($mimeType === 'image/gif') {
        return imagegif($image, $destination);
    }

    if ($mimeType === 'image/webp') {
        return imagewebp($image, $destination, 92);
    }

    return false;
}

function resizeAndUploadAvatar($uploadedFile, $uploadDir, $name, $i18n)
{
    $uploadError = (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return "";
    }

    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException(translate('error_updating_user_data', $i18n));
    }

    $tmpName = $uploadedFile['tmp_name'] ?? '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException(translate('error_updating_user_data', $i18n));
    }

    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false || empty($imageInfo['mime'])) {
        throw new RuntimeException(translate('file_type_error', $i18n));
    }

    $mimeTypeMap = getAvatarMimeTypeMap();
    $mimeType = $imageInfo['mime'];
    if (!isset($mimeTypeMap[$mimeType])) {
        throw new RuntimeException(translate('file_type_error', $i18n));
    }

    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);
    if ($width < 1 || $height < 1) {
        throw new RuntimeException(translate('file_type_error', $i18n));
    }

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $targetWidth = 80;
    $targetHeight = 80;
    $fileExtension = $mimeTypeMap[$mimeType];
    $fileName = time() . '-avatars-' . sanitizeFilename($name) . '.' . $fileExtension;
    $uploadFile = $uploadDir . $fileName;

    $image = loadAvatarImageResource($tmpName, $mimeType);
    if ($image === false) {
        throw new RuntimeException(translate('file_type_error', $i18n));
    }

    if ($mimeType !== 'image/jpeg') {
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
    if ($mimeType !== 'image/jpeg') {
        imagealphablending($resizedImage, false);
        imagesavealpha($resizedImage, true);
        $transparency = imagecolorallocatealpha($resizedImage, 0, 0, 0, 127);
        imagefill($resizedImage, 0, 0, $transparency);
    }

    imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    $writeSucceeded = writeAvatarImageResource($resizedImage, $uploadFile, $mimeType);

    imagedestroy($image);
    imagedestroy($resizedImage);

    if (!$writeSucceeded) {
        throw new RuntimeException(translate('error_updating_user_data', $i18n));
    }

    return "images/uploads/logos/avatars/" . $fileName;
}

if (
    isset($_SESSION['username']) 
    && isset($_POST['firstname'])
    && isset($_POST['lastname'])
    && isset($_POST['email']) && $_POST['email'] !== ""
    && isset($_POST['avatar']) && $_POST['avatar'] !== ""
    && isset($_POST['main_currency']) && $_POST['main_currency'] !== ""
    && isset($_POST['language']) && $_POST['language'] !== ""
) {

    $firstname = validate($_POST['firstname']);
    $lastname = validate($_POST['lastname']);
    $email = validate($_POST['email']);

    $query = "SELECT email FROM user WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', $userId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);

    $oldEmail = $user['email'];

    if ($oldEmail != $email) {
        $query = "SELECT email FROM user WHERE email = :email AND id != :userId";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $otherUser = $result->fetchArray(SQLITE3_ASSOC);

        if ($otherUser) {
            save_user_response(false, translate('email_exists', $i18n));
        }
    }

    $avatar = filter_var($_POST['avatar'], FILTER_SANITIZE_URL);
    $main_currency = $_POST['main_currency'];
    $language = $_POST['language'];

    if (!empty($_FILES['profile_pic']["name"])) {
        $file = $_FILES['profile_pic'];
        $name = $file['name'];

        try {
            $avatar = resizeAndUploadAvatar($_FILES['profile_pic'], $avatarUploadDirectory, $name, $i18n);
        } catch (RuntimeException $exception) {
            save_user_response(false, $exception->getMessage());
        }

        if ($avatar !== "") {
            $stmt = $db->prepare("INSERT INTO uploaded_avatars (user_id, path) VALUES (:userId, :path)");
            $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
            $stmt->bindParam(':path', $avatar, SQLITE3_TEXT);
            $stmt->execute();
        }
    }

    if (isset($_POST['password']) && $_POST['password'] != "" && !$demoMode) {
        $password = $_POST['password'];
        if (isset($_POST['confirm_password'])) {
            $confirm = $_POST['confirm_password'];
            if ($password != $confirm) {
                save_user_response(false, translate('passwords_dont_match', $i18n));
            }
        } else {
            save_user_response(false, translate('passwords_dont_match', $i18n));
        }
    }

    if (isset($_POST['password']) && $_POST['password'] != "" && !$demoMode) {
        $sql = "UPDATE user SET avatar = :avatar, firstname = :firstname, lastname = :lastname, email = :email, password = :password, main_currency = :main_currency, language = :language WHERE id = :userId";
    } else {
        $sql = "UPDATE user SET avatar = :avatar, firstname = :firstname, lastname = :lastname, email = :email, main_currency = :main_currency, language = :language WHERE id = :userId";
    }

    $stmt = $db->prepare($sql);
    $stmt->bindParam(':avatar', $avatar, SQLITE3_TEXT);
    $stmt->bindParam(':firstname', $firstname, SQLITE3_TEXT);
    $stmt->bindParam(':lastname', $lastname, SQLITE3_TEXT);
    $stmt->bindParam(':email', $email, SQLITE3_TEXT);
    $stmt->bindParam(':main_currency', $main_currency, SQLITE3_INTEGER);
    $stmt->bindParam(':language', $language, SQLITE3_TEXT);
    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);

    if (isset($_POST['password']) && $_POST['password'] != "" && !$demoMode) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt->bindParam(':password', $hashedPassword, SQLITE3_TEXT);
    }

    $result = $stmt->execute();

    if ($result) {
        $cookieExpire = time() + (30 * 24 * 60 * 60);
        $oldLanguage = isset($_COOKIE['language']) ? $_COOKIE['language'] : "en";
        $root = str_replace('/endpoints/user', '', dirname($_SERVER['PHP_SELF']));
        $root = $root == '' ? '/' : $root;
        setcookie('language', $language, [
            'path' => $root,
            'expires' => $cookieExpire,
            'samesite' => 'Lax'
        ]);
        $_SESSION['firstname'] = $firstname;
        $_SESSION['avatar'] = $avatar;
        $_SESSION['main_currency'] = $main_currency;

        if ($main_currency != $mainCurrencyId) {
            update_exchange_rate($db, $userId);
        }

        $reload = $oldLanguage != $language;
        save_user_response(true, translate('user_details_saved', $i18n), [
            "reload" => $reload
        ]);
    } else {
        save_user_response(false, translate('error_updating_user_data', $i18n));
    }

    exit();
} else {
    save_user_response(false, translate('fill_all_fields', $i18n));
}
