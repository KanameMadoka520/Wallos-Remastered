<?php

require_once __DIR__ . '/../includes/logo_theme_variant.php';

function wallos_logo_theme_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$temporaryDirectory = sys_get_temp_dir() . '/wallos-logo-theme-' . bin2hex(random_bytes(5));

try {
    if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
        throw new RuntimeException('Unable to create logo test directory.');
    }

    $source = imagecreatetruecolor(20, 10);
    imagealphablending($source, false);
    imagesavealpha($source, true);
    $transparent = imagecolorallocatealpha($source, 0, 0, 0, 127);
    imagefill($source, 0, 0, $transparent);
    $black = imagecolorallocatealpha($source, 8, 8, 8, 0);
    imagefilledrectangle($source, 2, 2, 17, 7, $black);
    $sourcePath = $temporaryDirectory . '/dark-logo.png';
    wallos_logo_theme_assert(imagepng($source, $sourcePath), 'Unable to write source logo fixture.');
    imagedestroy($source);

    $result = wallos_create_logo_theme_variant($sourcePath, 'dark-logo.png');
    wallos_logo_theme_assert($result['text_color'] === 'dark', 'Dark logo text was not classified.');
    wallos_logo_theme_assert($result['variant'] === 'dark-logo-variant.png', 'Variant filename is unstable.');
    $variantPath = $temporaryDirectory . '/' . $result['variant'];
    wallos_logo_theme_assert(is_file($variantPath) && filesize($variantPath) > 0, 'The themed variant was not written.');

    $variant = imagecreatefrompng($variantPath);
    $variantPixel = imagecolorsforindex($variant, imagecolorat($variant, 5, 5));
    imagedestroy($variant);
    wallos_logo_theme_assert(
        $variantPixel['red'] > 240 && $variantPixel['green'] > 240 && $variantPixel['blue'] > 240,
        'The dark grayscale ink was not inverted for the opposite theme.'
    );

    $addSource = file_get_contents(__DIR__ . '/../endpoints/subscription/add.php');
    $listSource = file_get_contents(__DIR__ . '/../includes/list_subscriptions.php');
    $cleanupSource = file_get_contents(__DIR__ . '/../endpoints/admin/deleteunusedlogos.php');
    $migrationSource = file_get_contents(__DIR__ . '/../migrations/000080.php');
    wallos_logo_theme_assert(
        strpos($addSource, 'wallos_create_logo_theme_variant') !== false
            && strpos($addSource, 'logo_text_color = :logoTextColor') !== false
            && strpos($addSource, 'logo_variant = :logoVariant') !== false
            && strpos($addSource, "translate('error_saving_logo'") !== false,
        'Subscription writes do not preserve themed metadata or report logo-save failures.'
    );
    wallos_logo_theme_assert(
        strpos($listSource, 'logo-theme-original') !== false
            && strpos($listSource, 'logo-theme-variant') !== false,
        'Subscription cards do not render both theme variants.'
    );
    wallos_logo_theme_assert(
        strpos($cleanupSource, 'SELECT logo, logo_variant FROM subscriptions') !== false,
        'Unused-logo cleanup can still delete a referenced themed variant.'
    );
    wallos_logo_theme_assert(
        strpos($migrationSource, 'ADD COLUMN logo_text_color') !== false
            && strpos($migrationSource, 'ADD COLUMN logo_variant') !== false,
        'The release migration does not add both themed-logo metadata columns.'
    );

    echo "Themed logo compatibility test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if (is_dir($temporaryDirectory)) {
        foreach (scandir($temporaryDirectory) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                @unlink($temporaryDirectory . '/' . $file);
            }
        }
        @rmdir($temporaryDirectory);
    }
}

?>
