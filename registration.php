<?php
require_once 'includes/connect.php';
require_once 'includes/checkuser.php';

require_once 'includes/i18n/languages.php';
require_once 'includes/i18n/getlang.php';
require_once 'includes/i18n/' . $lang . '.php';
require_once 'includes/default_user_seed.php';
require_once 'includes/invite_codes.php';
require_once 'includes/request_logs.php';
require_once 'includes/user_status.php';
require_once 'includes/decorative_background.php';
require_once 'includes/theme_resolver.php';
require_once 'includes/settings_defaults.php';
require_once 'includes/public_page_branding.php';
require_once 'includes/public_entry_animation.php';

require_once 'includes/version.php';

$loginCssVersion = $version . '.' . @filemtime(__DIR__ . '/styles/login.css');
$registrationJsVersion = $version . '.' . @filemtime(__DIR__ . '/scripts/registration.js');
$decorativeBackgroundCssVersion = $version . '.' . @filemtime(__DIR__ . '/styles/decorative-background.css');
$decorativeBackgroundJsVersion = $version . '.' . @filemtime(__DIR__ . '/scripts/decorative-background.js');
$publicEntryTransitionCssVersion = $version . '.' . @filemtime(__DIR__ . '/styles/public-entry-transition.css');
$publicEntryTransitionJsVersion = $version . '.' . @filemtime(__DIR__ . '/scripts/public-entry-transition.js');

function validate($value)
{
    $value = trim($value);
    $value = stripslashes($value);
    $value = htmlspecialchars($value);
    $value = htmlentities($value);
    return $value;
}

// If logo folder doesn't exist, create it
if (!file_exists('images/uploads/logos')) {
    mkdir('images/uploads/logos', 0777, true);
    mkdir('images/uploads/logos/avatars', 0777, true);
}

// If there's already a user on the database, redirect to login page if registrations are closed or maxn users is reached
$stmt = $db->prepare('SELECT COUNT(*) as userCount FROM user WHERE account_status = :account_status');
$stmt->bindValue(':account_status', WALLOS_USER_STATUS_ACTIVE, SQLITE3_TEXT);
$result = $stmt->execute();
$userCountResult = $result->fetchArray(SQLITE3_ASSOC);
$userCount = $userCountResult['userCount'];
$inviteOnlyRegistrationEnabled = false;

if ($userCount > 0) {
    $stmt = $db->prepare('SELECT * FROM admin');
    $result = $stmt->execute();
    $settings = $result->fetchArray(SQLITE3_ASSOC);
    $inviteOnlyRegistrationEnabled = !empty($settings['invite_only_registration']);

    if ($settings['registrations_open'] == 0) {
        header("Location: login.php");
        exit();
    }

    if ($settings['max_users'] != 0) {

        if ($userCount >= $settings['max_users']) {
            header("Location: login.php");
            exit();
        }
    }
}

$publicThemePreferences = wallos_resolve_public_theme_preferences();
$theme = $publicThemePreferences['theme'];
$updateThemeSettings = $publicThemePreferences['update_theme_settings'];
$colorTheme = wallos_resolve_public_color_theme_cookie();
$publicPageBranding = wallos_get_public_page_branding($db);

$decorativeBackgroundEnabled = wallos_is_public_decorative_background_enabled();
$decorativeBackgroundClass = $decorativeBackgroundEnabled ? 'decorative-background-enabled' : 'decorative-background-disabled';

$currencies = wallos_get_default_currencies($lang);
$categories = wallos_get_default_categories($lang);
$payment_methods = wallos_get_default_payment_methods($lang);

$defaultMainCurrencyCode = 'CNY';
$availableCurrencyCodes = array_column($currencies, 'code');
if (!in_array($defaultMainCurrencyCode, $availableCurrencyCodes, true) && !empty($availableCurrencyCodes)) {
    $defaultMainCurrencyCode = $availableCurrencyCodes[0];
}

$passwordMismatch = false;
$usernameExists = false;
$emailExists = false;
$registrationFailed = false;
$inviteCodeRequired = false;
$inviteCodeInvalid = false;
$hasErrors = false;
if (isset($_POST['username'])) {
    $username = validate($_POST['username']);
    $firstname = validate($_POST['firstname']);
    $lastname = validate($_POST['lastname']);
    $email = validate($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $main_currency = $_POST['main_currency'];
    $invite_code = validate($_POST['invite_code'] ?? '');
    $language = wallos_resolve_supported_language($_POST['language'], array_keys($languages));
    $inviteCodeRow = false;
    $seedCurrencies = wallos_get_default_currencies($language);
    $seedCategories = wallos_get_default_categories($language);
    $seedPaymentMethods = wallos_get_default_payment_methods($language);
    $main_currency_index = array_search($main_currency, array_column($seedCurrencies, 'code'), true);
    if ($main_currency_index === false) {
        $main_currency_index = 0;
        $main_currency = $seedCurrencies[0]['code'];
    }
    $main_currency_id = $seedCurrencies[$main_currency_index]['id'];
    $avatar = "images/avatars/0.svg";

    if ($password != $confirm_password) {
        $passwordMismatch = true;
        $hasErrors = true;
    }

    $emailQuery = "SELECT * FROM user WHERE email = :email";
    $stmtEmail = $db->prepare($emailQuery);
    $stmtEmail->bindValue(':email', $email, SQLITE3_TEXT);
    $resultEmail = $stmtEmail->execute();

    if ($resultEmail->fetchArray()) {
        $emailExists = true;
        $hasErrors = true;
    }

    $usernameQuery = "SELECT * FROM user WHERE username = :username";
    $stmtUsername = $db->prepare($usernameQuery);
    $stmtUsername->bindValue(':username', $username, SQLITE3_TEXT);
    $resultUsername = $stmtUsername->execute();

    if ($resultUsername->fetchArray()) {
        $usernameExists = true;
        $hasErrors = true;
    }

    if ($inviteOnlyRegistrationEnabled) {
        if ($invite_code === '') {
            $inviteCodeRequired = true;
            $hasErrors = true;
        } else {
            $inviteCodeRow = wallos_find_available_invite_code($db, $invite_code);
            if ($inviteCodeRow === false) {
                $inviteCodeInvalid = true;
                $hasErrors = true;
            }
        }
    }

    $requireValidation = false;

    if ($hasErrors == false) {
        try {
            $db->exec('BEGIN IMMEDIATE');

            $query = "INSERT INTO user (username, firstname, lastname, email, password, main_currency, avatar, language, budget) VALUES (:username, :firstname, :lastname, :email, :password, :main_currency, :avatar, :language, :budget)";
            $stmt = $db->prepare($query);
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt->bindValue(':username', $username, SQLITE3_TEXT);
            $stmt->bindValue(':firstname', $firstname, SQLITE3_TEXT);
            $stmt->bindValue(':lastname', $lastname, SQLITE3_TEXT);
            $stmt->bindValue(':email', $email, SQLITE3_TEXT);
            $stmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
            $stmt->bindValue(':main_currency', $main_currency_id, SQLITE3_TEXT);
            $stmt->bindValue(':avatar', $avatar, SQLITE3_TEXT);
            $stmt->bindValue(':language', $language, SQLITE3_TEXT);
            $stmt->bindValue(':budget', 0, SQLITE3_INTEGER);
            $result = $stmt->execute();

            if (!$result) {
                throw new RuntimeException('failed');
            }

            $userId = $db->lastInsertRowID();

            $query = "INSERT INTO household (name, user_id) VALUES (:name, :user_id)";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':name', $username, SQLITE3_TEXT);
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $stmt->execute();

            if ($userId > 1) {
                $query = 'INSERT INTO categories (name, "order", user_id) VALUES (:name, :order, :user_id)';
                $stmt = $db->prepare($query);
                foreach ($seedCategories as $index => $category) {
                    $stmt->bindValue(':name', $category['name'], SQLITE3_TEXT);
                    $stmt->bindValue(':order', $index + 1, SQLITE3_INTEGER);
                    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                    $stmt->execute();
                }

                $query = 'INSERT INTO payment_methods (name, icon, "order", user_id) VALUES (:name, :icon, :order, :user_id)';
                $stmt = $db->prepare($query);
                foreach ($seedPaymentMethods as $index => $payment_method) {
                    $stmt->bindValue(':name', $payment_method['name'], SQLITE3_TEXT);
                    $stmt->bindValue(':icon', $payment_method['icon'], SQLITE3_TEXT);
                    $stmt->bindValue(':order', $index + 1, SQLITE3_INTEGER);
                    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                    $stmt->execute();
                }

                $query = "INSERT INTO currencies (name, symbol, code, rate, user_id) VALUES (:name, :symbol, :code, :rate, :user_id)";
                $stmt = $db->prepare($query);
                foreach ($seedCurrencies as $currency) {
                    $stmt->bindValue(':name', $currency['name'], SQLITE3_TEXT);
                    $stmt->bindValue(':symbol', $currency['symbol'], SQLITE3_TEXT);
                    $stmt->bindValue(':code', $currency['code'], SQLITE3_TEXT);
                    $stmt->bindValue(':rate', 1, SQLITE3_FLOAT);
                    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                    $stmt->execute();
                }

                $query = "SELECT id FROM currencies WHERE code = :code AND user_id = :user_id";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':code', $main_currency, SQLITE3_TEXT);
                $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                $result = $stmt->execute();
                $currency = $result->fetchArray(SQLITE3_ASSOC);

                $query = "UPDATE user SET main_currency = :main_currency WHERE id = :user_id";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':main_currency', $currency['id'], SQLITE3_INTEGER);
                $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                $stmt->execute();

                if (!wallos_insert_default_settings($db, $userId)) {
                    throw new RuntimeException('failed');
                }

                $query = "SELECT * FROM admin";
                $stmt = $db->prepare($query);
                $result = $stmt->execute();
                $settings = $result->fetchArray(SQLITE3_ASSOC);

                if ($settings['require_email_verification'] == 1) {
                    $query = "INSERT INTO email_verification (user_id, email, token, email_sent) VALUES (:user_id, :email, :token, 0)";
                    $stmt = $db->prepare($query);
                    $token = bin2hex(random_bytes(32));
                    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
                    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
                    $stmt->execute();

                    $requireValidation = true;
                }
            }

            if ($inviteOnlyRegistrationEnabled && $inviteCodeRow !== false) {
                if (!wallos_consume_invite_code($db, (int) $inviteCodeRow['id'], $userId, $username, $email)) {
                    throw new RuntimeException('invite_code_invalid');
                }
            }

            $db->exec('COMMIT');
            $db->close();
            header("Location: login.php?registered=true&requireValidation=$requireValidation");
            exit();
        } catch (Throwable $throwable) {
            $db->exec('ROLLBACK');
            if ($throwable->getMessage() === 'invite_code_invalid') {
                $inviteCodeInvalid = true;
            } else {
                $registrationFailed = true;
            }
        }
    }
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
    <link rel="stylesheet" href="styles/decorative-background.css?<?= $decorativeBackgroundCssVersion ?>">
    <link rel="stylesheet" href="styles/login.css?<?= $loginCssVersion ?>">
    <link rel="stylesheet" href="styles/public-entry-transition.css?<?= $publicEntryTransitionCssVersion ?>">
    <link rel="stylesheet" href="styles/themes/red.css?<?= $version ?>" id="red-theme" <?= $colorTheme != "red" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/themes/green.css?<?= $version ?>" id="green-theme" <?= $colorTheme != "green" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/themes/yellow.css?<?= $version ?>" id="yellow-theme" <?= $colorTheme != "yellow" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/themes/purple.css?<?= $version ?>" id="purple-theme" <?= $colorTheme != "purple" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/login-dark-theme.css?<?= $version ?>" id="dark-theme" <?= $theme == "light" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/font-awesome.min.css">
    <link rel="stylesheet" href="styles/brands.css">
    <link rel="stylesheet" href="styles/barlow.css">
    <script type="text/javascript">
        document.documentElement.classList.add('public-entry-js');
        window.update_theme_settings = <?= $updateThemeSettings ? 'true' : 'false' ?>;
        window.colorTheme = "<?= $colorTheme ?>";
    </script>
    <script type="text/javascript" src="scripts/decorative-background.js?<?= $decorativeBackgroundJsVersion ?>"></script>
    <script type="text/javascript" src="scripts/registration.js?<?= $registrationJsVersion ?>"></script>
    <script type="text/javascript" src="scripts/public-entry-transition.js?<?= $publicEntryTransitionJsVersion ?>"></script>
</head>

<body class="<?= $languages[$lang]['dir'] ?> public-page registration-page public-entry-pending <?= $decorativeBackgroundClass ?>">
    <?php wallos_render_public_entry_overlay('registration', $lang, $i18n); ?>
    <?php wallos_render_decorative_background('public'); ?>
    <div class="content">
        <section class="container registration-container public-auth-shell public-auth-shell-registration">
            <div class="public-auth-layout">
                <aside class="public-auth-aside">
                    <div class="public-auth-aside-inner">
                        <span class="public-auth-kicker">WALLOS // REMASTERED</span>
                        <div class="logo-image public-auth-logo" title="Wallos - Subscription Tracker">
                            <?php include "images/siteicons/svg/logo.php"; ?>
                        </div>
                        <span class="public-auth-page-code">CREATE ACCOUNT</span>
                        <h1 class="public-auth-side-title"><?= translate('create_account', $i18n) ?></h1>
                        <div class="public-page-edition-note">
                            <div class="public-page-edition-content">
                                <span class="public-page-edition-badge"><?= htmlspecialchars($publicPageBranding['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span><?= htmlspecialchars($publicPageBranding['subtitle'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </div>
                        <a class="button secondary-button public-page-feedback-button"
                            href="https://github.com/KanameMadoka520/Wallos-Remastered/issues" target="_blank" rel="noreferrer">
                            <i class="fa-solid fa-bug"></i>
                            <?= translate('issues_and_requests', $i18n) ?>
                        </a>
                    </div>
                </aside>
                <div class="public-auth-panel">
                    <div class="public-auth-panel-frame">
                        <div class="registration-page-notice">
                            <i class="fa-solid fa-circle-info"></i>
                            <span><?= translate('registration_form_notice', $i18n) ?></span>
                        </div>
                        <?php
                        if ($hasErrors) {
                            ?>
                            <ul class="error-box">
                                <?php
                                if ($passwordMismatch) {
                                    ?>
                                    <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('passwords_dont_match', $i18n) ?>
                                    </li>
                                    <?php
                                }
                                ?>
                                <?php
                                if ($usernameExists) {
                                    ?>
                                    <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('username_exists', $i18n) ?></li>
                                    <?php
                                }
                                ?>
                                <?php
                                if ($emailExists) {
                                    ?>
                                    <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('email_exists', $i18n) ?></li>
                                    <?php
                                }
                                ?>
                                <?php
                                if ($inviteCodeRequired) {
                                    ?>
                                    <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('invite_code_required', $i18n) ?></li>
                                    <?php
                                }
                                ?>
                                <?php
                                if ($inviteCodeInvalid) {
                                    ?>
                                    <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('invite_code_invalid', $i18n) ?></li>
                                    <?php
                                }
                                ?>
                                <?php
                                if ($registrationFailed) {
                                    ?>
                                    <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('registration_failed', $i18n) ?>
                                    </li>
                                    <?php
                                }
                                ?>
                            </ul>
                            <?php
                        }
                        ?>
                        <form action="registration.php" method="post" class="registration-form public-auth-form">
                            <input type="hidden" id="registration-language" name="language" value="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="registration-form-grid">
                                <div class="form-group">
                                    <label for="username"><?= translate('username', $i18n) ?>:</label>
                                    <div class="public-auth-input-wrap">
                                        <span class="public-auth-input-icon" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
                                        <input type="text" id="username" name="username" autocomplete="username" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="email"><?= translate('email', $i18n) ?>:</label>
                                    <div class="public-auth-input-wrap">
                                        <span class="public-auth-input-icon" aria-hidden="true"><i class="fa-solid fa-envelope"></i></span>
                                        <input type="email" id="email" name="email" autocomplete="email" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="firstname"><?= translate('firstname', $i18n) ?>:</label>
                                    <div class="public-auth-input-wrap">
                                        <span class="public-auth-input-icon" aria-hidden="true"><i class="fa-solid fa-signature"></i></span>
                                        <input type="text" id="firstname" name="firstname" autocomplete="given-name">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="lastname"><?= translate('lastname', $i18n) ?>:</label>
                                    <div class="public-auth-input-wrap">
                                        <span class="public-auth-input-icon" aria-hidden="true"><i class="fa-solid fa-id-badge"></i></span>
                                        <input type="text" id="lastname" name="lastname" autocomplete="family-name">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="password"><?= translate('password', $i18n) ?>:</label>
                                    <div class="public-auth-input-wrap">
                                        <span class="public-auth-input-icon" aria-hidden="true"><i class="fa-solid fa-lock"></i></span>
                                        <input type="password" id="password" name="password" autocomplete="new-password" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="confirm_password"><?= translate('confirm_password', $i18n) ?>:</label>
                                    <div class="public-auth-input-wrap">
                                        <span class="public-auth-input-icon" aria-hidden="true"><i class="fa-solid fa-key"></i></span>
                                        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="currency"><?= translate('main_currency', $i18n) ?>:</label>
                                    <div class="public-auth-input-wrap">
                                        <span class="public-auth-input-icon" aria-hidden="true"><i class="fa-solid fa-coins"></i></span>
                                        <select id="currency" name="main_currency" placeholder="<?= translate('currency', $i18n) ?>">
                                            <?php
                                            $selectedMainCurrencyCode = isset($_POST['main_currency']) && trim((string) $_POST['main_currency']) !== ''
                                                ? (string) $_POST['main_currency']
                                                : $defaultMainCurrencyCode;
                                            foreach ($currencies as $currency) {
                                                $selected = $currency['code'] === $selectedMainCurrencyCode ? 'selected' : '';
                                                ?>
                                                <option value="<?= $currency['code'] ?>" <?= $selected ?>><?= $currency['name'] ?></option>
                                                <?php
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>
                                <?php
                                if ($inviteOnlyRegistrationEnabled) {
                                    ?>
                                    <div class="form-group">
                                        <label for="invite_code"><?= translate('invite_code', $i18n) ?>:</label>
                                        <div class="public-auth-input-wrap">
                                            <span class="public-auth-input-icon" aria-hidden="true"><i class="fa-solid fa-ticket"></i></span>
                                            <input type="text" id="invite_code" name="invite_code" autocomplete="off" required>
                                        </div>
                                    </div>
                                    <?php
                                }
                                ?>
                            </div>
                            <?php
                            if ($inviteOnlyRegistrationEnabled) {
                                ?>
                                <div class="settings-notes registration-settings-notes">
                                    <p>
                                        <i class="fa-solid fa-circle-info"></i>
                                        <?= translate('invite_only_registration_notice', $i18n) ?>
                                    </p>
                                </div>
                                <?php
                            }
                            ?>
                            <div class="form-group form-group-submit">
                                <input type="submit" value="<?= translate('register', $i18n) ?>">
                            </div>
                            <div class="public-auth-inline-actions">
                                <div class="public-page-language-switcher public-page-language-switcher-inline">
                                    <span class="public-page-language-icon" aria-hidden="true">
                                        <i class="fa-solid fa-earth-asia"></i>
                                    </span>
                                    <label for="public-page-language"><?= translate('language', $i18n) ?>:</label>
                                    <select id="public-page-language">
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
                                <?php if ($userCount > 0) { ?>
                                    <a class="button secondary-button public-auth-inline-action-button" href="login.php"><?= translate('login', $i18n) ?></a>
                                <?php } ?>
                            </div>
                        </form>
                        <?php
                        if ($userCount == 0) {
                            ?>
                            <div class="separator">
                                <input type="button" class="secondary-button" value="<?= translate('restore_database', $i18n) ?>"
                                    id="restoreDB" onClick="openRestoreDBFileSelect()" />
                                <input type="file" name="restoreDBFile" id="restoreDBFile" style="display: none;" onChange="restoreDB()"
                                    accept=".zip">
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                </div>
            </div>
        </section>
    </div>
    <?php
    require_once 'includes/public_footer.php';
    ?>
