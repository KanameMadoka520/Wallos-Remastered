<?php

/**
 * Escape a value for an iCalendar property value (RFC 5545).
 *
 * User-controlled text must not be able to introduce a new property or event.
 */
function icalEscape($value)
{
    $value = (string) $value;
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);
    $value = str_replace(',', '\\,', $value);
    $value = str_replace(';', '\\;', $value);
    return $value;
}

// RFC 5545 limits physical lines to 75 octets, including the continuation space.
function icalFold($line)
{
    $characters = preg_split('//u', (string) $line, -1, PREG_SPLIT_NO_EMPTY);
    if ($characters === false) $characters = str_split((string) $line);
    $result = '';
    $length = 0;
    foreach ($characters as $character) {
        if ($length + strlen($character) > 75) {
            $result .= "\r\n ";
            $length = 1;
        }
        $result .= $character;
        $length += strlen($character);
    }
    return $result;
}

function icalFormatContent($content)
{
    $lines = preg_split('/\r\n|\r|\n/', rtrim((string) $content, "\r\n"));
    return implode("\r\n", array_map('icalFold', $lines)) . "\r\n";
}
