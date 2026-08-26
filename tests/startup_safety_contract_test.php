<?php

function wallos_startup_contract_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_startup_contract_source($path)
{
    $source = file_get_contents(__DIR__ . '/../' . $path);
    if ($source === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $source;
}

try {
    $startup = wallos_startup_contract_source('startup.sh');
    $migrationPosition = strpos($startup, '/endpoints/db/migrate.php');
    $verificationPosition = strpos($startup, '/endpoints/db/verify.php');
    $phpFpmPosition = strpos($startup, 'php-fpm -F &');
    $cronPosition = strpos($startup, 'crond -f &');
    $nginxPosition = strpos($startup, "nginx -g 'daemon off;' &");
    wallos_startup_contract_assert(
        $migrationPosition !== false
            && $verificationPosition > $migrationPosition
            && $phpFpmPosition > $verificationPosition
            && $cronPosition > $verificationPosition
            && $nginxPosition > $verificationPosition,
        'Database migration and verification must finish before services start.'
    );
    wallos_startup_contract_assert(
        strpos($startup, 'WALLOS_REQUIRE_EXISTING_DB') !== false
            && strpos($startup, 'WALLOS_CRON_ENABLED') !== false
            && strpos($startup, 'touch "$READY_FILE"') !== false,
        'Startup must enforce production mounts, support isolated validation, and publish readiness.'
    );
    foreach (['updateexchange.php', 'cleanuprequestlogs.php', 'cleanupbannedusers.php', 'cleanuptrashedsubscriptions.php', 'createbackup.php cleanup'] as $forbidden) {
        wallos_startup_contract_assert(
            strpos($startup, $forbidden) === false,
            'Startup must not run scheduled side effect: ' . $forbidden
        );
    }

    $health = wallos_startup_contract_source('health.php');
    wallos_startup_contract_assert(
        strpos($health, 'WALLOS_READY_FILE') !== false
            && strpos($health, 'SQLITE3_OPEN_READONLY') !== false
            && strpos($health, 'PRAGMA quick_check(1)') !== false
            && strpos($health, "http_response_code(503)") !== false,
        'Health checks must verify readiness and SQLite instead of returning unconditional OK.'
    );

    $migrator = wallos_startup_contract_source('endpoints/db/migrate.php');
    $insertPosition = strpos($migrator, 'INSERT INTO migrations');
    $commitPosition = strpos($migrator, "\$db->exec('COMMIT')", $insertPosition ?: 0);
    wallos_startup_contract_assert(
        strpos($migrator, "\$db->enableExceptions(true)") !== false
            && strpos($migrator, "\$db->exec('BEGIN IMMEDIATE')") !== false
            && $insertPosition !== false
            && $commitPosition > $insertPosition
            && strpos($migrator, "\$db->exec('ROLLBACK')") !== false,
        'Migration changes and completion markers must commit atomically and roll back on failure.'
    );
    foreach (glob(__DIR__ . '/../migrations/*.php') ?: [] as $migrationPath) {
        $migrationSource = file_get_contents($migrationPath);
        wallos_startup_contract_assert(
            is_string($migrationSource)
                && preg_match('/\$db->exec\([\'\"](?:BEGIN|COMMIT|ROLLBACK)\b/i', $migrationSource) !== 1,
            basename($migrationPath) . ' must leave transaction ownership to the migration runner.'
        );
    }

    $dockerfile = wallos_startup_contract_source('Dockerfile');
    wallos_startup_contract_assert(
        strpos($dockerfile, 'php:8.3.33-fpm-alpine3.24@sha256:') !== false
            && strpos($dockerfile, 'apk upgrade') === false
            && strpos($dockerfile, 'imagick-3.8.1') !== false,
        'Production build inputs must keep the audited base and PECL version pinned.'
    );

    $dockerIgnore = wallos_startup_contract_source('.dockerignore');
    foreach (['node_modules/', 'tests/', 'screenshots/', '.planning/'] as $ignoredPath) {
        wallos_startup_contract_assert(
            strpos($dockerIgnore, $ignoredPath) !== false,
            'Production image context must exclude ' . $ignoredPath
        );
    }

    echo "Startup and migration safety contracts passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

?>
