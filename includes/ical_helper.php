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

