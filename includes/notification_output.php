<?php

/** Cron output may be shown in an admin browser; remote responses remain data. */
function wallos_notification_output_text($value)
{
    return PHP_SAPI === 'cli'
        ? (string) $value
        : htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
