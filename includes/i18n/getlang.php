<?php

$lang = "zh_cn";
$selectedLanguage = '';

if (isset($_GET['set_language'])) {
    $candidateLanguage = strtolower(str_replace('-', '_', trim((string) $_GET['set_language'])));
    if (array_key_exists($candidateLanguage, $languages)) {
        $selectedLanguage = $candidateLanguage;
        $_COOKIE['language'] = $selectedLanguage;
        setcookie('language', $selectedLanguage, [
            'expires' => time() + (365 * 24 * 60 * 60),
            'path' => '/',
            'samesite' => 'Lax',
        ]);
    }
}

if ($selectedLanguage === '' && isset($_COOKIE['language'])) {
    $selectedLanguage = $_COOKIE['language'];
}

if ($selectedLanguage !== '') {
    if (array_key_exists($selectedLanguage, $languages)) {
        $lang = $selectedLanguage;
    }
}

function translate($text, $translations)
{
    if (array_key_exists($text, $translations)) {
        return $translations[$text];
    } else {
        require 'en.php';
        if (array_key_exists($text, $i18n)) {
            return $i18n[$text];
        } else {
            return "[i18n String Missing]";
        }
    }
}

?>
