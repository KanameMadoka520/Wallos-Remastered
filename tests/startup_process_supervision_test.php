<?php

function wallos_supervision_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_supervision_write_executable($path, $contents)
{
    if (file_put_contents($path, $contents) === false || !chmod($path, 0755)) {
        throw new RuntimeException('Unable to create executable fixture: ' . $path);
    }
}

function wallos_supervision_remove_tree($path)
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            wallos_supervision_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
}

function wallos_supervision_process_exists($pid)
{
    return $pid > 0 && is_dir('/proc/' . $pid);
}

function wallos_supervision_run_failure_case($startupPath, $failingService, $cronEnabled, $existingDatabase = true)
{
    $fixtureRoot = sys_get_temp_dir() . '/wallos-supervision-' . bin2hex(random_bytes(8));
    $appRoot = $fixtureRoot . '/app';
    $binRoot = $fixtureRoot . '/bin';
    $runRoot = $fixtureRoot . '/run';
    $stateRoot = $fixtureRoot . '/state';

    foreach ([$appRoot . '/db', $binRoot, $runRoot, $stateRoot] as $directory) {
        if (!mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create fixture directory: ' . $directory);
        }
    }
    if ($existingDatabase) {
        file_put_contents($appRoot . '/db/wallos.db', 'read-only preflight fixture');
    }

    wallos_supervision_write_executable(
        $binRoot . '/php',
        <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$WALLOS_TEST_PHP_LOG"
exit 0
SH
    );

    $serviceFixture = <<<'SH'
#!/bin/sh
service_name=${0##*/}
printf '%s\n' "$$" > "$WALLOS_TEST_STATE_DIR/$service_name.pid"

if [ "$WALLOS_TEST_FAIL_SERVICE" = "$service_name" ]; then
  while [ ! -f "$WALLOS_READY_FILE" ]; do
    sleep 1
  done
  exit 7
fi

trap 'exit 0' TERM QUIT INT
while :; do
  sleep 1
done
SH;
    foreach (['php-fpm', 'crond', 'nginx'] as $service) {
        wallos_supervision_write_executable($binRoot . '/' . $service, $serviceFixture);
    }
    foreach (['groupmod', 'usermod'] as $command) {
        wallos_supervision_write_executable($binRoot . '/' . $command, "#!/bin/sh\nexit 0\n");
    }

    $outputFile = $fixtureRoot . '/startup-output.log';
    $phpLog = $fixtureRoot . '/php-calls.log';
    $readyFile = $runRoot . '/ready';
    $environment = getenv();
    $environment['PATH'] = $binRoot . ':' . ($environment['PATH'] ?? '/usr/bin:/bin');
    $environment['WALLOS_APP_ROOT'] = $appRoot;
    $environment['WALLOS_PHP_BIN'] = $binRoot . '/php';
    $environment['WALLOS_PHP_CONFIG_FILE'] = $fixtureRoot . '/php.ini';
    $environment['WALLOS_RUN_DIR'] = $runRoot;
    $environment['WALLOS_READY_FILE'] = $readyFile;
    $environment['WALLOS_STARTUP_LOG'] = $fixtureRoot . '/startup.log';
    $environment['WALLOS_CRON_LOG_DIR'] = $fixtureRoot . '/cron-log';
    $environment['WALLOS_SHUTDOWN_TIMEOUT'] = '1';
    $environment['WALLOS_REQUIRE_EXISTING_DB'] = $existingDatabase ? '1' : '0';
    $environment['WALLOS_CRON_ENABLED'] = $cronEnabled ? '1' : '0';
    $environment['WALLOS_TEST_FAIL_SERVICE'] = $failingService;
    $environment['WALLOS_TEST_PHP_LOG'] = $phpLog;
    $environment['WALLOS_TEST_STATE_DIR'] = $stateRoot;
    $environment['PUID'] = '33';
    $environment['PGID'] = '33';

    $process = proc_open(
        ['/bin/sh', $startupPath],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $outputFile, 'a'],
            2 => ['file', $outputFile, 'a'],
        ],
        $pipes,
        $fixtureRoot,
        $environment
    );
    if (!is_resource($process)) {
        wallos_supervision_remove_tree($fixtureRoot);
        throw new RuntimeException('Unable to launch startup supervision fixture.');
    }
    fclose($pipes[0]);

    $exitCode = null;
    $deadline = microtime(true) + 12;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);

    if ($exitCode === null) {
        proc_terminate($process, 15);
        usleep(500000);
        proc_terminate($process, 9);
    }
    $closeCode = proc_close($process);
    if ($exitCode === null || $exitCode === -1) {
        $exitCode = $closeCode;
    }

    try {
        $output = is_file($outputFile) ? file_get_contents($outputFile) : '';
        wallos_supervision_assert(
            $exitCode === 7,
            $failingService . ' failure must be propagated as status 7; got ' . $exitCode . '. Output: ' . $output
        );
        wallos_supervision_assert(!file_exists($readyFile), 'Readiness must be removed after ' . $failingService . ' exits.');
        wallos_supervision_assert(
            strpos($output, 'Critical process ' . $failingService . ' exited unexpectedly') !== false,
            'Startup must identify the failed critical process: ' . $failingService
        );

        $phpCalls = is_file($phpLog) ? file($phpLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        wallos_supervision_assert(count($phpCalls) === 3, 'Database startup must make exactly three setup calls.');
        if ($existingDatabase) {
            wallos_supervision_assert(
                str_ends_with($phpCalls[0], '/endpoints/db/verify.php --pre-migration')
                    && str_ends_with($phpCalls[1], '/endpoints/db/migrate.php')
                    && str_ends_with($phpCalls[2], '/endpoints/db/verify.php'),
                'Preflight, migration, and full verification order is incorrect.'
            );
        } else {
            wallos_supervision_assert(
                str_ends_with($phpCalls[0], '/endpoints/cronjobs/createdatabase.php')
                    && str_ends_with($phpCalls[1], '/endpoints/db/migrate.php')
                    && str_ends_with($phpCalls[2], '/endpoints/db/verify.php'),
                'New database creation, migration, and full verification order is incorrect.'
            );
        }

        foreach (glob($stateRoot . '/*.pid') ?: [] as $pidFile) {
            $pid = (int) trim((string) file_get_contents($pidFile));
            $stopDeadline = microtime(true) + 2;
            while (wallos_supervision_process_exists($pid) && microtime(true) < $stopDeadline) {
                usleep(50000);
            }
            wallos_supervision_assert(
                !wallos_supervision_process_exists($pid),
                basename($pidFile, '.pid') . ' was left running after supervisor shutdown.'
            );
        }

        $cronPidFile = $stateRoot . '/crond.pid';
        wallos_supervision_assert(
            $cronEnabled === file_exists($cronPidFile),
            'WALLOS_CRON_ENABLED was not respected.'
        );
    } finally {
        wallos_supervision_remove_tree($fixtureRoot);
    }
}

try {
    if (!function_exists('proc_open')) {
        throw new RuntimeException('proc_open is required for the startup supervision test.');
    }

    $projectRoot = dirname(__DIR__);
    $startupPath = $projectRoot . '/startup.sh';
    $startup = file_get_contents($startupPath);
    $dockerfile = file_get_contents($projectRoot . '/Dockerfile');
    $health = file_get_contents($projectRoot . '/health.php');

    wallos_supervision_assert(strpos($startup, '/run/wallos') !== false, 'Runtime readiness must default to /run/wallos.');
    wallos_supervision_assert(strpos($startup, '"$DB_ENDPOINT_DIR/verify.php" --pre-migration') !== false, 'Startup must run a read-only preflight.');
    wallos_supervision_assert(strpos($startup, 'chmod -R 770 /tmp') === false, 'Startup must not recursively chmod /tmp.');
    wallos_supervision_assert(strpos($startup, 'chown -R www-data:www-data /tmp') === false, 'Startup must not recursively chown /tmp.');
    wallos_supervision_assert(strpos($startup, 'WALLOS_SHUTDOWN_TIMEOUT') !== false, 'Shutdown must have a finite grace period.');
    foreach (['TERM', 'INT', 'QUIT'] as $signal) {
        wallos_supervision_assert(
            strpos($startup, "trap 'handle_signal " . $signal . "' " . $signal) !== false,
            'Startup must handle container signal: ' . $signal
        );
    }
    wallos_supervision_assert(
        substr_count($startup, 'signal_if_running') >= 7
            && strpos($startup, 'signal_if_running "$NGINX_PID" KILL') !== false
            && strpos($startup, 'signal_if_running "$PHP_FPM_PID" KILL') !== false
            && strpos($startup, 'signal_if_running "$CROND_PID" KILL') !== false
            && strpos($startup, 'reap_processes') !== false,
        'Shutdown must forcibly stop and reap services that exceed the grace period.'
    );
    wallos_supervision_assert(
        strpos($dockerfile, 'ENTRYPOINT ["dumb-init", "--single-child", "--"]') !== false,
        'dumb-init must forward signals only to the supervising startup script.'
    );
    foreach (['000079.php', 'period_budget', 'budget_period_type', 'budget_period_anchor_date',
        'idx_subscriptions_user_inactive_next_payment', 'idx_subscriptions_user_notify_inactive', 'One-time'] as $contract) {
        wallos_supervision_assert(strpos($health, $contract) !== false, 'Health check is missing contract: ' . $contract);
    }

    wallos_supervision_run_failure_case($startupPath, 'php-fpm', false);
    wallos_supervision_run_failure_case($startupPath, 'crond', true);
    wallos_supervision_run_failure_case($startupPath, 'nginx', true);
    wallos_supervision_run_failure_case($startupPath, 'php-fpm', false, false);

    echo "Startup process supervision tests passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

?>
