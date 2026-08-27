<?php

const WALLOS_LOGO_THEME_BLACK_MAX = 60;
const WALLOS_LOGO_THEME_WHITE_MIN = 200;
const WALLOS_LOGO_THEME_MAX_SPREAD = 20;
const WALLOS_LOGO_THEME_SIGNIFICANT_RATIO = 0.05;
const WALLOS_LOGO_THEME_DOMINANCE_RATIO = 0.30;

function wallos_classify_logo_text_color($image)
{
    $width = imagesx($image);
    $height = imagesy($image);
    $darkPixels = 0;
    $lightPixels = 0;
    $opaquePixels = 0;

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $color = imagecolorat($image, $x, $y);
            $alpha = ($color >> 24) & 0x7f;
            if ($alpha >= 125) {
                continue;
            }
            $opaquePixels++;
            $red = ($color >> 16) & 0xff;
            $green = ($color >> 8) & 0xff;
            $blue = $color & 0xff;
            if ((max($red, $green, $blue) - min($red, $green, $blue)) > WALLOS_LOGO_THEME_MAX_SPREAD) {
                continue;
            }
            $lightness = ($red + $green + $blue) / 3;
            if ($lightness <= WALLOS_LOGO_THEME_BLACK_MAX) {
                $darkPixels++;
            } elseif ($lightness >= WALLOS_LOGO_THEME_WHITE_MIN) {
                $lightPixels++;
            }
        }
    }

    if ($opaquePixels === 0) {
        return null;
    }
    $hasDark = ($darkPixels / $opaquePixels) >= WALLOS_LOGO_THEME_SIGNIFICANT_RATIO;
    $hasLight = ($lightPixels / $opaquePixels) >= WALLOS_LOGO_THEME_SIGNIFICANT_RATIO;
    if (!$hasDark && !$hasLight) {
        return null;
    }
    if ($hasDark && $hasLight) {
        $maximum = max($darkPixels, $lightPixels);
        if ($maximum > 0 && min($darkPixels, $lightPixels) / $maximum > WALLOS_LOGO_THEME_DOMINANCE_RATIO) {
            return null;
        }
    }

    return $darkPixels > $lightPixels ? 'dark' : 'light';
}

function wallos_generate_themed_logo_variant($image)
{
    $width = imagesx($image);
    $height = imagesy($image);
    $variant = imagecreatetruecolor($width, $height);
    imagealphablending($variant, false);
    imagesavealpha($variant, true);

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $color = imagecolorat($image, $x, $y);
            $alpha = ($color >> 24) & 0x7f;
            $red = ($color >> 16) & 0xff;
            $green = ($color >> 8) & 0xff;
            $blue = $color & 0xff;
            if ($alpha < 125
                && (max($red, $green, $blue) - min($red, $green, $blue)) <= WALLOS_LOGO_THEME_MAX_SPREAD) {
                $red = 255 - $red;
                $green = 255 - $green;
                $blue = 255 - $blue;
            }
            imagesetpixel($variant, $x, $y, imagecolorallocatealpha($variant, $red, $green, $blue, $alpha));
        }
    }

    return $variant;
}

function wallos_create_logo_theme_variant($logoPath, $logoFilename)
{
    $extension = strtolower(pathinfo((string) $logoFilename, PATHINFO_EXTENSION));
    if ($extension === 'png') {
        $source = @imagecreatefrompng($logoPath);
    } elseif ($extension === 'webp') {
        $source = @imagecreatefromwebp($logoPath);
    } else {
        return ['text_color' => null, 'variant' => null];
    }
    if ($source === false) {
        return ['text_color' => null, 'variant' => null];
    }

    imagealphablending($source, false);
    imagesavealpha($source, true);
    $textColor = wallos_classify_logo_text_color($source);
    if ($textColor === null) {
        imagedestroy($source);
        return ['text_color' => null, 'variant' => null];
    }

    $variant = wallos_generate_themed_logo_variant($source);
    $variantFilename = pathinfo((string) $logoFilename, PATHINFO_FILENAME) . '-variant.png';
    $variantPath = dirname($logoPath) . DIRECTORY_SEPARATOR . $variantFilename;
    $written = imagepng($variant, $variantPath);
    imagedestroy($variant);
    imagedestroy($source);
    if (!$written) {
        @unlink($variantPath);
        return ['text_color' => null, 'variant' => null];
    }

    return ['text_color' => $textColor, 'variant' => $variantFilename];
}

?>
