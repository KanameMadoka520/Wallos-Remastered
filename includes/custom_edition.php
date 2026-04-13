<?php

function wallos_custom_edition_looks_corrupted($value)
{
    $trimmedValue = trim((string) $value);

    if ($trimmedValue === '') {
        return true;
    }

    if (strpos($trimmedValue, '�') !== false) {
        return true;
    }

    if (strpos($trimmedValue, '?') === false) {
        return false;
    }

    return !preg_match('/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}]/u', $trimmedValue);
}

function wallos_normalize_custom_edition_value($value, $fallback)
{
    $trimmedValue = trim((string) $value);

    if (wallos_custom_edition_looks_corrupted($trimmedValue)) {
        return $fallback;
    }

    return $trimmedValue;
}
