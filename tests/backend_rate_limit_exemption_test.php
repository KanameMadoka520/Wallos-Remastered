<?php

if (!function_exists('translate')) {
    function translate($key, $i18n)
    {
        return $key === 'rate_limit_triggered_message' ? '%s %s' : $key;
    }
}

require_once __DIR__ . '/../includes/security_rate_limits.php';

function wallos_backend_rate_limit_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

class WallosForbiddenRateLimitDatabase
{
    public $calls = 0;

    public function query($sql)
    {
        $this->calls++;
        throw new RuntimeException('An exempt request attempted to query rate-limit settings.');
    }

    public function querySingle($sql, $entireRow = false)
    {
        $this->calls++;
        throw new RuntimeException('An exempt request attempted to query rate-limit settings.');
    }
}

$originalRequestUri = $_SERVER['REQUEST_URI'] ?? null;

try {
    $exemptCases = [
        ['uri' => '/settings.php?tab=profile', 'user_id' => 1],
        ['uri' => '/endpoints/admin/users.php', 'user_id' => 2],
        ['uri' => '/endpoints/db/backup.php', 'user_id' => 2],
        ['uri' => '/endpoints/db/restore.php?mode=validate', 'user_id' => 2],
        ['uri' => '/endpoints/client/loganomaly.php?source=browser', 'user_id' => 2],
    ];

    foreach ($exemptCases as $case) {
        $_SERVER['REQUEST_URI'] = $case['uri'];
        $forbiddenDatabase = new WallosForbiddenRateLimitDatabase();
        $result = wallos_enforce_backend_request_rate_limit(
            $forbiddenDatabase,
            $case['user_id'],
            'exempt-user',
            []
        );
        wallos_backend_rate_limit_assert(
            $result === null && $forbiddenDatabase->calls === 0,
            'Exempt backend requests must return before any rate-limit database read: ' . $case['uri']
        );
    }

    $db = new SQLite3(':memory:');
    $db->enableExceptions(true);
    $db->exec('CREATE TABLE admin (
        id INTEGER PRIMARY KEY,
        advanced_rate_limit_enabled INTEGER,
        backend_request_limit_per_minute INTEGER,
        backend_request_limit_per_hour INTEGER,
        image_upload_limit_per_minute INTEGER,
        image_upload_limit_per_hour INTEGER,
        image_upload_mb_per_minute INTEGER,
        image_upload_mb_per_hour INTEGER,
        image_download_limit_per_minute INTEGER,
        image_download_limit_per_hour INTEGER,
        image_download_mb_per_minute INTEGER,
        image_download_mb_per_hour INTEGER
    )');
    $db->exec('INSERT INTO admin VALUES (1, 0, 1, 1, 20, 240, 120, 1200, 180, 2400, 300, 3000)');
    $db->exec('CREATE TABLE rate_limit_usage (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        username TEXT,
        category TEXT,
        unit_count INTEGER,
        byte_count INTEGER,
        path TEXT,
        created_at TEXT
    )');

    $_SERVER['REQUEST_URI'] = '/endpoints/user/budget.php?source=test';
    $disabledResult = wallos_enforce_backend_request_rate_limit($db, 2, 'regular-user', []);
    wallos_backend_rate_limit_assert(
        $disabledResult === null
            && (int) $db->querySingle('SELECT COUNT(*) FROM rate_limit_usage') === 0,
        'A disabled rate limiter must continue to allow non-exempt requests without recording usage.'
    );

    $db->exec('UPDATE admin SET advanced_rate_limit_enabled = 1 WHERE id = 1');
    $firstResult = wallos_enforce_backend_request_rate_limit($db, 2, 'regular-user', []);
    $usageRow = $db->querySingle('SELECT * FROM rate_limit_usage', true);
    wallos_backend_rate_limit_assert(
        $firstResult === null
            && (int) ($usageRow['user_id'] ?? 0) === 2
            && ($usageRow['username'] ?? '') === 'regular-user'
            && ($usageRow['category'] ?? '') === 'backend_request'
            && (int) ($usageRow['unit_count'] ?? 0) === 1
            && ($usageRow['path'] ?? '') === '/endpoints/user/budget.php',
        'An enabled rate limiter must preserve non-exempt usage recording semantics.'
    );

    $secondResult = wallos_enforce_backend_request_rate_limit($db, 2, 'regular-user', []);
    wallos_backend_rate_limit_assert(
        is_array($secondResult)
            && (int) ($secondResult['status'] ?? 0) === 429
            && ($secondResult['code'] ?? '') === 'backend_request_count_minute'
            && (int) $db->querySingle('SELECT COUNT(*) FROM rate_limit_usage') === 1,
        'Non-exempt requests above the configured limit must retain the existing 429 behavior.'
    );

    $db->close();
    $db = null;
    echo "Backend rate-limit exemption and compatibility checks passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if (isset($db) && $db instanceof SQLite3) {
        try {
            $db->close();
        } catch (Throwable $throwable) {
            // The database may already be closed after a successful test run.
        }
    }

    if ($originalRequestUri === null) {
        unset($_SERVER['REQUEST_URI']);
    } else {
        $_SERVER['REQUEST_URI'] = $originalRequestUri;
    }
}
