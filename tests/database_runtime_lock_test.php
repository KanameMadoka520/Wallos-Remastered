<?php

require_once __DIR__ . '/../includes/database_runtime_lock.php';

function wallos_runtime_lock_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_runtime_lock_remove_tree($path)
{
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            wallos_runtime_lock_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
}

$testRoot = sys_get_temp_dir() . '/wallos-runtime-lock-' . bin2hex(random_bytes(8));
$databaseDirectory = $testRoot . '/db';
$databaseFile = $databaseDirectory . '/wallos.db';
$maintenanceFile = $testRoot . '/maintenance.lock';
$resultFile = $testRoot . '/exclusive-result.txt';
$process = null;

try {
    wallos_runtime_lock_assert(mkdir($databaseDirectory, 0770, true), 'Unable to create lock test directory.');
    wallos_runtime_lock_assert(file_put_contents($databaseFile, 'fixture') !== false, 'Unable to create lock fixture.');
    putenv('WALLOS_DATABASE_MAINTENANCE_FILE=' . $maintenanceFile);

    wallos_database_acquire_shared_runtime_lock($databaseFile);

    $childCode = <<<'PHP'
require $argv[1];
putenv('WALLOS_DATABASE_MAINTENANCE_FILE=' . $argv[3]);
$started = microtime(true);
$lock = wallos_database_acquire_exclusive_runtime_lock($argv[2], 3000);
file_put_contents($argv[4], (string) (microtime(true) - $started));
usleep(150000);
wallos_database_release_exclusive_runtime_lock($lock);
PHP;
    $process = proc_open(
        [PHP_BINARY, '-r', $childCode, __DIR__ . '/../includes/database_runtime_lock.php', $databaseFile, $maintenanceFile, $resultFile],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );
    wallos_runtime_lock_assert(is_resource($process), 'Unable to start exclusive lock fixture.');
    fclose($pipes[0]);

    $markerDeadline = microtime(true) + 2;
    while (!is_file($maintenanceFile) && microtime(true) < $markerDeadline) {
        usleep(20000);
    }
    wallos_runtime_lock_assert(is_file($maintenanceFile), 'Exclusive lock did not publish maintenance state.');

    $blockedCode = <<<'PHP'
require $argv[1];
putenv('WALLOS_DATABASE_MAINTENANCE_FILE=' . $argv[3]);
try {
    wallos_database_acquire_shared_runtime_lock($argv[2], 200);
    exit(2);
} catch (RuntimeException $runtimeException) {
    exit(strpos($runtimeException->getMessage(), 'maintenance') !== false ? 0 : 3);
}
PHP;
    $blockedProcess = proc_open(
        [PHP_BINARY, '-r', $blockedCode, __DIR__ . '/../includes/database_runtime_lock.php', $databaseFile, $maintenanceFile],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $blockedPipes
    );
    wallos_runtime_lock_assert(is_resource($blockedProcess), 'Unable to start shared lock rejection fixture.');
    foreach ($blockedPipes as $blockedPipe) {
        fclose($blockedPipe);
    }
    wallos_runtime_lock_assert(proc_close($blockedProcess) === 0, 'A new reader entered during exclusive maintenance.');

    usleep(150000);
    wallos_database_release_shared_runtime_lock();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $process = null;
    wallos_runtime_lock_assert($exitCode === 0, 'Exclusive lock fixture failed: ' . trim($stdout . ' ' . $stderr));
    wallos_runtime_lock_assert(!is_file($maintenanceFile), 'Maintenance marker remained after a successful restore lock.');
    wallos_runtime_lock_assert(
        (float) file_get_contents($resultFile) >= 0.1,
        'Exclusive maintenance did not wait for the active shared reader.'
    );

    wallos_database_acquire_shared_runtime_lock($databaseFile);
    wallos_database_release_shared_runtime_lock();

    echo "Database runtime lock test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    wallos_database_release_shared_runtime_lock();
    if (is_resource($process)) {
        proc_terminate($process, 9);
        proc_close($process);
    }
    putenv('WALLOS_DATABASE_MAINTENANCE_FILE');
    wallos_runtime_lock_remove_tree($testRoot);
}

?>
