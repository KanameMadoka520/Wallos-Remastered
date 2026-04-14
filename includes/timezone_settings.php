<?php

define('WALLOS_DEFAULT_USER_TIMEZONE', 'Asia/Shanghai');
define('WALLOS_DEFAULT_BACKUP_TIMEZONE', 'Asia/Shanghai');

function wallos_get_default_user_timezone()
{
    return WALLOS_DEFAULT_USER_TIMEZONE;
}

function wallos_get_default_backup_timezone()
{
    return WALLOS_DEFAULT_BACKUP_TIMEZONE;
}

function wallos_is_supported_timezone($timezone)
{
    return in_array((string) $timezone, timezone_identifiers_list(), true);
}

function wallos_normalize_timezone_identifier($timezone, $fallback = null)
{
    $fallbackTimezone = $fallback ?: wallos_get_default_user_timezone();
    $candidateTimezone = trim((string) $timezone);

    return wallos_is_supported_timezone($candidateTimezone) ? $candidateTimezone : $fallbackTimezone;
}

function wallos_apply_php_timezone($timezone)
{
    date_default_timezone_set(
        wallos_normalize_timezone_identifier($timezone, wallos_get_default_user_timezone())
    );
}

function wallos_fetch_user_timezone($db, $userId)
{
    if (!isset($db) || (int) $userId < 1) {
        return wallos_get_default_user_timezone();
    }

    $stmt = $db->prepare('SELECT user_timezone FROM settings WHERE user_id = :userId');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    return wallos_normalize_timezone_identifier(
        $row['user_timezone'] ?? '',
        wallos_get_default_user_timezone()
    );
}

function wallos_fetch_backup_timezone($db)
{
    if (!isset($db)) {
        return wallos_get_default_backup_timezone();
    }

    $row = $db->querySingle('SELECT backup_timezone FROM admin WHERE id = 1', true);
    return wallos_normalize_timezone_identifier(
        $row['backup_timezone'] ?? '',
        wallos_get_default_backup_timezone()
    );
}

function wallos_get_timezone_offset_label($timezone, DateTimeImmutable $referenceDate = null)
{
    $reference = $referenceDate ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $timezoneObject = new DateTimeZone(
        wallos_normalize_timezone_identifier($timezone, wallos_get_default_user_timezone())
    );
    $offsetSeconds = $timezoneObject->getOffset($reference);
    $sign = $offsetSeconds >= 0 ? '+' : '-';
    $absoluteSeconds = abs($offsetSeconds);
    $hours = floor($absoluteSeconds / 3600);
    $minutes = floor(($absoluteSeconds % 3600) / 60);

    return sprintf('UTC%s%02d:%02d', $sign, $hours, $minutes);
}

function wallos_build_timezone_label($timezone, DateTimeImmutable $referenceDate = null)
{
    $normalizedTimezone = wallos_normalize_timezone_identifier($timezone, wallos_get_default_user_timezone());
    return sprintf('(%s) %s', wallos_get_timezone_offset_label($normalizedTimezone, $referenceDate), $normalizedTimezone);
}

function wallos_get_timezone_options($selectedTimezone = null)
{
    $selected = wallos_normalize_timezone_identifier($selectedTimezone, wallos_get_default_user_timezone());
    $reference = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $allTimezones = timezone_identifiers_list();
    $preferredTimezones = [
        wallos_get_default_user_timezone(),
        'UTC',
        'Asia/Tokyo',
        'America/Los_Angeles',
        'America/New_York',
        'Europe/London',
        'Europe/Berlin',
    ];

    $orderedTimezones = [];
    foreach (array_merge($preferredTimezones, $allTimezones) as $timezone) {
        $normalizedTimezone = wallos_normalize_timezone_identifier($timezone, wallos_get_default_user_timezone());
        $orderedTimezones[$normalizedTimezone] = [
            'value' => $normalizedTimezone,
            'label' => wallos_build_timezone_label($normalizedTimezone, $reference),
            'selected' => $normalizedTimezone === $selected,
        ];
    }

    return array_values($orderedTimezones);
}

function wallos_format_datetime_for_timezone($value, $timezone, $format = 'Y-m-d H:i:s')
{
    $rawValue = trim((string) $value);
    if ($rawValue === '') {
        return '';
    }

    $normalizedTimezone = wallos_normalize_timezone_identifier($timezone, wallos_get_default_user_timezone());
    $timezoneObject = new DateTimeZone($normalizedTimezone);

    try {
        $dateTime = new DateTimeImmutable($rawValue);
    } catch (Throwable $throwable) {
        return $rawValue;
    }

    return $dateTime->setTimezone($timezoneObject)->format($format);
}
