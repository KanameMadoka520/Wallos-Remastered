<?php

require_once __DIR__ . '/security_rate_limits.php';

define('WALLOS_RATE_LIMIT_TEST_PRESET_NAME', '测试用极低速率限制预设');
define('WALLOS_RATE_LIMIT_NORMAL_PRESET_NAME', '常规速率限制预设');

define('WALLOS_RATE_LIMIT_RECOMMENDED_PRESET_NAME', '推荐常规速率限制预设');

function wallos_get_rate_limit_preset_field_names()
{
    return [
        'advanced_rate_limit_enabled',
        'login_rate_limit_max_attempts',
        'login_rate_limit_block_minutes',
        'backend_request_limit_per_minute',
        'backend_request_limit_per_hour',
        'image_upload_limit_per_minute',
        'image_upload_limit_per_hour',
        'image_upload_mb_per_minute',
        'image_upload_mb_per_hour',
        'image_download_limit_per_minute',
        'image_download_limit_per_hour',
        'image_download_mb_per_minute',
        'image_download_mb_per_hour',
    ];
}

function wallos_normalize_rate_limit_preset_config(array $input, array $fallback = [])
{
    $fallbackSettings = array_merge([
        'advanced_rate_limit_enabled' => 0,
        'login_rate_limit_max_attempts' => 8,
        'login_rate_limit_block_minutes' => 15,
        'backend_request_limit_per_minute' => 240,
        'backend_request_limit_per_hour' => 3600,
        'image_upload_limit_per_minute' => 20,
        'image_upload_limit_per_hour' => 240,
        'image_upload_mb_per_minute' => 120,
        'image_upload_mb_per_hour' => 1200,
        'image_download_limit_per_minute' => 180,
        'image_download_limit_per_hour' => 2400,
        'image_download_mb_per_minute' => 300,
        'image_download_mb_per_hour' => 3000,
    ], $fallback);

    return [
        'advanced_rate_limit_enabled' => !empty($input['advanced_rate_limit_enabled']) ? 1 : 0,
        'login_rate_limit_max_attempts' => max(1, (int) ($input['login_rate_limit_max_attempts'] ?? $fallbackSettings['login_rate_limit_max_attempts'])),
        'login_rate_limit_block_minutes' => max(1, (int) ($input['login_rate_limit_block_minutes'] ?? $fallbackSettings['login_rate_limit_block_minutes'])),
        'backend_request_limit_per_minute' => max(1, (int) ($input['backend_request_limit_per_minute'] ?? $fallbackSettings['backend_request_limit_per_minute'])),
        'backend_request_limit_per_hour' => max(1, (int) ($input['backend_request_limit_per_hour'] ?? $fallbackSettings['backend_request_limit_per_hour'])),
        'image_upload_limit_per_minute' => max(1, (int) ($input['image_upload_limit_per_minute'] ?? $fallbackSettings['image_upload_limit_per_minute'])),
        'image_upload_limit_per_hour' => max(1, (int) ($input['image_upload_limit_per_hour'] ?? $fallbackSettings['image_upload_limit_per_hour'])),
        'image_upload_mb_per_minute' => max(1, (int) ($input['image_upload_mb_per_minute'] ?? $fallbackSettings['image_upload_mb_per_minute'])),
        'image_upload_mb_per_hour' => max(1, (int) ($input['image_upload_mb_per_hour'] ?? $fallbackSettings['image_upload_mb_per_hour'])),
        'image_download_limit_per_minute' => max(1, (int) ($input['image_download_limit_per_minute'] ?? $fallbackSettings['image_download_limit_per_minute'])),
        'image_download_limit_per_hour' => max(1, (int) ($input['image_download_limit_per_hour'] ?? $fallbackSettings['image_download_limit_per_hour'])),
        'image_download_mb_per_minute' => max(1, (int) ($input['image_download_mb_per_minute'] ?? $fallbackSettings['image_download_mb_per_minute'])),
        'image_download_mb_per_hour' => max(1, (int) ($input['image_download_mb_per_hour'] ?? $fallbackSettings['image_download_mb_per_hour'])),
    ];
}

function wallos_build_rate_limit_preset_config_from_admin_row(array $adminRow)
{
    return wallos_normalize_rate_limit_preset_config($adminRow);
}

function wallos_build_rate_limit_test_preset_config()
{
    return wallos_normalize_rate_limit_preset_config([
        'advanced_rate_limit_enabled' => 1,
        'login_rate_limit_max_attempts' => 2,
        'login_rate_limit_block_minutes' => 2,
        'backend_request_limit_per_minute' => 3,
        'backend_request_limit_per_hour' => 10,
        'image_upload_limit_per_minute' => 1,
        'image_upload_limit_per_hour' => 3,
        'image_upload_mb_per_minute' => 5,
        'image_upload_mb_per_hour' => 20,
        'image_download_limit_per_minute' => 2,
        'image_download_limit_per_hour' => 5,
        'image_download_mb_per_minute' => 5,
        'image_download_mb_per_hour' => 20,
    ]);
}

function wallos_build_rate_limit_recommended_preset_config()
{
    return wallos_normalize_rate_limit_preset_config([
        'advanced_rate_limit_enabled' => 1,
        'login_rate_limit_max_attempts' => 6,
        'login_rate_limit_block_minutes' => 30,
        'backend_request_limit_per_minute' => 120,
        'backend_request_limit_per_hour' => 1800,
        'image_upload_limit_per_minute' => 4,
        'image_upload_limit_per_hour' => 40,
        'image_upload_mb_per_minute' => 40,
        'image_upload_mb_per_hour' => 320,
        'image_download_limit_per_minute' => 120,
        'image_download_limit_per_hour' => 1200,
        'image_download_mb_per_minute' => 180,
        'image_download_mb_per_hour' => 1800,
    ]);
}

function wallos_encode_rate_limit_preset_config(array $config)
{
    return json_encode(
        wallos_normalize_rate_limit_preset_config($config),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

function wallos_decode_rate_limit_preset_config($rawValue)
{
    $decoded = json_decode((string) $rawValue, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    return wallos_normalize_rate_limit_preset_config($decoded);
}

function wallos_get_rate_limit_presets($db)
{
    $presets = [];
    $result = $db->query('SELECT id, name, config_json, created_at, updated_at FROM rate_limit_presets ORDER BY id ASC');

    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $presets[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'config' => wallos_decode_rate_limit_preset_config($row['config_json'] ?? '{}'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $presets;
}

function wallos_find_rate_limit_preset_by_id($db, $presetId)
{
    $stmt = $db->prepare('SELECT id, name, config_json, created_at, updated_at FROM rate_limit_presets WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', (int) $presetId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    if ($row === false) {
        return null;
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['name'] ?? ''),
        'config' => wallos_decode_rate_limit_preset_config($row['config_json'] ?? '{}'),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}
