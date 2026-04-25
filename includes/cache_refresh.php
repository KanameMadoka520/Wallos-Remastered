<?php

function wallos_cache_refresh_marker_path($basePath = null)
{
    $root = $basePath !== null ? rtrim((string) $basePath, '/\\') : dirname(__DIR__);
    return $root . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'client-cache-refresh.json';
}

function wallos_read_cache_refresh_marker($basePath = null)
{
    $path = wallos_cache_refresh_marker_path($basePath);
    if (!is_file($path)) {
        return [
            'token' => '',
            'requested_at' => '',
        ];
    }

    $raw = file_get_contents($path);
    $payload = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        return [
            'token' => '',
            'requested_at' => '',
        ];
    }

    return [
        'token' => preg_replace('/[^a-f0-9]/i', '', (string) ($payload['token'] ?? '')),
        'requested_at' => trim((string) ($payload['requested_at'] ?? '')),
    ];
}

function wallos_write_cache_refresh_marker($basePath = null)
{
    $path = wallos_cache_refresh_marker_path($basePath);
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    $payload = [
        'token' => bin2hex(random_bytes(12)),
        'requested_at' => gmdate('c'),
    ];

    file_put_contents(
        $path,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        LOCK_EX
    );

    return $payload;
}

