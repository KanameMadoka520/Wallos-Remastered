<?php
require_once 'includes/connect.php';
require_once 'includes/checkuser.php';

require_once 'includes/i18n/languages.php';
require_once 'includes/i18n/getlang.php';
require_once 'includes/i18n/' . $lang . '.php';
require_once 'includes/request_security.php';
require_once 'includes/theme_resolver.php';
require_once 'includes/theme_cookie_sync.php';

require_once 'includes/version.php';

if ($userCount == 0) {
    header("Location: registration.php");
    exit();
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(wallos_build_session_cookie_params(30 * 24 * 60 * 60));
    session_start();
}

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    $db->close();
    header("Location: .");
    exit();
}

if (!isset($_SESSION['totp_user_id'])) {
    $db->close();
    header("Location: login.php");
    exit();
}

$theme = wallos_resolve_public_theme_cookie();
$updateThemeSettings = wallos_public_theme_requires_live_update();
$colorTheme = wallos_resolve_public_color_theme_cookie();

$pendingThemeSettings = wallos_fetch_theme_cookie_settings($db, $_SESSION['totp_user_id']);
if ($pendingThemeSettings !== null) {
    wallos_apply_public_theme_view_settings_from_row($pendingThemeSettings, $theme, $updateThemeSettings, $colorTheme);
}

$demoMode = getenv('DEMO_MODE');

$cookieExpire = time() + (30 * 24 * 60 * 60);
$invalidTotp = false;
$totpLocked = false;

if (isset($_POST['one-time-code'])) {
    $totp_code = trim((string) $_POST['one-time-code']);

    $statement = $db->prepare('SELECT totp_secret, backup_codes, last_totp_used, failed_attempts, lockout_until FROM totp WHERE user_id = :id');
    $statement->bindValue(':id', $_SESSION['totp_user_id'], SQLITE3_INTEGER);
    $result = $statement->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $totp_secret = $row['totp_secret'] ?? '';
    $backupCodes = json_decode($row['backup_codes'] ?? '[]', true);
    $backupCodes = is_array($backupCodes) ? $backupCodes : [];
    $failedAttempts = (int) ($row['failed_attempts'] ?? 0);
    $lockoutUntil = (int) ($row['lockout_until'] ?? 0);
    $totpLocked = $lockoutUntil > time();

    require_once 'libs/OTPHP/FactoryInterface.php';
    require_once 'libs/OTPHP/Factory.php';
    require_once 'libs/OTPHP/ParameterTrait.php';
    require_once 'libs/OTPHP/OTPInterface.php';
    require_once 'libs/OTPHP/OTP.php';
    require_once 'libs/OTPHP/TOTPInterface.php';
    require_once 'libs/OTPHP/TOTP.php';
    require_once 'libs/Psr/Clock/ClockInterface.php';
    require_once 'libs/OTPHP/InternalClock.php';
    require_once 'libs/constant_time_encoding/Binary.php';
    require_once 'libs/constant_time_encoding/EncoderInterface.php';
    require_once 'libs/constant_time_encoding/Base32.php';

    $valid = false;
    $matchedStep = null;

    if (!$totpLocked && $totp_secret !== '') {
        $clock = new OTPHP\InternalClock();
        $totp = OTPHP\TOTP::createFromSecret($totp_secret, $clock);
        $totp->setPeriod(30);

        // Check the normal +/-15 second window while retaining the matched
        // step so a code cannot be replayed in the same window.
        $currentStep = intdiv(time(), 30);
        $lastUsedStep = (int) ($row['last_totp_used'] ?? 0);
        if ($lastUsedStep > $currentStep) {
            // Older installs stored a Unix timestamp instead of a step.
            $lastUsedStep = intdiv($lastUsedStep, 30);
        }

        foreach ([time() - 15, time(), time() + 15] as $candidate) {
            if ($candidate >= 0 && hash_equals($totp->at($candidate), $totp_code)) {
                $matchedStep = intdiv($candidate, 30);
                break;
            }
        }

        $valid = $matchedStep !== null && $matchedStep > $lastUsedStep;
        if ($valid) {
            $statement = $db->prepare('UPDATE totp SET last_totp_used = :last_totp_used WHERE user_id = :id');
            $statement->bindValue(':last_totp_used', $matchedStep, SQLITE3_INTEGER);
            $statement->bindValue(':id', $_SESSION['totp_user_id'], SQLITE3_INTEGER);
            $statement->execute();
        }

        if (!$valid && in_array($totp_code, $backupCodes, true)) {
            $key = array_search($totp_code, $backupCodes, true);
            unset($backupCodes[$key]);
            $backupCodes = array_values($backupCodes);
            $statement = $db->prepare('UPDATE totp SET backup_codes = :backup_codes WHERE user_id = :id');
            $statement->bindValue(':backup_codes', json_encode($backupCodes), SQLITE3_TEXT);
            $statement->bindValue(':id', $_SESSION['totp_user_id'], SQLITE3_INTEGER);
            $statement->execute();
            $valid = true;
        }
    }

    if ($valid) {
        $counterStmt = $db->prepare('UPDATE totp SET failed_attempts = 0, lockout_until = 0 WHERE user_id = :id');
        $counterStmt->bindValue(':id', $_SESSION['totp_user_id'], SQLITE3_INTEGER);
        $counterStmt->execute();
    } elseif (!$totpLocked) {
        $invalidTotp = true;
        $failedAttempts++;
        if ($failedAttempts >= 5) {
            $counterStmt = $db->prepare('UPDATE totp SET failed_attempts = 0, lockout_until = :lockout_until WHERE user_id = :id');
            $counterStmt->bindValue(':lockout_until', time() + 30, SQLITE3_INTEGER);
            $counterStmt->bindValue(':id', $_SESSION['totp_user_id'], SQLITE3_INTEGER);
            $counterStmt->execute();
            $totpLocked = true;
        } else {
            $counterStmt = $db->prepare('UPDATE totp SET failed_attempts = :failed_attempts WHERE user_id = :id');
            $counterStmt->bindValue(':failed_attempts', $failedAttempts, SQLITE3_INTEGER);
            $counterStmt->bindValue(':id', $_SESSION['totp_user_id'], SQLITE3_INTEGER);
            $counterStmt->execute();
        }
    } else {
        $invalidTotp = true;
    }

    if ($valid) {
        $query = "SELECT id, username, main_currency, language FROM user WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':id', $_SESSION['totp_user_id'], SQLITE3_INTEGER);
        $result = $stmt->execute();
        $user = $result->fetchArray(SQLITE3_ASSOC);

        session_regenerate_id(true);

        $_SESSION['username'] = $user['username'];
        $_SESSION['loggedin'] = true;
        $_SESSION['main_currency'] = $user['main_currency'];
        $_SESSION['userId'] = $user['id'];

        if (!empty($_SESSION['pending_remember_me'])) {
            $token = bin2hex(random_bytes(32));
            $addLoginTokens = "INSERT INTO login_tokens (user_id, token) VALUES (:userId, :token)";
            $addLoginTokensStmt = $db->prepare($addLoginTokens);
            $addLoginTokensStmt->bindParam(':userId', $user['id'], SQLITE3_INTEGER);
            $addLoginTokensStmt->bindParam(':token', $token, SQLITE3_TEXT);
            $addLoginTokensStmt->execute();
            $cookieExpire = time() + (30 * 24 * 60 * 60);
            $cookieValue = $user['username'] . "|" . $token . "|" . $user['main_currency'];
            setcookie('wallos_login', $cookieValue, wallos_build_cookie_options($cookieExpire, ['httponly' => true]));
            unset($_SESSION['pending_remember_me']);
        }

        setcookie('language', $user['language'], wallos_build_cookie_options($cookieExpire));

        if (!isset($_COOKIE['sortOrder'])) {
            setcookie('sortOrder', 'manual_order', wallos_build_cookie_options($cookieExpire));
        }

        wallos_sync_theme_cookies_for_user($db, $_SESSION['totp_user_id'], $cookieExpire);

        unset($_SESSION['totp_user_id']);

        $db->close();
        header("Location: .");
        exit();
    }

}

?>
<!DOCTYPE html>
<html dir="<?= $languages[$lang]['dir'] ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="<?= $theme == "light" ? "#FFFFFF" : "#222222" ?>" id="theme-color" />
    <meta name="apple-mobile-web-app-title" content="Wallos">
    <title>Wallos - Subscription Tracker</title>
    <link rel="icon" type="image/png" href="images/icon/favicon.ico" sizes="16x16">
    <link rel="apple-touch-icon" href="images/icon/apple-touch-icon.png">
    <link rel="apple-touch-icon" sizes="152x152" href="images/icon/apple-touch-icon-152.png">
    <link rel="apple-touch-icon" sizes="180x180" href="images/icon/apple-touch-icon-180.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="styles/theme.css?<?= $version ?>">
    <link rel="stylesheet" href="styles/login.css?<?= $version ?>">
    <link rel="stylesheet" href="styles/themes/red.css?<?= $version ?>" id="red-theme" <?= $colorTheme != "red" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/themes/green.css?<?= $version ?>" id="green-theme" <?= $colorTheme != "green" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/themes/yellow.css?<?= $version ?>" id="yellow-theme" <?= $colorTheme != "yellow" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/themes/purple.css?<?= $version ?>" id="purple-theme" <?= $colorTheme != "purple" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/font-awesome.min.css">
    <link rel="stylesheet" href="styles/barlow.css">
    <link rel="stylesheet" href="styles/login-dark-theme.css?<?= $version ?>" id="dark-theme" <?= $theme == "light" ? "disabled" : "" ?>>
    <script type="text/javascript">
        window.update_theme_settings = <?= $updateThemeSettings ? 'true' : 'false' ?>;
        window.color_theme = "<?= $colorTheme ?>";
    </script>
    <script type="text/javascript" src="scripts/login.js?<?= $version ?>"></script>
</head>

<body class="<?= $languages[$lang]['dir'] ?>">
    <div class="content">
        <section class="container">
            <header>
                <div class="logo-image" title="Wallos - Subscription Tracker">
                    <?php include "images/siteicons/svg/logo.php"; ?>
                </div>
                <p>
                    <?= translate('insert_totp_code', $i18n) ?>
                </p>
            </header>
            <form action="totp.php" method="post">
                <div class="form-group">
                    <label for="one-time-code"><?= translate('totp_code', $i18n) ?>:</label>
                    <input type="text" id="one-time-code" name="one-time-code" autocomplete="one-time-code" required>
                </div>
                <div class="form-group">
                    <input type="submit" value="<?= translate('login', $i18n) ?>">
                </div>
                <?php
                if ($invalidTotp) {
                    ?>
                    <ul class="error-box">
                        <li>
                            <i class="fa-solid fa-triangle-exclamation"></i><?= translate('totp_code_incorrect', $i18n) ?>
                        </li>
                    </ul>
                    <?php
                }
                ?>

            </form>
        </section>
    </div>
</body>

</html>
