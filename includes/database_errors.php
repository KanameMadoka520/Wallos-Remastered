<?php

function wallos_database_message_is_busy($message)
{
    $message = strtolower(trim((string) $message));
    if ($message === '') {
        return false;
    }

    $busyNeedles = [
        'database is locked',
        'database table is locked',
        'database schema is locked',
        'database is busy',
        'sqlite_busy',
        'sqlite_locked',
        'sqlstate[hy000]: general error: 5',
        'unable to execute statement: database is locked',
    ];

    foreach ($busyNeedles as $needle) {
        if (strpos($message, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function wallos_database_get_last_error_message($db)
{
    if (!($db instanceof SQLite3)) {
        return '';
    }

    try {
        $message = trim((string) @$db->lastErrorMsg());
    } catch (Throwable $throwable) {
        return '';
    }
    if ($message === 'not an error') {
        return '';
    }

    return $message;
}

function wallos_database_throwable_is_busy($throwable, $db = null)
{
    if ($throwable instanceof Throwable) {
        if (wallos_database_message_is_busy($throwable->getMessage())) {
            return true;
        }

        $previous = $throwable->getPrevious();
        while ($previous instanceof Throwable) {
            if (wallos_database_message_is_busy($previous->getMessage())) {
                return true;
            }
            $previous = $previous->getPrevious();
        }
    } elseif (wallos_database_message_is_busy($throwable)) {
        return true;
    }

    return wallos_database_message_is_busy(wallos_database_get_last_error_message($db));
}

function wallos_database_translate($i18n, $key, $fallback)
{
    if (function_exists('translate') && is_array($i18n)) {
        $translated = translate($key, $i18n);
        if (is_string($translated) && $translated !== '' && $translated !== '[i18n String Missing]') {
            return $translated;
        }
    }

    return $fallback;
}

function wallos_database_build_busy_payload($i18n, array $extra = [])
{
    $retryAfterSeconds = isset($extra['retry_after_seconds'])
        ? max(1, (int) $extra['retry_after_seconds'])
        : 5;

    return array_merge([
        'success' => false,
        'code' => 'database_busy',
        'error' => 'database_busy',
        'database_busy' => true,
        'retry_after_seconds' => $retryAfterSeconds,
        'message' => wallos_database_translate(
            $i18n,
            'database_busy_retry',
            'The database is busy processing another write. Please wait a few seconds and try again.'
        ),
    ], $extra);
}

function wallos_database_emit_busy_response($i18n, array $extra = [])
{
    $payload = wallos_database_build_busy_payload($i18n, $extra);
    http_response_code(503);
    header('Content-Type: application/json; charset=UTF-8');
    header('Retry-After: ' . (int) ($payload['retry_after_seconds'] ?? 5));
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wallos_database_emit_busy_response_if_needed($i18n, $throwable = null, $db = null, array $extra = [])
{
    if (!wallos_database_throwable_is_busy($throwable, $db)) {
        return false;
    }

    wallos_database_emit_busy_response($i18n, $extra);
    return true;
}

function wallos_database_register_endpoint_exception_handler($i18n)
{
    static $registered = false;
    if ($registered) {
        return;
    }

    $registered = true;
    $previousHandler = set_exception_handler(function ($throwable) use ($i18n, &$previousHandler) {
        global $db;

        if (wallos_database_throwable_is_busy($throwable, $db ?? null)) {
            wallos_database_emit_busy_response($i18n);
        }

        if (is_callable($previousHandler)) {
            call_user_func($previousHandler, $throwable);
            return;
        }

        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'code' => 'endpoint_error',
            'error' => 'endpoint_error',
            'message' => wallos_database_translate($i18n, 'error', 'Error'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    });
}
