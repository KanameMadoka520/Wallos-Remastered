<?php

$projectRoot = dirname(__DIR__, 2);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $preflightProcess = proc_open(
        [PHP_BINARY, $projectRoot . '/endpoints/db/verify.php', '--pre-migration'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $preflightPipes,
        $projectRoot
    );
    if (!is_resource($preflightProcess)) {
        throw new RuntimeException('Unable to start the read-only database preflight.');
    }

    fclose($preflightPipes[0]);
    $preflightOutput = stream_get_contents($preflightPipes[1]);
    $preflightError = stream_get_contents($preflightPipes[2]);
    fclose($preflightPipes[1]);
    fclose($preflightPipes[2]);
    $preflightStatus = proc_close($preflightProcess);
    if ($preflightStatus !== 0) {
        throw new RuntimeException(
            'Read-only database preflight failed: ' . trim((string) $preflightError)
        );
    }
    if (trim((string) $preflightOutput) !== '') {
        echo trim((string) $preflightOutput) . PHP_EOL;
    }

    chdir($projectRoot);
    require_once $projectRoot . '/includes/connect_endpoint_crontabs.php';

    /** @var SQLite3 $db */
    $db->enableExceptions(true);
    $db->busyTimeout(10000);
    $db->exec('PRAGMA foreign_keys = ON');

    $completedMigrations = [];
    $migrationTableExists = $db->querySingle(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'migrations' LIMIT 1"
    ) !== null;

    if ($migrationTableExists) {
        $migrationQuery = $db->query('SELECT migration FROM migrations');
        while ($row = $migrationQuery->fetchArray(SQLITE3_ASSOC)) {
            $rawMigration = str_replace('\\', '/', trim((string) ($row['migration'] ?? '')));
            if (preg_match('#(?:^|/)migrations/(\d{6}\.php)$#', $rawMigration, $matches) === 1) {
                $completedMigrations['migrations/' . $matches[1]] = true;
            }
        }
    }

    $migrationFiles = glob($projectRoot . '/migrations/*.php') ?: [];
    sort($migrationFiles, SORT_STRING);
    $requiredMigrations = array_values(array_filter($migrationFiles, static function ($migrationPath) use ($completedMigrations) {
        return !isset($completedMigrations['migrations/' . basename($migrationPath)]);
    }));

    if (!$requiredMigrations) {
        echo "No migrations to run.\n";
    }

    foreach ($requiredMigrations as $migrationPath) {
        $migration = 'migrations/' . basename($migrationPath);
        $runnerTransaction = false;

        try {
            $db->exec('BEGIN IMMEDIATE');
            $runnerTransaction = true;

            require $migrationPath;

            $stmt = $db->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
            $stmt->bindValue(':migration', $migration, SQLITE3_TEXT);
            $stmt->execute();
            $db->exec('COMMIT');
            $runnerTransaction = false;

            echo sprintf("Migration %s completed successfully.\n", $migration);
        } catch (Throwable $throwable) {
            if ($runnerTransaction) {
                try {
                    $db->exec('ROLLBACK');
                } catch (Throwable $rollbackError) {
                    // Preserve the original migration failure.
                }
            }
            throw new RuntimeException(
                'Migration ' . $migration . ' failed: ' . $throwable->getMessage(),
                0,
                $throwable
            );
        }
    }
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
    exit(1);
}

?>
