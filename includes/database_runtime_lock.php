<?php

function wallos_database_runtime_lock_paths($databaseFile)
{
    $databaseFile = (string) $databaseFile;
    $projectRoot = dirname(dirname($databaseFile));
    $maintenanceFile = trim((string) getenv('WALLOS_DATABASE_MAINTENANCE_FILE'));
    if ($maintenanceFile === '') {
        $maintenanceFile = $projectRoot . '/.tmp/database-maintenance.lock';
    }

    return [
        'lock' => dirname($databaseFile) . '/.wallos-runtime.lock',
        'maintenance' => $maintenanceFile,
        'restore_transaction' => dirname($databaseFile) . '/.wallos-restore-transaction',
    ];
}

function wallos_database_restore_transaction_exists(array $paths)
{
    $transactionFile = (string) ($paths['restore_transaction'] ?? '');
    return $transactionFile !== '' && @lstat($transactionFile) !== false;
}

function wallos_database_maintenance_marker_exists(array $paths)
{
    $maintenanceFile = (string) ($paths['maintenance'] ?? '');
    return $maintenanceFile !== '' && @lstat($maintenanceFile) !== false;
}

function wallos_database_sync_directory($directory)
{
    if (!function_exists('fsync')) {
        return;
    }
    if (is_link($directory) || !is_dir($directory)) {
        throw new RuntimeException('Database maintenance directory is not a real directory.');
    }

    $stream = @fopen($directory, 'r');
    if ($stream === false) {
        throw new RuntimeException('Unable to open the database maintenance directory for sync.');
    }
    try {
        if (!fsync($stream)) {
            throw new RuntimeException('Unable to sync the database maintenance directory.');
        }
    } finally {
        fclose($stream);
    }
}

function wallos_database_release_shared_runtime_lock()
{
    global $wallosDatabaseSharedRuntimeLock;

    if (is_resource($wallosDatabaseSharedRuntimeLock)) {
        @flock($wallosDatabaseSharedRuntimeLock, LOCK_UN);
        @fclose($wallosDatabaseSharedRuntimeLock);
    }
    $wallosDatabaseSharedRuntimeLock = null;
}

function wallos_database_acquire_shared_runtime_lock($databaseFile, $timeoutMilliseconds = 5000)
{
    global $wallosDatabaseSharedRuntimeLock;

    if (is_resource($wallosDatabaseSharedRuntimeLock)) {
        return $wallosDatabaseSharedRuntimeLock;
    }

    $paths = wallos_database_runtime_lock_paths($databaseFile);
    if (wallos_database_maintenance_marker_exists($paths)
        || wallos_database_restore_transaction_exists($paths)) {
        throw new RuntimeException('Database maintenance is in progress.');
    }

    $handle = @fopen($paths['lock'], 'c');
    if ($handle === false) {
        throw new RuntimeException('Unable to open the database runtime lock.');
    }

    $deadline = microtime(true) + (max(1, (int) $timeoutMilliseconds) / 1000);
    do {
        if (@flock($handle, LOCK_SH | LOCK_NB)) {
            if (wallos_database_maintenance_marker_exists($paths)
                || wallos_database_restore_transaction_exists($paths)) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
                throw new RuntimeException('Database maintenance is in progress.');
            }

            $wallosDatabaseSharedRuntimeLock = $handle;
            return $handle;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    @fclose($handle);
    throw new RuntimeException('Timed out waiting for the database runtime lock.');
}

function wallos_database_acquire_exclusive_runtime_lock($databaseFile, $timeoutMilliseconds = 30000)
{
    $paths = wallos_database_runtime_lock_paths($databaseFile);
    if (wallos_database_restore_transaction_exists($paths)) {
        throw new RuntimeException('An incomplete database restore transaction requires recovery.');
    }

    $maintenanceDirectory = dirname($paths['maintenance']);
    if (file_exists($maintenanceDirectory) || is_link($maintenanceDirectory)) {
        if (is_link($maintenanceDirectory) || !is_dir($maintenanceDirectory)) {
            throw new RuntimeException('Database maintenance parent must be a real directory.');
        }
    } elseif (!mkdir($maintenanceDirectory, 0700, true) && !is_dir($maintenanceDirectory)) {
        throw new RuntimeException('Unable to create the database maintenance directory.');
    }

    $markerHandle = @fopen($paths['maintenance'], 'x');
    if ($markerHandle === false) {
        throw new RuntimeException('Another database maintenance operation is already in progress.');
    }
    try {
        $payload = (string) getmypid() . PHP_EOL;
        $offset = 0;
        while ($offset < strlen($payload)) {
            $written = fwrite($markerHandle, substr($payload, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write database maintenance state.');
            }
            $offset += $written;
        }
        if (!@chmod($paths['maintenance'], 0660)
            || !fflush($markerHandle)
            || (function_exists('fsync') && !fsync($markerHandle))) {
            throw new RuntimeException('Unable to persist database maintenance state.');
        }
    } catch (Throwable $throwable) {
        fclose($markerHandle);
        @unlink($paths['maintenance']);
        throw $throwable;
    }
    fclose($markerHandle);
    wallos_database_sync_directory($maintenanceDirectory);

    wallos_database_release_shared_runtime_lock();

    $handle = @fopen($paths['lock'], 'c');
    if ($handle === false) {
        @unlink($paths['maintenance']);
        throw new RuntimeException('Unable to open the database runtime lock.');
    }

    $deadline = microtime(true) + (max(1, (int) $timeoutMilliseconds) / 1000);
    do {
        if (@flock($handle, LOCK_EX | LOCK_NB)) {
            return [
                'handle' => $handle,
                'maintenance' => $paths['maintenance'],
                'maintenance_cleared' => false,
            ];
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    @fclose($handle);
    @unlink($paths['maintenance']);
    throw new RuntimeException('Timed out waiting for active database requests to finish.');
}

function wallos_database_clear_exclusive_maintenance_marker(array &$lock)
{
    $maintenanceFile = (string) ($lock['maintenance'] ?? '');
    if ($maintenanceFile === '' || !empty($lock['maintenance_cleared'])) {
        return;
    }
    if (is_link($maintenanceFile) || !is_file($maintenanceFile) || !@unlink($maintenanceFile)) {
        throw new RuntimeException('Unable to clear database maintenance state.');
    }
    wallos_database_sync_directory(dirname($maintenanceFile));
    $lock['maintenance_cleared'] = true;
    $lock['maintenance'] = '';
}

function wallos_database_downgrade_exclusive_runtime_lock(array &$lock)
{
    global $wallosDatabaseSharedRuntimeLock;

    if (is_resource($wallosDatabaseSharedRuntimeLock)) {
        throw new RuntimeException('A shared database runtime lock is already active.');
    }
    $handle = $lock['handle'] ?? null;
    if (!is_resource($handle) || !@flock($handle, LOCK_SH)) {
        throw new RuntimeException('Unable to downgrade the database runtime lock.');
    }

    $lock['handle'] = null;
    $wallosDatabaseSharedRuntimeLock = $handle;
    try {
        wallos_database_clear_exclusive_maintenance_marker($lock);
    } catch (Throwable $throwable) {
        wallos_database_release_shared_runtime_lock();
        throw $throwable;
    }

    return $handle;
}

function wallos_database_release_exclusive_runtime_lock($lock)
{
    if (!is_array($lock)) {
        return;
    }

    if (empty($lock['retain_maintenance'])) {
        try {
            wallos_database_clear_exclusive_maintenance_marker($lock);
        } catch (Throwable $throwable) {
            error_log('Wallos database maintenance cleanup warning: ' . $throwable->getMessage());
        }
    }

    $handle = $lock['handle'] ?? null;
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

?>
