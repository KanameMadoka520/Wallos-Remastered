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
    $restoreTransactionPosition = strpos($startup, '.wallos-restore-transaction');
    wallos_startup_contract_assert(
        $migrationPosition !== false
            && $verificationPosition > $migrationPosition
            && $phpFpmPosition > $verificationPosition
            && $cronPosition > $verificationPosition
            && $nginxPosition > $verificationPosition
            && $restoreTransactionPosition !== false
            && $restoreTransactionPosition < $migrationPosition,
        'Database migration and verification must finish before services start.'
    );
    wallos_startup_contract_assert(
        strpos($startup, 'WALLOS_REQUIRE_EXISTING_DB') !== false
            && strpos($startup, 'WALLOS_CRON_ENABLED') !== false
            && strpos($startup, 'touch "$READY_FILE"') !== false
            && strpos($startup, 'delgroup nginx www-data') !== false
            && strpos($startup, 'reserved Nginx worker IDs') !== false
            && strpos($startup, '-type d -exec chmod 0770') !== false
            && strpos($startup, '-type f -exec chmod 0660') !== false
            && strpos($startup, 'chmod 0771 "$APP_ROOT/db"') !== false,
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
    wallos_startup_contract_assert(
        strpos($dockerfile, 'COPY cronjobs /tmp/wallos-cronjobs') !== false
            && strpos($dockerfile, '/usr/bin/crontab /tmp/wallos-cronjobs') !== false
            && strpos($dockerfile, 'rm -f /tmp/wallos-cronjobs') !== false
            && strpos($dockerfile, 'COPY cronjobs /etc/cron.d/cronjobs') === false,
        'Cron jobs must be installed once instead of being loaded as both system and user schedules.'
    );

    wallos_startup_contract_assert(
        !file_exists(__DIR__ . '/../Dockerfile.local'),
        'The obsolete bellamy/wallos:latest Dockerfile.local build path must stay removed.'
    );
    foreach (['README.md', 'README_EN.md'] as $readmeName) {
        $readme = wallos_startup_contract_source($readmeName);
        wallos_startup_contract_assert(
            strpos($readme, 'Dockerfile.local') === false,
            $readmeName . ' must not recommend the obsolete local-image build path.'
        );
    }

    $dockerIgnore = wallos_startup_contract_source('.dockerignore');
    foreach (['node_modules/', 'tests/', 'screenshots/', '.planning/', 'db/', 'backups/', 'logos/', 'images/uploads/logos/'] as $ignoredPath) {
        wallos_startup_contract_assert(
            strpos($dockerIgnore, $ignoredPath) !== false,
            'Production image context must exclude ' . $ignoredPath
        );
    }

    $gitIgnore = wallos_startup_contract_source('.gitignore');
    wallos_startup_contract_assert(
        strpos($gitIgnore, '/logos/*') !== false
            && strpos($gitIgnore, '!/logos/.gitkeep') !== false
            && file_exists(__DIR__ . '/../logos/.gitkeep'),
        'The root runtime logo mount must exist without allowing real media into Git.'
    );

    echo "Startup and migration safety contracts passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

?>
