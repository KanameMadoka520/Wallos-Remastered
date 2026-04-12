<?php
require_once 'includes/connect.php';
require_once 'includes/checkuser.php';

require_once 'includes/i18n/languages.php';
require_once 'includes/i18n/getlang.php';
require_once 'includes/i18n/' . $lang . '.php';
require_once 'includes/user_status.php';
require_once 'includes/request_logs.php';
require_once 'includes/login_rate_limit.php';

require_once 'includes/version.php';

function wallos_build_recycle_bin_login_message($i18n, $reason = '', $scheduledDeleteAt = '')
{
    $message = translate('account_in_recycle_bin', $i18n);

    $reason = trim((string) $reason);
    if ($reason !== '') {
        $message .= ' ' . translate('recycle_bin_reason_prefix', $i18n) . ' ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
    }

    $scheduledDeleteAt = trim((string) $scheduledDeleteAt);
    if ($scheduledDeleteAt !== '') {
        $message .= ' ' . translate('recycle_bin_scheduled_delete_prefix', $i18n) . ' ' . htmlspecialchars($scheduledDeleteAt, ENT_QUOTES, 'UTF-8');
    }

    return $message;
}

function wallos_mark_login_failed(&$loginFailed)
{
    $loginFailed = true;
}

if ($userCount == 0) {
    header("Location: registration.php");
    exit();
}

$secondsInMonth = 30 * 24 * 60 * 60;
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $secondsInMonth,             
        'httponly' => true,          
        'samesite' => 'Lax'          
    ]);
    session_start();
}
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    $db->close();
    header("Location: .");
    exit();
}

$demoMode = getenv('DEMO_MODE');

$cookieExpire = time() + (30 * 24 * 60 * 60);

// Check if login is disabled
$adminQuery = "SELECT login_disabled FROM admin";
$adminResult = $db->query($adminQuery);
$adminRow = $adminResult->fetchArray(SQLITE3_ASSOC);
if ($adminRow['login_disabled'] == 1) {

    $query = "SELECT id, username, main_currency, language FROM user WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id', 1, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if ($row === false) {
        // Something is wrong with admin user. Reenable login
        $updateQuery = "UPDATE admin SET login_disabled = 0";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->execute();

        $db->close();
        header("Location: login.php");
    } else {
        $userId = $row['id'];
        $main_currency = $row['main_currency'];
        $username = $row['username'];
        $language = $row['language'];

        $_SESSION['username'] = $username;
        $_SESSION['loggedin'] = true;
        $_SESSION['main_currency'] = $main_currency;
        $_SESSION['userId'] = $userId;
        setcookie('language', $language, [
            'expires' => $cookieExpire,
            'samesite' => 'Lax'
        ]);

        if (!isset($_COOKIE['sortOrder'])) {
            setcookie('sortOrder', 'next_payment', [
                'expires' => $cookieExpire,
                'samesite' => 'Lax'
            ]);
        }

        $query = "SELECT color_theme FROM settings";
        $stmt = $db->prepare($query);
        $result = $stmt->execute();
        $settings = $result->fetchArray(SQLITE3_ASSOC);
        setcookie('colorTheme', $settings['color_theme'], [
            'expires' => $cookieExpire,
            'samesite' => 'Lax',
        ]);

        $cookieValue = $username . "|" . "abc123ABC" . "|" . $main_currency;
        setcookie('wallos_login', $cookieValue, [
            'expires' => $cookieExpire,
            'samesite' => 'Lax',
            'httponly' => true,
        ]);

        $db->close();
        header("Location: .");
    }
}

if (isset($_SESSION['totp_user_id'])) {
    unset($_SESSION['totp_user_id']);
}

if (isset($_SESSION['token'])) {
    unset($_SESSION['token']);
}


$theme = "light";
$updateThemeSettings = false;
if (isset($_COOKIE['theme'])) {
    $theme = $_COOKIE['theme'];
} else {
    $updateThemeSettings = true;
}

$colorTheme = "blue";
if (isset($_COOKIE['colorTheme'])) {
    $colorTheme = $_COOKIE['colorTheme'];
}

// Check if OIDC is Enabled
$password_login_disabled = false;
$oidcEnabled = false;
$oidcQuery = "SELECT oidc_oauth_enabled FROM admin";
$oidcResult = $db->query($oidcQuery);
$oidcRow = $oidcResult->fetchArray(SQLITE3_ASSOC);
if ($oidcRow) {
    $oidcEnabled = $oidcRow['oidc_oauth_enabled'] == 1;
    if ($oidcEnabled) {
        // Fetch OIDC settings
        $oidcSettingsQuery = "SELECT * FROM oauth_settings WHERE id = 1";
        $oidcSettingsResult = $db->query($oidcSettingsQuery);
        $oidcSettings = $oidcSettingsResult->fetchArray(SQLITE3_ASSOC);
        if (!$oidcSettings) {
            $oidcEnabled = false;
        } else {
            $oidc_name = $oidcSettings['name'] ?? '';
            $password_login_disabled = $oidcSettings['password_login_disabled'] == 1;

            // Generate a CSRF-protecting state string
            $secondsInMonth = 30 * 24 * 60 * 60;
            if (session_status() === PHP_SESSION_NONE) {
                session_set_cookie_params([
                    'lifetime' => $secondsInMonth,             
                    'httponly' => true,          
                    'samesite' => 'Lax'          
                ]);
                session_start();
            }
            $state = bin2hex(random_bytes(16));
            $_SESSION['oidc_state'] = $state;

            // Build the OIDC authorization URL
            $params = http_build_query([
                'response_type' => 'code',
                'client_id' => $oidcSettings['client_id'],
                'redirect_uri' => $oidcSettings['redirect_url'],
                'scope' => $oidcSettings['scopes'],
                'state' => $state,
            ]);

            $oidc_auth_url = rtrim($oidcSettings['authorization_url'], '?') . '?' . $params;
        }
    }
}

$loginFailed = false;
$trashedAccountMessage = '';
$loginRateLimitMessage = '';
$hasSuccessMessage = (isset($_GET['validated']) && $_GET['validated'] == "true") || (isset($_GET['registered']) && $_GET['registered'] == true) ? true : false;
$userEmailWaitingVerification = false;
if (isset($_POST['username']) && isset($_POST['password'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $rememberMe = isset($_POST['remember']) ? true : false;
    $loginRateLimitIp = wallos_get_login_rate_limit_ip();
    $loginRateLimitUsername = wallos_normalize_login_rate_limit_username($username);

    wallos_prune_login_attempts($db);
    $blockedUntil = wallos_get_login_rate_limit_block($db, $loginRateLimitIp, $loginRateLimitUsername);

    if ($blockedUntil !== '') {
        wallos_mark_login_failed($loginFailed);
        $loginRateLimitMessage = wallos_build_login_rate_limit_message($i18n, $blockedUntil);
    } else {

    $query = "SELECT id, password, main_currency, language FROM user WHERE username = :username";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if ($row) {
        $hashedPasswordFromDb = $row['password'];
        $userId = $row['id'];
        $main_currency = $row['main_currency'];
        $language = $row['language'];
        if (password_verify($password, $hashedPasswordFromDb)) {
            wallos_clear_login_attempts($db, $loginRateLimitIp, $loginRateLimitUsername);
            $statusStmt = $db->prepare('SELECT username, account_status, trash_reason, scheduled_delete_at FROM user WHERE id = :userId');
            $statusStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $statusResult = $statusStmt->execute();
            $statusRow = $statusResult ? $statusResult->fetchArray(SQLITE3_ASSOC) : false;

            if ($statusRow && wallos_is_user_trashed($statusRow['account_status'] ?? WALLOS_USER_STATUS_ACTIVE)) {
                $trashedAccountMessage = wallos_build_recycle_bin_login_message(
                    $i18n,
                    $statusRow['trash_reason'] ?? '',
                    $statusRow['scheduled_delete_at'] ?? ''
                );
                $loginFailed = true;
                $userEmailWaitingVerification = false;
            } else {

            // Check if the user is in the email_verification table
            $query = "SELECT 1 FROM email_verification WHERE user_id = :userId";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $verificationMissing = $result->fetchArray(SQLITE3_ASSOC);

            // Check if the user has 2fa enabled
            $query = "SELECT totp_enabled FROM user WHERE id = :userId";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $totpEnabled = $result->fetchArray(SQLITE3_ASSOC);

            if ($verificationMissing) {
                $userEmailWaitingVerification = true;
                $loginFailed = true;
            } else {
                if ($totpEnabled['totp_enabled'] == 1) {
                    $_SESSION['totp_user_id'] = $userId;
                    if ($rememberMe) {
                        $_SESSION['pending_remember_me'] = true; // defer cookie until TOTP done
                    }
                    $db->close();
                    header("Location: totp.php");
                    exit();
                }

                // No TOTP — safe to create remember-me token now
                if ($rememberMe) {
                    $token = bin2hex(random_bytes(32));
                    $addLoginTokens = "INSERT INTO login_tokens (user_id, token) VALUES (:userId, :token)";
                    $addLoginTokensStmt = $db->prepare($addLoginTokens);
                    $addLoginTokensStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
                    $addLoginTokensStmt->bindParam(':token', $token, SQLITE3_TEXT);
                    $addLoginTokensStmt->execute();
                    $_SESSION['token'] = $token;
                    $cookieValue = $username . "|" . $token . "|" . $main_currency;
                    setcookie('wallos_login', $cookieValue, [
                        'expires' => $cookieExpire,
                        'samesite' => 'Lax',
                        'httponly' => true,
                    ]);
                }

                $_SESSION['username'] = $username;
                $_SESSION['loggedin'] = true;
                $_SESSION['main_currency'] = $main_currency;
                $_SESSION['userId'] = $userId;
                setcookie('language', $language, [
                    'expires' => $cookieExpire,
                    'samesite' => 'Lax'
                ]);

                if (!isset($_COOKIE['sortOrder'])) {
                    setcookie('sortOrder', 'next_payment', [
                        'expires' => $cookieExpire,
                        'samesite' => 'Lax'
                    ]);
                }

                $query = "SELECT color_theme FROM settings WHERE user_id = :userId";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                $result = $stmt->execute();
                $settings = $result->fetchArray(SQLITE3_ASSOC);
                setcookie('colorTheme', $settings['color_theme'], [
                    'expires' => $cookieExpire,
                    'samesite' => 'Lax'
                ]);

                $db->close();
                header("Location: .");
                exit();
            }
            }

        } else {
            $blockedUntil = wallos_record_failed_login_attempt($db, $loginRateLimitIp, $loginRateLimitUsername);
            wallos_mark_login_failed($loginFailed);
            if ($blockedUntil !== '') {
                $loginRateLimitMessage = wallos_build_login_rate_limit_message($i18n, $blockedUntil);
            }
        }
    } else {
        $blockedUntil = wallos_record_failed_login_attempt($db, $loginRateLimitIp, $loginRateLimitUsername);
        wallos_mark_login_failed($loginFailed);
        if ($blockedUntil !== '') {
            $loginRateLimitMessage = wallos_build_login_rate_limit_message($i18n, $blockedUntil);
        }
    }
    }
}

//Check if registration is open
$registrations = false;
$resetPasswordEnabled = false;
if (!$password_login_disabled) {
    $adminQuery = "SELECT registrations_open, max_users, server_url, smtp_address FROM admin";
    $adminResult = $db->query($adminQuery);
    $adminRow = $adminResult->fetchArray(SQLITE3_ASSOC);
    $registrationsOpen = $adminRow['registrations_open'];
    $maxUsers = $adminRow['max_users'];

    if ($registrationsOpen == 1 && $maxUsers == 0) {
        $registrations = true;
    } else if ($registrationsOpen == 1 && $maxUsers > 0) {
        $userCountQuery = "SELECT COUNT(id) as userCount FROM user WHERE account_status = :account_status";
        $userCountStmt = $db->prepare($userCountQuery);
        $userCountStmt->bindValue(':account_status', WALLOS_USER_STATUS_ACTIVE, SQLITE3_TEXT);
        $userCountResult = $userCountStmt->execute();
        $userCountRow = $userCountResult->fetchArray(SQLITE3_ASSOC);
        $userCount = $userCountRow['userCount'];
        if ($userCount < $maxUsers) {
            $registrations = true;
        }
    }

    if ($adminRow['smtp_address'] != "" && $adminRow['server_url'] != "") {
        $resetPasswordEnabled = true;
    }
}


if (isset($_GET['error']) && $_GET['error'] == "oidc_user_not_found") {
    $loginFailed = true;
}

if (isset($_GET['error']) && $_GET['error'] === 'account_trashed') {
    $loginFailed = true;
    $trashedAccountMessage = wallos_build_recycle_bin_login_message(
        $i18n,
        $_GET['reason'] ?? '',
        $_GET['scheduled_delete_at'] ?? ''
    );
}

wallos_log_request($db, 0, '');

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
        window.update_theme_settings = "<?= $updateThemeSettings ?>";
        window.color_theme = "<?= $colorTheme ?>";
    </script>
    <script type="text/javascript" src="scripts/login.js?<?= $version ?>"></script>
</head>

<body class="<?= $languages[$lang]['dir'] ?>">
    <div class="content">
        <section class="container">
            <div class="public-page-toolbar">
                <div class="public-page-language-switcher">
                    <label for="public-page-language-login"><?= translate('language', $i18n) ?>:</label>
                    <select id="public-page-language-login" onchange="changePublicPageLanguage(this.value)">
                        <?php
                        foreach ($languages as $code => $languageOption) {
                            $selected = ($code === $lang) ? 'selected' : '';
                            ?>
                            <option value="<?= $code ?>" <?= $selected ?>><?= $languageOption['name'] ?></option>
                            <?php
                        }
                        ?>
                    </select>
                </div>
            </div>
            <header>
                <div class="logo-image" title="Wallos - Subscription Tracker">
                    <?php include "images/siteicons/svg/logo.php"; ?>
                </div>
                <p>
                    <?= translate('please_login', $i18n) ?>
                </p>
            </header>
            <form action="login.php" method="post">
                <?php if (!$password_login_disabled) { ?>
                    <div class="form-group">
                        <label for="username"><?= translate('username', $i18n) ?>:</label>
                        <input type="text" id="username" name="username" autocomplete="username" required>
                    </div>
                    <div class="form-group">
                        <label for="password"><?= translate('password', $i18n) ?>:</label>
                        <input type="password" id="password" name="password" autocomplete="current-password" required>
                    </div>
                    <?php
                    if (!$demoMode) {
                        ?>
                        <div class="form-group-inline">
                            <input type="checkbox" id="remember" name="remember">
                            <label for="remember"><?= translate('stay_logged_in', $i18n) ?></label>
                        </div>
                        <?php
                    }
                    ?>
                    <div class="form-group">
                        <input type="submit" value="<?= translate('login', $i18n) ?>">
                    </div>
                <?php } ?>
                <div class="form-group">
                    <?php
                    if ($oidcEnabled) {
                        if (!$password_login_disabled) {
                            ?>
                            <span class="or-separator"><?= translate('or', $i18n) ?></span>
                            <?php
                        }
                        ?>
                        <a class="button secondary-button" href="<?= htmlspecialchars($oidc_auth_url) ?>">
                            <?= translate('login_with', $i18n) ?>     <?= htmlspecialchars($oidc_name) ?>
                        </a>
                        <?php
                    }
                    ?>
                </div>
                <?php
                if ($loginFailed) {
                    ?>
                    <ul class="error-box">
                        <?php
                        if ($userEmailWaitingVerification) {
                            ?>
                            <li><i
                                    class="fa-solid fa-triangle-exclamation"></i><?= translate('user_email_waiting_verification', $i18n) ?>
                            </li>
                            <?php
                        } else if ($loginRateLimitMessage !== '') {
                            ?>
                            <li><i class="fa-solid fa-triangle-exclamation"></i><?= $loginRateLimitMessage ?></li>
                            <?php
                        } else if ($trashedAccountMessage !== '') {
                            ?>
                            <li><i class="fa-solid fa-triangle-exclamation"></i><?= $trashedAccountMessage ?></li>
                            <?php
                        } else {
                            ?>
                            <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('login_failed', $i18n) ?></li>
                            <?php
                        }
                        ?>
                    </ul>
                    <?php
                }
                if ($hasSuccessMessage) {
                    ?>
                    <ul class="success-box">
                        <?php
                        if (isset($_GET['validated']) && $_GET['validated'] == "true") {
                            ?>
                            <li><i class="fa-solid fa-check"></i><?= translate('email_verified', $i18n) ?></li>
                            <?php
                        } else if (isset($_GET['registered']) && $_GET['registered']) {
                            ?>
                                <li><i class="fa-solid fa-check"></i><?= translate('registration_successful', $i18n) ?></li>
                                <?php
                                if (isset($_GET['requireValidation']) && $_GET['requireValidation'] == true) {
                                    ?>
                                    <li><?= translate('user_email_waiting_verification', $i18n) ?></li>
                                <?php
                                }
                        }
                        ?>
                    </ul>
                    <?php
                }

                if ($resetPasswordEnabled) {
                    ?>
                    <div class="login-form-link">
                        <a href="passwordreset.php"><?= translate('forgot_password', $i18n) ?></a>
                    </div>
                    <?php
                }
                ?>
                <?php
                if ($registrations) {
                    ?>
                    <div class="separator">
                        <input type="button" class="secondary-button" onclick="openRegitrationPage()"
                            value="<?= translate('register', $i18n) ?>"></input>
                    </div>
                    <?php
                }
                ?>
            </form>
        </section>
    </div>
    <script type="text/javascript">
        function openRegitrationPage() {
            window.location.href = "registration.php";
        }
    </script>
</body>

</html>
