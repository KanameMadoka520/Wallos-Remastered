<?php

require_once __DIR__ . '/../includes/i18n/languages.php';

function wallos_i18n_contract_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_i18n_placeholder_signature($value)
{
    $value = str_replace('%%', '', (string) $value);
    preg_match_all(
        "/%(?:([0-9]+)\\$)?[-+0#']*(?:[0-9]+|\\*)?(?:\\.(?:[0-9]+|\\*))?([bcdeEfFgGosuxX])/",
        $value,
        $matches,
        PREG_SET_ORDER
    );

    $nextImplicitPosition = 1;
    $signature = array();
    foreach ($matches as $match) {
        $position = isset($match[1]) && $match[1] !== ''
            ? (int) $match[1]
            : $nextImplicitPosition++;
        $signature[] = $position . ':' . strtolower((string) $match[2]);
    }
    sort($signature, SORT_STRING);

    return $signature;
}

function wallos_i18n_source_key_counts($source, $kind)
{
    $pattern = $kind === 'php'
        ? '/^[\t ]*["\']([^"\']+)["\'][\t ]*=>/m'
        : '/^[\t ]*(?:["\']([^"\']+)["\']|([A-Za-z_$][A-Za-z0-9_$]*))[\t ]*:/m';
    preg_match_all($pattern, (string) $source, $matches, PREG_SET_ORDER);

    $counts = array();
    foreach ($matches as $match) {
        $key = $kind === 'php'
            ? (string) $match[1]
            : (string) ($match[1] !== '' ? $match[1] : $match[2]);
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    return $counts;
}

function wallos_i18n_parse_js_catalog($source, $path)
{
    $pattern = '/^[\t ]*(?:"([^"]+)"|\'([^\']+)\'|([A-Za-z_$][A-Za-z0-9_$]*))[\t ]*:[\t ]*(?:"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\')[\t ]*,?[\t ]*\r?$/m';
    preg_match_all($pattern, (string) $source, $matches, PREG_SET_ORDER);

    $catalog = array();
    foreach ($matches as $match) {
        $key = (string) ($match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : $match[3]));
        $value = (string) ($match[4] !== '' ? $match[4] : $match[5]);
        wallos_i18n_contract_assert(!array_key_exists($key, $catalog), $path . ' defines duplicate JS key `' . $key . '`.');
        $catalog[$key] = $value;
    }

    wallos_i18n_contract_assert($catalog !== array(), $path . ' did not contain a readable JS translation catalog.');

    return $catalog;
}

function wallos_i18n_assert_same_keys(array $expected, array $actual, $label)
{
    $missing = array_keys(array_diff_key($expected, $actual));
    $extra = array_keys(array_diff_key($actual, $expected));
    sort($missing, SORT_STRING);
    sort($extra, SORT_STRING);

    wallos_i18n_contract_assert(
        $missing === array(),
        $label . ' is missing keys: ' . implode(', ', $missing)
    );
    wallos_i18n_contract_assert(
        $extra === array(),
        $label . ' has keys outside the English baseline: ' . implode(', ', $extra)
    );
}

function wallos_i18n_assert_placeholders(array $english, array $localized, $label)
{
    foreach ($localized as $key => $value) {
        if (!array_key_exists($key, $english)) {
            continue;
        }

        $expected = wallos_i18n_placeholder_signature($english[$key]);
        $actual = wallos_i18n_placeholder_signature($value);
        wallos_i18n_contract_assert(
            $expected === $actual,
            $label . ' has incompatible placeholders for `' . $key . '`: expected ['
                . implode(', ', $expected) . '], got [' . implode(', ', $actual) . ']'
        );
    }
}

function wallos_i18n_application_files($root)
{
    $skipDirectories = array(
        '.git', '.planning', '.tmp', 'backups', 'db', 'docs', 'images',
        'node_modules', 'screenshots', 'tests', 'webfonts',
    );
    $files = array();
    $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator(
        $directory,
        static function ($current) use ($skipDirectories) {
            return !$current->isDir() || !in_array($current->getFilename(), $skipDirectories, true);
        }
    );
    $iterator = new RecursiveIteratorIterator($filter);
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $extension = strtolower((string) $file->getExtension());
        if (!in_array($extension, array('php', 'js', 'mjs', 'html'), true)) {
            continue;
        }
        $path = $file->getPathname();
        if (strpos($path, DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'i18n' . DIRECTORY_SEPARATOR) !== false
            || strpos($path, DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'i18n' . DIRECTORY_SEPARATOR) !== false
            || strpos($path, '.min.js') !== false) {
            continue;
        }
        $files[] = $path;
    }

    return $files;
}

function wallos_i18n_literal_calls($source, $pattern)
{
    preg_match_all($pattern, (string) $source, $matches, PREG_SET_ORDER);
    $keys = array();
    foreach ($matches as $match) {
        $keys[] = (string) $match[2];
    }

    return $keys;
}

try {
    $root = dirname(__DIR__);
    $phpDirectory = $root . '/includes/i18n';
    $jsDirectory = $root . '/scripts/i18n';
    $languageCodes = array_keys($languages);

    wallos_i18n_contract_assert(isset($languages['ar']), 'Arabic must be registered.');
    wallos_i18n_contract_assert(($languages['ar']['dir'] ?? '') === 'rtl', 'Arabic must use RTL layout.');
    wallos_i18n_contract_assert(isset($languages['hu']), 'Hungarian must be registered.');
    wallos_i18n_contract_assert(isset($languages['jp']), 'The existing Japanese `jp` language code must remain compatible.');
    wallos_i18n_contract_assert(!isset($languages['ja']), 'Japanese must not appear twice in the language selector.');

    unset($i18n);
    require $phpDirectory . '/en.php';
    $englishPhp = $i18n;
    wallos_i18n_contract_assert(count($englishPhp) > 0, 'The English PHP baseline is empty.');

    $englishPhpSource = file_get_contents($phpDirectory . '/en.php');
    $englishPhpCounts = wallos_i18n_source_key_counts($englishPhpSource, 'php');
    foreach ($englishPhpCounts as $key => $count) {
        wallos_i18n_contract_assert($count === 1, 'English PHP defines duplicate key `' . $key . '`.');
    }

    foreach ($languageCodes as $languageCode) {
        $phpPath = $phpDirectory . '/' . $languageCode . '.php';
        $jsPath = $jsDirectory . '/' . $languageCode . '.js';
        wallos_i18n_contract_assert(is_file($phpPath), 'Missing PHP language file: ' . basename($phpPath));
        wallos_i18n_contract_assert(is_file($jsPath), 'Missing JS language file: ' . basename($jsPath));

        unset($i18n);
        require $phpPath;
        $effectivePhp = $i18n;
        wallos_i18n_assert_same_keys($englishPhp, $effectivePhp, $languageCode . '.php');
        wallos_i18n_assert_placeholders($englishPhp, $effectivePhp, $languageCode . '.php');
        foreach ($effectivePhp as $key => $value) {
            wallos_i18n_contract_assert(is_string($value) && trim($value) !== '', $languageCode . '.php has an empty value for `' . $key . '`.');
            wallos_i18n_contract_assert(
                !preg_match('/^\[(?:i18n String Missing|Translation Missing)\]$/i', trim($value)),
                $languageCode . '.php contains a missing marker for `' . $key . '`.'
            );
        }

        $phpSource = file_get_contents($phpPath);
        $phpCounts = wallos_i18n_source_key_counts($phpSource, 'php');
        foreach ($phpCounts as $key => $count) {
            wallos_i18n_contract_assert($count === 1, $languageCode . '.php defines duplicate key `' . $key . '`.');
            wallos_i18n_contract_assert(array_key_exists($key, $englishPhp), $languageCode . '.php has unknown key `' . $key . '`.');
        }
        if ($languageCode !== 'en') {
            wallos_i18n_contract_assert(
                strpos($phpSource, "require __DIR__ . '/en.php';") !== false
                    && strpos($phpSource, 'array_replace($i18n, [') !== false,
                $languageCode . '.php must merge its translations over the English baseline.'
            );
        }
        if (in_array($languageCode, array('zh_cn', 'zh_tw'), true)) {
            wallos_i18n_contract_assert(
                count($phpCounts) === count($englishPhpCounts),
                $languageCode . '.php must provide a complete native Chinese catalog.'
            );
        }
    }

    $englishJsSource = file_get_contents($jsDirectory . '/en.js');
    $englishJs = wallos_i18n_parse_js_catalog($englishJsSource, 'en.js');
    wallos_i18n_contract_assert(
        strpos($englishJsSource, 'globalThis.wallosI18nEnglish = {') !== false,
        'en.js must expose the canonical browser fallback catalog.'
    );

    foreach ($languageCodes as $languageCode) {
        $jsPath = $jsDirectory . '/' . $languageCode . '.js';
        $jsSource = file_get_contents($jsPath);
        $localizedJs = wallos_i18n_parse_js_catalog($jsSource, $languageCode . '.js');
        $jsCounts = wallos_i18n_source_key_counts($jsSource, 'js');
        foreach ($jsCounts as $key => $count) {
            wallos_i18n_contract_assert($count === 1, $languageCode . '.js defines duplicate key `' . $key . '`.');
            wallos_i18n_contract_assert(array_key_exists($key, $englishJs), $languageCode . '.js has unknown key `' . $key . '`.');
        }
        wallos_i18n_assert_placeholders($englishJs, $localizedJs, $languageCode . '.js');
        if ($languageCode !== 'en') {
            wallos_i18n_contract_assert(
                strpos($jsSource, 'globalThis.wallosI18nEnglish || {}') !== false,
                $languageCode . '.js must overlay the English browser fallback catalog.'
            );
        }
        if (in_array($languageCode, array('zh_cn', 'zh_tw'), true)) {
            wallos_i18n_assert_same_keys($englishJs, $localizedJs, $languageCode . '.js native catalog');
        }
    }

    $sharedEnglishKeys = array_intersect_key($englishPhp, $englishJs);
    wallos_i18n_assert_placeholders($sharedEnglishKeys, array_intersect_key($englishJs, $englishPhp), 'English PHP/JS catalogs');

    $missingPhpCalls = array();
    $missingJsCalls = array();
    $phpLiteralPattern = '/\btranslate\(\s*(["\'])([^"\'\r\n]+)\1\s*,/';
    $phpFallbackPattern = '/\bwallos_translate_with_fallback\(\s*(["\'])([^"\'\r\n]+)\1/';
    $phpHelperPattern = '/\bwallos_(?:database|maintenance)_translate\(\s*[^,]+,\s*(["\'])([^"\'\r\n]+)\1\s*,/';
    $jsLiteralPattern = '/\btranslate\(\s*(["\'])([^"\'\r\n]+)\1\s*\)/';
    $jsHelperPattern = '/\b[A-Za-z_$][A-Za-z0-9_$]*(?:Translate|translate)[A-Za-z0-9_$]*\(\s*(["\'])([^"\'\r\n]+)\1\s*(?:,|\))/';
    foreach (wallos_i18n_application_files($root) as $path) {
        $source = file_get_contents($path);
        foreach (array_merge(
            wallos_i18n_literal_calls($source, $phpLiteralPattern),
            wallos_i18n_literal_calls($source, $phpFallbackPattern),
            wallos_i18n_literal_calls($source, $phpHelperPattern)
        ) as $key) {
            if (!array_key_exists($key, $englishPhp)) {
                $missingPhpCalls[$key][] = str_replace($root . '/', '', $path);
            }
        }
        foreach (wallos_i18n_literal_calls($source, $jsLiteralPattern) as $key) {
            if (!array_key_exists($key, $englishJs)) {
                $missingJsCalls[$key][] = str_replace($root . '/', '', $path);
            }
        }
        if (in_array(strtolower((string) pathinfo($path, PATHINFO_EXTENSION)), array('js', 'mjs'), true)) {
            foreach (wallos_i18n_literal_calls($source, $jsHelperPattern) as $key) {
                if (!array_key_exists($key, $englishJs)) {
                    $missingJsCalls[$key][] = str_replace($root . '/', '', $path);
                }
            }
        }
    }
    wallos_i18n_contract_assert(
        $missingPhpCalls === array(),
        'PHP source calls missing English keys: ' . json_encode($missingPhpCalls, JSON_UNESCAPED_SLASHES)
    );
    wallos_i18n_contract_assert(
        $missingJsCalls === array(),
        'JS source calls missing English keys: ' . json_encode($missingJsCalls, JSON_UNESCAPED_SLASHES)
    );

    // These keys are selected through bounded maps or ternaries, so a literal-only
    // source scanner cannot infer them without producing false positives for every
    // genuinely dynamic translation helper.
    $knownDynamicPhpKeys = array(
        'daily', 'weekly', 'monthly', 'yearly', 'one-time',
        'subscription_added_successfuly', 'subscription_updated_successfuly',
        'backup_cleanup_success', 'backup_cleanup_no_old_backups',
        'localized_defaults_reset_categories_success',
        'localized_defaults_reset_payment_methods_success',
        'localized_defaults_reset_currencies_success',
        'success', 'error', 'enabled', 'disabled',
        'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat',
        'month-01', 'month-02', 'month-03', 'month-04', 'month-05', 'month-06',
        'month-07', 'month-08', 'month-09', 'month-10', 'month-11', 'month-12',
        'csrf_token_footer_status_valid', 'csrf_token_footer_status_expired',
        'maintenance_recommendation_status_ok',
        'maintenance_recommendation_status_watch',
        'maintenance_recommendation_status_action',
        'log_growth_risk_normal', 'log_growth_risk_watch', 'log_growth_risk_high',
    );
    foreach ($knownDynamicPhpKeys as $key) {
        wallos_i18n_contract_assert(array_key_exists($key, $englishPhp), 'Dynamic PHP i18n key is missing: ' . $key);
    }
    foreach (array('subscription_reorder_handle_title', 'subscription_reorder_unavailable') as $key) {
        wallos_i18n_contract_assert(array_key_exists($key, $englishJs), 'Dynamic JS i18n key is missing: ' . $key);
    }

    foreach (array('login.php', 'registration.php', 'includes/header.php') as $relativePath) {
        $source = file_get_contents($root . '/' . $relativePath);
        $englishPosition = strrpos($source, 'scripts/i18n/en.js');
        $localePosition = strrpos($source, 'scripts/i18n/<?= $lang ?>.js');
        $translatorPosition = strrpos($source, 'scripts/i18n/getlang.js');
        wallos_i18n_contract_assert(
            $englishPosition !== false && $localePosition !== false && $translatorPosition !== false
                && $englishPosition < $localePosition && $localePosition < $translatorPosition,
            $relativePath . ' must load English, locale overlay, then getlang.js in that order.'
        );
    }

    $serviceWorker = file_get_contents($root . '/service-worker.js');
    wallos_i18n_contract_assert(
        strpos($serviceWorker, "'scripts/'") !== false
            && strpos($serviceWorker, 'STATIC_PATH_PREFIXES') !== false
            && strpos($serviceWorker, 'hasExactAssetFingerprint(url)') !== false,
        'service-worker.js must runtime-cache requested versioned language scripts through the packaged scripts prefix.'
    );

    echo 'i18n contract checks passed for ' . count($languageCodes)
        . ' languages, ' . count($englishPhp) . ' PHP keys, and '
        . count($englishJs) . ' JS keys.' . PHP_EOL;
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
